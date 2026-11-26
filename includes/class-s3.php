<?php
/**
 * S3-compatible remote storage manager with AWS Signature Version 4.
 *
 * Supports AWS S3, Backblaze B2, Cloudflare R2, MinIO, and other S3-compatible services
 * without external SDK dependencies.
 *
 * @package LumosBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lumos_Backup_S3
 */
class Lumos_Backup_S3 {

	/**
	 * WordPress option storing S3 settings.
	 */
	const OPTION_NAME = 'lumos_backup_s3';

	/**
	 * Get S3 configuration settings.
	 *
	 * @return array S3 configuration.
	 */
	public static function get_settings() {
		$defaults = [
			'endpoint'   => '',
			'region'     => 'us-east-1',
			'bucket'     => '',
			'access_key' => '',
			'secret_key' => '',
			'prefix'     => '',
		];

		$saved = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Save S3 configuration settings and update restore-config.json.
	 *
	 * @param array $settings Array of S3 settings.
	 * @return bool True if updated.
	 */
	public static function save_settings( array $settings ) {
		$sanitized = [
			'endpoint'   => esc_url_raw( trim( $settings['endpoint'] ?? '' ) ),
			'region'     => sanitize_text_field( trim( $settings['region'] ?? 'us-east-1' ) ),
			'bucket'     => sanitize_text_field( trim( $settings['bucket'] ?? '' ) ),
			'access_key' => sanitize_text_field( trim( $settings['access_key'] ?? '' ) ),
			'secret_key' => sanitize_text_field( trim( $settings['secret_key'] ?? '' ) ),
			'prefix'     => trim( sanitize_text_field( trim( $settings['prefix'] ?? '' ) ), '/' ),
		];

		if ( empty( $sanitized['region'] ) ) {
			$sanitized['region'] = 'us-east-1';
		}

		$updated = update_option( self::OPTION_NAME, $sanitized );

		// Update restore-config.json with the new remote configuration
		Lumos_Backup_Restore_Gate::write_config_file();

		return $updated;
	}

	/**
	 * Determine if S3 remote storage is sufficiently configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$settings = self::get_settings();
		return ! empty( $settings['bucket'] )
			&& ! empty( $settings['access_key'] )
			&& ! empty( $settings['secret_key'] );
	}

	/**
	 * Build full S3 URL and host for a given key or query.
	 *
	 * @param string $key   Optional object key.
	 * @param array  $query Optional query parameters.
	 * @return array [ 'url' => string, 'host' => string, 'path' => string, 'query' => string ]
	 */
	public static function build_s3_url_parts( $key = '', array $query = [] ) {
		$settings = self::get_settings();
		$bucket   = $settings['bucket'];
		$endpoint = $settings['endpoint'];
		$region   = ! empty( $settings['region'] ) ? $settings['region'] : 'us-east-1';

		$query_str = '';
		if ( ! empty( $query ) ) {
			ksort( $query );
			$pairs = [];
			foreach ( $query as $k => $v ) {
				$pairs[] = rawurlencode( $k ) . '=' . rawurlencode( (string) $v );
			}
			$query_str = implode( '&', $pairs );
		}

		// Clean key
		$key = ltrim( $key, '/' );

		if ( empty( $endpoint ) ) {
			// Standard AWS S3 (Virtual-host style)
			$host = sprintf( '%s.s3.%s.amazonaws.com', $bucket, $region );
			$path = '/' . $key;
			$url  = 'https://' . $host . $path . ( $query_str ? '?' . $query_str : '' );

			return [
				'url'   => $url,
				'host'  => $host,
				'path'  => $path,
				'query' => $query_str,
			];
		}

		// Custom endpoint (e.g. Cloudflare R2, Backblaze B2, MinIO)
		$parsed = parse_url( $endpoint );
		$scheme = $parsed['scheme'] ?? 'https';
		$host   = $parsed['host'] ?? $endpoint;
		$base_p = trim( $parsed['path'] ?? '', '/' );

		// Check if host already includes bucket
		if ( 0 === strpos( $host, $bucket . '.' ) ) {
			$path = '/' . ( $base_p ? $base_p . '/' : '' ) . $key;
		} else {
			// Path-style: https://endpoint/bucket/key
			$path = '/' . ( $base_p ? $base_p . '/' : '' ) . $bucket . ( $key !== '' ? '/' . $key : '' );
		}

		$url = $scheme . '://' . $host . $path . ( $query_str ? '?' . $query_str : '' );

		return [
			'url'   => $url,
			'host'  => $host,
			'path'  => $path,
			'query' => $query_str,
		];
	}

	/**
	 * Compute AWS Signature Version 4 headers.
	 *
	 * @param string $method       HTTP method (GET, PUT, DELETE, HEAD).
	 * @param array  $url_parts    Result from build_s3_url_parts().
	 * @param array  $headers      Additional request headers.
	 * @param string $payload_hash SHA256 hex string of payload.
	 * @return array Headers with Authorization, x-amz-date, and x-amz-content-sha256.
	 */
	public static function sign_request( $method, array $url_parts, array $headers = [], $payload_hash = '' ) {
		$settings   = self::get_settings();
		$access_key = $settings['access_key'];
		$secret_key = $settings['secret_key'];
		$region     = ! empty( $settings['region'] ) ? $settings['region'] : 'us-east-1';

		$datetime   = gmdate( 'Ymd\THis\Z' );
		$date_stamp = gmdate( 'Ymd' );

		if ( empty( $payload_hash ) ) {
			$payload_hash = hash( 'sha256', '' );
		}

		$host = $url_parts['host'];
		$path = $url_parts['path'];
		if ( empty( $path ) ) {
			$path = '/';
		}

		// 1. Prepare and sort headers
		$headers['host']                 = $host;
		$headers['x-amz-date']           = $datetime;
		$headers['x-amz-content-sha256'] = $payload_hash;

		// Sort headers alphabetically by lowercase name
		$canonical_headers_arr = [];
		$signed_headers_arr    = [];

		foreach ( $headers as $h_name => $h_val ) {
			$lower = strtolower( trim( $h_name ) );
			$canonical_headers_arr[ $lower ] = trim( (string) $h_val );
		}
		ksort( $canonical_headers_arr );

		$canonical_headers_str = '';
		foreach ( $canonical_headers_arr as $k => $v ) {
			$canonical_headers_str .= $k . ':' . $v . "\n";
			$signed_headers_arr[]   = $k;
		}
		$signed_headers_str = implode( ';', $signed_headers_arr );

		// 2. Canonical URI (URL-encoded segments without encoding slash)
		$segments = explode( '/', $path );
		$encoded_segments = [];
		foreach ( $segments as $seg ) {
			$encoded_segments[] = rawurlencode( $seg );
		}
		$canonical_uri = implode( '/', $encoded_segments );
		if ( empty( $canonical_uri ) ) {
			$canonical_uri = '/';
		}

		// 3. Canonical Query String
		$canonical_query = $url_parts['query'] ?? '';

		// 4. Canonical Request
		$canonical_request = strtoupper( $method ) . "\n"
			. $canonical_uri . "\n"
			. $canonical_query . "\n"
			. $canonical_headers_str . "\n"
			. $signed_headers_str . "\n"
			. $payload_hash;

		// 5. String to Sign
		$algorithm        = 'AWS4-HMAC-SHA256';
		$credential_scope = $date_stamp . '/' . $region . '/s3/aws4_request';
		$string_to_sign   = $algorithm . "\n"
			. $datetime . "\n"
			. $credential_scope . "\n"
			. hash( 'sha256', $canonical_request );

		// 6. Signing Key calculation
		$k_date    = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', 's3', $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );

		// 7. Signature
		$signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

		// 8. Authorization Header
		$auth_header = sprintf(
			'%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$algorithm,
			$access_key,
			$credential_scope,
			$signed_headers_str,
			$signature
		);

		$headers['Authorization'] = $auth_header;

		return $headers;
	}

	/**
	 * Upload a local backup archive to the configured S3 bucket.
	 *
	 * @param string $file_path Local path to backup zip archive.
	 * @param string $filename  Archive filename (e.g. backup-YYYYMMDD-HHMMSS.zip).
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function upload_file( $file_path, $filename ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 's3_not_configured', __( 'Remote S3 storage is not configured.', 'lumos-backup' ) );
		}

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Backup file to upload does not exist.', 'lumos-backup' ) );
		}

		$settings = self::get_settings();
		$prefix   = $settings['prefix'];
		$key      = ( $prefix ? $prefix . '/' : '' ) . $filename;

		$file_size    = filesize( $file_path );
		$payload_hash = hash_file( 'sha256', $file_path );
		$url_parts    = self::build_s3_url_parts( $key );

		$headers = [
			'Content-Type'   => 'application/zip',
			'Content-Length' => (string) $file_size,
		];

		$signed_headers = self::sign_request( 'PUT', $url_parts, $headers, $payload_hash );

		// Prefer streaming the file from disk with cURL so large archives are never
		// loaded into PHP memory.
		if ( function_exists( 'curl_init' ) ) {
			return self::stream_put( $url_parts['url'], $signed_headers, $file_path, $file_size );
		}

		$content = file_get_contents( $file_path );
		if ( false === $content ) {
			return new WP_Error( 'file_read_error', __( 'Could not read backup file for S3 upload.', 'lumos-backup' ) );
		}

		$response = wp_remote_request(
			$url_parts['url'],
			[
				'method'    => 'PUT',
				'headers'   => $signed_headers,
				'body'      => $content,
				'timeout'   => 300,
				'sslverify' => true,
			]
		);

		unset( $content );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$body = wp_remote_retrieve_body( $response );
			return new WP_Error(
				's3_upload_failed',
				sprintf(
					/* translators: 1: HTTP status code, 2: Response body */
					__( 'S3 upload returned HTTP status %1$d: %2$s', 'lumos-backup' ),
					$status_code,
					substr( wp_strip_all_tags( $body ), 0, 300 )
				)
			);
		}

		return true;
	}

	/**
	 * Stream a file to S3 with a signed PUT request without reading it into memory.
	 *
	 * @param string $url       Request URL.
	 * @param array  $headers   Signed headers (name => value).
	 * @param string $file_path Local file to send.
	 * @param int    $file_size File size in bytes.
	 * @return true|WP_Error
	 */
	private static function stream_put( $url, array $headers, $file_path, $file_size ) {
		$fp = @fopen( $file_path, 'rb' );
		if ( ! $fp ) {
			return new WP_Error( 'file_read_error', __( 'Could not read backup file for S3 upload.', 'lumos-backup' ) );
		}

		$header_lines = [ 'Expect:' ];
		foreach ( $headers as $name => $value ) {
			if ( 'host' === strtolower( $name ) ) {
				continue; // cURL sets Host from the URL.
			}
			$header_lines[] = $name . ': ' . $value;
		}

		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			[
				CURLOPT_PUT            => true,
				CURLOPT_INFILE         => $fp,
				CURLOPT_INFILESIZE     => $file_size,
				CURLOPT_HTTPHEADER     => $header_lines,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 1800,
				CURLOPT_CONNECTTIMEOUT => 30,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
			]
		);

		$body   = curl_exec( $ch );
		$errno  = curl_errno( $ch );
		$error  = curl_error( $ch );
		$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		fclose( $fp );

		if ( $errno ) {
			return new WP_Error( 's3_upload_failed', sprintf( __( 'S3 upload failed: %s', 'lumos-backup' ), $error ) );
		}
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				's3_upload_failed',
				sprintf(
					/* translators: 1: HTTP status code, 2: Response body */
					__( 'S3 upload returned HTTP status %1$d: %2$s', 'lumos-backup' ),
					$status,
					substr( wp_strip_all_tags( (string) $body ), 0, 300 )
				)
			);
		}

		return true;
	}

	/**
	 * List backup archives stored in the remote S3 bucket.
	 *
	 * @return array|WP_Error Array of remote archive objects or WP_Error on failure.
	 */
	public static function list_objects() {
		if ( ! self::is_configured() ) {
			return [];
		}

		$settings = self::get_settings();
		$prefix   = $settings['prefix'];

		$query = [ 'list-type' => '2' ];
		if ( ! empty( $prefix ) ) {
			$query['prefix'] = $prefix . '/';
		}

		$url_parts      = self::build_s3_url_parts( '', $query );
		$signed_headers = self::sign_request( 'GET', $url_parts );

		$response = wp_remote_request(
			$url_parts['url'],
			[
				'method'    => 'GET',
				'headers'   => $signed_headers,
				'timeout'   => 30,
				'sslverify' => true,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				's3_list_failed',
				sprintf(
					/* translators: 1: HTTP status code, 2: Response body */
					__( 'S3 list returned HTTP status %1$d: %2$s', 'lumos-backup' ),
					$status,
					substr( wp_strip_all_tags( $body ), 0, 200 )
				)
			);
		}

		// Suppress XML errors and parse
		$prev_libxml = libxml_use_internal_errors( true );
		$xml         = simplexml_load_string( $body );
		libxml_use_internal_errors( $prev_libxml );

		if ( false === $xml ) {
			return new WP_Error( 's3_xml_parse_error', __( 'Could not parse S3 ListBucket response XML.', 'lumos-backup' ) );
		}

		$archives = [];
		if ( isset( $xml->Contents ) ) {
			foreach ( $xml->Contents as $item ) {
				$key      = (string) $item->Key;
				$filename = basename( $key );

				if ( ! Lumos_Backup_Storage::is_valid_archive_name( $filename ) ) {
					continue;
				}

				$size     = (int) $item->Size;
				$last_mod = (string) $item->LastModified;
				$mtime    = strtotime( $last_mod ) ?: time();

				$archives[] = [
					'filename'       => $filename,
					'key'            => $key,
					'size'           => $size,
					'size_formatted' => size_format( $size, 2 ),
					'created_utc'    => Lumos_Backup_Storage::parse_date_from_filename( $filename, $mtime ),
					'mtime'          => $mtime,
					'origin'         => 'remote',
				];
			}
		}

		// Sort newest first
		usort(
			$archives,
			function ( $a, $b ) {
				return $b['mtime'] <=> $a['mtime'];
			}
		);

		return $archives;
	}

	/**
	 * Delete a specific object from S3.
	 *
	 * @param string $key S3 object key.
	 * @return true|WP_Error
	 */
	public static function delete_object( $key ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 's3_not_configured', __( 'Remote S3 storage is not configured.', 'lumos-backup' ) );
		}

		$url_parts      = self::build_s3_url_parts( $key );
		$signed_headers = self::sign_request( 'DELETE', $url_parts );

		$response = wp_remote_request(
			$url_parts['url'],
			[
				'method'    => 'DELETE',
				'headers'   => $signed_headers,
				'timeout'   => 30,
				'sslverify' => true,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 204 === $status || 200 === $status ) {
			return true;
		}

		return new WP_Error( 's3_delete_failed', __( 'Could not delete remote S3 archive.', 'lumos-backup' ) );
	}

	/**
	 * Prune remote S3 archives keeping only the newest $keep_count archives.
	 *
	 * @param int $keep_count Maximum number of remote archives to keep.
	 * @return int Number of remote archives deleted.
	 */
	public static function prune_remote_archives( $keep_count = 7 ) {
		$keep_count = max( 1, (int) $keep_count );
		$archives   = self::list_objects();

		if ( is_wp_error( $archives ) || count( $archives ) <= $keep_count ) {
			return 0;
		}

		$to_delete = array_slice( $archives, $keep_count );
		$deleted   = 0;

		foreach ( $to_delete as $item ) {
			$res = self::delete_object( $item['key'] );
			if ( true === $res ) {
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * Test S3 connectivity with current configuration.
	 *
	 * @return array [ 'success' => bool, 'message' => string ]
	 */
	public static function test_connection() {
		if ( ! self::is_configured() ) {
			return [
				'success' => false,
				'message' => __( 'Please fill in Bucket, Access Key, and Secret Key.', 'lumos-backup' ),
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
				/* translators: %d: Number of backup archives found */
				__( 'Connection successful! Found %d existing backup archives in bucket.', 'lumos-backup' ),
				count( $list )
			),
		];
	}
}
