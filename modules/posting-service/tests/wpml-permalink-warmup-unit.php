<?php
/** A yielded lookup must never grant or deny ownership until the complete scan runs. */
define( 'ABSPATH', __DIR__ );
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-mapped-content-target.php';
use Nova_Bridge_Suite_Mapped_Content_Target as Target;
class WP_Error {
    private $code;
    public function __construct( $code, $message ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WPML_Absolute_Url_Persisted {
    const OPTION_KEY = 'provider-cache';
    public $entries = [];
    public $saved = [];
    public $writable = true;
    public static function get_instance() { return $GLOBALS['cache']; }
    public function get( $url, $lang ) { return $this->entries[$url][$lang] ?? null; }
    public function persist() { if ( $this->writable ) { $this->saved = $this->entries; } }
}
function get_option( $key, $default = null ) { return $key === WPML_Absolute_Url_Persisted::OPTION_KEY ? $GLOBALS['cache']->saved : $default; }
function has_filter( $key ) { return $key === 'wpml_element_language_details'; }
function get_permalink( $id ) { return 'https://example.de/article-' . $id; }
function apply_filters( $key, $value, ...$args ) {
    if ( $key === 'wpml_element_language_details' ) { return [ 'language_code' => 'de' ]; }
    if ( $key !== 'wpml_permalink' || $args !== [ 'de', true ] ) { throw new RuntimeException( 'Expected full WPML permalink lookup.' ); }
    $GLOBALS['cache']->entries[$value]['de'] = false;
    return $value;
}
function check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
$sitepress = new class {
    public $mode = 2;
    public function get_setting( $key ) { return $this->mode; }
};
$cache = new WPML_Absolute_Url_Persisted();
$lookup = static function () { return Target::native_url( 'post_type:post', 'post', 1 ); };
$result = Target::with_wpml_cache_budget( $lookup, 0 );
check( is_wp_error( $result ) && $result->get_error_code() === 'nova_wpml_permalink_warmup', 'Persisted progress yields before a native write.' );
$cache->entries = $cache->saved;
check( Target::with_wpml_cache_budget( $lookup, 0 ) === get_permalink( 1 ), 'Cached false is a hit and still uses the full WPML filter.' );
$cache->entries = $cache->saved = [];
$cache->writable = false;
$result = Target::with_wpml_cache_budget( $lookup, 0 );
check( is_wp_error( $result ) && $result->get_error_code() === 'nova_wpml_permalink_cache_unavailable', 'A failed persistence check must take the bounded retry path.' );
$cache->entries = [];
$sitepress->mode = 1;
check( Target::with_wpml_cache_budget( $lookup, 0 ) === get_permalink( 1 ), 'Directory-mode sites use the existing synchronous lookup.' );
$sitepress->mode = 2;
$cache->entries = [];
check( $lookup() === get_permalink( 1 ), 'Preview and native verification outside preparation stay synchronous.' );
echo "WPML permalink continuation checks passed.\n";
