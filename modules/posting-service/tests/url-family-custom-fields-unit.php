<?php
/** Both real localized-field renderers resolve an explicit primary on a different URL ID. */
if ( ! isset( $argv[1] ) ) {
    foreach ( [ 'translatepress', 'weglot' ] as $name ) {
        passthru( escapeshellarg( PHP_BINARY ) . ' -n ' . escapeshellarg( __FILE__ ) . ' ' . $name, $status );
        if ( $status ) { exit( $status ); }
    }
    return;
}
$grouped_source = true;
require __DIR__ . '/localized-custom-fields-unit.php';
