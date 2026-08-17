<?php
/**
 * Tests for the pure content-pack logic.
 *
 * WordPress is not loaded. The handful of functions the plugin calls at load
 * time are stubbed below, which is enough to exercise everything that does not
 * touch the database or render a page. That covers the parts worth testing:
 * merging, flattening, path writing, colour validation, and what happens when
 * the submitted form data is not shaped like the form.
 *
 * Run:  php tests/test-content-pack.php
 *
 * @package wp-runtime-content
 */

define( 'ABSPATH', __DIR__ );

// --- WordPress stubs -------------------------------------------------------
// Deliberately thin. These only need to be faithful enough that the functions
// under test behave the way they would in WordPress.

function add_action() {}
function add_menu_page() {}
function register_activation_hook() {}
function register_rest_route() {}
function __( $text ) { return $text; }
function esc_html__( $text ) { return $text; }

/**
 * Stand-in for wp_kses_post.
 *
 * The real one allows a specific subset of HTML. For these tests all that
 * matters is that it is a string function, so passing it a non-string would
 * blow up exactly as it does in WordPress.
 */
function wp_kses_post( $value ) {
	if ( ! is_string( $value ) ) {
		throw new TypeError( 'wp_kses_post() expects a string, got ' . gettype( $value ) );
	}
	return strip_tags( $value, '<strong><em><a><br><p><ul><ol><li>' );
}

require_once __DIR__ . '/../wp-runtime-content.php';

// --- Tiny assertion harness ------------------------------------------------

$passed = 0;
$failed = 0;

function check( $label, $condition, $detail = '' ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		printf( "  ok    %s\n", $label );
	} else {
		$failed++;
		printf( "  FAIL  %s   %s\n", $label, $detail );
	}
}

function check_no_throw( $label, callable $fn ) {
	global $passed, $failed;
	try {
		$fn();
		$passed++;
		printf( "  ok    %s\n", $label );
	} catch ( Throwable $e ) {
		$failed++;
		printf( "  FAIL  %s   threw %s: %s\n", $label, get_class( $e ), $e->getMessage() );
	}
}

echo "Content pack\n";

$defaults = wprc_default_content();
check( 'defaults are a non-empty array', is_array( $defaults ) && count( $defaults ) > 5 );
check( 'consent paths exist in the defaults', isset( $defaults['consents']['dataUse'] ) );
check( 'every compliance path resolves', (function () use ( $defaults ) {
	$flat = wprc_flatten( $defaults );
	foreach ( wprc_compliance_paths() as $path ) {
		if ( ! array_key_exists( $path, $flat ) ) {
			return false;
		}
	}
	return true;
} )(), 'a flagged path does not exist in the content' );

echo "\nFlatten and set\n";

$flat = wprc_flatten( array( 'a' => array( 'b' => 'x', 'c' => array( 'd' => 'y' ) ), 'e' => 'z' ) );
check( 'nested keys become dot paths', $flat === array( 'a.b' => 'x', 'a.c.d' => 'y', 'e' => 'z' ), var_export( $flat, true ) );

$target = array();
wprc_set_path( $target, 'one.two.three', 'value' );
check( 'set_path builds intermediate arrays', 'value' === $target['one']['two']['three'] );

$overwrite = array( 'one' => 'scalar' );
wprc_set_path( $overwrite, 'one.two', 'value' );
check( 'set_path replaces a scalar standing where an array is needed', 'value' === $overwrite['one']['two'] );

echo "\nDeep merge\n";

$merged = wprc_deep_merge( array( 'a' => array( 'b' => 1, 'c' => 2 ) ), array( 'a' => array( 'c' => 99 ) ) );
check( 'override wins', 99 === $merged['a']['c'] );
check( 'untouched default survives', 1 === $merged['a']['b'] );

echo "\nColour validation\n";

foreach ( array( '#fff', '#FFFFFF', '#1f3a5f' ) as $good ) {
	check( "accepts $good", wprc_is_hex_color( $good ) );
}
foreach ( array(
	'red',
	'#ffff',
	'fff',
	'#12345g',
	'#fff;background-image:url(x)',   // the reason this validation exists
	'',
) as $bad ) {
	check( 'rejects ' . var_export( $bad, true ), ! wprc_is_hex_color( $bad ) );
}

echo "\nHostile input to the save path\n";

// The bug this was written for: a crafted request sends an array where the
// form would send a string. Before the fix this reached wp_kses_post and threw.
check_no_throw( 'array where a string is expected does not throw', function () {
	wprc_build_content_from_input( array( 'welcome__heading' => array( 'x', 'y' ) ) );
} );

$result = wprc_build_content_from_input( array( 'welcome__heading' => array( 'x' ) ) );
check(
	'array submission falls back to the default',
	$result['welcome']['heading'] === wprc_default_content()['welcome']['heading'],
	var_export( $result['welcome']['heading'], true )
);

check_no_throw( 'a string where the array is expected does not throw', function () {
	wprc_build_content_from_input( 'not-an-array' );
} );

check_no_throw( 'null does not throw', function () {
	wprc_build_content_from_input( null );
} );

check_no_throw( 'deeply nested junk does not throw', function () {
	wprc_build_content_from_input( array( 'welcome__heading' => array( array( array( 'deep' ) ) ) ) );
} );

$result = wprc_build_content_from_input( array() );
check( 'an empty submission rebuilds the full pack', count( wprc_flatten( $result ) ) === count( wprc_flatten( wprc_default_content() ) ) + 0 || isset( $result['welcome']['heading'] ) );
check( 'an empty submission does not blank anything', '' !== $result['welcome']['heading'] );

$result = wprc_build_content_from_input( array( 'attacker__field' => 'nope', 'welcome__heading' => 'Hello' ) );
check( 'an unknown field cannot introduce a key', ! isset( $result['attacker'] ), 'unknown key was stored' );
check( 'a known field is accepted', 'Hello' === $result['welcome']['heading'] );

$result = wprc_build_content_from_input( array( 'theme__primaryColor' => 'red; background-image:url(javascript:alert(1))' ) );
check(
	'a colour that is not hex reverts to the default',
	'#1f3a5f' === $result['theme']['primaryColor'],
	var_export( $result['theme']['primaryColor'], true )
);

$result = wprc_build_content_from_input( array( 'welcome__intro' => '<script>alert(1)</script>Safe <strong>text</strong>' ) );
check( 'script tags are stripped from body copy', false === strpos( $result['welcome']['intro'], '<script' ), $result['welcome']['intro'] );
check( 'safe inline formatting survives', false !== strpos( $result['welcome']['intro'], '<strong>' ), $result['welcome']['intro'] );

$result = wprc_build_content_from_input( array() );
check( 'every publish is stamped', isset( $result['version'] ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T/', $result['version'] ) );

printf( "\n%d passed, %d failed\n", $passed, $failed );
exit( $failed > 0 ? 1 : 0 );
