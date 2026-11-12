<?php
/** Actual adapter/compiler with memory-only WordPress library records. */
require __DIR__ . '/recipe-engine-unit.php';
use Nova_Bridge_Suite_Elementor_Reusable as Reusable;
$before_checks = $checks; $before_writes = $writes;
function library_fixture( int $id, string $kind, array $nodes ): void {
    $GLOBALS['posts'][$id] = (object) [ 'ID' => $id, 'post_type' => 'elementor_library', 'post_status' => 'publish', 'post_title' => 'Shared component', 'post_password' => '' ];
    $GLOBALS['metadata'][$id] = [ '_elementor_template_type' => $kind, '_elementor_data' => json_encode( $nodes ) ];
}
function reusable_rejected( callable $work, string $fragment ): void {
    try { $work(); } catch ( InvalidArgumentException $e ) { check( str_contains( $e->getMessage(), $fragment ), $e->getMessage() ); return; }
    throw new RuntimeException( 'Expected rejection: ' . $fragment );
}
library_fixture( 701, 'widget', [ widget( 'button1', 'button', [ 'text' => 'Contact us', 'link' => [ 'url' => '/contact/' ], 'background_color' => '#123456', 'typography_font_size_mobile' => [ 'size' => 18, 'unit' => 'px' ] ] ) ] );
$global = widget( 'shared1', 'global', [ 'text' => 'Cached old text' ] ) + [ 'templateID' => 701 ];
$second = $global; $second['id'] = 'shared2';
$native = [ 'document' => [ $global, $second, widget( 'title1', 'heading', [ 'title' => 'Protected heading' ] ) ], 'meta' => [], 'template' => '' ];
$design = good( $adapter->describe( $native ), 'Describe linked globals' );
check( count( $design['reusable_blocks'] ) === 2 && str_contains( $design['reusable_blocks'][0]['label'], 'Global widget' ), 'Each global occurrence is explicitly identified' );
check( ! array_filter( $design['fields'], fn( $f ) => $f['writable'] && str_starts_with( $f['target'], '/document/0/' ) ), 'Global content is protected' );
$provider = Reusable::native_nodes( [ $global + [ 'originalWidgetType' => 'button' ] ] );
check( $provider[0]['settings']['text'] === 'Contact us' && ! isset( $provider[0]['originalWidgetType'] ), 'Shared payload matches Pro library resolution and strips its editor-only hint' );
$draft_global = $global + [ 'draft' => true ]; $draft_global['settings']['text'] = 'Local draft CTA';
$provider = Reusable::native_nodes( [ $draft_global ] );
check( $provider[0]['settings']['text'] === 'Contact us' && ! isset( $provider[0]['draft'] ), 'Keep shared explicitly uses the published library rather than an unsaved editor snapshot' );
$draft_copy = good( $adapter->expand_block( [ 'document' => [ $draft_global ] ], '/document/0' ), 'Capture global draft changes' );
check( $draft_copy['blueprint']['document'][0]['settings']['text'] === 'Local draft CTA', 'Explicit independent copy retains the draft values shown in the example' );
$legacy = $native; $legacy['document'][0]['widgetType'] = 'button';
bad( $adapter->validate_binding( $legacy, binding( '/document/0/settings/text', '/title', 'text' ) ), 'Underlying cached widget type cannot bypass shared protection' );
$expanded = good( $adapter->expand_block( $native, '/document/0' ), 'Copy first occurrence' );
$copy = $expanded['blueprint'];
check( $copy['document'][1] === $second && $copy['document'][2] === $native['document'][2], 'Unselected nodes and binding paths stay exact' );
check( $copy['document'][0]['settings']['text'] === 'Contact us' && $copy['document'][0]['settings']['background_color'] === '#123456', 'Snapshot uses actual shared content and style, not stale instance settings' );
check( $expanded['reusable_blocks'][0]['mode'] === 'captured' && str_contains( $expanded['reusable_blocks'][0]['label'], 'Global widget' ), 'Copied global remains clearly identified' );
check( Reusable::linked_blueprint( $copy ) === $native, 'Provenance restores exact reference without reading source' );
good( $adapter->validate_binding( $copy, binding( '/document/0/settings/text', '/title', 'text' ) ), 'Copied fields are writable' );
$both = good( $adapter->expand_block( $copy, '/document/1' ), 'Copy repeated occurrence' )['blueprint'];
check( $both['document'][0]['id'] !== $both['document'][1]['id'], 'Repeated global copies have separate native identities' );
unset( $posts[701], $metadata[701] );
Reusable::validation_blueprint( $both );
$compiled = good( Nova_Bridge_Suite_Recipe_Engine::compile( recipe( $both, [ binding( '/document/0/settings/text', '/first', 'text' ), binding( '/document/1/settings/text', '/second', 'text' ) ], [], 'static' ), [ 'first' => 'First CTA', 'second' => 'Second CTA' ], 'independent-test' ), 'Compile after source deletion' );
$document = $compiled['writer_payload']['elementor_data'];
check( $document[0]['settings']['text'] === 'First CTA' && $document[1]['settings']['text'] === 'Second CTA', 'Copies accept independent content' );
check( ! str_contains( json_encode( $document ), Reusable::COPY ) && ! str_contains( json_encode( $document ), 'templateID' ), 'Native payload contains no provenance or deleted shared dependency' );
reusable_rejected( fn()=>Reusable::validation_blueprint( $native ), 'missing' );
library_fixture( 702, 'container', [ container( 'box', [ widget( 'body1', 'text-editor', [ 'editor' => '<p>Nested source body</p>' ] ) ], [ 'background_color' => '#abcdef' ] ) ] );
$metadata[702]['_elementor_page_settings'] = [ 'custom_css' => '.elementor-702 .elementor-element-body1 {letter-spacing:2px;}' ];
$template = widget( 'temp1', 'template', [ 'template_id' => '702', '_margin_mobile' => [ 'unit' => 'px', 'top' => 12 ], '_background_color' => '#abcdef', '__globals__' => [ '_background_color' => 'globals/colors?id=primary' ], 'custom_css' => 'selector {opacity:1;}' ] );
library_fixture( 703, 'container', [ container( 'outer', [ $template ] ) ] );
$shortcode = widget( 'short1', 'shortcode', [ 'shortcode' => '[elementor-template id="703"]' ] );
$nested = good( $adapter->expand_block( [ 'document' => [ $shortcode ] ], '/document/0' ), 'Nested template shortcode copy' )['blueprint'];
unset( $posts[702], $posts[703], $metadata[702], $metadata[703] );
Reusable::validation_blueprint( $nested );
check( ! str_contains( json_encode( Reusable::native_nodes( $nested['document'] ) ), 'template_id' ), 'Nested copy is source independent too' );
check( str_contains( json_encode( $nested ), 'Nested source body' ) && str_contains( json_encode( $nested ), 'margin_mobile' ), 'Nested content and responsive wrapper styling retained' );
$native_copy = Reusable::native_nodes( $nested['document'] );
check( str_contains( json_encode( $native_copy ), 'letter-spacing:2px' ) && ! str_contains( json_encode( $native_copy ), '.elementor-702' ) && ! str_contains( json_encode( $native_copy ), '.elementor-element-body1' ), 'Template document CSS is retained and rebound to the copied elements' );
check( ! str_contains( json_encode( $native_copy ), '_background_color' ) && str_contains( json_encode( $native_copy, JSON_UNESCAPED_SLASHES ), 'globals/colors?id=primary' ), 'Wrapper styling and its global control map use the corresponding native container controls' );
library_fixture( 704, 'container', [ widget( 'cycle', 'template', [ 'template_id' => '704' ] ) ] );
reusable_rejected( fn()=>Reusable::validation_blueprint( [ 'document' => [ widget( 'cycle1', 'template', [ 'template_id' => '704' ] ) ] ] ), 'circular' );
library_fixture( 705, 'container', [ container( 'anchorbox', [ widget( 'anchorbody', 'text-editor', [ 'editor' => '<p id="details">Detail content</p><a href="#contact">Contact</a>' ] ) ], [ '_element_id' => 'contact', 'custom_css' => '#contact {padding:4px;}' ] ) ] );
$anchor_example = [ 'document' => [ widget( 'incoming', 'button', [ 'text' => 'Jump to contact', 'link' => [ 'url' => '#contact' ] ] ), widget( 'incoming2', 'text-editor', [ 'editor' => '<p><a href="#details">Read details</a></p>' ] ), widget( 'anchortemplate', 'template', [ 'template_id' => '705' ] ) ] ];
$anchor_copy = good( $adapter->expand_block( $anchor_example, '/document/2' ), 'Copy anchored template' )['blueprint'];
$anchor_payload = good( Nova_Bridge_Suite_Recipe_Engine::compile( recipe( $anchor_copy, [], [], 'static' ), [], 'anchors' ), 'Compile anchored copy' )['writer_payload']['elementor_data'];
check( $anchor_payload[2]['elements'][0]['settings']['_element_id'] === 'contact' && $anchor_payload[0]['settings']['link']['url'] === '#contact', 'Incoming native links retain their copied anchor target' );
check( str_contains( $anchor_payload[2]['elements'][0]['elements'][0]['settings']['editor'], 'id="details"' ) && str_contains( $anchor_payload[1]['settings']['editor'], 'href="#details"' ), 'Incoming rich-text links retain copied inline HTML IDs too' );
check( $anchor_payload[2]['elements'][0]['settings']['custom_css'] === '#contact {padding:4px;}', 'Anchor-based styles retain their targets' );
check( Reusable::reference( widget( 'arbitrary', 'shortcode', [ 'shortcode' => '[unknown id="702"]' ] ) ) === null, 'Unknown shortcodes are not treated as safe library references' );
check( $before_writes === $writes, 'Copy, inspect and compile perform no persistence' );
echo 'PASS ' . ( $checks - $before_checks ) . " reusable Elementor checks.\n";
