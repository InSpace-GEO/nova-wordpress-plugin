<?php
/** Salient's page-local header settings and references to separately managed content. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Mapping_Salient {
    // Explicit page presentation keys; never copy portfolio content, SEO, caches or global display conditions.
    private const META = [
        '_header_nav_entrance_animation', '_header_nav_entrance_animation_delay', '_header_nav_entrance_animation_easing',
        '_disable_transparent_header', '_force_transparent_header', '_force_transparent_header_color',
        '_nectar_full_screen_rows', '_nectar_full_screen_rows_animation', '_nectar_full_screen_rows_animation_speed',
        '_nectar_full_screen_rows_overall_bg_color', '_nectar_full_screen_rows_anchors', '_nectar_full_screen_rows_mobile_disable',
        '_nectar_full_screen_rows_row_bg_animation', '_nectar_full_screen_rows_dot_navigation', '_nectar_full_screen_rows_content_overflow', '_nectar_full_screen_rows_footer',
        '_nectar_slider_bg_type', '_nectar_canvas_shapes', '_nectar_media_upload_webm', '_nectar_media_upload_mp4', '_nectar_media_upload_ogv',
        '_nectar_slider_preview_image', '_nectar_header_bg', '_nectar_header_parallax', '_nectar_header_box_roll', '_nectar_header_box_roll_disable_mobile',
        '_nectar_header_bg_height', '_nectar_header_fullscreen', '_nectar_header_title', '_nectar_header_subtitle', '_nectar_page_header_text-effect',
        '_nectar_particle_rotation_timing', '_nectar_particle_disable_explosion', '_nectar_page_header_alignment', '_nectar_page_header_alignment_v',
        '_nectar_page_header_bg_alignment', '_nectar_header_bg_color', '_nectar_header_font_color', '_nectar_header_bg_overlay_color',
        '_nectar_header_overlay', '_nectar_header_bottom_shadow', '_nectar_header_bg_overlay_opacity',
    ];
    public static function active(): bool { return function_exists( 'get_template' ) && 'salient' === get_template(); }
    public static function meta_key( string $key ): bool { return self::active() && in_array( $key, self::META, true ); }
    public static function shared( string $tag ): bool { return in_array( $tag, [ 'nectar_global_section', 'nectar_slider' ], true ); }
    public static function fields( array $meta ): array {
        $fields = [];
        foreach ( [ '_nectar_header_title' => [ 'Header title', 'text', 'heading', 'title' ], '_nectar_header_subtitle' => [ 'Header subtitle', 'html', 'body', 'subtitle' ], '_nectar_header_bg' => [ 'Header background image', 'url', 'image_url', 'image' ] ] as $key => $spec ) {
            if ( ! isset( $meta[$key] ) || ! is_string( $meta[$key] ) ) { continue; }
            // Responsive image objects and dynamic tokens must keep their original representation.
            if ( 'url' === $spec[1] && '' !== $meta[$key] && ! preg_match( '#^https?://#i', $meta[$key] ) ) { continue; }
            $target = '/meta/' . $key;
            $field = [ 'target' => $target, 'label' => 'Salient · ' . $spec[0], 'format' => $spec[1], 'kind' => $spec[2], 'value' => $meta[$key], 'context' => 'Salient page header', 'writable' => true, 'protected' => false,
                'locator' => [ 'strategy' => 'salient_header', 'node_id' => 'page-header-bg', 'control' => $spec[3], 'precision' => 'field' ] ];
            if ( 'url' === $spec[1] ) { $field['native_media'] = [ 'kind' => 'salient_header', 'id_target' => $target ]; }
            $fields[] = $field;
        }
        return $fields;
    }
    public static function warnings( array $nodes ): array {
        $warnings = [];
        foreach ( $nodes as $node ) {
            if ( self::shared( $node['tag'] ?? '' ) ) { $warnings[] = 'Salient global sections and Nectar Slider slides remain linked to their existing shared content. Edit them in Salient; this page mapping does not rewrite them.'; }
            $warnings = array_merge( $warnings, self::warnings( $node['children'] ?? [] ) );
        }
        return array_values( array_unique( $warnings ) );
    }
    /** Validate dependencies before reserving a destination; shared posts are never changed. */
    public static function probe( array $nodes, array $meta = [] ) {
        $checked = []; $remaining = 4096;
        return self::walk_dependencies( $nodes, $meta, [], 0, $checked, $remaining );
    }
    private static function walk_dependencies( array $nodes, array $meta, array $ancestors, int $depth, array &$checked, int &$remaining ) {
        if ( $depth > 32 ) { return new WP_Error( 'nova_salient_dependency', 'Salient shared sections exceed the supported nesting depth.' ); }
        if ( array_intersect( array_keys( $meta ), self::META ) && ! self::active() ) { return new WP_Error( 'nova_salient_theme', 'Activate Salient before publishing a layout with Salient page settings.' ); }
        foreach ( $nodes as $node ) {
            if ( --$remaining < 0 ) { return new WP_Error( 'nova_salient_dependency', 'Salient shared dependencies exceed the supported inspection limit.' ); }
            $tag = $node['tag'] ?? ''; $attrs = $node['attributes'] ?? [];
            if ( 0 === strpos( $tag, 'nectar_' ) && null === Nova_Mapping_WPBakery_Elements::definition( $tag ) && ! ( function_exists( 'shortcode_exists' ) && shortcode_exists( $tag ) ) ) { return new WP_Error( 'nova_salient_element', 'The Salient element ' . $tag . ' is unavailable. Activate its Salient plugin before publishing.' ); }
            if ( 'nectar_global_section' === $tag ) {
                $id = (int) ( $attrs['id'] ?? 0 ); $post = $id ? get_post( $id ) : null;
                if ( ! $post || 'salient_g_sections' !== $post->post_type || 'publish' !== $post->post_status ) { return new WP_Error( 'nova_salient_dependency', 'Salient global section ' . $id . ' must exist and be published on this site.' ); }
                if ( in_array( $id, $ancestors, true ) ) { return new WP_Error( 'nova_salient_dependency', 'Salient global sections contain a circular reference at section ' . $id . '.' ); }
                if ( ! isset( $checked[$id] ) ) {
                    $result = self::walk_dependencies( nova_mapping_wpb_parse_shortcodes_to_compact( $post->post_content ), [], array_merge( $ancestors, [ $id ] ), $depth + 1, $checked, $remaining );
                    if ( is_wp_error( $result ) ) { return $result; }
                    $checked[$id] = true;
                }
            } elseif ( 'nectar_slider' === $tag ) {
                $location = (string) ( $attrs['location'] ?? '' );
                $term = function_exists( 'get_term_by' ) ? get_term_by( 'name', html_entity_decode( $location, ENT_QUOTES, 'UTF-8' ), 'slider-locations' ) : false;
                if ( ! $term || is_wp_error( $term ) || ! get_posts( [ 'post_type' => 'nectar_slider', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'tax_query' => [ [ 'taxonomy' => 'slider-locations', 'field' => 'term_id', 'terms' => (int) $term->term_id ] ] ] ) ) { return new WP_Error( 'nova_salient_dependency', 'Nectar Slider location "' . $location . '" must contain a published slide on this site.' ); }
            }
            $result = self::walk_dependencies( $node['children'] ?? [], [], $ancestors, $depth + 1, $checked, $remaining ); if ( is_wp_error( $result ) ) { return $result; }
        }
        return true;
    }
}
