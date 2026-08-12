<?php
/**
 * The WordPress Abilities API adapter (Phase 4 — agent-native).
 *
 * Exposes VibeSnip's safe loop — read context, list/read snippets, create an
 * inactive draft, edit it, activate it behind the guard + health-check,
 * deactivate, and roll back — as standard WordPress
 * *abilities*. Any AI agent, MCP client, or plugin that speaks the Abilities
 * API can now drive the exact same guarded flow the dashboard uses. Nothing
 * here bypasses the guard: create writes an INACTIVE draft, activate validates
 * first and health-checks after, and every write goes through the
 * VibeSnip_Snippets repository so revisions and the audit log stay intact.
 *
 * The Abilities API ships as WordPress core / the abilities-api feature plugin.
 * If it is not present, the registration hooks simply never fire and VibeSnip
 * behaves exactly as before — this file is purely additive.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

class VibeSnip_Abilities {

	/** Ability namespace + category slug (lowercase alphanumeric + dashes only). */
	const NS       = 'vibesnip';
	const CATEGORY = 'vibesnip';

	/**
	 * Register the category + abilities on the Abilities API init hooks.
	 * Safe to call even when the Abilities API is absent — the hooks just
	 * never fire in that case.
	 */
	public static function init() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Is the Abilities API actually available on this install?
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'wp_register_ability' );
	}

	/**
	 * Shared repository instance.
	 *
	 * @return VibeSnip_Snippets
	 */
	private static function repo() {
		static $repo = null;
		if ( null === $repo ) {
			$repo = new VibeSnip_Snippets();
		}
		return $repo;
	}

	/**
	 * Every ability requires the VibeSnip management capability.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_vibesnip' );
	}

	/**
	 * Refuse a write whose snippet type the caller may not author.
	 *
	 * The per-ability permission_callback can only answer "may this caller use
	 * VibeSnip at all" — it never sees the input, so it cannot know a PHP snippet
	 * is involved. Every ability that creates, edits, tests or activates therefore
	 * re-checks the type through the same authority the dashboard uses.
	 *
	 * @param string $type Snippet type.
	 * @return WP_Error|null Error to return to the agent, or null when allowed.
	 */
	private static function denied_for_type( $type ) {
		if ( VibeSnip_Guard::can_author( $type ) ) {
			return null;
		}
		return new WP_Error(
			'vibesnip_forbidden_type',
			VibeSnip_Guard::author_denied_message(),
			array( 'status' => 403 )
		);
	}

	/**
	 * A write driven through an ability is, by definition, a programmatic /
	 * agent action rather than a direct dashboard click. Attribute it as such
	 * in the audit log (VibeSnip_Snippets::log reads this constant).
	 */
	private static function mark_agent_actor() {
		if ( ! defined( 'VIBESNIP_ACTOR_AI' ) ) {
			define( 'VIBESNIP_ACTOR_AI', true );
		}
	}

	/* ================================================================== */
	/* Registration                                                       */
	/* ================================================================== */

	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'VibeSnip code snippets', 'vibesnip' ),
				'description' => __( 'Safely read, write, test, activate and roll back site code snippets through VibeSnip\'s guarded pipeline.', 'vibesnip' ),
			)
		);
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$perm = array( __CLASS__, 'can_manage' );

		// ---- Read: site context -------------------------------------------------
		wp_register_ability(
			self::NS . '/get-site-context',
			array(
				'label'               => __( 'Get site context', 'vibesnip' ),
				'description'         => __( 'Return a compact, non-sensitive snapshot of this WordPress site (versions, active theme, public post types, notable plugins) so generated code fits the actual site.', 'vibesnip' ),
				'category'            => self::CATEGORY,
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => $perm,
				'execute_callback'    => array( __CLASS__, 'exec_get_site_context' ),
				'meta'                => array(
					'annotations' => array( 'readonly' => true, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);

		// ---- Read: list snippets ------------------------------------------------
		wp_register_ability(
			self::NS . '/list-snippets',
			array(
				'label'               => __( 'List snippets', 'vibesnip' ),
				'description'         => __( 'List VibeSnip snippets with their status, type and location. Optionally filter by status, type, or a search term. Does not return snippet code — use get-snippet for that.', 'vibesnip' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'status' => array( 'type' => 'string', 'enum' => array( '', 'active', 'inactive', 'error', 'draft' ), 'description' => 'Filter by status.' ),
						'type'   => array( 'type' => 'string', 'enum' => array_merge( array( '' ), array_keys( VibeSnip_Snippet::types() ) ), 'description' => 'Filter by snippet type.' ),
						'search' => array( 'type' => 'string', 'description' => 'Match title, description or tags.' ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => $perm,
				'execute_callback'    => array( __CLASS__, 'exec_list_snippets' ),
				'meta'                => array(
					'annotations' => array( 'readonly' => true, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);

		// ---- Read: one snippet (with code) --------------------------------------
		wp_register_ability(
			self::NS . '/get-snippet',
			array(
				'label'               => __( 'Get a snippet', 'vibesnip' ),
				'description'         => __( 'Return a single snippet in full, including its code, conditions and last recorded error.', 'vibesnip' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array( 'id' => array( 'type' => 'integer', 'description' => 'Snippet ID.' ) ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => $perm,
				'execute_callback'    => array( __CLASS__, 'exec_get_snippet' ),
				'meta'                => array(
					'annotations' => array( 'readonly' => true, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);

		// ---- Write: create an inactive draft ------------------------------------
		wp_register_ability(
			self::NS . '/create-snippet',
			array(
				'label'               => __( 'Create a snippet', 'vibesnip' ),
				'description'         => __( 'Create a new snippet as an INACTIVE draft. It is stored and (for PHP) syntax-checked, but nothing runs until activate-snippet is called. Returns the new ID and whether the code passed validation.', 'vibesnip' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'       => array( 'type' => 'string', 'description' => 'Human-readable name.' ),
						'type'        => array( 'type' => 'string', 'enum' => array_keys( VibeSnip_Snippet::types() ), 'description' => 'php, css, js, html or text.' ),
						'code'        => array( 'type' => 'string', 'description' => 'The snippet code. For PHP, omit the opening <?php tag.' ),
						'location'    => array( 'type' => 'string', 'enum' => array_keys( VibeSnip_Snippet::locations() ), 'description' => 'Where it runs; must be valid for the type.' ),
						'description' => array( 'type' => 'string' ),
						'priority'    => array( 'type' => 'integer' ),
					),
					'required'             => array( 'title', 'type', 'code' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => $perm,
				'execute_callback'    => array( __CLASS__, 'exec_create_snippet' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'     => false,
						'destructive'  => false,
						'idempotent'   => false,
						'instructions' => __( 'Creates an inactive draft only. Call activate-snippet to make it live.', 'vibesnip' ),
					),
					'show_in_rest' => true,
				),
			)
		);

		// ---- Write: update a snippet --------------------------------------------
		wp_register_ability(
			self::NS . '/update-snippet',
			array(
				'label'               => __( 'Update a snippet', 'vibesnip' ),
				'description'         => __( 'Edit an existing snippet (records a revision). Only the fields you pass are changed. If the snippet is currently active PHP, the new code must pass syntax validation or the edit is rejected, so a live snippet is never overwritten with broken code.', 'vibesnip' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'          => array( 'type' => 'integer', 'description' => 'Snippet ID.' ),
						'title'       => array( 'type' => 'string' ),
						'code'        => array( 'type' => 'string' ),
						'type'        => array( 'type' => 'string', 'enum' => array_keys( VibeSnip_Snippet::types() ) ),
						'location'    => array( 'type' => 'string', 'enum' => array_keys( VibeSnip_Snippet::locations() ) ),
						'description' => array( 'type' => 'string' ),
						'priority'    => array( 'type' => 'integer' ),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => $perm,
				'execute_callback'    => array( __CLASS__, 'exec_update_snippet' ),
				'meta'                => array(
					'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);

		// ---- Write: activate (guarded + health-checked) -------------------------
		wp_register_ability(
			self::NS . '/activate-snippet',
			array(
				'label'               => __( 'Activate a snippet', 'vibesnip' ),
				'description'         => __( 'Make a snippet live. PHP is syntax-validated first (a syntax error is rejected without activating). After activation the front page is loaded in the background so the guard vets it immediately — if it fatals on the front end it is automatically rolled back and "rolled_back" is returned true.', 'vibesnip' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array( 'id' => array( 'type' => 'integer', 'description' => 'Snippet ID.' ) ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => $perm,
				'execute_callback'    => array( __CLASS__, 'exec_activate_snippet' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'     => false,
						'destructive'  => false,
						'idempotent'   => true,
						'instructions' => __( 'Changes site behavior. A fatal on the front end is auto-rolled-back; always check the returned "rolled_back" flag.', 'vibesnip' ),
					),
					'show_in_rest' => true,
				),
			)
		);

		// ---- Write: deactivate --------------------------------------------------
		wp_register_ability(
			self::NS . '/deactivate-snippet',
			array(
				'label'               => __( 'Deactivate a snippet', 'vibesnip' ),
				'description'         => __( 'Switch a snippet off. It stops running immediately on the next request. The code is preserved.', 'vibesnip' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array( 'id' => array( 'type' => 'integer', 'description' => 'Snippet ID.' ) ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => $perm,
				'execute_callback'    => array( __CLASS__, 'exec_deactivate_snippet' ),
				'meta'                => array(
					'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);

		// ---- Write: rollback (the universal undo) -------------------------------
		wp_register_ability(
			self::NS . '/rollback-snippet',
			array(
				'label'               => __( 'Roll back a snippet', 'vibesnip' ),
				'description'         => __( 'The universal undo: deactivates the snippet and, if a "revision_id" is given, restores that earlier revision\'s code and title (recording the restore as a new revision). Use this to recover after a bad change.', 'vibesnip' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'          => array( 'type' => 'integer', 'description' => 'Snippet ID.' ),
						'revision_id' => array( 'type' => 'integer', 'description' => 'Optional revision to restore (from list-revisions in get-snippet output).' ),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => $perm,
				'execute_callback'    => array( __CLASS__, 'exec_rollback_snippet' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'     => false,
						'destructive'  => false,
						'idempotent'   => true,
						'instructions' => __( 'Always safe: it can only turn a snippet off and/or restore known-good earlier code.', 'vibesnip' ),
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/* ================================================================== */
	/* Execute callbacks                                                  */
	/* ================================================================== */

	/**
	 * Flatten a snippet into an ability-friendly array.
	 *
	 * @param VibeSnip_Snippet $s        Snippet.
	 * @param bool             $withCode Include the code + conditions.
	 * @return array
	 */
	private static function shape( $s, $withCode = false ) {
		$out = array(
			'id'         => (int) $s->id,
			'title'      => $s->title,
			'type'       => $s->type,
			'location'   => $s->location,
			'status'     => $s->status,
			'priority'   => (int) $s->priority,
			'source'     => $s->source,
			'updated_at' => $s->updated_at,
		);
		if ( $withCode ) {
			$out['description'] = $s->description;
			$out['code']       = $s->code;
			$out['conditions'] = VibeSnip_Conditions::decode( $s->conditions );
			$out['error']      = $s->error();
		}
		return $out;
	}

	public static function exec_get_site_context( $input = array() ) {
		unset( $input );
		return array(
			'context' => VibeSnip_Context::gather(),
			'summary' => VibeSnip_Context::summary(),
		);
	}

	public static function exec_list_snippets( $input = array() ) {
		$args = array(
			'status' => isset( $input['status'] ) ? (string) $input['status'] : '',
			'type'   => isset( $input['type'] ) ? (string) $input['type'] : '',
			'search' => isset( $input['search'] ) ? (string) $input['search'] : '',
			'number' => 200,
		);
		$rows     = self::repo()->query( $args );
		$snippets = array();
		foreach ( $rows as $row ) {
			$snippets[] = self::shape( $row, false );
		}
		return array(
			'count'    => count( $snippets ),
			'snippets' => $snippets,
		);
	}

	public static function exec_get_snippet( $input = array() ) {
		$id      = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$snippet = self::repo()->get( $id );
		if ( ! $snippet ) {
			return new WP_Error( 'vibesnip_not_found', __( 'No snippet with that ID.', 'vibesnip' ), array( 'status' => 404 ) );
		}
		$out              = self::shape( $snippet, true );
		$revs             = self::repo()->get_revisions( $id, 20 );
		$out['revisions'] = array();
		foreach ( (array) $revs as $r ) {
			$out['revisions'][] = array(
				'id'         => (int) $r->id,
				'message'    => $r->message,
				'created_at' => $r->created_at,
			);
		}
		return $out;
	}

	public static function exec_create_snippet( $input = array() ) {
		self::mark_agent_actor();

		$type = isset( $input['type'] ) ? (string) $input['type'] : 'php';
		$code = isset( $input['code'] ) ? (string) $input['code'] : '';

		$denied = self::denied_for_type( $type );
		if ( $denied ) {
			return $denied;
		}

		$data = array(
			'title'       => isset( $input['title'] ) ? (string) $input['title'] : __( 'Untitled snippet', 'vibesnip' ),
			'description' => isset( $input['description'] ) ? (string) $input['description'] : '',
			'code'        => $code,
			'type'        => $type,
			'location'    => isset( $input['location'] ) ? (string) $input['location'] : '',
			'priority'    => isset( $input['priority'] ) ? (int) $input['priority'] : 10,
			'status'      => 'inactive', // Never active on create — this is the contract.
			'source'      => 'ability',
		);

		$id = self::repo()->create( $data );
		if ( ! $id ) {
			return new WP_Error( 'vibesnip_create_failed', __( 'The snippet could not be created.', 'vibesnip' ) );
		}

		$valid = VibeSnip_Guard::validate( $type, $code );
		return array(
			'id'         => (int) $id,
			'status'     => 'inactive',
			'validation' => array(
				'valid'   => ( true === $valid ),
				'message' => ( true === $valid ) ? '' : (string) $valid,
			),
			'next'       => __( 'Review the validation result, then make it live with activate-snippet — which syntax-checks again and rolls back a front-end fatal.', 'vibesnip' ),
		);
	}

	public static function exec_update_snippet( $input = array() ) {
		self::mark_agent_actor();

		$id      = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$snippet = self::repo()->get( $id );
		if ( ! $snippet ) {
			return new WP_Error( 'vibesnip_not_found', __( 'No snippet with that ID.', 'vibesnip' ), array( 'status' => 404 ) );
		}

		// Start from the existing row, override only the provided fields, so a
		// partial update never wipes untouched columns.
		$data = array(
			'title'       => $snippet->title,
			'description' => $snippet->description,
			'code'        => $snippet->code,
			'type'        => $snippet->type,
			'location'    => $snippet->location,
			'priority'    => (int) $snippet->priority,
			'status'      => $snippet->status,
			'source'      => $snippet->source,
		);
		foreach ( array( 'title', 'description', 'code', 'type', 'location' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$data[ $key ] = (string) $input[ $key ];
			}
		}
		if ( isset( $input['priority'] ) ) {
			$data['priority'] = (int) $input['priority'];
		}
		$conditions = VibeSnip_Conditions::decode( $snippet->conditions );
		if ( $conditions ) {
			$data['conditions'] = $conditions;
		}

		// Both the existing type and the requested one must be authorable, so a PHP
		// snippet cannot be edited — or retyped into PHP — by a caller who may not.
		$denied = self::denied_for_type( $snippet->type );
		if ( ! $denied ) {
			$denied = self::denied_for_type( $data['type'] );
		}
		if ( $denied ) {
			return $denied;
		}

		// Never overwrite a LIVE php snippet with code that would not validate.
		$valid = VibeSnip_Guard::validate( $data['type'], $data['code'] );
		if ( 'active' === $snippet->status && 'php' === $data['type'] && true !== $valid ) {
			return new WP_Error(
				'vibesnip_invalid_live_edit',
				/* translators: %s: validation error message. */
				sprintf( __( 'Refusing to push broken code to a live snippet: %s. Deactivate it first, or fix the syntax.', 'vibesnip' ), (string) $valid )
			);
		}

		$ok = self::repo()->update( $id, $data );
		if ( ! $ok ) {
			return new WP_Error( 'vibesnip_update_failed', __( 'The snippet could not be updated.', 'vibesnip' ) );
		}
		return array(
			'id'         => $id,
			'status'     => $data['status'],
			'validation' => array(
				'valid'   => ( true === $valid ),
				'message' => ( true === $valid ) ? '' : (string) $valid,
			),
		);
	}

	public static function exec_activate_snippet( $input = array() ) {
		self::mark_agent_actor();

		$id      = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$snippet = self::repo()->get( $id );
		if ( ! $snippet ) {
			return new WP_Error( 'vibesnip_not_found', __( 'No snippet with that ID.', 'vibesnip' ), array( 'status' => 404 ) );
		}

		$denied = self::denied_for_type( $snippet->type );
		if ( $denied ) {
			return $denied;
		}

		// Guard: PHP must pass syntax validation before it can go live.
		$valid = VibeSnip_Guard::validate( $snippet->type, $snippet->code );
		if ( true !== $valid ) {
			return new WP_Error(
				'vibesnip_invalid',
				/* translators: %s: validation error message. */
				sprintf( __( 'Not activated — the code has a syntax error: %s', 'vibesnip' ), (string) $valid )
			);
		}

		self::repo()->set_status( $id, 'active' );
		self::repo()->record_error( $id, array() ); // Clear any prior error.

		$rolled_back = false;
		$http        = 0;
		if ( 'php' === $snippet->type && ! VibeSnip_Guard::is_safe_mode() ) {
			// Blocking: an agent calling this ability runs out of the served web
			// request (WP-CLI / a separate process), so there is no self-deadlock,
			// and the caller expects a synchronous rolled_back result.
			$health      = VibeSnip_Guard::health_check( $id, true );
			$rolled_back = ! empty( $health['rolled_back'] );
			$http        = isset( $health['http'] ) ? (int) $health['http'] : 0;
		}

		$fresh = self::repo()->get( $id );
		return array(
			'id'          => $id,
			'status'      => $fresh ? $fresh->status : 'active',
			'rolled_back' => $rolled_back,
			'health_http' => $http,
			'message'     => $rolled_back
				? __( 'Activated but immediately rolled back: it fataled on the front end. Fix the code and try again.', 'vibesnip' )
				: __( 'Activated and healthy.', 'vibesnip' ),
		);
	}

	public static function exec_deactivate_snippet( $input = array() ) {
		self::mark_agent_actor();

		$id      = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$snippet = self::repo()->get( $id );
		if ( ! $snippet ) {
			return new WP_Error( 'vibesnip_not_found', __( 'No snippet with that ID.', 'vibesnip' ), array( 'status' => 404 ) );
		}
		self::repo()->set_status( $id, 'inactive' );
		return array( 'id' => $id, 'status' => 'inactive' );
	}

	public static function exec_rollback_snippet( $input = array() ) {
		self::mark_agent_actor();

		$id      = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$snippet = self::repo()->get( $id );
		if ( ! $snippet ) {
			return new WP_Error( 'vibesnip_not_found', __( 'No snippet with that ID.', 'vibesnip' ), array( 'status' => 404 ) );
		}

		// Always turn it off first — that alone restores a healthy site.
		self::repo()->set_status( $id, 'inactive' );

		$restored = 0;
		if ( ! empty( $input['revision_id'] ) ) {
			$revision_id = (int) $input['revision_id'];
			$match       = null;
			foreach ( (array) self::repo()->get_revisions( $id, 100 ) as $rev ) {
				if ( (int) $rev->id === $revision_id ) {
					$match = $rev;
					break;
				}
			}
			if ( ! $match ) {
				return new WP_Error( 'vibesnip_no_revision', __( 'That revision was not found for this snippet.', 'vibesnip' ), array( 'status' => 404 ) );
			}
			$data = array(
				'title'       => $match->title,
				'description' => $snippet->description,
				'code'        => $match->code,
				'type'        => $snippet->type,
				'location'    => $snippet->location,
				'priority'    => (int) $snippet->priority,
				'status'      => 'inactive',
				'source'      => $snippet->source,
			);
			$conditions = VibeSnip_Conditions::decode( $snippet->conditions );
			if ( $conditions ) {
				$data['conditions'] = $conditions;
			}
			self::repo()->update( $id, $data );
			$restored = $revision_id;
		}

		return array(
			'id'                => $id,
			'status'            => 'inactive',
			'restored_revision' => $restored,
			'message'           => $restored
				? __( 'Deactivated and restored the requested revision.', 'vibesnip' )
				: __( 'Deactivated. The code is unchanged and can be reactivated once fixed.', 'vibesnip' ),
		);
	}
}
