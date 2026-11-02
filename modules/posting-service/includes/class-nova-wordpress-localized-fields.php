<?php
/** Render mapped custom fields on the published source in the requested provider locale. */
defined( 'ABSPATH' ) || exit;

final class Nova_WordPress_Localized_Fields {
    private $id;
    private $fields;
    private $seen = [];
    private $attributes = [];
    private $reading_bulk = false;

    public function __construct( int $id, array $fields ) {
        $this->id = $id;
        $this->fields = $fields;
    }

    public static function on_request(): void {
        if ( is_admin() || ! is_singular() || is_feed() || is_preview() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }
        $trp = class_exists( 'TRP_Translate_Press' );
        $language = $trp ? (string) ( $GLOBALS['TRP_LANGUAGE'] ?? '' ) : ( new Nova_WordPress_Weglot() )->get_current_code();
        $provider = Nova_Bridge_Suite_Translation_Writer::provider( $language );
        if ( ! is_array( $provider ) ) { return; }
        $id = (int) get_queried_object_id();
        $fields = Nova_Bridge_Suite_Translation_Writer::localized_fields( $id, $provider['provider'], $provider['language'] );
        if ( ! $fields ) { return; }

        // If the site repurposes a field after delivery, stop serving its old translation.
        if ( ! class_exists( 'Nova_Bridge_Suite_Content_Transport' ) ) { require_once dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-content-transport.php'; }
        $catalog = Nova_Bridge_Suite_Content_Transport::field_catalog( get_post_type( $id ), $id, (string) get_page_template_slug( $id ) );
        foreach ( $fields as $name => $field ) {
            if ( is_protected_meta( $name, 'post' ) ) { unset( $fields[$name] ); continue; }
            if ( 'acf' === $field['provider'] ) {
                $definition = $catalog['acf'][$name] ?? [];
                $reference = get_post_meta( $id, '_' . $name, true );
                if ( ( $definition['key'] ?? '' ) !== $field['key'] || ( $definition['type'] ?? '' ) !== $field['type'] || ( '' !== $reference && $reference !== $field['key'] ) ) { unset( $fields[$name] ); }
                elseif ( function_exists( 'acf_flush_value_cache' ) ) {
                    // get_field() can already have cached the formatted/escaped original before 'wp'.
                    acf_flush_value_cache( $id, $name );
                }
            } elseif ( ( $catalog['meta'][$name]['type'] ?? '' ) !== 'string' || empty( $catalog['meta'][$name]['single'] ) || empty( $catalog['meta'][$name]['show_in_rest'] ) ) {
                unset( $fields[$name] );
            }
        }
        if ( ! $fields ) { return; }
        $renderer = new self( $id, $fields );
        add_filter( 'get_post_metadata', [ $renderer, 'meta' ], 30, 4 );
        add_filter( 'acf/pre_load_value', [ $renderer, 'acf' ], 30, 3 );
        // A cached Elementor widget may otherwise skip the field read or cache the secondary language on the original.
        if ( did_action( 'elementor/loaded' ) ) {
            $disable_cache = static function () { return 'disable'; };
            add_filter( 'option_elementor_element_cache_ttl', $disable_cache, 99 );
            add_filter( 'default_option_elementor_element_cache_ttl', $disable_cache, 99 );
        }
        add_filter( $trp ? 'trp_pre_translating_html' : 'weglot_modify_original_content', [ $renderer, 'protect' ], 20 );
        add_filter( $trp ? 'trp_translated_html' : 'weglot_render_dom', [ $renderer, 'restore_attributes' ], PHP_INT_MAX );
    }

    public function meta( $previous, $id, $name, $single ) {
        if ( (int) $id !== $this->id || $this->reading_bulk ) { return $previous; }
        if ( '' === $name ) {
            // get_post_custom() exposes the complete, serialized cache map, even for unrelated arrays/objects.
            $this->reading_bulk = true;
            try { $all = null === $previous ? get_post_meta( $id ) : ( $single ? ( $previous[0] ?? $previous ) : $previous ); }
            finally { $this->reading_bulk = false; }
            if ( ! is_array( $all ) ) { return $previous; }
            foreach ( array_keys( $this->fields ) as $key ) { $all[$key] = [ maybe_serialize( $this->value( $key ) ) ]; }
            if ( isset( $all['_elementor_element_cache'] ) ) { $all['_elementor_element_cache'] = [ '' ]; }
            return $single ? [ $all ] : $all;
        }
        if ( '_elementor_element_cache' === $name ) { return [ '' ]; }
        if ( ! isset( $this->fields[$name] ) ) { return $previous; }
        return [ $this->value( $name ) ]; // get_metadata_raw unwraps one level for single reads.
    }

    public function acf( $previous, $id, $definition ) {
        // ACF also calls this filter for terms, users and its options pages.
        if ( ! ctype_digit( (string) $id ) || (int) $id !== $this->id ) { return $previous; }
        $name = $definition['name'] ?? ''; $field = $this->fields[$name] ?? [];
        if ( ( $field['provider'] ?? '' ) !== 'acf' || $field['key'] !== ( $definition['key'] ?? '' ) || $field['type'] !== ( $definition['type'] ?? '' ) ) { return $previous; }
        return $this->value( $name );
    }

    private function value( string $name ): string {
        $value = $this->fields[$name]['value'];
        if ( ! isset( $this->seen[$name] ) ) {
            $this->seen[$name] = [];
            $parts = [ $value ];
            if ( 'wysiwyg' === $this->fields[$name]['type'] ) {
                $parts = [];
                $tags = new WP_HTML_Tag_Processor( $value );
                while ( $tags->next_token() ) {
                    if ( '#text' === $tags->get_token_type() ) { $parts[] = $tags->get_modifiable_text(); }
                }
            }
            foreach ( $parts as $part ) {
                foreach ( preg_split( '/\R/u', $part ) ?: [] as $line ) {
                    foreach ( [ $line, html_entity_decode( wptexturize( $line ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ] as $form ) {
                        $text = trim( $form );
                        if ( '' !== $text ) { $this->seen[$name][$text] = true; }
                    }
                }
            }
        }
        return $value;
    }

    /** Tell the provider which final, already-localized HTML it should leave alone.
     * Metadata itself remains clean for ACF formatters, esc_attr, links and ordinary PHP.
     * Restrict this to primary content markup and fields actually read for this post.
     */
    public function protect( $html ) {
        if ( ! is_string( $html ) || ! $this->seen ) { return $html; }
        $known = [];
        foreach ( $this->seen as $values ) { $known += $values; }
        if ( ! $known ) { return $html; }
        // First count exact matches. If a short field also appears as an unrelated label,
        // attributing either node to that field would disable legitimate translations.
        $counts = []; $replacements = [];
        for ( $pass = 1; $pass <= 2; ++$pass ) {
            $tags = new WP_HTML_Tag_Processor( $html ); $scope = 0; $skipped = 0; $regions = [];
            while ( $tags->next_token() ) {
                $tag = $tags->get_tag();
                if ( null !== $tag ) {
                    $closing = $tags->is_tag_closer();
                    // WordPress consumes raw-text elements (SCRIPT, STYLE, TEXTAREA) in a single token.
                    $excluded = in_array( $tag, [ 'HEAD', 'SELECT', 'TEMPLATE', 'SVG', 'MATH', 'NAV', 'FOOTER', 'PRE', 'CODE' ], true );
                    if ( $excluded || in_array( $tag, [ 'DIV', 'SECTION', 'MAIN', 'ARTICLE' ], true ) ) {
                        if ( $closing ) {
                            if ( ! empty( $regions[$tag] ) ) {
                                [ $area, $omit ] = array_pop( $regions[$tag] ); $scope -= $area; $skipped -= $omit;
                            }
                        } elseif ( ! $tags->has_self_closing_flag() ) {
                            $type = $tags->get_attribute( 'data-elementor-type' );
                            $content = in_array( $type, [ 'wp-page', 'wp-post' ], true );
                            $element_id = $tags->get_attribute( 'data-elementor-id' );
                            $omit = $excluded || in_array( $type, [ 'header', 'footer', 'loop-item' ], true ) ||
                                ( $content && is_string( $element_id ) && (int) $element_id !== $this->id );
                            $area = in_array( $tag, [ 'MAIN', 'ARTICLE' ], true ) || 'main' === $tags->get_attribute( 'role' ) ||
                                $content || in_array( $type, [ 'single', 'single-post', 'single-page' ], true ) || 'post-' . $this->id === $tags->get_attribute( 'id' );
                            $regions[$tag][] = [ (int) $area, (int) $omit ]; $scope += (int) $area; $skipped += (int) $omit;
                        }
                    }
                    if ( ! $scope || $skipped || $closing ) { continue; }
                    $attrs = [];
                    foreach ( [ 'href', 'title', 'alt', 'aria-label', 'placeholder', 'value' ] as $name ) {
                        $value = $tags->get_attribute( $name );
                        if ( ! is_string( $value ) ) { continue; }
                        $text = 0 === strpos( $value, 'mailto:' ) ? substr( $value, 7 ) : $value;
                        if ( ! isset( $known[$text] ) ) { continue; }
                        if ( 1 === $pass ) { $counts[$name][$value] = 1 + ( $counts[$name][$value] ?? 0 ); }
                        elseif ( 1 === $counts[$name][$value] ) { $attrs[$name] = $value; }
                    }
                    if ( $attrs ) {
                        $marker = wp_generate_uuid4(); $this->attributes[$marker] = $attrs;
                        $tags->set_attribute( 'data-nova-field-attrs', $marker );
                    }
                } elseif ( '#text' === $tags->get_token_type() && $scope && ! $skipped ) {
                    $original = $tags->get_modifiable_text(); $text = trim( $original );
                    if ( ! isset( $known[$text] ) ) { continue; }
                    if ( 1 === $pass ) { $counts['#text'][$text] = 1 + ( $counts['#text'][$text] ?? 0 ); }
                    elseif ( 1 === $counts['#text'][$text] ) {
                        // Substitute markup only at tokens positively identified as ordinary HTML text.
                        $marker = wp_generate_uuid4(); $tags->set_modifiable_text( $marker );
                        $replacements[$marker] = '<span data-no-translation data-wg-notranslate>' . htmlspecialchars( $original, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</span>';
                    }
                }
            }
            if ( ! $counts ) { return $html; }
        }
        return strtr( $tags->get_updated_html(), $replacements );
    }

    public function restore_attributes( $html ) {
        if ( ! $this->attributes || ! is_string( $html ) || false === strpos( $html, 'data-nova-field-attrs' ) ) { return $html; }
        $tags = new WP_HTML_Tag_Processor( $html );
        while ( $tags->next_tag() ) {
            $marker = $tags->get_attribute( 'data-nova-field-attrs' );
            if ( ! is_string( $marker ) || ! isset( $this->attributes[$marker] ) ) { continue; }
            foreach ( $this->attributes[$marker] as $name => $value ) { $tags->set_attribute( $name, $value ); }
            $tags->remove_attribute( 'data-nova-field-attrs' );
        }
        return $tags->get_updated_html();
    }
}
