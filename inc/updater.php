<?php

if ( ! class_exists( 'WP_GitHub_Updater' ) ) {

	class WP_GitHub_Updater {

		private $api_url = 'https://api.github.com';

		function __construct( $settings ) {

			// Never load outside of the admin... would be a waste of cycles
			if ( ! is_admin() ) {
				return;
			}

			// Check for minimum config
			if ( ! isset( $settings[ 'owner' ] ) || ! isset( $settings[ 'repo' ] ) || ! isset( $settings[ 'basename' ] ) ) {
				return;
			}

			$this->owner    = $settings[ 'owner' ];
			$this->repo     = $settings[ 'repo' ];
			$this->basename = $settings[ 'basename' ];

			// Optional config
			$this->branch  = ! empty( $settings[ 'branch' ] ) ? $settings[ 'branch' ] : 'master';
			$this->token   = ! empty( $settings[ 'access_token' ] ) ? $settings[ 'access_token' ] : false;
			$this->timeout = ! empty( $settings[ 'timeout' ] ) ? (int) $settings[ 'timeout' ] : 12000;

			// Determine plugin folder and main file name from basename
			$dir             = explode( '/', $settings[ 'basename' ] );
			$this->folder    = $dir[ 0 ];
			$this->main_file = $dir[ 1 ];

			// Not current output anywhere, but might be useful for debugging
			$this->errors = [ ];

			// Check for GH plugin updates when WP checks for plugin updates
			add_filter( 'pre_set_site_transient_update_plugins', [ &$this, 'check_for_update' ] );

			// Get latest plugin information when the updater is run
			add_filter( 'plugins_api', [ &$this, 'pre_run_update' ], 10, 3 );

			// GitHub's archives have ugly names that break the WP plugin installer. This function changes the downloaded archive path to align with the old plugin's path
			add_filter( 'upgrader_source_selection', [ &$this, 'set_install_source_name' ], 10, 3 );

		}


		/**
		 * 检查插件升级
		 *
		 * @param $transient
		 *
		 * @return mixed
		 */
		public function check_for_update( $transient ) {

			// $transient->checked the array of plugins to check for updates. If our plugin isn't in the array, stop.
			if ( empty( $transient->checked[ $this->basename ] ) ) {
				return $transient;
			}

			// Get the latest plugin meta from the GH repo
			$repo_meta = $this->get_repo_meta();
			if ( ! $repo_meta ) {
				return $transient;
			}

			// Compare the current plugin version to the repo's version. See the PHP documentation on version_compare() for acceptable version strings
			if ( version_compare( $repo_meta[ 'Version' ], $transient->checked[ $this->basename ], '>' ) ) {
				$transient->response[ $this->basename ] = $this->set_update_object( $repo_meta );
			}

			return $transient;

		}


		/**
		 * 准备升级
		 *
		 * @param $obj
		 * @param $action
		 * @param $arg
		 *
		 * @return \stdClass
		 */
		public function pre_run_update( $obj, $action, $arg ) {

			// Make sure plugin_information is being requested
			if ( 'plugin_information' !== $action ) {
				return $obj;
			}

			// Make sure this plugin's info is being requested
			if ( $this->repo !== $arg->slug ) {
				return $obj;
			}

			// Get the latest plugin meta from the GH repo...
			$repo_meta = $this->get_repo_meta();

			// ...and use that meta to append the plugin info object
			return $this->set_update_object( $repo_meta );

		}


		/**
		 * 构造 Github API URL
		 *
		 * @param       $endpoint
		 * @param array $params
		 *
		 * @return bool
		 */
		private function build_api_url( $endpoint, $params = [ ] ) {

			// If you've set an access_token, append it to the query
			if ( $this->token ) {
				$params[ 'access_token' ] = $this->token;
			}

			// Build the GitHub API URL
			$url = [
				untrailingslashit( $this->api_url ),
				'repos',
				$this->owner,
				$this->repo,
				$endpoint,
			];
			$url = implode( '/', $url );

			// Add the query, if it exists
			$query = http_build_query( $params, '' );
			if ( ! empty( $query ) ) {
				$url .= '?' . $query;
			}

			return $url;

		}


		/**
		 * 从 Github 获取数据, Github 接口封装
		 *
		 * @param       $endpoint
		 *
		 * @return bool
		 */
		private function get_from_github( $endpoint ) {

			// If you've set an access_token, append it to the query
			if ( $this->token ) {
				$params[ 'access_token' ] = $this->token;
			}

			$url = $this->build_api_url( $endpoint );

			$args = [
				'method'  => 'GET',
				'timeout' => 300,
			];

			// Initialize the request
			$response = wp_remote_get( $url, $args );

			return ! empty( $response ) ? $response : false;

		}


		/**
		 * 从仓库中获取插件元数据
		 *
		 * @return bool
		 */
		private function get_repo_meta() {

			$params = [
				'ref' => $this->branch,
			];

			$response = $this->get_from_github( 'contents/' . $this->main_file, $params );

			// If data is returned
			if ( $response ) {

				// GH API v3 returns the file contents as base64. We need to decode it.
				$base_file_content = base64_decode( json_decode( wp_remote_retrieve_body( $response ) )->content );

				// If decoding went well
				if ( ! empty( $base_file_content ) ) {

					// We want to use WP's get_plugin_data() function for parsing metadata from the returned plugin file. This function requires a physical file, so we create a temporary one.
					// http://sg3.php.net/tempnam
					$temp_base_file = tempnam( sys_get_temp_dir(), "WPGH" );
					$handle         = fopen( $temp_base_file, "w" );
					fwrite( $handle, $base_file_content );
					fclose( $handle );

					// Get the repo plugin file's metadata
					$repo_plugin_meta = get_plugin_data( $temp_base_file, false, false );
					unlink( $temp_base_file );

					// If successful, return the meta
					if ( ! empty( $repo_plugin_meta ) ) {
						return $repo_plugin_meta;
					} else {
						$this->errors[] = 'Repo plugin version is unreadable.';
					}
				} else {
					$this->errors[] = 'Repo plugin base file is unreadable.';
				}
			} else {
				$this->errors[] = 'GitHub connection timed out.';
			}

			return false;

		}


		/**
		 * 从仓库中获取插件 zip 存档
		 *
		 * @return bool
		 */
		private function get_repo_zip() {

			$url = $this->build_api_url( 'zipball/' . $this->branch );

			return $url;

		}


		/**
		 * 设置插件更新对象
		 *
		 * @param      $meta
		 * @param bool $include_zip_url
		 *
		 * @return \stdClass
		 */
		private function set_update_object( $meta, $include_zip_url = true ) {

			$obj              = new stdClass();
			$obj->url         = $meta[ 'PluginURI' ];
			$obj->slug        = $this->repo;
			$obj->new_version = $meta[ 'Version' ];

			if ( $include_zip_url ) {
				$obj->package = $this->get_repo_zip();
			}

			return $obj;

		}


		/**
		 * 设置安装源名称
		 *
		 * @param $source
		 * @param $remote_source
		 * @param $plugin
		 *
		 * @return mixed
		 */
		public function set_install_source_name( $source, $remote_source, $plugin ) {

			if ( strpos( $source, $this->owner . '-' . $this->repo . '-' ) !== false ) {
				$plugins_basenames = array_keys( get_plugins() );
				$plugins_names     = [ ];

				foreach ( $plugins_basenames as $basename ) {
					$path      = explode( '/', $basename );
					$path[ 1 ] = str_replace( '.php', '', $path[ 1 ] );
					if ( $this->repo !== $path[ 1 ] ) {
						$plugins_names[] = $path[ 1 ];
					}
				}

				foreach ( $plugins_names as $name ) {
					if ( strpos( $source, $this->owner . '-' . $name . '-' ) !== false ) {
						return $source;
					}
				}

				// Break the unzipped archive's path apart
				$target = untrailingslashit( $source );
				$path   = explode( '/', $target );

				// Change the last part of the path to the plugin folder's name
				$length              = count( $path );
				$path[ $length - 1 ] = $this->folder;

				// Assemble the new path
				$target = trailingslashit( implode( '/', $path ) );

				// Move the unzipped archive from the old path to the new one
				rename( $source, $target );

				// Return the new path
				return $target;
			} else {
				// Return the original value, if not our plugin
				return $source;
			}

		}

	}

}