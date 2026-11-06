<?php
/** Real webhook/discovery/worker entry points share the switch lock. No DB/network. */
require __DIR__ . '/webhook-worker-unit.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-connection.php';
function wp_cache_delete( $key, $group ) { $GLOBALS['cleared'][] = $key; }
$checks = 0;
[ $store, $server, $request ] = wake_fixture();
$store->locks['connection'] = true;
$result = Nova_Bridge_Suite_Posting_Delivery::guarded_ready( $request );
delivery_check( is_wp_error( $result ) && 'nova_posting_connection_busy' === $result->get_error_code() && 0 === $store->state['wake_sequence'], 'A switching connection cannot accept a stale webhook wake.' );
unset( $store->locks['connection'] );
$result = Nova_Bridge_Suite_Posting_Delivery::guarded_ready( $request );
delivery_check( 202 === $result->status && ! isset( $store->locks['connection'] ), 'Ready wakes release the shared connection lock.' );
$server->on_discovery = static function () use ( $store ) { delivery_check( isset( $store->locks['connection'] ), 'The runner holds the connection lock across remote discovery and native processing.' ); };
Nova_Bridge_Suite_Posting_Delivery::guarded_run_queued();
delivery_check( 'complete' === $store->row['state'] && 1 === Contract_Test_Writer::$apply && ! isset( $store->locks['connection'] ), 'Guarded delivery still publishes exactly once and releases its lock.' );
delivery_check( in_array( Nova_Bridge_Suite_Posting_Settings::OPTION, $GLOBALS['cleared'], true ), 'Worker entry invalidates connection data cached before a switch.' );
[ $store, $server, $request ] = wake_fixture();
Nova_Bridge_Suite_Posting_Settings::$connection['webhook_secret'] = 'production-secret';
$result = Nova_Bridge_Suite_Posting_Delivery::guarded_ready( $request );
delivery_check( is_wp_error( $result ) && 0 === $store->state['wake_sequence'] && 0 === Contract_Test_Writer::$apply, 'Old test signatures cannot wake the production connection.' );
echo "PASS {$checks} connection worker checks\n";
