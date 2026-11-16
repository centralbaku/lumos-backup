<?php
/**
 * Standalone restore file and token gate manager for Lumos Backup.
 *
 * @package LumosBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lumos_Backup_Restore_Gate
 */
class Lumos_Backup_Restore_Gate {

	/**
	 * Option name storing the active restore secret token.
	 */
	const TOKEN_OPTION = 'lumos_backup_token';

	/**
	 * Option name storing the active encryption passphrase.
	 */
	const PASSPHRASE_OPTION = 'lumos_backup_passphrase';

	/**
	 * Option name storing encryption KDF metadata.
	 */
	const ENCRYPTION_META_OPTION = 'lumos_backup_encryption_meta';

	/**
	 * Retrieve the current secret restore token.
	 *
	 * If none exists, a new 32-byte token is generated and persisted.
	 *
	 * @return string Secret token string.
	 */
	public static function get_token() {
		$token = get_option( self::TOKEN_OPTION );
		if ( empty( $token ) || ! is_string( $token ) ) {
			$token = self::rotate_token();
		}
		return $token;
	}

	/**
	 * Retrieve current encryption passphrase (or empty string if disabled).
	 *
	 * @return string
	 */
	public static function get_passphrase() {
		$p = get_option( self::PASSPHRASE_OPTION, '' );
		return is_string( $p ) ? $p : '';
	}

	/**
	 * Retrieve encryption metadata (salt, iterations, password_hash).
	 *
	 * @return array
	 */
	public static function get_encryption_meta() {
		$defaults = [
			'enabled'       => false,
			'password_hash' => '',
			'kdf_salt'      => '',
			'iterations'    => 100000,
		];
		$meta = get_option( self::ENCRYPTION_META_OPTION, [] );
		return wp_parse_args( is_array( $meta ) ? $meta : [], $defaults );
	}

	/**
	 * Set or rotate the archive encryption passphrase.
	 *
	 * Rotation re-encrypts nothing; it applies to new archives only.
	 *
	 * @param string $passphrase New passphrase string or empty to disable encryption.
	 */
	public static function set_passphrase( $passphrase ) {
		$passphrase = trim( (string) $passphrase );

		if ( '' === $passphrase ) {
			delete_option( self::PASSPHRASE_OPTION );
			delete_option( self::ENCRYPTION_META_OPTION );
		} else {
			$salt = bin2hex( random_bytes( 16 ) );
			$meta = [
				'enabled'       => true,
				'password_hash' => password_hash( $passphrase, PASSWORD_DEFAULT ),
				'kdf_salt'      => $salt,
				'iterations'    => 100000,
			];
			update_option( self::PASSPHRASE_OPTION, $passphrase );
			update_option( self::ENCRYPTION_META_OPTION, $meta );
		}

		self::write_config_file();
	}

	/**
	 * Check if archive encryption is currently active.
	 *
	 * @return bool
	 */
	public static function is_encryption_enabled() {
		return '' !== self::get_passphrase();
	}

	/**
	 * Generate a new 32-byte cryptographic token and rewrite restore-config.json.
	 *
	 * @return string Newly generated secret token.
	 */
	public static function rotate_token() {
		// 32 bytes of secure random data encoded as 64 hex characters
		$token = bin2hex( random_bytes( 32 ) );

		update_option( self::TOKEN_OPTION, $token );

		// Rewrite restore-config.json with the new sha256 hash
		self::write_config_file( $token );

		return $token;
	}

	/**
	 * Get the full public emergency restore URL.
	 *
	 * @param string|null $token Optional token to use. Defaults to current stored token.
	 * @return string Public restore URL.
	 */
	public static function get_restore_url( $token = null ) {
		if ( null === $token ) {
			$token = self::get_token();
		}

		// ABSPATH/lumos-restore.php corresponds directly to site_url()
		$base_url = function_exists( 'site_url' ) ? site_url() : '';
		return trailingslashit( $base_url ) . 'lumos-restore.php?k=' . rawurlencode( $token );
	}

