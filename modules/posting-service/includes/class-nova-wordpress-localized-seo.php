<?php
/** Render approved per-language SEO without changing the shared source post. */
defined( 'ABSPATH' ) || exit;
require_once dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-wordpress-layout-seo.php';

final class Nova_WordPress_Localized_SEO {
    private const HOOKS = [
        'yoast' => [ 'title' => 'wpseo_title', 'description' => 'wpseo_metadesc' ],
        'rank_math' => [ 'title' => 'rank_math/frontend/title', 'description' => 'rank_math/frontend/description' ],
        'seopress' => [ 'title' => 'seopress_titles_title', 'description' => 'seopress_titles_desc' ],
        'tsf' => [ 'title' => 'the_seo_framework_pre_get_document_title', 'description' => 'the_seo_framework_custom_field_description' ],
        'smartcrawl' => [ 'title' => 'smartcrawl_get_meta_title', 'description' => 'smartcrawl_get_meta_description' ],
        'slim_seo' => [ 'title' => 'slim_seo_meta_title', 'description' => 'slim_seo_meta_description' ],
        'aioseo' => [ 'title' => 'aioseo_title', 'description' => 'aioseo_description' ],
        // Squirrly packages these strings into HTML at priorities 98/99.
        'squirrly' => [ 'title' => 'sq_title', 'description' => 'sq_description' ],
    ];
    private $seen = [];

    public static function on_request( int $id, array $fields, bool $translatepress ): void {
        $renderer = new self(); $active = Nova_WordPress_Layout_SEO::active();
        foreach ( $fields as $path => $binding ) {
            $provider = $binding['key'] ?? ''; $field = $binding['type'] ?? '';
            if ( ! isset( $active[$provider], self::HOOKS[$provider][$field] ) || ! is_string( $binding['value'] ?? null ) || $path !== Nova_WordPress_Layout_SEO::path( $provider, $field ) ) { continue; }
            add_filter( self::HOOKS[$provider][$field], static function ( $original, $context = null ) use ( $renderer, $id, $provider, $field, $binding ) {
                $context_id = is_array( $context ) ? ( $context['id'] ?? $id ) : ( is_numeric( $context ) ? (int) $context : $id );
                if ( $id !== (int) get_queried_object_id() || $id !== (int) $context_id ) { return $original; }
                $renderer->seen[$field] = $binding['value'];
                // TSF formats descriptions after this filter. Protect/restore its native
                // result (including smart quotes), rather than undoing that formatting.
                if ( 'tsf' === $provider && 'description' === $field && is_callable( [ 'The_SEO_Framework\\Data\\Filter\\Sanitize', 'metadata_content' ] ) ) {
                    $renderer->seen[$field] = \The_SEO_Framework\Data\Filter\Sanitize::metadata_content( $binding['value'] );
                }
                return $binding['value'];
            }, 20, 2 );
        }
        add_filter( $translatepress ? 'trp_pre_translating_html' : 'weglot_modify_original_content', [ $renderer, 'protect' ], 25 );
        add_filter( $translatepress ? 'trp_translated_html' : 'weglot_render_dom', [ $renderer, 'restore' ], PHP_INT_MAX );
    }

    /** Only mark head values actually emitted by a selected plugin's native filter. */
    public function protect( $html ) {
        if ( ! is_string( $html ) || ! $this->seen ) { return $html; }
        $tags = new WP_HTML_Tag_Processor( $html ); $head = false;
        while ( $tags->next_token() ) {
            $tag = $tags->get_tag();
            if ( 'HEAD' === $tag ) { if ( $tags->is_tag_closer() ) { break; } $head = true; continue; }
            if ( ! $head || $tags->is_tag_closer() ) { continue; }
            $field = 'TITLE' === $tag ? 'title' : ( 'META' === $tag && 'description' === strtolower( (string) $tags->get_attribute( 'name' ) ) ? 'description' : '' );
            if ( ! isset( $this->seen[$field] ) ) { continue; }
            $value = 'title' === $field ? $tags->get_modifiable_text() : $tags->get_attribute( 'content' );
            if ( $value !== $this->seen[$field] ) { continue; }
            $tags->set_attribute( 'data-no-translation', true ); $tags->set_attribute( 'data-wg-notranslate', true );
            $tags->set_attribute( 'data-nova-seo-field', $field );
        }
        return $tags->get_updated_html();
    }

    /** Provider SEO parsers can translate attributes independently of element exclusions. */
    public function restore( $html ) {
        if ( ! is_string( $html ) || ! $this->seen ) { return $html; }
        $tags = new WP_HTML_Tag_Processor( $html );
        while ( $tags->next_tag() ) {
            $field = $tags->get_attribute( 'data-nova-seo-field' );
            if ( ! is_string( $field ) || ! isset( $this->seen[$field] ) ) { continue; }
            if ( 'title' === $field && 'TITLE' === $tags->get_tag() ) { $tags->set_modifiable_text( $this->seen[$field] ); }
            elseif ( 'description' === $field && 'META' === $tags->get_tag() && 'description' === strtolower( (string) $tags->get_attribute( 'name' ) ) ) { $tags->set_attribute( 'content', $this->seen[$field] ); }
            $tags->remove_attribute( 'data-nova-seo-field' );
        }
        return $tags->get_updated_html();
    }
}
