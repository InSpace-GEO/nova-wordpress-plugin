<?php
/**
 * WPBakery element fields, read from the element definitions WPBakery itself registers (vc_map).
 *
 * Themes and add-ons register their own elements through the same API, so their image, text and
 * link parameters are recognized without per-theme code. A conservative fallback covers elements
 * whose definition is unavailable (for example when WPBakery is inactive during a review).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Mapping_WPBakery_Elements {
    /** Parameter groups that hold presentation rather than editorial content. */
    private const DESIGN_GROUP = '/design|style|styling|animation|responsive|advanced|css|typograph|colou?r|effect|extra|hover|spacing/i';
    private const EDITORIAL_NAME = '/^(?:text|title|sub_?title|heading|sub_?heading|button_?text|btn_text|link_text|label|caption|alt|alt_text|description|desc|name|quote|author|author_name|job|question|answer|tagline|highlight_text|price|cta_text|tab_title|text_[a-z0-9_]+|title_[a-z0-9_]+|heading_[a-z0-9_]+)$/';
    private const PRESENTATION_NAME = '/(?:colou?r|size|width|height|margin|padding|font|class|^id$|_id$|^el_|css|animation|delay|speed|offset|opacity|radius|icon|align|style|border|shadow|z_index|duration|spacing|columns?|gap|ratio|position_|_position$|target|rel$)/';
    private const IMAGE_NAME = '/^(?:image|images?_?url|image_id|img|img_id|img_url|bg_image|background_image|photo|picture|thumbnail|image_src|media|logo|icon_image)$/';
    private const NEVER_BODY = [ 'vc_raw_html', 'vc_raw_js', 'vc_wp_text' ];
    private static $definitions = [];

    /** The WPBakery definition for one element, or null when WPBakery cannot describe it. */
    public static function definition( string $tag ): ?array {
        if ( array_key_exists( $tag, self::$definitions ) ) { return self::$definitions[ $tag ]; }
        $definition = null;
        try {
            if ( class_exists( 'WPBMap' ) && is_callable( [ 'WPBMap', 'getShortCode' ] ) ) {
                $found = WPBMap::getShortCode( $tag );
                if ( is_array( $found ) && is_array( $found['params'] ?? null ) ) { $definition = $found; }
            }
        } catch ( Throwable $error ) { $definition = null; }
        return self::$definitions[ $tag ] = $definition;
    }

    public static function label( string $tag ): string {
        $definition = self::definition( $tag );
        $name = is_string( $definition['name'] ?? null ) ? trim( wp_strip_all_tags( $definition['name'] ) ) : '';
        return '' !== $name && strlen( $name ) <= 80 ? $name : '';
    }

    /**
     * Editorial controls for one shortcode node.
     * Each entry: suffix (pointer below the node), format, kind, label, and for images an attachment URL sample.
     */
    public static function fields( array $node ): array {
        $tag = (string) ( $node['tag'] ?? '' ); $attributes = is_array( $node['attributes'] ?? null ) ? $node['attributes'] : [];
        $definition = self::definition( $tag );
        return null === $definition ? self::fallback( $tag, $node, $attributes ) : self::described( $tag, $node, $attributes, $definition );
    }

    private static function described( string $tag, array $node, array $attributes, array $definition ): array {
        $fields = []; $content_seen = false;
        foreach ( $definition['params'] as $param ) {
            if ( ! is_array( $param ) || ! is_string( $param['param_name'] ?? null ) ) { continue; }
            $name = $param['param_name']; $type = (string) ( $param['type'] ?? '' );
            $heading = is_string( $param['heading'] ?? null ) ? trim( wp_strip_all_tags( $param['heading'] ) ) : '';
            $label = '' !== $heading && strlen( $heading ) <= 80 ? $heading : ucwords( str_replace( '_', ' ', $name ) );
            $group = is_string( $param['group'] ?? null ) ? $param['group'] : '';
            if ( '' !== $group && preg_match( self::DESIGN_GROUP, $group ) ) { continue; }
            if ( 'content' === $name ) {
                $content_seen = true;
                if ( in_array( $type, [ 'textarea_html', 'textarea' ], true ) && ! in_array( $tag, self::NEVER_BODY, true ) ) {
                    $body = self::body( $node, 'textarea_html' === $type ? 'html' : 'text', $label ); if ( $body ) { $fields[] = $body; }
                }
                continue;
            }
            if ( ! array_key_exists( $name, $attributes ) || ! is_string( $attributes[ $name ] ) ) { continue; }
            $value = $attributes[ $name ];
            if ( 'attach_image' === $type ) { $image = self::image( $name, $value, $label ); if ( $image ) { $fields[] = $image; } continue; }
            if ( 'vc_link' === $type ) { $fields[] = [ 'suffix' => '/attributes/' . $name, 'format' => 'url', 'kind' => 'link_url', 'label' => $label, 'packed_link' => true ]; continue; }
            if ( 'href' === $type ) { $fields[] = [ 'suffix' => '/attributes/' . $name, 'format' => 'url', 'kind' => 'link_url', 'label' => $label ]; continue; }
            if ( in_array( $type, [ 'textfield', 'textarea' ], true ) ) {
                $editorial = preg_match( self::EDITORIAL_NAME, $name ) || ( ! empty( $param['admin_label'] ) && ! preg_match( self::PRESENTATION_NAME, $name ) );
                if ( $editorial ) {
                    $fields[] = [ 'suffix' => '/attributes/' . $name, 'format' => 'text', 'kind' => self::kind( $tag, $name ), 'label' => $label ];
                }
            }
        }
        // Elements whose definition omits the content parameter still keep visible inner text.
        if ( ! $content_seen && empty( $definition['is_container'] ) && empty( $definition['as_parent'] ) && ! in_array( $tag, self::NEVER_BODY, true ) ) {
            $body = self::body( $node, 'html', 'Content' ); if ( $body ) { $fields[] = $body; }
        }
        return $fields;
    }

    private static function fallback( string $tag, array $node, array $attributes ): array {
        $fields = [];
        if ( ! in_array( $tag, self::NEVER_BODY, true ) && ( 'vc_column_text' === $tag || 'paired' === ( $node['syntax'] ?? '' ) ) ) {
            $body = self::body( $node, 'html', 'Content' ); if ( $body ) { $fields[] = $body; }
        }
        foreach ( [ 'text', 'title', 'button_text', 'link_text', 'alt', 'caption', 'heading', 'subtitle', 'description' ] as $key ) {
            if ( isset( $attributes[ $key ] ) && is_string( $attributes[ $key ] ) ) { $fields[] = [ 'suffix' => '/attributes/' . $key, 'format' => 'text', 'kind' => self::kind( $tag, $key ), 'label' => ucwords( str_replace( '_', ' ', $key ) ) ]; }
        }
        foreach ( [ 'url', 'href', 'link_url', 'button_url', 'image_link', 'custom_src', 'src', 'image_url' ] as $key ) {
            if ( ! isset( $attributes[ $key ] ) || ! is_string( $attributes[ $key ] ) ) { continue; }
            if ( '' !== $attributes[ $key ] && ! preg_match( '~^(?:https?://|/)~i', $attributes[ $key ] ) ) { continue; }
            $fields[] = [ 'suffix' => '/attributes/' . $key, 'format' => 'url', 'kind' => in_array( $key, [ 'custom_src', 'src', 'image_url' ], true ) ? 'image_url' : 'link_url', 'label' => ucwords( str_replace( '_', ' ', $key ) ) ];
        }
        if ( isset( $attributes['link'] ) && is_string( $attributes['link'] ) ) { nova_mapping_wpb_packed_link_url( $attributes['link'], $found ); }
        if ( ! empty( $found ) ) {
            $fields[] = [ 'suffix' => '/attributes/link', 'format' => 'url', 'kind' => 'link_url', 'label' => 'Link', 'packed_link' => true ];
        }
        foreach ( $attributes as $key => $value ) {
            if ( is_string( $key ) && is_string( $value ) && preg_match( self::IMAGE_NAME, $key ) && ! in_array( '/attributes/' . $key, array_column( $fields, 'suffix' ), true ) ) {
                $image = self::image( $key, $value, ucwords( str_replace( '_', ' ', $key ) ) ); if ( $image ) { $fields[] = $image; }
            }
        }
        return $fields;
    }

    /** Inner content of a leaf element; encoded raw HTML and nested shortcodes stay protected. */
    private static function body( array $node, string $format, string $label ): ?array {
        if ( ! empty( $node['children'] ) || 'paired' !== ( $node['syntax'] ?? '' ) || ! is_string( $node['text'] ?? null ) ) { return null; }
        $text = $node['text'];
        if ( '' === trim( wp_strip_all_tags( $text ) ) && false === stripos( $text, '<img' ) ) { return null; }
        if ( preg_match( '/\A[A-Za-z0-9+\/=%]{40,}\z/', trim( $text ) ) ) { return null; }
        $heading = 1 === preg_match( '/\A\s*<h([1-6])\b[^>]*>[\s\S]*<\/h\1>\s*\z/i', $text ) && 1 === preg_match_all( '/<h[1-6]\b/i', $text );
        // A single heading keeps its tag and attributes; the mapped value replaces only its text.
        return $heading ? [ 'suffix' => '/text', 'format' => 'text', 'allowed_formats' => [ 'text', 'html' ], 'kind' => 'heading', 'label' => $label ] : [ 'suffix' => '/text', 'format' => $format, 'kind' => 'body', 'label' => $label ];
    }

    /** Attachment-ID image parameters are exposed as their image URL and imported on delivery. */
    private static function image( string $name, string $value, string $label ): ?array {
        $value = trim( $value );
        if ( preg_match( '~^https?://~i', $value ) ) { return [ 'suffix' => '/attributes/' . $name, 'format' => 'url', 'kind' => 'image_url', 'label' => $label ]; }
        if ( ! ctype_digit( $value ) || '0' === $value ) { return null; }
        $id = (int) $value;
        if ( function_exists( 'wp_attachment_is_image' ) && ! wp_attachment_is_image( $id ) ) { return null; }
        $url = function_exists( 'wp_get_attachment_url' ) ? (string) wp_get_attachment_url( $id ) : '';
        return [ 'suffix' => '/attributes/' . $name, 'format' => 'url', 'kind' => 'image_url', 'label' => $label, 'media' => true, 'sample' => $url ];
    }

    private static function kind( string $tag, string $name ): string {
        if ( preg_match( '/button|btn|link_text|cta/', $name ) || preg_match( '/btn|button|cta/', $tag ) && in_array( $name, [ 'text', 'title' ], true ) ) { return 'button_text'; }
        if ( preg_match( '/heading|title/', $name ) || preg_match( '/heading/', $tag ) && 'text' === $name ) { return 'heading'; }
        if ( 'alt' === $name || 'alt_text' === $name ) { return 'image_alt'; }
        return 'text';
    }
}
