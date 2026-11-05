<?php
/** Real bounded worker and signed local wake; every HTTP call and journal write stays in memory. */
require __DIR__ . '/webhook-worker-unit.php';
$checks = 0;
function rest_url( $path ) { return ( $GLOBALS['local_rest_origin'] ?? 'https://wordpress.fixture.invalid' ) . '/wp-json/' . $path; }
// Declare after the legacy fixture has run, so its provider-free behavior remains covered.
if ( true ) {
    class TRP_Translate_Press {
        public static function get_trp_instance() { return new self(); }
        public function get_component( $name ) { return $this; }
        public function get_settings() { return [ 'default-language' => 'en_US', 'publish-languages' => [ 'en_US', 'de_DE' ], 'url-slugs' => [ 'en_US' => 'en', 'de_DE' => 'de' ] ]; }
    }
}
class Family_Continuation_Store extends Wake_Store {
    public $rows = []; public $source_id = 3; public $delay = false; public $claim_failure_after = null; public $wake_calls = 0;
    public function enqueue_discovered( $site, $item, $checkpoint ) { return true; }
    public function claim( $site, $paused ) {
        if ( $this->claim_failure_after !== null && $this->claims >= $this->claim_failure_after ) { return new WP_Error( 'nova_posting_storage' ); }
        foreach ( $this->rows as $id => $row ) {
            if ( $row['state'] !== 'ready' || $row['next_attempt'] > time() || $row['lease_until'] > time() || $paused ) { continue; }
            if ( $this->delay ) { $this->delay = false; usleep( 20100000 ); }
            ++$this->claims; $row['state'] = 'running'; ++$row['attempts']; $row['lease_until'] = time() + 300;
            return $this->rows[$id] = $row;
        }
        return null;
    }
    public function save( $job, $changes ) {
        $next = parent::save( $job, $changes );
        if ( ! is_wp_error( $next ) ) { $this->rows[$next['id']] = $next; }
        return $next;
    }
    public function wake_translation_dependents( $original ) {
        if ( $original['content_item_id'] !== (string) ( 100 + $this->source_id ) ) { return 0; }
        ++$this->wake_calls; $count = 0;
        foreach ( $this->rows as &$row ) {
            if ( $row['state'] === 'blocked' && $row['last_error'] === 'nova_translation_source_pending' ) {
                $row['state'] = 'ready'; $row['last_error'] = 'nova_translation_source_ready'; ++$count;
            }
        }
        return $count;
    }
    public function next_translation_dependent( $site ) {
        foreach ( $this->rows as $row ) {
            if ( $row['state'] === 'ready' && $row['last_error'] === 'nova_translation_source_ready' && $row['next_attempt'] <= time() && $row['lease_until'] === 0 ) {
                return $GLOBALS['server']->fixtures[$row['content_id']]['snapshot'];
            }
        }
        return null;
    }
}
class Family_Continuation_Server extends Wake_Server {
    public $fixtures = []; public $wakes = []; public $fail_loopback = false; public $fail_read = false;
    public function request( $url, $args ) {
        if ( $url === rest_url( 'nova-bridge/v1/posting/ready' ) ) {
            $this->wakes[] = [ $url, $args ];
            delivery_check( $args['method'] === 'POST' && $args['blocking'] === false && $args['redirection'] === 0 && $args['timeout'] === 1 && $args['sslverify'] && $args['reject_unsafe_urls'], 'Continuation uses bounded safe nonblocking local HTTP.' );
            delivery_check( ! isset( $args['headers']['Authorization'] ) && ! str_contains( json_encode( $args ), 'synthetic-test-token-only' ), 'Continuation contains no posting-service access token.' );
            $valid = Nova_Bridge_Suite_Posting_Delivery::authenticate( $args['body'], $args['headers']['X-Nova-Timestamp'], $args['headers']['X-Nova-Signature'], Nova_Bridge_Suite_Posting_Settings::connection() );
            delivery_check( ! is_wp_error( $valid ), 'Continuation uses the existing authenticated ready-event contract.' );
            return $this->fail_loopback ? new WP_Error( 'fixture_network' ) : self::reply( 202, '{}' );
        }
        if ( str_contains( $url, '/deliveries?' ) ) { return self::reply( 200, '{"items":[],"next_cursor":null}' ); }
        preg_match( '#/deliveries/([^/?]+)#', $url, $match );
        $f = $this->fixtures[$match[1]]; $this->fixture = $f; $this->raw = $f['raw']; $this->etag = $f['etag']; $this->attempt = $f['attempt_id'];
        $this->read_status = $this->fail_read ? 401 : 200;
        return parent::request( $url, $args );
    }
}
function continuation_fixture( $count = 8 ) {
    [ , , $request ] = wake_fixture();
    $f = $GLOBALS['wake_fixture'];
    $GLOBALS['wake_store'] = $store = new Family_Continuation_Store();
    $GLOBALS['server'] = $server = new Family_Continuation_Server( $f );
    for ( $i = 1; $i <= $count; ++$i ) {
        $content = $f['snapshot']; $content['id'] = sprintf( '00000000-0000-4000-8000-%012d', 1000 + $i );
        $content['url_id'] = (string) ( 10 + $i ); $content['content_item_id'] = (string) ( 100 + $i ); $content['content_item_version_id'] = (string) ( 200 + $i );
        $content['url_family'] = [ 'url_group_id' => '50', 'primary_url_id' => '13', 'primary_url' => 'https://native.fixture.invalid/service/', 'primary_language' => 'en-US' ];
        $fixture = nova_contract_fixture( [ 'content' => $content ] ); $fixture['job']['id'] = $i;
        $fixture['job']['state'] = $i <= 3 ? 'ready' : 'blocked'; $fixture['job']['lease_until'] = 0;
        $fixture['job']['last_error'] = $i <= 3 ? '' : 'nova_translation_source_pending';
        $store->rows[$i] = $fixture['job']; $server->fixtures[$content['id']] = $fixture;
    }
    return [ $store, $server, delivery_worker( $f, $store ), $request ];
}
[ $store, $server, $worker ] = continuation_fixture();
$worker->run();
delivery_check( $store->claims === 3 && $store->wake_calls === 1 && count( $server->wakes ) === 1, 'Original occupying the last batch slot wakes five dependents and schedules exactly one continuation.' );
delivery_check( count( array_filter( $store->rows, static function ( $row ) { return $row['last_error'] === 'nova_translation_source_ready'; } ) ) === 5, 'Newly awakened rows remain ready until the next batch.' );
// Simulate separate requests emitted by the verified signed local transport above.
$worker->run();
delivery_check( $store->claims === 6 && count( $server->wakes ) === 2, 'More than three awakened dependencies continue in another bounded batch.' );
$worker->run();
delivery_check( $store->claims === 8 && count( $server->wakes ) === 2 && count( array_filter( $store->rows, static function ( $row ) { return $row['state'] === 'complete'; } ) ) === 8, 'Final batch drains all work and does not schedule an idle loop.' );

