<?php
/**
 * Uninstall handler.
 *
 * Data is preserved by default. Only when the site owner explicitly opts in
 * (the `vibesnip_delete_data` option) do we drop tables and remove settings.
 *
 * @package VibeSnip
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Capabilities are not user data — they are grants this plugin added to roles, and
// leaving them behind would keep handing `manage_vibesnip` (the right to run PHP) to
// roles after the plugin is gone. So they come off on every uninstall, before the
// data opt-in is consulted.
foreach ( wp_roles()->roles as $vibesnip_role_slug => $vibesnip_role_info ) {
	$vibesnip_role = get_role( $vibesnip_role_slug );
	if ( ! $vibesnip_role ) {
		continue;
	}
	$vibesnip_role->remove_cap( 'manage_vibesnip' );
	$vibesnip_role->remove_cap( 'vibesnip_use_ai' );
}

$vibesnip_prefs = get_option( 'vibesnip_prefs', array() );

// The opt-in is the "Delete all data on uninstall" setting on Settings → General.
// `vibesnip_delete_data` is the pre-0.7.0 key, still honoured.
if ( empty( $vibesnip_prefs['complete_uninstall'] ) && ! get_option( 'vibesnip_delete_data' ) ) {
	return;
}

global $wpdb;

$vibesnip_tables = array(
	$wpdb->prefix . 'vibesnip_runs',
	$wpdb->prefix . 'vibesnip_revisions',
	$wpdb->prefix . 'vibesnip_snippets',
);
foreach ( $vibesnip_tables as $vibesnip_table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is built from $wpdb->prefix, never user input; a schema DROP takes no bindable values and cannot be cached.
	$wpdb->query( "DROP TABLE IF EXISTS {$vibesnip_table}" );
}

delete_option( 'vibesnip_version' );
delete_option( 'vibesnip_db_version' );
delete_option( 'vibesnip_delete_data' );
delete_option( 'vibesnip_prefs' );
delete_option( 'vibesnip_settings' );
