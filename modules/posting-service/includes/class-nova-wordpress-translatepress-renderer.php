<?php
/** Group rendered source fields for TranslatePress without modifying the canonical post or builder data. */
defined( 'ABSPATH' ) || exit;

final class Nova_WordPress_TranslatePress_Renderer {
    private const KEY = '_nova_trp_render_fields';

    public static function prepare( int $id, bool $body, array $custom ) {
        $old = get_post_meta( $id, self::KEY, true );
        $fields = is_array( $old ) ? $old : [];
        if ( $body ) { $fields['content'] = true; }
        foreach ( array_keys( $custom ) as $key ) {
            if ( ! is_string( $key ) || is_protected_meta( $key, 'post' ) ) { return new WP_Error( 'nova_trp_invalid_custom_field', 'Choose a public editorial field.' ); }
            $fields['meta'][$key] = true;
        }
        if ( $fields === $old || ! $fields ) { return true; }
        update_post_meta( $id, self::KEY, $fields );
        return get_post_meta( $id, self::KEY, true ) === $fields ? true : new WP_Error( 'nova_trp_write_failed', 'Cannot register the source fields for TranslatePress.' );
    }

    public static function on_request(): void {
        if ( is_admin() || ! is_singular() || is_feed() ) { return; }
        $id = (int) get_queried_object_id();
        $fields = get_post_meta( $id, self::KEY, true );
        if ( ! is_array( $fields ) ) { return; }
        $body_meta = ! empty( $fields['content'] ) && in_array( get_post_type( $id ), nova_bridge_suite_get_managed_blog_post_types(), true ) ? 'blog_part_1' : '';
        if ( ! empty( $fields['content'] ) && '' === $body_meta ) {
            add_filter( 'the_content', static function( $content ) use ( $id ) {
                return get_the_ID() === $id ? self::wrap( $content, 'nova-trp-source-' . $id ) : $content;
            }, PHP_INT_MAX );
        }
        if ( empty( $fields['meta'] ) && '' === $body_meta ) { return; }
        add_filter( 'get_post_metadata', static function( $value, $object, $key, $single ) use ( $id, $fields, $body_meta ) {
            static $reading = false;
            if ( $reading || $id !== (int) $object || ! $single || '' === $key || ( $key !== $body_meta && empty( $fields['meta'][$key] ) ) ) { return $value; }
            $reading = true;
            try { $raw = null === $value ? get_post_meta( $id, $key, true ) : ( is_array( $value ) ? reset( $value ) : $value ); }
            finally { $reading = false; }
            $decorated = $key === $body_meta ? self::wrap( $raw, 'nova-trp-source-' . $id ) : self::render_leaves( $raw, 'custom_fields:' . $key );
            return [ $decorated ];
        }, 20, 4 );
    }

    private static function render_leaves( $value, string $path ) {
        if ( is_array( $value ) ) {
            foreach ( $value as $key => $nested ) { $value[$key] = self::render_leaves( $nested, $path . ':' . $key ); }
        } elseif ( is_string( $value ) && $value !== wp_strip_all_tags( $value ) ) {
            return self::wrap( $value, 'nova-trp-field-' . md5( $path ) );
        }
        return $value;
    }

    private static function wrap( $content, string $marker ) {
        return is_string( $content ) && '' !== $content && false === strpos( $content, $marker ) ?
            '<div class="translation-block ' . $marker . '">' . $content . '</div>' : $content;
    }
}
