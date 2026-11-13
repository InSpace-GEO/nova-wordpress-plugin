<?php
/** Native taxonomy SEO registration, permissions and field-preservation checks. */
define( 'ABSPATH', __DIR__ );
define( 'WPSEO_VERSION', 'fixture' );
$checks = 0;
$GLOBALS['wp_rest_additional_fields'] = [];
$GLOBALS['term_seo_permitted'] = true;
$GLOBALS['term_seo_values'] = [ 41 => [ 'wpseo_title' => 'Old title', 'wpseo_desc' => 'Old description',
    'wpseo_noindex' => 'noindex', 'wpseo_canonical' => 'https://example.test/original/' ] ];
class WP_Error {
    public $code;
    public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; }
}
class WPSEO_Taxonomy_Meta {
    public static function get_term_meta( $id, $taxonomy, $field = null ) {
        $values = $GLOBALS['term_seo_values'][$id] ?? [];
        return null === $field ? $values : ( $values['wpseo_' . $field] ?? '' );
    }
    public static function validate_term_meta_data( $values, $old ) {
        return array_map( static fn( $value ) => trim( strip_tags( $value ) ), $values );
    }
    public static function set_values( $id, $taxonomy, $values ) { $GLOBALS['term_seo_values'][$id] = $values; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_action( ...$arguments ) {}
function taxonomy_exists( $taxonomy ) { return in_array( $taxonomy, [ 'product_cat', 'product_tag' ], true ); }
function register_rest_field( $taxonomy, $key, $args ) { $GLOBALS['wp_rest_additional_fields'][$taxonomy][$key] = $args; }
function get_term( $id, $taxonomy ) {
    return $id === 41 && taxonomy_exists( $taxonomy ) ? (object) [ 'term_id' => 41, 'taxonomy' => $taxonomy ] : null;
}
function current_user_can( ...$arguments ) { return $GLOBALS['term_seo_permitted']; }
function check( $value, $label ) { if ( !$value ) { throw new RuntimeException( $label ); } ++$GLOBALS['checks']; }
require __DIR__ . '/../includes/class-nova-bridge-suite-term-seo.php';
use Nova_Bridge_Suite_Term_SEO as SEO;

SEO::register_fields();
check( count( $GLOBALS['wp_rest_additional_fields']['product_tag'] ) === 2, 'Both native tag SEO fields are discoverable' );
check( count( $GLOBALS['wp_rest_additional_fields']['product_cat'] ) === 2, 'Category endpoints expose the same fields' );
check( !isset( $GLOBALS['wp_rest_additional_fields']['category'] ), 'Other taxonomies retain their current capabilities' );
check( SEO::read( [ 'id' => 41 ], 'wpseo_title', null, 'product_tag' ) === 'Old title', 'Reads native Yoast storage' );
$term = get_term( 41, 'product_tag' );
check( SEO::write( 'New title', $term, 'wpseo_title', null, 'product_tag' ) === true, 'Writes the selected native title' );
check( $GLOBALS['term_seo_values'][41]['wpseo_desc'] === 'Old description', 'Unselected description is retained' );
check( $GLOBALS['term_seo_values'][41]['wpseo_noindex'] === 'noindex', 'Robots remains native' );
check( $GLOBALS['term_seo_values'][41]['wpseo_canonical'] === 'https://example.test/original/', 'Canonical remains native' );
check( SEO::write( '<b>Clear description</b>', $term, 'wpseo_desc', null, 'product_tag' ) === true, 'Uses the provider sanitizer' );
check( SEO::read( [ 'id' => 41 ], 'wpseo_desc', null, 'product_tag' ) === 'Clear description', 'Reads the sanitized description' );
check( is_wp_error( SEO::sanitize( [], null, 'wpseo_title' ) ), 'Rejects structured values' );
check( is_wp_error( SEO::sanitize( 'x', null, 'wpseo_canonical' ) ), 'Rejects unselected SEO destinations' );
$GLOBALS['term_seo_permitted'] = false;
check( is_wp_error( SEO::write( 'Denied', $term, 'wpseo_title', null, 'product_tag' ) ), 'Requires edit permission' );
$GLOBALS['term_seo_permitted'] = true;
check( SEO::write( '', $term, 'wpseo_title', null, 'product_tag' ) === true, 'An explicit empty title clears the override' );
$registration = $GLOBALS['wp_rest_additional_fields']['product_tag']['wpseo_title'];
$registration['schema']['title'] = 'Existing site adapter';
$GLOBALS['wp_rest_additional_fields']['product_tag']['wpseo_title'] = $registration;
SEO::register_fields();
check( $GLOBALS['wp_rest_additional_fields']['product_tag']['wpseo_title']['schema']['title'] === 'Existing site adapter', 'Keeps an existing registration and its authority' );
echo "$checks taxonomy SEO checks passed\n";
