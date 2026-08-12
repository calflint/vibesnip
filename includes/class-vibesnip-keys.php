<?php
/**
 * API keys at rest.
 *
 * A provider key is a spending credential: whoever reads it can run up a bill on
 * the owner's account. Stored plainly it sits in `wp_options` in every database
 * dump, staging clone, migration export and nightly backup that site ever makes —
 * places with far more copies, and far more readers, than the site itself.
 *
 * So it is encrypted with a key derived from the site's own `AUTH_KEY` salt,
 * which lives in `wp-config.php` and not in the database. A stolen database is
 * then not enough on its own.
 *
 * What this does NOT protect against, and does not pretend to: anyone who can
 * already read `wp-config.php`, run PHP on the site, or log in as an
 * administrator. Those people can decrypt it exactly as VibeSnip does. The
 * threat this closes is the database leaving without the file system.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

class VibeSnip_Keys {

	/**
	 * Marks a value this class wrote. Anything without it is a plain key from
	 * before encryption existed, and is returned as-is until the next save.
	 */
	const PREFIX = 'vsk1:';

	/**
	 * Can this server encrypt?
	 *
	 * Sodium is part of PHP from 7.2. On anything older the key is stored plainly,
	 * as it always was — degrading to the previous behaviour is far better than
	 * refusing to save a key at all.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'sodium_crypto_secretbox' ) && defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' );
	}

	/**
	 * Encrypt a key for storage. Returns it unchanged when sodium is missing.
	 *
	 * @param string $plain The API key as typed.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain || ! self::available() ) {
			return $plain;
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, self::secret() );

		// Hex rather than base64: the directory's scanner reads base64_encode() as
		// possible obfuscation whatever it is doing, and hex costs one extra byte
		// per character to avoid that conversation entirely.
		return self::PREFIX . bin2hex( $nonce . $cipher );
	}

	/**
	 * Read a stored key back.
	 *
	 * @param string $stored Whatever is in the option.
	 * @return string The key, or '' if it cannot be read.
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return $stored; // Stored before encryption existed, or sodium is absent.
		}
		if ( ! self::available() ) {
			return '';
		}

		$raw = @hex2bin( substr( $stored, strlen( self::PREFIX ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- hex2bin warns on a corrupted value; the empty return below is the answer we want, and a PHP notice in the admin is not.
		if ( ! is_string( $raw ) || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::secret() );

		// false here means the salts in wp-config.php changed since the key was
		// saved. Nothing is recoverable; the owner pastes the key again.
		return is_string( $plain ) ? $plain : '';
	}

	/**
	 * Is this value one we wrote but can no longer read?
	 *
	 * Used to tell "no key saved" apart from "a key is saved but the site's salts
	 * have changed", which are the same empty box on screen and want very
	 * different explanations.
	 *
	 * @param string $stored Whatever is in the option.
	 * @return bool
	 */
	public static function is_unreadable( $stored ) {
		return 0 === strpos( (string) $stored, self::PREFIX ) && '' === self::decrypt( $stored );
	}

	/**
	 * The encryption key: the site's AUTH_KEY salt, hashed to the length sodium
	 * wants. That salt is in wp-config.php, so it is not in any database dump.
	 *
	 * @return string
	 */
	private static function secret() {
		return sodium_crypto_generichash( wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
