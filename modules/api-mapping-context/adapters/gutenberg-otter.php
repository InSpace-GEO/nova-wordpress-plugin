<?php
/** Editorial fields in Otter's native Gutenberg markup. */
defined( 'ABSPATH' ) || exit;

final class Nova_Mapping_Gutenberg_Otter {
    private const PREFIX = 'themeisle-blocks/';
    private const CONTAINERS = [ 'advanced-columns', 'advanced-column', 'button-group', 'accordion', 'accordion-item', 'icon-list' ];

    public static function structural( string $name ): bool {
        return self::is_block( $name ) && in_array( substr( $name, strlen( self::PREFIX ) ), self::CONTAINERS, true );
    }

    public static function is_block( string $name ): bool {
        return str_starts_with( $name, self::PREFIX );
    }

    public static function controls( array $block ): array {
        if ( ! class_exists( 'WP_HTML_Tag_Processor' ) || ! empty( $block['attrs']['metadata']['bindings'] ) ) { return []; }
        $html = $block['innerHTML'];
        $name = substr( (string) $block['blockName'], strlen( self::PREFIX ) );
        $attrs = $block['attrs'];
        $controls = [];
        if ( 'advanced-heading' === $name ) {
            $tag = is_string( $attrs['tag'] ?? 'h2' ) ? strtoupper( $attrs['tag'] ?? 'h2' ) : '';
            if ( ! preg_match( '/^(?:H[1-6]|DIV|P|SPAN)$/D', $tag ) ) { return []; }
            $element = self::element( $html, 'wp-block-themeisle-blocks-advanced-heading', $tag );
            self::text_control( $controls, $html, $element, '/innerHTML', 'heading' );
        } elseif ( 'button' === $name ) {
            $anchor = self::element( $html, 'wp-block-button__link', 'A' );
            $label = self::inside( $html, $anchor, 'SPAN' );
            self::text_control( $controls, $html, $label, '/innerHTML', 'button_text' );
            if ( $anchor && is_string( $anchor['href'] ) && ! self::dynamic( $anchor['href'] ) ) {
                $controls['/innerContent/0'] = [ 'kind' => 'link_url', 'format' => 'url', 'value' => $anchor['href'], 'element' => $anchor ];
            }
        } elseif ( 'icon-list-item' === $name ) {
            $element = self::element( $html, 'wp-block-themeisle-blocks-icon-list-item-content', 'P' )
                ?? self::element( $html, 'wp-block-themeisle-blocks-icon-list-item-content-custom', 'P' );
            self::text_control( $controls, $html, $element, '/attrs/content', 'body', $attrs['content'] ?? null );
        } elseif ( 'accordion-item' === $name ) {
            $summary = self::element( $html, 'wp-block-themeisle-blocks-accordion-item__title' );
            $tag = is_string( $attrs['tag'] ?? 'div' ) ? strtoupper( $attrs['tag'] ?? 'div' ) : '';
            if ( ! preg_match( '/^(?:H[1-6]|DIV|P|SPAN)$/D', $tag ) ) { return []; }
            $title = self::inside( $html, $summary, $tag );
            self::text_control( $controls, $html, $title, '/attrs/title', 'question', $attrs['title'] ?? null );
        }
        return $controls;
    }

    private static function dynamic( string $value ): bool {
        return 1 === preg_match( '/\{\{|\$variable\(|\[\/?[a-z_][^\]]*\]|<script\b/i', $value );
    }

