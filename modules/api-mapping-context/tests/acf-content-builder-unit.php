<?php
/**
 * A theme "content builder": flexible layouts with page links, conditional buttons, a sub-field
 * type without dedicated handling and same-named sub-fields. Real ACF reader, recipe adapter and
 * publication provider; in-memory WordPress seams.
 */
require __DIR__ . '/recipe-visual-api-unit.php';
if ( ! function_exists( 'wp_get_attachment_url' ) ) { function wp_get_attachment_url( $id ) { return 'https://site.example/media/' . $id . '.png'; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $url ) { return $url; } }
if ( ! function_exists( 'acf_validate_value' ) ) { function acf_validate_value( $value, $field, $path ) { return true; } }
use Nova_Bridge_Suite_Content_Transport as Transport;
use Nova_Bridge_Suite_Recipe_ACF as ACF;
use Nova_Bridge_Suite_Recipe_Engine as Engine;

$administrator = true; $denied_caps = []; $acf_example_only = false; $acf_reject_save = false; $native_validation = false;
$posts[30] = new WP_Post( [ 'ID' => 30, 'post_type' => 'page', 'post_title' => 'Linked page', 'post_content' => '', 'post_status' => 'publish', 'post_author' => 7 ] );
$posts[42] = new WP_Post( [ 'ID' => 42, 'post_type' => 'attachment', 'post_title' => 'Image', 'post_content' => '', 'post_status' => 'inherit', 'post_author' => 7 ] );

function button_fields( bool $required ): array {
    $type = af( 'button_type', 'select', [ 'choices' => [ 'normal' => 'Page', 'url' => 'Address' ], 'required' => $required ] );
    $when = static function ( $value ) use ( $type ) { return [ 'conditional_logic' => [ [ [ 'field' => $type['key'], 'operator' => '==', 'value' => $value ] ] ], 'required' => true ]; };
    return [ $type, af( 'button_label' ), af( 'button_link', 'page_link', $when( 'normal' ) + [ 'post_type' => [ 'page' ], 'multiple' => 0, 'allow_null' => 0 ] ), af( 'button_url', 'text', $when( 'url' ) ) ];
}
$hide_a = af( 'hide_block', 'checkbox', [ 'choices' => [ 'yes' => 'Hide' ] ] ); $hide_b = af( 'hide_block', 'checkbox', [ 'choices' => [ 'yes' => 'Hide' ], 'label' => 'Hide (copy)' ] );
$builder = af( 'builder', 'flexible_content', [ 'layouts' => [
    [ 'key' => 'layout_hero', 'name' => 'hero', 'label' => 'Hero', 'sub_fields' => array_merge( [ af( 'title', 'text', [ 'required' => true ] ), af( 'image', 'image' ) ], button_fields( true ), [ $hide_a, $hide_b ] ) ],
    [ 'key' => 'layout_cards', 'name' => 'cards', 'label' => 'Cards', 'sub_fields' => [ af( 'block_title' ), af( 'cards', 'repeater', [ 'sub_fields' => array_merge( [ af( 'title', 'text', [ 'required' => true ] ), af( 'text', 'wysiwyg' ), af( 'image', 'image' ) ], button_fields( false ) ) ] ) ] ],
    [ 'key' => 'layout_text', 'name' => 'text', 'label' => 'Text', 'sub_fields' => [ [ 'key' => 'field_tab', 'name' => '', 'type' => 'tab', 'label' => 'Content' ], af( 'content', 'wysiwyg', [ 'required' => true ] ), af( 'icon', 'icon_picker' ), af( 'shared', 'clone', [ 'sub_fields' => [ af( 'note' ) ] ] ) ] ],
] ] );
$acf_schema = [ 'builder' => $builder, 'intro' => af( 'intro', 'textarea' ) ];
$document = [ 'builder' => [
    [ 'acf_fc_layout' => 'hero', 'title' => 'Example hero', 'image' => 42, 'button_type' => 'normal', 'button_label' => 'More', 'button_link' => 30, 'button_url' => '', 'hide_block' => [] ],
    [ 'acf_fc_layout' => 'cards', 'block_title' => 'Our range', 'cards' => [
        [ 'title' => 'First', 'text' => '<p>First card.</p>', 'image' => 42, 'button_type' => 'normal', 'button_label' => 'Read', 'button_link' => 30, 'button_url' => '' ],
        [ 'title' => 'Second', 'text' => '<p>Second card.</p>', 'image' => 42, 'button_type' => 'url', 'button_label' => 'Open', 'button_link' => '', 'button_url' => '#tool' ],
    ] ],
    [ 'acf_fc_layout' => 'text', 'content' => '<p>Body text.</p>', 'icon' => [ 'type' => 'dashicons', 'value' => 'dashicons-star-filled' ], 'shared' => [ 'note' => 'Kept as is' ] ],
], 'intro' => 'Intro text' ];
$posts[11]->post_content = '';
$acf_values[11] = [];
foreach ( $document as $name => $value ) { $acf_values[11][$acf_schema[$name]['key']] = $value; }

