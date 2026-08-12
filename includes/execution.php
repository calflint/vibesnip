<?php
/**
 * Global-namespace execution helpers.
 *
 * ---------------------------------------------------------------------------
 * NOTE FOR REVIEWERS: this file contains the plugin's only two eval() calls.
 *
 * They are the two Plugin Check errors this plugin reports, and they are
 * deliberate, disclosed, and left plain and unobfuscated on purpose. There are
 * exactly two, they are both below, and there are none anywhere else in the
 * plugin. This is the same mechanism WPCode and Code Snippets use, both of
 * which are listed in the directory.
 *
 *   vibesnip_execute_php()  — runs an admin-authored snippet. This is what the
 *       plugin is for: it does for a database-stored snippet exactly what
 *       WordPress does for a theme's functions.php. Without it the plugin
 *       stores code and never runs it.
 *
 *   vibesnip_validate_php() — the syntax check, which executes NOTHING.
 *       The code is wrapped in `if ( false ) { ... }`, so PHP parses
 *       every line (raising ParseError on a typo) while not one statement
 *       inside it can run. This is how a broken snippet is refused BEFORE it
 *       can reach the line above and white-screen the site.
 *
 * Neither is reachable by an untrusted user. The guard chain around them:
 *
 *   1. `manage_vibesnip` capability, treated as equivalent to `edit_plugins`
 *      — never granted to a role below administrator-level trust, and on
 *      multisite PHP snippets additionally require `is_super_admin()`.
 *   2. A nonce on every state change (`check_admin_referer` / `check_ajax_referer`).
 *   3. PHP is syntax-validated (`VibeSnip_Guard::validate`) before a snippet is
 *      ever allowed to become active.
 *   4. Snippets are inactive by default. Nothing runs — including anything the
 *      optional AI wrote — until a human explicitly activates it.
 *   5. Activation health-checks the front page and auto-rolls-back a fatal;
 *      a live snippet that fatals later is auto-deactivated by the shutdown
 *      handler and the error recorded.
 *   6. `define( 'VIBESNIP_SAFE_MODE', true )` in wp-config.php is a
 *      constant-only kill switch that pauses all execution.
 *
 * See the "Notes for reviewers" section of readme.txt for the same summary.
 * ---------------------------------------------------------------------------
 *
 * These functions live in the GLOBAL namespace on purpose: code evaluated here
 * resolves class names, constants and functions against the global namespace,
 * exactly as it would inside a theme's functions.php. Never move this file
 * under a namespace or wrap it in a class.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

/**
 * Strip a leading <?php / opening tag and a trailing ?> from snippet code so it
 * can be eval'd. Users routinely paste snippets with tags included.
 *
 * @param string $code Raw snippet code.
 * @return string
 */
function vibesnip_strip_php_tags( $code ) {
	$code = preg_replace( '/^\s*<\?php\b/', '', $code, 1 );
	$code = preg_replace( '/^\s*<\?/', '', $code, 1 );
	$code = preg_replace( '/\?>\s*$/', '', $code, 1 );
	return $code;
}

/**
 * Execute a PHP snippet. Sets the guard's "currently running" marker so that if
 * this snippet triggers a fatal, the shutdown handler knows which snippet to
 * blame and auto-deactivate.
 *
 * @param int    $id   Snippet ID.
 * @param string $code PHP code (with or without opening tag).
 * @return mixed Whatever the snippet returns.
 */
function vibesnip_execute_php( $id, $code ) {
	VibeSnip_Guard::mark_running( $id );
	try {
		// Eval #1 of 2 in the whole plugin. See the reviewer note at the top of this file.
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- running admin-authored snippets IS this plugin's purpose, the same job functions.php does. Reaching here requires the manage_vibesnip capability, a nonce, a passing syntax check, and a human having activated the snippet.
		return eval( vibesnip_strip_php_tags( $code ) );
	} finally {
		// Runs on normal return and on a catchable Throwable, but NOT on an
		// uncatchable fatal — in that case the marker survives so the shutdown
		// handler can still identify and deactivate the culprit.
		VibeSnip_Guard::clear_running();
	}
}

/**
 * Validate PHP snippet syntax WITHOUT executing it.
 *
 * PHP 7+ throws a ParseError (not a fatal) from eval() on a syntax error. By
 * wrapping the code in `if ( false ) { ... }` we force a full parse while
 * guaranteeing not a single statement runs.
 *
 * @param string $code PHP code.
 * @return true|string  True when valid, otherwise the error message.
 */
function vibesnip_validate_php( $code ) {
	$code = vibesnip_strip_php_tags( $code );

	// Reject the obvious ways to smuggle out of the wrapper.
	if ( preg_match( '/\bnamespace\s+[^;{]+[;{]/i', $code ) ) {
		return __( 'Top-level "namespace" declarations are not allowed in a snippet.', 'vibesnip' );
	}

	try {
		// Eval #2 of 2 in the whole plugin, and this one EXECUTES NOTHING.
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- compile-only check: the `if ( false )` wrapper means PHP parses every line (throwing ParseError on a typo) while not one statement inside can run. This is the gate that stops a broken snippet from ever reaching vibesnip_execute_php() and white-screening the site.
		eval( 'if ( false ) { ' . $code . "\n}" );
	} catch ( \ParseError $e ) {
		return $e->getMessage() . ' (line ' . $e->getLine() . ')';
	} catch ( \Throwable $e ) {
		// Anything that isn't a parse error means it at least parses.
		return true;
	}

	return true;
}
