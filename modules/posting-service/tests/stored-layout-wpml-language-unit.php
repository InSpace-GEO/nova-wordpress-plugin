<?php
/** The real route planner and WPML adapter against domain and translation-group test doubles. */
define( 'ABSPATH', __DIR__ );
use Nova_Bridge_Suite_Stored_Layout_Language as Language;
class WP_Error {
    private $code, $message;
    public function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function current_user_can( $cap, ...$args ) { return true; }
function wpml_check( $condition, $message ) { ++$GLOBALS['checks']; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function good( $value ) { wpml_check( ! is_wp_error( $value ), is_wp_error( $value ) ? $value->get_error_message() : 'Unexpected result' ); return $value; }
function has_filter( $key ) { return $key === 'wpml_element_language_details'; }
function home_url( $path = '/' ) { return $GLOBALS['installation'] . $path; }
function get_option( $key, $default = null ) { return [ 'home' => $GLOBALS['installation'], 'permalink_structure' => '/%postname%/' ][$key] ?? $default; }
function get_post_type_object( $type ) { return (object) [ 'hierarchical' => false, 'rewrite' => [ 'slug' => 'services' ] ]; }
function sanitize_title( $text ) { return trim( strtolower( $text ) ); }
function get_permalink( $id ) { return home_url( '/' . $GLOBALS['posts'][$id]->post_name . '/' ); }
function get_posts( $args ) { return array_slice( array_keys( $GLOBALS['posts'] ), $args['offset'], $args['posts_per_page'] ); }
function get_post( $id ) { return $GLOBALS['posts'][$id] ?? null; }
function apply_filters( $key, $value, ...$args ) {
    global $details, $homes, $active;
    if ( ! empty( $GLOBALS['activating_wpml'] ) ) { throw new RuntimeException( 'WPML has no URL converter yet' ); }
    switch ( $key ) {
        case 'wpml_current_language': return $GLOBALS['current'];
        case 'wpml_default_language': return 'en';
        case 'wpml_active_languages': return empty( $GLOBALS['worker'] ) ? $active : null;
        case 'wpml_permalink':
            $suffix = substr( parse_url( $value, PHP_URL_PATH ) ?: '/', strlen( parse_url( $GLOBALS['installation'], PHP_URL_PATH ) ?: '' ) );
            return rtrim( $homes[$args[0]], '/' ) . '/' . ltrim( $suffix, '/' );
        case 'wpml_is_translated_post_type': return in_array( $args[0], [ 'post', 'service_page' ], true );
        case 'wpml_get_translated_slug': return 'de' === $args[1] && 'services' === $value ? 'leistungen' : $value;
        case 'wpml_element_language_details': return isset( $details[$args[0]['element_id']] ) ? (object) $details[$args[0]['element_id']] : null;
        case 'wpml_get_element_translations': return array_filter( $details, static function ( $row ) use ( $args ) { return $row['trid'] === $args[0]; } );
        case 'wpml_object_id':
            if ( ! isset( $details[$value] ) ) { return null; }
            foreach ( $details as $id => $row ) { if ( $row['trid'] === $details[$value]['trid'] && $row['language_code'] === $args[2] ) { return $id; } }
            return null;
    }
    return $value;
}
function do_action( $key, $value ) {
    if ( $key === 'wpml_switch_language' ) { $GLOBALS['current'] = $value; }
    if ( $key === 'wpml_set_element_language_details' ) {
        ++$GLOBALS['assignments'];
        $GLOBALS['details'][$value['element_id']] = [ 'trid' => $value['trid'] ?: $value['element_id'], 'language_code' => $value['language_code'], 'source_language_code' => $value['source_language_code'] ];
    }
}
class Nova_Bridge_Suite_Document_Mapping {
    public static function resources() { return [ [ 'id'=>'post_type:post', 'type'=>'post_type', 'post_type'=>'post', 'availability'=>'available', 'can_inspect_existing'=>true ] ]; }
}
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-mapped-content-target.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout-language.php';
$checks = $assignments = 0;
require_once dirname( __DIR__, 3 ) . '/includes/class-nova-bridge-suite-wpml-support.php';
$activating_wpml = true;
wpml_check( Nova_Bridge_Suite_WPML_Support::filter_nondefault_option_updates( [ 'setup' => 1 ], 'icl_sitepress_settings', [] ) === [ 'setup' => 1 ], 'Native plugin activation can save its own option before language routing is initialized' );
$activating_wpml = false;
$installation = 'https://en.site.test';
$current = 'en';
$active = [ 'en' => ['default_locale'=>'en_US'], 'de' => ['default_locale'=>'de_DE'], 'de-at'=>['default_locale'=>'de_AT'] ];
$homes = [ 'en'=>'https://en.site.test/', 'de'=>'https://de.site.test/', 'de-at'=>'https://at.site.test/' ];
$worker = true;
$sitepress = new class { public function get_active_languages() { return $GLOBALS['active']; } };
$wp_rewrite = new class { public function get_extra_permastruct( $type ) { return '/services/%' . $type . '%'; } };
$posts = $details = [];
$url = 'https://de.site.test/article/';
$target = good( Language::creation( 'post_type:post', good( Language::delivery_url( $url, 'de-DE' ) ), 'de-DE' ) );
wpml_check( $target['native_language']['code'] === 'de' && $target['target_url'] === rtrim( $url, '/' ) && $current === 'en', 'Canonical German route from webhook restores English request' );
wpml_check( good( Language::delivery_url( '/article/', 'de-DE' ) ) === $url, 'Rooted path uses configured German origin' );
$installation = 'https://de.site.test';
wpml_check( good( Language::creation( 'post_type:post', $url, 'de-DE' ) )['target_url'] === $target['target_url'], 'German and English request homes select the same post' );
$installation = 'https://en.site.test';
wpml_check( is_wp_error( Language::creation( 'post_type:post', 'https://at.site.test/article/', 'de-DE' ) ), 'Do not mistake another German region for the requested locale' );
wpml_check( is_wp_error( Language::creation( 'post_type:post', 'https://foreign.example/article/', 'de-DE' ) ), 'Arbitrary domains cannot become delivery targets' );
wpml_check( is_wp_error( Language::creation( 'post_type:post', $url, 'de-CH' ) ), 'Unsupported regional locale fails before a native mutation' );
wpml_check( is_wp_error( Language::creation( 'post_type:no_translation', $url, 'de-DE' ) ), 'Check WPML post-type support' );
$cpt = good( Language::creation( 'post_type:service_page', 'https://de.site.test/leistungen/service/', 'de-DE' ) );
wpml_check( $cpt['slug'] === 'service', 'Use WPML translated CPT rewrite base' );
$posts[10] = (object) [ 'post_type'=>'post', 'post_name'=>'article', 'post_parent'=>0 ];
$details[10] = ['trid'=>100,'language_code'=>'en','source_language_code'=>null];
wpml_check( is_wp_error( Language::resolve( $url, 'post_type:post', 'de-DE' ) ), 'English post with same slug cannot be adopted in German' );
$posts[20] = clone $posts[10];
$details[20] = ['trid'=>200,'language_code'=>'en','source_language_code'=>null];
good( Language::assign( 20, $target ) );
good( Language::assign( 20, $target ) );
wpml_check( $assignments === 1 && $details[20]['language_code'] === 'de', 'Idempotently assign a new German original' );
$found = good( Language::resolve( $url, 'post_type:post', 'de-DE' ) );
wpml_check( $found['reference_id'] === 20, 'Update the German post even when its English sibling has the same slug' );
good( Language::existing( 'post_type:post', 20, $url, 'de-DE' ) );
wpml_check( is_wp_error( Language::existing( 'post_type:post', 10, $url, 'de-DE' ) ), 'Cannot update the English post from the German delivery' );
wpml_check( Language::different_language( 10, $target ) && ! Language::different_language( 20, $target ), 'Resolve matching reservations per native language' );
good( Language::check_sibling( 0, $target, 10 ) );
good( Language::check_sibling( 20, $target, 10 ) );
wpml_check( $assignments === 1, 'Free-slot preflight never changes an existing translation group' );
good( Language::link_sibling( 20, $target, 10 ) );
wpml_check( is_wp_error( Language::check_sibling( 0, $target, 10 ) ), 'Occupied language slot rejects a new reservation' );
good( Language::check_sibling( 20, $target, 10 ) );
good( Language::link_sibling( 20, $target, 10 ) );
wpml_check( $assignments === 2 && $details[20]['trid'] === 100 && $details[20]['source_language_code'] === 'en', 'Verified group with repeat handling' );
$details[20]['trid'] = 200; $details[30] = [ 'trid'=>200, 'language_code'=>'de-at' ];
wpml_check( is_wp_error( Language::check_sibling( 20, $target, 10 ) ) && $assignments === 2, 'Existing unrelated translations block before content replacement' );
wpml_check( is_wp_error( Language::link_sibling( 20, $target, 10 ) ) && $assignments === 2, 'Never split another translation group to adopt a page' );
$homes['de'] = 'https://de.site.test/shop/';
wpml_check( is_wp_error( Language::verify( 'post_type:post', 20, $target ) ), 'Frozen target cannot move when the configured language home changes' );
$installation = 'https://example.test/shop';
$homes = ['en'=>'https://example.test/shop/', 'de'=>'https://example.test/shop/de/', 'de-at'=>'https://example.test/shop/at/'];
$prefix = good( Language::delivery_url( 'de/article/', 'de-DE' ) );
wpml_check( $prefix === 'https://example.test/shop/de/article/', 'Keep explicit locale prefix on a subdirectory installation exactly once' );
wpml_check( is_wp_error( Language::delivery_url( '/shop/de/article/', 'en-US' ) ), 'Default language cannot claim another language directory' );
echo "Stored-layout WPML checks: $checks passed.\n";
