<?php
/** Wake -> batch -> follow-up: the queue is drained in bounded batches without further wakes. In-memory WordPress, queue and worker. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code; private $data;
    public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
    public $data; public $status;
    public function __construct( $data, $status ) { $this->data = $data; $this->status = $status; }
    public function header( $name, $value ) {}
}
class Drain_Request {
    public $body = ''; public $headers = [];
    public function get_body() { return $this->body; }
    public function get_header( $name ) { return $this->headers[$name] ?? ''; }
    public function get_param( $name ) { return null; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function current_user_can( $cap ) { return true; }
function is_user_logged_in() { return true; }
function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); }
function get_option( $name, $default = false ) { return $GLOBALS['options'][$name] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['options'][$name] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['options'][$name] ); return true; }
function add_action( $hook, $callback, ...$args ) { $GLOBALS['hooks'][$hook][] = $callback; }
function register_rest_route( $namespace, $path, $route ) { $GLOBALS['routes'][$path] = $route; }
function wp_next_scheduled( $hook ) { return $GLOBALS['cron'][$hook] ?? false; }
function wp_schedule_single_event( $at, $hook ) { $GLOBALS['cron'][$hook] = $at; return true; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['cron'][$hook] ); return 1; }
function wp_get_scheduled_event( $hook ) { return isset( $GLOBALS['cron'][$hook] ) ? (object) [ 'timestamp' => $GLOBALS['cron'][$hook], 'schedule' => false ] : false; }
function rest_url( $path ) { return 'https://site.fixture.invalid/wp-json/' . $path; }
function apply_filters( $hook, $value ) { return $value; }
function wp_remote_post( $url, $args ) { $GLOBALS['loopbacks'][] = [ 'url' => $url, 'args' => $args ]; return []; }
class Nova_Bridge_Suite_Posting_Settings {
    public static $connection = [];
    public static function connection() { return self::$connection; }
}
class Nova_Bridge_Suite_Posting_Client { public function __construct( $connection ) {} }
class Nova_Bridge_Suite_Posting_Connection {
    public static $busy = false;
    public static function guard( callable $callback ) {
        if ( self::$busy ) { return new WP_Error( 'nova_posting_connection_busy', 'busy', [ 'status' => 409 ] ); }
        return $callback();
    }
    public static $switching = false;
    public static function switching( callable $callback, int $wait = 0 ) {
        if ( self::$switching ) { return new WP_Error( 'nova_posting_connection_busy', 'busy', [ 'status' => 409 ] ); }
        return $callback();
    }
}
/** The durable queue: one row per delivery. */
class Nova_Bridge_Suite_Posting_Jobs {
    public static $rows = []; public static $discovery = []; public static $paused_queries = [];
    public function lock( $key ) { return true; }
    public function unlock( $key ) {}
    public function install() { return true; }
    public function storage_exists() { return true; }
    public function summaries( $site ) { return []; }
    public function discovery_state( $site ) { return self::$discovery; }
    public function save_discovery_state( $site, $state ) { self::$discovery = $state; return true; }
    public function request_discovery( $site ) { ++self::$discovery['wake_sequence']; return true; }
    public static function claimable( array $row, bool $paused ): bool {
        return in_array( $row['state'], [ 'ready', 'running' ], true ) && ( ! $paused || in_array( $row['phase'], [ 'applying', 'committed', 'event_pending' ], true ) );
    }
    public function pending_work( $site, $paused ) {
        self::$paused_queries[] = $paused; $now = time(); $due = 0; $next = null; $pending = 0;
        foreach ( self::$rows as $row ) {
            if ( ! self::claimable( $row, $paused ) ) { continue; }
            ++$pending; if ( $row['next_attempt'] <= $now && $row['lease_until'] < $now ) { ++$due; }
            $at = max( $row['next_attempt'], $row['lease_until'] + 1 ); $next = null === $next ? $at : min( $next, $at );
        }
        return [ 'pending' => $pending, 'due' => $due, 'next_due' => $next ];
    }
}
/** Same budget as the production worker: at most three deliveries per batch. */
class Nova_Bridge_Suite_Posting_Worker {
    public static $batches = []; public static $claim_nothing = false; public static $during = null;
    private $connection;
    public function __construct( $connection ) { $this->connection = $connection; }
    public function run( ?callable $observe = null ): void {
        if ( empty( $this->connection['enabled'] ) ) { return; }
        $state = &Nova_Bridge_Suite_Posting_Jobs::$discovery;
        $state['consumed_sequence'] = $state['wake_sequence']; $state['cycle_started'] = 0;
        if ( self::$during ) { $during = self::$during; self::$during = null; $during(); }
        $done = [];
        foreach ( Nova_Bridge_Suite_Posting_Jobs::$rows as $id => &$row ) {
            if ( self::$claim_nothing || count( $done ) >= 3 ) { break; }
            if ( ! Nova_Bridge_Suite_Posting_Jobs::claimable( $row, ! empty( $this->connection['paused'] ) ) || $row['next_attempt'] > time() || $row['lease_until'] >= time() ) { continue; }
            $row['state'] = 'complete'; $done[] = $id;
            if ( $observe ) { $observe( [ 'jobs_claimed' => count( $done ), 'last_job' => [ 'id' => $id, 'state' => 'complete', 'phase' => 'committed' ] ] ); }
        }
        unset( $row );
        self::$batches[] = $done;
    }
}
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-protocol.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-discovery.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-delivery.php';
$checks = 0;
function drain_check( $condition, $message ) { ++$GLOBALS['checks']; if ( ! $condition ) { throw new RuntimeException( $message ); } }
const DRAIN_SITE = '11111111-1111-4111-8111-111111111111';
const DRAIN_SECRET = 'fixture-signing-secret';
function drain_reset( int $deliveries, array $patch = [] ): Drain_Request {
    $GLOBALS['options'] = [ Nova_Bridge_Suite_Posting_Delivery::SCHEMA_OPTION => Nova_Bridge_Suite_Posting_Delivery::SCHEMA_VERSION ];
    $GLOBALS['cron'] = []; $GLOBALS['loopbacks'] = []; $GLOBALS['hooks'] = []; $GLOBALS['routes'] = [];
    Nova_Bridge_Suite_Posting_Connection::$busy = false; Nova_Bridge_Suite_Posting_Connection::$switching = false;
    Nova_Bridge_Suite_Posting_Worker::$batches = []; Nova_Bridge_Suite_Posting_Worker::$claim_nothing = false; Nova_Bridge_Suite_Posting_Worker::$during = null;
    Nova_Bridge_Suite_Posting_Jobs::$paused_queries = [];
    Nova_Bridge_Suite_Posting_Jobs::$rows = [];
    for ( $i = 1; $i <= $deliveries; ++$i ) { Nova_Bridge_Suite_Posting_Jobs::$rows[ $i ] = [ 'state' => 'ready', 'phase' => 'accepted', 'next_attempt' => 0, 'lease_until' => 0 ]; }
    Nova_Bridge_Suite_Posting_Jobs::$discovery = [ 'cursor' => null, 'cycle_started' => 0, 'last_completed' => 0, 'next_attempt' => 0, 'consumed_sequence' => 0, 'wake_sequence' => 0, 'failures' => 0, 'last_error' => '', 'connection_key' => '', 'suspended' => false, 'auth_status' => 0 ];
    Nova_Bridge_Suite_Posting_Settings::$connection = array_merge( [ 'enabled' => true, 'paused' => false, 'site_id' => DRAIN_SITE, 'base_url' => 'https://posting.fixture.invalid', 'token' => 'fixture-token', 'webhook_secret' => DRAIN_SECRET ], $patch );
    $flag = new ReflectionProperty( Nova_Bridge_Suite_Posting_Delivery::class, 'hops' ); if ( PHP_VERSION_ID < 80100 ) { $flag->setAccessible( true ); } $flag->setValue( null, 0 );
    return drain_reset_request();
}
function drain_reset_request(): Drain_Request {
    $request = new Drain_Request();
    $request->body = json_encode( [ 'event' => 'content.ready', 'content_id' => '123', 'site' => DRAIN_SITE, 'version' => 1 ] );
    $timestamp = (string) time();
    $request->headers = [ 'x-nova-timestamp' => $timestamp, 'x-nova-signature' => 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $request->body, DRAIN_SECRET ) ];
    return $request;
}
/** Deliver the newest loopback the way the web server would: a new request to /posting/continue, then its shutdown. */
function drain_follow_loopbacks( int $limit = 500 ): int {
    $hops = 0;
    while ( $GLOBALS['loopbacks'] && $hops < $limit ) {
        $call = array_pop( $GLOBALS['loopbacks'] ); $GLOBALS['loopbacks'] = [];
        $request = new Drain_Request(); $request->headers = [ 'x-nova-continue' => $call['args']['headers']['X-NOVA-Continue'] ];
        $answer = Nova_Bridge_Suite_Posting_Delivery::continue_run( $request );
        drain_check( ! is_wp_error( $answer ) && 202 === $answer->status, 'A continuation from this site is accepted.' );
        Nova_Bridge_Suite_Posting_Delivery::guarded_run_queued(); ++$hops;
    }
    return $hops;
}
function drain_done(): int { return count( array_filter( Nova_Bridge_Suite_Posting_Jobs::$rows, static function ( $row ) { return 'complete' === $row['state']; } ) ); }
function drain_last_run(): array { return get_option( Nova_Bridge_Suite_Posting_Delivery::LAST_RUN_OPTION ); }
$hook = Nova_Bridge_Suite_Posting_Delivery::CRON_HOOK;

