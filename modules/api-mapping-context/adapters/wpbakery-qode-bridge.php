<?php
/** Bridge theme settings and Bridge Core shortcode dependencies. */
defined( 'ABSPATH' ) || exit;

final class Nova_Mapping_Qode_Bridge {
    // Page-local layout controls declared by Bridge. Theme options and shared content stay on the site.
    private const META = [
        'qode_show-page-title', 'qode_show-page-title-text', 'qode_animate-page-title',
        'qode_page_title_position', 'qode_page-title-color', 'qode_page_title_font_size', 'qode_title_text_shadow',
        'qode_page-title-background-color', 'qode_show-page-title-image', 'qode_title-image', 'qode_title-overlay-image',
        'qode_responsive-title-image', 'qode_fixed-title-image', 'qode_title-height', 'qode_separator_bellow_title',
        'qode_title_separator_color', 'qode_title_gradient_separator_meta', 'qode_enable_page_title_angled',
        'qode_title_angled_section_direction', 'qode_title_angled_section_color', 'qode_enable_breadcrumbs',
        'qode_page_breadcrumbs_color', 'show_only_breadcrumbs_meta', 'qode_page_subtitle', 'qode_page_subtitle_color',
        'qode_page_text_above_title', 'qode_page_text_above_title_color', 'qode_margin_after_title',
        'qode_margin_after_title_mobile', 'qode_margin_after_title_mobile_value',
        'qode_show-sidebar', 'qode_choose-sidebar', 'qode_page_background_color', 'qode_page_background_image',
        'qode_page_background_image_fixed', 'qode_page_background_pattern_image', 'qode_show-animation',
        'qode_enable_content_top_margin', 'qode_transparent_content_page', 'qode_header-style',
        'qode_header-style-on-scroll', 'qode_header_color_per_page', 'qode_header_color_transparency_per_page',
        'qode_page_scroll_amount_for_sticky', 'qode_page_hide_initial_sticky',
    ];

    private const TITLE_FIELDS = [
        'qode_page_subtitle' => [ 'Page subtitle', 'subtitle' ],
        'qode_page_text_above_title' => [ 'Text above title', 'above_title' ],
    ];

    public static function active(): bool {
        return function_exists( 'get_template' ) && 'bridge' === get_template();
    }

    public static function meta_key( string $key ): bool {
        return self::active() && in_array( $key, self::META, true );
    }

    private static function is_shortcode( string $tag ): bool {
        return str_starts_with( $tag, 'qode_' );
    }

    /** Bridge Core can own the complete shortcode document without a vc_row wrapper. */
    public static function has_layout( string $content ): bool {
        if ( ! str_contains( $content, '[qode_' ) || ! self::active() || ! function_exists( 'get_shortcode_regex' ) ) { return false; }
        require_once __DIR__ . '/wpbakery-document.php';
        $nodes = nova_mapping_wpb_parse_shortcodes_to_compact( $content );
        if ( ! $nodes || nova_mapping_wpb_compact_to_shortcodes( $nodes ) !== $content ) { return false; }
        $has_qode = false;
        foreach ( $nodes as $node ) {
            if ( '' !== trim( $node['raw_before'] . $node['raw_after'] ) ) { return false; }
            $has_qode = $has_qode || self::is_shortcode( $node['tag'] );
        }
        return $has_qode;
    }

    public static function fields( array $meta ): array {
        if ( ! self::active() || 'yes' === ( $meta['qode_show-page-title'] ?? '' ) || 'yes' === ( $meta['qode_show-page-title-text'] ?? '' ) || 'yes' === ( $meta['show_only_breadcrumbs_meta'] ?? '' ) ) { return []; }
        $fields = [];
        foreach ( self::TITLE_FIELDS as $key => [ $label, $control ] ) {
            if ( ! is_string( $meta[$key] ?? null ) || '' === trim( $meta[$key] ) ) { continue; }
            $fields[] = [ 'target' => '/meta/' . $key, 'label' => 'Bridge · ' . $label,
                'format' => 'text', 'kind' => 'text', 'value' => html_entity_decode( wp_strip_all_tags( $meta[$key] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
                'context' => 'Bridge page title area', 'writable' => true, 'protected' => false,
                'locator' => [ 'strategy' => 'qode_title', 'node_id' => 'qode-title-area', 'control' => $control, 'precision' => 'field' ] ];
        }
        return $fields;
    }

    public static function is_title_target( string $target ): bool {
        return str_starts_with( $target, '/meta/' ) && isset( self::TITLE_FIELDS[substr( $target, 6 )] );
    }

    public static function probe( array $nodes, array $meta ) {
        if ( array_intersect( self::META, array_keys( $meta ) ) && ! self::active() ) {
            return new WP_Error( 'nova_qode_theme', 'Activate the Bridge theme to publish a layout using its page settings.', [ 'status' => 422 ] );
        }
        return self::check_shortcodes( $nodes );
    }

    private static function check_shortcodes( array $nodes ) {
        foreach ( $nodes as $node ) {
            $tag = (string) ( $node['tag'] ?? '' );
            if ( self::is_shortcode( $tag ) && function_exists( 'shortcode_exists' ) && ! shortcode_exists( $tag ) && ! self::has_lazy_renderer( $tag ) ) {
                return new WP_Error( 'nova_qode_element', 'The Qode element ' . $tag . ' is unavailable. Enable the Bridge Core component that renders it before publishing.', [ 'status' => 422 ] );
            }
            $result = self::check_shortcodes( $node['children'] ?? [] );
            if ( is_wp_error( $result ) ) { return $result; }
        }
        return true;
    }

    private static function has_lazy_renderer( string $tag ): bool {
        // WPBakery registers the Bridge Elements Holder callbacks when it renders post content.
        return in_array( $tag, [ 'qode_elements_holder', 'qode_elements_holder_item' ], true )
            && self::active() && class_exists( 'WPBakeryShortCode_' . $tag )
            && function_exists( 'get_template_directory' ) && is_readable( get_template_directory() . '/vc_templates/' . $tag . '.php' )
            && null !== Nova_Mapping_WPBakery_Elements::definition( $tag );
    }
}
