<?php
/** In-memory provider APIs only. Exercise the writer and verify the actual content selected by its Weglot render hooks. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code; private $message;
    public function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function trailingslashit( $v ) { return rtrim( $v, '/' ) . '/'; }
function home_url( $p = '' ) { return 'https://lingua.test/sub' . $p; }
function get_option( $key, $fallback = false ) { return $key === 'home' ? home_url() : ( empty( $GLOBALS['pending'] ) ? ( $GLOBALS['associations'][$key] ?? $fallback ) : $fallback ); }
function get_post( $id ) { return $GLOBALS['original']; }
function get_permalink( $post ) { return home_url( '/stone/' ); }
function current_user_can( $cap, ...$args ) { return ! ( $GLOBALS['deny'] ?? false ); }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][$id][$key] = $value; return true; }
function wp_slash( $v ) { return $v; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function apply_filters( $name, $value ) { return $value; }
function get_registered_meta_keys( $type, $subtype ) { return [ 'blog_intro' => [ 'show_in_rest' => true ] ]; }
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function wp_kses_post( $v ) { return $v; }
function add_filter( ...$args ) {}
function add_action( ...$args ) {}
function esc_attr( $v ) { return htmlspecialchars( $v, ENT_QUOTES ); }
function esc_html( $v ) { return htmlspecialchars( $v, ENT_QUOTES ); }
function get_the_ID() { return 41; }
function get_queried_object_id() { return 41; }
function is_admin() { return false; }
function is_singular() { return true; }
function get_current_user_id() { return 1; }
function weglot_get_current_language() { return $GLOBALS['visitor']; }
function weglot_get_request_url_service() {
    return new class {
        public function get_current_language() { return $GLOBALS['visitor'] === '' ? null : new LabLanguage( $GLOBALS['visitor'] ); }
    };
}
class LabLanguage {
    public $code;
    public function __construct( $code ) { $this->code = $code; }
    public function getInternalCode() { return $this->code; }
    public function getExternalCode() { return $this->code; }
}
function weglot_get_service( $name ) {
    return new class {
        public function get_original_language() { return new LabLanguage( 'en' ); }
        public function get_destination_languages() { return [new LabLanguage( 'de' ),new LabLanguage( 'fr' )]; }
        public function get_language_from_internal( $code ) { return new LabLanguage( $code ); }
    };
}
function weglot_create_url_object( $link ) {
    return new class( $link ) {
        private $url;
        public function __construct( $url ) { $this->url = $url; }
        public function getForLanguage( $entry ) {
            if ( $entry->code === 'en' ) { return $this->url; }
            if ( $entry->code === 'de' && str_ends_with( $this->url, '/stone/' ) && isset( $GLOBALS['provider_url_override'] ) ) { return $GLOBALS['provider_url_override']; }
            if ( ! empty( $GLOBALS['directories'] ) ) { return str_replace( 'https://lingua.test/sub', 'https://lingua.test/sub' . ( $entry->code === 'en' ? '' : '/' . $entry->code ), $this->url ); }
            return str_replace( 'https://lingua.test/sub', 'https://' . $entry->code . '.lingua.test/sub', $this->url );
        }
    };
}
if ( empty( $grouped_source ) ) {
    class Nova_Bridge_Suite_Translation_Source {
        public static function post_id( $content, $configuration, $source ) { return $GLOBALS['pending'] ? new WP_Error( 'nova_translation_source_pending', 'Waiting' ) : 41; }
    }
} else {
    require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-translation-source.php';
    require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout-language.php';
}
class Nova_Bridge_Suite_Writing_Adapter {
    public static function canonical_json( $v ) { return json_encode( $v ); }
    public static function field_id( $p ) { return 'f-' . md5( $p ); }
}
class Nova_Bridge_Suite_Posting_Protocol { public static function uuid( $id ) { return true; } }
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-mapped-content-target.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-translation-writer.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-wordpress-weglot-renderer.php';
use Nova_Bridge_Suite_Translation_Writer as Writer;
function check( $ok, $msg ) { if ( ! $ok ) { throw new RuntimeException( $msg ); } ++$GLOBALS['checks']; }
function ok( $result ) { if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_code() . ': ' . $result->get_error_message() ); } return $result; }
$checks = 0; $pending = false; $visitor = 'en';
$visitor = ''; check( (new Nova_WordPress_Weglot())->get_current_code() === '', 'Front-end failed during Weglot onboarding' ); $visitor = 'en';
$wpdb = new class {
    public $posts = 'wp_posts';
    public function prepare( $sql, $key ) { return $sql; }
    public function get_var( $sql ) { return !empty($GLOBALS['busy']) && str_contains($sql,'GET_LOCK') ? '0' : '1'; }
};
$original = (object) [ 'ID' => 41, 'post_type' => 'blog', 'post_status' => 'publish', 'post_password' => '', 'post_title' => 'Stone', 'post_excerpt' => '', 'post_content' => 'Original' ];
$fields = [];
foreach ( [ '/title' => 'text', '/meta/blog_intro' => 'rich_text' ] as $path => $type ) {
    $fields[Nova_Bridge_Suite_Writing_Adapter::field_id( $path )] = [ 'target' => $path, 'value_type' => $type ];
}
$local = [ 'routing' => [ 'post_type' => 'blog', 'publication' => 'publish' ], 'fields' => $fields ];
$template = [ 'id' => 'template', 'definition' => [ 'version' => 3 ] ];
$profile = [ 'site_id' => 'site', 'mode' => 'owned_cpt_v3', 'local' => $local, 'template' => $template, 'profile_digest' => hash( 'sha256', json_encode( $local ) ) ];
$content = [ 'site_id' => 'site', 'url_id' => '31', 'content_item_id' => '29', 'version_number' => 1, 'language' => 'de-DE', 'configuration' => $template, 'url' => 'https://de.lingua.test/sub/stein/', 'destination_values' => array_combine( array_keys( $fields ), [ 'Stein', '<p>Ein Stein.</p>' ] ) ];
if ( ! empty( $grouped_source ) ) {
    $content['url_family'] = [ 'url_group_id' => '90', 'primary_url_id' => '91', 'primary_url' => home_url( '/stone/' ), 'primary_language' => 'en-US' ];
    $associations['nova_cpt_url_' . hash( 'sha256', 'site:91:en-us' )] = [ 'site_id' => 'site', 'url_id' => '91', 'language' => 'en-US', 'post_id' => 41, 'state' => 'committed' ];
}
$ctx = [ 'target_post_id' => 41, 'desired_status' => 'publish', 'operation_id' => 'op' ];
$pinned = $profile; $pinned['local']['routing']['locale'] = 'nl_NL';
check( Writer::policy( $content, $pinned )->get_error_code() === 'nova_translation_locale', 'Ignored explicit locale of approved mapping' );
check( ! is_wp_error( Writer::policy( $content, $profile ) ), 'A verified source with a different localized slug was rejected' );
$pinned['local']['routing']['locale'] = 'de_DE';
check( ! is_wp_error( Writer::policy( $content, $pinned ) ), 'Equivalent locale spelling rejected' );
check( Writer::provider( 'en-US' ) === null && Writer::provider( 'de_DE' )['language'] === 'de', 'Locale resolution' );
check( is_wp_error( Writer::provider( 'it-IT' ) ), 'Unconfigured language accepted' );
check( Writer::match_language( 'pt', [ [ 'code' => 'pt-PT' ], [ 'code' => 'pt-BR' ] ] ) === '', 'Ambiguous language selected' );
check( Writer::delivery_url( 'stone/', 'de-DE' ) === 'https://de.lingua.test/sub/stone', 'Lost language home or site directory' );
check( is_wp_error( Writer::delivery_url( 'https://foreign.test/sub/stone/', 'de-DE' ) ), 'Foreign domain accepted' );
$directories = true;
check( is_wp_error( Writer::delivery_url( 'https://lingua.test/sub/de/stone/', 'en-US' ) ), 'Original claimed a translated path' );
check( Writer::delivery_url( 'https://lingua.test/sub/de/stone/', 'de-DE' ) === 'https://lingua.test/sub/de/stone', 'Translation cannot own its configured path' );
$directory_content = $content; $directory_content['url'] = '/sub/de/stein/';
$directory_plan = ok( Writer::plan( $directory_content, $profile, $ctx ) );
check( $directory_plan['target']['target_url'] === 'https://lingua.test/sub/de/stone', 'Subdirectory provider URL was not retained' );
$provider_url_override = 'https://lingua.test/sub/fr/stone/';
check( is_wp_error( Writer::plan( $directory_content, $profile, $ctx ) ), 'Provider URL in another language directory was accepted' );
unset( $provider_url_override );
$directories = false;
foreach ( [ 'https://foreign.test/sub/stone/', 'https://fr.lingua.test/sub/stone/', 'https://de.lingua.test/another-installation/stone/', 'https://de.lingua.test/sub/?p=41', 'https://de.lingua.test/sub/stone/#fragment', 'http://de.lingua.test/sub/stone/', '' ] as $provider_url_override ) {
    check( is_wp_error( Writer::plan( $content, $profile, $ctx ) ), 'Invalid provider destination was accepted: ' . $provider_url_override );
}
unset( $provider_url_override );
$bad_destination = $content; $bad_destination['url'] = 'https://foreign.test/sub/stein/';
check( is_wp_error( Writer::plan( $bad_destination, $profile, $ctx ) ), 'A foreign intended URL was accepted after resolving the source' );
$pending = true; check( Writer::policy( $content, $profile )->get_error_code() === 'nova_translation_source_pending', 'Missing source retried as its own post' ); $pending = false;
check( ok( Writer::policy( $content, $profile ) )['reference']['reference_id'] === 41, 'Incorrect source identity' );
$plan = ok( Writer::plan( $content, $profile, $ctx ) );
check( $plan['target']['target_url'] === 'https://de.lingua.test/sub/stone' && $plan['provider']['url'] === $plan['target']['target_url'], 'Plan did not pin the resolved provider URL' );
check( $content['url'] === 'https://de.lingua.test/sub/stein/', 'Source delivery URL was changed' );
$matching = $content; $matching['url'] = 'https://de.lingua.test/sub/stone/';
check( ok( Writer::plan( $matching, $profile, $ctx ) ) === $plan, 'An existing matching-URL plan changed shape' );
$provider_url_override = 'https://de.lingua.test/sub/new-stone/';
check( Writer::apply( $plan, 'op' )->get_error_code() === 'nova_translation_url', 'A changed provider URL was followed during apply' );
check( Writer::recover( $plan, 'op' )->get_error_code() === 'nova_translation_url', 'A changed provider URL was followed during recovery' );
check( ! get_post_meta( 41, '_nova_weglot_i18n_de', true ), 'Provider URL drift wrote localized content' );
unset( $provider_url_override );
check( ok( Writer::recover( $plan, 'op' ) )['no_native_commit'], 'Empty journal treated as committed' );
$busy=true; check(Writer::apply($plan,'op')->get_error_code()==='nova_translation_busy', 'Concurrent native write was permitted'); $busy=false;
$deny = true; check( is_wp_error( Writer::apply( $plan, 'op' ) ), 'Write permission was bypassed' ); $deny = false;
$key = '_nova_localized_' . hash( 'sha256', 'weglot:de' );
update_post_meta( 41, $key, [ 'state' => 'saving', 'operation_id' => 'op', 'digest' => $plan['digest'], 'version' => 1, 'content_item' => '29' ] );
$original->post_title = 'Unrelated source';
check( Writer::recover( $plan, 'op' )->get_error_code() === 'nova_translation_source_changed', 'Resumed after an intervening edit' );
check( Writer::apply( $plan, 'op' )->get_error_code() === 'nova_translation_source_changed' && ! get_post_meta( 41, '_nova_weglot_i18n_de', true ), 'Stale translation published after interruption' );
$original->post_title = 'Stone';
$done = ok( Writer::apply( $plan, 'op' ) );
check( $done['url'] === 'https://de.lingua.test/sub/stone' && $done['post_id'] === 41, 'Wrong receipt target' );
check( ok( Writer::recover( $plan, 'op' ) ) === $done && ok( Writer::apply( $plan, 'op' ) ) === $done, 'Retry failed' );
$provider_url_override = 'https://de.lingua.test/sub/new-stone/';
check( Writer::verify( $plan, $done )->get_error_code() === 'nova_translation_url', 'Verification followed a changed provider URL' );
unset( $provider_url_override );
check( $original->post_title === 'Stone' && ! get_post_meta( 41, 'blog_intro', true ), 'Translated fields leaked onto English post' );
check( Nova_WordPress_Weglot::get( 41, 'de' )['title'] === 'Stein', 'Wrong language payload' );
$visitor = 'de'; $wg = new Nova_WordPress_Weglot(); $renderer = new Nova_WordPress_Weglot_Renderer( $wg, $wg ); $renderer->resolve_payload();
check( $renderer->filter_title( 'Stone', 41 ) === 'Stein' && $renderer->filter_post_metadata( null, 41, 'blog_intro', true ) === [ '<p>Ein Stein.</p>' ], 'Rendered Weglot fields mismatch' );
$visitor = 'en'; $renderer = new Nova_WordPress_Weglot_Renderer( $wg, $wg ); $renderer->resolve_payload(); check( $renderer->filter_title( 'Stone', 41 ) === 'Stone', 'English visitor saw German title' );
$meta[41]['_nova_weglot_i18n_de']['title'] = 'external change';
check( is_wp_error( Writer::verify( $plan, $done ) ), 'External edits went undetected' );
echo "Single-source delivery checks: $checks passed.\n";
