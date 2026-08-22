<?php
/**
 * Harness: VibeSnip_Snippets::sanitize() must pair location WITH type.
 *
 * A php snippet stored against site_header/site_body/site_footer is never eval'd —
 * the executor buckets those locations for output and render() has no php case, so
 * the code would be echoed verbatim into the page. Valid PHP includes comments, so
 * `// <script>...</script>` passes the syntax check and reaches every visitor as
 * markup. The admin UI filters the dropdown by type, but a hand-crafted POST and the
 * Abilities API both write straight through the repository, so the pairing has to be
 * enforced there. This asserts it is.
 *
 * Run: php tests/harness-location.php
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
function current_time( $t ) { return '2026-08-12 00:00:00'; }
function get_current_user_id() { return 1; }
function absint( $n ) { return abs( (int) $n ); }
function maybe_serialize( $d ) { return is_array( $d ) ? serialize( $d ) : $d; }
function wp_parse_args( $a, $d = array() ) { return array_merge( $d, (array) $a ); }

require_once __DIR__ . '/../includes/class-vibesnip-snippet.php';
require_once __DIR__ . '/../includes/class-vibesnip-snippets.php';

$repo   = ( new ReflectionClass( 'VibeSnip_Snippets' ) )->newInstanceWithoutConstructor();
$method = new ReflectionMethod( 'VibeSnip_Snippets', 'sanitize' );

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

$run = function ( $type, $location ) use ( $repo, $method ) {
	$out = $method->invoke( $repo, array( 'type' => $type, 'location' => $location, 'code' => 'x' ) );
	return $out['location'];
};

echo "\n--- The attack: php snippet aimed at an output bucket ---\n";
check( 'php + site_header  is rejected', $run( 'php', 'site_header' ), 'everywhere' );
check( 'php + site_body    is rejected', $run( 'php', 'site_body' ),   'everywhere' );
check( 'php + site_footer  is rejected', $run( 'php', 'site_footer' ), 'everywhere' );

echo "\n--- Legitimate pairings still survive ---\n";
check( 'php  + everywhere',    $run( 'php',  'everywhere' ),    'everywhere' );
check( 'php  + admin_only',    $run( 'php',  'admin_only' ),    'admin_only' );
check( 'php  + frontend_only', $run( 'php',  'frontend_only' ), 'frontend_only' );
check( 'php  + shortcode',     $run( 'php',  'shortcode' ),     'shortcode' );
check( 'css  + site_header',   $run( 'css',  'site_header' ),   'site_header' );
check( 'css  + site_footer',   $run( 'css',  'site_footer' ),   'site_footer' );
check( 'js   + site_body',     $run( 'js',   'site_body' ),     'site_body' );
check( 'html + site_footer',   $run( 'html', 'site_footer' ),   'site_footer' );
check( 'text + site_header',   $run( 'text', 'site_header' ),   'site_header' );

echo "\n--- Non-php types must not inherit php-only locations ---\n";
check( 'css  + everywhere     falls back', $run( 'css',  'everywhere' ),    'site_header' );
check( 'js   + admin_only     falls back', $run( 'js',   'admin_only' ),    'site_header' );
check( 'html + frontend_only  falls back', $run( 'html', 'frontend_only' ), 'site_header' );

echo "\n--- Junk input never yields an empty location ---\n";
check( 'php  + garbage', $run( 'php',  'nonsense' ), 'everywhere' );
check( 'css  + garbage', $run( 'css',  'nonsense' ), 'site_header' );
check( 'text + empty',   $run( 'text', '' ),         'site_header' );

echo "\n--- Every type yields a real default (key() on an empty set would be null) ---\n";
foreach ( array_keys( VibeSnip_Snippet::types() ) as $vibesnip_type ) {
	$got = $run( $vibesnip_type, 'total-garbage' );
	check( "type '$vibesnip_type' default is a real location", ( '' !== $got && null !== $got ), true );
}

echo "\n========================================\n";
echo "  $pass passed, $fail failed\n";
echo "========================================\n";
exit( $fail > 0 ? 1 : 0 );
