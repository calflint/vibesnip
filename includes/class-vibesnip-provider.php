<?php
/**
 * AI provider adapters.
 *
 * VibeSnip makes exactly two one-shot calls — AI Compose and Ask AI — each a
 * system prompt, one user message, and a demand that the reply be JSON matching
 * a fixed schema. Only five things differ between vendors, and all five live
 * here, nowhere else:
 *
 *   1. Auth header + request URL — Google puts the model in the path.
 *   2. Where the system prompt goes — a field, or the first message.
 *   3. How the reply is constrained to a JSON schema. The hard part: each
 *      vendor accepts a different dialect (see clean_schema()).
 *   4. Where the JSON text sits in the response.
 *   5. How a refusal, a truncated reply and an error are signalled.
 *
 * Everything downstream — VibeSnip_AI::parse_response(), ::parse_review() and
 * the validation that whitelists types/locations — works on already-decoded
 * JSON and stays provider-agnostic. That is what keeps this layer thin.
 *
 * Adding a vendor is one entry in providers() plus one adapter class. The
 * shape deliberately mirrors Claudpress's provider layer; the same person
 * maintains both.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

abstract class VibeSnip_Provider {

	/** Stable identifier stored in settings. */
	abstract public static function id(): string;

	/** Human-readable name for the settings dropdown. */
	abstract public static function label(): string;

	/** Where requests go when the owner has not overridden the endpoint. */
	abstract public static function default_endpoint(): string;

	/** Model used when the owner has not chosen one. */
	abstract public static function default_model(): string;

	/**
	 * Fallback models, best first — what the dropdown offers before it has been
	 * refreshed from the vendor.
	 *
	 * These go stale: vendors add and retire models on their own schedule and a
	 * list baked into a release is wrong within months. It is a starting point,
	 * not the answer — `models_url()` + `parse_models()` fetch the real list from
	 * the vendor using the owner's own key, and that is what the dropdown shows
	 * once it has been refreshed.
	 *
	 * An empty list means this vendor has no catalogue we can offer at all and the
	 * owner types the model name themselves — the settings screen renders a text
	 * box instead of a dropdown.
	 *
	 * @return array<int, array{id: string, note: string}>
	 */
	abstract public static function models(): array;

	/**
	 * Where to ask the vendor which models this key may actually use.
	 *
	 * Derived from the chat endpoint rather than hardcoded, so an owner who has
	 * overridden the endpoint gets the model list from the same host they are
	 * sending requests to. Return '' when the vendor has no such endpoint.
	 *
	 * @param string $endpoint The chat endpoint in use.
	 * @return string
	 */
	public static function models_url( string $endpoint ): string {
		return '';
	}

	/**
	 * Turn a model-list response into what the dropdown needs.
	 *
	 * Filtered to models this plugin can actually use: VibeSnip only ever asks for
	 * one schema-constrained chat completion, so a vendor's embedding, speech and
	 * image models would be dead entries that fail the moment they were picked.
	 *
	 * @param array $data Decoded 2xx response body.
	 * @return array<string, string> model id => label, best first.
	 */
	public static function parse_models( array $data ): array {
		return array();
	}

	/** Where the owner gets an API key, or '' when there is no single such page. */
	abstract public static function key_url(): string;

	/** What an API key for this vendor looks like. */
	abstract public static function key_hint(): string;

	/** Auth + content headers. */
	abstract public static function headers( string $key ): array;

	/** Final request URL. Most vendors ignore $model; Google puts it in the path. */
	public static function url( string $endpoint, string $model ): string {
		return $endpoint;
	}

	/**
	 * Request body: a system prompt, one user turn, and a schema the reply must satisfy.
	 *
	 * @param string $model  Model id.
	 * @param string $system System prompt.
	 * @param string $user   The single user message.
	 * @param array  $schema JSON schema the reply must match.
	 * @return array
	 */
	abstract public static function body( string $model, string $system, string $user, array $schema ): array;

	/**
	 * The JSON text the model produced.
	 *
	 * Returns '' when the reply carried no text at all — the caller's parser
	 * reports that as an unusable answer. A refusal or a reply cut short by the
	 * token budget returns a WP_Error instead, because those have a cause worth
	 * telling the owner about.
	 *
	 * @param array $data Decoded 2xx response body.
	 * @return string|WP_Error
	 */
	abstract public static function extract_json( array $data );

	/**
	 * Human-readable message from a non-2xx body.
	 *
	 * @param array $data Decoded response body.
	 * @return string
	 */
	public static function error_message( array $data ): string {
		return isset( $data['error']['message'] ) ? (string) $data['error']['message'] : '';
	}

	/** Shared wording, so three adapters cannot drift into three different apologies. */
	protected static function refused(): WP_Error {
		return new WP_Error( 'vibesnip_refusal', __( 'The model declined this request. Try rephrasing it.', 'vibesnip' ) );
	}

	protected static function truncated(): WP_Error {
		return new WP_Error( 'vibesnip_truncated', __( 'The AI ran out of room and its answer was cut off before it finished. Try a shorter or simpler request.', 'vibesnip' ) );
	}

	// ---------------------------------------------------------------- registry

	/** @return array<string, string> id => adapter class name */
	public static function providers(): array {
		return (array) apply_filters(
			'vibesnip_ai_providers',
			array(
				'anthropic'         => 'VibeSnip_Provider_Anthropic',
				'openai'            => 'VibeSnip_Provider_OpenAI',
				'google'            => 'VibeSnip_Provider_Google',
				'openai_compatible' => 'VibeSnip_Provider_OpenAI_Compatible',
			)
		);
	}

	/** Adapter for an id, falling back to Anthropic for unknown values. */
	public static function get( string $id ): string {
		$all = self::providers();
		return isset( $all[ $id ] ) ? $all[ $id ] : 'VibeSnip_Provider_Anthropic';
	}

	/** The adapter selected in settings. */
	public static function current(): string {
		$settings = VibeSnip_AI::settings();
		return self::get( isset( $settings['provider'] ) ? (string) $settings['provider'] : 'anthropic' );
	}

	/**
	 * A schema every vendor accepts.
	 *
	 * Gemini's parser rejects the vocabulary the others tolerate
	 * (additionalProperties, $schema, const, oneOf), so strip to the common
	 * subset rather than maintaining two copies of every schema. Ported from
	 * Claudpress verbatim.
	 *
	 * @param array $schema JSON schema.
	 * @return array
	 */
	protected static function clean_schema( array $schema ): array {
		$allowed = array( 'type', 'description', 'enum', 'items', 'properties', 'required', 'format', 'nullable' );
		$out     = array();

		foreach ( $schema as $k => $v ) {
			if ( ! in_array( $k, $allowed, true ) ) {
				continue;
			}
			if ( 'properties' === $k && is_array( $v ) ) {
				$props = array();
				foreach ( $v as $name => $prop ) {
					$props[ $name ] = is_array( $prop ) ? self::clean_schema( $prop ) : $prop;
				}
				$out[ $k ] = $props ? $props : (object) array();
				continue;
			}
			if ( 'items' === $k && is_array( $v ) ) {
				$out[ $k ] = self::clean_schema( $v );
				continue;
			}
			$out[ $k ] = $v;
		}

		if ( isset( $out['type'] ) && 'object' === $out['type'] && ! isset( $out['properties'] ) ) {
			$out['properties'] = (object) array();
		}
		return $out;
	}
}

