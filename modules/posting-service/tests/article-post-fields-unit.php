<?php
/** Dynamic articles retain native media, metadata and explicit supplementary sources. */
require __DIR__ . '/stored-recipe-layout-unit.php';
use Nova_Bridge_Suite_Stored_Layout as Layout;
use Nova_Bridge_Suite_Writing_Adapter as Writing;
if ( ! function_exists( 'post_type_supports' ) ) {
    function post_type_supports( $type, $feature ) { return in_array( $feature, [ 'title', 'editor', 'excerpt', 'thumbnail' ], true ); }
}
$article = record( 'gutenberg', 'post', $article_starter['blueprint'], $article_starter['suggested_bindings'] );
$article['recipe']['article'] = Nova_Mapping_Gutenberg_Article::defaults();
$article['recipe']['bindings'][] = binding( '/native_post/featured_image', 'url', 'Primary image' ) + [ 'native_media' => true ];
$article['recipe']['bindings'][] = binding( '/native_post/excerpt', 'text', 'Summary' );
$article['recipe']['bindings'][1]['required'] = false;
$article['recipe']['bindings'][2]['required'] = false;
$article['recipe']['seo_fields'] = $seo_record['recipe']['seo_fields'];
$custom_path = '/@nova/custom-fields/meta/article_summary';
$article['recipe']['posting_fields'][$custom_path] = [ 'provider' => 'meta', 'name' => 'article_summary', 'key' => 'article_summary', 'type' => 'string', 'label' => 'Article summary' ];
$article['recipe']['article']['sources'][$custom_path] = 'meta_description';
$draft = good( Layout::from_recipe( $article ), 'Project dynamic post details' );
$wire = good( Writing::template_input( $draft ), 'Map dynamic post details to direct sources' );
$mapped = array_column( $wire['mapping']['fields'], 'source_field', 'field_id' );
foreach ( [ '/native_post/featured_image' => 'image_url', '/native_post/excerpt' => 'meta_description', $custom_path => 'meta_description', '/@nova/seo/yoast/title' => 'title', '/@nova/seo/yoast/description' => 'meta_description' ] as $path => $source ) {
    check( $mapped[Writing::field_id( $path )] === $source, 'Dynamic direct source for ' . $path );
}
$values = fitted( [ '/title' => 'Visible title', '/document/0/innerHTML' => '<p>Complete body.</p><img src="https://example.org/inline.jpg" alt="Inline description">', '/native_post/featured_image' => 'https://example.org/featured.jpg', '/native_post/excerpt' => 'Summary.', $custom_path => 'Custom summary.', '/@nova/seo/yoast/title' => 'Search title', '/@nova/seo/yoast/description' => 'Search summary' ] );
$compiled = good( Layout::compile( $draft, $values, 'dynamic-details' ), 'Compile dynamic details' );
check( $compiled['post_fields']['excerpt'] === 'Summary.' && $compiled['post_fields']['title'] === 'Visible title', 'Native title and excerpt survive article conversion' );
check( $compiled['writer_payload']['nova_media']['/native_post/featured_image']['url'] === 'https://example.org/featured.jpg', 'Featured image enters the existing import pipeline' );
check( str_contains( $compiled['writer_payload']['native_document'], 'alt="Inline description"' ), 'Inline image description survives native conversion' );
check( $compiled['custom_fields']['article_summary']['value'] === 'Custom summary.', 'Selected editorial field survives conversion' );
check( $compiled['custom_fields']['/@nova/seo/yoast/title']['value'] === 'Search title', 'SEO title remains independent of the native title' );
$optional = good( Layout::compile( $draft, fitted( [ '/title' => 'Title', '/document/0/innerHTML' => '<p>Body</p>' ] ), 'optional-details' ), 'Optional post details can be omitted' );
check( empty( $optional['writer_payload']['nova_media'] ) && ! isset( $optional['custom_fields']['article_summary'] ), 'Omitted optional media and metadata cause no replacement' );
$invalid = $draft; $invalid['article']['sources'][$custom_path] = 'content';
check( is_wp_error( Writing::template_input( $invalid ) ), 'Rich HTML cannot silently populate a plain-text field' );
$invalid = $draft; $invalid['article']['sources']['/native_post/title'] = 'content';
check( is_wp_error( Writing::template_input( $invalid ) ), 'Unselected additional source is rejected' );
check( is_wp_error( Layout::compile( $invalid, $values, 'changed-sources' ) ), 'Changing selected sources invalidates the immutable layout' );
$url_article = $article;
$url_path = '/@nova/custom-fields/acf/article_link';
$url_article['recipe']['posting_fields'][$url_path] = [ 'provider' => 'acf', 'name' => 'article_link', 'key' => 'field_article_link', 'type' => 'url', 'label' => 'Article URL' ];
$url_article['recipe']['article']['sources'][$url_path] = 'url';
$url_draft = good( Layout::from_recipe( $url_article ), 'Project a typed article URL field' );
$url_wire = good( Writing::template_input( $url_draft ), 'URL destinations use the existing scalar delivery contract' );
check( array_column( $url_wire['mapping']['fields'], 'source_field', 'field_id' )[Writing::field_id( $url_path )] === 'url', 'Page URL source maps to the typed destination' );
$url_values = $values + fitted( [ $url_path => 'https://example.org/article/' ] );
check( good( Layout::compile( $url_draft, $url_values, 'url-field' ), 'Compile the normalized page URL' )['custom_fields']['article_link']['value'] === 'https://example.org/article/', 'Typed URL field receives an absolute page URL' );
$legacy = $article; unset( $legacy['recipe']['article'], $legacy['recipe']['posting_fields'] );
check( good( Writing::template_input( good( Layout::from_recipe( $legacy ), 'Legacy layout projection' ) ), 'Legacy fitting retained' )['definition']['version'] === 3, 'Existing static layouts retain their fitting contract' );
echo "PASS dynamic post details and unchanged static contract.\n";