// Reading: a page link, an unhandled sub-field type and same-named sub-fields no longer hide the whole builder.
$catalog = Transport::field_catalog( 'page', 11 )['acf'];
check( isset( $catalog['builder'], $catalog['intro'] ), 'A content builder with page links must be exposed.' );
$legacy = Transport::with_legacy_acf_rules( static function () { return Transport::field_catalog( 'page', 11 )['acf']; } );
check( ! isset( $legacy['builder'] ) && isset( $legacy['intro'] ), 'The 1.2.2 filter is reproducible for layouts sealed with it.' );
check( Nova_Bridge_Suite_Recipe_Visual::detected_builder( $resource, 11 ) === 'acf', 'A page built only from a content builder must be detected.' );
$read = Transport::read_named_acf( $catalog, 11 );
check( Engine::digest( $read ) === Engine::digest( $document ), 'Page links, carried values and duplicates must read back exactly: ' . json_encode( $read ) );

// Mapping: text stays mappable, a page link and carried values are kept from the example.
$fixed = ACF::design( 'page', 11, 'protected-template.php', '', true );
check( ! is_wp_error( $fixed ), 'Fixed capture failed: ' . ( is_wp_error( $fixed ) ? $fixed->get_error_message() : '' ) );
$fields = array_column( $fixed['fields'], null, 'target' );
foreach ( [ '/document/builder/0/title', '/document/builder/1/cards/0/title', '/document/builder/1/cards/1/button_url', '/document/builder/2/content', '/document/builder/0/image' ] as $path ) { check( ! empty( $fields[$path]['writable'] ), 'Editable field missing: ' . $path ); }
check( empty( $fields['/document/builder/0/button_link']['writable'] ) && 30 === $fields['/document/builder/0/button_link']['value'], 'A page link keeps its example target.' );
check( empty( $fields['/document/builder/2/icon']['writable'] ) && empty( $fields['/document/builder/2/shared']['writable'] ), 'Unhandled sub-field types are carried, never written from content.' );
check( 1 === count( array_filter( array_keys( $fields ), static function ( $path ) { return '/document/builder/0/hide_block' === $path; } ) ), 'Same-named sub-fields appear once.' );
$hero = array_values( array_filter( $fixed['blueprint']['schema']['builder']['layouts'], static function ( $layout ) { return 'hero' === $layout['name']; } ) )[0];
check( 1 === count( array_keys( array_column( $hero['sub_fields'], 'name' ), 'hide_block', true ) ), 'The saved schema lists a shared sub-field once.' );

// Preview: the theme prints these values, so the canvas looks for them by value.
$visual = array_column( Nova_Bridge_Suite_Recipe_Visual::enrich( [ 'builder' => 'acf' ] + $fixed + [ 'recipe_preset' => [ 'mode' => 'static' ] ], $resource )['preview_fields'], 'locator', 'target' );
check( [ 'strategy' => 'value_match', 'node_id' => 'acf', 'text' => 'Example hero', 'precision' => 'field' ] === $visual['/document/builder/0/title'], 'A text field is located by its text.' );
check( 'First card.' === $visual['/document/builder/1/cards/0/text']['text'] && 'region' === $visual['/document/builder/1/cards/0/text']['precision'], 'An editor field is located by its visible text.' );
check( 'https://site.example/media/42.png' === $visual['/document/builder/0/image']['media'], 'An image is located by its media file.' );
foreach ( [ '/document/builder/1/cards/1/button_url', '/document/builder/0/button_link', '/document/builder/2/icon' ] as $path ) { check( 'unbound' === $visual[$path]['strategy'], 'Addresses, page links and carried values are not looked up on the page: ' . $path ); }

// Writing: a required button address hidden by the button type is not required.
$provider = new ACF();
$compiled = $provider->finalize( $fixed['blueprint'], 'approval' );
check( ! is_wp_error( $compiled ), 'A hidden required field must not block approval: ' . ( is_wp_error( $compiled ) ? $compiled->get_error_message() : '' ) );
$shown = $fixed['blueprint']; $shown['document']['builder'][0]['button_type'] = 'url';
$blocked = $provider->finalize( $shown, 'approval' );
check( is_wp_error( $blocked ) && false !== strpos( $blocked->get_error_message(), 'button_url' ), 'A visible required field still has to be filled.' );

