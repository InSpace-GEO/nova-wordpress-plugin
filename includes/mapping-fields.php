<?php
/** Existing scalar ACF fields retained in mappings created on the Suite. */
defined( 'ABSPATH' ) || exit;

final class Nova_WordPress_Mapping_Fields {
    public static function register(): void {
        if ( ! function_exists( 'acf_get_field' ) ) {
            return;
        }
        global $wp_rest_additional_fields;
        foreach ( get_post_types( [ 'show_in_rest' => true ] ) as $type ) {
            if ( isset( $wp_rest_additional_fields[ $type ]['meta_all'] ) || in_array( $type, [ 'revision', 'attachment' ], true ) ) {
                continue;
            }
            register_rest_field( $type, 'meta_all', [
                'get_callback'    => [ __CLASS__, 'read' ],
                'update_callback' => [ __CLASS__, 'update' ],
                'schema'          => [ 'type' => 'object', 'context' => [ 'edit' ], 'additionalProperties' => [ 'type' => 'string' ] ],
            ] );
        }
    }

    public static function field( int $id, string $name ): ?array {
        if ( ! Nova_Bridge_Suite_Content_Transport::safe_key( $name ) || ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_]*$/D', $name ) || ! current_user_can( 'edit_post_meta', $id, $name ) ) {
            return null;
        }
        $values = get_post_meta( $id, $name, false );
        $key = get_post_meta( $id, '_' . $name, true );
        if ( count( $values ) !== 1 || ! is_string( $values[0] ) || ! is_string( $key ) || strpos( $key, 'field_' ) !== 0 ) {
            return null;
        }
        $field = acf_get_field( $key );
        if ( ! is_array( $field ) || ! empty( $field['readonly'] ) || ! empty( $field['disabled'] ) || ! in_array( $field['type'] ?? '', [ 'text', 'textarea', 'wysiwyg', 'url', 'email' ], true ) ) {
            return null;
        }
        return $field;
    }

    public static function read( $item, $attribute, $request ) {
        $id = (int) ( $item['id'] ?? 0 );
        $out = [];
        if ( 'edit' === $request->get_param( 'context' ) && current_user_can( 'edit_post', $id ) ) {
            foreach ( array_keys( get_post_meta( $id ) ) as $name ) {
                if ( self::field( $id, $name ) ) {
                    $out[ $name ] = get_post_meta( $id, $name, true );
                }
            }
        }
        return (object) $out;
    }

    public static function update( $value, $post ) {
        if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
            return new WP_Error( 'rest_forbidden', 'Cannot edit this post.', [ 'status' => 403 ] );
        }
        if ( ! is_array( $value ) ) {
            return new WP_Error( 'rest_invalid_param', 'Expected an object of mapped ACF values.', [ 'status' => 400 ] );
        }
        $changes = [];
        foreach ( $value as $name => $raw ) {
            $field = self::field( $post->ID, $name );
            if ( ! $field || ! is_string( $raw ) ) {
                return new WP_Error( 'rest_invalid_param', 'Select an existing editable scalar ACF field.', [ 'status' => 400 ] );
            }
            switch ( $field['type'] ) {
                case 'wysiwyg':  $changes[ $name ] = wp_kses_post( $raw ); break;
                case 'textarea': $changes[ $name ] = sanitize_textarea_field( $raw ); break;
                case 'url':      $changes[ $name ] = esc_url_raw( $raw ); break;
                case 'email':    $changes[ $name ] = sanitize_email( $raw ); break;
                default:         $changes[ $name ] = sanitize_text_field( $raw );
            }
        }
        foreach ( $changes as $name => $clean ) {
            update_post_meta( $post->ID, $name, wp_slash( $clean ) );
            if ( get_post_meta( $post->ID, $name, true ) !== $clean ) {
                return new WP_Error( 'nova_acf_write_failed', 'WordPress could not save the mapped ACF value.', [ 'status' => 500 ] );
            }
            if ( function_exists( 'acf_flush_value_cache' ) ) {
                acf_flush_value_cache( $post->ID, $name );
            }
        }
        return true;
    }
}
add_action( 'rest_api_init', [ Nova_WordPress_Mapping_Fields::class, 'register' ], 9 );
