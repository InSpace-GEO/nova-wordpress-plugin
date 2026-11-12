<?php
/** Actual family/linking code with in-memory WordPress and language-provider state. */
define( 'ABSPATH', __DIR__ );
$provider = $argv[1] ?? 'polylang';
if ( ! in_array( $provider, [ 'polylang', 'wpml' ], true ) ) { throw new RuntimeException( 'Unknown fixture provider' ); }
$options = $posts = $owners = $details = $groups = $links = [];
$languages = [ 'en' => 'en_US', 'fr' => 'fr_FR', 'nl-nl' => 'nl_NL', 'nl-be' => 'nl_BE' ];
$checks = $saves = 0;
class WP_Error { private $code; public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function get_option( $key, $fallback = false ) { return $key === 'home' ? 'https://example.test' : ( $GLOBALS['options'][$key] ?? $fallback ); }
function add_option( $key, $value, $unused = '', $autoload = false ) { if ( isset( $GLOBALS['options'][$key] ) ) { return false; } $GLOBALS['options'][$key] = $value; return true; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['options'][$key] = $value; return true; }
function get_post( $id ) { return $GLOBALS['posts'][$id] ?? null; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['owners'][$id][$key] ?? ''; }
function get_permalink( $id ) { return $GLOBALS['links'][$id] ?? ''; }
function current_user_can( ...$args ) { return true; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function has_filter( $key ) { return $GLOBALS['provider'] === 'wpml'; }
function language_home( $code ) { return 'https://example.test/' . ( $code === 'en' ? '' : $code . '/' ); }
if ( $provider === 'polylang' ) {
    function pll_get_post_language( $id, $field = 'slug' ) { $code = $GLOBALS['details'][$id]['language_code'] ?? ''; return $field === 'slug' ? $code : ( $GLOBALS['languages'][$code] ?? '' ); }
    function pll_languages_list( $args ) { return $args['fields'] === 'slug' ? array_keys( $GLOBALS['languages'] ) : array_values( $GLOBALS['languages'] ); }
    function pll_home_url( $code ) { return language_home( $code ); }
    function pll_get_post_translations( $id ) { return $GLOBALS['groups'][$id] ?? [ pll_get_post_language( $id ) => $id ]; }
    function pll_get_post( $id, $code ) { return pll_get_post_translations( $id )[$code] ?? 0; }
    function pll_save_post_translations( $members ) { ++$GLOBALS['saves']; foreach ( $members as $id ) { $GLOBALS['groups'][$id] = $members; } }
} else { define( 'ICL_SITEPRESS_VERSION', 'fixture' ); }
function apply_filters( $key, $value, ...$args ) {
    if ( $key === 'wpml_element_language_details' ) { return $GLOBALS['details'][$args[0]['element_id']] ?? []; }
    if ( $key === 'wpml_active_languages' ) { return array_map( static function ( $locale ) { return [ 'default_locale' => $locale ]; }, $GLOBALS['languages'] ); }
    if ( $key === 'wpml_permalink' ) { return $value === 'https://example.test/' ? language_home( $args[0] ) : $value; }
    if ( $key === 'wpml_object_id' ) {
        $group = $GLOBALS['details'][$value]['trid'] ?? 0;
        foreach ( $GLOBALS['details'] as $id => $record ) { if ( $record['trid'] === $group && $record['language_code'] === $args[2] ) { return $id; } }
        return 0;
    }
    if ( $key === 'wpml_get_element_translations' ) { return array_filter( $GLOBALS['details'], static function ( $r ) use ( $args ) { return $r['trid'] === $args[0]; } ); }
    return $value;
}
function do_action( $key, $value ) {
    if ( $key === 'wpml_set_element_language_details' ) { ++$GLOBALS['saves']; $GLOBALS['details'][$value['element_id']] = array_intersect_key( $value, array_flip( [ 'trid', 'language_code', 'source_language_code' ] ) ); }
}
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-mapped-content-target.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout-language.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-translation-source.php';
use Nova_Bridge_Suite_Translation_Source as Source;
function check( $ok, $message ) { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( $message ); } }
function good( $value, $message ) { check( ! is_wp_error( $value ), $message . ( is_wp_error( $value ) ? ' (' . $value->get_error_code() . ')' : '' ) ); }
function reset_fixture() { foreach ( [ 'options', 'posts', 'owners', 'details', 'groups', 'links' ] as $key ) { $GLOBALS[$key] = []; } $GLOBALS['saves'] = 0; }
function identity( $url, $language, $group = '50' ) {
    $row = [ 'site_id' => 'site-1', 'url_id' => $url, 'language' => $language ];
    if ( null !== $group ) { $row['url_family'] = [ 'url_group_id' => $group, 'primary_url_id' => '100', 'primary_url' => 'https://example.test/original/', 'primary_language' => 'en-US' ]; }
    return $row;
}
function plan( $identity, $code ) { return [ 'delivery_identity' => $identity, 'post_type' => 'page', 'target' => [ 'native_language' => [ 'routing_version' => 2, 'provider' => $GLOBALS['provider'], 'code' => $code, 'post_type' => 'page' ] ] ]; }
function native_post( $id, $code, $url ) { $GLOBALS['posts'][$id] = (object) [ 'post_type' => 'page', 'post_status' => 'publish' ]; $GLOBALS['details'][$id] = [ 'trid' => $id, 'language_code' => $code, 'source_language_code' => null ]; $GLOBALS['links'][$id] = $url; }
function association( $identity, $id ) { $key = 'nova_layout_page_' . hash( 'sha256', $identity['site_id'] . ':' . $identity['url_id'] . ':' . strtolower( str_replace( '_', '-', $identity['language'] ) ) ); $GLOBALS['options'][$key] = $identity + [ 'state' => 'committed', 'post_id' => $id ]; $GLOBALS['owners'][$id]['_nova_stored_layout_owner'] = array_intersect_key( $identity, array_flip( [ 'site_id', 'url_id' ] ) ); }
function linked( $first, $second, $code ) { return $GLOBALS['provider'] === 'polylang' ? pll_get_post( $first, $code ) === $second : apply_filters( 'wpml_object_id', $first, 'page', false, $code ) === $second; }

foreach ( [ false, true ] as $secondary_first ) {
    reset_fixture();
    $primary = identity( '100', 'en-US' ); $secondary = identity( '200', 'fr-FR' );
    native_post( 10, 'en', 'https://example.test/original/' ); native_post( 20, 'fr', 'https://example.test/fr/localise/' );
    $first = $secondary_first ? [ $secondary, 20, 'fr' ] : [ $primary, 10, 'en' ];
    $second = $secondary_first ? [ $primary, 10, 'en' ] : [ $secondary, 20, 'fr' ];
    good( Source::link( plan( $first[0], $first[2] ), $first[1] ), 'Register first localized native item' ); association( $first[0], $first[1] );
    $before = $saves; good( Source::preflight( plan( $second[0], $second[2] ), $second[1] ), 'Preflight a different URL in the same group' ); check( $saves === $before, 'Preflight is read-only' );
    good( Source::link( plan( $second[0], $second[2] ), $second[1] ), 'Link different URL IDs' ); association( $second[0], $second[1] );
    check( linked( 10, 20, 'fr' ) && linked( 20, 10, 'en' ), 'Native association is reciprocal in either arrival order' );
    $before = $saves; good( Source::link( plan( $second[0], $second[2] ), $second[1] ), 'Replaying the family link is safe' ); check( $before === $saves, 'Replay does not relink' );
    native_post( 30, 'fr', 'https://example.test/fr/conflict/' );
    check( is_wp_error( Source::preflight( plan( identity( '300', 'fr-FR' ), 'fr' ), 30 ) ), 'Another URL cannot occupy the same native language slot' );
    $changed = $primary; $changed['url_family']['primary_url_id'] = '999';
    check( is_wp_error( Source::preflight( plan( $changed, 'en' ), 10 ) ), 'Conflicting primary identity cannot silently move a family' );
}
reset_fixture();
$primary = identity( '100', 'en-US', null ); $secondary = identity( '200', 'fr-FR' );
native_post( 10, 'en', 'https://example.test/original/' ); native_post( 20, 'fr', 'https://example.test/fr/localise/' ); association( $primary, 10 );
good( Source::link( plan( $secondary, 'fr' ), 20 ), 'Adopt verified pre-upgrade primary by exact retained identity and native URL' );
check( linked( 10, 20, 'fr' ), 'Existing original participates without reposting' );
reset_fixture(); native_post( 10, 'en', 'https://example.test/original/' ); native_post( 20, 'fr', 'https://example.test/fr/localise/' ); association( identity( '100', 'en', null ), 10 );
good( Source::link( plan( $secondary, 'fr' ), 20 ), 'Recognize native code and full locale as the same primary language' );
check( linked( 10, 20, 'fr' ), 'An older bare language code is adopted through configured aliases' );
check( ! Nova_Bridge_Suite_Stored_Layout_Language::same_language_slot( 'nl-NL', 'nl-BE' ), 'Different configured regions remain different language slots' );
check( ! Nova_Bridge_Suite_Stored_Layout_Language::same_language_slot( 'en-US', 'en-GB' ), 'Unconfigured regional alias cannot be guessed from a common base code' );
reset_fixture(); native_post( 10, 'en', 'https://example.test/different/' ); native_post( 20, 'fr', 'https://example.test/fr/localise/' ); association( $primary, 10 );
good( Source::link( plan( $secondary, 'fr' ), 20 ), 'An unrelated permalink cannot be adopted as primary' );
check( ! linked( 10, 20, 'fr' ), 'Primary URL mismatch leaves unrelated post unlinked' );
reset_fixture(); native_post( 10, 'en', 'https://example.test/original/' ); native_post( 20, 'fr', 'https://example.test/fr/original/' ); association( $primary, 10 );
good( Source::link( plan( identity( '100', 'fr-FR', null ), 'fr' ), 20 ), 'Same URL multilingual linking remains supported' );
check( linked( 10, 20, 'fr' ), 'Legacy family links as before' );
// The same group combines a translation on one URL ID and a localization on another.
foreach ( [ [ 0, 1, 2 ], [ 2, 1, 0 ], [ 1, 0, 2 ] ] as $order ) {
    reset_fixture();
    $items = [
        [ identity( '100', 'nl-NL' ), 10, 'nl-nl', 'https://example.test/nl-nl/diensten/' ],
        [ identity( '100', 'en-US' ), 20, 'en', 'https://example.test/services/' ],
        [ identity( '200', 'fr-FR' ), 30, 'fr', 'https://example.test/fr/prestations/' ],
    ];
    foreach ( $order as $index ) {
        [ $item, $post_id, $code, $url ] = $items[$index];
        $item['url_family']['primary_language'] = 'nl-NL';
        $item['url_family']['primary_url'] = $items[0][3];
        native_post( $post_id, $code, $url );
        good( Source::preflight( plan( $item, $code ), $post_id ), 'Mixed translation/localization preflight' );
        good( Source::link( plan( $item, $code ), $post_id ), 'Mixed translation/localization links' );
        association( $item, $post_id );
    }
    foreach ( $items as [ $item, $post_id, $code, $url ] ) {
        check( get_permalink( $post_id ) === $url && get_post_meta( $post_id, '_nova_stored_layout_owner' )['url_id'] === $item['url_id'], 'Each member keeps its own permalink and URL ownership' );
        foreach ( $items as [ , $other_id, $other_code ] ) {
            if ( $other_id !== $post_id ) { check( linked( $post_id, $other_id, $other_code ), 'All three members belong to one native family' ); }
        }
    }
}
check( Source::group_lock( $primary ) === null, 'Legacy deliveries retain only their original publication lock' );
check( Source::group_lock( identity( '100', 'en-US' ) ) === Source::group_lock( identity( '200', 'fr-FR' ) ), 'Grouped URLs share a family lock' );
$other = identity( '200', 'fr-FR' ); $other['site_id'] = 'site-2';
check( Source::group_lock( $other ) !== Source::group_lock( identity( '200', 'fr-FR' ) ), 'Family locks are site scoped' );
// Five locale pages keep separate URL ownership and reciprocal native links.
$languages += [ 'it' => 'it_IT', 'de' => 'de_DE' ];
foreach ( [ [ 0, 1, 2, 3, 4 ], [ 4, 3, 2, 1, 0 ] ] as $order ) {
    reset_fixture();
    $items = [
        [ identity( '100', 'en-US' ), 10, 'en', 'https://example.test/original/' ],
        [ identity( '200', 'nl-NL' ), 20, 'nl-nl', 'https://example.test/nl-nl/diensten/' ],
        [ identity( '300', 'it-IT' ), 30, 'it', 'https://example.test/it/servizi/' ],
        [ identity( '400', 'de-DE' ), 40, 'de', 'https://example.test/de/leistungen/' ],
        [ identity( '500', 'fr-FR' ), 50, 'fr', 'https://example.test/fr/prestations/' ],
    ];
    foreach ( $order as $index ) {
        [ $item, $post_id, $code, $url ] = $items[$index];
        native_post( $post_id, $code, $url );
        good( Source::preflight( plan( $item, $code ), $post_id ), 'Five-locale member preflight' );
        good( Source::link( plan( $item, $code ), $post_id ), 'Five-locale member linking' );
        association( $item, $post_id );
    }
    foreach ( $items as [ $item, $post_id, $code, $url ] ) {
        check( get_permalink( $post_id ) === $url && get_post_meta( $post_id, '_nova_stored_layout_owner' )['url_id'] === $item['url_id'], 'Five-locale member retains its own URL and owner' );
        foreach ( $items as [ , $other_id, $other_code ] ) {
            if ( $other_id !== $post_id ) { check( linked( $post_id, $other_id, $other_code ), 'All five locale links are reciprocal' ); }
        }
    }
    $before = $saves;
    good( Source::link( plan( $items[2][0], $items[2][2] ), $items[2][1] ), 'Replayed update retains the five-locale family' );
    check( $before === $saves, 'Five-locale update replay is idempotent' );
}

echo "URL family native $provider checks: $checks passed.\n";
