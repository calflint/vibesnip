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
 * The button is Ko-fi's own drop-in widget (`storage.ko-fi.com/cdn/widget/Widget_2.js`).
 * That is a third-party script, so this screen does make one outbound request —
 * it is disclosed under `== External services ==` in readme.txt. VibeSnip is
 * self-hosted, not a wordpress.org directory plugin, so the directory rule
 * against loading code from another host does not apply.
 *
 * The widget draws itself with `document.write`, so both tags must stay inline
 * and un-deferred at the point the button belongs — moving them to
 * `wp_enqueue_script` would run them after the document closed and blank the
 * admin page. The `<noscript>` link is the fallback for anyone whose browser or
 * network never gets the script.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

class VibeSnip_Donate {

	/**
	 * The owner's Ko-fi page code, and the page it resolves to.
	 *
	 * Constants rather than settings: an editable payout URL in a plugin that
	 * asks for money is a phishing vector, and nobody but the author has a reason
	 * to change it.
	 */
	const CODE = 'N4S525E796';
	const URL  = 'https://ko-fi.com/N4S525E796';

	/** Ko-fi brand colour the widget is drawn in. */
	const COLOR = '#86acb2';

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
				<script type="text/javascript" src="https://storage.ko-fi.com/cdn/widget/Widget_2.js"></script>
				<script type="text/javascript">
					kofiwidget2.init(
						<?php echo wp_json_encode( __( 'Donate', 'vibesnip' ) ); ?>,
						<?php echo wp_json_encode( self::COLOR ); ?>,
						<?php echo wp_json_encode( self::CODE ); ?>
					);
					kofiwidget2.draw();
				</script>
				<noscript>
					<a class="button button-secondary" href="<?php echo esc_url( self::URL ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Donate', 'vibesnip' ); ?>
					</a>
				</noscript>
			</p>
		</div>
		<?php
	}
}
