<?php
/**
 * Runs active snippets: PHP execution + CSS/JS/HTML injection + shortcodes.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

class VibeSnip_Executor {

	/** @var VibeSnip_Snippet[] Output snippets keyed by hook. */
	private $output = array(
		'site_header' => array(),
		'site_body'   => array(),
		'site_footer' => array(),
	);

	/** @var VibeSnip_Snippet[] Snippets available as shortcodes, keyed by ID. */
	private $shortcodes = array();

	/**
	 * Entry point, fired on `plugins_loaded`.
	 */
	public function boot() {
		if ( VibeSnip_Guard::is_safe_mode() ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', array( $this, 'safe_mode_notice' ) );
			}
			return;
		}

		$repo    = new VibeSnip_Snippets();
		$active  = $repo->get_active();
		$is_admin = is_admin();

		add_shortcode( 'vibesnip', array( $this, 'do_shortcode' ) );

		foreach ( $active as $snippet ) {
			switch ( $snippet->location ) {
				case 'everywhere':
					if ( 'php' === $snippet->type ) {
						$this->maybe_run_php( $snippet );
					}
					break;

				case 'admin_only':
					if ( 'php' === $snippet->type && $is_admin ) {
						$this->maybe_run_php( $snippet );
					}
					break;

				case 'frontend_only':
					if ( 'php' === $snippet->type && ! $is_admin ) {
						$this->maybe_run_php( $snippet );
					}
					break;

				case 'site_header':
				case 'site_body':
				case 'site_footer':
					// These buckets are echoed verbatim, so php must never reach them —
					// render() has no php case and would print the source into the page.
					// The repository now refuses to store that pairing; this also covers
					// rows written before it did.
					if ( 'php' === $snippet->type ) {
						break;
					}
					$this->output[ $snippet->location ][] = $snippet;
					break;

				case 'shortcode':
					$this->shortcodes[ $snippet->id ] = $snippet;
					break;
			}
		}

		if ( ! empty( $this->output['site_header'] ) ) {
			add_action( 'wp_head', array( $this, 'print_header' ), 99 );
		}
		if ( ! empty( $this->output['site_body'] ) ) {
			add_action( 'wp_body_open', array( $this, 'print_body' ), 1 );
		}
		if ( ! empty( $this->output['site_footer'] ) ) {
			add_action( 'wp_footer', array( $this, 'print_footer' ), 99 );
		}
	}

	/**
	 * Run a PHP snippet now, or — if it has conditions — defer it to a hook
	 * where page/query context exists so the conditions can be evaluated.
	 *
	 * Unconditional snippets keep running early (on plugins_loaded) so they can
	 * register their own hooks; conditional ones inherently need late context.
	 *
	 * @param VibeSnip_Snippet $snippet Snippet.
	 */
	private function maybe_run_php( $snippet ) {
		if ( ! VibeSnip_Conditions::has( $snippet->conditions ) ) {
			$this->run_php( $snippet );
			return;
		}
		$hook = is_admin() ? 'admin_init' : 'wp';
		add_action(
			$hook,
			function () use ( $snippet ) {
				if ( VibeSnip_Conditions::passes( $snippet->conditions ) ) {
					$this->run_php( $snippet );
				}
			},
			20
		);
	}

	/**
	 * Run a direct PHP snippet (everywhere / admin_only / frontend_only).
	 *
	 * A well-formed snippet only registers hooks and emits nothing here. But if
	 * one echoes directly, that stray output must not reach the admin response —
	 * it would print atop every screen and, worse, break redirects (a deactivate
	 * click can't send its Location header once output has started). So in the
	 * admin we buffer and discard anything the snippet prints. Output belongs in
	 * a shortcode or an output-location snippet, not in direct execution.
	 *
	 * @param VibeSnip_Snippet $snippet Snippet.
	 */
	private function run_php( $snippet ) {
		$buffering = is_admin();
		if ( $buffering ) {
			ob_start();
		}

		$this->execute_php( $snippet );

		if ( $buffering && ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}

	/**
	 * Execute a PHP snippet inside the guarded, global-namespace frame.
	 * Callers decide what to do with any output it produces.
	 *
	 * @param VibeSnip_Snippet $snippet Snippet.
	 */
	private function execute_php( $snippet ) {
		$before = $this->hook_snapshot();

		try {
			vibesnip_execute_php( $snippet->id, $snippet->code );
		} catch ( \Throwable $e ) {
			// Catchable: deactivate + record, then keep the request alive.
			VibeSnip_Guard::handle_throwable( $snippet->id, $e );
		}

		// Most snippets only register hooks here and do their real work later, long
		// after the guarded frame above has closed. Wrap whatever they just hooked
		// so a fatal inside those callbacks is still attributed to this snippet.
		$this->guard_new_hooks( $snippet->id, $before );
	}

	/**
	 * Record which hook callbacks are registered right now.
	 *
	 * @return array Hook name => priority => callback key => true.
	 */
	private function hook_snapshot() {
		$snapshot = array();
		if ( empty( $GLOBALS['wp_filter'] ) ) {
			return $snapshot;
		}
		foreach ( $GLOBALS['wp_filter'] as $hook => $wp_hook ) {
			if ( ! $wp_hook instanceof WP_Hook ) {
				continue;
			}
			foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $key => $unused ) {
					$snapshot[ $hook ][ $priority ][ $key ] = true;
				}
			}
		}
		return $snapshot;
	}

	/**
	 * Wrap every callback registered since $before in the guard.
	 *
	 * The wrapper marks the snippet as running for the duration of the callback,
	 * so an uncatchable fatal inside it is attributed by the shutdown handler, and
	 * catches any Throwable so the request survives. A filter still gets its first
	 * argument back on failure, so the filter chain is not broken.
	 *
	 * @param int   $id     Snippet ID.
	 * @param array $before Snapshot taken before the snippet ran.
	 */
	private function guard_new_hooks( $id, $before ) {
		if ( empty( $GLOBALS['wp_filter'] ) ) {
			return;
		}
		foreach ( $GLOBALS['wp_filter'] as $hook => $wp_hook ) {
			if ( ! $wp_hook instanceof WP_Hook ) {
				continue;
			}
			foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $key => $callback ) {
					if ( isset( $before[ $hook ][ $priority ][ $key ] ) || ! isset( $callback['function'] ) ) {
						continue;
					}
					$wp_hook->callbacks[ $priority ][ $key ]['function'] = $this->guarded_callback( $id, $callback['function'] );
				}
			}
		}
	}

	/**
	 * Build the guarded wrapper around one snippet-registered callback.
	 *
	 * @param int      $id       Snippet ID.
	 * @param callable $original The callback the snippet registered.
	 * @return callable
	 */
	private function guarded_callback( $id, $original ) {
		return function () use ( $id, $original ) {
			$args = func_get_args();
			VibeSnip_Guard::mark_running( $id );
			try {
				return call_user_func_array( $original, $args );
			} catch ( \Throwable $e ) {
				VibeSnip_Guard::handle_throwable( $id, $e );
				// Filters must still return something usable.
				return isset( $args[0] ) ? $args[0] : null;
			} finally {
				VibeSnip_Guard::clear_running();
			}
		};
	}

	/**
	 * Render one output snippet as its markup.
	 *
	 * @param VibeSnip_Snippet $snippet Snippet.
	 * @return string
	 */
	private function render( $snippet ) {
		$id   = (int) $snippet->id;
		$code = $snippet->code;

		switch ( $snippet->type ) {
			case 'css':
				return "\n<style id=\"vibesnip-css-{$id}\">\n{$code}\n</style>\n";
			case 'js':
				return "\n<script id=\"vibesnip-js-{$id}\">\n{$code}\n</script>\n";
			case 'html':
			case 'text':
			default:
				return "\n<!-- vibesnip:{$id} -->\n{$code}\n";
		}
	}

	/**
	 * Echo all snippets registered for a hook bucket.
	 *
	 * @param string $bucket Location key.
	 */
	private function print_bucket( $bucket ) {
		foreach ( $this->output[ $bucket ] as $snippet ) {
			if ( VibeSnip_Conditions::has( $snippet->conditions ) && ! VibeSnip_Conditions::passes( $snippet->conditions ) ) {
				continue;
			}
			// Output is authored by an admin with manage_vibesnip and stored
			// verbatim; it is emitted exactly as written, like a theme template.
			echo $this->render( $snippet ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- this IS the snippet's markup, authored by a user with manage_vibesnip and emitted verbatim like a theme template; escaping it would print the code instead of running it.
		}
	}

	public function print_header() {
		$this->print_bucket( 'site_header' );
	}

	public function print_body() {
		$this->print_bucket( 'site_body' );
	}

	public function print_footer() {
		$this->print_bucket( 'site_footer' );
	}

	/**
	 * `[vibesnip id="12"]` — render a shortcode-located snippet on demand.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function do_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'vibesnip' );
		$id   = (int) $atts['id'];

		if ( ! isset( $this->shortcodes[ $id ] ) ) {
			return '';
		}
		$snippet = $this->shortcodes[ $id ];

		if ( VibeSnip_Conditions::has( $snippet->conditions ) && ! VibeSnip_Conditions::passes( $snippet->conditions ) ) {
			return '';
		}

		if ( 'php' === $snippet->type ) {
			ob_start();
			$this->execute_php( $snippet );
			return ob_get_clean();
		}
		return $this->render( $snippet );
	}

	/**
	 * Admin notice shown while Safe Mode is engaged.
	 */
	public function safe_mode_notice() {
		echo '<div class="notice notice-warning"><p><strong>VibeSnip Safe Mode is on.</strong> ';
		echo esc_html__( 'All snippets are paused because VIBESNIP_SAFE_MODE is defined in wp-config.php. Remove that line to resume.', 'vibesnip' );
		echo '</p></div>';
	}
}
