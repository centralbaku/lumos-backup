<?php
/**
 * WP-Cron daily scheduler for Lumos Backup.
 *
 * @package LumosBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lumos_Backup_Scheduler
 */
class Lumos_Backup_Scheduler {

	/**
	 * WP-Cron event hook name for backups.
	 */
	const CRON_HOOK = 'lumos_backup_daily_event';

	/**
	 * WP-Cron event hook name for scheduled restore drills.
	 */
	const DRILL_CRON_HOOK = 'lumos_backup_scheduled_drill';

	/**
	 * Register cron event hook listeners, custom intervals, and upgrader listener.
	 */
	public function init() {
		add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );
		add_action( self::CRON_HOOK, [ $this, 'run_daily_backup' ] );
		add_action( self::CRON_HOOK . '_once', [ $this, 'run_daily_backup' ] );
		add_action( self::DRILL_CRON_HOOK, [ $this, 'run_scheduled_drill' ] );
		add_action( 'lumos_backup_drill_failed', [ $this, 'on_drill_failed' ], 10, 1 );
		add_action( 'upgrader_process_complete', [ $this, 'on_upgrader_process_complete' ], 10, 2 );
	}

	/**
	 * Add custom recurring intervals for restore drills.
	 *
	 * @param array $schedules Registered WP-Cron schedules.
	 * @return array
	 */
	public function add_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = [
				'interval' => 7 * 86400,
				'display'  => __( 'Once Weekly', 'lumos-backup' ),
			];
		}
		if ( ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = [
				'interval' => 30 * 86400,
				'display'  => __( 'Once Monthly (30 days)', 'lumos-backup' ),
			];
		}
		return $schedules;
	}

	/**
	 * Schedule the daily recurring backup event if not already scheduled.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Schedule to run starting from next midnight UTC or 1 hour from now
			$timestamp = strtotime( 'tomorrow 02:00:00 UTC' );
			if ( false === $timestamp ) {
				$timestamp = time() + ( defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 );
			}
			wp_schedule_event( $timestamp, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the daily recurring backup event.
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::CRON_HOOK . '_once' );
	}

	/**
	 * Execute the scheduled daily backup task.
	 */
	public function run_daily_backup() {
		// Keep running even if the triggering HTTP connection closes.
		@ignore_user_abort( true );
		$archiver = new Lumos_Backup_Archiver();
		$archiver->create_backup();
	}

	/**
	 * Trigger automatic backup on update if last backup is older than 6 hours.
	 *
	 * Does not start a second run if one is already locked (stale after 2 hours).
	 *
	 * @param mixed $upgrader Upgrader instance.
	 * @param array $options  Upgrade options.
	 */
	public function on_upgrader_process_complete( $upgrader = null, $options = [] ) {
		$last      = get_option( 'lumos_backup_last', [] );
		$last_time = is_array( $last ) && isset( $last['timestamp'] ) ? (int) $last['timestamp'] : 0;
		$hour_secs = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;

		// Skip if last backup was less than 6 hours ago
		if ( ( time() - $last_time ) < ( 6 * $hour_secs ) ) {
			return;
		}

		// Check lock file (stale after 2 hours)
		$lock_file = Lumos_Backup_Storage::get_lock_path();
		if ( file_exists( $lock_file ) ) {
			$raw  = @file_get_contents( $lock_file );
			$data = $raw ? json_decode( $raw, true ) : null;
			$time = is_array( $data ) && isset( $data['time'] ) ? (int) $data['time'] : filemtime( $lock_file );

			if ( ( time() - $time ) < 7200 ) {
				return; // Already locked, do not start second run
			}
		}

		// Do not run the backup inside the update request itself (that request belongs to
		// the admin and may time out or exhaust memory). Queue a one-off background event.
		if ( ! wp_next_scheduled( self::CRON_HOOK . '_once' ) ) {
			wp_schedule_single_event( time() + 60, self::CRON_HOOK . '_once' );
		}
	}

	/**
	 * Get the next scheduled run timestamp.
	 *
	 * @return int|false Unix timestamp or false if not scheduled.
	 */
	public static function get_next_run() {
		return wp_next_scheduled( self::CRON_HOOK );
	}

	/**
	 * Schedule automated restore drills at the specified frequency.
	 *
	 * @param string $frequency 'weekly', 'monthly', 'daily', or 'disabled'.
	 * @return bool
	 */
	public static function schedule_drill( $frequency ) {
		self::unschedule_drill();

		if ( 'disabled' === $frequency || empty( $frequency ) ) {
			update_option( 'lumos_backup_drill_frequency', 'disabled' );
			return true;
		}

		$valid = [ 'daily', 'weekly', 'monthly' ];
		if ( ! in_array( $frequency, $valid, true ) ) {
			return false;
		}

		update_option( 'lumos_backup_drill_frequency', $frequency );

		$first_run = time() + ( defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 );
		return false !== wp_schedule_event( $first_run, $frequency, self::DRILL_CRON_HOOK );
	}

	/**
	 * Unschedule automated restore drills.
	 */
	public static function unschedule_drill() {
		$timestamp = wp_next_scheduled( self::DRILL_CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::DRILL_CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::DRILL_CRON_HOOK );
		update_option( 'lumos_backup_drill_frequency', 'disabled' );
	}

	/**
	 * Execute scheduled restore drill.
	 */
	public function run_scheduled_drill() {
		if ( class_exists( 'Lumos_Backup_Drill' ) ) {
			Lumos_Backup_Drill::run_drill();
		}
	}

	/**
	 * Send alert email when a restore drill fails.
	 *
	 * @param array $drill_record Drill record details.
	 */
	public function on_drill_failed( $drill_record ) {
		$to = get_option( 'lumos_backup_drill_email' );
		if ( empty( $to ) || ! is_email( $to ) ) {
			$to = get_option( 'admin_email' );
		}

		if ( empty( $to ) || ! is_email( $to ) ) {
			return;
		}

		$site_url   = site_url();
		$site_name  = get_bloginfo( 'name' );
		$archive_id = $drill_record['archive_id'] ?? 'unknown';
		$error_msg  = $drill_record['error'] ?? 'Unknown error';
		$time_utc   = $drill_record['datetime_utc'] ?? gmdate( 'Y-m-d H:i:s' );

		$subject = sprintf( '[Lumos Backup] ALERT: Restore Drill FAILED on %s', $site_name );

		$body  = "Attention Administrator,\n\n";
		$body .= "A Lumos Backup automated restore drill just executed and FAILED verification.\n\n";
		$body .= "Details:\n";
		$body .= "--------------------------------------------------------\n";
		$body .= "Site:       " . $site_url . "\n";
		$body .= "Archive ID: " . $archive_id . "\n";
		$body .= "Time (UTC): " . $time_utc . "\n";
		$body .= "Error:      " . $error_msg . "\n";
		$body .= "--------------------------------------------------------\n\n";
		$body .= "Because the restore drill failed, this backup archive cannot be guaranteed recoverable in an emergency.\n";
		$body .= "Please log into WordPress admin and check Tools -> Lumos Backup for full diagnostics.\n\n";
		$body .= "Admin URL: " . admin_url( 'tools.php?page=lumos-backup' ) . "\n\n";
		$body .= "--\nLumos Backup Automated Health Monitor";

		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

		@wp_mail( $to, $subject, $body, $headers );
	}

	/**
	 * Get the next scheduled drill timestamp.
	 *
	 * @return int|false Unix timestamp or false if not scheduled.
	 */
	public static function get_next_drill_run() {
		return wp_next_scheduled( self::DRILL_CRON_HOOK );
	}
}
