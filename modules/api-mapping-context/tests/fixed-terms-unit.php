<?php
/** Exercise taxonomy scoping without loading a WordPress installation. */
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/class-nova-wordpress-fixed-terms.php';
class WP_Error { public $code; public function __construct( $code, $message ) { $this->code = $code; } }
function current_user_can( $cap ) { return 'assign' === $cap; }
function get_object_taxonomies( $type, $output ) {
    return array_map( static function ( $name ) {
        return (object) [ 'name' => $name, 'labels' => (object) [ 'name' => $name ], 'show_ui' => true, 'cap' => (object) [ 'assign_terms' => 'assign' ] ];
    }, 'story' === $type ? [ 'topicGroup', 'post_tag' ] : [ 'category' ] );
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_terms( $args ) {
    $slug = $args['slug']; $taxonomy = $args['taxonomy'];
    $id = [ 'topicGroup' => [ 'news' => 10, 'news-at' => 11 ], 'post_tag' => [ 'industry' => 12 ] ][$taxonomy][$slug] ?? 0;
    return $id ? [ (object) [ 'slug' => $slug, 'term_id' => $id ] ] : [];
}
function apply_filters( $hook, $value, ...$args ) {
    if ( 'wpml_is_translated_taxonomy' === $hook ) { return 'topicGroup' === $args[0]; }
    if ( 'wpml_object_id' === $hook ) { return 'fr' === $args[2] ? 30 : null; }
    return $value;
}
function term_exists( $id, $taxonomy ) { return ( 'topicGroup' === $taxonomy && 30 === $id ) || ( 'post_tag' === $taxonomy && 12 === $id ); }
function check( $ok, $description ) { if ( ! $ok ) { throw new RuntimeException( $description ); } }
$rules = [
    [ 'taxonomy' => 'topicGroup', 'locale' => '*', 'slug' => 'news' ],
    [ 'taxonomy' => 'topicGroup', 'locale' => 'de-at', 'slug' => 'news-at' ],
    [ 'taxonomy' => 'post_tag', 'locale' => '*', 'slug' => 'industry' ],
];
$resolve = static function ( $locale, $native = '' ) use ( $rules ) { return Nova_WordPress_Fixed_Terms::resolve( $rules, 'story', $locale, false, $native ); };
check( $resolve( 'de_AT' ) === [ 'post_tag' => [12], 'topicGroup' => [11] ], 'Only the overridden taxonomy changes.' );
check( $resolve( 'de-CH' ) === [ 'post_tag' => [12], 'topicGroup' => [10] ], 'Other regions retain the defaults.' );
check( $resolve( 'fr-FR', 'fr' )['topicGroup'] === [30], 'Native translated taxonomy uses the exact linked term.' );
check( $resolve( 'it-IT', 'it' )->code === 'fixed_term_translation_missing', 'A missing translation fails before writing.' );
check( Nova_WordPress_Fixed_Terms::resolve( $rules, 'post', 'de-AT' )->code === 'fixed_taxonomy_unavailable', 'Reject taxonomies from another post type.' );
$rules[0]['slug'] = 'deleted';
check( Nova_WordPress_Fixed_Terms::resolve( $rules, 'story', 'en-US' )->code === 'fixed_term_missing', 'Removed terms cannot create new terms.' );
echo "PASS fixed taxonomy assignments and locale scoping\n";
