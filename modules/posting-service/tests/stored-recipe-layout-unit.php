<?php
/** Pure saved-layout projection/compilation with real WordPress block serialization. */
if ( defined( 'ABSPATH' ) ) { throw new RuntimeException( 'Run standalone only.' ); }
$core = getenv( 'NOVA_WP_CORE_FIXTURE' );
if ( ! $core || ! is_file( $core . '/blocks.php' ) || ! is_file( $core . '/class-wp-block-parser.php' ) ) {
    echo "SKIP: set NOVA_WP_CORE_FIXTURE to a disposable copy of WordPress blocks.php and its parser.\n";
    exit( 0 );
}
define( 'ABSPATH', __DIR__ . '/' );
set_error_handler( static function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function apply_filters( $hook, $value ) { return $value; }
function wp_kses_post( $value ) { return preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $value ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function current_user_can( ...$args ) { return true; }
function get_post_type_object( $type ) { return (object) [ 'cap' => (object) [ 'create_posts' => 'edit_posts', 'edit_posts' => 'edit_posts' ] ]; }
function no_live_io(): void { ++$GLOBALS['live_io_attempts']; throw new RuntimeException( 'Saved recipe compilation must not read an example, write data, or call HTTP.' ); }
function get_post( $id ) {
    if ( isset( $GLOBALS['loop_fixture'] ) && 4121 === (int) $id ) { return $GLOBALS['loop_fixture']['post']; }
    no_live_io();
}
function get_post_meta( $id, $key, $single ) {
    if ( isset( $GLOBALS['loop_fixture'] ) && 4121 === (int) $id && '_elementor_template_type' === $key && $single ) { return $GLOBALS['loop_fixture']['type']; }
    no_live_io();
}
function metadata_exists( ...$args ) { no_live_io(); }
function get_page_template_slug( ...$args ) { no_live_io(); }
function get_option( ...$args ) { no_live_io(); }
function wp_insert_post( ...$args ) { no_live_io(); }
function wp_update_post( ...$args ) { no_live_io(); }
function update_post_meta( ...$args ) { no_live_io(); }
function update_option( ...$args ) { no_live_io(); }
function wp_remote_request( ...$args ) { no_live_io(); }
function wp_remote_get( ...$args ) { no_live_io(); }
function wp_remote_post( ...$args ) { no_live_io(); }
if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $value, $start, $length = null ) { return implode( '', array_slice( preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY ), $start, $length ) ); } }
require $core . '/class-wp-block-parser.php';
require $core . '/blocks.php';
require dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-mapping-drafts.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-writing-adapter.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-protocol.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout.php';
use Nova_Bridge_Suite_Stored_Layout as Layout;
use Nova_Bridge_Suite_Writing_Adapter as Writing;
$checks = 0; $live_io_attempts = 0;
function check( $truth, string $message ): void { ++$GLOBALS['checks']; if ( ! $truth ) { throw new RuntimeException( $message ); } }
function good( $result, string $message ) { check( ! is_wp_error( $result ), $message . ( is_wp_error( $result ) ? ': ' . $result->get_error_message() : '' ) ); return $result; }
function binding( string $target, string $format, string $instructions ): array {
    return [ 'target' => $target, 'source' => '/body', 'format' => $format, 'instructions' => $instructions, 'label' => 'Editorial field ' . $target, 'required' => true ];
}
function record( string $builder, string $type, array $blueprint, array $bindings ): array {
    return [ 'id' => '11111111-1111-4111-8111-111111111111', 'revision' => 9, 'builder' => $builder,
        'resource_id' => 'post_type:' . $type, 'posting_page_type' => 'informative', 'label' => 'Saved ' . $builder . ' design',
        'reference_id' => 987654321, // This source is deliberately absent; compilation has only the saved blueprint.
        'recipe' => [ 'builder' => $builder, 'mode' => 'static', 'blueprint' => $blueprint,
            'instructions' => 'Keep every paragraph in its original order. Do not repeat the heading in the body.',
            'bindings' => $bindings, 'regions' => [], 'custom_fields' => [] ] ];
}
function fitted( array $by_path ): array { $values = []; foreach ( $by_path as $path => $value ) { $values[Writing::field_id( $path )] = $value; } return $values; }
function check_projection( array $draft, array $record ): void {
    check( $draft['reference_id'] === 0 && $draft['revision'] === $record['id'] . ':9', 'Saved recipe identity does not retain a live example dependency' );
    check( $draft['routing']['post_type'] === substr( $record['resource_id'], 10 ) && $draft['routing']['operation'] === 'create', 'Selected native post type survives projection' );
    check( $draft['profile_page_type'] === 'informative' && $draft['guidance'] === $record['recipe']['instructions'], 'NOVA page type and template instructions survive exactly' );
    check( $draft['stored_layout']['blueprint'] === $record['recipe']['blueprint'], 'Projection retains the complete saved native blueprint' );
    foreach ( $record['recipe']['bindings'] as $binding ) {
        $field = $draft['fields'][$binding['target']];
        check( $field['instructions'] === $binding['instructions'] && $field['description']['label'] === $binding['label'], 'Field instructions and human label survive projection' );
        check( $field['description']['value_type'] === ( 'html' === $binding['format'] ? 'rich_text' : 'text' ), 'The editorial field retains its intended format' );
    }
}

