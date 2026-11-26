<?php
/**
 * Lumos Backup - Standalone Emergency Restore Tool
 *
 * CRITICAL REQUIREMENTS:
 * 1. Runs completely independently of WordPress when WordPress fails to boot.
 * 2. MUST NOT include wp-load.php, wp-config.php, or any WordPress files.
 * 3. Rate-limited failed keys: 5 misses per IP per 15 minutes, then HTTP 429.
 * 4. Passphrase authentication for encrypted archives (.zip.enc) with rate-limiting:
 *    5 failures per IP per 15 minutes, then HTTP 429.
 * 5. SHA-256 checksum verification against .sha256 sidecar before extract.
 *    Mismatch aborts with plain error and does NOT touch database or files.
 * 6. Two-step confirmation: First click displays archive id, created time,
 *    siteurl from manifest.json, and file size. Second click performs restore.
 * 7. Domain change on restore: Serialized-safe replacement across options, posts (skipping GUID),
 *    postmeta, usermeta, comments, and wp_options siteurl/home update.
 * 8. Writes ABSPATH/.lumos-maintenance before restore and deletes it after success or failure.
 * 9. Never overwrites lumos-restore.php, wp-config.php, or restore-config.json.
 * 10. On SQL failure, STOP immediately. Do not start file copy.
 * 11. Log restore attempts to wp-content/uploads/lumos-backup/restore.log (never log tokens or passphrases).
 * 12. Lists and restores both local and remote S3-compatible archives using AWS SigV4.
 *
 * @package LumosBackup
 */

declare( strict_types=1 );

// Disable displaying internal PHP errors to avoid leaking credentials/paths, but log them
error_reporting( E_ALL );
ini_set( 'display_errors', '0' );

// PHP 8.1+ makes mysqli throw on every SQL error by default. This script checks return
// values itself (and must clean up staging tables on failure), so use classic reporting.
mysqli_report( MYSQLI_REPORT_OFF );

// Enforce privacy and security headers
header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
header( 'X-Content-Type-Options: nosniff' );
header( 'X-Frame-Options: DENY' );
header( 'Referrer-Policy: no-referrer' );

/**
 * Locate the restore-config.json file.
 *
 * @return string|null Absolute path to config file or null if not found.
 */
function lumos_find_config_file(): ?string {
	$candidates = [
		__DIR__ . '/wp-content/uploads/lumos-backup/restore-config.php',
		dirname( __DIR__ ) . '/wp-content/uploads/lumos-backup/restore-config.php',
		// Legacy plain-JSON location (plugin versions before 1.1.1).
		__DIR__ . '/wp-content/uploads/lumos-backup/restore-config.json',
		dirname( __DIR__ ) . '/wp-content/uploads/lumos-backup/restore-config.json',
	];

	foreach ( $candidates as $candidate ) {
		if ( file_exists( $candidate ) && is_readable( $candidate ) ) {
			return $candidate;
		}
	}

	return null;
}

/**
 * Get client IP address safely.
 *
 * @return string
 */
function lumos_get_client_ip(): string {
	$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '127.0.0.1';
}

/**
 * Check if the current client IP is rate-limited (5 misses per IP per 15 minutes).
 *
 * @param string $rate_limit_file Path to rate-limits JSON file.
 * @param string $ip              Client IP.
 * @return bool True if allowed, false if rate limited.
 */
function lumos_check_rate_limit( string $rate_limit_file, string $ip ): bool {
	$now    = time();
	$window = 900; // 15 minutes
	$limit  = 5;

	if ( ! file_exists( $rate_limit_file ) || ! is_readable( $rate_limit_file ) ) {
		return true;
	}

	$raw     = @file_get_contents( $rate_limit_file );
	$decoded = $raw ? json_decode( $raw, true ) : null;
	if ( ! is_array( $decoded ) || ! isset( $decoded[ $ip ] ) || ! is_array( $decoded[ $ip ] ) ) {
		return true;
	}

	$valid = array_filter( $decoded[ $ip ], function ( $ts ) use ( $now, $window ) {
		return is_numeric( $ts ) && ( $now - (int) $ts ) < $window;
	} );

	return count( $valid ) < $limit;
}

/**
 * Record a failed attempt for rate limiting.
 *
 * @param string $rate_limit_file Path to rate-limits JSON file.
 * @param string $ip              Client IP.
 */
function lumos_record_failed_key( string $rate_limit_file, string $ip ): void {
	$now    = time();
	$window = 900; // 15 minutes

	$data = [];
	if ( file_exists( $rate_limit_file ) && is_readable( $rate_limit_file ) ) {
		$raw     = @file_get_contents( $rate_limit_file );
		$decoded = $raw ? json_decode( $raw, true ) : null;
		if ( is_array( $decoded ) ) {
			$data = $decoded;
		}
	}

	// Prune old timestamps
	$pruned = [];
	foreach ( $data as $entry_ip => $timestamps ) {
		if ( ! is_array( $timestamps ) ) {
			continue;
		}
		$valid = array_filter( $timestamps, function ( $ts ) use ( $now, $window ) {
			return is_numeric( $ts ) && ( $now - (int) $ts ) < $window;
		} );
		if ( ! empty( $valid ) ) {
			$pruned[ $entry_ip ] = array_values( $valid );
		}
	}

	if ( ! isset( $pruned[ $ip ] ) ) {
		$pruned[ $ip ] = [];
	}
	$pruned[ $ip ][] = $now;

	$dir = dirname( $rate_limit_file );
	if ( ! is_dir( $dir ) ) {
		@mkdir( $dir, 0755, true );
	}

	@file_put_contents( $rate_limit_file, json_encode( $pruned ), LOCK_EX );
	@chmod( $rate_limit_file, 0600 );
}

/**
 * Log restore attempts to wp-content/uploads/lumos-backup/restore.log.
 *
 * CRITICAL: Never logs secret tokens or passphrases.
 *
 * @param string $log_file   Path to restore.log.
 * @param string $ip         Client IP address.
 * @param string $archive_id Archive ID.
 * @param string $status     'SUCCESS' or 'FAILURE'.
 * @param string $message    Summary message.
 */
function lumos_log_restore( string $log_file, string $ip, string $archive_id, string $status, string $message = '' ): void {
	$time  = gmdate( 'Y-m-d H:i:s' );
	$entry = sprintf(
		"[%s UTC] IP: %s | Archive: %s | Result: %s | %s\n",
		$time,
		$ip,
		$archive_id,
		strtoupper( $status ),
		$message
	);

	$dir = dirname( $log_file );
	if ( ! is_dir( $dir ) ) {
		@mkdir( $dir, 0755, true );
	}

	@file_put_contents( $log_file, $entry, FILE_APPEND | LOCK_EX );
	@chmod( $log_file, 0600 );
}

/**
 * Render a styled HTML error page and exit.
 *
 * @param string $title       Error title.
 * @param string $message     Error description.
 * @param int    $status_code HTTP response status code.
 */
function lumos_exit_with_error( string $title, string $message, int $status_code = 403 ): void {
	http_response_code( $status_code );
	?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo htmlspecialchars( $title, ENT_QUOTES, 'UTF-8' ); ?> - Lumos Emergency Restore</title>
	<style>
		:root {
			--bg: #0f172a;
			--card: #1e293b;
			--border: #334155;
			--text: #f8fafc;
			--muted: #94a3b8;
			--danger: #ef4444;
		}
		* { box-sizing: border-box; margin: 0; padding: 0; }
		body {
			background: var(--bg);
			color: var(--text);
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
			display: flex;
			align-items: center;
			justify-content: center;
			min-height: 100vh;
			padding: 24px;
		}
		.card {
			background: var(--card);
			border: 1px solid var(--border);
			border-radius: 12px;
			max-width: 520px;
			width: 100%;
			padding: 32px;
			box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4);
		}
		.badge {
			display: inline-block;
			background: rgba(239, 68, 68, 0.15);
			color: var(--danger);
			padding: 4px 10px;
			border-radius: 6px;
			font-size: 13px;
			font-weight: 600;
			margin-bottom: 16px;
		}
		h1 { font-size: 22px; font-weight: 600; margin-bottom: 12px; }
		p { color: var(--muted); font-size: 15px; line-height: 1.6; }
	</style>
</head>
<body>
	<div class="card">
		<div class="badge"><?php echo htmlspecialchars( $title, ENT_QUOTES, 'UTF-8' ); ?></div>
		<h1>Access Denied / System Notice</h1>
		<p><?php echo htmlspecialchars( $message, ENT_QUOTES, 'UTF-8' ); ?></p>
	</div>
</body>
</html>
	<?php
	exit;
}

/**
 * Format bytes into human readable format without WordPress functions.
 *
 * @param int $bytes Number of bytes.
 * @return string Formatted size string.
 */
function lumos_format_size( int $bytes ): string {
	$units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	$bytes = max( $bytes, 0 );
	$pow   = $bytes ? floor( log( $bytes ) / log( 1024 ) ) : 0;
	$pow   = min( $pow, count( $units ) - 1 );
	$bytes /= pow( 1024, $pow );
	return round( $bytes, 2 ) . ' ' . $units[ (int) $pow ];
}

/**
 * Perform an authenticated AWS SigV4 S3 REST request using raw cURL or streams.
 *
 * @param array  $s3        S3 configuration credentials array.
 * @param string $method    HTTP method (GET, PUT, etc.).
 * @param string $key       Object key.
 * @param array  $query     Query parameters.
 * @param string $dest_file Optional file path to stream download response to.
 * @return array [ 'success' => bool, 'code' => int, 'body' => string, 'error' => string ]
 */
