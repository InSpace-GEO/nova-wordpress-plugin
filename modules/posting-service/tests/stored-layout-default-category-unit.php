<?php
/** WordPress can set the default category on each wp_update_post, including publishing a draft. */
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout-writer.php';

function get_option( $key ) { return 'default_category' === $key ? 7 : false; }
function taxonomy_exists( $taxonomy ) { return 'category' === $taxonomy; }
function is_wp_error( $value ) { return false; }
function wp_get_object_terms( $id, $taxonomy, $args ) { return $GLOBALS['terms'][$id]; }
function wp_remove_object_terms( $id, $value, $taxonomy ) {
    $GLOBALS['terms'][$id] = array_values( array_diff( $GLOBALS['terms'][$id], [ $value ] ) );
    return true;
}
function add_action( $name, $callback, $priority, $accepted ) { $GLOBALS['hook'] = $callback; }
function remove_action( $name, $callback, $priority ) { unset( $GLOBALS['hook'] ); }

$save = new ReflectionMethod( 'Nova_Bridge_Suite_Stored_Layout_Writer', 'save_with_term_policy' );
if ( PHP_VERSION_ID < 80100 ) { $save->setAccessible( true ); }
foreach ( [
    [ [ 'fixed_terms' => [ 'category' => [] ] ], [], [] ],
    [ [ 'preserve_term_taxonomies' => [ 'category' ] ], [], [] ],
    [ [ 'preserve_term_taxonomies' => [ 'category' ] ], [7], [7] ],
    [ [ 'preserve_term_taxonomies' => [ 'category' ] ], [8], [8] ],
    [ [], [], [7] ],
] as [ $plan, $initial, $expected ] ) {
    $terms = [ 21 => $initial, 22 => [] ];
    $save->invoke( null, $plan, 21, static function () {
        foreach ( [ 21, 22 ] as $id ) {
            if ( ! $GLOBALS['terms'][$id] ) {
                $GLOBALS['terms'][$id] = [7];
                if ( isset( $GLOBALS['hook'] ) ) { $GLOBALS['hook']( $id, [7], [17], 'category' ); }
            }
        }
    } );
    if ( $terms[21] !== $expected || $terms[22] !== [7] || isset( $GLOBALS['hook'] ) ) { throw new RuntimeException( 'Incorrect default category or leaked hook.' ); }
}
echo "PASS empty and manually assigned categories across create, repost and unrelated saves.\n";
