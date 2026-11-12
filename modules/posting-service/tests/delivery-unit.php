<?php
/** Exercises the production client/worker against a deterministic current API, without a CMS. */
define( 'ABSPATH', __DIR__ . '/' ); define( 'NOVA_BRIDGE_SUITE_VERSION', '3.0.0' );
class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_safe_remote_request( $url, $args ) { return $GLOBALS['server']->request( $url, $args ); }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_header( $r, $key ) { return $r['headers'][$key] ?? ''; }
function wp_generate_uuid4() { static $n = 100; return '11111111-1111-4111-8111-' . sprintf( '%012d', ++$n ); }
function get_current_user_id() { return $GLOBALS['actor'] ?? 0; }
function wp_set_current_user( $id ) { $GLOBALS['actor'] = $id; }
require_once __DIR__ . '/posting-actor-worker-fixture.php';
function get_post_status( $id ) { return class_exists( 'Contract_Test_Writer', false ) ? Contract_Test_Writer::$status : 'publish'; }
function get_post_type( $id ) { return 'post'; }
function get_permalink( $id ) { return $GLOBALS['fixture_permalink'] ?? 'https://native.fixture.invalid/2026/11/06/post-' . $id . '/'; }
function get_sample_permalink( $id ) { return [ 'https://native.fixture.invalid/2026/11/06/%postname%/', 'post-' . $id ]; }
foreach ( [ 'posting-client', 'posting-protocol', 'receipt-json', 'posting-discovery', 'posting-worker' ] as $file ) { require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-' . $file . '.php'; }
require __DIR__ . '/contract-fixture.php';
$checks = 0;
function delivery_check( $condition, $message ) { ++$GLOBALS['checks']; if ( ! $condition ) { throw new RuntimeException( $message ); } }
class Contract_Test_Store {
    public $saved; public $history = []; public $locks = []; public $fail_phase = ''; public $fail_state = ''; public $uncertain = false;
    public function save( $job, $changes ) {
        $next = array_merge( $job, $changes );
        if ( $this->fail_phase === $next['phase'] || $this->fail_state === $next['state'] ) { return new WP_Error( 'fixture_journal_failure' ); }
        $this->history[] = $next; return $this->saved = $next;
    }
    public function lock( $key ) { $this->locks[$key] = true; return true; }
    public function unlock( $key ) { unset( $this->locks[$key] ); }
    public function uncertain_target( $job ) { return $this->uncertain; }
}
class Contract_Test_Writer {
    public static $apply = 0; public static $recover = 0; public static $finish = 0; public static $crash = false; public static $plan_error = false; public static $apply_error = null; public static $verify_error = null; public static $recover_value = null; public static $status = 'publish'; public static $context;
    public static function reset() { self::$apply = self::$recover = self::$finish = 0; self::$crash = self::$plan_error = false; self::$apply_error = self::$verify_error = self::$recover_value = null; self::$status = 'publish'; }
    public static function plan( $snapshot, $config, $context ) { self::$context = $context; return self::$plan_error ? new WP_Error( 'fixture_plan_failed', "The <b>native</b>
 permalink   check failed." ) : [ 'context' => $context ]; }
    public static function result( $plan ) { return [ 'post_id' => $plan['context']['target_post_id'], 'cms_post_status' => self::$status ]; }
    public static function apply( $plan, $operation ) { ++self::$apply; if ( self::$crash ) { throw new RuntimeException( 'Process lost after commit' ); } return self::$apply_error ?? self::result( $plan ); }
    public static function recover( $plan, $operation ) { ++self::$recover; return self::$recover_value ?? self::result( $plan ); }
    public static function finish( $plan, $result ) { ++self::$finish; return $result; }
    public static function verify( $plan, $result ) { return self::$verify_error ?? self::result( $plan ); }
}
class Contract_Test_Server {
    public $fixture; public $calls = []; public $posts = []; public $accepted = []; public $lose = ''; public $throw_after = ''; public $read_status = 200; public $post_status = 0; public $raw; public $etag; public $attempt; public $wrong_ack = false;
    public function __construct( $fixture ) { $this->fixture = $fixture; $this->raw = $fixture['raw']; $this->etag = $fixture['etag']; $this->attempt = $fixture['attempt_id']; }
    public static function reply( $code, $body, $headers = [] ) { return [ 'response' => [ 'code' => $code ], 'body' => $body, 'headers' => $headers ]; }
    public function request( $url, $args ) {
        $this->calls[] = [ $url, $args ];
        delivery_check( $args['sslverify'] && 0 === $args['redirection'] && $args['reject_unsafe_urls'], 'HTTP safety settings retained.' );
        $path = '/v1/sites/' . $this->fixture['job']['site_id'] . '/deliveries/' . $this->fixture['item']['id'];
        delivery_check( false !== strpos( $url, $path ), 'Only current delivery routes used.' );
        if ( 'GET' === $args['method'] ) {
            delivery_check( ! isset( $args['headers']['If-None-Match'] ), 'GET must return full bytes and current attempt.' );
            return 200 === $this->read_status ? self::reply( 200, $this->raw, [ 'etag' => $this->etag, 'x-nova-attempt-id' => $this->attempt ] ) : self::reply( $this->read_status, '{"error":{"code":"fixture_read_error"}}' );
        }
        delivery_check( substr( $url, -7 ) === '/events', 'Current event endpoint selected.' );
        $bytes = $args['body']; $event = json_decode( $bytes, true );
        delivery_check( ! is_wp_error( Nova_Bridge_Suite_Posting_Protocol::event_input( $event ) ), 'Exact conditional event schema.' );
        $this->posts[] = $bytes;
        if ( $this->post_status ) { return self::reply( $this->post_status, '{"error":{"code":"fixture_event_error"}}' ); }
        $id = $event['event_id'];
        if ( isset( $this->accepted[$id] ) && $this->accepted[$id]['bytes'] !== $bytes ) { return self::reply( 409, '{"error":{"code":"conflict"}}' ); }
        $this->accepted[$id] = $this->accepted[$id] ?? [ 'bytes' => $bytes, 'ack' => nova_contract_fixture_event_accept( $bytes ) ];
        if ( $this->throw_after === $event['kind'] ) { $this->throw_after = ''; throw new RuntimeException( 'Process exception after event acceptance' ); }
        if ( $this->lose === $event['kind'] ) { $this->lose = ''; return new WP_Error( 'fixture_ack_lost' ); }
        $ack = $this->accepted[$id]['ack']; if ( $this->wrong_ack ) { $ack['attempt_id'] = '00000000-0000-4000-8000-000000000099'; }
        return self::reply( 202, json_encode( $ack ) );
    }
}
function delivery_fixture( $patch = [] ) {
    Contract_Test_Writer::reset(); $f = nova_contract_fixture(); $f['connection'] = array_merge( $f['connection'], $patch );
    $s = new Contract_Test_Store(); $server = new Contract_Test_Server( $f ); $GLOBALS['server'] = $server;
    return [ $f['job'], $s, $server, delivery_worker( $f, $s ) ];
}
function delivery_worker( array $f, $s ) {
    $configuration = static function ( $snapshot, $site ) use ( $f ) { return $f['configuration']; };
    $policy = static function () use ( $f ) { return [ 'reference' => [ 'reference_type' => 'post', 'reference_id' => 77 ], 'routing' => [ 'operation' => 'update', 'publication' => 'publish' ] ]; };
    return new Nova_Bridge_Suite_Posting_Worker( $f['connection'], $s, new Nova_Bridge_Suite_Posting_Client( $f['connection'] ), $configuration, 'Contract_Test_Writer', $policy );
}
function delivery_resume( $job ) { $job['state'] = 'running'; return $job; }

[ $job, $store, $server, $worker ] = delivery_fixture(); $GLOBALS['actor'] = 9;
$result = $worker->process( $job );
delivery_check( 'complete' === $result['state'] && 1 === Contract_Test_Writer::$apply && [] === $store->locks && 9 === get_current_user_id(), 'One native write, locks and actor restored.' );
$events = array_map( static function ( $b ) { return json_decode( $b, true ); }, $server->posts );
delivery_check( [ 'received_complete', 'publication_succeeded' ] === array_column( $events, 'kind' ) && $events[0]['event_id'] !== $events[1]['event_id'], 'Separate persisted IDs for receipt and publication.' );
delivery_check( 'pending' === $result['payload']['events']['publication_succeeded']['accepted']['status'], 'Acceptance does not claim backend application.' );
delivery_check( $result['payload']['snapshot_raw'] === $server->raw && $events[1]['delivery_etag'] === $server->etag && $events[1]['source_sha256'] !== hash( 'sha256', $server->raw ), 'Raw delivery and backend source hash remain separate.' );

foreach ( [ 'received_complete', 'publication_succeeded' ] as $lost ) {
    [ $job, $store, $server, $worker ] = delivery_fixture(); $server->lose = $lost;
    $result = $worker->process( $job );
    delivery_check( 'event_pending' === $result['phase'] && 'ready' === $result['state'], 'Lost acknowledgement retains durable event bytes.' );
    $pending = $result['payload']['event_bytes']; $result = $worker->process( delivery_resume( $result ) );
    delivery_check( 'complete' === $result['state'] && 1 === Contract_Test_Writer::$apply && count( $server->accepted ) === 2, 'Exact event replay continues once without repeating native write.' );
    delivery_check( count( array_filter( $server->posts, static function ( $b ) use ( $pending ) { return $pending === $b; } ) ) === 2, 'Lost response replays identical event ID and bytes.' );
}
foreach ( [ 'received_complete', 'publication_succeeded' ] as $kind ) {
    [ $job, $store, $server, $worker ] = delivery_fixture(); $server->throw_after = $kind;
    $result = $worker->process( $job );
    delivery_check( 'event_pending' === $result['phase'] && isset( $result['payload']['event_bytes'] ), 'Thrown exception preserves the newly saved event journal.' );
    $pending = $result['payload']['event_bytes']; $result = $worker->process( delivery_resume( $result ) );
    delivery_check( 'complete' === $result['state'] && 1 === Contract_Test_Writer::$apply && count( $server->accepted ) === 2, 'Exception recovery creates no new event identity or native write.' );
    delivery_check( 2 === count( array_filter( $server->posts, static function ( $bytes ) use ( $pending ) { return $bytes === $pending; } ) ), 'Exception recovery replays the exact accepted event bytes.' );
}
[ $job, $store, $server, $worker ] = delivery_fixture(); Contract_Test_Writer::$crash = true;
$result = $worker->process( $job );
delivery_check( 'applying' === $result['phase'] && 'ready' === $result['state'], 'Process loss retains uncertain mutation phase.' );
Contract_Test_Writer::$crash = false; $result = $worker->process( delivery_resume( $result ) );
delivery_check( 'complete' === $result['state'] && 1 === Contract_Test_Writer::$apply && 1 === Contract_Test_Writer::$recover, 'Native commit is recovered without a second write.' );

foreach ( [ 'tampered_bytes', 'weak_etag', 'missing_attempt', 'wrong_site', 'legacy_member', 'unexpected_304' ] as $case ) {
    [ $job, $store, $server, $worker ] = delivery_fixture();
    if ( 'tampered_bytes' === $case ) { $server->raw .= ' '; }
    elseif ( 'weak_etag' === $case ) { $server->etag = 'W/' . $server->etag; }
    elseif ( 'missing_attempt' === $case ) { $server->attempt = ''; }
    elseif ( 'unexpected_304' === $case ) { $server->read_status = 304; }
    else {
        $body = json_decode( $server->raw, true );
        if ( 'wrong_site' === $case ) { $body['site_id'] = '00000000-0000-4000-8000-000000000099'; }
        else { $body['pin'] = []; }
        $server->raw = json_encode( $body ); $server->etag = '"' . hash( 'sha256', $server->raw ) . '"';
    }
    $result = $worker->process( $job );
    delivery_check( 'blocked' === $result['state'] && 0 === Contract_Test_Writer::$apply && [] === $server->posts, $case . ' stops before any event/write.' );
}
[ $job, $store, $server, $worker ] = delivery_fixture(); $server->attempt = '00000000-0000-4000-8000-000000000099';
$result = $worker->process( $job );
delivery_check( 'complete' === $result['state'] && json_decode( $server->posts[0], true )['attempt_id'] === $server->attempt, 'Header attempt is authoritative over historical body attempt.' );
[ $job, $store, $server, $worker ] = delivery_fixture(); Contract_Test_Writer::$crash = true;
$result = $worker->process( $job ); Contract_Test_Writer::$crash = false; $server->attempt = '00000000-0000-4000-8000-000000000099';
$result = $worker->process( delivery_resume( $result ) );
delivery_check( 'complete' === $result['state'] && 1 === Contract_Test_Writer::$apply && 1 === Contract_Test_Writer::$recover && $result['payload']['attempt_id'] === $server->attempt, 'Changed attempt reconciles the retained native write without duplicating it.' );

foreach ( [ 'draft', 'future', 'pending', 'private', 'publish' ] as $saved_status ) {
    [ $job, $store, $server, $worker ] = delivery_fixture(); Contract_Test_Writer::$status = $saved_status;
    $result = $worker->process( $job );
    $events = array_map( static function ( $bytes ) { return json_decode( $bytes, true ); }, $server->posts );
    delivery_check( 'complete' === $result['state'] && 'committed' === $result['phase'] && 1 === Contract_Test_Writer::$apply, $saved_status . ' completes after one verified native save.' );
    delivery_check( $saved_status === $result['payload']['result']['cms_post_status'] && [ 'received_complete', 'publication_succeeded' ] === array_column( $events, 'kind' ), 'Success attests application while retaining the actual native status.' );
    delivery_check( ! empty( $events[1]['published_at'] ) && false !== strpos( $events[1]['publication_ref'], ';operation:' . $job['operation_id'] ), 'Existing success contract retains the verification timestamp and native operation reference.' );
    $report = $events[1]['publication'] ?? [];
    delivery_check( 'https://native.fixture.invalid/2026/11/06/post-77/' === ( $report['url'] ?? null ) && ( 'publish' === $saved_status ? 'final' : 'provisional' ) === $report['url_status'] && $saved_status === $report['cms_status'], 'Success reports the URL WordPress actually gave the item: ' . $saved_status );
    delivery_check( [ 'system' => 'wordpress', 'type' => 'post', 'id' => '77' ] == $report['native'] && 'https://native.fixture.invalid/service/' === $report['intended_url'] && 'en' === $report['language'] && ! isset( $events[0]['publication'] ), 'The URL report carries the delivery URL, language and native identity on success only.' );
}
[ $job, $store, $server, $worker ] = delivery_fixture(); Contract_Test_Writer::$status = 'draft'; Contract_Test_Writer::$verify_error = new WP_Error( 'fixture_verification_failed' );
$result = $worker->process( $job );
delivery_check( 'ready' === $result['state'] && 'committed' === $result['phase'] && 1 === count( $server->posts ) && 1 === Contract_Test_Writer::$apply, 'A saved draft cannot report success when native verification fails.' );
// Model a retained job from the previous publication-waiting behavior.
Contract_Test_Writer::$verify_error = null; $result['last_error'] = 'awaiting_publication'; $result['next_attempt'] = time() - 1;
$result = $worker->process( delivery_resume( $result ) );
delivery_check( 'complete' === $result['state'] && 1 === Contract_Test_Writer::$apply && 2 === count( $server->posts ) && 'draft' === $result['payload']['result']['cms_post_status'], 'Explicit catch-up verifies an old awaiting-publication draft without repeating its native save.' );

[ $job, $store, $server, $worker ] = delivery_fixture(); Contract_Test_Writer::$plan_error = true;
$result = $worker->process( $job );
delivery_check( 'complete' === $result['state'] && 0 === Contract_Test_Writer::$apply && json_decode( $server->posts[1], true )['kind'] === 'publication_failed', 'Proven pre-write failure is reported separately from receipt.' );
delivery_check( 'The native permalink check failed.' === ( $result['payload']['failure_message'] ?? null ) && ! array_key_exists( 'message', json_decode( $server->posts[1], true ) ), 'A pre-write failure keeps its WordPress message locally without changing the event.' );
$failed = $result; $failed['phase'] = 'attempt_check'; $server->attempt = '00000000-0000-4000-8000-000000000099'; Contract_Test_Writer::$plan_error = false;
$result = $worker->process( delivery_resume( $failed ) );
delivery_check( 'complete' === $result['state'] && 1 === Contract_Test_Writer::$apply && count( $result['payload']['prior_attempts'] ) === 1, 'Operator-issued new attempt proceeds only after retained no-native-write proof.' );
$result['phase'] = 'attempt_check'; $server->attempt = '00000000-0000-4000-8000-000000000098';
$result = $worker->process( delivery_resume( $result ) );
delivery_check( 'complete' === $result['state'] && 1 === Contract_Test_Writer::$apply && count( $result['payload']['prior_attempts'] ) === 2, 'A replacement attempt attests the already verified frozen write without duplicating it.' );

[ $job, $store, $server, $worker ] = delivery_fixture();
Contract_Test_Writer::$apply_error = new WP_Error( 'nova_layout_writer_native', "<b>Salient header</b>\n differs after saving." );
Contract_Test_Writer::$recover_value = [ 'state'=>'not_committed', 'no_native_commit'=>false, 'safe_to_apply'=>true, 'post_id'=>77 ];
$pending = $worker->process( $job );
delivery_check( 'native_commit_reconciliation' === $pending['last_error'] && 'nova_layout_writer_native: Salient header differs after saving.' === $pending['payload']['native_error_detail'], 'Reserved pages retain their real native error alongside reconciliation, with plain text details.' );
delivery_check( count( $server->posts ) === 1 && ! isset( $pending['payload']['failure_code'] ), 'An uncertain commit is not reported as failed or successful.' );
Contract_Test_Writer::$apply_error = null; Contract_Test_Writer::$recover_value = null;
$reconciled = $worker->process( delivery_resume( $pending ) );
delivery_check( 'complete' === $reconciled['state'] && '' === $reconciled['last_error'] && '' === $reconciled['payload']['native_error_detail'] && 1 === Contract_Test_Writer::$apply, 'Successful reconciliation clears stale issue details and does not repeat the write.' );

// An actor can leave between the native commit, verification, and the final acknowledgement.
[ $job, $store, $server, $worker ] = delivery_fixture();
Contract_Test_Writer::$verify_error = new WP_Error( 'fixture_verification_failed' );
$committed = $worker->process( $job );
$f = $server->fixture; $f['connection']['actor_user_id'] = 2; $worker = delivery_worker( $f, $store );
unset( $committed['payload']['actor_user_id'] ); // Earlier releases pinned only the retained context.
$GLOBALS['revoked_actor'] = 1; Contract_Test_Writer::$verify_error = null;
$server->lose = 'publication_succeeded';
$pending = $worker->process( delivery_resume( $committed ) );
$complete = $worker->process( delivery_resume( $pending ) );
delivery_check( 'event_pending' === $pending['phase'] && 'complete' === $complete['state'] && $pending['payload']['event_bytes'] === end( $server->posts ), 'Committed verification and exact pending receipt replay still complete after actor revocation.' );
$server->attempt = '00000000-0000-4000-8000-000000000099'; $complete['phase'] = 'attempt_check';
$replacement = $worker->process( delivery_resume( $complete ) );
delivery_check( 'complete' === $replacement['state'] && 1 === $replacement['payload']['context']['actor_user_id'] && $replacement['payload']['attempt_id'] === $server->attempt, 'A replacement attempt reattests the same verified commitment and author.' );
delivery_check( 1 === Contract_Test_Writer::$apply && 1 === Contract_Test_Writer::$finish && 0 === Contract_Test_Writer::$recover, 'Verification and final receipt replay after revocation call no mutating writer methods.' );
Contract_Test_Writer::$verify_error = [ 'post_id' => 77, 'cms_post_status' => 'draft', 'state' => 'cms_committed', 'derived_pending' => true ];
$server->attempt = $committed['payload']['attempt_id']; $before_posts = count( $server->posts );
$unfinished = $worker->process( delivery_resume( $committed ) );
delivery_check( 'blocked' === $unfinished['state'] && count( $server->posts ) === $before_posts && 1 === Contract_Test_Writer::$finish, 'An unfinished derived write cannot finalize or report success after its actor is revoked.' );
unset( $GLOBALS['revoked_actor'] );

[ $job, $store, $server, $worker ] = delivery_fixture(); Contract_Test_Writer::$plan_error = true;
$failed = $worker->process( $job ); $failed['phase'] = 'attempt_check';
$f = $server->fixture; $f['connection']['actor_user_id'] = 2; $new_worker = delivery_worker( $f, $store );
$GLOBALS['revoked_actor'] = 1; Contract_Test_Writer::$plan_error = false;
$server->attempt = '00000000-0000-4000-8000-000000000098';
$replacement = $new_worker->process( delivery_resume( $failed ) );
delivery_check( 'complete' === $replacement['state'] && 2 === Contract_Test_Writer::$context['actor_user_id'] && 1 === Contract_Test_Writer::$apply, 'A replacement with no retained plan and proven pre-write failure uses the newly selected publisher.' );
$prior = $replacement['payload']['prior_attempts'][0];
delivery_check( 1 === $prior['actor_user_id'] && true === $prior['native_absence_proof']['no_native_commit'] && $prior['events'] === $failed['payload']['events'], 'The failed attempt retains its original actor, proof and accepted receipt bytes.' );
unset( $GLOBALS['revoked_actor'] );

[ $job, $store, $server, $worker ] = delivery_fixture(); Contract_Test_Writer::$crash = true;
$uncertain = $worker->process( $job ); Contract_Test_Writer::$crash = false;
$GLOBALS['revoked_actor'] = 1; $f = $server->fixture; $f['connection']['actor_user_id'] = 2;
$blocked = delivery_worker( $f, $store )->process( delivery_resume( $uncertain ) );
delivery_check( 'blocked' === $blocked['state'] && 'publishing_user_unavailable' === $blocked['last_error'] && 1 === Contract_Test_Writer::$apply && 0 === Contract_Test_Writer::$recover, 'An uncertain native operation cannot recover or reapply under a replacement author.' );
unset( $GLOBALS['revoked_actor'] );

foreach ( [ 'event_pending', 'applying' ] as $phase ) {
    [ $job, $store, $server, $worker ] = delivery_fixture(); $store->fail_phase = $phase;
    $result = $worker->process( $job );
    delivery_check( is_wp_error( $result ) && 0 === Contract_Test_Writer::$apply && count( $server->posts ) === ( 'applying' === $phase ? 1 : 0 ), 'Journal failure precedes external effect at ' . $phase );
}
[ $job, $store, $server, $worker ] = delivery_fixture(); $store->fail_state = 'complete';
$result = $worker->process( $job );
delivery_check( is_wp_error( $result ) && 'event_pending' === $store->saved['phase'], 'Failed local acknowledgement retains outgoing event.' );
$store->fail_state = ''; $result = $worker->process( delivery_resume( $store->saved ) );
delivery_check( 'complete' === $result['state'] && 1 === Contract_Test_Writer::$apply, 'Local acknowledgement recovery never repeats mutation.' );

[ $job, $store, $server, $worker ] = delivery_fixture( [ 'paused' => true ] );
$job['attempts'] = 19;
$result = $worker->process( $job );
delivery_check( 'paused' === $result['last_error'] && 0 === $result['attempts'] && [] === $server->calls && 0 === Contract_Test_Writer::$apply, 'Pause prevents new reads/mutations without exhausting retries.' );
[ $job, $store, $server, $worker ] = delivery_fixture(); $server->post_status = 409;
$result = $worker->process( $job );
delivery_check( 'blocked' === $result['state'] && 0 === Contract_Test_Writer::$apply, 'Event conflict blocks before publishing.' );
[ $job, $store, $server, $worker ] = delivery_fixture(); $server->wrong_ack = true;
$result = $worker->process( $job );
delivery_check( 'blocked' === $result['state'] && 0 === Contract_Test_Writer::$apply, 'Wrong acceptance identity cannot authorize continuation.' );
echo 'PASS ' . $checks . " current delivery execution checks.\n";