$payload = [ 'acf_layout' => array_diff_key( $fixed['blueprint'], [ 'document' => true ] ), 'meta_all' => [ 'acf' => $document ] ];
$payload['meta_all']['acf']['builder'][1]['cards'][0]['title'] = 'Delivered card';
$payload['meta_all']['acf']['builder'][2]['content'] = '<p>Delivered body.</p>';
check( ! is_wp_error( $provider->publication_probe( 'page', $payload, 'publish' ) ), 'The content-builder payload must pass the probe.' );
$id = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Reserved', 'post_content' => '', 'post_status' => 'draft' ], true );
$saved = $provider->publication_apply( $id, $payload, [ 'title' => 'Delivered page' ] );
check( ! is_wp_error( $saved ), 'Publication failed: ' . ( is_wp_error( $saved ) ? $saved->get_error_message() : '' ) );
$stored = $acf_values[$id][$builder['key']];
check( 30 === $stored[0][ $hero['sub_fields'][4]['key'] ] && [] === $stored[0][$hide_a['key']] && [] === $stored[0][$hide_b['key']], 'The page link and both same-named keys are written.' );
check( Engine::digest( Transport::read_named_acf( $catalog, $id ) ) === Engine::digest( $payload['meta_all']['acf'] ), 'The delivered builder must round-trip, carried values included.' );
check( ! is_wp_error( $provider->publication_verify( $id, $payload ) ), 'Recovery must recognise the delivered builder.' );

// Validation of the new value kinds.
$probe = static function ( callable $change ) use ( $provider, $payload ) { $next = $payload; $change( $next['meta_all']['acf']['builder'] ); return $provider->publication_probe( 'page', $next, 'publish' ); };
check( is_wp_error( $probe( static function ( &$rows ) { $rows[0]['button_link'] = 'javascript:alert(1)'; } ) ), 'A page link must be a page ID or an HTTP(S) URL.' );
check( is_wp_error( $probe( static function ( &$rows ) { $rows[0]['button_link'] = 999; } ) ), 'A page link must point at an existing page.' );
check( ! is_wp_error( $probe( static function ( &$rows ) { $rows[0]['button_link'] = 'https://site.example/news/'; } ) ), 'Archive links are stored as URLs.' );
check( is_wp_error( $probe( static function ( &$rows ) { $rows[2]['icon'] = [ 'bad key' => 'x' ]; } ) ), 'Carried values must stay plain stored data.' );
$filtered = Transport::validate_named_acf( [ 'builder' => [ [ 'acf_fc_layout' => 'text', 'content' => '<p>x</p>', 'icon' => '<script>x</script>star', 'shared' => [ 'note' => 'n' ] ] ] ], Transport::field_catalog( 'page', 0 )['acf'] );
check( ! is_wp_error( $filtered ) && 'star' === $filtered['builder']['value'][0][ $builder['layouts'][2]['sub_fields'][2]['key'] ], 'Markup in a carried value is filtered like post content.' );

// Fields that must still hide their container.
$acf_schema['secret_box'] = af( 'secret_box', 'group', [ 'sub_fields' => [ af( 'label' ), af( 'pin', 'password' ) ] ] );
$acf_schema['mixed'] = af( 'mixed', 'repeater', [ 'sub_fields' => [ af( 'item' ), af( 'item', 'image' ) ] ] );
$catalog = Transport::field_catalog( 'page', 0 )['acf'];
check( ! isset( $catalog['secret_box'] ) && ! isset( $catalog['mixed'] ) && isset( $catalog['builder'] ), 'Password sub-fields and conflicting same-named sub-fields still hide their container.' );
unset( $acf_schema['secret_box'], $acf_schema['mixed'] );

// Layouts saved before 1.2.3 keep working exactly as sealed.
$old = Transport::with_legacy_acf_rules( static function () { return ACF::design( 'page', 11, 'protected-template.php', '', true ); } );
check( ! is_wp_error( $old ) && ! isset( $old['blueprint']['document']['builder'] ), 'The 1.2.2 capture did not include the builder.' );
$old_compiled = ( new ACF() )->finalize( $old['blueprint'], 'approval' );
check( ! is_wp_error( $old_compiled ) && [ 'intro' ] === array_keys( $old_compiled['writer_payload']['meta_all']['acf'] ), 'A layout saved before 1.2.3 still compiles with its own fields.' );
$old_payload = [ 'acf_layout' => array_diff_key( $old['blueprint'], [ 'document' => true ] ), 'meta_all' => [ 'acf' => [ 'intro' => 'Delivered intro' ] ] ];
$old_id = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Old', 'post_content' => '', 'post_status' => 'draft' ], true );
check( ! is_wp_error( ( new ACF() )->publication_apply( $old_id, $old_payload, [ 'title' => 'Old layout' ] ) ) && ! isset( $acf_values[$old_id][$builder['key']] ), 'A layout saved before 1.2.3 publishes without touching the builder.' );
$tampered = $old['blueprint']; $tampered['schema_hash'] = str_repeat( 'a', 64 );
check( is_wp_error( ( new ACF() )->finalize( $tampered, 'approval' ) ), 'An unknown schema identity is still refused.' );
echo "PASS content builder with page links, carried sub-fields, shared names, conditional requirements and 1.2.2 layouts\n";
