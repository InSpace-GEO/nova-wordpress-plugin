<?php
/** Native-object fingerprints and localization between two isolated in-memory WordPress registries. */
define( 'ABSPATH', __DIR__ . '/no-wordpress/' );
final class WP_Error {
    public function __construct( private $code, private $message, private $data = [] ) {}
    public function get_error_data() { return $this->data; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_post( $id ) { return $GLOBALS['registry'][$id] ?? null; }
function get_option( $key ) { return $GLOBALS['kit']; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function get_page_template_slug( $id ) { return ''; }
function get_attached_file( $id, $unfiltered = false ) { return $GLOBALS['files'][$id] ?? false; }
function wp_get_upload_dir() { return [ 'basedir' => $GLOBALS['work'] ]; }
function wp_get_attachment_url( $id ) { return 'https://follower.test/uploads/' . $id . '.png'; }
function attachment_url_to_postid( $url ) { return $GLOBALS['by_url'][$url] ?? 0; }
function get_posts( $q ) {
    $ids = [];
    foreach ( $GLOBALS['registry'] as $id => $post ) {
        if ( $post->post_type !== $q['post_type'] ) { continue; }
        if ( isset( $q['name'] ) && $q['name'] !== $post->post_name ) { continue; }
        if ( isset( $q['meta_key'] ) && get_post_meta( $id, $q['meta_key'], true ) !== $q['meta_value'] ) { continue; }
        $ids[] = $id;
    }
    return array_slice( $ids, 0, $q['numberposts'] );
}
function check( $yes, string $why ) { if ( ! $yes ) { throw new RuntimeException( $why ); } }
function record( $builder, $doc ) {
    return [ 'builder' => $builder, 'id' => 'a', 'revision' => 3, 'recipe' => [ 'builder' => $builder, 'mode' => 'static', 'blueprint' => [ 'document' => $doc, 'template' => '' ], 'regions' => [] ] ];
}
function attach( $id, $slug, $bytes, $relative ) {
    $GLOBALS['registry'][$id] = (object) [ 'ID' => $id, 'post_name' => $slug, 'post_type' => 'attachment', 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_password' => '' ];
    $GLOBALS['meta'][$id]['_wp_attached_file'] = $relative;
    $file = $GLOBALS['work'] . DIRECTORY_SEPARATOR . $id . '.png';
    file_put_contents( $file, $bytes );
    $GLOBALS['files'][$id] = $file;
}
function loop_item( $id, $slug, $doc ) {
    $GLOBALS['registry'][$id] = (object) [ 'ID' => $id, 'post_name' => $slug, 'post_type' => 'elementor_library', 'post_status' => 'publish', 'post_password' => '' ];
    $GLOBALS['meta'][$id] = [ '_elementor_data' => json_encode( $doc ), '_elementor_template_type' => 'loop-item' ];
}
function clear_site() { $GLOBALS['registry'] = $GLOBALS['meta'] = $GLOBALS['files'] = []; }
function require_issue( $result, $key, $manual ) {
    check( is_wp_error( $result ), 'Missing issue: ' . $key );
    foreach ( $result->get_error_data()['issues'] as $issue ) {
        if ( str_starts_with( $issue['key'], $key ) && $manual === $issue['remappable'] ) { return; }
    }
    throw new RuntimeException( 'Wrong issue for ' . $key . ': ' . $result->get_error_message() );
}
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-shared-layout-dependencies.php';
class_alias( 'Nova_Bridge_Suite_Shared_Layout_Dependencies', 'Deps' );
$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nova-layout-deps-' . bin2hex( random_bytes( 6 ) );
mkdir( $work );
try {
    clear_site(); $kit = 8;
    attach( 12, 'hero', 'same pixels', '2026/01/hero.png' );
    $loop = [ [ 'elType' => 'widget', 'id' => '12', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Card' ] ] ];
    loop_item( 71, 'card', $loop );
    $meta[$kit]['_elementor_page_settings'] = [ 'system_colors' => [ [ '_id' => 'primary', 'color' => '#abc', 'title' => 'Brand' ] ] ];
    $source = record( 'elementor', [
        [ 'id' => '12', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => [ 'image' => [ 'id' => 12, 'url' => 'https://primary.test/uploads/hero.png' ], 'padding' => 12, '__globals__' => [ 'color' => 'globals/colors?id=primary' ] ] ],
        [ 'id' => '55', 'elType' => 'widget', 'widgetType' => 'loop-grid', 'settings' => [ 'template_id' => '71' ] ],
    ] );
    $manifest = Deps::capture( $source );
    check( ! is_wp_error( $manifest ) && count( $manifest['entries'] ) === 3, 'All and only actual object references are captured.' );
    clear_site(); $kit = 9;
    attach( 12, 'wrong', 'completely different', '2026/01/wrong.png' );
    attach( 112, 'hero', 'same pixels', '2026/02/hero.png' );
    loop_item( 71, 'unrelated', [ [ 'content' => 'Wrong card' ] ] );
    loop_item( 171, 'card', $loop );
    $meta[$kit]['_elementor_page_settings'] = [ 'system_colors' => [ [ 'color' => '#abc', 'title' => 'Different label', '_id' => 'primary' ] ] ];
    $result = Deps::resolve( $source, $manifest );
    check( ! is_wp_error( $result ), 'Two installations with different IDs can use the same layout.' );
    $doc = $result['recipe']['blueprint']['document'];
    check( $doc[0]['settings']['image'] === [ 'id' => 112, 'url' => wp_get_attachment_url( 112 ) ] && $doc[0]['id'] === '12' && $doc[0]['settings']['padding'] === 12 && $doc[1]['settings']['template_id'] === '171', 'Only typed media and template references were changed.' );
    check( $source['recipe']['blueprint']['document'][0]['settings']['image']['id'] === 12, 'Package remains immutable after localization.' );
    require_issue( Deps::resolve( $source, $manifest, [ 'attachment:12' => 12 ] ), 'attachment:12', true );
    $bak = get_post( 112 );
    $bak->post_name = 'moved';
    attach( 42, 'hero', 'different pixels even with the original filename', '2026/01/hero.png' );
    $missing = Deps::resolve( $source, $manifest );
    require_issue( $missing, 'attachment:12', true );
    check( str_contains( $missing->get_error_message(), '2026/01/hero.png' ) && str_contains( $missing->get_error_message(), 'primary ID 12' ) && str_contains( $missing->get_error_message(), 'element 1 / image' ), 'An unrelated file with the same original name is refused; UI identifies file, ID and control.' );
    $bak->post_name = 'hero'; unset( $registry[42] );
    $meta[171]['_elementor_data'] = '[{"elType":"widget","settings":{"title":"Changed"}}]';
    require_issue( Deps::resolve( $source, $manifest ), 'elementor_loop:71', true );
    loop_item( 171, 'card', $loop );
    attach( 113, 'hero', 'same pixels', '2026/04/hero.png' );
    require_issue( Deps::resolve( $source, $manifest ), 'attachment:12', true );
    check( ! is_wp_error( Deps::resolve( $source, $manifest, [ 'attachment:12' => 113 ] ) ), 'Manual selection disambiguates only verified matching originals.' );
    unset( $registry[113] );
    $meta[$kit]['_elementor_page_settings']['system_colors'][0]['color'] = '#def';
    require_issue( Deps::resolve( $source, $manifest ), 'unsupported:', false );
    $meta[$kit]['_elementor_page_settings']['system_colors'][0]['color'] = '#abc';
    $reduced = $manifest; unset( $reduced['entries']['attachment:12'] );
    require_issue( Deps::resolve( $source, $reduced ), 'unsupported:', false );
    $extra = $manifest; $extra['entries']['attachment:999'] = $manifest['entries']['attachment:12'];
    require_issue( Deps::resolve( $source, $extra ), 'unsupported:', false );
    $linked = record( 'flatsome', [ [ 'kind' => 'shortcode', 'tag' => 'block', 'attributes' => [ 'id' => 12 ], 'definition' => [ 'protected' => true ] ] ] );
    require_issue( Deps::capture( $linked ), 'unsupported:', false );
    $snapshot = record( 'flatsome', [ [ 'kind' => 'block_snapshot', 'block_id' => 12, 'original_node' => $linked['recipe']['blueprint']['document'][0], 'children' => [ [ 'kind' => 'html', 'content' => '<p>Frozen</p>' ] ] ] ] );
    check( [] === Deps::capture( $snapshot )['entries'], 'An expanded reusable UX block is source free.' );
    $native_image = record( 'flatsome', [ [ 'kind' => 'shortcode', 'tag' => 'ux_image', 'definition' => [ 'fields' => [ 'id' => [ 'image' => true ] ] ], 'attributes' => [ 'id' => '112' ], 'original_attributes' => [ 'id' => '112' ], 'raw_open' => '[ux_image id="112"]', 'children' => [] ] ] );
    $media = Deps::capture( $native_image );
    attach( 212, 'moved-hero', 'same pixels', 'shared/name.png' );
    $placed = Deps::resolve( $native_image, $media, [ 'attachment:112' => 212 ] );
    check( $placed['recipe']['blueprint']['document'][0]['attributes']['id'] === '212' && $placed['recipe']['blueprint']['document'][0]['original_attributes']['id'] === '112', 'Shortcode serializer can update the actual native attribute.' );
    $blocks = record( 'gutenberg', [ [ 'blockName' => 'core/block', 'attrs' => [ 'ref' => 12 ] ] ] );
    require_issue( Deps::capture( $blocks ), 'unsupported:', false );
    $blocks = record( 'gutenberg', [ [ 'blockName' => 'core/paragraph', 'attrs' => [], 'innerHTML' => '<p>Portable [example].</p>' ] ] );
    check( Deps::resolve( $blocks, Deps::capture( $blocks ) ) === $blocks, 'Independent Gutenberg layout round-trips.' );
    $query = [ 'postType' => 'page', 'perPage' => 6, 'orderBy' => 'date', 'parents' => [ 0 ], 'taxQuery' => [ 'category' => [] ], 'author' => '', 'exclude' => [] ];
    $loop_block = static fn( $query ) => record( 'gutenberg', [ [ 'blockName' => 'core/query', 'attrs' => [ 'queryId' => 123, 'query' => $query ] ] ] );
    $portable = $loop_block( $query );
    check( Deps::resolve( $portable, Deps::capture( $portable ) ) === $portable, 'A local query number, pagination and the root-page filter do not refer to another site’s records.' );
    foreach ( [ 'taxQuery' => [ 'category' => [ 45 ] ], 'exclude' => [ 72 ], 'parents' => [ 0, 15 ], 'author' => '0,13,27', 'categoryIds' => [ 18 ], 'tagIds' => [ 7 ] ] as $filter => $selection ) {
        $scoped = $loop_block( array_replace( $query, [ $filter => $selection ] ) );
        require_issue( Deps::capture( $scoped ), 'unsupported:', false );
        require_issue( Deps::resolve( $scoped, [ 'format' => 'nova.shared-dependencies/v1', 'entries' => [] ] ), 'unsupported:', false );
    }
    foreach ( [ [ 'core/latest-posts', [ 'categories' => [ [ 'id' => 7, 'name' => 'News' ] ] ] ], [ 'core/latest-posts', [ 'selectedAuthor' => 7 ] ], [ 'core/avatar', [ 'userId' => 7 ] ], [ 'core/page-list', [ 'parentPageID' => 7 ] ] ] as [ $name, $attrs ] ) {
        require_issue( Deps::capture( record( 'gutenberg', [ [ 'blockName' => $name, 'attrs' => $attrs ] ] ) ), 'unsupported:', false );
        check( ! is_wp_error( Deps::capture( record( 'gutenberg', [ [ 'blockName' => $name, 'attrs' => [] ] ] ) ) ), 'The same block without a source record selection is portable.' );
    }
    $bad = record( 'core', [ 'sections' => [ [ 'body' => '<img class="wp-image-12" src="/a.png">' ] ] ] );
    require_issue( Deps::capture( $bad ), 'unsupported:', false );
    $source_url = 'https://primary.test/eindhoven/wp-content/uploads/2026/01/hero.png';
    $by_url[$source_url] = 112;
    $background = record( 'elementor', [ [ 'elType' => 'container', 'settings' => [ 'background_image' => [ 'id' => '', 'url' => $source_url ] ] ] ] );
    $media = Deps::capture( $background ); unset( $by_url[$source_url] );
    check( ! is_wp_error( $media ) && array_key_first( $media['entries'] ) === 'attachment-url:' . hash( 'sha256', $source_url ), 'URL-only native background is fingerprinted on the primary.' );
    $localized = Deps::resolve( $background, $media, [ array_key_first( $media['entries'] ) => 212 ] );
    check( $localized['recipe']['blueprint']['document'][0]['settings']['background_image'] === [ 'id' => 212, 'url' => wp_get_attachment_url( 212 ) ], 'Follower rewrites both local URL and attachment ID without primary URL lookup.' );
    require_issue( Deps::capture( $background ), 'unsupported:', false );
    $css = record( 'elementor', [ [ 'settings' => [ 'custom_css' => 'body {background:url(' . $source_url . ')}' ] ] ] );
    require_issue( Deps::capture( $css ), 'unsupported:', false );
    echo "Shared-layout dependency checks passed.\n";
} finally {
    foreach ( glob( $work . DIRECTORY_SEPARATOR . '*.png' ) as $file ) { unlink( $file ); }
    rmdir( $work );
}
