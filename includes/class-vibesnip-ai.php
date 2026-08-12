<?php
/**
 * The AI feature. Standalone, bring-your-own-key: builds the system prompt, the
 * user message and the JSON schema the reply must satisfy, then hands the wire
 * format to the provider adapter selected in settings (see
 * class-vibesnip-provider.php). Every call goes out over WordPress' HTTP API —
 * there is no Composer SDK to bundle.
 *
 * The whole point of the safety architecture: generate() only proposes. Nothing
 * it returns runs until a human saves it (as an inactive draft) and activates
 * it through the same validate-before-activate path as any other snippet.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

class VibeSnip_AI {

	const OPTION = 'vibesnip_settings';

	/**
	 * Provider/key/model/endpoint to use instead of the stored ones, for the
	 * duration of one call. Only test_connection() sets it, so that the button can
	 * test what is on the Settings screen rather than what was last saved — the
	 * previous behaviour reported the saved provider back at you and read as a bug.
	 *
	 * @var array
	 */
	private static $override = array();

	/** Transient prefix for a provider's fetched model list. */
	const MODELS_CACHE = 'vibesnip_models_';

	/**
	 * Selectable models for a provider, as id => label.
	 *
	 * The list the vendor gave us for this key if we have fetched one, otherwise
	 * the fallback baked into the adapter. Whatever is currently saved is always
	 * included, even if it is in neither — a model disappearing from a dropdown
	 * would silently switch the site to a different one on the next save.
	 *
	 * @param string $id Provider id; defaults to the one in use.
	 * @return array id => label
	 */
	public static function models( $id = '' ) {
		$id       = $id ? $id : self::settings()['provider'];
		$provider = VibeSnip_Provider::get( $id );

		$cached = get_transient( self::MODELS_CACHE . $id );
		$out    = is_array( $cached ) && $cached ? $cached : array();

		if ( ! $out ) {
			foreach ( $provider::models() as $model ) {
				$out[ $model['id'] ] = $model['note'];
			}
		}

		$stored = self::settings();
		$chosen = isset( $stored['models'][ $id ] ) ? (string) $stored['models'][ $id ] : '';
		if ( '' !== $chosen && ! isset( $out[ $chosen ] ) ) {
			$out = array( $chosen => $chosen ) + $out;
		}

		return $out;
	}

	/**
	 * Have we ever fetched this provider's real model list?
	 *
	 * @param string $id Provider id.
	 * @return bool
	 */
	public static function has_fetched_models( $id ) {
		return (bool) get_transient( self::MODELS_CACHE . $id );
	}

	/**
	 * Ask the vendor which models this key may actually use, and remember it.
	 *
	 * A list baked into a release is wrong within months — which is exactly how
	 * someone ends up picking a model their account has never had and reading a
	 * "model does not exist" error they cannot act on. This asks the vendor.
	 *
	 * @param array $override Provider/api_key/endpoint to use instead of the
	 *                        stored ones, so the Settings screen can refresh a key
	 *                        that has been typed but not saved yet.
	 * @return array|WP_Error id => label, or an error.
	 */
	public static function fetch_models( $override = array() ) {
		if ( ! self::is_enabled() ) {
			return self::disabled_error();
		}
		self::$override = is_array( $override ) ? array_filter( $override, 'strlen' ) : array();

		try {
			$settings = self::settings();
			$provider = VibeSnip_Provider::current();

			if ( empty( $settings['api_key'] ) ) {
				return new WP_Error( 'vibesnip_no_key', __( 'Add an API key first, then refresh the model list.', 'vibesnip' ) );
			}

			$url = $provider::models_url( (string) $settings['endpoint'] );
			if ( '' === $url ) {
				return new WP_Error(
					'vibesnip_no_model_list',
					__( 'This provider does not publish a model list. Type the model name your service uses.', 'vibesnip' )
				);
			}

			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 20,
					'headers' => $provider::headers( (string) $settings['api_key'] ),
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 !== $code ) {
				$message = is_array( $data ) ? $provider::error_message( $data ) : '';
				if ( 401 === $code || 403 === $code ) {
					$message = __( 'Your API key was rejected. Check it above.', 'vibesnip' );
				}
				/* translators: %d: HTTP status code. */
				return new WP_Error( 'vibesnip_api', $message ? $message : sprintf( __( 'The model list could not be read (HTTP %d).', 'vibesnip' ), $code ) );
			}
			if ( ! is_array( $data ) ) {
				return new WP_Error( 'vibesnip_parse', __( 'The model list could not be read.', 'vibesnip' ) );
			}

			$models = $provider::parse_models( $data );
			if ( ! $models ) {
				return new WP_Error( 'vibesnip_no_models', __( 'That provider returned no models this plugin can use.', 'vibesnip' ) );
			}

			// A week: vendors add models occasionally, not hourly, and the button is
			// there for anyone who cannot wait.
			set_transient( self::MODELS_CACHE . $settings['provider'], $models, WEEK_IN_SECONDS );

			return $models;
		} finally {
			self::$override = array();
		}
	}

	/** One wording for "AI is switched off", wherever someone runs into it. */
	private static function disabled_error() {
		return new WP_Error(
			'vibesnip_ai_off',
			__( 'AI features are switched off. Turn them on under VibeSnip → Settings → General.', 'vibesnip' )
		);
	}

	/** Drop every cached model list, so the next look asks the vendors again. */
	public static function forget_models() {
		foreach ( array_keys( VibeSnip_Provider::providers() ) as $id ) {
			delete_transient( self::MODELS_CACHE . $id );
		}
	}

	/**
	 * Current settings, plus the resolved values for the provider in use.
	 *
	 * Keys, models and endpoints are stored per provider. Switching provider to
	 * compare two vendors would otherwise silently discard the key you had, which
	 * is a bad enough surprise that it is worth three maps instead of three fields.
	 *
	 * `api_key`, `model` and `endpoint` are derived, not stored: they are whatever
	 * the selected provider resolves to, falling back to that adapter's defaults.
	 * Everything downstream reads those three and never has to know about the maps.
	 *
	 * @return array
	 */
	public static function settings() {
		$defaults = array(
			// Off until the owner turns it on and says they understand what it does.
			// A fresh install is an ordinary snippets plugin that talks to nobody.
			'ai_enabled'    => false,
			'ai_consent_at' => '',
			'provider'      => 'anthropic',
			'api_keys'      => array(),
			'models'        => array(),
			'endpoints'     => array(),
			'php_autoapply' => false,
		);
		$raw    = (array) get_option( self::OPTION, array() );
		$stored = wp_parse_args( $raw, $defaults );

		// The provider override has to land before the key, model and endpoint are
		// resolved, or they would be resolved for the stored provider instead.
		if ( isset( self::$override['provider'] ) ) {
			$stored['provider'] = (string) self::$override['provider'];
		}

		$id       = isset( VibeSnip_Provider::providers()[ $stored['provider'] ] ) ? $stored['provider'] : 'anthropic';
		$provider = VibeSnip_Provider::get( $id );

		// The pre-0.10.0 shape had one flat api_key/model. VibeSnip_Activator moves
		// them onto the maps, but that runs on admin_init — so they are also read
		// here as a fallback, or a front-end or ability call in the window between
		// the files landing and the first admin page load would see no key at all.
		// Anthropic only: it was the sole provider before the flat shape was retired,
		// so a flat key can never have belonged to anything else. Without that
		// restriction, selecting a vendor you have no key for would silently send
		// your Anthropic key to OpenAI or Google.
		$is_legacy    = 'anthropic' === $id && ! isset( $raw['api_keys'] );
		$legacy_key   = ( $is_legacy && isset( $raw['api_key'] ) ) ? (string) $raw['api_key'] : '';
		$legacy_model = ( $is_legacy && isset( $raw['model'] ) ) ? (string) $raw['model'] : '';

		$stored['provider'] = $id;

		// Keys are encrypted at rest (see VibeSnip_Keys) and decrypted here, so
		// everything downstream keeps reading a plain string and never has to know.
		$stored['api_key'] = ! empty( $stored['api_keys'][ $id ] )
			? VibeSnip_Keys::decrypt( (string) $stored['api_keys'][ $id ] )
			: VibeSnip_Keys::decrypt( $legacy_key );

		if ( ! empty( $stored['models'][ $id ] ) ) {
			$stored['model'] = (string) $stored['models'][ $id ];
		} elseif ( '' !== $legacy_model ) {
			$stored['model'] = $legacy_model;
		} else {
			$stored['model'] = $provider::default_model();
		}
		$stored['endpoint'] = ! empty( $stored['endpoints'][ $id ] ) ? (string) $stored['endpoints'][ $id ] : $provider::default_endpoint();

		return self::$override ? array_merge( $stored, self::$override ) : $stored;
	}

	/**
	 * Which provider and model the next call will actually use.
	 *
	 * Read it rather than settings() when the answer is going on screen, so a
	 * message can never name one provider while the request went to another.
	 *
	 * @return array{provider: string, label: string, model: string}
	 */
	public static function target() {
		$settings = self::settings();
		$provider = VibeSnip_Provider::current();

		return array(
			'provider' => $settings['provider'],
			'label'    => $provider::label(),
			'model'    => (string) $settings['model'],
		);
	}

	/**
	 * One cheap round-trip that answers "is this key going to work?".
	 *
	 * A bad key used to surface only when someone tried to compose, several
	 * minutes and one confusing error message later.
	 *
	 * @param array $override Provider/api_key/model/endpoint to test instead of the
	 *                        stored ones, so the button tests what is on screen.
	 *                        Anything missing falls back to what is stored.
	 * @return array|WP_Error Which provider and model answered, or an error.
	 */
	public static function test_connection( $override = array() ) {
		self::$override = is_array( $override ) ? array_filter( $override, 'strlen' ) : array();

		try {
			$settings = self::settings();
			if ( empty( $settings['api_key'] ) ) {
				return new WP_Error( 'vibesnip_no_key', __( 'Add an API key first, then test it.', 'vibesnip' ) );
			}

			$schema = array(
				'type'                 => 'object',
				'properties'           => array( 'ok' => array( 'type' => 'boolean' ) ),
				'required'             => array( 'ok' ),
				'additionalProperties' => false,
			);

			$text = self::response_text( self::request( 'Reply with {"ok":true} and nothing else.', 'ping', $schema ) );

			return is_wp_error( $text ) ? $text : self::target();
		} finally {
			self::$override = array();
		}
	}

	/**
	 * Has the owner switched AI on and accepted what it does?
	 *
	 * The single gate every AI path is behind. VibeSnip ships with this off: a new
	 * install manages snippets, contacts nobody, and has no key field to fill in
	 * until someone deliberately turns AI on — which takes reading what can go
	 * wrong and ticking a box to say so. That is the honest default for a feature
	 * that writes code onto a live site, and it is what a plugin reviewer sees
	 * first: an ordinary snippets plugin, with AI as something the owner opted
	 * into with their eyes open.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return ! empty( self::settings()['ai_enabled'] );
	}

	/**
	 * Enabled, permitted, and with a key — everything needed before an AI button
	 * should do anything. Screens use it to decide what to show.
	 *
	 * @return bool
	 */
	public static function is_ready() {
		return self::is_enabled() && current_user_can( 'vibesnip_use_ai' ) && self::has_key();
	}

	public static function has_key() {
		$s = self::settings();
		return ! empty( $s['api_key'] );
	}

	/**
	 * The risks the owner is agreeing to when they switch AI on.
	 *
	 * Kept here rather than inline in the settings markup because the same list is
	 * the consent text, and a consent text that lives in one place cannot quietly
	 * differ from what was actually agreed to.
	 *
	 * @return string[]
	 */
	public static function risk_points() {
		return array(
			__( 'AI writes code for your live site. It can be wrong, and wrong code can break pages, checkout or the admin area.', 'vibesnip' ),
			__( 'Your request, your snippet code and a short description of your site (versions, theme name, active well-known plugins) are sent to the AI provider you choose, under that provider\'s terms.', 'vibesnip' ),
			__( 'You pay the provider directly for what you use. VibeSnip cannot see or cap that spend.', 'vibesnip' ),
			__( 'Nothing the AI writes ever runs on its own: it is saved switched off, PHP is syntax-checked before it can go live, and a snippet that fatals is switched back off automatically. Those are real safeguards, not a guarantee.', 'vibesnip' ),
			__( 'You stay responsible for reading a snippet before you activate it, and for having a backup.', 'vibesnip' ),
		);
	}

	/**
	 * Hover text for the editor's Ask AI button, one per reason it can be blocked.
	 *
	 * Shared by the editor view (initial state) and admin.js (as you type), so the
	 * two cannot drift apart.
	 *
	 * @return array<string,string> Keyed nokey|empty|unsaved|ready.
	 */
	public static function ask_tips() {
		return array(
			'nokey'   => __( 'Ask AI reads this snippet and reports in plain English what it does, what it changes and how safe it looks. Add your AI provider API key on the VibeSnip Settings page to switch it on.', 'vibesnip' ),
			'empty'   => __( 'Ask AI reports in plain English what a snippet does and how safe it looks. Write or paste some code first, and it will review that.', 'vibesnip' ),
			'unsaved' => __( 'Ask AI stores its report against the saved snippet, so save this one first. Then it will report what the code does and how safe it looks.', 'vibesnip' ),
			'ready'   => __( 'Ask AI what this code does and how safe it looks. It only reports — it never switches anything on.', 'vibesnip' ),
		);
	}

	/**
	 * Restate a request and propose an approach, without writing any code.
	 *
	 * This is the cheap half of AI Compose. A misread request is expensive to spot
	 * once it has become a plausible-looking snippet sitting in the list under a
	 * name nobody recognises, and almost free to spot in a paragraph. So the model
	 * says what it thinks it heard first, and the author corrects it or agrees.
	 *
	 * @param string   $prompt  The user's latest request.
	 * @param string[] $history Earlier requests this session, oldest first, so a
	 *                          rephrase reads as a correction rather than a fresh start.
	 * @return array|WP_Error Plan array, or an error.
	 */
	public static function plan( $prompt, $history = array() ) {
		$settings = self::settings();
		if ( empty( $settings['api_key'] ) ) {
			return new WP_Error( 'vibesnip_no_key', __( 'Add your API key on the VibeSnip Settings page first.', 'vibesnip' ) );
		}
		$prompt = trim( (string) $prompt );
		if ( '' === $prompt ) {
			return new WP_Error( 'vibesnip_empty_prompt', __( 'Describe what you want the snippet to do.', 'vibesnip' ) );
		}

		$message = '';
		if ( ! empty( $history ) && is_array( $history ) ) {
			$message .= "Earlier attempts at describing this, oldest first:\n";
			foreach ( $history as $earlier ) {
				$message .= '- ' . trim( (string) $earlier ) . "\n";
			}
			$message .= "\nThe wording below is the one that counts — the earlier lines are only there to show what was missing.\n\n";
		}
		$message .= 'Request: ' . $prompt;

		return self::parse_plan( self::request( self::plan_system_prompt(), $message, self::plan_schema() ) );
	}

	/**
	 * Generate snippet proposals for a natural-language request.
	 *
	 * @param string $prompt   The user's request.
	 * @param string $approved An approved plan from plan(), so the build follows what
	 *                         was agreed instead of re-reading the prompt from scratch.
	 * @return array|WP_Error  Array of proposals (title/description/type/location/code/explanation), or an error.
	 */
	public static function generate( $prompt, $approved = '' ) {
		$settings = self::settings();
		if ( empty( $settings['api_key'] ) ) {
			return new WP_Error( 'vibesnip_no_key', __( 'Add your API key on the VibeSnip Settings page first.', 'vibesnip' ) );
		}
		$prompt = trim( (string) $prompt );
		if ( '' === $prompt ) {
			return new WP_Error( 'vibesnip_empty_prompt', __( 'Describe what you want the snippet to do.', 'vibesnip' ) );
		}

		$message  = 'Request: ' . $prompt;
		$approved = trim( (string) $approved );
		if ( '' !== $approved ) {
			$message .= "\n\nThe site owner has already read and approved this plan. Build exactly it — do not"
				. " reinterpret the request or add snippets the plan does not mention:\n" . $approved;
		}

		$response = self::request( self::system_prompt(), $message, self::schema() );

		return self::parse_response( $response );
	}

	/**
	 * Turn the HTTP response into a normalised plan, or a WP_Error.
	 *
	 * Every field is sanitized here for the same reason the review report is: it is
	 * model output on its way to a screen, so it is treated as untrusted text.
	 *
	 * @param array|WP_Error $response Result of wp_remote_post.
	 * @return array|WP_Error
	 */
	public static function parse_plan( $response ) {
		$text = self::response_text( $response );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$parsed = json_decode( $text, true );
		if ( ! is_array( $parsed ) || empty( $parsed['understanding'] ) ) {
			return new WP_Error( 'vibesnip_parse', __( 'The AI did not explain what it understood. Try again.', 'vibesnip' ) );
		}

		$snippets = array();
		if ( ! empty( $parsed['snippets'] ) && is_array( $parsed['snippets'] ) ) {
			foreach ( array_slice( $parsed['snippets'], 0, 5 ) as $s ) {
				if ( empty( $s['title'] ) ) {
					continue;
				}
				$type = ( isset( $s['type'] ) && isset( VibeSnip_Snippet::types()[ $s['type'] ] ) ) ? $s['type'] : 'php';
				$snippets[] = array(
					'title'   => sanitize_text_field( (string) $s['title'] ),
					'type'    => $type,
					'summary' => isset( $s['summary'] ) ? sanitize_text_field( (string) $s['summary'] ) : '',
				);
			}
		}

		return array(
			'understanding' => sanitize_textarea_field( (string) $parsed['understanding'] ),
			'approach'      => isset( $parsed['approach'] ) ? sanitize_textarea_field( (string) $parsed['approach'] ) : '',
			'snippets'      => $snippets,
			'assumptions'   => self::string_list( isset( $parsed['assumptions'] ) ? $parsed['assumptions'] : null ),
			'caveats'       => self::string_list( isset( $parsed['caveats'] ) ? $parsed['caveats'] : null ),
		);
	}

	/**
	 * Sanitize a model-supplied list of short strings, dropping the empties.
	 *
	 * @param mixed $raw Whatever the model put there.
	 * @return string[]
	 */
	private static function string_list( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( $raw, 0, 6 ) as $item ) {
			$item = sanitize_text_field( (string) $item );
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * POST one schema-constrained request to the selected provider.
	 *
	 * The three inputs are the same whichever vendor answers; the adapter owns
	 * the URL, the auth header and the body shape.
	 *
	 * @param string $system System prompt.
	 * @param string $user   The single user message.
	 * @param array  $schema JSON schema the reply must satisfy.
	 * @return array|WP_Error Result of wp_remote_post.
	 */
	private static function request( $system, $user, $schema ) {
		// The one place every outbound AI call passes through, so the one place the
		// off switch has to hold. Screens hide their AI buttons when it is off;
		// this is what makes that a rule rather than a decoration.
		if ( ! self::is_enabled() ) {
			return self::disabled_error();
		}

		$settings = self::settings();
		$provider = VibeSnip_Provider::current();
		$model    = (string) $settings['model'];

		// Only the OpenAI-compatible adapter can reach here without both: every
		// other vendor supplies its own defaults. Caught here rather than letting
		// wp_remote_post() fail on an empty URL, which reports nothing useful.
		if ( '' === trim( (string) $settings['endpoint'] ) || '' === trim( $model ) ) {
			return new WP_Error(
				'vibesnip_provider_incomplete',
				__( 'This provider needs both an API address and a model name. Add them on the VibeSnip Settings page.', 'vibesnip' )
			);
		}

		return wp_remote_post(
			$provider::url( (string) $settings['endpoint'], $model ),
			array(
				'timeout' => 60,
				'headers' => $provider::headers( (string) $settings['api_key'] ),
				'body'    => wp_json_encode( $provider::body( $model, $system, $user, $schema ) ),
			)
		);
	}

	/**
	 * Review a snippet: what it does, how it affects the site, how safe it looks.
	 *
	 * Advisory only. The report never gates anything — VibeSnip_Guard::validate()
	 * remains the sole authority on whether a snippet may activate, and this call
	 * cannot change a snippet's status.
	 *
	 * @param VibeSnip_Snippet $snippet The snippet to review.
	 * @return array|WP_Error Report array, or an error.
	 */
	public static function review( $snippet ) {
		$settings = self::settings();
		if ( empty( $settings['api_key'] ) ) {
			return new WP_Error( 'vibesnip_no_key', __( 'Add your API key on the VibeSnip Settings page first.', 'vibesnip' ) );
		}
		$code = isset( $snippet->code ) ? trim( (string) $snippet->code ) : '';
		if ( '' === $code ) {
			return new WP_Error( 'vibesnip_empty_code', __( 'There is no code to review yet.', 'vibesnip' ) );
		}

		$facts = array(
			'Type: ' . $snippet->type,
			'Location: ' . $snippet->location,
			'Current title: ' . ( $snippet->title ? $snippet->title : '(none)' ),
			'Currently active: ' . ( 'active' === $snippet->status ? 'yes' : 'no' ),
		);

		$message = implode( "\n", $facts ) . "\n\nCode:\n" . $code;

		$response = self::request( self::review_system_prompt(), $message, self::review_schema() );

		return self::parse_review( $response );
	}

	/**
	 * Turn the HTTP response into a normalised review report or a WP_Error.
	 *
	 * @param array|WP_Error $response Result of wp_remote_post.
	 * @return array|WP_Error
	 */
	public static function parse_review( $response ) {
		$text = self::response_text( $response );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$parsed = json_decode( $text, true );
		if ( ! is_array( $parsed ) || empty( $parsed['summary'] ) ) {
			return new WP_Error( 'vibesnip_parse', __( 'The AI did not return a usable report. Try again.', 'vibesnip' ) );
		}

		$verdicts = array( 'safe', 'caution', 'unsafe' );
		$verdict  = isset( $parsed['verdict'] ) && in_array( $parsed['verdict'], $verdicts, true ) ? $parsed['verdict'] : 'caution';

		$type = isset( $parsed['recommended_type'] ) && isset( VibeSnip_Snippet::types()[ $parsed['recommended_type'] ] ) ? $parsed['recommended_type'] : '';

		$location = '';
		if ( $type && isset( $parsed['recommended_location'] ) ) {
			$valid = VibeSnip_Snippet::locations_for_type( $type );
			if ( isset( $valid[ $parsed['recommended_location'] ] ) ) {
				$location = $parsed['recommended_location'];
			}
		}

		$risks = array();
		if ( ! empty( $parsed['risks'] ) && is_array( $parsed['risks'] ) ) {
			foreach ( $parsed['risks'] as $risk ) {
				$risk = sanitize_text_field( (string) $risk );
				if ( '' !== $risk ) {
					$risks[] = $risk;
				}
			}
		}

		$tags = array();
		if ( ! empty( $parsed['suggested_tags'] ) && is_array( $parsed['suggested_tags'] ) ) {
			foreach ( array_slice( $parsed['suggested_tags'], 0, 5 ) as $tag ) {
				$tag = sanitize_text_field( (string) $tag );
				if ( '' !== $tag ) {
					$tags[] = $tag;
				}
			}
		}

		return array(
			'summary'                => sanitize_textarea_field( (string) $parsed['summary'] ),
			'verdict'                => $verdict,
			'what_it_does'           => isset( $parsed['what_it_does'] ) ? sanitize_textarea_field( (string) $parsed['what_it_does'] ) : '',
			'site_impact'            => isset( $parsed['site_impact'] ) ? sanitize_textarea_field( (string) $parsed['site_impact'] ) : '',
			'risks'                  => $risks,
			'recommended_type'       => $type,
			'recommended_location'   => $location,
			'recommended_conditions' => isset( $parsed['recommended_conditions'] ) ? sanitize_textarea_field( (string) $parsed['recommended_conditions'] ) : '',
			'suggested_title'        => isset( $parsed['suggested_title'] ) ? sanitize_text_field( (string) $parsed['suggested_title'] ) : '',
			'suggested_tags'         => $tags,
			'should_activate'        => ! empty( $parsed['should_activate'] ),
			'notes'                  => isset( $parsed['notes'] ) ? sanitize_textarea_field( (string) $parsed['notes'] ) : '',
			'model'                  => self::settings()['model'],
			'reviewed_at'            => current_time( 'mysql' ),
		);
	}

	/**
	 * Turn the HTTP response into proposals or a WP_Error.
	 *
	 * @param array|WP_Error $response Result of wp_remote_post.
	 * @return array|WP_Error
	 */
	public static function parse_response( $response ) {
		$text = self::response_text( $response );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$parsed = json_decode( $text, true );
		if ( ! is_array( $parsed ) || empty( $parsed['snippets'] ) || ! is_array( $parsed['snippets'] ) ) {
			return new WP_Error( 'vibesnip_parse', __( 'The AI did not return a usable snippet. Try again or rephrase.', 'vibesnip' ) );
		}

		$out = array();
		foreach ( $parsed['snippets'] as $s ) {
			if ( empty( $s['code'] ) || empty( $s['type'] ) ) {
				continue;
			}
			$type = in_array( $s['type'], array_keys( VibeSnip_Snippet::types() ), true ) ? $s['type'] : 'php';
			$loc  = isset( $s['location'] ) ? $s['location'] : '';
			$valid_locs = VibeSnip_Snippet::locations_for_type( $type );
			if ( ! isset( $valid_locs[ $loc ] ) ) {
				$loc = ( 'php' === $type ) ? 'everywhere' : 'site_header';
			}
			$out[] = array(
				'title'       => isset( $s['title'] ) ? sanitize_text_field( $s['title'] ) : __( 'AI snippet', 'vibesnip' ),
				'description' => isset( $s['description'] ) ? sanitize_textarea_field( $s['description'] ) : '',
				'type'        => $type,
				'location'    => $loc,
				'code'        => (string) $s['code'],
				'explanation' => isset( $s['explanation'] ) ? sanitize_textarea_field( $s['explanation'] ) : '',
			);
		}

		if ( empty( $out ) ) {
			return new WP_Error( 'vibesnip_parse', __( 'The AI response contained no valid snippets.', 'vibesnip' ) );
		}
		return $out;
	}

	/**
	 * Shared response handling: HTTP status first, then the adapter digs the
	 * structured JSON out of whatever envelope its vendor uses.
	 *
	 * @param array|WP_Error $response Result of wp_remote_post.
	 * @return string|WP_Error The JSON text, or an error.
	 */
	private static function response_text( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$provider = VibeSnip_Provider::current();
		$code     = (int) wp_remote_retrieve_response_code( $response );
		$raw      = wp_remote_retrieve_body( $response );
		$data     = json_decode( $raw, true );

		if ( 200 !== $code ) {
			$message = is_array( $data ) ? $provider::error_message( $data ) : '';
			if ( 401 === $code ) {
				$message = __( 'Your API key was rejected. Check it on the Settings page.', 'vibesnip' );
			}
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'vibesnip_api', $message ? $message : sprintf( __( 'The AI request failed (HTTP %d).', 'vibesnip' ), $code ) );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'vibesnip_parse', __( 'The AI response could not be read.', 'vibesnip' ) );
		}

		return $provider::extract_json( $data );
	}

	/**
	 * System prompt for reviews. Aimed at someone who found a snippet online and
	 * cannot read code: be concrete, name what the code actually touches, and err
	 * toward caution.
	 *
	 * @return string
	 */
	private static function review_system_prompt() {
		$rules = array(
			'You review a single code snippet for a WordPress site owner who may not be able to read code. The snippet runs through the VibeSnip plugin: PHP behaves like code in the theme functions.php file, and CSS/JS/HTML are injected into the page.',
			'',
			'Write for a non-programmer. Plain English, no jargon you do not explain, no code in your prose.',
			'',
			'Be concrete and specific:',
			'- Name the actual hooks, filters and functions the code uses, and say what each one affects.',
			'- Say what the visitor or the administrator would actually notice.',
			'- Flag anything that writes to the database, makes outbound network requests, changes user capabilities or roles, disables a security feature, exposes data publicly, loads remote code, or runs on every page load.',
			'- "risks" must each be a specific consequence, not a generic warning. If there are genuinely none, return an empty list.',
			'',
			'Judging safety:',
			'- Prefer "caution" over "safe" whenever you are unsure. Use "safe" only for code you fully understand and that has no meaningful downside.',
			'- Use "unsafe" for code that could break the site, leak data, weaken security, or that you cannot account for.',
			'- Never claim the code is syntactically correct. A separate deterministic check does that, and it is the only authority on it.',
			'- set "should_activate" to false whenever the verdict is "unsafe", or when activating it needs a decision only the site owner can make.',
			'',
			'Recommendations: pick the "recommended_type" the code actually is, and a "recommended_location" valid for that type. Keep "suggested_title" under 60 characters. Give at most 5 lowercase "suggested_tags". "recommended_conditions" is plain-English advice about when the snippet should run, or an empty string if it should always run. "notes" is what to check on the site right after switching it on.',
			'',
			self::class_context(),
		);

		return implode( "\n", $rules );
	}

	/**
	 * JSON schema for a review report.
	 *
	 * @return array
	 */
	private static function review_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'summary'                => array( 'type' => 'string' ),
				'verdict'                => array( 'type' => 'string', 'enum' => array( 'safe', 'caution', 'unsafe' ) ),
				'what_it_does'           => array( 'type' => 'string' ),
				'site_impact'            => array( 'type' => 'string' ),
				'risks'                  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'recommended_type'       => array( 'type' => 'string', 'enum' => array_keys( VibeSnip_Snippet::types() ) ),
				'recommended_location'   => array( 'type' => 'string', 'enum' => array_keys( VibeSnip_Snippet::locations() ) ),
				'recommended_conditions' => array( 'type' => 'string' ),
				'suggested_title'        => array( 'type' => 'string' ),
				'suggested_tags'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'should_activate'        => array( 'type' => 'boolean' ),
				'notes'                  => array( 'type' => 'string' ),
			),
			'required'             => array( 'summary', 'verdict', 'what_it_does', 'site_impact', 'risks', 'recommended_type', 'recommended_location', 'recommended_conditions', 'suggested_title', 'suggested_tags', 'should_activate', 'notes' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * System prompt for the planning step. Deliberately forbids code: the value of
	 * this turn is that it is readable by someone who cannot read code, and a code
	 * block would pull their attention straight past the sentence that matters.
	 *
	 * @return string
	 */
	private static function plan_system_prompt() {
		$rules = array(
			'A WordPress site owner has described something they want their site to do. Before any code is written, you restate the request so they can confirm you understood it.',
			'',
			'Write for a non-programmer. Plain English, no jargon you do not explain.',
			'',
			'- "understanding" restates what they asked for in your own words, concretely enough that a misreading would be obvious to them. Do not simply echo their wording back.',
			'- "approach" says how you would build it and which WordPress hooks or features you would use. Two or three sentences.',
			'- "snippets" lists what you would actually write: one entry per snippet, with the title you would give it, its type, and one line on what that piece does.',
			'- Plan ONE snippet unless you genuinely need more. A second snippet is justified only when it does something useful on its own and the owner might reasonably want to switch it on or off separately. Two halves of one feature — a setting and the code that reads it, a hook and its helper — are one snippet. A snippet that does nothing until another one is also switched on is a bug, not a second snippet.',
			'- Plan the simplest thing that fully does the job, and no more: no extra options, admin screens, filters or "flexibility" the request did not ask for. Equally, no shortcuts — if doing it properly needs a few more lines, plan those lines.',
			'- "assumptions" is anything the request left open that you had to decide for them. Be specific — this is where a misunderstanding usually hides. Empty list if genuinely nothing was ambiguous.',
			'- "caveats" is anything that could stop this working on their site, such as a theme or plugin that would have to cooperate. Empty list if there is nothing worth saying.',
			'',
			'Write no code in this reply, not even a single function name in a code fence. You are agreeing on the goal, not delivering it.',
			'',
			self::class_context(),
		);

		return implode( "\n", $rules );
	}

	/**
	 * JSON schema for a plan.
	 *
	 * @return array
	 */
	private static function plan_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'understanding' => array( 'type' => 'string' ),
				'approach'      => array( 'type' => 'string' ),
				'snippets'      => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'title'   => array( 'type' => 'string' ),
							'type'    => array( 'type' => 'string', 'enum' => array_keys( VibeSnip_Snippet::types() ) ),
							'summary' => array( 'type' => 'string' ),
						),
						'required'             => array( 'title', 'type', 'summary' ),
						'additionalProperties' => false,
					),
				),
				'assumptions'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'caveats'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			),
			'required'             => array( 'understanding', 'approach', 'snippets', 'assumptions', 'caveats' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * System prompt: steer toward safe, idempotent, reversible WordPress code.
	 *
	 * @return string
	 */
	private static function system_prompt() {
		$types = implode( ', ', array_keys( VibeSnip_Snippet::types() ) );

		$locations = array();
		foreach ( VibeSnip_Snippet::locations() as $slug => $meta ) {
			$locations[] = $slug . ' (' . implode( '/', $meta[1] ) . ')';
		}

		$rules = array(
			'You generate code snippets for a WordPress site managed by the VibeSnip plugin.',
			'Each snippet runs like code in the theme functions.php file (for PHP) or is injected into the page (for CSS/JS/HTML/text).',
			'',
			'Rules:',
			'- Write PHP WITHOUT the opening <?php tag. Register hooks/filters; do not echo output directly in PHP snippets meant to run everywhere.',
			'- Prefer WordPress APIs (add_action, add_filter, wp_enqueue_*) over hacks. Make code idempotent and reversible (deactivating the snippet must fully undo it).',
			'- Never call exec/shell_exec/system, never write files outside WordPress APIs, never delete data.',
			'- Choose the correct "type" (' . $types . ') and a valid "location" for that type.',
			'- Valid locations: ' . implode( '; ', $locations ) . '.',
			'- The "explanation" must be a short, plain-English description of what the code does and any caveats.',
			'',
			'How much to write:',
			'- Return ONE snippet. Return more only when each one does something useful on its own and the owner might reasonably switch it on or off separately.',
			'- A snippet that has no effect until another snippet is also active is never correct. A setting and the code that reads it, a hook and the helper it calls, a style and the markup it targets — each of those pairs is ONE snippet. Splitting them ships two things that each do nothing.',
			'- Everything the request needs must be in what you return. No placeholders, no TODO comments, no "you would also need to…". If it is needed, write it.',
			'',
			'How to write it:',
			'- Write the smallest code that fully does the job. If a shorter version would do the same thing, write that one instead.',
			'- Build exactly what was asked for. No extra options, settings screens, filters, hooks or configurability nobody asked for, and no error handling for situations that cannot happen.',
			'- Take no shortcuts either. Use the proper WordPress API rather than the quicker hack, escape everything you output, check what you read back from the database, and handle the cases the request actually implies.',
			'- Do not add a settings screen for a value the owner has already told you, unless they asked to be able to change it later without editing code.',
			'- You cannot run this code or look at the site, so do not stake the whole snippet on an exact string, option name, element id or version you are recalling from memory. Prefer an approach that does not depend on one at all; where you must key off one, match it loosely enough to survive a wording change (a substring rather than an identity test).',
			'- When you change something WordPress or another plugin builds, hook late enough that it already exists — do not guess the order, and prefer a high priority when you are not certain. Then check that what you expected is really there before changing it. A snippet that hooks too early finds nothing, changes nothing, and reports no error, which is the hardest kind of failure for the owner to diagnose.',
			'- Comment only where a reader would otherwise ask "why?". Do not narrate what the next line does.',
			'',
			self::class_context(),
		);

		return implode( "\n", $rules );
	}

	private static function class_context() {
		if ( class_exists( 'VibeSnip_Context' ) ) {
			return VibeSnip_Context::summary();
		}
		return '';
	}

	/**
	 * JSON schema for the structured output.
	 *
	 * @return array
	 */
	private static function schema() {
		$types     = array_keys( VibeSnip_Snippet::types() );
		$locations = array_keys( VibeSnip_Snippet::locations() );

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'snippets' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'title'       => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
							'type'        => array( 'type' => 'string', 'enum' => $types ),
							'location'    => array( 'type' => 'string', 'enum' => $locations ),
							'code'        => array( 'type' => 'string' ),
							'explanation' => array( 'type' => 'string' ),
						),
						'required'             => array( 'title', 'description', 'type', 'location', 'code', 'explanation' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'snippets' ),
			'additionalProperties' => false,
		);
	}
}