// 1. Ten ready deliveries and one wake: every delivery is written, three per request.
$request = drain_reset( 10 );
drain_check( 202 === Nova_Bridge_Suite_Posting_Delivery::guarded_ready( $request )->status, 'The wake is acknowledged.' );
Nova_Bridge_Suite_Posting_Delivery::guarded_run_queued();
drain_check( [ [ 1, 2, 3 ] ] === Nova_Bridge_Suite_Posting_Worker::$batches && 1 === count( $GLOBALS['loopbacks'] ), 'The first batch keeps its three-delivery budget and starts a continuation for the rest.' );
$loopback = $GLOBALS['loopbacks'][0];
drain_check( 'https://site.fixture.invalid/wp-json/nova-bridge/v1/posting/continue' === $loopback['url'] && false === $loopback['args']['blocking'] && 0 === $loopback['args']['redirection'] && preg_match( '/^[a-f0-9]{64}$/', $loopback['args']['headers']['X-NOVA-Continue'] ), 'The continuation is a non-blocking request to this site with a one-time token.' );
drain_check( ! isset( get_option( Nova_Bridge_Suite_Posting_Delivery::CONTINUE_OPTION )['token'] ) && hash( 'sha256', $loopback['args']['headers']['X-NOVA-Continue'] ) === get_option( Nova_Bridge_Suite_Posting_Delivery::CONTINUE_OPTION )['hash'], 'Only a hash of the continuation token is stored.' );
drain_check( 'continue' === drain_last_run()['follow_up'] && isset( $GLOBALS['cron'][$hook] ) && $GLOBALS['cron'][$hook] > time() + 60, 'A continuing chain keeps one backstop event in case the continuation never arrives.' );
$hops = drain_follow_loopbacks();
drain_check( 3 === $hops && [ [ 1, 2, 3 ], [ 4, 5, 6 ], [ 7, 8, 9 ], [ 10 ] ] === Nova_Bridge_Suite_Posting_Worker::$batches && 10 === drain_done(), 'Ten deliveries are written in batches of 3, 3, 3 and 1 after a single wake.' );
drain_check( 'idle' === drain_last_run()['follow_up'] && 4 === drain_last_run()['batch'] && [] === $GLOBALS['cron'] && [] === $GLOBALS['loopbacks'] && false === get_option( Nova_Bridge_Suite_Posting_Delivery::CONTINUE_OPTION ), 'The chain stops by itself once the queue is empty and leaves no event or token behind.' );

