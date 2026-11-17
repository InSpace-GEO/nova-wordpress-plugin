<?php
/** Content-driven article synchronization and frozen-profile verification. */
require __DIR__ . '/stored-recipe-sync-unit.php';
// Article revisions use the same conditional synchronization and frozen-profile verification.
if ( ! function_exists( 'get_post_type_object' ) ) { function get_post_type_object( $type ) { return (object) [ 'cap' => (object) [ 'create_posts' => 'edit_posts', 'edit_posts' => 'edit_posts' ] ]; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value ) { return $value; } }
$core = getenv( 'NOVA_WP_CORE_FIXTURE' );
if ( $core && ! function_exists( 'parse_blocks' ) ) { require $core . '/class-wp-block-parser.php'; require $core . '/blocks.php'; }
if ( function_exists( 'parse_blocks' ) ) {
    Nova_Bridge_Suite_Stored_Layout_Writer::$failure = null;
    $starter = [ 'blueprint' => [ 'document' => parse_blocks( '<!-- wp:html --><p>Article</p><!-- /wp:html -->' ), 'meta' => [], 'template' => '' ], 'suggested_bindings' => [ [ 'target' => '/document/0/innerHTML', 'source' => '/body', 'format' => 'html', 'required' => true ] ] ];
    $article = [ 'id' => '12345678-1234-4123-8123-123456789abc', 'revision' => 1, 'resource_id' => 'post_type:post', 'builder' => 'gutenberg', 'mode' => 'static', 'posting_page_type' => 'informative', 'label' => 'Article',
        'recipe' => [ 'builder' => 'gutenberg', 'mode' => 'static', 'blueprint' => $starter['blueprint'], 'bindings' => $starter['suggested_bindings'], 'regions' => [], 'article' => Nova_Mapping_Gutenberg_Article::defaults() ] ];
    Nova_Bridge_Suite_Layout_Recipes::$record = $article;
    $article_input = stored_ok( Nova_Bridge_Suite_Writing_Adapter::template_input( stored_ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $article ) ) ) );
    $article_remote = array_merge( $remote, $article_input, [ 'id' => '23456789-2345-4234-8234-23456789abcd', 'revision' => 1 ] );
    $empty = [ 'revision' => 1, 'defaults' => [] ]; $selected = [ 'revision' => 2, 'defaults' => [ 'informative' => $article_remote['id'] ] ];
    $client->queue = [ [ 'GET', '/template-defaults', reply( $empty ) ], [ 'POST', '/templates', reply( $article_remote, 201 ) ], [ 'GET', '/templates/' . $article_remote['id'], reply( $article_remote ) ], [ 'PUT', '/template-defaults', reply( $selected ) ], [ 'GET', '/template-defaults', reply( $selected ) ] ];
    $article_result = stored_ok( stored_sync( $client, $connection ) );
    check( $article_result['posting_sync']['verified'] && ! $article_result['local_only'], 'Article synchronization confirms its direct-delivery default.' );
    $frozen_article = array_intersect_key( $article_remote, array_flip( [ 'id', 'revision', 'name', 'page_type', 'definition', 'mapping' ] ) );
    $approved_article = stored_ok( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( [ 'site_id' => $site, 'configuration' => $frozen_article ], $site ) );
    check( $approved_article['local']['stored_layout']['article'] === $article['recipe']['article'] && $approved_article['template']['definition']['version'] === 1, 'Approved article reload retains exact rules and V1 direct bindings.' );
}

echo 'PASS ' . $checks . " article synchronization assertions\n";