    private static function text_control( array &$controls, string $html, ?array $element, string $target, string $kind, $attribute = null ): void {
        if ( ! $element || ( str_starts_with( $target, '/attrs/' ) && ! is_string( $attribute ) ) ) { return; }
        $markup = substr( $html, $element['body'], $element['end'] - $element['body'] );
        if ( self::dynamic( $markup ) || ( is_string( $attribute ) && self::dynamic( $attribute ) ) ) { return; }
        $format = 'body' === $kind ? 'html' : 'text';
        $value = 'html' === $format ? $markup : html_entity_decode( strip_tags( $markup ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $controls[$target] = [ 'kind' => $kind, 'format' => $format, 'value' => $value, 'element' => $element ];
    }

    private static function inside( string $html, ?array $outer, string $tag ): ?array {
        if ( ! $outer ) { return null; }
        $inner = self::element( substr( $html, $outer['body'], $outer['end'] - $outer['body'] ), '', $tag );
        if ( ! $inner ) { return null; }
        foreach ( [ 'start', 'body', 'end' ] as $key ) { $inner[$key] += $outer['body']; }
        return $inner;
    }

    private static function element( string $html, string $class, string $tag = '' ): ?array {
        require_once __DIR__ . '/gutenberg-html-tag-cursor.php';
        $cursor = new Nova_Mapping_Otter_HTML_Tags( $html );
        $match = null;
        $depth = 0;
        while ( $cursor->next_tag( [ 'tag_closers' => 'visit' ] ) ) {
            $current = $cursor->get_tag();
            if ( $depth && $current === $matched_tag ) {
                if ( ! $cursor->is_tag_closer() ) { ++$depth; continue; }
                if ( 0 === --$depth ) { [ $match['end'] ] = $cursor->span(); }
                continue;
            }
            if ( $cursor->is_tag_closer() || 'html' !== $cursor->get_namespace() || ( '' !== $tag && $tag !== $current ) || ( '' !== $class && ! $cursor->has_class( $class ) ) ) { continue; }
            if ( $match ) { return null; }
            [ $start, $length ] = $cursor->span();
            $match = [ 'start' => $start, 'body' => $start + $length, 'tag' => $current, 'href' => $cursor->get_attribute( 'href' ) ];
            $matched_tag = $current;
            $depth = 1;
        }
        return isset( $match['end'] ) && ! $cursor->paused_at_incomplete_token() ? $match : null;
    }

    public static function bind( array $block, string $target, array $control, string $format, string $value ) {
        if ( $control['value'] === $value ) { return $block; }
        $element = $control['element'];
        if ( self::dynamic( $value ) ) { return new WP_Error( 'nova_recipe_otter_value', 'Use static editorial content for the selected Otter field.', [ 'status' => 422 ] ); }
        if ( 'url' === $format ) {
            $offset = $element['start'];
            $length = $element['body'] - $offset;
            $tag = new WP_HTML_Tag_Processor( substr( $block['innerHTML'], $offset, $length ) );
            $tag->next_tag();
            if ( ! $tag->set_attribute( 'href', $value ) || $tag->get_attribute( 'href' ) !== $value ) {
                return new WP_Error( 'nova_recipe_otter_link', 'WordPress could not store this Otter button link exactly. Use a public URL or a root-relative path.', [ 'status' => 422 ] );
            }
            $replacement = $tag->get_updated_html();
        } else {
            $offset = $element['body'];
            $length = $element['end'] - $offset;
            if ( 'html' === $format && 'heading' === $control['kind'] ) {
                $tag = $element['tag'];
                if ( preg_match( '/\A\s*<' . $tag . '\b[^>]*>([\s\S]*)<\/' . $tag . '>\s*\z/i', $value, $outer ) ) { $value = $outer[1]; }
            }
            if ( 'html' === $format && preg_match( '/<\/?(?:h[1-6]|p|div|section|article|main|table|ul|ol|li|blockquote|pre|details)\b/i', $value ) ) {
                return new WP_Error( 'nova_recipe_otter_value', 'This Otter field accepts inline content inside its existing element.', [ 'status' => 422 ] );
            }
            $replacement = 'text' === $format ? esc_html( $value ) : $value;
            if ( str_starts_with( $target, '/attrs/' ) ) { $block['attrs'][basename( $target )] = $replacement; }
        }
        $block['innerHTML'] = substr_replace( $block['innerHTML'], $replacement, $offset, $length );
        // Otter accordion items contain child blocks, so their null placeholders must stay in place.
        $cursor = 0;
        foreach ( $block['innerContent'] as &$chunk ) {
            if ( ! is_string( $chunk ) ) { continue; }
            $end = $cursor + strlen( $chunk );
            if ( $offset >= $cursor && $offset < $end && $offset + $length <= $end ) {
                $chunk = substr_replace( $chunk, $replacement, $offset - $cursor, $length );
                return $block;
            }
            $cursor = $end;
        }
        return new WP_Error( 'nova_recipe_otter_markup', 'The Otter field crosses a native child block boundary.', [ 'status' => 422 ] );
    }

    public static function locator( string $path, array $block, string $target ): array {
        return [ 'strategy' => 'custom_html', 'marker' => self::marker( $path, $block, $target ), 'precision' => 'field' ];
    }

    private static function marker( string $path, array $block, string $target ): string {
        return substr( hash( 'sha256', 'otter:' . $path . ':' . $target . ':' . $block['innerHTML'] ), 0, 32 );
    }

    /** Apply the same source markers the mapping canvas expects, only on its preview copy. */
    public static function preview( array $blocks, string $parent = '/document', bool $inside_otter = false ): array {
        foreach ( $blocks as $index => &$block ) {
            $path = $parent . '/' . $index;
            $name = (string) $block['blockName'];
            $is_otter = self::is_block( $name );
            if ( $block['innerBlocks'] ) { $block['innerBlocks'] = self::preview( $block['innerBlocks'], $path . '/innerBlocks', $inside_otter || $is_otter ); }
            $markers = [];
            if ( $is_otter ) {
                foreach ( self::controls( $block ) as $target => $control ) {
                    $markers[$control['element']['start']][] = self::marker( $path, $block, $target );
                }
            } elseif ( $inside_otter && ( $target = self::core_target( $name ) ) ) {
                $markers[0][] = self::marker( $path, $block, $target );
            }
            krsort( $markers );
            foreach ( $markers as $offset => $ids ) {
                $cursor = 0;
                foreach ( $block['innerContent'] as &$chunk ) {
                    if ( ! is_string( $chunk ) ) { continue; }
                    $end = $cursor + strlen( $chunk );
                    if ( $offset < $end ) {
                        $local = $offset - $cursor;
                        $tag = new WP_HTML_Tag_Processor( substr( $chunk, $local ) );
                        if ( $tag->next_tag() ) {
                            $tag->set_attribute( 'data-nova-preview-fields', implode( ' ', $ids ) );
                            $chunk = substr( $chunk, 0, $local ) . $tag->get_updated_html();
                        }
                        break;
                    }
                    $cursor = $end;
                }
                unset( $chunk );
            }
        }
        return $blocks;
    }

    public static function core_target( string $name ): ?string {
        return in_array( $name, [ 'core/heading', 'core/paragraph', 'core/list-item', 'core/quote', 'core/preformatted', 'core/image', 'core/button' ], true ) ? '/innerHTML' : null;
    }
}
