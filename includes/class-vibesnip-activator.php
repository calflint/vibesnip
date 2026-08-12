<?php
/**
 * Activation / deactivation routines.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

class VibeSnip_Activator {

	/**
	 * Run on plugin activation: build tables, grant caps, seed an example.
	 */
	public static function activate() {
		VibeSnip_DB::create_tables();
		self::add_capabilities();

		if ( ! get_option( 'vibesnip_version' ) ) {
			self::seed_example();
		}

		update_option( 'vibesnip_version', VIBESNIP_VERSION );
		update_option( 'vibesnip_db_version', VIBESNIP_VERSION );
	}

	/**
	 * Bring the schema up to date after a plugin update.
	 *
	 * WordPress does not re-run activation hooks when a plugin is updated, so a
	 * site that updates in place would never get columns added in a later
	 * release. This runs on every admin load, but only touches the database when
	 * the stored version differs — a single option read otherwise.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'vibesnip_db_version' ) === VIBESNIP_VERSION ) {
			return;
		}
		VibeSnip_DB::create_tables();
		self::add_capabilities();
		self::migrate_ai_settings();
		self::encrypt_stored_keys();
		// Model lists are a cache of what each vendor offered; a new release is a
		// good moment to ask again rather than show a week-old answer.
		VibeSnip_AI::forget_models();
		update_option( 'vibesnip_version', VIBESNIP_VERSION );
		update_option( 'vibesnip_db_version', VIBESNIP_VERSION );
	}

	/**
	 * Encrypt API keys that were saved before VibeSnip encrypted them.
	 *
	 * A key already in the database is already in every backup taken since, so
	 * this cannot un-leak anything — it stops the next backup carrying it. Runs
	 * once: `VibeSnip_Keys::encrypt()` skips values it has already written.
	 */
	private static function encrypt_stored_keys() {
		if ( ! VibeSnip_Keys::available() ) {
			return;
		}
		$settings = get_option( VibeSnip_AI::OPTION, array() );
		if ( ! is_array( $settings ) || empty( $settings['api_keys'] ) || ! is_array( $settings['api_keys'] ) ) {
			return;
		}

		$changed = false;
		foreach ( $settings['api_keys'] as $provider => $key ) {
			if ( '' === (string) $key || 0 === strpos( (string) $key, VibeSnip_Keys::PREFIX ) ) {
				continue;
			}
			$settings['api_keys'][ $provider ] = VibeSnip_Keys::encrypt( (string) $key );
			$changed                           = true;
		}

		if ( $changed ) {
			update_option( VibeSnip_AI::OPTION, $settings );
		}
	}

	/**
	 * Move a single-provider API key onto the per-provider map.
	 *
	 * Up to 0.8.0 there was one `api_key` and one `model`, because there was one
	 * provider. Multi-provider stores them per provider, so an in-place update has
	 * to carry the existing key across or the owner silently loses AI and has to
	 * find and re-paste a key they already gave us.
	 *
	 * Idempotent: the flat keys are removed once moved, so a second run is a no-op.
	 */
	private static function migrate_ai_settings() {
		$settings = get_option( VibeSnip_AI::OPTION, array() );
		if ( ! is_array( $settings ) ) {
			return;
		}

		// AI became opt-in in 0.11.0, defaulting to off. A site already using it
		// has already made that decision — taking the feature away mid-update, and
		// silently, would be its own kind of broken. Anyone with a key keeps it on;
		// only genuinely new installs start off.
		if ( ! isset( $settings['ai_enabled'] ) ) {
			$has_key = ! empty( $settings['api_key'] )
				|| ( ! empty( $settings['api_keys'] ) && is_array( $settings['api_keys'] ) && array_filter( $settings['api_keys'] ) );

			$settings['ai_enabled']    = (bool) $has_key;
			$settings['ai_consent_at'] = $has_key ? current_time( 'mysql' ) : '';
			update_option( VibeSnip_AI::OPTION, $settings );
		}

		if ( ! isset( $settings['api_key'] ) ) {
			return;
		}

		$provider = ! empty( $settings['provider'] ) ? (string) $settings['provider'] : 'anthropic';

		if ( ! isset( $settings['api_keys'] ) || ! is_array( $settings['api_keys'] ) ) {
			$settings['api_keys'] = array();
		}
		if ( ! isset( $settings['models'] ) || ! is_array( $settings['models'] ) ) {
			$settings['models'] = array();
		}

		// Never overwrite a key already stored under the new shape.
		if ( '' !== (string) $settings['api_key'] && empty( $settings['api_keys'][ $provider ] ) ) {
			$settings['api_keys'][ $provider ] = (string) $settings['api_key'];
		}
		if ( ! empty( $settings['model'] ) && empty( $settings['models'][ $provider ] ) ) {
			$settings['models'][ $provider ] = (string) $settings['model'];
		}

		unset( $settings['api_key'], $settings['model'] );

		update_option( VibeSnip_AI::OPTION, $settings );
	}

	/**
	 * Run on deactivation. We deliberately keep data; snippets stop running
	 * simply because the plugin is no longer loaded.
	 */
	public static function deactivate() {
		// Reserved for scheduled-event cleanup in later phases.
	}

	/**
	 * Every capability VibeSnip manages, and what each one gates.
	 *
	 * These are real WordPress capabilities granted on roles, not an option blob
	 * checked ad hoc — so `current_user_can()` works everywhere and other plugins
	 * can see them.
	 *
	 * @return array cap => label
	 */
	public static function capabilities() {
		return array(
			'manage_vibesnip' => __( 'Manage snippets', 'vibesnip' ),
			'vibesnip_use_ai' => __( 'Use AI features', 'vibesnip' ),
		);
	}

	/**
	 * Grant every VibeSnip capability to administrators.
	 */
	public static function add_capabilities() {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}
		foreach ( array_keys( self::capabilities() ) as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Seed a single, safe, INACTIVE example so the manager isn't empty.
	 */
	public static function seed_example() {
		$repo = new VibeSnip_Snippets();
		$repo->create(
			array(
				'title'       => __( 'Example: hide the admin bar on the front-end', 'vibesnip' ),
				'description' => __( 'A harmless starter snippet. Activate it to hide the top admin bar while viewing the site. Edit or delete it any time.', 'vibesnip' ),
				'type'        => 'php',
				'location'    => 'frontend_only',
				'priority'    => 10,
				'status'      => 'inactive',
				'tags'        => 'example',
				'source'      => 'library',
				'code'        => "add_filter( 'show_admin_bar', '__return_false' );",
			)
		);
	}
}
