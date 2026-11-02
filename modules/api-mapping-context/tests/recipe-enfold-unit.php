<?php
/** Memory-only native Enfold authoring/known-ID lifecycle checks. No WordPress/database bootstrap. */
$fixture = getenv( 'NOVA_WP_CORE_FIXTURE' );
if ( ! is_file( $fixture . '/shortcodes.php' ) ) { throw new RuntimeException( 'Set NOVA_WP_CORE_FIXTURE to the WordPress shortcode parser.' ); }
define( 'ABSPATH', __DIR__ );
class WP_Error { function __construct( public $code, public $message, public $data = [] ) {} function get_error_message() { return $this->message; } function get_error_data() { return $this->data; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function wp_kses_post( $v ) { return $v; } function esc_html( $v ) { return htmlspecialchars( $v, ENT_QUOTES ); }
function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function current_user_can( ...$args ) { return $GLOBALS['allowed']; } function get_current_user_id() { return 9; }
function get_post_type_object( $type ) { return (object) [ 'cap' => (object) [ 'edit_posts' => 'edit_posts', 'create_posts' => 'create_posts' ] ]; }
function get_post( $id ) { if ( ! empty( $GLOBALS['forbid_all_reads'] ) ) { throw new RuntimeException( 'Unexpected source read while compiling.' ); } return $GLOBALS['posts'][$id] ?? null; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function metadata_exists( $type, $id, $key ) { return array_key_exists( $key, $GLOBALS['meta'][$id] ?? [] ); }
function get_page_template_slug( $id ) { $template = get_post_meta( $id, '_wp_page_template' ); return 'default' === $template ? '' : $template; }
function update_post_meta( $id, $key, $v ) { $GLOBALS['meta'][$id][$key] = wp_unslash( $v ); ++$GLOBALS['writes']; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][$id][$key] ); ++$GLOBALS['writes']; }
function clean_post_cache( $id ) {} function get_edit_post_link( $id, $context ) { return '/edit/' . $id; } function get_preview_post_link( $id ) { return '/preview/' . $id; }
function wp_get_attachment_url( $id ) { return 'https://media.test/' . $id . '.jpg'; }
function wp_update_post( $data, $error ) { ++$GLOBALS['writes']; $data = wp_unslash( $data ); $id = $data['ID']; foreach ( $data as $k => $v ) { if ( 'meta_input' === $k ) { foreach ( $v as $name => $val ) { $GLOBALS['meta'][$id][$name] = $val; } } elseif ( 'page_template' === $k ) { $GLOBALS['meta'][$id]['_wp_page_template'] = $v; } else { $GLOBALS['posts'][$id]->$k = $v; } } return $id; }
function wp_insert_post( $data, $error ) { $id = ++$GLOBALS['last_id']; $GLOBALS['posts'][$id] = (object) []; return wp_update_post( $data + [ 'ID' => $id, 'post_content' => '', 'post_excerpt' => '' ], true ); }
class ShortcodeHelper { static function build_shortcode_tree( $source ) { if ( ! isset( $GLOBALS['post']->ID ) ) { throw new RuntimeException( 'Native tree generation needs its target.' ); } preg_match_all( '/\[(av_[a-z_]+)/', $source, $matches ); return array_map( static fn( $tag ) => [ 'tag' => $tag ], $matches[1] ); } }
class Test_Enfold_Builder {
    function get_theme_version() { return '8.3-fixture'; } function get_supported_post_types() { return [ 'post', 'page', 'service' ]; }
    function get_shortcode_class( $tag ) {
        $text = static fn( $key, $type = 'input' ) => [ 'id' => $key, 'name' => ucfirst( $key ), 'type' => $type ];
        $img = [ 'id' => 'src', 'name' => 'Image', 'type' => 'image', 'locked' => [ 'src', 'attachment', 'attachment_size' ] ];
        $types = [ 'av_section' => [ $img, $text( 'padding' ) ], 'av_one_full' => [], 'av_heading' => [ $text( 'heading' ), $text( 'content', 'textarea' ) ], 'av_button' => [ $text( 'label' ), $text( 'link', 'linkpicker' ) ], 'av_textblock' => [ $text( 'content', 'tiny_mce' ) ], 'av_image' => [ $img ], 'av_codeblock' => [ $text( 'content', 'textarea' ) ], 'av_postcontent' => [ $text( 'link', 'linkpicker' ) ], 'av_client_component' => [ $text( 'title' ) ] ];
        return array_key_exists( $tag, $types ) ? (object) [ 'config' => [ 'name' => $tag ], 'elements' => $types[$tag] ] : false;
    }
    function get_posts_alb_content( $id ) { return get_post_meta( $id, '_aviaLayoutBuilderCleanData' ); }
    function set_alb_builder_status( $s, $id ) { update_post_meta( $id, '_aviaLayoutBuilder_active', $s ); }
    function get_alb_builder_status( $id ) { return get_post_meta( $id, '_aviaLayoutBuilder_active' ); }
    function save_posts_alb_content( $id, $source ) { update_post_meta( $id, '_aviaLayoutBuilderCleanData', $source ); }
    function save_shortcode_tree( $id, $tree ) { update_post_meta( $id, '_avia_builder_shortcode_tree', $tree ); }
    function get_shortcode_tree( $id ) { return (array) get_post_meta( $id, '_avia_builder_shortcode_tree' ); }
    function element_manager() { return $this; }
    function updated_post_content( $source, $id ) { if ( $GLOBALS['post']->ID !== $id ) { throw new RuntimeException( 'Wrong target for asset refresh' ); } $GLOBALS['usage'][] = $id; }
    function handler_wp_save_post( $id, $post, $update ) { if ( $GLOBALS['post'] !== $post || $post->ID !== $id ) { throw new RuntimeException( 'Wrong target for CSS refresh' ); } $GLOBALS['css'][] = $id; }
}
function Avia_Builder() { static $builder; return $builder ??= new Test_Enfold_Builder(); } function AviaPostCss() { return Avia_Builder(); }

require $fixture . '/shortcodes.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-engine.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-enfold.php';
function good( $v ) { if ( is_wp_error( $v ) ) { throw new RuntimeException( $v->get_error_message() ); } return $v; }
function check( $v, string $why ) { ++$GLOBALS['checks']; if ( ! $v ) { throw new RuntimeException( $why ); } }
function choose( array $fields, string $tag, string $kind ) { foreach ( $fields as $field ) { if ( $field['element_type'] === $tag && $field['kind'] === $kind ) { return $field; } } throw new RuntimeException( 'Missing: ' . $tag . '/' . $kind ); }
$checks = $writes = 0; $allowed = true; $last_id = 70; $posts = $meta = $css = $usage = []; $adapter = new Nova_Bridge_Suite_Recipe_Enfold();
$source = "[av_section src='https://old.test/bg.jpg' attachment='11' attachment_size='large' av_uid='av-source'][av_one_full first][av_heading heading='Original heading' tag='h1'][/av_heading][av_textblock font_color='blue']<p>Old text.</p><p>Old second paragraph.</p>[/av_textblock][av_image src='https://old.test/pic.jpg' attachment='12' attachment_size='medium'][/av_image][av_button label='Call us' link='manually,https://old.test/'][av_client_component title='Original custom title'][/av_one_full][/av_section]";
$posts[5] = (object) [ 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => 'An intentionally stale preview mirror.' ]; $meta[5] = [ '_aviaLayoutBuilder_active' => 'active', '_aviaLayoutBuilderCleanData' => $source, 'layout' => 'no_sidebar', 'footer' => 'nofooterarea', '_wp_page_template' => 'template-blank.php', 'private_key' => 'not-a-layout-field' ];
$d = good( $adapter->capture( 5 ) ); $b = $d['blueprint']; $fields = $d['fields'];
check( Nova_Bridge_Suite_Recipe_Enfold::has_document( $source ) && ! Nova_Bridge_Suite_Recipe_Enfold::has_document( '[[av_image]] example' ), 'Detect native Avia without claiming escaped prose.' );
check( good( $adapter->finalize( $b, 'roundtrip' ) )['native_document'] === $source, 'Capture reads native editor source losslessly, not a stale WP content mirror.' );
check( $b['meta']['footer'] === [ 'present' => true, 'value' => 'nofooterarea' ] && $b['meta']['sidebar'] === [ 'present' => false, 'value' => '' ] && ! isset( $b['meta']['private_key'] ) && $b['template'] === 'template-blank.php', 'Capture only page presentation and its presence, never arbitrary meta.' );
$heading = choose( $fields, 'av_heading', 'heading' ); $body = choose( $fields, 'av_textblock', 'body' ); $link = choose( $fields, 'av_button', 'link_url' ); $img = choose( $fields, 'av_image', 'image_url' ); $bg = choose( $fields, 'av_section', 'image_url' );
check( 'Original heading' === $heading['value'] && 'html' === $body['format'] && 'https://old.test/' === $link['value'], 'Native editable heading, whole rich body and decoded link reflect their actual carriers.' );
check( choose( $fields, 'av_client_component', 'title' )['writable'] && ! array_filter( $fields, static fn( $f ) => 'padding' === $f['kind'] ), 'Native extension declarations work without exposing styling fields.' );
foreach ( [ $img, $bg ] as $media ) { check( $media['target'] === $media['native_media']['url_target'] && str_ends_with( $media['native_media']['id_target'], '/attributes/attachment' ), 'Element images and section backgrounds have paired native placement targets.' ); }
$rich = '<p>First mapped paragraph.</p><h2>Mapped section</h2><ul><li>List</li></ul><p>Last <strong>paragraph</strong>.</p>';
$b = good( $adapter->bind( $b, $heading, 'New heading' ) ); $b = good( $adapter->bind( $b, $body, $rich ) ); $b = good( $adapter->bind( $b, $link, 'https://new.test/?a=1&b=2' ) );
unset( $posts[5], $meta[5] ); $forbid_all_reads = true; $compiled = good( $adapter->finalize( $b, 'source-gone' ) ); $forbid_all_reads = false;
check( str_contains( $compiled['native_document'], $rich ) && ! str_contains( $compiled['native_document'], '<p><p>' ) && str_contains( $compiled['native_document'], "font_color='blue'" ) && str_contains( $compiled['native_document'], "link='manually,https://new.test/?a=1&amp;b=2'" ), 'Deleted-source compilation preserves native rich blocks, design and Enfold manual-link encoding.' );
$protected = good( $adapter->capture_document( '[av_textblock element_template="42"]<p>Global</p>[/av_textblock][av_codeblock]<p>Untrusted code.</p>[/av_codeblock][unknown]<h1>Dynamic</h1>[/unknown]' ) );
check( ! array_filter( $adapter->fields( [ 'document' => $protected ] ), static fn( $field ) => $field['writable'] ), 'Unknown, global template and code-backed values are protected.' );
$empty = [ 'document' => good( $adapter->capture_document( '[av_textblock][/av_textblock][av_image][/av_image][av_heading tag="h1"][/av_heading]' ) ) ]; $empty_fields = $adapter->fields( $empty );
check( '' === choose( $empty_fields, 'av_textblock', 'body' )['value'] && '' === choose( $empty_fields, 'av_image', 'image_url' )['value'], 'Empty declared native text and image destinations are discoverable.' );
$filled = good( $adapter->bind( $empty, choose( $empty_fields, 'av_heading', 'heading' ), 'Added' ) );
check( str_contains( good( $adapter->finalize( $filled, 'filled' ) )['native_document'], 'heading="Added"' ), 'A previously omitted native attribute is added only when selected.' );
$styled = [ 'document' => good( $adapter->capture_document( '[av_textblock]<p class="lead">Old intro</p>[/av_textblock]' ) ) ]; $styled = good( $adapter->bind( $styled, choose( $adapter->fields( $styled ), 'av_textblock', 'body' ), '<p>New intro</p><h2>New heading</h2><ul><li>Item</li></ul>' ) );
check( str_contains( good( $adapter->finalize( $styled, 'style' ) )['native_document'], '<p class="lead">New intro</p><h2>New heading</h2><ul><li>Item</li></ul>' ), 'Replacing a rich-text box retains its original paragraph styling without enclosing new headings and lists inside it.' );
$global_before = (object) [ 'ID' => 777 ]; $GLOBALS['post'] = $global_before; $payload = $compiled['writer_payload'];
$draft = good( $adapter->write_draft( $payload, [ 'title' => 'New drafted page', 'excerpt' => 'Summary', 'post_type' => 'service', 'slug' => 'new-service' ] ) ); $id = $draft['post_id']; $saved = $posts[$id]->post_content;
check( $id === 71 && $posts[$id]->post_title === 'New drafted page' && $posts[$id]->post_status === 'draft' && $posts[$id]->post_type === 'service' && $posts[$id]->post_name === 'new-service', 'Create a real new native draft in the supported CPT instead of cloning the example.' );
check( $saved === Avia_Builder()->get_posts_alb_content( $id ) && 'active' === Avia_Builder()->get_alb_builder_status( $id ) && ! empty( Avia_Builder()->get_shortcode_tree( $id ) ), 'Both frontend/preview and editable Avia carriers match, with active builder and native tree.' );
check( ! str_contains( $saved, 'av-source' ) && preg_match_all( '/av_uid=[\x22\x27](av-[a-f0-9]+)/', $saved, $uids ) > 3 && count( $uids[1] ) === count( array_unique( $uids[1] ) ), 'Target receives unique element identities rather than reusing example IDs.' );
check( $GLOBALS['post'] === $global_before && $css === [ $id ] && $usage === [ $id ], 'Native CSS/asset refresh is limited to the retained target and restores prior WP context.' );
check( get_page_template_slug( $id ) === 'template-blank.php' && get_post_meta( $id, 'layout' ) === 'no_sidebar' && ! metadata_exists( 'post', $id, 'sidebar' ), 'New target retains native layout and absence of unrelated presentation fields.' );
$before = $writes; good( $adapter->publication_verify( $id, $payload ) ); check( $writes === $before, 'Native readback is read-only.' );
$posts[$id]->post_status = 'publish'; $posts[$id]->post_author = 42; good( $adapter->publication_apply( $id, $payload, [ 'title' => 'Updated retained item' ] ) );
check( $posts[$id]->post_content === $saved && $posts[$id]->post_status === 'publish' && $posts[$id]->post_author === 42 && $posts[$id]->post_title === 'Updated retained item', 'Known-target updates preserve ID/status/author and deterministic shortcode IDs across retries.' );
$draft2 = good( $adapter->write_draft( $payload, [ 'title' => 'Another item' ] ) ); check( $posts[$draft2['post_id']]->post_content !== $saved, 'Separate targets receive distinct element IDs from the same frozen layout.' );
$meta[$id]['_aviaLayoutBuilderCleanData'] = 'Stale editor copy'; check( is_wp_error( $adapter->publication_verify( $id, $payload ) ), 'A correct WordPress mirror cannot hide stale Avia editor data.' );
$meta[$id]['_aviaLayoutBuilderCleanData'] = $saved; $meta[$id]['_avia_builder_shortcode_tree'] = []; check( is_wp_error( $adapter->publication_verify( $id, $payload ) ), 'Native readback detects a missing shortcode tree.' );
$allowed = false; $before = $writes; check( is_wp_error( $adapter->publication_apply( $id, $payload, [ 'title' => 'Denied' ] ) ) && $writes === $before, 'Permission denial does not mutate native state.' );
$allowed = true; check( ! $adapter->publication_support( 'unsupported' )['supported'], 'The post type must be enabled in the actual Avia builder.' );
echo 'Enfold recipe checks: ' . $checks . " passed.\n";
