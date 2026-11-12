<?php
/** A refused editorial field is named, with the plugin that decided it, using WordPress's own hook API. */
$core = getenv( 'NOVA_WP_CORE_FIXTURE' );
if ( ! $core || ! is_file( $core . '/plugin.php' ) || ! is_file( $core . '/meta.php' ) ) { throw new RuntimeException( 'Set NOVA_WP_CORE_FIXTURE to a WordPress core fixture.' ); }
define( 'ABSPATH', dirname( $core ) . '/' ); define( 'WPINC', basename( $core ) );
require_once $core . '/plugin.php';
require_once $core . '/meta.php';
function get_post_type( $id ) { return 7 === $id ? 'post' : false; }
function __( $text ) { return $text; }
class WP_REST_Posts_Controller {}
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-content-transport.php';
require_once __DIR__ . '/fixtures/wp-content/plugins/field-guard/field-guard.php';
$checks = 0;
function check( $value, string $message ) { global $checks; $checks++; if ( ! $value ) { throw new RuntimeException( $message ); } }

$plain = Nova_Bridge_Suite_Content_Transport::meta_denial( 'post', 7, 'content' );
check( false !== strpos( $plain, 'the field "content"' ) && false !== strpos( $plain, 'a capability filter on this site' ) && false === strpos( $plain, 'added by' ), 'A refusal without a field filter did not name the field and the capability filter: ' . $plain );

add_filter( 'auth_post_meta_content_for_post', 'nova_test_field_guard_deny' );
$filtered = Nova_Bridge_Suite_Content_Transport::meta_denial( 'post', 7, 'content' );
check( false !== strpos( $filtered, 'a permission filter for this field, added by plugin field-guard' ), 'A field auth filter was not attributed to its plugin: ' . $filtered );

add_filter( 'auth_post_meta_video', static function () { return false; } );
$closure = Nova_Bridge_Suite_Content_Transport::meta_denial( 'post', 7, 'video' );
check( false !== strpos( $closure, 'the field "video"' ) && false !== strpos( $closure, 'a permission filter for this field' ) && false === strpos( $closure, 'added by' ), 'A filter outside plugins and themes was attributed to one: ' . $closure );

add_filter( 'is_protected_meta', static function ( $protected, $key ) { return 'short_description' === $key ? true : $protected; }, 10, 2 );
$protected = Nova_Bridge_Suite_Content_Transport::meta_denial( 'post', 7, 'short_description' );
check( false !== strpos( $protected, 'it is marked as protected metadata' ), 'A field a plugin marks as protected was not explained: ' . $protected );

add_filter( 'map_meta_cap', 'nova_test_field_guard_deny' );
$mapped = Nova_Bridge_Suite_Content_Transport::meta_denial( 'post', 7, 'role' );
check( false !== strpos( $mapped, 'a capability filter on this site; plugins and themes that change capabilities: plugin field-guard' ) && false === strpos( $mapped, 'added by' ), 'Capability filter plugins were not listed as candidates: ' . $mapped );
check( false !== strpos( $plain, 'a capability filter on this site).' ), 'A refusal without capability filters listed candidates: ' . $plain );

echo "PASS {$checks} refused-field explanation checks\n";
