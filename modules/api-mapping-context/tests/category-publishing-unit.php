<?php
/** Fixed category destinations use the same fitter and compiler as post layouts. */
function acf_validate_value( $value, $field, $input ) {
    return empty( $GLOBALS['category_runtime_validation'] ) || isset( $field['_name'] );
}
require __DIR__ . '/layout-recipes-api-unit.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-mapping-drafts.php';
require_once dirname( __DIR__, 2 ) . '/posting-service/includes/class-nova-bridge-suite-writing-adapter.php';
require_once dirname( __DIR__, 2 ) . '/posting-service/includes/class-nova-bridge-suite-posting-protocol.php';
require_once dirname( __DIR__, 2 ) . '/posting-service/includes/class-nova-bridge-suite-static-recipe-adapter.php';
$baseline = $checks;
$acf_schema = [
    'seo_text' => af( 'seo_text', 'wysiwyg', [ 'label' => 'SEO Text', '_name' => 'seo_text' ] ),
    'protected_content' => af( 'protected_content', 'text', [ '_name' => 'protected_content' ] ),
];
$category_runtime_validation = true;
$acf_values['product_cat_31'] = [ 'field_seo_text' => '<p>Original bottom.</p>', 'field_protected_content' => 'Keep this' ];
$category = design( 'core', 'static', 'taxonomy:product_cat', 31 );
$recipe = $category['recipe_preset'];
$recipe['bindings'] = array_values( array_filter( $recipe['bindings'], static function ( $b ) { return in_array( $b['target'], [ '/document/title', '/document/sections/0/body' ], true ); } ) );
$recipe['custom_fields'] = $category['custom_fields_design']['recipe_preset'];
$recipe['custom_fields']['bindings'] = array_values( array_filter( $recipe['custom_fields']['bindings'], static function ( $b ) { return $b['target'] === '/document/seo_text'; } ) );
foreach ( $recipe['bindings'] as &$binding ) { $binding['id'] = 'nova_role_' . ( $binding['target'] === '/document/title' ? 'title' : 'intro' ) . '_0123456789abcdef'; } unset( $binding );
$record = [ 'resource_id' => 'taxonomy:product_cat', 'builder' => 'core', 'mode' => 'static', 'label' => 'Category', 'recipe' => $recipe ];
$projection = Nova_Bridge_Suite_Static_Recipe_Adapter::project_record( $record );
check( ! is_wp_error( $projection ), is_wp_error( $projection ) ? $projection->get_error_message() : 'Projection failed.' );
check( $projection['input']['definition']['version'] === 3 && count( $projection['input']['definition']['fields'] ) === 3, 'Category companion did not use three fitted destinations.' );
$profile = [ 'record' => $record, 'projection' => $projection ];
$values = [];
foreach ( $projection['bindings'] as $path => $spec ) { $values[Nova_Bridge_Suite_Writing_Adapter::field_id( $path )] = $spec['scope'] === 'acf' ? '<h2>Bottom content</h2><p>New bottom.</p>' : ( $path === '/document/title' ? 'New category' : '<p>New introduction.</p>' ); }
$snapshot = [ 'kind' => 'adaptation.delivery', 'schema_version' => 2, 'destination_values' => $values ];
$payload = Nova_Bridge_Suite_Category_Recipe::compile( $snapshot, $profile, 'category-test' );
check( ! is_wp_error( $payload ), is_wp_error( $payload ) ? $payload->get_error_message() : 'Compile failed.' );
check( $payload['name'] === 'New category' && $payload['description'] === '<p>New introduction.</p>' && $payload['meta_all']['acf'] === [ 'seo_text' => '<h2>Bottom content</h2><p>New bottom.</p>' ], 'Fitted values crossed native and ACF destinations or copied an unselected field.' );
$optional = $snapshot; unset( $optional['destination_values'][Nova_Bridge_Suite_Writing_Adapter::field_id( '/@nova/custom-fields/acf/seo_text' )] );
$omitted = Nova_Bridge_Suite_Category_Recipe::compile( $optional, $profile, 'optional' );
check( ! is_wp_error( $omitted ) && ! isset( $omitted['meta_all']['acf'] ), 'Omitted optional ACF value overwrote stored content.' );
$optional_top = $snapshot; unset( $optional_top['destination_values'][Nova_Bridge_Suite_Writing_Adapter::field_id( '/document/sections/0/body' )] );
$omitted_top = Nova_Bridge_Suite_Category_Recipe::compile( $optional_top, $profile, 'optional-top' );
check( ! is_wp_error( $omitted_top ) && ! isset( $omitted_top['description'] ), 'Omitted optional native content copied the example description.' );
check( strpos( wp_json_encode( $projection['input'] ), 'Keep this' ) === false, 'Unselected example content leaked to the remote template.' );
$unknown = $snapshot; $unknown['destination_values']['unselected'] = 'Reject';
check( is_wp_error( Nova_Bridge_Suite_Category_Recipe::compile( $unknown, $profile, 'unknown' ) ), 'Unknown fitted destinations were accepted.' );
$missing = $snapshot; unset( $missing['destination_values'][Nova_Bridge_Suite_Writing_Adapter::field_id( '/document/title' )] );
check( is_wp_error( Nova_Bridge_Suite_Category_Recipe::compile( $missing, $profile, 'missing' ) ), 'Category creation allowed a missing title.' );
$invalid = $record; $invalid['recipe']['custom_fields']['mode'] = 'mixed';
check( is_wp_error( Nova_Bridge_Suite_Static_Recipe_Adapter::project_record( $invalid ) ), 'Unsupported dynamic companion was enabled.' );
$tag_record = json_decode( str_replace( [ 'taxonomy:product_cat', '"product_cat"' ], [ 'taxonomy:product_tag', '"product_tag"' ], wp_json_encode( $record ) ), true );
$tag_projection = Nova_Bridge_Suite_Static_Recipe_Adapter::project_record( $tag_record );
check( ! is_wp_error( $tag_projection ) && $tag_projection['input']['page_type'] === 'category', 'Product-tag mappings must use the Category writing contract.' );
$tag_payload = Nova_Bridge_Suite_Category_Recipe::compile( $snapshot, [ 'record' => $tag_record, 'projection' => $tag_projection ], 'product-tag-test' );
check( $tag_payload === $payload, 'Product-tag fitting changed the mapped native or ACF content.' );
$tag_record['recipe']['custom_fields']['blueprint']['taxonomy'] = 'product_cat';
check( is_wp_error( Nova_Bridge_Suite_Static_Recipe_Adapter::project_record( $tag_record ) ), 'A category companion was allowed to write product-tag fields.' );
echo 'PASS ' . ( $checks - $baseline ) . " category publishing projection checks.\n";
