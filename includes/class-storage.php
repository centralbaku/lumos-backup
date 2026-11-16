<?php
/**
 * Storage and file system management for Lumos Backup.
 *
 * @package LumosBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lumos_Backup_Storage
 */
class Lumos_Backup_Storage {

	/**
	 * Regex pattern for backup filenames (supports plain .zip and encrypted .zip.enc).
	 */
	const ARCHIVE_PATTERN = '/^backup-\d{8}-\d{6}\.zip(\.enc)?$/';

	/**
	 * Get the base directory for Lumos Backup files.
	 *
	 * Default: wp-content/uploads/lumos-backup
	 *
	 * @return string
	 */
	public static function get_base_dir() {
		$upload_dir = wp_upload_dir();
		return untrailingslashit( $upload_dir['basedir'] ) . '/lumos-backup';
	}

	/**
	 * Get the archives directory.
	 *
	 * Default: wp-content/uploads/lumos-backup/archives
	 *
	 * @return string
	 */
	public static function get_archives_dir() {
		return self::get_base_dir() . '/archives';
	}

	/**
	 * Get path to the restore-config.json file.
	 *
	 * @return string
	 */
	public static function get_config_path() {
		return self::get_base_dir() . '/restore-config.json';
	}

	/**
	 * Get path to the recovery.md dead-server kit file.
	 *
	 * @return string
	 */
	public static function get_recovery_sheet_path() {
		return self::get_base_dir() . '/recovery.md';
	}

	/**
	 * Get path to the backup.lock file.
	 *
	 * @return string
	 */
	public static function get_lock_path() {
		return self::get_base_dir() . '/backup.lock';
	}

	/**
	 * Get the destination path for the standalone restore script in WordPress root.
	 *
	 * @return string
	 */
	public static function get_restore_script_path() {
		return trailingslashit( ABSPATH ) . 'lumos-restore.php';
	}

	/**
	 * Ensure backup and archive directories exist and are protected against direct HTTP access.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function ensure_directories() {
		$base_dir     = self::get_base_dir();
		$archives_dir = self::get_archives_dir();

		if ( ! file_exists( $base_dir ) ) {
			wp_mkdir_p( $base_dir );
		}

		if ( ! file_exists( $archives_dir ) ) {
			wp_mkdir_p( $archives_dir );
		}

		// Protect base directory
		self::protect_directory( $base_dir, true );

		// Protect archives directory
		self::protect_directory( $archives_dir, false );

		return is_dir( $base_dir ) && is_dir( $archives_dir );
	}

	/**
	 * Write .htaccess and blank index.php to prevent directory indexing and direct HTTP access.
	 *
	 * @param string $dir             Target directory path.
	 * @param bool   $protect_config  Whether to specifically block restore-config.json as well.
	 */
	private static function protect_directory( $dir, $protect_config = false ) {
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return;
		}

		// 1. Blank index.php
		$index_file = untrailingslashit( $dir ) . '/index.php';
		if ( ! file_exists( $index_file ) ) {
			@file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}

