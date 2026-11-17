<?php
/** Frozen publication intent travels with the exact snapshot and controls native post status. */
require __DIR__ . '/stored-layout-generic-writer-unit.php';
require __DIR__ . '/contract-fixture.php';
$before_checks = $checks;
$wire = nova_contract_fixture();
foreach ( [ 'draft', 'publish' ] as $status ) {
    $snapshot = $wire['snapshot'];
    $snapshot['publication_status'] = $status;
    $raw = json_encode( $snapshot );
    $reply = [ 'status' => 200, 'raw_body' => $raw, 'etag' => '"' . hash( 'sha256', $raw ) . '"', 'attempt_id' => $wire['attempt_id'] ];
    $accepted = Nova_Bridge_Suite_Posting_Protocol::snapshot( $reply, $wire['job'] );
    check( ! is_wp_error( $accepted ) && $accepted === $snapshot, 'Frozen publication status must be accepted without changing the snapshot.' );
    $unified = $snapshot;
    unset( $unified['attempt_id'] );
    $unified['configuration'] = Nova_Bridge_Suite_Posting_Protocol::public_configuration( $snapshot['configuration'] );
    $unified['content'] = [ 'heading' => 'Fixture heading' ];
    check( true === Nova_Bridge_Suite_Posting_Protocol::validate( json_decode( json_encode( $unified ) ), 'Delivery' ), 'The unified delivery accepts publication intent.' );
}
foreach ( [ null, false, 'private', 'pending', '' ] as $status ) {
    $snapshot = $wire['snapshot']; $snapshot['publication_status'] = $status;
    check( is_wp_error( Nova_Bridge_Suite_Posting_Protocol::validate( json_decode( json_encode( $snapshot ) ), 'DeliverySnapshot' ) ), 'Unsupported publication status must be rejected.' );
}
check( true === Nova_Bridge_Suite_Posting_Protocol::validate( json_decode( $wire['raw'] ), 'DeliverySnapshot' ), 'Previously frozen snapshots without publication status still pass.' );

foreach ( [ 'draft', 'publish' ] as $requested ) {
    reset_native();
    [ $content, $configuration ] = fixture( 'core', 'page', '501', $requested === 'draft' ? 'publish' : 'draft' );
    $content['publication_status'] = $requested;
    $policy = ok( Nova_Bridge_Suite_Stored_Layout_Writer::policy( $content, $configuration ) );
    check( $policy['routing']['publication'] === $requested, 'Frozen status overrides the mapping default for a new page.' );
    $plan = ok( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $content, $configuration, [ 'operation_id' => op( 801 ) ] ) );
    check( $plan['desired_status'] === $requested, 'The native plan uses the frozen status.' );
    $result = ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 801 ) ) );
    check( $result['cms_post_status'] === $requested, 'The saved page reaches the frozen status.' );
    [ $update, $configuration ] = fixture( 'core', 'page', '502', 'draft' );
    $update['publication_status'] = $requested === 'draft' ? 'publish' : 'draft';
    $plan = ok( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $update, $configuration, [ 'operation_id' => op( 802 ) ] ) );
    check( $plan['desired_status'] === 'publish', 'An update can publish a draft and keeps a published page live.' );
    $updated = ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 802 ) ) );
    check( $updated['post_id'] === $result['post_id'] && $updated['cms_post_status'] === 'publish', 'Status handling reuses the existing page.' );
}
reset_native();
[ $content, $configuration ] = fixture( 'core', 'page', '501', 'draft' );
$content['publication_status'] = 'publish'; $denied = [ 'publish_page' ];
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $content, $configuration, [ 'operation_id' => op( 803 ) ] ) ), 'Missing publish permission must not silently downgrade the request.' );
check( $inserts === 0, 'A rejected publication request creates no page.' );
reset_native();
check( Nova_Bridge_Suite_Publication_Policy::publication( [ 'url' => '/lorem-ipsum-demo-service/', 'publication_status' => 'publish' ], 'publish' ) === 'draft', 'Existing application demo URLs remain drafts.' );
check( Nova_Bridge_Suite_Publication_Policy::publication( [], 'preserve', 'publish' ) === 'preserve', 'An absent status preserves the existing mapping behavior.' );
echo 'PASS ' . ( $checks - $before_checks ) . " publication-status assertions\n";