$elementor = [ 'template' => 'elementor_canvas', 'meta' => [ 'background_color' => '#eef2f8', '__globals__' => [ 'background_color' => 'globals/colors?id=primary' ] ],
    'document' => [ [ 'id' => 'aaaaaaa', 'elType' => 'container', 'settings' => [ 'content_width' => 'full', 'padding_mobile' => [ 'unit' => 'px', 'top' => 24 ] ], 'elements' => [
        [ 'id' => 'bbbbbbb', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Example title', 'header_size' => 'h2', 'typography_font_size' => [ 'size' => 38.5, 'unit' => 'px' ] ], 'elements' => [] ],
        [ 'id' => 'ccccccc', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => [ 'editor' => '<p>Example body</p>' ], 'elements' => [] ],
    ] ] ] ];
$headline = '/document/0/elements/0/settings/title'; $body = '/document/0/elements/1/settings/editor';
foreach ( [ 'post', 'case_study' ] as $type ) {
    $saved = record( 'elementor', $type, $elementor, [ binding( $headline, 'text', 'Use the section heading once.' ), binding( $body, 'html', 'Place the article body here without duplicating the heading.' ) ] );
    $draft = good( Layout::from_recipe( $saved ), 'Elementor projects for ' . $type );
    check_projection( $draft, $saved );
    $values = fitted( [ '/title' => 'New native title', $headline => 'New section heading', $body => '<p>First paragraph.</p><p>Second paragraph.</p>' ] );
    $compiled = good( Layout::compile( $draft, $values, 'elementor-' . $type ), 'Elementor compiles for ' . $type );
    check( $compiled['builder'] === 'elementor' && $compiled['post_type'] === $type, 'Elementor does not coerce Posts or arbitrary CPTs into Pages' );
    check( $compiled['post_fields']['title'] === 'New native title', 'Elementor retains the fitted native title' );
    $out = $compiled['writer_payload'];
    check( $out['elementor_data'][0]['elements'][0]['settings']['title'] === 'New section heading' && $out['elementor_data'][0]['elements'][1]['settings']['editor'] === '<p>First paragraph.</p><p>Second paragraph.</p>', 'Both fitted editorial values replace the saved example values' );
    check( $out['elementor_data'][0]['settings'] === $elementor['document'][0]['settings'] && $out['elementor_data'][0]['elements'][0]['settings']['typography_font_size']['size'] === 38.5, 'Elementor responsive settings and fractional typography survive' );
    check( $out['elementor_page_settings'] === $elementor['meta'] && $out['template'] === 'elementor_canvas', 'Elementor native document settings and template survive' );
    check( $out['elementor_data'][0]['id'] !== 'aaaaaaa', 'Each compiled Elementor item gets independent element identities' );
    check( $compiled === good( Layout::compile( $draft, $values, 'elementor-' . $type ), 'Retained Elementor operation recompiles' ), 'Retained operation compilation is deterministic' );
    $wrong = $draft; $wrong['routing']['post_type'] = 'page';
    check( is_wp_error( Layout::compile( $wrong, $values, 'wrong-destination' ) ), 'A changed destination cannot silently replace the selected native post type' );
}

$public_loop_item = [ 'ID' => 4121, 'post_type' => 'elementor_library', 'post_status' => 'publish', 'post_password' => '' ];
$loop_fixture = [ 'post' => (object) $public_loop_item, 'type' => 'loop-item' ];

// Elementor stores the Loop Carousel on LSG's Waardebepaling example with this string ID.
$loop_saved = record( 'elementor', 'page', $elementor, [ binding( $headline, 'text', 'Map only the section heading.' ) ] );
$loop_widget = [ 'id' => '39516939', 'elType' => 'widget', 'widgetType' => 'loop-carousel', 'elements' => [],
    'settings' => [ 'template_id' => '4121', 'post_query_post_type' => 'post', 'post_query_orderby' => 'date', 'slides_to_show' => '3' ] ];
$loop_saved['recipe']['blueprint']['document'][0]['elements'][] = $loop_widget;
foreach ( [ 'loop-carousel' => '4121', 'loop-grid' => 4121 ] as $widget_type => $template_id ) {
    $example = $loop_saved;
    $example['recipe']['blueprint']['document'][0]['elements'][2]['widgetType'] = $widget_type;
    $expected_settings = $loop_widget['settings'];
    $expected_settings['template_id'] = $template_id;
    $example['recipe']['blueprint']['document'][0]['elements'][2]['settings'] = $expected_settings;
    $loop_draft = good( Layout::from_recipe( $example ), 'An untouched native ' . $widget_type . ' can accompany a mapped heading' );
    check( count( $loop_draft['fields'] ) === 2 && isset( $loop_draft['fields']['/title'], $loop_draft['fields'][$headline] ), 'Loop query and card template are not mapper destinations' );
    $loop_compiled = good( Layout::compile( $loop_draft, fitted( [ '/title' => 'New service', $headline => 'New heading' ] ), 'new-service' ), 'Compile the saved layout without the source page or a query for matching posts' );
    $widgets = $loop_compiled['writer_payload']['elementor_data'][0]['elements'];
    check( 'New heading' === $widgets[0]['settings']['title'] && $widgets[2]['widgetType'] === $widget_type && $widgets[2]['settings'] === $expected_settings && [] === $widgets[2]['elements'], 'Compile fills the heading and retains the live loop and its exact native settings' );
    $example['recipe']['bindings'][] = binding( '/document/0/elements/2/settings/template_id', 'text', 'Change the loop template.' );
    check( is_wp_error( Layout::from_recipe( $example ) ), 'Mapping instructions cannot replace the native loop template ID' );
}
foreach ( [
    'missing' => [ 'post' => null, 'type' => 'loop-item' ],
    'ordinary post' => [ 'post' => (object) array_merge( $public_loop_item, [ 'post_type' => 'post' ] ), 'type' => 'loop-item' ],
    'different Elementor library type' => [ 'post' => (object) $public_loop_item, 'type' => 'section' ],
    'draft' => [ 'post' => (object) array_merge( $public_loop_item, [ 'post_status' => 'draft' ] ), 'type' => 'loop-item' ],
    'password-protected' => [ 'post' => (object) array_merge( $public_loop_item, [ 'post_password' => 'protected' ] ), 'type' => 'loop-item' ],
] as $reason => $loop_fixture ) {
    check( is_wp_error( Layout::from_recipe( $loop_saved ) ), 'Do not sync a ' . $reason . ' loop item' );
}
check( is_wp_error( Layout::compile( $loop_draft, fitted( [ '/title' => 'New service', $headline => 'New heading' ] ), 'blocked-loop' ) ), 'A previously approved loop item cannot be used once password-protected' );
$loop_fixture = [ 'post' => (object) $public_loop_item, 'type' => 'loop-item' ];
foreach ( [
    'unrecognized nested template ID' => [ 'extension' => [ 'template_id' => '4121' ] ],
    'widget-shaped data inside settings' => [ 'extension' => $loop_widget ],
    'dynamic loop template selection' => [ '__dynamic__' => [ 'template_id' => '[elementor-tag name="template-selector"]' ] ],
    'template shortcode' => [ 'caption' => '[elementor-template id="4121"]' ],
    'inline dynamic tag' => [ 'caption' => '[elementor-tag name="post-title"]' ],
] as $reason => $extra ) {
    $bad = $loop_saved;
    $bad['recipe']['blueprint']['document'][0]['elements'][2]['settings'] += $extra;
    check( is_wp_error( Layout::from_recipe( $bad ) ), 'A valid loop ID does not permit ' . $reason . ' in the same widget' );
}
$bad = $loop_saved;
$bad['recipe']['blueprint']['document'][0]['elements'][2]['settings']['template_id'] = '4121other';
check( is_wp_error( Layout::from_recipe( $bad ) ), 'A malformed ID cannot silently resolve to the published loop item' );
$bad = $loop_saved;
$bad['recipe']['blueprint']['document'][0]['elements'][0]['settings']['template_id'] = 4121;
check( is_wp_error( Layout::from_recipe( $bad ) ), 'The published loop item is still prohibited in an unrelated widget' );
unset( $loop_fixture );

$dynamic_elementor = $elementor;
$dynamic_elementor['document'][0]['settings']['__dynamic__'] = [ 'background_image' => '[elementor-tag name="post-featured-image" settings="%7B%7D"]' ];
$dynamic_elementor['meta']['__dynamic__'] = [ 'background_color' => '[elementor-tag name="site-color" settings="%7B%7D"]' ];
$protected_settings = [
    [ 'widget' => 'heading', 'settings' => [ 'title' => 'Native fallback', '__dynamic__' => [ 'title' => '[elementor-tag name="post-title" settings="%7B%7D"]' ] ] ],
    [ 'widget' => 'image', 'settings' => [ 'image' => [ 'id' => 0, 'url' => '', 'alt' => '' ], '__dynamic__' => [ 'image' => '[elementor-tag name="post-featured-image" settings="%7B%7D"]' ] ] ],
    [ 'widget' => 'button', 'settings' => [ 'text' => 'Contact us', 'link' => [ 'url' => '' ], '__dynamic__' => [ 'link' => '[elementor-tag name="internal-url" settings="%7B%22post_id%22%3A9%7D"]' ] ] ],
    [ 'widget' => 'custom-provider', 'settings' => [ 'custom_data' => '', '__dynamic__' => [ 'custom_data' => 'provider-owned-expression' ] ] ],
    [ 'widget' => 'accordion', 'settings' => [ 'tabs' => [ [ '_id' => 'dynamic-row', 'tab_title' => 'Native row fallback', 'tab_content' => '<p>Fixed row body.</p>', '__dynamic__' => [ 'tab_title' => '[elementor-tag name="custom-row-title" settings="%7B%7D"]' ] ] ] ] ],
];
foreach ( $protected_settings as $index => $protected ) {
    $dynamic_elementor['document'][0]['elements'][] = [ 'id' => 'dynamic-' . $index, 'elType' => 'widget', 'widgetType' => $protected['widget'], 'settings' => $protected['settings'], 'elements' => [] ];
}
$dynamic_saved = record( 'elementor', 'post', $dynamic_elementor, [ binding( $headline, 'text', 'Use the mapped section title.' ), binding( $body, 'html', 'Keep all paragraphs.' ) ] );
$dynamic_draft = good( Layout::from_recipe( $dynamic_saved ), 'Protected native tags synchronize without needing the example' );
check_projection( $dynamic_draft, $dynamic_saved );
$dynamic_values = fitted( [ '/title' => 'Destination native title', $headline => 'New mapped heading', $body => '<p>New mapped body.</p>' ] );
$dynamic_compiled = good( Layout::compile( $dynamic_draft, $dynamic_values, 'dynamic-post' ), 'Native tags remain independent of the missing source during compilation' );
$dynamic_output = $dynamic_compiled['writer_payload'];
check( $dynamic_output['elementor_page_settings'] === $dynamic_elementor['meta'] && $dynamic_output['elementor_data'][0]['settings'] === $dynamic_elementor['document'][0]['settings'], 'Native page and container dynamic styles are retained exactly' );
foreach ( $protected_settings as $index => $protected ) {
    $actual = $dynamic_output['elementor_data'][0]['elements'][$index + 2]['settings'];
    $expected = $protected['settings'];
    if ( isset( $expected['tabs'] ) ) { unset( $expected['tabs'][0]['_id'], $actual['tabs'][0]['_id'] ); }
    check( $actual === $expected, 'Protected native expressions and fallbacks survive unchanged for ' . $protected['widget'] );
}
check( count( $dynamic_draft['fields'] ) === 3 && $dynamic_compiled['post_fields']['title'] === 'Destination native title', 'The mapper receives only declared static destinations and the new native title' );
foreach ( [ '/document/0/elements/2/settings/title' => 'text', '/document/0/elements/3/settings/image/url' => 'url', '/document/0/elements/4/settings/link/url' => 'url', '/document/0/elements/6/settings/tabs/0/tab_title' => 'text' ] as $dynamic_target => $format ) {
    $bad = $dynamic_saved; $bad['recipe']['bindings'][] = binding( $dynamic_target, $format, 'Do not override a native binding.' );
    check( is_wp_error( Layout::from_recipe( $bad ) ), 'Dynamic control or nested repeater control remains unavailable to mapped writes: ' . $dynamic_target );
}
$bad = $dynamic_saved; $bad['recipe']['blueprint']['document'][0]['settings']['__dynamic__'] = 'not-a-map';
check( is_wp_error( Layout::from_recipe( $bad ) ), 'Malformed native dynamic settings are rejected' );
$bad = $dynamic_saved; $bad['recipe']['blueprint']['document'][0]['settings']['__dynamic__']['background_image'] = [ 'widgetType' => 'template', 'templateID' => 123 ];
check( is_wp_error( Layout::from_recipe( $bad ) ), 'A dynamic metadata key cannot conceal an external template object' );
$bad = $dynamic_saved; $bad['recipe']['blueprint']['document'][0]['settings']['__dynamic__']['background_image'] = '[elementor-template id="123"]';
check( is_wp_error( Layout::from_recipe( $bad ) ), 'A dynamic metadata key cannot conceal an external template shortcode' );
$bad = $dynamic_saved; $bad['recipe']['blueprint']['__dynamic__'] = [ 'title' => '[elementor-tag name="post-title"]' ];
check( is_wp_error( Layout::from_recipe( $bad ) ), 'Native tag exceptions apply only to native settings metadata' );
$bad = $dynamic_saved; $bad['recipe']['blueprint']['document'][0]['elements'][1]['settings']['editor'] = '[elementor-tag name="post-title"]';
check( is_wp_error( Layout::from_recipe( $bad ) ), 'Inline tag text remains distinct from protected native metadata' );
$bad = $dynamic_saved; $bad['recipe']['blueprint']['document'][0]['settings']['custom_css'] = '.elementor-page-987654321 { color:red; }';
check( is_wp_error( Layout::from_recipe( $bad ) ), 'Preserved native tags do not weaken source-specific CSS checks' );

$core_blueprint = [ 'resource_id' => 'post_type:post', 'type' => 'post_type', 'post_type' => 'post', 'template' => 'templates/full-width.php',
    'document' => [ 'title' => 'Example title', 'excerpt' => 'Example excerpt', 'intro' => '<p>Example introduction</p>', 'sections' => [ [ 'heading' => 'Example section', 'body' => '<p>Example paragraph</p>' ] ], 'faqs' => [] ] ];
$saved = record( 'core', 'post', $core_blueprint, [ binding( '/document/title', 'text', 'Use the document title with the requested product name.' ), binding( '/document/intro', 'html', 'Use the introduction first.' ), binding( '/document/sections/0/heading', 'text', 'Use the next section heading.' ), binding( '/document/sections/0/body', 'html', 'Keep all remaining paragraphs in order.' ) ] );
$draft = good( Layout::from_recipe( $saved ), 'Core native content projects without an example' );
check_projection( $draft, $saved );
check( count( array_intersect( [ '/title', '/document/title' ], array_keys( $draft['fields'] ) ) ) === 1 && isset( $draft['fields']['/document/title'] ), 'Core exposes only the configured native title, without a competing synthetic title' );
check( ! isset( $draft['target_descriptors']['/title'] ) && $draft['fields']['/document/title']['instructions'] === 'Use the document title with the requested product name.', 'Core title instructions stay attached to the sole authoritative title destination' );
check( ! isset( $draft['fields']['/excerpt'] ) && ! isset( $draft['fields']['/document/excerpt'] ), 'The adapter does not expose a synthetic editable excerpt when none is configured' );
$values = fitted( [ '/document/title' => 'Requested product article title', '/document/intro' => '<p>Introduction.</p>', '/document/sections/0/heading' => 'Article section', '/document/sections/0/body' => '<p>First body paragraph.</p><p>Second body paragraph.</p>' ] );
$compiled = good( Layout::compile( $draft, $values, 'core-post' ), 'Core native content compiles from the stored recipe' );
check( $compiled['builder'] === 'core' && $compiled['post_type'] === 'post', 'Core compiler preserves the standard Posts destination' );
check( $compiled['writer_payload']['content'] === '<p>Introduction.</p><h2>Article section</h2><p>First body paragraph.</p><p>Second body paragraph.</p>', 'Core output keeps fitted content in the saved native field order' );
check( $compiled['writer_payload']['template'] === 'templates/full-width.php', 'Core native template survives compilation' );
check( $compiled['post_fields']['title'] === 'Requested product article title', 'Core configured title becomes the authoritative native post value used by the writer' );
check( $compiled['post_fields']['excerpt'] === 'Example excerpt', 'An unmapped static Core excerpt remains preserved from the saved blueprint' );
$extra_title = $values; $extra_title[Writing::field_id( '/title' )] = 'Unconfigured competing title';
check( is_wp_error( Layout::compile( $draft, $extra_title, 'core-extra-title' ) ), 'An unconfigured synthetic title cannot silently override the instructed native title' );
$wrong = $saved; $wrong['recipe']['blueprint']['post_type'] = 'page';
check( is_wp_error( Layout::from_recipe( $wrong ) ), 'A core blueprint for another post type is rejected before synchronization' );

// A theme can render only ACF, leaving both native body and its list of mapped fields empty.
$php_template = $core_blueprint; $php_template['post_type'] = 'page'; $php_template['resource_id'] = 'post_type:page';
$php_template['document'] = [ 'title' => '', 'sections' => [ [ 'body' => '' ] ], 'faqs' => [] ];
$only = record( 'core', 'page', $php_template, [] );
$acf_path = '/@nova/custom-fields/acf/summary'; $meta_path = '/@nova/custom-fields/meta/credit_line';
$only['recipe']['posting_fields'] = [
    $acf_path => [ 'name' => 'summary', 'key' => 'field_summary', 'provider' => 'acf', 'type' => 'wysiwyg', 'label' => 'Summary', 'instructions' => 'Use the full introduction.' ],
    $meta_path => [ 'name' => 'credit_line', 'key' => 'credit_line', 'provider' => 'meta', 'type' => 'string', 'label' => 'Author credit' ],
];
$destination = good( Layout::from_recipe( $only ), 'Core PHP layout with only companion bindings exports' );
$wire = good( Writing::template_input( $destination ), 'The posting-service wire format accepts both explicit native registrations' );
$wire_fields = array_column( $wire['definition']['fields'], null, 'id' );
check( count( $wire_fields ) === 3 && $wire_fields[Writing::field_id( $acf_path )]['value_type'] === 'rich_text' && $wire_fields[Writing::field_id( $meta_path )]['value_type'] === 'text', 'Only the native title and the selected editorial destinations are exposed.' );
check( ! str_contains( wp_json_encode( $wire ), 'field_summary' ) && str_contains( wp_json_encode( $wire ), 'Use the full introduction.' ), 'Only instructions and opaque IDs go to posting service. Native ACF keys stay in WordPress.' );
$translated = good( Layout::compile( $destination, fitted( [ '/title' => 'Dienst', $acf_path => '<p>Inleiding.</p>' ] ), 'custom-only' ), 'A custom-only native page compiles without access to the example' );
check( $translated['writer_payload']['template'] === 'templates/full-width.php' && $translated['post_fields']['title'] === 'Dienst' && $translated['custom_fields'] === [ 'summary' => [ 'provider' => 'acf', 'key' => 'field_summary', 'type' => 'wysiwyg', 'value' => '<p>Inleiding.</p>' ] ], 'The PHP template and fitted ACF value survive while absent optional post meta is omitted.' );
$legacy = $only; unset( $legacy['recipe']['posting_fields'] );
$legacy['recipe']['custom_fields'] = [ 'mode' => 'static', 'builder' => 'acf', 'bindings' => [ [ 'target' => '/document/summary', 'instructions' => 'Use the full introduction.' ] ], 'regions' => [], 'blueprint' => [ 'schema' => [ 'summary' => $only['recipe']['posting_fields'][$acf_path] ], 'document' => [ 'summary' => 'Private example copy', 'unselected' => 'Also private' ] ] ];
$legacy_draft = good( Layout::from_recipe( $legacy ), 'An existing explicitly bound legacy scalar companion can be synchronized' );
check( isset( $legacy_draft['fields'][$acf_path] ) && ! str_contains( wp_json_encode( $legacy_draft ), 'Private example copy' ) && ! str_contains( wp_json_encode( $legacy_draft ), 'Also private' ), 'Existing scalar bindings export without any of the example post meta.' );
$tampered = $destination; $tampered['posting_fields'][$acf_path]['key'] = 'field_other';
check( is_wp_error( Layout::compile( $tampered, fitted( [ '/title' => 'Dienst' ] ), 'changed-key' ) ), 'Changing a sealed field identity invalidates the approved native snapshot.' );

$block_markup = '<!-- wp:group {"layout":{"type":"constrained","contentSize":"1180px"},"className":"retained-layout"} --><div class="wp-block-group retained-layout"><!-- wp:heading {"level":2,"className":"retained-heading"} --><h2 class="wp-block-heading retained-heading">Example heading</h2><!-- /wp:heading --><!-- wp:html --><p>Example paragraph</p><!-- /wp:html --></div><!-- /wp:group -->';
$gutenberg = [ 'document' => parse_blocks( $block_markup ), 'template' => 'templates/full-width.php', 'meta' => [ 'retained_design' => [ 'width' => 'wide', 'spacing' => 24 ] ] ];
$headline = '/document/0/innerBlocks/0/innerHTML'; $body = '/document/0/innerBlocks/1/innerHTML';
$saved = record( 'gutenberg', 'case_study', $gutenberg, [ binding( $headline, 'text', 'Place the section heading here exactly once.' ), binding( $body, 'html', 'Keep the body paragraphs in source order.' ) ] );
$draft = good( Layout::from_recipe( $saved ), 'Gutenberg nested blocks project from the saved native document' );
check_projection( $draft, $saved );
$compiled = good( Layout::compile( $draft, fitted( [ '/title' => 'Block article', $headline => 'New block heading', $body => '<p>New first paragraph.</p><p>New second paragraph.</p>' ] ), 'gutenberg-cpt' ), 'Gutenberg compiles with the real WordPress serializer' );
check( $compiled['builder'] === 'gutenberg' && $compiled['post_type'] === 'case_study', 'Gutenberg retains its arbitrary native CPT destination' );
$blocks = parse_blocks( $compiled['writer_payload']['native_document'] );
check( $blocks[0]['attrs'] === $gutenberg['document'][0]['attrs'] && $blocks[0]['innerBlocks'][0]['attrs'] === $gutenberg['document'][0]['innerBlocks'][0]['attrs'], 'Nested Gutenberg layout and heading attributes survive serialization' );
check( $blocks[0]['innerBlocks'][0]['innerHTML'] === '<h2 class="wp-block-heading retained-heading">New block heading</h2>', 'Gutenberg binds a plain heading while retaining its native heading wrapper' );
check( $blocks[0]['innerBlocks'][1]['innerHTML'] === '<p>New first paragraph.</p><p>New second paragraph.</p>', 'Gutenberg rich content replaces the target body in source order' );
check( $compiled['writer_payload']['meta']['retained_design'] === $gutenberg['meta']['retained_design'] && $compiled['writer_payload']['meta']['_wp_page_template'] === 'templates/full-width.php', 'Gutenberg retains native design metadata and page template' );
// SEO is explicit metadata, independent of the builder and never copied from the example.
$seo_record = record( 'core', 'page', $php_template, [] );
foreach ( [ 'yoast', 'rank_math', 'seopress', 'tsf', 'smartcrawl', 'slim_seo', 'aioseo', 'squirrly' ] as $provider ) {
    foreach ( [ 'title', 'description' ] as $field ) {
        $path = Nova_WordPress_Layout_SEO::path( $provider, $field );
        $seo_record['recipe']['seo_fields'][$path] = [ 'provider' => $provider, 'field' => $field, 'required' => false ];
    }
}
$seo_draft = good( Layout::from_recipe( $seo_record ), 'Every supported SEO provider projects without accessing a live source' );
$seo_wire = good( Writing::template_input( $seo_draft ), 'SEO destinations use the existing V3 contract' );
$hints = array_column( $seo_wire['mapping']['source_hints'], 'source_fields', 'field_id' );
foreach ( $seo_record['recipe']['seo_fields'] as $path => $entry ) {
    check( ( $hints[Writing::field_id( $path )] ?? null ) === [ 'title' === $entry['field'] ? 'title' : 'meta_description' ], 'SEO source hints distinguish NOVA title from the visible heading' );
}
$seo_path = '/@nova/seo/yoast/title'; $desc_path = '/@nova/seo/yoast/description';
$seo_compiled = good( Layout::compile( $seo_draft, fitted( [ '/title' => 'Visible heading', $seo_path => 'Search title', $desc_path => '' ] ), 'seo-create' ), 'SEO values compile independently of the native heading' );
check( $seo_compiled['post_fields']['title'] === 'Visible heading' && $seo_compiled['custom_fields'] === [ $seo_path => [ 'provider'=>'seo','key'=>'yoast','type'=>'title','value'=>'Search title' ] ], 'Only a supplied nonempty SEO value is written; missing and optional empty metadata is preserved' );
$no_seo = good( Layout::compile( $seo_draft, fitted( [ '/title'=>'Heading' ] ), 'seo-absent' ), 'Optional absent SEO does not block content' );
check( ! isset( $no_seo['custom_fields'] ), 'No SEO mutation is compiled without supplied mapped metadata' );
$bad_seo = $seo_record; $bad_seo['recipe']['seo_fields'][$seo_path]['field'] = 'canonical';
check( is_wp_error( Layout::from_recipe( $bad_seo ) ), 'Unselected SEO controls cannot be smuggled into native writes' );
$changed_seo = $seo_draft; $changed_seo['seo_fields'][$seo_path]['provider'] = 'rank_math';
check( is_wp_error( Layout::compile( $changed_seo, fitted( [ '/title'=>'Heading' ] ), 'changed-seo' ) ), 'Frozen SEO destination identity cannot be changed by a delivery' );
check( $live_io_attempts === 0, 'No source or query-result reads, writes, or HTTP requests occurred; the only lookups allowed were for the declared local Loop Item' );
echo 'PASS ' . $checks . " generic stored-recipe layout assertions\n";
