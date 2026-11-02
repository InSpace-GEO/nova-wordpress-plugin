<?php
/** Exercise the adopted scalar setter, including preflight and private-field isolation. */
define( 'ABSPATH', __DIR__ );
class WP_Post { public $ID = 1; }
class WP_Error { public $code; public function __construct( $code, ...$args ) { $this->code = $code; } }
class Nova_Bridge_Suite_Content_Transport { public static function safe_key( $key ) { return strpos( $key, '_' ) !== 0; } }
function add_action( ...$args ) {}
function current_user_can( $cap, ...$args ) { return true; }
function acf_get_field( $key ) { return 'field_heading' === $key ? [ 'type' => 'text' ] : false; }
function get_post_meta( $id, $name = null, $single = false ) {
    global $meta;
    if ( null === $name ) { return $meta; }
    return $single ? ( $meta[$name][0] ?? '' ) : ( $meta[$name] ?? [] );
}
function sanitize_text_field( $v ) { return strip_tags( $v ); }
function wp_slash( $v ) { return addslashes( $v ); }
function update_post_meta( $id, $name, $value ) { $GLOBALS['meta'][$name] = [ stripslashes( $value ) ]; }
require dirname( __DIR__ ) . '/includes/mapping-fields.php';
$meta = [ 'row_0_heading' => [ 'Before' ], '_row_0_heading' => [ 'field_heading' ], 'other' => [ 'Unmapped' ] ];
$post = new WP_Post();
$v = Nova_WordPress_Mapping_Fields::update( [ 'row_0_heading' => 'After', 'other' => 'No' ], $post );
if ( ! $v instanceof WP_Error || $meta['row_0_heading'][0] !== 'Before' ) { throw new RuntimeException( 'Partial write on invalid input.' ); }
$v = Nova_WordPress_Mapping_Fields::update( [ 'row_0_heading' => "<b>It's ready</b>" ], $post );
if ( true !== $v || $meta['row_0_heading'][0] !== "It's ready" || $meta['_row_0_heading'][0] !== 'field_heading' ) { throw new RuntimeException( 'Existing mapped field did not round-trip.' ); }
echo "PASS scalar ACF update and field isolation\n";
