<?php
/** Linked components select native locale templates without copying or changing shared content. */
require __DIR__ . '/beaver-html-unit.php';
$shared_start = $checks;
if ( ! function_exists( 'pll_get_post_translations' ) ) {
function pll_get_post_translations( $id ) { return $GLOBALS['shared_groups'][$id] ?? []; }
function pll_get_post_language( $id, $field = 'slug' ) {
    $code = $GLOBALS['shared_languages'][$id] ?? '';
    return 'locale' === $field ? ( [ 'nl' => 'nl_NL', 'it' => 'it_IT', 'en' => 'en_US', 'de' => 'de_DE', 'fr' => 'fr_FR' ][$code] ?? '' ) : $code;
}
function pll_languages_list( $args ) { return 'slug' === $args['fields'] ? [ 'nl', 'it', 'en', 'de', 'fr' ] : [ 'nl_NL', 'it_IT', 'en_US', 'de_DE', 'fr_FR' ]; }
}
$adapter = Nova_Bridge_Suite_Recipe_Builders::adapter( 'beaver' );
$group = [];
foreach ( [ 'nl', 'it', 'en', 'de', 'fr' ] as $index => $code ) {
    $id = 920 + $index; $group[$code] = $id; $GLOBALS['shared_languages'][$id] = $code;
    $posts[$id] = (object) [ 'ID' => $id, 'post_type' => 'fl-builder-template', 'post_status' => 'publish', 'post_title' => 'Shared ' . $code ];
    $meta[$id] = [ '_fl_builder_template_global' => true, '_fl_builder_template_id' => 'template_' . $code,
        '_fl_builder_data' => [ 'root_' . $code => (object) [ 'node' => 'root_' . $code, 'type' => 'row', 'parent' => null, 'settings' => (object) [] ] ] ];
}
foreach ( $group as $id ) { $GLOBALS['shared_groups'][$id] = $group; }
$posts[930] = (object) [ 'ID' => 930, 'post_type' => 'page', 'post_title' => 'Source' ];
$GLOBALS['shared_languages'][930] = 'nl';
$root = (object) [ 'node' => 'instance', 'type' => 'row', 'parent' => null, 'position' => 1, 'global' => 920,
    'template_id' => 'template_nl', 'template_node_id' => 'root_nl', 'template_root_node' => true, 'settings' => (object) [ 'heading' => 'Shared heading' ] ];
$article = (object) [ 'node' => 'article', 'type' => 'module', 'parent' => null, 'position' => 0, 'settings' => (object) [ 'type' => 'heading', 'heading' => 'Article' ] ];
$meta[930] = [ '_fl_builder_enabled' => true, '_fl_builder_data' => [ 'article' => $article, 'instance' => $root ] ];
$capture = ok( $adapter->capture( 930 ) );
$fields = array_column( $capture['fields'], null, 'value' );
$protected = $capture['blueprint'];
$protected['document'][1]['children'][] = $protected['document'][0];
$protected_fields = $adapter->fields( $protected );
check( ! $protected_fields[count( $protected_fields ) - 1]['writable'], 'Shared component child controls are protected.' );
$record = html_record( $capture['blueprint'], [ 'Article' => 'Delivered article' ], $fields );
$record['builder'] = $record['recipe']['builder'] = 'beaver';
$snapshot = Nova_Bridge_Suite_Layout_Locales::capture( [ 'type' => 'post_type', 'post_type' => 'page' ], 'beaver', $capture['blueprint'], 930 );
check( count( $snapshot['shared_templates'][0]['variants'] ) === 5 && ! $snapshot['shared_templates'][0]['missing'], 'Shared template translations are captured independently of page translations.' );
$record['locale_preservation'] = $snapshot;
$draft = ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $record ) );
$values = [ Nova_Bridge_Suite_Writing_Adapter::field_id( '/title' ) => 'Page', Nova_Bridge_Suite_Writing_Adapter::field_id( $fields['Article']['target'] ) => 'Delivered article' ];
$before = serialize( $meta );
foreach ( [ 'nl-NL', 'it_IT', 'en-US', 'de-DE', 'fr-FR' ] as $locale ) {
    $code = substr( $locale, 0, 2 );
    $compiled = ok( Nova_Bridge_Suite_Stored_Layout::compile( $draft, $values, 'shared-' . $code, $locale ) );
    $nodes = array_values( $compiled['writer_payload']['layout']['nodes'] );
    check( $nodes[1]['template_id'] === 'template_' . $code && $nodes[1]['global'] === $group[$code] && $nodes[1]['template_node_id'] === 'root_' . $code, 'The linked ' . $code . ' template identity is selected.' );
    check( count( $nodes ) === 2 && $nodes[0]['settings']['heading'] === 'Delivered article', 'The shared component stays linked alongside generated page content.' );
    check( ok( Nova_Bridge_Suite_Stored_Layout::compile( $draft, $values, 'shared-' . $code, $locale ) ) === $compiled, 'Recovery compilation is stable for ' . $code );
}
check( serialize( $meta ) === $before, 'Shared templates and the source page remain unchanged.' );
$fallback = ok( Nova_Bridge_Suite_Stored_Layout::compile( $draft, $values, 'missing', 'es-ES' ) );
check( array_values( $fallback['writer_payload']['layout']['nodes'] )[1]['global'] === 920, 'Missing locales retain the original linked component.' );
$posts[920]->post_status = 'trash';
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout::compile( $draft, $values, 'missing-source', 'es-ES' ) ), 'A missing reference template cannot be used as a locale fallback.' );
$posts[920]->post_status = 'publish';
$meta[921]['_fl_builder_template_id'] = 'changed';
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout::compile( $draft, $values, 'changed', 'it-IT' ) ), 'Changed shared target identity requires review.' );
$meta[921]['_fl_builder_template_id'] = 'template_it';
$posts[921]->post_status = 'trash';
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout::compile( $draft, $values, 'deleted', 'it-IT' ) ), 'Unavailable localized templates fail before publishing.' );
$posts[921]->post_status = 'publish';
$invalid = $record; $invalid['locale_preservation']['shared_templates'][0]['source']['reference_id'] = 999;
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout::from_recipe( $invalid ) ), 'A mismatched shared source is rejected.' );
$invalid = $record; $invalid['locale_preservation']['shared_templates'][0]['variants'][] = $snapshot['shared_templates'][0]['variants'][1];
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout::from_recipe( $invalid ) ), 'Duplicate shared locale identities are rejected.' );
$meta[921]['_fl_builder_template_global'] = false;
$missing = Nova_Bridge_Suite_Layout_Locales::capture( [ 'type' => 'post_type', 'post_type' => 'page' ], 'beaver', $capture['blueprint'], 930 );
check( in_array( 'it_IT', $missing['shared_templates'][0]['missing'], true ), 'A detached localized template appears as missing in the mapping summary.' );
$copy = $capture['blueprint']; $copy['document'][1]['orig']['global'] = false;
check( ! Nova_Bridge_Suite_Beaver_Shared::references( $copy['document'] ), 'An independent saved-row copy is not treated as a global component.' );
echo 'Shared Beaver locale checks: ' . ( $checks - $shared_start ) . " passed.\n";
