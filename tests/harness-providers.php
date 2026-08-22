<?php
/**
 * Provider adapter harness. Runs on plain PHP, no WordPress:
 *
 *     php tests/harness-providers.php
 *
 * Feeds four recorded response shapes through each adapter's extraction path
 * and asserts the four outcomes an owner can actually hit: a usable answer, a
 * refusal, a vendor error, and a reply cut off by the token budget. The
 * schema-dialect and envelope differences between vendors are exactly the kind
 * of bug that lints clean and fails in production, so this is cheap insurance.
 *
 * It also pins the P1 refactor: the two golden hashes below were captured from
 * the pre-refactor class-vibesnip-ai.php (commit 0e2606b, before the provider
 * layer existed) by feeding it the same Anthropic fixture. If either changes,
 * the "pure refactor" claim is false.
 *
 * @package VibeSnip
 */

define( 'ABSPATH', __DIR__ . '/' );

// ------------------------------------------------------------ WordPress stubs

function __( $text, $domain = null ) {
	return $text;
}

function esc_html__( $text, $domain = null ) {
	return $text;
}

function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags );
}

function sanitize_text_field( $str ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $str ) );
}

function sanitize_textarea_field( $str ) {
	return trim( (string) $str );
}

function current_time( $type ) {
	return '2026-01-01 00:00:00';
}

function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, (array) $args );
}

function apply_filters( $hook, $value ) {
	return $value;
}

/** What the next wp_remote_post() should hand back; set per test case. */
$GLOBALS['vibesnip_test_response'] = array();

function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['vibesnip_test_url'] = $url;
	return $GLOBALS['vibesnip_test_response'];
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_salt( $scheme = 'auth' ) {
	return 'harness-salt-not-a-real-site-' . $scheme;
}

/** Transients: an in-memory store is enough to exercise the model-list cache. */
$GLOBALS['vibesnip_test_transients'] = array();

function get_transient( $key ) {
	return isset( $GLOBALS['vibesnip_test_transients'][ $key ] ) ? $GLOBALS['vibesnip_test_transients'][ $key ] : false;
}

function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['vibesnip_test_transients'][ $key ] = $value;
	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['vibesnip_test_transients'][ $key ] );
	return true;
}

/** What the next wp_remote_get() should hand back; set per test case. */
$GLOBALS['vibesnip_test_get_response'] = array();

function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['vibesnip_test_get_url'] = $url;
	return $GLOBALS['vibesnip_test_get_response'];
}

define( 'WEEK_IN_SECONDS', 604800 );

/** The provider VibeSnip_AI::settings() should report; set per test case. */
$GLOBALS['vibesnip_test_provider'] = 'anthropic';

/** Extra stored settings for a case that needs the per-provider maps. */
$GLOBALS['vibesnip_test_settings'] = array();

function get_option( $name, $default = false ) {
	return array_merge(
		array(
			// AI is off on a new install, but every assertion here is about what the
			// adapters do once it is on, so the harness site has it switched on.
			'ai_enabled'    => true,
			'provider'      => $GLOBALS['vibesnip_test_provider'],
			'api_key'       => 'test-key-never-asserted-on',
			'model'         => 'claude-opus-5',
			'php_autoapply' => false,
		),
		$GLOBALS['vibesnip_test_settings']
	);
}

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

/** Only the parts of the snippet model that the AI validators read. */
class VibeSnip_Snippet {
	public static function types() {
		return array( 'php' => 'PHP', 'css' => 'CSS', 'js' => 'JavaScript', 'html' => 'HTML', 'text' => 'Text' );
	}

	public static function locations() {
		return array(
			'everywhere'    => array( 'Run everywhere', array( 'php' ) ),
			'admin_only'    => array( 'Admin area only', array( 'php' ) ),
			'frontend_only' => array( 'Front-end only', array( 'php' ) ),
			'site_header'   => array( 'Site-wide header', array( 'css', 'js', 'html', 'text' ) ),
			'site_body'     => array( 'Site-wide body', array( 'js', 'html', 'text' ) ),
			'site_footer'   => array( 'Site-wide footer', array( 'css', 'js', 'html', 'text' ) ),
			'shortcode'     => array( 'Shortcode only', array( 'php', 'css', 'js', 'html', 'text' ) ),
		);
	}

	public static function locations_for_type( $type ) {
		$out = array();
		foreach ( self::locations() as $slug => $meta ) {
			if ( in_array( $type, $meta[1], true ) ) {
				$out[ $slug ] = $meta[0];
			}
		}
		return $out;
	}
}

