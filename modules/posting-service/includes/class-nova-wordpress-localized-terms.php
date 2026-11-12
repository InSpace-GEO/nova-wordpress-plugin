<?php
/** Serve approved shared-category copy in its requested language. */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-nova-bridge-suite-term-translation-writer.php';

final class Nova_WordPress_Localized_Terms {
    public static function on_request(): void {
        $trp = class_exists( 'TRP_Translate_Press' );
        $language = $trp ? (string) ( $GLOBALS['TRP_LANGUAGE'] ?? '' ) : ( new Nova_WordPress_Weglot() )->get_current_code();
        $provider = Nova_Bridge_Suite_Translation_Writer::provider( $language );
        if ( ! is_array( $provider ) ) { return; }
        $id = (int) get_queried_object_id();
        $term = get_queried_object();
        $taxonomy = $term->taxonomy ?? '';
        if ( ! in_array( $taxonomy, [ 'product_cat', 'product_tag' ], true ) ) { return; }
        $record = Nova_Bridge_Suite_Term_Translation_Writer::record( $id, $provider );
        if ( ! $term || is_wp_error( $term ) || ( $record['state'] ?? '' ) !== 'committed' ||
            ( $record['term_id'] ?? 0 ) !== $id || ( $record['taxonomy'] ?? '' ) !== $taxonomy ) { return; }
        $fields = $record['fields']['meta'] ?? [];
        foreach ( $fields as $name => $field ) {
            if ( ! is_string( $field['value'] ?? null ) ) { unset( $fields[$name] ); continue; }
            if ( ( $field['provider'] ?? '' ) === 'acf' ) {
                $definition = function_exists( 'acf_get_field' ) ? acf_get_field( $field['key'] ) : null;
                if ( ! $definition || $definition['name'] !== $name || $definition['type'] !== $field['type'] ) {
                    unset( $fields[$name] );
                } elseif ( function_exists( 'acf_flush_value_cache' ) ) {
                    acf_flush_value_cache( 'term_' . $id, $name );
                    acf_flush_value_cache( $taxonomy . '_' . $id, $name );
                }
            }
        }
        $native = array_intersect_key( $record['fields']['native'] ?? [], array_flip( [ 'name', 'description' ] ) );
        foreach ( $native as $name => $field ) {
            if ( is_string( $field['value'] ?? null ) ) { $fields['@term/' . $name] = $field; }
            else { unset( $native[$name] ); }
        }
        $renderer = new Nova_WordPress_Localized_Fields( $id, $fields, 'term', $taxonomy );
        Nova_WordPress_Localized_Fields::attach( $renderer, $trp );
        $replace = static function ( $value ) use ( $renderer, $native, $id, $taxonomy ) {
            if ( ! $value instanceof WP_Term || $value->taxonomy !== $taxonomy || (int) $value->term_id !== $id ) { return $value; }
            $localized = clone $value;
            foreach ( $native as $name => $field ) { $localized->$name = $renderer->value( '@term/' . $name ); }
            return $localized;
        };
        add_filter( 'get_term', $replace, 30 );
        global $wp_query;
        if ( isset( $wp_query->queried_object ) ) {
            $wp_query->queried_object = $replace( $wp_query->queried_object );
        }
        self::seo( $id, $fields, $trp );
    }

    private static function seo( int $id, array $fields, bool $trp ): void {
        require_once __DIR__ . '/class-nova-wordpress-localized-seo.php';
        $keys = [
            '_seopress_titles_title' => [ 'seopress', 'title' ],
            '_seopress_titles_desc' => [ 'seopress', 'description' ],
            '_yoast_wpseo_title' => [ 'yoast', 'title' ],
            '_yoast_wpseo_metadesc' => [ 'yoast', 'description' ],
            'wpseo_title' => [ 'yoast', 'title' ],
            'wpseo_desc' => [ 'yoast', 'description' ],
            'rank_math_title' => [ 'rank_math', 'title' ],
            'rank_math_description' => [ 'rank_math', 'description' ],
        ];
        $seo = [];
        foreach ( $keys as $name => [ $provider, $type ] ) {
            if ( isset( $fields[$name] ) ) {
                $seo[Nova_WordPress_Layout_SEO::path( $provider, $type )] = [
                    'provider' => 'seo', 'key' => $provider, 'type' => $type, 'value' => $fields[$name]['value'],
                ];
            }
        }
        if ( $seo ) { Nova_WordPress_Localized_SEO::on_request( $id, $seo, $trp ); }
    }
}
