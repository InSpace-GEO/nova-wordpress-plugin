<?php
ini_set( 'memory_limit', '512M' );
$_SERVER['HTTPS'] = 'on';
// Run only against a disposable WordPress installation with WooCommerce, ACF and SEOPress installed.
$bootstrap = getenv( 'NOVA_WP_BOOTSTRAP' );
if ( ! $bootstrap || ! is_file( $bootstrap ) ) { throw new RuntimeException( 'Set NOVA_WP_BOOTSTRAP to the disposable installation wp-load.php.' ); }
require $bootstrap;
if ( wp_get_environment_type() !== 'local' ) { throw new RuntimeException( 'This mutating integration test requires a disposable local installation.' ); }
require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user( 1 );
$simulated_connection = [ 'base_url' => 'https://posting.fixture.invalid', 'site_id' => wp_generate_uuid4(), 'layout_legacy_environment' => str_repeat( '0', 64 ) ];
add_filter( 'pre_option_nova_bridge_posting_connection', static function () use ( $simulated_connection ) { return $simulated_connection; } );
add_filter( 'home_url', static function ( $url ) { return preg_replace( '#^http://#', 'https://', $url ); } );
function ensure( $value, string $message = '' ) {
    if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); }
    if ( ! $value ) { throw new RuntimeException( $message ); }
    return $value;
}
add_filter( 'pre_http_request', static function ( $pre ) { return false === $pre ? new WP_Error( 'offline', 'Simulation only.' ) : $pre; }, 99 );
foreach ( [ 'woocommerce/woocommerce.php', 'advanced-custom-fields/acf.php', 'wp-seopress/seopress.php' ] as $plugin ) { $activation = activate_plugin( $plugin ); if ( is_wp_error( $activation ) ) { ensure( $activation ); } }
// Load newly activated plugins on this first CLI run as well.
foreach ( [ 'woocommerce/woocommerce.php', 'advanced-custom-fields/acf.php', 'wp-seopress/seopress.php' ] as $plugin ) { require_once WP_PLUGIN_DIR . '/' . $plugin; }
if ( ! taxonomy_exists( 'product_cat' ) ) { WC_Post_Types::register_taxonomies(); }
acf_add_local_field_group( [ 'key' => 'group_grail_category', 'title' => 'Grail category content', 'fields' => [
    [ 'key' => 'field_grail_seo_text', 'name' => 'seo_text', 'label' => 'SEO Text', 'type' => 'wysiwyg' ],
    [ 'key' => 'field_grail_protected', 'name' => 'protected_copy', 'label' => 'Protected Copy', 'type' => 'text' ],
], 'location' => [ [ [ 'param' => 'taxonomy', 'operator' => '==', 'value' => 'product_cat' ] ] ] ] );
update_option( 'woocommerce_permalinks', [ 'category_base' => 'shop' ] );
global $wp_taxonomies; $wp_taxonomies['product_cat']->rewrite['slug'] = 'shop';
update_option( 'permalink_structure', '/%postname%/' );
$term = get_term_by( 'slug', 'omega-3-supplements', 'product_cat' );
if ( ! $term ) { $created = ensure( wp_insert_term( 'Omega 3 Supplements', 'product_cat', [ 'slug' => 'omega-3-supplements' ] ) ); $term = get_term( $created['term_id'], 'product_cat' ); }
$id = $term->term_id;
ensure( wp_update_term( $id, 'product_cat', [ 'name' => 'Omega 3 Supplements', 'description' => '<p>Shop premium omega 3 supplements with EPA and DHA.</p>' ] ) );
update_field( 'field_grail_seo_text', '<h2>What matters most when buying omega 3</h2><p>Existing lower content.</p>', 'product_cat_' . $id );
update_field( 'field_grail_protected', 'Protected ACF content', 'product_cat_' . $id );
foreach ( [ '_seopress_titles_title' => 'Omega 3 Supplements | Premium Fish Oil', '_seopress_titles_desc' => 'Premium omega 3 with clear EPA and DHA dosages.', 'wpseo_title' => 'Protected legacy SEO', '_nova_weglot_languages' => [ 'de' ], '_nova_weglot_i18n_de' => [ 'name' => 'Geschützter Inhalt', 'source_term_id' => $id ] ] as $key => $value ) { update_term_meta( $id, $key, $value ); }
function api( string $action, array $body ): array {
    $r = new WP_REST_Request( 'POST', '/nova-bridge/v1/mapping/layout-recipes/' . $action );
    $r->set_header( 'content-type', 'application/json' ); $r->set_body( wp_json_encode( $body ) );
    $reply = rest_do_request( $r ); $data = $reply->get_data();
    ensure( $reply->get_status() === 200, wp_json_encode( $data ) ); return $data;
}
$d = api( 'design', [ 'builder' => 'core', 'mode' => 'static', 'resource_id' => 'taxonomy:product_cat', 'reference_id' => $id,
    'declared_term_fields' => [ '_seopress_titles_title' => [ 'type' => 'string' ], '_seopress_titles_desc' => [ 'type' => 'string' ] ] ] );