// 2. Continuation tokens are single-use, exact and short-lived.
$request = drain_reset( 4 );
Nova_Bridge_Suite_Posting_Delivery::guarded_ready( $request ); Nova_Bridge_Suite_Posting_Delivery::guarded_run_queued();
$token = $GLOBALS['loopbacks'][0]['args']['headers']['X-NOVA-Continue'];
$forged = new Drain_Request(); $forged->headers = [ 'x-nova-continue' => str_repeat( 'a', 64 ) ];
drain_check( 401 === Nova_Bridge_Suite_Posting_Delivery::continue_run( $forged )->get_error_data()['status'], 'A guessed continuation token is refused.' );
$forged->headers = [ 'x-nova-continue' => '' ];
drain_check( 401 === Nova_Bridge_Suite_Posting_Delivery::continue_run( $forged )->get_error_data()['status'], 'A request without a token is refused.' );
$GLOBALS['options'][Nova_Bridge_Suite_Posting_Delivery::CONTINUE_OPTION]['expires'] = time() - 1;
$late = new Drain_Request(); $late->headers = [ 'x-nova-continue' => $token ];
drain_check( 401 === Nova_Bridge_Suite_Posting_Delivery::continue_run( $late )->get_error_data()['status'], 'An expired continuation token is refused.' );
$GLOBALS['options'][Nova_Bridge_Suite_Posting_Delivery::CONTINUE_OPTION]['expires'] = time() + 60;
drain_check( 202 === Nova_Bridge_Suite_Posting_Delivery::continue_run( $late )->status, 'The real token is accepted once.' );
drain_check( 401 === Nova_Bridge_Suite_Posting_Delivery::continue_run( $late )->get_error_data()['status'], 'A replayed continuation token is refused.' );
Nova_Bridge_Suite_Posting_Delivery::guarded_run_queued();
drain_check( 4 === drain_done() && 'idle' === drain_last_run()['follow_up'], 'The accepted continuation ran the remaining delivery.' );