require_once __DIR__ . '/../includes/class-vibesnip-keys.php';
require_once __DIR__ . '/../includes/class-vibesnip-provider.php';
require_once __DIR__ . '/../includes/class-vibesnip-ai.php';

// ----------------------------------------------------------------- assertions

$failures = 0;

function check( $label, $passed, $detail = '' ) {
	global $failures;
	if ( $passed ) {
		echo "PASS  $label\n";
		return;
	}
	$failures++;
	echo "FAIL  $label" . ( '' !== $detail ? "\n        $detail" : '' ) . "\n";
}

/** Build the array wp_remote_post() would have returned. */
function http( $code, array $body ) {
	return array(
		'response' => array( 'code' => $code ),
		'body'     => json_encode( $body ),
	);
}

// ------------------------------------------------------------------- fixtures

$generate_json = '{"snippets":[{"title":"Disable the admin bar","description":"Hides the admin toolbar for non-administrators.","type":"php","location":"everywhere","code":"add_action( \'after_setup_theme\', function () {\n\tif ( ! current_user_can( \'manage_options\' ) ) {\n\t\tshow_admin_bar( false );\n\t}\n} );","explanation":"Runs on every page load and hides the toolbar unless the user can manage options."}]}';

$review_json = '{"summary":"Hides the admin toolbar for anyone who cannot manage options.","verdict":"caution","what_it_does":"Registers a hook that switches the toolbar off.","site_impact":"Editors and subscribers stop seeing the black bar.","risks":["Anyone relying on the toolbar shortcuts loses them."],"recommended_type":"php","recommended_location":"everywhere","recommended_conditions":"","suggested_title":"Hide admin bar","suggested_tags":["admin","ui"],"should_activate":true,"notes":"Log in as a subscriber and confirm the bar is gone."}';

/**
 * Recorded response shapes per vendor. Each entry is [ http status, body ].
 */
