<?php
/** The editable element tree used by Themeco Cornerstone's content builder. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Cornerstone_Document {
    /** Only editorial controls may be bound. Other properties travel with their native element. */
    private static function controls( string $type ): array {
        switch ( $type ) {
            case 'headline':
                return [ 'text_content' => 'heading', 'text_subheadline_content' => 'text' ];
            case 'text':
                return [ 'text_content' => 'body' ];
            case 'classic:custom-headline':
            case 'classic:feature-headline':
                return [ 'content' => 'heading' ];
            case 'classic:text':
            case 'content-area':
                return [ 'content' => 'body' ];
            case 'quote':
                return [ 'quote_content' => 'body', 'quote_cite_content' => 'text' ];
            case 'card':
                return [ 'card_front_text_content' => 'body', 'card_back_text_content' => 'body', 'anchor_text_primary_content' => 'text', 'anchor_text_secondary_content' => 'text', 'anchor_href' => 'link_url' ];
            case 'button':
                return [ 'anchor_text_primary_content' => 'text', 'anchor_text_secondary_content' => 'text', 'anchor_href' => 'link_url' ];
            case 'classic:button':
                return [ 'content' => 'text', 'href' => 'link_url' ];
            case 'image':
                return [ 'image_alt' => 'text', 'image_src' => 'image_url' ];
            case 'classic:image':
                return [ 'alt' => 'text', 'src' => 'image_url' ];
            case 'accordion-item':
                return [ 'accordion_item_header_content' => 'heading', 'accordion_item_content' => 'body' ];
            case 'accordion-item-elements':
                return [ 'accordion_item_header_content' => 'heading' ];
        }
        return [];
    }

    private static function encode( $value ): string {
        return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR, 64 );
    }

    /** Decode into objects so empty settings objects and indexed objects survive a JS/PHP round trip. */
    public static function capture( $source ): array {
        $elements = json_decode( is_string( $source ) ? $source : self::encode( $source ), false, 64, JSON_THROW_ON_ERROR );
        if ( ! is_array( $elements ) || ! $elements ) {
            throw new InvalidArgumentException( 'Choose an example with a saved Cornerstone element tree.' );
        }
        return self::freeze( $elements );
    }

    private static function freeze( array $elements ): array {
        $frozen = [];
        foreach ( $elements as $element ) {
            if ( ! $element instanceof stdClass || ! is_string( $element->_type ?? null ) || '' === $element->_type ) {
                throw new InvalidArgumentException( 'A Cornerstone element is missing its native type.' );
            }
            $node = [ 'values' => [] ];
            if ( property_exists( $element, '_modules' ) ) {
                if ( ! is_array( $element->_modules ) ) {
                    throw new InvalidArgumentException( 'The Cornerstone child elements are not an ordered list.' );
                }
                $node['children'] = self::freeze( $element->_modules );
                unset( $element->_modules );
            }
            foreach ( self::controls( $element->_type ) as $key => $kind ) {
                if ( is_string( $element->{$key} ?? null ) ) {
                    $node['values'][ $key ] = $element->{$key};
                    unset( $element->{$key} );
                }
            }
            // Keep compound controls opaque to the recipe editor. Otherwise JSON {} becomes [].
            $node['attributes'] = self::encode( $element );
            $frozen[] = $node;
        }
        return $frozen;
    }

    private static function attributes( array $node ): stdClass {
        $element = json_decode( $node['attributes'] ?? '', false, 64, JSON_THROW_ON_ERROR );
        if ( ! $element instanceof stdClass || ! is_string( $element->_type ?? null ) || '' === $element->_type
            || property_exists( $element, '_modules' ) || ! is_array( $node['values'] ?? null ) ) {
            throw new InvalidArgumentException( 'The saved Cornerstone element is incomplete.' );
        }
        $controls = self::controls( $element->_type );
        foreach ( $node['values'] as $key => $value ) {
            if ( ! isset( $controls[ $key ] ) || ! is_string( $value ) || property_exists( $element, $key ) ) {
                throw new InvalidArgumentException( 'The saved Cornerstone control does not belong to its native element.' );
            }
        }
        return $element;
    }

    private static function thaw( array $nodes ): array {
        if ( ! Nova_Bridge_Suite_Recipe_Engine::list_array( $nodes ) ) {
            throw new InvalidArgumentException( 'The saved Cornerstone elements are not an ordered list.' );
        }
        $elements = [];
        foreach ( $nodes as $node ) {
            $element = self::attributes( $node );
            foreach ( $node['values'] as $key => $value ) {
                $element->{$key} = $value;
            }
            if ( array_key_exists( 'children', $node ) ) {
                $element->_modules = self::thaw( $node['children'] );
            }
            $elements[] = $element;
        }
        return $elements;
    }

    public static function source( array $nodes ): string {
        if ( ! $nodes ) { throw new InvalidArgumentException( 'The saved Cornerstone element tree is empty.' ); }
        return self::encode( self::thaw( $nodes ) );
    }

    private static function executable( string $value ): bool {
        if ( preg_match( '~\{\{|\{%|<(?:script|style|iframe|form)\b~i', $value ) ) { return true; }
        preg_match_all( '~\[/?([a-z][\w-]*)(?=[\s/\]])~i', $value, $matches );
        foreach ( $matches[1] as $tag ) {
            if ( str_starts_with( $tag, 'cs_' ) || str_starts_with( $tag, 'x_' ) || ( function_exists( 'shortcode_exists' ) && shortcode_exists( $tag ) ) ) { return true; }
        }
        return false;
    }

    public static function inventory( array $blueprint ): array {
        $fields = [];
        $scan = static function ( array $nodes, string $path, string $parent, bool $locked ) use ( &$scan, &$fields ) {
            foreach ( $nodes as $index => $node ) {
                $element = self::attributes( $node );
                $type = $element->_type;
                $label = ! empty( $element->_label ) && is_string( $element->_label ) ? strip_tags( $element->_label ) : ucfirst( $type );
                $context = ( $parent ? $parent . ' › ' : '' ) . $label . ' ' . ( $index + 1 );
                $pointer = $path . '/' . $index;
                $protected = $locked || ! empty( $element->_locked ) || ! empty( $element->looper_consumer );
                $kinds = self::controls( $type );
                foreach ( $node['values'] as $key => $value ) {
                    $kind = $kinds[ $key ];
                    $unsafe = $protected || self::executable( $value );
                    $format = 'body' === $kind ? 'html' : ( str_ends_with( $kind, '_url' ) ? 'url' : 'text' );
                    $sample = $value;
                    if ( 'image_url' === $kind && preg_match( '/^([1-9][0-9]*)(?::([a-zA-Z0-9_-]+))?$/D', $value, $match ) ) {
                        $sample = function_exists( 'wp_get_attachment_image_url' ) ? ( wp_get_attachment_image_url( (int) $match[1], $match[2] ?? 'full' ) ?: '' ) : '';
                    }
                    $anchor = isset( $element->id ) && is_string( $element->id ) && preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]{0,127}$/D', $element->id ) ? $element->id : '';
                    $fields[] = [
                        'target' => $pointer . '/values/' . $key, 'node_target' => $pointer, 'label' => $label . ' · ' . str_replace( '_', ' ', $key ),
                        'format' => $format, 'kind' => $kind, 'value' => $sample, 'sample' => $sample, 'context' => $context,
                        'writable' => ! $unsafe, 'protected' => (bool) $unsafe, 'reason' => $unsafe ? 'Cornerstone controls this dynamic or protected value.' : '',
                        'element_type' => $type, 'control_path' => [ $key ], 'native_node_id' => $anchor,
                        'locator' => $anchor ? [ 'strategy' => 'native_anchor', 'anchor' => $anchor ] : [ 'strategy' => 'unbound' ],
                    ];
                }
                if ( ! empty( $node['children'] ) ) {
                    $scan( $node['children'], $pointer . '/children', $context, $protected );
                }
            }
        };
        $scan( $blueprint['document'], '/document', '', false );
        return [ 'fields' => $fields, 'containers' => [] ];
    }

    public static function field( array $blueprint, array $binding ): ?array {
        foreach ( self::inventory( $blueprint )['fields'] as $field ) {
            if ( $field['writable'] && $field['target'] === ( $binding['target'] ?? null ) && $field['format'] === ( $binding['format'] ?? null ) ) {
                return $field;
            }
        }
        return null;
    }

    public static function bind( array $blueprint, array $binding, string $value ): array {
        $field = self::field( $blueprint, $binding );
        if ( ! $field ) {
            throw new InvalidArgumentException( 'Choose a writable editorial control from the saved Cornerstone layout.' );
        }
        if ( self::executable( $value ) ) {
            throw new InvalidArgumentException( 'Mapped Cornerstone content cannot introduce executable builder expressions or embedded controls.' );
        }
        if ( 'image_url' === $field['kind'] ) {
            Nova_Bridge_Suite_Recipe_Native_Media::entry( [], $value );
        }
        // Headline and button text are HTML-capable in Cornerstone. Keep a fitted plain value literal.
        if ( 'text' === $field['format'] && ! in_array( $field['control_path'][0], [ 'image_alt', 'alt' ], true ) ) {
            $value = htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false );
        }
        Nova_Bridge_Suite_Recipe_Engine::put( $blueprint, $field['target'], $value );
        return $blueprint;
    }
}
