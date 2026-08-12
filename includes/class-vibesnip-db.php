<?php
/**
 * Table names and schema.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

class VibeSnip_DB {

	/**
	 * Snippets table name (with prefix).
	 *
	 * @return string
	 */
	public static function snippets() {
		global $wpdb;
		return $wpdb->prefix . 'vibesnip_snippets';
	}

	/**
	 * Revisions table name (with prefix).
	 *
	 * @return string
	 */
	public static function revisions() {
		global $wpdb;
		return $wpdb->prefix . 'vibesnip_revisions';
	}

	/**
	 * Audit/run log table name (with prefix).
	 *
	 * @return string
	 */
	public static function runs() {
		global $wpdb;
		return $wpdb->prefix . 'vibesnip_runs';
	}

	/**
	 * Create or upgrade all tables via dbDelta().
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$snippets   = self::snippets();
		$revisions  = self::revisions();
		$runs       = self::runs();

		$sql = array();

		$sql[] = "CREATE TABLE {$snippets} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid VARCHAR(36) NOT NULL DEFAULT '',
			title VARCHAR(255) NOT NULL DEFAULT '',
			description TEXT NULL,
			code LONGTEXT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'php',
			location VARCHAR(40) NOT NULL DEFAULT 'everywhere',
			priority INT NOT NULL DEFAULT 10,
			status VARCHAR(20) NOT NULL DEFAULT 'inactive',
			conditions LONGTEXT NULL,
			tags VARCHAR(255) NOT NULL DEFAULT '',
			source VARCHAR(20) NOT NULL DEFAULT 'manual',
			ai_prompt TEXT NULL,
			ai_model VARCHAR(60) NOT NULL DEFAULT '',
			ai_review LONGTEXT NULL,
			ai_review_at DATETIME NULL DEFAULT NULL,
			error_last LONGTEXT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NULL DEFAULT NULL,
			updated_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY type (type),
			KEY location (location),
			KEY uuid (uuid)
		) {$charset};";

		$sql[] = "CREATE TABLE {$revisions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			snippet_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(255) NOT NULL DEFAULT '',
			code LONGTEXT NULL,
			message VARCHAR(255) NOT NULL DEFAULT '',
			author_type VARCHAR(10) NOT NULL DEFAULT 'user',
			author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY snippet_id (snippet_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$runs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			snippet_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(40) NOT NULL DEFAULT '',
			actor_type VARCHAR(10) NOT NULL DEFAULT 'user',
			actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			result VARCHAR(20) NOT NULL DEFAULT 'ok',
			message TEXT NULL,
			context LONGTEXT NULL,
			created_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY snippet_id (snippet_id),
			KEY action (action)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Delete VibeSnip's transients.
	 *
	 * These are the short-lived handoff records (`vibesnip_sbx_*` and
	 * `vibesnip_sbxres_*`) written by the sandbox dry-run that 0.9.0 removed.
	 * Nothing creates them any more, and they expire on their own within a minute —
	 * this is kept so a site upgrading from an older version can clear any that a
	 * loopback left behind, and so the Debug tab's button has something to do.
	 *
	 * @return int Number of transient rows removed.
	 */
	public static function flush_transients() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- table is core's own options table; both LIKE values are plugin-owned literals passed through esc_like() and bound with prepare(). Transient names cannot be enumerated through a core API, and a cached list would defeat the purpose of a cleanup.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_vibesnip_sbx' ) . '%',
				$wpdb->esc_like( '_transient_timeout_vibesnip_sbx' ) . '%'
			)
		);

		$count = 0;
		foreach ( (array) $names as $name ) {
			// Strip the prefix so delete_transient() clears object caches too.
			if ( 0 === strpos( $name, '_transient_timeout_' ) ) {
				continue;
			}
			if ( delete_transient( substr( $name, strlen( '_transient_' ) ) ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Drop all tables (used on uninstall when the user opts in).
	 */
	public static function drop_tables() {
		global $wpdb;
		foreach ( array( self::runs(), self::revisions(), self::snippets() ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is internal (self::* == $wpdb->prefix), never user input; a schema DROP takes no bindable values and cannot be cached.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}
}
