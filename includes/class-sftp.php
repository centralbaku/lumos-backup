<?php
/**
 * SFTP remote storage manager using PHP cURL SFTP protocol.
 *
 * Provides off-site backup storage over SSH/SFTP without external dependencies.
 *
 * @package LumosBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lumos_Backup_SFTP
 */
class Lumos_Backup_SFTP {

	/**
	 * WordPress option name.
	 */
	const OPTION_NAME = 'lumos_backup_sftp';

	/**
	 * Retrieve saved SFTP settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = [
			'host'     => '',
			'port'     => 22,
			'username' => '',
			'password' => '',
			'path'     => '/backups',
		];

		$saved = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Save SFTP settings and refresh restore-config.json.
	 *
	 * @param array $settings Input settings.
	 * @return bool True if updated.
	 */
	public static function save_settings( array $settings ) {
		$clean = function( $val ) {
			return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( trim( (string) $val ) ) : trim( strip_tags( (string) $val ) );
		};

		$sanitized = [
			'host'     => $clean( $settings['host'] ?? '' ),
			'port'     => ! empty( $settings['port'] ) ? max( 1, min( 65535, (int) $settings['port'] ) ) : 22,
			'username' => $clean( $settings['username'] ?? '' ),
			'password' => $clean( $settings['password'] ?? '' ),
			'path'     => '/' . trim( $clean( $settings['path'] ?? '/backups' ), '/' ),
		];

		$updated = update_option( self::OPTION_NAME, $sanitized );
		if ( class_exists( 'Lumos_Backup_Restore_Gate' ) ) {
			Lumos_Backup_Restore_Gate::write_config_file();
		}
		return $updated;
	}

	/**
	 * Determine if SFTP storage is sufficiently configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$s = self::get_settings();
		return ! empty( $s['host'] ) && ! empty( $s['username'] ) && ! empty( $s['password'] );
	}

	/**
	 * Build full SFTP URL for a given remote file or directory path.
	 *
	 * @param string $filename Optional file name.
	 * @return string SFTP URL.
	 */
	public static function build_sftp_url( $filename = '' ) {
		$s    = self::get_settings();
		$path = rtrim( $s['path'], '/' );
		if ( '' !== $filename ) {
			$path .= '/' . ltrim( $filename, '/' );
		}
		return sprintf( 'sftp://%s:%d%s', $s['host'], (int) $s['port'], $path );
	}

	/**
	 * Upload a file to the configured SFTP destination.
	 *
	 * @param string $local_path Local file path.
	 * @param string $filename   Target filename on SFTP.
	 * @return true|WP_Error
	 */
	public static function upload_file( $local_path, $filename ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'sftp_not_configured', __( 'SFTP storage is not configured.', 'lumos-backup' ) );
		}

		if ( ! file_exists( $local_path ) || ! is_readable( $local_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Source file does not exist for SFTP upload.', 'lumos-backup' ) );
		}

		$s   = self::get_settings();
		$url = self::build_sftp_url( $filename );
		$fp  = @fopen( $local_path, 'rb' );

		if ( ! $fp ) {
			return new WP_Error( 'file_open_failed', __( 'Could not open local file for reading.', 'lumos-backup' ) );
		}

		$filesize = filesize( $local_path );
		$ch       = curl_init( $url );

		curl_setopt_array(
			$ch,
			[
				CURLOPT_UPLOAD         => true,
				CURLOPT_INFILE         => $fp,
				CURLOPT_INFILESIZE     => $filesize,
				CURLOPT_USERPWD        => sprintf( '%s:%s', $s['username'], $s['password'] ),
				CURLOPT_TIMEOUT        => 300,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_FTP_CREATE_MISSING_DIRS => 1,
			]
		);

		$res   = curl_exec( $ch );
		$errno = curl_errno( $ch );
		$error = curl_error( $ch );
		curl_close( $ch );
		fclose( $fp );

		if ( $errno || false === $res ) {
			return new WP_Error( 'sftp_upload_failed', sprintf( __( 'SFTP upload failed: %s (code %d)', 'lumos-backup' ), $error, $errno ) );
		}

		return true;
	}

	/**
	 * List archives stored on the SFTP server.
	 *
	 * @return array|WP_Error
	 */
	public static function list_objects() {
		if ( ! self::is_configured() ) {
			return [];
		}

		$s   = self::get_settings();
		$url = self::build_sftp_url() . '/';
		$ch  = curl_init( $url );

		curl_setopt_array(
			$ch,
			[
				CURLOPT_USERPWD        => sprintf( '%s:%s', $s['username'], $s['password'] ),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FTPLISTONLY    => true,
				CURLOPT_TIMEOUT        => 30,
			]
		);

		$output = curl_exec( $ch );
		$errno  = curl_errno( $ch );
		$error  = curl_error( $ch );
		curl_close( $ch );

		if ( $errno ) {
			return new WP_Error( 'sftp_list_failed', sprintf( __( 'SFTP list failed: %s', 'lumos-backup' ), $error ) );
		}

		$lines    = explode( "\n", str_replace( "\r", '', (string) $output ) );
		$archives = [];

		foreach ( $lines as $line ) {
			$filename = trim( basename( $line ) );
			if ( ! Lumos_Backup_Storage::is_valid_archive_name( $filename ) ) {
				continue;
			}

			$mtime = time();
			$archives[] = [
				'filename'       => $filename,
				'key'            => $filename,
				'size'           => 0,
				'size_formatted' => __( 'Remote SFTP', 'lumos-backup' ),
				'created_utc'    => Lumos_Backup_Storage::parse_date_from_filename( $filename, $mtime ),
				'mtime'          => $mtime,
				'origin'         => 'sftp',
			];
		}

		usort(
			$archives,
			function ( $a, $b ) {
				return ( $b['mtime'] ?? 0 ) <=> ( $a['mtime'] ?? 0 );
			}
		);

		return $archives;
	}

	/**
	 * Test SFTP connection.
	 *
	 * @return array [ 'success' => bool, 'message' => string ]
	 */
	public static function test_connection() {
		if ( ! self::is_configured() ) {
			return [
				'success' => false,
				'message' => __( 'Please fill in Host, Username, and Password for SFTP.', 'lumos-backup' ),
			];
		}

		$list = self::list_objects();
		if ( is_wp_error( $list ) ) {
			return [
				'success' => false,
				'message' => $list->get_error_message(),
			];
		}

		return [
			'success' => true,
			'message' => sprintf(
				__( 'SFTP connection successful! Found %d backup archives on remote server.', 'lumos-backup' ),
				count( $list )
			),
		];
	}
}
