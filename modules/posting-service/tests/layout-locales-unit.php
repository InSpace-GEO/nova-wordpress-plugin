<?php
/** Frozen locale values select only preserved fields, without a live reference lookup. */
require __DIR__ . '/beaver-html-unit.php';
$locale_start = $checks;
$adapter = Nova_Bridge_Suite_Recipe_Builders::adapter( 'beaver' );
$locale_card = '<section><h4>Restaurant</h4><p>Example article</p></section>';
$locale_node = (object) [ 'node' => 'localecard', 'type' => 'module', 'parent' => null, 'position' => 0,
    'settings' => (object) [ 'type' => 'html', 'html' => $locale_card ] ];
$locale_post = new WP_Post(); $locale_post->ID = 904; $locale_post->post_type = 'page';
$posts[904] = $locale_post; $meta[904] = [ '_fl_builder_enabled' => true, '_fl_builder_data' => [ 'localecard' => $locale_node ] ];
$locale_capture = ok( $adapter->capture( 904 ) );
$locale_fields = array_column( $locale_capture['fields'], null, 'value' );
$locale_heading = $locale_fields['Restaurant']['target'];
$locale_body = $locale_fields['Example article']['target'];
$locale_record = html_record( $locale_capture['blueprint'], [ 'Example article' => 'Delivered article' ], $locale_fields );
$locale_record['builder'] = $locale_record['recipe']['builder'] = 'beaver';
$locale_snapshot = [ 'version' => 1, 'provider' => 'polylang', 'source_locale' => 'nl_NL', 'unavailable' => [], 'variants' => [] ];
foreach ( [ 'it-IT' => 'Ristorante', 'de-DE' => 'Restaurant DE', 'fr-FR' => 'Restaurant FR', 'en-US' => 'Restaurant EN' ] as $index => $label ) {
    $locale_snapshot['variants'][] = [ 'locale' => $index, 'code' => explode( '-', $index )[0], 'reference_id' => 905 + count( $locale_snapshot['variants'] ),
        'values' => [ $locale_heading => [ 'format' => 'text', 'value' => $label ], $locale_body => [ 'format' => 'text', 'value' => 'Old translated article' ] ] ];
}
$locale_record['locale_preservation'] = Nova_Bridge_Suite_Layout_Locales::retained( $locale_snapshot, $locale_record['recipe'] );
check( ! isset( $locale_record['locale_preservation']['variants'][0]['values'][$locale_body] ), 'Generated fields are excluded from saved locale values.' );
$locale_draft = ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $locale_record ) );
unset( $posts[904], $meta[904] );
$locale_values = [ Nova_Bridge_Suite_Writing_Adapter::field_id( '/title' ) => 'Delivered title', Nova_Bridge_Suite_Writing_Adapter::field_id( $locale_body ) => 'Delivered article' ];
foreach ( [ 'nl-NL' => 'Restaurant', 'it_IT' => 'Ristorante', 'de-DE' => 'Restaurant DE', 'fr-FR' => 'Restaurant FR', 'en-US' => 'Restaurant EN', 'nl-BE' => 'Restaurant' ] as $locale => $label ) {
    $compiled = ok( Nova_Bridge_Suite_Stored_Layout::compile( $locale_draft, $locale_values, 'five-locale-fixture', $locale ) );
    $locale_html = reset( $compiled['writer_payload']['layout']['nodes'] )['settings']['html'];
    check( $locale_html === '<section><h4>' . $label . '</h4><p>Delivered article</p></section>', 'Preserved content and delivered content remain independent for ' . $locale );
    check( ok( Nova_Bridge_Suite_Stored_Layout::compile( $locale_draft, $locale_values, 'five-locale-fixture', $locale ) ) === $compiled, 'Locale compilation is deterministic for recovery.' );
}
$invalid = $locale_record;
$invalid['locale_preservation']['variants'][0]['values'][$locale_body] = [ 'format' => 'text', 'value' => 'Overwrite delivered content' ];
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout::from_recipe( $invalid ) ), 'Saved locale values cannot replace a generated field.' );
$invalid = $locale_record; $invalid['locale_preservation']['variants'][] = $invalid['locale_preservation']['variants'][0];
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout::from_recipe( $invalid ) ), 'Ambiguous locale identities are rejected.' );
$invalid = $locale_draft; $invalid['stored_layout']['locale_preservation']['variants'][0]['values'][$locale_heading]['value'] = 'Tampered';
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout::compile( $invalid, $locale_values, 'fixture', 'it-IT' ) ), 'Locale values remain covered by the stored layout digest.' );
$legacy = $locale_record; unset( $legacy['locale_preservation'] );
$legacy_draft = ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $legacy ) );
check( ! isset( $legacy_draft['stored_layout']['locale_preservation'] ), 'Historical mappings retain their original serialized layout shape.' );
if ( ! function_exists( 'pll_get_post_translations' ) ) {
    function pll_get_post_translations( $id ) { return $GLOBALS['locale_test_group']; }
    function pll_get_post_language( $id, $field = 'slug' ) {
        $code = array_search( $id, $GLOBALS['locale_test_group'], true );
        return 'locale' === $field ? [ 'nl' => 'nl_NL', 'it' => 'it_IT' ][$code] : $code;
    }
}
$GLOBALS['locale_test_group'] = [ 'nl' => 910, 'it' => 911 ];
foreach ( $GLOBALS['locale_test_group'] as $code => $id ) {
    $post = new WP_Post(); $post->ID = $id; $post->post_type = 'page'; $posts[$id] = $post;
    $heading_node = (object) [ 'node' => 'sameheading', 'type' => 'module', 'parent' => null, 'position' => 0,
        'settings' => (object) [ 'type' => 'heading', 'heading' => 'it' === $code ? 'Titolo conservato' : 'Bewaarde titel', 'tag' => 'h2' ] ];
    $body_node = (object) [ 'node' => 'samebody', 'type' => 'module', 'parent' => null, 'position' => 1,
        'settings' => (object) [ 'type' => 'rich-text', 'text' => '<p>Identical value</p>' ] ];
    if ( 'it' === $code ) { $body_node->position = 0; $heading_node->position = 1; }
    $meta[$id] = [ '_fl_builder_enabled' => true, '_fl_builder_data' => [ 'sameheading' => $heading_node, 'samebody' => $body_node ] ];
}
$source = ok( $adapter->capture( 910 ) );
$source_fields = array_column( $source['fields'], null, 'value' );
$snapshot = Nova_Bridge_Suite_Layout_Locales::capture( [ 'id' => 'post_type:page', 'type' => 'post_type', 'post_type' => 'page' ], 'beaver', $source['blueprint'], 910 );
check( count( $snapshot['variants'] ) === 1, 'Capture uses the explicitly linked locale reference.' );
check( $snapshot['variants'][0]['values'][$source_fields['Bewaarde titel']['target']]['value'] === 'Titolo conservato', 'Stable native control identity survives reordered localized modules.' );
check( ! isset( $snapshot['variants'][0]['values'][$source_fields['<p>Identical value</p>']['target']] ), 'Identical values are not duplicated in locale snapshots.' );
echo 'Locale preservation checks: ' . ( $checks - $locale_start ) . " passed.\n";
