<?php
/**
 * Live progress for backup runs.
 *
 * The running backup writes its stage, percent and a heartbeat to progress.php (guarded
 * so web servers never serve it). The admin page polls it to show a progress bar, time
 * left, and a warning when the heartbeat stops.
 *
 * @package LumosBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lumos_Backup_Progress
 */
class Lumos_Backup_Progress {

	/**
	 * Seconds without a heartbeat before a stage that reports often is called stuck.
	 */
	const STALL_AFTER = 120;

	/**
	 * Current run state while a backup runs in this process.
	 *
	 * @var array|null
	 */
	private static $state = null;

	/**
	 * Time of the last write, for throttling.
	 *
	 * @var float
	 */
	private static $last_write = 0.0;

	/**
	 * Path of the progress file.
	 *
	 * @return string
	 */
	public static function get_path() {
		return Lumos_Backup_Storage::get_base_dir() . '/progress.php';
	}

	/**
	 * Start tracking a new run.
	 *
	 * @param string $trigger What started it: manual, schedule or update.
	 */
	public static function start( $trigger = 'manual' ) {
		self::$state = [
			'run_id'      => bin2hex( random_bytes( 8 ) ),
			'status'      => 'running',
			'trigger'     => $trigger,
			'stage'       => 'prepare',
			'label'       => __( 'Preparing', 'lumos-backup' ),
			'detail'      => '',
			'percent'     => 0,
			'started'     => time(),
			'updated'     => time(),
			'stall_after' => self::STALL_AFTER,
		];
		self::write( true );
	}

	/**
	 * Enter a new stage.
	 *
	 * @param string $stage       Stage key.
	 * @param string $label       Human label, e.g. "Exporting database".
	 * @param float  $percent     Overall percent at the start of the stage.
	 * @param int    $stall_after Seconds of silence allowed in this stage. Stages that cannot
	 *                            report while working (compressing, uploading) get more time.
	 */
	public static function stage( $stage, $label, $percent, $stall_after = self::STALL_AFTER ) {
		if ( null === self::$state ) {
			return;
		}
		self::$state['stage']       = $stage;
		self::$state['label']       = $label;
		self::$state['detail']      = '';
		self::$state['percent']     = round( (float) $percent, 1 );
		self::$state['stall_after'] = (int) $stall_after;
		self::write( true );
	}

	/**
	 * Report progress within the current stage (written at most once per second).
	 *
	 * @param float  $percent Overall percent.
	 * @param string $detail  Detail line, e.g. "wp_posts: 1,500 of 4,000 rows".
	 */
	public static function tick( $percent, $detail = '' ) {
		if ( null === self::$state ) {
			return;
		}
		self::$state['percent'] = round( max( (float) self::$state['percent'], min( 99.0, (float) $percent ) ), 1 );
		self::$state['detail']  = $detail;
		self::write( false );
	}

	/**
	 * Finish the run.
	 *
	 * @param string $status   success or error.
	 * @param string $message  Error message or summary.
	 * @param array  $extra    Extra fields (filename, size).
	 */
	public static function finish( $status, $message = '', array $extra = [] ) {
		if ( null === self::$state ) {
			return;
		}
		self::$state['status']   = $status;
		self::$state['message']  = $message;
		self::$state['finished'] = time();
		if ( 'success' === $status ) {
			self::$state['percent'] = 100;
			self::$state['label']   = __( 'Backup complete', 'lumos-backup' );
			self::$state['detail']  = '';
		}
		self::$state = array_merge( self::$state, $extra );
		self::write( true );
		self::$state = null;
	}

	/**
	 * Write the state to disk.
	 *
	 * @param bool $force Write even if the last write was under a second ago.
	 */
	private static function write( $force ) {
		$now = microtime( true );
		if ( ! $force && ( $now - self::$last_write ) < 1.0 ) {
			return;
		}
		self::$last_write       = $now;
		self::$state['updated'] = time();

		$json = wp_json_encode( self::$state );
		if ( false !== $json ) {
			@file_put_contents( self::get_path(), Lumos_Backup_Storage::CONFIG_GUARD . $json, LOCK_EX );
		}
	}

	/**
	 * Mark a run that stopped sending heartbeats as failed, so a new backup can start.
	 *
	 * @return bool True if a stuck run was cleared.
	 */
	public static function clear_stuck() {
		$data = self::read();
		if ( empty( $data['stalled'] ) ) {
			return false;
		}

		$lock = Lumos_Backup_Storage::get_lock_path();
		if ( file_exists( $lock ) ) {
			@unlink( $lock );
		}

		$data['status']   = 'error';
		$data['message']  = __( 'The backup stopped responding and was cleared. Check the PHP error log; the server may have run out of memory or time.', 'lumos-backup' );
		$data['finished'] = time();
		unset( $data['stalled'], $data['elapsed'], $data['eta'], $data['silent_for'], $data['now'] );

		$json = wp_json_encode( $data );
		@file_put_contents( self::get_path(), Lumos_Backup_Storage::CONFIG_GUARD . $json, LOCK_EX );

		update_option(
			'lumos_backup_last',
			[
				'timestamp'    => time(),
				'datetime_utc' => gmdate( 'Y-m-d H:i:s' ),
				'status'       => 'error',
				'error'        => $data['message'],
			]
		);
		return true;
	}

	/**
	 * Read the latest run with computed timing: elapsed, eta, silent_for and stalled.
	 *
	 * @return array Empty array when no run has been recorded.
	 */
	public static function read() {
		$path = self::get_path();
		if ( ! file_exists( $path ) ) {
			return [];
		}

		$raw = (string) @file_get_contents( $path );
		$pos = strpos( $raw, "\n" );
		$raw = ( 0 === strpos( $raw, '<?php' ) && false !== $pos ) ? substr( $raw, $pos + 1 ) : $raw;
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['run_id'] ) ) {
			return [];
		}

		$now                = time();
		$data['now']        = $now;
		$end                = ( 'running' === $data['status'] ) ? $now : (int) ( $data['finished'] ?? $now );
		$data['elapsed']    = max( 0, $end - (int) $data['started'] );
		$data['silent_for'] = max( 0, $now - (int) $data['updated'] );
		$data['stalled']    = 'running' === $data['status'] && $data['silent_for'] > (int) ( $data['stall_after'] ?? self::STALL_AFTER );

		// Time left from the average speed so far; only once there is enough to go on.
		$data['eta'] = null;
		$pct         = (float) $data['percent'];
		if ( 'running' === $data['status'] && ! $data['stalled'] && $pct >= 3 && $data['elapsed'] >= 8 ) {
			$data['eta'] = (int) round( $data['elapsed'] * ( 100 - $pct ) / $pct );
		}

		return $data;
	}
}
