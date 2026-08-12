<?php
/**
 * The ready-made snippet library.
 *
 * A curated set of vetted, safe snippets a site owner can add with one click.
 * Each becomes an INACTIVE snippet (source = library) that opens in the editor
 * for review before it is switched on — nothing runs until the user activates.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

class VibeSnip_Library {

	/**
	 * All library items, keyed by slug.
	 *
	 * @return array
	 */
	public static function all() {
		return array(

			/* ---------------- Admin ---------------- */
			'admin-bar-non-admins' => array(
				'title'       => __( 'Toolbar for admins only', 'vibesnip' ),
				'category'    => __( 'Admin', 'vibesnip' ),
				'type'        => 'php',
				'location'    => 'everywhere',
				'tags'        => 'admin, toolbar',
				'description' => __( 'Hide the top toolbar for everyone except users who can manage the site.', 'vibesnip' ),
				'code'        => "// Show the toolbar only to users who can manage the site.\nadd_action( 'after_setup_theme', function () {\n\tif ( ! current_user_can( 'manage_options' ) ) {\n\t\tshow_admin_bar( false );\n\t}\n} );",
			),
			'dashboard-footer' => array(
				'title'       => __( 'Custom admin footer text', 'vibesnip' ),
				'category'    => __( 'Admin', 'vibesnip' ),
				'type'        => 'php',
				'location'    => 'admin_only',
				'tags'        => 'admin, branding',
				'description' => __( 'Replace the "Thank you for creating with WordPress" footer credit in wp-admin.', 'vibesnip' ),
				'code'        => "// Replace the admin footer credit.\nadd_filter( 'admin_footer_text', function () {\n\treturn 'Managed with care.';\n} );",
			),

			/* ---------------- Security ---------------- */
			'disable-xmlrpc' => array(
				'title'       => __( 'Disable XML-RPC', 'vibesnip' ),
				'category'    => __( 'Security', 'vibesnip' ),
				'type'        => 'php',
				'location'    => 'everywhere',
				'tags'        => 'security, xmlrpc',
				'description' => __( 'Turn off XML-RPC, a common brute-force and DDoS attack surface most sites never use.', 'vibesnip' ),
				'code'        => "// Turn off XML-RPC.\nadd_filter( 'xmlrpc_enabled', '__return_false' );",
			),
			'remove-wp-version' => array(
				'title'       => __( 'Remove the WordPress version number', 'vibesnip' ),
				'category'    => __( 'Security', 'vibesnip' ),
				'type'        => 'php',
				'location'    => 'everywhere',
				'tags'        => 'security',
				'description' => __( 'Stop broadcasting your exact WordPress version in the page head and feeds.', 'vibesnip' ),
				'code'        => "// Stop leaking the WordPress version.\nremove_action( 'wp_head', 'wp_generator' );\nadd_filter( 'the_generator', '__return_empty_string' );",
			),
			'disable-file-editing' => array(
				'title'       => __( 'Disable the built-in file editors', 'vibesnip' ),
				'category'    => __( 'Security', 'vibesnip' ),
				'type'        => 'php',
				'location'    => 'everywhere',
				'tags'        => 'security',
				'description' => __( 'Block the Appearance and Plugin code editors so a compromised admin account cannot edit PHP from the dashboard.', 'vibesnip' ),
				'code'        => "// Block the built-in theme/plugin code editors.\nif ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {\n\tdefine( 'DISALLOW_FILE_EDIT', true );\n}",
			),

			/* ---------------- Content ---------------- */
			'disable-comments' => array(
				'title'       => __( 'Disable comments site-wide', 'vibesnip' ),
				'category'    => __( 'Content', 'vibesnip' ),
				'type'        => 'php',
				'location'    => 'everywhere',
				'tags'        => 'comments',
				'description' => __( 'Close comments and pingbacks everywhere and hide any existing ones.', 'vibesnip' ),
				'code'        => "// Close comments and pingbacks everywhere.\nadd_filter( 'comments_open', '__return_false', 20, 2 );\nadd_filter( 'pings_open', '__return_false', 20, 2 );\n// Hide existing comments.\nadd_filter( 'comments_array', '__return_empty_array', 10, 2 );",
			),
			'excerpt-length' => array(
				'title'       => __( 'Shorten automatic excerpts', 'vibesnip' ),
				'category'    => __( 'Content', 'vibesnip' ),
				'type'        => 'php',
				'location'    => 'everywhere',
				'tags'        => 'excerpt',
				'description' => __( 'Trim auto-generated excerpts to 25 words.', 'vibesnip' ),
				'code'        => "// Trim automatic excerpts to 25 words.\nadd_filter( 'excerpt_length', function () {\n\treturn 25;\n}, 999 );",
			),
			'reading-time' => array(
				'title'       => __( 'Estimated reading time', 'vibesnip' ),
				'category'    => __( 'Content', 'vibesnip' ),
				'type'        => 'php',
				'location'    => 'shortcode',
				'tags'        => 'posts',
				'description' => __( 'Outputs "N min read" for the current post. Place it with the shortcode shown after you save.', 'vibesnip' ),
				'code'        => "// Estimated reading time for the current post.\n\$vibesnip_words   = str_word_count( wp_strip_all_tags( get_post_field( 'post_content', get_the_ID() ) ) );\n\$vibesnip_minutes = max( 1, (int) ceil( \$vibesnip_words / 200 ) );\necho esc_html( \$vibesnip_minutes . ' min read' );",
			),

			/* ---------------- Media ---------------- */
			'allow-svg' => array(
				'title'       => __( 'Allow SVG uploads', 'vibesnip' ),
				'category'    => __( 'Media', 'vibesnip' ),
				'type'        => 'php',
				'location'    => 'everywhere',
				'tags'        => 'media, svg',
				'description' => __( 'Permit SVG image uploads. Only enable this if you trust everyone who can upload — SVG files can contain scripts.', 'vibesnip' ),
				'code'        => "// Allow SVG uploads (trusted users only).\nadd_filter( 'upload_mimes', function ( \$mimes ) {\n\t\$mimes['svg']  = 'image/svg+xml';\n\t\$mimes['svgz'] = 'image/svg+xml';\n\treturn \$mimes;\n} );",
			),

			/* ---------------- Performance ---------------- */
			'remove-query-strings' => array(
				'title'       => __( 'Remove version query strings from assets', 'vibesnip' ),
				'category'    => __( 'Performance', 'vibesnip' ),
				'type'        => 'php',
				'location'    => 'frontend_only',
				'tags'        => 'performance',
				'description' => __( 'Strip ?ver= from script and style URLs so some CDNs and proxies cache them more aggressively.', 'vibesnip' ),
				'code'        => "// Strip ?ver= from script/style URLs.\n\$vibesnip_strip_ver = function ( \$src ) {\n\treturn \$src ? remove_query_arg( 'ver', \$src ) : \$src;\n};\nadd_filter( 'script_loader_src', \$vibesnip_strip_ver, 15 );\nadd_filter( 'style_loader_src', \$vibesnip_strip_ver, 15 );",
			),
			'limit-revisions' => array(
				'title'       => __( 'Limit post revisions', 'vibesnip' ),
				'category'    => __( 'Performance', 'vibesnip' ),
				'type'        => 'php',
				'location'    => 'everywhere',
				'tags'        => 'performance, database',
				'description' => __( 'Keep only the last 5 revisions per post to stop the database bloating over time.', 'vibesnip' ),
				'code'        => "// Keep only the last 5 revisions per post.\nif ( ! defined( 'WP_POST_REVISIONS' ) ) {\n\tdefine( 'WP_POST_REVISIONS', 5 );\n}",
			),

			/* ---------------- Appearance ---------------- */
			'smooth-scroll' => array(
				'title'       => __( 'Smooth-scroll anchor links', 'vibesnip' ),
				'category'    => __( 'Appearance', 'vibesnip' ),
				'type'        => 'css',
				'location'    => 'site_header',
				'tags'        => 'css, ux',
				'description' => __( 'Adds smooth scrolling to same-page anchor links across the whole site.', 'vibesnip' ),
				'code'        => "/* Smooth-scroll anchor links across the whole site. */\nhtml {\n\tscroll-behavior: smooth;\n}",
			),
			'button-lift' => array(
				'title'       => __( 'Subtle button hover lift', 'vibesnip' ),
				'category'    => __( 'Appearance', 'vibesnip' ),
				'type'        => 'css',
				'location'    => 'site_header',
				'tags'        => 'css',
				'description' => __( 'A gentle raise and shadow on buttons when hovered.', 'vibesnip' ),
				'code'        => "/* A subtle lift on buttons. */\n.wp-block-button__link,\nbutton,\n.button {\n\ttransition: transform .15s ease, box-shadow .15s ease;\n}\n.wp-block-button__link:hover,\nbutton:hover,\n.button:hover {\n\ttransform: translateY(-1px);\n\tbox-shadow: 0 6px 18px -8px rgba(0, 0, 0, .4);\n}",
			),
		);
	}

	/**
	 * Fetch a single library item.
	 *
	 * @param string $key Slug.
	 * @return array|null
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Items grouped by category (preserving definition order within a group).
	 *
	 * @return array category => array( key => item )
	 */
	public static function by_category() {
		$grouped = array();
		foreach ( self::all() as $key => $item ) {
			$cat = $item['category'];
			if ( ! isset( $grouped[ $cat ] ) ) {
				$grouped[ $cat ] = array();
			}
			$grouped[ $cat ][ $key ] = $item;
		}
		return $grouped;
	}
}
