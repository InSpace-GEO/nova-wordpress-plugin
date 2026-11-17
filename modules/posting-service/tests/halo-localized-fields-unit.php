<?php
use Nova_Bridge_Suite_Translation_Writer as Writer;
/** Typed HALO translations use native storage aliases for both source-shared providers. */
if ( ! isset( $argv[1] ) ) {
    foreach ( [ 'translatepress', 'weglot' ] as $provider ) {
        passthru( escapeshellarg( PHP_BINARY ) . ' -n ' . escapeshellarg( __FILE__ ) . ' ' . $provider, $status );
        if ( $status ) { exit( $status ); }
    }
    return;
}
function get_registered_meta_keys( $kind, $type = '' ) { return $GLOBALS['halo_registry'][$type] ?? []; }
function metadata_exists( $kind, $id, $key ) { return isset( $GLOBALS['data'][$id][$key] ); }
function wp_is_numeric_array( $value ) { return is_array( $value ) && array_is_list( $value ); }
function wp_attachment_is_image( $id ) { return 23 === $id || isset( $GLOBALS['attachment_posts'][$id] ); }
function wp_http_validate_url( $url ) { return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : false; }
function add_option( $key, $value, ...$unused ) { $GLOBALS['associations'][$key] = $value; return true; }
function update_option( $key, $value, ...$unused ) { $GLOBALS['associations'][$key] = $value; return true; }
function remove_filter( $name, $callback, ...$unused ) { $GLOBALS['hooks'][$name] = array_filter( $GLOBALS['hooks'][$name] ?? [], static fn( $fn ) => $fn !== $callback ); }
function wp_tempnam( $name ) { return tempnam( sys_get_temp_dir(), 'nova-halo-media-' ); }
function wp_safe_remote_get( $url, $args ) {
    ++$GLOBALS['media_downloads'];
    file_put_contents( $args['filename'], base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6Y6IAAAAASUVORK5CYII=' ) );
    return ['status'=>200];
}
function wp_remote_retrieve_response_code( $reply ) { return $reply['status']; }
function wp_get_attachment_metadata( $id ) { return ['width'=>1,'height'=>1]; }
function wp_generate_attachment_metadata( $id, $file ) { return ['width'=>1,'height'=>1]; }
function media_handle_sideload( $file, $parent, $description, $data ) {
    $id = 100 + count( $GLOBALS['attachment_posts'] ?? [] );
    $GLOBALS['attachment_posts'][$id] = (object) ['ID'=>$id,'post_type'=>'attachment','post_parent'=>$parent,'post_content_filtered'=>$data['post_content_filtered']];
    throw new RuntimeException( 'Lost import response' );
}
require __DIR__ . '/localized-custom-fields-unit.php';
require_once $core . '/rest-api.php';
$hooks = []; $halo_registry = [];
foreach ( [ 'seo_title_text'=>'string', 'seo_meta_description_text'=>'string', 'article_faq_questions'=>'array',
    'display_intake_form_boolean'=>'boolean', 'service_intro_rich_text'=>'string', 'optional_second_image_id'=>'integer' ] as $name => $type ) {
    $schema = [ 'type'=>$type, 'context'=>['edit'] ];
    if ( 'array' === $type ) {
        $schema['items'] = [ 'type'=>'object', 'properties'=>[ 'question'=>['type'=>'string'], 'answer'=>['type'=>'string'] ], 'additionalProperties'=>false ];
    }
    $halo_registry['page']['_local_' . $name] = [ 'type'=>$type, 'single'=>true, 'show_in_rest'=>['name'=>$name,'schema'=>$schema] ];
}
$halo_registry['post'] = $halo_registry['page'];
$values = [ 'seo_title_text'=>'Lokaler Suchtitel', 'seo_meta_description_text'=>'Lokale Beschreibung',
    'article_faq_questions'=>[['question'=>'Lokale Frage?', 'answer'=>'<p>Eine genaue <strong>Antwort.</strong></p>']],
    'display_intake_form_boolean'=>false, 'service_intro_rich_text'=>'<h2>Einführung</h2><p>Lokaler Einstieg.</p>', 'optional_second_image_id'=>23 ];
$halo_fields = Nova_WordPress_Halo_Fields::catalog( 'page' );
foreach ( $halo_fields as $name => &$field ) {
    $field['value'] = $values[$name];
    $data[41][$field['key']] = [ 'Source ' . $name ];
}
unset( $field );
$original_halo = array_intersect_key( $data[41], array_flip( array_column( $halo_fields, 'key' ) ) );
$seo_plugins = [];
publish_locale( 'de_DE', 4, $halo_fields );
request_locale( 'de_DE' );
foreach ( $halo_fields as $name => $field ) {
    verify( $values[$name] === get_post_meta( 41, $field['key'], true ), 'HALO native alias lost its typed localized value: ' . $name );
    verify( '' === get_post_meta( 41, $name, true ), 'A HALO public alias became a storage key' );
}
$all = get_post_meta( 41 );
verify( $all['_local_article_faq_questions'] === [serialize($values['article_faq_questions'])], 'HALO bulk metadata lost native array serialization' );
$html = '<html><head><title>Lokaler Suchtitel</title><meta name="description" content="Lokale Beschreibung"></head><body><nav>Lokaler Einstieg.</nav><main>' .
    $values['service_intro_rich_text'] . '<h3>Lokale Frage?</h3>' . $values['article_faq_questions'][0]['answer'] . '</main></body></html>';
$protected = apply_filters( $pre, $html );
verify( substr_count( $protected, 'data-no-translation' ) === 7, 'HALO nested rich text or SEO was exposed to double translation' );
verify( str_contains( $protected, '<nav>Lokaler Einstieg.</nav>' ), 'HALO protection changed navigation' );
$restored = apply_filters( $post_hook, str_replace( 'Lokaler Suchtitel', 'Wrong title', $protected ) );
verify( str_contains( $restored, 'Lokaler Suchtitel' ) && ! str_contains( $restored, 'Wrong title' ), 'HALO SEO lost its native localized value' );
request_locale( 'en_US' );
verify( 'Source article_faq_questions' === get_post_meta( 41, '_local_article_faq_questions', true ), 'HALO translation changed source metadata' );
$clear = $halo_fields; $clear['article_faq_questions']['value'] = []; $clear['optional_second_image_id']['value'] = 0;
publish_locale( 'de_DE', 5, $clear ); request_locale( 'de_DE' );
verify( [] === get_post_meta( 41, '_local_article_faq_questions', true ) && 0 === get_post_meta( 41, '_local_optional_second_image_id', true ), 'HALO explicit list or media clear was dropped' );
$hooks = [];
verify( $original_halo === array_intersect_key( $data[41], $original_halo ), 'HALO source values were modified by dictionary publication' );
$import = $halo_fields['optional_second_image_id']; $import['value'] = 'https://images.example.test/localized.jpg';
$content = [ 'site_id'=>'site', 'url_id'=>'url', 'url'=>'/de/entry/', 'language'=>'de_DE', 'version_number'=>6,
    'configuration'=>$config['template'], 'content_item_id'=>'item', 'destination_values'=>['optional_second_image_id'=>$import] ];
$wpdb = new class {
    public $posts = 'posts';
    public function get_var( $query ) { return '1'; }
    public function prepare( $query, ...$args ) { return [$query,$args]; }
    public function get_col( $query ) {
        return array_keys( array_filter( $GLOBALS['attachment_posts'] ?? [], static fn( $post ) => $post->post_content_filtered === $query[1][1] ) );
    }
};
$media_downloads = 0;
$operation = 'halo-image-retry';
$plan = success( Writer::plan( $content, $config, ['target_post_id'=>41,'desired_status'=>'publish','operation_id'=>$operation] ) );
verify( is_wp_error( Writer::apply( $plan, $operation ) ), 'An unavailable HALO localized image was reported as committed' );
verify( 'not_committed' === success( Writer::recover( $plan, $operation ) )['state'], 'Failed image import lost recoverable progress' );
verify( 1 === $media_downloads && count( $attachment_posts ) === 1, 'HALO import did not retain its interrupted attachment' );
success( Writer::apply( $plan, $operation ) );
verify( 'complete' === success( Writer::recover( $plan, $operation ) )['state'], 'HALO image retry failed verification' );
request_locale( 'de_DE' );
verify( 100 === get_post_meta( 41, '_local_optional_second_image_id', true ), 'Localized HALO image exposed its URL instead of an attachment ID' );
verify( 1 === $media_downloads && count( $attachment_posts ) === 1, 'HALO image retry downloaded another attachment' );
$hooks = [];
verify( $original_halo === array_intersect_key( $data[41], $original_halo ), 'HALO image import changed the source fields' );
$native_plan = ['post_type'=>'page', 'custom_template'=>'service.php', 'operation_id'=>$operation, 'custom_fields'=>['optional_second_image_id'=>$import]];
$data[41]['_local_optional_second_image_id'] = ['100'];
verify( true === Nova_Bridge_Suite_Stored_Custom_Fields::finish( $native_plan, 41, [], false ), 'Native HALO image readback failed for its retained attachment' );
unset( $attachment_posts[100] );
verify( is_wp_error( Nova_Bridge_Suite_Stored_Custom_Fields::finish( $native_plan, 41, [], false ) ) && 1 === $media_downloads,
    'Native HALO verification accepted a missing image or downloaded a replacement' );
$data[41]['_local_optional_second_image_id'] = $original_halo['_local_optional_second_image_id'];
verify( is_wp_error( Writer::verify( $plan, [] ) ) && 1 === $media_downloads, 'HALO verification accepted a missing image or downloaded a replacement' );
$halo_registry['page']['_local_service_intro_rich_text']['show_in_rest']['schema']['maxLength'] = 3;
request_locale( 'de_DE' );
verify( 'Source service_intro_rich_text' === get_post_meta( 41, '_local_service_intro_rich_text', true ), 'HALO schema drift did not stop stale rendering' );
echo "$provider typed HALO checks: $checks passed.\n";
