<?php
/**
 * Self-hosted update checker.
 *
 * VibeSnip is distributed from GitHub Releases rather than wordpress.org, so
 * wp-admin has no update channel unless we supply one. This wires the same
 * two filters wordpress.org itself uses (`pre_set_site_transient_update_plugins`,
 * `plugins_api`) to the latest release of calflint/VibeSnip, giving admins the
 * normal one-click "update now" instead of a manual re-download.
 *
 * @package VibeSnip
 */

// No direct access.
defined( 'ABSPATH' ) || exit;

class VibeSnip_Updater {

	const REPO       = 'calflint/VibeSnip';
	const CACHE_KEY  = 'vibesnip_update_check';
	const CACHE_TTL  = 12 * HOUR_IN_SECONDS;
	const RETRY_TTL  = HOUR_IN_SECONDS;

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'purge_cache' ), 10, 2 );
	}

	/**
	 * Fetches the latest GitHub release, caching the response so an update
	 * check never costs more than one API call per CACHE_TTL window.
	 *
	 * @return array Decoded release payload, or empty array on failure.
	 */
	private static function fetch_release() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Back off for a short window so a down/rate-limited API doesn't
			// turn into a request on every admin page load.
			set_transient( self::CACHE_KEY, array(), self::RETRY_TTL );
			return array();
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		$release = is_array( $release ) ? $release : array();

		set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
		return $release;
	}

	/**
	 * @param array $release Decoded GitHub release payload.
	 * @return string The release's .zip asset URL, or '' if none was attached.
	 */
	private static function zip_url( $release ) {
		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			if ( isset( $asset['browser_download_url'] ) && str_ends_with( $asset['browser_download_url'], '.zip' ) ) {
				return $asset['browser_download_url'];
			}
		}
		return '';
	}

	public static function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = self::fetch_release();
		$version = ltrim( (string) ( $release['tag_name'] ?? '' ), 'v' );
		$package = self::zip_url( $release );

		if ( '' === $version || '' === $package || ! version_compare( $version, VIBESNIP_VERSION, '>' ) ) {
			return $transient;
		}

		$transient->response[ VIBESNIP_BASENAME ] = (object) array(
			'slug'        => 'vibesnip',
			'plugin'      => VIBESNIP_BASENAME,
			'new_version' => $version,
			'url'         => 'https://github.com/' . self::REPO,
			'package'     => $package,
		);

		return $transient;
	}

	/**
	 * Supplies the "View version x.x details" popup wp-admin shows before an
	 * update. Falls back silently (returns $result unchanged) if the release
	 * can't be fetched — the update itself still works without this.
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'vibesnip' !== $args->slug ) {
			return $result;
		}

		$release = self::fetch_release();
		if ( empty( $release ) ) {
			return $result;
		}

		return (object) array(
			'name'          => 'VibeSnip',
			'slug'          => 'vibesnip',
			'version'       => ltrim( (string) ( $release['tag_name'] ?? '' ), 'v' ),
			'author'        => '<a href="https://github.com/calflint">Caleb Arthur-Flints</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => self::zip_url( $release ),
			'sections'      => array(
				'changelog' => wpautop( wp_kses_post( (string) ( $release['body'] ?? '' ) ) ),
			),
		);
	}

	/**
	 * Drops the cached release the moment a VibeSnip update finishes, so a
	 * second manual "check again" doesn't wait out the full cache window.
	 */
	public static function purge_cache( $upgrader, $data ) {
		if ( isset( $data['action'], $data['type'], $data['plugins'] )
			&& 'update' === $data['action']
			&& 'plugin' === $data['type']
			&& in_array( VIBESNIP_BASENAME, (array) $data['plugins'], true )
		) {
			delete_transient( self::CACHE_KEY );
		}
	}
}