// 3. A wake that arrives while a batch holds the connection is recorded, not refused.
$request = drain_reset( 2 );
Nova_Bridge_Suite_Posting_Connection::$busy = true;
$answer = Nova_Bridge_Suite_Posting_Delivery::guarded_ready( $request );
drain_check( ! is_wp_error( $answer ) && 202 === $answer->status && 1 === Nova_Bridge_Suite_Posting_Jobs::$discovery['wake_sequence'], 'A wake during a running batch is answered 202 and recorded.' );
drain_check( isset( $GLOBALS['cron'][$hook] ) && $GLOBALS['cron'][$hook] <= time() + 60 && [] === Nova_Bridge_Suite_Posting_Worker::$batches, 'The recorded wake gets a backstop event within a minute and runs nothing in the refused request.' );
$request->body .= ' ';
drain_check( 401 === Nova_Bridge_Suite_Posting_Delivery::guarded_ready( $request )->get_error_data()['status'] && 1 === Nova_Bridge_Suite_Posting_Jobs::$discovery['wake_sequence'], 'A wake during a batch is still signature-checked before anything is recorded.' );
Nova_Bridge_Suite_Posting_Connection::$switching = true;
drain_check( 409 === Nova_Bridge_Suite_Posting_Delivery::guarded_ready( drain_reset_request() )->get_error_data()['status'] && 1 === Nova_Bridge_Suite_Posting_Jobs::$discovery['wake_sequence'], 'A wake during a connection switch is still refused, never recorded against the old connection.' );
Nova_Bridge_Suite_Posting_Connection::$switching = false;
Nova_Bridge_Suite_Posting_Connection::$busy = false;
Nova_Bridge_Suite_Posting_Delivery::cron_run();
drain_check( 2 === drain_done() && Nova_Bridge_Suite_Posting_Jobs::$discovery['consumed_sequence'] === Nova_Bridge_Suite_Posting_Jobs::$discovery['wake_sequence'] && [] === $GLOBALS['cron'], 'The backstop event consumes the recorded wake and drains the queue.' );
$request = drain_reset( 0 );
Nova_Bridge_Suite_Posting_Delivery::guarded_ready( $request );
Nova_Bridge_Suite_Posting_Connection::$busy = true; Nova_Bridge_Suite_Posting_Delivery::guarded_run_queued();
drain_check( isset( $GLOBALS['cron'][$hook] ) && [] === Nova_Bridge_Suite_Posting_Worker::$batches, 'A queued run that cannot get the connection leaves a backstop event instead of dropping the work.' );

// 4. A wake that arrives during the batch itself is picked up by the chain, not left for the next wake.
$request = drain_reset( 2 );
Nova_Bridge_Suite_Posting_Worker::$during = static function () { Nova_Bridge_Suite_Posting_Jobs::$rows[3] = [ 'state' => 'ready', 'phase' => 'accepted', 'next_attempt' => 0, 'lease_until' => 0 ]; ++Nova_Bridge_Suite_Posting_Jobs::$discovery['wake_sequence']; };
Nova_Bridge_Suite_Posting_Delivery::guarded_ready( $request ); Nova_Bridge_Suite_Posting_Delivery::guarded_run_queued();
drain_check( 'continue' === drain_last_run()['follow_up'], 'An unconsumed wake after a productive batch continues the chain.' );
drain_follow_loopbacks();
drain_check( 3 === drain_done() && 'idle' === drain_last_run()['follow_up'] && Nova_Bridge_Suite_Posting_Jobs::$discovery['consumed_sequence'] === Nova_Bridge_Suite_Posting_Jobs::$discovery['wake_sequence'], 'The next batch consumes that wake and writes its delivery.' );

