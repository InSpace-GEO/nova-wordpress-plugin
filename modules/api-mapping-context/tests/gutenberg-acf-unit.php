<?php
/** ACF block fields use the same native compiler and writer as other blocks. */
require __DIR__ . '/recipe-builders-unit.php';

function acf_get_block_type( $name ) { return 'acf/content-sections' === $name ? [ 'name' => $name ] : null; }
function acf_get_block_fields( $block ) { return $GLOBALS['block_schema']; }
function acf_get_field( $key ) {
    $walk = static function ( $fields ) use ( &$walk, $key ) {
        foreach ( $fields as $field ) {
            if ( $field['key'] === $key ) { return $field; }
            $found = $walk( $field['sub_fields'] ?? [] ); if ( $found ) { return $found; }
            foreach ( $field['layouts'] ?? [] as $layout ) { $found = $walk( $layout['sub_fields'] ?? [] ); if ( $found ) { return $found; } }
        }
        return null;
    };
    return $walk( array_merge( $GLOBALS['block_schema'] ?? [], $GLOBALS['post_acf_schema'] ?? [] ) );
}
function acf_validate_value( $value, $field, $path ) { return $value !== 'Provider rejects this'; }
function acf_flush_value_cache( $id, $name ) { $GLOBALS['acf_flushed'][] = [ $id, $name ]; }
$GLOBALS['block_schema'] = [ [ 'key' => 'field_sections', 'name' => 'sections', 'type' => 'flexible_content', 'label' => 'Sections', 'layouts' => [
    [ 'name' => 'intro', 'label' => 'Introduction', 'sub_fields' => [
        [ 'key' => 'field_rows', 'name' => 'rows', 'label' => 'Rows', 'type' => 'repeater', 'sub_fields' => [
            [ 'key' => 'field_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text' ],
            [ 'key' => 'field_body', 'name' => 'body', 'label' => 'Body', 'type' => 'wysiwyg' ],
            [ 'key' => 'field_link', 'name' => 'link', 'label' => 'Link', 'type' => 'url' ],
            [ 'key' => 'field_image', 'name' => 'image', 'label' => 'Image', 'type' => 'image' ],
            [ 'key' => 'field_style', 'name' => 'style', 'label' => 'Style', 'type' => 'select' ],
            [ 'key' => 'field_locked', 'name' => 'locked', 'label' => 'Locked', 'type' => 'text', 'readonly' => 1 ],
        ] ],
    ] ],
] ] ];
$data = [ 'sections' => [ 'intro' ], '_sections' => 'field_sections', 'sections_0_rows' => 2, '_sections_0_rows' => 'field_rows' ];
foreach ( [ 0, 1 ] as $index ) {
    foreach ( [ 'title' => 'Original heading ' . $index, 'body' => '<p>Original introduction ' . $index . '.</p>', 'link' => '/original/', 'image' => 42, 'style' => 'wide', 'locked' => 'Keep locked' ] as $name => $value ) {
        $data['sections_0_rows_' . $index . '_' . $name] = $value;
        $data['_sections_0_rows_' . $index . '_' . $name] = 'field_' . $name;
    }
}
$data['orphan'] = 'Never infer a writable field'; $data['_orphan'] = 'field_body';
$native = [ 'document' => [ [ 'blockName' => 'acf/content-sections', 'attrs' => [ 'name' => 'acf/content-sections', 'data' => $data, 'mode' => 'edit' ], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ] ], 'meta' => [], 'template' => '' ];
$adapter = Nova_Bridge_Suite_Recipe_Builders::adapter( 'gutenberg' );
$fields = array_column( $adapter->fields( $native ), null, 'target' ); $prefix = '/document/0/attrs/data/';
$scopes = $fields[$prefix . 'sections_0_rows_0_body']['section_scopes'];
recipe_assert( count( $scopes ) === 2, 'Nested ACF fields offer their flexible section and their individual repeater row.' );
$row_recipe = [ 'format' => Nova_Bridge_Suite_Recipe_Engine::FORMAT, 'mode' => 'static', 'builder' => 'gutenberg', 'blueprint' => $native,
    'bindings' => [ [ 'target' => $prefix . 'sections_0_rows_1_title', 'source' => '/title', 'format' => 'text', 'required' => true ] ],
    'regions' => [], 'omissions' => [ [ 'target' => $scopes[1]['target'] ] ] ];
