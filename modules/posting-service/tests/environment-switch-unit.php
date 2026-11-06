<?php
/** Connection lifecycle with transactional in-memory storage; no database or network access. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code; public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_generate_uuid4() { static $n = 0; return '00000000-0000-4000-8000-' . sprintf( '%012d', ++$n ); }
function wp_cache_delete( $key, $group ) { $GLOBALS['cache_deletes'][] = $key; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function delete_option( $key ) { unset( $GLOBALS['options'][$key] ); return true; }
function add_settings_error( ...$args ) { $GLOBALS['errors'][] = $args; }
function update_option( $key, $value, $autoload = false ) {
    if ( $GLOBALS['fail_save'] ) { return false; }
    $old = get_option( $key, [] );
    $value = Nova_Bridge_Suite_Posting_Connection::protect_update( $value, $old );
    if ( $value === $old ) { return false; }
    $GLOBALS['options'][$key] = $value;
    return true;
}
class Nova_Bridge_Suite_Posting_Settings {
    public const OPTION = 'connection';
    public static function connection() { return get_option( self::OPTION, [] ); }
    public static function effective_connection( $next ) { return $next; }
    public static function sanitize( $input ) { return array_merge( self::connection(), $input ); }
}
class Nova_Bridge_Suite_Posting_Delivery {
    public const BOOTSTRAP_OPTION = 'bootstrap'; public const LAST_RUN_OPTION = 'last_run';
    public static $schema = true; public static $initialized = 0;
    public static function ensure_schema() { return self::$schema; }
    public static function initialize() { ++self::$initialized; }
}
class Nova_Bridge_Suite_Posting_Jobs {
    public static $busy = false; public static $busy_names = []; public static $locks = 0; public static $unlocks = 0; public static $waits = [];
    public function lock( $name, $wait = 0 ) { ++self::$locks; self::$waits[$name] = $wait; return self::$busy || in_array( $name, self::$busy_names, true ) ? new WP_Error( 'busy' ) : true; }
    public function unlock( $name ) { ++self::$unlocks; }
}
class Switch_DB {
    public $prefix = 'wp_'; public $options = 'wp_options'; public $last_error = '';
    public $jobs = []; public $discovery = []; public $archive = []; public $fail = ''; public $engine = 'InnoDB'; public $snapshot; public $queries = []; public $live = 0;
    public function prepare( $sql, ...$args ) { return [ $sql, $args ]; }
    public function get_var( $query ) {
        if ( is_string( $query ) && str_contains( $query, "state<>'complete'" ) ) { return count( array_filter( $this->jobs, static function ( $row ) { return $row['state'] !== 'complete'; } ) ); }
        [ $sql, $args ] = $query;
        if ( str_starts_with( $sql, 'SELECT COUNT(*)' ) ) { return $this->live; }
        if ( str_starts_with( $sql, 'SELECT ENGINE' ) ) { return $this->engine; }
        if ( str_starts_with( $sql, 'SELECT id FROM wp_nova_posting_archive' ) ) {
            if ( $this->fail === 'archive_read' ) { $this->last_error = 'read failed'; return null; }
            foreach ( $this->archive as $row ) { if ( $row['record_type'] === 'job' && $row['site_id'] === $args[0] && $row['delivery_id'] === $args[1] && $row['source_origin'] === $args[2] ) { return '1'; } }
            return null;
        }
        throw new RuntimeException( 'Unexpected scalar query' );
    }
    public function query( $query ) {
        [ $sql, $args ] = is_array( $query ) ? $query : [ $query, [] ]; $this->queries[] = $sql;
        if ( $this->fail !== '' && str_contains( $sql, $this->fail ) ) { return false; }
        if ( $sql === 'START TRANSACTION' ) { $this->snapshot = [ $this->jobs, $this->discovery, $this->archive, $GLOBALS['options'] ]; return 0; }
        if ( $sql === 'ROLLBACK' ) { [ $this->jobs, $this->discovery, $this->archive, $GLOBALS['options'] ] = $this->snapshot; return 0; }
        if ( $sql === 'COMMIT' ) { return 0; }
        if ( str_starts_with( $sql, 'INSERT INTO wp_nova_posting_archive' ) ) {
            if ( str_ends_with( $sql, 'FROM wp_nova_posting_jobs' ) ) {
                assert_switch( str_contains( $sql, "CASE WHEN state='complete' THEN 'complete' ELSE 'cancelled' END" ), 'Archive distinguishes completion from cancellation.' );
                foreach ( $this->jobs as $row ) { $this->archive[] = [ 'record_type' => 'job', 'site_id' => $row['site_id'], 'delivery_id' => $row['content_id'], 'source_origin' => $row['site_id'] === $args[1] ? $args[2] : '', 'state' => $row['state'] === 'complete' ? 'complete' : 'cancelled', 'record' => $row ]; }
            } else {
                foreach ( $this->discovery as $row ) { $this->archive[] = [ 'record_type' => 'discovery', 'record' => $row ]; }
            }
            return 1;
        }
        if ( $sql === 'DELETE FROM wp_nova_posting_jobs' ) { $this->jobs = []; return 1; }
        if ( $sql === 'DELETE FROM wp_nova_posting_discovery' ) { $this->discovery = []; return 1; }
        throw new RuntimeException( 'Unexpected mutation' );
    }
}
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-connection.php';
$checks = 0;
function assert_switch( $ok, $message ) { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( $message ); } }
function fixture() {
    global $wpdb, $options, $fail_save, $cache_deletes, $errors;
    $wpdb = new Switch_DB(); $fail_save = false; $cache_deletes = []; $errors = [];
    Nova_Bridge_Suite_Posting_Jobs::$busy = false; Nova_Bridge_Suite_Posting_Delivery::$schema = true;
    $options = [ 'connection' => [ 'base_url' => 'https://test.example', 'site_id' => 'site-a', 'token' => 'test-token', 'enabled' => true ], 'bootstrap' => 'old-cursor', 'last_run' => 'old-run', 'saved_layout' => 'untouched', 'native_page' => 'untouched' ];
    foreach ( [ 'ready', 'running', 'blocked', 'complete' ] as $id => $state ) { $wpdb->jobs[] = [ 'id' => $id + 1, 'site_id' => 'site-a', 'content_id' => 'delivery-' . $id, 'state' => $state, 'phase' => 'event_pending', 'target_id' => 42, 'payload' => '{"event_bytes":"exact old acknowledgement"}' ]; }
    $wpdb->discovery = [ [ 'site_id' => 'site-a', 'payload' => '{"cursor":"old"}' ] ];
}
fixture(); $original_jobs = $wpdb->jobs;
assert_switch( true === Nova_Bridge_Suite_Posting_Connection::save( [ 'base_url' => 'https://prod.example', 'token' => 'prod-token' ] ), 'Same-site-UUID environment switch succeeds.' );
assert_switch( 'info' === $errors[0][3] && false !== strpos( $errors[0][2], '3 pending deliveries from the previous connection were cancelled' ) && false !== strpos( $errors[0][2], 'archived' ), 'The administrator is told how many deliveries were cancelled and that history is archived.' );
assert_switch( $wpdb->jobs === [] && $wpdb->discovery === [] && count( $wpdb->archive ) === 5, 'All old history and discovery are separate from active jobs.' );
assert_switch( array_column( array_slice( $wpdb->archive, 0, 4 ), 'state' ) === [ 'cancelled', 'cancelled', 'cancelled', 'complete' ], 'Unfinished work is cancelled; successes keep their historical status.' );
assert_switch( array_column( array_slice( $wpdb->archive, 0, 4 ), 'record' ) === $original_jobs, 'Original state, target and exact receipt bytes remain available in the archive.' );
assert_switch( ! isset( $options['bootstrap'], $options['last_run'] ) && $options['saved_layout'] === 'untouched' && $options['native_page'] === 'untouched', 'Only old delivery diagnostics are cleared; native content and layouts survive.' );
assert_switch( ! Nova_Bridge_Suite_Posting_Connection::archived( $wpdb, 'site-a', 'delivery-0' ), 'Same delivery UUID in a different service is independent.' );
$options['connection']['base_url'] = 'https://test.example';
assert_switch( Nova_Bridge_Suite_Posting_Connection::archived( $wpdb, 'site-a', 'delivery-0' ), 'Returning to test never re-admits a cancelled delivery.' );
assert_switch( ! Nova_Bridge_Suite_Posting_Connection::archived( $wpdb, 'site-a', 'new-delivery' ), 'Fresh deliveries in the old environment remain eligible.' );
$wpdb->fail = 'archive_read';
assert_switch( is_wp_error( Nova_Bridge_Suite_Posting_Connection::archived( $wpdb, 'site-a', 'delivery-0' ) ), 'An unreadable archive cannot silently republish cancelled work.' );
foreach ( [ 'INSERT INTO', 'DELETE FROM wp_nova_posting_jobs', 'DELETE FROM wp_nova_posting_discovery', 'COMMIT', 'settings' ] as $failure ) {
    fixture(); $before = [ $wpdb->jobs, $wpdb->discovery, $options ];
    if ( $failure === 'settings' ) { $fail_save = true; } else { $wpdb->fail = $failure; }
    assert_switch( is_wp_error( Nova_Bridge_Suite_Posting_Connection::save( [ 'base_url' => 'https://prod.example' ] ) ), 'A failed switch reports an error.' );
    assert_switch( [ $wpdb->jobs, $wpdb->discovery, $options ] === $before && $wpdb->archive === [], 'Every storage failure restores the old connection, jobs and discovery.' );
    assert_switch( ! Nova_Bridge_Suite_Posting_Connection::saving() && in_array( 'alloptions', $cache_deletes, true ), 'Failure releases save mode and invalidates cached transaction state.' );
}
fixture(); $before = [ $wpdb->jobs, $wpdb->discovery ];
assert_switch( true === Nova_Bridge_Suite_Posting_Connection::save( [ 'token' => 'rotated' ] ) && [ $wpdb->jobs, $wpdb->discovery ] === $before && $wpdb->archive === [], 'Credential rotation keeps active work and history.' );
assert_switch( [] === $errors, 'Credential rotation shows no switch notice.' );
fixture(); Nova_Bridge_Suite_Posting_Jobs::$busy = true; $before = $options;
assert_switch( is_wp_error( Nova_Bridge_Suite_Posting_Connection::save( [ 'base_url' => 'https://prod.example' ] ) ) && $options === $before && $wpdb->queries === [], 'An active worker prevents a concurrent switch before any mutation.' );
fixture(); $wpdb->engine = 'MyISAM';
assert_switch( is_wp_error( Nova_Bridge_Suite_Posting_Connection::save( [ 'base_url' => 'https://prod.example' ] ) ) && $wpdb->queries === [], 'Nontransactional storage cannot lose the old journal.' );
fixture(); $wpdb->live = 1;
assert_switch( is_wp_error( Nova_Bridge_Suite_Posting_Connection::save( [ 'base_url' => 'https://prod.example' ] ) ) && $wpdb->queries === [], 'An in-flight worker from the older plugin release must finish before its journal can be archived.' );
fixture(); $old = $options['connection'];
assert_switch( Nova_Bridge_Suite_Posting_Connection::protect_update( array_merge( $old, [ 'site_id' => 'site-b' ] ), $old ) === $old, 'Programmatic destination changes cannot bypass archival.' );
$before = Nova_Bridge_Suite_Posting_Jobs::$locks;
Nova_Bridge_Suite_Posting_Connection::guard( static function () { Nova_Bridge_Suite_Posting_Connection::guard( static function () {} ); } );
assert_switch( Nova_Bridge_Suite_Posting_Jobs::$locks === $before + 1, 'Nested work shares one connection lock.' );
fixture();
$test_identity = Nova_WordPress_Environment::identity();
$layout_option = 'nova_bridge_layout_recipes_v1';
$options[$layout_option] = [ 'test-layout' ];
assert_switch( true === Nova_Bridge_Suite_Posting_Connection::save( [ 'base_url' => 'https://prod.example' ] ), 'Switch records the old layout namespace atomically.' );
assert_switch( $options['connection']['layout_legacy_environment'] === $test_identity && null === get_option( Nova_WordPress_Environment::key( $layout_option ), null ), 'Production starts empty while legacy layouts belong to test.' );
$production_key = Nova_WordPress_Environment::key( $layout_option );
$options[$production_key] = [ 'production-layout' ];
assert_switch( true === Nova_Bridge_Suite_Posting_Connection::save( [ 'base_url' => 'https://test.example' ] ) && get_option( Nova_WordPress_Environment::key( $layout_option ) ) === [ 'test-layout' ], 'Switching back restores the original layout store.' );
assert_switch( true === Nova_Bridge_Suite_Posting_Connection::save( [ 'base_url' => 'https://prod.example' ] ) && get_option( Nova_WordPress_Environment::key( $layout_option ) ) === [ 'production-layout' ], 'Repeated switches recover the destination layout store.' );
assert_switch( true === Nova_Bridge_Suite_Posting_Connection::save( [ 'token' => 'rotated' ] ) && Nova_WordPress_Environment::key( $layout_option ) === $production_key, 'Credential updates preserve layout namespace ownership.' );

class Environment_Request {
    public $identity; public $method = 'POST'; public $route = '/nova-bridge/v1/mapping/layout-recipes/save';
    public function get_route() { return $this->route; }
    public function get_method() { return $this->method; }
    public function get_header( $name ) { return $this->identity; }
}
$request = new Environment_Request(); $request->identity = $test_identity; $calls = 0;
$handler = [ 'callback' => static function () use ( &$calls ) {
    ++$calls;
    return Nova_Bridge_Suite_Posting_Connection::guard( static function () { return 'saved'; } );
} ];
$result = Nova_Bridge_Suite_Posting_Connection::dispatch( null, $request, '', $handler );
assert_switch( is_wp_error( $result ) && $result->get_error_code() === 'nova_posting_environment_changed' && 0 === $calls, 'A stale editor cannot write into a different environment.' );
$request->identity = '';
assert_switch( is_wp_error( Nova_Bridge_Suite_Posting_Connection::dispatch( null, $request, '', $handler ) ) && 0 === $calls, 'An editor loaded before upgrade must reload after a switch.' );
$request->identity = Nova_WordPress_Environment::identity(); $locks = Nova_Bridge_Suite_Posting_Jobs::$locks;
assert_switch( 'saved' === Nova_Bridge_Suite_Posting_Connection::dispatch( null, $request, '', $handler ) && 1 === $calls && $locks + 2 === Nova_Bridge_Suite_Posting_Jobs::$locks && 15 === Nova_Bridge_Suite_Posting_Jobs::$waits['connection-authoring'], 'Current-environment writes wait for the authoring lock; nested delivery-lock work is still serialized.' );
$plain = [ 'callback' => static function () use ( &$calls ) { ++$calls; return 'saved'; } ];
Nova_Bridge_Suite_Posting_Jobs::$busy_names = [ 'connection' ];
assert_switch( 'saved' === Nova_Bridge_Suite_Posting_Connection::dispatch( null, $request, '', $plain ) && 2 === $calls, 'A running delivery batch does not block layout editing.' );
$request->method = 'GET'; $locks = Nova_Bridge_Suite_Posting_Jobs::$locks; Nova_Bridge_Suite_Posting_Jobs::$busy_names = [ 'connection', 'connection-authoring' ];
assert_switch( 'saved' === Nova_Bridge_Suite_Posting_Connection::dispatch( null, $request, '', $plain ) && 3 === $calls && $locks === Nova_Bridge_Suite_Posting_Jobs::$locks, 'Reads take no lock, so parallel screen requests and running deliveries never make them fail.' );
$request->identity = $test_identity;
assert_switch( is_wp_error( Nova_Bridge_Suite_Posting_Connection::dispatch( null, $request, '', $plain ) ) && 3 === $calls, 'A stale screen is still told to reload on reads.' );
$request->identity = Nova_WordPress_Environment::identity(); $request->method = 'POST';
assert_switch( 'nova_posting_authoring_busy' === Nova_Bridge_Suite_Posting_Connection::dispatch( null, $request, '', $plain )->get_error_code() && 3 === $calls, 'A write during a switch or another layout change is refused after waiting.' );
$before = $options;
assert_switch( 'nova_posting_authoring_busy' === Nova_Bridge_Suite_Posting_Connection::save( [ 'base_url' => 'https://other.example' ] )->get_error_code() && $before === $options, 'A switch never runs underneath an in-flight layout change.' );
Nova_Bridge_Suite_Posting_Jobs::$busy_names = [];
Nova_Bridge_Suite_Posting_Jobs::$busy = true;
assert_switch( is_wp_error( Nova_Bridge_Suite_Posting_Connection::dispatch( null, $request, '', $handler ) ) && 3 === $calls, 'A concurrent switch prevents authoring before its callback.' );
Nova_Bridge_Suite_Posting_Jobs::$busy = false;
$request->route = '/nova-bridge/v1/posting/ready';
assert_switch( null === Nova_Bridge_Suite_Posting_Connection::dispatch( null, $request, '', $handler ), 'Webhook requests do not acquire a browser environment requirement.' );
$request->route = '/nova-bridge/v1/mapping/layout-recipes/save';
assert_switch( 'handled' === Nova_Bridge_Suite_Posting_Connection::dispatch( 'handled', $request, '', $handler ) && 3 === $calls, 'An earlier REST handler is preserved.' );

// Optional copy of local layouts into a new connection.
function add_option( $key, $value, $deprecated = '', $autoload = false ) {
    if ( $GLOBALS['fail_copy'] || array_key_exists( $key, $GLOBALS['options'] ) ) { return false; }
    $GLOBALS['options'][$key] = $value; return true;
}
function wp_json_encode( $value ) { return json_encode( $value ); }
class Nova_Bridge_Suite_Shared_Layouts { public static $subscriber = false; public static function is_subscriber( $c ) { return self::$subscriber; } }
class Nova_Bridge_Suite_Stored_Recipe_Sync {
    public static $live = [];
    public static function saved_status( $connection, $id, $revision ) { return in_array( $connection['base_url'] . '|' . $id . ':' . $revision, self::$live, true ) ? [ 'status' => 'synced' ] : null; }
}
class WP_REST_Request {
    public $body; public function __construct( $method, $route ) {}
    public function set_header( $name, $value ) {} public function set_body( $body ) { $this->body = $body; }
}
class Nova_Bridge_Suite_Layout_Recipes {
    public static $calls = []; public static $replies = [];
    public static function sync( $request ) {
        $body = json_decode( $request->body, true );
        self::$calls[] = [ $body, Nova_Bridge_Suite_Posting_Settings::connection()['base_url'] ];
        return [ 'posting_sync' => self::$replies[ $body['id'] ] ?? [ 'status' => 'synced', 'template_id' => 'new-template' ] ];
    }
}
$fail_copy = false;
const PAGE = '7e1af410-0000-4000-8000-000000000001'; const POST = '7e1af410-0000-4000-8000-000000000002'; const CAT = '7e1af410-0000-4000-8000-000000000003'; const DRAFT = '7e1af410-0000-4000-8000-000000000004';
function copy_fixture() {
    global $options; fixture();
    $profile = static function ( $id, $rev, $label ) { return [ 'id' => $id, 'revision' => $rev, 'label' => $label, 'resource_id' => 'post_type:page' ]; };
    $options['nova_bridge_layout_recipes_v1'] = [ 'format' => 'nova-layout-recipes-store/v1',
        'profiles' => [ PAGE => [ 1 => $profile( PAGE, 1, 'Pagina' ), 2 => $profile( PAGE, 2, 'Pagina' ), 3 => $profile( PAGE, 3, 'Pagina' ) ], POST => [ 2 => $profile( POST, 2, 'Bericht' ) ], CAT => [ 1 => $profile( CAT, 1, 'Categorie' ) ], DRAFT => [ 1 => $profile( DRAFT, 1, 'Concept' ) ] ],
        'defaults' => [ 'post_type:page' => [ 'id' => PAGE, 'revision' => 3 ], 'post_type:post' => [ 'id' => POST, 'revision' => 2 ], 'taxonomy:product_cat' => [ 'id' => CAT, 'revision' => 1 ], 'post_type:service_page' => [ 'id' => DRAFT, 'revision' => 1 ] ] ];
    $options['nova_bridge_endpoint_mappings_v1'] = [ 'defaults' => [ 'post_type:page' => [ 'id' => 'm', 'revision' => 1 ] ], 'revisions' => [ 'm' => [ 1 => [] ] ] ];
    // Remote-specific state of the test service must never travel.
    $options['nova_mapping_sync_test'] = [ 'template' => [ 'id' => 'test-template' ] ];
    $options['nova_publishing_profile_test'] = [ 'approved' => true ];
    Nova_Bridge_Suite_Stored_Recipe_Sync::$live = [ 'https://test.example|' . PAGE . ':3', 'https://test.example|' . POST . ':2' ];
    Nova_Bridge_Suite_Layout_Recipes::$calls = []; Nova_Bridge_Suite_Layout_Recipes::$replies = []; Nova_Bridge_Suite_Shared_Layouts::$subscriber = false;
    $GLOBALS['fail_copy'] = false;
}
$copy = [ 'copy_environment_layouts' => '1' ];
$prod = [ 'base_url' => 'https://prod.example', 'token' => 'prod-token' ];

copy_fixture(); $test = $options; $test_store = $options['nova_bridge_layout_recipes_v1'];
assert_switch( true === Nova_Bridge_Suite_Posting_Connection::save( $prod + $copy ), 'A switch can copy the current layouts.' );
$layout_key = Nova_WordPress_Environment::key( 'nova_bridge_layout_recipes_v1' );
assert_switch( $layout_key !== 'nova_bridge_layout_recipes_v1' && $options[$layout_key] === $test_store && $options['nova_bridge_layout_recipes_v1'] === $test_store, 'Production gets an identical copy, including revision numbers and defaults; test keeps its own.' );
assert_switch( $options[ Nova_WordPress_Environment::key( 'nova_bridge_endpoint_mappings_v1' ) ] === $test['nova_bridge_endpoint_mappings_v1'], 'Endpoint mappings are copied too.' );
$added = array_diff( array_keys( $options ), array_keys( $test ) );
assert_switch( count( $added ) === 2 && ! array_filter( $added, static function ( $k ) { return false !== strpos( $k, 'sync' ) || false !== strpos( $k, 'profile' ); } ), 'Only the local stores are copied; sync state and approvals are not.' );
assert_switch( count( $wpdb->archive ) === 5 && $wpdb->jobs === [], 'Copying does not change delivery cancellation and archival.' );
assert_switch( [] === Nova_Bridge_Suite_Layout_Recipes::$calls && in_array( 'warning', array_column( $errors, 3 ), true ), 'Without the sync option nothing is sent and the administrator is told to synchronize.' );
$prod_store = $options[$layout_key];

assert_switch( 'nova_environment_copy_destination_configured' === Nova_Bridge_Suite_Posting_Connection::save( [ 'base_url' => 'https://test.example' ] + $copy )->get_error_code() && $options['connection']['base_url'] === 'https://prod.example' && count( $wpdb->archive ) === 5, 'Copying into a connection that already has layouts is refused before any change.' );
assert_switch( true === Nova_Bridge_Suite_Posting_Connection::save( [ 'base_url' => 'https://test.example' ] ) && $options['nova_bridge_layout_recipes_v1'] === $test_store && $options[$layout_key] === $prod_store, 'Switching back without copying restores test exactly and keeps the production copy.' );

copy_fixture(); $options[ Nova_WordPress_Environment::key( 'nova_bridge_layout_recipes_v1', array_replace( $options['connection'], $prod ) ) ] = [ 'format' => 'nova-layout-recipes-store/v1', 'profiles' => [ 'prod-own' => [ 1 => [] ] ], 'defaults' => [] ];
$before = [ $options, $wpdb->jobs ];
assert_switch( is_wp_error( Nova_Bridge_Suite_Posting_Connection::save( $prod + $copy ) ) && $before === [ $options, $wpdb->jobs ] && $wpdb->queries === [], 'Production layouts made earlier are never overwritten or merged.' );
copy_fixture(); $options[ Nova_WordPress_Environment::key( 'nova_bridge_layout_recipes_v1', array_replace( $options['connection'], $prod ) ) ] = [ 'format' => 'nova-layout-recipes-store/v1', 'profiles' => [], 'defaults' => [] ];
assert_switch( true === Nova_Bridge_Suite_Posting_Connection::save( $prod + $copy ) && $options[ Nova_WordPress_Environment::key( 'nova_bridge_layout_recipes_v1' ) ] === $test_store, 'An empty store left by an earlier visit can receive the copy.' );

copy_fixture(); $fail_copy = true; $before = [ $options, $wpdb->jobs, $wpdb->discovery ];
assert_switch( is_wp_error( Nova_Bridge_Suite_Posting_Connection::save( $prod + $copy ) ) && $before === [ $options, $wpdb->jobs, $wpdb->discovery ] && $wpdb->archive === [], 'A copy failure rolls back the whole switch.' );
copy_fixture(); $options['nova_bridge_layout_recipes_v1']['defaults']['post_type:page']['revision'] = 9; $before = $options;
assert_switch( 'nova_environment_copy_source_invalid' === Nova_Bridge_Suite_Posting_Connection::save( $prod + $copy )->get_error_code() && $before === $options && $wpdb->queries === [], 'Inconsistent layout history is refused before any change.' );
copy_fixture(); Nova_Bridge_Suite_Shared_Layouts::$subscriber = true; $before = $options;
assert_switch( 'nova_environment_copy_receive_only' === Nova_Bridge_Suite_Posting_Connection::save( $prod + $copy )->get_error_code() && $before === $options, 'Receive-only installations import layouts instead of copying.' );
copy_fixture(); $before = $options['nova_bridge_layout_recipes_v1'];
assert_switch( true === Nova_Bridge_Suite_Posting_Connection::save( [ 'token' => 'rotated' ] + $copy ) && count( $options ) === count( $test ) && $wpdb->archive === [], 'The copy option is ignored for credential updates.' );
copy_fixture(); foreach ( Nova_WordPress_Environment_Copy::STORES as $store ) { unset( $options[$store] ); }
assert_switch( true === Nova_Bridge_Suite_Posting_Connection::save( $prod + $copy ) && 'info' === $errors[0][3], 'With nothing to copy the switch still completes and says so.' );

// Synchronize after the switch: only what was live on test, never replacing a production default.
copy_fixture();
Nova_Bridge_Suite_Layout_Recipes::$replies[ POST ] = [ 'status' => 'failed', 'code' => 'nova_stored_recipe_default_conflict', 'message' => 'Another template currently handles this NOVA content type.' ];
assert_switch( true === Nova_Bridge_Suite_Posting_Connection::save( $prod + $copy + [ 'sync_copied_layouts' => '1' ] ), 'Copy and synchronize completes.' );
assert_switch( Nova_Bridge_Suite_Layout_Recipes::$calls === [ [ [ 'id' => PAGE, 'revision' => 3 ], 'https://prod.example' ], [ [ 'id' => POST, 'revision' => 2 ], 'https://prod.example' ] ], 'Only live defaults sync, at their exact revisions, against the new connection, without a replace instruction.' );
$by_type = []; foreach ( $errors as $e ) { $by_type[ $e[3] ][] = $e[2]; }
assert_switch( count( $by_type['success'] ) === 2 && count( $by_type['error'] ) === 1 && false !== strpos( $by_type['error'][0], 'already has a different default' ) && count( $by_type['warning'] ) === 2 && false !== strpos( implode( ' ', $by_type['warning'] ), 'category' ) && false !== strpos( implode( ' ', $by_type['warning'] ), 'Concept (revision 1): copied only. It was not synchronized' ), 'The report separates synchronized, conflicting, never-synchronized and category layouts.' );
copy_fixture(); Nova_Bridge_Suite_Layout_Recipes::$replies[ PAGE ] = [ 'status' => 'failed', 'code' => 'nova_posting_transport', 'message' => 'The posting service could not be reached.' ];
assert_switch( true === Nova_Bridge_Suite_Posting_Connection::save( $prod + $copy + [ 'sync_copied_layouts' => '1' ] ) && $options['connection']['base_url'] === 'https://prod.example' && isset( $options[ Nova_WordPress_Environment::key( 'nova_bridge_layout_recipes_v1' ) ] ), 'A failed synchronization keeps the committed switch and copy.' );

$plan = [ 'stores' => [ 'x' => [ 'y' => [ 1 ] ] ], 'layouts' => 4, 'defaults' => [
    [ 'resource_id' => 'post_type:page', 'id' => 'a', 'revision' => 3, 'label' => 'A', 'live' => true ],
    [ 'resource_id' => 'post_type:post', 'id' => 'b', 'revision' => 1, 'label' => 'B', 'live' => false ],
    [ 'resource_id' => 'post_type:service', 'id' => 'c', 'revision' => 2, 'label' => 'C', 'live' => true ],
] ];
$now = 0.0; $sent = [];
$results = Nova_WordPress_Environment_Copy::synchronize( $plan, static function ( $id, $rev ) use ( &$now, &$sent ) { $sent[] = $id; $now += 30; return [ 'status' => 'synced' ]; }, static function () use ( &$now ) { return $now; } );
assert_switch( $sent === [ 'a' ] && array_column( $results, 'outcome' ) === [ 'synced', 'not_live', 'deferred' ], 'Synchronization stops within its time budget and reports the rest as pending.' );
echo "PASS {$checks} environment switch checks\n";