// 5. Back-off retries run at their due time through the single event, not on the next wake.
$request = drain_reset( 1 );
$due = time() + 120; Nova_Bridge_Suite_Posting_Jobs::$rows[1]['next_attempt'] = $due;
Nova_Bridge_Suite_Posting_Delivery::guarded_ready( $request ); Nova_Bridge_Suite_Posting_Delivery::guarded_run_queued();
drain_check( 'scheduled' === drain_last_run()['follow_up'] && $due === drain_last_run()['next_run_at'] && $due === $GLOBALS['cron'][$hook] && [] === $GLOBALS['loopbacks'], 'A retry in back-off gets one event at exactly its due time and no continuation.' );
Nova_Bridge_Suite_Posting_Jobs::$rows[1]['next_attempt'] = time() - 1; Nova_Bridge_Suite_Posting_Delivery::cron_run();
drain_check( 1 === drain_done() && [] === $GLOBALS['cron'], 'The due event writes the retried delivery and clears itself.' );
$request = drain_reset( 2 );
Nova_Bridge_Suite_Posting_Jobs::$rows[1]['lease_until'] = time() + 200; Nova_Bridge_Suite_Posting_Jobs::$rows[2]['next_attempt'] = time() + 900;
Nova_Bridge_Suite_Posting_Delivery::guarded_ready( $request ); Nova_Bridge_Suite_Posting_Delivery::guarded_run_queued();
drain_check( time() + 201 === $GLOBALS['cron'][$hook], 'A delivery leased by an interrupted worker is retried right after its lease, the earliest pending time.' );

// 6. If continuations cannot reach the site, the backstop event drains the queue instead.
$request = drain_reset( 7 );
Nova_Bridge_Suite_Posting_Delivery::guarded_ready( $request ); Nova_Bridge_Suite_Posting_Delivery::guarded_run_queued();
$runs = 0;
while ( drain_done() < 7 && $runs < 10 ) { $GLOBALS['loopbacks'] = []; drain_check( isset( $GLOBALS['cron'][$hook] ), 'Every unfinished batch leaves a backstop event.' ); Nova_Bridge_Suite_Posting_Delivery::cron_run(); ++$runs; }
drain_check( 2 === $runs && 7 === drain_done() && [] === $GLOBALS['cron'], 'With every continuation lost, the backstop events alone finish all deliveries.' );

// 7. A batch that makes no progress never spins; the chain is bounded.
$request = drain_reset( 5 );
Nova_Bridge_Suite_Posting_Worker::$claim_nothing = true;
Nova_Bridge_Suite_Posting_Delivery::guarded_ready( $request ); Nova_Bridge_Suite_Posting_Delivery::guarded_run_queued();
drain_check( [] === $GLOBALS['loopbacks'] && 'scheduled' === drain_last_run()['follow_up'] && $GLOBALS['cron'][$hook] <= time() + 60, 'Due work that a batch could not claim waits a minute for the backstop instead of looping.' );
$request = drain_reset( 3 * Nova_Bridge_Suite_Posting_Delivery::MAX_HOPS + 9 );
Nova_Bridge_Suite_Posting_Delivery::guarded_ready( $request ); Nova_Bridge_Suite_Posting_Delivery::guarded_run_queued();
$hops = drain_follow_loopbacks();
drain_check( Nova_Bridge_Suite_Posting_Delivery::MAX_HOPS - 1 === $hops && Nova_Bridge_Suite_Posting_Delivery::MAX_HOPS === drain_last_run()['batch'] && 'scheduled' === drain_last_run()['follow_up'] && $GLOBALS['cron'][$hook] <= time() + 60, 'A long chain hands over to the backstop after its bounded number of batches.' );
Nova_Bridge_Suite_Posting_Delivery::cron_run(); drain_follow_loopbacks();
drain_check( 3 * Nova_Bridge_Suite_Posting_Delivery::MAX_HOPS + 9 === drain_done() && 'idle' === drain_last_run()['follow_up'], 'The backstop starts a fresh chain that finishes the queue.' );

