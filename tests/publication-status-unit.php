<?php
define( 'ABSPATH', __DIR__ );
function check_status( $condition, $message ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}
require dirname( __DIR__ ) . '/modules/posting-service/includes/class-nova-bridge-suite-publication-status.php';
$status = 'Nova_Bridge_Suite_Publication_Status';

// No status on the delivery: the mapping decides, exactly as before.
foreach ( [ 'preserve', 'draft', 'publish' ] as $mapping ) {
    check_status( $mapping === $status::routing( $mapping, [], true ), "Absent status changed a create ($mapping)." );
    check_status( $mapping === $status::routing( $mapping, [], false ), "Absent status changed an update ($mapping)." );
}
// Anything but the two frozen values is not a status.
foreach ( [ 'Draft', 'private', '', null, 1, [ 'draft' ] ] as $invalid ) {
    check_status( null === $status::from_delivery( [ 'publication_status' => $invalid ] ), 'Accepted an invalid status.' );
    check_status( 'publish' === $status::routing( 'publish', [ 'publication_status' => $invalid ], true ), 'An invalid status overrode the mapping.' );
}

// Creating: the delivery's status wins, including over a hard-coded publish mapping.
check_status( 'draft' === $status::routing( 'publish', [ 'publication_status' => 'draft' ], true ), 'A draft delivery was published on create.' );
check_status( 'publish' === $status::routing( 'draft', [ 'publication_status' => 'publish' ], true ), 'A publish delivery stayed a draft on create.' );
check_status( 'draft' === $status::routing( 'preserve', [ 'publication_status' => 'draft' ], true ), 'Preserve ignored a draft on create.' );

// Updating: publish publishes; draft never takes a live page offline.
check_status( 'publish' === $status::routing( 'preserve', [ 'publication_status' => 'publish' ], false ), 'A publish delivery did not publish on update.' );
check_status( 'preserve' === $status::routing( 'publish', [ 'publication_status' => 'draft' ], false ), 'A draft delivery changed an existing post.' );
check_status( 'preserve' === $status::routing( 'draft', [ 'publication_status' => 'draft' ], false ), 'A draft delivery demoted an existing post.' );

echo "PASS frozen publication status resolution\n";
