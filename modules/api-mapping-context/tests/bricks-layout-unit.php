<?php
/** Fixed layouts preserve local content, shared references and signed provider settings. */
require __DIR__ . '/recipe-builders-unit.php';

$adapter = Nova_Bridge_Suite_Recipe_Builders::adapter( 'bricks' );
$id = 9021;
$GLOBALS['recipe_posts'][$id] = (object) [ 'ID' => $id, 'post_content' => '<!-- wp:paragraph --><p>Search copy</p><!-- /wp:paragraph -->' ];
$document = [
    [ 'id' => 'lead', 'parent' => 0, 'name' => 'ba-multi-heading', 'children' => [], 'settings' => [
        'multi_heading_item' => [ [ 'text' => 'First', 'color' => 'red' ], [ 'text' => 'Second' ] ],
    ] ],
    [ 'id' => 'shared', 'parent' => 0, 'name' => 'section', 'global' => 'template', 'children' => [ 'nested' ] ],
    [ 'id' => 'nested', 'parent' => 'shared', 'name' => 'heading', 'settings' => [ 'text' => 'Shared' ] ],
    [ 'id' => 'dyn', 'name' => 'text-basic', 'settings' => [ 'text' => '{post_title}' ] ],
];
$GLOBALS['recipe_meta'][$id] = [ '_bricks_editor_mode' => 'bricks', '_bricks_page_content_2' => $document ];
recipe_assert( [ 'bricks' ] === Nova_Bridge_Suite_Recipe_Builders::detect_reference( $id ), 'The WordPress search mirror does not replace the active Bricks document' );
$capture = recipe_ok( $adapter->capture( $id ), 'Capture native Bricks layout' );
$layout = $capture['blueprint'];
recipe_assert( array_column( $capture['fields'], 'value' ) === [ 'First', 'Second' ], 'Only local literal heading fragments are writable' );
$recipe = recipe_for( 'bricks', $capture, 'static' );
$recipe['bindings'][] = [ 'target' => $capture['fields'][1]['target'], 'format' => 'text', 'source' => '/heading', 'required' => true ];
$result = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, [ 'heading' => 'New second' ], 'bricks-fixed' ), 'Compile Bricks layout' );
$expected = $document;
$expected[0]['settings']['multi_heading_item'][1]['text'] = 'New second';
recipe_assert( $result['writer_payload']['bricks']['document'] === $expected, 'Changing one fragment preserves its siblings, style and all references' );
$layout['document'][0]['settings']['multi_heading_item'][0]['color'] = 'blue';
recipe_assert( is_wp_error( $adapter->validate_blueprint( $layout ) ), 'The captured provider settings cannot be silently replaced' );
recipe_assert( is_wp_error( $adapter->bind( $capture['blueprint'], $recipe['bindings'][0], '{post_title}' ) ), 'Literal bindings cannot introduce dynamic provider tags' );
$GLOBALS['recipe_meta'][$id]['_bricks_editor_mode'] = 'wordpress';
recipe_assert( [ 'gutenberg' ] === Nova_Bridge_Suite_Recipe_Builders::detect_reference( $id ), 'Switching back to WordPress restores block detection' );
