<?php
/**
 * Manage download of plugin updates from GitHub.
 *
 * @package FlashBackup
 */

/*
Copyright 2009-2022 Rocketgenius, Inc.

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License version 3 as published by
the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

defined( 'ABSPATH' ) || die();

/**
 * Update WordPress plugin from GitHub Repository.
 */
class FlashBackup_Updater {

	/**
	 * Defines the GitHub respository name.
	 *
	 * @var $github_repository GitHub respository name.
	 */
	private $github_repository = 'flashbackup';

	/**
	 * Defines the GitHub username
	 *
	 * @var $github_username GitHub username.
	 */
	private $github_username = 'samuelaguilera';

	private $file; // Main plugin file.
	private $basename; // Plugin basename - folder/file.php .
	private $slug; // Plugin slug - folder .

	public function __construct( $file ) {
		// Some variables for later usage.
		$this->file     = $file;
		$this->basename = plugin_basename( $this->file );
		$this->slug     = dirname( plugin_basename( $this->file ) );
		add_filter( 'upgrader_source_selection', array( $this, 'change_source_dir' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'get_update_details' ), 20, 3 );
		add_filter( 'transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'site_transient_update_plugins', array( $this, 'check_update' ) );
	}

	/**
	 * Get plugin data from Github.
	 */
	public function get_github_plugin_data() {
		$github_plugin_data = array();

		// Cached request to prevent GitHub rate limit.
		$github_plugin_data = get_transient( $this->github_repository . '_update_check' );

		$request_uri = esc_url_raw( "https://api.github.com/repos/$this->github_username/$this->github_repository/releases" );

		$args = array(
			'method'    => 'GET',
			'timeout'   => 5,
			'sslverify' => true,
		);

		// Fetch plugin data from GitHub if transient is no longer valid.
		if ( false === $github_plugin_data ) {
			$github_plugin_data = wp_remote_get( $request_uri, $args );
			if ( ! is_wp_error( $github_plugin_data ) ) {
				$github_plugin_data = current( json_decode( wp_remote_retrieve_body( $github_plugin_data ), true ) );
			}
			set_transient( $this->github_repository . '_update_check', $github_plugin_data, HOUR_IN_SECONDS );
		}

		if ( isset( $github_plugin_data['tag_name'] ) ) {
			// Remove v from tag name if any.
			$github_plugin_data['tag_name'] = str_replace( 'v', '', $github_plugin_data['tag_name'] );
		}

		return $github_plugin_data;

	}

	/**
	 * Obtain information to display when View version details link is clicked.
	 *
	 * @param false|object|array $result The result object or array. Default false.
	 * @param string             $action The type of information being requested from the Plugin Installation API.
	 * @param object             $args   Plugin API arguments.
	 */
	public function get_update_details( $result, $action, $args ) {

		// Run only for the right action and our plugin slug.
		if ( 'plugin_information' !== $action || $args->slug !== $this->slug ) {
			return $result;
		}

		$github_plugin_data = $this->get_github_plugin_data();
		$local_plugin_data  = get_plugin_data( $this->file );

		// Only the information to be displayed when clicking the view version details link.
		$args           = new stdClass();
		$args->plugin   = $this->basename;
		$args->name     = $local_plugin_data['Name'];
		$args->version  = $github_plugin_data['tag_name'];
		$args->slug     = $this->slug;
		$args->url      = $local_plugin_data['PluginURI'];
		$args->author   = $local_plugin_data['AuthorName'];
		$args->homepage = esc_url_raw( "https://github.com/$this->github_username/$this->github_repository/" );
		$args->sections = array(
			'Description' => $local_plugin_data['Description'],
			'Updates'     => $github_plugin_data['body'],
		);

		return $args;
	}

	/**
	 * Filters the value of an existing site transient to include our plugin update information.
	 *
	 * @param mixed $transient Value of site transient.
	 */
	public function check_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) { // Return if no plugins update check.
			return $transient;
		}

		$github_plugin_data = $this->get_github_plugin_data();

		// Prevent notices if repository is deleted or can't get the data.
		if ( ! is_array( $github_plugin_data ) || ! isset( $github_plugin_data['tag_name'] ) ) {
			return $transient;
		}

		$local_plugin_data = get_plugin_data( $this->file );

		if ( empty( $transient->response[ $this->basename ] ) ) {
			$transient->response[ $this->basename ] = new stdClass();
		}

		$plugin = array(
			'plugin'      => $this->basename,
			'url'         => $local_plugin_data['PluginURI'],
			'slug'        => $this->slug,
			'package'     => $github_plugin_data['zipball_url'],
			'new_version' => $github_plugin_data['tag_name'],
			'id'          => '0',
		);

		if ( version_compare( $github_plugin_data['tag_name'], $transient->checked[ $this->basename ], '>' ) ) {
			$transient->response[ $this->basename ] = (object) $plugin;
		} else {
			unset( $transient->response[ $this->basename ] );
			$transient->no_update[ $this->basename ] = (object) $plugin;
		}

		return $transient;

	}

	public function change_source_dir( $source, $remote_source, $upgrader ) {
		global $wp_filesystem;

		if ( ! is_object( $wp_filesystem ) || empty( $upgrader->skin->plugin_info ) ) {
			return $source;
		}

		// Do things only for our plugin.
		$local_plugin_data = get_plugin_data( $this->file );
		$plugin_info       = $upgrader->skin->plugin_info;
		if ( $local_plugin_data['PluginURI'] !== $plugin_info['PluginURI'] ) {
			return $source;
		}

		// New path using the expected folder name to prevent issues.
		$new_source = dirname( $remote_source ) . "/$this->slug/";

		// Set the new source path only after successful move.
		if ( true === $wp_filesystem->move( $source, $new_source ) ) {
			$source = $new_source;
			return $source;
		}

		return $source;
	}

}
