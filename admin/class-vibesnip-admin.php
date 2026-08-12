<?php
/**
 * Admin: menu, assets, page rendering and action handling.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

class VibeSnip_Admin {

	const CAP   = 'manage_vibesnip';
	const PREFS = 'vibesnip_prefs';

	/**
	 * Transient prefix holding a rejected editor submission, per user.
	 *
	 * A refused save must never cost someone the code they just wrote, so the
	 * submission is parked here and the editor repopulates itself from it.
	 */
	const STASH = 'vibesnip_stash_';

	/** @var VibeSnip_Snippets */
	private $repo;

	/** @var VibeSnip_Compose */
	private $compose;

	public function __construct() {
		$this->repo = new VibeSnip_Snippets();

		require_once VIBESNIP_DIR . 'admin/class-vibesnip-compose.php';
		$this->compose = new VibeSnip_Compose( $this->repo );
	}

	public function hooks() {
		$this->compose->hooks();
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_row_actions' ) );
		add_action( 'admin_init', array( $this, 'handle_library' ) );
		add_action( 'admin_post_vibesnip_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_vibesnip_import', array( $this, 'handle_import' ) );
		add_action( 'admin_post_vibesnip_settings', array( $this, 'handle_settings' ) );
		add_action( 'admin_post_vibesnip_permissions', array( $this, 'handle_permissions' ) );
		add_action( 'admin_post_vibesnip_debug', array( $this, 'handle_debug' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'wp_ajax_vibesnip_ask_ai', array( $this, 'ajax_ask_ai' ) );
		add_action( 'wp_ajax_vibesnip_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_vibesnip_fetch_models', array( $this, 'ajax_fetch_models' ) );
	}

	/**
	 * Test connection: one cheap round-trip that says whether a key works.
	 *
	 * It tests the provider, model, key and endpoint currently shown on the
	 * Settings screen, not the saved ones. Testing the saved ones meant that
	 * choosing OpenAI, pasting an OpenAI key and pressing the button reported
	 * "Connected to Anthropic" — a correct answer to a question nobody asked.
	 * Nothing is written here; the form still has to be saved.
	 */
	public function ajax_test_connection() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change these settings.', 'vibesnip' ) ), 403 );
		}
		check_ajax_referer( 'vibesnip_test_connection', 'nonce' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce is checked on the line above.
		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$on_screen = array(
			'provider' => isset( VibeSnip_Provider::providers()[ $provider ] ) ? $provider : '',
			'api_key'  => isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '',
			'model'    => isset( $_POST['model'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['model'] ) ) ) : '',
			// esc_url_raw() rather than a text sanitizer, and before the trim: this is
			// an address the request will actually be sent to, so the scheme has to be
			// validated rather than tidied.
			'endpoint' => isset( $_POST['endpoint'] ) ? trim( esc_url_raw( wp_unslash( $_POST['endpoint'] ), array( 'http', 'https' ) ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = VibeSnip_AI::test_connection( $on_screen );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: provider name, 2: model id. */
					__( 'Connected to %1$s using %2$s.', 'vibesnip' ),
					$result['label'],
					$result['model']
				),
			)
		);
	}

	/**
	 * Replace a provider's model dropdown with the models its key can really use.
	 *
	 * Reads the form rather than the option, for the same reason Test connection
	 * does: someone refreshing the list has usually just pasted the key and not
	 * saved it yet. Writes nothing but the cache.
	 */
	public function ajax_fetch_models() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change these settings.', 'vibesnip' ) ), 403 );
		}
		check_ajax_referer( 'vibesnip_test_connection', 'nonce' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce is checked on the line above.
		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$models   = VibeSnip_AI::fetch_models(
			array(
				'provider' => isset( VibeSnip_Provider::providers()[ $provider ] ) ? $provider : '',
				'api_key'  => isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '',
				'endpoint' => isset( $_POST['endpoint'] ) ? trim( esc_url_raw( wp_unslash( $_POST['endpoint'] ), array( 'http', 'https' ) ) ) : '',
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( is_wp_error( $models ) ) {
			wp_send_json_error( array( 'message' => $models->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'models'  => $models,
				/* translators: %d: number of models the provider listed. */
				'message' => sprintf( _n( '%d model available.', '%d models available.', count( $models ), 'vibesnip' ), count( $models ) ),
			)
		);
	}

	/**
	 * Ask AI: review a saved snippet and store the report.
	 *
	 * Requires the snippet to exist. The editor's live buffer is reviewed (so the
	 * report reflects what is on screen), but the row must already exist because
	 * the report is stored against it. Advisory only — this cannot change a
	 * snippet's status; VibeSnip_Guard remains the sole gate on activation.
	 */
	public function ajax_ask_ai() {
		if ( ! VibeSnip_AI::is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'AI features are switched off. Turn them on under VibeSnip → Settings → General.', 'vibesnip' ) ), 403 );
		}
		if ( ! current_user_can( self::CAP ) || ! current_user_can( 'vibesnip_use_ai' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to use AI features.', 'vibesnip' ) ), 403 );
		}
		check_ajax_referer( 'vibesnip_ask_ai', 'nonce' );

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Save the snippet first, then ask AI to review it.', 'vibesnip' ) ), 400 );
		}

		$snippet = $this->repo->get( $id );
		if ( ! $snippet ) {
			wp_send_json_error( array( 'message' => __( 'Snippet not found.', 'vibesnip' ) ), 404 );
		}

		// Review what is on screen, not what was last saved. Raw by design (it is
		// code, not content) — it is only ever sent to the API, never output.
		if ( isset( $_POST['code'] ) ) {
			$snippet->code = (string) wp_unslash( $_POST['code'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- snippet code is code, not content: sanitizing it would corrupt valid syntax. Nonce + capability are checked above, and this value is only sent to the API, never output.
		}
		if ( isset( $_POST['type'] ) ) {
			$type = sanitize_key( wp_unslash( $_POST['type'] ) );
			if ( isset( VibeSnip_Snippet::types()[ $type ] ) ) {
				$snippet->type = $type;
			}
		}

		$report = VibeSnip_AI::review( $snippet );
		if ( is_wp_error( $report ) ) {
			wp_send_json_error( array( 'message' => $report->get_error_message() ), 400 );
		}

		$this->repo->save_review( $id, $report );

		wp_send_json_success( $report );
	}

	/* ------------------------------------------------------------------ */
	/* Menu                                                               */
	/* ------------------------------------------------------------------ */

	public function menu() {
		// A core dashicon rather than a base64 data URI: the directory's scanner reads
		// any base64_encode() call as possible obfuscation, and this is the same glyph.
		add_menu_page( 'VibeSnip', 'VibeSnip', self::CAP, 'vibesnip', array( $this, 'render_manage' ), VibeSnip_Brand::icon_url(), 58 );
		add_submenu_page( 'vibesnip', __( 'All Snippets', 'vibesnip' ), __( 'All Snippets', 'vibesnip' ), self::CAP, 'vibesnip', array( $this, 'render_manage' ) );
		// No AI menu item on a site that has not switched AI on, so the plugin's
		// navigation matches what it can actually do.
		if ( VibeSnip_AI::is_enabled() ) {
			add_submenu_page( 'vibesnip', __( 'AI Compose', 'vibesnip' ), '✨ ' . __( 'AI Compose', 'vibesnip' ), self::CAP, 'vibesnip-compose', array( $this, 'render_compose' ) );
		}
		add_submenu_page( 'vibesnip', __( 'Add Snippet', 'vibesnip' ), __( 'Add Snippet', 'vibesnip' ), self::CAP, 'vibesnip-edit', array( $this, 'render_edit' ) );
		add_submenu_page( 'vibesnip', __( 'Library', 'vibesnip' ), __( 'Library', 'vibesnip' ), self::CAP, 'vibesnip-library', array( $this, 'render_library' ) );
		add_submenu_page( 'vibesnip', __( 'Import', 'vibesnip' ), __( 'Import', 'vibesnip' ), self::CAP, 'vibesnip-import', array( $this, 'render_import' ) );
		add_submenu_page( 'vibesnip', __( 'Activity', 'vibesnip' ), __( 'Activity', 'vibesnip' ), self::CAP, 'vibesnip-activity', array( $this, 'render_activity' ) );
		add_submenu_page( 'vibesnip', __( 'Settings', 'vibesnip' ), __( 'Settings', 'vibesnip' ), self::CAP, 'vibesnip-settings', array( $this, 'render_settings' ) );
		add_submenu_page( 'vibesnip', __( "What's New", 'vibesnip' ), __( "What's New", 'vibesnip' ), self::CAP, 'vibesnip-whats-new', array( $this, 'render_whats_new' ) );
	}

	/**
	 * What's New: the current release's highlights, so nobody has to read a
	 * changelog to find out what changed.
	 */
	public function render_whats_new() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$items = array(
			array(
				__( 'AI is off until you turn it on', 'vibesnip' ),
				__( 'VibeSnip now installs as an ordinary snippets plugin: no AI menu, no AI buttons, no key field, and no contact with anyone. Switching AI on is a deliberate step under Settings → General that first sets out what it does, what is sent where, who pays for it and what can go wrong, and asks you to accept that. If you were already using AI, it stays on and you are not asked again.', 'vibesnip' ),
				__( 'You can switch it back off at any time, which hides the provider settings again.', 'vibesnip' ),
			),
			array(
				__( 'The model list now comes from your provider', 'vibesnip' ),
				__( 'Click “Refresh list” next to the model dropdown and VibeSnip asks Anthropic, OpenAI or Google which models your key can actually use, then offers exactly those, by their real names. A list shipped inside a release is out of date the moment a vendor renames something — which is how you end up choosing a model your account has never had and reading an error you cannot do anything about.', 'vibesnip' ),
				__( 'Only your key is sent. No site data, no snippet code. The answer is cached for a week.', 'vibesnip' ),
			),
			array(
				__( 'Your API key is encrypted, and browsers stop trying to save it', 'vibesnip' ),
				__( 'Keys are now encrypted before they are stored, using a secret from your wp-config.php that never goes into the database — so a leaked backup or database dump does not hand over your key. The key box is also no longer a browser password field, so Chrome, Safari and password managers stop offering to remember a credential you never asked them to keep. Keys saved by an earlier version were encrypted automatically when you updated.', 'vibesnip' ),
				__( 'It is still masked while you type, and a saved key is never shown back to you.', 'vibesnip' ),
			),
			array(
				__( 'A snippet can no longer be saved without a name', 'vibesnip' ),
				__( 'An untitled snippet used to save happily and then sit in your list as a blank row you could not identify. A name is now required. And if a save is ever refused, everything you had typed is put straight back into the editor instead of disappearing.', 'vibesnip' ),
				'',
			),
			array(
				__( 'Dates follow your site, and say so', 'vibesnip' ),
				__( 'Revisions and the activity log now use the date and time format you set for the site, and each screen names the timezone it is showing. If those times look hours out from your own clock, your site is still on the WordPress default of UTC — set your timezone under Settings → General and they will line up.', 'vibesnip' ),
				'',
			),
			array(
				__( 'Test connection now tells you the truth', 'vibesnip' ),
				__( 'It used to test whatever was last saved, so choosing OpenAI, pasting an OpenAI key and pressing the button answered “Connected to Anthropic”. It now tests the provider, model, key and address shown on screen — including a key you have just typed and not saved yet — and names the provider that actually answered.', 'vibesnip' ),
				__( 'It still saves nothing. Save the form as usual once the test passes.', 'vibesnip' ),
			),
			array(
				__( 'OpenRouter, Ollama and any other OpenAI-compatible service', 'vibesnip' ),
				__( '“OpenAI-compatible” is now its own choice in the provider list, separate from OpenAI. Pick it and you type the API address and the model name yourself, which is what OpenRouter, Groq, Together, Azure OpenAI, vLLM and a local Ollama or llama.cpp need. Before, you could point OpenAI at a gateway but were still picking from a list of OpenAI model names that gateway had never heard of.', 'vibesnip' ),
				__( 'Everything you send goes to the address you enter, under that host\'s terms. If it is running on your own machine, nothing leaves it.', 'vibesnip' ),
			),
			array(
				__( 'You can see when the AI is working', 'vibesnip' ),
				__( 'AI Compose and Ask AI both used to sit on a still screen for the tens of seconds a request takes, which looks exactly like one that has failed. AI Compose now adds a card to the conversation with a spinner, what it is doing and a running clock; Ask AI spins in its own button.', 'vibesnip' ),
				'',
			),
			array(
				__( 'AI Compose is readable again in dark mode', 'vibesnip' ),
				__( 'The conversation cards followed your computer\'s dark-mode setting while the rest of wp-admin — and the text inside those cards — stayed light, so you got near-black text on a near-black card. wp-admin does not follow that setting, so VibeSnip no longer does either.', 'vibesnip' ),
				'',
			),
			array(
				__( 'What the AI writes: one snippet that works, not two that half-work', 'vibesnip' ),
				__( 'It is now told to aim for a single snippet that completely does the job, and to write the simplest code that fully works — no invented settings screens, no configurability nobody asked for, and equally no shortcuts or placeholders. Asking for a customizable admin greeting used to come back as two snippets, one of which did nothing at all without the other.', 'vibesnip' ),
				__( 'Genuinely separate features still come back as separate snippets — but only when each one does something useful on its own.', 'vibesnip' ),
			),
			array(
				__( 'Use the AI provider you already pay for', 'vibesnip' ),
				__( 'VibeSnip now works with OpenAI and Google Gemini as well as Anthropic. Pick one on the Settings page and paste that vendor’s key. Your key, model and endpoint are remembered separately for each provider, so switching between them to compare does not throw the other one away.', 'vibesnip' ),
				__( 'Only the provider you have selected is ever contacted, and nothing is sent anywhere until you add a key and click a button.', 'vibesnip' ),
			),
			array(
				__( 'Any OpenAI-compatible gateway, and a Test connection button', 'vibesnip' ),
				__( 'Each provider has an optional custom endpoint. The OpenAI adapter speaks the standard chat-completions format, so pointing it at OpenRouter, Groq, Together, Azure OpenAI or a local Ollama works with no extra setup. And “Test connection” now tells you straight away whether your key works, rather than letting you find out minutes later in the middle of composing.', 'vibesnip' ),
				__( 'If you do point the endpoint at a gateway, your prompts and snippet code go to that host instead, under its terms rather than the provider’s.', 'vibesnip' ),
			),
			array(
				__( 'AI Compose asks before it writes, and saves nothing until you say so', 'vibesnip' ),
				__( 'Describe what you want and it tells you what it understood, how it would build it, what it would write and what it had to assume — with no code yet. Approve it and it writes the snippet; tell it that it got the wrong end of the stick and you can rephrase, with your earlier wording kept so the second attempt is a correction rather than a fresh start. What it writes is shown with its name, its language, what it does and a safety review, and only then is there a Save button.', 'vibesnip' ),
				__( 'Nothing reaches your snippets list unless you save it, and what you save still arrives inactive.', 'vibesnip' ),
			),
			array(
				__( 'The Permissions screen explains itself', 'vibesnip' ),
				__( 'It used to look like an empty area: the only tickable box was already ticked and every other cell was a bare dash. Each checkbox now has a label you can click, the Administrator row reads “Always on” rather than looking broken, and cells that cannot be granted say “Not eligible” and explain what makes a role eligible.', 'vibesnip' ),
				__( 'The rule itself has not changed. Managing snippets means running code on your site, so it stays with roles that can already administer it.', 'vibesnip' ),
			),
			array(
				__( 'An optional donation, and an off switch for it', 'vibesnip' ),
				__( 'There is now a small donation link at the bottom of the main screens. VibeSnip is free, GPL-licensed, and every feature stays free whether or not you ever use it.', 'vibesnip' ),
				__( 'If you would rather not be asked, tick “Hide the donation footer” under Settings → General once and it disappears from every screen.', 'vibesnip' ),
			),
			array(
				__( 'Test and Preview are gone', 'vibesnip' ),
				__( 'The Test button needed your site to make an HTTP request to itself, which times out on any host that serves one request at a time — so it reported a 25-second timeout instead of a result. Preview could only ever show a placeholder for PHP, which is what most snippets are. Both are removed rather than left half-working, and Ask AI now has the editor heading bar to itself.', 'vibesnip' ),
				__( 'Nothing about safety changed: PHP is still syntax-checked before it can activate, and a snippet that fatals is still switched off automatically.', 'vibesnip' ),
			),
			array(
				__( 'Easier to read at a glance', 'vibesnip' ),
				__( 'The editor heading bar is larger, and PHP and CSS no longer share a near-identical blue — PHP is violet, CSS is teal.', 'vibesnip' ),
				'',
			),
			array(
				__( 'Multisite: PHP is Super Admin only', 'vibesnip' ),
				__( 'On a multisite network, only a Super Admin can create, edit, test or activate PHP snippets, because a PHP snippet runs as site code. It is the same reason WordPress disables the plugin and theme file editors on a network. CSS, JavaScript, HTML and text snippets are unaffected, and PHP snippets that are already active keep running.', 'vibesnip' ),
				__( 'Single-site installs are completely unchanged.', 'vibesnip' ),
			),
			array(
				__( 'Permissions are harder to get wrong', 'vibesnip' ),
				__( 'The role matrix no longer offers snippet management to roles that cannot already administer the site. Managing snippets means running code, so handing it to a subscriber or contributor would give them more power than their role is meant to have.', 'vibesnip' ),
				__( 'If an earlier version granted it to such a role, saving permissions once removes it.', 'vibesnip' ),
			),
			array(
				__( 'Ask AI', 'vibesnip' ),
				__( 'Found a snippet online and cannot read code? Open it in the editor and click Ask AI. You get a plain-English report: a safe, caution or not-safe assessment, what the code does, what it changes on your site, the specific risks, and what to check after switching it on. One-click chips fill in its suggested name, type, location and tags.', 'vibesnip' ),
				__( 'It only ever reports. The PHP syntax check is still the only thing that decides whether a snippet may activate, and the report never claims your code compiles.', 'vibesnip' ),
			),
			array(
				__( 'Permissions', 'vibesnip' ),
				__( 'Settings now has a role-by-role matrix for “Manage snippets” and “Use AI features”. Hand the site to an editor or author for content work and they cannot touch snippets or spend AI credit.', 'vibesnip' ),
				__( 'Administrators always keep “Manage snippets”, so you cannot lock yourself out.', 'vibesnip' ),
			),
			array(
				__( 'Settings, reorganised', 'vibesnip' ),
				__( 'General, Permissions and Debug tabs. General adds which save button is primary, a default sort order for your snippets, and an opt-in “delete everything on uninstall”. Debug adds “Upgrade database tables” and “Reset caches”.', 'vibesnip' ),
				'',
			),
			array(
				__( 'Two fixes worth knowing about', 'vibesnip' ),
				__( 'Database changes shipped in an update now actually reach sites that update in place — previously new columns only arrived on a fresh activation. And the “delete all data on uninstall” option had no way to be switched on; it is now the checkbox on Settings → General.', 'vibesnip' ),
				'',
			),
		);
		?>
		<div class="wrap vibesnip-wrap">
			<?php // The one screen with room for the full mark, pulse line and all. ?>
			<img class="vibesnip-hero-mark" src="<?php echo esc_url( VibeSnip_Brand::mark_url() ); ?>" alt="" width="80" height="80" />
			<h1>
				<?php
				/* translators: %s: plugin version. */
				printf( esc_html__( "What's new in VibeSnip %s", 'vibesnip' ), esc_html( VIBESNIP_VERSION ) );
				?>
			</h1>
			<?php foreach ( $items as $item ) : ?>
				<div class="vibesnip-panel" style="max-width:75ch;">
					<h2><?php echo esc_html( $item[0] ); ?></h2>
					<p><?php echo esc_html( $item[1] ); ?></p>
					<?php if ( '' !== $item[2] ) : ?>
						<p class="vibesnip-microcopy"><?php echo esc_html( $item[2] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
			<?php VibeSnip_Donate::render(); ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Assets                                                             */
	/* ------------------------------------------------------------------ */

	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'vibesnip' ) ) {
			return;
		}

		wp_enqueue_style( 'vibesnip-admin', VIBESNIP_URL . 'admin/css/admin.css', array(), VIBESNIP_VERSION );

		if ( false !== strpos( (string) $hook, 'vibesnip-compose' ) ) {
			$this->compose->assets();
		}

		if ( false !== strpos( (string) $hook, 'vibesnip-settings' ) ) {
			wp_enqueue_script( 'vibesnip-settings', VIBESNIP_URL . 'admin/js/settings.js', array( 'jquery' ), VIBESNIP_VERSION, true );
			wp_localize_script(
				'vibesnip-settings',
				'VibeSnipSettings',
				array(
					'nonce' => wp_create_nonce( 'vibesnip_test_connection' ),
					'i18n'  => array(
						'testing'       => __( 'Testing…', 'vibesnip' ),
						'failed'        => __( 'The test could not be completed.', 'vibesnip' ),
						'loadingModels' => __( 'Asking the provider…', 'vibesnip' ),
					),
				)
			);
		}

		// Only the editor screen needs CodeMirror.
		if ( false !== strpos( (string) $hook, 'vibesnip-edit' ) ) {
			// Enqueue for PHP: CodeMirror's PHP mode pulls in clike, css,
			// javascript, htmlmixed and xml, so every snippet type has a mode.
			$cm = wp_enqueue_code_editor( array( 'type' => 'application/x-httpd-php' ) );

			wp_enqueue_script( 'vibesnip-admin', VIBESNIP_URL . 'admin/js/admin.js', array( 'jquery', 'wp-codemirror', 'code-editor' ), VIBESNIP_VERSION, true );

			// Conditions builder config: site-specific roles + public post types.
			$roles = array();
			foreach ( wp_roles()->roles as $slug => $role ) {
				$roles[ $slug ] = $role['name'];
			}
			$post_types = array();
			foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $slug => $obj ) {
				$post_types[ $slug ] = isset( $obj->labels->singular_name ) ? $obj->labels->singular_name : $obj->label;
			}

			$edit_id  = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which snippet to load into the editor. Cast to int, changes no state.
			$existing = null;
			if ( $edit_id ) {
				$edit_snippet = $this->repo->get( $edit_id );
				if ( $edit_snippet ) {
					$existing = VibeSnip_Conditions::decode( $edit_snippet->conditions );
				}
			}

			wp_localize_script(
				'vibesnip-admin',
				'VibeSnip',
				array(
					'cm'             => $cm ? $cm : false,
					'modes'          => array(
						'php'  => 'application/x-httpd-php',
						'css'  => 'text/css',
						'js'   => 'text/javascript',
						'html' => 'htmlmixed',
						'text' => 'text/plain',
					),
					'conditions'     => VibeSnip_Conditions::ui_config( $roles, $post_types ),
					'conditionsData' => $existing ? $existing : null,
					'askNonce'       => wp_create_nonce( 'vibesnip_ask_ai' ),
					'askTips'        => VibeSnip_AI::ask_tips(),
					'i18n'           => array(
						'asking'    => __( 'Asking AI…', 'vibesnip' ),
						'ask'       => __( 'Ask AI', 'vibesnip' ),
						'failed'    => __( 'The review could not be completed.', 'vibesnip' ),
						'saveFirst' => __( 'Save the snippet first, then ask AI to review it.', 'vibesnip' ),
						'applied'   => __( 'Applied', 'vibesnip' ),
						'safe'      => __( 'Looks safe', 'vibesnip' ),
						'caution'   => __( 'Use caution', 'vibesnip' ),
						'unsafe'    => __( 'Not safe', 'vibesnip' ),
						'risks'     => __( 'Risks', 'vibesnip' ),
						'impact'    => __( 'Effect on your site', 'vibesnip' ),
						'does'      => __( 'What it does', 'vibesnip' ),
						'notes'     => __( 'After you switch it on', 'vibesnip' ),
						'when'      => __( 'When it should run', 'vibesnip' ),
						'apply'     => __( 'Apply', 'vibesnip' ),
						'advisory'  => __( 'AI assessment — an opinion, not a syntax check.', 'vibesnip' ),
					),
				)
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Pages                                                              */
	/* ------------------------------------------------------------------ */

	public function render_manage() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		require_once VIBESNIP_DIR . 'admin/class-vibesnip-list-table.php';
		$table = new VibeSnip_List_Table( $this->repo );
		$table->prepare_items();
		$counts   = $this->repo->counts();
		$add_url  = admin_url( 'admin.php?page=vibesnip-edit' );
		?>
		<div class="wrap vibesnip-wrap">
			<h1 class="wp-heading-inline"><?php VibeSnip_Brand::heading_logo(); ?>VibeSnip</h1>
			<a href="<?php echo esc_url( $add_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add Snippet', 'vibesnip' ); ?></a>
			<p class="vibesnip-tagline"><?php esc_html_e( 'Add PHP, CSS, JS, HTML and text snippets safely — validated before they run, auto-deactivated if they ever fatal.', 'vibesnip' ); ?></p>
			<?php if ( $counts['error'] > 0 ) : ?>
				<div class="notice notice-error inline"><p>
					<?php
					/* translators: %d: number of snippets. */
					echo esc_html( sprintf( _n( '%d snippet was auto-deactivated after an error. Open it to see the details.', '%d snippets were auto-deactivated after errors. Open them to see the details.', $counts['error'], 'vibesnip' ), $counts['error'] ) );
					?>
				</p></div>
			<?php endif; ?>
			<form method="get">
				<input type="hidden" name="page" value="vibesnip" />
				<?php
				$table->search_box( __( 'Search snippets', 'vibesnip' ), 'vibesnip-search' );
				$table->views();
				$table->display();
				?>
			</form>
			<?php VibeSnip_Donate::render(); ?>
		</div>
		<?php
	}

	public function render_edit() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$id      = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which snippet to display. Cast to int, changes no state.
		$snippet = $id ? $this->repo->get( $id ) : new VibeSnip_Snippet();
		if ( $id && ! $snippet ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Snippet not found.', 'vibesnip' ) . '</p></div>';
			return;
		}
		$this->restore_stash( $snippet, $id );
		$revisions = $id ? $this->repo->get_revisions( $id, 10 ) : array();
		require VIBESNIP_DIR . 'admin/views/edit.php';
	}

	/**
	 * Format a stored timestamp the way the rest of wp-admin does.
	 *
	 * Every timestamp VibeSnip writes comes from `current_time( 'mysql' )`, which
	 * is site-local — the same convention core uses for `post_date`. So the value
	 * is read back in the site timezone and printed in the site's own date and
	 * time formats, rather than a format hardcoded here. A site left on the
	 * WordPress default of UTC will therefore show UTC; that is the site's setting
	 * showing through, not a bug, which is what `timezone_note()` explains.
	 *
	 * @param string $mysql    A `Y-m-d H:i:s` timestamp in site time.
	 * @param bool   $with_year Include the year (long form).
	 * @return string
	 */
	public static function format_time( $mysql, $with_year = true ) {
		if ( empty( $mysql ) ) {
			return '';
		}
		$date = $with_year ? get_option( 'date_format', 'M j, Y' ) : 'M j';
		return (string) mysql2date( $date . ' ' . get_option( 'time_format', 'H:i' ), $mysql );
	}

	/**
	 * One line saying which clock these timestamps are on, and where to change it.
	 *
	 * WordPress has one timezone per site and none per user, so a site left on the
	 * default (UTC) shows times that do not match the wall clock of whoever is
	 * reading them. Naming the timezone turns "the date is wrong" into a setting
	 * they can go and fix.
	 *
	 * @return string
	 */
	public static function timezone_note() {
		$tz = get_option( 'timezone_string' );
		if ( ! $tz ) {
			$offset = (float) get_option( 'gmt_offset', 0 );
			$tz     = $offset ? sprintf( 'UTC%+g', $offset ) : 'UTC';
		}
		/* translators: %s: the site's timezone, e.g. "UTC" or "Asia/Dubai". */
		return sprintf( __( 'Times are shown in your site timezone (%s), which you set under Settings → General.', 'vibesnip' ), $tz );
	}

	/**
	 * Put a refused submission back in the editor, so the refusal costs nothing.
	 *
	 * Read once and dropped: leaving it behind would resurrect old typing the next
	 * time the same snippet was opened. Only applies to the row it came from.
	 *
	 * @param VibeSnip_Snippet $snippet Snippet being rendered, modified in place.
	 * @param int              $id      Snippet id currently open.
	 */
	private function restore_stash( $snippet, $id ) {
		$key   = self::STASH . get_current_user_id();
		$stash = get_transient( $key );
		if ( ! is_array( $stash ) ) {
			return;
		}
		delete_transient( $key );

		if ( (int) $id !== (int) ( isset( $stash['snippet_id'] ) ? $stash['snippet_id'] : 0 ) ) {
			return;
		}
		foreach ( array( 'title', 'description', 'code', 'type', 'location', 'priority', 'tags', 'conditions' ) as $field ) {
			if ( isset( $stash[ $field ] ) ) {
				$snippet->$field = $stash[ $field ];
			}
		}
	}

	public function render_library() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$grouped = VibeSnip_Library::by_category();
		$show_cloud = empty( self::prefs()['hide_upgrade_notices'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
		$lib_tab = isset( $_GET['tab'] ) && 'private' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ? 'private' : 'bundled';
		?>
		<div class="wrap vibesnip-wrap vibesnip-library-page">
			<h1><?php VibeSnip_Brand::heading_logo(); ?><?php esc_html_e( 'Snippet Library', 'vibesnip' ); ?></h1>

			<?php if ( $show_cloud ) : ?>
				<nav class="nav-tab-wrapper vibesnip-tabs">
					<a class="nav-tab <?php echo 'bundled' === $lib_tab ? 'nav-tab-active' : ''; ?>"
						href="<?php echo esc_url( admin_url( 'admin.php?page=vibesnip-library' ) ); ?>"><?php esc_html_e( 'VibeSnip Library', 'vibesnip' ); ?></a>
					<a class="nav-tab <?php echo 'private' === $lib_tab ? 'nav-tab-active' : ''; ?>"
						href="<?php echo esc_url( admin_url( 'admin.php?page=vibesnip-library&tab=private' ) ); ?>"><?php esc_html_e( 'Private Library', 'vibesnip' ); ?></a>
				</nav>
			<?php endif; ?>

			<?php
			if ( 'private' === $lib_tab && $show_cloud ) {
				$this->render_library_private();
				VibeSnip_Donate::render();
				echo '</div>';
				return;
			}
			?>

			<p class="vibesnip-tagline"><?php esc_html_e( 'Vetted, ready-made snippets that ship with VibeSnip. Add one and it lands in your list as inactive — review it, then switch it on. Replace a handful of plugins with a few of these.', 'vibesnip' ); ?></p>

			<?php foreach ( $grouped as $category => $items ) : ?>
				<h2 class="vibesnip-lib-cat"><?php echo esc_html( $category ); ?></h2>
				<div class="vibesnip-lib-grid">
					<?php foreach ( $items as $key => $item ) : ?>
						<?php
						$add_url = wp_nonce_url(
							add_query_arg(
								array(
									'page'      => 'vibesnip-library',
									'vs_action' => 'library_add',
									'lib'       => $key,
								),
								admin_url( 'admin.php' )
							),
							'vibesnip_library_' . $key
						);
						?>
						<div class="vibesnip-lib-card">
							<div class="vibesnip-lib-head">
								<span class="vs-lang vs-lang-<?php echo esc_attr( $item['type'] ); ?>"><?php echo esc_html( strtoupper( $item['type'] ) ); ?></span>
								<h3><?php echo esc_html( $item['title'] ); ?></h3>
							</div>
							<p class="vibesnip-lib-desc"><?php echo esc_html( $item['description'] ); ?></p>
							<div class="vibesnip-lib-foot">
								<span class="vs-muted"><?php echo esc_html( VibeSnip_Snippet::location_label( $item['location'] ) ); ?></span>
								<a href="<?php echo esc_url( $add_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Use snippet', 'vibesnip' ); ?></a>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
			<?php VibeSnip_Donate::render(); ?>
		</div>
		<?php
	}

	/**
	 * The Private Library tab.
	 *
	 * A description of the optional VibeSnip Cloud add-on. There is deliberately
	 * no connector here: this plugin contains no cloud code and makes no external
	 * request other than the AI calls disclosed in readme.txt. Everything on this
	 * screen is static text.
	 */
	private function render_library_private() {
		?>
		<p class="vibesnip-tagline"><?php esc_html_e( 'A private library keeps your own snippets against your account instead of only in this site\'s database — so the ones you write here are available on every site you work on.', 'vibesnip' ); ?></p>
		<div class="vibesnip-panel" style="max-width:70ch;">
			<h2><?php esc_html_e( 'VibeSnip Cloud', 'vibesnip' ); ?></h2>
			<p><?php esc_html_e( 'An optional paid add-on, installed separately. It adds:', 'vibesnip' ); ?></p>
			<ul class="vibesnip-review-risks">
				<li><?php esc_html_e( 'Your own private library of snippets, synced across every site you license.', 'vibesnip' ); ?></li>
				<li><?php esc_html_e( 'AI without supplying an API key — metered, no key to manage.', 'vibesnip' ); ?></li>
				<li><?php esc_html_e( 'Snippet testing that runs off-site, so it works on hosts that block loopback requests.', 'vibesnip' ); ?></li>
				<li><?php esc_html_e( 'Cross-site history: who changed what, on which site.', 'vibesnip' ); ?></li>
			</ul>
			<p class="vibesnip-microcopy"><?php esc_html_e( 'Everything else in VibeSnip — the snippet engine, the safety loop, revisions, Safe Mode and bring-your-own-key AI — is free and always will be. You can turn this tab off under Settings → General.', 'vibesnip' ); ?></p>
		</div>
		<?php
	}

	public function render_import() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$post_url = admin_url( 'admin-post.php' );
		?>
		<div class="wrap vibesnip-wrap vibesnip-import-page">
			<h1><?php VibeSnip_Brand::heading_logo(); ?><?php esc_html_e( 'Import Snippets', 'vibesnip' ); ?></h1>
			<p class="vibesnip-tagline"><?php esc_html_e( 'Moving from WPCode or Code Snippets? Upload their JSON export file. Everything comes in inactive, so you can review each snippet before switching it on.', 'vibesnip' ); ?></p>

			<?php
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['imported'] ) ) {
				$n = (int) $_GET['imported'];
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: %d: number of snippets imported. */
							_n( 'Imported %d snippet as inactive. Review and activate it below.', 'Imported %d snippets as inactive. Review and activate them.', $n, 'vibesnip' ),
							$n
						)
					)
				);
			} elseif ( isset( $_GET['import_error'] ) ) {
				$errors = array(
					'nofile'          => __( 'Please choose a file to import.', 'vibesnip' ),
					'toobig'          => __( 'That file is too large (max 2 MB).', 'vibesnip' ),
					'unreadable'      => __( 'That file could not be read from the server.', 'vibesnip' ),
					'php_denied'      => VibeSnip_Guard::author_denied_message(),
					'vibesnip_invalid' => __( 'That file is not valid JSON.', 'vibesnip' ),
					'vibesnip_empty'  => __( 'No snippets were found in that file.', 'vibesnip' ),
				);
				$code = sanitize_key( wp_unslash( $_GET['import_error'] ) );
				$msg  = isset( $errors[ $code ] ) ? $errors[ $code ] : __( 'The import could not be completed.', 'vibesnip' );
				printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $msg ) );
			}
			// phpcs:enable
			?>

			<div class="vibesnip-panel vibesnip-import-card">
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="vibesnip_import" />
					<?php wp_nonce_field( 'vibesnip_import' ); ?>
					<label class="vibesnip-field-label" for="vibesnip-import-file"><?php esc_html_e( 'Export file (.json)', 'vibesnip' ); ?></label>
					<input type="file" name="import_file" id="vibesnip-import-file" accept=".json,application/json" required />
					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Import snippets', 'vibesnip' ); ?></button>
					</p>
					<p class="vibesnip-microcopy"><?php esc_html_e( 'Supported: WPCode and Code Snippets JSON exports. Scopes are mapped to VibeSnip locations; anything unrecognised falls back to a safe default.', 'vibesnip' ); ?></p>
				</form>
			</div>
			<?php VibeSnip_Donate::render(); ?>
		</div>
		<?php
	}

	public function render_compose() {
		$this->compose->render();
	}

	/**
	 * Site preferences (not AI settings — those live on VibeSnip_AI).
	 *
	 * @return array
	 */
	public static function prefs() {
		$defaults = array(
			'hide_upgrade_notices' => false,
			'hide_donate'          => false,
			'complete_uninstall'   => false,
			'activate_by_default'  => true,
			'list_order'           => 'updated_at',
		);
		return wp_parse_args( get_option( self::PREFS, array() ), $defaults );
	}

	/**
	 * Settings tabs.
	 *
	 * @return array slug => label
	 */
	private function settings_tabs() {
		return array(
			'general'     => __( 'General', 'vibesnip' ),
			'permissions' => __( 'Permissions', 'vibesnip' ),
			'debug'       => __( 'Debug', 'vibesnip' ),
		);
	}

	public function render_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tabs = $this->settings_tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}
		?>
		<div class="wrap vibesnip-wrap">
			<h1><?php VibeSnip_Brand::heading_logo(); ?><?php esc_html_e( 'VibeSnip Settings', 'vibesnip' ); ?></h1>
			<nav class="nav-tab-wrapper vibesnip-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab <?php echo $slug === $tab ? 'nav-tab-active' : ''; ?>"
						href="<?php echo esc_url( admin_url( 'admin.php?page=vibesnip-settings&tab=' . $slug ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			if ( 'permissions' === $tab ) {
				$this->render_settings_permissions();
			} elseif ( 'debug' === $tab ) {
				$this->render_settings_debug();
			} else {
				$this->render_settings_general();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Permissions tab: a role x capability matrix backed by real WordPress caps.
	 */
	private function render_settings_permissions() {
		$caps      = VibeSnip_Activator::capabilities();
		$roles     = wp_roles();
		$grantable = 0;
		foreach ( array_keys( $roles->roles ) as $slug ) {
			if ( self::role_may_hold_caps( $slug ) && 'administrator' !== $slug ) {
				++$grantable;
			}
		}
		?>
		<p class="vibesnip-tagline"><?php esc_html_e( 'Who may use VibeSnip. These are real WordPress capabilities, so they apply everywhere — the dashboard, the REST API and agent access alike. Administrators always keep "Manage snippets" so you cannot lock yourself out.', 'vibesnip' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="vibesnip_permissions" />
			<?php wp_nonce_field( 'vibesnip_permissions' ); ?>
			<table class="widefat striped vibesnip-perms">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Role', 'vibesnip' ); ?></th>
						<?php foreach ( $caps as $label ) : ?>
							<th><?php echo esc_html( $label ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $roles->roles as $role_slug => $role_info ) : ?>
					<?php
					$role     = get_role( $role_slug );
					$eligible = self::role_may_hold_caps( $role_slug );
					?>
					<tr>
						<td><strong><?php echo esc_html( translate_user_role( $role_info['name'] ) ); ?></strong></td>
						<?php foreach ( $caps as $cap => $label ) : ?>
							<?php
							$locked = ( 'administrator' === $role_slug && 'manage_vibesnip' === $cap );
							$id     = 'vs-cap-' . $role_slug . '-' . str_replace( '_', '-', $cap );
							?>
							<td>
								<?php if ( ! $eligible ) : ?>
									<span class="vs-perm-na"><?php esc_html_e( 'Not eligible', 'vibesnip' ); ?></span>
								<?php elseif ( $locked ) : ?>
									<span class="vs-perm-locked">
										<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" checked="checked" disabled="disabled" />
										<label for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Always on', 'vibesnip' ); ?></label>
									</span>
									<input type="hidden" name="caps[<?php echo esc_attr( $role_slug ); ?>][]" value="<?php echo esc_attr( $cap ); ?>" />
								<?php else : ?>
									<span class="vs-perm-box">
										<input type="checkbox"
											id="<?php echo esc_attr( $id ); ?>"
											name="caps[<?php echo esc_attr( $role_slug ); ?>][]"
											value="<?php echo esc_attr( $cap ); ?>"
											<?php checked( $role && $role->has_cap( $cap ) ); ?> />
										<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
									</span>
								<?php endif; ?>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save permissions', 'vibesnip' ); ?></button></p>
		</form>
		<div class="vibesnip-panel vibesnip-perms-note">
			<h2><?php esc_html_e( 'Why most roles say “Not eligible”', 'vibesnip' ); ?></h2>
			<p><?php esc_html_e( 'Managing snippets means running code on your site, so VibeSnip only offers these permissions to a role that can already administer the site — one that holds the “manage_options” capability. On a standard WordPress install that is Administrator and nothing else, which is why Editor, Author, Contributor and Subscriber are shown as not eligible. Granting snippet management to a lower role would let that account write PHP that gives itself anything else.', 'vibesnip' ); ?></p>
			<p><?php esc_html_e( '“Use AI features” follows the same rule, because every AI screen also requires “Manage snippets” — on its own it would grant nothing.', 'vibesnip' ); ?></p>
			<?php if ( 0 === $grantable ) : ?>
				<p><?php esc_html_e( 'To let somebody other than an administrator use VibeSnip, give them a role that can administer the site — either make them an Administrator, or add a custom role that holds “manage_options” with a role editor. Any such role appears here with real checkboxes automatically.', 'vibesnip' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					printf(
						/* translators: %d: number of non-administrator roles that may hold these capabilities. */
						esc_html( _n( '%d other role on this site can administer it, so it is offered these permissions above.', '%d other roles on this site can administer it, so they are offered these permissions above.', $grantable, 'vibesnip' ) ),
						(int) $grantable
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<p class="vibesnip-microcopy"><?php esc_html_e( 'Hand the site to an editor or author for content work and, with these unchecked, they cannot touch snippets or spend AI credit.', 'vibesnip' ); ?></p>
		<?php
	}

	/**
	 * Debug tab: maintenance actions that reduce support load.
	 */
	private function render_settings_debug() {
		global $wpdb;
		$table   = VibeSnip_DB::snippets();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is $wpdb->prefix, never user input; schema introspection cannot be prepared or cached.
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		$missing = array_diff( array( 'ai_review', 'ai_review_at' ), (array) $columns );
		?>
		<p class="vibesnip-tagline"><?php esc_html_e( 'Maintenance actions. Safe to run at any time — none of them touch your snippet code.', 'vibesnip' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="vibesnip_debug" />
			<?php wp_nonce_field( 'vibesnip_debug' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Database tables', 'vibesnip' ); ?></th>
					<td>
						<button type="submit" name="vs_debug" value="upgrade_db" class="button"><?php esc_html_e( 'Upgrade database tables', 'vibesnip' ); ?></button>
						<p class="description">
							<?php
							if ( $missing ) {
								echo esc_html__( 'One or more columns from a newer release are missing. Run this to add them.', 'vibesnip' );
							} else {
								echo esc_html__( 'Tables are up to date. Running this again is harmless.', 'vibesnip' );
							}
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Caches', 'vibesnip' ); ?></th>
					<td>
						<button type="submit" name="vs_debug" value="reset_caches" class="button"><?php esc_html_e( 'Reset caches', 'vibesnip' ); ?></button>
						<p class="description"><?php esc_html_e( 'Clears VibeSnip transients, including any left behind by the sandbox testing that earlier versions used.', 'vibesnip' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Versions', 'vibesnip' ); ?></th>
					<td>
						<p class="description">
							<?php
							printf(
								/* translators: 1: plugin version, 2: stored database version. */
								esc_html__( 'Plugin %1$s · database schema %2$s', 'vibesnip' ),
								esc_html( VIBESNIP_VERSION ),
								esc_html( get_option( 'vibesnip_db_version', '—' ) )
							);
							?>
						</p>
					</td>
				</tr>
			</table>
		</form>
		<?php
	}

	private function render_settings_general() {
		$settings = VibeSnip_AI::settings();
		$prefs    = self::prefs();
		$post_url = admin_url( 'admin-post.php' );
		$has_key  = ! empty( $settings['api_key'] );
		?>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>">
				<input type="hidden" name="action" value="vibesnip_settings" />
				<?php wp_nonce_field( 'vibesnip_settings' ); ?>

				<?php
				/*
				 * AI is opt-in, and the opt-in is where the risks are stated. Everything
				 * below this switch — provider, key, model, endpoint — is hidden until
				 * it is on, so an owner who never wants AI is never asked for a key and
				 * this plugin never contacts anyone on their behalf.
				 */
				$ai_on = ! empty( $settings['ai_enabled'] );
				?>
				<h2 class="vibesnip-section-h"><?php esc_html_e( 'AI features', 'vibesnip' ); ?></h2>
				<div class="vibesnip-ai-gate<?php echo $ai_on ? ' is-on' : ''; ?>">
					<p class="vibesnip-gate-lead">
						<?php esc_html_e( 'VibeSnip manages snippets perfectly well without AI, and ships with AI switched off. Turn it on and two extra features appear: AI Compose, which writes a snippet from a plain-English description, and Ask AI, which explains a snippet you already have. Both need an API key from a provider you pay directly.', 'vibesnip' ); ?>
					</p>

					<div class="vibesnip-gate-risks">
						<h3><?php esc_html_e( 'Before you switch this on', 'vibesnip' ); ?></h3>
						<ul>
							<?php foreach ( VibeSnip_AI::risk_points() as $risk ) : ?>
								<li><?php echo esc_html( $risk ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>

					<p class="vibesnip-gate-choice">
						<label>
							<input type="checkbox" name="ai_enabled" id="vibesnip-ai-enabled" value="1" <?php checked( $ai_on ); ?> />
							<strong><?php esc_html_e( 'Enable AI features on this site', 'vibesnip' ); ?></strong>
						</label>
					</p>
					<p class="vibesnip-gate-choice" id="vibesnip-ai-consent-row" <?php echo $ai_on ? 'hidden' : ''; ?>>
						<label>
							<input type="checkbox" name="ai_consent" id="vibesnip-ai-consent" value="1" />
							<?php esc_html_e( 'I have read the points above and accept that AI-written code runs on my site at my own risk.', 'vibesnip' ); ?>
						</label>
					</p>
					<?php if ( $ai_on && ! empty( $settings['ai_consent_at'] ) ) : ?>
						<p class="vibesnip-microcopy">
							<?php
							/* translators: %s: date and time the risks were accepted. */
							printf( esc_html__( 'Accepted on %s. Unticking the box above switches AI off again and hides these settings.', 'vibesnip' ), esc_html( self::format_time( $settings['ai_consent_at'] ) ) );
							?>
						</p>
					<?php endif; ?>
				</div>

				<div class="vibesnip-ai-settings" <?php echo $ai_on ? '' : 'hidden'; ?>>
				<p class="vibesnip-tagline"><?php esc_html_e( 'Connect an AI provider for AI Compose and Ask AI. Bring your own key — it is encrypted on your own site, sent only to the provider you select, and used only for your own requests.', 'vibesnip' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vibesnip-provider"><?php esc_html_e( 'Provider', 'vibesnip' ); ?></label></th>
						<td>
							<select name="provider" id="vibesnip-provider">
								<?php foreach ( VibeSnip_Provider::providers() as $id => $class ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $settings['provider'], $id ); ?>><?php echo esc_html( $class::label() ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Your key, model and endpoint are remembered separately for each provider, so switching to compare two of them does not throw the other one away.', 'vibesnip' ); ?></p>
						</td>
					</tr>
					<?php foreach ( VibeSnip_Provider::providers() as $id => $class ) : ?>
						<?php
						$is_current  = ( $settings['provider'] === $id );
						$stored_key  = ! empty( $settings['api_keys'][ $id ] );
						$stored_mdl  = isset( $settings['models'][ $id ] ) ? (string) $settings['models'][ $id ] : '';
						$stored_end  = isset( $settings['endpoints'][ $id ] ) ? (string) $settings['endpoints'][ $id ] : '';
						// A vendor with no default address is one VibeSnip cannot guess: the
						// address and the model name are the owner's to supply, so they are
						// asked for outright rather than hidden under "Advanced".
						$own_address = ( '' === $class::default_endpoint() );
						$row_attrs   = 'class="vibesnip-provider-row" data-provider="' . esc_attr( $id ) . '"'
							. ' data-has-key="' . ( $stored_key ? '1' : '0' ) . '"' . ( $is_current ? '' : ' hidden' );
						?>
						<tr <?php echo $row_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr() above; the rest is literal markup. ?>>
							<th scope="row"><label for="vibesnip-api-key-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'API key', 'vibesnip' ); ?></label></th>
							<td>
								<?php
								/*
								 * A masked TEXT field, not a password field. Browsers and password
								 * managers offer to remember anything typed into type="password",
								 * which puts a spending credential into a second store the owner
								 * never chose — and one they can reveal with a click. A text input
								 * masked in CSS looks identical, is never offered for saving, and
								 * the data-*ignore attributes turn off the major managers that
								 * volunteer anyway. The saved key is never rendered back into the
								 * page at all, so the box can only ever expose what is being typed
								 * into it right now.
								 */
								?>
								<input type="text" name="api_key[<?php echo esc_attr( $id ); ?>]" id="vibesnip-api-key-<?php echo esc_attr( $id ); ?>"
									class="regular-text vibesnip-key-input" autocomplete="off" spellcheck="false"
									data-lpignore="true" data-1p-ignore data-bwignore data-form-type="other"
									placeholder="<?php echo $stored_key ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'vibesnip' ) : esc_attr( $class::key_hint() ); ?>" />
								<p class="description">
									<?php esc_html_e( 'Leave blank to keep the key already stored.', 'vibesnip' ); ?>
									<?php if ( $class::key_url() ) : ?>
										<a href="<?php echo esc_url( $class::key_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get a key', 'vibesnip' ); ?></a>
									<?php else : ?>
										<?php esc_html_e( 'If your gateway needs no key, type any placeholder here.', 'vibesnip' ); ?>
									<?php endif; ?>
								</p>
								<?php if ( VibeSnip_Keys::is_unreadable( isset( $settings['api_keys'][ $id ] ) ? $settings['api_keys'][ $id ] : '' ) ) : ?>
									<p class="vs-inline-err">
										<?php esc_html_e( 'A key is saved here but can no longer be decrypted — this happens when the security keys in wp-config.php are regenerated. Paste the key again to fix it.', 'vibesnip' ); ?>
									</p>
								<?php endif; ?>

								<p>
									<label for="vibesnip-model-<?php echo esc_attr( $id ); ?>"><strong><?php esc_html_e( 'Model', 'vibesnip' ); ?></strong></label><br />
									<?php if ( $class::models() ) : ?>
										<select name="model[<?php echo esc_attr( $id ); ?>]" id="vibesnip-model-<?php echo esc_attr( $id ); ?>" class="vibesnip-model-input">
											<?php foreach ( VibeSnip_AI::models( $id ) as $model_id => $model_label ) : ?>
												<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $stored_mdl, $model_id ); ?>><?php echo esc_html( $model_label ); ?></option>
											<?php endforeach; ?>
										</select>
										<button type="button" class="button button-secondary vibesnip-refresh-models"><?php esc_html_e( 'Refresh list', 'vibesnip' ); ?></button>
										<span class="vibesnip-models-result" role="status"></span>
										<?php // The dropdown is only trustworthy once the vendor has answered; say which state we are in rather than letting a stale shipped list look authoritative. ?>
										<p class="description">
											<?php if ( VibeSnip_AI::has_fetched_models( $id ) ) : ?>
												<?php esc_html_e( 'These are the models this key can actually use, as listed by the provider. Refresh after they release a new one.', 'vibesnip' ); ?>
											<?php else : ?>
												<?php esc_html_e( 'A short starter list that ships with the plugin, so it can go out of date. Add your key and click Refresh list to replace it with the models your account really has.', 'vibesnip' ); ?>
											<?php endif; ?>
										</p>
									<?php else : ?>
										<input type="text" name="model[<?php echo esc_attr( $id ); ?>]" id="vibesnip-model-<?php echo esc_attr( $id ); ?>" class="regular-text code vibesnip-model-input"
											value="<?php echo esc_attr( $stored_mdl ); ?>" placeholder="e.g. anthropic/claude-sonnet-5, llama3.1, mixtral" />
										<p class="description"><?php esc_html_e( 'The model name exactly as your gateway spells it. VibeSnip cannot list these for you — only your gateway knows what it serves.', 'vibesnip' ); ?></p>
									<?php endif; ?>
								</p>

								<?php if ( $own_address ) : ?>
									<p>
										<label for="vibesnip-endpoint-<?php echo esc_attr( $id ); ?>"><strong><?php esc_html_e( 'API address', 'vibesnip' ); ?></strong></label><br />
										<input type="url" name="endpoint[<?php echo esc_attr( $id ); ?>]" id="vibesnip-endpoint-<?php echo esc_attr( $id ); ?>" class="regular-text code vibesnip-endpoint-input"
											value="<?php echo esc_attr( $stored_end ); ?>" placeholder="https://openrouter.ai/api/v1/chat/completions" />
									</p>
									<p class="description">
										<?php esc_html_e( 'The chat-completions address of your gateway — OpenRouter, Groq, Together, Azure OpenAI, or a local Ollama or llama.cpp.', 'vibesnip' ); ?>
										<strong><?php esc_html_e( 'Your prompts and snippet code go to that host, governed by its terms and privacy policy.', 'vibesnip' ); ?></strong>
									</p>
								<?php else : ?>
									<details class="vibesnip-advanced" <?php echo $stored_end ? 'open' : ''; ?>>
										<summary><?php esc_html_e( 'Advanced: custom endpoint', 'vibesnip' ); ?></summary>
										<p>
											<input type="url" name="endpoint[<?php echo esc_attr( $id ); ?>]" class="regular-text code vibesnip-endpoint-input"
												value="<?php echo esc_attr( $stored_end ); ?>"
												placeholder="<?php echo esc_attr( $class::default_endpoint() ); ?>" />
										</p>
										<p class="description">
											<?php esc_html_e( 'Leave blank to use the provider’s own address.', 'vibesnip' ); ?>
											<strong><?php esc_html_e( 'If you point this at a third-party gateway, your prompts and snippet code go to that host instead, governed by its terms and privacy policy rather than the provider’s.', 'vibesnip' ); ?></strong>
										</p>
									</details>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Connection', 'vibesnip' ); ?></th>
						<td>
							<button type="button" class="button" id="vibesnip-test-connection" <?php disabled( ! $has_key ); ?>><?php esc_html_e( 'Test connection', 'vibesnip' ); ?></button>
							<span class="vibesnip-test-result" id="vibesnip-test-result" role="status"></span>
							<p class="description"><?php esc_html_e( 'Sends one tiny request using the provider, model and key shown above — including a key you have just typed but not saved yet — and reports which provider and model answered. It saves nothing.', 'vibesnip' ); ?></p>
						</td>
					</tr>
				</table>
				</div><?php // .vibesnip-ai-settings ?>

				<h2 class="vibesnip-section-h"><?php esc_html_e( 'General', 'vibesnip' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Editing', 'vibesnip' ); ?></th>
						<td>
							<label><input type="checkbox" name="activate_by_default" <?php checked( $prefs['activate_by_default'] ); ?> />
								<?php esc_html_e( 'Make “Save & Activate” the primary button in the editor.', 'vibesnip' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vibesnip-list-order"><?php esc_html_e( 'Snippets list order', 'vibesnip' ); ?></label></th>
						<td>
							<select name="list_order" id="vibesnip-list-order">
								<?php foreach ( self::list_orders() as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $prefs['list_order'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Default sort on the All Snippets screen. Clicking a column header still overrides it.', 'vibesnip' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Notices', 'vibesnip' ); ?></th>
						<td>
							<label><input type="checkbox" name="hide_upgrade_notices" <?php checked( $prefs['hide_upgrade_notices'] ); ?> />
								<?php esc_html_e( 'Hide notices about optional paid features.', 'vibesnip' ); ?></label>
							<p class="description"><?php esc_html_e( 'VibeSnip is fully functional without any paid add-on. Tick this and it will stop mentioning them.', 'vibesnip' ); ?></p>
							<label><input type="checkbox" name="hide_donate" <?php checked( $prefs['hide_donate'] ); ?> />
								<?php esc_html_e( 'Hide the donation footer.', 'vibesnip' ); ?></label>
							<p class="description"><?php esc_html_e( 'Removes the “Donate via PayPal” block from the bottom of every VibeSnip screen. Donating is entirely optional and nothing in the plugin is withheld either way.', 'vibesnip' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'On uninstall', 'vibesnip' ); ?></th>
						<td>
							<label><input type="checkbox" name="complete_uninstall" <?php checked( $prefs['complete_uninstall'] ); ?> />
								<?php esc_html_e( 'Delete all snippets, revisions, history and settings when the plugin is deleted.', 'vibesnip' ); ?></label>
							<p class="description"><?php esc_html_e( 'Off by default: deleting the plugin leaves your snippets in the database, so reinstalling restores them. There is no undo once this is on.', 'vibesnip' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'vibesnip' ); ?></button></p>
			</form>

			<?php $abilities_on = VibeSnip_Abilities::available(); ?>
			<hr />
			<h2><?php esc_html_e( 'Agent access (Abilities API)', 'vibesnip' ); ?></h2>
			<p class="vibesnip-tagline">
				<?php esc_html_e( 'VibeSnip registers its safe loop as WordPress abilities so any AI agent can read context, create a draft, activate it behind the guard, and roll it back — the same guarded flow this dashboard uses.', 'vibesnip' ); ?>
			</p>
			<p>
				<span class="vs-result vs-<?php echo $abilities_on ? 'ok' : 'warning'; ?>">
					<?php echo $abilities_on ? esc_html__( 'Abilities API detected — agent access is live.', 'vibesnip' ) : esc_html__( 'Abilities API not detected on this install.', 'vibesnip' ); ?>
				</span>
			</p>
			<?php if ( ! $abilities_on ) : ?>
				<p class="description"><?php esc_html_e( 'Install the WordPress Abilities API (core in recent versions, or the abilities-api feature plugin) to expose these abilities. VibeSnip works fully without it — this only affects agent access.', 'vibesnip' ); ?></p>
			<?php endif; ?>
			<p class="description">
				<?php esc_html_e( 'Registered abilities:', 'vibesnip' ); ?>
				<code>vibesnip/get-site-context</code>, <code>vibesnip/list-snippets</code>, <code>vibesnip/get-snippet</code>,
				<code>vibesnip/create-snippet</code>, <code>vibesnip/update-snippet</code>,
				<code>vibesnip/activate-snippet</code>, <code>vibesnip/deactivate-snippet</code>, <code>vibesnip/rollback-snippet</code>.
			</p>
		<?php
	}

	/**
	 * May this role be granted VibeSnip's capabilities at all?
	 *
	 * `manage_vibesnip` is the right to run arbitrary code on the site, so it is
	 * only ever offered to roles that can already administer it. Ticking it for a
	 * subscriber or contributor would be a privilege-escalation ladder: a low-trust
	 * account could write PHP that grants itself anything else.
	 *
	 * `vibesnip_use_ai` follows the same rule because every AI path also requires
	 * `manage_vibesnip` — on its own it grants nothing.
	 *
	 * @param string $role_slug Role slug.
	 * @return bool
	 */
	public static function role_may_hold_caps( $role_slug ) {
		$role = get_role( $role_slug );
		return (bool) ( $role && $role->has_cap( 'manage_options' ) );
	}

	/**
	 * Sort options for the All Snippets screen.
	 *
	 * @return array column => label
	 */
	public static function list_orders() {
		return array(
			'updated_at' => __( 'Last modified', 'vibesnip' ),
			'title'      => __( 'Name', 'vibesnip' ),
			'priority'   => __( 'Priority', 'vibesnip' ),
			'type'       => __( 'Type', 'vibesnip' ),
		);
	}

	public function render_activity() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$log = $this->repo->get_log( 100 );
		?>
		<div class="wrap vibesnip-wrap">
			<h1><?php VibeSnip_Brand::heading_logo(); ?><?php esc_html_e( 'VibeSnip Activity', 'vibesnip' ); ?></h1>
			<p class="vibesnip-tagline">
				<?php esc_html_e( 'Every create, edit, activation and auto-rollback — who did it and when.', 'vibesnip' ); ?>
				<?php echo esc_html( self::timezone_note() ); ?>
			</p>
			<table class="widefat striped vibesnip-log">
				<thead><tr>
					<th><?php esc_html_e( 'When', 'vibesnip' ); ?></th>
					<th><?php esc_html_e( 'Action', 'vibesnip' ); ?></th>
					<th><?php esc_html_e( 'Snippet', 'vibesnip' ); ?></th>
					<th><?php esc_html_e( 'By', 'vibesnip' ); ?></th>
					<th><?php esc_html_e( 'Result', 'vibesnip' ); ?></th>
					<th><?php esc_html_e( 'Detail', 'vibesnip' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $log ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No activity yet.', 'vibesnip' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $log as $row ) : ?>
						<?php
						$user  = $row->actor_id ? get_userdata( $row->actor_id ) : null;
						$badge = 'ai' === $row->actor_type ? 'ai' : 'user';
						?>
						<tr>
							<td><?php echo esc_html( self::format_time( $row->created_at ) ); ?></td>
							<td><code><?php echo esc_html( $row->action ); ?></code></td>
							<td><?php echo $row->snippet_id ? esc_html( '#' . $row->snippet_id ) : '&mdash;'; ?></td>
							<td><span class="vs-chip vs-<?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $user ? $user->display_name : $badge ); ?></span></td>
							<td><span class="vs-result vs-<?php echo esc_attr( $row->result ); ?>"><?php echo esc_html( $row->result ); ?></span></td>
							<td><?php echo esc_html( $row->message ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			<?php VibeSnip_Donate::render(); ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Handle activate / deactivate / delete / duplicate links from the list.
	 */
	public function handle_row_actions() {
		if ( ! isset( $_GET['vs_action'], $_GET['id'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage snippets.', 'vibesnip' ) );
		}
		$action = sanitize_key( wp_unslash( $_GET['vs_action'] ) );
		$id     = (int) $_GET['id'];
		check_admin_referer( 'vibesnip_' . $action . '_' . $id );

		$notice  = '';
		$snippet = $this->repo->get( $id );
		if ( ! $snippet ) {
			$this->redirect_manage( 'notfound' );
		}

		switch ( $action ) {
			case 'activate':
				if ( ! VibeSnip_Guard::can_author( $snippet->type ) ) {
					$this->redirect_manage( 'php_denied' );
				}
				$valid = VibeSnip_Guard::validate( $snippet->type, $snippet->code );
				if ( true !== $valid ) {
					$this->redirect_manage( 'invalid' );
				}
				$this->repo->set_status( $id, 'active' );
				$this->repo->record_error( $id, array() ); // clear prior error
				$notice = 'activated';
				// Dispatch a NON-BLOCKING front-end health-check. A blocking loopback
				// would hang this request on a single-threaded server / single-worker
				// host. Fired async, the loopback still runs the snippet and the guard
				// auto-deactivates a front-end fatal, which the manager then surfaces.
				if ( 'php' === $snippet->type && ! VibeSnip_Guard::is_safe_mode() ) {
					VibeSnip_Guard::health_check( $id );
				}
				break;

			case 'deactivate':
				$this->repo->set_status( $id, 'inactive' );
				$notice = 'deactivated';
				break;

			case 'duplicate':
				$this->repo->duplicate( $id );
				$notice = 'duplicated';
				break;

			case 'delete':
				$this->repo->delete( $id );
				$notice = 'deleted';
				break;
		}

		$this->redirect_manage( $notice );
	}

	/**
	 * Add a library snippet as an inactive copy, then open it in the editor.
	 */
	public function handle_library() {
		if ( ! isset( $_GET['vs_action'] ) || 'library_add' !== $_GET['vs_action'] || ! isset( $_GET['lib'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage snippets.', 'vibesnip' ) );
		}
		$key = sanitize_key( wp_unslash( $_GET['lib'] ) );
		check_admin_referer( 'vibesnip_library_' . $key );

		$item = VibeSnip_Library::get( $key );
		if ( ! $item ) {
			$this->redirect_manage( 'notfound' );
		}
		if ( ! VibeSnip_Guard::can_author( $item['type'] ) ) {
			$this->redirect_manage( 'php_denied' );
		}

		$id = $this->repo->create(
			array(
				'title'       => $item['title'],
				'description' => $item['description'],
				'code'        => $item['code'],
				'type'        => $item['type'],
				'location'    => $item['location'],
				'tags'        => $item['tags'],
				'source'      => 'library',
			)
		);

		$url = add_query_arg(
			array(
				'page'      => 'vibesnip-edit',
				'id'        => $id,
				'vs_notice' => 'from_library',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Handle the editor form submit.
	 */
	public function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage snippets.', 'vibesnip' ) );
		}
		check_admin_referer( 'vibesnip_save' );

		$id       = isset( $_POST['snippet_id'] ) ? (int) $_POST['snippet_id'] : 0;
		$activate = isset( $_POST['save_activate'] );

		$data = array(
			'title'       => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			// Raw code by design (see repository::sanitize). Snippet code is stored
			// exactly as entered and guarded by validate-before-activate + the
			// manage_vibesnip capability + a nonce, never escaped/sanitized.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- snippet code is intentionally raw; see above.
			'code'        => isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '',
			'type'        => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'php',
			'location'    => isset( $_POST['location'] ) ? sanitize_key( wp_unslash( $_POST['location'] ) ) : 'everywhere',
			'priority'    => isset( $_POST['priority'] ) ? (int) $_POST['priority'] : 10,
			'tags'        => isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : '',
		);

		// PHP is site code, not content — on multisite only a Super Admin may author it.
		// The row's CURRENT type is checked as well as the submitted one, so an
		// existing PHP snippet cannot be rewritten by retyping it on the way in.
		$existing_row = $id ? $this->repo->get( $id ) : null;
		if ( ! VibeSnip_Guard::can_author( $data['type'] )
			|| ( $existing_row && ! VibeSnip_Guard::can_author( $existing_row->type ) ) ) {
			$this->redirect_manage( 'php_denied' );
		}

		$data['conditions'] = $this->collect_conditions();

		// The name is how you find this row again. An untitled snippet lands in the
		// list as a blank line nobody recognises, which is how they get abandoned
		// rather than fixed. The title input carries `required`, so the browser
		// normally stops this before it is sent; refusing here covers the rest.
		// Nothing is discarded — the submission is parked and the editor restores it.
		if ( '' === trim( $data['title'] ) ) {
			$data['snippet_id'] = $id;
			set_transient( self::STASH . get_current_user_id(), $data, 5 * MINUTE_IN_SECONDS );
			$this->redirect_edit( $id, 'untitled' );
		}

		// Validate PHP before we let it become active.
		$notice = 'saved';
		if ( $activate ) {
			$valid = VibeSnip_Guard::validate( $data['type'], $data['code'] );
			if ( true === $valid ) {
				$data['status'] = 'active';
			} else {
				$data['status'] = 'inactive';
				$notice         = 'invalid';
				set_transient( 'vibesnip_validation_' . get_current_user_id(), $valid, 60 );
			}
		} else {
			// Preserve an existing active state only when the code still validates.
			$existing = $id ? $this->repo->get( $id ) : null;
			if ( $existing && $existing->is_active() ) {
				$valid          = VibeSnip_Guard::validate( $data['type'], $data['code'] );
				$data['status'] = ( true === $valid ) ? 'active' : 'inactive';
			} else {
				$data['status'] = 'inactive';
			}
		}

		if ( $id ) {
			$this->repo->update( $id, $data );
		} else {
			$id = $this->repo->create( $data );
		}

		// If it went live as PHP, dispatch a NON-BLOCKING front-end health-check so
		// the guard vets it without hanging the save (a blocking loopback deadlocks
		// on single-threaded servers / single-worker hosts). A front-end fatal is
		// auto-deactivated by the guard and surfaced on the manager screen.
		if ( 'active' === $data['status'] && 'php' === $data['type'] && ! VibeSnip_Guard::is_safe_mode() ) {
			VibeSnip_Guard::health_check( $id );
		}

		$url = add_query_arg(
			array(
				'page'      => 'vibesnip-edit',
				'id'        => $id,
				'vs_notice' => $notice,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Build the conditions structure from the submitted builder fields.
	 *
	 * @return array
	 */
	private function collect_conditions() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in handle_save().
		$enabled  = isset( $_POST['conditions_enabled'] );
		$relation = ( isset( $_POST['conditions_relation'] ) && 'OR' === strtoupper( sanitize_text_field( wp_unslash( $_POST['conditions_relation'] ) ) ) ) ? 'OR' : 'AND';

		$rules = array();
		if ( isset( $_POST['condition_type'] ) && is_array( $_POST['condition_type'] ) ) {
			$types = array_map( 'sanitize_key', wp_unslash( $_POST['condition_type'] ) );
			$ops   = isset( $_POST['condition_operator'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['condition_operator'] ) ) : array();
			$vals  = isset( $_POST['condition_value'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['condition_value'] ) ) : array();

			foreach ( $types as $i => $type ) {
				if ( '' === $type ) {
					continue;
				}
				$rules[] = array(
					'type'     => $type,
					'operator' => isset( $ops[ $i ] ) && '' !== $ops[ $i ] ? $ops[ $i ] : 'is',
					'value'    => isset( $vals[ $i ] ) ? $vals[ $i ] : '',
				);
			}
		}
		// phpcs:enable

		return array(
			'enabled'  => ( $enabled && ! empty( $rules ) ),
			'relation' => $relation,
			'rules'    => $rules,
		);
	}

	/**
	 * Handle a snippet-import upload from another plugin's JSON export.
	 */
	public function handle_import() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage snippets.', 'vibesnip' ) );
		}
		check_admin_referer( 'vibesnip_import' );

		$redirect = admin_url( 'admin.php?page=vibesnip-import' );

		// WPCode and Code Snippets exports are overwhelmingly PHP, so a user who may
		// not author PHP is refused outright rather than handed a part-empty import.
		if ( ! VibeSnip_Guard::can_author( 'php' ) ) {
			wp_safe_redirect( add_query_arg( 'import_error', 'php_denied', $redirect ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a file tmp path is validated by is_uploaded_file(), not sanitized as text; request is nonce-checked above.
		if ( empty( $_FILES['import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['import_file']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'import_error', 'nofile', $redirect ) );
			exit;
		}
		if ( ! empty( $_FILES['import_file']['size'] ) && $_FILES['import_file']['size'] > 2 * MB_IN_BYTES ) {
			wp_safe_redirect( add_query_arg( 'import_error', 'toobig', $redirect ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a file tmp path is validated by is_uploaded_file() above, not sanitized as text.
		$tmp = $_FILES['import_file']['tmp_name'];

		// WP_Filesystem rather than file_get_contents(): the upload is owned by the
		// PHP user, so the "direct" transport applies and no credentials are prompted.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;
		$contents = '';
		if ( WP_Filesystem() && $wp_filesystem ) {
			$contents = (string) $wp_filesystem->get_contents( $tmp );
		}
		if ( '' === $contents ) {
			wp_safe_redirect( add_query_arg( 'import_error', 'unreadable', $redirect ) );
			exit;
		}

		$result = VibeSnip_Importer::import_json( $contents );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'import_error', $result->get_error_code(), $redirect ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'imported', (int) $result, $redirect ) );
		exit;
	}

	/**
	 * Save the AI settings (provider key, model, PHP auto-apply policy).
	 */
	public function handle_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage snippets.', 'vibesnip' ) );
		}
		check_admin_referer( 'vibesnip_settings' );

		$current   = VibeSnip_AI::settings();
		$providers = VibeSnip_Provider::providers();

		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		if ( ! isset( $providers[ $provider ] ) ) {
			$provider = $current['provider'];
		}

		$keys      = is_array( $current['api_keys'] ) ? $current['api_keys'] : array();
		$models    = is_array( $current['models'] ) ? $current['models'] : array();
		$endpoints = is_array( $current['endpoints'] ) ? $current['endpoints'] : array();

		foreach ( $providers as $id => $class ) {
			// A blank key field means "keep the stored key" — it is never pre-filled,
			// so treating blank as "clear it" would wipe the key on every other save.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on the next line; the array index is validated against $providers.
			$posted = isset( $_POST['api_key'][ $id ] ) ? wp_unslash( $_POST['api_key'][ $id ] ) : '';
			$posted = trim( sanitize_text_field( $posted ) );
			if ( '' !== $posted ) {
				$keys[ $id ] = VibeSnip_Keys::encrypt( $posted );
			}

			// Whitelisted against what the dropdown actually offered, which is the
			// vendor's own list once it has been refreshed — not the shipped
			// fallback, or a model the owner just fetched could never be saved.
			$valid = $class::models() ? array_keys( VibeSnip_AI::models( $id ) ) : array();
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on the lines below, either against that whitelist or through sanitize_text_field().
			$posted_model = isset( $_POST['model'][ $id ] ) ? wp_unslash( $_POST['model'][ $id ] ) : '';
			// An adapter with no catalogue (the OpenAI-compatible one) cannot have a
			// whitelist — its models are whatever the owner's gateway serves.
			$models[ $id ] = $valid
				? ( in_array( $posted_model, $valid, true ) ? sanitize_text_field( $posted_model ) : '' )
				: trim( sanitize_text_field( $posted_model ) );

			// esc_url_raw() rather than a text sanitizer: this is a URL the request
			// will actually be sent to, so the scheme has to be validated, not tidied.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed through esc_url_raw() on the next line.
			$posted_endpoint  = isset( $_POST['endpoint'][ $id ] ) ? wp_unslash( $_POST['endpoint'][ $id ] ) : '';
			$endpoints[ $id ] = $posted_endpoint ? esc_url_raw( trim( $posted_endpoint ), array( 'http', 'https' ) ) : '';
		}

		// Switching AI ON requires ticking the acknowledgement in the same submit;
		// switching it OFF requires nothing, because nobody needs consent to stop.
		// Already-on stays on without re-consenting, or every unrelated save would
		// ask again and the acknowledgement would become something to click past.
		$was_on     = ! empty( $current['ai_enabled'] );
		$wants_on   = isset( $_POST['ai_enabled'] );
		$consented  = isset( $_POST['ai_consent'] );
		$ai_enabled = $wants_on && ( $was_on || $consented );
		$notice     = 'settings_saved';
		if ( $wants_on && ! $ai_enabled ) {
			$notice = 'ai_needs_consent';
		}

		$consent_at = ! empty( $current['ai_consent_at'] ) ? (string) $current['ai_consent_at'] : '';
		if ( $ai_enabled && ! $was_on ) {
			$consent_at = current_time( 'mysql' );
		}

		update_option(
			VibeSnip_AI::OPTION,
			array(
				'ai_enabled'    => $ai_enabled,
				'ai_consent_at' => $consent_at,
				'provider'      => $provider,
				'api_keys'      => $keys,
				'models'        => $models,
				'endpoints'     => $endpoints,
				'php_autoapply' => isset( $_POST['php_autoapply'] ),
			)
		);

		$orders = array_keys( self::list_orders() );
		update_option(
			self::PREFS,
			array(
				'hide_upgrade_notices' => isset( $_POST['hide_upgrade_notices'] ),
				'hide_donate'          => isset( $_POST['hide_donate'] ),
				'complete_uninstall'   => isset( $_POST['complete_uninstall'] ),
				'activate_by_default'  => isset( $_POST['activate_by_default'] ),
				'list_order'           => isset( $_POST['list_order'] ) && in_array( $_POST['list_order'], $orders, true ) ? sanitize_key( wp_unslash( $_POST['list_order'] ) ) : 'updated_at',
			)
		);

		wp_safe_redirect( add_query_arg( array( 'page' => 'vibesnip-settings', 'vs_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Save the role x capability matrix.
	 *
	 * Administrators always keep manage_vibesnip so the site owner cannot lock
	 * themselves out of the plugin they installed.
	 */
	public function handle_permissions() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage snippets.', 'vibesnip' ) );
		}
		check_admin_referer( 'vibesnip_permissions' );

		$all_caps = array_keys( VibeSnip_Activator::capabilities() );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is validated against $all_caps below.
		$submitted = isset( $_POST['caps'] ) && is_array( $_POST['caps'] ) ? wp_unslash( $_POST['caps'] ) : array();

		foreach ( wp_roles()->roles as $role_slug => $unused ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}
			$wanted = isset( $submitted[ $role_slug ] ) && is_array( $submitted[ $role_slug ] ) ? array_map( 'sanitize_key', $submitted[ $role_slug ] ) : array();

			foreach ( $all_caps as $cap ) {
				$grant = in_array( $cap, $wanted, true );
				// Enforced here as well as in the form: a hand-crafted POST must not be
				// able to hand snippet management — i.e. code execution — to a role that
				// cannot already administer the site. Ineligible roles are also actively
				// stripped, so a grant made by an earlier version is cleaned up on save.
				if ( ! self::role_may_hold_caps( $role_slug ) ) {
					$grant = false;
				}
				if ( 'administrator' === $role_slug && 'manage_vibesnip' === $cap ) {
					$grant = true;
				}
				if ( $grant && ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				} elseif ( ! $grant && $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'vibesnip-settings', 'tab' => 'permissions', 'vs_notice' => 'perms_saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Debug-tab maintenance actions.
	 */
	public function handle_debug() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage snippets.', 'vibesnip' ) );
		}
		check_admin_referer( 'vibesnip_debug' );

		$action = isset( $_POST['vs_debug'] ) ? sanitize_key( wp_unslash( $_POST['vs_debug'] ) ) : '';
		$notice = 'debug_done';

		if ( 'upgrade_db' === $action ) {
			VibeSnip_DB::create_tables();
			update_option( 'vibesnip_db_version', VIBESNIP_VERSION );
			$notice = 'db_upgraded';
		} elseif ( 'reset_caches' === $action ) {
			VibeSnip_DB::flush_transients();
			$notice = 'caches_reset';
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'vibesnip-settings', 'tab' => 'debug', 'vs_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Back to the editor with a notice, keeping whichever snippet was being edited.
	 *
	 * @param int    $id     Snippet id, or 0 for a new one.
	 * @param string $notice Notice key for notices().
	 */
	private function redirect_edit( $id, $notice ) {
		$args = array( 'page' => 'vibesnip-edit', 'vs_notice' => $notice );
		if ( $id ) {
			$args['id'] = (int) $id;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function redirect_manage( $notice ) {
		$url = add_query_arg(
			array(
				'page'      => 'vibesnip',
				'vs_notice' => $notice,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render success/error notices from the vs_notice query arg.
	 */
	public function notices() {
		if ( ! isset( $_GET['page'] ) || false === strpos( sanitize_key( wp_unslash( $_GET['page'] ) ), 'vibesnip' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: are we on a VibeSnip screen. Changes no state.
			return;
		}
		if ( ! isset( $_GET['vs_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: presence check for a post-redirect notice key, whitelisted against $map below.
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice key from a post-redirect GET; no state change, value is whitelisted against $map below.
		$notice = sanitize_key( wp_unslash( $_GET['vs_notice'] ) );

		$map = array(
			'saved'       => array( 'success', __( 'Snippet saved.', 'vibesnip' ) ),
			'from_library' => array( 'success', __( 'Added from the library as an inactive snippet — review it below, then Save & Activate.', 'vibesnip' ) ),
			'activated'   => array( 'success', __( 'Snippet activated.', 'vibesnip' ) ),
			'deactivated' => array( 'success', __( 'Snippet deactivated.', 'vibesnip' ) ),
			'duplicated'  => array( 'success', __( 'Snippet duplicated.', 'vibesnip' ) ),
			'deleted'     => array( 'success', __( 'Snippet deleted.', 'vibesnip' ) ),
			'settings_saved' => array( 'success', __( 'Settings saved.', 'vibesnip' ) ),
			'ai_needs_consent' => array( 'error', __( 'AI features were not switched on: the box accepting the risks was not ticked. Everything else you changed has been saved.', 'vibesnip' ) ),
			'perms_saved'    => array( 'success', __( 'Permissions saved.', 'vibesnip' ) ),
			'db_upgraded'    => array( 'success', __( 'Database tables are up to date.', 'vibesnip' ) ),
			'caches_reset'   => array( 'success', __( 'Caches cleared.', 'vibesnip' ) ),
			'debug_done'     => array( 'success', __( 'Done.', 'vibesnip' ) ),
			'rolled_back'    => array( 'error', __( 'Activated, but the health-check caught a fatal error on the front end — the snippet was automatically rolled back. Fix it and try again.', 'vibesnip' ) ),
			'untitled'    => array( 'error', __( 'Give the snippet a name before saving — an untitled snippet is impossible to find again in your list. Everything you had typed is still here.', 'vibesnip' ) ),
			'notfound'    => array( 'error', __( 'Snippet not found.', 'vibesnip' ) ),
			'php_denied'  => array( 'error', VibeSnip_Guard::author_denied_message() ),
		);

		if ( 'invalid' === $notice ) {
			$detail = get_transient( 'vibesnip_validation_' . get_current_user_id() );
			delete_transient( 'vibesnip_validation_' . get_current_user_id() );
			$msg = __( 'Saved, but not activated — the PHP has a syntax error:', 'vibesnip' );
			echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html( $msg ) . '</strong> ' . esc_html( (string) $detail ) . '</p></div>';
			return;
		}

		if ( isset( $map[ $notice ] ) ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $map[ $notice ][0] ),
				esc_html( $map[ $notice ][1] )
			);
		}
	}
}
