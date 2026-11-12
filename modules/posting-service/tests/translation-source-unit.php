<?php
/** A translated view can only attach to the committed original for the same NOVA site and URL. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    public $code;
    public function __construct( $code, $message ) { $this->code = $code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $key, $fallback ) { return $GLOBALS['options'][$key] ?? $fallback; }
function get_post( $id ) { return $GLOBALS['posts'][$id] ?? null; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['owners'][$id][$key] ?? ''; }
function current_user_can( $cap, $id ) { return $GLOBALS['editable']; }
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-translation-source.php';

$identity = [ 'site_id' => 'site-1', 'url_id' => '42' ];
$configuration = [ 'local' => [ 'routing' => [ 'post_type' => 'post' ] ] ];
$row = $identity + [ 'post_id' => 15, 'language' => 'en-US', 'state' => 'committed' ];
$posts = [ 15 => (object) [ 'post_type' => 'post', 'post_status' => 'publish' ] ];
$editable = true;
$options = [];
$legacy = 'nova_cpt_url_' . hash( 'sha256', 'site-1:42' );
$scoped = 'nova_layout_page_' . hash( 'sha256', 'site-1:42:en-us' );
$checks = 0;
function source_check( $expected, $language = 'en' ) {
    global $checks, $identity, $configuration;
    $result = Nova_Bridge_Suite_Translation_Source::post_id( $identity, $configuration, $language );
    $value = $result instanceof WP_Error ? $result->code : $result;
    if ( $expected !== $value ) { throw new RuntimeException( 'Source lookup returned ' . var_export( $value, true ) ); }
    ++$checks;
}

source_check( 'nova_translation_source_pending' );
$options[$legacy] = $row;
source_check( 15 );
source_check( 'nova_translation_source_pending', 'en-GB' );
$options[$legacy]['language'] = 'de-DE';
source_check( 'nova_translation_source_pending' );
unset( $options[$legacy] );
$options[$scoped] = $row;
source_check( 15, 'en_US' );
$options[$scoped]['state'] = 'pending';
source_check( 'nova_translation_source_pending' );
$options[$scoped] = $row;
$posts[15]->post_status = 'draft';
source_check( 'nova_translation_source_pending' );
$posts[15]->post_status = 'publish';
$editable = false;
source_check( 'nova_translation_source_permission' );
$editable = true;
$owners[15]['_nova_stored_layout_owner'] = [ 'site_id' => 'other', 'url_id' => '42' ];
source_check( 'nova_translation_source_pending' );
unset( $owners[15] );
$posts[20] = clone $posts[15];
$options[$legacy] = array_merge( $row, [ 'post_id' => 20, 'language' => 'en' ] );
source_check( 'nova_translation_source_ambiguous' );
echo "Translation source checks: $checks passed.\n";
