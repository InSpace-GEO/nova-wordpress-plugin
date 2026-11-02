<?php
/** Eligible site members and the suggested author for a new connection. */
defined( 'ABSPATH' ) || exit;
require_once dirname( __DIR__, 3 ) . '/includes/editorial-post-types.php';

final class Nova_Bridge_Suite_Posting_Actor {
    private const BATCH = 250;

    private static function content_types(): array {
        return array_filter( get_post_types( [ 'public' => true, 'show_ui' => true ], 'objects' ), 'nova_bridge_suite_is_editorial_post_type' );
    }

    private static function publishing_types(): array {
        return array_filter( self::content_types(), static function ( $type ) {
            // Product posts contribute to activity but have no supported native posting writer.
            return ! empty( $type->show_in_rest ) && 'product' !== $type->name && ! empty( $type->cap->edit_posts ) && ! empty( $type->cap->publish_posts );
        } );
    }

    private static function can_post( $user, array $types ): bool {
        if ( ! empty( $user->spam ) || ! empty( $user->deleted ) ) { return false; }
        foreach ( $types as $type ) {
            $caps = $type->cap;
            if ( user_can( $user, $caps->create_posts ?? $caps->edit_posts )
                && user_can( $user, $caps->edit_posts ) && user_can( $user, $caps->publish_posts ) ) { return true; }
        }
        return false;
    }

    public static function eligible( int $id ): bool {
        if ( $id < 1 || ! is_user_member_of_blog( $id, get_current_blog_id() ) ) { return false; }
        $user = get_userdata( $id );
        return $user && self::can_post( $user, self::publishing_types() );
    }

    /** Find site members with a role or direct publishing grant, then verify effective permissions. */
    public static function choices( int $selected = 0 ): array {
        $choices = [];
        $types = self::publishing_types();
        if ( ! $types ) { return []; }
        $caps = array_values( array_unique( array_map( static function ( $type ) { return $type->cap->publish_posts; }, $types ) ) );
        $site = get_current_blog_id();
        for ( $offset = 0; ; $offset += self::BATCH ) {
            $users = get_users( [ 'blog_id' => $site, 'capability__in' => $caps, 'fields' => 'all', 'number' => self::BATCH, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC' ] );
            foreach ( $users as $user ) {
                if ( self::can_post( $user, $types ) ) { $choices[ (int) $user->ID ] = $user->display_name; }
            }
            if ( count( $users ) < self::BATCH ) { break; }
        }
        // Super admins and an explicitly selected user can hold permission without a stored grant.
        $extra = $selected > 0 && ! isset( $choices[ $selected ] ) ? [ get_userdata( $selected ) ] : [];
        if ( is_multisite() ) {
            foreach ( get_super_admins() as $login ) {
                $extra[] = get_user_by( 'login', $login );
            }
        }
        foreach ( $extra as $user ) {
            if ( $user && ! isset( $choices[ $user->ID ] ) && is_user_member_of_blog( $user->ID, $site ) && self::can_post( $user, $types ) ) {
                $choices[ (int) $user->ID ] = $user->display_name;
            }
        }
        natcasesort( $choices );
        return $choices;
    }

    /** Count published content in bulk; ties, including empty sites, use the lowest user ID. */
    public static function default_id( ?array $choices = null ): int {
        $ids = array_keys( $choices ?? self::choices() );
        if ( ! $ids ) { return 0; }
        $types = array_keys( self::content_types() );
        sort( $ids, SORT_NUMERIC );
        $best = 0;
        $highest = -1;
        foreach ( array_chunk( $ids, self::BATCH ) as $batch ) {
            $counts = count_many_users_posts( $batch, $types, true );
            foreach ( $batch as $id ) {
                $count = (int) ( $counts[ $id ] ?? 0 );
                if ( $count > $highest ) { $best = $id; $highest = $count; }
            }
        }
        return $best;
    }
}
