<?php
/** Two separate in-memory WP option stores, one site credential and the production layout compiler. */
class Nova_Bridge_Suite_Posting_Settings {
    public static $test_connection = [];
    public static function connection() { return self::$test_connection; }
}
require __DIR__ . '/stored-recipe-sync-unit.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-worker.php';
class Nova_Bridge_Suite_Owned_CPT_Sync {
    public static function configuration_for_snapshot( $snapshot, $site ) { return new WP_Error( 'stale_legacy_profile', 'A stale legacy native writer exists for this identity.' ); }
}
function wp_get_upload_dir() { return [ 'basedir' => $GLOBALS['test_uploads'] ]; }
function get_attached_file( $id, $unfiltered = false ) { return $GLOBALS['shared_test_media'][$id] ?? false; }
function wp_get_attachment_url( $id ) { return 'https://media.test/' . $id . '.png'; }
function get_posts( $query ) { return array_keys( $GLOBALS['shared_test_media'] ); }
$test_uploads = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nova-shared-transfer-' . bin2hex( random_bytes( 8 ) );
mkdir( $test_uploads );
$photo = $test_uploads . DIRECTORY_SEPARATOR . 'photo.png'; $other_photo = $test_uploads . DIRECTORY_SEPARATOR . 'other.png';
file_put_contents( $photo, 'same bytes' ); file_put_contents( $other_photo, 'wrong bytes' );
register_shutdown_function( static function () use ( $test_uploads, $photo, $other_photo ) { unlink( $photo ); unlink( $other_photo ); rmdir( $test_uploads ); } );
$GLOBALS['shared_test_media'] = [ 41 => $photo ];
$connection['actor_user_id'] = 7;
Nova_Bridge_Suite_Posting_Settings::$test_connection = $connection;
$GLOBALS['wpdb'] = new SyncDB();
Nova_Bridge_Suite_Stored_Layout_Writer::$failure = null;
$locks = new Stored_Sync_Lock();
$native_template = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$source_recipe = [
    'id' => '77777777-7777-4777-8777-777777777777', 'revision' => 1, 'resource_id' => 'post_type:page',
    'builder' => 'elementor', 'mode' => 'static', 'posting_page_type' => 'service', 'label' => 'Location page',
    'sample' => [ 'never' => 'private authoring sample' ], 'created_by' => 42, 'source_receipt' => 'private source receipt',
    'recipe' => [ 'builder' => 'elementor', 'mode' => 'static', 'instructions' => 'Use the service heading.', 'regions' => [],
        'blueprint' => [ 'document' => [ [ 'id' => 'abc1234', 'elType' => 'widget', 'widgetType' => 'heading',
            'settings' => [ 'title' => 'Placeholder', 'title_color' => '#123abc' ], 'elements' => [] ] ], 'meta' => [], 'template' => '' ],
        'bindings' => [ [ 'id' => 'hero', 'target' => '/document/0/settings/title', 'source' => '/hero_title', 'format' => 'text', 'label' => 'Heading', 'required' => true, 'instructions' => '' ] ],
    ],
];
$client = new QueueClient( $connection );
$source_recipe['recipe']['blueprint']['document'][] = [ 'id' => 'abc1235', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => [ 'image' => [ 'id' => 41, 'url' => wp_get_attachment_url( 41 ) ] ], 'elements' => [] ];
$initial = [ 'revision' => 1, 'defaults' => [] ]; $selected = [ 'revision' => 2, 'defaults' => [ 'service' => $native_template ] ];
$frozen_versions = [];
for ( $revision = 1; $revision <= 2; ++$revision ) {
    $source_recipe['revision'] = $revision;
    $source_recipe['recipe']['blueprint']['document'][0]['settings']['title_color'] = 1 === $revision ? '#123abc' : '#ff00ff';
    Nova_Bridge_Suite_Layout_Recipes::$history[$source_recipe['id']][$revision] = $source_recipe;
    Nova_Bridge_Suite_Layout_Recipes::$record = $source_recipe;
    $projected = stored_ok( Nova_Bridge_Suite_Writing_Adapter::template_input( stored_ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $source_recipe ) ) ) );
    $server = array_merge( $remote, $projected, [ 'id' => $native_template, 'revision' => $revision ] );
    $client->queue = 1 === $revision
        ? [ [ 'GET', '/template-defaults', reply( $initial ) ], [ 'POST', '/templates', reply( $server, 201 ) ], [ 'GET', '/templates/' . $native_template, reply( $server ) ], [ 'PUT', '/template-defaults', reply( $selected ) ], [ 'GET', '/template-defaults', reply( $selected ) ] ]
        : [ [ 'GET', '/template-defaults', reply( $selected ) ], [ 'PUT', '/templates/' . $native_template, reply( $server ) ], [ 'GET', '/templates/' . $native_template, reply( $server ) ], [ 'GET', '/template-defaults', reply( $selected ) ] ];
    $saved = stored_ok( stored_sync( $client, $connection ) ); check( $saved['posting_sync']['verified'], 'The canonical layout was normally published before sharing.' );
    $frozen_versions[$revision] = array_intersect_key( $server, array_flip( [ 'id', 'name', 'page_type', 'definition', 'mapping', 'revision' ] ) );
}
$versions = [ [ 'template_id' => $native_template, 'revision' => 1 ], [ 'template_id' => $native_template, 'revision' => 2 ] ];
$file = stored_ok( Nova_Bridge_Suite_Shared_Layouts::export_selected( $connection, $versions ) );
$copied = json_decode( wp_json_encode( $file ), true );
check( ! str_contains( wp_json_encode( $file ), $connection['token'] ) && ! str_contains( wp_json_encode( $file ), 'private authoring sample' ) && ! str_contains( wp_json_encode( $file ), 'private source receipt' ), 'File excludes credentials, source receipts and sample content.' );
check( array_column( stored_ok( Nova_Bridge_Suite_Shared_Layouts::catalog( $connection ) )['templates'], 'revision' ) === [ 2, 1 ], 'Source can select active and historical approvals without resaving.' );
$source_options = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = clone $source_options;
$matched = stored_ok( Nova_Bridge_Suite_Shared_Layouts::import_selected( $connection, $copied, [], $locks ) );
check( 2 === $matched['unchanged'] && Nova_Bridge_Suite_Shared_Layouts::is_subscriber( $connection ), 'A first real import that matches locally authored layouts still switches the entire site into receiving mode.' );
$never_load = static function () { throw new RuntimeException( 'A subscriber must not save an authoring revision.' ); };
check( 'nova_shared_layout_subscriber' === ( new Nova_Bridge_Suite_Mapping_Sync( $client, 'new-layout' ) )->synchronize( [] )->get_error_code(), 'Even a previously unknown template cannot be uploaded from a subscriber.' );
check( 'nova_shared_layout_subscriber' === Nova_Bridge_Suite_Stored_Recipe_Sync::sync_editor( $connection, $never_load, true )->get_error_code(), 'A subscriber cannot save and sync a stored creation layout.' );
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-static-recipe-adapter.php';
check( 'nova_shared_layout_subscriber' === Nova_Bridge_Suite_Static_Recipe_Adapter::sync_editor( $connection, $never_load, true )->get_error_code(), 'Legacy static editor cannot create a default on an importing installation.' );
check( 'nova_shared_layout_subscriber' === Nova_Bridge_Suite_Static_Recipe_Adapter::sync_and_approve( $connection, $source_recipe['id'], 1, '1', '2' )->get_error_code(), 'Legacy CLI setup cannot create a default on an importing installation.' );
$GLOBALS['wpdb'] = new SyncDB();
$GLOBALS['shared_test_media'] = [ 61 => $photo ];
$receive = static fn( $f, $conn = null ) => Nova_Bridge_Suite_Shared_Layouts::import_selected( $conn ?? $connection, $f, [], $locks );
$result = $receive( $copied, array_replace( $connection, [ 'token' => str_repeat( 'x', 36 ) ] ) );
check( $result instanceof WP_Error && 'nova_shared_layout_signature' === $result->get_error_code(), 'A different credential cannot certify a package for the same site UUID.' );
$tampered = $copied; $tampered['layouts'][0]['record']['recipe']['blueprint']['document'][0]['settings']['title_color'] = '#invalid';
check( 'nova_shared_layout_signature' === $receive( $tampered )->get_error_code(), 'Even a styling-only alteration invalidates the signature.' );
check( 'nova_shared_layout_scope' === $receive( $copied, array_replace( $connection, [ 'base_url' => 'https://different.example' ] ) )->get_error_code(), 'Service origins cannot be mixed.' );
Nova_Bridge_Suite_Stored_Layout_Writer::$failure = static fn( $draft ) => str_ends_with( $draft['revision'], ':2' ) ? new WP_Error( 'forbidden_actor', 'The local publishing account lacks native access.' ) : true;
check( $receive( $copied )->get_error_code() === 'forbidden_actor' && ! Nova_Bridge_Suite_Shared_Layouts::has_imports( $connection, $connection['site_id'] ), 'No revisions activate when local approval fails.' );
check( ! Nova_Bridge_Suite_Shared_Layouts::is_subscriber( $connection ), 'A rejected file does not change an authoring installation into a subscriber.' );
Nova_Bridge_Suite_Stored_Layout_Writer::$failure = null;
$success = stored_ok( $receive( $copied ) ); check( 2 === $success['imported'] && 0 === $success['replaced'], 'All requested revisions activate together.' );
check( 'stored_layout_v3' === stored_ok( Nova_Bridge_Suite_Posting_Worker::configuration_for_snapshot( [ 'site_id' => $connection['site_id'], 'configuration' => $frozen_versions[1] ], $connection['site_id'] ) )['mode'], 'An explicit import takes precedence over old backend-specific native writer records.' );
check( Nova_Bridge_Suite_Mapping_Sync::destination_enabled( $connection['site_id'] ), 'An import alone enables V3 discovery on its own installation.' );
$target_profile = static fn( $v ) => stored_ok( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( [ 'site_id' => $connection['site_id'], 'configuration' => $frozen_versions[$v] ], $connection['site_id'] ) );
$color = static fn( $profile ) => $profile['local']['stored_layout']['blueprint']['document'][0]['settings']['title_color'];
check( $color( $target_profile( 1 ) ) === '#123abc' && $color( $target_profile( 2 ) ) === '#ff00ff', 'Old queued deliveries keep v1 after importing a newer native design with identical public fields.' );
check( 2 === stored_ok( $receive( $copied ) )['unchanged'], 'A repeated file is idempotent.' );
Nova_Bridge_Suite_Stored_Layout_Writer::$failure = new WP_Error( 'forbidden_actor', 'The account lost its native access.' );
check( 'forbidden_actor' === $receive( $copied )->get_error_code(), 'An identical file performs local approval again before reporting an unchanged import.' );
Nova_Bridge_Suite_Stored_Layout_Writer::$failure = null;
check( stored_ok( Nova_Bridge_Suite_Shared_Layouts::export_selected( $connection, $versions ) ) === $file, 'A subscriber exports the original dependency identities.' );
$unavailable = Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( [ 'site_id' => $connection['site_id'], 'configuration' => array_replace( $frozen_versions[2], [ 'revision' => 3 ] ) ], $connection['site_id'] );
check( is_wp_error( $unavailable ), 'An unimported revision cannot use the latest native layout.' );