echo 'Captured fields: ' . implode( ', ', array_column( $d['fields'], 'label' ) ) . "\n";
$recipe = $d['recipe_preset'];
$keep = [];
foreach ( $recipe['bindings'] as $b ) {
    $target = $b['target']; $label = $b['label'] ?? '';
    $role = $target === '/document/title' ? 'title' : ( $target === '/document/sections/0/body' ? 'intro' : ( strpos( $label, '_seopress_titles_title' ) !== false ? 'seo_title' : ( strpos( $label, '_seopress_titles_desc' ) !== false ? 'meta_description' : null ) ) );
    if ( ! $role ) { continue; }
    $b['id'] = 'nova_role_' . $role . '_0123456789abcdef'; $b['required'] = false; $keep[] = $b;
}
$recipe['bindings'] = $keep;
ensure( count( $keep ) === 4, 'Expected the four user-selected core and SEO fields.' );
$recipe['custom_fields'] = $d['custom_fields_design']['recipe_preset'];
$recipe['custom_fields']['bindings'] = array_values( array_filter( $recipe['custom_fields']['bindings'], static function ( $b ) { return $b['target'] === '/document/seo_text'; } ) );
ensure( count( $recipe['custom_fields']['bindings'] ) === 1, 'Expected SEO Text ACF companion.' );
$recipe['custom_fields']['bindings'][0]['required'] = false;
$content = array_replace_recursive( $d['sample'], $d['custom_fields_design']['sample'] );
$saved = api( 'save', [ 'recipe' => $recipe, 'seal' => $d['seal'], 'acf_seal' => $d['custom_fields_design']['seal'], 'resource_id' => 'taxonomy:product_cat', 'expected_revision' => 0, 'expected_default' => Nova_Bridge_Suite_Layout_Recipes::defaults()['taxonomy:product_cat'] ?? null, 'label' => 'Grail Formula category', 'content' => $content ] );
$record = ensure( Nova_Bridge_Suite_Layout_Recipes::delivery_record( $saved['id'], $saved['revision'] ) );
$projection = ensure( Nova_Bridge_Suite_Static_Recipe_Adapter::project_record( $record ) );
ensure( count( $projection['input']['definition']['fields'] ) === 5, 'Expected five selected V3 destinations.' );
echo "Saved and projected five fields.\n";
// The simulated posting service checks the real bundled wire schema and ETags.
class CategoryService extends Nova_Bridge_Suite_Posting_Client {
    public $template = null; public $defaults = []; public $revision = 1; public $requests = [];
    public function site_request( string $method, string $path, $body = null, array $headers = [] ) {
        $this->requests[] = [ $method, $path ];
        if ( $path === '/template-defaults' ) {
            if ( $method === 'PUT' ) { ensure( $headers['If-Match'] === '"' . $this->revision . '"', 'Default ETag' ); $this->defaults = $body['defaults']; ++$this->revision; }
            return [ 'status' => 200, 'etag' => '"' . $this->revision . '"', 'body' => [ 'revision' => $this->revision, 'defaults' => $this->defaults ] ];
        }
        if ( $method === 'POST' || $method === 'PUT' ) {
            ensure( Nova_Bridge_Suite_Posting_Protocol::validate( $body, 'PublishingTemplateInput' ) );
            if ( $method === 'PUT' ) { ensure( $headers['If-Match'] === '"' . $this->template['revision'] . '"', 'Template ETag' ); }
            $this->template = $body + [ 'id' => '00000000-0000-4000-8000-000000000111', 'revision' => ( $this->template['revision'] ?? 0 ) + 1, 'retired_at' => null ];
        }
        return [ 'status' => $method === 'POST' ? 201 : 200, 'etag' => '"' . $this->template['revision'] . '"', 'body' => $this->template ];
    }
}
$connection = $simulated_connection + [ 'token' => 'synthetic-test-token-only', 'enabled' => true, 'paused' => false, 'actor_user_id' => 1 ];
$client = new CategoryService( $connection );
$sync = Nova_Bridge_Suite_Static_Recipe_Adapter::sync_editor( $connection, static function () use ( $saved ) { return $saved; }, false, $client );
ensure( ( $sync['posting_sync']['status'] ?? '' ) === 'synced', wp_json_encode( $sync['posting_sync'] ?? $sync ) );
$again = Nova_Bridge_Suite_Static_Recipe_Adapter::sync_editor( $connection, static function () use ( $saved ) { return $saved; }, false, $client );
ensure( count( array_filter( $client->requests, static function ( $r ) { return $r[0] === 'POST'; } ) ) === 1, 'Sync retry duplicated template.' );
echo "Template sync and idempotent retry passed.\n";
$saved = api( 'save', [ 'id' => $saved['id'], 'recipe' => $recipe, 'seal' => $d['seal'], 'acf_seal' => $d['custom_fields_design']['seal'], 'resource_id' => 'taxonomy:product_cat', 'expected_revision' => $saved['revision'], 'expected_default' => Nova_Bridge_Suite_Layout_Recipes::defaults()['taxonomy:product_cat'], 'label' => 'Grail Formula category revision 2', 'content' => $content ] );
$sync = Nova_Bridge_Suite_Static_Recipe_Adapter::sync_editor( $connection, static function () use ( $saved ) { return $saved; }, false, $client );
ensure( ( $sync['posting_sync']['status'] ?? '' ) === 'synced' && $sync['posting_sync']['recipe_revision'] === 2 && $sync['posting_sync']['template_revision'] === 2, 'Saved revision 2 failed to sync.' );
$record = ensure( Nova_Bridge_Suite_Layout_Recipes::delivery_record( $saved['id'], $saved['revision'] ) );
$projection = ensure( Nova_Bridge_Suite_Static_Recipe_Adapter::project_record( $record ) );
echo "Saved revision 2 updated the same remote template.\n";
require __DIR__ . '/contract-fixture.php';
$f = nova_contract_fixture(); $snapshot = $f['snapshot'];
$snapshot['site_id'] = $connection['site_id'];
$snapshot['configuration'] = array_intersect_key( $client->template, array_flip( [ 'id', 'name', 'page_type', 'definition', 'mapping', 'revision' ] ) );
$snapshot['url'] = get_term_link( $id, 'product_cat' ); $snapshot['page_type_frontend'] = 'category';
$profile = ensure( Nova_Bridge_Suite_Static_Recipe_Adapter::configuration_for_snapshot( $snapshot, $connection['site_id'] ) );
$snapshot['kind'] = 'adaptation.delivery'; $snapshot['schema_version'] = 2; $snapshot['destination_values'] = [];
foreach ( $projection['bindings'] as $path => $spec ) {
    $value = $spec['scope'] === 'acf' ? '<h2>Choosing omega 3</h2><p>New bottom category content.</p>' : ( $path === '/document/title' ? 'Omega 3 Supplements' : ( $path === '/document/sections/0/body' ? '<h2>Premium omega 3</h2><p>New top category content.</p>' : ( strpos( $record['recipe']['blueprint']['term_fields']['declarations'][basename( $path )]['key'] ?? '', '_desc' ) !== false ? 'New omega 3 SEO description.' : 'New Omega 3 SEO Title' ) ) );
    $snapshot['destination_values'][Nova_Bridge_Suite_Writing_Adapter::field_id( $path )] = $value;
}
$context = [ 'operation_id' => wp_generate_uuid4(), 'site_id' => $connection['site_id'], 'operation' => 'update', 'desired_status' => 'publish', 'target_post_id' => $id ];
$plan = ensure( Nova_Bridge_Suite_Static_Term_Writer::plan( $snapshot, $profile, $context ) );
$result = ensure( Nova_Bridge_Suite_Static_Term_Writer::apply( $plan, $context['operation_id'] ) );
ensure( Nova_Bridge_Suite_Static_Term_Writer::verify( $plan, $result ) );
ensure( Nova_Bridge_Suite_Static_Term_Writer::recover( $plan, $context['operation_id'] ) );
ensure( get_field( 'protected_copy', 'product_cat_' . $id, false ) === 'Protected ACF content', 'Unselected ACF changed.' );
ensure( get_term_meta( $id, 'wpseo_title', true ) === 'Protected legacy SEO' && get_term_meta( $id, '_nova_weglot_languages', true ) === [ 'de' ], 'Protected metadata changed.' );
ensure( strpos( get_field( 'seo_text', 'product_cat_' . $id, false ), 'New bottom' ) !== false, 'Bottom ACF did not update.' );
echo "Five-field native update, readback, recovery and protected metadata passed.\n";

