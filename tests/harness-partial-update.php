<?php
/**
 * Harness: VibeSnip_Snippets::update() must not blank the columns it wasn't given.
 *
 * sanitize() returns a fixed column list, so update() writes every column on every
 * call. Before the merge below, a caller that passed only `code` also wrote the
 * sanitize() defaults over everything else: the title became '', tags became '',
 * and — worst of all — an *active* snippet silently dropped back to 'inactive',
 * because 'inactive' is the default status. The Abilities layer had been quietly
 * re-reading the row and resending every field to work around exactly this, which
 * is the tell that it was a repository bug, not a caller bug.
 *
 * Run: php tests/harness-partial-update.php
 *
 * @package VibeSnip
 */

define( 'ABSPATH', true );

function __( $s, $d = null ) { return $s; }
function sanitize_text_field( $s ) { return trim( wp_strip_all_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( wp_strip_all_tags( (string) $s ) ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $s ) ); }
function wp_json_encode( $d ) { return json_encode( $d ); }
function current_time( $t ) { return '2026-08-17 00:00:00'; }
function get_current_user_id() { return 1; }
function wp_generate_uuid4() { return 'uuid-0000'; }
function absint( $n ) { return abs( (int) $n ); }

/**
 * Enough of $wpdb to exercise the repository: rows in memory, keyed by id.
 */
class VibeSnip_Fake_Wpdb {
	public $rows      = array();
	public $insert_id = 0;

	public function insert( $table, $fields ) {
		$this->insert_id      = count( $this->rows ) + 1;
		$fields['id']         = $this->insert_id;
		$this->rows[ $this->insert_id ] = $fields;
		return 1;
	}

	public function update( $table, $fields, $where ) {
		$id = (int) $where['id'];
		if ( ! isset( $this->rows[ $id ] ) ) {
			return 0;
		}
		$this->rows[ $id ] = array_merge( $this->rows[ $id ], $fields );
		return 1;
	}

	public function get_row( $query ) {
		preg_match( '/id = (\d+)/', $query, $m );
		$id = isset( $m[1] ) ? (int) $m[1] : 0;
		return isset( $this->rows[ $id ] ) ? (object) $this->rows[ $id ] : null;
	}

	public function prepare( $query, ...$args ) {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}
}

class VibeSnip_DB {
	public static function snippets() { return 'snippets'; }
	public static function revisions() { return 'revisions'; }
	public static function log() { return 'log'; }
	public static function runs() { return 'runs'; }
}

global $wpdb;
$wpdb = new VibeSnip_Fake_Wpdb();

require_once __DIR__ . '/../includes/class-vibesnip-snippet.php';
require_once __DIR__ . '/../includes/class-vibesnip-snippets.php';

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	if ( $got === $want ) {
		$pass++;
		echo "  PASS  $label\n";
	} else {
		$fail++;
		echo "  FAIL  $label — got '" . var_export( $got, true ) . "', want '" . var_export( $want, true ) . "'\n";
	}
}

$repo = new VibeSnip_Snippets();

echo "\n--- A code-only save keeps everything else ---\n";
$id = $repo->create(
	array(
		'title'       => 'Disable comments',
		'description' => 'Turns comments off site-wide.',
		'code'        => '<?php echo 1;',
		'type'        => 'php',
		'location'    => 'admin_only',
		'priority'    => 5,
		'status'      => 'active',
		'tags'        => 'cleanup,comments',
		'source'      => 'ai',
		'ai_prompt'   => 'turn off comments',
		'ai_model'    => 'claude-opus-5',
	)
);

check( 'update() reports success', $repo->update( $id, array( 'code' => '<?php echo 2;' ) ), true );

$after = $repo->get( $id );
check( 'code is the new code', $after->code, '<?php echo 2;' );
check( 'title survives',       $after->title, 'Disable comments' );
check( 'description survives', $after->description, 'Turns comments off site-wide.' );
check( 'type survives',        $after->type, 'php' );
check( 'location survives',    $after->location, 'admin_only' );
check( 'priority survives',    $after->priority, 5 );
check( 'STATUS survives (an active snippet must not silently deactivate)', $after->status, 'active' );
check( 'tags survive',         $after->tags, 'cleanup,comments' );
check( 'source survives',      $after->source, 'ai' );
check( 'ai_prompt survives',   $after->ai_prompt, 'turn off comments' );
check( 'ai_model survives',    $after->ai_model, 'claude-opus-5' );

echo "\n--- A full save still writes every field ---\n";
$repo->update(
	$id,
	array(
		'title'  => 'Renamed',
		'code'   => '<?php echo 3;',
		'type'   => 'php',
		'status' => 'inactive',
		'tags'   => 'other',
	)
);
$after = $repo->get( $id );
check( 'title changed',  $after->title, 'Renamed' );
check( 'status changed', $after->status, 'inactive' );
check( 'tags changed',   $after->tags, 'other' );

echo "\n--- conditions must not gain a layer of JSON on every save ---\n";
$conditional = $repo->create(
	array(
		'title'      => 'Conditional',
		'code'       => '<?php echo 4;',
		'type'       => 'php',
		'conditions' => array( 'logged_in' => true ),
	)
);
$repo->update( $conditional, array( 'code' => '<?php echo 5;' ) );
$repo->update( $conditional, array( 'code' => '<?php echo 6;' ) );
$after = $repo->get( $conditional );
check( 'still decodes to the same array after two saves', json_decode( $after->conditions, true ), array( 'logged_in' => true ) );

$plain = $repo->create( array( 'title' => 'Plain', 'code' => '<?php echo 7;', 'type' => 'php' ) );
$repo->update( $plain, array( 'code' => '<?php echo 8;' ) );
$after = $repo->get( $plain );
check( 'an unconditioned snippet keeps an empty conditions column', (string) $after->conditions, '' );

echo "\n--- A missing snippet is a failed update, not a stray insert ---\n";
check( 'update() on an unknown id returns false', $repo->update( 99999, array( 'code' => 'x' ) ), false );

echo "\n========================================\n";
echo "  $pass passed, $fail failed\n";
echo "========================================\n";
exit( $fail > 0 ? 1 : 0 );