// An independently approved source previously styled the same remote revision differently.
$target_options = $GLOBALS['wpdb']; $GLOBALS['wpdb'] = new SyncDB();
$GLOBALS['shared_test_media'] = [ 41 => $photo ];
$different_record = $source_recipe; $different_record['recipe']['blueprint']['document'][0]['settings']['title_color'] = '#ee0000';
$different_draft = stored_ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $different_record ) );
$original_profile = unserialize( $source_options->rows['nova_publishing_profile_' . hash( 'sha256', $connection['site_id'] . ':' . $native_template . ':2' )], [ 'allowed_classes' => false ] );
$original_profile['local'] = $different_draft;
$original_profile['profile_digest'] = hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $different_draft ) );
add_option( 'nova_publishing_profile_' . hash( 'sha256', $connection['site_id'] . ':' . $native_template . ':2' ), $original_profile, '', false );
Nova_Bridge_Suite_Layout_Recipes::$record = $different_record;
$replacement = stored_ok( Nova_Bridge_Suite_Shared_Layouts::export_selected( $connection, [ $versions[1] ] ) );
$source_replaced = stored_ok( $receive( $file ) ); check( 1 === $source_replaced['replaced'] && $color( $target_profile( 2 ) ) === '#ff00ff', 'An incoming file can replace an authored native layout for the selected identity.' );
$GLOBALS['wpdb'] = $target_options;
$GLOBALS['shared_test_media'] = [ 61 => $photo ];
Nova_Bridge_Suite_Stored_Layout_Writer::$failure = new WP_Error( 'forbidden_actor', 'No destination approval.' );
check( 'forbidden_actor' === $receive( $replacement )->get_error_code() && $color( $target_profile( 2 ) ) === '#ff00ff', 'An unsuccessful replacement keeps the old writer active.' );
Nova_Bridge_Suite_Stored_Layout_Writer::$failure = null;
check( 1 === stored_ok( $receive( $replacement ) )['replaced'] && '#ee0000' === $color( $target_profile( 2 ) ) && '#123abc' === $color( $target_profile( 1 ) ), 'An imported v2 replaces only the selected v2 without altering v1.' );
check( 1 === stored_ok( $receive( $replacement ) )['unchanged'], 'Repeating the replacement does not change any writer.' );
$GLOBALS['shared_test_media'] = [ 62 => $photo, 63 => $photo ];
check( 'nova_shared_layout_dependencies' === $receive( $replacement )->get_error_code(), 'A repeated package now reports ambiguous target media.' );
$choice = [ $native_template . ':2' => [ 'attachment:41' => 63 ] ];
check( 1 === stored_ok( Nova_Bridge_Suite_Shared_Layouts::import_selected( $connection, $replacement, $choice, $locks ) )['replaced'], 'A verified manual selection replaces the outdated local target.' );
check( 1 === stored_ok( $receive( $replacement ) )['unchanged'], 'The same verified choice survives a future import while ambiguity remains.' );
$GLOBALS['shared_test_media'][63] = $other_photo;
check( 1 === stored_ok( $receive( $replacement ) )['replaced'] && 62 === $target_profile( 2 )['local']['stored_layout']['blueprint']['document'][1]['settings']['image']['id'], 'A reused ID with different bytes yields to a uniquely verified movable attachment.' );
unset( $GLOBALS['shared_test_media'][62] );
check( 'nova_shared_layout_dependencies' === $receive( $replacement )->get_error_code(), 'An identical file cannot claim readiness after matching media disappear.' );
echo "PASS shared layout transfer and replacement\n";
