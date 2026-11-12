<?php
/** Native HTML card fields reuse the WordPress HTML parser and exact preview markers. */
require __DIR__ . '/custom-html-preview-unit.php';
require_once dirname( __DIR__, 2 ) . '/api-mapping-context/adapters/beaver-html.php';
$start_checks = $checks;
$adapter = Nova_Bridge_Suite_Recipe_Builders::adapter( 'beaver' );
$card = '<div class="pagename-wrap"><img src="/icon.svg" /><h4 class="card-heading fl-heading">Restaurant</h4></div>';
$native = (object) [ 'node' => 'htmlcard123', 'type' => 'module', 'parent' => null, 'position' => 0,
    'settings' => (object) [ 'type' => 'html', 'html' => $card, 'custom_css' => '.pagename-wrap{display:flex}' ] ];
$native->settings->typography = [ 'font_family' => 'Arial', 'font_weight' => '400' ];
$native->settings->data = (object) [ 'sizes' => (object) [ 'full' => (object) [ 'width' => 500 ] ] ];
$source = new WP_Post(); $source->ID = 901; $source->post_type = 'page';
$posts[901] = $source;
$meta[901] = [ '_fl_builder_enabled' => true, '_fl_builder_data' => [ 'htmlcard123' => $native ] ];
$source_before = serialize( $meta[901] );
$capture = ok( $adapter->capture( 901 ) );
check( serialize( $meta[901] ) === $source_before, 'Capturing HTML fields does not change the example page.' );
$blueprint = $capture['blueprint'];
$fields = array_column( $capture['fields'], null, 'value' );
check( isset( $fields['Restaurant'], $fields['/icon.svg'] ), 'HTML module text and image URL are separate fields.' );
check( $fields['Restaurant']['format'] === 'text' && $fields['Restaurant']['kind'] === 'heading' && $fields['Restaurant']['writable'], 'The card heading is an editable text field.' );
$record = html_record( $blueprint, [ 'Restaurant' => 'New sector & audience' ], $fields );
$record['builder'] = $record['recipe']['builder'] = 'beaver';
$draft = ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $record ) );
$values = [ Nova_Bridge_Suite_Writing_Adapter::field_id( '/title' ) => 'New page', Nova_Bridge_Suite_Writing_Adapter::field_id( $fields['Restaurant']['target'] ) => 'New sector & audience' ];
$compiled = ok( Nova_Bridge_Suite_Stored_Layout::compile( $draft, $values, 'html-card' ) );
$output = reset( $compiled['writer_payload']['layout']['nodes'] );
check( $output['settings']['html'] === str_replace( 'Restaurant', 'New sector &amp; audience', $card ), 'Only the selected text changes, preserving the icon, classes and HTML wrapper.' );
check( $output['settings']['custom_css'] === $native->settings->custom_css && ! isset( $output['nova_html_slots'] ), 'Design settings survive and mapping-only slots never enter native data.' );
$decoded_payload = json_decode( wp_json_encode( $compiled['writer_payload'] ), true );
$restored = nova_mapping_bb_normalize_incoming_nodes( $decoded_payload['layout']['nodes'], $decoded_payload['layout']['array_paths'] );
$restored_node = reset( $restored );
check( is_object( $restored_node->settings ) && is_array( $restored_node->settings->typography ), 'Native typography remains an associative array after the JSON round trip.' );
check( is_object( $restored_node->settings->data->sizes->full ), 'Native object properties retain their original shape alongside typography arrays.' );
$legacy = $blueprint; unset( $legacy['document'][0]['nova_html_slots'] );
$legacy_output = ok( $adapter->finalize( $legacy, 'old-profile', [] ) );
check( reset( $legacy_output['writer_payload']['layout']['nodes'] )['settings']['html'] === $card, 'Existing profiles without HTML slots retain their exact source.' );
$connected = $blueprint; $connected['document'][0]['settings']['connections']['html'] = 'provider:field';
$connected_fields = array_column( $adapter->fields( $connected ), null, 'value' );
check( ! $connected_fields['Restaurant']['writable'] && str_contains( $connected_fields['Restaurant']['reason'], 'active field connection' ), 'Active HTML field connections remain protected.' );