function lumos_s3_raw_request( array $s3, string $method, string $key = '', array $query = [], string $dest_file = '' ): array {
	$bucket   = trim( $s3['bucket'] ?? '' );
	$region   = ! empty( $s3['region'] ) ? trim( $s3['region'] ) : 'us-east-1';
	$endpoint = trim( $s3['endpoint'] ?? '' );
	$access   = trim( $s3['access_key'] ?? '' );
	$secret   = trim( $s3['secret_key'] ?? '' );

	if ( empty( $bucket ) || empty( $access ) || empty( $secret ) ) {
		return [ 'success' => false, 'code' => 0, 'body' => '', 'error' => 'Missing S3 credentials' ];
	}

	$clean_key = ltrim( $key, '/' );

	// Build query string sorted alphabetically
	$query_str = '';
	if ( ! empty( $query ) ) {
		ksort( $query );
		$pairs = [];
		foreach ( $query as $k => $v ) {
			$pairs[] = rawurlencode( $k ) . '=' . rawurlencode( (string) $v );
		}
		$query_str = implode( '&', $pairs );
	}

	// Build Host & Path
	if ( empty( $endpoint ) ) {
		$host = sprintf( '%s.s3.%s.amazonaws.com', $bucket, $region );
		$path = '/' . $clean_key;
		$url  = 'https://' . $host . $path . ( $query_str ? '?' . $query_str : '' );
	} else {
		$parsed = parse_url( $endpoint );
		$scheme = $parsed['scheme'] ?? 'https';
		$host   = $parsed['host'] ?? $endpoint;
		$base_p = trim( $parsed['path'] ?? '', '/' );

		if ( 0 === strpos( $host, $bucket . '.' ) ) {
			$path = '/' . ( $base_p ? $base_p . '/' : '' ) . $clean_key;
		} else {
			$path = '/' . ( $base_p ? $base_p . '/' : '' ) . $bucket . ( $clean_key !== '' ? '/' . $clean_key : '' );
		}
		$url = $scheme . '://' . $host . $path . ( $query_str ? '?' . $query_str : '' );
	}

	$datetime     = gmdate( 'Ymd\THis\Z' );
	$date_stamp   = gmdate( 'Ymd' );
	$payload_hash = hash( 'sha256', '' );

	// Headers
	$canonical_headers = "host:{$host}\nx-amz-content-sha256:{$payload_hash}\nx-amz-date:{$datetime}\n";
	$signed_headers    = 'host;x-amz-content-sha256;x-amz-date';

	// Canonical URI
	$segments = explode( '/', $path );
	$encoded  = [];
	foreach ( $segments as $seg ) {
		$encoded[] = rawurlencode( $seg );
	}
	$canonical_uri = implode( '/', $encoded );
	if ( empty( $canonical_uri ) ) {
		$canonical_uri = '/';
	}

	// Canonical Request
	$canonical_request = strtoupper( $method ) . "\n"
		. $canonical_uri . "\n"
		. $query_str . "\n"
		. $canonical_headers . "\n"
		. $signed_headers . "\n"
		. $payload_hash;

	// String to Sign
	$algorithm        = 'AWS4-HMAC-SHA256';
	$credential_scope = $date_stamp . '/' . $region . '/s3/aws4_request';
	$string_to_sign   = $algorithm . "\n"
		. $datetime . "\n"
		. $credential_scope . "\n"
		. hash( 'sha256', $canonical_request );

	// Signing Key
	$k_date    = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret, true );
	$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
	$k_service = hash_hmac( 'sha256', 's3', $k_region, true );
	$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );

	$signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );
	$auth      = "{$algorithm} Credential={$access}/{$credential_scope}, SignedHeaders={$signed_headers}, Signature={$signature}";

	$http_headers = [
		"Host: {$host}",
		"x-amz-date: {$datetime}",
		"x-amz-content-sha256: {$payload_hash}",
		"Authorization: {$auth}",
	];

	// Execute via cURL
	if ( function_exists( 'curl_init' ) ) {
		$ch      = curl_init( $url );
		$options = [
			CURLOPT_CUSTOMREQUEST  => strtoupper( $method ),
			CURLOPT_HTTPHEADER     => $http_headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT        => 300,
			CURLOPT_SSL_VERIFYPEER => true,
		];

		$fp = null;
		if ( $dest_file !== '' ) {
			$fp = @fopen( $dest_file, 'wb' );
			if ( $fp ) {
				$options[ CURLOPT_FILE ]           = $fp;
				$options[ CURLOPT_RETURNTRANSFER ] = false;
			}
		}

		curl_setopt_array( $ch, $options );
		$response = curl_exec( $ch );
		$code     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$err      = curl_error( $ch );
		curl_close( $ch );

		if ( $fp ) {
			fclose( $fp );
		}

		if ( $code >= 200 && $code < 300 ) {
			return [
				'success' => true,
				'code'    => $code,
				'body'    => is_string( $response ) ? $response : '',
				'error'   => '',
			];
		}

		return [
			'success' => false,
			'code'    => $code,
			'body'    => is_string( $response ) ? $response : '',
			'error'   => $err ? $err : "HTTP {$code}",
		];
	} else {
		// Fallback to stream context
		$ctx = stream_context_create( [
			'http' => [
				'method'        => strtoupper( $method ),
				'header'        => implode( "\r\n", $http_headers ) . "\r\n",
				'timeout'       => 300,
				'ignore_errors' => true,
			],
		] );

		if ( $dest_file !== '' ) {
			$in  = @fopen( $url, 'rb', false, $ctx );
			$out = @fopen( $dest_file, 'wb' );
			if ( $in && $out ) {
				stream_copy_to_stream( $in, $out );
				fclose( $in );
				fclose( $out );
				return [ 'success' => true, 'code' => 200, 'body' => '', 'error' => '' ];
			}
			return [ 'success' => false, 'code' => 0, 'body' => '', 'error' => 'Stream download failed' ];
		} else {
			$body = @file_get_contents( $url, false, $ctx );
			return [
				'success' => false !== $body,
				'code'    => false !== $body ? 200 : 500,
				'body'    => is_string( $body ) ? $body : '',
				'error'   => false !== $body ? '' : 'Failed to fetch S3 response',
			];
		}
	}
}

/**
 * List remote S3 archives (matches both .zip and .zip.enc).
 *
 * @param array $s3 S3 credentials array.
 * @return array List of remote archive items.
 */
function lumos_list_s3_archives( array $s3 ): array {
	$prefix = trim( $s3['prefix'] ?? '', '/' );
	$query  = [ 'list-type' => '2' ];
	if ( ! empty( $prefix ) ) {
		$query['prefix'] = $prefix . '/';
	}

	$res = lumos_s3_raw_request( $s3, 'GET', '', $query );
	if ( ! $res['success'] || empty( $res['body'] ) ) {
		return [];
	}

	$prev_libxml = libxml_use_internal_errors( true );
	$xml         = simplexml_load_string( $res['body'] );
	libxml_use_internal_errors( $prev_libxml );

	if ( false === $xml || ! isset( $xml->Contents ) ) {
		return [];
	}

	$archives = [];
	foreach ( $xml->Contents as $item ) {
		$key      = (string) $item->Key;
		$filename = basename( $key );

		if ( ! preg_match( '/^backup-\d{8}-\d{6}(-[a-f0-9]{16})?\.zip(\.enc)?$/', $filename ) ) {
			continue;
		}

		$size     = (int) $item->Size;
		$last_mod = (string) $item->LastModified;
		$mtime    = strtotime( $last_mod ) ?: time();
		$is_enc   = substr( $filename, -4 ) === '.enc';

		$archives[] = [
			'id'           => $filename,
			'key'          => $key,
			'size'         => lumos_format_size( $size ),
			'size_raw'     => $size,
			'mtime'        => $mtime,
			'datetime'     => gmdate( 'Y-m-d H:i:s', $mtime ),
			'origin'       => 'remote',
			'is_encrypted' => $is_enc,
		];
	}

	return $archives;
}

/**
 * Check if a string is a PHP serialized string.
 *
 * @param mixed $data Data to test.
 * @return bool
 */
function lumos_is_serialized( $data ): bool {
	if ( ! is_string( $data ) ) {
		return false;
	}
	$data = trim( $data );
	if ( 'N;' === $data ) {
		return true;
	}
	if ( strlen( $data ) < 4 || ':' !== $data[1] ) {
		return false;
	}
	$last = substr( $data, -1 );
	if ( ';' !== $last && '}' !== $last ) {
		return false;
	}
	$token = $data[0];
	switch ( $token ) {
		case 's':
			if ( '"' !== substr( $data, -2, 1 ) ) {
				return false;
			}
			// Fallthrough
		case 'a':
		case 'O':
			return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
		case 'b':
		case 'i':
		case 'd':
			return (bool) preg_match( "/^{$token}:[0-9.E+-]+;$/si", $data );
	}
	return false;
}

/**
 * Perform a serialized-safe recursive string replacement.
 *
 * Ensures that PHP serialized strings have their byte lengths correctly updated,
 * preventing data corruption when URLs change length.
 *
 * @param string $search  Search string (original domain).
 * @param string $replace Replacement string (new domain).
 * @param mixed  $data    Subject data.
 * @return mixed
 */
function lumos_recursive_replace( string $search, string $replace, $data ) {
	if ( is_array( $data ) ) {
		$new_arr = [];
		foreach ( $data as $k => $v ) {
			$new_k = is_string( $k ) ? str_replace( $search, $replace, $k ) : $k;
			$new_arr[ $new_k ] = lumos_recursive_replace( $search, $replace, $v );
		}
		return $new_arr;
	} elseif ( is_object( $data ) ) {
		if ( $data instanceof __PHP_Incomplete_Class ) {
			$arr = (array) $data;
			foreach ( $arr as $k => $v ) {
				if ( '__PHP_Incomplete_Class_Name' === $k ) {
					continue;
				}
				$clean_k = ltrim( $k, "\0*" );
				$data->$clean_k = lumos_recursive_replace( $search, $replace, $v );
			}
			return $data;
		}
		$props = get_object_vars( $data );
		foreach ( $props as $k => $v ) {
			$data->$k = lumos_recursive_replace( $search, $replace, $v );
		}
		return $data;
	} elseif ( is_string( $data ) ) {
		if ( lumos_is_serialized( $data ) ) {
			$un = @unserialize( $data );
			if ( false !== $un || 'b:0;' === $data ) {
				return serialize( lumos_recursive_replace( $search, $replace, $un ) );
			}
		}
		return str_replace( $search, $replace, $data );
	}
	return $data;
}

/**
 * Replace domain URLs across target WordPress database tables safely.
 *
 * Tables: options, posts (SKIPPING GUIDs), postmeta, usermeta, comments.
 * Also explicitly updates siteurl and home in options table.
 *
 * @param mysqli $mysqli       Active database connection.
 * @param string $prefix       WordPress table prefix (e.g. wp_).
 * @param array  $replacements Associative array [ old_url => new_url ].
 * @param string $new_siteurl  New siteurl value.
 * @param string $new_home     New home value.
 * @return array Stats on updated rows.
 */
