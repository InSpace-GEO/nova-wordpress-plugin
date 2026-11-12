<?php
/** Exercise real WordPress HTML parsing and PHP output buffering, without persistence. */
define( 'ABSPATH', __DIR__ );
$core = getenv( 'NOVA_WP_CORE_FIXTURE' );
if ( ! $core || ! is_file( $core . '/html-api/class-wp-html-tag-processor.php' ) ) { exit( "SKIP: WordPress HTML parser fixture required.\n" ); }
require_once $core . '/class-wp-token-map.php';
require_once $core . '/compat-utf8.php';
function _wp_can_use_pcre_u() { return true; }
function _doing_it_wrong( ...$args ) { throw new RuntimeException( implode( ' ', $args ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) { return esc_attr( $value ); }
require_once $core . '/utf8.php';
require_once $core . '/html-api/html5-named-character-references.php';
spl_autoload_register( static function ( $class ) use ( $core ) {
    if ( str_starts_with( $class, 'WP_HTML_' ) ) { require_once $core . '/html-api/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php'; }
} );
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-preview-bootstrap.php';
$checks = 0;
function check( $ok, $message ) { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( $message ); } }
$config = [ 'referenceId' => 12, 'parentOrigin' => 'https://example.test', 'title' => '</script><script>bad()</script>' ];
$url = 'https://example.test/plugin/recipe-canvas.js?ver=117'; $nonce = 'trusted-preview-nonce';
$html = '<!doctype html><html><head><title>Example</title></head><body><main><h1>Example</h1></main><script src="theme.js"></script></body></html>';
$complete = static fn( $value ) => Nova_Bridge_Suite_Preview_Bootstrap::complete( $value, $config, $url, $nonce );
$fixed = $complete( $html ); $pair = substr( $fixed, strlen( $html ) );
check( str_starts_with( $fixed, $html ), 'Native HTML must remain byte-for-byte unchanged' );
check( substr_count( $pair, '<script' ) === 2 && substr_count( $pair, 'nonce="' . $nonce . '"' ) === 2, 'Missing hooks get only our two nonce-authorized scripts' );
check( ! str_contains( $pair, '<script>bad()' ) && str_contains( $pair, '\\u003C' ), 'Configuration cannot break out of its script' );
check( $complete( $fixed ) === $fixed, 'Fallback is idempotent' );
$normal = str_replace( '</head>', $pair . '</head>', $html );
check( $complete( $normal ) === $normal, 'Normal WordPress script output is unchanged' );
foreach ( [ '<template>' . $pair . '</template>', '<!-- ' . $pair . ' -->', str_replace( $nonce, 'wrong-nonce', $pair ), str_replace( '<script ', '<script type="application/json" ', $pair ) ] as $fake ) {
    $candidate = str_replace( '</head>', $fake . '</head>', $html );
    check( str_ends_with( $complete( $candidate ), $pair ), 'Inert or unauthorized script markers must not suppress bootstrap' );
    check( $complete( $candidate ) !== $candidate, 'Inert marker must receive real bootstrap' );
}
$parts = explode( '</script>', trim( $pair ) );
foreach ( [ $parts[0] . '</script>', $parts[1] . '</script>', $parts[1] . '</script>' . $parts[0] . '</script>' ] as $partial ) {
    $candidate = str_replace( '</head>', $partial . '</head>', $html );
    check( $complete( $candidate ) !== $candidate && str_ends_with( $complete( $candidate ), $pair ), 'Missing or misordered configuration/script is repaired' );
}
foreach ( [ '{"body":"not HTML"}', '<!-- <body> -->', '<html><body><script>unterminated', '<html><body><textarea>unterminated' ] as $invalid ) { check( $complete( $invalid ) === $invalid, 'Incomplete or non-HTML documents are not rewritten' ); }
check( Nova_Bridge_Suite_Preview_Bootstrap::complete( $html, $config, $url, '' ) === $html, 'No bootstrap without an authenticated nonce' );
$buffered = static function ( $write ) use ( $config, $url, $nonce ) {
    ob_start(); Nova_Bridge_Suite_Preview_Bootstrap::start( $config, $url, $nonce ); $write(); ob_end_flush(); return ob_get_clean();
};
check( $buffered( static function () use ( $html ) { echo $html; } ) === $fixed, 'Actual PHP output handler repairs omitted hooks' );
check( $buffered( static function () use ( $html ) { echo substr( $html, 0, 50 ); ob_flush(); echo substr( $html, 50 ); } ) === $fixed, 'A theme flush does not duplicate or truncate HTML' );
check( $buffered( static function () use ( $html ) { echo 'discarded'; ob_clean(); echo $html; } ) === $fixed, 'Cleaned buffers do not leak discarded output' );
$large = '<html><body>' . str_repeat( 'x', 8388608 ) . '</body></html>';
check( $buffered( static function () use ( $large ) { echo $large; ob_flush(); echo 'tail'; } ) === $large . 'tail', 'Oversized output passes through without truncation or extra buffering' );
http_response_code( 404 );
check( $buffered( static function () use ( $html ) { echo $html; } ) === $html, 'Error responses are unchanged' );
http_response_code( 200 ); $_SERVER['REQUEST_METHOD'] = 'HEAD';
check( $buffered( static function () use ( $html ) { echo $html; } ) === $html, 'HEAD responses are unchanged' );
unset( $_SERVER['REQUEST_METHOD'] );
echo "PASS $checks preview bootstrap checks\n";
