<?php
/** Simulated site membership, effective capabilities, and WordPress's grouped post counts. */
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-actor.php';
use Nova_Bridge_Suite_Posting_Actor as Actor;
function check( $value, $message ) { if ( ! $value ) { throw new RuntimeException( $message ); } }
function get_current_blog_id() { return 5; }
function is_multisite() { return $GLOBALS['multisite'] ?? false; }
function get_super_admins() { return [ 'network-author', 'other-network-admin' ]; }
function get_user_by( $field, $login ) { return get_userdata( [ 'network-author' => 24, 'other-network-admin' => 25 ][ $login ] ); }
function get_userdata( $id ) { return $GLOBALS['users'][ $id ] ?? false; }
function is_user_member_of_blog( $id, $site ) { return get_userdata( $id ) && get_userdata( $id )->site === $site; }
function user_can( $user, $cap ) { return in_array( $cap, $user->caps, true ); }
function get_users( $args ) {
    $GLOBALS['user_queries'][] = $args;
    $members = array_filter( $GLOBALS['users'], static function ( $user ) use ( $args ) {
        return $user->site === $args['blog_id'] && array_intersect( $args['capability__in'], $user->stored_caps ?? $user->caps );
    } );
    $result = array_slice( $members, $args['offset'], $args['number'] );
    $GLOBALS['fetched_users'] += count( $result );
    return $result;
}
function get_post_types( $args, $output ) {
    $types = [];
    foreach ( [ 'post', 'page', 'case_study', 'legacy_news', 'attachment', 'revision', 'elementor_library', 'wp_template', 'shop_order', 'hidden_type', 'product' ] as $name ) {
        $types[ $name ] = (object) [ 'name' => $name, 'public' => 'hidden_type' !== $name, 'show_ui' => true, 'show_in_rest' => 'legacy_news' !== $name,
            'cap' => (object) [ 'create_posts' => "create_$name", 'edit_posts' => "edit_$name", 'publish_posts' => "publish_$name" ] ];
    }
    return $types;
}
function count_many_users_posts( $ids, $types, $public_only ) {
    check( true === $public_only, 'Only published posts count.' );
    check( $types === [ 'post', 'page', 'case_study', 'legacy_news', 'product' ], 'Only public editorial post types, including legacy CPTs, count.' );
    $GLOBALS['count_queries'][] = $ids;
    return array_intersect_key( $GLOBALS['counts'], array_flip( $ids ) );
}
function account( $id, $name, $type, $site = 5 ) {
    return (object) [ 'ID' => $id, 'site' => $site, 'display_name' => $name, 'caps' => [ "edit_$type", "create_$type", "publish_$type" ] ];
}
$users = [
    3 => account( 3, 'Zed', 'post' ), 8 => account( 8, 'Alex', 'page' ), 9 => account( 9, 'Lina', 'case_study' ),
    10 => account( 10, 'Editor elsewhere', 'post', 7 ), 11 => account( 11, 'Draft contributor', 'post' ),
    12 => account( 12, 'Cannot create', 'case_study' ), 13 => account( 13, 'Uploads only', 'attachment' ),
    14 => account( 14, 'Hidden type', 'hidden_type' ), 15 => account( 15, 'Legacy type only', 'legacy_news' ),
    16 => account( 16, 'Product-only store manager', 'product' ),
    17 => account( 17, 'Spammed network account', 'post' ), 18 => account( 18, 'Deleted network account', 'page' ),
];
$users[17]->spam = 1; $users[18]->deleted = 1;
$users[11]->caps = [ 'edit_post', 'create_post' ]; $users[12]->caps = [ 'edit_case_study', 'publish_case_study' ];
$user_queries = $count_queries = []; $fetched_users = 0;
$choices = Actor::choices();
check( [ 8 => 'Alex', 9 => 'Lina', 3 => 'Zed' ] === $choices, 'The select lists site publishers by display name.' );
foreach ( array_keys( $users ) as $id ) { check( isset( $choices[ $id ] ) === Actor::eligible( $id ), 'The server enforces the same eligibility as the form.' ); }
check( ! Actor::eligible( 0 ) && ! Actor::eligible( 999 ), 'Missing and anonymous users are ineligible.' );
$counts = [ 3 => 8, 8 => 0, 9 => 17, 10 => 500, 11 => 200 ];
check( Actor::default_id( $choices ) === 9, 'Most active eligible CPT author wins.' );
$counts[3] = 17; check( Actor::default_id( $choices ) === 3, 'Positive count ties use lowest eligible ID.' );
$counts = []; check( Actor::default_id( $choices ) === 3, 'A new site uses the same deterministic tie-breaker.' );
check( count( $user_queries ) === 1, 'The rendered choices can also supply the default without listing users twice.' );
check( $user_queries[0]['capability__in'] === [ 'publish_post', 'publish_page', 'publish_case_study' ], 'The candidate search uses registered publishing grants across all supported native types.' );
for ( $id = 1000; $id < 5000; ++$id ) { $users[ $id ] = account( $id, "Customer $id", 'customer' ); }
$fetched_users = 0; check( Actor::choices() === $choices && $fetched_users < 10, 'Subscriber-only customer accounts are never loaded by the settings screen.' );
$users[20] = account( 20, 'Filter grant', 'post' ); $users[20]->stored_caps = [];
$users[21] = account( 21, 'Vetoed role grant', 'post' ); $users[21]->stored_caps = $users[21]->caps; $users[21]->caps = [];
check( Actor::choices() === $choices && isset( Actor::choices( 20 )[20] ), 'An existing filter-only selection is rechecked and retained; a runtime veto removes a native grant.' );
$multisite = true; $users[24] = account( 24, 'Network publisher on site', 'post' ); $users[25] = account( 25, 'Network publisher elsewhere', 'post', 7 );
$users[24]->stored_caps = $users[25]->stored_caps = [];
$network_choices = Actor::choices();
check( isset( $network_choices[24] ) && ! isset( $network_choices[25] ), 'Network super admins with synthetic grants are included only on sites to which they belong.' );
$multisite = false;
for ( $id = 50; $id < 560; ++$id ) { $users[ $id ] = account( $id, "User $id", 'case_study' ); }
$counts = [ 550 => 72 ]; $user_queries = $count_queries = [];
check( Actor::default_id() === 550, 'Later batches participate in ranking.' );
check( array_column( $user_queries, 'offset' ) === [ 0, 250, 500 ] && count( $count_queries ) === 3, 'User retrieval and grouped activity counts are batched.' );
$users = []; $count_queries = []; check( Actor::default_id() === 0 && ! $count_queries, 'No eligible users yields no actor.' );
echo "PASS posting user eligibility and default selection\n";
