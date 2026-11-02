<?php
/** Exercise a frozen Avia mapping through both compilers and native media readback without WordPress storage or HTTP. */
define( 'ABSPATH', __DIR__ );
$core = getenv( 'NOVA_WP_CORE_FIXTURE' );
if ( ! $core || ! is_file( $core . '/shortcodes.php' ) ) { throw new RuntimeException( 'Set NOVA_WP_CORE_FIXTURE to a WordPress parser fixture.' ); }
require $core . '/shortcodes.php';
class WP_Error {
    private $message;
    public function __construct( $code, $message ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function post_type_supports( $type, $feature ) { return 'portfolio' === $type && 'thumbnail' === $feature; }
function wp_get_attachment_url( $id ) { return 'https://example.test/uploads/' . $id . '.jpg'; }
function wp_attachment_is_image( $id ) { return 901 === $id; }
function get_option( $key, $default = null ) { return $GLOBALS['media_options'][$key] ?? $default; }

$includes = dirname( __DIR__ ) . '/includes/';
$posting = dirname( __DIR__, 2 ) . '/posting-service/includes/';
require $includes . 'class-nova-bridge-suite-recipe-engine.php';
require $includes . 'class-nova-bridge-suite-recipe-builders.php';
require $includes . 'class-nova-bridge-suite-mapping-drafts.php';
require $posting . 'class-nova-bridge-suite-native-media.php';
require $posting . 'class-nova-bridge-suite-stored-layout.php';
require $posting . 'class-nova-bridge-suite-stored-recipe-layout.php';
require $posting . 'class-nova-bridge-suite-writing-adapter.php';

function check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } ++$GLOBALS['checks']; }
function result( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_message() ); } return $value; }
$checks = 0;
$adapter = result( Nova_Bridge_Suite_Recipe_Builders::adapter( 'enfold' ) );
Nova_Bridge_Suite_Recipe_Engine::register_adapter( 'enfold', $adapter );
// Only capture consults the fixture registry. Neither compiler receives an example post.
$registry = new class {
    public function get_shortcode_class( $tag ) {
        $image = [ 'type' => 'image', 'id' => 'src', 'name' => 'Image', 'locked' => [ 'attachment', 'attachment_size' ] ];
        return (object) [ 'config' => [ 'name' => $tag ], 'elements' => in_array( $tag, [ 'av_section', 'av_image' ], true ) ? [ $image ] : [] ];
    }
};
$source = '[av_section attachment="42" attachment_size="large"][av_one_full][av_image attachment_size="medium"][/av_image][av_image src="keep.jpg" attachment="71"][/av_image][/av_one_full][/av_section]';
$blueprint = [ 'document' => Nova_Bridge_Suite_Enfold_Document::capture( $source, $registry ), 'template' => '', 'meta' => [] ];
unset( $registry );
$section = '/document/0/attributes/src';
$image = '/document/0/children/0/children/0/attributes/src';
$featured = Nova_Bridge_Suite_Recipe_Native_Media::FEATURED;
$bindings = [];
foreach ( [ $section, $image, $featured ] as $target ) {
    $bindings[] = [ 'target' => $target, 'source' => '/image_url', 'format' => 'url', 'required' => false, 'native_media' => true ];
}
$recipe = [ 'format' => Nova_Bridge_Suite_Recipe_Engine::FORMAT, 'builder' => 'enfold', 'mode' => 'static', 'blueprint' => $blueprint, 'bindings' => $bindings, 'regions' => [] ];
$asset = 'https://assets.example.test/generated.jpg';
$draft = result( Nova_Bridge_Suite_Stored_Recipe_Layout::from_recipe( [ 'resource_id' => 'post_type:portfolio', 'recipe' => $recipe ] ) );
$values = [ Nova_Bridge_Suite_Writing_Adapter::field_id( '/title' ) => 'Mapped Avia portfolio' ];
foreach ( $bindings as $binding ) { $values[Nova_Bridge_Suite_Writing_Adapter::field_id( $binding['target'] )] = $asset; }
$payload = result( Nova_Bridge_Suite_Stored_Recipe_Layout::compile( $draft, $values, 'test-delivery' ) )['writer_payload'];
$ui_payload = result( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, [ 'image_url' => $asset ], 'test-preview' ) )['writer_payload'];
check( $payload === $ui_payload, 'The UI and stored-layout publication must compile identical frozen media bindings.' );
check( count( $payload['nova_media'] ) === 3 && $payload['enfold_document'] === $blueprint['document'], 'All three bindings must preserve source controls until a local attachment is retained.' );
foreach ( [ $section, $image ] as $target ) {
    $entry = $payload['nova_media'][$target];
    check( $entry['kind'] === 'enfold' && $entry['url_target'] === $target && $entry['id_target'] === dirname( $target ) . '/attachment', 'The Avia capture must produce a sibling URL and attachment ID for each selected control.' );
}