function lumos_replace_domains_in_database( mysqli $mysqli, string $prefix, array $replacements, string $new_siteurl, string $new_home ): array {
	$stats = [
		'options'  => 0,
		'posts'    => 0,
		'postmeta' => 0,
		'usermeta' => 0,
		'comments' => 0,
	];

	if ( empty( $replacements ) && '' === $new_siteurl && '' === $new_home ) {
		return $stats;
	}

	// 1. Explicitly update siteurl and home in options table
	$opt_table = $prefix . 'options';
	$chk_opt   = $mysqli->query( "SHOW TABLES LIKE '{$opt_table}'" );
	if ( $chk_opt && $chk_opt->num_rows > 0 ) {
		if ( '' !== $new_siteurl ) {
			$stmt = $mysqli->prepare( "UPDATE `{$opt_table}` SET `option_value` = ? WHERE `option_name` = 'siteurl'" );
			if ( $stmt ) {
				$stmt->bind_param( 's', $new_siteurl );
				$stmt->execute();
				$stmt->close();
				$stats['options']++;
			}
		}
		if ( '' !== $new_home ) {
			$stmt = $mysqli->prepare( "UPDATE `{$opt_table}` SET `option_value` = ? WHERE `option_name` = 'home'" );
			if ( $stmt ) {
				$stmt->bind_param( 's', $new_home );
				$stmt->execute();
				$stmt->close();
				$stats['options']++;
			}
		}

		// Serialized-safe scan across other options
		foreach ( $replacements as $search => $replace ) {
			$esc = $mysqli->real_escape_string( $search );
			$res = $mysqli->query( "SELECT `option_id`, `option_name`, `option_value` FROM `{$opt_table}` WHERE `option_value` LIKE '%{$esc}%'" );
			if ( $res ) {
				while ( $row = $res->fetch_assoc() ) {
					if ( 'siteurl' === $row['option_name'] || 'home' === $row['option_name'] ) {
						continue;
					}
					$new_val = lumos_recursive_replace( $search, $replace, $row['option_value'] );
					if ( $new_val !== $row['option_value'] ) {
						$stmt = $mysqli->prepare( "UPDATE `{$opt_table}` SET `option_value` = ? WHERE `option_id` = ?" );
						if ( $stmt ) {
							$id = (int) $row['option_id'];
							$stmt->bind_param( 'si', $new_val, $id );
							$stmt->execute();
							$stmt->close();
							$stats['options']++;
						}
					}
				}
				$res->free();
			}
		}
	}
	if ( $chk_opt ) {
		$chk_opt->free();
	}

	// 2. Posts table - CRITICAL: SKIP GUIDs!
	$posts_table = $prefix . 'posts';
	$chk_posts   = $mysqli->query( "SHOW TABLES LIKE '{$posts_table}'" );
	if ( $chk_posts && $chk_posts->num_rows > 0 ) {
		foreach ( $replacements as $search => $replace ) {
			$esc = $mysqli->real_escape_string( $search );
			$res = $mysqli->query( "SELECT `ID`, `post_content`, `post_title`, `post_excerpt`, `post_content_filtered` FROM `{$posts_table}` WHERE `post_content` LIKE '%{$esc}%' OR `post_excerpt` LIKE '%{$esc}%' OR `post_title` LIKE '%{$esc}%' OR `post_content_filtered` LIKE '%{$esc}%'" );
			if ( $res ) {
				while ( $row = $res->fetch_assoc() ) {
					$new_content  = lumos_recursive_replace( $search, $replace, $row['post_content'] );
					$new_title    = lumos_recursive_replace( $search, $replace, $row['post_title'] );
					$new_excerpt  = lumos_recursive_replace( $search, $replace, $row['post_excerpt'] );
					$new_filtered = lumos_recursive_replace( $search, $replace, $row['post_content_filtered'] );

					if ( $new_content !== $row['post_content'] || $new_title !== $row['post_title'] || $new_excerpt !== $row['post_excerpt'] || $new_filtered !== $row['post_content_filtered'] ) {
						$stmt = $mysqli->prepare( "UPDATE `{$posts_table}` SET `post_content` = ?, `post_title` = ?, `post_excerpt` = ?, `post_content_filtered` = ? WHERE `ID` = ?" );
						if ( $stmt ) {
							$id = (int) $row['ID'];
							$stmt->bind_param( 'ssssi', $new_content, $new_title, $new_excerpt, $new_filtered, $id );
							$stmt->execute();
							$stmt->close();
							$stats['posts']++;
						}
					}
				}
				$res->free();
			}
		}
	}
	if ( $chk_posts ) {
		$chk_posts->free();
	}

	// 3. Postmeta table
	$pm_table = $prefix . 'postmeta';
	$chk_pm   = $mysqli->query( "SHOW TABLES LIKE '{$pm_table}'" );
	if ( $chk_pm && $chk_pm->num_rows > 0 ) {
		foreach ( $replacements as $search => $replace ) {
			$esc = $mysqli->real_escape_string( $search );
			$res = $mysqli->query( "SELECT `meta_id`, `meta_value` FROM `{$pm_table}` WHERE `meta_value` LIKE '%{$esc}%'" );
			if ( $res ) {
				while ( $row = $res->fetch_assoc() ) {
					$new_val = lumos_recursive_replace( $search, $replace, $row['meta_value'] );
					if ( $new_val !== $row['meta_value'] ) {
						$stmt = $mysqli->prepare( "UPDATE `{$pm_table}` SET `meta_value` = ? WHERE `meta_id` = ?" );
						if ( $stmt ) {
							$id = (int) $row['meta_id'];
							$stmt->bind_param( 'si', $new_val, $id );
							$stmt->execute();
							$stmt->close();
							$stats['postmeta']++;
						}
					}
				}
				$res->free();
			}
		}
	}
	if ( $chk_pm ) {
		$chk_pm->free();
	}

	// 4. Usermeta table
	$um_table = $prefix . 'usermeta';
	$chk_um   = $mysqli->query( "SHOW TABLES LIKE '{$um_table}'" );
	if ( $chk_um && $chk_um->num_rows > 0 ) {
		foreach ( $replacements as $search => $replace ) {
			$esc = $mysqli->real_escape_string( $search );
			$res = $mysqli->query( "SELECT `umeta_id`, `meta_value` FROM `{$um_table}` WHERE `meta_value` LIKE '%{$esc}%'" );
			if ( $res ) {
				while ( $row = $res->fetch_assoc() ) {
					$new_val = lumos_recursive_replace( $search, $replace, $row['meta_value'] );
					if ( $new_val !== $row['meta_value'] ) {
						$stmt = $mysqli->prepare( "UPDATE `{$um_table}` SET `meta_value` = ? WHERE `umeta_id` = ?" );
						if ( $stmt ) {
							$id = (int) $row['umeta_id'];
							$stmt->bind_param( 'si', $new_val, $id );
							$stmt->execute();
							$stmt->close();
							$stats['usermeta']++;
						}
					}
				}
				$res->free();
			}
		}
	}
	if ( $chk_um ) {
		$chk_um->free();
	}

	// 5. Comments table
	$cm_table = $prefix . 'comments';
	$chk_cm   = $mysqli->query( "SHOW TABLES LIKE '{$cm_table}'" );
	if ( $chk_cm && $chk_cm->num_rows > 0 ) {
		foreach ( $replacements as $search => $replace ) {
			$esc = $mysqli->real_escape_string( $search );
			$res = $mysqli->query( "SELECT `comment_ID`, `comment_author_url`, `comment_content` FROM `{$cm_table}` WHERE `comment_author_url` LIKE '%{$esc}%' OR `comment_content` LIKE '%{$esc}%'" );
			if ( $res ) {
				while ( $row = $res->fetch_assoc() ) {
					$new_author_url = lumos_recursive_replace( $search, $replace, $row['comment_author_url'] );
					$new_content    = lumos_recursive_replace( $search, $replace, $row['comment_content'] );
					if ( $new_author_url !== $row['comment_author_url'] || $new_content !== $row['comment_content'] ) {
						$stmt = $mysqli->prepare( "UPDATE `{$cm_table}` SET `comment_author_url` = ?, `comment_content` = ? WHERE `comment_ID` = ?" );
						if ( $stmt ) {
							$id = (int) $row['comment_ID'];
							$stmt->bind_param( 'ssi', $new_author_url, $new_content, $id );
							$stmt->execute();
							$stmt->close();
							$stats['comments']++;
						}
					}
				}
				$res->free();
			}
		}
	}
	if ( $chk_cm ) {
		$chk_cm->free();
	}

	// 6. Multisite Network Support: wp_site, wp_blogs, wp_sitemeta, and subsite options tables
	$site_table = $prefix . 'site';
	$chk_site   = $mysqli->query( "SHOW TABLES LIKE '{$site_table}'" );
	if ( $chk_site && $chk_site->num_rows > 0 ) {
		$stats['multisite'] = true;
		$old_host           = '';
		$new_host           = '';

		if ( ! empty( $new_siteurl ) ) {
			$new_host = parse_url( $new_siteurl, PHP_URL_HOST ) ?: '';
		}

		foreach ( $replacements as $search => $replace ) {
			$parsed_old = parse_url( $search, PHP_URL_HOST );
			$parsed_new = parse_url( $replace, PHP_URL_HOST );
			if ( $parsed_old && $parsed_new ) {
				$old_host = $parsed_old;
				if ( empty( $new_host ) ) {
					$new_host = $parsed_new;
				}
				break;
			}
		}

		if ( ! empty( $old_host ) && ! empty( $new_host ) && $old_host !== $new_host ) {
			// Update wp_site
			$stmt = $mysqli->prepare( "UPDATE `{$site_table}` SET `domain` = ? WHERE `domain` = ?" );
			if ( $stmt ) {
				$stmt->bind_param( 'ss', $new_host, $old_host );
				$stmt->execute();
				$stmt->close();
			}

			// Update wp_blogs
			$blogs_table = $prefix . 'blogs';
			$chk_blogs   = $mysqli->query( "SHOW TABLES LIKE '{$blogs_table}'" );
			if ( $chk_blogs && $chk_blogs->num_rows > 0 ) {
				$res_b = $mysqli->query( "SELECT `blog_id`, `domain` FROM `{$blogs_table}`" );
				if ( $res_b ) {
					while ( $b_row = $res_b->fetch_assoc() ) {
						$cur_dom = $b_row['domain'];
						if ( strpos( $cur_dom, $old_host ) !== false ) {
							$updated_dom = str_replace( $old_host, $new_host, $cur_dom );
							$b_id        = (int) $b_row['blog_id'];
							$stmt_b      = $mysqli->prepare( "UPDATE `{$blogs_table}` SET `domain` = ? WHERE `blog_id` = ?" );
							if ( $stmt_b ) {
								$stmt_b->bind_param( 'si', $updated_dom, $b_id );
								$stmt_b->execute();
								$stmt_b->close();
							}
						}
					}
					$res_b->free();
				}
				$chk_blogs->free();
			}
		}

		// Update wp_sitemeta
		$sm_table = $prefix . 'sitemeta';
		$chk_sm   = $mysqli->query( "SHOW TABLES LIKE '{$sm_table}'" );
		if ( $chk_sm && $chk_sm->num_rows > 0 ) {
			foreach ( $replacements as $search => $replace ) {
				$esc = $mysqli->real_escape_string( $search );
				$res = $mysqli->query( "SELECT `meta_id`, `meta_key`, `meta_value` FROM `{$sm_table}` WHERE `meta_value` LIKE '%{$esc}%'" );
				if ( $res ) {
					while ( $row = $res->fetch_assoc() ) {
						$new_val = lumos_recursive_replace( $search, $replace, $row['meta_value'] );
						if ( $new_val !== $row['meta_value'] ) {
							$stmt = $mysqli->prepare( "UPDATE `{$sm_table}` SET `meta_value` = ? WHERE `meta_id` = ?" );
							if ( $stmt ) {
								$id = (int) $row['meta_id'];
								$stmt->bind_param( 'si', $new_val, $id );
								$stmt->execute();
								$stmt->close();
							}
						}
					}
					$res->free();
				}
			}
			$chk_sm->free();
		}

		// Update subsite option tables: {$prefix}{blog_id}_options
		$all_sub_tables = $mysqli->query( "SHOW TABLES LIKE '{$prefix}%_options'" );
		if ( $all_sub_tables ) {
			while ( $t_row = $all_sub_tables->fetch_array() ) {
				$sub_opt_table = $t_row[0];
				if ( $sub_opt_table === $prefix . 'options' ) {
					continue;
				}
				if ( ! preg_match( '/^' . preg_quote( $prefix, '/' ) . '[0-9]+_options$/', $sub_opt_table ) ) {
					continue;
				}

				foreach ( $replacements as $search => $replace ) {
					$esc = $mysqli->real_escape_string( $search );
					$res = $mysqli->query( "SELECT `option_id`, `option_name`, `option_value` FROM `{$sub_opt_table}` WHERE `option_value` LIKE '%{$esc}%'" );
					if ( $res ) {
						while ( $row = $res->fetch_assoc() ) {
							$new_val = lumos_recursive_replace( $search, $replace, $row['option_value'] );
							if ( $new_val !== $row['option_value'] ) {
								$stmt = $mysqli->prepare( "UPDATE `{$sub_opt_table}` SET `option_value` = ? WHERE `option_id` = ?" );
								if ( $stmt ) {
									$id = (int) $row['option_id'];
									$stmt->bind_param( 'si', $new_val, $id );
									$stmt->execute();
									$stmt->close();
									$stats['options']++;
								}
							}
						}
						$res->free();
					}
				}
			}
			$all_sub_tables->free();
		}
	}
	if ( $chk_site ) {
		$chk_site->free();
	}

	return $stats;
}

/**
 * Decrypt an encrypted archive (.zip.enc) to a plain zip file.
 *
 * @param string $enc_path      Path to encrypted file.
 * @param string $dest_zip_path Target output zip path.
 * @param string $passphrase    User provided passphrase.
 * @param string $kdf_salt      Hex salt string from config.
 * @param int    $iterations    PBKDF2 iteration count.
 * @return bool True on success, false on failure.
 */
function lumos_decrypt_archive( string $enc_path, string $dest_zip_path, string $passphrase, string $kdf_salt, int $iterations = 100000 ): bool {
	$key = hash_pbkdf2( 'sha256', $passphrase, hex2bin( $kdf_salt ), $iterations, 32, true );

	// Stream-decrypt in 1 MB chunks so large archives do not exhaust memory.
	$in = @fopen( $enc_path, 'rb' );
	if ( ! $in ) {
		return false;
	}
	$iv = (string) fread( $in, 16 );
	if ( 16 !== strlen( $iv ) ) {
		fclose( $in );
		return false;
	}
	$out = @fopen( $dest_zip_path, 'wb' );
	if ( ! $out ) {
		fclose( $in );
		return false;
	}

	$ok    = true;
	$chunk = 1048576;
	$cur   = (string) fread( $in, $chunk );
	if ( '' === $cur ) {
		$ok = false;
	}

	while ( $ok ) {
		$next    = feof( $in ) ? '' : (string) fread( $in, $chunk );
		$is_last = ( '' === $next );
		$flags   = $is_last ? OPENSSL_RAW_DATA : ( OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING );
		$pt      = openssl_decrypt( $cur, 'aes-256-cbc', $key, $flags, $iv );

		if ( false === $pt || false === fwrite( $out, $pt ) ) {
			$ok = false;
			break;
		}
		if ( $is_last ) {
			break;
		}
		$iv  = substr( $cur, -16 );
		$cur = $next;
	}

	fclose( $in );
	fclose( $out );
	if ( ! $ok ) {
		@unlink( $dest_zip_path );
	}

	return $ok;
}

