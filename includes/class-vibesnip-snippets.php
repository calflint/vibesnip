<?php
/**
 * Snippet repository: CRUD, revisions and the audit log.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

class VibeSnip_Snippets {

	/**
	 * Whitelisted, sanitised columns for writes.
	 *
	 * @param array $data Raw input.
	 * @return array
	 */
	private function sanitize( $data ) {
		$types    = array_keys( VibeSnip_Snippet::types() );
		$statuses = array( 'active', 'inactive', 'error', 'draft' );

		$type = isset( $data['type'] ) && in_array( $data['type'], $types, true ) ? $data['type'] : 'php';

		// The location must be valid FOR THIS TYPE, not merely a known location.
		// A php snippet saved to site_header/site_body/site_footer would never be
		// eval'd at all: the executor buckets those for output, and render() has no
		// php case, so the code would be echoed verbatim into the page. Since valid
		// PHP includes comments, `// <script>...</script>` passes the syntax check
		// and reaches every visitor as markup. The admin UI filters the dropdown by
		// type, but a hand-crafted POST and the Abilities API both land here, so the
		// pairing is enforced at the repository — the one choke point every write
		// passes through.
		$valid_locs = VibeSnip_Snippet::locations_for_type( $type );
		$loc        = isset( $data['location'] ) && isset( $valid_locs[ $data['location'] ] )
			? $data['location']
			: key( $valid_locs );

		$status = isset( $data['status'] ) && in_array( $data['status'], $statuses, true ) ? $data['status'] : 'inactive';

		$out = array(
			'title'       => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
			'description' => isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : '',
			// Snippet code is intentionally NOT escaped here — it is code. It is
			// validated (PHP) before activation and only ever run by a user with
			// the manage_vibesnip capability. Storage is raw and exact.
			'code'        => isset( $data['code'] ) ? (string) $data['code'] : '',
			'type'        => $type,
			'location'    => $loc,
			'priority'    => isset( $data['priority'] ) ? (int) $data['priority'] : 10,
			'status'      => $status,
			'tags'        => isset( $data['tags'] ) ? sanitize_text_field( $data['tags'] ) : '',
			'source'      => isset( $data['source'] ) ? sanitize_key( $data['source'] ) : 'manual',
			'conditions'  => isset( $data['conditions'] ) ? wp_json_encode( $data['conditions'] ) : '',
			'ai_prompt'   => isset( $data['ai_prompt'] ) ? sanitize_textarea_field( $data['ai_prompt'] ) : '',
			'ai_model'    => isset( $data['ai_model'] ) ? sanitize_text_field( $data['ai_model'] ) : '',
		);
		return $out;
	}

	/**
	 * Create a snippet.
	 *
	 * @param array $data Input.
	 * @return int New ID, or 0 on failure.
	 */
	public function create( $data ) {
		global $wpdb;
		$fields               = $this->sanitize( $data );
		$fields['uuid']       = wp_generate_uuid4();
		$fields['created_by'] = get_current_user_id();
		$fields['created_at'] = current_time( 'mysql' );
		$fields['updated_at'] = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table; no core API covers it, and snippet state must never be served stale so it is deliberately uncached.
		$ok = $wpdb->insert( VibeSnip_DB::snippets(), $fields );
		if ( ! $ok ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;

		$this->add_revision( $id, $fields['title'], $fields['code'], __( 'Created', 'vibesnip' ) );
		$this->log( $id, 'create', 'ok' );
		return $id;
	}

	/**
	 * Update a snippet and record a revision.
	 *
	 * Partial input is supported: sanitize() writes a fixed column list, so any
	 * column the caller omitted would otherwise be written back as its default
	 * and silently blank the stored value (a code-only save would wipe the
	 * title, tags and status). The current row is merged in first so an omitted
	 * key means "leave it alone".
	 *
	 * @param int   $id   Snippet ID.
	 * @param array $data Input; may contain only the columns being changed.
	 * @return bool
	 */
	public function update( $id, $data ) {
		global $wpdb;
		$id = (int) $id;

		$current = $this->get( $id );
		if ( ! $current ) {
			return false;
		}

		$existing = array(
			'title'       => $current->title,
			'description' => $current->description,
			'code'        => $current->code,
			'type'        => $current->type,
			'location'    => $current->location,
			'priority'    => $current->priority,
			'status'      => $current->status,
			'tags'        => $current->tags,
			'source'      => $current->source,
			'ai_prompt'   => $current->ai_prompt,
			'ai_model'    => $current->ai_model,
		);

		// Stored decoded: sanitize() re-encodes, and feeding it the stored JSON
		// string would wrap it in quotes again on every save. Left out entirely
		// when empty so an unconditioned snippet keeps an empty column.
		if ( '' !== $current->conditions && null !== $current->conditions ) {
			$existing['conditions'] = json_decode( $current->conditions, true );
		}

		$fields               = $this->sanitize( array_merge( $existing, $data ) );
		$fields['updated_at'] = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table; no core API covers it, and snippet state must never be served stale so it is deliberately uncached.
		$ok = $wpdb->update( VibeSnip_DB::snippets(), $fields, array( 'id' => $id ) );
		if ( false === $ok ) {
			return false;
		}

		$this->add_revision( $id, $fields['title'], $fields['code'], __( 'Edited', 'vibesnip' ) );
		$this->log( $id, 'update', 'ok' );
		return true;
	}

	/**
	 * Store an AI review report against a snippet.
	 *
	 * Deliberately NOT part of sanitize()/update(): those write a fixed column
	 * list, so an ordinary editor save would blank the stored report. This touches
	 * only the two review columns. It cannot change a snippet's status — the
	 * report is advisory and never activates anything.
	 *
	 * @param int   $id     Snippet ID.
	 * @param array $report Normalised report from VibeSnip_AI::review().
	 * @return bool
	 */
	public function save_review( $id, $report ) {
		global $wpdb;
		$id = (int) $id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table; no core API covers it, and snippet state must never be served stale so it is deliberately uncached.
		$ok = $wpdb->update(
			VibeSnip_DB::snippets(),
			array(
				'ai_review'    => wp_json_encode( $report ),
				'ai_review_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
		if ( false === $ok ) {
			return false;
		}

		$verdict = isset( $report['verdict'] ) ? $report['verdict'] : '';
		$this->log( $id, 'ai_review', 'ok', $verdict );
		return true;
	}

	/**
	 * Set a snippet's status (active/inactive/error) directly.
	 *
	 * @param int    $id     Snippet ID.
	 * @param string $status New status.
	 * @param string $action Audit action label.
	 * @return bool
	 */
	public function set_status( $id, $status, $action = '' ) {
		global $wpdb;
		$id = (int) $id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table; no core API covers it, and snippet state must never be served stale so it is deliberately uncached.
		$ok = $wpdb->update(
			VibeSnip_DB::snippets(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
		if ( false !== $ok ) {
			$this->log( $id, $action ? $action : ( 'active' === $status ? 'activate' : 'deactivate' ), 'ok' );
		}
		return false !== $ok;
	}

	/**
	 * Record a captured error against a snippet.
	 *
	 * @param int   $id    Snippet ID.
	 * @param array $error Error details.
	 */
	public function record_error( $id, $error ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table; no core API covers it, and snippet state must never be served stale so it is deliberately uncached.
		$wpdb->update(
			VibeSnip_DB::snippets(),
			array(
				'error_last' => wp_json_encode( $error ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Delete a snippet (and its revisions).
	 *
	 * @param int $id Snippet ID.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;
		$id = (int) $id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table; no core API covers it, and snippet state must never be served stale so it is deliberately uncached.
		$wpdb->delete( VibeSnip_DB::revisions(), array( 'snippet_id' => $id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table; no core API covers it, and snippet state must never be served stale so it is deliberately uncached.
		$ok = $wpdb->delete( VibeSnip_DB::snippets(), array( 'id' => $id ) );
		$this->log( $id, 'delete', 'ok' );
		return (bool) $ok;
	}

	/**
	 * Duplicate a snippet as an inactive copy.
	 *
	 * @param int $id Snippet ID.
	 * @return int New ID.
	 */
	public function duplicate( $id ) {
		$snippet = $this->get( $id );
		if ( ! $snippet ) {
			return 0;
		}
		$copy = array(
			/* translators: %s: original snippet title. */
			'title'       => sprintf( __( '%s (copy)', 'vibesnip' ), $snippet->title ),
			'description' => $snippet->description,
			'code'        => $snippet->code,
			'type'        => $snippet->type,
			'location'    => $snippet->location,
			'priority'    => $snippet->priority,
			'status'      => 'inactive',
			'tags'        => $snippet->tags,
			'source'      => 'manual',
		);
		$conditions = VibeSnip_Conditions::decode( $snippet->conditions );
		if ( $conditions ) {
			$copy['conditions'] = $conditions;
		}
		return $this->create( $copy );
	}

	/**
	 * Fetch one snippet.
	 *
	 * @param int $id Snippet ID.
	 * @return VibeSnip_Snippet|null
	 */
	public function get( $id ) {
		global $wpdb;
		$table = VibeSnip_DB::snippets();
		// Table name comes from VibeSnip_DB (== $wpdb->prefix), never user input, so interpolation is safe.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- only the table name is interpolated and it comes from VibeSnip_DB ($wpdb->prefix), never user input; every value is bound via prepare(). Custom plugin table, deliberately uncached.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
		return $row ? new VibeSnip_Snippet( $row ) : null;
	}

	/**
	 * Query snippets.
	 *
	 * @param array $args status, type, location, search, orderby, order, number, offset.
	 * @return VibeSnip_Snippet[]
	 */
	public function query( $args = array() ) {
		global $wpdb;
		$table = VibeSnip_DB::snippets();

		$defaults = array(
			'status'  => '',
			'type'    => '',
			'search'  => '',
			'orderby' => 'updated_at',
			'order'   => 'DESC',
			'number'  => 200,
			'offset'  => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = '1=1';
		$params = array();

		if ( $args['status'] ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}
		if ( $args['type'] ) {
			$where   .= ' AND type = %s';
			$params[] = $args['type'];
		}
		if ( $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where   .= ' AND ( title LIKE %s OR description LIKE %s OR tags LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$allowed_orderby = array( 'updated_at', 'created_at', 'title', 'priority', 'status', 'type', 'id' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'updated_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$params[] = (int) $args['number'];
		$params[] = (int) $args['offset'];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix-derived; $where is built from literals above with every value bound as a %s/%d placeholder; $orderby and $order are whitelisted against $allowed_orderby.
		$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql carries only placeholders for user values; $params supplies them in order. Custom plugin table, deliberately uncached.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		return array_map(
			function ( $row ) {
				return new VibeSnip_Snippet( $row );
			},
			$rows ? $rows : array()
		);
	}

	/**
	 * All currently active snippets, ordered for execution (priority ASC).
	 *
	 * @return VibeSnip_Snippet[]
	 */
	public function get_active() {
		global $wpdb;
		$table = VibeSnip_DB::snippets();
		// Table name comes from VibeSnip_DB (== $wpdb->prefix), never user input, so interpolation is safe.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- only the table name is interpolated and it comes from VibeSnip_DB ($wpdb->prefix), never user input; every value is bound via prepare(). Custom plugin table, deliberately uncached.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'active' ORDER BY priority ASC, id ASC" );
		return array_map(
			function ( $row ) {
				return new VibeSnip_Snippet( $row );
			},
			$rows ? $rows : array()
		);
	}

	/**
	 * Count snippets grouped by status (plus 'all').
	 *
	 * @return array
	 */
	public function counts() {
		global $wpdb;
		$table = VibeSnip_DB::snippets();
		// Table name comes from VibeSnip_DB (== $wpdb->prefix), never user input, so interpolation is safe.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- only the table name is interpolated and it comes from VibeSnip_DB ($wpdb->prefix), never user input; every value is bound via prepare(). Custom plugin table, deliberately uncached.
		$rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		$counts = array(
			'all'      => 0,
			'active'   => 0,
			'inactive' => 0,
			'error'    => 0,
			'draft'    => 0,
		);
		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['n'];
			$counts['all']           += (int) $row['n'];
		}
		return $counts;
	}

	/* ------------------------------------------------------------------ */
	/* Revisions + audit log                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Store a revision snapshot.
	 */
	public function add_revision( $snippet_id, $title, $code, $message = '' ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table; no core API covers it, and snippet state must never be served stale so it is deliberately uncached.
		$wpdb->insert(
			VibeSnip_DB::revisions(),
			array(
				'snippet_id'  => (int) $snippet_id,
				'title'       => $title,
				'code'        => $code,
				'message'     => $message,
				'author_type' => 'user',
				'author_id'   => get_current_user_id(),
				'created_at'  => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Recent revisions for a snippet.
	 *
	 * @return array
	 */
	public function get_revisions( $snippet_id, $limit = 20 ) {
		global $wpdb;
		$table = VibeSnip_DB::revisions();
		// Table name comes from VibeSnip_DB (== $wpdb->prefix), never user input, so interpolation is safe.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- only the table name is interpolated and it comes from VibeSnip_DB ($wpdb->prefix), never user input; every value is bound via prepare(). Custom plugin table, deliberately uncached.
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE snippet_id = %d ORDER BY id DESC LIMIT %d", (int) $snippet_id, (int) $limit ) );
	}

	/**
	 * Append to the audit log.
	 *
	 * @param int    $snippet_id Snippet ID (0 for global events).
	 * @param string $action     Action slug.
	 * @param string $result     ok|error|warning.
	 * @param string $message    Optional detail.
	 */
	public function log( $snippet_id, $action, $result = 'ok', $message = '' ) {
		global $wpdb;
		$is_ai = defined( 'VIBESNIP_ACTOR_AI' ) && VIBESNIP_ACTOR_AI;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table; no core API covers it, and snippet state must never be served stale so it is deliberately uncached.
		$wpdb->insert(
			VibeSnip_DB::runs(),
			array(
				'snippet_id' => (int) $snippet_id,
				'action'     => $action,
				'actor_type' => $is_ai ? 'ai' : 'user',
				'actor_id'   => get_current_user_id(),
				'result'     => $result,
				'message'    => $message,
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Recent audit entries.
	 *
	 * @return array
	 */
	public function get_log( $limit = 50 ) {
		global $wpdb;
		$table = VibeSnip_DB::runs();
		// Table name comes from VibeSnip_DB (== $wpdb->prefix), never user input, so interpolation is safe.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- only the table name is interpolated and it comes from VibeSnip_DB ($wpdb->prefix), never user input; every value is bound via prepare(). Custom plugin table, deliberately uncached.
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", (int) $limit ) );
	}
}
