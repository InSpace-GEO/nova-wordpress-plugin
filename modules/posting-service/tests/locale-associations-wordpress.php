<?php
/** wp --user=1 eval-file ...; run only in a task-owned disposable installation. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || getenv( 'NOVA_DISPOSABLE_WORDPRESS' ) !== '1' || home_url() !== 'https://bridge-locale.test' ) {
    throw new RuntimeException( 'This proof requires the dedicated disposable WordPress installation.' );
}
$root = dirname( __DIR__, 3 );
require_once $root . '/modules/api-mapping-context/includes/class-nova-bridge-suite-document-mapping.php';
require_once $root . '/modules/api-mapping-context/includes/class-nova-bridge-suite-mapping-drafts.php';
foreach ( [ 'posting-protocol', 'writing-adapter', 'mapped-content-target', 'stored-layout', 'stored-layout-language', 'stored-layout-writer', 'owned-cpt-writer' ] as $file ) {
    require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-' . $file . '.php';
}
update_option( 'permalink_structure', '/%postname%/' );
global $wp_rewrite; $wp_rewrite->init();
foreach ( [ 'service_page', 'blog' ] as $type ) {
    register_post_type( $type, [ 'public' => true, 'show_in_rest' => true, 'supports' => [ 'title', 'editor', 'custom-fields' ], 'rewrite' => [ 'slug' => $type ] ] );
    register_post_meta( $type, $type === 'blog' ? 'blog_intro' : 'sp_intro', [ 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'auth_callback' => static function () { return current_user_can( 'edit_posts' ); } ] );
}
rest_get_server();
$checked = static function ( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; };
$checks = [];
$assert = static function ( bool $condition, string $message ) use ( &$checks ) { if ( ! $condition ) { throw new RuntimeException( $message ); } $checks[] = $message; };
$digest = static function ( $value ) { return hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $value ) ); };
$results = [];
foreach ( [ 'service_page', 'blog', 'core', 'gutenberg' ] as $provider ) {
    $stored = in_array( $provider, [ 'core', 'gutenberg' ], true );
    $writer = $stored ? Nova_Bridge_Suite_Stored_Layout_Writer::class : Nova_Bridge_Suite_Owned_CPT_Writer::class;
    $prefix = $stored ? 'nova_layout_page_' : 'nova_cpt_url_';
    $op_prefix = $stored ? 'nova_layout_operation_' : 'nova_cpt_operation_';
    $site = wp_generate_uuid4(); $template_id = wp_generate_uuid4();
    $suffix = substr( str_replace( '-', '', $site ), 0, 8 );
    if ( $stored ) {
        $target = $provider === 'core' ? '/document/sections/0/body' : '/document/0/innerHTML';
        $blueprint = $provider === 'core' ? [ 'document' => [ 'title' => 'Example', 'sections' => [ [ 'body' => '<p>Old body</p>' ] ], 'faqs' => [] ], 'type' => 'post_type', 'post_type' => 'page', 'resource_id' => 'post_type:page', 'template' => '' ] : [ 'document' => parse_blocks( '<!-- wp:paragraph --><p>Old body</p><!-- /wp:paragraph -->' ), 'template' => '' ];
        $local = $checked( Nova_Bridge_Suite_Stored_Layout::from_recipe( [ 'id' => wp_generate_uuid4(), 'revision' => 1, 'resource_id' => 'post_type:page', 'builder' => $provider, 'label' => 'Disposable locale proof', 'recipe' => [ 'builder' => $provider, 'mode' => 'static', 'blueprint' => $blueprint, 'bindings' => [ [ 'target' => $target, 'source' => '/body', 'format' => 'html', 'required' => true ] ], 'regions' => [] ] ] ) );
        $local['routing']['publication'] = 'publish';
    } else {
        $target = '/meta/' . ( $provider === 'blog' ? 'blog_intro' : 'sp_intro' );
        $local = [ 'resource_id' => 'post_type:' . $provider, 'routing' => [ 'post_type' => $provider, 'operation' => 'create', 'publication' => 'publish' ], 'fields' => [] ];
        foreach ( [ '/title' => 'text', $target => 'rich_text' ] as $path => $type ) { $local['fields'][Nova_Bridge_Suite_Writing_Adapter::field_id( $path )] = [ 'target' => $path, 'value_type' => $type ]; }
    }
    $template = [ 'id' => $template_id, 'revision' => 1, 'definition' => [ 'version' => 3 ], 'mapping' => [ 'version' => 3, 'fields' => [] ] ];
    $configuration = [ 'mode' => $stored ? 'stored_layout_v3' : 'owned_cpt_v3', 'site_id' => $site, 'template' => $template, 'local' => $local, 'profile_digest' => $digest( $local ) ];
    $make = static function ( string $locale, int $version, string $path, string $url_id = '42' ) use ( $site, $template, $provider, $stored, $target, $suffix ): array {
        return [ 'kind' => 'adaptation.delivery', 'schema_version' => 2, 'site_id' => $site, 'url_id' => $url_id, 'content_item_id' => $locale === 'nl-NL' || $locale === 'NL_nl' ? '202' : '101', 'content_item_version_id' => (string) ( 500 + $version ), 'version_number' => $version, 'source_sha256' => str_repeat( 'a', 64 ), 'configuration' => $template, 'url' => home_url( '/' . ( $stored ? '' : $provider . '/' ) . $path . '-' . $suffix . '/' ), 'language' => $locale, 'destination_values' => [ Nova_Bridge_Suite_Writing_Adapter::field_id( '/title' ) => $provider . ' ' . $locale . ' version ' . $version, Nova_Bridge_Suite_Writing_Adapter::field_id( $target ) => '<p>Body ' . $locale . ' version ' . $version . '</p>' ] ];
    };
    $plan = static function ( array $content ) use ( $writer, $configuration, $checked ): array {
        $policy = $checked( $writer::policy( $content, $configuration ) );
        return $checked( $writer::plan( $content, $configuration, [ 'operation_id' => wp_generate_uuid4(), 'target_post_id' => $policy['reference']['reference_id'] ] ) );
    };
    $save = static function ( array $content ) use ( $writer, $plan, $checked ): array {
        $p = $plan( $content ); $result = $checked( $writer::apply( $p, $p['operation_id'] ) ); $checked( $writer::verify( $p, $result ) ); return $result;
    };
    $a = $save( $make( 'en-US', 1, $provider . '-english' ) );
    $collision = $make( 'nl-NL', 2, $provider . '-english' ); $original_title = get_post( $a['post_id'] )->post_title;
    $assert( is_wp_error( $writer::policy( $collision, $configuration ) ) && is_wp_error( $writer::plan( $collision, $configuration, [ 'operation_id' => wp_generate_uuid4() ] ) ) && get_post( $a['post_id'] )->post_title === $original_title, $provider . ': a second locale cannot adopt the same native URL' );
    $b = $save( $make( 'nl-NL', 1, $provider . '-dutch' ) );
    $assert( $a['post_id'] !== $b['post_id'] && get_post_status( $a['post_id'] ) === 'publish' && get_post_status( $b['post_id'] ) === 'publish', $provider . ': same URL ID, two locales, distinct published IDs' );
    $b_title = get_post( $b['post_id'] )->post_title;
    $a2 = $save( $make( 'EN_us', 2, $provider . '-english' ) );
    $assert( $a2['post_id'] === $a['post_id'] && get_post( $b['post_id'] )->post_title === $b_title, $provider . ': canonical English update leaves Dutch untouched' );
    $a_title = get_post( $a['post_id'] )->post_title;
    $b2 = $save( $make( 'NL_nl', 2, $provider . '-dutch' ) );
    $assert( $b2['post_id'] === $b['post_id'] && get_post( $a['post_id'] )->post_title === $a_title, $provider . ': canonical Dutch update leaves English untouched' );
    // Recreate the exact pre-upgrade option shape, retaining the genuine native page.
    $localized = $prefix . hash( 'sha256', $site . ':42:en-us' ); $legacy_key = $prefix . hash( 'sha256', $site . ':42' );
    $legacy = get_option( $localized ); update_option( $legacy_key, $legacy, false ); delete_option( $localized );
    $a3 = $save( $make( 'en-US', 3, $provider . '-english' ) );
    $assert( $a3['post_id'] === $a['post_id'] && get_option( $legacy_key ) === $legacy, $provider . ': matching legacy association upgrades without a duplicate or legacy mutation' );
    $a4 = $save( $make( 'en-US', 4, $provider . '-english' ) );
    $assert( $a4['post_id'] === $a['post_id'], $provider . ': migrated record takes precedence over legacy version' );

    if ( ! $stored ) {
        $adopt = $make( 'en-US', 1, $provider . '-adopted', '44' );
        $adopted_id = $checked( wp_insert_post( [ 'post_type' => $provider, 'post_status' => 'publish', 'post_title' => $adopt['destination_values'][Nova_Bridge_Suite_Writing_Adapter::field_id( '/title' )], 'post_name' => $provider . '-adopted-' . $suffix, 'post_content_filtered' => 'Unrelated native data' ], true ) );
        update_post_meta( $adopted_id, $provider === 'blog' ? 'blog_intro' : 'sp_intro', $adopt['destination_values'][Nova_Bridge_Suite_Writing_Adapter::field_id( $target )] );
        $competing = $adopt; $competing['language'] = 'nl-NL'; $competing['content_item_id'] = '202';
        $adoption_plan = $plan( $adopt ); $competing_plan = $plan( $competing );
        $assert( $adoption_plan['target_post_id'] === $adopted_id && $competing_plan['target_post_id'] === $adopted_id, $provider . ': two plans can inspect a genuinely unowned existing native page' );
        $adopted = $checked( $writer::apply( $adoption_plan, $adoption_plan['operation_id'] ) );
        $assert( $adopted['post_id'] === $adopted_id && get_post( $adopted_id )->post_content_filtered === 'Unrelated native data', $provider . ': first adoption preserves the existing ID and unrelated native marker data' );
        $assert( is_wp_error( $writer::apply( $competing_plan, $competing_plan['operation_id'] ) ) && get_post( $adopted_id )->post_title === $adopt['destination_values'][Nova_Bridge_Suite_Writing_Adapter::field_id( '/title' )], $provider . ': an already-planned competing locale cannot write after the first native claim' );
    }

    // Interrupt after WordPress persisted the reservation but before the ID reply.
    $pending = $plan( $make( 'en-US', 1, $provider . '-pending', '43' ) );
    $competing_pending = ! $stored ? $plan( $make( 'nl-NL', 1, $provider . '-pending', '43' ) ) : null;
    $marker = $writer::FORMAT . ':' . $pending['operation_id'] . ':' . $pending['plan_digest'];
    $lost = static function ( $id, $post, $update ) use ( $marker ) { if ( ! $update && $post->post_content_filtered === $marker ) { throw new RuntimeException( 'Disposable lost insert acknowledgement' ); } };
    add_action( 'wp_insert_post', $lost, PHP_INT_MAX, 3 );
    try { $interrupted = $writer::apply( $pending, $pending['operation_id'] ); } finally { remove_action( 'wp_insert_post', $lost, PHP_INT_MAX ); }
    $assert( is_wp_error( $interrupted ), $provider . ': lost insert acknowledgement remains uncertain' );
    if ( $competing_pending ) {
        $blocked = $writer::apply( $competing_pending, $competing_pending['operation_id'] );
        global $wpdb; $same_path = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d AND post_name = %s", $provider, $pending['target']['parent'], $pending['target']['slug'] ) );
        $assert( is_wp_error( $blocked ) && count( $same_path ) === 1, $provider . ': an already-planned competing creation inserts no second native draft' );
    }
    $pending_local = $prefix . hash( 'sha256', $site . ':43:en-us' ); $pending_legacy = $prefix . hash( 'sha256', $site . ':43' );
    $old_pending = get_option( $pending_local ); update_option( $pending_legacy, $old_pending, false ); delete_option( $pending_local );
    if ( ! $stored ) {
        $pending_collision = $make( 'nl-NL', 2, $provider . '-pending', '43' );
        $assert( $old_pending['post_id'] === 0 && is_wp_error( $writer::plan( $pending_collision, $configuration, [ 'operation_id' => wp_generate_uuid4() ] ) ), $provider . ': another locale cannot adopt a legacy reservation whose ID reply was lost' );
    }
    $replacement = $make( 'en-US', 2, $provider . '-pending', '43' );
    $assert( is_wp_error( $writer::plan( $replacement, $configuration, [ 'operation_id' => wp_generate_uuid4() ] ) ), $provider . ': unfinished matching legacy operation blocks replacement' );
    $other = $save( $make( 'nl-NL', 1, $provider . '-pending-dutch', '43' ) );
    $proof = $checked( $writer::recover( $pending, $pending['operation_id'] ) );
    $recovered = ( $proof['state'] ?? '' ) === 'not_committed' ? $checked( $writer::apply( $pending, $pending['operation_id'] ) ) : $proof;
    $checked( $writer::verify( $pending, $recovered ) );
    global $wpdb;
    $reserved = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_content_filtered = %s", $marker ) );
    $assert( count( $reserved ) === 1 && (int) $reserved[0] === $recovered['post_id'] && $other['post_id'] !== $recovered['post_id'] && get_option( $pending_legacy ) === $old_pending, $provider . ': original legacy reservation recovers once while another locale publishes' );
    $journal = get_option( $op_prefix . hash( 'sha256', $pending['operation_id'] ) );
    $assert( $journal['plan'] === $pending && $journal['plan_digest'] === $pending['plan_digest'], $provider . ': upgrade preserves the frozen operation plan and digest' );
    $results[$provider] = [ 'url_id' => '42', 'english_post_id' => $a['post_id'], 'dutch_post_id' => $b['post_id'], 'english_title' => get_post( $a['post_id'] )->post_title, 'dutch_title' => get_post( $b['post_id'] )->post_title, 'recovered_post_id' => $recovered['post_id'], 'independent_pending_locale_post_id' => $other['post_id'] ];
}
echo wp_json_encode( [ 'ok' => true, 'wordpress' => get_bloginfo( 'version' ), 'checks_passed' => count( $checks ), 'checks' => $checks, 'providers' => $results ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
