<?php
/** WPBakery element definitions, image import, element removal, preview markers and known-ID publishing. */
require __DIR__ . '/recipe-builders-unit.php';
$before = $assertions;

// WPBakery registers each element and its parameters through vc_map(); themes use the same registry.
final class WPBMap {
    public static $map = [];
    public static function getShortCode( $tag ) { return self::$map[ $tag ] ?? null; }
}
WPBMap::$map = [
    'nova_test_single_image' => [ 'name' => 'Single Image', 'params' => [
        [ 'type' => 'attach_image', 'param_name' => 'image', 'heading' => 'Image' ],
        [ 'type' => 'textfield', 'param_name' => 'el_class', 'heading' => 'Extra class name' ],
        [ 'type' => 'css_editor', 'param_name' => 'css', 'heading' => 'CSS box', 'group' => 'Design Options' ],
    ] ],
    'nova_theme_image' => [ 'name' => 'Image With Animation', 'params' => [
        [ 'type' => 'attach_image', 'param_name' => 'image_url', 'heading' => 'Image' ],
        [ 'type' => 'textfield', 'param_name' => 'alt', 'heading' => 'Alt Text' ],
        [ 'type' => 'textfield', 'param_name' => 'max_width', 'heading' => 'Image Max Width' ],
        [ 'type' => 'dropdown', 'param_name' => 'animation', 'heading' => 'Animation' ],
    ] ],
    'nova_theme_box' => [ 'name' => 'Fancy Box', 'params' => [
        [ 'type' => 'attach_image', 'param_name' => 'image_url', 'heading' => 'Background Image' ],
        [ 'type' => 'textarea_html', 'param_name' => 'content', 'heading' => 'Box Content' ],
        [ 'type' => 'textfield', 'param_name' => 'link_text', 'heading' => 'Link Text' ],
        [ 'type' => 'vc_link', 'param_name' => 'link', 'heading' => 'Link' ],
        [ 'type' => 'textfield', 'param_name' => 'min_height', 'heading' => 'Box Min Height', 'admin_label' => true ],
        [ 'type' => 'textfield', 'param_name' => 'title', 'heading' => 'Hover title', 'group' => 'Hover Style' ],
    ] ],
    'nova_theme_heading' => [ 'name' => 'Split Line Heading', 'params' => [ [ 'type' => 'textarea_html', 'param_name' => 'content', 'heading' => 'Text Content' ] ] ],
    'nova_theme_toggles' => [ 'name' => 'Toggles', 'is_container' => true, 'params' => [] ],
    'nova_theme_toggle' => [ 'name' => 'Toggle', 'params' => [ [ 'type' => 'textfield', 'param_name' => 'title', 'heading' => 'Title', 'admin_label' => true ], [ 'type' => 'textarea_html', 'param_name' => 'content', 'heading' => 'Content' ] ] ],
    'nova_test_raw' => [ 'name' => 'Raw HTML', 'params' => [ [ 'type' => 'textarea_raw_html', 'param_name' => 'content', 'heading' => 'Raw HTML' ] ] ],
];
$GLOBALS['attachments'] = [ 42 => 'https://fixture.example/wp-content/uploads/photo.jpg', 43 => 'https://fixture.example/wp-content/uploads/animated.jpg', 44 => 'https://fixture.example/wp-content/uploads/box.jpg', 45 => 'https://fixture.example/wp-content/uploads/fallback.jpg' ];
function wp_attachment_is_image( $id ) { return isset( $GLOBALS['attachments'][ (int) $id ] ); }
function wp_get_attachment_url( $id ) { return $GLOBALS['attachments'][ (int) $id ] ?? false; }
function post_type_supports( $type, $feature ) { return in_array( $feature, $GLOBALS['supports'][ $type ] ?? [ 'title', 'editor', 'excerpt', 'thumbnail' ], true ); }
function metadata_exists( $type, $id, $key ) { return array_key_exists( $key, $GLOBALS['recipe_meta'][ $id ] ?? [] ); }
function clean_post_cache( $id ) {}
function wp_update_post( $data, $error = false ) { $data = wp_unslash( $data ); $id = (int) $data['ID']; foreach ( $data as $key => $value ) { $GLOBALS['recipe_posts'][ $id ]->$key = $value; } $GLOBALS['wpb_updates'] = ( $GLOBALS['wpb_updates'] ?? 0 ) + 1; return $id; }
if ( ! defined( 'WPB_VC_VERSION' ) ) { define( 'WPB_VC_VERSION', '8.7.2' ); }

