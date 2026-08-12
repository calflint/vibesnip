<?php
/**
 * Conditional logic: decide whether a snippet should run for THIS request.
 *
 * Stored shape (JSON on the snippet):
 *   { "enabled": true, "relation": "AND"|"OR", "rules": [ { type, operator, value }, ... ] }
 *
 * Evaluation must happen where the needed context exists — page/post-type rules
 * only mean something after the main query runs. The executor therefore defers
 * conditional PHP to the `wp` (front-end) / `admin_init` (admin) hooks, and
 * evaluates output snippets at their print hook.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

class VibeSnip_Conditions {

	/**
	 * Decode a stored conditions value to an array (or null).
	 *
	 * @param string|array $value Stored JSON or array.
	 * @return array|null
	 */
	public static function decode( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Does this snippet have active conditions?
	 *
	 * @param string|array $value Stored conditions.
	 * @return bool
	 */
	public static function has( $value ) {
		$c = self::decode( $value );
		return $c && ! empty( $c['enabled'] ) && ! empty( $c['rules'] );
	}

	/**
	 * Evaluate the rules against the current request.
	 *
	 * @param string|array $value Stored conditions.
	 * @return bool True when the snippet should run.
	 */
	public static function passes( $value ) {
		$c = self::decode( $value );
		if ( ! $c || empty( $c['enabled'] ) || empty( $c['rules'] ) ) {
			return true;
		}

		$relation = ( isset( $c['relation'] ) && 'OR' === strtoupper( $c['relation'] ) ) ? 'OR' : 'AND';
		$results  = array();
		foreach ( $c['rules'] as $rule ) {
			$results[] = self::eval_rule( (array) $rule );
		}
		if ( empty( $results ) ) {
			return true;
		}
		return ( 'OR' === $relation ) ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}

	/**
	 * Evaluate a single rule.
	 *
	 * @param array $rule type/operator/value.
	 * @return bool
	 */
	private static function eval_rule( $rule ) {
		$type = isset( $rule['type'] ) ? $rule['type'] : '';
		$op   = isset( $rule['operator'] ) ? $rule['operator'] : 'is';
		$val  = isset( $rule['value'] ) ? $rule['value'] : '';

		if ( 'url_path' === $type ) {
			$uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$path   = (string) wp_parse_url( $uri, PHP_URL_PATH );
			$needle = (string) $val;
			if ( 'equals' === $op ) {
				return untrailingslashit( $path ) === untrailingslashit( $needle );
			}
			if ( 'starts_with' === $op ) {
				return '' !== $needle && 0 === strpos( $path, $needle );
			}
			return '' !== $needle && false !== strpos( $path, $needle ); // contains
		}

		$match = self::matches( $type, $val );
		return ( 'is_not' === $op ) ? ! $match : $match;
	}

	/**
	 * Does the current request match a value for a given rule type?
	 *
	 * @param string $type Rule type.
	 * @param string $val  Expected value.
	 * @return bool
	 */
	private static function matches( $type, $val ) {
		switch ( $type ) {
			case 'user_status':
				return ( 'logged_in' === $val ) ? is_user_logged_in() : ! is_user_logged_in();

			case 'user_role':
				if ( ! is_user_logged_in() ) {
					return false;
				}
				$user = wp_get_current_user();
				return in_array( $val, (array) $user->roles, true );

			case 'device':
				return ( 'mobile' === $val ) ? wp_is_mobile() : ! wp_is_mobile();

			case 'page_type':
				return self::is_page_type( $val );

			case 'post_type':
				$pt = get_post_type( get_queried_object_id() );
				if ( ! $pt && isset( $GLOBALS['post'] ) ) {
					$pt = get_post_type( $GLOBALS['post'] );
				}
				return $pt === $val;
		}
		return false;
	}

	/**
	 * Map a page-type value to its conditional tag.
	 *
	 * @param string $val Page-type value.
	 * @return bool
	 */
	private static function is_page_type( $val ) {
		switch ( $val ) {
			case 'front_page':
				return is_front_page();
			case 'home':
				return is_home();
			case 'single':
				return is_single();
			case 'page':
				return is_page();
			case 'archive':
				return is_archive();
			case 'search':
				return is_search();
			case '404':
				return is_404();
		}
		return false;
	}

	/**
	 * Config for the builder UI (types, operators, value option-sets).
	 * Roles and post types are injected by the caller (they are site-specific).
	 *
	 * @param array $roles      role slug => label.
	 * @param array $post_types post-type slug => label.
	 * @return array
	 */
	public static function ui_config( $roles = array(), $post_types = array() ) {
		return array(
			'types'     => array(
				'user_status' => __( 'User is', 'vibesnip' ),
				'user_role'   => __( 'User role', 'vibesnip' ),
				'page_type'   => __( 'Page type', 'vibesnip' ),
				'post_type'   => __( 'Post type', 'vibesnip' ),
				'url_path'    => __( 'URL path', 'vibesnip' ),
				'device'      => __( 'Device', 'vibesnip' ),
			),
			'operators' => array(
				'default'  => array(
					array( 'is', __( 'is', 'vibesnip' ) ),
					array( 'is_not', __( 'is not', 'vibesnip' ) ),
				),
				'url_path' => array(
					array( 'contains', __( 'contains', 'vibesnip' ) ),
					array( 'equals', __( 'equals', 'vibesnip' ) ),
					array( 'starts_with', __( 'starts with', 'vibesnip' ) ),
				),
			),
			'values'    => array(
				'user_status' => array(
					array( 'logged_in', __( 'Logged in', 'vibesnip' ) ),
					array( 'logged_out', __( 'Logged out', 'vibesnip' ) ),
				),
				'device'      => array(
					array( 'mobile', __( 'Mobile', 'vibesnip' ) ),
					array( 'desktop', __( 'Desktop', 'vibesnip' ) ),
				),
				'page_type'   => array(
					array( 'front_page', __( 'Front page', 'vibesnip' ) ),
					array( 'home', __( 'Blog posts index', 'vibesnip' ) ),
					array( 'single', __( 'Single post', 'vibesnip' ) ),
					array( 'page', __( 'Page', 'vibesnip' ) ),
					array( 'archive', __( 'Archive', 'vibesnip' ) ),
					array( 'search', __( 'Search results', 'vibesnip' ) ),
					array( '404', __( '404 not found', 'vibesnip' ) ),
				),
				'user_role'   => self::pairs( $roles ),
				'post_type'   => self::pairs( $post_types ),
				'url_path'    => null, // free-text input
			),
		);
	}

	/**
	 * Turn a slug => label map into [ [slug,label], ... ] for the UI.
	 *
	 * @param array $map slug => label.
	 * @return array
	 */
	private static function pairs( $map ) {
		$out = array();
		foreach ( (array) $map as $slug => $label ) {
			$out[] = array( (string) $slug, (string) $label );
		}
		return $out;
	}
}
