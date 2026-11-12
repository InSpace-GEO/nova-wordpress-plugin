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
$snapshot['catalog'][] = [ 'code' => 'fr', 'locale' => 'fr_FR' ];
$manual_recipe = [ 'builder' => 'beaver', 'mode' => 'static', 'blueprint' => $source['blueprint'], 'bindings' => [], 'regions' => [], 'locale_values' => [
    'it_IT' => [ $source_fields['Bewaarde titel']['target'] => 'Manual should yield to the reference', $source_fields['<p>Identical value</p>']['target'] => '<p>Manual should yield to identical reference text</p>' ],
    'fr_FR' => [ $source_fields['Bewaarde titel']['target'] => 'Titre manuel' ],
] ];
$manual_snapshot = Nova_Bridge_Suite_Layout_Locales::with_manual( $snapshot, $manual_recipe );
check( $manual_snapshot['variants'][0]['values'][$source_fields['Bewaarde titel']['target']]['value'] === 'Titolo conservato', 'Manual fallbacks yield to existing localized reference values.' );
check( ! isset( $manual_snapshot['variants'][0]['values'][$source_fields['<p>Identical value</p>']['target']] ), 'An identical native localized value also takes precedence over a manual fallback.' );
check( $manual_snapshot['variants'][1]['reference_id'] === 0 && $manual_snapshot['variants'][1]['values'][$source_fields['Bewaarde titel']['target']]['value'] === 'Titre manuel', 'A configured locale without a matching reference accepts a manual fallback.' );
$manual_record = html_record( $source['blueprint'], [], [] );
$manual_record['builder'] = $manual_record['recipe']['builder'] = 'beaver';
$manual_record['recipe']['locale_values'] = $manual_recipe['locale_values'];
$manual_record['locale_preservation'] = $manual_snapshot;
$manual_draft = ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $manual_record ) );
$manual_compiled = ok( Nova_Bridge_Suite_Stored_Layout::compile( $manual_draft, [ Nova_Bridge_Suite_Writing_Adapter::field_id( '/title' ) => 'Delivered title' ], 'manual-locale', 'fr-FR' ) );
check( str_contains( wp_json_encode( $manual_compiled['writer_payload'] ), 'Titre manuel' ), 'Publishing uses the saved manual value for its exact locale.' );
$unknown = $manual_recipe; $unknown['locale_values'] = [ 'zz_ZZ' => [ $source_fields['Bewaarde titel']['target'] => 'Unknown' ] ];
try { Nova_Bridge_Suite_Layout_Locales::with_manual( $snapshot, $unknown ); check( false, 'Unknown locale should fail' ); }
catch ( InvalidArgumentException $error ) { check( true, 'Manual values cannot create unconfigured locales.' ); }
// Core descriptors expose their values through the blueprint rather than a value property.
foreach ( [ 910 => 'Dutch body', 911 => '<p>Corpo italiano &amp; contenuto</p>' ] as $id => $body ) {
    $posts[$id]->post_title = 'Title ' . $id;
    $posts[$id]->post_content = $body;
    $meta[$id] = [];
}
$resource = [ 'id' => 'post_type:page', 'type' => 'post_type', 'post_type' => 'page' ];
$core = ok( Nova_Bridge_Suite_Recipe_Core::design( $resource, 910, 'static' ) );
$core_snapshot = Nova_Bridge_Suite_Layout_Locales::capture( $resource, 'core', $core['blueprint'], 910 );
check( $core_snapshot['variants'][0]['values']['/document/sections/0/body']['value'] === $posts[911]->post_content, 'Core references capture values from the blueprint.' );
$core_restored = Nova_Bridge_Suite_Layout_Locales::apply( $core_snapshot, 'it-IT', $core['blueprint'], Nova_Bridge_Suite_Recipe_Engine::adapter( 'core' ) );
check( $core_restored['document']['sections'][0]['body'] === $posts[911]->post_content, 'Core locale content preserves its exact HTML.' );

$elementor = Nova_Bridge_Suite_Recipe_Engine::adapter( 'elementor' );
$elementor_blueprint = [ 'document' => [ [ 'id' => 'head123', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Contact' ], 'elements' => [] ] ], 'meta' => [], 'template' => '' ];
$italian_heading = 'Contatti <em>oggi</em> &amp; domani';
$native_snapshot = $locale_snapshot;
$native_snapshot['variants'] = [ [ 'locale' => 'it-IT', 'code' => 'it', 'reference_id' => 911, 'values' => [
    '/document/0/settings/title' => [ 'format' => 'text', 'value' => $italian_heading, 'native_value' => $italian_heading ],
] ] ];
$restored = Nova_Bridge_Suite_Layout_Locales::apply( $native_snapshot, 'it-IT', $elementor_blueprint, $elementor );
check( $restored['document'][0]['settings']['title'] === $italian_heading, 'Captured Elementor inline markup and entities retain native encoding.' );
$manual_snapshot = $native_snapshot;
unset( $manual_snapshot['variants'][0]['values']['/document/0/settings/title']['native_value'] );
$manual_snapshot['variants'][0]['values']['/document/0/settings/title']['source'] = 'manual';
$restored = Nova_Bridge_Suite_Layout_Locales::apply( $manual_snapshot, 'it-IT', $elementor_blueprint, $elementor );
check( $restored['document'][0]['settings']['title'] === htmlspecialchars( $italian_heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ), 'Manual text continues through the normal text binding rules.' );

echo 'Locale preservation checks: ' . ( $checks - $locale_start ) . " passed.\n";
