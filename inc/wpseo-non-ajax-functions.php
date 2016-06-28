<?php
/**
 * @package WPSEO\Internals
 */

if ( ! defined( 'WPSEO_VERSION' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit();
}


/**
 * Test whether force rewrite should be enabled or not.
 */
function wpseo_title_test() {
	$options = get_option( 'wpseo_titles' );

	$options[ 'forcerewritetitle' ] = false;
	$options[ 'title_test' ]        = 1;
	update_option( 'wpseo_titles', $options );

	// Setting title_test to > 0 forces the plugin to output the title below through a filter in class-frontend.php.
	$expected_title = 'This is a Yoast Test Title';

	WPSEO_Utils::clear_cache();


	$args = [
		'user-agent' => sprintf( 'WordPress/%1$s; %2$s - Yoast', $GLOBALS[ 'wp_version' ], get_site_url() ),
	];
	$resp = wp_remote_get( get_bloginfo( 'url' ), $args );

	if ( ( $resp && ! is_wp_error( $resp ) ) && ( 200 == $resp[ 'response' ][ 'code' ] && isset( $resp[ 'body' ] ) ) ) {
		$res = preg_match( '`<title>([^<]+)</title>`im', $resp[ 'body' ], $matches );

		if ( $res && strcmp( $matches[ 1 ], $expected_title ) !== 0 ) {
			$options[ 'forcerewritetitle' ] = true;

			$resp = wp_remote_get( get_bloginfo( 'url' ), $args );
			$res  = false;
			if ( ( $resp && ! is_wp_error( $resp ) ) && ( 200 == $resp[ 'response' ][ 'code' ] && isset( $resp[ 'body' ] ) ) ) {
				$res = preg_match( '`/<title>([^>]+)</title>`im', $resp[ 'body' ], $matches );
			}
		}

		if ( ! $res || $matches[ 1 ] != $expected_title ) {
			$options[ 'forcerewritetitle' ] = false;
		}
	} else {
		// If that dies, let's make sure the titles are correct and force the output.
		$options[ 'forcerewritetitle' ] = true;
	}

	$options[ 'title_test' ] = 0;
	update_option( 'wpseo_titles', $options );
}

// Commented out? add_filter( 'switch_theme', 'wpseo_title_test', 0 ); R.
/**
 * Test whether the active theme contains a <meta> description tag.
 *
 * @since 1.4.14 Moved from dashboard.php and adjusted - see changelog
 *
 * @return void
 */
function wpseo_description_test() {
	$options = get_option( 'wpseo' );

	// Reset any related options - dirty way of getting the default to make sure it works on activation.
	$options[ 'theme_has_description' ]   = WPSEO_Option_Wpseo::$desc_defaults[ 'theme_has_description' ];
	$options[ 'theme_description_found' ] = WPSEO_Option_Wpseo::$desc_defaults[ 'theme_description_found' ];

	/**
	 * @internal Should this be reset too ? Best to do so as test is done on re-activate and switch_theme
	 * as well and new warning would be warranted then. Only might give irritation on theme upgrade.
	 */
	$options[ 'ignore_meta_description_warning' ] = WPSEO_Option_Wpseo::$desc_defaults[ 'ignore_meta_description_warning' ];

	$file = false;
	if ( file_exists( get_stylesheet_directory() . '/header.php' ) ) {
		// Theme or child theme.
		$file = get_stylesheet_directory() . '/header.php';
	} elseif ( file_exists( get_template_directory() . '/header.php' ) ) {
		// Parent theme in case of a child theme.
		$file = get_template_directory() . '/header.php';
	}

	if ( is_string( $file ) && $file !== '' ) {
		$header_file = file_get_contents( $file );
		$issue       = preg_match_all( '#<\s*meta\s*(name|content)\s*=\s*("|\')(.*)("|\')\s*(name|content)\s*=\s*("|\')(.*)("|\')(\s+)?/?>#i', $header_file, $matches, PREG_SET_ORDER );
		if ( $issue === false || $issue === 0 ) {
			$options[ 'theme_has_description' ] = false;
		} else {
			foreach ( $matches as $meta ) {
				if ( ( strtolower( $meta[ 1 ] ) == 'name' && strtolower( $meta[ 3 ] ) == 'description' ) || ( strtolower( $meta[ 5 ] ) == 'name' && strtolower( $meta[ 7 ] ) == 'description' ) ) {
					$options[ 'theme_description_found' ]         = $meta[ 0 ];
					$options[ 'ignore_meta_description_warning' ] = false;
					break; // No need to run through the rest of the meta's.
				}
			}
			if ( $options[ 'theme_description_found' ] !== '' ) {
				$options[ 'theme_has_description' ] = true;
			} else {
				$options[ 'theme_has_description' ] = false;
			}
		}
	}
	update_option( 'wpseo', $options );
}

add_filter( 'after_switch_theme', 'wpseo_description_test', 0 );

if ( version_compare( $GLOBALS[ 'wp_version' ], '3.6.99', '>' ) ) {
	// Use the new and *sigh* adjusted action hook WP 3.7+.
	add_action( 'upgrader_process_complete', 'wpseo_upgrader_process_complete', 10, 2 );
} elseif ( version_compare( $GLOBALS[ 'wp_version' ], '3.5.99', '>' ) ) {
	// Use the new action hook WP 3.6+.
	add_action( 'upgrader_process_complete', 'wpseo_upgrader_process_complete', 10, 3 );
} else {
	// Abuse filters to do our action.
	add_filter( 'update_theme_complete_actions', 'wpseo_update_theme_complete_actions', 10, 2 );
	add_filter( 'update_bulk_theme_complete_actions', 'wpseo_update_theme_complete_actions', 10, 2 );
}


/**
 * Check if the current theme was updated and if so, test the updated theme
 * for the title and meta description tag
 *
 * @since    1.4.14
 *
 * @param   object $upgrader_object
 * @param   array  $context_array
 * @param   mixed  $themes
 *
 * @return  void
 */
function wpseo_upgrader_process_complete( $upgrader_object, $context_array, $themes = null ) {
	$options = get_option( 'wpseo' );

	// Break if admin_notice already in place.
	if ( ( ( isset( $options[ 'theme_has_description' ] ) && $options[ 'theme_has_description' ] === true ) || $options[ 'theme_description_found' ] !== '' ) && $options[ 'ignore_meta_description_warning' ] !== true ) {
		return;
	}
	// Break if this is not a theme update, not interested in installs as after_switch_theme would still be called.
	if ( ! isset( $context_array[ 'type' ] ) || $context_array[ 'type' ] !== 'theme' || ! isset( $context_array[ 'action' ] ) || $context_array[ 'action' ] !== 'update' ) {
		return;
	}

	$theme = get_stylesheet();
	if ( ! isset( $themes ) ) {
		// WP 3.7+.
		$themes = [ ];
		if ( isset( $context_array[ 'themes' ] ) && $context_array[ 'themes' ] !== [ ] ) {
			$themes = $context_array[ 'themes' ];
		} elseif ( isset( $context_array[ 'theme' ] ) && $context_array[ 'theme' ] !== '' ) {
			$themes = $context_array[ 'theme' ];
		}
	}

	if ( ( isset( $context_array[ 'bulk' ] ) && $context_array[ 'bulk' ] === true ) && ( is_array( $themes ) && count( $themes ) > 0 ) ) {

		if ( in_array( $theme, $themes ) ) {
			// Commented out? wpseo_title_test(); R.
			wpseo_description_test();
		}
	} elseif ( is_string( $themes ) && $themes === $theme ) {
		// Commented out? wpseo_title_test(); R.
		wpseo_description_test();
	}

	return;
}

/**
 * Abuse a filter to check if the current theme was updated and if so, test the updated theme
 * for the title and meta description tag
 *
 * @since 1.4.14
 *
 * @param   array $update_actions
 * @param   mixed $updated_theme
 *
 * @return  array  $update_actions    Unchanged array
 */
function wpseo_update_theme_complete_actions( $update_actions, $updated_theme ) {
	$options = get_option( 'wpseo' );

	// Break if admin_notice already in place.
	if ( ( ( isset( $options[ 'theme_has_description' ] ) && $options[ 'theme_has_description' ] === true ) || $options[ 'theme_description_found' ] !== '' ) && $options[ 'ignore_meta_description_warning' ] !== true ) {
		return $update_actions;
	}

	$theme = get_stylesheet();
	if ( is_object( $updated_theme ) ) {
		/*
		Bulk update and $updated_theme only contains info on which theme was last in the list
		   of updated themes, so go & test
		*/

		// Commented out? wpseo_title_test(); R.
		wpseo_description_test();
	} elseif ( $updated_theme === $theme ) {
		/*
		Single theme update for the active theme
		*/

		// Commented out? wpseo_title_test(); R.
		wpseo_description_test();
	}

	return $update_actions;
}

/**
 * Enqueue a tiny bit of CSS to show so the adminbar shows right.
 */
function wpseo_admin_bar_css() {
	if ( is_admin_bar_showing() && is_singular() ) {
		wp_enqueue_style( 'boxes', plugins_url( 'css/adminbar' . WPSEO_CSSJS_SUFFIX . '.css', WPSEO_FILE ), [ ], WPSEO_VERSION );
	}
}

add_action( 'wp_enqueue_scripts', 'wpseo_admin_bar_css' );

/**
 * Allows editing of the meta fields through weblog editors like Marsedit.
 *
 * @param array $allcaps Capabilities that must all be true to allow action.
 * @param array $cap     Array of capabilities to be checked, unused here.
 * @param array $args    List of arguments for the specific cap to be checked.
 *
 * @return array $allcaps
 */
function allow_custom_field_edits( $allcaps, $cap, $args ) {
	// $args[0] holds the capability.
	// $args[2] holds the post ID.
	// $args[3] holds the custom field.
	// Make sure the request is to edit or add a post meta (this is usually also the second value in $cap,
	// but this is safer to check).
	if ( in_array( $args[ 0 ], [ 'edit_post_meta', 'add_post_meta' ] ) ) {
		// Only allow editing rights for users who have the rights to edit this post and make sure
		// the meta value starts with _yoast_wpseo (WPSEO_Meta::$meta_prefix).
		if ( ( isset( $args[ 2 ] ) && current_user_can( 'edit_post', $args[ 2 ] ) ) && ( ( isset( $args[ 3 ] ) && $args[ 3 ] !== '' ) && strpos( $args[ 3 ], WPSEO_Meta::$meta_prefix ) === 0 ) ) {
			$allcaps[ $args[ 0 ] ] = true;
		}
	}

	return $allcaps;
}

add_filter( 'user_has_cap', 'allow_custom_field_edits', 0, 3 );

/**
 * Display an import message when robots-meta is active
 *
 * @since 1.5.0
 */
function wpseo_robots_meta_message() {
	// Check if robots meta is running.
	if ( ( ! isset( $_GET[ 'page' ] ) || 'wpseo_import' !== $_GET[ 'page' ] ) && is_plugin_active( 'robots-meta/robots-meta.php' ) ) {
		add_action( 'admin_notices', 'wpseo_import_robots_meta_notice' );
	}
}

add_action( 'admin_init', 'wpseo_robots_meta_message' );

/**
 * Handle deactivation Robots Meta
 *
 * @since 1.5.0
 */
function wpseo_disable_robots_meta() {
	if ( isset( $_GET[ 'deactivate_robots_meta' ] ) && $_GET[ 'deactivate_robots_meta' ] === '1' && is_plugin_active( 'robots-meta/robots-meta.php' ) ) {
		// Deactivate the plugin.
		deactivate_plugins( 'robots-meta/robots-meta.php' );

		// Show notice that robots meta has been deactivated.
		add_action( 'admin_notices', 'wpseo_deactivate_robots_meta_notice' );

		// Clean up the referrer url for later use.
		if ( isset( $_SERVER[ 'REQUEST_URI' ] ) ) {
			$_SERVER[ 'REQUEST_URI' ] = remove_query_arg( [ 'deactivate_robots_meta' ], sanitize_text_field( $_SERVER[ 'REQUEST_URI' ] ) );
		}
	}
}

add_action( 'admin_init', 'wpseo_disable_robots_meta' );

/**
 * Handle deactivation & import of AIOSEO data
 *
 * @since 1.5.0
 */
function wpseo_aioseo_message() {
	// Check if aioseo is running.
	if ( ( ! isset( $_GET[ 'page' ] ) || 'wpseo_import' != $_GET[ 'page' ] ) && is_plugin_active( 'all-in-one-seo-pack/all_in_one_seo_pack.php' ) ) {
		add_action( 'admin_notices', 'wpseo_import_aioseo_setting_notice' );
	}
}

add_action( 'admin_init', 'wpseo_aioseo_message' );

/**
 * Handle deactivation AIOSEO
 *
 * @since 1.5.0
 */
function wpseo_disable_aioseo() {
	if ( isset( $_GET[ 'deactivate_aioseo' ] ) && $_GET[ 'deactivate_aioseo' ] === '1' && is_plugin_active( 'all-in-one-seo-pack/all_in_one_seo_pack.php' ) ) {
		// Deactivate AIO.
		deactivate_plugins( 'all-in-one-seo-pack/all_in_one_seo_pack.php' );

		// Show notice that aioseo has been deactivated.
		add_action( 'admin_notices', 'wpseo_deactivate_aioseo_notice' );

		// Clean up the referrer url for later use.
		if ( isset( $_SERVER[ 'REQUEST_URI' ] ) ) {
			$_SERVER[ 'REQUEST_URI' ] = remove_query_arg( [ 'deactivate_aioseo' ], sanitize_text_field( $_SERVER[ 'REQUEST_URI' ] ) );
		}
	}
}

add_action( 'admin_init', 'wpseo_disable_aioseo' );

/**
 * Throw a notice to import AIOSEO.
 *
 * @since 1.4.8
 */
function wpseo_import_aioseo_setting_notice() {
	$url = add_query_arg( [ '_wpnonce' => wp_create_nonce( 'wpseo-import' ) ], admin_url( 'admin.php?page=wpseo_tools&tool=import-export&import=1&importaioseo=1#top#import-seo' ) );
	echo '<div class="error"><p>', sprintf( esc_html__( 'The plugin All-In-One-SEO has been detected. Do you want to %simport its settings%s?', 'wordpress-seo' ), sprintf( '<a href="%s">', esc_url( $url ) ), '</a>' ), '</p></div>';
}

/**
 * Throw a notice to inform the user AIOSEO has been deactivated
 *
 * @since 1.4.8
 */
function wpseo_deactivate_aioseo_notice() {
	echo '<div class="updated"><p>', esc_html__( 'All-In-One-SEO has been deactivated', 'wordpress-seo' ), '</p></div>';
}

/**
 * Throw a notice to import Robots Meta.
 *
 * @since 1.4.8
 */
function wpseo_import_robots_meta_notice() {
	$url = add_query_arg( [ '_wpnonce' => wp_create_nonce( 'wpseo-import' ) ], admin_url( 'admin.php?page=wpseo_tools&tool=import-export&import=1&importrobotsmeta=1#top#import-other' ) );
	echo '<div class="error"><p>', sprintf( esc_html__( 'The plugin Robots-Meta has been detected. Do you want to %simport its settings%s.', 'wordpress-seo' ), sprintf( '<a href="%s">', esc_url( $url ) ), '</a>' ), '</p></div>';
}

/**
 * Throw a notice to inform the user Robots Meta has been deactivated
 *
 * @since 1.4.8
 */
function wpseo_deactivate_robots_meta_notice() {
	echo '<div class="updated"><p>', esc_html__( 'Robots-Meta has been deactivated', 'wordpress-seo' ), '</p></div>';
}

/********************** DEPRECATED FUNCTIONS **********************/

/**
 * Set the default settings.
 *
 * @deprecated 1.5.0
 * @deprecated use WPSEO_Options::initialize()
 * @see        WPSEO_Options::initialize()
 */
function wpseo_defaults() {
	_deprecated_function( __FUNCTION__, 'WPSEO 1.5.0', 'WPSEO_Options::initialize()' );
	WPSEO_Options::initialize();
}

/**
 * Translates a decimal analysis score into a textual one.
 *
 * @deprecated 1.5.6.1
 * @deprecated use WPSEO_Utils::translate_score()
 * @see        WPSEO_Utils::translate_score()
 *
 * @param int  $val       The decimal score to translate.
 * @param bool $css_value Whether to return the i18n translated score or the CSS class value.
 *
 * @return string
 */
function wpseo_translate_score( $val, $css_value = true ) {
	_deprecated_function( __FUNCTION__, 'WPSEO 1.5.6.1', 'WPSEO_Utils::translate_score()' );

	return WPSEO_Utils::translate_score();
}


/**
 * Check whether file editing is allowed for the .htaccess and robots.txt files
 *
 * @deprecated 1.5.6.1
 * @deprecated use WPSEO_Utils::allow_system_file_edit()
 * @see        WPSEO_Utils::allow_system_file_edit()
 *
 * @internal   current_user_can() checks internally whether a user is on wp-ms and adjusts accordingly.
 *
 * @return bool
 */
function wpseo_allow_system_file_edit() {
	_deprecated_function( __FUNCTION__, 'WPSEO 1.5.6.1', 'WPSEO_Utils::allow_system_file_edit()' );

	return WPSEO_Utils::allow_system_file_edit();
}
