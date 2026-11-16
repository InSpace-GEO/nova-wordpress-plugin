<?php
/** Ignore unpublished drafts only while checking or publishing one retained post. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Draft_Slug {
    public static function permalink( int $id ): string {
        if ( ! function_exists( 'get_sample_permalink' ) && is_file( ABSPATH . 'wp-admin/includes/post.php' ) ) { require_once ABSPATH . 'wp-admin/includes/post.php'; }
        if ( ! function_exists( 'get_sample_permalink' ) ) { return ''; }
        $sample = self::for_post( $id, static function () use ( $id ) { return get_sample_permalink( $id ); } );
        $post = get_post( $id );
        return str_replace( [ '%pagename%', '%postname%', '%' . ( $post->post_type ?? '' ) . '%' ], $sample[1] ?? '', $sample[0] ?? '' );
    }

    public static function for_post( int $id, callable $work ) {
        global $wpdb;
        // Keep standalone consumers without WordPress's query API working.
        if ( ! isset( $wpdb->posts ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->posts ) ) { return $work(); }
        $prefix = 'SELECT post_name FROM ' . $wpdb->posts . ' WHERE post_name = ';
        $filter = static function ( $query ) use ( $prefix, $id ) {
            // wp_unique_post_slug issues this bounded lookup. Other post queries,
            // other IDs and plugin-defined slug checks keep their own behavior.
            if ( ! is_string( $query ) || 0 !== strpos( $query, $prefix ) ||
                ! preg_match( '/ AND ID != ' . $id . '(?: AND post_parent = [0-9]+)? LIMIT 1$/D', $query ) ) { return $query; }
            return substr( $query, 0, -8 ) . " AND post_status NOT IN ('draft','auto-draft') LIMIT 1";
        };
        add_filter( 'query', $filter, PHP_INT_MAX );
        try { return $work(); }
        finally { remove_filter( 'query', $filter, PHP_INT_MAX ); }
    }
}
