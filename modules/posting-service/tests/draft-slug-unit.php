<?php
/** Query filtering is confined to one native slug lookup and always removed. */
define( 'ABSPATH', __DIR__ );
$filters = []; $checks = 0;
$wpdb = (object) [ 'posts' => 'wp_posts' ];
function add_filter( $hook, $callback, $priority ) { $GLOBALS['filters'][$hook][] = $callback; }
function remove_filter( $hook, $callback, $priority ) { $GLOBALS['filters'][$hook] = array_values( array_filter( $GLOBALS['filters'][$hook], static function ( $value ) use ( $callback ) { return $value !== $callback; } ) ); }
function filter_query( $query ) { foreach ( $GLOBALS['filters']['query'] ?? [] as $filter ) { $query = $filter( $query ); } return $query; }
function check( $ok, $message ) { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( $message ); } }
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-draft-slug.php';

$query = "SELECT post_name FROM wp_posts WHERE post_name = 'article' AND post_type = 'post' AND ID != 42 LIMIT 1";
Nova_Bridge_Suite_Draft_Slug::for_post( 42, static function () use ( $query ) {
    check( filter_query( $query ) === substr( $query, 0, -8 ) . " AND post_status NOT IN ('draft','auto-draft') LIMIT 1", 'Only draft and auto-draft collisions are excluded.' );
    $hierarchical = str_replace( ' LIMIT 1', ' AND post_parent = 10 LIMIT 1', $query );
    check( strpos( filter_query( $hierarchical ), "AND post_parent = 10 AND post_status NOT IN ('draft','auto-draft')" ) !== false, 'Hierarchical slug lookups retain the parent constraint.' );
    foreach ( [ str_replace( '42', '43', $query ), str_replace( 'wp_posts', 'other_posts', $query ), "SELECT ID FROM wp_posts WHERE ID = 42", 'UPDATE wp_posts SET post_name = \'article\' WHERE ID = 42' ] as $other ) {
        check( filter_query( $other ) === $other, 'Other queries remain intact.' );
    }
} );
check( filter_query( $query ) === $query, 'The temporary filter is removed after success.' );
try { Nova_Bridge_Suite_Draft_Slug::for_post( 42, static function () { throw new RuntimeException( 'interrupted' ); } ); }
catch ( RuntimeException $error ) { check( $error->getMessage() === 'interrupted', 'Native errors propagate.' ); }
check( filter_query( $query ) === $query && empty( $filters['query'] ), 'The temporary filter is removed after failure.' );
echo "PASS $checks scoped draft-slug checks.\n";
