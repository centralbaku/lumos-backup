<?php
/**
 * WP-CLI command integration for Lumos Backup.
 *
 * Provides command-line interface for backups, restores, drills, and token management.
 *
 * @package LumosBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage Lumos Backup archives, restore drills, and emergency recovery.
 */
class Lumos_Backup_CLI {

	/**
	 * Create a new full backup snapshot.
	 *
	 * ## OPTIONS
	 *
	 * [--encrypt]
	 * : Encrypt the archive using the configured passphrase.
	 *
	 * [--passphrase=<passphrase>]
	 * : Provide or override encryption passphrase for this run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lumos backup
	 *     wp lumos backup --encrypt
	 *     wp lumos backup --encrypt --passphrase="secretpassword"
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value flags.
	 */
	public function backup( $args, $assoc_args ) {
		WP_CLI::line( 'Initiating Lumos Backup snapshot...' );

		if ( isset( $assoc_args['passphrase'] ) ) {
			Lumos_Backup_Restore_Gate::set_passphrase( sanitize_text_field( $assoc_args['passphrase'] ) );
		}

		$archiver = new Lumos_Backup_Archiver();
		$result   = $archiver->create_backup();

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( sprintf( 'Backup failed: %s', $result->get_error_message() ) );
		}

		WP_CLI::success( sprintf( 'Backup completed successfully: %s (%s)', $result['filename'], $result['size_formatted'] ) );
		WP_CLI::line( sprintf( 'SHA-256 Checksum: %s', $result['sha256'] ) );