$row_result = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $row_recipe, [ 'title' => 'Surviving row' ], 'acf-row' ), 'Compile mapped sibling before reindexing' );
$rows = parse_blocks( $row_result['writer_payload']['native_document'] )[0]['attrs']['data'];
recipe_assert( $rows['sections_0_rows'] === 1 && $rows['sections_0_rows_0_title'] === 'Surviving row' && $rows['_sections_0_rows_0_title'] === 'field_title' && ! isset( $rows['sections_0_rows_1_title'] ), 'Retain sibling content and reindex its hidden ACF field references.' );
$row_recipe['omissions'][0]['target'] = $scopes[0]['target'];
$leaf_recipe = $row_recipe;
$leaf_recipe['omissions'] = [ [ 'target' => $scopes[1]['target'] ], [ 'target' => $prefix . 'sections_0_rows_1_body' ] ];
$leaf_result = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $leaf_recipe, [ 'title' => 'Sibling' ], 'acf-field' ), 'Omit a field and a preceding row in one layout' );
$leaf_rows = parse_blocks( $leaf_result['native_document'] )[0]['attrs']['data'];
recipe_assert( '' === $leaf_rows['sections_0_rows_0_body'] && 'Sibling' === $leaf_rows['sections_0_rows_0_title'] && 'field_body' === $leaf_rows['_sections_0_rows_0_body'], 'Omit the captured field before shifting its row' );
$leaf_recipe['omissions'] = [ [ 'target' => $prefix . 'sections_0_rows_0_locked' ] ];
recipe_assert( is_wp_error( Nova_Bridge_Suite_Recipe_Engine::validate( $leaf_recipe ) ), 'Locked ACF fields cannot be omitted' );
$GLOBALS['block_schema'][0]['layouts'][0]['sub_fields'][0]['sub_fields'][1]['required'] = 1;
$leaf_recipe['omissions'] = [ [ 'target' => $prefix . 'sections_0_rows_0_body' ] ];
recipe_assert( is_wp_error( Nova_Bridge_Suite_Recipe_Engine::validate( $leaf_recipe ) ), 'Required ACF fields cannot be omitted' );
unset( $GLOBALS['block_schema'][0]['layouts'][0]['sub_fields'][0]['sub_fields'][1]['required'] );
recipe_assert( is_wp_error( Nova_Bridge_Suite_Recipe_Engine::validate( $row_recipe ) ), 'A removed section cannot contain an active mapping.' );
$row_recipe['bindings'] = [];
$GLOBALS['block_schema'][0]['min'] = 1;
recipe_assert( is_wp_error( Nova_Bridge_Suite_Recipe_Engine::validate( $row_recipe ) ), 'Revalidate row minima against the installed schema.' );
unset( $GLOBALS['block_schema'][0]['min'] );
recipe_assert( count( $fields ) === 8, 'Only registered scalar leaves in both rows are exposed' );
recipe_assert( $fields[$prefix . 'sections_0_rows_0_body']['format'] === 'html', 'Rich text retains its native format' );
recipe_assert( ! $fields[$prefix . 'sections_0_rows_0_locked']['writable'], 'Read-only child stays protected' );
recipe_assert( ! isset( $fields[$prefix . 'orphan'], $fields[$prefix . 'sections_0_rows_0_image'], $fields[$prefix . 'sections_0_rows_0_style'] ), 'References, images and design choices stay fixed' );
recipe_assert( ! isset( $fields[$prefix . 'sections_0_rows_0_body']['node_target'] ), 'A scalar omission cannot delete the complete ACF block' );
$recipe = [ 'format' => 'nova-layout-recipe/v1', 'builder' => 'gutenberg', 'mode' => 'static', 'blueprint' => $native, 'regions' => [], 'bindings' => [
    [ 'target' => $prefix . 'sections_0_rows_0_body', 'source' => '/intro', 'format' => 'html', 'required' => false ],
    [ 'target' => $prefix . 'sections_0_rows_1_title', 'source' => '/heading', 'format' => 'text', 'required' => true ],
] ];
$compiled = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, [ 'intro' => '<h2>Updated intro</h2><p>New copy.</p>', 'heading' => 'Updated second heading' ], 'acf-block' ), 'Compile ACF block scalar bindings' );
$expected = $data; $expected['sections_0_rows_0_body'] = '<h2>Updated intro</h2><p>New copy.</p>'; $expected['sections_0_rows_1_title'] = 'Updated second heading';
recipe_assert( parse_blocks( $compiled['writer_payload']['native_document'] )[0]['attrs']['data'] === $expected, 'Only mapped leaves change in serialized block data' );
$draft = recipe_ok( $adapter->write_draft( $compiled['writer_payload'], [ 'title' => 'ACF verification' ] ), 'Native draft writer accepts ACF block data' );
recipe_assert( get_post( $draft['post_id'] )->post_content === $compiled['writer_payload']['native_document'], 'Native draft stores the exact compiled ACF document' );
$empty = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, [ 'heading' => 'Second heading' ], 'acf-empty' ), 'Omit one ACF scalar' );
$empty_data = parse_blocks( $empty['writer_payload']['native_document'] )[0]['attrs']['data'];
recipe_assert( $empty_data['sections_0_rows_0_body'] === '' && $empty_data['sections_0_rows_0_title'] === $data['sections_0_rows_0_title'], 'Missing optional content only empties its selected scalar' );
$bad = $native; $bad['document'][0]['attrs']['data']['_sections_0_rows_0_body'] = 'field_title';
recipe_assert( ! isset( array_column( $adapter->fields( $bad ), null, 'target' )[$prefix . 'sections_0_rows_0_body'] ), 'Mismatched native field references are rejected' );
$bad = $native; $bad['document'][0]['attrs']['lock'] = [ 'remove' => true ];
recipe_assert( ! array_filter( $adapter->fields( $bad ), static fn( $field ) => $field['writable'] ), 'Block locks protect all nested fields' );
$GLOBALS['post_acf_schema'] = [
    'field_faq' => [ 'key' => 'field_faq', 'name' => 'faq', 'label' => 'FAQ', 'type' => 'repeater', 'sub_fields' => [
        [ 'key' => 'field_question', 'name' => 'question', 'label' => 'Question', 'type' => 'text' ],
        [ 'key' => 'field_answer', 'name' => 'answer', 'label' => 'Answer', 'type' => 'textarea' ],
    ] ],
];
$native['meta'] = [ 'faq' => 1, '_faq' => 'field_faq', 'faq_0_question' => 'Existing question?', '_faq_0_question' => 'field_question', 'faq_0_answer' => 'Existing answer.', '_faq_0_answer' => 'field_answer' ];
$meta_fields = array_column( $adapter->fields( $native ), null, 'target' );
recipe_assert( $meta_fields['/meta/faq_0_question']['writable'] && $meta_fields['/meta/faq_0_answer']['writable'], 'Nested post-level ACF questions and answers are individually writable' );
$meta_recipe = $recipe; $meta_recipe['blueprint'] = $native; $meta_recipe['bindings'] = [ [ 'target' => '/meta/faq_0_answer', 'source' => '/answer', 'format' => 'text', 'required' => true ] ];
$meta_result = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $meta_recipe, [ 'answer' => 'Updated answer.' ], 'acf-faq' ), 'Compile post-level FAQ mapping beside blocks' );
$meta_expected = $native['meta']; $meta_expected['faq_0_answer'] = 'Updated answer.';
recipe_assert( $meta_result['writer_payload']['meta'] === $meta_expected, 'FAQ mapping preserves its question, row count and field references' );
recipe_assert( parse_blocks( $meta_result['writer_payload']['native_document'] )[0]['attrs']['data'] === $data, 'FAQ mapping leaves the complete ACF block unchanged' );
$GLOBALS['acf_flushed'] = [];
$meta_draft = recipe_ok( $adapter->write_draft( $meta_result['writer_payload'], [ 'title' => 'FAQ verification' ] ), 'Write ACF FAQ draft' );
recipe_assert( in_array( [ $meta_draft['post_id'], 'faq' ], $GLOBALS['acf_flushed'], true ) && in_array( [ $meta_draft['post_id'], 'faq_0_answer' ], $GLOBALS['acf_flushed'], true ), 'Flush the ACF parent and leaf caches after native meta writes' );
if ( ! class_exists( 'Nova_Bridge_Suite_Content_Transport' ) ) {
    class Nova_Bridge_Suite_Content_Transport {
        public static function field_catalog( $type, $id, $template ) { return [ 'acf' => [ 'faq' => $GLOBALS['post_acf_schema']['field_faq'] ] ]; }
    }
}
require_once dirname( __DIR__, 3 ) . '/includes/class-nova-wordpress-meta-permission.php';
$GLOBALS['recipe_posts'][700] = (object) [ 'ID' => 700, 'post_type' => 'page' ];
$GLOBALS['recipe_meta'][700] = $native['meta'] + [ 'faq_private' => 'Unrelated prefixed value', '_faq_private' => 'field_unrelated', 'unrelated' => 'Keep outside capture' ];
recipe_assert( Nova_Mapping_Gutenberg_ACF::capture_meta( 700 ) === $native['meta'], 'Capture only exact registered ACF references, excluding unrelated prefixed keys' );
$GLOBALS['recipe_denied'] = [ 'edit_post_meta' ];
recipe_assert( Nova_Mapping_Gutenberg_ACF::capture_meta( 700 ) === [], 'Capture honors post metadata permissions' );
$GLOBALS['recipe_denied'] = [];

$GLOBALS['post_acf_schema']['field_faq']['sub_fields'][1]['maxlength'] = 6;
recipe_assert( is_wp_error( Nova_Bridge_Suite_Recipe_Engine::compile( $meta_recipe, [ 'answer' => 'Too long for this field' ], 'acf-length' ) ), 'Native ACF length limits are enforced before writing' );
unset( $GLOBALS['post_acf_schema']['field_faq']['sub_fields'][1]['maxlength'] );
$GLOBALS['post_acf_schema']['field_faq']['sub_fields'][1]['required'] = true;
recipe_assert( is_wp_error( $adapter->bind( $native, $meta_recipe['bindings'][0], '' ) ), 'Required ACF fields cannot be emptied' );
unset( $GLOBALS['post_acf_schema']['field_faq']['sub_fields'][1]['required'] );
recipe_assert( is_wp_error( $adapter->bind( $native, $meta_recipe['bindings'][0], 'Provider rejects this' ) ), 'Native ACF validation filters apply before writing' );
echo "PASS ACF block scalar capture, nested rows, compile, native write and preservation\n";
