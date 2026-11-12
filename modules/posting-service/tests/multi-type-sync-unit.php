<?php
/** One native layout, multiple source types, retained revisions and conditional routing. */
require __DIR__ . '/stored-recipe-sync-unit.php';
Nova_Bridge_Suite_Stored_Layout_Writer::$failure = null;
$record['id'] = '11401140-1140-4140-8140-114011401140';
$record['revision'] = 1; $record['posting_page_type'] = 'service'; $record['posting_page_types'] = [ 'service', 'category' ];
$record['resource_id'] = 'post_type:page';
unset( $record['recipe']['blueprint']['document'][1] );
Nova_Bridge_Suite_Layout_Recipes::$record = $record;
$draft = stored_ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $record ) );
check( $draft['profile_page_types'] === [ 'service', 'category' ], 'The retained writer receives both source types.' );
$input = stored_ok( Nova_Bridge_Suite_Writing_Adapter::template_input( $draft ) );
$id = '11401140-1140-4140-8140-114011401141';
$remote = array_merge( $remote, $input, [ 'id' => $id, 'revision' => 1 ] );
$initial = [ 'revision' => 1, 'defaults' => [ 'informative' => $other_id ], 'routes' => [] ];
$both = [ 'revision' => 2, 'defaults' => [ 'informative' => $other_id, 'service' => $id, 'category' => $id ], 'routes' => [] ];
$client = new QueueClient( $connection );
$client->queue = [ [ 'GET', '/template-defaults', reply( $initial ) ], [ 'POST', '/templates', reply( $remote, 201 ) ], [ 'GET', '/templates/' . $id, reply( $remote ) ], [ 'PUT', '/template-defaults', reply( $both ) ], [ 'GET', '/template-defaults', reply( $both ) ] ];
$result = stored_ok( stored_sync( $client, $connection ) );
check( ! $result['local_only'] && $result['posting_sync']['page_types'] === [ 'service', 'category' ], 'Both defaults are read back before confirmation.' );
check( $client->calls[3][2]['defaults'] === $both['defaults'], 'One conditional write selects both types and preserves Informative.' );
$old = Nova_Bridge_Suite_Mapping_Sync::authored_profile( $site, $id, 1 );
foreach ( [ 'service', 'category' ] as $type ) {
    $snapshot = [ 'site_id' => $site, 'page_type_frontend' => $type, 'configuration' => [ 'id' => $id, 'revision' => 1, 'mapping_version' => 3 ], 'content' => [] ];
    // Use the real public configuration projection, then the worker's native view.
    $snapshot['configuration'] = Nova_Bridge_Suite_Posting_Protocol::public_configuration( $old['template'] );
    $view = Nova_Bridge_Suite_Posting_Protocol::writer_snapshot( $snapshot, $old );
    stored_ok( $view ); check( $view['page_type_frontend'] === $type, 'The original source type is preserved: ' . $type );
}
$snapshot['page_type_frontend'] = 'informative';
check( is_wp_error( Nova_Bridge_Suite_Posting_Protocol::writer_snapshot( $snapshot, $old ) ), 'An unselected type cannot use the layout.' );
$record['revision'] = 2; $record['posting_page_types'] = [ 'category' ]; Nova_Bridge_Suite_Layout_Recipes::$record = $record;
$next = array_replace( $remote, [ 'revision' => 2 ] );
$category = [ 'revision' => 3, 'defaults' => [ 'informative' => $other_id, 'category' => $id ], 'routes' => [] ];
$client->queue = [ [ 'GET', '/template-defaults', reply( $both ) ], [ 'PUT', '/templates/' . $id, reply( $next ) ], [ 'GET', '/templates/' . $id, reply( $next ) ], [ 'PUT', '/template-defaults', reply( $category ) ], [ 'GET', '/template-defaults', reply( $category ) ] ];
$result = stored_ok( stored_sync( $client, $connection ) );
check( ! $result['local_only'], 'Removing a source type detaches its owned default.' );
$new = Nova_Bridge_Suite_Mapping_Sync::authored_profile( $site, $id, 2 );
check( Nova_Bridge_Suite_Layout_Page_Types::accepts( $old, 'service' ) && ! Nova_Bridge_Suite_Layout_Page_Types::accepts( $new, 'service' ) && Nova_Bridge_Suite_Layout_Page_Types::accepts( $new, 'category' ), 'Queued revision one remains valid after revision two removes Service.' );
check( Nova_Bridge_Suite_Mapping_Sync::authored_profile( $site, $id, 1 ) === $old, 'Old native authority stays byte-for-byte unchanged.' );
$record['revision'] = 3; $record['posting_page_types'] = [ 'service', 'category' ]; Nova_Bridge_Suite_Layout_Recipes::$record = $record;
$conflicting = [ 'revision' => 4, 'defaults' => [ 'service' => $other_id, 'category' => $other_id ], 'routes' => [] ];
$client->queue = [ [ 'GET', '/template-defaults', reply( $conflicting ) ] ];
$result = stored_ok( stored_sync( $client, $connection ) );
check( $result['posting_sync']['default_replacement']['page_type'] === 'service' && ! $client->queue, 'Conflicts are preflighted before any remote template mutation.' );
$approval = [ 'page_type' => 'service', 'template_id' => $other_id, 'defaults_revision' => 4 ];
$client->queue = [ [ 'GET', '/template-defaults', reply( $conflicting ) ] ];
$result = stored_ok( stored_sync( $client, $connection, '', $approval ) );
check( $result['posting_sync']['default_replacement']['page_type'] === 'category', 'Approving Service does not silently replace Category.' );
$client->queue = [ [ 'GET', '/template-defaults', reply( $conflicting ) ], [ 'PUT', '/templates/' . $id, reply( array_replace( $remote, [ 'revision' => 3 ] ) ) ], [ 'GET', '/templates/' . $id, reply( array_replace( $remote, [ 'revision' => 3 ] ) ) ], [ 'PUT', '/template-defaults', reply( [ 'revision' => 5, 'defaults' => [ 'service' => $id, 'category' => $id ], 'routes' => [] ] ) ], [ 'GET', '/template-defaults', reply( [ 'revision' => 5, 'defaults' => [ 'service' => $id, 'category' => $id ], 'routes' => [] ] ) ] ];
$result = stored_ok( Nova_Bridge_Suite_Stored_Recipe_Sync::sync_editor( $connection, static function () use ( $record ) { return $record; }, false, $record['id'], $client, new Stored_Sync_Lock(), '', null, [ $approval, array_replace( $approval, [ 'page_type' => 'category' ] ) ] ) );
check( ! $result['local_only'], 'Both reviewed replacements are applied in one conditional defaults write.' );
echo "PASS multiple source types, revision isolation and replacement review\n";