		if ( ! empty( $result['s3']['uri'] ) ) {
			WP_CLI::line( sprintf( 'S3 Remote Upload: %s', $result['s3']['uri'] ) );
		}
	}

	/**
	 * List available backup archives (local disk and remote storage).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lumos list
	 *     wp lumos list --format=json
	 *
	 * @subcommand list
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value flags.
	 */
	public function list_( $args, $assoc_args ) {
		$local_archives = Lumos_Backup_Storage::get_archives();
		$combined       = [];

		foreach ( $local_archives as $a ) {
			$combined[] = [
				'filename' => $a['filename'],
				'location' => 'local',
				'size'     => $a['size_formatted'] ?? size_format( $a['size'] ),
				'created'  => $a['created_utc'] ?? '',
				'sha256'   => substr( $a['sha256'] ?? '', 0, 16 ) . ( ! empty( $a['sha256'] ) ? '...' : '' ),
				'mtime'    => $a['mtime'] ?? 0,
			];
		}

		if ( class_exists( 'Lumos_Backup_S3' ) && Lumos_Backup_S3::is_configured() ) {
			$remote = Lumos_Backup_S3::list_objects();
			if ( ! is_wp_error( $remote ) && is_array( $remote ) ) {
				foreach ( $remote as $ra ) {
					$combined[] = [
						'filename' => $ra['filename'],
						'location' => 's3',
						'size'     => $ra['size_formatted'] ?? size_format( $ra['size'] ),
						'created'  => $ra['created_utc'] ?? '',
						'sha256'   => 'remote',
						'mtime'    => $ra['mtime'] ?? 0,
					];
				}
			}
		}

		usort(
			$combined,
			function ( $x, $y ) {
				return ( $y['mtime'] ?? 0 ) <=> ( $x['mtime'] ?? 0 );
			}
		);

		$format = $assoc_args['format'] ?? 'table';
		if ( 'count' === $format ) {
			WP_CLI::line( (string) count( $combined ) );
			return;
		}

		WP_CLI\Utils\format_items( $format, $combined, [ 'filename', 'location', 'size', 'created', 'sha256' ] );
	}

	/**
	 * Run an isolated restore drill to verify the latest or a specified backup.
	 *
	 * ## OPTIONS
	 *
	 * [--archive-id=<id>]
	 * : Test a specific backup archive instead of the latest.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lumos drill
	 *     wp lumos drill --archive-id=backup-20261005-120000.zip
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value flags.
	 */
	public function drill( $args, $assoc_args ) {
		WP_CLI::line( 'Starting isolated restore verification drill...' );

		if ( ! class_exists( 'Lumos_Backup_Drill' ) ) {
			WP_CLI::error( 'Drill engine class is not loaded.' );
		}

		$result = Lumos_Backup_Drill::run_drill( $assoc_args['archive-id'] ?? null );

		if ( 'pass' === ( $result['status'] ?? '' ) ) {
			WP_CLI::success( sprintf( 'Restore drill PASSED for archive %s!', $result['archive_id'] ) );
			if ( ! empty( $result['details']['statements'] ) ) {
				WP_CLI::line( sprintf( 'Tested SQL statements: %d (Method: %s)', $result['details']['statements'], $result['details']['db_method'] ?? 'isolated' ) );
			}
		} else {
			WP_CLI::error( sprintf( 'Restore drill FAILED: %s', $result['error'] ?? 'Unknown verification error' ) );
		}
	}

	/**
	 * Display current Lumos Backup status, configuration, and emergency links.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lumos status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value flags.
	 */
	public function status( $args, $assoc_args ) {
		$token       = Lumos_Backup_Restore_Gate::get_token();
		$restore_url = Lumos_Backup_Restore_Gate::get_restore_url( $token );
		$last_run    = get_option( 'lumos_backup_last', [] );
		$last_drill  = get_option( 'lumos_backup_last_drill', [] );
		$keep        = (int) get_option( 'lumos_backup_keep', 7 );
		$archives    = Lumos_Backup_Storage::get_archives();

		WP_CLI::line( WP_CLI::colorize( '%G=== Lumos Backup System Status ===%n' ) );
		WP_CLI::line( sprintf( 'Emergency Restore URL : %s', $restore_url ) );
		WP_CLI::line( sprintf( 'Restore Script Ready  : %s', Lumos_Backup_Restore_Gate::is_restore_script_ready() ? 'Yes' : 'No' ) );
		WP_CLI::line( sprintf( 'Restore Config Ready  : %s', Lumos_Backup_Restore_Gate::is_config_file_ready() ? 'Yes (0600)' : 'No' ) );
		WP_CLI::line( sprintf( 'Encryption Active     : %s', Lumos_Backup_Restore_Gate::is_encryption_enabled() ? 'Yes (AES-256-CBC)' : 'No' ) );
		WP_CLI::line( sprintf( 'Retention Policy      : Keep %d archives', $keep ) );
		WP_CLI::line( sprintf( 'Total Local Archives  : %d', count( $archives ) ) );

		if ( class_exists( 'Lumos_Backup_S3' ) && Lumos_Backup_S3::is_configured() ) {
			$s3 = Lumos_Backup_S3::get_settings();
			WP_CLI::line( sprintf( 'Remote S3 Storage     : Enabled (Bucket: %s, Region: %s)', $s3['bucket'], $s3['region'] ) );
		} else {
			WP_CLI::line( 'Remote S3 Storage     : Disabled' );
		}

		if ( ! empty( $last_run['timestamp'] ) ) {
			WP_CLI::line( sprintf( 'Last Backup Run       : %s (Archive: %s, Status: %s)', $last_run['datetime_utc'] ?? '', $last_run['filename'] ?? '', $last_run['status'] ?? '' ) );
		}

		if ( ! empty( $last_drill['time'] ) ) {
			WP_CLI::line( sprintf( 'Last Restore Drill    : %s (Status: %s)', $last_drill['datetime_utc'] ?? '', strtoupper( $last_drill['status'] ?? 'NONE' ) ) );
		} else {
			WP_CLI::line( 'Last Restore Drill    : Unproven (No drill recorded)' );
		}
	}

	/**
	 * Retrieve or rotate the emergency restore secret token.
	 *
	 * ## OPTIONS
	 *
	 * [--rotate]
	 * : Rotate the secret token, updating the hash in restore-config.json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lumos token
	 *     wp lumos token --rotate
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value flags.
	 */
	public function token( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['rotate'] ) ) {
			$new_token = Lumos_Backup_Restore_Gate::rotate_token();
			WP_CLI::success( 'Restore token successfully rotated.' );
			WP_CLI::line( sprintf( 'New Secret Token : %s', $new_token ) );
			WP_CLI::line( sprintf( 'New Restore URL  : %s', Lumos_Backup_Restore_Gate::get_restore_url( $new_token ) ) );
		} else {
			$token = Lumos_Backup_Restore_Gate::get_token();
			WP_CLI::line( sprintf( 'Active Secret Token : %s', $token ) );
			WP_CLI::line( sprintf( 'Emergency URL       : %s', Lumos_Backup_Restore_Gate::get_restore_url( $token ) ) );
		}
	}

	/**
	 * Register CLI command with WP_CLI.
	 */
	public static function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'lumos', __CLASS__ );
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	Lumos_Backup_CLI::register();
}
