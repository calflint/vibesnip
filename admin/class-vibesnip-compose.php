<?php
/**
 * AI Compose: describe what you want, agree on a plan, then decide what to keep.
 *
 * The screen is a three-step conversation rather than a one-shot generator, because
 * the one-shot version had a failure mode that was invisible until later: the model
 * would misread a vague request, write something plausible anyway, and the snippet
 * would land in All Snippets under a name the author did not recognise. So:
 *
 *   1. plan()  — the model restates what it thinks you asked for and what it intends
 *                to write. No code yet. You approve it or send it back.
 *   2. build() — it writes the code, and reviews its own output through the same
 *                Ask AI reviewer the editor uses.
 *   3. save()  — the ONLY step that touches the database, and only when you click it.
 *
 * The conversation lives in the browser; every request here is stateless. Nothing is
 * written until step 3, and what step 3 writes is an inactive draft that still has to
 * go through VibeSnip_Guard before it can run — exactly like a hand-typed snippet.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

class VibeSnip_Compose {

	const NONCE = 'vibesnip_compose';

	/** @var VibeSnip_Snippets */
	private $repo;

	public function __construct( VibeSnip_Snippets $repo ) {
		$this->repo = $repo;
	}

	public function hooks() {
		add_action( 'wp_ajax_vibesnip_compose_plan', array( $this, 'ajax_plan' ) );
		add_action( 'wp_ajax_vibesnip_compose_build', array( $this, 'ajax_build' ) );
		add_action( 'wp_ajax_vibesnip_compose_save', array( $this, 'ajax_save' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Screen                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Enqueue the conversation script. Called from VibeSnip_Admin::assets().
	 */
	public function assets() {
		wp_enqueue_script( 'vibesnip-compose', VIBESNIP_URL . 'admin/js/compose.js', array( 'jquery' ), VIBESNIP_VERSION, true );

		wp_localize_script(
			'vibesnip-compose',
			'VibeSnipCompose',
			array(
				'nonce' => wp_create_nonce( self::NONCE ),
				'i18n'  => array(
					'you'           => __( 'You asked for', 'vibesnip' ),
					'thinking'      => __( 'Reading your request…', 'vibesnip' ),
					'writing'       => __( 'Writing the code, then reviewing it…', 'vibesnip' ),
					'saving'        => __( 'Saving…', 'vibesnip' ),
					'understanding' => __( 'What I understood', 'vibesnip' ),
					'approach'      => __( 'How I would build it', 'vibesnip' ),
					'willWrite'     => __( 'What I would write', 'vibesnip' ),
					'caveats'       => __( 'Worth knowing', 'vibesnip' ),
					'questions'     => __( 'I had to assume', 'vibesnip' ),
					'approve'       => __( 'Looks right — build it', 'vibesnip' ),
					'rephrase'      => __( 'Not quite — let me rephrase', 'vibesnip' ),
					'rephraseHint'  => __( 'Add the detail that was missing and send it again. I still have the earlier attempt for context.', 'vibesnip' ),
					'save'          => __( 'Save to my snippets', 'vibesnip' ),
					'discard'       => __( 'Discard', 'vibesnip' ),
					'saved'         => __( 'Saved as an inactive draft.', 'vibesnip' ),
					'openEditor'    => __( 'Open in the editor', 'vibesnip' ),
					'failed'        => __( 'That did not work. Try again.', 'vibesnip' ),
					'whatItDoes'    => __( 'What it does', 'vibesnip' ),
					'risks'         => __( 'Risks', 'vibesnip' ),
					'notes'         => __( 'After you switch it on', 'vibesnip' ),
					'advisory'      => __( 'AI assessment — an opinion, not a syntax check.', 'vibesnip' ),
					'safe'          => __( 'Looks safe', 'vibesnip' ),
					'caution'       => __( 'Use caution', 'vibesnip' ),
					'unsafe'        => __( 'Not safe', 'vibesnip' ),
					'noReview'      => __( 'The review could not be produced, but the code above is complete. Read it before saving.', 'vibesnip' ),
				),
			)
		);
	}

	/**
	 * Render the screen. Everything after the prompt box is built by compose.js
	 * from the JSON each step returns.
	 */
	public function render() {
		if ( ! current_user_can( VibeSnip_Admin::CAP ) ) {
			return;
		}
		$ai_on        = VibeSnip_AI::is_enabled();
		$can_ai       = current_user_can( 'vibesnip_use_ai' );
		$has_key      = VibeSnip_AI::has_key();
		$settings_url = admin_url( 'admin.php?page=vibesnip-settings' );
		$ready        = $ai_on && $can_ai && $has_key;
		?>
		<div class="wrap vibesnip-wrap vibesnip-compose-page">
			<h1><?php esc_html_e( 'AI Compose', 'vibesnip' ); ?></h1>
			<p class="vibesnip-tagline"><?php esc_html_e( 'Describe what you want your site to do. VibeSnip tells you what it understood and what it plans to write before it writes anything — and nothing is added to your snippets until you say so.', 'vibesnip' ); ?></p>

			<?php if ( ! $ai_on ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					printf(
						/* translators: %s: settings page URL. */
						wp_kses_post( __( 'AI features are switched off on this site. Turn them on under <a href="%s">Settings → General</a>, where the risks are set out first.', 'vibesnip' ) ),
						esc_url( $settings_url )
					);
					?>
				</p></div>
			<?php elseif ( ! $can_ai ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Your account does not have the “Use AI features” permission.', 'vibesnip' ); ?></p></div>
			<?php elseif ( ! $has_key ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					printf(
						/* translators: %s: settings page URL. */
						wp_kses_post( __( 'Add your AI provider API key on the <a href="%s">Settings page</a> to start composing.', 'vibesnip' ) ),
						esc_url( $settings_url )
					);
					?>
				</p></div>
			<?php endif; ?>

			<div class="vibesnip-compose-thread" id="vs-compose-thread" aria-live="polite"></div>

			<form class="vibesnip-compose-form" id="vs-compose-form" autocomplete="off">
				<label class="screen-reader-text" for="vs-compose-prompt"><?php esc_html_e( 'Describe the snippet you want', 'vibesnip' ); ?></label>
				<textarea id="vs-compose-prompt" class="vibesnip-compose-input" rows="3" <?php disabled( ! $ready ); ?>
					placeholder="<?php esc_attr_e( 'e.g. Add a “SALE” badge to discounted WooCommerce products.', 'vibesnip' ); ?>"></textarea>
				<div class="vibesnip-compose-actions">
					<span class="vibesnip-microcopy"><?php esc_html_e( 'Ctrl+Enter sends.', 'vibesnip' ); ?></span>
					<button type="submit" class="button button-primary" id="vs-compose-send" <?php disabled( ! $ready ); ?>>✨ <?php esc_html_e( 'Generate', 'vibesnip' ); ?></button>
				</div>
			</form>

			<p class="vibesnip-microcopy"><?php esc_html_e( 'Anything you save arrives inactive. PHP is syntax-checked before it can be switched on, and auto-deactivates if it ever fatals.', 'vibesnip' ); ?></p>

			<?php VibeSnip_Donate::render(); ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Steps                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Step 1 — restate the request and propose an approach. Writes no code and
	 * touches no table.
	 */
	public function ajax_plan() {
		$this->authorize();

		$prompt = $this->read_prompt();
		if ( '' === $prompt ) {
			wp_send_json_error( array( 'message' => __( 'Describe what you want the snippet to do.', 'vibesnip' ) ), 400 );
		}

		$plan = VibeSnip_AI::plan( $prompt, $this->read_history() );
		if ( is_wp_error( $plan ) ) {
			wp_send_json_error( array( 'message' => $plan->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'plan' => $plan ) );
	}

	/**
	 * Step 2 — write the code, then review it. Still touches no table: the result
	 * goes back to the browser and waits there for a decision.
	 */
	public function ajax_build() {
		$this->authorize();

		$prompt = $this->read_prompt();
		if ( '' === $prompt ) {
			wp_send_json_error( array( 'message' => __( 'Describe what you want the snippet to do.', 'vibesnip' ) ), 400 );
		}

		$proposals = VibeSnip_AI::generate( $prompt, $this->read_approved_plan() );
		if ( is_wp_error( $proposals ) ) {
			wp_send_json_error( array( 'message' => $proposals->get_error_message() ), 400 );
		}

		foreach ( $proposals as $i => $proposal ) {
			$proposals[ $i ]['review']  = $this->review_unsaved( $proposal );
			$proposals[ $i ]['locationLabel'] = VibeSnip_Snippet::location_label( $proposal['location'] );
			$proposals[ $i ]['typeLabel']     = VibeSnip_Snippet::types()[ $proposal['type'] ];
		}

		wp_send_json_success( array( 'snippets' => array_values( $proposals ) ) );
	}

	/**
	 * Step 3 — the only step that writes. Saves one snippet as an inactive draft.
	 */
	public function ajax_save() {
		$this->authorize();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce is checked by authorize() on the line above; the sniff cannot follow it into a helper method.
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		if ( ! isset( VibeSnip_Snippet::types()[ $type ] ) ) {
			wp_send_json_error( array( 'message' => __( 'That snippet type is not one VibeSnip supports.', 'vibesnip' ) ), 400 );
		}

		// The same authoring gate the editor and the importer use: on multisite a
		// PHP snippet is Super Admin only, whoever or whatever wrote it.
		if ( ! VibeSnip_Guard::can_author( $type ) ) {
			wp_send_json_error( array( 'message' => VibeSnip_Guard::author_denied_message() ), 403 );
		}

		$location = isset( $_POST['location'] ) ? sanitize_key( wp_unslash( $_POST['location'] ) ) : '';
		if ( ! isset( VibeSnip_Snippet::locations_for_type( $type )[ $location ] ) ) {
			$location = ( 'php' === $type ) ? 'everywhere' : 'site_header';
		}

		// Raw by design — snippet code is code, not content, and sanitizing it would
		// corrupt valid syntax. It is guarded instead: this handler is behind a
		// capability and a nonce, the row is created INACTIVE, and PHP is
		// syntax-checked by VibeSnip_Guard before anything can switch it on.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- snippet code is intentionally raw; see above.
		$code = isset( $_POST['code'] ) ? (string) wp_unslash( $_POST['code'] ) : '';
		if ( '' === trim( $code ) ) {
			wp_send_json_error( array( 'message' => __( 'There is no code to save.', 'vibesnip' ) ), 400 );
		}

		$id = $this->repo->create(
			array(
				'title'       => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : __( 'AI snippet', 'vibesnip' ),
				'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
				'code'        => $code,
				'type'        => $type,
				'location'    => $location,
				'priority'    => 10,
				'tags'        => 'ai',
				'status'      => 'inactive',
				'source'      => 'ai',
				'ai_prompt'   => $this->read_prompt(),
				// Recorded from settings, not from the browser: provenance should say
				// which model actually answered, not which one a form field claimed.
				'ai_model'    => (string) VibeSnip_AI::settings()['model'],
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'The snippet could not be saved.', 'vibesnip' ) ), 500 );
		}

		// Keep the review that was already produced during the build, so opening the
		// snippet in the editor shows it without paying for a second call.
		$review = $this->read_review();
		if ( $review ) {
			$this->repo->save_review( $id, $review );
		}

		wp_send_json_success(
			array(
				'id'      => $id,
				'editUrl' => admin_url( 'admin.php?page=vibesnip-edit&id=' . $id ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Capability then nonce, on every step. AI screens need both `manage_vibesnip`
	 * and `vibesnip_use_ai`; holding one without the other grants nothing here.
	 */
	private function authorize() {
		if ( ! VibeSnip_AI::is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'AI features are switched off. Turn them on under VibeSnip → Settings → General.', 'vibesnip' ) ), 403 );
		}
		if ( ! current_user_can( VibeSnip_Admin::CAP ) || ! current_user_can( 'vibesnip_use_ai' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to use AI features.', 'vibesnip' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	/**
	 * @return string The user's request.
	 */
	private function read_prompt() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked by authorize(), which every caller runs first; the sniff cannot follow it into a helper method.
		return isset( $_POST['prompt'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) ) : '';
	}

	/**
	 * Earlier turns of this conversation, so a rephrase is a correction rather
	 * than a fresh start. Capped because the whole thing is one request body.
	 *
	 * @return string[]
	 */
	private function read_history() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked by authorize(), which every caller runs first; the sniff cannot follow it into a helper method.
		if ( ! isset( $_POST['history'] ) || ! is_array( $_POST['history'] ) ) {
			return array();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
		$raw = array_map( 'sanitize_textarea_field', wp_unslash( $_POST['history'] ) );

		return array_slice( array_filter( $raw, 'strlen' ), -6 );
	}

	/**
	 * The plan the user approved, flattened to the prose the model will read.
	 *
	 * It arrives as the JSON we sent to the browser a moment ago, so it is decoded
	 * and rebuilt field by field rather than passed through a text sanitizer —
	 * `sanitize_textarea_field()` on a JSON string collapses the newlines and eats
	 * any `<`, which would hand the model a corrupted version of its own plan.
	 *
	 * @return string Empty when no usable plan came back.
	 */
	private function read_approved_plan() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce is checked by authorize(); the value is JSON, decoded here and every field sanitized individually below, which a whole-blob sanitizer would corrupt.
		$plan = isset( $_POST['plan'] ) ? json_decode( (string) wp_unslash( $_POST['plan'] ), true ) : null;
		if ( ! is_array( $plan ) ) {
			return '';
		}

		$lines = array();
		foreach ( array( 'understanding', 'approach' ) as $field ) {
			if ( ! empty( $plan[ $field ] ) ) {
				$lines[] = sanitize_textarea_field( (string) $plan[ $field ] );
			}
		}
		if ( ! empty( $plan['snippets'] ) && is_array( $plan['snippets'] ) ) {
			foreach ( $plan['snippets'] as $s ) {
				if ( empty( $s['title'] ) ) {
					continue;
				}
				$lines[] = '- ' . sanitize_text_field( (string) $s['title'] )
					. ' (' . sanitize_key( isset( $s['type'] ) ? $s['type'] : '' ) . '): '
					. sanitize_text_field( isset( $s['summary'] ) ? (string) $s['summary'] : '' );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * The review produced during the build, re-normalised before it is stored.
	 *
	 * It has been to the browser and back, so it is treated as untrusted input and
	 * rebuilt from a fixed set of keys rather than stored as it arrives.
	 *
	 * @return array|null
	 */
	private function read_review() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce is checked by authorize(); the value is JSON, decoded here and rebuilt field by field from a fixed key set below.
		$raw = isset( $_POST['review'] ) ? json_decode( (string) wp_unslash( $_POST['review'] ), true ) : null;
		if ( ! is_array( $raw ) || empty( $raw['summary'] ) ) {
			return null;
		}

		$verdicts = array( 'safe', 'caution', 'unsafe' );
		$review   = array(
			'summary'      => sanitize_textarea_field( (string) $raw['summary'] ),
			'verdict'      => ( isset( $raw['verdict'] ) && in_array( $raw['verdict'], $verdicts, true ) ) ? $raw['verdict'] : 'caution',
			'risks'        => array(),
			'reviewed_at'  => current_time( 'mysql' ),
		);
		foreach ( array( 'what_it_does', 'site_impact', 'recommended_conditions', 'notes' ) as $field ) {
			$review[ $field ] = isset( $raw[ $field ] ) ? sanitize_textarea_field( (string) $raw[ $field ] ) : '';
		}
		if ( ! empty( $raw['risks'] ) && is_array( $raw['risks'] ) ) {
			foreach ( array_slice( $raw['risks'], 0, 10 ) as $risk ) {
				$risk = sanitize_text_field( (string) $risk );
				if ( '' !== $risk ) {
					$review['risks'][] = $risk;
				}
			}
		}

		return $review;
	}

	/**
	 * Review a proposal that has not been saved. The reviewer only reads fields, so
	 * a value object is enough — and this must not create a row, because the whole
	 * point of the screen is that nothing exists until Save.
	 *
	 * A failed review is not a failed build: the code is still shown, with a note.
	 *
	 * @param array $proposal One entry from VibeSnip_AI::generate().
	 * @return array|null
	 */
	private function review_unsaved( array $proposal ) {
		$snippet = new VibeSnip_Snippet(
			array(
				'title'    => $proposal['title'],
				'code'     => $proposal['code'],
				'type'     => $proposal['type'],
				'location' => $proposal['location'],
				'status'   => 'inactive',
			)
		);

		$report = VibeSnip_AI::review( $snippet );

		return is_wp_error( $report ) ? null : $report;
	}
}
