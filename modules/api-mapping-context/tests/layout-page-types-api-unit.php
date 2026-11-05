<?php
require __DIR__ . '/layout-recipes-api-unit.php';
$d = design( 'core', 'static', 'post_type:page' );
$body = save_body( $d, $d['recipe_preset'], $d['sample'] );
$body['posting_page_type'] = 'service'; $body['posting_page_types'] = [ 'service', 'category' ];
$saved = data( route( 'save', 'POST', [], $body ) );
check( $saved['posting_page_types'] === [ 'service', 'category' ], 'Saving accepts both source types for native Pages.' );
$loaded = data( route( 'profile', 'GET', [ 'id' => $saved['id'], 'revision' => 1 ] ) );
check( $loaded['posting_page_types'] === $saved['posting_page_types'], 'Reopening preserves both source types.' );
foreach ( [ [], [ 'unknown' ], [ 'service', 'service' ], [ [ 'service' ] ], 'service' ] as $bad ) {
    $invalid = $body; $invalid['posting_page_types'] = $bad;
    rejected( route( 'save', 'POST', [], $invalid ), 400, 'Malformed content-type selection was accepted.' );
}
$d = design( 'core', 'static', 'taxonomy:product_cat' );
$body = save_body( $d, $d['recipe_preset'], $d['sample'], 'taxonomy:product_cat' );
$body['posting_page_types'] = [ 'service', 'category' ];
rejected( route( 'save', 'POST', [], $body ), 400, 'WooCommerce terms were incorrectly enabled for Service.' );
echo "PASS multiple content types through the layout API\n";
