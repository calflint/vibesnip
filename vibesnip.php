<?php
/**
 * Plugin Name:       VibeSnip
 * Plugin URI:        https://github.com/calflint/VibeSnip
 * Description:        AI-native code snippets for WordPress. Add PHP, CSS, JS, HTML and text snippets safely — with syntax validation, auto-deactivate on fatal error, revisions, an audit log, and Safe Mode recovery. You stay in control: every change is admin-approved and reversible.
 * Version:           0.12.0
 * Requires at least: 6.9
 * Requires PHP:      7.0
 * Author:            Caleb Arthur-Flints
 * Author URI:        https://github.com/calflint
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vibesnip
 *
 * @package VibeSnip
 */

// No direct access.
defined( 'ABSPATH' ) || exit;

define( 'VIBESNIP_VERSION', '0.12.0' );
define( 'VIBESNIP_FILE', __FILE__ );
define( 'VIBESNIP_DIR', plugin_dir_path( __FILE__ ) );
define( 'VIBESNIP_URL', plugin_dir_url( __FILE__ ) );
define( 'VIBESNIP_BASENAME', plugin_basename( __FILE__ ) );

// Safe Mode may also be forced from wp-config.php:  define( 'VIBESNIP_SAFE_MODE', true );

require_once VIBESNIP_DIR . 'includes/execution.php';
require_once VIBESNIP_DIR . 'includes/class-vibesnip-db.php';
require_once VIBESNIP_DIR . 'includes/class-vibesnip-activator.php';
require_once VIBESNIP_DIR . 'includes/class-vibesnip-snippet.php';
require_once VIBESNIP_DIR . 'includes/class-vibesnip-snippets.php';
require_once VIBESNIP_DIR . 'includes/class-vibesnip-library.php';
require_once VIBESNIP_DIR . 'includes/class-vibesnip-conditions.php';
require_once VIBESNIP_DIR . 'includes/class-vibesnip-importer.php';
require_once VIBESNIP_DIR . 'includes/class-vibesnip-context.php';
require_once VIBESNIP_DIR . 'includes/class-vibesnip-keys.php';
require_once VIBESNIP_DIR . 'includes/class-vibesnip-provider.php';
require_once VIBESNIP_DIR . 'includes/class-vibesnip-ai.php';
require_once VIBESNIP_DIR . 'includes/class-vibesnip-donate.php';
require_once VIBESNIP_DIR . 'includes/class-vibesnip-abilities.php';
require_once VIBESNIP_DIR . 'includes/class-vibesnip-guard.php';
require_once VIBESNIP_DIR . 'includes/class-vibesnip-executor.php';

register_activation_hook( __FILE__, array( 'VibeSnip_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'VibeSnip_Activator', 'deactivate' ) );

/**
 * Main plugin orchestrator.
 */
final class VibeSnip {

	/** @var VibeSnip|null */
	private static $instance = null;

	/** @var VibeSnip_Executor */
	public $executor;

	/** @var VibeSnip_Admin|null */
	public $admin = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// The guard arms its shutdown handler as early as possible so a fatal
		// inside a snippet is always caught and the culprit auto-deactivated.
		VibeSnip_Guard::init();

		// The Abilities API adapter exposes the safe loop (read/create/test/
		// activate/rollback) as standard WordPress abilities for any AI agent.
		// No-op on installs without the Abilities API — the hooks never fire.
		VibeSnip_Abilities::init();

		// Execution runs on both front-end and admin unless Safe Mode is on.
		$this->executor = new VibeSnip_Executor();
		add_action( 'plugins_loaded', array( $this->executor, 'boot' ), 20 );

		if ( is_admin() ) {
			// WordPress does not re-run activation hooks on update, so the schema
			// is reconciled here instead. No-ops unless the version changed.
			add_action( 'admin_init', array( 'VibeSnip_Activator', 'maybe_upgrade' ) );

			// VibeSnip ships from GitHub Releases, not wordpress.org, so it has
			// to supply its own update channel or "Check for updates" is a dead
			// end forever.
			require_once VIBESNIP_DIR . 'includes/class-vibesnip-updater.php';
			VibeSnip_Updater::init();

			require_once VIBESNIP_DIR . 'admin/class-vibesnip-brand.php';
			require_once VIBESNIP_DIR . 'admin/class-vibesnip-admin.php';
			$this->admin = new VibeSnip_Admin();
			$this->admin->hooks();
		}

		// Translations for wordpress.org-hosted plugins are loaded automatically
		// by WordPress since 4.6 — no load_plugin_textdomain() call needed.
	}
}

/**
 * Global accessor.
 *
 * @return VibeSnip
 */
function vibesnip() {
	return VibeSnip::instance();
}

vibesnip();
