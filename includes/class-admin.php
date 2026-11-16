<?php
/**
 * Admin dashboard and action handling for Lumos Backup.
 *
 * @package LumosBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lumos_Backup_Admin
 */
class Lumos_Backup_Admin {

	/**
	 * Transient key for transient error messages across redirects.
	 */
	const ERROR_TRANSIENT = 'lumos_backup_admin_error';

	/**
	 * Initialize admin hooks.
	 */
	public function init() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', [ $this, 'register_network_menu' ] );
		}
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
		add_action( 'admin_notices', [ $this, 'display_notices' ] );
		if ( is_multisite() ) {
			add_action( 'network_admin_notices', [ $this, 'display_notices' ] );
		}
	}

	/**
	 * Get required user capability based on single-site vs multisite network.
	 *
	 * @return string
	 */
	public static function get_capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Get the appropriate admin page URL based on context.
	 *
	 * @return string
	 */
	public static function get_page_url() {
		if ( is_multisite() && is_network_admin() ) {
			return network_admin_url( 'admin.php?page=lumos-backup' );
		}
		return admin_url( 'tools.php?page=lumos-backup' );
	}

	/**
	 * Register Tools menu item for standard admin.
	 */
	public function register_menu() {
		add_management_page(
			__( 'Lumos Backup', 'lumos-backup' ),
			__( 'Lumos Backup', 'lumos-backup' ),
			self::get_capability(),
			'lumos-backup',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Register network admin menu item for Multisite super admins.
	 */
	public function register_network_menu() {
		add_menu_page(
			__( 'Lumos Backup', 'lumos-backup' ),
			__( 'Lumos Backup', 'lumos-backup' ),
			'manage_network_options',
			'lumos-backup',
			[ $this, 'render_page' ],
			'dashicons-shield-alt'
		);
	}

	/**
	 * Handle admin POST/GET actions with nonces and capability verification.
	 */
	public function handle_actions() {
		if ( ! isset( $_REQUEST['page'] ) || 'lumos-backup' !== $_REQUEST['page'] ) {
			return;
		}

		if ( ! current_user_can( self::get_capability() ) ) {
			return;
		}

		$action = isset( $_REQUEST['lumos_action'] ) ? sanitize_key( $_REQUEST['lumos_action'] ) : '';
		if ( empty( $action ) ) {
			return;
		}

		$redirect_url = self::get_page_url();

		switch ( $action ) {
			case 'backup_now':
				check_admin_referer( 'lumos_backup_now' );
				$archiver = new Lumos_Backup_Archiver();
				$result   = $archiver->create_backup();

				if ( is_wp_error( $result ) ) {
					set_transient( self::ERROR_TRANSIENT, $result->get_error_message(), 60 );
					wp_safe_redirect( add_query_arg( 'lumos_msg', 'backup_failed', $redirect_url ) );
				} else {
					wp_safe_redirect( add_query_arg( 'lumos_msg', 'backup_success', $redirect_url ) );
				}
				exit;

			case 'rotate_token':
				check_admin_referer( 'lumos_backup_rotate_token' );
				Lumos_Backup_Restore_Gate::rotate_token();
				wp_safe_redirect( add_query_arg( 'lumos_msg', 'token_rotated', $redirect_url ) );
				exit;

			case 'save_settings':
				check_admin_referer( 'lumos_backup_save_settings' );
				$keep = isset( $_POST['lumos_backup_keep'] ) ? absint( $_POST['lumos_backup_keep'] ) : 7;
				$keep = max( 1, min( 100, $keep ) );
				update_option( 'lumos_backup_keep', $keep );

				// Prune archives to match new retention limit
				Lumos_Backup_Storage::purge_old_archives( $keep );
				if ( class_exists( 'Lumos_Backup_S3' ) && Lumos_Backup_S3::is_configured() ) {
					Lumos_Backup_S3::prune_remote_archives( $keep );
				}

				wp_safe_redirect( add_query_arg( 'lumos_msg', 'settings_saved', $redirect_url ) );
				exit;

			case 'save_s3':
				check_admin_referer( 'lumos_backup_save_s3' );
				if ( class_exists( 'Lumos_Backup_S3' ) ) {
					Lumos_Backup_S3::save_settings( $_POST );
				}
				wp_safe_redirect( add_query_arg( 'lumos_msg', 's3_saved', $redirect_url ) );
				exit;

			case 'test_s3':
				check_admin_referer( 'lumos_backup_test_s3' );
				if ( class_exists( 'Lumos_Backup_S3' ) ) {
					$test = Lumos_Backup_S3::test_connection();
					set_transient( 'lumos_backup_s3_test_result', $test, 60 );
				}
				wp_safe_redirect( add_query_arg( 'lumos_msg', 's3_tested', $redirect_url ) );
				exit;

			case 'save_sftp':
				check_admin_referer( 'lumos_backup_save_sftp' );
				if ( class_exists( 'Lumos_Backup_SFTP' ) ) {
					$host     = isset( $_POST['sftp_host'] ) ? sanitize_text_field( wp_unslash( $_POST['sftp_host'] ) ) : '';
					$port     = isset( $_POST['sftp_port'] ) ? absint( $_POST['sftp_port'] ) : 22;
					$username = isset( $_POST['sftp_username'] ) ? sanitize_text_field( wp_unslash( $_POST['sftp_username'] ) ) : '';
					$password = isset( $_POST['sftp_password'] ) ? sanitize_text_field( wp_unslash( $_POST['sftp_password'] ) ) : '';
					$path     = isset( $_POST['sftp_path'] ) ? sanitize_text_field( wp_unslash( $_POST['sftp_path'] ) ) : '';

					$existing = Lumos_Backup_SFTP::get_settings();
					if ( empty( $password ) && ! empty( $existing['password'] ) ) {
						$password = $existing['password'];
					}

					Lumos_Backup_SFTP::save_settings( [
						'host'     => $host,
						'port'     => $port,
						'username' => $username,
						'password' => $password,
						'path'     => $path,
					] );

					wp_safe_redirect( add_query_arg( 'lumos_msg', 'sftp_saved', $redirect_url ) );
					exit;
				}
				break;

			case 'test_sftp':
				check_admin_referer( 'lumos_backup_test_sftp' );
				if ( class_exists( 'Lumos_Backup_SFTP' ) ) {
					$test_res = Lumos_Backup_SFTP::test_connection();
					if ( is_wp_error( $test_res ) ) {
						set_transient( 'lumos_backup_sftp_test_result', [
							'success' => false,
							'message' => $test_res->get_error_message(),
						], 60 );
					} else {
						set_transient( 'lumos_backup_sftp_test_result', [
							'success' => true,
							'message' => __( 'SFTP connection test successful!', 'lumos-backup' ),
						], 60 );
					}
					wp_safe_redirect( add_query_arg( 'lumos_msg', 'sftp_tested', $redirect_url ) );
					exit;
				}
				break;

			case 'save_drill_schedule':
				check_admin_referer( 'lumos_backup_save_drill_schedule' );
				$freq  = isset( $_POST['drill_frequency'] ) ? sanitize_key( $_POST['drill_frequency'] ) : 'disabled';
				$email = isset( $_POST['drill_email'] ) ? sanitize_email( wp_unslash( $_POST['drill_email'] ) ) : '';

				if ( ! empty( $email ) ) {
					update_option( 'lumos_backup_drill_email', $email );
				}
				if ( class_exists( 'Lumos_Backup_Scheduler' ) ) {
					Lumos_Backup_Scheduler::schedule_drill( $freq );
				}

				wp_safe_redirect( add_query_arg( 'lumos_msg', 'drill_scheduled', $redirect_url ) );
				exit;

			case 'save_passphrase':
				check_admin_referer( 'lumos_backup_save_passphrase' );
				$pass = isset( $_POST['lumos_passphrase'] ) ? sanitize_text_field( wp_unslash( $_POST['lumos_passphrase'] ) ) : '';
				Lumos_Backup_Restore_Gate::set_passphrase( $pass );
				wp_safe_redirect( add_query_arg( 'lumos_msg', 'passphrase_saved', $redirect_url ) );
				exit;

			case 'download':
				$archive_id = isset( $_GET['archive'] ) ? sanitize_text_field( wp_unslash( $_GET['archive'] ) ) : '';
				check_admin_referer( 'lumos_download_' . $archive_id );

				if ( Lumos_Backup_Storage::is_valid_archive_name( $archive_id ) ) {
					$archive_path = Lumos_Backup_Storage::get_archives_dir() . '/' . $archive_id;
					if ( file_exists( $archive_path ) && is_readable( $archive_path ) ) {
						nocache_headers();
						header( 'Content-Description: File Transfer' );
						header( 'Content-Type: application/zip' );
						header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $archive_id ) . '"' );
						header( 'Content-Transfer-Encoding: binary' );
						header( 'Content-Length: ' . filesize( $archive_path ) );
						readfile( $archive_path );
						exit;
					}
				}
				wp_die( esc_html__( 'The requested backup file could not be found.', 'lumos-backup' ) );
				break;

			case 'test_drill':
				check_admin_referer( 'lumos_backup_test_drill' );
				if ( class_exists( 'Lumos_Backup_Drill' ) ) {
					$drill_res = Lumos_Backup_Drill::run_drill();
					if ( 'pass' === ( $drill_res['status'] ?? '' ) ) {
						wp_safe_redirect( add_query_arg( 'lumos_msg', 'drill_pass', $redirect_url ) );
					} else {
						$err_msg = ! empty( $drill_res['error'] ) ? $drill_res['error'] : __( 'Drill verification failed.', 'lumos-backup' );
						set_transient( self::ERROR_TRANSIENT, $err_msg, 60 );
						wp_safe_redirect( add_query_arg( 'lumos_msg', 'drill_fail', $redirect_url ) );
					}
				}
				exit;

			case 'download_recovery_sheet':
				check_admin_referer( 'lumos_backup_download_recovery_sheet' );
				$sheet_path = Lumos_Backup_Storage::get_recovery_sheet_path();

				// If sheet doesn't exist yet, try to generate one based on last backup
				if ( ! file_exists( $sheet_path ) || ! is_readable( $sheet_path ) ) {
					$last_backup = get_option( 'lumos_backup_last', [] );
					if ( ! empty( $last_backup['filename'] ) ) {
						Lumos_Backup_Archiver::generate_recovery_sheet(
							$last_backup['filename'],
							$last_backup['sha256'] ?? '',
							! empty( $last_backup['is_encrypted'] )
						);
					}
				}

				if ( file_exists( $sheet_path ) && is_readable( $sheet_path ) ) {
					nocache_headers();
					header( 'Content-Description: File Transfer' );
					header( 'Content-Type: text/markdown; charset=UTF-8' );
					header( 'Content-Disposition: attachment; filename="lumos-recovery-sheet.md"' );
					header( 'Content-Transfer-Encoding: binary' );
					header( 'Content-Length: ' . filesize( $sheet_path ) );
					readfile( $sheet_path );
					exit;
				}
				wp_die( esc_html__( 'No recovery sheet is available yet. Run a backup first.', 'lumos-backup' ) );
				break;

			case 'delete':
				$archive_id = isset( $_GET['archive'] ) ? sanitize_text_field( wp_unslash( $_GET['archive'] ) ) : '';
				check_admin_referer( 'lumos_delete_' . $archive_id );

				if ( Lumos_Backup_Storage::delete_archive( $archive_id ) ) {
					wp_safe_redirect( add_query_arg( 'lumos_msg', 'archive_deleted', $redirect_url ) );
				} else {
					set_transient( self::ERROR_TRANSIENT, __( 'Failed to delete backup archive file.', 'lumos-backup' ), 60 );
					wp_safe_redirect( add_query_arg( 'lumos_msg', 'action_failed', $redirect_url ) );
				}
				exit;
		}
	}

	/**
	 * Display admin notices on redirect.
	 */
	public function display_notices() {
		$screen = get_current_screen();
		if ( ! $screen || 'tools_page_lumos-backup' !== $screen->id ) {
			return;
		}

		$msg = isset( $_GET['lumos_msg'] ) ? sanitize_key( $_GET['lumos_msg'] ) : '';
		if ( empty( $msg ) ) {
			return;
		}

		switch ( $msg ) {
			case 'backup_success':
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Backup completed successfully!', 'lumos-backup' ) . '</p></div>';
				break;
			case 'token_rotated':
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Standalone restore secret key was rotated and config hash updated.', 'lumos-backup' ) . '</p></div>';
				break;
			case 'settings_saved':
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Lumos Backup settings saved.', 'lumos-backup' ) . '</p></div>';
				break;
			case 'passphrase_saved':
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Encryption passphrase settings updated. New backups will use this configuration.', 'lumos-backup' ) . '</p></div>';
				break;
			case 's3_saved':
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Remote S3 storage settings saved and restore configuration updated.', 'lumos-backup' ) . '</p></div>';
				break;
			case 's3_tested':
				$test = get_transient( 'lumos_backup_s3_test_result' );
				delete_transient( 'lumos_backup_s3_test_result' );
				if ( is_array( $test ) && ! empty( $test['success'] ) ) {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $test['message'] ) . '</p></div>';
				} else {
					$err_msg = is_array( $test ) && ! empty( $test['message'] ) ? $test['message'] : __( 'Failed to connect to S3.', 'lumos-backup' );
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err_msg ) . '</p></div>';
				}
				break;
			case 'drill_pass':
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Restore drill PASSED! The backup was successfully extracted, verified, and simulated without touching the live site.', 'lumos-backup' ) . '</p></div>';
				break;
			case 'drill_fail':
				$err = get_transient( self::ERROR_TRANSIENT );
				delete_transient( self::ERROR_TRANSIENT );
				$message = $err ? $err : __( 'Restore drill failed.', 'lumos-backup' );
				echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__( 'Restore drill FAILED:', 'lumos-backup' ) . '</strong> ' . esc_html( $message ) . '</p></div>';
				break;
			case 'sftp_saved':
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'SFTP remote storage settings saved.', 'lumos-backup' ) . '</p></div>';
				break;
			case 'sftp_tested':
				$test_sftp = get_transient( 'lumos_backup_sftp_test_result' );
				delete_transient( 'lumos_backup_sftp_test_result' );
				if ( is_array( $test_sftp ) && ! empty( $test_sftp['success'] ) ) {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $test_sftp['message'] ) . '</p></div>';
				} else {
					$err_msg = is_array( $test_sftp ) && ! empty( $test_sftp['message'] ) ? $test_sftp['message'] : __( 'Failed to connect via SFTP.', 'lumos-backup' );
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err_msg ) . '</p></div>';
				}
				break;
			case 'drill_scheduled':
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Automated restore drill schedule and notification settings updated.', 'lumos-backup' ) . '</p></div>';
				break;
			case 'archive_deleted':
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Backup archive deleted.', 'lumos-backup' ) . '</p></div>';
				break;
			case 'backup_failed':
			case 'action_failed':
				$err = get_transient( self::ERROR_TRANSIENT );
				delete_transient( self::ERROR_TRANSIENT );
				$message = $err ? $err : __( 'Operation failed. Please check server logs.', 'lumos-backup' );
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
				break;
		}
	}

	/**
	 * Render the Tools → Lumos Backup admin interface.
	 */
	public function render_page() {
		if ( ! current_user_can( self::get_capability() ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'lumos-backup' ) );
		}

		$token           = Lumos_Backup_Restore_Gate::get_token();
		$restore_url     = Lumos_Backup_Restore_Gate::get_restore_url( $token );
		$restore_ready   = Lumos_Backup_Restore_Gate::is_restore_script_ready();
		$config_ready    = Lumos_Backup_Restore_Gate::is_config_file_ready();
		$archives        = Lumos_Backup_Storage::get_archives();
		$keep_count      = (int) get_option( 'lumos_backup_keep', 7 );
		$last_run        = get_option( 'lumos_backup_last', [] );
		$last_drill      = get_option( 'lumos_backup_last_drill', [] );
		$next_cron       = Lumos_Backup_Scheduler::get_next_run();
		$action_url      = self::get_page_url();
		$s3_settings     = class_exists( 'Lumos_Backup_S3' ) ? Lumos_Backup_S3::get_settings() : [];
		$s3_configured   = class_exists( 'Lumos_Backup_S3' ) && Lumos_Backup_S3::is_configured();
		$sftp_settings   = class_exists( 'Lumos_Backup_SFTP' ) ? Lumos_Backup_SFTP::get_settings() : [];
		$sftp_configured = class_exists( 'Lumos_Backup_SFTP' ) && Lumos_Backup_SFTP::is_configured();
		$drill_frequency = get_option( 'lumos_backup_drill_frequency', 'disabled' );
		$drill_email     = get_option( 'lumos_backup_drill_email', get_option( 'admin_email' ) );
		$next_drill      = Lumos_Backup_Scheduler::get_next_drill_run();
		$remote_archives = $s3_configured ? Lumos_Backup_S3::list_objects() : [];
		if ( is_wp_error( $remote_archives ) ) {
			$remote_archives = [];
		}

		$total_bytes = 0;
		foreach ( $archives as $arch ) {
			$total_bytes += $arch['size'];
		}
		?>
		<div class="wrap lumos-backup-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Lumos Backup', 'lumos-backup' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Reliable local backups, S3 and SFTP remote storage, automated restore drills, and an autonomous standalone restore console that runs even when WordPress will not boot.', 'lumos-backup' ); ?>
			</p>
			<hr class="wp-header-end">

			<?php if ( is_multisite() ) : ?>
				<div class="notice notice-info inline" style="margin-top: 15px;">
					<p><strong><?php esc_html_e( 'WordPress Multisite Network Detected:', 'lumos-backup' ); ?></strong> <?php esc_html_e( 'Lumos Backup creates complete snapshots of the entire multisite network (all subsites, shared tables, and network uploads). Standalone emergency restore handles network tables (wp_site, wp_blogs, wp_sitemeta) and subsite options tables automatically.', 'lumos-backup' ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Emergency Standalone Restore Card -->
			<div class="card lumos-emergency-card" style="margin-top: 20px; border-left: 4px solid #f59e0b; padding: 20px; max-width: 100%;">
				<h2><?php esc_html_e( 'Emergency Standalone Restore Console', 'lumos-backup' ); ?></h2>
				<p>
					<?php esc_html_e( 'If a fatal error, bad update, or corrupted file prevents WordPress from loading, access this independent restore page directly. It operates completely outside WordPress core.', 'lumos-backup' ); ?>
				</p>

				<div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 14px; margin: 16px 0;">
					<label for="lumos-restore-url" style="font-weight: 600; display: block; margin-bottom: 6px;">
						<?php esc_html_e( 'Your Private Restore URL:', 'lumos-backup' ); ?>
					</label>
					<div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
						<input type="text" id="lumos-restore-url" value="<?php echo esc_url( $restore_url ); ?>" readonly style="flex: 1; min-width: 320px; font-family: monospace; font-size: 13px;" onclick="this.select();">
						<button type="button" class="button" onclick="lumosCopyUrl(this)">
							<?php esc_html_e( 'Copy Link', 'lumos-backup' ); ?>
						</button>
						<a href="<?php echo esc_url( $restore_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary">
							<?php esc_html_e( 'Test Restore Page &rarr;', 'lumos-backup' ); ?>
						</a>
					</div>
					<p class="description" style="margin-top: 8px;">
						<?php esc_html_e( 'Keep this link bookmarked or recorded off-site. The token embedded in this URL is your private recovery key.', 'lumos-backup' ); ?>
					</p>
				</div>

				<div style="display: flex; gap: 16px; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-top: 12px;">
					<div style="font-size: 13px; color: #475569;">
						<strong><?php esc_html_e( 'Status:', 'lumos-backup' ); ?></strong>
						<?php if ( $restore_ready && $config_ready ) : ?>
							<span style="color: #15803d; font-weight: 600;">&#10003; <?php esc_html_e( 'Restore script & configuration active', 'lumos-backup' ); ?></span>
						<?php else : ?>
							<span style="color: #b91c1c; font-weight: 600;">&#9888; <?php esc_html_e( 'Configuration pending or missing write permissions', 'lumos-backup' ); ?></span>
						<?php endif; ?>
					</div>
					<div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
						<!-- Download Recovery Sheet Button -->
						<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display: inline-block;">
							<?php wp_nonce_field( 'lumos_backup_download_recovery_sheet' ); ?>
							<input type="hidden" name="lumos_action" value="download_recovery_sheet">
							<button type="submit" class="button button-secondary">
								&#128196; <?php esc_html_e( 'Download recovery sheet', 'lumos-backup' ); ?>
							</button>
						</form>

						<!-- Rotate Link Button -->
						<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display: inline-block;" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to rotate the restore link? Any previously saved links will stop working.', 'lumos-backup' ) ); ?>');">
							<?php wp_nonce_field( 'lumos_backup_rotate_token' ); ?>
							<input type="hidden" name="lumos_action" value="rotate_token">
							<button type="submit" class="button button-secondary">
								<?php esc_html_e( 'Rotate Restore Link', 'lumos-backup' ); ?>
							</button>
						</form>
					</div>
				</div>
			</div>

			<!-- Status & Actions Grid -->
			<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin: 24px 0;">
				<!-- Backup Now & Drill Card -->
				<div class="card" style="padding: 20px;">
					<h2><?php esc_html_e( 'Manual Backup & Drill', 'lumos-backup' ); ?></h2>
					<p><?php esc_html_e( 'Generate a complete snapshot containing your database dump, site manifest, and wp-content files.', 'lumos-backup' ); ?></p>
					
					<div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px;">
						<form method="post" action="<?php echo esc_url( $action_url ); ?>">
							<?php wp_nonce_field( 'lumos_backup_now' ); ?>
							<input type="hidden" name="lumos_action" value="backup_now">
							<button type="submit" class="button button-primary button-hero">
								<?php esc_html_e( 'Backup Now', 'lumos-backup' ); ?>
							</button>
						</form>

						<form method="post" action="<?php echo esc_url( $action_url ); ?>">
							<?php wp_nonce_field( 'lumos_backup_test_drill' ); ?>
							<input type="hidden" name="lumos_action" value="test_drill">
							<button type="submit" class="button button-secondary button-hero">
								&#9881; <?php esc_html_e( 'Test latest backup', 'lumos-backup' ); ?>
							</button>
						</form>
					</div>
				</div>

				<!-- Schedule & Retention Card -->
				<div class="card" style="padding: 20px;">
					<h2><?php esc_html_e( 'Retention & Schedule', 'lumos-backup' ); ?></h2>
					<p>
						<strong><?php esc_html_e( 'Daily Cron:', 'lumos-backup' ); ?></strong>
						<?php
						if ( $next_cron ) {
							/* translators: %s: Human time diff */
							printf( esc_html__( 'Next in %s', 'lumos-backup' ), human_time_diff( time(), $next_cron ) );
						} else {
							esc_html_e( 'Not scheduled', 'lumos-backup' );
						}
						?>
					</p>
					<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin-top: 12px;">
						<?php wp_nonce_field( 'lumos_backup_save_settings' ); ?>
						<input type="hidden" name="lumos_action" value="save_settings">
						<label for="lumos_backup_keep" style="font-weight: 600; display: block; margin-bottom: 4px;">
							<?php esc_html_e( 'Archives to keep (local & remote):', 'lumos-backup' ); ?>
						</label>
						<div style="display: flex; gap: 8px; align-items: center;">
							<input type="number" id="lumos_backup_keep" name="lumos_backup_keep" value="<?php echo esc_attr( $keep_count ); ?>" min="1" max="100" style="width: 80px;">
							<button type="submit" class="button button-secondary">
								<?php esc_html_e( 'Save Retention', 'lumos-backup' ); ?>
							</button>
						</div>
					</form>
				</div>

				<!-- Last Run & Restore Drill Details Card -->
				<div class="card" style="padding: 20px;">
					<h2><?php esc_html_e( 'Last Backup & Verification', 'lumos-backup' ); ?></h2>
					<?php if ( ! empty( $last_run['timestamp'] ) ) : ?>
						<p>
							<strong><?php esc_html_e( 'Status:', 'lumos-backup' ); ?></strong>
							<?php if ( 'success' === ( $last_run['status'] ?? '' ) ) : ?>
								<span style="color: #15803d; font-weight: 600;"><?php esc_html_e( 'Success', 'lumos-backup' ); ?></span>
							<?php else : ?>
								<span style="color: #b91c1c; font-weight: 600;"><?php esc_html_e( 'Failed', 'lumos-backup' ); ?></span>
							<?php endif; ?>
						</p>
						<?php if ( ! empty( $last_run['filename'] ) ) : ?>
							<p>
								<strong><?php esc_html_e( 'Archive ID:', 'lumos-backup' ); ?></strong>
								<code><?php echo esc_html( $last_run['filename'] ); ?></code>
							</p>
						<?php endif; ?>
						<p>
							<strong><?php esc_html_e( 'Run Date (UTC):', 'lumos-backup' ); ?></strong>
							<?php echo esc_html( $last_run['datetime_utc'] ?? '' ); ?>
						</p>
						<?php if ( ! empty( $last_run['size_formatted'] ) ) : ?>
							<p>
								<strong><?php esc_html_e( 'Archive Size:', 'lumos-backup' ); ?></strong>
								<?php echo esc_html( $last_run['size_formatted'] ); ?>
							</p>
						<?php endif; ?>
						<?php if ( ! empty( $last_run['sha256'] ) ) : ?>
							<p>
								<strong><?php esc_html_e( 'SHA-256 Checksum:', 'lumos-backup' ); ?></strong>
								<code style="display: block; font-size: 11px; word-break: break-all; margin-top: 4px;"><?php echo esc_html( $last_run['sha256'] ); ?></code>
							</p>
						<?php endif; ?>
						<?php if ( ! empty( $last_run['s3'] ) ) : ?>
							<p style="margin-top: 6px;">
								<strong><?php esc_html_e( 'Remote S3 Upload:', 'lumos-backup' ); ?></strong>
								<?php if ( 'success' === ( $last_run['s3']['status'] ?? '' ) ) : ?>
									<span style="color: #15803d; font-weight: 600;">&#10003; <?php esc_html_e( 'Uploaded', 'lumos-backup' ); ?></span>
									<code style="display: block; margin-top: 4px; word-break: break-all;"><?php echo esc_html( $last_run['s3']['uri'] ?? '' ); ?></code>
								<?php else : ?>
									<span style="color: #b91c1c; font-weight: 600;">&#9888; <?php esc_html_e( 'Upload Failed', 'lumos-backup' ); ?></span>
									<span style="display: block; color: #b91c1c; font-size: 12px;"><?php echo esc_html( $last_run['s3']['error'] ?? '' ); ?></span>
								<?php endif; ?>
							</p>
						<?php endif; ?>

						<!-- Restore Drill Result Details -->
						<div style="margin-top: 14px; padding-top: 12px; border-top: 1px solid #e2e8f0;">
							<strong><?php esc_html_e( 'Restore Drill Verification:', 'lumos-backup' ); ?></strong>
							<?php if ( ! empty( $last_drill['time'] ) ) : ?>
								<div style="margin-top: 6px;">
									<?php if ( 'pass' === ( $last_drill['status'] ?? '' ) ) : ?>
										<span style="background: #dcfce7; color: #166534; padding: 3px 8px; border-radius: 4px; font-weight: 600; font-size: 12px;">
											&#10003; <?php esc_html_e( 'Passed', 'lumos-backup' ); ?>
										</span>
										<span style="font-size: 12px; color: #64748b; margin-left: 6px;">
											(<?php echo esc_html( $last_drill['datetime_utc'] ?? '' ); ?>)
										</span>
										<div style="font-size: 12px; color: #475569; margin-top: 4px;">
											<?php esc_html_e( 'Tested Archive:', 'lumos-backup' ); ?> <code><?php echo esc_html( $last_drill['archive_id'] ?? '' ); ?></code>
										</div>
									<?php else : ?>
										<span style="background: #fee2e2; color: #991b1b; padding: 3px 8px; border-radius: 4px; font-weight: 600; font-size: 12px;">
											&#10007; <?php esc_html_e( 'Failed', 'lumos-backup' ); ?>
										</span>
										<span style="font-size: 12px; color: #64748b; margin-left: 6px;">
											(<?php echo esc_html( $last_drill['datetime_utc'] ?? '' ); ?>)
										</span>
										<?php if ( ! empty( $last_drill['error'] ) ) : ?>
											<div style="color: #b91c1c; font-size: 12px; margin-top: 4px;">
												<strong><?php esc_html_e( 'Error:', 'lumos-backup' ); ?></strong> <?php echo esc_html( $last_drill['error'] ); ?>
											</div>
										<?php endif; ?>
									<?php endif; ?>
								</div>
							<?php else : ?>
								<div style="margin-top: 6px;">
									<span style="background: #f1f5f9; color: #64748b; padding: 3px 8px; border-radius: 4px; font-weight: 600; font-size: 12px;">
										<?php esc_html_e( 'Unproven (No drill performed)', 'lumos-backup' ); ?>
									</span>
								</div>
							<?php endif; ?>
						</div>

					<?php else : ?>
						<p style="color: #64748b;"><?php esc_html_e( 'No backups have been recorded yet.', 'lumos-backup' ); ?></p>
					<?php endif; ?>
					<p style="margin-top: 10px;">
						<strong><?php esc_html_e( 'Total Local Storage:', 'lumos-backup' ); ?></strong>
						<?php echo esc_html( size_format( $total_bytes, 2 ) ); ?>
					</p>
				</div>
			</div>

			<!-- Automated Restore Drills & Health Monitoring Card -->
			<div class="card" style="padding: 24px; margin-bottom: 24px; max-width: 100%;">
				<h2><?php esc_html_e( 'Automated Restore Drills & Health Monitoring', 'lumos-backup' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Regularly tests your latest backup archive in an isolated sandbox without altering live files or database tables. If a drill fails, an emergency alert email is immediately dispatched.', 'lumos-backup' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin-top: 16px;">
					<?php wp_nonce_field( 'lumos_backup_save_drill_schedule' ); ?>
					<input type="hidden" name="lumos_action" value="save_drill_schedule">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="drill_frequency"><?php esc_html_e( 'Drill Frequency', 'lumos-backup' ); ?></label></th>
							<td>
								<select name="drill_frequency" id="drill_frequency">
									<option value="disabled" <?php selected( $drill_frequency, 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'lumos-backup' ); ?></option>
									<option value="daily" <?php selected( $drill_frequency, 'daily' ); ?>><?php esc_html_e( 'Once Daily', 'lumos-backup' ); ?></option>
									<option value="weekly" <?php selected( $drill_frequency, 'weekly' ); ?>><?php esc_html_e( 'Once Weekly (Recommended)', 'lumos-backup' ); ?></option>
									<option value="monthly" <?php selected( $drill_frequency, 'monthly' ); ?>><?php esc_html_e( 'Once Monthly', 'lumos-backup' ); ?></option>
								</select>
								<?php if ( 'disabled' !== $drill_frequency && $next_drill ) : ?>
									<p class="description" style="color: #15803d; font-weight: 500; margin-top: 4px;">
										<?php printf( esc_html__( 'Next automated drill scheduled in %s.', 'lumos-backup' ), human_time_diff( time(), $next_drill ) ); ?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="drill_email"><?php esc_html_e( 'Alert Notification Email', 'lumos-backup' ); ?></label></th>
							<td>
								<input name="drill_email" type="email" id="drill_email" value="<?php echo esc_attr( $drill_email ); ?>" class="regular-text" placeholder="admin@example.com">
								<p class="description">
									<?php esc_html_e( 'Recipient for failure alerts if an automated restore drill fails verification.', 'lumos-backup' ); ?>
								</p>
							</td>
						</tr>
					</table>
					<p class="submit">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Save Drill Schedule', 'lumos-backup' ); ?>
						</button>
					</p>
				</form>
			</div>

			<!-- Archive Encryption Settings Card -->
			<div class="card" style="padding: 24px; margin-bottom: 24px; max-width: 100%;">
				<h2><?php esc_html_e( 'Archive Encryption (AES-256-CBC)', 'lumos-backup' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Optional passphrase to encrypt backup archives with OpenSSL AES-256-CBC and PBKDF2 key derivation. Leave blank for unencrypted backups.', 'lumos-backup' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin-top: 16px;">
					<?php wp_nonce_field( 'lumos_backup_save_passphrase' ); ?>
					<input type="hidden" name="lumos_action" value="save_passphrase">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="lumos_passphrase"><?php esc_html_e( 'Restore Passphrase', 'lumos-backup' ); ?></label></th>
							<td>
								<input name="lumos_passphrase" type="password" id="lumos_passphrase" value="<?php echo esc_attr( Lumos_Backup_Restore_Gate::get_passphrase() ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Leave empty for unencrypted backups', 'lumos-backup' ); ?>" autocomplete="new-password">
								<p class="description">
									<?php esc_html_e( 'Only the password_hash, KDF salt, and iteration count are mirrored into restore-config.json (mode 0600). The raw passphrase is never written to that file.', 'lumos-backup' ); ?>
								</p>
								<p class="description" style="color: #92400e; font-weight: 500; margin-top: 4px;">
									<?php esc_html_e( 'Note: Rotating the passphrase does not re-encrypt existing archives. It applies to new archives only.', 'lumos-backup' ); ?>
								</p>
							</td>
						</tr>
					</table>
					<p class="submit">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Save Passphrase Settings', 'lumos-backup' ); ?>
						</button>
					</p>
				</form>
			</div>

			<!-- Remote Storage (S3-Compatible) Configuration Card -->
			<div class="card" style="padding: 24px; margin-bottom: 24px; max-width: 100%;">
				<h2><?php esc_html_e( 'Remote Storage: S3-Compatible', 'lumos-backup' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Off-site backup destination. Compatible with Amazon S3, Backblaze B2, Cloudflare R2, MinIO, Wasabi, and other S3-compliant object storage services.', 'lumos-backup' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin-top: 16px;">
					<?php wp_nonce_field( 'lumos_backup_save_s3' ); ?>
					<input type="hidden" name="lumos_action" value="save_s3">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="s3_endpoint"><?php esc_html_e( 'Custom Endpoint URL', 'lumos-backup' ); ?></label></th>
							<td>
								<input name="endpoint" type="text" id="s3_endpoint" value="<?php echo esc_attr( $s3_settings['endpoint'] ?? '' ); ?>" class="regular-text" placeholder="https://s3.us-west-004.backblazeb2.com">
								<p class="description"><?php esc_html_e( 'Leave blank for standard AWS S3. For Backblaze B2, Cloudflare R2, or MinIO, enter the full S3 endpoint URL.', 'lumos-backup' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="s3_region"><?php esc_html_e( 'Region', 'lumos-backup' ); ?></label></th>
							<td>
								<input name="region" type="text" id="s3_region" value="<?php echo esc_attr( $s3_settings['region'] ?? 'us-east-1' ); ?>" class="regular-text" placeholder="us-east-1">
								<p class="description"><?php esc_html_e( 'AWS region or custom region name (e.g. us-east-1, auto, eu-central-1).', 'lumos-backup' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="s3_bucket"><?php esc_html_e( 'Bucket Name', 'lumos-backup' ); ?></label></th>
							<td>
								<input name="bucket" type="text" id="s3_bucket" value="<?php echo esc_attr( $s3_settings['bucket'] ?? '' ); ?>" class="regular-text" required placeholder="my-backup-bucket">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="s3_access_key"><?php esc_html_e( 'Access Key ID', 'lumos-backup' ); ?></label></th>
							<td>
								<input name="access_key" type="text" id="s3_access_key" value="<?php echo esc_attr( $s3_settings['access_key'] ?? '' ); ?>" class="regular-text" autocomplete="off">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="s3_secret_key"><?php esc_html_e( 'Secret Access Key', 'lumos-backup' ); ?></label></th>
							<td>
								<input name="secret_key" type="password" id="s3_secret_key" value="<?php echo esc_attr( $s3_settings['secret_key'] ?? '' ); ?>" class="regular-text" autocomplete="new-password">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="s3_prefix"><?php esc_html_e( 'Folder / Key Prefix', 'lumos-backup' ); ?></label></th>
							<td>
								<input name="prefix" type="text" id="s3_prefix" value="<?php echo esc_attr( $s3_settings['prefix'] ?? '' ); ?>" class="regular-text" placeholder="backups">
								<p class="description"><?php esc_html_e( 'Optional subfolder inside your bucket (e.g. "backups" or "site-backups").', 'lumos-backup' ); ?></p>
							</td>
						</tr>
					</table>

					<div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 12px; margin: 16px 0; font-size: 13px; color: #92400e;">
						<strong><?php esc_html_e( 'Security Risk Notice:', 'lumos-backup' ); ?></strong>
						<?php esc_html_e( 'S3 credentials are saved in WordPress options and mirrored into restore-config.json (mode 0600) alongside database credentials. This allows the standalone emergency restore script to fetch remote backups when WordPress core is offline. Server permissions must protect this file from other system users.', 'lumos-backup' ); ?>
					</div>

					<p class="submit" style="display: flex; gap: 10px; align-items: center;">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Save Remote Settings', 'lumos-backup' ); ?>
						</button>
					</p>
				</form>

				<?php if ( $s3_configured ) : ?>
					<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin-top: -10px;">
						<?php wp_nonce_field( 'lumos_backup_test_s3' ); ?>
						<input type="hidden" name="lumos_action" value="test_s3">
						<button type="submit" class="button button-secondary">
							<?php esc_html_e( 'Test S3 Connection', 'lumos-backup' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</div>

			<!-- Remote Storage: SFTP / SSH Card -->
			<div class="card" style="padding: 24px; margin-bottom: 24px; max-width: 100%;">
				<h2><?php esc_html_e( 'Remote Storage: SFTP (SSH File Transfer Protocol)', 'lumos-backup' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Upload backups directly to a secure remote SFTP server using native PHP cURL SFTP without requiring external extensions.', 'lumos-backup' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin-top: 16px;">
					<?php wp_nonce_field( 'lumos_backup_save_sftp' ); ?>
					<input type="hidden" name="lumos_action" value="save_sftp">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="sftp_host"><?php esc_html_e( 'SFTP Server Host', 'lumos-backup' ); ?></label></th>
							<td>
								<input name="sftp_host" type="text" id="sftp_host" value="<?php echo esc_attr( $sftp_settings['host'] ?? '' ); ?>" class="regular-text" placeholder="sftp.example.com">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sftp_port"><?php esc_html_e( 'Port', 'lumos-backup' ); ?></label></th>
							<td>
								<input name="sftp_port" type="number" id="sftp_port" value="<?php echo esc_attr( $sftp_settings['port'] ?? 22 ); ?>" style="width: 80px;" min="1" max="65535">
								<p class="description"><?php esc_html_e( 'Default is 22.', 'lumos-backup' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sftp_username"><?php esc_html_e( 'Username', 'lumos-backup' ); ?></label></th>
							<td>
								<input name="sftp_username" type="text" id="sftp_username" value="<?php echo esc_attr( $sftp_settings['username'] ?? '' ); ?>" class="regular-text" autocomplete="off">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sftp_password"><?php esc_html_e( 'Password', 'lumos-backup' ); ?></label></th>
							<td>
								<input name="sftp_password" type="password" id="sftp_password" value="<?php echo esc_attr( $sftp_settings['password'] ?? '' ); ?>" class="regular-text" autocomplete="new-password">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sftp_path"><?php esc_html_e( 'Remote Directory Path', 'lumos-backup' ); ?></label></th>
							<td>
								<input name="sftp_path" type="text" id="sftp_path" value="<?php echo esc_attr( $sftp_settings['path'] ?? '' ); ?>" class="regular-text" placeholder="/backups/site1">
								<p class="description"><?php esc_html_e( 'Absolute path on the remote server where backup archives should be stored.', 'lumos-backup' ); ?></p>
							</td>
						</tr>
					</table>

					<p class="submit" style="display: flex; gap: 10px; align-items: center;">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Save SFTP Settings', 'lumos-backup' ); ?>
						</button>
					</p>
				</form>

				<?php if ( $sftp_configured ) : ?>
					<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin-top: -10px;">
						<?php wp_nonce_field( 'lumos_backup_test_sftp' ); ?>
						<input type="hidden" name="lumos_action" value="test_sftp">
						<button type="submit" class="button button-secondary">
							<?php esc_html_e( 'Test SFTP Connection', 'lumos-backup' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</div>

			<!-- Archives Table -->
			<h2><?php esc_html_e( 'Available Backup Archives', 'lumos-backup' ); ?></h2>
			<table class="wp-list-table widefat fixed striped" style="margin-top: 12px;">
				<thead>
					<tr>
						<th scope="col" style="width: 30%;"><?php esc_html_e( 'Archive Name', 'lumos-backup' ); ?></th>
						<th scope="col" style="width: 15%;"><?php esc_html_e( 'Location', 'lumos-backup' ); ?></th>
						<th scope="col" style="width: 20%;"><?php esc_html_e( 'Date Created (UTC)', 'lumos-backup' ); ?></th>
						<th scope="col" style="width: 15%;"><?php esc_html_e( 'Size', 'lumos-backup' ); ?></th>
						<th scope="col" style="width: 20%;"><?php esc_html_e( 'Actions', 'lumos-backup' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$combined = [];
					foreach ( $archives as $a ) {
						$a['origin'] = 'local';
						$combined[]  = $a;
					}
					foreach ( $remote_archives as $ra ) {
						$combined[] = $ra;
					}
					if ( class_exists( 'Lumos_Backup_SFTP' ) && Lumos_Backup_SFTP::is_configured() ) {
						$sftp_archives = Lumos_Backup_SFTP::list_objects();
						if ( ! is_wp_error( $sftp_archives ) && is_array( $sftp_archives ) ) {
							foreach ( $sftp_archives as $sa ) {
								$combined[] = $sa;
							}
						}
					}

					// Sort newest first
					usort(
						$combined,
						function ( $x, $y ) {
							return ( $y['mtime'] ?? 0 ) <=> ( $x['mtime'] ?? 0 );
						}
					);
					?>

					<?php if ( empty( $combined ) ) : ?>
						<tr>
							<td colspan="5" style="text-align: center; padding: 24px; color: #64748b;">
								<?php esc_html_e( 'No backup archives found. Click "Backup Now" to create your first archive.', 'lumos-backup' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $combined as $arch ) : ?>
							<?php
							$is_local     = ( 'local' === ( $arch['origin'] ?? 'local' ) );
							$download_url = $is_local ? wp_nonce_url(
								add_query_arg(
									[
										'page'         => 'lumos-backup',
										'lumos_action' => 'download',
										'archive'      => $arch['filename'],
									],
									admin_url( 'tools.php' )
								),
								'lumos_download_' . $arch['filename']
							) : '';
							$delete_url   = $is_local ? wp_nonce_url(
								add_query_arg(
									[
										'page'         => 'lumos-backup',
										'lumos_action' => 'delete',
										'archive'      => $arch['filename'],
									],
									admin_url( 'tools.php' )
								),
								'lumos_delete_' . $arch['filename']
							) : '';
							?>
							<tr>
								<td>
									<strong><code><?php echo esc_html( $arch['filename'] ); ?></code></strong>
									<?php if ( ! empty( $last_drill['archive_id'] ) && $last_drill['archive_id'] === $arch['filename'] ) : ?>
										<?php if ( 'pass' === ( $last_drill['status'] ?? '' ) ) : ?>
											<span style="background: #dcfce7; color: #166534; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-left: 6px;">
												&#10003; <?php esc_html_e( 'Drill Passed', 'lumos-backup' ); ?>
											</span>
										<?php else : ?>
											<span style="background: #fee2e2; color: #991b1b; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-left: 6px;">
												&#10007; <?php esc_html_e( 'Drill Failed', 'lumos-backup' ); ?>
											</span>
										<?php endif; ?>
									<?php else : ?>
										<span style="background: #f1f5f9; color: #64748b; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; text-transform: uppercase; margin-left: 6px;">
											<?php esc_html_e( 'Unproven', 'lumos-backup' ); ?>
										</span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $is_local ) : ?>
										<span style="background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">
											<?php esc_html_e( 'Local Disk', 'lumos-backup' ); ?>
										</span>
									<?php elseif ( 'sftp' === ( $arch['origin'] ?? '' ) ) : ?>
										<span style="background: #f3e8ff; color: #6b21a8; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">
											<?php esc_html_e( 'Remote SFTP', 'lumos-backup' ); ?>
										</span>
									<?php else : ?>
										<span style="background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">
											<?php esc_html_e( 'Remote S3', 'lumos-backup' ); ?>
										</span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $arch['created_utc'] ?? '' ); ?></td>
								<td><?php echo esc_html( $arch['size_formatted'] ?? '' ); ?></td>
								<td>
									<?php if ( $is_local ) : ?>
										<a href="<?php echo esc_url( $download_url ); ?>" class="button button-small">
											<?php esc_html_e( 'Download', 'lumos-backup' ); ?>
										</a>
										<a href="<?php echo esc_url( $restore_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-small">
											<?php esc_html_e( 'Restore', 'lumos-backup' ); ?>
										</a>
										<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js( sprintf( __( 'Permanently delete %s?', 'lumos-backup' ), $arch['filename'] ) ); ?>');">
											<?php esc_html_e( 'Delete', 'lumos-backup' ); ?>
										</a>
									<?php else : ?>
										<a href="<?php echo esc_url( $restore_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-small">
											<?php esc_html_e( 'Restore (S3)', 'lumos-backup' ); ?>
										</a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<script>
		function lumosCopyUrl(button) {
			const input = document.getElementById('lumos-restore-url');
			if (!input) return;
			input.select();
			navigator.clipboard.writeText(input.value).then(() => {
				const originalText = button.textContent;
				button.textContent = '<?php echo esc_js( __( 'Copied!', 'lumos-backup' ) ); ?>';
				setTimeout(() => { button.textContent = originalText; }, 2000);
			}).catch(() => {
				document.execCommand('copy');
			});
		}
		</script>
		<?php
	}
}