$design = Nova_Bridge_Suite_Recipe_Visual::enrich( $capture, [ 'type' => 'post_type', 'post_type' => 'page' ] );
$heading = array_column( $design['preview_fields'], null, 'current_value' )['Restaurant'];
$canvas->setValue( null, [ 'referenceId' => 901, 'referenceType' => 'post' ] );
$GLOBALS['preview_post_id'] = 901;
$module = (object) [ 'node' => 'htmlcard123' ];
$rendered = '<div class="fl-html">' . $card . '</div>';
$marked = Nova_Bridge_Suite_Mapping_Preview::mark_beaver_html( $rendered, 'html', $native->settings, $module );
check( $heading['locator']['strategy'] === 'custom_html' && substr_count( $marked, $heading['locator']['marker'] ) === 1, 'The preview marks the exact card text for visual selection.' );
$second = Nova_Mapping_Beaver_HTML::preview( $rendered, 'html', $native->settings, (object) [ 'node' => 'anothercard' ] );
check( ! str_contains( $second, $heading['locator']['marker'] ), 'Repeated card text in another module has a different preview identity.' );
$GLOBALS['preview_post_id'] = 902;
check( Nova_Bridge_Suite_Mapping_Preview::mark_beaver_html( $rendered, 'html', $native->settings, $module ) === $rendered, 'Other posts receive no preview markers.' );
$canvas->setValue( null, null );
check( Nova_Bridge_Suite_Mapping_Preview::mark_beaver_html( $rendered, 'html', $native->settings, $module ) === $rendered, 'Ordinary frontend rendering receives no preview markers.' );
$info = (object) [ 'node' => 'infolist123', 'type' => 'module', 'parent' => null, 'position' => 0,
    'settings' => (object) [ 'type' => 'pp-infolist', 'title_tag' => 'h3', 'title_typography' => [ 'font_size' => [ 'length' => '30', 'unit' => 'px' ] ],
        'list_items' => [
            (object) [ 'title' => 'Original item heading', 'description' => '<p>Original description</p>', 'icon_type' => 'image', 'image_select' => '275', 'image_select_src' => '/icon.svg', 'connections' => (object) [ 'title' => '' ] ],
            (object) [ 'title' => 'Connected item heading', 'description' => '', 'connections' => (object) [ 'title' => 'provider:field' ] ],
        ] ] ];
$meta[901]['_fl_builder_data'] = [ 'infolist123' => $info ];
$info_capture = ok( $adapter->capture( 901 ) );
$info_fields = array_column( $info_capture['fields'], null, 'value' );
$info_title = $info_fields['Original item heading'];
check( $info_title['writable'] && $info_title['format'] === 'text' && $info_title['kind'] === 'heading', 'PowerPack list titles are exposed as native heading fields.' );
check( $info_fields['<p>Original description</p>']['format'] === 'html', 'PowerPack descriptions have their own rich-text field.' );
check( ! $info_fields['Connected item heading']['writable'], 'An individual connected list field stays protected.' );
check( $info_title['native_node_id'] === 'infolist123' && $info_title['control_path'] === [ 'settings', 'list_items', '0', 'title' ], 'Nested list fields retain their module identity and item position.' );
$info_record = html_record( $info_capture['blueprint'], [ 'Original item heading' => 'Updated item heading' ], $info_fields );
$info_record['builder'] = $info_record['recipe']['builder'] = 'beaver';
$info_draft = ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $info_record ) );
$info_compiled = ok( Nova_Bridge_Suite_Stored_Layout::compile( $info_draft, [ Nova_Bridge_Suite_Writing_Adapter::field_id( '/title' ) => 'New page', Nova_Bridge_Suite_Writing_Adapter::field_id( $info_title['target'] ) => 'Updated item heading' ], 'infolist-fixture' ) );
$info_output = reset( $info_compiled['writer_payload']['layout']['nodes'] );
check( $info_output['settings']['list_items'][0]['title'] === 'Updated item heading', 'The mapped title reaches the native PowerPack item.' );
$expected_settings = json_decode( wp_json_encode( $info->settings ), true );
$expected_settings['list_items'][0]['title'] = 'Updated item heading';
check( $info_output['settings'] === $expected_settings, 'Updating the title preserves icons, typography, descriptions and other list items.' );

echo 'Beaver HTML and Info List field checks: ' . ( $checks - $start_checks ) . " passed.\n";
