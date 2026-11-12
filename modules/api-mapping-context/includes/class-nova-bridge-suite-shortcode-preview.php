<?php
/**
 * Mapping-preview markers for shortcode layouts (WPBakery, Avada, Divi 4).
 *
 * Only the authorized preview render receives a temporary marker attribute on each captured
 * shortcode. The builder renders normally; the marker is then copied onto the first element of
 * that shortcode's output so the canvas can outline exactly the selected element. Nothing is saved.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Shortcode_Preview {
    private const ATTRIBUTE = 'nova_preview_node';

    public static function marker( string $path, string $content_hash ): string {
        return substr( hash( 'sha256', 'shortcode:' . $path . ':' . $content_hash ), 0, 32 );
    }

    /** Hash of the serialized captured document, identical to the stored post content it was read from. */
    public static function document_hash( array $document ): string {
        require_once dirname( __DIR__ ) . '/adapters/wpbakery-document.php';
        return hash( 'sha256', nova_mapping_wpb_compact_to_shortcodes( $document ) );
    }

    /** Adds markers to stored shortcodes before WordPress renders them. Unchanged when the document cannot round-trip. */
    public static function mark_content( string $content ): string {
        if ( false === strpos( $content, '[' ) || ! function_exists( 'get_shortcode_regex' ) ) { return $content; }
        require_once dirname( __DIR__ ) . '/adapters/wpbakery-document.php';
        try {
            $document = nova_mapping_wpb_parse_shortcodes_to_compact( $content );
            if ( ! $document || nova_mapping_wpb_compact_to_shortcodes( $document ) !== $content ) { return $content; }
            $marked = nova_mapping_wpb_compact_to_shortcodes( self::mark_nodes( $document, '/document', hash( 'sha256', $content ) ) );
            return is_string( $marked ) && '' !== $marked ? $marked : $content;
        } catch ( Throwable $error ) { return $content; }
    }

    private static function mark_nodes( array $nodes, string $prefix, string $hash ): array {
        foreach ( $nodes as $index => &$node ) {
            $path = $prefix . '/' . $index;
            if ( ! is_array( $node ) || ! is_string( $node['tag'] ?? null ) ) { continue; }
            $node['attributes'][ self::ATTRIBUTE ] = self::marker( $path, $hash );
            if ( ! empty( $node['children'] ) ) { $node['children'] = self::mark_nodes( $node['children'], $path . '/children', $hash ); }
        }
        unset( $node );
        return $nodes;
    }

    /** do_shortcode_tag filter: copy the marker onto the element the shortcode rendered. */
    public static function tag_output( $output, $tag, $attributes ) {
        if ( ! is_string( $output ) || ! is_array( $attributes ) || ! is_string( $attributes[ self::ATTRIBUTE ] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $attributes[ self::ATTRIBUTE ] ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) { return $output; }
        $html = new WP_HTML_Tag_Processor( $output );
        // Elements may print their own styles or scripts first; mark the first visible element.
        do { if ( ! $html->next_tag() ) { return $output; } } while ( in_array( $html->get_tag(), [ 'STYLE', 'SCRIPT', 'LINK', 'META', 'NOSCRIPT', 'TEMPLATE' ], true ) );
        $existing = $html->get_attribute( 'data-nova-preview-node' );
        $html->set_attribute( 'data-nova-preview-node', trim( ( is_string( $existing ) ? $existing . ' ' : '' ) . $attributes[ self::ATTRIBUTE ] ) );
        return $html->get_updated_html();
    }
}
