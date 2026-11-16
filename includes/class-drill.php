<?php
/**
 * Standalone Restore Drill Runner for Lumos Backup.
 *
 * Runs backup verification drills in an isolated sandbox without touching
 * the live database or live wp-content files.
 *
 * @package LumosBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lumos_Backup_Drill
 */
class Lumos_Backup_Drill {

	/**
	 * Run a drill on the latest backup or a specific target backup archive.
	 *
	 * 1. Find or download target/latest backup archive.
	 * 2. Decrypt if needed, verify sha256 checksum against sidecar.
	 * 3. Extract into wp-content/uploads/lumos-backup/drill/<id>/.
	 * 4. Test database import into <db>_lumos_drill or temporary tables lumos_drill_*.
	 * 5. Verify manifest.json, SQL import, wp-content/themes and wp-content/plugins existence.
	 * 6. Record result in lumos_backup_last_drill.
	 *
	 * @param string|null $target_archive_id Optional specific archive filename to test.
	 * @return array Drill result array.
	 */
	public static function run_drill( $target_archive_id = null ) {
		@set_time_limit( 600 );
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$start_time = time();

		try {
			if ( ! class_exists( 'ZipArchive' ) ) {
				throw new Exception( __( 'PHP ZipArchive extension is required to perform a restore drill.', 'lumos-backup' ) );
			}

			// 1. Locate the archive (specific target or latest)
			if ( ! empty( $target_archive_id ) ) {
				$latest_archive = self::find_archive( $target_archive_id );
				if ( ! $latest_archive ) {
					throw new Exception( sprintf( __( 'Specified backup archive not found: %s', 'lumos-backup' ), $target_archive_id ) );
				}
			} else {
				$latest_archive = self::get_latest_archive();
				if ( ! $latest_archive ) {
					throw new Exception( __( 'No backup archives found to test.', 'lumos-backup' ) );
				}
			}

			$archive_id   = $latest_archive['filename'];
			$is_remote    = ( 'remote' === ( $latest_archive['origin'] ?? 'local' ) );
			$is_encrypted = ( substr( $archive_id, -4 ) === '.enc' );

			// Drill directories
			$drill_base = Lumos_Backup_Storage::get_base_dir() . '/drill';
			$drill_dir  = $drill_base . '/' . $archive_id;

			// If previous drill folder exists for this ID, remove it first
			if ( file_exists( $drill_dir ) ) {
				self::recursive_rmdir( $drill_dir );
			}

			if ( ! wp_mkdir_p( $drill_dir ) ) {
				throw new Exception( sprintf( __( 'Could not create drill directory: %s', 'lumos-backup' ), $drill_dir ) );
			}

			// Download remote archive if not local
			$archive_file_path = '';
			$sidecar_file_path = '';
			$temp_download_dir = null;

			if ( $is_remote ) {
				$temp_download_dir = Lumos_Backup_Storage::get_base_dir() . '/tmp-drill-dl-' . bin2hex( random_bytes( 6 ) );
				wp_mkdir_p( $temp_download_dir );
				$archive_file_path = $temp_download_dir . '/' . $archive_id;
				$sidecar_file_path = $archive_file_path . '.sha256';

				// Download archive
				$s3_key = $latest_archive['key'] ?? $archive_id;
				$dl_res = self::download_s3_file( $s3_key, $archive_file_path );
				if ( is_wp_error( $dl_res ) ) {
					throw new Exception( sprintf( __( 'Failed downloading remote archive from S3: %s', 'lumos-backup' ), $dl_res->get_error_message() ) );
				}

				// Download sidecar if present
				self::download_s3_file( $s3_key . '.sha256', $sidecar_file_path );
			} else {
				$archive_file_path = Lumos_Backup_Storage::get_archives_dir() . '/' . $archive_id;
				$sidecar_file_path = $archive_file_path . '.sha256';
			}

			if ( ! file_exists( $archive_file_path ) || ! is_readable( $archive_file_path ) ) {
				throw new Exception( sprintf( __( 'Archive file not accessible: %s', 'lumos-backup' ), $archive_id ) );
			}

			// 2. Verify SHA-256 Checksum against sidecar
			if ( file_exists( $sidecar_file_path ) && is_readable( $sidecar_file_path ) ) {
				$expected_sha256 = trim( (string) file_get_contents( $sidecar_file_path ) );
				$actual_sha256   = hash_file( 'sha256', $archive_file_path );

				if ( ! empty( $expected_sha256 ) && ! hash_equals( strtolower( $expected_sha256 ), strtolower( $actual_sha256 ) ) ) {
					throw new Exception( sprintf(
						__( 'SHA-256 checksum mismatch for %s. Expected: %s, Actual: %s.', 'lumos-backup' ),
						$archive_id,
						$expected_sha256,
						$actual_sha256
					) );
				}
			}

			// 3. Decrypt if encrypted (.zip.enc)
			$zip_to_extract = $archive_file_path;
			$temp_decrypted_file = null;

			if ( $is_encrypted ) {
				$passphrase = Lumos_Backup_Restore_Gate::get_passphrase();
				if ( empty( $passphrase ) ) {
					throw new Exception( __( 'Encryption passphrase is required to test encrypted backup archive.', 'lumos-backup' ) );
				}

				$enc_meta   = Lumos_Backup_Restore_Gate::get_encryption_meta();
				$kdf_salt   = $enc_meta['kdf_salt'] ?? '';
				$iterations = (int) ( $enc_meta['iterations'] ?? 100000 );

				$temp_decrypted_file = $drill_dir . '/decrypted-' . bin2hex( random_bytes( 6 ) ) . '.zip';
				$decrypted = self::decrypt_archive_file(
					$archive_file_path,
					$temp_decrypted_file,
					$passphrase,
					$kdf_salt,
					$iterations
				);

				if ( ! $decrypted || ! file_exists( $temp_decrypted_file ) ) {
					throw new Exception( __( 'Failed to decrypt backup archive. Invalid passphrase or corrupted data.', 'lumos-backup' ) );
				}

				$zip_to_extract = $temp_decrypted_file;
			}

			// 4. Extract into drill directory
			$zip = new ZipArchive();
			$opened = $zip->open( $zip_to_extract, ZipArchive::CHECKCONS );
			if ( true !== $opened ) {
				throw new Exception( sprintf( __( 'Could not open zip archive (error code %d).', 'lumos-backup' ), $opened ) );
			}

			$zip->extractTo( $drill_dir );
			$zip->close();

			// Clean up decrypted temp zip if used
			if ( $temp_decrypted_file && file_exists( $temp_decrypted_file ) ) {
				@unlink( $temp_decrypted_file );
			}

			// Clean up remote download directory if used
			if ( $temp_download_dir && file_exists( $temp_download_dir ) ) {
				self::recursive_rmdir( $temp_download_dir );
			}

			// 5. Verification Check: Parse manifest.json
			$manifest_file = $drill_dir . '/manifest.json';
			if ( ! file_exists( $manifest_file ) || ! is_readable( $manifest_file ) ) {
				throw new Exception( __( 'manifest.json missing from backup archive.', 'lumos-backup' ) );
			}

			$manifest_raw  = file_get_contents( $manifest_file );
			$manifest_data = json_decode( (string) $manifest_raw, true );
			if ( ! is_array( $manifest_data ) || empty( $manifest_data['id'] ) ) {
				throw new Exception( __( 'manifest.json is invalid or corrupted.', 'lumos-backup' ) );
			}

			// 6. Verification Check: wp-content/themes and wp-content/plugins exist in drill copy
			$themes_dir  = $drill_dir . '/wp-content/themes';
			$plugins_dir = $drill_dir . '/wp-content/plugins';

			if ( ! is_dir( $themes_dir ) ) {
				throw new Exception( __( 'Verification failed: wp-content/themes directory does not exist in drill copy.', 'lumos-backup' ) );
			}

			if ( ! is_dir( $plugins_dir ) ) {
				throw new Exception( __( 'Verification failed: wp-content/plugins directory does not exist in drill copy.', 'lumos-backup' ) );
			}

			// 7. Verification Check: Import SQL into isolated sandbox
			$sql_file = $drill_dir . '/database.sql';
			if ( ! file_exists( $sql_file ) || ! is_readable( $sql_file ) ) {
				throw new Exception( __( 'database.sql missing from backup archive.', 'lumos-backup' ) );
			}

			$db_drill_result = self::execute_isolated_sql_drill( $sql_file );
			if ( is_wp_error( $db_drill_result ) ) {
				throw new Exception( $db_drill_result->get_error_message() );
			}

			// Drill Passed!
			$drill_record = [
				'time'         => time(),
				'datetime_utc' => gmdate( 'Y-m-d H:i:s' ),
				'archive_id'   => $archive_id,
				'status'       => 'pass',
				'error'        => '',
				'details'      => [
					'db_method'   => $db_drill_result['method'] ?? 'unknown',
					'statements'  => $db_drill_result['statements'] ?? 0,
					'manifest_id' => $manifest_data['id'],
					'siteurl'     => $manifest_data['siteurl'] ?? '',
				],
			];

			update_option( 'lumos_backup_last_drill', $drill_record );
			do_action( 'lumos_backup_drill_passed', $drill_record );
			return $drill_record;

		} catch ( Exception $e ) {
			// Record failure
			$drill_record = [
				'time'         => time(),
				'datetime_utc' => gmdate( 'Y-m-d H:i:s' ),
				'archive_id'   => isset( $archive_id ) ? $archive_id : 'unknown',
				'status'       => 'fail',
				'error'        => $e->getMessage(),
			];

			update_option( 'lumos_backup_last_drill', $drill_record );
			do_action( 'lumos_backup_drill_failed', $drill_record );
			return $drill_record;
		}
	}

