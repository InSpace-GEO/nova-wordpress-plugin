<?php
/** Exact delivery validation and locking with optional URL-family metadata. No external services. */
require __DIR__ . '/delivery-unit.php';
$checks = 0;
$family = [ 'url_group_id' => '50', 'primary_url_id' => '100', 'primary_url' => 'https://native.fixture.invalid/original/', 'primary_language' => 'en-US' ];
$snapshot = nova_contract_fixture()['snapshot'];
$snapshot['url_family'] = $family;
$fixture = nova_contract_fixture( [ 'content' => $snapshot ] );
$response = [ 'status' => 200, 'raw_body' => $fixture['raw'], 'etag' => $fixture['etag'], 'attempt_id' => $fixture['attempt_id'] ];
$decoded = Nova_Bridge_Suite_Posting_Protocol::snapshot( $response, $fixture['job'] );
delivery_check( ! is_wp_error( $decoded ) && $decoded['url_family'] === $family, 'Family metadata is retained in a private V1 snapshot.' );
$unified = $snapshot;
unset( $unified['attempt_id'] );
$unified['configuration'] = Nova_Bridge_Suite_Posting_Protocol::public_configuration( $snapshot['configuration'] );
$unified['content'] = [ 'heading' => 'Localized heading' ];
$wire = nova_contract_fixture( [ 'content' => $unified ] );
$wire['raw'] = json_encode( $unified, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
$wire['etag'] = '"' . hash( 'sha256', $wire['raw'] ) . '"';
$result = Nova_Bridge_Suite_Posting_Protocol::snapshot( [ 'status' => 200, 'raw_body' => $wire['raw'], 'etag' => $wire['etag'], 'attempt_id' => $wire['attempt_id'] ], $wire['job'] );
delivery_check( ! is_wp_error( $result ) && $result['url_family'] === $family, 'Unified deliveries retain the same metadata.' );
foreach ( [ [ 'url_group_id', '0' ], [ 'url_group_id', '9223372036854775808' ], [ 'primary_url_id', '9223372036854775808' ], [ 'primary_url_id', 100 ], [ 'primary_language', '' ] ] as [ $key, $value ] ) {
    $bad = $snapshot; $bad['url_family'][$key] = $value;
    $f = nova_contract_fixture( [ 'content' => $bad ] );
    delivery_check( is_wp_error( Nova_Bridge_Suite_Posting_Protocol::snapshot( [ 'status' => 200, 'raw_body' => $f['raw'], 'etag' => $f['etag'], 'attempt_id' => $f['attempt_id'] ], $f['job'] ) ), 'Invalid metadata is rejected before any native work.' );
}
$store = new class extends Contract_Test_Store {
    public $seen = [];
    public function lock( $key ) { $this->seen[] = $key; return parent::lock( $key ); }
};
$server = new Contract_Test_Server( $fixture ); $GLOBALS['server'] = $server;
$done = delivery_worker( $fixture, $store )->process( $fixture['job'] );
delivery_check( $done['state'] === 'complete' && $done['payload']['snapshot_raw'] === $fixture['raw'], 'Worker preserves the exact grouped delivery bytes.' );
delivery_check( in_array( 'publication:' . $snapshot['site_id'] . ':' . $snapshot['url_id'], $store->seen, true ) && in_array( 'publication-group:' . $snapshot['site_id'] . ':50', $store->seen, true ) && !$store->locks, 'Group locking supplements the existing URL lock and both are released.' );
$store = new class extends Contract_Test_Store {
    public function lock( $key ) { return str_starts_with( $key, 'publication-group:' ) ? new WP_Error( 'fixture_busy' ) : parent::lock( $key ); }
};
Contract_Test_Writer::reset();
$server = new Contract_Test_Server( $fixture ); $GLOBALS['server'] = $server;
$busy = delivery_worker( $fixture, $store )->process( $fixture['job'] );
delivery_check( $busy['state'] === 'ready' && $busy['last_error'] === 'target_busy' && Contract_Test_Writer::$apply === 0 && !$store->locks, 'Contended family cannot write or leak the URL lock.' );
echo "URL-family wire checks: $checks passed.\n";
