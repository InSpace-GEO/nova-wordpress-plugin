<?php
/** Frozen Advanced Layout Builder controls. Read Enfold's installed registry only during capture. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-nova-bridge-suite-flatsome-document.php';

final class Nova_Bridge_Suite_Enfold_Document {
    public const PRESENTATION = [ 'layout', 'sidebar', 'footer', 'footer_behavior', 'header_title_bar', 'header_transparency' ];

    private static function controls( string $tag, $builder, ?string $parent ): array {
        $object = $builder->get_shortcode_class( $tag );
        $elements = $object->elements ?? [];
        if ( ! $object && $parent ) {
            $candidate = $builder->get_shortcode_class( $parent );
            if ( $candidate && in_array( $tag, (array) ( $candidate->config['shortcode_nested'] ?? [] ), true ) && is_callable( [ $candidate, 'get_modal_group_subelements' ] ) ) {
                $object = $candidate; $elements = $candidate->get_modal_group_subelements();
            }
        }
        $definition = [ 'name' => $object ? (string) ( $object->config['name'] ?? $tag ) : $tag, 'native' => (bool) $object, 'protected' => ! $object, 'fields' => [], 'body' => '' ];
        // These native elements resolve other posts/templates or execute user-provided code.
        if ( preg_match( '/^av_(?:postcontent|custom_layout|codeblock|code|contact|blog|postslider|masonry_entries|portfolio|magazine|upcoming_events|product|layerslider|revolutionslider|html|dynamic_field|dynamic_content)(?:_|$)/i', $tag ) ) { $definition['protected'] = true; }
        if ( $definition['protected'] ) { return $definition; }
        foreach ( $elements as $control ) {
            if ( ! is_array( $control ) ) { continue; }
            $name = (string) ( $control['id'] ?? '' ); $type = $control['type'] ?? '';
            if ( ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_-]*$/D', $name ) || ! is_string( $type ) ) { continue; }
            $label = strip_tags( (string) ( $control['name'] ?? $name ) );
            if ( 'content' === $name && in_array( $type, [ 'tiny_mce', 'textarea' ], true ) ) { $definition['body'] = 'tiny_mce' === $type ? 'html' : 'text'; continue; }
            if ( 'image' === $type && in_array( 'attachment', (array) ( $control['locked'] ?? [] ), true ) && 'src' === $name ) {
                $definition['fields'][$name] = [ 'format' => 'url', 'kind' => 'image_url', 'label' => $label ]; continue;
            }
            if ( 'linkpicker' === $type ) {
                $definition['fields'][$name] = [ 'format' => 'url', 'kind' => 'link_url', 'label' => $label ]; continue;
            }
            if ( in_array( $type, [ 'input', 'textarea', 'tiny_mce' ], true ) && preg_match( '/^(?:heading|subheading|title|label|text|caption|description|alt|alt_attr|title_attr|button_text|button_label|link_text|author|name)$/D', $name ) ) {
                $definition['fields'][$name] = [ 'format' => 'tiny_mce' === $type ? 'html' : 'text', 'kind' => $name, 'label' => $label ];
            }
        }
        return $definition;
    }

    public static function capture( string $source, $builder ): array {
        // The lossless shortcode tokenizer does not load Flatsome or call shortcode callbacks.
        $nodes = Nova_Bridge_Suite_Flatsome_Document::parse( $source );
        $walk = static function ( array &$items, ?string $parent ) use ( &$walk, $builder ) {
            foreach ( $items as &$node ) {
                if ( 'shortcode' !== $node['kind'] ) { continue; }
                $node['definition'] = self::controls( $node['tag'], $builder, $parent );
                // A selected global element template overrides local settings at render time.
                if ( ! empty( $node['attributes']['element_template'] ) || ! empty( $node['attributes']['one_element_template'] ) ) { $node['definition']['protected'] = true; }
                $walk( $node['children'], $node['tag'] );
                if ( '' !== $node['definition']['body'] && '' !== $node['raw_close'] && ! array_filter( $node['children'], static fn( $child ) => 'shortcode' === $child['kind'] ) ) {
                    $node['content'] = Nova_Bridge_Suite_Flatsome_Document::serialize( $node['children'] ); $node['children'] = [];
                }
            }
            unset( $node );
        };
        $walk( $nodes, null );
        if ( self::source( $nodes ) !== $source ) { throw new RuntimeException( 'The native Avia source cannot be captured without changing its unselected markup.' ); }
        return $nodes;
    }

    public static function validate( array $blueprint ): void {
        if ( array_diff( array_keys( $blueprint ), [ 'document', 'template', 'meta' ] ) || ! Nova_Bridge_Suite_Recipe_Engine::list_array( $blueprint['document'] ?? null ) || ! is_string( $blueprint['template'] ?? '' ) || ! is_array( $blueprint['meta'] ?? [] ) ) { throw new RuntimeException( 'Use a complete frozen Avia document and its presentation.' ); }
        foreach ( $blueprint['meta'] ?? [] as $key => $value ) {
            if ( ! in_array( $key, self::PRESENTATION, true ) || ! is_array( $value ) || ! is_bool( $value['present'] ?? null ) || ! is_string( $value['value'] ?? null ) || strlen( $value['value'] ) > 8192 ) { throw new RuntimeException( 'The frozen Enfold page presentation is invalid.' ); }
        }
        if ( strlen( Nova_Bridge_Suite_Recipe_Engine::json( $blueprint ) ) > 2097152 ) { throw new RuntimeException( 'The complete Avia layout exceeds two megabytes.' ); }
        $count = 0;
        $walk = static function ( array $nodes, int $depth ) use ( &$walk, &$count ) {
            if ( $depth > 32 || ! Nova_Bridge_Suite_Recipe_Engine::list_array( $nodes ) ) { throw new RuntimeException( 'The frozen Avia child collection is invalid or too deep.' ); }
            foreach ( $nodes as $node ) {
                if ( ++$count > 4000 || ! is_array( $node ) ) { throw new RuntimeException( 'The Avia document contains too many native elements.' ); }
                $kind = $node['kind'] ?? '';
                if ( 'shortcode' === $kind ) {
                    $def = $node['definition'] ?? null;
                    if ( ! is_string( $node['tag'] ?? null ) || ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_-]*$/D', $node['tag'] ) || ! is_array( $node['attributes'] ?? null ) || ! is_array( $node['original_attributes'] ?? null ) || ! is_string( $node['raw_open'] ?? null ) || ! is_string( $node['raw_close'] ?? null ) || ! is_array( $node['children'] ?? null ) || ! is_array( $def ) || ! is_string( $def['name'] ?? null ) || ! is_bool( $def['native'] ?? null ) || ! is_bool( $def['protected'] ?? null ) || ! is_array( $def['fields'] ?? null ) || ! in_array( $def['body'] ?? null, [ '', 'text', 'html' ], true ) || ( isset( $node['content'] ) && ( ! is_string( $node['content'] ) || $node['children'] ) ) ) { throw new RuntimeException( 'The frozen Avia shortcode or its declared controls are incomplete.' ); }
                    foreach ( $node['attributes'] as $key => $value ) {
                        if ( ! is_string( $value ) && ! is_numeric( $value ) ) { throw new RuntimeException( 'Avia attributes must be native scalar strings.' ); }
                        if ( is_string( $key ) && preg_match( '/(?:password|secret|token|credential|api[_-]?key)/i', $key ) && '' !== (string) $value ) { throw new RuntimeException( 'An Avia element contains a credential-like setting and cannot be exported.' ); }
                    }
                    foreach ( $def['fields'] as $key => $field ) { if ( ! is_string( $key ) || ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_-]*$/D', $key ) || ! is_array( $field ) || ! in_array( $field['format'] ?? '', [ 'text', 'html', 'url' ], true ) || ! is_string( $field['label'] ?? null ) || ! is_string( $field['kind'] ?? null ) ) { throw new RuntimeException( 'The frozen Avia control is invalid.' ); } }
                    $walk( $node['children'], $depth + 1 );
                } elseif ( ! in_array( $kind, [ 'raw', 'html' ], true ) || ! is_string( $node['content'] ?? null ) || ( 'html' === $kind && ( ! is_string( $node['tag'] ?? null ) || ! is_string( $node['raw_open'] ?? null ) || ! is_string( $node['raw_close'] ?? null ) ) ) ) { throw new RuntimeException( 'The frozen Avia fragment is invalid.' ); }
            }
        };
        $walk( $blueprint['document'], 0 );
    }

    /** Give each retained target its own stable element IDs; retries must not generate new CSS classes. */
    public static function source( array $nodes, int $target = 0 ): string {
        if ( $target > 0 ) {
            $walk = static function ( array &$items, string $path ) use ( &$walk, $target ) {
                foreach ( $items as $i => &$node ) {
                    if ( 'shortcode' !== $node['kind'] ) { continue; }
                    $p = $path . '/' . $i;
                    if ( ! empty( $node['definition']['native'] ) ) { $node['attributes']['av_uid'] = 'av-' . substr( hash( 'sha256', $target . ':' . $p ), 0, 16 ); }
                    $walk( $node['children'], $p );
                }
                unset( $node );
            };
            $walk( $nodes, '' );
        }
        return Nova_Bridge_Suite_Flatsome_Document::serialize( $nodes );
    }

    public static function inventory( array $native ): array {
        $result = [ 'fields' => [], 'containers' => [] ];
        $walk = static function ( array $nodes, string $pointer, bool $protected, string $context ) use ( &$walk, &$result ) {
            $entries = [];
            foreach ( $nodes as $i => $node ) {
                $p = $pointer . '/' . $i; $tag = $node['tag'] ?? 'text'; $label = $node['definition']['name'] ?? $tag;
                $blocked = $protected || ! empty( $node['definition']['protected'] ); $ctx = trim( $context . ' > ' . $label . ' ' . ( $i + 1 ), ' >' );
                $entries[] = [ 'index' => $i, 'label' => $label . ' ' . ( $i + 1 ), 'native_type' => $tag ];
                $add = static function ( string $suffix, string $format, string $kind, string $label, string $value, bool $locked, array $extra = [] ) use ( &$result, $p, $tag, $ctx ) {
                    $result['fields'][] = [ 'target' => $p . $suffix, 'label' => $label, 'format' => $format, 'kind' => $kind, 'value' => $value, 'sample' => $value, 'writable' => ! $locked, 'protected' => $locked, 'reason' => $locked ? 'This native dynamic or interactive value is retained.' : '', 'context' => $ctx, 'node_target' => $p, 'element_type' => $tag, 'native_node_id' => '', 'control_path' => Nova_Bridge_Suite_Recipe_Engine::parts( $suffix ), 'locator' => [ 'strategy' => 'none', 'native_node_id' => '', 'anchor' => '', 'precision' => 'element', 'status' => 'unavailable', 'reason' => 'Use the named native Avia field in the list.' ] ] + $extra;
                };
                if ( 'shortcode' === $node['kind'] ) {
                    foreach ( $node['definition']['fields'] as $key => $field ) {
                        $attrs = $node['attributes']; $value = (string) ( $attrs[$key] ?? '' ); $extra = [];
                        if ( 'image_url' === $field['kind'] ) {
                            if ( '' === $value && ! empty( $attrs['attachment'] ) && function_exists( 'wp_get_attachment_url' ) ) { $value = (string) wp_get_attachment_url( (int) $attrs['attachment'] ); }
                            $extra['native_media'] = [ 'kind' => 'enfold', 'id_target' => $p . '/attributes/attachment', 'url_target' => $p . '/attributes/' . $key ];
                        } elseif ( 'link_url' === $field['kind'] && str_starts_with( $value, 'manually,' ) ) { $value = substr( $value, 9 ); }
                        $add( '/attributes/' . $key, $field['format'], $field['kind'], $label . ' · ' . $field['label'], html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $blocked || ! empty( $attrs[$key . '_dynamic'] ), $extra );
                    }
                    if ( isset( $node['content'] ) && '' !== $node['definition']['body'] ) {
                        $value = $node['content']; $format = $node['definition']['body']; $heading = self::heading( $value );
                        $add( '/content', $heading ? 'text' : $format, $heading ? 'heading' : 'body', $label . ' · Content', $heading ? html_entity_decode( strip_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : $value, $blocked || ! empty( $node['attributes']['content_dynamic'] ) || self::unsafe_body( $value ) );
                    }
                    $walk( $node['children'], $p . '/children', $blocked, $ctx );
                } elseif ( 'html' === $node['kind'] && '' !== trim( strip_tags( $node['content'] ) ) ) {
                    $heading = 1 === preg_match( '/^h[1-6]$/', $tag ); $value = $node['content'];
                    $add( '/content', $heading ? 'text' : 'html', $heading ? 'heading' : 'body', $heading ? 'Heading' : 'Body', $heading ? html_entity_decode( strip_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : $value, $blocked || self::unsafe_body( $value ) );
                }
            }
            if ( $entries && ! $protected ) { $result['containers'][] = [ 'target' => $pointer, 'label' => $context ?: 'Avia layout', 'items' => $entries ]; }
        };
        $walk( $native['document'] ?? $native, isset( $native['document'] ) ? '/document' : '', false, '' );
        return $result;
    }
    public static function heading( string $value ): bool { return 1 === preg_match( '~^\s*<h([1-6])\b[^>]*>[\s\S]*?</h\1>\s*$~i', $value ) && 1 === preg_match_all( '~<h[1-6]\b~i', $value ); }
    /** Keep existing per-block design without wrapping a replacement list/heading inside an old paragraph. */
    public static function body_styles( string $original, string $value ): string {
        preg_match_all( '~<(p|h[1-6]|ul|ol|table|blockquote|pre|figure)\b([^>]*)>~i', $original, $matches, PREG_SET_ORDER ); $styles = [];
        foreach ( $matches as $match ) { $styles[strtolower( $match[1] )][] = $match[2]; } $indices = [];
        return preg_replace_callback( '~<(p|h[1-6]|ul|ol|table|blockquote|pre|figure)\b[^>]*>~i', static function ( $match ) use ( $styles, &$indices ) { $tag = strtolower( $match[1] ); $index = $indices[$tag] ?? 0; $indices[$tag] = $index + 1; return ! empty( $styles[$tag][$index] ) ? '<' . $match[1] . $styles[$tag][$index] . '>' : $match[0]; }, $value );
    }
    private static function unsafe_body( string $value ): bool { return 1 === preg_match( '~<(?:script|style|iframe|form)\b|\[(?:[a-zA-Z_]|\[)~i', $value ); }
}