// 8. Pause, suspension and disabling are respected.
$request = drain_reset( 2, [ 'paused' => true ] );
Nova_Bridge_Suite_Posting_Jobs::$rows[2]['phase'] = 'event_pending';
Nova_Bridge_Suite_Posting_Delivery::guarded_ready( $request ); Nova_Bridge_Suite_Posting_Delivery::guarded_run_queued();
drain_check( 'complete' === Nova_Bridge_Suite_Posting_Jobs::$rows[2]['state'] && 'ready' === Nova_Bridge_Suite_Posting_Jobs::$rows[1]['state'] && 'idle' === drain_last_run()['follow_up'] && [ true ] === Nova_Bridge_Suite_Posting_Jobs::$paused_queries, 'A paused connection only finishes work already in the CMS and does not wait on new writes.' );
$request = drain_reset( 3 );
Nova_Bridge_Suite_Posting_Delivery::guarded_ready( $request );
Nova_Bridge_Suite_Posting_Worker::$during = static function () { Nova_Bridge_Suite_Posting_Jobs::$discovery['suspended'] = true; Nova_Bridge_Suite_Posting_Jobs::$discovery['auth_status'] = 401; };
Nova_Bridge_Suite_Posting_Delivery::guarded_run_queued();
drain_check( 'blocked' === drain_last_run()['follow_up'] && [] === $GLOBALS['cron'] && [] === $GLOBALS['loopbacks'], 'Rejected credentials stop the chain; an administrator has to recheck access.' );
$request = drain_reset( 6 );
Nova_Bridge_Suite_Posting_Delivery::guarded_ready( $request );
Nova_Bridge_Suite_Posting_Worker::$during = static function () { Nova_Bridge_Suite_Posting_Jobs::$discovery['suspended'] = true; Nova_Bridge_Suite_Posting_Jobs::$discovery['auth_status'] = 403; };
Nova_Bridge_Suite_Posting_Delivery::guarded_run_queued();
drain_check( 'idle' === drain_last_run()['follow_up'] && [ true ] === Nova_Bridge_Suite_Posting_Jobs::$paused_queries, 'An ineligible site only counts work that already reached the CMS.' );
$request = drain_reset( 6 );
Nova_Bridge_Suite_Posting_Delivery::guarded_ready( $request );
Nova_Bridge_Suite_Posting_Worker::$during = static function () { Nova_Bridge_Suite_Posting_Settings::$connection['enabled'] = false; };
Nova_Bridge_Suite_Posting_Delivery::guarded_run_queued();
drain_check( 'idle' === drain_last_run()['follow_up'] && [] === $GLOBALS['cron'] && [] === $GLOBALS['loopbacks'], 'Disabling the connection ends the chain and clears the event.' );

// 9. Discovery that is still paging or backing off is followed up, too.
$request = drain_reset( 0 );
Nova_Bridge_Suite_Posting_Delivery::guarded_ready( $request );
Nova_Bridge_Suite_Posting_Worker::$during = static function () { Nova_Bridge_Suite_Posting_Jobs::$discovery['next_attempt'] = time() + 300; ++Nova_Bridge_Suite_Posting_Jobs::$discovery['wake_sequence']; };
Nova_Bridge_Suite_Posting_Delivery::guarded_run_queued();
drain_check( 'scheduled' === drain_last_run()['follow_up'] && time() + 300 === $GLOBALS['cron'][$hook], 'A wake held back by discovery back-off runs when the back-off ends.' );

// 10. Status, routes and the upgrade path.
$request = drain_reset( 1 );
$due = time() + 600; Nova_Bridge_Suite_Posting_Jobs::$rows[1]['next_attempt'] = $due;
Nova_Bridge_Suite_Posting_Delivery::guarded_ready( $request ); Nova_Bridge_Suite_Posting_Delivery::guarded_run_queued();
drain_check( gmdate( 'c', $due ) === Nova_Bridge_Suite_Posting_Delivery::status( $request )->data['next_scheduled'], 'Administrator status shows when the next batch is due.' );
Nova_Bridge_Suite_Posting_Delivery::register_routes();
drain_check( 'POST' === $GLOBALS['routes']['/posting/continue']['methods'] && '__return_true' === $GLOBALS['routes']['/posting/continue']['permission_callback'], 'The continuation route is public and authenticated by its one-time token.' );
$GLOBALS['options'][Nova_Bridge_Suite_Posting_Delivery::BOOTSTRAP_OPTION] = hash( 'sha256', 'unchanged' );
Nova_Bridge_Suite_Posting_Delivery::initialize();
drain_check( $due === $GLOBALS['cron'][$hook], 'Ordinary page loads keep the single follow-up event.' );
echo 'PASS ' . $checks . " batch follow-up checks.\n";
