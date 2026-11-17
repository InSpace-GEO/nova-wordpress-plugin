<?php
/** Exercise localized images through the shared-source writer and retained importer. */
use Nova_Bridge_Suite_Translation_Writer as Writer;
$argv[1] = 'weglot';
require __DIR__ . '/localized-custom-fields-unit.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-wordpress-weglot-renderer.php';

function add_option( $key, $value, ...$unused ) { $GLOBALS['associations'][$key] = $value; return true; }
function update_option( $key, $value, ...$unused ) { $GLOBALS['associations'][$key] = $value; return true; }
function wp_http_validate_url( $url ) { return str_starts_with( $url, 'https://images.example.test/' ); }
function wp_tempnam( $name ) { return tempnam( sys_get_temp_dir(), 'nova-translation-media-' ); }
function wp_safe_remote_get( $url, $args ) {
    ++$GLOBALS['media_downloads'];
    file_put_contents( $args['filename'], base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6Y6IAAAAASUVORK5CYII=' ) );
    return [ 'status' => 200 ];
}
function wp_remote_retrieve_response_code( $reply ) { return $reply['status']; }
function wp_attachment_is_image( $id ) { return isset( $GLOBALS['attachment_posts'][$id] ); }
function wp_get_attachment_url( $id ) { return wp_attachment_is_image( $id ) ? home_url( '/uploads/image-' . $id . '.png' ) : false; }
function wp_get_attachment_metadata( $id ) { return [ 'width' => 1, 'height' => 1 ]; }
function wp_generate_attachment_metadata( $id, $file ) { return [ 'width' => 1, 'height' => 1 ]; }
function wp_update_attachment_metadata( $id, $data ) { return true; }
function media_handle_sideload( $file, $parent, $description, $data ) {
    $id = 100 + count( $GLOBALS['attachment_posts'] );
    $GLOBALS['attachment_posts'][$id] = (object) [ 'ID' => $id, 'post_type' => 'attachment', 'post_parent' => $parent,
        'post_content_filtered' => $data['post_content_filtered'] ];
    if ( ! empty( $GLOBALS['interrupt_import'] ) ) { $GLOBALS['interrupt_import'] = false; throw new RuntimeException( 'Lost import response' ); }
    return $id;
}
$wpdb = new class {
    public $posts = 'posts';
    public function get_var( $query ) { return '1'; }
    public function prepare( $query, ...$args ) { return [ $query, $args ]; }
    public function get_col( $query ) {
        return array_keys( array_filter( $GLOBALS['attachment_posts'], static function ( $post ) use ( $query ) {
            return $post->post_content_filtered === $query[1][1];
        } ) );
    }
};
$hooks = []; $visitor = 'de'; $associations = []; $attachment_posts = []; $media_downloads = 0;
$post = (object) [ 'ID'=>41, 'post_type'=>'page', 'post_status'=>'publish', 'post_password'=>'',
    'post_title'=>'Original title', 'post_excerpt'=>'', 'post_content'=>'Original body' ];
$original = json_encode( [ [ 'id'=>'hero', 'elType'=>'widget', 'widgetType'=>'image',
    'settings'=>[ 'image'=>[ 'id'=>7, 'url'=>home_url( '/original.jpg' ) ] ] ] ] );
$data = [ 41=>[ '_elementor_data'=>[$original], '_thumbnail_id'=>['7'] ] ];
$baseline = $data[41];
$payload = [ 'elementor_data'=>json_decode( $original, true ), 'nova_media'=>[
    '/native_post/featured_image'=>[ 'kind'=>'featured', 'url'=>'https://images.example.test/german.png' ],
    '/document/0/settings/image/url'=>[ 'kind'=>'elementor', 'url'=>'https://images.example.test/german.png',
        'url_target'=>'/document/0/settings/image/url', 'id_target'=>'/document/0/settings/image/id' ],
] ];
$compiled_layout = [ 'builder'=>'elementor', 'post_fields'=>[], 'writer_payload'=>$payload ];
$local = [ 'routing'=>[ 'post_type'=>'page', 'publication'=>'publish' ], 'stored_layout'=>[
    'bindings'=>[ [ 'kind'=>'recipe', 'mode'=>'adapt' ], [ 'kind'=>'media', 'mode'=>'adapt' ] ],
] ];
$config = [ 'mode'=>'stored_layout_v3', 'local'=>$local, 'profile_digest'=>hash( 'sha256', json_encode( $local ) ),
    'template'=>[ 'definition'=>[ 'version'=>3 ] ], 'site_id'=>'site' ];
function image_plan( $version ) {
    global $config;
    return Writer::plan( [ 'site_id'=>'site', 'url_id'=>'media-url', 'url'=>'/de/entry/', 'language'=>'de',
        'version_number'=>$version, 'configuration'=>$config['template'], 'content_item_id'=>'media-item', 'destination_values'=>[] ],
        $config, [ 'target_post_id'=>41, 'desired_status'=>'publish', 'operation_id'=>'media-operation-' . $version ] );
}
$plan = success( image_plan( 1 ) );
verify( isset( $plan['media'] ) && ! isset( $plan['fields']['native_media'] ), 'Media must be retained separately from localized fields' );
verify( 0 === $media_downloads && $data[41] === $baseline, 'Planning performed an image or native write' );
$interrupt_import = true;
verify( is_wp_error( Writer::apply( $plan, $plan['operation_id'] ) ), 'An interrupted import must remain recoverable' );
verify( 1 === $media_downloads && count( $attachment_posts ) === 1, 'The interrupted operation lost its attachment' );
verify( 'not_committed' === success( Writer::recover( $plan, $plan['operation_id'] ) )['state'], 'Partial import was reported as published' );
success( Writer::apply( $plan, $plan['operation_id'] ) );
success( Writer::apply( $plan, $plan['operation_id'] ) );
success( Writer::verify( $plan, [], true ) );
verify( 1 === $media_downloads && count( $attachment_posts ) === 1, 'Retry or verification downloaded the image again' );
$saved = Nova_WordPress_Weglot::get( 41, 'de' );
$document = json_decode( $saved['meta']['_elementor_data'], true );
verify( '100' === $saved['meta']['_thumbnail_id'] && 100 === $document[0]['settings']['image']['id'], 'Localized attachment IDs were not retained' );
verify( home_url( '/uploads/image-100.png' ) === $document[0]['settings']['image']['url'], 'Localized Elementor image URL was not resolved' );
verify( $original === get_post_meta( 41, '_elementor_data', true ) && '7' === get_post_meta( 41, '_thumbnail_id', true ), 'Localized media changed the native source' );
$renderer = new Nova_WordPress_Weglot_Renderer( new Nova_WordPress_Weglot(), new Nova_WordPress_Weglot() );
$renderer->resolve_payload();
verify( '100' === get_post_meta( 41, '_thumbnail_id', true ), 'German frontend did not use its localized featured image' );
$hooks = []; $visitor = 'en';
$renderer = new Nova_WordPress_Weglot_Renderer( new Nova_WordPress_Weglot(), new Nova_WordPress_Weglot() );
$renderer->resolve_payload();
verify( '7' === get_post_meta( 41, '_thumbnail_id', true ), 'English frontend received the German image' );
$hooks = []; $visitor = 'de';
$plan2 = success( image_plan( 2 ) );
$data[41]['_thumbnail_id'] = ['8'];
verify( is_wp_error( Writer::apply( $plan2, $plan2['operation_id'] ) ), 'Changed original image was not detected' );
$data[41]['_thumbnail_id'] = ['7'];
$compiled_layout['writer_payload']['nova_media']['/native_post/featured_image'] = [ 'kind'=>'featured', 'url'=>'', 'clear'=>true ];
unset( $compiled_layout['writer_payload']['nova_media']['/document/0/settings/image/url'] );
$clear = success( image_plan( 2 ) );
success( Writer::apply( $clear, $clear['operation_id'] ) );
verify( '0' === Nova_WordPress_Weglot::get( 41, 'de' )['meta']['_thumbnail_id'] && '7' === get_post_meta( 41, '_thumbnail_id', true ), 'Localized clear changed the source image' );
$unsupported = Nova_WordPress_Translation_Fields::compile( ['site_id'=>'site','url_id'=>'media-url'], $config, ['provider'=>'translatepress','post_id'=>41,'post_type'=>'page'] );
verify( is_wp_error( $unsupported ) && 'nova_translation_field_unsupported' === $unsupported->get_error_code(), 'Unsupported provider silently accepted native media' );
$featured_config = $config;
$featured_config['local']['stored_layout']['bindings'] = [ [ 'kind'=>'media', 'mode'=>'adapt', 'native_media'=>['kind'=>'featured'] ] ];
$compiled_layout = [ 'builder'=>'core', 'post_fields'=>[], 'writer_payload'=>[ 'content'=>'Retained source layout', 'nova_media'=>[
    '/native_post/featured_image'=>[ 'kind'=>'featured', 'url'=>'https://images.example.test/featured.png' ],
] ] ];
$featured_fields = success( Nova_WordPress_Translation_Fields::compile( ['site_id'=>'site','url_id'=>'media-url'], $featured_config, ['provider'=>'weglot','post_id'=>41,'post_type'=>'page'] ) );
verify( isset( $featured_fields['native_media'] ) && ! isset( $featured_fields['content'], $featured_fields['meta'] ), 'A featured-only mapping copied the source document into the translation' );
echo "Localized image import, recovery, rendering and source-preservation checks passed.\n";