$operation = 'test-delivery';
$hash = hash( 'sha256', $operation . ':' . $asset );
$media_options = [ 'nova_cpt_media_' . $hash => [ 'state' => 'complete', 'attachment_id' => 901 ] ];
$wpdb = new class( 'nova.cpt-media/v1:' . $hash ) {
    public $posts = 'mock_posts'; public $last_error = ''; public $reads = 0; private $marker;
    public function __construct( $marker ) { $this->marker = $marker; }
    public function prepare( $query, $type, $marker ) { return $marker; }
    public function get_col( $marker ) { ++$this->reads; return $marker === $this->marker ? [ 901 ] : []; }
};
$resolved = result( Nova_Bridge_Suite_Native_Media::resolve( 'enfold', 17, $payload, $operation, false ) );
check( $wpdb->reads === 1 && 901 === $resolved['featured'], 'Readback must reuse the retained attachment for all three destinations.' );
$section_node = $resolved['payload']['enfold_document'][0];
$images = $section_node['children'][0]['children'];
foreach ( [ $section_node, $images[0] ] as $node ) {
    check( $node['attributes']['attachment'] === '901' && $node['attributes']['src'] === wp_get_attachment_url( 901 ), 'Both native attributes must be written, including previously absent controls.' );
}
check( $section_node['attributes']['attachment_size'] === 'large' && $images[0]['attributes']['attachment_size'] === 'medium', 'Enfold should retain each selected attachment size.' );
check( $images[1] === $blueprint['document'][0]['children'][0]['children'][1], 'An unmapped decorative image must remain byte-identical.' );
check( 2 === substr_count( Nova_Bridge_Suite_Enfold_Document::source( [ $section_node ] ), wp_get_attachment_url( 901 ) ), 'The editor shortcode document must contain the two local URLs.' );

$forged = $payload; $forged['nova_media'][$section]['id_target'] = $payload['nova_media'][$image]['id_target'];
check( is_wp_error( Nova_Bridge_Suite_Native_Media::validate( 'enfold', $forged ) ), 'An image cannot overwrite the attachment ID on a different control.' );
check( is_wp_error( Nova_Bridge_Suite_Native_Media::validate( 'flatsome', $payload ) ), 'The Avia image pair cannot be applied by a different builder.' );
foreach ( $bindings as $binding ) { $values[Nova_Bridge_Suite_Writing_Adapter::field_id( $binding['target'] )] = ''; }
$optional = result( Nova_Bridge_Suite_Stored_Recipe_Layout::compile( $draft, $values, 'missing' ) )['writer_payload'];
check( ! isset( $optional['nova_media'] ) && $optional['enfold_document'] === $blueprint['document'], 'Missing optional images must retain the captured design.' );
$media_options['nova_cpt_media_' . $hash] = [ 'state' => 'skipped' ];
$skipped = result( Nova_Bridge_Suite_Native_Media::resolve( 'enfold', 17, $payload, $operation, false ) );
check( ! isset( $skipped['featured'] ) && $skipped['payload'] === $optional, 'A skipped optional import must keep the frozen native attributes and featured image.' );
echo 'Enfold media integration checks: ' . $checks . " passed.\n";
