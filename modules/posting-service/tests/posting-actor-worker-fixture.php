<?php
/** Site user API used by standalone delivery worker tests. */
function get_current_blog_id() { return 1; }
function is_user_member_of_blog( $id, $blog_id ) { return $blog_id === 1 && in_array( $id, [ 1, 2 ], true ); }
function get_userdata( $id ) { return (object) [ 'ID' => $id ]; }
function get_post_types( $args, $output ) {
    return [ 'page' => (object) [ 'name' => 'page', 'public' => true, 'show_ui' => true, 'show_in_rest' => true, 'cap' => (object) [ 'edit_posts' => 'edit_pages', 'create_posts' => 'edit_pages', 'publish_posts' => 'publish_pages' ] ] ];
}
function user_can( $user, $cap ) { return $user->ID !== ( $GLOBALS['revoked_actor'] ?? 0 ); }