// Exercise public V3 delivery through the production HTTP client and worker.
class CategoryJobStore {
    public $saved; public $locks = [];
    public function save( $job, $changes ) { return $this->saved = array_merge( $job, $changes ); }
    public function lock( $key ) { ensure( ! isset( $this->locks[$key] ), 'Duplicate lock' ); $this->locks[$key] = true; return true; }
    public function unlock( $key ) { unset( $this->locks[$key] ); }
    public function uncertain_target( $job ) { return false; }
}
class CategoryDeliveryService {
    public $fixture; public $events = []; public $lose_success = false;
    public function request( $pre, $args, $url ) {
        if ( strpos( $url, 'https://posting.fixture.invalid/' ) !== 0 ) { return $pre; }
        if ( $args['method'] === 'GET' ) {
            return [ 'response' => [ 'code' => 200 ], 'headers' => [ 'etag' => $this->fixture['etag'], 'x-nova-attempt-id' => $this->fixture['attempt_id'] ], 'body' => $this->fixture['raw'] ];
        }
        $event = json_decode( $args['body'], true );
        ensure( Nova_Bridge_Suite_Posting_Protocol::event_input( $event ) );
        $this->events[] = $event;
        if ( $event['kind'] === 'publication_succeeded' && $this->lose_success ) { $this->lose_success = false; return new WP_Error( 'http_request_failed', 'Simulated lost acknowledgement.' ); }
        return [ 'response' => [ 'code' => 202 ], 'headers' => [], 'body' => wp_json_encode( nova_contract_fixture_event_accept( $args['body'] ) ) ];
    }
}
function deliver_category( array $snapshot, array $connection, bool $lose_success = false ): array {
    unset( $snapshot['kind'], $snapshot['schema_version'], $snapshot['destination_values'], $snapshot['attempt_id'] );
    ensure( Nova_Bridge_Suite_Posting_Protocol::validate( json_decode( wp_json_encode( $snapshot ) ), 'Delivery' ) );
    $f = nova_contract_fixture( [ 'content' => $snapshot, 'configuration' => [ 'site_id' => $connection['site_id'] ], 'context' => [ 'operation_id' => wp_generate_uuid4() ] ] );
    $f['raw'] = wp_json_encode( $snapshot ); $f['etag'] = '"' . hash( 'sha256', $f['raw'] ) . '"';
    $server = new CategoryDeliveryService(); $server->fixture = $f; $server->lose_success = $lose_success;
    add_filter( 'pre_http_request', [ $server, 'request' ], 1, 3 );
    $store = new CategoryJobStore();
    $worker = new Nova_Bridge_Suite_Posting_Worker( $connection, $store );
    try {
        $result = ensure( $worker->process( $f['job'] ) );
        if ( $lose_success ) {
            ensure( $result['state'] === 'ready' && isset( $result['payload']['result']['term_id'] ), wp_json_encode( $result ) );
            $before = wp_count_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
            $result['state'] = 'running'; $result = ensure( $worker->process( $result ) );
            ensure( wp_count_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] ) === $before, 'Receipt retry created another category.' );
        }
        ensure( $result['state'] === 'complete' && empty( $result['last_error'] ), wp_json_encode( $result ) );
        ensure( end( $server->events )['kind'] === 'publication_succeeded', wp_json_encode( $server->events ) );
        ensure( $store->locks === [], 'Worker retained a lock.' );
        return $result;
    } finally { remove_filter( 'pre_http_request', [ $server, 'request' ], 1 ); }
}
$wire = $snapshot;
$wire['configuration'] = ensure( Nova_Bridge_Suite_Posting_Protocol::public_configuration( $profile['template'] ) );
$wire['content'] = $snapshot['destination_values'];
$worker_update = deliver_category( $wire, $connection );
ensure( $worker_update['target_id'] === $id, 'Worker selected a different category.' );
$wire['id'] = wp_generate_uuid4(); $wire['url_id'] = '99';
$wire['url'] = home_url( '/shop/nova-test-' . strtolower( wp_generate_password( 8, false ) ) . '/' );
$created = deliver_category( $wire, $connection, true );
$new_id = $created['target_id'];
ensure( $new_id !== $id && get_field( 'seo_text', 'product_cat_' . $new_id, false ) !== '', 'Created category lost ACF content.' );
ensure( get_term_meta( $new_id, '_seopress_titles_title', true ) === 'New Omega 3 SEO Title', 'Created category lost SEO title.' );
ensure( ! metadata_exists( 'term', $new_id, 'protected_copy' ) && ! metadata_exists( 'term', $new_id, '_nova_weglot_languages' ), 'New category copied protected example values.' );
echo "Public delivery worker update, creation and lost-receipt recovery passed.\n";

