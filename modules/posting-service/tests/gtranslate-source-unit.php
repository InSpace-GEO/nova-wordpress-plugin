<?php
/** Check source-language ownership independently of the WordPress administrative locale. */
define( 'ABSPATH', __DIR__ );
define( 'GTRANSLATE_VERSION', '5.0.2' );
class GTranslate { public static $lang_array = [ 'en' => 'English', 'nl' => 'Dutch', 'zh-CN' => 'Chinese', 'zh-TW' => 'Chinese' ]; }
class WP_Error { public function __construct( public $code, public $message ) {} }
function get_option( $key ) { return $key === 'GTranslate' ? $GLOBALS['settings'] : 'https://example.test/shop'; }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function has_filter( $hook ) { return false; }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-translation-writer.php';
function check_delivery( $language, $path, $code ) {
    $result = Nova_WordPress_GTranslate::native_delivery( [ 'language' => $language, 'url' => $path ] );
    if ( ( true === $result ? 'ok' : $result->code ) !== $code ) { throw new RuntimeException( "$language $path" ); }
}
$settings = [ 'default_language' => 'en', 'pro_version' => 1 ];
check_delivery( 'en-US', '/shop/brand/watch/', 'ok' );
check_delivery( 'en', '/shop/nl/watch/', 'nova_gtranslate_localized_url' );
check_delivery( 'nl', '/shop/brand/watch/', 'nova_gtranslate_secondary_delivery' );
check_delivery( 'en', '/shop/english/watch/', 'ok' );
$settings['default_language'] = 'zh-TW';
check_delivery( 'zh-TW', '/shop/watch/', 'ok' );
check_delivery( 'zh-CN', '/shop/watch/', 'nova_gtranslate_secondary_delivery' );
check_delivery( 'zh', '/shop/watch/', 'nova_gtranslate_secondary_delivery' );
$settings = [ 'default_language' => 'en' ];
check_delivery( 'en', '/shop/nl/', 'ok' ); // The free widget owns no directory.
echo "PASS GTranslate source routing\n";

$settings = [ 'default_language' => 'en', 'pro_version' => 1, 'widget_look' => 'float', 'fincl_langs' => [ 'en', 'nl' ], 'incl_langs' => [ 'zh-TW' ] ];
$provider = Nova_Bridge_Suite_Translation_Writer::provider( 'nl-NL' );
if ( $provider !== [ 'provider' => 'gtranslate', 'source' => 'en', 'language' => 'nl' ] ||
     Nova_WordPress_GTranslate::url( 'https://example.test/shop/watches/', 'nl' ) !== 'https://example.test/shop/nl/watches/' ||
     '' !== Nova_WordPress_GTranslate::url( 'https://example.test/other/', 'nl' ) ||
     isset( Nova_WordPress_GTranslate::catalog()['languages']['zh-TW'] ) ) {
    throw new RuntimeException( 'Hosted language routing must preserve the installation path and enabled widget languages.' );
}
$settings['pro_version'] = 0;
if ( ! Nova_Bridge_Suite_Translation_Writer::provider( 'nl' ) instanceof WP_Error ||
    null !== Nova_Bridge_Suite_Translation_Writer::provider( 'en-US' ) ) {
    throw new RuntimeException( 'The free widget has only a native publishing destination.' );
}