/**
 * Execute a SQL dump file using mysqli with batched statement parsing.
 *
 * Limits: Semicolons outside quotes delimit statements. Custom DELIMITER directives
 * (triggers/stored procedures) are not supported in this starter pass.
 *
 * @param mysqli $mysqli   Active database connection.
 * @param string $filepath Path to database.sql.
 * @return array Stats array with statement and error counts.
 * @throws Exception If file cannot be read or serious failure occurs.
 */
/**
 * Rewrite one dump statement so it targets a staging copy of its table.
 *
 * Returns null when the statement must not run: it targets a table outside this
 * WordPress install's prefix, or is not a plain table/session statement.
 *
 * @param string $sql          Statement.
 * @param string $live_prefix  WordPress table prefix (e.g. wp_).
 * @param string $stage_prefix Prefix prepended to staged tables (e.g. lmrs_).
 * @param array  $staged       Accumulates staged table names (stage => live).
 * @return string|null
 */
function lumos_stage_statement( string $sql, string $live_prefix, string $stage_prefix, array &$staged ): ?string {
	$sql = ltrim( $sql );

	if ( preg_match( '/^(SET\s|UNLOCK\s+TABLES)/i', $sql ) ) {
		return $sql;
	}

	$pattern = '/^((?:DROP\s+TABLE(?:\s+IF\s+EXISTS)?|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|LOCK\s+TABLES|ALTER\s+TABLE)\s+)`([^`]+)`/i';
	if ( ! preg_match( $pattern, $sql, $m ) ) {
		return null;
	}

	$table = $m[2];
	if ( '' === $live_prefix || 0 !== strpos( $table, $live_prefix ) ) {
		return null; // Never touch tables that do not belong to this WordPress install.
	}

	$stage_name = $stage_prefix . $table;
	if ( preg_match( '/^CREATE\s+TABLE/i', $sql ) ) {
		$staged[ $stage_name ] = $table;
	}

	return $m[1] . '`' . $stage_name . '`' . substr( $sql, strlen( $m[0] ) );
}

/**
 * Run a statement through optional staging and execute it.
 *
 * @param mysqli     $mysqli  Connection.
 * @param string     $sql     Statement.
 * @param array|null $staging [ 'live' => prefix, 'stage' => prefix ] or null for direct execution.
 * @param array      $staged  Staged tables accumulator.
 * @return bool|null True on success, false on error, null when skipped.
 */
function lumos_run_dump_statement( mysqli $mysqli, string $sql, ?array $staging, array &$staged ): ?bool {
	if ( null !== $staging ) {
		$sql = lumos_stage_statement( $sql, $staging['live'], $staging['stage'], $staged );
		if ( null === $sql ) {
			return null;
		}
	}
	$GLOBALS['lumos_last_sql'] = $sql;
	return (bool) @$mysqli->query( $sql );
}

/**
 * Import a dump into staging tables, then atomically swap them in for the live tables.
 *
 * The live tables are untouched until every statement has succeeded. The final swap is
 * a single RENAME TABLE statement, which MySQL applies atomically.
 *
 * @param mysqli $mysqli   Connection.
 * @param string $filepath Path to database.sql.
 * @param string $prefix   WordPress table prefix.
 * @return array Stats.
 * @throws Exception On any failure (live tables are left as they were).
 */
function lumos_import_sql_atomically( mysqli $mysqli, string $filepath, string $prefix ): array {
	$stage = 'lmrs_';
	$old   = 'lmold_';

	// Remove leftovers from an earlier interrupted restore.
	lumos_drop_tables_like( $mysqli, $stage . $prefix );
	lumos_drop_tables_like( $mysqli, $old . $prefix );

	$staged = [];
	try {
		$stats = lumos_execute_sql_dump( $mysqli, $filepath, [ 'live' => $prefix, 'stage' => $stage ], $staged );
	} catch ( Throwable $t ) {
		lumos_drop_tables_like( $mysqli, $stage . $prefix );
		throw new Exception( 'SQL import failed (' . $t->getMessage() . '). Your live database was NOT changed.' );
	}

	if ( $stats['errors'] > 0 || empty( $staged ) ) {
		lumos_drop_tables_like( $mysqli, $stage . $prefix );
		$detail = $stats['errors'] > 0
			? implode( '; ', array_slice( $stats['details'], 0, 3 ) )
			: 'The archive contains no tables for prefix ' . $prefix;
		throw new Exception( "SQL import failed ({$detail}). Your live database was NOT changed." );
	}

	foreach ( $staged as $stage_name => $live_name ) {
		if ( strlen( $old . $live_name ) > 64 ) {
			lumos_drop_tables_like( $mysqli, $stage . $prefix );
			throw new Exception( "Table name too long to swap safely: {$live_name}. Your live database was NOT changed." );
		}
	}

	// Build one atomic RENAME: live -> old, staged -> live.
	$existing = [];
	$res      = @$mysqli->query( "SHOW TABLES LIKE '" . $mysqli->real_escape_string( addcslashes( $prefix, '_%\\' ) ) . "%'" );
	if ( $res ) {
		while ( $row = $res->fetch_array() ) {
			$existing[ $row[0] ] = true;
		}
		$res->free();
	}

	$pairs = [];
	foreach ( $staged as $stage_name => $live_name ) {
		if ( isset( $existing[ $live_name ] ) ) {
			$pairs[] = "`{$live_name}` TO `{$old}{$live_name}`";
		}
		$pairs[] = "`{$stage_name}` TO `{$live_name}`";
	}

	if ( ! @$mysqli->query( 'RENAME TABLE ' . implode( ', ', $pairs ) ) ) {
		$err = $mysqli->error;
		lumos_drop_tables_like( $mysqli, $stage . $prefix );
		throw new Exception( "Could not swap restored tables into place ({$err}). Your live database was NOT changed." );
	}

	lumos_drop_tables_like( $mysqli, $old . $prefix );

	return $stats;
}

/**
 * Drop every table whose name starts with the given string.
 *
 * @param mysqli $mysqli Connection.
 * @param string $start  Name prefix.
 */
function lumos_drop_tables_like( mysqli $mysqli, string $start ): void {
	$res = @$mysqli->query( "SHOW TABLES LIKE '" . $mysqli->real_escape_string( addcslashes( $start, '_%\\' ) ) . "%'" );
	if ( ! $res ) {
		return;
	}
	$names = [];
	while ( $row = $res->fetch_array() ) {
		$names[] = $row[0];
	}
	$res->free();
	foreach ( $names as $name ) {
		if ( 0 === strpos( $name, $start ) ) {
			@$mysqli->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $name ) . '`' );
		}
	}
}