$content = '[vc_row][vc_column]' . "\n"
    . '[nova_test_single_image image="42" el_class="hero" css=".vc_custom_1{margin:0}"]' . "\n"
    . '[nova_theme_image image_url="43" alt="Example photo" max_width="100%" animation="fade-in"]' . "\n"
    . '[nova_theme_box image_url="44" link_text="Read more" link="url:%2Fplan%2F|title:Plan" min_height="600" title="Hover"]<h3>Personal plan</h3><p>A plan.</p>[/nova_theme_box]' . "\n"
    . '[nova_theme_heading]<h1 style="color:#25334e">Example brand</h1>[/nova_theme_heading]' . "\n"
    . '[nova_theme_toggles][nova_theme_toggle title="Question?"]<p>Answer.</p>[/nova_theme_toggle][/nova_theme_toggles]' . "\n"
    . '[nova_test_raw]JTNDZGl2JTNFJTNDJTJGZGl2JTNFJTNDZGl2JTNFZXh0cmElM0MlMkZkaXYlM0U=[/nova_test_raw]' . "\n"
    . '[nova_unknown_box image="45"]<p>Fallback body.</p>[/nova_unknown_box]' . "\n"
    . '[/vc_column][/vc_row]';
$GLOBALS['recipe_posts'][900] = (object) [ 'ID' => 900, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home', 'post_content' => $content, 'post_excerpt' => '' ];
$GLOBALS['recipe_meta'][900] = [ '_wpb_post_custom_css' => '.x{color:red}' ];
$adapter = Nova_Bridge_Suite_Recipe_Builders::adapter( 'wpbakery' );
$capture = recipe_ok( $adapter->capture( 900 ), 'A theme-extended WPBakery page is captured' );
$fields = array_column( $capture['fields'], null, 'target' );
$base = '/document/0/children/0/children/';
$image = $fields[ $base . '0/attributes/image' ] ?? null;
recipe_assert( $image && 'image_url' === $image['kind'] && 'url' === $image['format'] && $image['writable'] && 'wpbakery' === ( $image['native_media']['kind'] ?? '' ) && $image['native_media']['id_target'] === $base . '0/attributes/image', 'A WPBakery attachment image is an importable image field' );
recipe_assert( 'https://fixture.example/wp-content/uploads/photo.jpg' === $image['value'] && 'Single Image · Image' === $image['label'], 'The image field shows its attachment URL and the element name from WPBakery' );
recipe_assert( ! isset( $fields[ $base . '0/attributes/el_class' ] ) && ! isset( $fields[ $base . '0/attributes/css' ] ), 'Design and identity parameters stay protected' );
recipe_assert( isset( $fields[ $base . '1/attributes/image_url' ]['native_media'] ) && 'image_alt' === ( $fields[ $base . '1/attributes/alt' ]['kind'] ?? '' ) && ! isset( $fields[ $base . '1/attributes/max_width' ] ) && ! isset( $fields[ $base . '1/attributes/animation' ] ), 'A theme image element exposes its image and alt text only' );
recipe_assert( isset( $fields[ $base . '2/attributes/image_url' ]['native_media'] ) && 'html' === ( $fields[ $base . '2/text' ]['format'] ?? '' ) && isset( $fields[ $base . '2/attributes/link_text' ] ), 'A theme content box exposes its image, rich content and link text' );
recipe_assert( '/plan/' === ( $fields[ $base . '2/attributes/link' ]['value'] ?? '' ) && ! empty( $fields[ $base . '2/attributes/link' ]['packed_link'] ), 'Any WPBakery link parameter exposes its URL' );
recipe_assert( ! isset( $fields[ $base . '2/attributes/min_height' ] ) && ! isset( $fields[ $base . '2/attributes/title' ] ), 'Admin-labelled sizes and design-group texts stay protected' );
recipe_assert( 'heading' === ( $fields[ $base . '3/text' ]['kind'] ?? '' ) && [ 'text', 'html' ] === ( $fields[ $base . '3/text' ]['allowed_formats'] ?? null ), 'Heading-only content is a heading field' );
recipe_assert( isset( $fields[ $base . '4/children/0/attributes/title' ], $fields[ $base . '4/children/0/text' ] ), 'Fields inside a theme container element are found' );
recipe_assert( ! isset( $fields[ $base . '5/text' ] ), 'Encoded raw HTML is never offered as editable content' );
recipe_assert( isset( $fields[ $base . '6/attributes/image' ]['native_media'], $fields[ $base . '6/text' ] ), 'Elements without a definition still expose attachment images and body text' );

// Binding keeps the heading tag and its styling; links keep the packed vc_link format.
$native = recipe_ok( $adapter->bind( $capture['blueprint'], [ 'target' => $base . '3/text', 'format' => 'text' ], 'New title & more' ), 'A heading binds as plain text' );
recipe_assert( '<h1 style="color:#25334e">New title &amp; more</h1>' === Nova_Bridge_Suite_Recipe_Engine::get( $native, $base . '3/text' ), 'The heading wrapper is preserved' );
$native = recipe_ok( $adapter->bind( $native, [ 'target' => $base . '2/attributes/link', 'format' => 'url' ], 'https://fixture.example/new/' ), 'A packed link binds' );
recipe_assert( 'url:https%3A%2F%2Ffixture.example%2Fnew%2F|title:Plan' === Nova_Bridge_Suite_Recipe_Engine::get( $native, $base . '2/attributes/link' ), 'The link title is kept' );

// Image import is accepted only for exposed WPBakery attachment parameters.
$spec = Nova_Bridge_Suite_Recipe_Native_Media::spec( $capture['blueprint'], [ 'target' => $base . '0/attributes/image', 'format' => 'url', 'native_media' => true ], $adapter );
recipe_assert( [ 'kind' => 'wpbakery', 'id_target' => $base . '0/attributes/image' ] === $spec, 'The authoring contract derives the WPBakery image target' );
try { Nova_Bridge_Suite_Recipe_Native_Media::spec( $capture['blueprint'], [ 'target' => $base . '0/attributes/el_class', 'format' => 'url', 'native_media' => true ], $adapter ); $thrown = false; } catch ( InvalidArgumentException $error ) { $thrown = true; }
recipe_assert( $thrown, 'A design parameter cannot receive an imported image' );

// Removing elements keeps the rest of the source byte-for-byte.
$recipe = [ 'format' => 'nova-layout-recipe/v1', 'builder' => 'wpbakery', 'mode' => 'static', 'blueprint' => $capture['blueprint'], 'bindings' => [ [ 'target' => $base . '3/text', 'source' => '/title', 'format' => 'text', 'required' => true ] ], 'regions' => [], 'omissions' => [ [ 'target' => $base . '1', 'label' => 'Image With Animation' ], [ 'target' => $base . '6' ] ] ];
recipe_ok( Nova_Bridge_Suite_Recipe_Engine::validate( $recipe ), 'Removed elements validate' );
$compiled = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, [ 'title' => 'New title' ], 'omit-check' ), 'A layout with removed elements compiles' );
$expected = str_replace( [ '[nova_theme_image image_url="43" alt="Example photo" max_width="100%" animation="fade-in"]' . "\n", '[nova_unknown_box image="45"]<p>Fallback body.</p>[/nova_unknown_box]' . "\n" ], '', $content );
$expected = str_replace( '<h1 style="color:#25334e">Example brand</h1>', '<h1 style="color:#25334e">New title</h1>', $expected );
recipe_assert( $expected === $compiled['writer_payload']['layout']['raw_shortcodes'] && 2 === $compiled['omitted'], 'Only the removed elements disappear from the generated page' );
recipe_assert( is_array( $compiled['writer_payload']['wpbakery_document'] ?? null ) && nova_mapping_wpb_compact_to_shortcodes( $compiled['writer_payload']['wpbakery_document'] ) === $expected, 'The structured document matches the generated shortcodes' );
foreach ( [
    [ [ [ 'target' => $base . '3' ] ], 'A removed element cannot also receive content' ],
    [ [ [ 'target' => $base . '0/attributes/image' ] ], 'Only whole elements can be removed' ],
    [ [ [ 'target' => '/document/0/children/0' ], [ 'target' => $base . '0' ] ], 'Nested removals are rejected' ],
    [ [ [ 'target' => $base . '0' ], [ 'target' => $base . '0' ] ], 'Duplicate removals are rejected' ],
    [ [ [ 'target' => $base . '99' ] ], 'Unknown elements are rejected' ],
] as [ $omissions, $message ] ) { $bad = $recipe; $bad['omissions'] = $omissions; recipe_assert( is_wp_error( Nova_Bridge_Suite_Recipe_Engine::validate( $bad ) ), $message ); }
$mixed = $recipe; $mixed['mode'] = 'mixed'; recipe_assert( is_wp_error( Nova_Bridge_Suite_Recipe_Engine::validate( $mixed ) ), 'Removal needs a fixed layout' );
$last = $adapter->remove_node( nova_mapping_wpb_parse_shortcodes_to_compact( "[vc_row]\n[vc_column]A[/vc_column]\n[vc_column]B[/vc_column]\ntrailing[/vc_row]" ), '/0/children', 1 );
recipe_assert( "[vc_row]\n[vc_column]A[/vc_column]\ntrailing[/vc_row]" === nova_mapping_wpb_compact_to_shortcodes( $last ), 'Removing the last element keeps the trailing source text' );

