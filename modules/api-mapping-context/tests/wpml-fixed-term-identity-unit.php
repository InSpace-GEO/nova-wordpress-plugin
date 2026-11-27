<?php
namespace WPML\TaxonomyTermTranslation {
    class Hooks { const KEY_SKIP_FILTERS = 'wpml_skip_filters'; }
}

namespace {
    define( 'ABSPATH', __DIR__ );
    require dirname( __DIR__ ) . '/includes/class-nova-wordpress-fixed-terms.php';

    class WP_Error {
        public $code;
        public function __construct( $code, $message ) { $this->code = $code; }
    }
    function is_wp_error( $value ) { return $value instanceof WP_Error; }
    class WP_Term {
        public $term_id, $term_taxonomy_id, $slug, $name, $taxonomy = 'category', $count = 0;
        public function __construct( $id, $slug, $name ) {
            $this->term_id = $id; $this->term_taxonomy_id = 100 + $id; $this->slug = $slug; $this->name = $name;
        }
        public static function get_instance( $id, $taxonomy ) { return $GLOBALS['terms'][$id] ?? false; }
    }
    $terms = [ 1 => new WP_Term( 1, 'company-news', 'News' ), 2 => new WP_Term( 2, 'nachrichten', 'Nachrichten' ) ];
    function current_user_can( $cap ) { return 'assign' === $cap; }
    function get_object_taxonomies( $type, $output ) {
        return [ (object) [ 'name' => 'category', 'labels' => (object) [ 'name' => 'Categories' ], 'show_ui' => true, 'cap' => (object) [ 'assign_terms' => 'assign' ] ] ];
    }
    function get_terms( $args ) {
        // The active German request localizes ordinary slug queries before the writer sees the term.
        if ( empty( $args['wpml_skip_filters'] ) ) { return [ $GLOBALS['terms'][2] ]; }
        if ( 'ids' !== $args['fields'] || ! empty( $args['suppress_filter'] ) ) { throw new \RuntimeException( 'Keep other term filters active.' ); }
        $matches = array_filter( $GLOBALS['terms'], static function ( $term ) use ( $args ) { return $term->slug === $args['slug']; } );
        return array_keys( $matches );
    }
    function apply_filters( $hook, $value, ...$args ) {
        if ( 'wpml_is_translated_taxonomy' === $hook ) { return 'category' === $args[0]; }
        if ( 'wpml_default_language' === $hook ) { return 'en'; }
        if ( 'wpml_current_language' === $hook ) { return 'de'; }
        if ( 'wpml_element_trid' === $hook ) { return 83; }
        if ( 'wpml_object_id' === $hook ) { return [ 'en' => 1, 'de' => 2 ][$args[2]] ?? null; }
        if ( 'wpml_get_element_translations' === $hook ) {
            return [ (object) [ 'language_code' => 'en', 'element_id' => 101 ], (object) [ 'language_code' => 'de', 'element_id' => 102 ] ];
        }
        return $value;
    }
    function term_exists( $id, $taxonomy ) { return isset( $GLOBALS['terms'][$id] ); }
    function check( $ok, $message ) { if ( ! $ok ) { throw new \RuntimeException( $message ); } }
    $resolve = static function ( $slug ) {
        return Nova_WordPress_Fixed_Terms::resolve( [ [ 'taxonomy' => 'category', 'locale' => '*', 'slug' => $slug ] ], 'post', 'de-DE', false, 'de' );
    };
    check( [ 'category' => [ 2 ] ] === $resolve( 'company-news' ), 'An English source slug assigns its linked German term.' );
    $choice = Nova_WordPress_Fixed_Terms::picker_term( $terms[1], 'de' );
    check( 'nachrichten' === $choice['slug'] && 'company-news' === $choice['translations'][0]['slug'], 'Picker retains both native slugs.' );
    check( 'fixed_term_missing' === $resolve( 'removed' )->code, 'Missing stored slugs fail.' );
    $terms[3] = new WP_Term( 3, 'company-news', 'Another category' );
    check( 'fixed_term_missing' === $resolve( 'company-news' )->code, 'Duplicate exact slugs remain ambiguous.' );
    echo "PASS WPML native fixed-term identity and translated picker\n";
}
