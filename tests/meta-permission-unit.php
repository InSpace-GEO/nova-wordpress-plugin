<?php
/** ACF 6.8.11+ refuses edit_post_meta on its own field keys; NOVA's ACF writes must still be authorized by every other rule. */
$core = getenv( 'NOVA_WP_CORE_FIXTURE' );
if ( ! $core || ! is_file( $core . '/plugin.php' ) ) { throw new RuntimeException( 'Set NOVA_WP_CORE_FIXTURE to a WordPress core fixture.' ); }
define( 'ABSPATH', dirname( $core ) . '/' );
require_once $core . '/plugin.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-wordpress-meta-permission.php';
$checks = 0;
function check( $value, string $message ) { global $checks; $checks++; if ( ! $value ) { throw new RuntimeException( $message ); } }
// Like map_meta_cap(): ordinary keys need edit_posts, protected keys need the primitive meta cap; then filters run.
function current_user_can( $cap, $id = 0, $key = '' ) {
    $caps = in_array( $cap, [ 'edit_post_meta', 'edit_term_meta' ], true ) && 0 === strpos( (string) $key, '_' ) ? [ 'edit_posts', $cap ] : [ 'edit_posts' ];
    $caps = apply_filters( 'map_meta_cap', $caps, $cap, 1, [ $id, $key ] );
    return [] === array_diff( $caps, [ 'edit_posts' ] );
}
// The ACF 6.8.11 guard: every key that stores an ACF field is refused, whoever the user is.
function acf_map_post_meta_caps( $caps, $cap, ...$context ) {
    $args = (array) ( $context[1] ?? [] );
    if ( in_array( $cap, [ 'add_post_meta', 'edit_post_meta', 'delete_post_meta' ], true ) && in_array( $args[1] ?? '', [ 'video', 'content', 'content_0_title' ], true ) ) { $caps[] = 'do_not_allow'; }
    return $caps;
}
add_filter( 'map_meta_cap', 'acf_map_post_meta_caps', 10, 4 );

check( ! current_user_can( 'edit_post_meta', 7, 'video' ), 'The modelled ACF guard did not refuse its own field.' );
foreach ( [ 'video', 'content', 'content_0_title', 'plain_meta' ] as $key ) {
    check( Nova_WordPress_Meta_Permission::can_edit( 'post', 7, $key ), 'An editable ACF field was refused only by ACF\'s guard for the Custom Fields box: ' . $key );
}
check( 10 === has_filter( 'map_meta_cap', 'acf_map_post_meta_caps' ) && ! current_user_can( 'edit_post_meta', 7, 'video' ), 'ACF\'s guard was not restored for WordPress\'s own Custom Fields box.' );
check( ! Nova_WordPress_Meta_Permission::can_edit( 'post', 7, '_content' ), 'A protected reference key became editable.' );

$other = static function ( $caps, $cap, $user, $args ) { return 'edit_post_meta' === $cap && 'content' === ( $args[1] ?? '' ) ? array_merge( $caps, [ 'do_not_allow' ] ) : $caps; };
add_filter( 'map_meta_cap', $other, 20, 4 );
check( ! Nova_WordPress_Meta_Permission::can_edit( 'post', 7, 'content' ) && Nova_WordPress_Meta_Permission::can_edit( 'post', 7, 'video' ), 'Another plugin\'s refusal was bypassed with ACF\'s guard.' );
remove_filter( 'map_meta_cap', $other, 20 );

$term = static function ( $caps, $cap ) { return 'edit_term_meta' === $cap ? array_merge( $caps, [ 'do_not_allow' ] ) : $caps; };
add_filter( 'map_meta_cap', $term, 10, 2 );
check( ! Nova_WordPress_Meta_Permission::can_edit( 'term', 3, 'video' ) && 10 === has_filter( 'map_meta_cap', 'acf_map_post_meta_caps' ), 'Term meta checks changed or touched ACF\'s post guard.' );
remove_filter( 'map_meta_cap', $term, 10 );

remove_filter( 'map_meta_cap', 'acf_map_post_meta_caps', 10 );
check( Nova_WordPress_Meta_Permission::can_edit( 'post', 7, 'video' ) && false === has_filter( 'map_meta_cap', 'acf_map_post_meta_caps' ), 'Without ACF\'s guard the check changed or added it.' );
echo "PASS {$checks} meta permission checks\n";
