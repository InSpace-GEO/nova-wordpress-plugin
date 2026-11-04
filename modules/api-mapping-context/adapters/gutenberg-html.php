<?php
/** Field-level bindings for newly captured Gutenberg Custom HTML. The source is never reserialized as a DOM. */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/gutenberg-schema.php';

final class Nova_Mapping_Gutenberg_HTML {
    public const SLOTS = 'nova_html_slots';

    public static function capture( array $blocks ): array {
        foreach ( $blocks as &$block ) {
            if ( ! empty( $block['innerBlocks'] ) ) { $block['innerBlocks'] = self::capture( $block['innerBlocks'] ); }
            if ( 'core/html' !== ( $block['blockName'] ?? '' ) ) { continue; }
            $plan = self::plan( $block['innerHTML'] );
            $block[self::SLOTS] = [];
            foreach ( $plan['fields'] as $key => $field ) {
                // Null means no value has been mapped into this revision, even if a sample exists.
                $block[self::SLOTS][$key] = null;
            }
        }
        return $blocks;
    }

    /** Compare the actual WordPress source for both legacy and newly captured recipes. */
    public static function source_blocks( array $current ): array {
        foreach ( $current as &$block ) {
            unset( $block[self::SLOTS] );
            if ( ! empty( $block['innerBlocks'] ) ) {
                $block['innerBlocks'] = self::source_blocks( $block['innerBlocks'] );
            }
        }
        return $current;
    }

    public static function fields( array $block, string $path, bool $protected ): array {
        $fields = [];
        foreach ( self::plan( $block['innerHTML'] )['fields'] as $key => $field ) {
            if ( ! array_key_exists( $key, $block[self::SLOTS] ) ) { continue; }
            $fields[] = $field + [
                'target' => $path . '/' . self::SLOTS . '/' . $key,
                'writable' => ! $protected, 'protected' => $protected, 'manual_purpose' => true,
            ];
        }
        return $fields;
    }

    /** Preview markers are derived from the captured block, never persisted into its blueprint. */
    public static function preview_locator( array $blueprint, array $field ): ?array {
        if ( ! preg_match( '~^(/document/[0-9]+(?:/innerBlocks/[0-9]+)*)/(?:innerHTML|nova_html_slots/([a-zA-Z0-9_]+))$~D', $field['target'], $m ) ) { return null; }
        $block = Nova_Bridge_Suite_Recipe_Engine::get( $blueprint, $m[1] );
        if ( 'core/html' !== ( $block['blockName'] ?? '' ) ) { return null; }
        if ( 'schema_value' === $field['kind'] ) {
            return [ 'strategy' => 'unbound', 'reason' => 'Structured data has no visible element on the page.' ];
        }
        return [ 'strategy' => 'custom_html', 'marker' => self::marker( $m[1], $block['innerHTML'], $m[2] ?? 'block' ), 'precision' => isset( $m[2] ) ? 'field' : 'region' ];
    }

    private static function marker( string $path, string $html, string $key ): string {
        return substr( hash( 'sha256', $path . ':' . hash( 'sha256', $html ) . ':' . $key ), 0, 32 );
    }

    /** Called only on the authorized preview copy, before WordPress renders its native blocks. */
    public static function preview_blocks( array $blocks, string $parent = '/document' ): array {
        foreach ( $blocks as $index => &$block ) {
            $path = $parent . '/' . $index;
            if ( ! empty( $block['innerBlocks'] ) ) { $block['innerBlocks'] = self::preview_blocks( $block['innerBlocks'], $path . '/innerBlocks' ); }
            if ( 'core/html' !== ( $block['blockName'] ?? '' ) ) { continue; }
            $html = $block['innerHTML']; $out = ''; $end = 0;
            try { $spans = self::plan( $html )['spans']; }
            catch ( InvalidArgumentException $ignored ) { continue; }
            foreach ( $spans as $start => $span ) {
                if ( 'schema' === $span['kind'] ) { continue; }
                $raw = substr( $html, $start, $span['length'] );
                if ( 'text' === $span['kind'] ) {
                    $raw = '<!--nova-preview:' . self::marker( $path, $html, $span['key'] ) . '-->' . $raw . '<!--/nova-preview-->';
                } else {
                    $keys = [];
                    foreach ( $span['attributes'] as $key ) { $keys[] = self::marker( $path, $html, $key ); }
                    $tag = new WP_HTML_Tag_Processor( $raw ); $tag->next_tag();
                    $tag->set_attribute( 'data-nova-preview-fields', implode( ' ', $keys ) );
                    $raw = $tag->get_updated_html();
                }
                $out .= substr( $html, $end, $start - $end ) . $raw;
                $end = $start + $span['length'];
            }
            $id = self::marker( $path, $html, 'block' );
            $block['innerHTML'] = '<!--nova-preview-block:' . $id . '-->' . $out . substr( $html, $end ) . '<!--/nova-preview-block-->';
            $block['innerContent'] = [ $block['innerHTML'] ];
        }
        return $blocks;
    }