/**
 * Anthropic Messages API. Structured output is requested through
 * output_config.format and arrives as JSON in the first text block.
 */
final class VibeSnip_Provider_Anthropic extends VibeSnip_Provider {

	const API_VER    = '2023-06-01';
	const MAX_TOKENS = 6000;

	public static function id(): string {
		return 'anthropic';
	}

	public static function label(): string {
		return __( 'Anthropic (Claude)', 'vibesnip' );
	}

	public static function default_endpoint(): string {
		return 'https://api.anthropic.com/v1/messages';
	}

	public static function default_model(): string {
		return 'claude-opus-5';
	}

	public static function key_url(): string {
		return 'https://console.anthropic.com/settings/keys';
	}

	public static function key_hint(): string {
		return 'sk-ant-...';
	}

	public static function models(): array {
		return array(
			array( 'id' => 'claude-opus-5', 'note' => 'claude-opus-5' ),
			array( 'id' => 'claude-sonnet-5', 'note' => 'claude-sonnet-5' ),
			array( 'id' => 'claude-opus-4-8', 'note' => 'claude-opus-4-8' ),
			array( 'id' => 'claude-haiku-4-5', 'note' => 'claude-haiku-4-5' ),
		);
	}

	public static function models_url( string $endpoint ): string {
		return str_replace( '/v1/messages', '/v1/models', $endpoint ) . '?limit=100';
	}