	/**
	 * Find a specific archive by filename (checks local first, then remote).
	 *
	 * @param string $archive_id Target archive filename.
	 * @return array|null Archive info array or null.
	 */
	public static function find_archive( $archive_id ) {
		$local_archives = Lumos_Backup_Storage::get_archives();
		foreach ( $local_archives as $la ) {
			if ( $la['filename'] === $archive_id ) {
				$la['origin'] = 'local';
				return $la;
			}
		}

		if ( class_exists( 'Lumos_Backup_S3' ) && Lumos_Backup_S3::is_configured() ) {
			$remote = Lumos_Backup_S3::list_objects();
			if ( ! is_wp_error( $remote ) && is_array( $remote ) ) {
				foreach ( $remote as $ra ) {
					if ( $ra['filename'] === $archive_id ) {
						$ra['origin'] = 'remote';
						return $ra;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Get latest backup archive (checks local first, then remote).
	 *
	 * @return array|null Archive info array or null.
	 */
	public static function get_latest_archive() {
		$local_archives = Lumos_Backup_Storage::get_archives();
		$combined       = [];

		foreach ( $local_archives as $la ) {
			$la['origin'] = 'local';
			$combined[]   = $la;
		}

		if ( class_exists( 'Lumos_Backup_S3' ) && Lumos_Backup_S3::is_configured() ) {
			$remote = Lumos_Backup_S3::list_objects();
			if ( ! is_wp_error( $remote ) && is_array( $remote ) ) {
				foreach ( $remote as $ra ) {
					$ra['origin'] = 'remote';
					$combined[]   = $ra;
				}
			}
		}

		if ( empty( $combined ) ) {
			return null;
		}

		usort(
			$combined,
			function ( $a, $b ) {
				return ( $b['mtime'] ?? 0 ) <=> ( $a['mtime'] ?? 0 );
			}
		);

		return $combined[0];
	}

	/**
	 * Execute SQL import in an isolated temporary database or prefixed temporary tables.
	 *
	 * Strategy:
	 * 1. Try to CREATE DATABASE `<db>_lumos_drill`. If permitted, import there and drop on cleanup.
	 * 2. If CREATE DATABASE fails / lacks privilege, rewrite table creation and inserts in memory/stream
	 *    to use `lumos_drill_` prefix, import into current database, and DROP TABLE `lumos_drill_*` after.
	 *
	 * @param string $sql_file Path to extracted database.sql.
	 * @return array|WP_Error Stats array on success, WP_Error on failure.
	 */
	private static function execute_isolated_sql_drill( $sql_file ) {
		$db_host   = defined( 'DB_HOST' ) ? DB_HOST : 'localhost';
		$db_user   = defined( 'DB_USER' ) ? DB_USER : '';
		$db_pass   = defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '';
		$live_name = defined( 'DB_NAME' ) ? DB_NAME : '';
		$db_port   = 3306;
		$db_socket = null;

		if ( strpos( $db_host, ':' ) !== false ) {
			$parts   = explode( ':', $db_host, 2 );
			$db_host = $parts[0];
			if ( is_numeric( $parts[1] ) ) {
				$db_port = (int) $parts[1];
			} else {
				$db_socket = $parts[1];
			}
		}

		$mysqli = @new mysqli( $db_host, $db_user, $db_pass, '', $db_port, $db_socket );
		if ( $mysqli->connect_error ) {
			// Connect to live DB directly if blank database connect is refused
			$mysqli = @new mysqli( $db_host, $db_user, $db_pass, $live_name, $db_port, $db_socket );
		}

		if ( $mysqli->connect_error ) {
			return new WP_Error( 'db_connect_failed', sprintf( __( 'Database connection failed: %s', 'lumos-backup' ), $mysqli->connect_error ) );
		}

		$charset = defined( 'DB_CHARSET' ) && DB_CHARSET ? DB_CHARSET : 'utf8mb4';
		@$mysqli->set_charset( $charset );

		$drill_dbname = $live_name . '_lumos_drill';
		$can_create_db = false;

		// Attempt to create isolated test database: <db>_lumos_drill
		$prev_err = mysqli_report( MYSQLI_REPORT_OFF );
		if ( @$mysqli->query( "CREATE DATABASE IF NOT EXISTS `{$drill_dbname}`" ) ) {
			if ( @$mysqli->select_db( $drill_dbname ) ) {
				// Verify user also has CREATE/INSERT privileges in this new database
				if ( @$mysqli->query( "CREATE TABLE IF NOT EXISTS `lumos_drill_test` (id INT)" ) ) {
					if ( @$mysqli->query( "INSERT INTO `lumos_drill_test` VALUES (1)" ) ) {
						$can_create_db = true;
					}
					@$mysqli->query( "DROP TABLE IF EXISTS `lumos_drill_test`" );
				}
			}
			if ( ! $can_create_db ) {
				@$mysqli->query( "DROP DATABASE IF EXISTS `{$drill_dbname}`" );
			}
		}

		if ( $can_create_db ) {
			try {
				$import_stats = self::run_sql_statements( $mysqli, $sql_file );
				if ( $import_stats['errors'] > 0 ) {
					$first_err = ! empty( $import_stats['details'][0] ) ? $import_stats['details'][0] : 'Unknown error';
					throw new Exception( sprintf( __( 'SQL drill import encountered errors: %s', 'lumos-backup' ), $first_err ) );
				}

				return [
					'method'     => 'isolated_database',
					'database'   => $drill_dbname,
					'statements' => $import_stats['statements'],
				];

			} finally {
				// Always drop drill database after drill
				@$mysqli->query( "DROP DATABASE IF EXISTS `{$drill_dbname}`" );
				$mysqli->close();
			}
		} else {
			// Fallback: DB user cannot create databases.
			// Import into current database using temporary table prefix `lumos_drill_`, and drop after.
			if ( ! @$mysqli->select_db( $live_name ) ) {
				$mysqli->close();
				return new WP_Error( 'db_select_failed', __( 'Could not select current WordPress database.', 'lumos-backup' ) );
			}

			global $wpdb;
			$orig_prefix  = isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';
			$drill_prefix = 'lumos_drill_';
			$created_tables = [];

			try {
				$import_stats = self::run_sql_statements( $mysqli, $sql_file, $orig_prefix, $drill_prefix, $created_tables );
				if ( $import_stats['errors'] > 0 ) {
					$first_err = ! empty( $import_stats['details'][0] ) ? $import_stats['details'][0] : 'Unknown error';
					throw new Exception( sprintf( __( 'SQL drill import encountered errors: %s', 'lumos-backup' ), $first_err ) );
				}

				return [
					'method'     => 'prefixed_tables',
					'prefix'     => $drill_prefix,
					'statements' => $import_stats['statements'],
				];

			} finally {
				// Drop all created temporary tables
				foreach ( $created_tables as $tbl ) {
					@$mysqli->query( "DROP TABLE IF EXISTS `{$tbl}`" );
				}
				// Also drop any tables beginning with lumos_drill_ just in case
				$res = @$mysqli->query( "SHOW TABLES LIKE '{$drill_prefix}%'" );
				if ( $res ) {
					while ( $row = $res->fetch_array() ) {
						if ( ! empty( $row[0] ) ) {
							@$mysqli->query( "DROP TABLE IF EXISTS `{$row[0]}`" );
						}
					}
					$res->free();
				}
				$mysqli->close();
			}
		}
	}

	/**
	 * Parse and execute SQL file statements against mysqli.
	 *
	 * Optionally replaces $orig_prefix with $drill_prefix for table names and records created tables.
	 *
	 * @param mysqli      $mysqli         Active connection.
	 * @param string      $sql_file       Path to SQL dump.
	 * @param string|null $orig_prefix    Original table prefix (optional).
	 * @param string|null $drill_prefix   Drill table prefix (optional).
	 * @param array       $created_tables Output array to accumulate created table names.
	 * @return array [ 'statements' => int, 'errors' => int, 'details' => array ]
	 */
	private static function run_sql_statements( mysqli $mysqli, $sql_file, $orig_prefix = null, $drill_prefix = null, array &$created_tables = [] ) {
		$fp = @fopen( $sql_file, 'r' );
		if ( ! $fp ) {
			return [ 'statements' => 0, 'errors' => 1, 'details' => [ 'Could not open SQL file' ] ];
		}

		$statement_count = 0;
		$error_count     = 0;
		$errors          = [];

		$query                = '';
		$in_single_quote      = false;
		$in_double_quote      = false;
		$in_backtick          = false;
		$in_multiline_comment = false;
		$current_delimiter    = ';';

		$replace_prefix = ( ! empty( $orig_prefix ) && ! empty( $drill_prefix ) );

		while ( ! feof( $fp ) ) {
			$line = fgets( $fp );
			if ( false === $line ) {
				break;
			}

			// Handle DELIMITER directive or single-line comments outside quotes and multiline comments
			if ( ! $in_single_quote && ! $in_double_quote && ! $in_backtick && ! $in_multiline_comment ) {
				$trimmed = trim( $line );
				if ( preg_match( '/^DELIMITER\s+(\S+)$/i', $trimmed, $delim_matches ) ) {
					$current_delimiter = $delim_matches[1];
					continue;
				}

				$ltrimmed = ltrim( $line );
				if ( strpos( $ltrimmed, '-- ' ) === 0 || $ltrimmed === "--\n" || $ltrimmed === "--\r\n" || strpos( $ltrimmed, '#' ) === 0 ) {
					continue;
				}
			}

			$len = strlen( $line );
			$i   = 0;

			while ( $i < $len ) {
				$char      = $line[ $i ];
				$next_char = ( $i + 1 < $len ) ? $line[ $i + 1 ] : '';

				if ( ! $in_single_quote && ! $in_double_quote && ! $in_backtick ) {
					if ( ! $in_multiline_comment && '/' === $char && '*' === $next_char ) {
						$in_multiline_comment = true;
						$i += 2;
						continue;
					}
					if ( $in_multiline_comment && '*' === $char && '/' === $next_char ) {
						$in_multiline_comment = false;
						$i += 2;
						continue;
					}
					if ( $in_multiline_comment ) {
						$i++;
						continue;
					}
					if ( '-' === $char && '-' === $next_char && ( $i + 2 >= $len || ' ' === $line[ $i + 2 ] || "\t" === $line[ $i + 2 ] || "\r" === $line[ $i + 2 ] || "\n" === $line[ $i + 2 ] ) ) {
						break;
					}
					if ( '#' === $char ) {
						break;
					}
				}

				if ( $in_multiline_comment ) {
					$i++;
					continue;
				}

				if ( '\\' === $char && ( $in_single_quote || $in_double_quote ) ) {
					$query .= $char;
					if ( $i + 1 < $len ) {
						$query .= $line[ $i + 1 ];
						$i += 2;
						continue;
					}
					$i++;
					continue;
				}

				if ( "'" === $char && ! $in_double_quote && ! $in_backtick ) {
					$in_single_quote = ! $in_single_quote;
				} elseif ( '"' === $char && ! $in_single_quote && ! $in_backtick ) {
					$in_double_quote = ! $in_double_quote;
				} elseif ( '`' === $char && ! $in_single_quote && ! $in_double_quote ) {
					$in_backtick = ! $in_backtick;
				}

				$delim_len = strlen( $current_delimiter );
				if ( ! $in_single_quote && ! $in_double_quote && ! $in_backtick && substr( $line, $i, $delim_len ) === $current_delimiter ) {
					$sql = trim( $query );
					if ( '' !== $sql ) {
						if ( $replace_prefix ) {
							$sql = self::replace_table_prefix_in_sql( $sql, $orig_prefix, $drill_prefix, $created_tables );
						}

						if ( ! @$mysqli->query( $sql ) ) {
							$error_count++;
							if ( count( $errors ) < 5 ) {
								$errors[] = $mysqli->error . ' [SQL: ' . substr( $sql, 0, 80 ) . '...]';
							}
						} else {
							$statement_count++;
						}
					}
					$query = '';
					$i    += $delim_len;
					continue;
				}

				$query .= $char;
				$i++;
			}
		}

		$sql = trim( $query );
		if ( '' !== $sql ) {
			if ( $replace_prefix ) {
				$sql = self::replace_table_prefix_in_sql( $sql, $orig_prefix, $drill_prefix, $created_tables );
			}
			if ( ! @$mysqli->query( $sql ) ) {
				$error_count++;
				if ( count( $errors ) < 5 ) {
					$errors[] = $mysqli->error;
				}
			} else {
				$statement_count++;
			}
		}

		fclose( $fp );

		return [
			'statements' => $statement_count,
			'errors'     => $error_count,
			'details'    => $errors,
		];
	}

	/**
	 * Replace table prefix in DDL and DML statements and track created table names.
	 *
	 * @param string $sql            SQL statement.
	 * @param string $orig_prefix    Original prefix (e.g. wp_).
	 * @param string $drill_prefix   Drill prefix (e.g. lumos_drill_).
	 * @param array  $created_tables Accumulated array of created table names.
	 * @return string Rewritten SQL statement.
	 */
	private static function replace_table_prefix_in_sql( $sql, $orig_prefix, $drill_prefix, array &$created_tables ) {
		// Detect table creation
		if ( preg_match( '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?' . preg_quote( $orig_prefix, '/' ) . '([a-zA-Z0-9_]+)[`"]?/i', $sql, $m ) ) {
			$created_tables[] = $drill_prefix . $m[1];
		}

		// Replace backtick quoted table names: `wp_users` -> `lumos_drill_users`
		$pattern = '/`' . preg_quote( $orig_prefix, '/' ) . '([a-zA-Z0-9_]+)`/';
		$replaced = preg_replace( $pattern, '`' . $drill_prefix . '$1`', $sql );

		return $replaced ?: $sql;
	}

	/**
	 * Decrypt archive file.
	 *
	 * @param string $enc_path   Path to ciphertext.
	 * @param string $dest_path  Path for decrypted zip.
	 * @param string $passphrase Passphrase.
	 * @param string $kdf_salt   Hex salt.
	 * @param int    $iterations Iterations.
	 * @return bool True on success.
	 */
	private static function decrypt_archive_file( $enc_path, $dest_path, $passphrase, $kdf_salt, $iterations = 100000 ) {
		$key = hash_pbkdf2( 'sha256', $passphrase, hex2bin( $kdf_salt ), $iterations, 32, true );
		$raw = @file_get_contents( $enc_path );
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return false;
		}

		$iv         = substr( $raw, 0, 16 );
		$ciphertext = substr( $raw, 16 );
		$plaintext  = openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		unset( $ciphertext, $raw );

		if ( false === $plaintext ) {
			return false;
		}

		$written = @file_put_contents( $dest_path, $plaintext );
		unset( $plaintext );

		return false !== $written;
	}

	/**
	 * Download a file from remote S3.
	 *
	 * @param string $key       S3 key.
	 * @param string $dest_file Destination path on disk.
	 * @return true|WP_Error True or WP_Error.
	 */
	private static function download_s3_file( $key, $dest_file ) {
		if ( ! class_exists( 'Lumos_Backup_S3' ) || ! Lumos_Backup_S3::is_configured() ) {
			return new WP_Error( 's3_not_configured', __( 'Remote S3 storage is not configured.', 'lumos-backup' ) );
		}

		$url_parts      = Lumos_Backup_S3::build_s3_url_parts( $key );
		$signed_headers = Lumos_Backup_S3::sign_request( 'GET', $url_parts );

		$response = wp_remote_request(
			$url_parts['url'],
			[
				'method'    => 'GET',
				'headers'   => $signed_headers,
				'timeout'   => 300,
				'sslverify' => true,
				'stream'    => true,
				'filename'  => $dest_file,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		return new WP_Error( 's3_download_failed', sprintf( __( 'S3 returned HTTP %d', 'lumos-backup' ), $code ) );
	}

	/**
	 * Recursively remove a directory and its contents.
	 *
	 * @param string $dir Path to directory.
	 */
	public static function recursive_rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				self::recursive_rmdir( $path );
			} else {
				@unlink( $path );
			}
		}

		@rmdir( $dir );
	}
}
