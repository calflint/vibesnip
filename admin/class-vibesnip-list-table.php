<?php
/**
 * The snippet manager list table.
 *
 * @package VibeSnip
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class VibeSnip_List_Table extends WP_List_Table {

	/** @var VibeSnip_Snippets */
	private $repo;

	public function __construct( $repo ) {
		$this->repo = $repo;
		parent::__construct(
			array(
				'singular' => 'snippet',
				'plural'   => 'snippets',
				'ajax'     => false,
			)
		);
	}

	protected function get_default_primary_column_name() {
		return 'title';
	}

	public function get_columns() {
		return array(
			'status'   => '',
			'title'    => __( 'Name', 'vibesnip' ),
			'type'     => __( 'Type', 'vibesnip' ),
			'location' => __( 'Location', 'vibesnip' ),
			'priority' => __( 'Priority', 'vibesnip' ),
			'tags'     => __( 'Tags', 'vibesnip' ),
			'updated'  => __( 'Last updated', 'vibesnip' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'title'    => array( 'title', false ),
			'priority' => array( 'priority', false ),
			'updated'  => array( 'updated_at', true ),
		);
	}

	public function get_views() {
		$counts  = $this->repo->counts();
		$current = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which status filter tab is selected. Changes no state.
		$base    = admin_url( 'admin.php?page=vibesnip' );

		$make = function ( $key, $label ) use ( $counts, $current, $base ) {
			$url   = 'all' === $key ? $base : add_query_arg( 'status', $key, $base );
			$class = $current === $key ? ' class="current"' : '';
			$n     = isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
			return sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( $url ), $class, esc_html( $label ), $n );
		};

		return array(
			'all'      => $make( 'all', __( 'All', 'vibesnip' ) ),
			'active'   => $make( 'active', __( 'Active', 'vibesnip' ) ),
			'inactive' => $make( 'inactive', __( 'Inactive', 'vibesnip' ) ),
			'error'    => $make( 'error', __( 'Errored', 'vibesnip' ) ),
		);
	}

	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status  = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		// Default sort comes from Settings → General; a clicked column header wins.
		$default_orderby = VibeSnip_Admin::prefs()['list_order'];
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : $default_orderby;
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : ( 'title' === $default_orderby ? 'asc' : 'desc' );
		// phpcs:enable

		$this->items = $this->repo->query(
			array(
				'status'  => ( 'all' === $status ) ? '' : $status,
				'search'  => $search,
				'orderby' => $orderby,
				'order'   => $order,
				'number'  => 300,
			)
		);
	}

	private function action_url( $action, $id ) {
		$url = add_query_arg(
			array(
				'page'      => 'vibesnip',
				'vs_action' => $action,
				'id'        => $id,
			),
			admin_url( 'admin.php' )
		);
		return wp_nonce_url( $url, 'vibesnip_' . $action . '_' . $id );
	}

	public function column_status( $snippet ) {
		if ( $snippet->is_active() ) {
			$url   = $this->action_url( 'deactivate', $snippet->id );
			$class = 'vs-toggle on';
			$label = __( 'Active — click to deactivate', 'vibesnip' );
		} elseif ( 'error' === $snippet->status ) {
			$url   = $this->action_url( 'activate', $snippet->id );
			$class = 'vs-toggle err';
			$label = __( 'Errored — fix, then click to re-activate', 'vibesnip' );
		} else {
			$url   = $this->action_url( 'activate', $snippet->id );
			$class = 'vs-toggle off';
			$label = __( 'Inactive — click to activate', 'vibesnip' );
		}
		return sprintf(
			'<a href="%s" class="%s" title="%s" aria-label="%s"><span class="vs-knob"></span></a>',
			esc_url( $url ),
			esc_attr( $class ),
			esc_attr( $label ),
			esc_attr( $label )
		);
	}

	public function column_title( $snippet ) {
		$edit = admin_url( 'admin.php?page=vibesnip-edit&id=' . $snippet->id );

		$name = sprintf(
			'<strong><a class="row-title" href="%s">%s</a></strong>',
			esc_url( $edit ),
			esc_html( $snippet->title ? $snippet->title : __( '(no title)', 'vibesnip' ) )
		);

		if ( 'error' === $snippet->status ) {
			$err = $snippet->error();
			if ( $err && ! empty( $err['message'] ) ) {
				$name .= '<div class="vs-inline-err">' . esc_html( wp_trim_words( $err['message'], 20 ) );
				if ( ! empty( $err['line'] ) ) {
					$name .= ' <em>(line ' . (int) $err['line'] . ')</em>';
				}
				$name .= '</div>';
			}
		}
		if ( 'manual' !== $snippet->source ) {
			$name .= ' <span class="vs-chip vs-source">' . esc_html( $snippet->source ) . '</span>';
		}

		// The AI's verdict, if the snippet has been reviewed, so the assessment
		// travels with the snippet instead of living only in the editor. Advisory:
		// it says nothing about whether the code passed validation.
		$review = $snippet->review();
		if ( $review && ! empty( $review['verdict'] ) ) {
			$labels = array(
				'safe'    => __( 'AI: looks safe', 'vibesnip' ),
				'caution' => __( 'AI: use caution', 'vibesnip' ),
				'unsafe'  => __( 'AI: not safe', 'vibesnip' ),
			);
			if ( isset( $labels[ $review['verdict'] ] ) ) {
				$name .= sprintf(
					' <span class="vs-chip vs-verdict-%s" title="%s">%s</span>',
					esc_attr( $review['verdict'] ),
					esc_attr( isset( $review['summary'] ) ? $review['summary'] : '' ),
					esc_html( $labels[ $review['verdict'] ] )
				);
			}
		}

		$actions = array(
			'edit' => sprintf( '<a href="%s">%s</a>', esc_url( $edit ), esc_html__( 'Edit', 'vibesnip' ) ),
		);
		if ( $snippet->is_active() ) {
			$actions['deactivate'] = sprintf( '<a href="%s">%s</a>', esc_url( $this->action_url( 'deactivate', $snippet->id ) ), esc_html__( 'Deactivate', 'vibesnip' ) );
		} else {
			$actions['activate'] = sprintf( '<a href="%s">%s</a>', esc_url( $this->action_url( 'activate', $snippet->id ) ), esc_html__( 'Activate', 'vibesnip' ) );
		}
		$actions['duplicate'] = sprintf( '<a href="%s">%s</a>', esc_url( $this->action_url( 'duplicate', $snippet->id ) ), esc_html__( 'Duplicate', 'vibesnip' ) );
		$actions['delete']    = sprintf(
			'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
			esc_url( $this->action_url( 'delete', $snippet->id ) ),
			esc_js( __( 'Delete this snippet permanently?', 'vibesnip' ) ),
			esc_html__( 'Delete', 'vibesnip' )
		);

		return $name . $this->row_actions( $actions );
	}

	public function column_type( $snippet ) {
		return '<span class="vs-lang vs-lang-' . esc_attr( $snippet->type ) . '">' . esc_html( strtoupper( $snippet->type ) ) . '</span>';
	}

	public function column_location( $snippet ) {
		return esc_html( VibeSnip_Snippet::location_label( $snippet->location ) );
	}

	public function column_priority( $snippet ) {
		return (int) $snippet->priority;
	}

	public function column_tags( $snippet ) {
		if ( ! $snippet->tags ) {
			return '<span class="vs-muted">&mdash;</span>';
		}
		$out = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', $snippet->tags ) ) ) as $tag ) {
			$out[] = '<span class="vs-tag">' . esc_html( $tag ) . '</span>';
		}
		return implode( ' ', $out );
	}

	public function column_updated( $snippet ) {
		if ( empty( $snippet->updated_at ) || '0000-00-00 00:00:00' === $snippet->updated_at ) {
			return '<span class="vs-muted">&mdash;</span>';
		}
		return esc_html( VibeSnip_Admin::format_time( $snippet->updated_at ) );
	}

	public function column_default( $snippet, $column_name ) {
		return isset( $snippet->$column_name ) ? esc_html( $snippet->$column_name ) : '';
	}

	public function no_items() {
		esc_html_e( 'No snippets yet. Add your first one — it stays inactive until you switch it on.', 'vibesnip' );
	}
}