    private static function plan( string $html ): array {
        static $cache = [];
        $hash = hash( 'sha256', $html );
        if ( isset( $cache[$hash] ) ) { return $cache[$hash]; }
        if ( ! class_exists( 'WP_HTML_Processor' ) ) { throw new InvalidArgumentException( 'Custom HTML requires the WordPress HTML API.' ); }
        require_once __DIR__ . '/gutenberg-html-cursor.php';
        $cursor = Nova_Mapping_HTML_Cursor::create_fragment( $html );
        $fields = []; $spans = []; $ordinal = 0;
        while ( $cursor->next_token() ) {
            ++$ordinal;
            if ( $cursor->is_tag_closer() || 'html' !== $cursor->get_namespace() ) { continue; }
            $tag = $cursor->get_tag();
            $trail = $cursor->get_breadcrumbs();
            if ( 'SCRIPT' === $tag && 'application/ld+json' === strtolower( trim( (string) $cursor->get_attribute( 'type' ) ) ) ) {
                [ $start, $length ] = $cursor->source_span();
                $doc = Nova_Mapping_Gutenberg_Schema::document( $cursor->get_modifiable_text() );
                $prefix = 's' . $ordinal . '_';
                $spans[$start] = [ 'kind' => 'schema', 'length' => $length, 'document' => $doc, 'prefix' => $prefix ];
                Nova_Mapping_Gutenberg_Schema::project( $doc, '', 'Schema', static function ( $id, $label, $value ) use ( &$fields, $prefix ) {
                    $fields[$prefix . $id] = [
                        'kind' => 'schema_value', 'label' => $label, 'format' => 'text',
                        'value' => is_string( $value ) ? $value : json_encode( $value ),
                    ];
                    return null;
                } );
                continue;
            }
            if ( array_intersect( $trail, [ 'SCRIPT', 'STYLE', 'FORM', 'TEMPLATE', 'NOSCRIPT', 'TEXTAREA', 'IFRAME', 'CODE', 'PRE', 'SELECT' ] ) ) { continue; }
            if ( '#text' === $cursor->get_token_type() ) {
                $text = trim( $cursor->get_modifiable_text() );
                if ( '' === $text || preg_match( '/\[\/?[a-zA-Z_][^\]]*\]/', $text ) ) { continue; }
                $kind = self::text_kind( $trail );
                if ( null === $kind ) { continue; }
                [ $start, $length ] = $cursor->source_span();
                $key = 't' . $ordinal;
                $fields[$key] = [ 'kind' => $kind, 'format' => 'text', 'value' => $text,
                    'label' => self::label( $kind, $text ) ];
                $spans[$start] = [ 'kind' => 'text', 'length' => $length, 'key' => $key ];
                continue;
            }
            if ( ! in_array( $tag, [ 'A', 'IMG' ], true ) ) { continue; }
            $attributes = 'A' === $tag ? [ 'href' => 'link_url' ] : [ 'src' => 'image_url', 'alt' => 'image_alt' ];
            foreach ( $attributes as $attribute => $kind ) {
                $value = $cursor->get_attribute( $attribute );
                if ( ! is_string( $value ) || ( 'alt' !== $attribute && ! self::valid_url( $value, $attribute ) ) ) { continue; }
                // A picture source or lazy-load script can override the img's src independently.
                if ( 'src' === $attribute && ( in_array( 'PICTURE', $trail, true ) || null !== $cursor->get_attribute( 'data-src' ) || null !== $cursor->get_attribute( 'data-srcset' ) ) ) { continue; }
                [ $start, $length ] = $cursor->source_span();
                $key = 'a' . $ordinal . '_' . $attribute;
                $fields[$key] = [ 'kind' => $kind, 'format' => 'alt' === $attribute ? 'text' : 'url', 'value' => $value,
                    'label' => self::label( $kind, $value ) ];
                $spans[$start]['kind'] = 'attribute';
                $spans[$start]['length'] = $length;
                $spans[$start]['attributes'][$attribute] = $key;
            }
        }
        if ( $cursor->get_last_error() || $cursor->paused_at_incomplete_token() ) {
            throw new InvalidArgumentException( 'WordPress could not fully parse a Custom HTML block. Review its markup before using this layout.' );
        }
        if ( count( $cache ) >= 64 ) { $cache = []; }
        return $cache[$hash] = [ 'fields' => $fields, 'spans' => $spans ];
    }

    private static function label( string $kind, string $value ): string {
        preg_match( '/^.{0,48}/us', $value, $excerpt );
        return ucfirst( str_replace( '_', ' ', $kind ) ) . ' · ' . ( $excerpt[0] ?? '' );
    }

