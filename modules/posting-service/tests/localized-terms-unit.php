<?php
use Nova_Bridge_Suite_Translation_Writer as Writer;
/** Reuse the provider and HTML fixtures to exercise localized category rendering. */
if ( ! isset( $argv[1] ) ) {
    foreach ( [ 'translatepress', 'weglot' ] as $name ) { foreach ( [ 'product_cat', 'product_tag' ] as $kind ) {
        passthru( escapeshellarg( PHP_BINARY ) . ' -n ' . escapeshellarg( __FILE__ ) . ' ' . $name . ' ' . $kind, $status );
        if ( $status ) { exit( $status ); }
    } }
    return;
}
$taxonomy = $argv[2] ?? 'product_cat';
require __DIR__ . '/localized-custom-fields-unit.php';
require $includes . 'class-nova-bridge-suite-term-translation-writer.php';
class WP_Term {
    public $term_id = 41;
    public $taxonomy = 'product_cat';
    public $name = 'Original category';
    public $description = '<p>Original description</p>';
}
function get_queried_object() { return $GLOBALS['wp_query']->queried_object; }
function get_term( $id, $taxonomy = '' ) {
    $term = clone $GLOBALS['source_term'];
    $term->term_id = $id;
    return apply_filters( 'get_term', $term );
}
function get_term_meta( $id, $name = '', $single = false ) {
    $filtered = apply_filters( 'get_term_metadata', null, $id, $name, $single );
    if ( '' === $name && null === $filtered ) { return $GLOBALS['term_data'][$id] ?? []; }
    $values = $filtered ?? ( $GLOBALS['term_data'][$id][$name] ?? [] );
    return $single ? ( $values[0] ?? '' ) : $values;
}
function acf_get_field( $key ) { return $GLOBALS['term_definitions'][$key] ?? null; }
$source_term = new WP_Term();
$source_term->taxonomy = $taxonomy;
$test_taxonomy = $taxonomy;
$wp_query = (object) [ 'queried_object' => clone $source_term ];
$term_data = [ 41 => [ 'seo_text' => [ '<p>Original lower content.</p>' ] ] ];
$term_definitions = [ 'field_lower' => [ 'name' => 'seo_text', 'type' => 'wysiwyg', 'key' => 'field_lower' ] ];
$fields = [ 'native' => [
    'name' => [ 'provider' => 'native', 'key' => 'name', 'type' => 'text', 'value' => 'Deutsche Kategorie' ],
    'description' => [ 'provider' => 'native', 'key' => 'description', 'type' => 'wysiwyg', 'value' => '<p>Deutsche Beschreibung</p>' ],
], 'meta' => [
    'content_above_products' => [ 'provider' => 'meta', 'key' => 'content_above_products', 'type' => 'wysiwyg', 'value' => '<p>Deutscher Text über den Produkten.</p>' ],
    'content_below_products' => [ 'provider' => 'meta', 'key' => 'content_below_products', 'type' => 'wysiwyg', 'value' => '<p>Deutscher Text unter den Produkten.</p>' ],
    'seo_text' => [ 'provider' => 'acf', 'key' => 'field_lower', 'type' => 'wysiwyg', 'value' => '<p>Deutscher Inhalt.</p>' ],
    '_yoast_wpseo_title' => [ 'provider' => 'meta', 'key' => '_yoast_wpseo_title', 'type' => 'text', 'value' => 'Deutscher Suchtitel' ],
] ];
$target = Writer::provider( 'de_DE' );
$key = '_nova_localized_term_' . hash( 'sha256', $target['provider'] . ':' . $target['language'] );
$term_data[41][$key] = [ [ 'state' => 'committed', 'term_id' => 41, 'taxonomy' => $taxonomy, 'fields' => $fields ] ];
$source_data = $term_data;
$seo_plugins = [ 'wordpress-seo/wp-seo.php' ];
request_locale( 'de_DE' );
verify( get_term( 41 )->name === 'Deutsche Kategorie', 'Category heading is localized' );
verify( $wp_query->queried_object->description === '<p>Deutsche Beschreibung</p>', 'Current archive description is localized' );
verify( get_term_meta( 41, 'seo_text', true ) === '<p>Deutscher Inhalt.</p>', 'Category metadata is localized' );
verify( get_term_meta( 41, 'content_above_products', true ) === '<p>Deutscher Text über den Produkten.</p>', 'Product archive intro is localized' );
verify( get_term_meta( 41, 'content_below_products', true ) === '<p>Deutscher Text unter den Produkten.</p>', 'Product archive body is localized' );
verify( apply_filters( 'acf/pre_load_value', null, $taxonomy . '_41', $term_definitions['field_lower'] ) === '<p>Deutscher Inhalt.</p>', 'ACF taxonomy notation is localized' );
verify( apply_filters( 'acf/pre_load_value', null, 'term_41', $term_definitions['field_lower'] ) === '<p>Deutscher Inhalt.</p>', 'ACF term notation is localized' );
verify( apply_filters( 'acf/pre_load_value', null, 41, $term_definitions['field_lower'] ) === null, 'Post ID with the same number stays separate' );
verify( get_term( 42 )->name === 'Original category', 'Another term stays separate' );
verify( apply_filters( 'wpseo_title', 'Original' ) === 'Deutscher Suchtitel', 'Category SEO title is localized' );
$marked = apply_filters( $pre, '<html><body><main><h1>Deutsche Kategorie</h1><p>Deutsche Beschreibung</p><p>Deutscher Inhalt.</p></main></body></html>' );
verify( str_contains( $marked, 'data-wg-notranslate' ), 'Already localized category copy is protected' );
verify( $term_data === $source_data && $source_term->name === 'Original category', 'Rendering preserves stored source fields' );
$wp_query->queried_object = clone $source_term;
request_locale( 'en_US' );
verify( get_term_meta( 41, 'seo_text', true ) === '<p>Original lower content.</p>' && get_term( 41 )->name === 'Original category', 'English keeps its original fields' );
$term_definitions['field_lower']['type'] = 'number';
$wp_query->queried_object = clone $source_term;
request_locale( 'de_DE' );
verify( get_term_meta( 41, 'seo_text', true ) === '<p>Original lower content.</p>', 'Repurposed ACF fields stop using stale localized values' );
echo "$provider localized category checks passed.\n";
