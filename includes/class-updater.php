<?php
/**
 * GitHub Self-Hosted Auto-Updater for Lumos Backup.
 *
 * Checks the GitHub repository releases for new versions, notifies WordPress admin,
 * and enables native 1-click updates from GitHub release zip packages.
 *
 * @package LumosBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lumos_Backup_Updater
 */
class Lumos_Backup_Updater {

	/**
	 * GitHub owner/repo slug.
	 */
	const REPO = 'centralbaku/lumos-backup';

	/**
	 * Plugin basename (e.g. lumos-backup/lumos-backup.php).
	 */
	private $plugin_basename;

	/**
	 * Plugin slug (e.g. lumos-backup).
	 */
	private $plugin_slug;

	/**
	 * Current version.
	 */
	private $version;

	/**
	 * Transient cache key.
	 */
	const TRANSIENT_KEY = 'lumos_backup_gh_release_info';

	/**
	 * Constructor.
	 *
	 * @param string $plugin_basename Plugin file basename.
	 * @param string $version         Current version string.
	 */
	public function __construct( $plugin_basename, $version ) {
		$this->plugin_basename = $plugin_basename;
		$this->plugin_slug     = dirname( $plugin_basename );
		$this->version         = $version;
	}

	/**
	 * Register update hooks into WordPress.
	 */
	public function init() {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_plugin_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_popup_information' ], 20, 3 );
		add_filter( 'upgrader_post_install', [ $this, 'post_install_cleanup' ], 10, 3 );
	}

	/**
	 * Fetch latest release from GitHub API with transient caching.
	 *
	 * @param bool $force Force cache refresh.
	 * @return array|false Release data array or false on failure.
	 */
	public function get_latest_github_release( $force = false ) {
		if ( ! $force ) {
			$cached = get_site_transient( self::TRANSIENT_KEY );
			if ( false !== $cached && is_array( $cached ) ) {
				return $cached;
			}
		}

		$url = sprintf( 'https://api.github.com/repos/%s/releases/latest', self::REPO );

		$args = [
			'timeout'    => 10,
			'headers'    => [
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'WordPress-LumosBackup-Updater/' . $this->version,
			],
			'sslverify'  => true,
		];

		// Optional private token support via constant if repository is made private
		if ( defined( 'LUMOS_GITHUB_TOKEN' ) && LUMOS_GITHUB_TOKEN ) {
			$args['headers']['Authorization'] = 'Bearer ' . LUMOS_GITHUB_TOKEN;
		}

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return false;
		}

		// Cache for 6 hours
		set_site_transient( self::TRANSIENT_KEY, $body, 6 * HOUR_IN_SECONDS );

		return $body;
	}

	/**
	 * Check GitHub release against local version and push update package info to WordPress.
	 *
	 * @param object $transient Update plugins transient.
	 * @return object
	 */
	public function check_for_plugin_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// Force check if user is on Updates screen checking manually
		global $pagenow;
		$force = ( 'update-core.php' === $pagenow && isset( $_GET['force-check'] ) );

		$release = $this->get_latest_github_release( $force );
		if ( ! $release ) {
			return $transient;
		}

		$raw_tag        = $release['tag_name'] ?? '';
		$latest_version = ltrim( $raw_tag, 'vV' );

		// Compare versions
		if ( version_compare( $latest_version, $this->version, '>' ) ) {
			// Find release zip asset (e.g. lumos-backup-vX.Y.Z.zip)
			$package_url = '';
			if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
				foreach ( $release['assets'] as $asset ) {
					if ( isset( $asset['name'] ) && preg_match( '/^lumos-backup.*\.zip$/i', $asset['name'] ) ) {
						$package_url = $asset['browser_download_url'] ?? '';
						break;
					}
				}
			}

			// Fallback to github zipball if specific asset not found
			if ( empty( $package_url ) && ! empty( $release['zipball_url'] ) ) {
				$package_url = $release['zipball_url'];
			}

			if ( ! empty( $package_url ) ) {
				$plugin_info = (object) [
					'id'            => 'https://github.com/' . self::REPO,
					'slug'          => $this->plugin_slug,
					'plugin'        => $this->plugin_basename,
					'new_version'   => $latest_version,
					'url'           => 'https://github.com/' . self::REPO,
					'package'       => $package_url,
					'icons'         => [],
					'banners'       => [],
					'banners_rtl'   => [],
					'tested'        => '6.7',
					'requires_php'  => '7.4',
					'compatibility' => new stdClass(),
				];

				$transient->response[ $this->plugin_basename ] = $plugin_info;
			}
		}

		return $transient;
	}

	/**
	 * Provide modal details when "View version X details" is clicked.
	 *
	 * @param false|object|array $result Default result.
	 * @param string             $action Action name.
	 * @param object             $args   Query args.
	 * @return object|false
	 */
	public function plugin_popup_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $args->slug !== $this->plugin_slug ) {
			return $result;
		}

		$release = $this->get_latest_github_release();
		if ( ! $release ) {
			return $result;
		}

		$latest_version = ltrim( $release['tag_name'] ?? '1.0.0', 'vV' );
		$changelog      = $release['body'] ?? 'No changelog provided.';

		$package_url = '';
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( isset( $asset['name'] ) && preg_match( '/^lumos-backup.*\.zip$/i', $asset['name'] ) ) {
					$package_url = $asset['browser_download_url'] ?? '';
					break;
				}
			}
		}

		$response = (object) [
			'name'          => 'Lumos Backup',
			'slug'          => $this->plugin_slug,
			'version'       => $latest_version,
			'author'        => '<a href="https://github.com/' . self::REPO . '">Lumos Backup Team</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.0',
			'tested'        => '6.7',
			'requires_php'  => '7.4',
			'download_link' => $package_url,
			'sections'      => [
				'description' => 'Emergency-resilient WordPress backups paired with an independent standalone restore page that runs even when WordPress will not boot.',
				'changelog'   => nl2br( esc_html( $changelog ) ),
			],
		];

		return $response;
	}

	/**
	 * Ensure the upgraded folder is properly named 'lumos-backup'.
	 *
	 * @param bool  $response   Install response.
	 * @param array $hook_extra Extra hook parameters.
	 * @param array $result     Installation results.
	 * @return array
	 */
	public function post_install_cleanup( $response, $hook_extra, $result ) {
		global $wp_filesystem;

		// Only handle this plugin
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $result;
		}

		$proper_destination = WP_PLUGIN_DIR . '/' . $this->plugin_slug;
		$installed_folder   = $result['destination'] ?? '';

		if ( ! empty( $installed_folder ) && $installed_folder !== $proper_destination ) {
			$wp_filesystem->move( $installed_folder, $proper_destination );
			$result['destination'] = $proper_destination;
		}

		return $result;
	}

	/**
	 * Manually check for updates immediately and clear transient.
	 *
	 * @return array|false
	 */
	public function force_check() {
		delete_site_transient( self::TRANSIENT_KEY );
		return $this->get_latest_github_release( true );
	}
}
