<?php
/**
 * Plugin Name: Jetpack Beta Tester
 * Plugin URI: https://jetpack.com/beta/
 * Description: Use the Beta plugin to get a sneak peek at new features and test them on your site.
 * Version: 2.3
 * Author: Automattic
 * Author URI: https://jetpack.com/
 * License: GPLv2 or later
 *
 * @package Jetpack Beta
 */

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How this plugin works.
 * Jetpack beta manages files inside jetpack-dev folder this folder should contain
 */
define( 'JPBETA__PLUGIN_FOLDER',  basename( dirname( __FILE__ ) ) );
define( 'JPBETA__PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JPBETA__PLUGIN_FILE', __FILE__ );
define( 'JPBETA_VERSION', 2 );

define( 'JPBETA_DEFAULT_BRANCH', 'rc_only' );

define( 'JETPACK_BETA_MANIFEST_URL', 'https://betadownload.jetpack.me/jetpack-branches.json' );
define( 'JETPACK_ORG_API_URL', 'https://api.wordpress.org/plugins/info/1.0/jetpack.json' );
define( 'JETPACK_RC_API_URL', 'https://betadownload.jetpack.me/rc/rc.json' );
define( 'JETPACK_GITHUB_API_URL', 'https://api.github.com/repos/Automattic/Jetpack/' );
define( 'JETPACK_GITHUB_URL', 'https://github.com/Automattic/jetpack' );
define( 'JETPACK_DEFAULT_URL', 'https://jetpack.com' );

define( 'JETPACK_DEV_PLUGIN_SLUG', 'jetpack-dev' );

define( 'JETPACK_PLUGIN_FILE', 'jetpack/jetpack.php' );
define( 'JETPACK_DEV_PLUGIN_FILE', 'jetpack-dev/jetpack.php' );

define( 'JETPACK_BETA_REPORT_URL', 'https://jetpack.com/contact-support/beta-group/' );


require_once 'autoupdate-self.php';
require_once 'class-jetpackbetaclicommand.php';
add_action( 'init', array( 'Jetpack_Beta_Autoupdate_Self', 'instance' ) );

class Jetpack_Beta {

	protected static $_instance = null;

	static $option = 'jetpack_beta_active';
	static $option_dev_installed = 'jetpack_beta_dev_currently_installed';

	static $auto_update_cron_hook = 'jetpack_beta_autoupdate_hourly_cron';

