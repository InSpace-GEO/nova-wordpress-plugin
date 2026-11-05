<?php
/** Native SEO storage behind explicitly approved layout fields. */
defined( 'ABSPATH' ) || exit;
require_once dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-wordpress-layout-seo.php';

final class Nova_WordPress_Stored_SEO {
    private static function error( string $message ): WP_Error { return new WP_Error( 'nova_stored_seo', $message, [ 'status' => 409 ] ); }

    public static function check( string $name, array $binding, int $id = 0 ) {
        $provider = $binding['key'] ?? ''; $field = $binding['type'] ?? '';
        if ( ! is_string( $provider ) || ! in_array( $field, [ 'title', 'description' ], true ) || $name !== Nova_WordPress_Layout_SEO::path( $provider, $field ) || ! is_string( $binding['value'] ?? null ) ) { return self::error( 'The approved SEO field is invalid.' ); }
        $definition = Nova_WordPress_Layout_SEO::active()[$provider] ?? null;
        if ( ! $definition ) { return self::error( 'The SEO plugin selected by this layout is no longer active: ' . $provider ); }
        if ( $id && ! current_user_can( 'edit_post', $id ) ) { return self::error( 'The publishing user cannot edit this page’s SEO metadata.' ); }
        // SEO plugins hide their storage from WordPress's generic Custom Fields editor.
        // A protected key alone is not their publishing permission. Honor explicit meta
        // authorization callbacks, while the fixed adapter allowlist requires edit_post.
        if ( $id && isset( $definition[$field] ) && function_exists( 'has_filter' ) ) {
            $key = $definition[$field]; $type = get_post_type( $id );
            if ( ( has_filter( 'auth_post_meta_' . $key ) || has_filter( 'auth_post_meta_' . $key . '_for_' . $type ) ) && ! current_user_can( 'edit_post_meta', $id, $key ) ) { return self::error( 'The publishing user cannot edit the selected SEO field.' ); }
        }
        if ( 'rank_math' === $provider && is_callable( [ 'RankMath\\Helper', 'has_cap' ] ) && ! \RankMath\Helper::has_cap( 'onpage_snippet' ) ) { return self::error( 'The publishing user cannot edit Rank Math snippets.' ); }
        if ( 'aioseo' === $provider && ! is_callable( [ 'AIOSEO\\Plugin\\Common\\Models\\Post', 'savePost' ] ) ) { return self::error( 'This All in One SEO version does not expose its supported post model.' ); }
        if ( 'squirrly' === $provider ) {
            // Squirrly loads model classes through its own factory, not PHP autoloading.
            try { $api = is_callable( [ 'SQ_Classes_ObjController', 'getClass' ] ) ? SQ_Classes_ObjController::getClass( 'SQ_Models_Api_Seo' ) : false; }
            catch ( Throwable $error ) { $api = false; }
            if ( ! is_callable( [ $api, 'getSeo' ] ) || ! is_callable( [ $api, 'saveSeo' ] ) ) { return self::error( 'Update Squirrly SEO to a version with its native per-page SEO API.' ); }
        }
        return $definition;
    }

    public static function read( int $id, string $name, array $binding ) {
        $definition = self::check( $name, $binding, $id ); if ( is_wp_error( $definition ) ) { return $definition; }
        $field = $binding['type'];
        if ( 'aioseo' === $binding['key'] ) {
            $post = \AIOSEO\Plugin\Common\Models\Post::getPost( $id ); $value = $post->$field;
        } elseif ( 'squirrly' === $binding['key'] ) {
            $data = SQ_Classes_ObjController::getClass( 'SQ_Models_Api_Seo' )->getSeo( [ 'post_id' => $id ] );
            if ( is_wp_error( $data ) ) { return $data; } $value = $data['seo'][$field] ?? null;
        } elseif ( 'slim_seo' === $binding['key'] ) {
            $data = get_post_meta( $id, 'slim_seo', true );
            if ( '' !== $data && ! is_array( $data ) ) { return self::error( 'Slim SEO has unexpected per-page storage.' ); }
            $value = is_array( $data ) ? ( $data[$field] ?? null ) : null;
        } else {
            $data = get_post_meta( $id, $definition[$field], false );
            if ( ! is_array( $data ) || count( $data ) > 1 ) { return self::error( 'The selected SEO field has ambiguous native storage.' ); }
            $value = $data[0] ?? null;
        }
        if ( null !== $value && ! is_string( $value ) ) { return self::error( 'The selected SEO field is not text.' ); }
        return [ 'value' => $value, 'reference' => null ];
    }

    /** Only the selected leaf changes; robots, canonical URLs and social settings stay native. */
    public static function write( int $id, string $name, array $binding ) {
        $definition = self::check( $name, $binding, $id ); if ( is_wp_error( $definition ) ) { return $definition; }
        $field = $binding['type']; $value = $binding['value'];
        if ( 'aioseo' === $binding['key'] ) {
            \AIOSEO\Plugin\Common\Models\Post::savePost( $id, [ $field => $value ] );
        } elseif ( 'squirrly' === $binding['key'] ) {
            $saved = SQ_Classes_ObjController::getClass( 'SQ_Models_Api_Seo' )->saveSeo( [ 'post_id' => $id ], [ $field => $value ] );
            if ( is_wp_error( $saved ) ) { return $saved; }
        } elseif ( 'slim_seo' === $binding['key'] ) {
            $data = get_post_meta( $id, 'slim_seo', true );
            if ( '' !== $data && ! is_array( $data ) ) { return self::error( 'Slim SEO has unexpected per-page storage.' ); }
            $data = is_array( $data ) ? $data : []; $data[$field] = $value;
            update_post_meta( $id, 'slim_seo', wp_slash( $data ) );
        } else { update_post_meta( $id, $definition[$field], wp_slash( $value ) ); }
        $actual = self::read( $id, $name, $binding );
        return is_wp_error( $actual ) ? $actual : ( $actual['value'] === $value ? true : self::error( 'The SEO plugin did not retain the selected value.' ) );
    }
}