	/**
	 * Anthropic returns a display name per model, and — usefully — says whether
	 * each one supports structured outputs. Every VibeSnip call is schema-
	 * constrained, so a model without them is not an option, only a support ticket.
	 */
	public static function parse_models( array $data ): array {
		$out = array();
		foreach ( isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array() as $model ) {
			if ( empty( $model['id'] ) ) {
				continue;
			}
			if ( isset( $model['capabilities']['structured_outputs']['supported'] )
				&& ! $model['capabilities']['structured_outputs']['supported'] ) {
				continue;
			}
			$id           = (string) $model['id'];
			$out[ $id ] = ! empty( $model['display_name'] ) ? (string) $model['display_name'] . ' — ' . $id : $id;
		}
		return $out;
	}

	public static function headers( string $key ): array {
		return array(
			'x-api-key'         => $key,
			'anthropic-version' => self::API_VER,
			'content-type'      => 'application/json',
		);
	}

	public static function body( string $model, string $system, string $user, array $schema ): array {
		$body = array(
			'model'         => $model,
			'max_tokens'    => self::MAX_TOKENS,
			'system'        => $system,
			'messages'      => array(
				array( 'role' => 'user', 'content' => $user ),
			),
			'output_config' => array(
				'format' => array(
					'type'   => 'json_schema',
					'schema' => $schema,
				),
			),
		);

		if ( self::thinking_on_by_default( $model ) ) {
			$body['thinking'] = array( 'type' => 'disabled' );
		}

		return $body;
	}

	/**
	 * Whether this model runs adaptive thinking unless told otherwise.
	 *
	 * Claude Opus 5 thinks by default, and max_tokens caps thinking PLUS the
	 * reply — so a fixed budget that was fine on Opus 4.8 can truncate the JSON
	 * mid-object. We switch thinking off for these models instead of raising the
	 * budget: the reply is schema-constrained JSON with no reasoning to surface,
	 * and a predictable budget keeps every request inside the HTTP timeout.
	 * Only sent for models documented to accept it — never blanket-applied.
	 *
	 * @param string $model Model id.
	 * @return bool
	 */
	private static function thinking_on_by_default( string $model ): bool {
		return 0 === strpos( $model, 'claude-opus-5' );
	}

	public static function extract_json( array $data ) {
		$stop = isset( $data['stop_reason'] ) ? $data['stop_reason'] : '';
		if ( 'refusal' === $stop ) {
			return self::refused();
		}
		if ( 'max_tokens' === $stop ) {
			return self::truncated();
		}

		if ( ! empty( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					return (string) $block['text'];
				}
			}
		}

		return '';
	}
}

/**
 * OpenAI Chat Completions.
 */
class VibeSnip_Provider_OpenAI extends VibeSnip_Provider {

	public static function id(): string {
		return 'openai';
	}

	public static function label(): string {
		return __( 'OpenAI (GPT)', 'vibesnip' );
	}

	public static function default_endpoint(): string {
		return 'https://api.openai.com/v1/chat/completions';
	}

	public static function default_model(): string {
		return 'gpt-5.1';
	}

	public static function key_url(): string {
		return 'https://platform.openai.com/api-keys';
	}

	public static function key_hint(): string {
		return 'sk-...';
	}

	public static function models(): array {
		return array(
			array( 'id' => 'gpt-5.1', 'note' => 'gpt-5.1' ),
			array( 'id' => 'gpt-5', 'note' => 'gpt-5' ),
			array( 'id' => 'gpt-4.1', 'note' => 'gpt-4.1' ),
			array( 'id' => 'gpt-4o', 'note' => 'gpt-4o' ),
		);
	}

	public static function models_url( string $endpoint ): string {
		return str_replace( '/chat/completions', '/models', $endpoint );
	}

