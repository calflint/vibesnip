<?php
/**
 * Snippet value object + type/location metadata.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

class VibeSnip_Snippet {

	public $id          = 0;
	public $uuid        = '';
	public $title       = '';
	public $description = '';
	public $code        = '';
	public $type        = 'php';
	public $location    = 'everywhere';
	public $priority    = 10;
	public $status      = 'inactive';
	public $conditions  = '';
	public $tags        = '';
	public $source      = 'manual';
	public $ai_prompt   = '';
	public $ai_model    = '';
	public $ai_review   = '';
	public $ai_review_at = '';
	public $error_last  = '';
	public $created_by  = 0;
	public $created_at  = '';
	public $updated_at  = '';

	/**
	 * @param array|object $row Row from the database.
	 */
	public function __construct( $row = array() ) {
		foreach ( (array) $row as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->$key = $value;
			}
		}
		$this->id       = (int) $this->id;
		$this->priority = (int) $this->priority;
	}

	public function is_active() {
		return 'active' === $this->status;
	}

	public function is_php() {
		return 'php' === $this->type;
	}

	/**
	 * The last recorded error, decoded.
	 *
	 * @return array|null
	 */
	public function error() {
		if ( empty( $this->error_last ) ) {
			return null;
		}
		$decoded = json_decode( $this->error_last, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * The stored AI review report, decoded. Advisory only — it never reflects
	 * whether the snippet passed validation.
	 *
	 * @return array|null
	 */
	public function review() {
		if ( empty( $this->ai_review ) ) {
			return null;
		}
		$decoded = json_decode( $this->ai_review, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * The supported snippet types.
	 *
	 * @return array slug => label
	 */
	public static function types() {
		return array(
			'php'  => __( 'PHP', 'vibesnip' ),
			'css'  => __( 'CSS', 'vibesnip' ),
			'js'   => __( 'JavaScript', 'vibesnip' ),
			'html' => __( 'HTML', 'vibesnip' ),
			'text' => __( 'Text', 'vibesnip' ),
		);
	}

	/**
	 * Valid locations grouped by the type they apply to.
	 *
	 * @return array location => array( label, applies_to[] )
	 */
	public static function locations() {
		return array(
			'everywhere'    => array( __( 'Run everywhere', 'vibesnip' ), array( 'php' ) ),
			'admin_only'    => array( __( 'Admin area only', 'vibesnip' ), array( 'php' ) ),
			'frontend_only' => array( __( 'Front-end only', 'vibesnip' ), array( 'php' ) ),
			'site_header'   => array( __( 'Site-wide header (<head>)', 'vibesnip' ), array( 'css', 'js', 'html', 'text' ) ),
			'site_body'     => array( __( 'Site-wide body (after <body>)', 'vibesnip' ), array( 'js', 'html', 'text' ) ),
			'site_footer'   => array( __( 'Site-wide footer', 'vibesnip' ), array( 'css', 'js', 'html', 'text' ) ),
			'shortcode'     => array( __( 'Shortcode only', 'vibesnip' ), array( 'php', 'css', 'js', 'html', 'text' ) ),
		);
	}

	/**
	 * Human label for a location slug.
	 *
	 * @param string $location Slug.
	 * @return string
	 */
	public static function location_label( $location ) {
		$all = self::locations();
		return isset( $all[ $location ] ) ? $all[ $location ][0] : $location;
	}

	/**
	 * Locations valid for a given type.
	 *
	 * @param string $type Snippet type.
	 * @return array location => label
	 */
	public static function locations_for_type( $type ) {
		$out = array();
		foreach ( self::locations() as $slug => $meta ) {
			if ( in_array( $type, $meta[1], true ) ) {
				$out[ $slug ] = $meta[0];
			}
		}
		return $out;
	}
}
