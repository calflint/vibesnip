<?php
/**
 * Site-context builder — the safe facts the AI needs so generated code fits
 * THIS site (its theme, whether WooCommerce is present, post types, versions),
 * rather than guessing blind.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

class VibeSnip_Context {

	/**
	 * Gather a compact, non-sensitive snapshot of the site.
	 *
	 * @return array
	 */
	public static function gather() {
		global $wp_version;

		$theme = wp_get_theme();

		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $slug => $obj ) {
			$post_types[] = $slug;
		}

		$known = array(
			'WooCommerce'            => class_exists( 'WooCommerce' ),
			'Easy Digital Downloads' => class_exists( 'Easy_Digital_Downloads' ),
			'Elementor'              => defined( 'ELEMENTOR_VERSION' ),
			'Advanced Custom Fields' => class_exists( 'ACF' ),
			'Yoast SEO'              => defined( 'WPSEO_VERSION' ),
			'Contact Form 7'         => defined( 'WPCF7_VERSION' ),
		);
		$plugins = array();
		foreach ( $known as $name => $present ) {
			if ( $present ) {
				$plugins[] = $name;
			}
		}

		return array(
			'wp_version'  => $wp_version,
			'php_version' => PHP_VERSION,
			'locale'      => get_locale(),
			'multisite'   => is_multisite(),
			'theme'       => $theme ? $theme->get( 'Name' ) : '',
			'post_types'  => $post_types,
			'plugins'     => $plugins,
		);
	}

	/**
	 * A short text block describing the site, for the model prompt.
	 *
	 * @return string
	 */
	public static function summary() {
		$c = self::gather();

		$lines   = array();
		$lines[] = 'Site context:';
		$lines[] = '- WordPress ' . $c['wp_version'] . ', PHP ' . $c['php_version'] . ', locale ' . $c['locale'] . ( $c['multisite'] ? ', multisite' : '' ) . '.';
		$lines[] = '- Active theme: ' . ( $c['theme'] ? $c['theme'] : 'unknown' ) . '.';
		$lines[] = '- Public post types: ' . implode( ', ', $c['post_types'] ) . '.';
		$lines[] = '- Detected plugins: ' . ( $c['plugins'] ? implode( ', ', $c['plugins'] ) : 'none of note' ) . '.';

		return implode( "\n", $lines );
	}
}