function lumos_execute_sql_dump( mysqli $mysqli, string $filepath, ?array $staging = null, array &$staged = [] ): array {
	$fp = @fopen( $filepath, 'r' );
	if ( ! $fp ) {
		throw new Exception( 'Could not open database dump file: ' . basename( $filepath ) );
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

			// Comments handling
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
				// Inline comment '-- ' or '#'
				if ( '-' === $char && '-' === $next_char && ( $i + 2 >= $len || ' ' === $line[ $i + 2 ] || "\t" === $line[ $i + 2 ] || "\r" === $line[ $i + 2 ] || "\n" === $line[ $i + 2 ] ) ) {
					break; // Rest of line is comment
				}
				if ( '#' === $char ) {
					break; // Rest of line is comment
				}
			}

			if ( $in_multiline_comment ) {
				$i++;
				continue;
			}

			// Handle escaped characters inside quotes
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

			// Toggle string quotes
			if ( "'" === $char && ! $in_double_quote && ! $in_backtick ) {
				$in_single_quote = ! $in_single_quote;
			} elseif ( '"' === $char && ! $in_single_quote && ! $in_backtick ) {
				$in_double_quote = ! $in_double_quote;
			} elseif ( '`' === $char && ! $in_single_quote && ! $in_double_quote ) {
				$in_backtick = ! $in_backtick;
			}

			// Statement delimiter
			$delim_len = strlen( $current_delimiter );
			if ( ! $in_single_quote && ! $in_double_quote && ! $in_backtick && substr( $line, $i, $delim_len ) === $current_delimiter ) {
				$sql = trim( $query );
				if ( '' !== $sql ) {
					$ran = lumos_run_dump_statement( $mysqli, $sql, $staging, $staged );
					if ( false === $ran ) {
						$error_count++;
						if ( count( $errors ) < 5 ) {
							$errors[] = $mysqli->error . ' [SQL: ' . substr( $sql, 0, 80 ) . '...]';
						}
					} elseif ( true === $ran ) {
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

	// Remainder statement
	$sql = trim( $query );
	if ( '' !== $sql ) {
		$ran = lumos_run_dump_statement( $mysqli, $sql, $staging, $staged );
		if ( false === $ran ) {
			$error_count++;
			if ( count( $errors ) < 5 ) {
				$errors[] = $mysqli->error;
			}
		} elseif ( true === $ran ) {
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
 * Copy restored wp-content files back to the WordPress installation.
 *
 * CRITICAL GUARDS:
 * 1. Skips lumos-backup archive directory so existing archives are preserved.
 * 2. Never overwrites lumos-restore.php, wp-config.php, or restore-config.json.
 *
 * @param string $source_content Extracted wp-content directory.
 * @param string $target_content Target WP_CONTENT_DIR path.
 * @param string $archive_dir    Lumos archives directory to skip.
 * @return array Stats array of restored and skipped files.
 */
function lumos_restore_content_files( string $source_content, string $target_content, string $archive_dir ): array {
	$stats = [
		'restored' => 0,
		'skipped'  => 0,
	];

	if ( ! is_dir( $source_content ) ) {
		return $stats;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source_content, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	$archive_real = realpath( $archive_dir );

	foreach ( $iterator as $item ) {
		$relative_path = substr( $item->getPathname(), strlen( $source_content ) );
		$target_path   = rtrim( $target_content, '/\\' ) . $relative_path;

		// Normalized path for checks
		$target_norm = str_replace( '\\', '/', $target_path );

		// Guard 1: Skip lumos-backup directory
		if ( false !== strpos( $target_norm, '/uploads/lumos-backup' ) ) {
			$stats['skipped']++;
			continue;
		}
		if ( $archive_real ) {
			$target_real = realpath( dirname( $target_path ) );
			if ( $target_real && 0 === strpos( $target_real, $archive_real ) ) {
				$stats['skipped']++;
				continue;
			}
		}

		// Guard 2: Never overwrite lumos-restore.php, wp-config.php, restore-config.json, or recovery.md
		$basename = basename( $target_path );
		if (
			'lumos-restore.php' === $basename ||
			'wp-config.php' === $basename ||
			'restore-config.json' === $basename ||
			'restore-config.php' === $basename ||
			'recovery.md' === $basename ||
			'.lumos-maintenance' === $basename
		) {
			$stats['skipped']++;
			continue;
		}

		if ( $item->isDir() ) {
			if ( ! file_exists( $target_path ) ) {
				@mkdir( $target_path, 0755, true );
			}
		} else {
			$parent = dirname( $target_path );
			if ( ! file_exists( $parent ) ) {
				@mkdir( $parent, 0755, true );
			}
			if ( @copy( $item->getPathname(), $target_path ) ) {
				$stats['restored']++;
			}
		}
	}

	return $stats;
}

/**
 * Recursively remove a temporary directory.
 *
 * @param string $dir Path to directory.
 */
function lumos_remove_temp_dir( string $dir ): void {
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
			lumos_remove_temp_dir( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
}

// ============================================================================
// MAIN EXECUTION FLOW
// ============================================================================

$client_ip = lumos_get_client_ip();

// 1. Locate and validate configuration
$config_path = lumos_find_config_file();
if ( null === $config_path ) {
	lumos_exit_with_error(
		'500 Configuration Missing',
		'Could not find restore-config.json. Ensure the file exists in wp-content/uploads/lumos-backup/ and is readable by the web server.',
		500
	);
}

$config_raw = @file_get_contents( $config_path );
if ( is_string( $config_raw ) && 0 === strpos( $config_raw, '<?php' ) ) {
	// Strip the PHP exit guard line written by the plugin.
	$guard_end  = strpos( $config_raw, "\n" );
	$config_raw = false === $guard_end ? '' : substr( $config_raw, $guard_end + 1 );
}
$config     = $config_raw ? json_decode( $config_raw, true ) : null;

if ( ! is_array( $config ) || empty( $config['token_hash'] ) ) {
	lumos_exit_with_error(
		'500 Configuration Corrupted',
		'The restore-config.json file is unreadable or missing required credentials.',
		500
	);
}

$lumos_base_dir             = dirname( $config_path );
$rate_limits_file           = $lumos_base_dir . '/rate-limits.json';
$passphrase_rate_limits_file = $lumos_base_dir . '/rate-limits-passphrase.json';
$restore_log_file           = $lumos_base_dir . '/restore.log';

// 2. Check Key Rate Limiting (5 misses per IP per 15 minutes -> 429)
if ( ! lumos_check_rate_limit( $rate_limits_file, $client_ip ) ) {
	lumos_exit_with_error(
		'429 Too Many Requests',
		'Too many failed recovery key attempts from your IP address. Access is locked for 15 minutes.',
		429
	);
}

// 3. Authenticate secret token
$token = isset( $_POST['k'] ) ? (string) $_POST['k'] : ( isset( $_GET['k'] ) ? (string) $_GET['k'] : '' );
if ( '' === $token ) {
	lumos_record_failed_key( $rate_limits_file, $client_ip );
	lumos_exit_with_error(
		'403 Forbidden',
		'A secret restore token is required to access this console. Please provide the key in the URL (?k=<token>).',
		403
	);
}

$input_hash = hash( 'sha256', $token );
if ( ! hash_equals( $config['token_hash'], $input_hash ) ) {
	lumos_record_failed_key( $rate_limits_file, $client_ip );
	lumos_exit_with_error(
		'403 Forbidden',
		'Invalid or expired restore token. Verify your link or rotate the key in WordPress LUMOS → Backup.',
		403
	);
}

// Token Authentication Succeeded!
if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
	@session_start();
}

// 4. Archive Encryption Passphrase Gate
$encryption_enabled  = ! empty( $config['encryption']['enabled'] ) && ! empty( $config['encryption']['password_hash'] );
$verified_passphrase = '';

if ( $encryption_enabled ) {
	// Rate limit check for passphrase: 5 misses per IP per 15 minutes -> 429
	if ( ! lumos_check_rate_limit( $passphrase_rate_limits_file, $client_ip ) ) {
		lumos_exit_with_error(
			'429 Too Many Requests',
			'Too many failed passphrase attempts from your IP address. Access is locked for 15 minutes.',
			429
		);
	}

	$submitted_passphrase = isset( $_POST['passphrase'] ) ? (string) $_POST['passphrase'] : '';
	$session_passphrase   = isset( $_SESSION['lumos_passphrase'] ) ? (string) $_SESSION['lumos_passphrase'] : '';
	$passphrase_error     = '';

	if ( '' !== $submitted_passphrase ) {
		if ( password_verify( $submitted_passphrase, $config['encryption']['password_hash'] ) ) {
			$_SESSION['lumos_passphrase'] = $submitted_passphrase;
			$verified_passphrase          = $submitted_passphrase;
		} else {
			lumos_record_failed_key( $passphrase_rate_limits_file, $client_ip );
			$passphrase_error = 'Incorrect encryption passphrase. Please try again.';
		}
	} elseif ( '' !== $session_passphrase && password_verify( $session_passphrase, $config['encryption']['password_hash'] ) ) {
		$verified_passphrase = $session_passphrase;
	}

	// If passphrase is not yet verified, display passphrase challenge screen
	if ( '' === $verified_passphrase ) {
		$site_url = htmlspecialchars( $config['siteurl'] ?? '', ENT_QUOTES, 'UTF-8' );
		?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Passphrase Required - Lumos Emergency Restore</title>
	<style>
		:root {
			--bg: #0b0f19;
			--surface: #131b2e;
			--border: #2a3756;
			--primary: #38bdf8;
			--primary-hover: #0ea5e9;
			--text: #f1f5f9;
			--muted: #94a3b8;
			--danger: #ef4444;
			--warning: #f59e0b;
		}
		* { box-sizing: border-box; margin: 0; padding: 0; }
		body {
			background: var(--bg);
			color: var(--text);
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
			padding: 40px 20px;
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
		}
		.card {
			background: var(--surface);
			border: 1px solid var(--border);
			border-radius: 12px;
			max-width: 460px;
			width: 100%;
			padding: 32px;
			box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4);
		}
		.badge-enc {
			background: rgba(245, 158, 11, 0.15);
			color: var(--warning);
			font-size: 12px;
			font-weight: 700;
			padding: 4px 10px;
			border-radius: 9999px;
			text-transform: uppercase;
			display: inline-block;
			margin-bottom: 16px;
			border: 1px solid rgba(245, 158, 11, 0.3);
		}
		h1 { font-size: 20px; font-weight: 700; margin-bottom: 8px; }
		p { color: var(--muted); font-size: 14px; line-height: 1.5; margin-bottom: 20px; }
		.input-group { margin-bottom: 20px; }
		label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: var(--text); }
		input[type="password"] {
			width: 100%;
			padding: 10px 14px;
			border-radius: 6px;
			border: 1px solid var(--border);
			background: #0b0f19;
			color: var(--text);
			font-size: 14px;
		}
		input[type="password"]:focus {
			outline: none;
			border-color: var(--primary);
			box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.2);
		}
		.btn {
			width: 100%;
			padding: 11px;
			background: var(--primary);
			color: #0b0f19;
			border: none;
			border-radius: 6px;
			font-size: 14px;
			font-weight: 700;
			cursor: pointer;
		}
		.btn:hover { background: var(--primary-hover); }
		.error-box {
			background: rgba(239, 68, 68, 0.15);
			border: 1px solid var(--danger);
			color: #fecaca;
			padding: 10px 14px;
			border-radius: 6px;
			font-size: 13px;
			margin-bottom: 16px;
		}
	</style>
</head>
<body>
	<div class="card">
		<span class="badge-enc">&#128274; AES-256 Encrypted</span>
		<h1>Enter Restore Passphrase</h1>
		<p>Backup archives on this site are encrypted. Please enter your restore passphrase to inspect and restore backups.</p>

		<?php if ( ! empty( $passphrase_error ) ) : ?>
			<div class="error-box"><?php echo htmlspecialchars( $passphrase_error, ENT_QUOTES, 'UTF-8' ); ?></div>
		<?php endif; ?>

		<form method="post" action="<?php echo htmlspecialchars( $_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8' ); ?>">
			<input type="hidden" name="k" value="<?php echo htmlspecialchars( $token, ENT_QUOTES, 'UTF-8' ); ?>">
			<div class="input-group">
				<label for="passphrase">Archive Passphrase</label>
				<input type="password" id="passphrase" name="passphrase" required autofocus placeholder="Enter encryption passphrase">
			</div>
			<button type="submit" class="btn">Unlock & View Archives &rarr;</button>
		</form>
	</div>
</body>
</html>
		<?php
		exit;
	}
}

$maintenance_file = rtrim( $config['abspath'] ?? __DIR__, '/\\' ) . '/.lumos-maintenance';
$wp_maintenance_file    = rtrim( $config['abspath'] ?? __DIR__, '/\\' ) . '/.maintenance';
$wp_maintenance_created = false;
$s3_configured    = ! empty( $config['s3']['bucket'] ) && ! empty( $config['s3']['access_key'] ) && ! empty( $config['s3']['secret_key'] );

$current_do = $_POST['do'] ?? ( $_GET['do'] ?? '' );
$archive_id = trim( (string) ( $_POST['id'] ?? ( $_GET['id'] ?? '' ) ) );
$origin     = trim( (string) ( $_POST['origin'] ?? ( $_GET['origin'] ?? 'local' ) ) );
$s3_key     = trim( (string) ( $_POST['s3_key'] ?? ( $_GET['s3_key'] ?? '' ) ) );

// ============================================================================
// STEP 1: CONFIRMATION VIEW (Two-step confirmation + Domain Change form)
// ============================================================================
if ( 'confirm' === $current_do && '' !== $archive_id ) {
	if ( ! preg_match( '/^backup-\d{8}-\d{6}(-[a-f0-9]{16})?\.zip(\.enc)?$/', $archive_id ) ) {
		lumos_exit_with_error( '400 Bad Request', 'Invalid backup identifier.', 400 );
	}

	$zip_path          = '';
	$temp_download     = '';
	$temp_decrypted    = '';
	$file_size         = 0;
	$manifest_data     = [];
	$confirm_error     = '';
	$is_encrypted_file = substr( $archive_id, -4 ) === '.enc';

	if ( 'remote' === $origin && $s3_configured ) {
		// Download remote object to temp file to inspect manifest
		$temp_base = sys_get_temp_dir();
		if ( ! is_writable( $temp_base ) ) {
			$temp_base = $lumos_base_dir;
		}
		$temp_download = rtrim( $temp_base, '/\\' ) . '/lumos-dl-' . bin2hex( random_bytes( 6 ) ) . '-' . $archive_id;
		$download_key  = $s3_key ?: ( ( $config['s3']['prefix'] ? $config['s3']['prefix'] . '/' : '' ) . $archive_id );

		$dl_res = lumos_s3_raw_request( $config['s3'], 'GET', $download_key, [], $temp_download );
		if ( ! $dl_res['success'] || ! file_exists( $temp_download ) ) {
			$confirm_error = 'Failed to download remote archive from S3: ' . ( $dl_res['error'] ?? 'Unknown error' );
		} else {
			$zip_path  = $temp_download;
			$file_size = filesize( $temp_download );

			// Also download and verify remote .sha256 sidecar if present
			$temp_sidecar = $temp_download . '.sha256';
			$dl_sidecar   = lumos_s3_raw_request( $config['s3'], 'GET', $download_key . '.sha256', [], $temp_sidecar );
			if ( $dl_sidecar['success'] && file_exists( $temp_sidecar ) ) {
				$expected_sha256 = trim( (string) @file_get_contents( $temp_sidecar ) );
				$actual_sha256   = hash_file( 'sha256', $temp_download );
				if ( ! empty( $expected_sha256 ) && ! hash_equals( strtolower( $expected_sha256 ), strtolower( $actual_sha256 ) ) ) {
					$confirm_error = "Checksum verification failed: SHA-256 hash mismatch. Expected {$expected_sha256}, got {$actual_sha256}.";
				}
				@unlink( $temp_sidecar );
			}
		}
	} else {
		// Local archive
		$archive_dir = rtrim( $config['archive_dir'] ?? '', '/\\' );
		$local_path  = $archive_dir . DIRECTORY_SEPARATOR . $archive_id;
		if ( ! file_exists( $local_path ) || ! is_readable( $local_path ) ) {
			$confirm_error = 'Local archive file not found: ' . $archive_id;
		} else {
			$zip_path  = $local_path;
			$file_size = filesize( $local_path );

			// Verify local .sha256 sidecar
			$sidecar_path = $local_path . '.sha256';
			if ( file_exists( $sidecar_path ) && is_readable( $sidecar_path ) ) {
				$expected_sha256 = trim( (string) @file_get_contents( $sidecar_path ) );
				$actual_sha256   = hash_file( 'sha256', $local_path );
				if ( ! empty( $expected_sha256 ) && ! hash_equals( strtolower( $expected_sha256 ), strtolower( $actual_sha256 ) ) ) {
					$confirm_error = "Checksum verification failed: SHA-256 hash mismatch. Archive file has been modified or tampered with.";
				}
			}
		}
	}

	// Handle decryption if file is encrypted (.zip.enc)
	$inspect_zip_path = $zip_path;
	if ( empty( $confirm_error ) && $is_encrypted_file ) {
		$temp_base = sys_get_temp_dir();
		if ( ! is_writable( $temp_base ) ) {
			$temp_base = $lumos_base_dir;
		}
		$temp_decrypted = rtrim( $temp_base, '/\\' ) . '/lumos-dec-' . bin2hex( random_bytes( 6 ) ) . '.zip';

		$salt       = $config['encryption']['kdf_salt'] ?? '';
		$iterations = (int) ( $config['encryption']['iterations'] ?? 100000 );

		if ( ! lumos_decrypt_archive( $zip_path, $temp_decrypted, $verified_passphrase, $salt, $iterations ) ) {
			$confirm_error = 'Decryption failed. Please verify your passphrase.';
		} else {
			$inspect_zip_path = $temp_decrypted;
		}
	}

	if ( empty( $confirm_error ) && file_exists( $inspect_zip_path ) ) {
		$zip = new ZipArchive();
		if ( true === $zip->open( $inspect_zip_path, ZipArchive::CHECKCONS ) ) {
			$manifest_raw = $zip->getFromName( 'manifest.json' );
			if ( false !== $manifest_raw ) {
				$manifest_data = json_decode( $manifest_raw, true ) ?: [];
			}
			$zip->close();
		} else {
			$confirm_error = 'Could not open backup archive zip file.';
		}
	}

	// Clean up temporary inspected decrypted zip if created
	if ( ! empty( $temp_decrypted ) && file_exists( $temp_decrypted ) ) {
		@unlink( $temp_decrypted );
	}

	$manifest_siteurl = $manifest_data['siteurl'] ?? ( $config['siteurl'] ?? 'Unknown' );
	$manifest_home    = $manifest_data['home'] ?? ( $config['home'] ?? 'Unknown' );
	$manifest_created = $manifest_data['created'] ?? 'Unknown';
	$site_url         = htmlspecialchars( $config['siteurl'] ?? '', ENT_QUOTES, 'UTF-8' );
	?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Confirm Restore - Lumos Emergency Recovery</title>
	<style>
		:root {
			--bg: #0b0f19;
			--surface: #131b2e;
			--surface-card: #1c273e;
			--border: #2a3756;
			--primary: #38bdf8;
			--text: #f1f5f9;
			--muted: #94a3b8;
			--danger: #ef4444;
			--warning: #f59e0b;
		}
		* { box-sizing: border-box; margin: 0; padding: 0; }
		body {
			background: var(--bg);
			color: var(--text);
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
			padding: 40px 20px;
			min-height: 100vh;
			display: flex;
			flex-direction: column;
			align-items: center;
		}
		.container { max-width: 680px; width: 100%; }
		.card {
			background: var(--surface);
			border: 1px solid var(--border);
			border-radius: 12px;
			padding: 28px;
			box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
		}
		.badge-step {
			background: rgba(245, 158, 11, 0.15);
			color: var(--warning);
			font-size: 13px;
			font-weight: 700;
			padding: 4px 12px;
			border-radius: 9999px;
			display: inline-block;
			margin-bottom: 16px;
			border: 1px solid rgba(245, 158, 11, 0.3);
		}
		h1 { font-size: 22px; margin-bottom: 12px; }
		p { color: var(--muted); font-size: 14px; line-height: 1.6; }
		.meta-box {
			background: var(--surface-card);
			border: 1px solid var(--border);
			border-radius: 8px;
			padding: 20px;
			margin: 20px 0;
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 16px;
		}
		.meta-field label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 4px; text-transform: uppercase; font-weight: 600; }
		.meta-field span { font-size: 15px; font-weight: 600; color: var(--text); word-break: break-all; }
		.domain-box {
			background: #111a2e;
			border: 1px solid var(--border);
			border-radius: 8px;
			padding: 18px;
			margin-bottom: 20px;
		}
		.domain-box h3 { font-size: 15px; font-weight: 600; margin-bottom: 6px; color: var(--primary); }
		.domain-box p { font-size: 13px; margin-bottom: 14px; }
		.domain-field { margin-bottom: 12px; }
		.domain-field label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 4px; font-weight: 600; }
		.domain-field input[type="text"] {
			width: 100%;
			padding: 8px 12px;
			border-radius: 6px;
			border: 1px solid var(--border);
			background: #0b0f19;
			color: var(--text);
			font-size: 13px;
		}
		.btn {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 10px 20px;
			font-size: 14px;
			font-weight: 600;
			border-radius: 6px;
			border: none;
			cursor: pointer;
			text-decoration: none;
		}
		.btn-danger { background: #dc2626; color: #fff; }
		.btn-danger:hover { background: #b91c1c; }
		.btn-secondary { background: rgba(255,255,255,0.1); color: var(--text); margin-left: 10px; }
		.warning-box {
			background: rgba(239, 68, 68, 0.12);
			border: 1px solid rgba(239, 68, 68, 0.3);
			color: #fecaca;
			padding: 14px 18px;
			border-radius: 8px;
			margin-bottom: 24px;
			font-size: 14px;
			line-height: 1.5;
		}
	</style>
</head>
<body>
	<div class="container">
		<div class="card">
			<span class="badge-step">Step 2 of 2: Confirmation Required</span>
			<h1>Confirm Disaster Recovery Restore</h1>
			<p>Please review the archive manifest details below before initiating restore.</p>

			<?php if ( ! empty( $confirm_error ) ) : ?>
				<div class="warning-box" style="margin-top: 16px;">
					<strong>Error preparing archive:</strong> <?php echo htmlspecialchars( $confirm_error, ENT_QUOTES, 'UTF-8' ); ?>
				</div>
				<a href="<?php echo htmlspecialchars( $_SERVER['PHP_SELF'] . '?k=' . rawurlencode( $token ), ENT_QUOTES, 'UTF-8' ); ?>" class="btn btn-secondary">
					&larr; Return to Archives List
				</a>
			<?php else : ?>
				<div class="meta-box">
					<div class="meta-field">
						<label>Archive Identifier</label>
						<span><code><?php echo htmlspecialchars( $archive_id, ENT_QUOTES, 'UTF-8' ); ?></code></span>
					</div>
					<div class="meta-field">
						<label>Storage Origin</label>
						<span><?php echo ( 'remote' === $origin ) ? 'Remote S3 Bucket' : 'Local Server Disk'; ?></span>
					</div>
					<div class="meta-field">
						<label>Created Time (UTC)</label>
						<span><?php echo htmlspecialchars( $manifest_created, ENT_QUOTES, 'UTF-8' ); ?></span>
					</div>
					<div class="meta-field">
						<label>Archive File Size</label>
						<span><?php echo htmlspecialchars( lumos_format_size( $file_size ), ENT_QUOTES, 'UTF-8' ); ?></span>
					</div>
					<div class="meta-field">
						<label>Manifest Site URL</label>
						<span><code><?php echo htmlspecialchars( $manifest_siteurl, ENT_QUOTES, 'UTF-8' ); ?></code></span>
					</div>
					<div class="meta-field">
						<label>Manifest Home URL</label>
						<span><code><?php echo htmlspecialchars( $manifest_home, ENT_QUOTES, 'UTF-8' ); ?></code></span>
					</div>
				</div>

				<form method="post" action="<?php echo htmlspecialchars( $_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8' ); ?>">
					<input type="hidden" name="k" value="<?php echo htmlspecialchars( $token, ENT_QUOTES, 'UTF-8' ); ?>">
					<input type="hidden" name="do" value="restore">
					<input type="hidden" name="id" value="<?php echo htmlspecialchars( $archive_id, ENT_QUOTES, 'UTF-8' ); ?>">
					<input type="hidden" name="origin" value="<?php echo htmlspecialchars( $origin, ENT_QUOTES, 'UTF-8' ); ?>">
					<input type="hidden" name="s3_key" value="<?php echo htmlspecialchars( $s3_key, ENT_QUOTES, 'UTF-8' ); ?>">
					<input type="hidden" name="temp_download" value="<?php echo htmlspecialchars( $temp_download, ENT_QUOTES, 'UTF-8' ); ?>">
					<input type="hidden" name="orig_siteurl" value="<?php echo htmlspecialchars( $manifest_siteurl, ENT_QUOTES, 'UTF-8' ); ?>">
					<input type="hidden" name="orig_home" value="<?php echo htmlspecialchars( $manifest_home, ENT_QUOTES, 'UTF-8' ); ?>">
					<?php if ( ! empty( $verified_passphrase ) ) : ?>
						<input type="hidden" name="passphrase" value="<?php echo htmlspecialchars( $verified_passphrase, ENT_QUOTES, 'UTF-8' ); ?>">
					<?php endif; ?>

					<!-- Optional Domain Change -->
					<div class="domain-box">
						<h3>Domain / URL Replacement (Optional)</h3>
						<p>If restoring to a new domain, staging site, or changed protocol, specify the new URLs below. Lumos Backup will perform a serialized-safe replacement across options, posts (skipping GUIDs), postmeta, usermeta, and comments without corrupting PHP serialized arrays.</p>
						<div class="domain-field">
							<label for="new_siteurl">New Site URL</label>
							<input type="text" id="new_siteurl" name="new_siteurl" value="" placeholder="e.g. <?php echo htmlspecialchars( $manifest_siteurl, ENT_QUOTES, 'UTF-8' ); ?>">
						</div>
						<div class="domain-field">
							<label for="new_home">New Home URL</label>
							<input type="text" id="new_home" name="new_home" value="" placeholder="e.g. <?php echo htmlspecialchars( $manifest_home, ENT_QUOTES, 'UTF-8' ); ?>">
						</div>
					</div>

					<div class="warning-box">
						<strong>WARNING:</strong> This action will overwrite existing database tables and replace files in <code>wp-content/</code> with the contents of this archive. An emergency maintenance file (<code>.lumos-maintenance</code>) will be active during the process.
					</div>

					<button type="submit" class="btn btn-danger">Confirm & Perform Restore Now</button>
					<a href="<?php echo htmlspecialchars( $_SERVER['PHP_SELF'] . '?k=' . rawurlencode( $token ), ENT_QUOTES, 'UTF-8' ); ?>" class="btn btn-secondary">
						Cancel
					</a>
				</form>
			<?php endif; ?>
		</div>
	</div>
</body>
</html>
	<?php
	exit;
}

// ============================================================================
// STEP 2: RESTORE EXECUTION (do=restore)
// ============================================================================
$action_result = null;
if ( 'POST' === $_SERVER['REQUEST_METHOD'] && 'restore' === $current_do ) {
	@set_time_limit( 600 );
	@ini_set( 'memory_limit', '512M' );

	$archive_id = trim( (string) ( $_POST['id'] ?? '' ) );
	$origin     = trim( (string) ( $_POST['origin'] ?? 'local' ) );

	if ( ! preg_match( '/^backup-\d{8}-\d{6}(-[a-f0-9]{16})?\.zip(\.enc)?$/', $archive_id ) ) {
		$action_result = [
			'success' => false,
			'message' => 'Invalid backup filename identifier. Must match backup-YYYYMMDD-HHMMSS.zip(.enc).',
		];
		lumos_log_restore( $restore_log_file, $client_ip, $archive_id, 'FAILURE', 'Invalid archive identifier' );
	} elseif ( ! class_exists( 'ZipArchive' ) ) {
		$action_result = [
			'success' => false,
			'message' => 'PHP ZipArchive extension is not available on this server.',
		];
		lumos_log_restore( $restore_log_file, $client_ip, $archive_id, 'FAILURE', 'ZipArchive extension missing' );
	} else {
		// Resolve archive file path
		$archive_path   = '';
		$sidecar_path   = '';
		$temp_remote    = trim( (string) ( $_POST['temp_download'] ?? '' ) );
		$temp_decrypted = '';

		if ( 'remote' === $origin ) {
			if ( ! empty( $temp_remote ) && file_exists( $temp_remote ) && is_readable( $temp_remote ) ) {
				$archive_path = $temp_remote;
			} elseif ( $s3_configured ) {
				// Download fresh copy
				$temp_base = sys_get_temp_dir();
				if ( ! is_writable( $temp_base ) ) {
					$temp_base = $lumos_base_dir;
				}
				$archive_path = rtrim( $temp_base, '/\\' ) . '/lumos-dl-' . bin2hex( random_bytes( 6 ) ) . '-' . $archive_id;
				$dl_key       = $s3_key ?: ( ( $config['s3']['prefix'] ? $config['s3']['prefix'] . '/' : '' ) . $archive_id );
				$dl_res       = lumos_s3_raw_request( $config['s3'], 'GET', $dl_key, [], $archive_path );
				if ( ! $dl_res['success'] || ! file_exists( $archive_path ) ) {
					$action_result = [
						'success' => false,
						'message' => 'Could not download remote archive from S3: ' . ( $dl_res['error'] ?? 'Unknown error' ),
					];
					lumos_log_restore( $restore_log_file, $client_ip, $archive_id, 'FAILURE', 'Remote S3 download failed' );
				}
			} else {
				$action_result = [
					'success' => false,
					'message' => 'Remote S3 storage credentials are not configured.',
				];
				lumos_log_restore( $restore_log_file, $client_ip, $archive_id, 'FAILURE', 'S3 credentials not configured' );
			}

			// Download remote sidecar
			if ( ! empty( $archive_path ) && file_exists( $archive_path ) ) {
				$sidecar_dl = $archive_path . '.sha256';
				$dl_key     = $s3_key ?: ( ( $config['s3']['prefix'] ? $config['s3']['prefix'] . '/' : '' ) . $archive_id );
				$sc_res     = lumos_s3_raw_request( $config['s3'], 'GET', $dl_key . '.sha256', [], $sidecar_dl );
				if ( $sc_res['success'] && file_exists( $sidecar_dl ) ) {
					$sidecar_path = $sidecar_dl;
				}
			}
		} else {
			// Local archive path
			$archive_dir  = rtrim( $config['archive_dir'] ?? '', '/\\' );
			$archive_path = $archive_dir . DIRECTORY_SEPARATOR . $archive_id;
			if ( ! file_exists( $archive_path ) || ! is_readable( $archive_path ) ) {
				$action_result = [
					'success' => false,
					'message' => 'The selected backup archive file cannot be found: ' . $archive_id,
				];
				lumos_log_restore( $restore_log_file, $client_ip, $archive_id, 'FAILURE', 'Local archive file missing' );
			} else {
				$local_sc = $archive_path . '.sha256';
				if ( file_exists( $local_sc ) && is_readable( $local_sc ) ) {
					$sidecar_path = $local_sc;
				}
			}
		}

		if ( null === $action_result && ! empty( $archive_path ) ) {
			// Setup extraction temp directory
			$temp_base   = sys_get_temp_dir();
			if ( ! is_writable( $temp_base ) ) {
				$temp_base = $lumos_base_dir;
			}
			$extract_dir = rtrim( $temp_base, '/\\' ) . DIRECTORY_SEPARATOR . 'lumos-rst-' . bin2hex( random_bytes( 8 ) );

			try {
				// 1. CHECKSUM VERIFICATION BEFORE EXTRACT
				// CRITICAL: Mismatch aborts immediately and does not touch database or files.
				if ( ! empty( $sidecar_path ) && file_exists( $sidecar_path ) ) {
					$expected_sha256 = trim( (string) @file_get_contents( $sidecar_path ) );
					$actual_sha256   = hash_file( 'sha256', $archive_path );

					if ( ! empty( $expected_sha256 ) && ! hash_equals( strtolower( $expected_sha256 ), strtolower( $actual_sha256 ) ) ) {
						throw new Exception( "Checksum verification failed: SHA-256 hash mismatch for {$archive_id} (Expected: {$expected_sha256}, Got: {$actual_sha256}). Archive may be corrupted or tampered with. Extraction aborted without modifying database or files." );
					}
				}

				// 2. ENCRYPTION DECRYPTION HANDLING
				$zip_to_extract = $archive_path;
				if ( substr( $archive_id, -4 ) === '.enc' ) {
					$temp_decrypted = rtrim( $temp_base, '/\\' ) . '/lumos-dec-' . bin2hex( random_bytes( 6 ) ) . '.zip';
					$salt           = $config['encryption']['kdf_salt'] ?? '';
					$iterations     = (int) ( $config['encryption']['iterations'] ?? 100000 );
					$pass           = ! empty( $verified_passphrase ) ? $verified_passphrase : trim( (string) ( $_POST['passphrase'] ?? '' ) );

					if ( empty( $pass ) ) {
						throw new Exception( 'Encryption passphrase is required to decrypt this archive.' );
					}

					if ( ! lumos_decrypt_archive( $archive_path, $temp_decrypted, $pass, $salt, $iterations ) ) {
						throw new Exception( 'Decryption failed: Incorrect passphrase or damaged archive.' );
					}

					// Verify the inner zip integrity
					$inner_test = new ZipArchive();
					if ( true !== $inner_test->open( $temp_decrypted, ZipArchive::CHECKCONS ) ) {
						throw new Exception( 'Decrypted payload is not a valid zip archive.' );
					}
					$inner_test->close();

					$zip_to_extract = $temp_decrypted;
				}

				// 3. Write ABSPATH/.lumos-maintenance file before restore starts
				@file_put_contents( $maintenance_file, 'Lumos Backup Restore In Progress at ' . gmdate( 'c' ) );

				if ( ! @mkdir( $extract_dir, 0700, true ) ) {
					throw new Exception( 'Failed to create temporary extraction directory.' );
				}

				$zip = new ZipArchive();
				if ( true !== $zip->open( $zip_to_extract ) ) {
					throw new Exception( 'Failed to open backup zip archive.' );
				}
				$zip->extractTo( $extract_dir );
				$zip->close();

				// 4. Database Import
				$sql_file  = $extract_dir . DIRECTORY_SEPARATOR . 'database.sql';
				$sql_stats = [ 'statements' => 0, 'errors' => 0, 'details' => [] ];

				if ( file_exists( $sql_file ) ) {
					$db_host   = $config['db_host'] ?? 'localhost';
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

					$mysqli = @new mysqli(
						$db_host,
						$config['db_user'] ?? '',
						$config['db_password'] ?? '',
						$config['db_name'] ?? '',
						$db_port,
						$db_socket
					);

					if ( $mysqli->connect_error ) {
						throw new Exception( 'Database connection failed: ' . $mysqli->connect_error );
					}

					$charset = ! empty( $config['db_charset'] ) ? $config['db_charset'] : 'utf8mb4';
					@$mysqli->set_charset( $charset );

					// Import into staging tables and swap atomically. On any failure this throws
					// and the live database is left exactly as it was; file copy never starts.
					$prefix = $config['db_prefix'] ?? 'wp_';
					try {
						$sql_stats = lumos_import_sql_atomically( $mysqli, $sql_file, $prefix );
					} catch ( Exception $import_error ) {
						$mysqli->close();
						throw $import_error;
					}

					// Show WordPress's own maintenance screen to visitors while URLs and files are updated.
					if ( ! file_exists( $wp_maintenance_file ) ) {
						$wp_maintenance_created = false !== @file_put_contents( $wp_maintenance_file, '<?php $upgrading = ' . time() . '; ?>' );
					}

					// 5. DOMAIN CHANGE ON RESTORE (Serialized-Safe)
					$orig_siteurl = trim( (string) ( $_POST['orig_siteurl'] ?? '' ) );
					$orig_home    = trim( (string) ( $_POST['orig_home'] ?? '' ) );
					$new_siteurl  = trim( (string) ( $_POST['new_siteurl'] ?? '' ) );
					$new_home     = trim( (string) ( $_POST['new_home'] ?? '' ) );

					// Fallback: read manifest if original urls not passed
					if ( '' === $orig_siteurl ) {
						$manifest_f = $extract_dir . DIRECTORY_SEPARATOR . 'manifest.json';
						if ( file_exists( $manifest_f ) ) {
							$m_data       = json_decode( (string) file_get_contents( $manifest_f ), true );
							$orig_siteurl = $m_data['siteurl'] ?? '';
							$orig_home    = $m_data['home'] ?? '';
						}
					}

					// Build replacement pairs
					$replacements = [];
					if ( '' !== $new_siteurl && '' !== $orig_siteurl && $new_siteurl !== $orig_siteurl ) {
						$replacements[ rtrim( $orig_siteurl, '/' ) ] = rtrim( $new_siteurl, '/' );
					}
					if ( '' !== $new_home && '' !== $orig_home && $new_home !== $orig_home ) {
						$home_orig_clean = rtrim( $orig_home, '/' );
						if ( ! isset( $replacements[ $home_orig_clean ] ) ) {
							$replacements[ $home_orig_clean ] = rtrim( $new_home, '/' );
						}
					}

					lumos_replace_domains_in_database( $mysqli, $prefix, $replacements, $new_siteurl, $new_home );

					$mysqli->close();
				}

				// 6. Restore wp-content files (ONLY reached if SQL succeeded!)
				$content_source = $extract_dir . DIRECTORY_SEPARATOR . 'wp-content';
				$content_target = $config['content_dir'] ?? ( __DIR__ . '/wp-content' );
				$content_stats  = lumos_restore_content_files( $content_source, $content_target, $config['archive_dir'] ?? '' );

				$action_result = [
					'success'        => true,
					'archive_id'     => $archive_id,
					'db_statements'  => $sql_stats['statements'],
					'db_errors'      => $sql_stats['errors'],
					'files_restored' => $content_stats['restored'],
				];

				lumos_log_restore(
					$restore_log_file,
					$client_ip,
					$archive_id,
					'SUCCESS',
					"Statements: {$sql_stats['statements']}, Files: {$content_stats['restored']}"
				);

			} catch ( Exception $e ) {
				$action_result = [
					'success' => false,
					'message' => $e->getMessage(),
				];
				lumos_log_restore( $restore_log_file, $client_ip, $archive_id, 'FAILURE', $e->getMessage() );
			} finally {
				// Clean extraction directory
				lumos_remove_temp_dir( $extract_dir );

				// Clean temp downloaded files if remote
				if ( 'remote' === $origin && ! empty( $archive_path ) && file_exists( $archive_path ) ) {
					@unlink( $archive_path );
				}
				if ( ! empty( $sidecar_path ) && 0 === strpos( $sidecar_path, sys_get_temp_dir() ) && file_exists( $sidecar_path ) ) {
					@unlink( $sidecar_path );
				}

				// Clean decrypted temp zip
				if ( ! empty( $temp_decrypted ) && file_exists( $temp_decrypted ) ) {
					@unlink( $temp_decrypted );
				}

				// Remove the WordPress maintenance file only if this restore created it.
				if ( $wp_maintenance_created && file_exists( $wp_maintenance_file ) ) {
					@unlink( $wp_maintenance_file );
				}

				// CRITICAL: Delete ABSPATH/.lumos-maintenance after success or failure
				if ( file_exists( $maintenance_file ) ) {
					@unlink( $maintenance_file );
				}
			}
		}
	}
}

// ============================================================================
// STEP 0: ARCHIVES LIST DASHBOARD
// ============================================================================
$local_archives = [];
$archive_dir    = $config['archive_dir'] ?? '';
if ( is_dir( $archive_dir ) && is_readable( $archive_dir ) ) {
	$scanned = scandir( $archive_dir );
	if ( false !== $scanned ) {
		foreach ( $scanned as $item ) {
			if ( ! preg_match( '/^backup-\d{8}-\d{6}(-[a-f0-9]{16})?\.zip(\.enc)?$/', $item ) ) {
				continue;
			}
			$path = $archive_dir . DIRECTORY_SEPARATOR . $item;
			if ( ! is_file( $path ) ) {
				continue;
			}
			$mtime            = filemtime( $path );
			$is_enc           = substr( $item, -4 ) === '.enc';
			$local_archives[] = [
				'id'           => $item,
				'size'         => lumos_format_size( (int) filesize( $path ) ),
				'mtime'        => $mtime,
				'datetime'     => gmdate( 'Y-m-d H:i:s', $mtime ),
				'origin'       => 'local',
				'is_encrypted' => $is_enc,
			];
		}
	}
}

$remote_archives = [];
if ( $s3_configured ) {
	$remote_archives = lumos_list_s3_archives( $config['s3'] );
}

$all_archives = array_merge( $local_archives, $remote_archives );
usort(
	$all_archives,
	function ( $a, $b ) {
		return $b['mtime'] <=> $a['mtime'];
	}
);

$site_url  = htmlspecialchars( $config['siteurl'] ?? '', ENT_QUOTES, 'UTF-8' );
$admin_url = rtrim( $site_url, '/' ) . '/wp-admin/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Lumos Backup - Standalone Emergency Restore</title>
	<style>
		:root {
			--bg: #0b0f19;
			--surface: #131b2e;
			--surface-card: #1c273e;
			--border: #2a3756;
			--primary: #38bdf8;
			--primary-hover: #0ea5e9;
			--text: #f1f5f9;
			--muted: #94a3b8;
			--success: #10b981;
			--danger: #ef4444;
			--warning: #f59e0b;
		}
		* { box-sizing: border-box; margin: 0; padding: 0; }
		body {
			background: var(--bg);
			color: var(--text);
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
			padding: 40px 20px;
			min-height: 100vh;
			display: flex;
			flex-direction: column;
			align-items: center;
		}
		.container {
			max-width: 900px;
			width: 100%;
		}
		.header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			margin-bottom: 24px;
			padding-bottom: 20px;
			border-bottom: 1px solid var(--border);
		}
		.title-area h1 {
			font-size: 24px;
			font-weight: 700;
			letter-spacing: -0.5px;
			display: flex;
			align-items: center;
			gap: 10px;
		}
		.badge-emergency {
			background: rgba(245, 158, 11, 0.15);
			color: var(--warning);
			font-size: 12px;
			font-weight: 600;
			padding: 4px 10px;
			border-radius: 9999px;
			text-transform: uppercase;
			letter-spacing: 0.5px;
			border: 1px solid rgba(245, 158, 11, 0.3);
		}
		.site-info {
			color: var(--muted);
			font-size: 14px;
			margin-top: 4px;
		}
		.card {
			background: var(--surface);
			border: 1px solid var(--border);
			border-radius: 12px;
			padding: 24px;
			margin-bottom: 24px;
			box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
		}
		.card-title {
			font-size: 18px;
			font-weight: 600;
			margin-bottom: 16px;
			display: flex;
			align-items: center;
			justify-content: space-between;
		}
		.alert {
			border-radius: 8px;
			padding: 16px 20px;
			margin-bottom: 24px;
			font-size: 15px;
			line-height: 1.5;
		}
		.alert-success {
			background: rgba(16, 185, 129, 0.15);
			border: 1px solid var(--success);
			color: #a7f3d0;
		}
		.alert-error {
			background: rgba(239, 68, 68, 0.15);
			border: 1px solid var(--danger);
			color: #fecaca;
		}
		.alert-info {
			background: rgba(56, 189, 248, 0.12);
			border: 1px solid var(--primary);
			color: #bae6fd;
		}
		table {
			width: 100%;
			border-collapse: collapse;
			text-align: left;
			font-size: 14px;
		}
		th {
			background: var(--surface-card);
			color: var(--muted);
			font-weight: 600;
			padding: 12px 16px;
			border-bottom: 1px solid var(--border);
		}
		th:first-child { border-top-left-radius: 8px; }
		th:last-child { border-top-right-radius: 8px; text-align: right; }
		td {
			padding: 14px 16px;
			border-bottom: 1px solid var(--border);
			vertical-align: middle;
		}
		td:last-child { text-align: right; }
		tr:last-child td { border-bottom: none; }
		.archive-name {
			font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
			font-weight: 600;
			color: #e2e8f0;
		}
		.badge-origin {
			font-size: 11px;
			font-weight: 700;
			padding: 2px 8px;
			border-radius: 4px;
			display: inline-block;
			margin-right: 4px;
		}
		.badge-local { background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); }
		.badge-remote { background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); }
		.badge-enc { background: rgba(168, 85, 247, 0.15); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.3); }
		.btn {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 8px 16px;
			font-size: 13px;
			font-weight: 600;
			border-radius: 6px;
			border: none;
			cursor: pointer;
			text-decoration: none;
			transition: all 0.15s ease-in-out;
		}
		.btn-primary { background: var(--primary); color: #0b0f19; }
		.btn-primary:hover { background: var(--primary-hover); }
		.btn-restore { background: #dc2626; color: #ffffff; }
		.btn-restore:hover { background: #b91c1c; }
		.empty-state {
			text-align: center;
			padding: 40px 20px;
			color: var(--muted);
		}
		.meta-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 16px;
			margin-top: 16px;
			padding-top: 16px;
			border-top: 1px solid var(--border);
			font-size: 13px;
		}
		.meta-item { color: var(--muted); }
		.meta-item strong { color: var(--text); display: block; margin-top: 2px; }
		.footer {
			margin-top: auto;
			padding-top: 30px;
			text-align: center;
			font-size: 13px;
			color: var(--muted);
		}
	</style>
</head>
<body>
	<div class="container">
		<header class="header">
			<div class="title-area">
				<h1>Lumos Backup <span class="badge-emergency">Standalone Restore</span></h1>
				<div class="site-info">
					Target Site: <strong><?php echo $site_url; ?></strong> &bull; Database: <strong><?php echo htmlspecialchars( $config['db_name'] ?? '', ENT_QUOTES, 'UTF-8' ); ?></strong>
				</div>
			</div>
			<div>
				<a href="<?php echo $site_url; ?>" class="btn btn-primary" target="_blank" rel="noopener">Visit Site &rarr;</a>
			</div>
		</header>

		<?php if ( ! empty( $action_result ) ) : ?>
			<?php if ( $action_result['success'] ) : ?>
				<div class="alert alert-success">
					<h3 style="margin-bottom: 8px;">Restore Completed Successfully!</h3>
					<p>Restored archive: <strong><?php echo htmlspecialchars( $action_result['archive_id'], ENT_QUOTES, 'UTF-8' ); ?></strong></p>
					<ul style="margin-left: 20px; margin-top: 8px;">
						<li>Executed <strong><?php echo (int) $action_result['db_statements']; ?></strong> database statements.</li>
						<li>Restored <strong><?php echo (int) $action_result['files_restored']; ?></strong> wp-content files.</li>
					</ul>
					<div style="margin-top: 16px;">
						<a href="<?php echo $admin_url; ?>" class="btn btn-primary" style="margin-right: 8px;">Log in to WordPress Admin</a>
						<a href="<?php echo htmlspecialchars( $_SERVER['PHP_SELF'] . '?k=' . rawurlencode( $token ), ENT_QUOTES, 'UTF-8' ); ?>" class="btn" style="background: rgba(255,255,255,0.1); color: var(--text);">Return to Archives</a>
					</div>
				</div>
			<?php else : ?>
				<div class="alert alert-error">
					<h3 style="margin-bottom: 8px;">Restore Operation Halted</h3>
					<p><?php echo htmlspecialchars( $action_result['message'], ENT_QUOTES, 'UTF-8' ); ?></p>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<div class="alert alert-info">
			<strong>Disaster Recovery Mode:</strong> This console is loaded directly by PHP without bootstrapping WordPress. It restores both local disk backups and off-site S3 archives, verifies SHA-256 integrity, supports AES-256 encrypted backups, and safely rewrites domains upon request.
		</div>

		<div class="card">
			<div class="card-title">
				<span>Available Backup Archives</span>
				<span style="font-size: 13px; color: var(--muted); font-weight: normal;">
					Total: <?php echo count( $all_archives ); ?>
					<?php if ( $s3_configured ) : ?>
						&bull; (Local + Remote S3)
					<?php endif; ?>
				</span>
			</div>

			<?php if ( empty( $all_archives ) ) : ?>
				<div class="empty-state">
					<p>No backup archives found locally or in remote storage.</p>
				</div>
			<?php else : ?>
				<table>
					<thead>
						<tr>
							<th>Archive Filename</th>
							<th>Origin / Security</th>
							<th>Created (UTC)</th>
							<th>Size</th>
							<th>Action</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $all_archives as $arch ) : ?>
							<?php
							$is_remote = ( 'remote' === ( $arch['origin'] ?? 'local' ) );
							$is_enc    = ! empty( $arch['is_encrypted'] );
							?>
							<tr>
								<td><span class="archive-name"><?php echo htmlspecialchars( $arch['id'], ENT_QUOTES, 'UTF-8' ); ?></span></td>
								<td>
									<?php if ( $is_remote ) : ?>
										<span class="badge-origin badge-remote">Remote S3</span>
									<?php else : ?>
										<span class="badge-origin badge-local">Local Disk</span>
									<?php endif; ?>

									<?php if ( $is_enc ) : ?>
										<span class="badge-origin badge-enc">&#128274; AES-256</span>
									<?php endif; ?>
								</td>
								<td style="color: var(--muted);"><?php echo htmlspecialchars( $arch['datetime'], ENT_QUOTES, 'UTF-8' ); ?></td>
								<td><?php echo htmlspecialchars( $arch['size'], ENT_QUOTES, 'UTF-8' ); ?></td>
								<td>
									<!-- First Click Triggers Step 1 Confirmation Screen -->
									<form method="post" action="<?php echo htmlspecialchars( $_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8' ); ?>">
										<input type="hidden" name="k" value="<?php echo htmlspecialchars( $token, ENT_QUOTES, 'UTF-8' ); ?>">
										<input type="hidden" name="do" value="confirm">
										<input type="hidden" name="id" value="<?php echo htmlspecialchars( $arch['id'], ENT_QUOTES, 'UTF-8' ); ?>">
										<input type="hidden" name="origin" value="<?php echo htmlspecialchars( $arch['origin'] ?? 'local', ENT_QUOTES, 'UTF-8' ); ?>">
										<?php if ( ! empty( $arch['key'] ) ) : ?>
											<input type="hidden" name="s3_key" value="<?php echo htmlspecialchars( $arch['key'], ENT_QUOTES, 'UTF-8' ); ?>">
										<?php endif; ?>
										<?php if ( ! empty( $verified_passphrase ) ) : ?>
											<input type="hidden" name="passphrase" value="<?php echo htmlspecialchars( $verified_passphrase, ENT_QUOTES, 'UTF-8' ); ?>">
										<?php endif; ?>
										<button type="submit" class="btn btn-restore">Restore</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<div class="meta-grid">
				<div class="meta-item">
					Target Content Directory:
					<strong><?php echo htmlspecialchars( $config['content_dir'] ?? 'Unknown', ENT_QUOTES, 'UTF-8' ); ?></strong>
				</div>
				<div class="meta-item">
					WordPress Root:
					<strong><?php echo htmlspecialchars( $config['abspath'] ?? 'Unknown', ENT_QUOTES, 'UTF-8' ); ?></strong>
				</div>
				<div class="meta-item">
					Table Prefix:
					<strong><?php echo htmlspecialchars( $config['db_prefix'] ?? 'wp_', ENT_QUOTES, 'UTF-8' ); ?></strong>
				</div>
			</div>
		</div>

		<footer class="footer">
			Lumos Backup &bull; Standalone Emergency Recovery &bull; Runs without WordPress
		</footer>
	</div>
</body>
</html>
