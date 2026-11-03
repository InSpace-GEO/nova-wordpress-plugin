<?php
/** Isolated round trip of modern and classic Cornerstone element structures. */
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-engine.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-cornerstone-document.php';

function wp_get_attachment_image_url( $id, $size ) {
    return 12 === $id && 'large' === $size ? 'https://example.test/large.jpg' : false;
}
function shortcode_exists( $tag ) { return 'gallery' === $tag; }
function check( $condition, $message ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function refuses( callable $fn, string $message ) {
    try { $fn(); } catch ( InvalidArgumentException | JsonException $expected ) { return; }
    throw new RuntimeException( $message );
}

$source = <<<'JSON'
[{"_type":"section","section_bg_color":"#112233","_p_data":{},"_p_local":{"nested":[],"responsive":{"0":"large","1":"small"}},"_modules":[
  {"_type":"headline","id":"hero-title","text_content":"Example headline","text_tag":"h2"},
  {"_type":"classic:row","_column_layout":"1/1","_modules":[{"_type":"classic:column","_modules":[{"_type":"classic:text","content":"<p>Original body.</p>","padding":["8px","12px"]}]}]},
  {"_type":"image","image_src":"12:large","image_alt":"Original photo"},
  {"_type":"button","anchor_text_primary_content":"Read more","anchor_href":"/initial/"},
  {"_type":"text","text_content":"{{dc:post:title}}"},
  {"_type":"accordion-item-elements","looper_consumer":true,"accordion_item_header_content":"Repeated header","_modules":[{"_type":"text","text_content":"Looped text"}]},
  {"_type":"bespoke-element","text_content":"Unregistered setting","arbitrary":{"by_size":[],"custom":{}},"_modules":[]}
]}]
JSON;
$document = Nova_Bridge_Suite_Cornerstone_Document::class;
$blueprint = [ 'document' => $document::capture( $source ) ];
$stored = json_decode( json_encode( $blueprint, JSON_THROW_ON_ERROR ), true, 64, JSON_THROW_ON_ERROR );
$projected = $document::source( $stored['document'] );
check( json_decode( $projected ) == json_decode( $source ), 'Every original element and setting survives storage unchanged.' );
check( str_contains( $projected, '"_p_data":{}' ) && str_contains( $projected, '"responsive":{"0":"large","1":"small"}' ), 'Cornerstone settings objects remain objects after authoring and delivery.' );

$fields = $document::inventory( $stored )['fields'];
$by_sample = array_column( $fields, null, 'value' );
check( 'hero-title' === $by_sample['Example headline']['locator']['anchor'], 'Named native anchors identify an actual element.' );
check( 'html' === $by_sample['<p>Original body.</p>']['format'] && str_contains( $by_sample['<p>Original body.</p>']['context'], 'Classic:column' ), 'Nested classic text is editable as rich text.' );
check( ! isset( $by_sample['Unregistered setting'] ) && ! isset( $by_sample['#112233'] ), 'Unknown controls and styling never become editorial fields.' );
check( ! $by_sample['{{dc:post:title}}']['writable'] && ! $by_sample['Looped text']['writable'], 'A dynamic expression and the children of a looper remain protected.' );
$stored = $document::bind( $stored, $by_sample['Example headline'], 'A & B [archived] <em>literal</em>' );
$stored = $document::bind( $stored, $by_sample['<p>Original body.</p>'], '<p>New <strong>body</strong>.</p>' );
$stored = $document::bind( $stored, $by_sample['https://example.test/large.jpg'], 'https://example.test/new.jpg' );
$stored = $document::bind( $stored, $by_sample['/initial/'], '/new/' );
$expected = json_decode( $source );
$expected[0]->_modules[0]->text_content = 'A &amp; B [archived] &lt;em&gt;literal&lt;/em&gt;';
$expected[0]->_modules[1]->_modules[0]->_modules[0]->content = '<p>New <strong>body</strong>.</p>';
$expected[0]->_modules[2]->image_src = 'https://example.test/new.jpg';
$expected[0]->_modules[3]->anchor_href = '/new/';
check( json_decode( $document::source( $stored['document'] ) ) == $expected, 'Fitting heading, body, image and link changes only the selected controls.' );
refuses( fn() => $document::bind( $stored, $by_sample['{{dc:post:title}}'], 'Replace' ), 'Dynamic bindings must reject writes.' );
refuses( fn() => $document::bind( $stored, $by_sample['Example headline'], '{{dc:user:meta key="secret"}}' ), 'Delivery text must not introduce executable builder expressions.' );
refuses( fn() => $document::bind( $stored, $by_sample['<p>Original body.</p>'], '[gallery ids="123"]' ), 'Delivery HTML must not execute WordPress shortcodes.' );
refuses( fn() => $document::bind( $stored, $by_sample['https://example.test/large.jpg'], '14:thumbnail' ), 'A native attachment reference is not an external image URL.' );
refuses( fn() => $document::capture( '[{"_type":"section","_modules":{}}]' ), 'Malformed children must not cause the layout to be dropped.' );
refuses( fn() => $document::capture( '[]' ), 'An empty example cannot be captured as a usable layout.' );
echo "Cornerstone document round trip, mapping and protected controls passed.\n";
