<?php
/**
 * Backup archiving manager for Lumos Backup.
 *
 * @package LumosBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lumos_Backup_Archiver
 */
class Lumos_Backup_Archiver {

	/**
	 * Generated image sizes left out of the current backup: [ count, bytes ].
	 *
	 * @var array
	 */
	private $skipped_thumbs = [ 0, 0 ];

	/**
	 * Run full backup: dumps database in batches, creates manifest,
	 * archives wp-content, handles optional AES-256-CBC encryption,
	 * creates sha256 sidecars, uploads to S3, and prunes old backups.
	 *
	 * @param string $trigger What started the run: manual, schedule or update (shown in progress).
	 * @return array|WP_Error Array of backup details on success, WP_Error on failure.
	 */
	public function create_backup( $trigger = 'manual' ) {
		// Large sites can take longer than 10 minutes; progress shows if a run stalls.
		@set_time_limit( 0 );
		@ignore_user_abort( true );
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'zip_extension_missing',
				__( 'PHP ZipArchive extension is required to create backup archives.', 'lumos-backup' )
			);
		}

		// 1. Check and acquire lock file
		Lumos_Backup_Storage::ensure_directories();
		$lock_file = Lumos_Backup_Storage::get_lock_path();

		if ( file_exists( $lock_file ) ) {
			$raw_lock  = @file_get_contents( $lock_file );
			$lock_data = $raw_lock ? json_decode( $raw_lock, true ) : null;
			$lock_time = is_array( $lock_data ) && isset( $lock_data['time'] ) ? (int) $lock_data['time'] : filemtime( $lock_file );

			// Stale after 2 hours (7200 seconds)
			if ( ( time() - $lock_time ) < 7200 ) {
				return new WP_Error( 'backup_locked', __( 'A backup run is currently in progress.', 'lumos-backup' ) );
			}
			@unlink( $lock_file );
		}

		@file_put_contents( $lock_file, json_encode( [ 'pid' => getmypid(), 'time' => time() ] ) );

		Lumos_Backup_Progress::start( $trigger );

		try {
			// Random suffix makes archive URLs unguessable even where .htaccess is ignored (Nginx).
			$base_filename = sprintf( 'backup-%s-%s.zip', gmdate( 'Ymd-His' ), bin2hex( random_bytes( 8 ) ) );
			$archives_dir  = Lumos_Backup_Storage::get_archives_dir();
			$raw_zip_path  = $archives_dir . '/' . $base_filename;
			$temp_zip_path = $raw_zip_path . '.tmp';

			// Create isolated temporary directory inside lumos-backup
			$temp_dir = Lumos_Backup_Storage::get_base_dir() . '/tmp-' . bin2hex( random_bytes( 8 ) );
			if ( ! wp_mkdir_p( $temp_dir ) ) {
				throw new Exception( __( 'Could not create temporary working directory for backup.', 'lumos-backup' ) );
			}

			// 2. Dump database in batches
			// Progress budget: database 2-30%, file scan 30-32%, adding files 32-45%,
			// compressing 45-90%, encryption 90-94%, checksum and uploads 94-99%.
			Lumos_Backup_Progress::stage( 'database', __( 'Exporting database', 'lumos-backup' ), 2 );
			$sql_file = $temp_dir . '/database.sql';
			$db       = new Lumos_Backup_Database();
			$db_res   = $db->dump_to_file(
				$sql_file,
				function ( $fraction, $detail ) {
					Lumos_Backup_Progress::tick( 2 + 28 * $fraction, $detail );
				}
			);

			if ( is_wp_error( $db_res ) ) {
				$this->cleanup_temp_dir( $temp_dir );
				throw new Exception( $db_res->get_error_message() );
			}

			// 3. Compute database checksum and create manifest.json
			$db_checksum   = hash_file( 'sha256', $sql_file );
			$manifest_file = $temp_dir . '/manifest.json';
			$manifest_res  = $this->create_manifest( $base_filename, $manifest_file, $db_checksum );
			if ( is_wp_error( $manifest_res ) ) {
				$this->cleanup_temp_dir( $temp_dir );
				throw new Exception( $manifest_res->get_error_message() );
			}

			// 4. Create Zip archive
			$zip      = new ZipArchive();
			$zip_open = $zip->open( $temp_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
			if ( true !== $zip_open ) {
				$this->cleanup_temp_dir( $temp_dir );
				throw new Exception( sprintf( __( 'Failed to create zip file (code %d).', 'lumos-backup' ), $zip_open ) );
			}

			$zip->addFile( $manifest_file, 'manifest.json' );
			$zip->addFile( $sql_file, 'database.sql' );

			$this->add_content_to_zip( $zip );

			// Compression happens inside close(). Report it when PHP supports it; otherwise
			// allow a long silence so a big site is not wrongly reported as stuck.
			if ( method_exists( $zip, 'registerProgressCallback' ) ) {
				Lumos_Backup_Progress::stage( 'compress', __( 'Compressing archive', 'lumos-backup' ), 45 );
				$zip->registerProgressCallback(
					0.01,
					function ( $rate ) {
						Lumos_Backup_Progress::tick( 45 + 45 * $rate, sprintf( __( '%d%% compressed', 'lumos-backup' ), (int) round( $rate * 100 ) ) );
					}
				);
			} else {
				Lumos_Backup_Progress::stage( 'compress', __( 'Compressing archive (this step does not report progress)', 'lumos-backup' ), 45, 30 * MINUTE_IN_SECONDS );
			}

			$zip_closed = $zip->close();
			if ( ! $zip_closed || ! file_exists( $temp_zip_path ) ) {
				$this->cleanup_temp_dir( $temp_dir );
				@unlink( $temp_zip_path );
				throw new Exception( __( 'Failed to finalize and save zip archive.', 'lumos-backup' ) );
			}

			rename( $temp_zip_path, $raw_zip_path );
			$this->cleanup_temp_dir( $temp_dir );

			// 5. Handle optional AES-256-CBC Encryption
			$is_encrypted        = Lumos_Backup_Restore_Gate::is_encryption_enabled();
			$final_filename      = $base_filename;
			$final_archive_path  = $raw_zip_path;

			if ( $is_encrypted ) {
				Lumos_Backup_Progress::stage( 'encrypt', __( 'Encrypting archive', 'lumos-backup' ), 90, 15 * MINUTE_IN_SECONDS );
				$passphrase   = Lumos_Backup_Restore_Gate::get_passphrase();
				$enc_meta     = Lumos_Backup_Restore_Gate::get_encryption_meta();
				$final_filename     = $base_filename . '.enc';
				$final_archive_path = $archives_dir . '/' . $final_filename;

				$enc_success = self::encrypt_archive_file(
					$raw_zip_path,
					$final_archive_path,
					$passphrase,
					$enc_meta['kdf_salt'],
					(int) ( $enc_meta['iterations'] ?? 100000 )
				);

				if ( ! $enc_success || ! file_exists( $final_archive_path ) ) {
					@unlink( $raw_zip_path );
					throw new Exception( __( 'Encryption of backup archive failed.', 'lumos-backup' ) );
				}

				// Safely delete unencrypted plain zip
				@unlink( $raw_zip_path );
			}

			// 6. Compute sha256 checksum and write sidecar file (.sha256)
			Lumos_Backup_Progress::stage( 'checksum', __( 'Verifying archive checksum', 'lumos-backup' ), 94, 10 * MINUTE_IN_SECONDS );
			@chmod( $final_archive_path, 0600 );
			$sha256 = hash_file( 'sha256', $final_archive_path );
			$sidecar_path = $final_archive_path . '.sha256';
			file_put_contents( $sidecar_path, $sha256 );

			// 7. Deploy standalone restore script and refresh restore-config.json
			Lumos_Backup_Restore_Gate::deploy_restore_script();
			Lumos_Backup_Restore_Gate::write_config_file();

			// 8. Purge old local archives based on retention policy
			$keep = (int) get_option( 'lumos_backup_keep', 7 );
			Lumos_Backup_Storage::purge_old_archives( $keep );

			// 9. Upload to S3 remote destination if configured (upload both archive and .sha256 sidecar)
			$s3_result = null;
			if ( class_exists( 'Lumos_Backup_S3' ) && Lumos_Backup_S3::is_configured() ) {
				Lumos_Backup_Progress::stage( 'upload', __( 'Uploading to S3', 'lumos-backup' ), 95, 35 * MINUTE_IN_SECONDS );
				$upload_res = Lumos_Backup_S3::upload_file( $final_archive_path, $final_filename );
				if ( is_wp_error( $upload_res ) ) {
					$s3_result = [
						'status' => 'error',
						'error'  => $upload_res->get_error_message(),
					];
				} else {
					// Also upload .sha256 sidecar to S3
					Lumos_Backup_S3::upload_file( $sidecar_path, $final_filename . '.sha256' );

					// Prune remote archives beyond retention limit
					$remote_pruned = Lumos_Backup_S3::prune_remote_archives( $keep );
					$s3_settings   = Lumos_Backup_S3::get_settings();
					$prefix_str    = $s3_settings['prefix'] ? $s3_settings['prefix'] . '/' : '';
					$s3_result     = [
						'status' => 'success',
						'uri'    => sprintf( 's3://%s/%s%s', $s3_settings['bucket'], $prefix_str, $final_filename ),
						'pruned' => $remote_pruned,
					];
				}
			}

			// 9b. Upload to SFTP remote destination if configured (upload archive and sidecar)
			$sftp_result = null;
			if ( class_exists( 'Lumos_Backup_SFTP' ) && Lumos_Backup_SFTP::is_configured() ) {
				Lumos_Backup_Progress::stage( 'upload', __( 'Uploading to SFTP', 'lumos-backup' ), 97, 10 * MINUTE_IN_SECONDS );
				$sftp_upload = Lumos_Backup_SFTP::upload_file( $final_archive_path, $final_filename );
				if ( is_wp_error( $sftp_upload ) ) {
					$sftp_result = [
						'status' => 'error',
						'error'  => $sftp_upload->get_error_message(),
					];
				} else {
					Lumos_Backup_SFTP::upload_file( $sidecar_path, $final_filename . '.sha256' );
					$sftp_pruned = Lumos_Backup_SFTP::prune_remote_archives( $keep );
					$sftp_result = [
						'status' => 'success',
						'uri'    => 'sftp://' . $sftp_upload['host'] . '/' . $sftp_upload['path'],
						'pruned' => $sftp_pruned,
					];
				}
			}

			// 10. Record last successful run
			$filesize = filesize( $final_archive_path );
			$record   = [
				'timestamp'      => time(),
				'datetime_utc'   => gmdate( 'Y-m-d H:i:s' ),
				'filename'       => $final_filename,
				'size'           => $filesize,
				'size_formatted' => size_format( $filesize, 2 ),
				'sha256'         => $sha256,
				'is_encrypted'   => $is_encrypted,
				'status'         => 'success',
				'thumbs_skipped' => $this->skipped_thumbs[0],
				'thumbs_bytes'   => $this->skipped_thumbs[1],
			];

			if ( null !== $s3_result ) {
				$record['s3'] = $s3_result;
			}
			if ( null !== $sftp_result ) {
				$record['sftp'] = $sftp_result;
			}

			update_option( 'lumos_backup_last', $record );

			// 11. Write dead-server kit recovery.md next to config
			self::generate_recovery_sheet( $final_filename, $sha256, $is_encrypted );

			Lumos_Backup_Progress::finish(
				'success',
				'',
				[
					'filename'       => $final_filename,
					'size_formatted' => $record['size_formatted'],
				]
			);

			return $record;

		} catch ( Exception $e ) {
			$this->record_failure( $e->getMessage() );
			Lumos_Backup_Progress::finish( 'error', $e->getMessage() );
			return new WP_Error( 'backup_failed', $e->getMessage() );
		} finally {
			// Always release lock
			if ( file_exists( $lock_file ) ) {
				@unlink( $lock_file );
			}
		}
	}

	/**
	 * Encrypt a backup zip archive using OpenSSL AES-256-CBC with PBKDF2 key derivation.
	 *
	 * Prepend 16-byte random IV to the resulting ciphertext file.
	 *
	 * @param string $source_path Path to plain zip.
	 * @param string $dest_path   Path to target .zip.enc file.
	 * @param string $passphrase  Secret passphrase.
	 * @param string $kdf_salt    Hex salt string.
	 * @param int    $iterations  PBKDF2 iteration count.
	 * @return bool True on success, false on failure.
	 */
	public static function encrypt_archive_file( $source_path, $dest_path, $passphrase, $kdf_salt, $iterations = 100000 ) {
		$key = hash_pbkdf2( 'sha256', $passphrase, hex2bin( $kdf_salt ), $iterations, 32, true );
		$iv  = random_bytes( 16 );

		// Stream in 1 MB chunks so memory use stays flat regardless of archive size.
		// Output is byte-identical to one-shot AES-256-CBC with PKCS#7 padding.
		$in  = @fopen( $source_path, 'rb' );
		$out = @fopen( $dest_path, 'wb' );
		if ( ! $in || ! $out ) {
			if ( $in ) {
				fclose( $in );
			}
			if ( $out ) {
				fclose( $out );
			}
			return false;
		}

		$ok    = false !== fwrite( $out, $iv );
		$chunk = 1048576; // Multiple of the 16-byte block size.
		$cur   = (string) fread( $in, $chunk );

		while ( $ok ) {
			$next    = feof( $in ) ? '' : (string) fread( $in, $chunk );
			$is_last = ( '' === $next );
			$flags   = $is_last ? OPENSSL_RAW_DATA : ( OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING );
			$ct      = openssl_encrypt( $cur, 'aes-256-cbc', $key, $flags, $iv );

			if ( false === $ct || false === fwrite( $out, $ct ) ) {
				$ok = false;
				break;
			}
			if ( $is_last ) {
				break;
			}
			$iv  = substr( $ct, -16 );
			$cur = $next;
		}

		fclose( $in );
		fclose( $out );

		return $ok;
	}

	/**
	 * Create manifest.json file with site metadata and checksum.
	 *
	 * @param string $archive_id    Backup archive filename.
	 * @param string $manifest_path  Destination file path.
	 * @param string $checksum       SHA256 checksum.
	 * @return true|WP_Error
	 */
	private function create_manifest( $archive_id, $manifest_path, $checksum = '' ) {
		global $wp_version, $wpdb;

		$is_ms    = function_exists( 'is_multisite' ) && is_multisite();
		$subsites = [];

		if ( $is_ms && function_exists( 'get_sites' ) ) {
			$sites = get_sites( [ 'number' => 500 ] );
			if ( is_array( $sites ) ) {
				foreach ( $sites as $s ) {
					$subsites[] = [
						'blog_id' => (int) $s->blog_id,
						'domain'  => $s->domain,
						'path'    => $s->path,
					];
				}
			}
		}

		$manifest = [
			'id'             => $archive_id,
			'created'        => gmdate( 'c' ), // UTC ISO 8601
			'siteurl'        => function_exists( 'site_url' ) ? site_url() : '',
			'home'           => function_exists( 'home_url' ) ? home_url() : '',
			'wp_version'     => ! empty( $wp_version ) ? $wp_version : 'unknown',
			'table_prefix'   => isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_',
			'sha256'         => $checksum,
			'is_multisite'   => $is_ms,
			'subsites'       => $subsites,
			// Restores rebuild generated image sizes when they were left out.
			'thumbnails_excluded' => Lumos_Backup_Thumbnails::is_skip_enabled(),
			'included_paths' => [
				'manifest.json',
				'database.sql',
				'wp-content',
			],
		];

		$json = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $json || false === file_put_contents( $manifest_path, $json ) ) {
			return new WP_Error( 'manifest_failed', __( 'Could not write backup manifest.json.', 'lumos-backup' ) );
		}

		return true;
	}

	/**
	 * Recursively add wp-content files to ZipArchive, applying strict exclusions.
	 *
	 * Excludes:
	 * - wp-content/cache (and all subdirectories)
	 * - wp-content/upgrade (and all subdirectories)
	 * - uploads/lumos-backup (the lumos-backup directory to prevent recursing)
	 *
	 * @param ZipArchive $zip Open ZipArchive instance.
	 */
	private function add_content_to_zip( ZipArchive $zip ) {
		$content_dir = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
		$base_backup = wp_normalize_path( untrailingslashit( Lumos_Backup_Storage::get_base_dir() ) );

		if ( ! is_dir( $content_dir ) || ! is_readable( $content_dir ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $content_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		// Pass 1: list what will be archived, so adding files can report "X of Y".
		Lumos_Backup_Progress::stage( 'scan', __( 'Scanning wp-content files', 'lumos-backup' ), 30 );
		$entries = [];
		$bytes   = 0;

		// Generated image sizes to leave out (only when the setting is on).
		$skip = Lumos_Backup_Thumbnails::is_skip_enabled() ? Lumos_Backup_Thumbnails::get_generated_paths() : [];

		foreach ( $iterator as $item ) {
			$pathname_normalized = wp_normalize_path( $item->getPathname() );

			// Check exclusions:
			// 1. Exclude lumos-backup directory (uploads/lumos-backup)
			if ( strpos( $pathname_normalized, $base_backup ) === 0 ) {
				continue;
			}

			// 2. Exclude cache directory
			if ( strpos( $pathname_normalized, $content_dir . '/cache' ) === 0 ) {
				continue;
			}

			// 3. Exclude upgrade directory
			if ( strpos( $pathname_normalized, $content_dir . '/upgrade' ) === 0 ) {
				continue;
			}

			// Relative path inside wp-content
			$relative_path = ltrim( substr( $pathname_normalized, strlen( $content_dir ) ), '/' );
			$zip_entry     = 'wp-content/' . $relative_path;

			if ( $item->isDir() ) {
				$entries[] = [ '', $zip_entry ];
			} elseif ( $skip && isset( $skip[ $pathname_normalized ] ) ) {
				$this->skipped_thumbs[0]++;
				$this->skipped_thumbs[1] += (int) $item->getSize();
			} elseif ( $item->isFile() && is_readable( $item->getPathname() ) ) {
				$entries[] = [ $item->getPathname(), $zip_entry ];
				$bytes    += (int) $item->getSize();
			}

			if ( 0 === count( $entries ) % 500 ) {
				Lumos_Backup_Progress::tick( 30, sprintf( __( '%s items found', 'lumos-backup' ), number_format_i18n( count( $entries ) ) ) );
			}
		}

		// Pass 2: add them to the archive (compression itself happens on close()).
		$total = count( $entries );
		Lumos_Backup_Progress::stage(
			'files',
			__( 'Adding files', 'lumos-backup' ),
			32
		);

		foreach ( $entries as $i => $entry ) {
			if ( '' === $entry[0] ) {
				$zip->addEmptyDir( $entry[1] );
			} else {
				$zip->addFile( $entry[0], $entry[1] );
			}

			if ( 0 === $i % 200 || $i === $total - 1 ) {
				Lumos_Backup_Progress::tick(
					32 + 13 * ( ( $i + 1 ) / max( 1, $total ) ),
					sprintf(
						/* translators: 1: items done, 2: items total, 3: total size */
						__( '%1$s of %2$s items (%3$s)', 'lumos-backup' ),
						number_format_i18n( $i + 1 ),
						number_format_i18n( $total ),
						size_format( $bytes, 1 )
					) . ( $this->skipped_thumbs[0] ? ' · ' . sprintf(
						/* translators: 1: thumbnail count, 2: size */
						__( '%1$s thumbnails skipped (%2$s)', 'lumos-backup' ),
						number_format_i18n( $this->skipped_thumbs[0] ),
						size_format( $this->skipped_thumbs[1], 1 )
					) : '' )
				);
			}
		}
	}

	/**
	 * Safely remove temporary backup working directory and files.
	 *
	 * @param string $dir Path to temp directory.
	 */
	private function cleanup_temp_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = scandir( $dir );
		if ( false !== $files ) {
			foreach ( $files as $file ) {
				if ( '.' === $file || '..' === $file ) {
					continue;
				}
				$path = $dir . '/' . $file;
				if ( is_dir( $path ) ) {
					$this->cleanup_temp_dir( $path );
				} else {
					@unlink( $path );
				}
			}
		}

		@rmdir( $dir );
	}

	/**
	 * Write wp-content/uploads/lumos-backup/recovery.md dead-server recovery kit.
	 *
	 * CRITICAL: Must NOT contain token, passphrase, or secret keys.
	 * Contains: archive id, sha256, bucket, key prefix, siteurl, and exact manual recovery steps.
	 *
	 * @param string $archive_id   Archive filename.
	 * @param string $sha256       SHA-256 checksum of archive.
	 * @param bool   $is_encrypted Whether the archive is encrypted.
	 * @return bool True if written successfully.
	 */
	public static function generate_recovery_sheet( $archive_id, $sha256 = '', $is_encrypted = false ) {
		$siteurl     = function_exists( 'site_url' ) ? site_url() : 'https://example.com';
		$s3_settings = class_exists( 'Lumos_Backup_S3' ) ? Lumos_Backup_S3::get_settings() : [];
		$bucket      = ! empty( $s3_settings['bucket'] ) ? $s3_settings['bucket'] : '[Not Configured]';
		$prefix      = ! empty( $s3_settings['prefix'] ) ? $s3_settings['prefix'] : '';
		$endpoint    = ! empty( $s3_settings['endpoint'] ) ? $s3_settings['endpoint'] : 'AWS S3 standard';
		$region      = ! empty( $s3_settings['region'] ) ? $s3_settings['region'] : 'us-east-1';

		$enc_note = $is_encrypted
			? "This archive is AES-256-CBC encrypted (`.zip.enc`). You will need the restore passphrase configured in WordPress to decrypt it."
			: "This archive is an unencrypted standard Zip archive (`.zip`).";

		$decrypt_cmd = $is_encrypted
			? "```bash\n# 2. Decrypt archive (OpenSSL PBKDF2 AES-256-CBC)\n# Extract 16-byte IV from first 16 bytes, then decrypt ciphertext:\n# If using Lumos restore script, lumos-restore.php handles this automatically upon passphrase entry.\n# Manual OpenSSL CLI example:\nopenssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 -in {$archive_id} -out backup.zip\n```\n"
			: "";

		$unzip_target = $is_encrypted ? 'backup.zip' : $archive_id;

		$content = <<<MD
# Lumos Backup - Dead-Server Recovery Kit

> **EMERGENCY RESTORATION RUNBOOK**  
> **Site URL:** `{$siteurl}`  
> **Archive ID:** `{$archive_id}`  
> **Archive SHA-256:** `{$sha256}`  
> **Generated (UTC):** Sat, Oct 5, 2026

---

## 1. Archive & Remote Storage Information

- **Primary Archive File:** `{$archive_id}`
- **Checksum (SHA-256):** `{$sha256}`
- **Sidecar Checksum File:** `{$archive_id}.sha256`
- **S3 Bucket:** `{$bucket}`
- **S3 Key Prefix:** `{$prefix}`
- **S3 Endpoint:** `{$endpoint}`
- **S3 Region:** `{$region}`
- **Encryption Status:** {$enc_note}

> **SECURITY NOTE:** This sheet does **NOT** contain any recovery tokens, encryption passphrases, database passwords, or S3 secret keys. Keep your credentials securely stored in your team password manager.

---

## 2. Emergency Recovery Steps When PHP / Server is Dead

When your server hardware fails, the operating system is corrupted, or PHP cannot boot, `lumos-restore.php` cannot run locally. Follow this manual runbook on a new server or recovery environment:

### Step 1: Download the Backup Object
Retrieve the backup file and its checksum from off-site storage (or local disk backup):

```bash
# Using AWS CLI (replace <bucket> and <prefix>):
aws s3 cp s3://{$bucket}/{$prefix}/{$archive_id} ./{$archive_id}
aws s3 cp s3://{$bucket}/{$prefix}/{$archive_id}.sha256 ./{$archive_id}.sha256
```

### Step 2: Verify Checksum (Integrity Check)
Verify that the downloaded file matches the expected SHA-256 checksum:

```bash
# macOS:
shasum -a 256 {$archive_id}

# Linux:
sha256sum {$archive_id}
```
Ensure the printed hash matches:
`{$sha256}`

### Step 3: Decrypt Archive (If Encrypted)
{$decrypt_cmd}
### Step 4: Extract the Archive
Extract the archive into a temporary recovery workspace:

```bash
mkdir -p /tmp/lumos-recovery
unzip {$unzip_target} -d /tmp/lumos-recovery
cd /tmp/lumos-recovery
```

You will find:
- `manifest.json`: Snapshot metadata, WordPress version, table prefix, and paths.
- `database.sql`: Batched MySQL database dump.
- `wp-content/`: Complete themes, plugins, uploads, and media files.

### Step 5: Import Database Dump
Import `database.sql` into your target MySQL/MariaDB database:

```bash
mysql -h <DB_HOST> -u <DB_USER> -p <DB_NAME> < database.sql
```

### Step 6: Restore `wp-content` Files
Copy the restored `wp-content/` directory into your WordPress installation document root:

```bash
cp -a /tmp/lumos-recovery/wp-content/* /path/to/wordpress/wp-content/
```
Ensure web server permissions:
```bash
chown -R www-data:www-data /path/to/wordpress/wp-content/
```

### Step 7: Accessing `lumos-restore.php`
- If the server and PHP environment are restored and online, you can also place `lumos-restore.php` in your web root alongside your configured `wp-content/uploads/lumos-backup/restore-config.json` to perform automated browser-based restoration with serialized-safe domain replacement.
- Visit: `https://{$siteurl}/lumos-restore.php?k=<YOUR_TOKEN>`

---
*Generated automatically by Lumos Backup.*
MD;

		$recovery_sheet_path = Lumos_Backup_Storage::get_recovery_sheet_path();
		Lumos_Backup_Storage::ensure_directories();
		return false !== @file_put_contents( $recovery_sheet_path, $content );
	}

	/**
	 * Record a backup failure event.
	 *
	 * @param string $message Error message.
	 */
	private function record_failure( $message ) {
		update_option(
			'lumos_backup_last',
			[
				'timestamp'    => time(),
				'datetime_utc' => gmdate( 'Y-m-d H:i:s' ),
				'status'       => 'error',
				'error'        => $message,
			]
		);
	}
}

