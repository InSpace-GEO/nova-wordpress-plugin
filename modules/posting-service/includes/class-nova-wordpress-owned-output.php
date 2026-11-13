<?php
/** Preserve the rendered output of fields supplied for the requested locale. */
defined( 'ABSPATH' ) || exit;

final class Nova_WordPress_Owned_Output {
    private static $registered = false;
    private static $elements = [];
    private static $frames = [];
    private static $tokens = [];
    private static $attributes = [];
    private static $native = [];

    public static function native( int $id, array $values ): void {
        self::$native[$id] = array_replace( self::$native[$id] ?? [], $values );
        self::register();
    }

    public static function register( array $elements = [] ): void {
        self::$elements = array_replace( self::$elements, $elements );
        if ( self::$registered ) { return; }
        self::$registered = true;
        add_filter( 'elementor/frontend/builder_content_data', [ self::class, 'builder' ], 1 );
        add_filter( 'elementor/frontend/the_content', [ self::class, 'finish_builder' ], PHP_INT_MAX );
        add_action( 'elementor/frontend/widget/before_render', [ self::class, 'begin' ], 1 );
        add_filter( 'elementor/widget/render_content', [ self::class, 'finish' ], PHP_INT_MAX, 2 );
        add_action( 'elementor/frontend/widget/after_render', [ self::class, 'cleanup' ], PHP_INT_MAX );
        add_filter( 'weglot_render_dom', [ self::class, 'restore_attributes' ], PHP_INT_MAX );
        $disable = static function () { return 'disable'; };
        add_filter( 'option_elementor_element_cache_ttl', $disable, 99 );
        add_filter( 'default_option_elementor_element_cache_ttl', $disable, 99 );
    }

    /** Mark values only while a native widget is rendering, retaining plain API values. */
    public static function value( $value, string $type = 'text' ) {
        if ( ! self::$frames ) { return $value; }
        if ( is_array( $value ) ) {
            foreach ( $value as &$item ) { $item = self::value( $item, $type ); }
            return $value;
        }
        if ( ! is_string( $value ) || '' === $value ) { return $value; }
        if ( 'text' === $type && strpos( $value, '<' ) !== false ) { $type = 'html'; }
        return self::mark( $value, $type );
    }

    private static function token(): string {
        $id = str_replace( '-', '', wp_generate_uuid4() );
        self::$tokens[strtolower( $id )] = true;
        return $id;
    }

    private static function mark_text( string $value ): string {
        $id = self::token();
        return 'NOVAOWNED_' . $id . '_START' . $value . 'NOVAOWNED_' . $id . '_END';
    }

    private static function mark_url( string $value ): string {
        return $value . '#nova-owned-' . self::token();
    }

    private static function mark( string $value, string $type ): string {
        if ( 'url' === $type ) { return self::mark_url( $value ); }
        if ( ! in_array( $type, [ 'html', 'rich_text', 'wysiwyg' ], true ) ) { return self::mark_text( $value ); }
        $html = new WP_HTML_Tag_Processor( $value );
        while ( $html->next_token() ) {
            if ( '#text' === $html->get_token_type() ) {
                $text = $html->get_modifiable_text();
                if ( '' !== trim( $text ) ) { $html->set_modifiable_text( self::mark_text( $text ) ); }
            } elseif ( null !== $html->get_tag() && ! $html->is_tag_closer() ) {
                foreach ( [ 'href', 'src', 'alt', 'title', 'aria-label', 'placeholder' ] as $name ) {
                    $text = $html->get_attribute( $name );
                    if ( ! is_string( $text ) || '' === $text ) { continue; }
                    $html->set_attribute( $name, in_array( $name, [ 'href', 'src' ], true ) ? self::mark_url( $text ) : self::mark_text( $text ) );
                }
            }
        }
        return $html->get_updated_html();
    }

    /** Elementor may cache dynamic settings while constructing its widgets. */
    public static function builder( $data ) {
        if ( ! is_array( $data ) || ! $data ) { return $data; }
        self::begin( null );
        return self::mark_elements( $data );
    }