// Gutenberg removal also drops the block's placeholder in its parent.
$blocks = parse_blocks( '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>One</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph --></div><!-- /wp:group -->' );
$without = Nova_Bridge_Suite_Recipe_Builders::adapter( 'gutenberg' )->remove_node( [ 'document' => $blocks ], '/document/0/innerBlocks', 0 );
recipe_assert( '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph --></div><!-- /wp:group -->' === serialize_blocks( $without['document'] ), 'A removed block leaves valid block markup' );

// Native title and excerpt are written to the post, never into the builder document.
$description = Nova_Bridge_Suite_Recipe_Native_Media::describe( [ 'fields' => [] ], 'post_type:page' );
recipe_assert( [ '/native_post/title', '/native_post/excerpt', '/native_post/featured_image' ] === array_column( $description['fields'], 'target' ), 'Title, excerpt and featured image are offered for a page' );
$GLOBALS['supports']['service'] = [ 'title', 'editor' ];
recipe_assert( [ '/native_post/title' ] === array_column( Nova_Bridge_Suite_Recipe_Native_Media::describe( [ 'fields' => [] ], 'post_type:service' )['fields'], 'target' ), 'Excerpt is offered only where the post type supports it' );
recipe_assert( ! in_array( '/native_post/title', array_column( Nova_Bridge_Suite_Recipe_Native_Media::describe( [ 'fields' => [ [ 'target' => '/document/title' ] ] ], 'post_type:page' )['fields'], 'target' ), true ), 'WordPress content layouts keep their own title control' );
$titled = $recipe; unset( $titled['omissions'] );
$titled['bindings'][] = [ 'target' => '/native_post/title', 'source' => '/seo_title', 'format' => 'text', 'required' => true ];
$titled['bindings'][] = [ 'target' => '/native_post/excerpt', 'source' => '/intro', 'format' => 'text', 'required' => false ];
$compiled = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $titled, [ 'title' => 'H', 'seo_title' => 'Page title', 'intro' => 'Short summary' ], 'post-fields' ), 'Native post fields compile' );
recipe_assert( [ 'title' => 'Page title', 'excerpt' => 'Short summary' ] === $compiled['post_fields'] && false === strpos( $compiled['writer_payload']['layout']['raw_shortcodes'], 'Page title' ), 'Title and excerpt are post fields' );
$html_title = $titled; $html_title['bindings'][1]['format'] = 'html'; recipe_assert( is_wp_error( Nova_Bridge_Suite_Recipe_Engine::validate( $html_title ) ), 'The native title is plain text' );

