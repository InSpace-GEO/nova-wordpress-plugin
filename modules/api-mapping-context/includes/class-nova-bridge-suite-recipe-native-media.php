<?php
/** Pure authoring contract for explicitly selected native image destinations. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Recipe_Native_Media {
    public const FEATURED = '/native_post/featured_image';
    public const TITLE = '/native_post/title';
    public const EXCERPT = '/native_post/excerpt';
    /** Native post columns written next to the layout, never inside the builder document. */
    public const POST_FIELDS = [ self::TITLE => 'title', self::EXCERPT => 'excerpt' ];

    public static function post_column( string $target ): ?string { return self::POST_FIELDS[ $target ] ?? null; }
    public static function is_native_post( string $target ): bool { return self::FEATURED === $target || isset( self::POST_FIELDS[ $target ] ); }

    public static function post_fields( string $post_type ): array {
        $fields = [ [ 'target' => self::TITLE, 'label' => 'Title', 'kind' => 'title', 'format' => 'text', 'allowed_formats' => [ 'text' ], 'writable' => true, 'required' => true, 'roles' => [ 'title', 'seo_title', 'custom' ],
            'value' => '', 'sample' => '', 'native_post' => 'title', 'context' => 'WordPress', 'instructions' => 'The page title. Themes usually show it as the main heading (H1).' ] ];
        if ( function_exists( 'post_type_supports' ) && post_type_supports( $post_type, 'excerpt' ) ) {
            $fields[] = [ 'target' => self::EXCERPT, 'label' => 'Excerpt', 'kind' => 'excerpt', 'format' => 'text', 'allowed_formats' => [ 'text' ], 'writable' => true, 'required' => false, 'roles' => [ 'intro', 'meta_description', 'custom' ],
                'value' => '', 'sample' => '', 'native_post' => 'excerpt', 'context' => 'WordPress', 'instructions' => 'A short plain-text summary. Themes, archives and dynamic builder content can show it.' ];
        }
        return $fields;
    }

    public static function featured_field(): array {
        return [ 'target' => self::FEATURED, 'label' => 'Featured image', 'kind' => 'image_url', 'format' => 'url', 'allowed_formats' => [ 'url' ], 'writable' => true, 'required' => false,
            'value' => '', 'sample' => '', 'instructions' => 'Choose the primary article image from image_url or image_urls. Return its exact image URL. If no suitable image is supplied, leave this optional field empty.', 'native_media' => [ 'kind' => 'featured' ] ];
    }

    public static function describe( array $description, string $resource ): array {
        if ( ! preg_match( '/^post_type:([a-z0-9_-]{1,20})$/D', $resource, $match ) ) { return $description; }
        $existing = array_column( $description['fields'] ?? [], 'target' );
        // Core layouts have their own title, but their body/Introduction is not the WordPress excerpt.
        foreach ( self::post_fields( $match[1] ) as $field ) {
            if ( self::TITLE === $field['target'] && in_array( '/document/title', $existing, true ) ) { continue; }
            if ( ! in_array( $field['target'], $existing, true ) ) { $description['fields'][] = $field; }
        }
        if ( function_exists( 'post_type_supports' ) && post_type_supports( $match[1], 'thumbnail' ) ) {
            $description['fields'][] = self::featured_field();
        }
        return $description;
    }

    /** Derive native pointers from the adapter, never from client-supplied metadata. */
    public static function spec( array $native, array $binding, $adapter ): ?array {
        $featured = self::FEATURED === ( $binding['target'] ?? '' );
        if ( ! array_key_exists( 'native_media', $binding ) && ! $featured ) { return null; }
        if ( true !== ( $binding['native_media'] ?? null ) || 'url' !== ( $binding['format'] ?? '' ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'Native image mappings require an explicit URL binding.' ); }
        if ( $featured ) { return [ 'kind' => 'featured' ]; }
        if ( ! is_callable( [ $adapter, 'describe' ] ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'This image field has no native media descriptor.' ); }
        $description = $adapter->describe( $native );
        if ( is_wp_error( $description ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( $description->get_error_message() ); }
        foreach ( $description['fields'] ?? [] as $field ) {
            if ( ( $field['target'] ?? '' ) !== $binding['target'] ) { continue; }
            $spec = $field['native_media'] ?? null;
            if ( empty( $field['writable'] ) || ! is_array( $spec ) || ! is_string( $spec['id_target'] ?? null ) ) { break; }
            Nova_Bridge_Suite_Recipe_Engine::parts( $spec['id_target'] );
            $kind = $spec['kind'] ?? '';
            if ( 'salient_header' === $kind && '/meta/_nectar_header_bg' === $binding['target'] && $adapter instanceof Nova_Bridge_Suite_Recipe_Native_Adapter ) { return [ 'kind' => $kind, 'id_target' => $binding['target'] ]; }
            if ( ( 'elementor' === $kind || ( 'enfold' === $kind && $adapter instanceof Nova_Bridge_Suite_Recipe_Enfold ) ) && ( $spec['url_target'] ?? '' ) === $binding['target'] ) {
                return [ 'kind' => $kind, 'url_target' => $spec['url_target'], 'id_target' => $spec['id_target'] ];
            }
            if ( 'avada' === $kind && ( $spec['url_target'] ?? '' ) === $binding['target'] && $adapter instanceof Nova_Bridge_Suite_Recipe_Native_Adapter ) {
                return [ 'kind' => 'avada', 'url_target' => $spec['url_target'], 'id_target' => $spec['id_target'] ];
            }
            if ( 'wpbakery' === $kind && $spec['id_target'] === $binding['target'] && $adapter instanceof Nova_Bridge_Suite_Recipe_Native_Adapter ) {
                return [ 'kind' => 'wpbakery', 'id_target' => $spec['id_target'] ];
            }
            if ( ( $spec['kind'] ?? '' ) === 'flatsome' && $spec['id_target'] === $binding['target'] && $adapter instanceof Nova_Bridge_Suite_Recipe_Flatsome ) {
                return [ 'kind' => 'flatsome', 'id_target' => $spec['id_target'] ];
            }
            if ( 'betheme' === $kind && $spec['id_target'] === $binding['target'] && $adapter instanceof Nova_Bridge_Suite_Recipe_Betheme ) {
                return [ 'kind' => $kind, 'id_target' => $binding['target'] ];
            }
            if ( 'cornerstone' === $kind && $spec['id_target'] === $binding['target'] && $adapter instanceof Nova_Bridge_Suite_Recipe_Cornerstone ) {
                return [ 'kind' => $kind, 'id_target' => $binding['target'] ];
            }
            if ( 'acf' === $kind && $spec['id_target'] === $binding['target'] && $adapter instanceof Nova_Bridge_Suite_Recipe_ACF ) {
                return [ 'kind' => $kind, 'id_target' => $binding['target'] ];
            }
            break;
        }
        Nova_Bridge_Suite_Recipe_Engine::fail( 'Only a captured native image control can use image import.' );
    }

    public static function entry( array $spec, string $url, bool $clear = false ): ?array {
        if ( '' === $url && ! $clear ) { return null; }
        if ( '' !== $url && ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://#i', $url ) || preg_match( '/[\x00-\x20]/', $url ) || null !== parse_url( $url, PHP_URL_USER ) || null !== parse_url( $url, PHP_URL_PASS ) ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'A mapped image must be an absolute HTTP(S) image URL.' ); }
        $entry = $spec + [ 'url' => $url ];
        if ( $clear ) { $entry['clear'] = true; }
        return $entry;
    }
}