    private static function mark_elements( array $nodes ): array {
        foreach ( $nodes as &$node ) {
            foreach ( self::$elements[$node['id'] ?? ''] ?? [] as $field ) {
                $value = &$node['settings'];
                foreach ( $field['path'] as $part ) {
                    if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) { unset( $value ); continue 2; }
                    $value = &$value[$part];
                }
                if ( is_string( $value ) && hash( 'sha256', $value ) === $field['hash'] ) { $value = self::mark( $value, $field['type'] ); }
                unset( $value );
            }
            if ( ! empty( $node['elements'] ) ) { $node['elements'] = self::mark_elements( $node['elements'] ); }
        }
        return $nodes;
    }

    public static function blocks( string $html, array $ownership ): string {
        if ( ( $ownership['hash'] ?? '' ) !== hash( 'sha256', $html ) ) { return $html; }
        $blueprint = $ownership['blueprint'];
        $adapter = Nova_Bridge_Suite_Recipe_Builders::adapter( 'gutenberg' );
        foreach ( $ownership['fields'] as $field ) {
            $blueprint = $adapter->bind( $blueprint, $field['binding'], self::mark( $field['value'], $field['binding']['format'] ?? 'text' ) );
            if ( is_wp_error( $blueprint ) ) { return $html; }
        }
        $result = $adapter->finalize( $blueprint, 'localized-render' );
        return is_wp_error( $result ) ? $html : self::finish( do_blocks( $result['writer_payload']['native_document'] ) );
    }

    public static function begin( $widget ): void {
        $frame = [ 'widget' => $widget, 'settings' => [] ];
        $id = (int) get_queried_object_id();
        global $wp_query, $post;
        if ( isset( self::$native[$id] ) && is_object( $post ) && (int) ( $post->ID ?? 0 ) === $id ) {
            $frame['post_object'] = $post;
            $post = clone $post;
            foreach ( self::$native[$id] as $name => $value ) {
                if ( is_string( $value ) && property_exists( $post, $name ) ) {
                    $post->$name = self::mark( $value, in_array( $name, [ 'description', 'post_content' ], true ) ? 'html' : 'text' );
                }
            }
        }
        if ( isset( self::$native[$id], $wp_query->queried_object ) && is_object( $wp_query->queried_object ) ) {
            $frame['query_object'] = $wp_query->queried_object;
            $wp_query->queried_object = clone $wp_query->queried_object;
            foreach ( self::$native[$id] as $name => $value ) {
                if ( is_string( $value ) && property_exists( $wp_query->queried_object, $name ) ) {
                    $wp_query->queried_object->$name = self::mark( $value, in_array( $name, [ 'description', 'post_content' ], true ) ? 'html' : 'text' );
                }
            }
        }
        self::$frames[] = $frame;
        if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_id' ) || ! method_exists( $widget, 'get_settings' ) || ! method_exists( $widget, 'set_settings' ) ) { return; }
        $fields = self::$elements[$widget->get_id()] ?? [];
        foreach ( $fields as $field ) {
            $path = $field['path'] ?? [];
            if ( ! $path ) { continue; }
            $settings = $widget->get_settings();
            $value = $settings;
            foreach ( $path as $part ) { $value = is_array( $value ) ? ( $value[$part] ?? null ) : null; }
            if ( ! is_string( $value ) || hash( 'sha256', $value ) !== ( $field['hash'] ?? '' ) ) { continue; }
            $root = array_shift( $path );
            $index = count( self::$frames ) - 1;
            if ( ! array_key_exists( $root, self::$frames[$index]['settings'] ) ) {
                self::$frames[$index]['settings'][$root] = $settings[$root];
            }
            $replacement = $settings[$root];
            $leaf = &$replacement;
            foreach ( $path as $part ) { $leaf = &$leaf[$part]; }
            $leaf = self::mark( $value, $field['type'] ?? 'text' );
            unset( $leaf );
            $widget->set_settings( $root, $replacement );
        }
    }

    public static function cleanup( $widget ): void {
        $index = count( self::$frames ) - 1;
        if ( $index < 0 || self::$frames[$index]['widget'] !== $widget ) { return; }
        $frame = array_pop( self::$frames );
        foreach ( $frame['settings'] as $name => $value ) { $widget->set_settings( $name, $value ); }
        if ( isset( $frame['query_object'] ) ) { global $wp_query; $wp_query->queried_object = $frame['query_object']; }
        if ( isset( $frame['post_object'] ) ) { $GLOBALS['post'] = $frame['post_object']; }
    }

    /** Protect only the byte ranges emitted for supplied document fields. */
    public static function content( string $html, array $ownership ): string {
        if ( ( $ownership['hash'] ?? '' ) !== hash( 'sha256', $html ) ) { return $html; }
        $cursor = 0; $output = '';
        foreach ( $ownership['ranges'] ?? [] as $range ) {
            $start = $range['offset']; $length = $range['length'];
            if ( ! is_int( $start ) || ! is_int( $length ) || $start < $cursor || $length < 0 || $start + $length > strlen( $html ) ) { return $html; }
            $fragment = substr( $html, $start, $length );
            $output .= substr( $html, $cursor, $start - $cursor ) . ( ! empty( $range['owned'] ) ? self::mark( $fragment, 'html' ) : $fragment );
            $cursor = $start + $length;
        }
        return self::finish( $output . substr( $html, $cursor ) );
    }

    public static function finish( $html, $widget = null ) {
        if ( null !== $widget ) { self::cleanup( $widget ); }
        if ( ! is_string( $html ) || ! self::$tokens ) { return $html; }
        $document = new WP_HTML_Tag_Processor( $html );
        $replacements = [];
        while ( $document->next_token() ) {
            if ( '#text' === $document->get_token_type() ) {
                $text = $document->get_modifiable_text();
                // Excerpt filters can trim the closing token along with the last words.
                $text = preg_replace_callback( '/NOVAOWNED_([a-z0-9]+)_START(.*?)(?:NOVAOWNED_\1_END|$)/is', static function ( $match ) use ( &$replacements ) {
                    if ( ! isset( self::$tokens[strtolower( $match[1] )] ) ) { return $match[0]; }
                    $placeholder = 'NOVA_RENDER_' . str_replace( '-', '', wp_generate_uuid4() );
                    $replacements[$placeholder] = '<span data-wg-notranslate>' . htmlspecialchars( $match[2], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</span>';
                    return $placeholder;
                }, $text );
                $text = self::strip_tokens( $text );
                $document->set_modifiable_text( $text );
            } elseif ( null !== $document->get_tag() && ! $document->is_tag_closer() ) {
                if ( in_array( $document->get_tag(), [ 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE' ], true ) ) {
                    $raw = $document->get_modifiable_text();
                    $clean = self::strip_tokens( $raw );
                    if ( $clean !== $raw ) {
                        $document->set_modifiable_text( $clean );
                        $document->set_attribute( 'data-wg-notranslate', true );
                    }
                }
                $owned = [];
                foreach ( $document->get_attribute_names_with_prefix( '' ) ?? [] as $name ) {
                    $value = $document->get_attribute( $name );
                    if ( ! is_string( $value ) ) { continue; }
                    $clean = preg_replace_callback( '/NOVAOWNED_([a-z0-9]+)_START(.*?)NOVAOWNED_\1_END/is', static function ( $match ) {
                        return isset( self::$tokens[strtolower( $match[1] )] ) ? $match[2] : $match[0];
                    }, $value );
                    $clean = preg_replace_callback( '/#nova-owned-([a-z0-9]+)/i', static function ( $match ) {
                        return isset( self::$tokens[strtolower( $match[1] )] ) ? '' : $match[0];
                    }, $clean );
                    $clean = self::strip_tokens( $clean );
                    if ( $clean !== $value ) { $owned[$name] = $clean; $document->set_attribute( $name, $clean ); }
                }
                if ( $owned ) {
                    $id = wp_generate_uuid4();
                    self::$attributes[$id] = $owned;
                    $document->set_attribute( 'data-nova-owned-attrs', $id );
                }
            }
        }
        return strtr( $document->get_updated_html(), $replacements );
    }

    public static function finish_builder( $html ) {
        self::cleanup( null );
        return self::finish( $html );
    }

    private static function strip_tokens( string $text ): string {
        return preg_replace_callback( '/NOVAOWNED_([a-z0-9]+)_(?:START|END)/i', static function ( $match ) {
            return isset( self::$tokens[strtolower( $match[1] )] ) ? '' : $match[0];
        }, $text );
    }

    public static function restore_attributes( $html ) {
        if ( ! is_string( $html ) || ! self::$attributes ) { return $html; }
        $document = new WP_HTML_Tag_Processor( $html );
        while ( $document->next_tag() ) {
            $id = $document->get_attribute( 'data-nova-owned-attrs' );
            if ( ! is_string( $id ) ) { continue; }
            foreach ( self::$attributes[$id] ?? [] as $name => $value ) { $document->set_attribute( $name, $value ); }
            if ( isset( self::$attributes[$id] ) ) { $document->remove_attribute( 'data-nova-owned-attrs' ); }
        }
        return $document->get_updated_html();
    }
}
