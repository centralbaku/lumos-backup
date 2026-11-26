<?php
/**
 * Optional "skip generated image sizes" for backups, and rebuilding them after a restore.
 *
 * WordPress makes resized copies of every uploaded image (thumbnail, medium, large and any
 * sizes themes or plugins add). They can be recreated from the originals, so a backup can
 * leave them out to save space. WordPress does NOT recreate them on its own, so after a
 * restore this class rebuilds the missing sizes in small background batches.
 *
 * Only files that WordPress lists as generated sizes in each image's metadata are skipped.
 * Originals, including the "-scaled" copy WordPress serves for large uploads and the
 * pre-scaling original, are always kept.
 *
 * @package LumosBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lumos_Backup_Thumbnails
 */
class Lumos_Backup_Thumbnails {

	/** Option: 1 when backups should skip generated image sizes. */
	const OPTION_SKIP = 'lumos_backup_skip_thumbnails';

	/** Option: rebuild state after a restore (status pending|running|done, counters). */
	const OPTION_REBUILD = 'lumos_backup_thumb_rebuild';

	/** WP-Cron hook that processes one batch. */
	const CRON_HOOK = 'lumos_backup_rebuild_thumbs';

	/** Seconds of work per batch, kept short so the server stays responsive. */
	const BATCH_SECONDS = 20;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_batch' ] );
		add_action( 'init', [ __CLASS__, 'maybe_start_rebuild' ] );
	}

	/**
	 * Whether the skip option can be used on this install.
	 *
	 * Multisite keeps uploads and image records per site; rebuilding them is not handled yet.
	 *
	 * @return bool
	 */
	public static function is_supported() {
		return ! is_multisite();
	}

	/**
	 * Whether backups should skip generated image sizes.
	 *
	 * @return bool
	 */
	public static function is_skip_enabled() {
		return self::is_supported() && (bool) get_option( self::OPTION_SKIP, false );
	}

	/**
	 * Absolute paths (normalized) of every generated image size WordPress knows about.
	 *
	 * Read from the database in batches; originals are never included.
	 *
	 * @return array<string,bool> Set of paths.
	 */
	public static function get_generated_paths() {
		global $wpdb;

		$uploads = wp_get_upload_dir();
		$basedir = wp_normalize_path( untrailingslashit( $uploads['basedir'] ) );
		$paths   = [];
		$last_id = 0;

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_value FROM {$wpdb->postmeta}
					 WHERE meta_key = '_wp_attachment_metadata' AND post_id > %d
					 ORDER BY post_id ASC LIMIT 500",
					$last_id
				)
			);

			foreach ( (array) $rows as $row ) {
				$last_id = (int) $row->post_id;
				$meta    = maybe_unserialize( $row->meta_value );
				if ( ! is_array( $meta ) || empty( $meta['file'] ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
					continue;
				}

				$dir  = dirname( $meta['file'] );
				$dir  = ( '.' === $dir ) ? '' : $dir . '/';
				$keep = [ basename( $meta['file'] ) => true ];
				if ( ! empty( $meta['original_image'] ) ) {
					$keep[ basename( $meta['original_image'] ) ] = true;
				}

				foreach ( $meta['sizes'] as $size ) {
					if ( empty( $size['file'] ) ) {
						continue;
					}
					$name = basename( $size['file'] );
					if ( isset( $keep[ $name ] ) ) {
						continue; // Never skip a file that is also an original.
					}
					$paths[ $basedir . '/' . $dir . $name ] = true;
				}
			}
		} while ( ! empty( $rows ) );

		return $paths;
	}

	/**
	 * Mark that thumbnails must be rebuilt (called by WP-CLI or tests; the standalone
	 * restore writes the same option directly).
	 */
	public static function request_rebuild() {
		update_option( self::OPTION_REBUILD, [ 'status' => 'pending', 'requested' => time() ], true );
	}

	/**
	 * Current rebuild state, or an empty array when nothing was requested.
	 *
	 * @return array
	 */
	public static function get_state() {
		$state = get_option( self::OPTION_REBUILD, [] );
		return is_array( $state ) ? $state : [];
	}

	/**
	 * Start processing when a restore asked for a rebuild.
	 */
	public static function maybe_start_rebuild() {
		$state = self::get_state();
		if ( empty( $state['status'] ) || 'done' === $state['status'] ) {
			return;
		}

		if ( 'pending' === $state['status'] ) {
			global $wpdb;
			$state = [
				'status'    => 'running',
				'requested' => (int) ( $state['requested'] ?? time() ),
				'started'   => time(),
				'updated'   => time(),
				'cursor'    => 0,
				'done'      => 0,
				'created'   => 0,
				'failed'    => 0,
				'total'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" ),
			];
			update_option( self::OPTION_REBUILD, $state, true );
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
	}

	/**
	 * Recreate the size files of one image that are missing on disk.
	 *
	 * The restored database still lists every size, and WordPress only compares that list,
	 * so sizes whose files are gone are first dropped from the metadata; WordPress then
	 * regenerates exactly those.
	 *
	 * @param int $id Attachment ID.
	 * @return int|false Number of sizes created, or false when it failed.
	 */
	private static function rebuild_missing_sizes( $id ) {
		$meta = wp_get_attachment_metadata( $id );
		$file = get_attached_file( $id );
		if ( ! is_array( $meta ) || ! $file || ! file_exists( $file ) ) {
			return 0; // Nothing to rebuild from.
		}

		$dir     = trailingslashit( dirname( $file ) );
		$removed = 0;
		foreach ( (array) ( $meta['sizes'] ?? [] ) as $name => $size ) {
			if ( empty( $size['file'] ) || ! file_exists( $dir . $size['file'] ) ) {
				unset( $meta['sizes'][ $name ] );
				$removed++;
			}
		}

		if ( 0 === $removed && empty( wp_get_missing_image_subsizes( $id ) ) ) {
			return 0;
		}

		if ( $removed ) {
			wp_update_attachment_metadata( $id, $meta );
		}

		$before = count( $meta['sizes'] ?? [] );
		$result = wp_update_image_subsizes( $id );
		if ( is_wp_error( $result ) ) {
			return false;
		}

		$after = wp_get_attachment_metadata( $id );
		return max( 0, count( (array) ( $after['sizes'] ?? [] ) ) - $before );
	}

	/**
	 * Rebuild missing sizes for the next images, for up to BATCH_SECONDS, then queue the
	 * next batch. Only sizes that are missing are created; existing files are left alone.
	 */
	public static function run_batch() {
		$state = self::get_state();
		if ( empty( $state['status'] ) || 'running' !== $state['status'] ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		@set_time_limit( 120 );

		global $wpdb;
		$deadline = microtime( true ) + self::BATCH_SECONDS;
		$finished = false;

		while ( microtime( true ) < $deadline ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					 WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%' AND ID > %d
					 ORDER BY ID ASC LIMIT 10",
					(int) $state['cursor']
				)
			);

			if ( empty( $ids ) ) {
				$finished = true;
				break;
			}

			foreach ( $ids as $id ) {
				$id              = (int) $id;
				$state['cursor'] = $id;
				$state['done']++;

				$created = self::rebuild_missing_sizes( $id );
				if ( false === $created ) {
					$state['failed']++;
				} else {
					$state['created'] += $created;
				}

				if ( microtime( true ) >= $deadline ) {
					break;
				}
			}
		}

		$state['updated'] = time();
		if ( $finished ) {
			$state['status']   = 'done';
			$state['finished'] = time();
		}
		update_option( self::OPTION_REBUILD, $state, true );

		if ( ! $finished && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}
}