	/**
	 * OpenAI's list is every model on the account — embeddings, speech, images,
	 * moderation — with no capability flags to sort them by. So the text ones are
	 * picked out by name: a family we know answers chat completions, minus the
	 * suffixes that mark a specialised variant. Anything genuinely new that does
	 * not match still reaches the owner through the OpenAI-compatible provider,
	 * which takes a typed model name.
	 */
	public static function parse_models( array $data ): array {
		$families = array( 'gpt-', 'chatgpt-', 'o1', 'o3', 'o4' );
		$not_chat = array( 'embedding', 'audio', 'realtime', 'tts', 'whisper', 'image', 'dall-e', 'moderation', 'transcribe', 'search', 'sora' );

		$models = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();

		// Newest first, so the model someone wants is near the top rather than
		// buried under years of retired ones.
		usort(
			$models,
			static function ( $a, $b ) {
				$at = isset( $a['created'] ) ? (int) $a['created'] : 0;
				$bt = isset( $b['created'] ) ? (int) $b['created'] : 0;
				return $bt <=> $at;
			}
		);

		$out = array();
		foreach ( $models as $model ) {
			if ( empty( $model['id'] ) ) {
				continue;
			}
			$id = (string) $model['id'];

			$known = false;
			foreach ( $families as $family ) {
				if ( 0 === strpos( $id, $family ) ) {
					$known = true;
					break;
				}
			}
			if ( ! $known ) {
				continue;
			}
			foreach ( $not_chat as $marker ) {
				if ( false !== strpos( $id, $marker ) ) {
					continue 2;
				}
			}
			$out[ $id ] = $id;
		}
		return $out;
	}

	public static function headers( string $key ): array {
		return array(
			'Authorization' => 'Bearer ' . $key,
			'content-type'  => 'application/json',
		);
	}

	public static function body( string $model, string $system, string $user, array $schema ): array {
		// OpenAI carries the system prompt as the first message, not a field.
		return array(
			'model'           => $model,
			'messages'        => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
			'response_format' => array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => 'vibesnip_result',
					'strict' => true,
					'schema' => $schema,
				),
			),
		);
	}

	public static function extract_json( array $data ) {
		$message = isset( $data['choices'][0]['message'] ) && is_array( $data['choices'][0]['message'] )
			? $data['choices'][0]['message']
			: array();

		// A refusal comes back in its own field, not in content.
		if ( ! empty( $message['refusal'] ) ) {
			return self::refused();
		}
		if ( isset( $data['choices'][0]['finish_reason'] ) && 'length' === $data['choices'][0]['finish_reason'] ) {
			return self::truncated();
		}

		return isset( $message['content'] ) ? (string) $message['content'] : '';
	}
}

/**
 * Any service that speaks the OpenAI chat-completions format: OpenRouter, Groq,
 * Together, Azure OpenAI, vLLM, a local Ollama or llama.cpp.
 *
 * The wire format is the OpenAI one, so it inherits everything. What differs is
 * that neither the address nor the model catalogue can be known here — the owner
 * supplies both, which is why this is a separate entry rather than the OpenAI
 * adapter with a custom endpoint: pointing that at OpenRouter left you choosing
 * from a list of OpenAI model ids the gateway had never heard of.
 */
final class VibeSnip_Provider_OpenAI_Compatible extends VibeSnip_Provider_OpenAI {

	public static function id(): string {
		return 'openai_compatible';
	}

	public static function label(): string {
		return __( 'OpenAI-compatible (OpenRouter, Ollama, Groq…)', 'vibesnip' );
	}

	/** No default: the owner's gateway is the only thing that knows its address. */
	public static function default_endpoint(): string {
		return '';
	}

	/** No default: likewise for the model name. */
	public static function default_model(): string {
		return '';
	}

	/** No catalogue — the settings screen renders a text box for the model name. */
	public static function models(): array {
		return array();
	}

	public static function key_url(): string {
		return '';
	}

	public static function key_hint(): string {
		return __( 'whatever key your gateway issues', 'vibesnip' );
	}
}

/**
 * Google Gemini generateContent. The model goes in the URL path, the system
 * prompt is its own top-level field, and the schema rides in generationConfig.
 */
final class VibeSnip_Provider_Google extends VibeSnip_Provider {

	public static function id(): string {
		return 'google';
	}

	public static function label(): string {
		return __( 'Google (Gemini)', 'vibesnip' );
	}

	public static function default_endpoint(): string {
		return 'https://generativelanguage.googleapis.com/v1beta/models';
	}

