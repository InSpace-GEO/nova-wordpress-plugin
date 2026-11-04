<?php
/** Scoped Polylang routing uses real exact-URL ownership; provider and native inventory are mocked. */
define( 'ABSPATH', __DIR__ ); $checks = 0;
class WP_Error { private $code; private $message; public function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function current_user_can( $cap, ...$args ) { return ! $GLOBALS['denied']; }
function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ), '-' ); }
function get_option( $key, $default = false ) { return $key === 'home' ? ( $GLOBALS['site_home'] ?? 'https://example.test' ) : ( $key === 'permalink_structure' ? '/%postname%/' : $default ); }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function get_locale() { return 'de_DE'; }
function update_option( ...$args ) { throw new RuntimeException( 'Persistent language settings must not change' ); }
$groups = []; $group_saves = 0;
function pll_get_post_translations( $id ) { return $GLOBALS['groups'][$id] ?? [pll_get_post_language($id)=>$id]; }
function pll_get_post( $id, $code ) { return pll_get_post_translations($id)[$code] ?? 0; }
function pll_save_post_translations( $group ) { ++$GLOBALS['group_saves']; foreach ($group as $member) { $GLOBALS['groups'][$member]=$group; } }
function pll_languages_list( $args ) { return array_values( $args['fields'] === 'slug' ? array_keys( $GLOBALS['languages'] ) : $GLOBALS['languages'] ); }
function pll_default_language( $field = 'slug' ) { return $field === 'locale' ? ( $GLOBALS['languages'][$GLOBALS['default_language']] ?? false ) : $GLOBALS['default_language']; }
function pll_home_url( $code ) { return $GLOBALS['homes'][$code]; }
function pll_is_translated_post_type( $type ) { return in_array( $type, [ 'page', 'post', 'case_study' ], true ); }
function get_post_type_object( $type ) { return (object) [ 'hierarchical' => $type === 'page' ]; }
function pll_get_post_language( $id, $field = 'slug' ) { $code = $GLOBALS['post_languages'][$id] ?? false; return $field === 'locale' && $code ? $GLOBALS['languages'][$code] : $code; }
function pll_set_post_language( $id, $code ) { ++$GLOBALS['assignments']; $GLOBALS['post_languages'][$id] = $code; }
function get_posts( $args ) { $ids = array_filter( array_keys( $GLOBALS['links'] ), static function ( $id ) use ( $args ) { return ( $GLOBALS['native_posts'][$id]->post_type ?? 'page' ) === $args['post_type']; } ); return array_slice( $ids, $args['offset'], $args['posts_per_page'] ); }
function get_permalink( $id ) { return $GLOBALS['links'][$id] ?? ''; }
function get_post( $id ) { return $GLOBALS['native_posts'][$id] ?? null; }
class Language_Rewrite { public $structure = '/%pagename%/'; public function get_page_permastruct() { return $this->structure; } public function get_extra_permastruct( $type ) { return '/cases/%case_study%/'; } }
class Nova_Bridge_Suite_Document_Mapping { public static function resources() { return array_map( static function ( $type ) { return [ 'id' => 'post_type:' . $type, 'type' => 'post_type', 'post_type' => $type, 'availability' => 'available', 'can_inspect_existing' => true ]; }, [ 'page', 'post', 'case_study' ] ); } }
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-mapped-content-target.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout-language.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout-writer.php';
use Nova_Bridge_Suite_Stored_Layout_Language as Language;
function check( $truth, $message ) { ++$GLOBALS['checks']; if ( ! $truth ) { throw new RuntimeException( $message ); } }
function good( $value, $message ) { check( ! is_wp_error( $value ), $message . ( is_wp_error( $value ) ? ': ' . $value->get_error_message() : '' ) ); return $value; }
function bad( $value, $message ) { check( is_wp_error( $value ), $message ); }
$languages = [ 'de-de' => 'de_DE', 'en' => 'en_US', 'nl-nl' => 'nl_NL', 'nl-be' => 'nl_BE' ];
$homes = [ 'de-de' => 'https://example.test/', 'en' => 'https://example.test/en/', 'nl-nl' => 'https://example.test/nl-nl/', 'nl-be' => 'https://example.test/nl-be/' ];
$post_languages = []; $links = []; $assignments = 0; $denied = false; $default_language = 'de-de'; $wp_rewrite = new Language_Rewrite();
$german = good( Language::creation( 'post_type:page', 'https://example.test/new-service/', 'de-DE' ), 'Default German page uses the native root' );
check( $german['parent'] === 0 && $german['native_language']['code'] === 'de-de', 'Configured slug may differ from language tag' );
$dutch = good( Language::creation( 'post_type:page', 'https://example.test/nl-nl/new-service/', 'nl-NL' ), 'Delivery selects exact nondefault region' );
check( $dutch['parent'] === 0 && $dutch['slug'] === 'new-service' && $dutch['native_language']['code'] === 'nl-nl', 'Language directory is not a native parent' );
foreach ( [ ['https://example.test/new-service/','nl-NL'], ['https://example.test/en/new-service/','de-DE'], ['https://example.test/nl-be/new-service/','nl-NL'], ['https://example.test/nl-nl/new-service/','nl'], ['https://foreign.test/new-service/','de-DE'], ['https://example.test/?lang=nl-nl','nl-NL'], ['https://example.test/nl-nl/','nl-NL'] ] as $case ) {
    bad( Language::creation( 'post_type:page', $case[0], $case[1] ), 'Reject a foreign, ambiguous or malformed target' );
}
$site_home = 'https://example.test/site/';
$homes['de-de'] = $site_home;
$homes['nl-nl'] = 'https://nl.example.test/site/';
foreach ( [ 'nieuw/', '/site/nieuw/', 'https://nl.example.test/site/nieuw/' ] as $url ) {
    $absolute = good( Language::delivery_url( $url, 'nl-NL' ), 'Resolve relative or absolute under selected language home' );
    $target = good( Language::creation( 'post_type:page', $absolute, 'nl-NL' ), 'Create on separate language domain' );
    check( $target['target_url'] === 'https://nl.example.test/site/nieuw', 'Keep provider domain and installation subdirectory' );
}
bad( Language::delivery_url( '/nieuw/', 'nl-NL' ), 'Rooted target cannot escape subsite path' );
unset( $site_home ); $homes['de-de'] = 'https://example.test/'; $homes['nl-nl'] = 'https://example.test/nl-nl/';
foreach ( [ '//evil.test/new', 'http://example.test/new', '/new//service', '../new-service/', '/%2e%2e/new/', '/new%2fservice/', '?x=1', '#fragment' ] as $url ) {
    bad( Language::delivery_url( $url, 'de-DE' ), 'Relative URL cannot widen language authority' );
}
$links[10] = 'https://example.test/nl-nl/services/';
$post_languages[10] = 'nl-nl';
$native_posts[10] = (object) [ 'post_type' => 'page', 'post_name' => 'services', 'post_parent' => 42 ];
$existing = good( Language::existing( 'post_type:page', 10, $links[10], 'nl-NL' ), 'Existing post remains editable in its true region' );
check( $existing['parent'] === 42 && $existing['native_language']['code'] === 'nl-nl', 'Adopt native parent and verified language' );
bad( Language::existing( 'post_type:page', 10, $links[10], 'de-DE' ), 'Cannot adopt another language' );
bad( Language::existing( 'post_type:page', 10, 'https://example.test/nl-nl/other/', 'nl-NL' ), 'Cannot adopt alias' );
$nested = good( Language::creation( 'post_type:page', 'https://example.test/nl-nl/services/new/', 'nl-NL' ), 'Resolve a translated native parent' );
check( $nested['parent'] === 10, 'Uses post ID of Dutch parent' );
$post_languages[10] = 'nl-be'; bad( Language::creation( 'post_type:page', 'https://example.test/nl-nl/services/new/', 'nl-NL' ), 'Do not use parent in another language' );
$post_languages[10] = 'nl-nl';
bad( Language::creation( 'post_type:page', $links[10], 'nl-NL' ), 'Existing native URL already taken' );
good( Language::assign( 20, $german ), 'New reservation gets native German language' );
good( Language::assign( 20, $german ), 'Retry assignment' );
check( $assignments === 1 && $post_languages[20] === 'de-de', 'Retry is idempotent' );
check( Language::different_language( 20, $dutch ) && ! Language::different_language( 999, $dutch ), 'Only proven different language avoids a conflicting reservation' );
$homes['de-de'] = 'https://example.test/changed/';
bad( Language::with_context( $german, static function () { return true; } ), 'Provider route changes invalidate a frozen plan' );
$homes['de-de'] = 'https://example.test/'; $default_language = 'nl-nl';
good( Language::with_context( $german, static function () { return true; } ), 'New plan retains German when default changes but its native route stays valid' );
$default_language = 'de-de'; $denied = true;
bad( Language::assign( 21, $german ), 'Respect native capabilities' ); $denied = false;
$wp_rewrite->structure = '/%year%/%pagename%/';
bad( Language::creation( 'post_type:page', 'https://example.test/unknown/', 'de-DE' ), 'Refuse unsupported dynamic route' );
$wp_rewrite->structure = '/%pagename%/'; $links = [];
$post = good( Language::creation( 'post_type:post', 'https://example.test/nl-nl/story/', 'nl-NL' ), 'Ordinary translated Post can use root' );
check( $post['slug'] === 'story', 'Only article slug goes into WordPress' );
bad( Language::creation( 'post_type:post', 'https://example.test/nl-nl/parent/new/', 'nl-NL' ), 'Flat post cannot acquire parent' );
$cpt = good( Language::creation( 'post_type:case_study', 'https://example.test/nl-nl/cases/new/', 'nl-NL' ), 'CPT uses translated home and registered permalink base' );
good( Language::assign( 32, $cpt ), 'CPT assigned in native language' );
bad( Language::verify( 'post_type:page', 32, $cpt ), 'Reject a different post type' );
bad( Language::creation( 'post_type:case_study', 'https://example.test/nl-nl/new/', 'nl-NL' ), 'Wrong CPT path fails' );
$native_posts[20] = (object) ['post_type'=>'page']; $native_posts[21] = (object) ['post_type'=>'page'];
good( Language::assign( 21, $dutch ), 'New translation assigned before grouping' );
$groups[20] = ['de-de'=>20, 'en'=>11]; $post_languages[11] = 'en';
good( Language::check_sibling( 0, $dutch, 20 ), 'New target can reserve a free language slot' );
good( Language::check_sibling( 21, $dutch, 20 ), 'Verified reservation can join the group' );
check( $group_saves === 0, 'Checking a future target never edits the native language group' );
good( Language::link_sibling( 21, $dutch, 20 ), 'Attach to known native group' );
bad( Language::check_sibling( 0, $dutch, 20 ), 'An occupied language slot blocks a new reservation' );
good( Language::check_sibling( 21, $dutch, 20 ), 'A retained post may resume against its own occupied slot' );
good( Language::link_sibling( 21, $dutch, 20 ), 'Group retry succeeds' );
check( $group_saves === 1 && pll_get_post( 21, 'en' ) === 11, 'Existing third language preserved, no duplicate group mutation' );
$groups[21] = ['nl-nl'=>21,'en'=>91]; $groups[20] = ['de-de'=>20];
bad( Language::check_sibling( 21, $dutch, 20 ), 'Conflicting editions block before replacing existing native content' );
check( $group_saves === 1, 'Conflicting preflight cannot detach another editorial group' );
bad( Language::link_sibling( 21, $dutch, 20 ), 'Never overwrite an unrelated translation group' );
$homes['en'] = 'https://www.example.test/en/';
bad( Language::delivery_url( 'https://example.test/en/article/', 'en' ), 'The bare host is registered to another language and must not be rewritten.' );
$homes['en'] = 'https://www.example.org/en/';
check( 'https://www.example.org/en/article/' === good( Language::delivery_url( 'https://example.org/en/article/', 'en' ), 'Localize the alias of a dedicated language host' ), 'Use the language’s canonical host and preserve its path.' );
echo "Stored-layout Polylang checks: $checks passed.\n";
