<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allow the Jetpack Beta to autoupdate itself.
 */
class Jetpack_Beta_Autoupdate_Self {
	protected static $_instance = null;

	const TRANSIENT_NAME = 'JETPACK_BETA_LATEST_TAG';

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
		if ( ! empty( self::$_instance ) ) {
			return;
		}

		$this->config = array(
			'plugin_file'        => JPBETA__PLUGIN_FOLDER . '/jetpack-beta.php',
			'slug'               => JPBETA__PLUGIN_FOLDER,
			'proper_folder_name' => JPBETA__PLUGIN_FOLDER,
			'api_url'            => 'https://api.github.com/repos/Automattic/jetpack-beta',
			'github_url'         => 'https://github.com/Automattic/jetpack-beta',
			'requires'           => '4.7',
			'tested'             => '4.7'
		);



		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'api_check' ) );
		add_filter( 'plugins_api', array( $this, 'get_plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'upgrader_source_selection' ), 10, 3 );

	}

	public function set_update_args() {
		$plugin_data                    = $this->get_plugin_data();
		$this->config[ 'plugin_name' ]  = $plugin_data['Name'];
		$this->config[ 'version' ]      = $plugin_data['Version'];
		$this->config[ 'author' ]       = $plugin_data['Author'];
		$this->config[ 'homepage' ]     = $plugin_data['PluginURI'];
		$this->config[ 'new_version' ]  = $this->get_latest_prerelease();
		$this->config[ 'last_updated' ] = $this->get_date();
		$this->config[ 'description' ]  = $this->get_description();
		$this->config[ 'zip_url' ]      = 'https://github.com/Automattic/jetpack-beta/zipball/' . $this->config[ 'new_version' ];
	}

	public function get_latest_prerelease() {
		$tagged_version = get_site_transient( self::TRANSIENT_NAME );
		if ( $this->overrule_transients() || empty( $tagged_version ) ) {
			$raw_response = wp_remote_get( trailingslashit( $this->config['api_url'] ) . 'releases' );
			if ( is_wp_error( $raw_response ) ) {
				return false;
			}
			$releases       = json_decode( $raw_response['body'] );
			$tagged_version = false;
			if ( is_array( $releases ) ) {
				foreach ( $releases as $release ) {
					// Since 2.2, So that we don't have to maker the Jetpack Beta 2.0.3 as prerelease
					if ( ! $release->prerelease ) {
						$tagged_version = $release->tag_name;
						break;
					}
				}
			}
			// refresh every 6 hours
			if ( ! empty( $tagged_version ) ) {
				set_site_transient( self::TRANSIENT_NAME, $tagged_version, 60*60*6 );
			}
		}
		return $tagged_version;
	}

	public function overrule_transients() {
		return ( defined( 'Jetpack_Beta_FORCE_UPDATE' ) && Jetpack_Beta_FORCE_UPDATE );
	}

	public function get_github_data() {
		if ( ! empty( $this->github_data ) ) {
			$github_data = $this->github_data;
		} else {
			$github_data = get_site_transient( md5( $this->config['slug'] ) . '_github_data' );
			if ( $this->overrule_transients() || ( ! isset( $github_data ) || ! $github_data || '' == $github_data ) ) {
				$github_data = wp_remote_get( $this->config['api_url'] );
				if ( is_wp_error( $github_data ) ) {
					return false;
				}
				$github_data = json_decode( $github_data['body'] );
				// refresh every 6 hours
				set_site_transient( md5( $this->config['slug'] ) . '_github_data', $github_data, 60*60*6 );
			}
			// Store the data in this class instance for future calls
			$this->github_data = $github_data;
		}
		return $github_data;
	}

	public function get_date() {
		$_date = $this->get_github_data();
		return ! empty( $_date->updated_at ) ? date( 'Y-m-d', strtotime( $_date->updated_at ) ) : false;
	}

	public function get_description() {
		$_description = $this->get_github_data();
		return ! empty( $_description->description ) ? $_description->description : false;
	}


	public function get_plugin_data() {
		return get_plugin_data( WP_PLUGIN_DIR . '/' . $this->config['plugin_file'] );
	}

	public function has_never_version() {
		if ( ! isset( $this->config['new_version'] ) ) {
			$this->set_update_args();
		}
		return version_compare( $this->config['new_version'], $this->config['version'], '>' );

	}

	public function api_check( $transient ) {
		// Check if the transient contains the 'checked' information
		// If not, just return its value without hacking it
		if ( ! isset( $transient->no_update ) ) {
			return $transient;
		}
		// get the latest version
		delete_site_transient( self::TRANSIENT_NAME );

		if ( $this->has_never_version() ) {
			$response              = new stdClass;
			$response->plugin      = $this->config['slug'];
			$response->new_version = $this->config['new_version'];
			$response->slug        = $this->config['slug'];
			$response->url         = $this->config['github_url'];
			$response->package     = $this->config['zip_url'];
			// If response is false, don't alter the transient
			if ( false !== $response ) {
				$transient->response[ $this->config['plugin_file'] ] = $response;
			}
		}
		return $transient;
	}

	public function get_plugin_info( $false, $action, $response ) {
		// Check if this call API is for the right plugin
		if ( ! isset( $response->slug ) || $response->slug != $this->config['slug'] ) {
			return false;
		}
		// Update tags
		$this->set_update_args();
		$response->slug          = $this->config['slug'];
		$response->plugin        = $this->config['slug'];
		$response->name          = $this->config['plugin_name'];
		$response->plugin_name   = $this->config['plugin_name'];
		$response->version       = $this->config['new_version'];
		$response->author        = $this->config['author'];
		$response->homepage      = $this->config['homepage'];
		$response->requires      = $this->config['requires'];
		$response->tested        = $this->config['tested'];
		$response->downloaded    = 0;
		$response->last_updated  = $this->config['last_updated'];
		$response->sections      = array( 'description' => $this->config['description'] );
		$response->download_link = $this->config['zip_url'];
		return $response;
	}

	public function upgrader_source_selection( $source, $remote_source, $upgrader ) {
		global $wp_filesystem;
		if ( strstr( $source, '/Automattic-jetpack-beta-' ) ) {
			$corrected_source = trailingslashit( $remote_source ) . trailingslashit( $this->config[ 'proper_folder_name' ] );
			if ( $wp_filesystem->move( $source, $corrected_source, true ) ) {
				return $corrected_source;
			} else {
				return new WP_Error();
			}
		}
		return $source;
	}
}
