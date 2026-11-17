<?php
/** Connection commits precede explicit layout retries, with active-environment isolation. */
require __DIR__ . '/environment-switch-unit.php';
function get_current_user_id() { return 17; }
function get_transient( $key ) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][$key] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['transients'][$key] ); }
class Nova_Bridge_Suite_Static_Recipe_Adapter {
    public static $status = null;
    public static function saved_status( ...$args ) { return self::$status; }
    public static function confirm_status( ...$args ) { return self::$status = [ 'status' => 'pending' ]; }
}
copy_fixture(); $checks = 0;
Nova_Bridge_Suite_Layout_Recipes::$metadata = [
    'post_type:page' => [ 'label' => 'Page', 'posting_support' => [ 'supported' => true ] ],
    'post_type:post' => [ 'label' => 'Post', 'posting_support' => [ 'supported' => true ], 'posting_sync' => [ 'status' => 'synced' ] ],
    'taxonomy:product_cat' => [ 'label' => 'Category', 'posting_support' => [ 'supported' => true ] ],
    'post_type:service_page' => [ 'posting_support' => [ 'supported' => false ] ],
];
$c = $options['connection'];
$pending = Nova_Bridge_Suite_Connection_Layout_Sync::pending( $c, true );
assert_switch( array_column( $pending, 'id' ) === [ PAGE, CAT ], 'Only unsynchronized current supported defaults are selected, including categories.' );
assert_switch( Nova_Bridge_Suite_Posting_Connection::save( [] ) === true && [] === Nova_Bridge_Suite_Layout_Recipes::$calls, 'An unchanged connection save offers synchronization without sending layouts.' );
assert_switch( Nova_Bridge_Suite_Connection_Layout_Sync::take_prompt( $c ) && ! Nova_Bridge_Suite_Connection_Layout_Sync::take_prompt( $c ), 'The active user receives the prompt once.' );
$input = [ 'sync_saved_layouts' => '1', 'sync_layouts_environment' => Nova_WordPress_Environment::identity( $c ) ];
Nova_Bridge_Suite_Layout_Recipes::$replies[ PAGE ] = [ 'status' => 'failed', 'message' => 'Offline' ];
assert_switch( true === Nova_Bridge_Suite_Posting_Connection::save( $input ), 'Opting in executes the saved-revision synchronization handler.' );
assert_switch( array_column( array_column( Nova_Bridge_Suite_Layout_Recipes::$calls, 0 ), 'id' ) === [ PAGE, CAT ], 'Only selected pending defaults are synchronized at their saved revisions.' );
assert_switch( $options['connection']['base_url'] === $c['base_url'] && str_contains( implode( ' ', array_column( $errors, 2 ) ), 'save this mapping to investigate and retry' ), 'Synchronization failures keep the connection and direct the user to the layout editor.' );
Nova_Bridge_Suite_Layout_Recipes::$calls = [];
assert_switch( true === Nova_Bridge_Suite_Posting_Connection::save( [ 'base_url' => 'https://another.example' ] + $input ) && [] === Nova_Bridge_Suite_Layout_Recipes::$calls, 'A stale prompt cannot send layouts to another connection.' );
copy_fixture(); Nova_Bridge_Suite_Shared_Layouts::$subscriber = true;
assert_switch( [] === Nova_Bridge_Suite_Connection_Layout_Sync::pending( $options['connection'], true ), 'Receiving installations do not offer uploads.' );
Nova_Bridge_Suite_Shared_Layouts::$subscriber = false;
$options['connection'] = [ 'enabled' => false, 'base_url' => '', 'site_id' => '' ];
$new = [ 'enabled' => true, 'base_url' => 'https://new.example', 'site_id' => 'new-site', 'token' => 'new-token' ];
Nova_Bridge_Suite_Static_Recipe_Adapter::$status = null;
assert_switch( true === Nova_Bridge_Suite_Posting_Connection::save( $new ) && Nova_Bridge_Suite_Connection_Layout_Sync::take_prompt( $options['connection'] ), 'Initially disconnected local mappings become eligible after the first complete connection is saved.' );
Nova_Bridge_Suite_Static_Recipe_Adapter::$status = [ 'status' => 'synced' ];
Nova_Bridge_Suite_Layout_Recipes::$metadata['post_type:page']['posting_sync'] = [ 'status' => 'synced' ];
assert_switch( [] === Nova_Bridge_Suite_Connection_Layout_Sync::pending( $options['connection'], true ), 'Confirmed categories and pages never produce a perpetual prompt.' );
echo "PASS {$checks} connection layout synchronization checks\n";
