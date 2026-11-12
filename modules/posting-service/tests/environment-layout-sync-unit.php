<?php
/** Real sync/approval/snapshot validation across origins; in-memory CMS and HTTP only. */
require __DIR__ . '/stored-recipe-sync-unit.php';
if ( ! class_exists( 'Nova_Bridge_Suite_Posting_Settings' ) ) {
    class Nova_Bridge_Suite_Posting_Settings {
        public static function connection(): array { return $GLOBALS['environment_connection']; }
    }
}
Nova_Bridge_Suite_Stored_Layout_Writer::$failure = null;
$record['id'] = '88888888-8888-4888-8888-888888888888'; $record['revision'] = 1;
$test_connection = $connection;
$test_connection['layout_legacy_environment'] = Nova_WordPress_Environment::identity( $test_connection );
$environment_connection = $test_connection;
$test_state = Nova_Bridge_Suite_Mapping_Sync::scope_state( $site, 'recipe:' . $record['id'] );
$test_remote = $test_state['template'];
$snapshot = [ 'site_id' => $site, 'configuration' => array_intersect_key( $test_remote, array_flip( [ 'id', 'revision', 'name', 'page_type', 'definition', 'mapping' ] ) ) ];
$test_profile = stored_ok( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( $snapshot, $site ) );
check( $test_state['status'] === 'active', 'The upgrade preserves the active legacy template and profile.' );

foreach ( [ [ 'base_url' => 'https://production.example' ], [ 'site_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd' ] ] as $destination ) {
    $environment_connection = array_replace( $test_connection, $destination );
    $destination_site = $environment_connection['site_id'];
    check( null === Nova_Bridge_Suite_Mapping_Sync::scope_state( $destination_site, 'recipe:' . $record['id'] ), 'Another origin OR site cannot see the old template state.' );
    check( ! Nova_Bridge_Suite_Mapping_Sync::destination_enabled( $destination_site ), 'Old approval cannot enable delivery in a new destination.' );
    check( is_wp_error( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( array_replace( $snapshot, [ 'site_id' => $destination_site ] ), $destination_site ) ), 'The old delivery profile is unavailable in the new destination, even with matching IDs.' );
    $record['revision'] = 7;
    $record['recipe']['blueprint']['document'][0]['settings']['title_color'] = '#987654';
    Nova_Bridge_Suite_Layout_Recipes::$record = $record;
    $fresh_input = stored_ok( Nova_Bridge_Suite_Writing_Adapter::template_input( stored_ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $record ) ) ) );
    // Deliberately reuse the test service's UUID and revision to exercise collision isolation.
    $fresh_remote = array_merge( $test_remote, $fresh_input, [ 'revision' => 1 ] );
    $defaults = [ 'revision' => 2, 'defaults' => [ 'category' => $fresh_remote['id'] ] ];
    $fresh_client = new QueueClient( $environment_connection );
    $fresh_client->queue = [
        [ 'GET', '/template-defaults', reply( [ 'revision' => 1, 'defaults' => [] ] ) ],
        [ 'POST', '/templates', reply( $fresh_remote, 201 ) ],
        [ 'GET', '/templates/' . $fresh_remote['id'], reply( $fresh_remote ) ],
        [ 'PUT', '/template-defaults', reply( $defaults ) ],
        [ 'GET', '/template-defaults', reply( $defaults ) ],
    ];
    $result = stored_ok( stored_sync( $fresh_client, $environment_connection ) );
    check( $result['posting_sync']['verified'] && $result['posting_sync']['recipe_revision'] === 7 && $result['posting_sync']['template_revision'] === 1 && ! $fresh_client->queue, 'Fresh template creation and routing succeed with independent local and remote revisions.' );
    $fresh_snapshot = [ 'site_id' => $destination_site, 'configuration' => array_intersect_key( $fresh_remote, array_flip( [ 'id', 'revision', 'name', 'page_type', 'definition', 'mapping' ] ) ) ];
    $fresh_profile = stored_ok( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( $fresh_snapshot, $destination_site ) );
    check( $fresh_profile['local']['revision'] === $record['id'] . ':7' && $fresh_profile !== $test_profile, 'Delivery validation resolves the production layout despite identical remote IDs/revisions.' );
    check( Nova_Bridge_Suite_Mapping_Sync::destination_enabled( $destination_site ), 'Only successful local approval enables new-environment delivery.' );
    $environment_connection['token'] = 'rotated-token-not-a-secret';
    check( $fresh_profile === stored_ok( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( $fresh_snapshot, $destination_site ) ), 'Credential rotation keeps the exact approved profile.' );
    $environment_connection = $test_connection;
    check( $test_state === Nova_Bridge_Suite_Mapping_Sync::scope_state( $site, 'recipe:' . $record['id'] ) && $test_profile === stored_ok( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( $snapshot, $site ) ), 'Returning to test restores its exact state and immutable delivery profile.' );
}
// The withdrawn build did not record which environment owned its unscoped history.
$environment_connection = array_replace( $connection, [ 'history_archived' => true ] );
check( null === Nova_Bridge_Suite_Mapping_Sync::scope_state( $site, 'recipe:' . $record['id'] ), 'Ambiguous pre-isolation history is retained without approving it for the current destination.' );
echo "PASS {$checks} environment layout synchronization checks\n";