	public static function default_model(): string {
		return 'gemini-3-pro';
	}

	public static function key_url(): string {
		return 'https://aistudio.google.com/apikey';
	}

	public static function key_hint(): string {
		return 'AIza...';
	}

	/**
	 * Google's floating aliases rather than pinned versions. A pinned Gemini id
	 * ages out — 2.5 is already refused for accounts that did not use it before —
	 * and the alias always points at something the account can actually call.
	 * Refreshing replaces these with the real list either way.
	 */
	public static function models(): array {
		return array(
			array( 'id' => 'gemini-pro-latest', 'note' => 'gemini-pro-latest' ),
			array( 'id' => 'gemini-flash-latest', 'note' => 'gemini-flash-latest' ),
			array( 'id' => 'gemini-flash-lite-latest', 'note' => 'gemini-flash-lite-latest' ),
		);
	}

	public static function models_url( string $endpoint ): string {
		// The chat endpoint already ends at .../models; the list is that URL as-is.
		return rtrim( preg_replace( '#/[^/]*:generateContent$#', '', $endpoint ), '/' ) . '?pageSize=200';
	}

	/**
	 * Gemini says outright which methods each model supports, so the filter is the
	 * vendor's own answer rather than a guess: keep the ones that can do
	 * generateContent, which is the only call VibeSnip makes.
	 */
	public static function parse_models( array $data ): array {
		// Gemini's speech and image models answer generateContent too, but return
		// audio or pictures — picking one would fail on the first request with an
		// error about response modalities that means nothing to a site owner.
		$not_text = array( '-tts', 'image', 'embedding', 'aqa', 'veo-', 'imagen-' );

		$out = array();
		foreach ( isset( $data['models'] ) && is_array( $data['models'] ) ? $data['models'] : array() as $model ) {
			if ( empty( $model['name'] ) ) {
				continue;
			}
			$methods = isset( $model['supportedGenerationMethods'] ) ? (array) $model['supportedGenerationMethods'] : array();
			if ( $methods && ! in_array( 'generateContent', $methods, true ) ) {
				continue;
			}
			// The API returns "models/gemini-2.5-pro"; requests take the bare id.
			$id = preg_replace( '#^models/#', '', (string) $model['name'] );
			if ( '' === $id ) {
				continue;
			}
			foreach ( $not_text as $marker ) {
				if ( false !== strpos( $id, $marker ) ) {
					continue 2;
				}
			}
			$out[ $id ] = ! empty( $model['displayName'] ) ? (string) $model['displayName'] . ' — ' . $id : $id;
		}
		return $out;
	}

	public static function url( string $endpoint, string $model ): string {
		// Owners who paste a full :generateContent URL keep it as-is.
		if ( false !== strpos( $endpoint, ':generateContent' ) ) {
			return $endpoint;
		}
		return rtrim( $endpoint, '/' ) . '/' . rawurlencode( $model ) . ':generateContent';
	}

	public static function headers( string $key ): array {
		return array(
			'x-goog-api-key' => $key,
			'content-type'   => 'application/json',
		);
	}

	public static function body( string $model, string $system, string $user, array $schema ): array {
		return array(
			'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
			'contents'          => array(
				array( 'role' => 'user', 'parts' => array( array( 'text' => $user ) ) ),
			),
			'generationConfig'  => array(
				'responseMimeType' => 'application/json',
				'responseSchema'   => self::clean_schema( $schema ),
			),
		);
	}

	public static function extract_json( array $data ) {
		$candidate = isset( $data['candidates'][0] ) && is_array( $data['candidates'][0] ) ? $data['candidates'][0] : array();
		$finish    = isset( $candidate['finishReason'] ) ? $candidate['finishReason'] : '';

		if ( 'SAFETY' === $finish ) {
			return new WP_Error(
				'vibesnip_refusal',
				__( 'The model declined this request: its safety filters blocked the answer. Try rephrasing it.', 'vibesnip' )
			);
		}
		if ( 'MAX_TOKENS' === $finish ) {
			return self::truncated();
		}

		$text = '';
		if ( ! empty( $candidate['content']['parts'] ) && is_array( $candidate['content']['parts'] ) ) {
			foreach ( $candidate['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$text .= (string) $part['text'];
				}
			}
		}

		return $text;
	}
}
