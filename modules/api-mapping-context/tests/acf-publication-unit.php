<?php
/** Real ACF reader, schema validator and publication provider; in-memory WordPress seams. */
require __DIR__ . '/recipe-visual-api-unit.php';
function wp_get_attachment_url( $id ) { return 'https://site.example/media/' . $id . '.png'; }
function acf_validate_value( $value, $field, $path ) {
    if ( ! empty( $GLOBALS['native_validation'] ) && ! isset( $field['_name'] ) ) { throw new RuntimeException( 'ACF validation requires its full native field definition.' ); }
    return true;
}
$administrator = true; $denied_caps = []; $acf_example_only = false; $acf_reject_save = false;
$acf_schema = [
    'header' => af( 'header', 'group', [ 'sub_fields' => [ af( 'heading' ), af( 'button', 'link' ) ] ] ),
    'components' => af( 'components', 'flexible_content', [ 'layouts' => [
        [ 'key' => 'layout_text', 'name' => 'text', 'label' => 'Text', 'sub_fields' => [ af( 'theme', 'select', [ 'choices' => [ 'light' => 'Light', 'dark' => 'Dark' ] ] ), af( 'columns', 'repeater', [ 'sub_fields' => [ af( 'body', 'wysiwyg' ) ] ] ), af( 'links', 'repeater', [ 'sub_fields' => [ af( 'link', 'link' ) ] ] ) ] ],
        [ 'key' => 'layout_image', 'name' => 'image', 'label' => 'Image', 'sub_fields' => [ af( 'photo', 'image' ), af( 'alignment', 'select', [ 'choices' => [ 'right' => 'Right', 'left' => 'Left' ] ] ) ] ],
    ] ] ),
    'meta_title' => af( 'meta_title' ),
];
$document = [ 'header' => [ 'heading' => 'Example heading', 'button' => [ 'url' => '/news/', 'title' => 'Read more', 'target' => '' ] ], 'components' => [
    [ 'acf_fc_layout' => 'text', 'theme' => 'light', 'columns' => [ [ 'body' => '<p>First paragraph.</p>' ] ], 'links' => [] ],
    [ 'acf_fc_layout' => 'text', 'theme' => 'dark', 'columns' => [ [ 'body' => '<p>Second paragraph.</p>' ] ], 'links' => [] ],
    [ 'acf_fc_layout' => 'image', 'photo' => 42, 'alignment' => 'right' ],
], 'meta_title' => 'Example SEO' ];
foreach ( $acf_schema as &$definition ) { $definition['_name'] = $definition['name']; } unset( $definition );
$posts[11]->post_content = '';
$posts[42] = new WP_Post( [ 'ID' => 42, 'post_type' => 'attachment', 'post_title' => 'Image', 'post_content' => '', 'post_status' => 'inherit', 'post_author' => 7 ] );
foreach ( $document as $name => $value ) { $acf_values[11][$acf_schema[$name]['key']] = $value; }
check( Nova_Bridge_Suite_Recipe_Visual::detected_builder( $resource, 11 ) === 'acf', 'A populated ACF component page must be detected.' );
$posts[11]->post_content = '<p>Ordinary post body.</p>';
check( Nova_Bridge_Suite_Recipe_Visual::detected_builder( $resource, 11 ) === 'core', 'Companion collections must not replace ordinary post content.' );
$posts[11]->post_content = '';
$fixed = Nova_Bridge_Suite_Recipe_ACF::design( 'page', 11, 'protected-template.php', '', true );
check( ! is_wp_error( $fixed ) && Nova_Bridge_Suite_Recipe_Engine::digest( $fixed['blueprint']['document'] ) === Nova_Bridge_Suite_Recipe_Engine::digest( $document ), 'Fixed capture must preserve rows, empty links, settings and media exactly.' );
$fields = array_column( $fixed['fields'], null, 'target' );
foreach ( [ '/document/header/heading', '/document/header/button/url', '/document/components/0/columns/0/body', '/document/components/1/columns/0/body', '/document/components/2/photo' ] as $path ) { check( ! empty( $fields[$path]['writable'] ), 'Nested editable field missing: ' . $path ); }
check( $fields['/document/components/2/photo']['native_media']['kind'] === 'acf', 'Image IDs must be represented as native media, not text.' );
check( ! isset( $fields['/document/components/0/links/0/link/url'] ), 'Capturing an empty collection must not invent a link.' );
$legacy = Nova_Bridge_Suite_Recipe_ACF::design( 'page', 11, 'protected-template.php' );
$legacy_fields = array_column( $legacy['fields'], null, 'target' );
check( empty( $legacy_fields['/document/components/2/photo']['writable'] ), 'Existing dynamic/local recipe media contract remains unchanged.' );
$provider = new Nova_Bridge_Suite_Recipe_ACF();
$native_validation = true;
$compiled = $provider->finalize( $fixed['blueprint'], 'approval' );
check( ! is_wp_error( $compiled ) && isset( $compiled['writer_payload']['acf_layout']['schema_hash'] ), 'Approval and delivery compilation must both carry the native schema context.' );
$payload = [ 'acf_layout' => array_diff_key( $fixed['blueprint'], [ 'document' => true ] ), 'meta_all' => [ 'acf' => $document ] ];
require_once dirname( __DIR__, 2 ) . '/posting-service/includes/class-nova-bridge-suite-native-media.php';
$manifest = $payload; $path = '/document/components/2/photo';
$manifest['nova_media'][$path] = [ 'kind' => 'acf', 'id_target' => $path, 'url' => 'https://site.example/new.png' ];
check( true === Nova_Bridge_Suite_Native_Media::validate( 'acf', $manifest ), 'The image manifest must accept the captured nested attachment control.' );
$forged = $manifest; $forged['nova_media'] = [ '/document/header/heading' => [ 'kind' => 'acf', 'id_target' => '/document/header/heading', 'url' => 'https://site.example/new.png' ] ];
check( is_wp_error( Nova_Bridge_Suite_Native_Media::validate( 'acf', $forged ) ), 'An image must not be written into an arbitrary text field.' );
$forged = $manifest; $forged['nova_media'][$path]['id_target'] = '/document/components/0/theme';
check( is_wp_error( Nova_Bridge_Suite_Native_Media::validate( 'acf', $forged ) ), 'Image target identity must come from the saved schema.' );
$payload['meta_all']['acf']['header']['heading'] = 'Delivered heading';
$payload['meta_all']['acf']['components'][0]['columns'][0]['body'] = '<p>Delivered intro &amp; details.</p>';
$payload['meta_all']['acf']['components'][1]['columns'][0]['body'] = '<p>Delivered continuation.</p>';
check( $provider->publication_support( 'page' )['supported'] && ! $provider->publication_support( 'product' )['supported'], 'Normal post scope must not include commerce/system resources.' );
check( ! is_wp_error( $provider->publication_probe( 'page', $payload, 'publish' ) ), 'Complete fixed ACF payload must pass the publication probe.' );
$id = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Reserved', 'post_content' => '', 'post_status' => 'draft' ], true );
unset( $posts[11], $acf_values[11] );
$saved = $provider->publication_apply( $id, $payload, [ 'title' => 'Delivered page', 'excerpt' => 'Summary' ] );
check( ! is_wp_error( $saved ), 'Publication failed: ' . ( is_wp_error( $saved ) ? $saved->get_error_message() : '' ) );
$actual = Nova_Bridge_Suite_Content_Transport::read_named_acf( $fixed['blueprint']['schema'], $id );
check( Nova_Bridge_Suite_Recipe_Engine::digest( $actual ) === Nova_Bridge_Suite_Recipe_Engine::digest( $payload['meta_all']['acf'] ), 'Nested native fields must round-trip with the example deleted.' );
check( $posts[$id]->post_title === 'Delivered page' && $actual['components'][2]['alignment'] === 'right' && $actual['components'][0]['links'] === [], 'Title, design and empty collections must survive.' );
$payload['meta_all']['acf']['components'][0]['columns'][0]['body'] = '<p>Updated intro.</p>';
$before = $post_counter;
check( ! is_wp_error( $provider->publication_apply( $id, $payload, [ 'title' => 'Updated page' ] ) ) && $post_counter === $before, 'Updating must use the retained ID without inserting another post.' );
check( ! is_wp_error( $provider->publication_verify( $id, $payload ) ), 'Recovery must recognize the retained payload.' );
$acf_reject_save = true; $payload['meta_all']['acf']['header']['heading'] = 'Rejected change';
check( is_wp_error( $provider->publication_apply( $id, $payload, [ 'title' => 'Interrupted page' ] ) ) && is_wp_error( $provider->publication_verify( $id, $payload ) ), 'Rejected native save must never report publication success.' );
$acf_reject_save = false;
$wrong = $payload; $wrong['acf_layout']['post_type'] = 'post';
check( is_wp_error( $provider->publication_probe( 'page', $wrong, 'publish' ) ), 'Post type mismatch must be rejected.' );
$wrong = $payload; $wrong['meta_all']['acf']['components'][0]['acf_fc_layout'] = 'invented';
check( is_wp_error( $provider->publication_probe( 'page', $wrong, 'publish' ) ), 'An unknown native row shape must be rejected.' );
$wrong = $payload; $wrong['meta_all']['acf']['components'][0]['unexpected'] = 'x';
check( is_wp_error( $provider->publication_probe( 'page', $wrong, 'publish' ) ), 'Delivery data cannot add arbitrary native fields.' );
$acf_schema['meta_title']['label'] = 'New schema';
check( is_wp_error( $provider->publication_probe( 'page', $payload, 'publish' ) ), 'A changed field definition must require recapture.' );
$denied_caps = [ 'edit_posts' ];
check( ! $provider->publication_support( 'page' )['supported'], 'Publishing user capabilities still apply.' );
echo "PASS fixed nested ACF publication, detection, images, source independence, updates and recovery\n";