// Another administrator changing a selected value after planning must win.
$context['operation_id'] = wp_generate_uuid4();
$concurrent = ensure( Nova_Bridge_Suite_Static_Term_Writer::plan( $snapshot, $profile, $context ) );
update_field( 'field_grail_seo_text', '<p>Administrator edit</p>', 'product_cat_' . $id );
$rejected = Nova_Bridge_Suite_Static_Term_Writer::apply( $concurrent, $context['operation_id'] );
ensure( is_wp_error( $rejected ) && $rejected->get_error_code() === 'nova_static_category_changed', 'Concurrent edit was overwritten.' );
echo "Concurrent selected-field edit preserved.\n";
$context['operation_id'] = wp_generate_uuid4();
$unselected = ensure( Nova_Bridge_Suite_Static_Term_Writer::plan( $snapshot, $profile, $context ) );
update_field( 'field_grail_protected', 'New unrelated administrator edit', 'product_cat_' . $id );
ensure( Nova_Bridge_Suite_Static_Term_Writer::apply( $unselected, $context['operation_id'] ) );
ensure( get_field( 'protected_copy', 'product_cat_' . $id, false ) === 'New unrelated administrator edit', 'Unselected concurrent change was lost.' );
echo "Unselected concurrent field edit preserved during successful delivery.\n";

// Render through WooCommerce and the simulated site's ACF lower-content hook.
$GLOBALS['wp_query'] = new WP_Query();
$GLOBALS['wp_query']->is_tax = true;
$GLOBALS['wp_query']->queried_object = get_term( $new_id, 'product_cat' );
$GLOBALS['wp_query']->queried_object_id = $new_id;
add_action( 'woocommerce_after_shop_loop', static function () use ( $new_id ) { echo wp_kses_post( get_field( 'seo_text', 'product_cat_' . $new_id ) ); } );
ob_start(); woocommerce_taxonomy_archive_description(); do_action( 'woocommerce_after_shop_loop' ); $html = ob_get_clean();
ensure( strpos( $html, 'New top category content' ) !== false && strpos( $html, 'New bottom category content' ) !== false, 'Rendered category lost top or bottom content.' );
echo "WooCommerce description and simulated ACF lower-content rendering passed.\n";
echo 'Versions: WordPress ' . get_bloginfo( 'version' ) . ', WooCommerce ' . WC_VERSION . ', ACF ' . ACF_VERSION . ', SEOPress ' . SEOPRESS_VERSION . "\n";
