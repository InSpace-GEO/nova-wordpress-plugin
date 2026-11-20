<?php
/** Exercise taxonomy scoping without loading a WordPress installation. */
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/class-nova-wordpress-fixed-terms.php';
class WP_Error { public $code; public function __construct( $code, $message ) { $this->code = $code; } }
function pll_is_translated_taxonomy( $taxonomy ) { return 'book_topic' === $taxonomy; }
function pll_default_language() { return 'en'; }
function pll_get_term_translations( $id ) { return 43 === $id ? [ 'fr' => 43 ] : [ 'de' => 41, 'en' => 42 ]; }
function get_term( $id, $taxonomy ) {
    return (object) [ 'term_id' => $id, 'taxonomy' => $taxonomy, 'slug' => 'topic-' . $id, 'name' => 'Topic ' . $id, 'count' => $id - 40 ];
}
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
check( $resolve( 'it-IT', 'it' ) === [ 'post_tag' => [12], 'topicGroup' => [] ], 'Skip the missing translation and keep the other taxonomy.' );
check( Nova_WordPress_Fixed_Terms::resolve( $rules, 'post', 'de-AT' )->code === 'fixed_taxonomy_unavailable', 'Reject taxonomies from another post type.' );
$rules[0]['slug'] = 'deleted';
check( Nova_WordPress_Fixed_Terms::resolve( $rules, 'story', 'en-US' )->code === 'fixed_term_missing', 'Removed terms cannot create new terms.' );
$family = Nova_WordPress_Fixed_Terms::picker_term( get_term( 41, 'book_topic' ), '' );
check( $family['slug'] === 'topic-42' && $family['count'] === 2 && $family['translations'][0]['slug'] === 'topic-41' && $family['translations'][0]['count'] === 1, 'Group a translated search hit under the primary term and retain individual counts.' );
$regional = Nova_WordPress_Fixed_Terms::picker_term( get_term( 42, 'book_topic' ), 'de' );
check( $regional['slug'] === 'topic-41' && $regional['translations'][0]['language'] === 'en', 'Use the local representative when editing that language.' );
$orphan = Nova_WordPress_Fixed_Terms::picker_term( get_term( 43, 'book_topic' ), '' );
check( $orphan['locale_only'] && $orphan['language'] === 'fr' && ! $orphan['translations'], 'Keep terms that only exist outside the main language selectable.' );
echo "PASS fixed taxonomy assignments and locale scoping\n";