[ $store, $server, $worker ] = continuation_fixture( 4 );
$store->source_id = 1; $store->delay = true;
$worker->run();
delivery_check( $store->claims === 1 && count( $server->wakes ) === 1, 'Exhausting the real twenty-second budget after source publication still continues its ready dependency.' );

foreach ( [ 'claim', 'auth', 'paused', 'future', 'blocked', 'unrelated' ] as $case ) {
    [ $store, $server, $worker ] = continuation_fixture( 4 );
    $store->source_id = 0; $store->rows[4]['state'] = 'ready'; $store->rows[4]['last_error'] = 'nova_translation_source_ready';
    if ( $case === 'claim' ) { $store->claim_failure_after = 0; }
    if ( $case === 'auth' ) { $server->fail_read = true; }
    if ( $case === 'paused' ) { $f = $GLOBALS['wake_fixture']; $f['connection']['paused'] = true; $worker = delivery_worker( $f, $store ); }
    if ( $case === 'future' ) { $store->rows[4]['next_attempt'] = time() + 100; }
    if ( $case === 'blocked' ) { $store->rows[4]['state'] = 'blocked'; }
    if ( $case === 'unrelated' ) { $store->rows[4]['last_error'] = 'nova_posting_transport'; }
    $worker->run();
    delivery_check( count( $server->wakes ) === 0, $case . ' cannot self-trigger a dependency continuation.' );
}

[ $store, $server, $worker ] = continuation_fixture(); $server->fail_loopback = true; $observed = [];
$worker->run( static function ( $summary ) use ( &$observed ) { $observed[] = $summary; } );
delivery_check( count( $server->wakes ) === 1 && $store->next_translation_dependent( $GLOBALS['wake_fixture']['connection']['site_id'] ) !== null, 'Failed local wake retains recoverable ready work without retrying HTTP in a loop.' );
delivery_check( in_array( 'nova_translation_continuation', array_column( $observed, 'last_error' ), true ), 'Loopback failure is visible as an explicit safe diagnostic.' );
$snapshot = $server->fixtures[$store->rows[4]['content_id']]['snapshot']; $before = count( $server->wakes );
$snapshot['url'] = 'https://foreign.invalid/untrusted'; Nova_Bridge_Suite_Posting_Delivery::continue_dependencies( $snapshot );
delivery_check( $server->wakes[$before][0] === 'https://wordpress.fixture.invalid/wp-json/nova-bridge/v1/posting/ready', 'Delivery URL never controls the loopback destination.' );
$snapshot['site_id'] = 'other-site'; Nova_Bridge_Suite_Posting_Delivery::continue_dependencies( $snapshot );
delivery_check( count( $server->wakes ) === $before + 1, 'Another site cannot emit a local continuation.' );
$GLOBALS['local_rest_origin'] = 'http://wordpress.fixture.invalid'; $snapshot['site_id'] = $GLOBALS['wake_fixture']['connection']['site_id'];
delivery_check( Nova_Bridge_Suite_Posting_Delivery::continue_dependencies( $snapshot )->get_error_code() === 'nova_translation_continuation' && count( $server->wakes ) === $before + 1, 'Unsigned HTTP cannot receive a continuation secret-derived signature.' );
echo 'PASS ' . $checks . " grouped continuation checks.\n";