$fixtures = array(
	'anthropic' => array(
		'success'   => array(
			200,
			array(
				'id'          => 'msg_01',
				'type'        => 'message',
				'role'        => 'assistant',
				'model'       => 'claude-opus-5',
				'stop_reason' => 'end_turn',
				'content'     => array( array( 'type' => 'text', 'text' => $generate_json ) ),
			),
		),
		'refusal'   => array(
			200,
			array(
				'id'           => 'msg_02',
				'stop_reason'  => 'refusal',
				'stop_details' => array( 'type' => 'refusal', 'category' => 'cyber' ),
				'content'      => array(),
			),
		),
		'error'     => array(
			400,
			array( 'type' => 'error', 'error' => array( 'type' => 'invalid_request_error', 'message' => 'max_tokens: must be greater than 0' ) ),
		),
		'truncated' => array(
			200,
			array(
				'id'          => 'msg_03',
				'stop_reason' => 'max_tokens',
				'content'     => array( array( 'type' => 'text', 'text' => '{"snippets":[{"title":"Disa' ) ),
			),
		),
	),
	'openai'    => array(
		'success'   => array(
			200,
			array(
				'id'      => 'chatcmpl-01',
				'object'  => 'chat.completion',
				'model'   => 'gpt-5.1',
				'choices' => array(
					array(
						'index'         => 0,
						'message'       => array( 'role' => 'assistant', 'content' => $generate_json, 'refusal' => null ),
						'finish_reason' => 'stop',
					),
				),
			),
		),
		'refusal'   => array(
			200,
			array(
				'id'      => 'chatcmpl-02',
				'choices' => array(
					array(
						'index'         => 0,
						'message'       => array( 'role' => 'assistant', 'content' => null, 'refusal' => 'I cannot help with that request.' ),
						'finish_reason' => 'stop',
					),
				),
			),
		),
		'error'     => array(
			401,
			array( 'error' => array( 'message' => 'Incorrect API key provided.', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key' ) ),
		),
		'truncated' => array(
			200,
			array(
				'id'      => 'chatcmpl-03',
				'choices' => array(
					array(
						'index'         => 0,
						'message'       => array( 'role' => 'assistant', 'content' => '{"snippets":[{"title":"Disa', 'refusal' => null ),
						'finish_reason' => 'length',
					),
				),
			),
		),
	),
	'google'    => array(
		'success'   => array(
			200,
			array(
				'candidates' => array(
					array(
						'content'      => array(
							'role'  => 'model',
							// Gemini often splits one JSON document across parts.
							'parts' => array(
								array( 'text' => substr( $generate_json, 0, 40 ) ),
								array( 'text' => substr( $generate_json, 40 ) ),
							),
						),
						'finishReason' => 'STOP',
					),
				),
			),
		),
		'refusal'   => array(
			200,
			array(
				'candidates' => array(
					array(
						'content'      => array( 'role' => 'model', 'parts' => array() ),
						'finishReason' => 'SAFETY',
					),
				),
			),
		),
		'error'     => array(
			400,
			array( 'error' => array( 'code' => 400, 'message' => 'API key not valid. Please pass a valid API key.', 'status' => 'INVALID_ARGUMENT' ) ),
		),
		'truncated' => array(
			200,
			array(
				'candidates' => array(
					array(
						'content'      => array( 'role' => 'model', 'parts' => array( array( 'text' => '{"snippets":[{"title":"Disa' ) ) ),
						'finishReason' => 'MAX_TOKENS',
					),
				),
			),
		),
	),
);

// The OpenAI-compatible adapter is the OpenAI one with the address and model
// left to the owner, so it must behave identically on every wire-format case.
// Running it through the same four fixtures is what keeps that true.
$fixtures['openai_compatible'] = $fixtures['openai'];

$vendor_errors = array(
	'anthropic'         => 'max_tokens: must be greater than 0',
	'openai'            => 'Incorrect API key provided.',
	'google'            => 'API key not valid. Please pass a valid API key.',
	'openai_compatible' => 'Incorrect API key provided.',
);

// ------------------------------------------------------- four cases × three adapters

foreach ( $fixtures as $id => $cases ) {
	$GLOBALS['vibesnip_test_provider'] = $id;
	$provider                          = VibeSnip_Provider::current();

	check(
		"$id: adapter selected from settings",
		$provider::id() === $id,
		'got ' . $provider::id()
	);

	// 1. A well-formed reply yields the JSON the model wrote, byte for byte.
	list( , $body ) = $cases['success'];
	$json           = $provider::extract_json( $body );
	check(
		"$id: success yields the expected JSON string",
		$json === $generate_json,
		is_string( $json ) ? 'got ' . substr( $json, 0, 80 ) : 'got a ' . gettype( $json )
	);

	// 2. A refusal names the model declining, not a parse failure.
	list( $code, $body ) = $cases['refusal'];
	$result              = VibeSnip_AI::parse_response( http( $code, $body ) );
	check(
		"$id: refusal is a WP_Error mentioning the model declining",
		is_wp_error( $result ) && 'vibesnip_refusal' === $result->get_error_code()
			&& false !== stripos( $result->get_error_message(), 'declined' ),
		describe( $result )
	);

	// 3. A non-200 carries the vendor's own message through.
	list( $code, $body ) = $cases['error'];
	$result              = VibeSnip_AI::parse_response( http( $code, $body ) );
	$expected_message    = 401 === $code
		? 'Your API key was rejected. Check it on the Settings page.'
		: $vendor_errors[ $id ];
	check(
		"$id: non-200 carries the vendor message",
		is_wp_error( $result ) && $result->get_error_message() === $expected_message,
		describe( $result )
	);

	// 4. A reply cut short by the token budget is its own, distinct error.
	list( $code, $body ) = $cases['truncated'];
	$result              = VibeSnip_AI::parse_response( http( $code, $body ) );
	check(
		"$id: truncated reply is a distinct 'cut off' error",
		is_wp_error( $result ) && 'vibesnip_truncated' === $result->get_error_code()
			&& false !== stripos( $result->get_error_message(), 'cut off' ),
		describe( $result )
	);
}

function describe( $result ) {
	if ( is_wp_error( $result ) ) {
		return 'got [' . $result->get_error_code() . '] ' . $result->get_error_message();
	}
	return 'got a ' . gettype( $result ) . ': ' . substr( json_encode( $result ), 0, 120 );
}

// ------------------------------------------- Test connection reports the truth
//
// The bug this pins: with Anthropic saved, choosing OpenAI on the Settings screen
// and pressing Test connection answered "Connected to Anthropic (Claude)". The
// button now tests what is on screen, so it must name that provider and that
// model — and must leave the stored settings exactly as it found them.

$GLOBALS['vibesnip_test_provider'] = 'anthropic';
$GLOBALS['vibesnip_test_response'] = http( 200, $fixtures['openai']['success'][1] );

$tested = VibeSnip_AI::test_connection(
	array(
		'provider' => 'openai',
		'api_key'  => 'sk-typed-but-not-saved-yet',
		'model'    => 'gpt-5.1-mini',
	)
);

check(
	'test connection names the provider on screen, not the saved one',
	is_array( $tested ) && 'openai' === $tested['provider'] && 'gpt-5.1-mini' === $tested['model'],
	describe( $tested )
);

check(
	'test connection sent the request to the on-screen provider',
	isset( $GLOBALS['vibesnip_test_url'] ) && 'https://api.openai.com/v1/chat/completions' === $GLOBALS['vibesnip_test_url'],
	'got ' . ( isset( $GLOBALS['vibesnip_test_url'] ) ? $GLOBALS['vibesnip_test_url'] : 'nothing' )
);

check(
	'the override is dropped once the test is over',
	'anthropic' === VibeSnip_AI::settings()['provider'],
	'got ' . VibeSnip_AI::settings()['provider']
);

// An OpenAI-compatible gateway has no address or model VibeSnip could guess, so
// a half-filled one must say which half is missing rather than fail on an empty URL.
$GLOBALS['vibesnip_test_provider'] = 'openai_compatible';
$GLOBALS['vibesnip_test_settings'] = array( 'api_keys' => array( 'openai_compatible' => 'k' ) );

$incomplete = VibeSnip_AI::test_connection();
check(
	'an OpenAI-compatible provider with no address or model says so',
	is_wp_error( $incomplete ) && 'vibesnip_provider_incomplete' === $incomplete->get_error_code(),
	describe( $incomplete )
);

$GLOBALS['vibesnip_test_settings'] = array();

// ----------------------------------------------------- keys survive a round trip

$plain     = 'sk-ant-api03-not-a-real-key_AbC123-/+=';
$encrypted = VibeSnip_Keys::encrypt( $plain );

check(
	'an encrypted key does not contain the key',
	VibeSnip_Keys::available() && false === strpos( $encrypted, $plain ) && 0 === strpos( $encrypted, VibeSnip_Keys::PREFIX ),
	'got ' . substr( $encrypted, 0, 40 )
);

check(
	'an encrypted key decrypts back to exactly what was typed',
	VibeSnip_Keys::decrypt( $encrypted ) === $plain,
	'got ' . VibeSnip_Keys::decrypt( $encrypted )
);

check(
	'encrypting twice gives different ciphertext for the same key',
	VibeSnip_Keys::encrypt( $plain ) !== $encrypted,
	'the nonce is not being randomised'
);

check(
	'a key stored before encryption existed still reads back',
	VibeSnip_Keys::decrypt( $plain ) === $plain,
	'got ' . VibeSnip_Keys::decrypt( $plain )
);

check(
	'a value whose salts have changed reports itself unreadable, not empty-looking',
	VibeSnip_Keys::is_unreadable( VibeSnip_Keys::PREFIX . bin2hex( random_bytes( 40 ) ) )
		&& ! VibeSnip_Keys::is_unreadable( $plain ),
	'corrupted ciphertext is not being detected'
);

// -------------------------------------------------- the AI off switch holds
//
// Screens hide their AI buttons when AI is off. This is the assertion that the
// hiding is a rule and not a decoration: the outbound call itself refuses.

$GLOBALS['vibesnip_test_provider'] = 'anthropic';
$GLOBALS['vibesnip_test_settings'] = array( 'ai_enabled' => false );

$off = VibeSnip_AI::test_connection();
check(
	'no AI request goes out while AI features are switched off',
	is_wp_error( $off ) && 'vibesnip_ai_off' === $off->get_error_code(),
	describe( $off )
);

$off_models = VibeSnip_AI::fetch_models();
check(
	'the model list is not fetched while AI features are switched off',
	is_wp_error( $off_models ) && 'vibesnip_ai_off' === $off_models->get_error_code(),
	describe( $off_models )
);

$GLOBALS['vibesnip_test_settings'] = array();

// ------------------------------------------------ model lists come from the vendor

$model_fixtures = array(
	'anthropic' => array(
		'data' => array(
			array( 'id' => 'claude-opus-5', 'display_name' => 'Claude Opus 5', 'capabilities' => array( 'structured_outputs' => array( 'supported' => true ) ) ),
			array( 'id' => 'claude-legacy-1', 'display_name' => 'Legacy', 'capabilities' => array( 'structured_outputs' => array( 'supported' => false ) ) ),
		),
	),
	'openai'    => array(
		'data' => array(
			array( 'id' => 'text-embedding-3-large', 'created' => 300 ),
			array( 'id' => 'gpt-4o-audio-preview', 'created' => 250 ),
			array( 'id' => 'gpt-5.1', 'created' => 200 ),
			array( 'id' => 'dall-e-3', 'created' => 150 ),
			array( 'id' => 'gpt-4o', 'created' => 100 ),
			array( 'id' => 'whisper-1', 'created' => 50 ),
		),
	),
	'google'    => array(
		'models' => array(
			array( 'name' => 'models/gemini-2.5-pro', 'displayName' => 'Gemini 2.5 Pro', 'supportedGenerationMethods' => array( 'generateContent' ) ),
			array( 'name' => 'models/embedding-001', 'displayName' => 'Embedding', 'supportedGenerationMethods' => array( 'embedContent' ) ),
		),
	),
);

$expected_models = array(
	// The model that cannot do structured outputs is dropped: every VibeSnip call
	// is schema-constrained, so offering it would only produce a failure later.
	'anthropic' => array( 'claude-opus-5' ),
	// Embeddings, audio, images and speech are dropped; chat models stay, newest first.
	'openai'    => array( 'gpt-5.1', 'gpt-4o' ),
	// Only what the vendor says supports generateContent, with the "models/" prefix off.
	'google'    => array( 'gemini-2.5-pro' ),
);

foreach ( $model_fixtures as $id => $body ) {
	$GLOBALS['vibesnip_test_provider']     = $id;
	$GLOBALS['vibesnip_test_get_response'] = http( 200, $body );
	// Only Anthropic inherits the pre-0.10.0 flat key, so the others need one of
	// their own before a fetch is even attempted.
	$GLOBALS['vibesnip_test_settings'] = array( 'api_keys' => array( $id => 'test-key' ) );

	VibeSnip_AI::forget_models();
	$fetched = VibeSnip_AI::fetch_models();

	check(
		"$id: the model list keeps only the models VibeSnip can use, best first",
		is_array( $fetched ) && array_keys( $fetched ) === $expected_models[ $id ],
		describe( $fetched )
	);

	check(
		"$id: a fetched list replaces the one shipped in the release",
		array_keys( VibeSnip_AI::models( $id ) ) === $expected_models[ $id ],
		describe( VibeSnip_AI::models( $id ) )
	);

	$GLOBALS['vibesnip_test_settings'] = array();
}

// A model the vendor no longer lists must still appear, or saving the form would
// silently move the site onto a different model than the one it has been using.
$GLOBALS['vibesnip_test_provider'] = 'openai';
$GLOBALS['vibesnip_test_settings'] = array( 'models' => array( 'openai' => 'gpt-4.1-retired' ) );
check(
	'a saved model missing from the vendor list is still offered, first',
	array_key_first( VibeSnip_AI::models( 'openai' ) ) === 'gpt-4.1-retired',
	describe( VibeSnip_AI::models( 'openai' ) )
);
$GLOBALS['vibesnip_test_settings'] = array();
VibeSnip_AI::forget_models();

// --------------------------------------------- P1: the refactor changed nothing

$GLOBALS['vibesnip_test_provider'] = 'anthropic';

list( , $body ) = $fixtures['anthropic']['success'];
$proposals      = VibeSnip_AI::parse_response( http( 200, $body ) );

$review_body = array(
	'id'          => 'msg_04',
	'stop_reason' => 'end_turn',
	'content'     => array( array( 'type' => 'text', 'text' => $review_json ) ),
);
$report      = VibeSnip_AI::parse_review( http( 200, $review_body ) );

// Captured by running the same fixture through the pre-refactor class (0e2606b).
$golden_proposals = 'befa4a46bea50a06a753d153ce194a9e';
$golden_report    = '4cd8f10955407c21bd4316d6b6b457a5';

check(
	'P1: parse_response() output is byte-identical to pre-refactor',
	md5( json_encode( $proposals ) ) === $golden_proposals,
	'got ' . md5( json_encode( $proposals ) ) . ' — ' . substr( json_encode( $proposals ), 0, 200 )
);

check(
	'P1: parse_review() output is byte-identical to pre-refactor',
	md5( json_encode( $report ) ) === $golden_report,
	'got ' . md5( json_encode( $report ) ) . ' — ' . substr( json_encode( $report ), 0, 200 )
);

echo "\n" . ( $failures ? "$failures assertion(s) FAILED\n" : "All assertions passed.\n" );
exit( $failures ? 1 : 0 );