// Preview markers: the server marks each rendered shortcode, the canvas finds the same marker.
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-shortcode-preview.php';
$marked = Nova_Bridge_Suite_Shortcode_Preview::mark_content( $content );
$hash = Nova_Bridge_Suite_Shortcode_Preview::document_hash( $capture['blueprint']['document'] );
recipe_assert( hash( 'sha256', $content ) === $hash, 'The preview hash is the stored content hash' );
recipe_assert( false !== strpos( $marked, 'nova_preview_node="' . Nova_Bridge_Suite_Shortcode_Preview::marker( $base . '0', $hash ) . '"' ), 'Each captured element carries its preview marker' );
recipe_assert( str_replace( [ '[nova_test_single_image ', 'animation="fade-in"' ], '', $marked ) !== $marked && false !== strpos( $marked, '<h1 style="color:#25334e">Example brand</h1>' ), 'Marking keeps the original attributes and content' );
recipe_assert( 'escaped [[vc_row]] text' === Nova_Bridge_Suite_Shortcode_Preview::mark_content( 'escaped [[vc_row]] text' ) && 'plain' === Nova_Bridge_Suite_Shortcode_Preview::mark_content( 'plain' ), 'Content without captured shortcodes is left unchanged' );
if ( ! function_exists( '_doing_it_wrong' ) ) { function _doing_it_wrong( ...$args ) {} }
if ( ! function_exists( '__' ) ) { function __( $text ) { return $text; } }
if ( ! function_exists( 'wp_kses_uri_attributes' ) ) { function wp_kses_uri_attributes() { return [ 'href', 'src' ]; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $url ) { return $url; } }
if ( ! function_exists( 'wp_has_noncharacters' ) ) { function wp_has_noncharacters( $text ) { return 1 === preg_match( '/[\x{FDD0}-\x{FDEF}\x{FFFE}\x{FFFF}]/u', $text ); } }
foreach ( [ 'class-wp-token-map.php', 'html-api/html5-named-character-references.php', 'html-api/class-wp-html-attribute-token.php', 'html-api/class-wp-html-span.php', 'html-api/class-wp-html-text-replacement.php', 'html-api/class-wp-html-decoder.php', 'html-api/class-wp-html-tag-processor.php' ] as $file ) {
    foreach ( [ $core . '/' . $file, $core . '/' . basename( $file ) ] as $candidate ) { if ( is_file( $candidate ) ) { require_once $candidate; break; } }
}
recipe_assert( class_exists( 'WP_HTML_Tag_Processor' ), 'The WordPress HTML API is available for the preview marker check' );
{
    $output = Nova_Bridge_Suite_Shortcode_Preview::tag_output( '<style>.a{}</style><div class="wpb_single_image"><img src="x.jpg"></div>', 'vc_single_image', [ 'nova_preview_node' => str_repeat( 'a', 32 ) ] );
    recipe_assert( false !== strpos( $output, '<div data-nova-preview-node="' . str_repeat( 'a', 32 ) . '" class="wpb_single_image">' ) || false !== strpos( $output, 'class="wpb_single_image" data-nova-preview-node' ), 'The marker lands on the first visible element' );
}
recipe_assert( 'plain' === Nova_Bridge_Suite_Shortcode_Preview::tag_output( 'plain', 'x', [ 'nova_preview_node' => '<bad>' ] ), 'Invalid markers are ignored' );

