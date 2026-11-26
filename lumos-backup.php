<?php
/**
 * Plugin Name:       Lumos Backup
 * Plugin URI:        https://example.com/lumos-backup
 * Description:       Emergency-resilient WordPress backups with an independent standalone restore page that runs even when WordPress will not boot.
 * Version:           1.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Lumos Backup Team
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lumos-backup
 * Update URI:        https://github.com/centralbaku/lumos-backup
 *
 * @package LumosBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LUMOS_BACKUP_VERSION', '1.1.1' );
define( 'LUMOS_BACKUP_FILE', __FILE__ );
define( 'LUMOS_BACKUP_PATH', plugin_dir_path( __FILE__ ) );
define( 'LUMOS_BACKUP_URL', plugin_dir_url( __FILE__ ) );
define( 'LUMOS_BACKUP_BASENAME', plugin_basename( __FILE__ ) );

// Shared LUMOS admin menu (identical copy ships in every Lumos plugin)
require_once LUMOS_BACKUP_PATH . 'includes/lumos-menu.php';

// Shared GitHub updater: every version pushed to main is offered as a WordPress update.
require_once LUMOS_BACKUP_PATH . 'includes/lumos-updater.php';
new Lumos_GitHub_Updater( __FILE__, 'centralbaku/lumos-backup', LUMOS_BACKUP_VERSION );

// Load class files
require_once LUMOS_BACKUP_PATH . 'includes/class-storage.php';
require_once LUMOS_BACKUP_PATH . 'includes/class-progress.php';
require_once LUMOS_BACKUP_PATH . 'includes/class-thumbnails.php';
require_once LUMOS_BACKUP_PATH . 'includes/class-database.php';
require_once LUMOS_BACKUP_PATH . 'includes/class-s3.php';
require_once LUMOS_BACKUP_PATH . 'includes/class-sftp.php';
require_once LUMOS_BACKUP_PATH . 'includes/class-drill.php';
require_once LUMOS_BACKUP_PATH . 'includes/class-archiver.php';
require_once LUMOS_BACKUP_PATH . 'includes/class-scheduler.php';
require_once LUMOS_BACKUP_PATH . 'includes/class-restore-gate.php';
require_once LUMOS_BACKUP_PATH . 'includes/class-admin.php';
require_once LUMOS_BACKUP_PATH . 'includes/class-cli.php';

// Register WP-CLI commands when running under CLI
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	Lumos_Backup_CLI::register();
}

/**
 * Plugin activation hook.
 *
 * Sets up storage directories, generates recovery token, writes restore-config.json (mode 0600),
 * copies standalone lumos-restore.php into ABSPATH, and schedules the daily backup cron event.
 */
function lumos_backup_activate() {
	// 1. Ensure storage and archives directories exist and are protected
	Lumos_Backup_Storage::ensure_directories();

	// 2. Ensure random 32-byte secret token exists
	$token = Lumos_Backup_Restore_Gate::get_token();

	// 3. Write restore-config.json mode 0600 with sha256 of token
	Lumos_Backup_Restore_Gate::write_config_file( $token );

	// 4. Deploy standalone restore UI to ABSPATH/lumos-restore.php
	Lumos_Backup_Restore_Gate::deploy_restore_script();

	// 5. Schedule daily WP-Cron backup event
	Lumos_Backup_Scheduler::schedule();

	// 6. Set default retention limit
	if ( false === get_option( 'lumos_backup_keep' ) ) {
		update_option( 'lumos_backup_keep', 7 );
	}
}
register_activation_hook( __FILE__, 'lumos_backup_activate' );

/**
 * Plugin deactivation hook.
 *
 * Clears scheduled cron events. Archives, config, and standalone restore script
 * are intentionally retained so emergency recovery remains possible.
 */
function lumos_backup_deactivate() {
	Lumos_Backup_Scheduler::unschedule();
}
register_deactivation_hook( __FILE__, 'lumos_backup_deactivate' );

/**
 * Initialize plugin components on plugins_loaded.
 */
function lumos_backup_init() {
	// Initialize daily cron listener
	$scheduler = new Lumos_Backup_Scheduler();
	$scheduler->init();

	// Rebuilds image sizes after restoring a backup made with "skip generated image sizes".
	Lumos_Backup_Thumbnails::init();

	// Initialize admin screens and actions
	if ( is_admin() ) {
		$admin = new Lumos_Backup_Admin();
		$admin->init();
	}
}
add_action( 'plugins_loaded', 'lumos_backup_init' );