	/**
	 * Deploy the standalone restore script to ABSPATH/lumos-restore.php.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function deploy_restore_script() {
		$source = LUMOS_BACKUP_PATH . 'restore/restore.php';
		$target = Lumos_Backup_Storage::get_restore_script_path();

		if ( ! file_exists( $source ) || ! is_readable( $source ) ) {
			return false;
		}

		$copied = @copy( $source, $target );
		if ( $copied ) {
			@chmod( $target, 0644 );
			return true;
		}

		return false;
	}

	/**
	 * Write the standalone restore configuration to wp-content/uploads/lumos-backup/restore-config.json mode 0600.
	 *
	 * CRITICAL: The raw token is NEVER written to disk; only its sha256 hash is saved.
	 *
	 * @param string|null $token Secret token to hash. If null, stored token is used.
	 * @return bool True on success, false on failure.
	 */
	public static function write_config_file( $token = null ) {
		global $wpdb;

		if ( null === $token ) {
			$token = self::get_token();
		}

		Lumos_Backup_Storage::ensure_directories();

		$token_hash = hash( 'sha256', $token );

		// Retrieve S3 settings if available
		$s3_settings = class_exists( 'Lumos_Backup_S3' ) ? Lumos_Backup_S3::get_settings() : [];

		// Retrieve encryption metadata (NEVER store raw passphrase in config!)
		$enc_meta = self::get_encryption_meta();

		$config = [
			'abspath'     => rtrim( ABSPATH, '/\\' ),
			'content_dir' => rtrim( WP_CONTENT_DIR, '/\\' ),
			'archive_dir' => rtrim( Lumos_Backup_Storage::get_archives_dir(), '/\\' ),
			'db_host'     => defined( 'DB_HOST' ) ? DB_HOST : 'localhost',
			'db_name'     => defined( 'DB_NAME' ) ? DB_NAME : '',
			'db_user'     => defined( 'DB_USER' ) ? DB_USER : '',
			'db_password' => defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '',
			'db_prefix'   => isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_',
			'db_charset'  => defined( 'DB_CHARSET' ) ? DB_CHARSET : 'utf8mb4',
			'siteurl'     => function_exists( 'site_url' ) ? site_url() : '',
			'home'        => function_exists( 'home_url' ) ? home_url() : '',
			'token_hash'  => $token_hash,
			'encryption'  => [
				'enabled'       => ! empty( $enc_meta['enabled'] ),
				'password_hash' => $enc_meta['password_hash'] ?? '',
				'kdf_salt'      => $enc_meta['kdf_salt'] ?? '',
				'iterations'    => (int) ( $enc_meta['iterations'] ?? 100000 ),
			],
			's3'          => [
				'endpoint'   => $s3_settings['endpoint'] ?? '',
				'region'     => ! empty( $s3_settings['region'] ) ? $s3_settings['region'] : 'us-east-1',
				'bucket'     => $s3_settings['bucket'] ?? '',
				'access_key' => $s3_settings['access_key'] ?? '',
				'secret_key' => $s3_settings['secret_key'] ?? '',
				'prefix'     => $s3_settings['prefix'] ?? '',
			],
			'updated_utc' => gmdate( 'Y-m-d H:i:s' ),
		];

		$json_data   = json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$config_path = Lumos_Backup_Storage::get_config_path();

		$written = @file_put_contents( $config_path, $json_data );
		if ( false !== $written ) {
			// Mode 0600 as required: read/write by owner only
			@chmod( $config_path, 0600 );
			return true;
		}

		return false;
	}

	/**
	 * Verify if the standalone restore file exists and is readable.
	 *
	 * @return bool
	 */
	public static function is_restore_script_ready() {
		$target = Lumos_Backup_Storage::get_restore_script_path();
		return file_exists( $target ) && is_readable( $target );
	}

	/**
	 * Verify if restore-config.json exists and is readable.
	 *
	 * @return bool
	 */
	public static function is_config_file_ready() {
		$path = Lumos_Backup_Storage::get_config_path();
		return file_exists( $path ) && is_readable( $path );
	}
}