// Known-ID publishing writes the document, page settings and WPBakery state, then verifies them.
$GLOBALS['recipe_posts'][901] = (object) [ 'ID' => 901, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Draft', 'post_content' => '', 'post_excerpt' => '' ];
$GLOBALS['recipe_meta'][901] = [];
$payload = $compiled['writer_payload'];
recipe_assert( true === $adapter->publication_support( 'page' )['supported'], 'WPBakery has a publishing provider' );
recipe_assert( false === Nova_Bridge_Suite_Recipe_Builders::adapter( 'avada' )->publication_support( 'page' )['supported'], 'Other shortcode builders still report no provider' );
recipe_assert( 'wpbakery-shortcodes-v1' === recipe_ok( $adapter->publication_probe( 'page', $payload, 'publish' ), 'Probe' )['provider_version'], 'The provider version is stable' );
recipe_ok( $adapter->publication_apply( 901, $payload, [ 'title' => 'Page title', 'excerpt' => 'Short summary' ] ), 'A WPBakery page is written by known ID' );
recipe_assert( $payload['layout']['raw_shortcodes'] === $GLOBALS['recipe_posts'][901]->post_content && 'Page title' === $GLOBALS['recipe_posts'][901]->post_title && 'Short summary' === $GLOBALS['recipe_posts'][901]->post_excerpt, 'Content, title and excerpt are saved' );
recipe_assert( 'true' === $GLOBALS['recipe_meta'][901]['_wpb_vc_js_status'] && '.x{color:red}' === $GLOBALS['recipe_meta'][901]['_wpb_post_custom_css'] && array_key_exists( '_wpb_shortcodes_custom_css', $GLOBALS['recipe_meta'][901] ), 'WPBakery editor state and custom CSS are saved' );
$GLOBALS['recipe_meta'][901]['_wpb_post_custom_css'] = 'changed';
recipe_assert( is_wp_error( $adapter->publication_verify( 901, $payload ) ), 'Changed page settings fail verification' );
$GLOBALS['recipe_meta'][901]['_wpb_post_custom_css'] = '.x{color:red}'; $GLOBALS['recipe_posts'][901]->post_content .= ' ';
recipe_assert( is_wp_error( $adapter->publication_verify( 901, $payload ) ), 'A changed document fails verification' );
$bad = $payload; $bad['meta']['_edit_lock'] = '1'; recipe_assert( is_wp_error( $adapter->publication_probe( 'page', $bad, 'publish' ) ), 'Protected metadata is refused' );
$GLOBALS['recipe_meta'][902] = [ '_elementor_edit_mode' => 'builder' ]; $GLOBALS['recipe_posts'][902] = (object) [ 'ID' => 902, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Other', 'post_content' => '', 'post_excerpt' => '' ];
recipe_assert( is_wp_error( $adapter->publication_apply( 902, $payload, [ 'title' => 'x' ] ) ), 'An item owned by another builder is not overwritten' );
echo 'WPBakery layout checks: ' . ( $assertions - $before ) . " passed.\n";