	/**
	 * Main Instance
	 */
	public static function instance() {
		return self::$_instance = is_null( self::$_instance ) ? new self() : self::$_instance;
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		if ( isset( $_GET['delete'] ) ) {
			delete_site_transient( 'update_plugins' );
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'maybe_plugins_update_transient' ) );
		add_filter( 'upgrader_post_install', array( $this, 'upgrader_post_install' ), 10, 3 );

		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ) );
		add_action( 'deactivate_plugin', array( $this, 'plugin_deactivated' ) , 10, 2 );

		add_action( 'upgrader_process_complete', array( $this, 'upgrader_process_complete' ), 10, 2 );

		add_filter( 'plugin_action_links_' . JETPACK_PLUGIN_FILE, array( $this, 'remove_activate_stable' ) );
		add_filter( 'plugin_action_links_' . JETPACK_DEV_PLUGIN_FILE, array( $this, 'remove_activate_dev' ) );

		add_filter( 'network_admin_plugin_action_links_' . JETPACK_PLUGIN_FILE, array( $this, 'remove_activate_stable' ) );
		add_filter( 'network_admin_plugin_action_links_' . JETPACK_DEV_PLUGIN_FILE, array( $this, 'remove_activate_dev' ) );

		add_filter( 'all_plugins', array( $this, 'update_all_plugins' ) );

		add_filter( 'plugins_api', array( $this, 'get_plugin_info' ), 10, 3 );

		add_action( 'jetpack_beta_autoupdate_hourly_cron', array( 'Jetpack_Beta', 'run_autoupdate' ) );

		if ( is_admin() ) {
			require JPBETA__PLUGIN_DIR . 'jetpack-beta-admin.php';
			Jetpack_Beta::maybe_schedule_autoupdate();
			Jetpack_Beta_Admin::init();
		}
	}

	public function upgrader_process_complete( $upgrader, $updates_completed ) {
		if ( ! isset( $updates_completed['plugins'] ) ) {
			return;
		}

		if ( $updates_completed['action'] == 'update' &&
		     $updates_completed['type'] == 'plugin' &&
		     in_array( JETPACK_DEV_PLUGIN_FILE, $updates_completed['plugins'] ) ) {
			list( $branch, $section ) = self::get_branch_and_section_dev();
			if ( self::should_update_dev_to_master() ) {
				list( $branch, $section ) = array( 'master', 'master' );
			}
			update_option( self::$option_dev_installed, array( $branch, $section, self::get_manifest_data( $branch, $section ) ) );
		}

	}

	public static function is_network_enabled() {
		if ( Jetpack_Beta::is_network_active() ) {
			add_filter( 'option_active_plugins', array( 'Jetpack_Beta','override_active_plugins' ) );
		}
	}

	/**
	 * @param $active_plugins
	 * Make sure that you can't have Jetpack and Jetpack Dev plugins versions loaded
	 * This filter is only applied if Jetpack is network activated.
	 * @return array
	 */
	public static function override_active_plugins( $active_plugins ) {
		$new_active_plugins = array();
		foreach( $active_plugins as $active_plugin ) {
			if ( ! self::is_jetpack_plugin( $active_plugin ) ) {
			$new_active_plugins[] = $active_plugin;
			}
		}
		return $new_active_plugins;
	}

	public function plugin_deactivated( $plugin, $network_wide ) {
		if ( ! Jetpack_Beta::is_jetpack_plugin( $plugin ) ) {
			return;
		}

		delete_option( Jetpack_Beta::$option );
	}

	public static function is_jetpack_plugin( $plugin ) {
		return in_array( $plugin, array( JETPACK_PLUGIN_FILE, JETPACK_DEV_PLUGIN_FILE ) );
	}

	public function remove_activate_dev( $actions ) {
		if ( is_plugin_active( JETPACK_PLUGIN_FILE ) || self::is_network_active() ) {
			$actions['activate'] = __( 'Plugin Already Active', 'jetpack-beta' );
		}
		return $actions;
	}

	public function remove_activate_stable( $actions ) {
		if ( is_plugin_active( JETPACK_DEV_PLUGIN_FILE ) || self::is_network_active() ) {
			$actions['activate'] = __( 'Plugin Already Active', 'jetpack-beta' );
		}
		return $actions;
	}

	public function update_all_plugins( $plugins ) {
		// WP.com requests away show regular plugin
		if ( defined( 'REST_API_REQUEST' ) && REST_API_REQUEST ) {
			//Ensure that Jetpack reports the version it's using on account of the Jetpack Beta plugin to Calypso
			if ( is_plugin_active( JETPACK_DEV_PLUGIN_FILE ) ) {
				$plugins[ JETPACK_PLUGIN_FILE ]['Version'] = $plugins[ JETPACK_DEV_PLUGIN_FILE ]['Version'];
			}
			unset( $plugins[ JETPACK_DEV_PLUGIN_FILE ] );
			return $plugins;
		}

		if ( is_plugin_active( JETPACK_DEV_PLUGIN_FILE ) ) {
			unset( $plugins[ JETPACK_PLUGIN_FILE ] );
		} else {
			unset( $plugins[ JETPACK_DEV_PLUGIN_FILE ] );
		}
		return $plugins;
	}

	public function update_jetpack_dev( $plugin ) {
		$plugin['Name'] = $plugin['Name'] . ' | ' . Jetpack_Beta::get_jetpack_plugin_pretty_version( true );
		return $plugin;
	}

	public function get_plugin_info( $false, $action, $response ) {

		// Check if this call API is for the right plugin
		if ( ! isset( $response->slug ) || $response->slug != JETPACK_DEV_PLUGIN_SLUG ) {
			return false;
		}
		$update_date =  null;
		$download_zip = null;
		$dev_data = self::get_dev_installed();
		if ( isset( $dev_data[2] ) ) {
			$update_date = $dev_data[2]->update_date;
			$download_zip = $dev_data[2]->download_url;
		}
		// Update tags
		$response->slug          = JETPACK_DEV_PLUGIN_SLUG;
		$response->plugin        = JETPACK_DEV_PLUGIN_SLUG;
		$response->name          = 'Jetpack | '. self::get_jetpack_plugin_pretty_version( true );
		$response->plugin_name   = 'Jetpack | '. self::get_jetpack_plugin_pretty_version( true );
		$response->version       = self::get_jetpack_plugin_version( true );
		$response->author        = 'Automattic';
		$response->homepage      = 'https://jetpack.com/contact-support/beta-group/';
		$response->downloaded    = false;
		$response->last_updated  = $update_date;
		$response->sections      = array( 'description' => Jetpack_Beta_Admin::to_test_content());
 		$response->download_link = $download_zip;
		return $response;


	}
	/**
	 * Run on activation to flush update cache
	 */
	public static function activate() {
		// don't do anyting funnly
		if ( defined('DOING_CRON')  ) {
			return;
		}
		delete_site_transient( 'update_plugins' );
	}

	public static function get_plugin_file() {
		return self::get_plugin_slug() . '/jetpack.php';
	}

	public static function get_plugin_slug() {
		$installed = self::get_branch_and_section();
		if ( empty( $installed ) || $installed[1] === 'stable' ) {
			return 'jetpack';
		}
		return JETPACK_DEV_PLUGIN_SLUG;
	}

	public static function deactivate() {
		// don't do anyting funnly
		if ( defined('DOING_CRON')  ) {
			return;
		}

		Jetpack_Beta::clear_autoupdate_cron();
		Jetpack_Beta::delete_all_transiants();
		add_action( 'shutdown', array( __CLASS__, 'switch_active' ), 5 );
		add_action( 'shutdown', array( __CLASS__, 'remove_dev_plugin' ), 20 );
		delete_option( self::$option );
	}

	static function remove_dev_plugin() {
		if ( is_multisite() ) {
			return;
		}

		// Delete the jetpack dev plugin
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$creds = request_filesystem_credentials( site_url() . '/wp-admin/', '', false, false, array() );
		if ( ! WP_Filesystem( $creds ) ) {
			/* any problems and we exit */
			return;
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return;
		}

		$working_dir = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . JETPACK_DEV_PLUGIN_SLUG;
		// delete the folder JETPACK_BETA_PLUGIN_FOLDER
		if ( $wp_filesystem->is_dir( $working_dir ) ) {
			$wp_filesystem->delete( $working_dir, true );
		}
		// Since we are removing this dev plugin we should also clean up this data.
		delete_option( self::$option_dev_installed );
	}

	static function admin_url( $query = '?page=jetpack-beta' ) {
		return ( Jetpack_Beta::is_network_active() )
			? network_admin_url( 'admin.php' . $query )
			: admin_url( 'admin.php' . $query );
	}

	public function admin_bar_menu() {
		global $wp_admin_bar;

		if ( ! is_object( $wp_admin_bar ) )
			return;

		// Nothing got activated yet.
		if ( ! self::get_option() ) {
			return;
		}

		$args = array(
			'id'    => 'jetpack-beta_admin_bar',
			'title' => 'Jetpack Beta',
			'parent' => 'top-secondary',
			'href'  => current_user_can( 'update_plugins' ) ? self::admin_url() : ''
		);
		$wp_admin_bar->add_node( $args );

		// add a child item to our parent item
		$args = array(
			'id'     => 'jetpack-beta_version',
			'title'  => sprintf( __( 'Running %s', 'jetpack-beta' ), self::get_jetpack_plugin_pretty_version() ),
			'parent' => 'jetpack-beta_admin_bar'
		);

		$wp_admin_bar->add_node( $args );

		if ( self::get_plugin_slug() === JETPACK_DEV_PLUGIN_SLUG ) {
			// Highlight the menu if you are running the BETA Versions..
			echo "<style>#wpadminbar #wp-admin-bar-jetpack-beta_admin_bar { background: #00BE28; }</style>";
		}

		$args = array(
			'id'     => 'jetpack-beta_report',
			'title'  => __( 'Report Bug', 'jetpack-beta' ),
			'href'   => JETPACK_BETA_REPORT_URL,
			'parent' => 'jetpack-beta_admin_bar'
		);
		$wp_admin_bar->add_node( $args );

		list( $branch, $section ) = self::get_branch_and_section();
		if( 'pr' === $section ) {
			$args = array(
				'id'     => 'jetpack-beta_report_more_info',
				'title'  => __( 'More Info ', 'jetpack-beta' ),
				'href'   => self::get_url( $branch, $section),
				'parent' => 'jetpack-beta_admin_bar'
			);
			$wp_admin_bar->add_node( $args );
		}
	}

	public function maybe_plugins_update_transient( $transient ) {
		if ( !isset( $transient->no_update ) ) {
			return $transient;
		}

		// Do not try to update things that do not exist
		if ( ! file_exists(  WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . JETPACK_DEV_PLUGIN_FILE ) ) {
			return $transient;
		}

		// Do not look for update if we are stable branch
		if ( self::is_on_stable() ) {
			return $transient;
		}

		// Lets always grab the latest
		delete_site_transient( 'jetpack_beta_manifest' );

		// check if there is a new version
		if ( self::should_update_dev_to_master() ) {
			// If response is false, don't alter the transient
			$transient->response[ JETPACK_DEV_PLUGIN_FILE ] = self::get_jepack_dev_master_update_response();
			// unset the that it doesn't need an update...
			unset( $transient->no_update[ JETPACK_DEV_PLUGIN_FILE ] );
		} else if( self::should_update_dev_version() ) {
			// If response is false, don't alter the transient
			$transient->response[ JETPACK_DEV_PLUGIN_FILE ] = self::get_jepack_dev_update_response();
			// unset the that it doesn't need an update...
			unset( $transient->no_update[ JETPACK_DEV_PLUGIN_FILE ] );
		} else {
			unset( $transient->response[ JETPACK_DEV_PLUGIN_FILE ] );
			if ( isset( $transient->no_update ) ) {
				$transient->no_update[ JETPACK_DEV_PLUGIN_FILE ] = self::get_jepack_dev_update_response();
			}
		}

		return $transient;
	}

	static function should_update_dev_version() {
		return version_compare( self::get_new_jetpack_version( true ), self::get_jetpack_plugin_version( true ), '>' );
	}

	static function get_jepack_dev_update_response() {
		$response               = new stdClass;
		$response->id           = JETPACK_DEV_PLUGIN_SLUG;
		$response->plugin       = JETPACK_DEV_PLUGIN_SLUG;
		$response->new_version  = self::get_new_jetpack_version( true );
		$response->slug         = JETPACK_DEV_PLUGIN_SLUG;
		$response->url          = self::get_url_dev();
		$response->package      = self::get_install_url_dev();
		return $response;
	}

	static function get_jepack_dev_master_update_response() {
		$response = self::get_jepack_dev_update_response();

		$master_manifest = self::get_manifest_data( 'master', 'master' );
		$response->new_version  = $master_manifest->version;
		$response->url          = self::get_url( 'master', 'master' );
		$response->package      = $master_manifest->download_url;
		return $response;
	}

	/**
	 * Moves the newly downloaded folder into jetpack-dev
	 * @param $worked
	 * @param $hook_extras
	 * @param $result
	 *
	 * @return WP_Error
	 */
	public function upgrader_post_install( $worked, $hook_extras, $result ) {
		global $wp_filesystem;

		if (
			! isset( $hook_extras['plugin'] )
			|| JETPACK_DEV_PLUGIN_FILE !== $hook_extras['plugin']
		) {
			return $worked;
		}

		if ( $wp_filesystem->move( $result['destination'], WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . JETPACK_DEV_PLUGIN_SLUG , true ) ) {
			return $worked;
		} else {
			return new WP_Error();
		}
	}

	static function get_jetpack_plugin_version( $is_dev_version = false ) {
		if ( $is_dev_version ) {
			$info = self::get_jetpack_plugin_info_dev();
		} else {
			$info = self::get_jetpack_plugin_info();
		}

		return isset( $info['Version'] ) ? $info['Version'] : 0 ;
	}

	static function get_option() {
		return get_option( self::$option );
	}

	static function get_dev_installed() {
		return get_option( self::$option_dev_installed );
	}

	static function get_branch_and_section() {
		$option = (array) self::get_option();
		if ( false === $option[0] ) {
			// see if the jetpack is plugin enabled
			if ( is_plugin_active( JETPACK_PLUGIN_FILE ) ) {
				return array( 'stable', 'stable' );
			}
			return array( false, false );
		}
		// branch and section
		return $option;
	}

	static function is_on_stable() {
		$branch_and_section = self::get_branch_and_section();
		if ( empty( $branch_and_section[0] ) || $branch_and_section[0] == 'stable' ) {
			return true;
		}
		return false;
	}

	static function get_branch_and_section_dev() {
		$option = (array) self::get_dev_installed();
		if ( false !== $option[0] && isset( $option[1] )) {
			return array( $option[0], $option[1] );
		}
		if ( is_plugin_active( JETPACK_DEV_PLUGIN_FILE ) ) {
			return array( 'stable', 'stable' );
		}
		return array( false, false );
	}

	static function get_jetpack_plugin_pretty_version( $is_dev_version = false ) {
		if( $is_dev_version ) {
			list( $branch, $section ) = self::get_branch_and_section_dev();
		} else {
			list( $branch, $section ) = self::get_branch_and_section();
		}

		if ( ! $section  ) {
			return '';
		}

		if ( 'master' === $section ) {
			return 'Bleeding Edge';
		}

		if ( 'stable' === $section ) {
			return 'Latest Stable';
		}

		if ( 'rc' === $section ) {
			return 'Release Candidate';
		}

		if ( 'pr' === $section ) {
			$branch = str_replace( '-', ' ', $branch );
			return 'Feature Branch: ' . str_replace( '_', ' / ', $branch );
		}

		return self::get_jetpack_plugin_version();
	}

	static function get_new_jetpack_version( $is_dev_version = false ) {
		$manifest = self::get_beta_manifest();
		if ( $is_dev_version ) {
			list( $branch, $section ) = self::get_branch_and_section_dev();
		} else {
			list( $branch, $section ) = self::get_branch_and_section();
		}

		if ( 'master' === $section && isset( $manifest->{$section}->version ) ) {
			return $manifest->{$section}->version;
		}

		if ( 'rc' === $section && isset( $manifest->{$section}->version ) ) {
			return $manifest->{$section}->version;
		}

		if ( isset( $manifest->{$section}->{$branch}->version ) ) {
			return $manifest->{$section}->{$branch}->version;
		}
		return 0;
	}

	static function get_url_dev() {
		list( $branch, $section ) = self::get_branch_and_section_dev();
		return self::get_url( $branch, $section );
	}

	static function get_url( $branch = null, $section = null ) {
		if ( is_null ( $section ) ) {
			list( $branch, $section ) = self::get_branch_and_section();
		}

		if ( 'master' === $section ) {
			return JETPACK_GITHUB_URL . '/tree/master-build';
		}

		if ( 'rc' === $section ) {
			return JETPACK_GITHUB_URL . '/tree/' . $section . '-build';
		}

		if ( 'pr' === $section ) {
			$manifest = self::get_beta_manifest();
			return isset( $manifest->{$section}->{$branch}->pr )
				? JETPACK_GITHUB_URL  . '/pull/' . $manifest->{$section}->{$branch}->pr
				: JETPACK_DEFAULT_URL;
		}
		return JETPACK_DEFAULT_URL;
	}

	static function get_install_url_dev() {
		list( $branch, $section ) = self::get_branch_and_section_dev();
		return self::get_install_url( $branch, $section );
	}

	static function get_install_url( $branch = null, $section = null ) {
		if ( is_null( $section ) ) {
			list( $branch, $section ) = self::get_branch_and_section();
		}

		if ( 'stable' === $section ) {
			$org_data = self::get_org_data();
			return $org_data->download_link;
		}
		$manifest = Jetpack_Beta::get_beta_manifest( true );

		if ( 'master' === $section && isset( $manifest->{$section}->download_url ) ) {
			return $manifest->{$section}->download_url;
		}

		if ( 'rc' === $section ) {
			if ( isset( $manifest->{$section}->download_url ) ) {
				return $manifest->{$section}->download_url;
			}
			$branches = array_keys( (array) $manifest->{$section} );
			foreach ( $branches as $branch ) {
				if ( isset( $manifest->{$section}->{$branch}->download_url ) ) {
					return $manifest->{$section}->{$branch}->download_url;
				}
			}
			return null;
		}

		if ( isset( $manifest->{$section}->{$branch}->download_url ) ) {
			return $manifest->{$section}->{$branch}->download_url;
		}
		return null;
	}

	static function get_jetpack_plugin_info_stable() {
		return self::get_jetpack_plugin_info( JETPACK_PLUGIN_FILE );
	}

	static function get_jetpack_plugin_info_dev() {
		return self::get_jetpack_plugin_info( JETPACK_DEV_PLUGIN_FILE );
	}

	static function get_jetpack_plugin_info( $plugin_file = null ) {

		if ( is_null( $plugin_file ) ) {
			$plugin_file = self::get_plugin_file();
		}

		if( ! function_exists( 'get_plugin_data' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}
		$plugin_file_path = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $plugin_file;
		if ( file_exists( $plugin_file_path ) ) {
			return get_plugin_data( WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $plugin_file );
		}

		return null;
	}

	/*
	 * This needs to happen on shutdown. Other wise it doesn't work.
	 */
	static function switch_active() {
		self::replace_active_plugin( JETPACK_DEV_PLUGIN_FILE, JETPACK_PLUGIN_FILE );
	}

	static function get_beta_manifest( $force_refresh = false ) {
		return self::get_remote_data( JETPACK_BETA_MANIFEST_URL, 'manifest', $force_refresh );
	}

	static function get_org_data() {
		return self::get_remote_data( JETPACK_ORG_API_URL, 'org_data' );
	}

	static function get_remote_data( $url, $transient, $bypass = false) {
		$prefix = 'jetpack_beta_';
		$cache  = get_site_transient( $prefix . $transient );
		if ( $cache && ! $bypass ) {
			return $cache;
		}

		$remote_manifest = wp_remote_get( $url );

		if ( is_wp_error( $remote_manifest ) ) {
			return false;
		}

		$cache = json_decode( wp_remote_retrieve_body( $remote_manifest ) );
		set_site_transient( $prefix . $transient, $cache, MINUTE_IN_SECONDS * 15 );

		return $cache;
	}

	static function delete_all_transiants() {
		$prefix = 'jetpack_beta_';

		delete_site_transient( $prefix. 'org_data' );
		delete_site_transient( $prefix. 'manifest' );

		delete_site_transient( Jetpack_Beta_Autoupdate_Self::TRANSIENT_NAME );

	}

	static function install_and_activate( $branch, $section ) {

		// Clean up previous version of the beta plugin
		if ( file_exists( WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'jetpack-pressable-beta' ) ) {
			// Delete the jetpack dev plugin
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$creds = request_filesystem_credentials( site_url() . '/wp-admin/', '', false, false, array() );
			if ( ! WP_Filesystem( $creds ) ) {
				/* any problems and we exit */
				return new WP_error( 'Filesystem Problem' );
			}
			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				return new WP_error( '$wp_filesystem is not global' );
			}

			$working_dir = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'jetpack-pressable-beta';
			// delete the folder JETPACK_BETA_PLUGIN_FOLDER
			if ( $wp_filesystem->is_dir( $working_dir ) ) {
				$wp_filesystem->delete( $working_dir, true );
			}
			// Deactivate the plugin
			self::replace_active_plugin( 'jetpack-pressable-beta/jetpack.php' );
		}

		if ( 'stable' === $section &&
		     file_exists( WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . JETPACK_PLUGIN_FILE ) ) {
			self::replace_active_plugin( JETPACK_DEV_PLUGIN_FILE, JETPACK_PLUGIN_FILE, true );
			self::update_option( $branch, $section );
			return;
		}

		if ( self::get_branch_and_section_dev() === array( $branch, $section )
		     && file_exists( WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . JETPACK_DEV_PLUGIN_FILE ) ) {
			self::replace_active_plugin( JETPACK_PLUGIN_FILE, JETPACK_DEV_PLUGIN_FILE, true );
			self::update_option( $branch, $section );
			return;
		}

		self::proceed_to_install_and_activate(
			self::get_install_url( $branch, $section ),
			self::get_plugin_slug( $section ),
			$section
		);
		self::update_option( $branch, $section );
		return;
	}

	static function update_plugin( $branch, $section ) {
		self::proceed_to_install(
			self::get_install_url( $branch, $section ),
			self::get_plugin_slug( $section ),
			$section
		);

		if ( $section !== 'stable' ) {
			update_option( self::$option_dev_installed, array( $branch, $section, self::get_manifest_data( $branch, $section ) ) );
		}
		return;
	}

	static function update_option( $branch, $section ) {
		if ( 'stable' !== $section ) {
			update_option( self::$option_dev_installed, array( $branch, $section, self::get_manifest_data( $branch, $section ) ) );
		}
		update_option( self::$option, array( $branch, $section) );
	}

	static function get_manifest_data( $branch, $section ) {
		$installed = get_option( self::$option_dev_installed );
		$current_manifest_data = isset( $installed[2] ) ? $installed[2] : false;

		$manifest_data = self::get_beta_manifest();

		if ( ! isset( $manifest_data->{$section} ) ) {
			return $current_manifest_data;
		}

		if ( 'master' === $section ) {
			return $manifest_data->{$section};
		}

		if ( 'rc' === $section ) {
			return $manifest_data->{$section};
		}

		if ( isset( $manifest_data->{$section}->{$branch} ) ) {
			return $manifest_data->{$section}->{$branch};
		}

		return $current_manifest_data;
	}

	static function proceed_to_install_and_activate( $url, $plugin_folder = JETPACK_DEV_PLUGIN_SLUG, $section ) {
		self::proceed_to_install( $url, $plugin_folder, $section );

		if ( 'stable' === $section ) {
			self::replace_active_plugin( JETPACK_DEV_PLUGIN_FILE, JETPACK_PLUGIN_FILE, true );
		} else {
			self::replace_active_plugin( JETPACK_PLUGIN_FILE, JETPACK_DEV_PLUGIN_FILE, true );
		}
	}

	static function proceed_to_install( $url, $plugin_folder = JETPACK_DEV_PLUGIN_SLUG, $section ) {
		$temp_path = download_url( $url );

		if ( is_wp_error( $temp_path ) ) {
			wp_die( sprintf( __( 'Error Downloading: <a href="%1$s">%1$s</a> - Error: %2$s', 'jetpack-beta' ), $url, $temp_path->get_error_message() ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$creds = request_filesystem_credentials( site_url() . '/wp-admin/', '', false, false, array() );
		/* initialize the API */
		if ( ! WP_Filesystem( $creds ) ) {
			/* any problems and we exit */
			wp_die( __( 'Jetpack Beta: No File System access', 'jetpack-beta' ) );
		}

		global $wp_filesystem;
		if ( 'stable' === $section ) {
			$plugin_path = WP_PLUGIN_DIR;
		} else {
			$plugin_path = str_replace( ABSPATH, $wp_filesystem->abspath(), WP_PLUGIN_DIR  );
		}

		$result = unzip_file( $temp_path, $plugin_path );

		if ( is_wp_error( $result ) ) {
			wp_die( sprintf( __( 'Error Unziping file: Error: %1$s', 'jetpack-beta' ), $result->get_error_message() ) );
		}
	}

	static function is_network_active() {
		if ( ! is_multisite() ) {
			return false;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			return false;
		}

		if ( is_plugin_active_for_network( JETPACK_PLUGIN_FILE ) || is_plugin_active_for_network( JETPACK_DEV_PLUGIN_FILE ) ){
			return true;
		}

		return false;
	}

	static function replace_active_plugin( $current_plugin, $replace_with_plugin = null, $force_activate = false ) {
		if ( self::is_network_active() ) {
			$new_active_plugins = array();
			$network_active_plugins = get_site_option( 'active_sitewide_plugins' );
			foreach ( $network_active_plugins as $plugin => $date ) {
				$key = ( $plugin === $current_plugin ? $replace_with_plugin : $plugin );
				$new_active_plugins[ $key ] = $date;
			}
			update_site_option( 'active_sitewide_plugins', $new_active_plugins );
			return;
		}

		$active_plugins     = (array) get_option( 'active_plugins', array() );
		$new_active_plugins = array();

		if ( empty( $replace_with_plugin ) ) {
			$new_active_plugins = array_diff( $active_plugins, array( $current_plugin ) );
		} else {
			foreach ( $active_plugins as $plugin ) {
				$new_active_plugins[] = ( $plugin === $current_plugin ? $replace_with_plugin : $plugin );
			}
		}

		if ( $force_activate && ! in_array( $replace_with_plugin, $new_active_plugins ) ) {
			$new_active_plugins[] = $replace_with_plugin;
		}
		update_option( 'active_plugins', $new_active_plugins );
	}

	static function should_update_stable_version() {
		// Pressable Jetpack version is manage via Pressable
		if( defined( 'IS_PRESSABLE' ) && IS_PRESSABLE ) {
			return false;
		}
		// Check if we are Jetpack plugin is installed via git
		if ( file_exists ( WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'jetpack/.git' ) ) {
			return false;
		}

		$updates = get_site_transient( 'update_plugins' );

		if ( isset( $updates->response, $updates->response[ JETPACK_PLUGIN_FILE ] ) ) {
			return true;
		}
		$org_data = self::get_org_data();
		$plugin_data = self::get_jetpack_plugin_info_stable();

		return ( isset( $plugin_data['Version'], $org_data->version )
		         && $org_data->version !== $plugin_data['Version'] );
	}

	/**
	 * Here we are checking if the DEV branch that we are currenly on is not something that is available in the manifest
	 * Meaning that the DEV branch was merged into master and so we need to update it.
	 * @return bool
	 */
	static function should_update_dev_to_master() {
		list( $branch, $section ) = self::get_branch_and_section_dev();

		if ( false === $branch || 'master' === $section || 'rc' === $section ) {
			return false;
		}
		$manifest = self::get_beta_manifest();
		return ! isset( $manifest->{$section}->{$branch} );
	}

	static function is_set_to_autoupdate() {
		return get_option( 'jp_beta_autoupdate', false );
	}

	static function is_set_to_email_notifications() {
		return get_option( 'jp_beta_email_notifications', true );
	}

	static function clear_autoupdate_cron() {
		if ( ! is_main_site() ) {
			return;
		}
		wp_clear_scheduled_hook( Jetpack_Beta::$auto_update_cron_hook );

		if ( function_exists( 'wp_unschedule_hook' ) ) { // new in WP 4.9
			wp_unschedule_hook( Jetpack_Beta::$auto_update_cron_hook );
		}
	}

	static function schedule_hourly_autoupdate() {
		wp_clear_scheduled_hook(  Jetpack_Beta::$auto_update_cron_hook );
		wp_schedule_event( time(), 'hourly',  Jetpack_Beta::$auto_update_cron_hook );
	}

	static function maybe_schedule_autoupdate() {
		if ( ! Jetpack_Beta::is_set_to_autoupdate() ) {
			return;
		}

		if ( ! is_main_site() ) {
			return;
		}
		$has_schedule_already = wp_get_schedule( Jetpack_Beta::$auto_update_cron_hook );
		if ( ! $has_schedule_already ) {
			Jetpack_Beta::schedule_hourly_autoupdate();
		}
	}

	static function what_changed() {
		if ( $commit = Jetpack_Beta::get_version_commit() ) {
			$html = '';
			$commit_data = Jetpack_Beta::get_commit_data_from_github( $commit );
			if ( isset( $commit_data->commit->message ) ) {
				$html .= sprintf(
					__( "\n %s \n\n[Commit](%s)", 'jetpack-beta' ),
						esc_html( $commit_data->commit->message ),
						esc_url( $commit_data->html_url )
					);
				"\n\n";
			}
			if ( $commit_data->files ) {
				$html .= "\n\n";
				$html .= sprintf( _n( 'One file changed ', '%d files changed',  count( $commit_data->files ), 'jetpack-beta' ), count( $commit_data->files ) );
				$html .= "\n";
				foreach(  $commit_data->files as $file ) {
					$added_deleted_changed = array();
					if( $file->additions ) {
						$added_deleted_changed[] = '+'. $file->additions;
					}
					if ( $file->deletions ) {
						$added_deleted_changed[] = '-'. $file->deletions;
					}
					$html .= sprintf( "- %s ... (%s %s) \n",  esc_html( $file->filename ), esc_html( $file->status ), implode( ' ', $added_deleted_changed ) );
				}
				$html .= "\n\n";
			}
			if ( ! empty( $html ) ) {
				return $html;
			}
		}
		return false;
	}

	static function get_version_commit() {
		$split_version = explode( '-', Jetpack_Beta::get_jetpack_plugin_version() );
		if ( isset( $split_version[3] )) {
			return $split_version[3];
		}
		return false;
	}

	static function get_commit_data_from_github( $commit ) {
		return self::get_remote_data( JETPACK_GITHUB_API_URL.'commits/'.$commit, 'github_commits_' . $commit );
	}

	static function run_autoupdate() {
		if ( ! Jetpack_Beta::is_set_to_autoupdate() ) {
			return;
		}

		if ( ! is_main_site() ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		wp_clean_plugins_cache();
		ob_start();
		wp_update_plugins(); // Check for Plugin updates
		ob_end_clean();
		$plugins = array();
		if (
			! Jetpack_Beta::is_on_stable() &&
			( Jetpack_Beta::should_update_dev_to_master() || Jetpack_Beta::should_update_dev_version() )
		) {
			add_filter( 'upgrader_source_selection', array( 'Jetpack_Beta', 'check_for_main_file' ), 10, 2 );

			// If response is false, don't alter the transient
			$plugins[] = JETPACK_DEV_PLUGIN_FILE;
		}
		$autupdate = Jetpack_Beta_Autoupdate_Self::instance();
		if ( $autupdate->has_never_version() ) {
			$plugins[] = JPBETA__PLUGIN_FOLDER . '/jetpack-beta.php';
		}

		if ( empty( $plugins ) ) {
			return;
		}

		// unhook this functions that output things before we send our response header.
		remove_action( 'upgrader_process_complete', array( 'Language_Pack_Upgrader', 'async_upgrade' ), 20 );
		remove_action( 'upgrader_process_complete', 'wp_version_check' );
		remove_action( 'upgrader_process_complete', 'wp_update_themes' );

		$skin = new WP_Ajax_Upgrader_Skin();
		// The Automatic_Upgrader_Skin skin shouldn't output anything.
		$upgrader = new Plugin_Upgrader( $skin );
		$upgrader->init();
		// This avoids the plugin to be deactivated.
		// Using bulk upgrade puts the site into maintenance mode during the upgrades
		$result             = $upgrader->bulk_upgrade( $plugins );
		$errors             = $upgrader->skin->get_errors();
		$log = $upgrader->skin->get_upgrade_messages();

		if ( is_wp_error( $errors ) && $errors->get_error_code() ) {
			return $errors;
		}

		if ( $result && ! defined( 'JETPACK_BETA_SKIP_EMAIL' ) && self::is_set_to_email_notifications() ) {
			$admin_email = get_site_option( 'admin_email' );

			if ( empty( $admin_email ) ) {
				return;
			}
			// Calling empty() on a function return value crashes in PHP < 5.5.
			// Thus we assign the return value explicitly and then check with empty().
			$bloginfo_name = get_bloginfo( 'name' );
			$site_title = ! empty( $bloginfo_name ) ? get_bloginfo( 'name' ) : get_site_url();
			$what_updated = 'Jetpack Beta Tester Plugin';
			$subject = sprintf( __( '[%s] Autoupdated Jetpack Beta Tester', 'jetpack-beta' ), $site_title );
			if ( in_array( JETPACK_DEV_PLUGIN_FILE, $plugins ) ) {
				$subject = sprintf(  __( '[%s] Autoupdated Jetpack %s ', 'jetpack-beta' ),
					$site_title,
					Jetpack_Beta::get_jetpack_plugin_pretty_version()
				);

				$what_updated = sprintf( __( 'Jetpack %s (%s)', 'jetpack-beta' ),
					Jetpack_Beta::get_jetpack_plugin_pretty_version(),
					Jetpack_Beta::get_jetpack_plugin_version()
				);

				if ( count( $plugins ) > 1 ) {
					$subject = sprintf( __( '[%s] Autoupdated Jetpack %s and the Jetpack Beta Tester', 'jetpack-beta' ),
						$site_title,
						Jetpack_Beta::get_jetpack_plugin_pretty_version()
					);

					$what_updated = sprintf( __(  'Jetpack %s (%s) and the Jetpack Beta Tester', 'jetpack-beta' ),
						Jetpack_Beta::get_jetpack_plugin_pretty_version(),
						Jetpack_Beta::get_jetpack_plugin_version()
					);
				}
			}

			$message  = sprintf(
				__( 'Howdy! Your site at %1$s has autoupdated %2$s.', 'jetpack-beta' ),
				home_url(),
				$what_updated
			);
			$message .= "\n\n";

			if ( $what_changed = Jetpack_Beta::what_changed() ) {
				$message .= __( 'What changed?', 'jetpack-beta' );
				$message .= strip_tags( $what_changed );
			}

			$message  .= __( 'During the autoupdate the following happened:', 'jetpack-beta' );
			$message .= "\n\n";
			// Can only reference the About screen if their update was successful.
			$log = array_map( 'html_entity_decode', $log );
			$message .= ' - ' . implode( "\n - ", $log );

			$message .= "\n\n";

			wp_mail( $admin_email, $subject, $message );

		}
	}

	/**
	 * This checks intends to fix errors in our build server when jetpack
	 * @param $source
	 * @param $remote_source
	 *
	 * @return WP_Error
	 */
	static function check_for_main_file( $source, $remote_source ) {
		if ( $source === $remote_source . '/jetpack-dev/' ) {
			if ( ! file_exists( $source. 'jetpack.php' ) ) {
				return new WP_Error( 'plugin_file_does_not_exist', __( 'Main Plugin File does not exist', 'jetpack-beta' ) );
			}
			if ( ! file_exists( $source. '_inc/build/static.html' ) ) {
				return new WP_Error( 'static_admin_page_does_not_exist', __( 'Static Admin Page File does not exist', 'jetpack-beta' ) );
			}
			if ( ! file_exists( $source. '_inc/build/admin.js' ) ) {
				return new WP_Error( 'admin_page_does_not_exist', __( 'Admin Page File does not exist', 'jetpack-beta' ) );
			}
		}

		return $source;
	}
}

register_activation_hook( __FILE__, array( 'Jetpack_Beta', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Jetpack_Beta', 'deactivate' ) );

add_action( 'init', array( 'Jetpack_Beta', 'instance' ) );
add_action( 'muplugins_loaded', array( 'Jetpack_Beta', 'is_network_enabled' ) );