		// 2. .htaccess
		$htaccess_file = untrailingslashit( $dir ) . '/.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			if ( $protect_config ) {
				$rules = "# Deny access to restore-config.json and prevent indexing\n" .
					"<Files \"restore-config.json\">\n" .
					"  Order allow,deny\n" .
					"  Deny from all\n" .
					"</Files>\n" .
					"<IfModule mod_authz_core.c>\n" .
					"  <Files \"restore-config.json\">\n" .
					"    Require all denied\n" .
					"  </Files>\n" .
					"</IfModule>\n" .
					"Options -Indexes\n";
			} else {
				$rules = "# Deny direct access to backup archives\n" .
					"<IfModule !mod_authz_core.c>\n" .
					"  Order allow,deny\n" .
					"  Deny from all\n" .
					"</IfModule>\n" .
					"<IfModule mod_authz_core.c>\n" .
					"  Require all denied\n" .
					"</IfModule>\n" .
					"Options -Indexes\n";
			}
			@file_put_contents( $htaccess_file, $rules );
		}
	}

	/**
	 * Validate if a given filename matches backup-YYYYMMDD-HHMMSS.zip.
	 *
	 * @param string $filename
	 * @return bool
	 */
	public static function is_valid_archive_name( $filename ) {
		return (bool) preg_match( self::ARCHIVE_PATTERN, $filename );
	}

	/**
	 * Get all backup archives, ordered newest first.
	 *
	 * @return array Array of archive details.
	 */
	public static function get_archives() {
		$archives_dir = self::get_archives_dir();
		$archives     = [];

		if ( ! is_dir( $archives_dir ) || ! is_readable( $archives_dir ) ) {
			return $archives;
		}

		$files = scandir( $archives_dir );
		if ( false === $files ) {
			return $archives;
		}

		foreach ( $files as $file ) {
			if ( ! self::is_valid_archive_name( $file ) ) {
				continue;
			}

			$path = $archives_dir . '/' . $file;
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}

			$size      = filesize( $path );
			$mtime     = filemtime( $path );
			$created   = self::parse_date_from_filename( $file, $mtime );
			$is_enc    = substr( $file, -4 ) === '.enc';

			// Read sha256 sidecar if present
			$sha256 = '';
			$sidecar_path = $path . '.sha256';
			if ( file_exists( $sidecar_path ) && is_readable( $sidecar_path ) ) {
				$sha256 = trim( (string) file_get_contents( $sidecar_path ) );
			}

			$archives[] = [
				'filename'       => $file,
				'path'           => $path,
				'size'           => $size,
				'size_formatted' => size_format( $size, 2 ),
				'mtime'          => $mtime,
				'created_utc'    => $created,
				'is_encrypted'   => $is_enc,
				'sha256'         => $sha256,
			];
		}

		// Sort newest first by mtime descending
		usort(
			$archives,
			function ( $a, $b ) {
				return $b['mtime'] <=> $a['mtime'];
			}
		);

		return $archives;
	}

	/**
	 * Extract datetime from filename or fallback to filemtime.
	 *
	 * @param string $filename
	 * @param int    $fallback_timestamp
	 * @return string UTC formatted date YYYY-MM-DD HH:MM:SS
	 */
	public static function parse_date_from_filename( $filename, $fallback_timestamp ) {
		if ( preg_match( '/^backup-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})\.zip/', $filename, $matches ) ) {
			return sprintf( '%s-%s-%s %s:%s:%s', $matches[1], $matches[2], $matches[3], $matches[4], $matches[5], $matches[6] );
		}
		return gmdate( 'Y-m-d H:i:s', $fallback_timestamp );
	}

	/**
	 * Purge archives keeping only the newest $keep_count archives.
	 *
	 * @param int $keep_count Maximum number of archives to keep.
	 * @return int Number of archives deleted.
	 */
	public static function purge_old_archives( $keep_count = 7 ) {
		$keep_count = max( 1, (int) $keep_count );
		$archives   = self::get_archives();

		if ( count( $archives ) <= $keep_count ) {
			return 0;
		}

		$deleted = 0;
		$to_delete = array_slice( $archives, $keep_count );

		foreach ( $to_delete as $archive ) {
			if ( self::delete_archive( $archive['filename'] ) ) {
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * Delete a specific archive file and its sha256 sidecar.
	 *
	 * @param string $filename Archive filename.
	 * @return bool True if deleted, false otherwise.
	 */
	public static function delete_archive( $filename ) {
		if ( ! self::is_valid_archive_name( $filename ) ) {
			return false;
		}

		$path    = self::get_archives_dir() . '/' . $filename;
		$sidecar = $path . '.sha256';

		if ( file_exists( $sidecar ) ) {
			@unlink( $sidecar );
		}

		if ( file_exists( $path ) ) {
			return @unlink( $path );
		}

		return false;
	}
}
