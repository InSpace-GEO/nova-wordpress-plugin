<?php
/** Beaver uses the existing compiler and a retained native post for publishing. */
require __DIR__ . '/recipe-builders-unit.php';
$before_checks = $assertions;
function metadata_exists( $type, $id, $key ) { return array_key_exists( $key, $GLOBALS['recipe_meta'][$id] ?? [] ); }
function clean_post_cache( $id ) {}
function wp_update_post( $data, $return_error = false ) {
    $data = wp_unslash( $data );
    $id = (int) $data['ID'];
    if ( ! empty( $GLOBALS['beaver_update_error'] ) ) { return new WP_Error( 'native_save_failed', 'Simulated native save failure' ); }
    foreach ( $data as $key => $value ) { $GLOBALS['recipe_posts'][$id]->$key = $value; }
    ++$GLOBALS['beaver_updates'];
    return $id;
}
$GLOBALS['beaver_updates'] = 0;
$adapter = Nova_Bridge_Suite_Recipe_Builders::adapter( 'beaver' );
$artifact = recipe_ok( $adapter->starter( 'static' ), 'Beaver starter' );
$recipe = recipe_for( 'beaver', $artifact, 'static' );
$recipe['blueprint']['meta']['_fl_builder_data_settings'] = [ 'css' => '.sample { color: red; }', 'js' => '' ];
$compiled = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, [ 'title' => 'Quoted "title"', 'body' => '<p>Independent content with a backslash \\ and an apostrophe\'s.</p>' ], 'beaver-publishing' ), 'Beaver compile' );
$payload = $compiled['writer_payload'];
$GLOBALS['recipe_posts'][901] = (object) [ 'ID' => 901, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Reserved page', 'post_excerpt' => '', 'post_content' => 'Old body' ];
$GLOBALS['recipe_meta'][901] = [ '_fl_builder_data' => ['old' => (object) ['node' => 'old']], '_fl_builder_draft' => ['unsaved'], '_fl_builder_draft_settings' => ['old' => true], 'unrelated' => 'Keep me' ];
$source = serialize( $GLOBALS['recipe_posts'][1] );
$inserts = $GLOBALS['native_fixture_writes'];
recipe_assert( $adapter->publication_support( 'page' )['supported'], 'Beaver exposes complete publishing support' );
recipe_assert( recipe_ok( $adapter->publication_probe( 'page', $payload, 'publish' ), 'Beaver probe' )['provider_version'] === 'beaver-known-post-v1', 'Beaver provider version is explicit' );
$before = recipe_ok( $adapter->publication_snapshot( 901, $payload ), 'Before snapshot' );
recipe_assert( $before['meta']['_fl_builder_draft']['present'] && ! isset( $before['meta']['unrelated'] ), 'Recovery snapshot includes editor drafts and excludes unrelated metadata' );
recipe_assert( true === $adapter->publication_apply( 901, $payload, [ 'title' => 'New "title"', 'excerpt' => 'New excerpt' ] ), 'Known-ID Beaver write succeeds' );
recipe_assert( $GLOBALS['native_fixture_writes'] === $inserts && $GLOBALS['beaver_updates'] === 1, 'Provider updates the retained post without inserting another one' );
recipe_assert( $GLOBALS['recipe_posts'][901]->post_title === 'New "title"' && $GLOBALS['recipe_posts'][901]->post_status === 'draft', 'Native fields preserve escaping and leave publication to the journal' );
recipe_assert( is_object( reset( $GLOBALS['recipe_meta'][901]['_fl_builder_data'] ) ) && true === $GLOBALS['recipe_meta'][901]['_fl_builder_enabled'], 'The saved layout retains native Beaver object nodes and activation' );
recipe_assert( ! metadata_exists( 'post', 901, '_fl_builder_draft' ) && ! metadata_exists( 'post', 901, '_fl_builder_draft_settings' ), 'Superseded editor drafts cannot replace the delivered layout' );
recipe_assert( $GLOBALS['recipe_meta'][901]['unrelated'] === 'Keep me' && serialize( $GLOBALS['recipe_posts'][1] ) === $source, 'Unrelated metadata and the example page are preserved' );
recipe_assert( true === $adapter->publication_verify( 901, $payload ), 'Readback verifies the published layout' );
$node_id = array_key_first( $GLOBALS['recipe_meta'][901]['_fl_builder_data'] );
$GLOBALS['recipe_meta'][901]['_fl_builder_data'][$node_id]->settings = (object) ['tampered' => true];
recipe_assert( is_wp_error( $adapter->publication_verify( 901, $payload ) ), 'Readback detects partial or changed layout data' );
recipe_assert( true === $adapter->publication_apply( 901, $payload, [ 'title' => 'New "title"' ] ) && $GLOBALS['native_fixture_writes'] === $inserts, 'Repeating a known-ID save repairs partial state without another post' );
update_post_meta( 901, '_fl_builder_draft', ['stale'] );
recipe_assert( is_wp_error( $adapter->publication_verify( 901, $payload ) ), 'Readback refuses a stale editor draft' );
delete_post_meta( 901, '_fl_builder_draft' );
update_post_meta( 901, '_fl_builder_data_settings', ['changed' => true] );
recipe_assert( is_wp_error( $adapter->publication_verify( 901, $payload ) ), 'Readback includes the retained Beaver page settings' );
$updates = $GLOBALS['beaver_updates'];
$bad = $payload; $bad['meta']['_nova_stored_layout_owner'] = ['site_id' => 'other'];
recipe_assert( is_wp_error( $adapter->publication_apply( 901, $bad, [] ) ) && $GLOBALS['beaver_updates'] === $updates, 'Protected metadata is rejected before a native write' );
$bad = $payload; unset( $bad['layout']['nodes'][array_key_first( $bad['layout']['nodes'] )]['node'] );
recipe_assert( is_wp_error( $adapter->publication_probe( 'page', $bad, 'publish' ) ), 'Malformed node identity is refused' );
$numeric = $payload;
$old_key = array_key_first( $numeric['layout']['nodes'] );
$numeric['layout']['nodes'][1234567890123] = $numeric['layout']['nodes'][$old_key];
$numeric['layout']['nodes'][1234567890123]['node'] = '1234567890123';
unset( $numeric['layout']['nodes'][$old_key] );
foreach ( $numeric['layout']['nodes'] as &$node ) { if ( ( $node['parent'] ?? null ) === $old_key ) { $node['parent'] = '1234567890123'; } }
unset( $node );
recipe_assert( ! is_wp_error( $adapter->publication_probe( 'page', $numeric, 'publish' ) ), 'Numeric-only native IDs survive PHP array-key conversion' );
$GLOBALS['recipe_denied'] = ['edit_posts'];
recipe_assert( ! $adapter->publication_support( 'page' )['supported'], 'The publishing actor must be allowed to edit the post type' );
unset( $GLOBALS['recipe_denied'] );
$GLOBALS['beaver_update_error'] = true;
recipe_assert( $adapter->publication_apply( 901, $payload, [] )->get_error_code() === 'native_save_failed', 'Native write errors propagate to the recovery journal' );
recipe_assert( ! Nova_Bridge_Suite_Recipe_Builders::adapter( 'divi4' )->publication_support( 'page' )['supported'], 'Other unsupported builders retain their existing boundary' );
echo 'Beaver publishing checks: ' . ( $assertions - $before_checks ) . " passed.\n";
