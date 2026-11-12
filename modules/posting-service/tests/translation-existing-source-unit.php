<?php
/** Resolve legacy publications by the authenticated primary URL, preserving ownership checks. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    public $code;
    public function __construct( $code, $message ) { $this->code = $code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $key, $fallback = false ) { return $GLOBALS['options'][$key] ?? $fallback; }
function get_post( $id ) { return $GLOBALS['posts'][$id] ?? null; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['owners'][$key] ?? ''; }
function current_user_can( $cap, $id ) { return $GLOBALS['allowed']; }
function url_to_postid( $url ) { return $url === 'https://example.test/original' ? 17 : 0; }
class Nova_Bridge_Suite_Translation_Writer {
    public static function same_language_slot( $a, $b ) { return strtok( strtolower( $a ), '-' ) === strtok( strtolower( $b ), '-' ); }
    public static function delivery_url( $url, $language ) { return rtrim( $url, '/' ); }
}
class Nova_Bridge_Suite_Mapped_Content_Target {
    public static function native_url( $resource, $kind, $id ) { return $GLOBALS['canonical']; }
    public static function normalize_url( $url ) { return [ 'identity' => rtrim( $url, '/' ) ]; }
}
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-translation-source.php';
$content = [ 'site_id' => 'site', 'url_id' => '42', 'url_family' => [
    'url_group_id' => '9', 'primary_url_id' => '41', 'primary_language' => 'en-GB',
    'primary_url' => 'https://example.test/original/',
] ];
$configuration = [ 'site_id' => 'site', 'local' => [ 'routing' => [ 'post_type' => 'post' ] ] ];
$posts = [ 17 => (object) [ 'ID' => 17, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '' ] ];
$canonical = 'https://example.test/original/'; $allowed = true; $owners = []; $options = []; $checks = 0;
function check_source( $expected, $input = null, $profile = null ) {
    global $checks, $content, $configuration;
    $result = Nova_Bridge_Suite_Translation_Source::post_id( $input ?? $content, $profile ?? $configuration, 'en' );
    $actual = is_wp_error( $result ) ? $result->code : $result;
    if ( $actual !== $expected ) { throw new RuntimeException( var_export( $actual, true ) ); }
    ++$checks;
}
check_source( 17 );
$options['nova_layout_page_' . hash( 'sha256', 'site:41:en-gb' )] = [ 'state' => 'pending' ];
check_source( 'nova_translation_source_pending' );
$options = [];
$bad = $content; unset( $bad['url_family'] ); check_source( 'nova_translation_source_pending', $bad );
$bad = $content; $bad['site_id'] = 'other'; check_source( 'nova_translation_source_pending', $bad );
$bad = $content; $bad['url_family']['primary_url'] = 'https://elsewhere.test/original/'; check_source( 'nova_translation_source_pending', $bad );
$bad = $configuration; $bad['local']['routing']['post_type'] = 'page'; check_source( 'nova_translation_source_pending', null, $bad );
$posts[17]->post_status = 'draft'; check_source( 'nova_translation_source_pending' );
$posts[17]->post_status = 'trash'; check_source( 'nova_translation_source_pending' );
$posts[17]->post_status = 'publish'; $posts[17]->post_password = 'secret'; check_source( 'nova_translation_source_pending' );
$posts[17]->post_password = ''; $canonical = 'https://example.test/renamed/'; check_source( 'nova_translation_source_pending' );
$canonical = 'https://example.test/original/';
$owners['_nova_stored_layout_owner'] = [ 'site_id' => 'other', 'url_id' => '41' ]; check_source( 'nova_translation_source_identity' );
$owners['_nova_stored_layout_owner'] = [ 'site_id' => 'site', 'url_id' => '99' ]; check_source( 'nova_translation_source_identity' );
$owners['_nova_stored_layout_owner'] = [ 'site_id' => 'site', 'url_id' => '41' ]; check_source( 'nova_translation_source_pending' );
$owners = []; $allowed = false; check_source( 'nova_translation_source_permission' );
echo "Existing original source checks: $checks passed.\n";
