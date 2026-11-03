<?php
/** Offline HTTP transport checks. The WordPress transport is an in-memory response queue. */
define( 'ABSPATH', __DIR__ ); define( 'NOVA_BRIDGE_SUITE_VERSION', '3.0.0' );
class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; } public function get_error_data() { return $this->data; } public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_parse_url( $value ) { return parse_url( $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_option( $key, $default = false ) { return $GLOBALS['role_options'][$key] ?? $default; }
function wp_cache_delete( $key, $group ) {}
class Nova_Bridge_Suite_Posting_Jobs {
    public static $locked = false;
    public static $on_lock;
    public function lock( $key ) {
        if ( self::$locked ) { return new WP_Error( 'busy' ); }
        self::$locked = true;
        if ( self::$on_lock ) { ( self::$on_lock )(); self::$on_lock = null; }
        return true;
    }
    public function unlock( $key ) { self::$locked = false; }
}
class Nova_Bridge_Suite_Posting_Settings {
    public const OPTION = 'nova_bridge_posting_connection';
    public static function connection() { return get_option( self::OPTION, [] ); }
}
function wp_safe_remote_request( $url, $args ) { $GLOBALS['calls'][] = [ $url, $args ]; if ( ! $GLOBALS['responses'] ) { throw new RuntimeException( 'Unexpected HTTP request.' ); } return array_shift( $GLOBALS['responses'] ); }
function wp_remote_retrieve_body( $value ) { return $value['body']; }
function wp_remote_retrieve_response_code( $value ) { return $value['response']['code']; }
function wp_remote_retrieve_header( $value, $name ) { return $value['headers'][strtolower($name)] ?? ''; }
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-client.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-protocol.php';
require __DIR__ . '/contract-fixture.php';
$checks = 0; $calls = []; $responses = [];
function client_check( $ok, $message ) { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( $message ); } }
function client_response( $status, $body, $headers = [] ) { return [ 'response' => [ 'code' => $status ], 'body' => $body, 'headers' => $headers ]; }
$fixture = nova_contract_fixture(); $client = new Nova_Bridge_Suite_Posting_Client( $fixture['connection'] ); $suffix = '/deliveries/' . $fixture['snapshot']['id'];
$responses[] = client_response( 200, $fixture['raw'], [ 'etag' => $fixture['etag'], 'x-nova-attempt-id' => $fixture['attempt_id'] ] );
$response = $client->site_request( 'GET', $suffix );
client_check( $response['raw_body'] === $fixture['raw'] && $response['etag'] === $fixture['etag'] && $response['attempt_id'] === $fixture['attempt_id'], 'Transport preserves exact body, strong ETag and current attempt header.' );
client_check( $fixture['snapshot'] === Nova_Bridge_Suite_Posting_Protocol::snapshot( $response, $fixture['job'] ), 'Real client response passes exact protocol intake.' );
$request = $calls[0];
client_check( $request[0] === $fixture['connection']['base_url'] . '/v1/sites/' . $fixture['connection']['site_id'] . $suffix && 'Bearer ' . $fixture['connection']['token'] === $request[1]['headers']['Authorization'], 'Site token addresses only configured origin and site.' );
client_check( 0 === $request[1]['redirection'] && true === $request[1]['sslverify'] && true === $request[1]['reject_unsafe_urls'], 'Credential-bearing requests keep TLS and no redirects.' );
$event = nova_contract_fixture_event( $fixture ); $bytes = json_encode( $event, JSON_PRETTY_PRINT );
$ack = nova_contract_fixture_event_accept( $bytes );
$responses[] = client_response( 202, json_encode( $ack ) ); $response = $client->site_request_bytes( 'POST', $suffix . '/events', $bytes );
client_check( $bytes === end($calls)[1]['body'] && $ack === Nova_Bridge_Suite_Posting_Protocol::accepted( $response, $event ), 'Durable event bytes sent unchanged and202 remains pending.' );
$responses[] = client_response( 202, json_encode( $ack ) ); $client->site_request_bytes( 'POST', $suffix . '/events', $bytes );
client_check( $calls[1][1]['body'] === $calls[2][1]['body'], 'Lost-ack retry sends identical request bytes.' );
foreach ( [ "\r\n", str_repeat('a', 600), [ $fixture['attempt_id'], $fixture['attempt_id'] ] ] as $invalid ) {
    $responses[] = client_response( 200, $fixture['raw'], [ 'etag' => $fixture['etag'], 'x-nova-attempt-id' => $invalid ] );
    $response = $client->site_request( 'GET', $suffix ); client_check( '' === $response['attempt_id'] && is_wp_error( Nova_Bridge_Suite_Posting_Protocol::snapshot( $response, $fixture['job'] ) ), 'Invalid or duplicate identity header cannot be normalized into authority.' );
}
foreach ( [ 401, 403, 429, 503 ] as $status ) {
    $responses[] = client_response( $status, '<html>gateway error, confidential detail</html>', [ 'retry-after' => '172800' ] ); $error = $client->site_request( 'GET', '/deliveries?limit=100' );
    client_check( is_wp_error($error) && $status === $error->get_error_data()['status'] && '172800' === $error->get_error_data()['retry_after'] && false === strpos($error->get_error_message(),'confidential'), 'Non-JSON error retains suspension/backoff status without exposing response: ' . $status );
}
$responses[] = client_response( 409, '{"error":{"code":"conflict","message":"secret remote detail"}}' ); $error = $client->site_request( 'GET', $suffix );
client_check( 'nova_posting_conflict' === $error->get_error_code() && false === strpos($error->get_error_message(),'secret'), 'Remote error code retained without remote message.' );
$responses[] = client_response( 200, '<html>invalid success</html>' ); client_check( 'nova_posting_response_json' === $client->site_request('GET',$suffix)->get_error_code(), 'Invalid success JSON rejected.' );
$responses[] = client_response( 302, '', [ 'location' => 'https://outside.invalid/' ] ); client_check( 'nova_posting_redirect' === $client->site_request('GET',$suffix)->get_error_code(), 'Redirect never followed.' );
$before = count($calls);
foreach ( [ '//outside.invalid/', '/../sites/other', '/%2e%2e/%2foutside', '/deliveries?x=%0d%0aInjected' ] as $bad ) { client_check( is_wp_error($client->site_request('GET',$bad)), 'Path escape rejected before HTTP.' ); }
client_check( is_wp_error($client->request('GET','/v1/sites/00000000-0000-4000-8000-000000000999/deliveries')) && count($calls) === $before, 'Wrong site never receives configured token.' );
client_check( is_wp_error($client->site_request('GET',$suffix,null,['Authorization'=>'replacement'])) && count($calls) === $before, 'Caller cannot replace auth header.' );
client_check( is_wp_error($client->site_request_bytes('POST',$suffix.'/events','[]')) && count($calls) === $before, 'Event wire body must be an object.' );
$responses[] = client_response( 200, str_repeat('x',1048577) ); client_check( 'nova_posting_response_size' === $client->site_request('GET',$suffix)->get_error_code(), 'Oversized response fails closed without truncation acceptance.' );
$connection = $fixture['connection']; $site = $connection['site_id'];
$index = 'nova_shared_layout_index_' . hash( 'sha256', $connection['base_url'] . "\n" . $site );
$responses[] = client_response( 201, '{}' );
client_check( 201 === $client->site_request( 'POST', '/templates', [] )['status'] && ! Nova_Bridge_Suite_Posting_Jobs::$locked, 'A primary can still publish a template.' );
$locked_client = new Nova_Bridge_Suite_Posting_Client( $connection + [ 'receive_shared_layouts' => true ] );
foreach ( [ 'checkbox', 'first import', 'damaged index' ] as $mode ) {
    $role_options = 'checkbox' === $mode ? [] : [ $index => ( 'first import' === $mode ? [ 'selected revision' ] : 'damaged' ) ];
    $actor = 'checkbox' === $mode ? $locked_client : $client;
    $before = count( $calls );
    foreach ( [ 'POST', 'PUT', 'DELETE' ] as $method ) {
        foreach ( [ '/templates', '/templates/' . $fixture['snapshot']['id'], '/template-defaults', '/pages/123/setting', '/%74emplates?flag=1' ] as $route ) {
            client_check( 'nova_shared_layout_subscriber' === $actor->site_request( $method, $route )->get_error_code(), 'The subscriber blocks ' . $method . ' ' . $route . ' via ' . $mode );
        }
    }
    client_check( 'nova_shared_layout_subscriber' === $actor->site_request_bytes( 'POST', '/templates', '{}' )->get_error_code() && 'nova_shared_layout_subscriber' === $actor->request( 'POST', '/v1/sites/' . $site . '/templates' )->get_error_code() && count( $calls ) === $before, 'No helper sends configuration HTTP from a subscriber.' );
    foreach ( [ '/templates', '/template-defaults', '/pages/123/setting', '/deliveries', $suffix ] as $read ) {
        $responses[] = client_response( 200, '{}' ); client_check( 200 === $actor->site_request( 'GET', $read )['status'], 'Subscriber reads ' . $read );
    }
    $responses[] = client_response( 202, '{}' );
    client_check( 202 === $actor->site_request_bytes( 'POST', $suffix . '/events', $bytes )['status'] && $bytes === end( $calls )[1]['body'], 'Subscriber receipts keep their exact durable wire bytes.' );
}
$role_options = []; $before = count( $calls );
Nova_Bridge_Suite_Posting_Jobs::$on_lock = static function () use ( $index ) { $GLOBALS['role_options'][$index] = [ 'activated by another request' ]; };
client_check( 'nova_shared_layout_subscriber' === $client->site_request( 'PUT', '/template-defaults', [] )->get_error_code() && count( $calls ) === $before && ! Nova_Bridge_Suite_Posting_Jobs::$locked, 'Role is rechecked after acquiring the lock shared with import.' );
$role_options = [ Nova_Bridge_Suite_Posting_Settings::OPTION => $connection + [ 'receive_shared_layouts' => true ] ];
client_check( 'nova_shared_layout_subscriber' === $client->site_request( 'POST', '/templates' )->get_error_code() && count( $calls ) === $before, 'A stale client respects newly saved receive-only settings.' );
$role_options[$index] = [ 'active' ];
$canonical_client = new Nova_Bridge_Suite_Posting_Client( array_replace( $connection, [ 'base_url' => $connection['base_url'] . ':443/' ] ) );
client_check( 'nova_shared_layout_subscriber' === $canonical_client->site_request( 'POST', '/templates' )->get_error_code(), 'An explicit default HTTPS port cannot change the subscriber role.' );
foreach ( [ [ 'base_url' => 'https://other.fixture.invalid' ], [ 'site_id' => '00000000-0000-4000-8000-000000000444' ] ] as $other ) {
    $other_client = new Nova_Bridge_Suite_Posting_Client( array_replace( $connection, $other ) );
    $responses[] = client_response( 201, '{}' ); client_check( 201 === $other_client->site_request( 'POST', '/templates' )['status'], 'The subscriber role belongs to this exact origin and NOVA site.' );
}
echo "PASS {$checks} posting client checks\n";
