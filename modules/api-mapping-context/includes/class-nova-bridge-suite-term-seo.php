<?php
/** Editable WooCommerce taxonomy SEO fields backed by the active provider. */
defined( 'ABSPATH' ) || exit;

final class Nova_Bridge_Suite_Term_SEO {
    private const FIELDS = [ 'wpseo_title' => 'title', 'wpseo_desc' => 'desc' ];

    public static function bootstrap(): void {
        add_action( 'rest_api_init', [ self::class, 'register_fields' ], 5 );
    }

    public static function register_fields(): void {
        if ( ! defined( 'WPSEO_VERSION' ) || ! is_callable( [ 'WPSEO_Taxonomy_Meta', 'get_term_meta' ] )
            || ! is_callable( [ 'WPSEO_Taxonomy_Meta', 'set_values' ] ) ) { return; }
        foreach ( [ 'product_cat', 'product_tag' ] as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) { continue; }
            foreach ( self::FIELDS as $key => $field ) {
                // A site's existing adapter owns its registration and callbacks.
                if ( isset( $GLOBALS['wp_rest_additional_fields'][$taxonomy][$key] ) ) { continue; }
                register_rest_field( $taxonomy, $key, [
                    'get_callback' => [ self::class, 'read' ],
                    'update_callback' => [ self::class, 'write' ],
                    'sanitize_callback' => [ self::class, 'sanitize' ],
                    'schema' => [ 'type' => 'string', 'default' => '', 'maxLength' => 4096,
                        'title' => 'Yoast SEO · ' . ( 'title' === $field ? 'SEO title' : 'Meta description' ),
                        'description' => 'Native Yoast taxonomy ' . ( 'title' === $field ? 'SEO title.' : 'meta description.' ),
                        'context' => [ 'view', 'edit' ],
                    ],
                ] );
            }
        }
    }

    public static function sanitize( $value, $request = null, $key = '' ) {
        if ( ! isset( self::FIELDS[$key] ) || ! is_string( $value ) || strlen( $value ) > 16384
            || false !== strpos( $value, "\0" ) || 1 !== preg_match( '//u', $value ) ) {
            return new WP_Error( 'nova_term_seo', 'Supply a valid SEO title or description.', [ 'status' => 422 ] );
        }
        $clean = WPSEO_Taxonomy_Meta::validate_term_meta_data( [ $key => $value ], [] );
        return $clean[$key] ?? '';
    }

    private static function term( $object, string $taxonomy ) {
        $id = is_object( $object ) ? (int) ( $object->term_id ?? 0 ) : (int) ( $object['id'] ?? $object['term_id'] ?? 0 );
        $term = $id > 0 ? get_term( $id, $taxonomy ) : null;
        return $term && ! is_wp_error( $term ) && in_array( $term->taxonomy, [ 'product_cat', 'product_tag' ], true )
            ? $term : new WP_Error( 'nova_term_seo', 'The SEO field needs an existing WooCommerce term.', [ 'status' => 404 ] );
    }

    public static function read( $object, $key, $request = null, $taxonomy = '' ) {
        if ( ! isset( self::FIELDS[$key] ) ) { return ''; }
        $term = self::term( $object, $taxonomy );
        if ( is_wp_error( $term ) ) { return $term; }
        $value = WPSEO_Taxonomy_Meta::get_term_meta( (int) $term->term_id, $term->taxonomy, self::FIELDS[$key] );
        return is_string( $value ) ? $value : '';
    }

    public static function write( $value, $object, $key, $request = null, $taxonomy = '' ) {
        $term = self::term( $object, $taxonomy );
        if ( is_wp_error( $term ) ) { return $term; }
        if ( ! current_user_can( 'edit_term', (int) $term->term_id ) ) {
            return new WP_Error( 'nova_term_seo', 'You cannot edit this term.', [ 'status' => 403 ] );
        }
        $value = self::sanitize( $value, $request, $key );
        if ( is_wp_error( $value ) ) { return $value; }
        $before = WPSEO_Taxonomy_Meta::get_term_meta( (int) $term->term_id, $term->taxonomy );
        if ( ! is_array( $before ) ) {
            return new WP_Error( 'nova_term_seo', 'The existing Yoast fields could not be read.', [ 'status' => 409 ] );
        }
        // Pass the complete native record so provider defaults cannot erase sibling fields.
        WPSEO_Taxonomy_Meta::set_values( (int) $term->term_id, $term->taxonomy, array_replace( $before, [ $key => $value ] ) );
        if ( function_exists( 'YoastSEO' ) ) {
            $watcher = YoastSEO()->classes->get( 'Yoast\WP\SEO\Integrations\Watchers\Indexable_Term_Watcher' );
            $watcher->build_indexable( (int) $term->term_id );
        }
        return self::read( $object, $key, $request, $taxonomy ) === $value
            ? true : new WP_Error( 'nova_term_seo', 'Yoast did not retain the SEO field.', [ 'status' => 409 ] );
    }
}
