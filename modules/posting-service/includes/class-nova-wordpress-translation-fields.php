<?php
/** Adapt fitted, approved fields to the two providers' single-source storage models. */
defined( 'ABSPATH' ) || exit;

final class Nova_WordPress_Translation_Fields {
    private static function unsupported( string $field ) {
        return new WP_Error( 'nova_translation_field_unsupported', 'This translation provider cannot persist the fitted destination ' . $field . '.' );
    }

    public static function compile( array $content, array $configuration, array $target ) {
        $local = $configuration['local']; $values = $content['destination_values'] ?? [];
        if ( 'owned_cpt_v3' === $configuration['mode'] ) { return self::owned( $local, $values, $target ); }
        if ( 'stored_layout_v3' !== $configuration['mode'] ) { return self::unsupported( $configuration['mode'] ); }
        if ( 1 === ( $configuration['template']['definition']['version'] ?? null ) ) {
            $sources = array_merge( $content['content'] ?? [], array_intersect_key( $content, array_flip( [ 'url', 'page_type_frontend', 'language' ] ) ) );
            $values = [];
            foreach ( $configuration['template']['mapping']['fields'] as $binding ) {
                if ( isset( $sources[$binding['source_field']] ) ) { $values[$binding['field_id']] = $sources[$binding['source_field']]; }
            }
        }
        $compiled = Nova_Bridge_Suite_Stored_Layout::compile( Nova_Bridge_Suite_Writing_Adapter::adaptation_draft( $local ), $values, $content['site_id'] . ':' . $content['url_id'] );
        if ( is_wp_error( $compiled ) ) { return $compiled; }
        $payload = $compiled['writer_payload']; $builder = $compiled['builder']; $fields = [];
        if ( ! empty( $payload['nova_media'] ) ) {
            if ( 'weglot' !== $target['provider'] || ! in_array( $builder, [ 'core', 'gutenberg', 'elementor' ], true ) ) { return self::unsupported( 'localized media' ); }
            require_once __DIR__ . '/class-nova-bridge-suite-native-media.php';
            $valid = Nova_Bridge_Suite_Native_Media::validate( $builder, $payload );
            if ( is_wp_error( $valid ) ) { return $valid; }
            // Resolve imports only after the writer has retained its operation and acquired the source lock.
            $fields['native_media'] = [ 'builder' => $builder, 'payload' => $payload ];
        }
        $bindings = $local['stored_layout']['bindings'] ?? [];
        $columns = []; $document = false;
        foreach ( $bindings as $binding ) {
            if ( 'protected' === $binding['mode'] ) { continue; }
            if ( 'media' === $binding['kind'] && 'featured' === ( $binding['native_media']['kind'] ?? '' ) ) { continue; }
            if ( 'post' === $binding['kind'] ) { $columns[$binding['column']] = true; }
            elseif ( ! in_array( $binding['kind'], [ 'custom_field', 'routing' ], true ) ) { $document = true; }
        }
        if ( ! empty( $compiled['custom_fields'] ) ) {
            $custom = Nova_Bridge_Suite_Stored_Custom_Fields::prepare( $target['post_type'], $target['post_id'], (string) get_page_template_slug( $target['post_id'] ), $compiled['custom_fields'] );
            if ( is_wp_error( $custom ) ) { return $custom; }
            $fields['mapped_fields'] = $custom;
        }
        foreach ( [ 'title', 'excerpt' ] as $column ) {
            if ( $bindings && ! isset( $columns[$column] ) ) { continue; }
            $value = $compiled['post_fields'][$column] ?? '';
            if ( '' === $value && 'title' === $column ) { $value = $payload['title'] ?? ''; }
            if ( '' !== $value ) { $fields[$column] = sanitize_text_field( $value ); }
        }
        // A source-shared provider only needs the native document when it has a writable document slot.
        // Retained styling and protected slots belong to the original in every language.
        if ( $bindings && ! $document ) { return $fields ?: self::unsupported( 'empty translation' ); }
        if ( 'elementor' === $builder && 'weglot' === $target['provider'] ) {
            $document = wp_json_encode( $payload['elementor_data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            if ( false === $document ) { return self::unsupported( 'Elementor document' ); }
            $fields['meta'] = [ '_elementor_data' => $document ];
        } elseif ( 'core' === $builder && isset( $payload['content'] ) ) {
            $fields['content'] = wp_kses_post( $payload['content'] );
        } elseif ( 'gutenberg' === $builder && isset( $payload['native_document'] ) ) {
            // Render the approved block document to the actual HTML the translation provider serves.
            $fields['content'] = wp_kses_post( do_blocks( $payload['native_document'] ) );
        } else { return self::unsupported( $builder ); }
        return $fields;
    }

    private static function owned( array $local, array $values, array $target ) {
        $fields = []; $meta = []; $seo = [];
        $registered = get_registered_meta_keys( 'post', $target['post_type'] );
        foreach ( $values as $id => $value ) {
            $binding = $local['fields'][$id] ?? []; $path = $binding['target'] ?? '';
            if ( '' === $path || $id !== Nova_Bridge_Suite_Writing_Adapter::field_id( $path ) ) { return self::unsupported( 'unknown field' ); }
            if ( null === $value || '' === $value ) { continue; }
            if ( ! is_string( $value ) ) { return self::unsupported( $path ); }
            if ( '/title' === $path ) { $fields['title'] = sanitize_text_field( $value ); continue; }
            if ( '/@nova/seo/title' === $path || '/@nova/seo/description' === $path ) {
                $seo[basename( $path )] = sanitize_text_field( $value ); continue;
            }
            if ( ! preg_match( '#^/meta/([a-z][a-z0-9_]*)(?:/([0-7])(?:/(question|answer))?)?$#D', $path, $m ) ||
                ! isset( $registered[$m[1]] ) || empty( $registered[$m[1]]['show_in_rest'] ) ||
                in_array( $m[1], [ 'sp_image_1', 'sp_image_2' ], true ) ) { return self::unsupported( $path ); }
            $safe = 'rich_text' === ( $binding['value_type'] ?? '' ) ? wp_kses_post( $value ) : sanitize_text_field( $value );
            if ( ! isset( $m[2] ) ) { $meta[$m[1]] = $safe; }
            elseif ( isset( $m[3] ) && in_array( $m[1], [ 'sp_faq', 'blog_faqs' ], true ) ) { $meta[$m[1]][(int) $m[2]][$m[3]] = $safe; }
            elseif ( ! isset( $m[3] ) && 'blog_key_takeaways' === $m[1] ) { $meta[$m[1]][(int) $m[2]] = $safe; }
            else { return self::unsupported( $path ); }
        }
        if ( $meta ) { $fields['weglot' === $target['provider'] ? 'meta' : 'custom_fields'] = $meta; }
        if ( $seo && 'weglot' === $target['provider'] ) {
            $fields['meta'] = array_merge( $fields['meta'] ?? [], Nova_Bridge_Suite_Owned_CPT_SEO::plan( $seo['title'] ?? null, $seo['description'] ?? null ) );
        } else { foreach ( $seo as $key => $value ) { $fields['seo_' . $key] = $value; } }
        return $fields ?: self::unsupported( 'empty translation' );
    }
}
