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
                return [ 'alt' => 'text', 'src' => 'image_url', 'href' => 'link_url' ];
            case 'classic:section':
                return [ 'bg_image' => 'image_url' ];
            case 'section':
            case 'layout-row':
            case 'layout-column':
            case 'div':
                return [ 'bg_lower_image' => 'image_url', 'bg_upper_image' => 'image_url' ];
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
    public static function capture( $source, ?callable $get_defaults = null ): array {
        $elements = json_decode( is_string( $source ) ? $source : self::encode( $source ), false, 64, JSON_THROW_ON_ERROR );
        if ( ! is_array( $elements ) || ! $elements ) {
            throw new InvalidArgumentException( 'Choose an example with a saved Cornerstone element tree.' );
        }
        $defaults = [];
        return self::freeze( $elements, $get_defaults, $defaults );
    }

    private static function freeze( array $elements, ?callable $get_defaults, array &$defaults ): array {
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
                $node['children'] = self::freeze( $element->_modules, $get_defaults, $defaults );
                unset( $element->_modules );
            }
            foreach ( self::controls( $element->_type ) as $key => $kind ) {
                // Sparse native saves may omit an empty image or an untouched headline. Freeze the
                // builder's registered editorial default so that the field can still be mapped.
                if ( ! property_exists( $element, $key ) && $get_defaults ) {
                    $defaults[ $element->_type ] ??= $get_defaults( $element->_type );
                    $default = $defaults[ $element->_type ][ $key ] ?? null;
                    if ( is_string( $default ) ) { $element->{$key} = $default; }
                }
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
        // Cornerstone also expands URL-encoded dynamic content in ordinary rich text.
        if ( preg_match( '~\{[{%#]|%7B%7Bdc:|<(?:script|style|iframe|form)\b~i', $value ) ) { return true; }
        // Use WordPress's do_shortcode() tag alphabet; plugins may register names containing dots or colons.
        preg_match_all( '~\[/?([^<>&/\[\]\x00-\x20=]++)~', $value, $matches );
        foreach ( $matches[1] as $tag ) {
            if ( str_starts_with( $tag, 'cs_' ) || str_starts_with( $tag, 'x_' ) || ( function_exists( 'shortcode_exists' ) && shortcode_exists( $tag ) ) ) { return true; }
        }
        return false;
    }

    public static function inventory( array $blueprint ): array {
        $fields = [];
        // The native content region receives index 0, followed by its elements in preorder.
        $native_id = 0;
        $scan = static function ( array $nodes, string $path, string $parent, bool $locked ) use ( &$scan, &$fields, &$native_id ) {
            foreach ( $nodes as $index => $node ) {
                $element = self::attributes( $node );
                $type = $element->_type;
                $element_id = (string) ++$native_id;
                $label = ! empty( $element->_label ) && is_string( $element->_label ) ? strip_tags( $element->_label ) : ucfirst( $type );
                $context = ( $parent ? $parent . ' › ' : '' ) . $label . ' ' . ( $index + 1 );
                $pointer = $path . '/' . $index;
                $protected = $locked || ! empty( $element->_locked ) || ! empty( $element->looper_consumer );
                $kinds = self::controls( $type );
                foreach ( $node['values'] as $key => $value ) {
                    if ( ( 'text_subheadline_content' === $key && empty( $element->text_subheadline ) )
                        || ( 'bg_lower_image' === $key && 'image' !== ( $element->bg_lower_type ?? '' ) )
                        || ( 'bg_upper_image' === $key && 'image' !== ( $element->bg_upper_type ?? '' ) )
                        || ( 'classic:section' === $type && 'bg_image' === $key && 'image' !== ( $element->bg_type ?? '' ) ) ) {
                        continue;
                    }
                    $kind = $kinds[ $key ];
                    $unsafe = $protected || self::executable( $value );
                    $format = 'body' === $kind ? 'html' : ( str_ends_with( $kind, '_url' ) ? 'url' : 'text' );
                    $sample = $value;
                    if ( 'image_url' === $kind && preg_match( '/^([1-9][0-9]*)(?::([a-zA-Z0-9_-]+))?$/D', $value, $match ) ) {
                        $sample = function_exists( 'wp_get_attachment_image_url' ) ? ( wp_get_attachment_image_url( (int) $match[1], $match[2] ?? 'full' ) ?: '' ) : '';
                    }
                    $anchor = isset( $element->id ) && is_string( $element->id ) && preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]{0,127}$/D', $element->id ) ? $element->id : '';
                    $classic = str_starts_with( $type, 'classic:' );
                    $locator = $classic ? [ 'strategy' => 'unbound' ] : [ 'strategy' => 'cornerstone' ];
                    if ( $anchor ) { $locator = [ 'strategy' => 'native_anchor', 'anchor' => $anchor ]; }
                    $target = $pointer . '/values/' . $key;
                    $media = 'image_url' === $kind && ! $unsafe ? [ 'native_media' => [ 'kind' => 'cornerstone', 'id_target' => $target ] ] : [];
                    $fields[] = [
                        'target' => $target, 'node_target' => $pointer, 'label' => $label . ' · ' . str_replace( '_', ' ', $key ),
                        'format' => $format, 'kind' => $kind, 'value' => $sample, 'sample' => $sample, 'context' => $context,
                        'writable' => ! $unsafe, 'protected' => (bool) $unsafe, 'reason' => $unsafe ? 'Cornerstone controls this dynamic or protected value.' : '',
                        'element_type' => $type, 'control_path' => [ $key ], 'native_node_id' => $anchor ?: ( $classic ? '' : $element_id ),
                        'locator' => $locator,
                    ] + $media;
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
