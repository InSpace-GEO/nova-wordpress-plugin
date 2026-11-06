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
    public static $busy = false; public static $locks = 0; public static $unlocks = 0;
    public function lock( $name ) { ++self::$locks; return self::$busy ? new WP_Error( 'busy' ) : true; }
    public function unlock( $name ) { ++self::$unlocks; }
}
class Switch_DB {
    public $prefix = 'wp_'; public $options = 'wp_options'; public $last_error = '';
    public $jobs = []; public $discovery = []; public $archive = []; public $fail = ''; public $engine = 'InnoDB'; public $snapshot; public $queries = []; public $live = 0;
    public function prepare( $sql, ...$args ) { return [ $sql, $args ]; }
    public function get_var( $query ) {
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
echo "PASS {$checks} environment switch checks\n";
