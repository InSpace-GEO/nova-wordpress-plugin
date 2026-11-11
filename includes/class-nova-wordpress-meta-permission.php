<?php
/** Meta permission checks shared by every module. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_WordPress_Meta_Permission {
    /**
     * current_user_can( "edit_{$type}_meta", $id, $key ), without ACF's guard for WordPress's own
     * Custom Fields box.
     *
     * ACF 6.8.11+ refuses add/edit/delete_post_meta on every key that stores an ACF field, for every
     * user, so the generic box cannot bypass ACF's save checks. ACF saves its own fields after
     * edit_post; NOVA writes them through ACF the same way. Every other rule still applies: field
     * auth filters, registered meta callbacks, protected keys and other capability filters.
     */
    public static function can_edit( string $type, int $id, string $key ): bool {
        $capability = 'term' === $type ? 'edit_term_meta' : 'edit_post_meta';
        $priority = 'post' === $type && function_exists( 'has_filter' ) ? has_filter( 'map_meta_cap', 'acf_map_post_meta_caps' ) : false;
        if ( false === $priority ) { return current_user_can( $capability, $id, $key ); }
        remove_filter( 'map_meta_cap', 'acf_map_post_meta_caps', $priority );
        try { return current_user_can( $capability, $id, $key ); }
        finally { add_filter( 'map_meta_cap', 'acf_map_post_meta_caps', $priority, 4 ); }
    }
}