    private static function text_kind( array $trail ): ?string {
        foreach ( [ 'A' => 'link_text', 'BUTTON' => 'button_text', 'SUMMARY' => 'question' ] as $tag => $kind ) {
            if ( in_array( $tag, $trail, true ) ) { return $kind; }
        }
        foreach ( array_reverse( $trail ) as $tag ) {
            if ( preg_match( '/^H[1-6]$/', $tag ) ) { return 'heading'; }
            if ( in_array( $tag, [ 'P', 'LI', 'FIGCAPTION', 'BLOCKQUOTE', 'DT', 'DD', 'DIV', 'SPAN', 'TD', 'TH', 'SECTION', 'ARTICLE' ], true ) ) {
                if ( 'SPAN' === $tag || 'DIV' === $tag ) { continue; }
                return in_array( 'DETAILS', $trail, true ) ? 'answer' : 'body';
            }
        }
        return array_intersect( $trail, [ 'DIV', 'SPAN' ] ) ? 'text' : null;
    }

    private static function valid_url( string $url, string $attribute ): bool {
        if ( '' === $url || preg_match( '~[\x00-\x20\x7f<>\[\]\\\\]~', $url ) || str_starts_with( $url, '//' ) ) { return false; }
        $scheme = parse_url( $url, PHP_URL_SCHEME );
        return null === $scheme || ( is_string( $scheme ) && in_array( strtolower( $scheme ), 'src' === $attribute ? [ 'https', 'http' ] : [ 'https', 'http', 'mailto', 'tel' ], true ) );
    }

    public static function render_blocks( array $blocks ): array {
        foreach ( $blocks as &$block ) {
            if ( ! empty( $block['innerBlocks'] ) ) { $block['innerBlocks'] = self::render_blocks( $block['innerBlocks'] ); }
            if ( ! isset( $block[self::SLOTS] ) || 'core/html' !== $block['blockName'] ) { continue; }
            $source = $block['innerHTML']; $values = $block[self::SLOTS];
            $plan = self::plan( $source ); $out = ''; $end = 0;
            foreach ( $plan['spans'] as $start => $span ) {
                $original = substr( $source, $start, $span['length'] );
                $out .= substr( $source, $end, $start - $end ) . self::render_span( $original, $span, $values, $plan['fields'] );
                $end = $start + $span['length'];
            }
            $block['innerHTML'] = $out . substr( $source, $end );
            $block['innerContent'] = [ $block['innerHTML'] ];
        }
        return $blocks;
    }

    private static function render_span( string $raw, array $span, array $values, array $fields ): string {
        if ( 'schema' === $span['kind'] ) {
            $doc = Nova_Mapping_Gutenberg_Schema::project( $span['document'], '', 'Schema', static function ( $id ) use ( $values, $span ) {
                return $values[$span['prefix'] . $id] ?? null;
            } );
            if ( null === $doc ) { return ''; }
            $token = new WP_HTML_Tag_Processor( $raw );
            $json = wp_json_encode( $doc, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE );
            if ( ! is_string( $json ) || ! $token->next_tag() || ! $token->set_modifiable_text( $json ) ) { throw new InvalidArgumentException( 'Cannot write the mapped JSON-LD.' ); }
            return $token->get_updated_html();
        }
        if ( 'text' === $span['kind'] ) {
            $key = $span['key']; $value = $values[$key] ?? null;
            if ( null === $value || $value === $fields[$key]['value'] ) { return $raw; }
            preg_match( '/^(\s*).*?(\s*)$/sD', $raw, $space );
            return $space[1] . str_replace( '[', '&#91;', htmlspecialchars( $value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' ) ) . $space[2];
        }
        $tag = new WP_HTML_Tag_Processor( $raw );
        $tag->next_tag();
        foreach ( $span['attributes'] as $attribute => $key ) {
            $value = $values[$key] ?? null;
            if ( null === $value || $value === $fields[$key]['value'] ) { continue; }
            if ( 'alt' !== $attribute && '' !== $value && ! self::valid_url( $value, $attribute ) ) { throw new InvalidArgumentException( 'Supply a safe public URL for the Custom HTML field.' ); }
            if ( preg_match( '/\[\/?[a-zA-Z_][^\]]*\]/', $value ) ) { throw new InvalidArgumentException( 'Shortcodes cannot be introduced in a mapped HTML attribute.' ); }
            if ( ! $tag->set_attribute( $attribute, $value ) ) { throw new InvalidArgumentException( 'WordPress rejected a mapped HTML attribute.' ); }
            if ( 'src' === $attribute ) {
                $tag->remove_attribute( 'srcset' );
                $tag->remove_attribute( 'sizes' );
            }
        }
        return $tag->get_updated_html();
    }
}
