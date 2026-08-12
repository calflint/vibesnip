<?php
/**
 * Import snippets from other plugins' JSON exports (WPCode, Code Snippets).
 *
 * Everything is imported INACTIVE so nothing runs until the user reviews and
 * activates it. Unknown scopes fall back to safe defaults rather than failing.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

class VibeSnip_Importer {

	/**
	 * Import from a JSON string.
	 *
	 * @param string $json Raw file contents.
	 * @return int|WP_Error Number imported, or an error.
	 */
	public static function import_json( $json ) {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'vibesnip_invalid', __( 'That file is not valid JSON.', 'vibesnip' ) );
		}

		$snippets = self::extract( $data );
		if ( empty( $snippets ) ) {
			return new WP_Error( 'vibesnip_empty', __( 'No snippets were found in that file.', 'vibesnip' ) );
		}

		$repo  = new VibeSnip_Snippets();
		$count = 0;
		foreach ( $snippets as $raw ) {
			$mapped = self::map( (array) $raw );
			if ( $mapped ) {
				$repo->create( $mapped );
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Find the array of snippet records inside an export.
	 *
	 * @param array $data Decoded file.
	 * @return array
	 */
	private static function extract( $data ) {
		if ( isset( $data['snippets'] ) && is_array( $data['snippets'] ) ) {
			return $data['snippets'];
		}
		// A bare list of snippet objects.
		if ( isset( $data[0] ) && is_array( $data[0] ) ) {
			return $data;
		}
		return array();
	}

	/**
	 * Map one exported record to VibeSnip fields.
	 *
	 * @param array $s Raw record.
	 * @return array|null
	 */
	private static function map( $s ) {
		$code = isset( $s['code'] ) ? (string) $s['code'] : '';
		if ( '' === trim( $code ) ) {
			return null;
		}

		if ( isset( $s['code_type'] ) || isset( $s['location'] ) ) {
			$type = self::wpcode_type( isset( $s['code_type'] ) ? $s['code_type'] : 'php' );
			$loc  = self::wpcode_location( isset( $s['location'] ) ? $s['location'] : '', $type );
		} elseif ( isset( $s['scope'] ) ) {
			list( $type, $loc ) = self::codesnippets_scope( $s['scope'] );
		} else {
			$type = 'php';
			$loc  = 'everywhere';
		}

		return array(
			'title'       => self::title( $s ),
			'description' => self::desc( $s ),
			'code'        => $code,
			'type'        => $type,
			'location'    => self::reconcile( $type, $loc ),
			'tags'        => self::tags( $s ),
			'status'      => 'inactive',
			'source'      => 'import',
		);
	}

	/* ---------------- WPCode ---------------- */

	private static function wpcode_type( $code_type ) {
		$map = array(
			'php'       => 'php',
			'js'        => 'js',
			'css'       => 'css',
			'html'      => 'html',
			'text'      => 'text',
			'universal' => 'html',
		);
		$code_type = strtolower( (string) $code_type );
		return isset( $map[ $code_type ] ) ? $map[ $code_type ] : 'php';
	}

	private static function wpcode_location( $location, $type ) {
		$map = array(
			'everywhere'       => 'everywhere',
			'admin_only'       => 'admin_only',
			'frontend_only'    => 'frontend_only',
			'frontend'         => 'frontend_only',
			'site_wide_header' => 'site_header',
			'site_wide_body'   => 'site_body',
			'site_wide_footer' => 'site_footer',
			'header'           => 'site_header',
			'footer'           => 'site_footer',
			'shortcode'        => 'shortcode',
		);
		$location = (string) $location;
		return isset( $map[ $location ] ) ? $map[ $location ] : self::default_location( $type );
	}

	/* ---------------- Code Snippets ---------------- */

	private static function codesnippets_scope( $scope ) {
		$map = array(
			'global'         => array( 'php', 'everywhere' ),
			'admin'          => array( 'php', 'admin_only' ),
			'front-end'      => array( 'php', 'frontend_only' ),
			'single-use'     => array( 'php', 'everywhere' ),
			'content'        => array( 'php', 'shortcode' ),
			'head-content'   => array( 'html', 'site_header' ),
			'footer-content' => array( 'html', 'site_footer' ),
			'admin-css'      => array( 'css', 'site_header' ),
			'site-css'       => array( 'css', 'site_header' ),
			'site-head-js'   => array( 'js', 'site_header' ),
			'site-footer-js' => array( 'js', 'site_footer' ),
		);
		$scope = (string) $scope;
		return isset( $map[ $scope ] ) ? $map[ $scope ] : array( 'php', 'everywhere' );
	}

	/* ---------------- shared helpers ---------------- */

	private static function default_location( $type ) {
		return ( 'php' === $type ) ? 'everywhere' : 'site_header';
	}

	/**
	 * Guarantee the location is valid for the type.
	 */
	private static function reconcile( $type, $loc ) {
		$valid = VibeSnip_Snippet::locations_for_type( $type );
		return isset( $valid[ $loc ] ) ? $loc : self::default_location( $type );
	}

	private static function title( $s ) {
		foreach ( array( 'title', 'name' ) as $k ) {
			if ( ! empty( $s[ $k ] ) ) {
				return sanitize_text_field( (string) $s[ $k ] );
			}
		}
		return __( 'Imported snippet', 'vibesnip' );
	}

	private static function desc( $s ) {
		foreach ( array( 'description', 'desc', 'note' ) as $k ) {
			if ( ! empty( $s[ $k ] ) ) {
				return sanitize_textarea_field( (string) $s[ $k ] );
			}
		}
		return '';
	}

	private static function tags( $s ) {
		if ( empty( $s['tags'] ) ) {
			return '';
		}
		$tags = $s['tags'];
		if ( is_array( $tags ) ) {
			$tags = implode( ', ', array_map( 'strval', $tags ) );
		}
		return sanitize_text_field( (string) $tags );
	}
}
