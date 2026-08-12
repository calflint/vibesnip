<?php
/**
 * The optional donation footer.
 *
 * VibeSnip is GPL and complete without paying for anything, so this asks once,
 * quietly, at the bottom of a screen you were already on — and can be switched
 * off permanently from Settings. It is a plain outbound link: nothing is sent
 * anywhere, no request is made, and no data leaves the site, which is why there
 * is no `== External services ==` entry owed for it.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

class VibeSnip_Donate {

	/**
	 * The owner's PayPal payment page.
	 *
	 * A constant rather than a setting: an editable payout URL in a plugin that
	 * asks for money is a phishing vector, and nobody but the author has a reason
	 * to change it.
	 */
	const URL = 'https://www.paypal.com/ncp/payment/N5XNPLY724KPC';

	/**
	 * Is the donation footer switched on for this site?
	 *
	 * @return bool
	 */
	public static function enabled() {
		$prefs = VibeSnip_Admin::prefs();
		return empty( $prefs['hide_donate'] );
	}

	/**
	 * Print the footer. Call at the end of a screen, inside its `.wrap`.
	 *
	 * Safe to call unconditionally — it prints nothing when switched off, and
	 * nothing for a user who cannot reach the setting that turns it off.
	 */
	public static function render() {
		if ( ! self::enabled() || ! current_user_can( 'manage_vibesnip' ) ) {
			return;
		}
		?>
		<div class="vibesnip-donate">
			<p class="vibesnip-donate-ask">
				<?php esc_html_e( 'VibeSnip is free, GPL-licensed, and every feature on this page stays free. If it has been useful and you feel like chipping in toward its development, you can — entirely optional, and nothing changes if you do not. If you would rather not be asked, switch this footer off under Settings → General and it disappears from every screen.', 'vibesnip' ); ?>
			</p>
			<p class="vibesnip-donate-actions">
				<a class="button button-secondary" href="<?php echo esc_url( self::URL ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Donate via PayPal', 'vibesnip' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
