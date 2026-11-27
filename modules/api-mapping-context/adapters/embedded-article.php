<?php
/** Independent editorial fields inside a single HTML article with a leading image. */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/gutenberg-html.php';

final class Nova_Mapping_Embedded_Article {
    private const FORMATS = [ 'image_url' => 'url', 'image_alt' => 'text', 'link_url' => 'url', 'article_body' => 'html' ];
    private const LABELS = [ 'image_url' => 'Lead image URL', 'image_alt' => 'Lead image alternative text', 'link_url' => 'Lead image link', 'article_body' => 'Article body below the image' ];

    /** Provider settings are extensible. Never interpret a stored setting as pending compiler state. */
    public static function pending_key(): string {
        static $key;
        return $key ??= 'nova_article_' . bin2hex( random_bytes( 16 ) );
    }

    /** Accept an explicit wrapper, one opening image region and a contiguous editorial body. */
    private static function plan( string $html ): ?array {
        if ( ! class_exists( 'WP_HTML_Processor' ) || strlen( $html ) > 2097152 || false === stripos( $html, '<img' ) ) { return null; }
        require_once __DIR__ . '/gutenberg-html-tag-cursor.php';
        $tags = new Nova_Mapping_Otter_HTML_Tags( $html );
        $stack = []; $root = ''; $image = null; $link = null; $start = null; $end = null;
        while ( $tags->next_token() ) {
            $tag = $tags->get_tag();
            if ( null === $tag ) {
                if ( null === $start || null !== $end ) {
                    if ( '#text' !== $tags->get_token_type() || ! preg_match( '/^[\s\x{a0}]*$/u', $tags->get_modifiable_text() ) ) { return null; }
                }
                continue;
            }
            if ( null !== $end ) { return null; }
            if ( $tags->is_tag_closer() ) {
                if ( array_pop( $stack ) !== $tag ) { return null; }
                if ( ! $stack ) { [ $end ] = $tags->span(); }
                continue;
            }
            if ( ! $stack ) {
                if ( ! in_array( $tag, [ 'DIV', 'ARTICLE', 'SECTION' ], true ) ) { return null; }
                $root = $tag;
            } elseif ( null === $start ) {
                if ( $image && 1 === count( $stack ) && in_array( $tag, [ 'P', 'H2', 'H3', 'UL', 'OL', 'BLOCKQUOTE' ], true ) ) {
                    [ $start ] = $tags->span();
                    $body_tag = $tag;
                } elseif ( 'IMG' === $tag && ! $image ) {
                    foreach ( [ 'srcset', 'data-src', 'data-srcset', 'data-lazy-src', 'data-lazy-srcset' ] as $attr ) { if ( null !== $tags->get_attribute( $attr ) ) { return null; } }
                    $src = $tags->get_attribute( 'src' );
                    if ( ! is_string( $src ) || ! Nova_Mapping_Gutenberg_HTML::valid_url( $src, 'src' ) ) { return null; }
                    $image = [ 'span' => $tags->span(), 'src' => $src, 'alt' => (string) $tags->get_attribute( 'alt' ), 'depth' => count( $stack ) ];
                    if ( $link && 'A' !== end( $stack ) ) { return null; }
                } elseif ( 'A' === $tag && ! $image && ! $link && is_string( $tags->get_attribute( 'href' ) ) ) {
                    $link = [ 'span' => $tags->span(), 'href' => $tags->get_attribute( 'href' ) ];
                } elseif ( ! ( ( ! $image && ! $link && in_array( $tag, [ 'DIV', 'FIGURE' ], true ) && count( $stack ) === 1 ) || ( $image && 1 === count( $stack ) && 'BR' === $tag ) ) ) { return null; }
            }
            // The native tokenizer consumes raw-text elements and their closing tag in one token.
            if ( ! WP_HTML_Processor::is_void( $tag ) && ! in_array( $tag, [ 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE', 'IFRAME', 'XMP', 'NOEMBED', 'NOFRAMES' ], true ) ) { $stack[] = $tag; }
        }
        if ( ! $image || null === $start || null === $end || $stack || $tags->paused_at_incomplete_token() ) { return null; }
        return compact( 'image', 'link', 'root', 'start', 'end', 'body_tag' );
    }

    public static function fields( string $html, string $owner, string $widget_id = '' ): array {
        $plan = self::plan( $html );
        if ( ! $plan ) { return []; }
        $values = [ 'image_url' => $plan['image']['src'], 'image_alt' => $plan['image']['alt'], 'article_body' => substr( $html, $plan['start'], $plan['end'] - $plan['start'] ) ];
        if ( $plan['link'] ) { $values['link_url'] = $plan['link']['href']; }
        $fields = [];
        foreach ( $values as $key => $value ) {
            $fields[] = [ 'target' => $owner . '/article_fields/' . $key, 'label' => self::LABELS[$key], 'kind' => 'article_body' === $key ? 'body' : $key, 'format' => self::FORMATS[$key], 'writable' => true, 'value' => $value,
                'locator' => [ 'strategy' => 'embedded_article', 'node_id' => $widget_id ?: 'post', 'media' => $plan['image']['src'], 'image_depth' => $plan['image']['depth'], 'root_tag' => $plan['root'], 'body_tag' => $plan['body_tag'], 'field' => $key, 'precision' => 'article_body' === $key ? 'region' : 'field' ] ];
        }
        return $fields;
    }

    public static function owner( string $target ): ?string {
        return preg_match( '~^(.+/(?:body|settings/editor))/article_fields/(image_url|image_alt|link_url|article_body)$~D', $target, $match ) ? $match[1] : null;
    }

    public static function validate( array $native, array $binding ) {
        $owner = self::owner( $binding['target'] );
        $html = Nova_Bridge_Suite_Recipe_Engine::get( $native, $owner );
        $key = basename( $binding['target'] );
        $plan = is_string( $html ) ? self::plan( $html ) : null;
        return $plan && self::FORMATS[$key] === $binding['format'] && ( 'link_url' !== $key || $plan['link'] ) ? true : new WP_Error( 'nova_article_field', 'The selected article field no longer matches its captured HTML or format.' );
    }

    public static function overlap( array $bindings ) {
        $targets = array_column( $bindings, null, 'target' );
        foreach ( array_keys( $targets ) as $target ) {
            $owner = self::owner( $target );
            if ( $owner && isset( $targets[$owner] ) ) { return new WP_Error( 'nova_article_overlap', 'Remove the whole-content mapping before mapping fields inside the same article.' ); }
        }
        return true;
    }

    /** Accumulate bindings on the containing row so binding order cannot change source offsets. */
    public static function bind( array $native, array $binding, string $value ): array {
        $parent = dirname( self::owner( $binding['target'] ) );
        $row = Nova_Bridge_Suite_Recipe_Engine::get( $native, $parent );
        $row[self::pending_key()][basename( $binding['target'] )] = $value;
        Nova_Bridge_Suite_Recipe_Engine::put( $native, $parent, $row );
        return $native;
    }

    public static function render( string $html, array $values ): string {
        $plan = self::plan( $html );
        if ( ! $plan ) { throw new InvalidArgumentException( 'Cannot locate the captured article fields.' ); }
        $changes = [];
        foreach ( [ 'image' => [ 'src' => 'image_url', 'alt' => 'image_alt' ], 'link' => [ 'href' => 'link_url' ] ] as $name => $attributes ) {
            if ( ! $plan[$name] || ! array_intersect( $attributes, array_keys( $values ) ) ) { continue; }
            [ $offset, $length ] = $plan[$name]['span'];
            $tag = new WP_HTML_Tag_Processor( substr( $html, $offset, $length ) ); $tag->next_tag();
            foreach ( $attributes as $attr => $key ) {
                if ( ! array_key_exists( $key, $values ) ) { continue; }
                $value = $values[$key];
                if ( 'alt' !== $attr && '' !== $value && ! Nova_Mapping_Gutenberg_HTML::valid_url( $value, $attr ) ) { throw new InvalidArgumentException( 'The article image or link has an unsupported URL.' ); }
                if ( $tag->get_attribute( $attr ) !== $value ) { $tag->set_attribute( $attr, $value ); }
            }
            $changes[$offset] = [ $length, $tag->get_updated_html() ];
        }
        if ( array_key_exists( 'article_body', $values ) ) { $changes[$plan['start']] = [ $plan['end'] - $plan['start'], wp_kses_post( $values['article_body'] ) ]; }
        krsort( $changes, SORT_NUMERIC );
        foreach ( $changes as $offset => [ $length, $replacement ] ) { $html = substr_replace( $html, $replacement, $offset, $length ); }
        return $html;
    }
}
