<?php

/**
 * This class defines a promo box and checks your translation site's API for stats about it, then shows them to the
 * user.
 */
class yoast_i18n {

	/**
	 * Your translation site's logo
	 *
	 * @var string
	 */
	private $glotpress_logo;

	/**
	 * Your translation site's name
	 *
	 * @var string
	 */
	private $glotpress_name;

	/**
	 * Your translation site's URL
	 *
	 * @var string
	 */
	private $glotpress_url;

	/**
	 * Hook where you want to show the promo box
	 *
	 * @var string
	 */
	private $hook;

	/**
	 * Will contain the site's locale
	 *
	 * @access private
	 * @var string
	 */
	private $locale;

	/**
	 * Will contain the locale's name, obtained from yoru translation site
	 *
	 * @access private
	 * @var string
	 */
	private $locale_name;

	/**
	 * Will contain the percentage translated for the plugin translation project in the locale
	 *
	 * @access private
	 * @var int
	 */
	private $percent_translated;

	/**
	 * Name of your plugin
	 *
	 * @var string
	 */
	private $plugin_name;

	/**
	 * Project slug for the project on your translation site
	 *
	 * @var string
	 */
	private $project_slug;

	/**
	 * URL to point to for registration links
	 *
	 * @var string
	 */
	private $register_url;

	/**
	 * Your plugins textdomain
	 *
	 * @var string
	 */
	private $textdomain;

	/**
	 * Indicates whether there's a translation available at all.
	 *
	 * @access private
	 * @var bool
	 */
	private $translation_exists;

	/**
	 * Indicates whether the translation's loaded.
	 *
	 * @access private
	 * @var bool
	 */
	private $translation_loaded;

	/**
	 * Class constructor
	 *
	 * @param array $args Contains the settings for the class.
	 */
	public function __construct( $args ) {
		if ( ! is_admin() ) {
			return;
		}

		$this->locale = get_locale();
		if ( 'en_US' === $this->locale ) {
			return;
		}

		$this->init( $args );

	}

	/**
	 * This is where you decide where to display the messages and where you set the plugin specific variables.
	 *
	 * @access private
	 *
	 * @param array $args
	 */
	private function init( $args ) {
		foreach ( $args as $key => $arg ) {
			$this->$key = $arg;
		}
	}


	/**
	 * Try to get translation details from cache, otherwise retrieve them, then parse them.
	 *
	 * @access private
	 */
	private function translation_details() {
		$set = $this->find_or_initialize_translation_details();

		$this->translation_exists = ! is_null( $set );
		$this->translation_loaded = is_textdomain_loaded( $this->textdomain );

		$this->parse_translation_set( $set );
	}

	/**
	 * Try to find the transient for the translation set or retrieve them.
	 *
	 * @access private
	 *
	 * @return object|null
	 */
	private function find_or_initialize_translation_details() {
		$set = get_transient( 'yoast_i18n_' . $this->project_slug . '_' . $this->locale );

		if ( ! $set ) {
			$set = $this->retrieve_translation_details();
			set_transient( 'yoast_i18n_' . $this->project_slug . '_' . $this->locale, $set, DAY_IN_SECONDS );
		}

		return $set;
	}

	/**
	 * Retrieve the translation details from Yoast Translate
	 *
	 * @access private
	 *
	 * @return object|null
	 */
	private function retrieve_translation_details() {
		$api_url = trailingslashit( $this->glotpress_url ) . 'api/projects/' . $this->project_slug;

		$resp = wp_remote_get( $api_url );
		$body = wp_remote_retrieve_body( $resp );
		unset( $resp );

		if ( $body ) {
			$body = json_decode( $body );
			foreach ( $body->translation_sets as $set ) {
				if ( $this->locale == $set->wp_locale ) {
					return $set;
				}
			}
		}

		return null;
	}

	/**
	 * Set the needed private variables based on the results from Yoast Translate
	 *
	 * @param object $set The translation set
	 *
	 * @access private
	 */
	private function parse_translation_set( $set ) {
		if ( $this->translation_exists && is_object( $set ) ) {
			$this->locale_name        = $set->name;
			$this->percent_translated = $set->percent_translated;
		} else {
			$this->locale_name        = '';
			$this->percent_translated = '';
		}
	}

}
