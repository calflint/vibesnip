<?php
/**
 * Brand assets.
 *
 * Two SVGs, deliberately not one. `vibesnip-icon.svg` drops the pulse line and
 * fattens the glyph because WordPress renders a menu icon at 20px, where the
 * fine detail of the full mark collapses into noise. `vibesnip-mark.svg` keeps
 * the pulse and is only used where there is room for it to read.
 *
 * Both are plain SVG files rather than inline data URIs: the review sniff reads
 * base64 as obfuscation regardless of intent.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

/**
 * Paths to, and markup for, the plugin's logo.
 */
class VibeSnip_Brand {

	/**
	 * Simplified mark, for anything rendered below roughly 64px.
	 *
	 * @return string
	 */
	public static function icon_url() {
		return VIBESNIP_URL . 'admin/images/vibesnip-icon.svg';
	}

	/**
	 * Full mark including the pulse line. Needs size to be legible.
	 *
	 * @return string
	 */
	public static function mark_url() {
		return VIBESNIP_URL . 'admin/images/vibesnip-mark.svg';
	}

	/**
	 * Echo the logo for use inside a page heading.
	 *
	 * Decorative: the heading text beside it already names the page, so an alt
	 * would just be read out twice.
	 *
	 * @return void
	 */
	public static function heading_logo() {
		printf(
			'<img class="vibesnip-logo" src="%s" alt="" width="28" height="28" />',
			esc_url( self::icon_url() )
		);
	}
}
