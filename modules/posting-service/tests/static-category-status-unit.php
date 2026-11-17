<?php
/** Older approved categories are reconciled through GETs and their exact local authority. */
require __DIR__ . '/static-category-sync-unit.php';
if ( ! class_exists( 'Nova_Bridge_Suite_Posting_Settings' ) ) {
    class Nova_Bridge_Suite_Posting_Settings { public static function connection() { return $GLOBALS['status_connection']; } }
}
$GLOBALS['status_connection'] = nova_contract_fixture()['connection'];
[ $c, $client, $locks ] = editor_fixture();
$GLOBALS['status_connection'] = $c;
$result = editor_sync( $c, $client, $locks ); $record = Nova_Bridge_Suite_Layout_Recipes::$record;
$checks = 0;
delivery_check( Nova_Bridge_Suite_Static_Recipe_Adapter::saved_status( $c, $record['id'], $record['revision'] )['status'] === 'synced', 'Successful category editor synchronization retains its exact revision status.' );
foreach ( array_keys( $options ) as $key ) { if ( str_starts_with( $key, 'nova_static_recipe_v1_status_' ) ) { unset( $options[$key] ); } }
$client->requests = [];
$status = Nova_Bridge_Suite_Static_Recipe_Adapter::confirm_status( $c, $record['id'], $record['revision'], $client );
delivery_check( $status['status'] === 'synced' && array_column( $client->requests, 0 ) === [ 'GET', 'GET' ], 'A legacy approved mapping is confirmed with reads and never republished.' );
delivery_check( null === Nova_Bridge_Suite_Static_Recipe_Adapter::saved_status( $c, $record['id'], $record['revision'] + 1 ), 'An old category status cannot confirm another local revision.' );
$other = array_replace( $c, [ 'base_url' => 'https://other.example' ] );
delivery_check( null === Nova_Bridge_Suite_Static_Recipe_Adapter::saved_status( $other, $record['id'], $record['revision'] ), 'Category status does not cross connection environments.' );
$client->defaults = [];
delivery_check( Nova_Bridge_Suite_Static_Recipe_Adapter::confirm_status( $c, $record['id'], $record['revision'], $client )['status'] === 'pending', 'An empty remote default confirms that the local mapping needs synchronization.' );
$client->defaults = [ 'category' => $client->remote['id'] ]; $client->remote['revision']++;
delivery_check( Nova_Bridge_Suite_Static_Recipe_Adapter::confirm_status( $c, $record['id'], $record['revision'], $client )['status'] === 'pending', 'A changed remote default without matching local authority needs review.' );
$offline = new class( $c ) extends Static_Editor_Client { public function site_request( string $method, string $suffix, $body = null, array $headers = [] ) { return new WP_Error( 'offline' ); } };
delivery_check( Nova_Bridge_Suite_Static_Recipe_Adapter::confirm_status( $c, $record['id'], $record['revision'], $offline )['status'] === 'unknown', 'An unreachable service remains unknown instead of being declared unsynchronized.' );
echo "PASS {$checks} category synchronization status checks\n";
