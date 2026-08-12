<?php
/**
 * The safety layer.
 *
 * Responsibilities:
 *  - Decide whether Safe Mode is engaged.
 *  - Track which snippet is currently executing.
 *  - Catch a fatal thrown by a snippet (via a shutdown handler) and a catchable
 *    Throwable (via the executor), then auto-deactivate the culprit and record
 *    the error so the site recovers on the very next request.
 *  - Validate PHP syntax before a snippet can be saved active.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

class VibeSnip_Guard {

	/**
	 * ID of the snippet currently mid-execution, or null.
	 *
	 * @var int|null
	 */
	private static $running_id = null;

	/**
	 * Guards against re-entrancy while we deactivate a failed snippet.
	 *
	 * @var bool
	 */
	private static $failing = false;

	/**
	 * Arm the shutdown handler. Called as early as possible.
	 */
	public static function init() {
		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
	}

	/**
	 * Is snippet execution disabled for this request?
	 *
	 * The genuine, secure recovery path: add to wp-config.php
	 *   define( 'VIBESNIP_SAFE_MODE', true );
	 * which requires filesystem access, so it can never be triggered by a visitor.
	 *
	 * @return bool
	 */
	public static function is_safe_mode() {
		return defined( 'VIBESNIP_SAFE_MODE' ) && VIBESNIP_SAFE_MODE;
	}

	/**
	 * May the current user author, edit, test or activate a snippet of this type?
	 *
	 * `manage_vibesnip` is the right to run arbitrary PHP as the web server, so it
	 * is treated like `edit_plugins` rather than like a content capability. On
	 * multisite that power belongs to Super Admins alone — the same reason core
	 * disables the plugin/theme file editors and withholds `unfiltered_html` from
	 * per-site administrators on a network. Without this, any site admin on any
	 * site of a network could execute PHP against the whole install.
	 *
	 * CSS/JS/HTML/text carry no server-side execution and stay at `manage_vibesnip`.
	 *
	 * This gates AUTHORING, not execution: PHP snippets that are already active keep
	 * running, exactly as an installed plugin keeps running when file editing is off.
	 *
	 * @param string $type Snippet type.
	 * @return bool
	 */
	public static function can_author( $type ) {
		if ( ! current_user_can( 'manage_vibesnip' ) ) {
			return false;
		}
		if ( 'php' !== $type ) {
			return true;
		}
		return ! is_multisite() || is_super_admin();
	}

	/**
	 * Why can_author() said no, in words a site owner can act on.
	 *
	 * @return string
	 */
	public static function author_denied_message() {
		return __( 'Only a Super Admin can create, edit, test or activate PHP snippets on a multisite network, because PHP snippets run as site code. CSS, JavaScript, HTML and text snippets are unaffected.', 'vibesnip' );
	}

	/**
	 * Mark a snippet as executing.
	 *
	 * @param int $id Snippet ID.
	 */
	public static function mark_running( $id ) {
		self::$running_id = (int) $id;
	}

	/**
	 * Clear the executing marker.
	 */
	public static function clear_running() {
		self::$running_id = null;
	}

	/**
	 * Fatal-error types worth acting on.
	 *
	 * @param int $type PHP error type.
	 * @return bool
	 */
	private static function is_fatal_type( $type ) {
		return in_array( $type, array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true );
	}

	/**
	 * Shutdown handler — catches the uncatchable.
	 */
	public static function on_shutdown() {
		if ( null === self::$running_id ) {
			return;
		}
		$error = error_get_last();
		if ( ! $error || ! self::is_fatal_type( $error['type'] ) ) {
			return;
		}
		self::fail_snippet(
			self::$running_id,
			array(
				'message' => $error['message'],
				'line'    => isset( $error['line'] ) ? (int) $error['line'] : 0,
				'type'    => 'fatal',
				'when'    => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Handle a catchable error/exception thrown by a snippet.
	 *
	 * @param int        $id Snippet ID.
	 * @param \Throwable $e  The throwable.
	 */
	public static function handle_throwable( $id, $e ) {
		self::fail_snippet(
			$id,
			array(
				'message' => $e->getMessage(),
				'line'    => $e->getLine(),
				'type'    => 'exception',
				'when'    => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Deactivate a failed snippet and record why. Wrapped defensively because
	 * this can run during shutdown, when anything might already be broken.
	 *
	 * @param int   $id    Snippet ID.
	 * @param array $error Error detail.
	 */
	public static function fail_snippet( $id, $error ) {
		if ( self::$failing || ! $id ) {
			return;
		}
		self::$failing = true;
		self::clear_running();

		try {
			$repo = new VibeSnip_Snippets();
			$repo->set_status( $id, 'error', 'auto_deactivate' );
			$repo->record_error( $id, $error );
			$repo->log(
				$id,
				'auto_deactivate',
				'error',
				/* translators: %1$s: error message, %2$d: line number. */
				sprintf( __( 'Auto-deactivated after a fatal error: %1$s (line %2$d)', 'vibesnip' ), $error['message'], $error['line'] )
			);
		} catch ( \Throwable $ignored ) {
			// Nothing more we can safely do here.
			unset( $ignored );
		}

		self::$failing = false;
	}

	/**
	 * Post-activation health-check: load the front page in the background so this
	 * guard vets a newly-activated snippet immediately. If it fatals on the front
	 * end, the shutdown handler auto-deactivates it and the manager surfaces that.
	 *
	 * Two modes:
	 *  - Non-blocking (default): fire-and-forget. A BLOCKING loopback would hang
	 *    the caller on a single-worker host, because the only worker is busy
	 *    serving THIS request and cannot answer its own loopback until it returns.
	 *    Dispatched async, the loopback still runs the snippet and the guard still
	 *    auto-deactivates a front-end fatal; the caller just doesn't wait for it,
	 *    so it cannot report rolled_back synchronously. Same pattern core uses to
	 *    spawn wp-cron.
	 *  - Blocking: wait for the loopback and report rolled_back. Only safe when the
	 *    caller runs OUT of the web request being served (WP-CLI, or an agent in a
	 *    separate process), where there is no self-deadlock.
	 *
	 * @param int  $id       Snippet ID that was just activated.
	 * @param bool $blocking Wait for the loopback and detect a rollback. Default false.
	 * @return array { rolled_back: bool, http: int, dispatched: bool }
	 */
	public static function health_check( $id, $blocking = false ) {
		$args = array(
			'redirection' => 0,
			// Same-site loopback to home_url(). Local and staging certificates are
			// frequently self-signed, and a TLS failure here would silently skip the
			// check that exists to catch a fatal. Never copy this to a third-party host.
			'sslverify'   => false,
		);
		if ( $blocking ) {
			$args['timeout'] = 25;
		} else {
			$args['timeout']  = 0.01;
			$args['blocking'] = false;
		}

		$resp = wp_remote_get( home_url( '/' ), $args );

		if ( ! $blocking ) {
			return array(
				'rolled_back' => false,
				'http'        => 0,
				'dispatched'  => ! is_wp_error( $resp ),
			);
		}

		$repo    = new VibeSnip_Snippets();
		$snippet = $repo->get( $id );
		$rolled  = ( $snippet && 'error' === $snippet->status );

		if ( $rolled ) {
			$repo->log( $id, 'auto_rollback', 'error', __( 'Health-check after activation caught a front-end fatal; snippet was rolled back.', 'vibesnip' ) );
		}

		return array(
			'rolled_back' => $rolled,
			'http'        => is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp ),
			'dispatched'  => true,
		);
	}

	/**
	 * Validate snippet code before it is saved active.
	 *
	 * @param string $type Snippet type.
	 * @param string $code Snippet code.
	 * @return true|string True when valid, else the error message.
	 */
	public static function validate( $type, $code ) {
		if ( 'php' === $type ) {
			return vibesnip_validate_php( $code );
		}
		// CSS/JS/HTML/text carry no server-side execution risk; accepted as-is
		// in P0. (Client-side linting arrives with the Monaco editor in P2.)
		return true;
	}
}
