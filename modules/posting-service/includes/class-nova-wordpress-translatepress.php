<?php
/** Import articles into TranslatePress's native dictionary. No frontend overrides. */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-nova-wordpress-translatepress-renderer.php';

final class Nova_WordPress_TranslatePress {

    private const INDEX = '_nova_trp_native_ids';

    public static function settings(): array {
        return (array) TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
    }

    public static function translation_allowed( $source ): bool {
        $rule = (array) ( get_option( 'trp_advanced_settings', [] )['translateable_content'] ?? [] );
        if ( empty( $rule['paths'] ) || ! in_array( $rule['option'] ?? '', [ 'exclude', 'include' ], true ) ) { return true; }
        $url = $source instanceof WP_Term ? get_term_link( $source ) : get_permalink( $source );
        if ( is_wp_error( $url ) || ! function_exists( 'trp_dntcp_get_paths' ) ) { return false; }
        $converter = TRP_Translate_Press::get_trp_instance()->get_component( 'url_converter' );
        $settings = self::settings();
        $default_url = $converter->get_url_for_language( $settings['default-language'], $url, '' );
        $home = trailingslashit( $converter->get_abs_home() );
        if ( ( $settings['add-subdirectory-to-default-language'] ?? '' ) === 'yes' ) {
            $home .= $settings['url-slugs'][ $settings['default-language'] ] . '/';
        }
        $path = ltrim( str_replace( $home, '', $default_url ), '/' );
        $segments = [];
        trp_test_current_slug( $path, $segments );
        $matched = (bool) trp_return_exclude_include_url( trp_dntcp_get_paths(), $path, $segments );
        return $rule['option'] === 'exclude' ? ! $matched : $matched;
    }

    private static function language( string $requested ): string {
        $settings = self::settings();
        $normalize = static function ( $value ) { return strtolower( str_replace( '_', '-', $value ) ); };
        $matches = [];
        foreach ( $settings['translation-languages'] ?? [] as $locale ) {
            if ( $normalize( $requested ) === $normalize( $locale ) ) { return $locale; }
            if ( $normalize( $requested ) === $normalize( $settings['url-slugs'][ $locale ] ?? '' ) ) { $matches[] = $locale; }
        }
        return count( $matches ) === 1 ? $matches[0] : '';
    }

    public static function url( $source, string $locale ): string {
        global $TRP_LANGUAGE;
        $original_url = is_string( $source ) ? $source : ( $source instanceof WP_Term ? get_term_link( $source ) : get_permalink( $source ) );
        if ( is_wp_error( $original_url ) ) { return ''; }
        $hash = hash( 'md4', $locale . $original_url . (string) $TRP_LANGUAGE );
        foreach ( [ 'get_url_for_language_', 'get_slug_translated_url_for_language_' ] as $prefix ) {
            wp_cache_delete( $prefix . $hash, 'trp' );
        }
        return TRP_Translate_Press::get_trp_instance()->get_component( 'url_converter' )->get_url_for_language( $locale, $original_url, '' );
    }

    private static function seo_available(): bool {
        return class_exists( 'TRP_IN_Seo_Pack' );
    }

    private static function source( int $id, bool $document ) {
        $post = get_post( $id );
        if ( ! $post || $post->post_status === 'trash' ) {
            return new WP_Error( 'nova_trp_missing_source', 'Source post not found.', [ 'status' => 404 ] );
        }
        $type = get_post_type_object( $post->post_type );
        // WordPress's built-in page type uses page routing despite publicly_queryable=false.
        if ( ! $type || ! $type->public || ( ! $type->publicly_queryable && $post->post_type !== 'page' ) || $post->post_type === 'attachment' || ( $document && ( get_post_meta( $id, '_elementor_edit_mode', true ) === 'builder' ||
            get_post_meta( $id, '_elementor_data', true ) || get_post_meta( $id, '_fl_builder_enabled', true ) ||
            get_post_meta( $id, '_et_pb_use_builder', true ) === 'on' || get_post_meta( $id, '_wpb_vc_js_status', true ) ||
            get_post_meta( $id, '_breakdance_data', true ) ) ) ) {
            return new WP_Error( 'nova_trp_source_unsupported', 'Choose a public WordPress post type without a builder document.', [ 'status' => 422 ] );
        }
        return $post;
    }

    private static function originals( $post, bool $body, bool $seo, array $custom_fields = [] ) {
        $id = $post->ID;
        $originals = [ 'title' => trp_full_trim( esc_html( get_the_title( $post ) ) ),
            'excerpt' => trp_full_trim( esc_html( wptexturize( get_the_excerpt( $post ) ) ) ) ];
        if ( ! $body && ! $seo && ! $custom_fields ) { return $originals; }
        $marker = 'nova-trp-source-' . $id;
        $prepared = Nova_WordPress_TranslatePress_Renderer::prepare( $id, $body, $custom_fields );
        if ( is_wp_error( $prepared ) ) { return $prepared; }
        // Use the rendered original, including WordPress's block/paragraph/shortcode formatting.
        $source_url = get_permalink( $post );
        if ( is_wp_error( $source_url ) ) { return $source_url; }
        // Retry only this read, before dictionary writes; never replay a partially saved import.
        $render_url = add_query_arg( 'nova_trp_source', wp_generate_uuid4(), $source_url );
        $response = null;
        if ( $response === null ) {
            for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
                $response = wp_safe_remote_get( $render_url, [ 'timeout' => 20, 'redirection' => 0, 'limit_response_size' => 3145728, 'headers' => [ 'Cache-Control' => 'no-cache' ] ] );
                if ( ! is_wp_error( $response ) && ! in_array( wp_remote_retrieve_response_code( $response ), [ 502, 503, 504 ], true ) ) { break; }
            }
        }
        if ( is_wp_error( $response ) || in_array( wp_remote_retrieve_response_code( $response ), [ 502, 503, 504 ], true ) ) {
            return new WP_Error( 'nova_trp_source_unavailable', 'Cannot read the public source article after two attempts. ' .
                ( is_wp_error( $response ) ? $response->get_error_message() : 'Source returned HTTP ' . wp_remote_retrieve_response_code( $response ) . '.' ),
                [ 'status' => 503, 'source_url' => $source_url, 'attempts' => 2 ] );
        }
        if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return new WP_Error( 'nova_trp_source_unavailable', 'Cannot read the public source article.', [ 'status' => 502 ] );
        }
        $html = TranslatePress\str_get_html( wp_remote_retrieve_body( $response ), true, true, TRP_DEFAULT_TARGET_CHARSET, false, TRP_DEFAULT_BR_TEXT, TRP_DEFAULT_SPAN_TEXT );
        if ( ! $html ) { return new WP_Error( 'nova_trp_source_unavailable', 'Cannot parse the source page.', [ 'status' => 422 ] ); }
        $nodes = $html ? $html->find( '.' . $marker ) : [];
        if ( $body && ( count( $nodes ) !== 1 || ! trp_full_trim( $nodes[0]->innertext ) ) ) {
            return new WP_Error( 'nova_trp_source_unavailable', 'The rendered source must contain exactly one nonempty article translation block. Purge page caches and retry.', [ 'status' => 422 ] );
        }
        if ( $body ) { $originals['content'] = trp_full_trim( $nodes[0]->innertext ); }
        foreach ( $custom_fields as $key => $translation ) {
            $value = get_metadata( 'post', $id, $key, true );
            $found = self::custom_field_pairs( $html, $value, $translation, 'custom_fields:' . $key );
            if ( is_wp_error( $found ) ) { $html->clear(); return $found; }
            $originals['custom_fields'] = array_merge( $originals['custom_fields'] ?? [], $found );
        }
        if ( $seo ) {
            $originals['seo_title'] = $originals['seo_description'] = [];
            foreach ( $html->find( 'head title' ) as $node ) { $originals['seo_title'][] = trp_full_trim( $node->innertext ); }
            foreach ( $html->find( 'head meta' ) as $node ) {
                $key = $node->getAttribute( 'name' ) ?: $node->getAttribute( 'property' );
                if ( in_array( $key, [ 'og:title', 'twitter:title' ], true ) ) { $originals['seo_title'][] = trp_full_trim( $node->content ); }
                if ( in_array( $key, [ 'description', 'og:description', 'twitter:description' ], true ) ) { $originals['seo_description'][] = trp_full_trim( $node->content ); }
            }
            foreach ( [ 'seo_title', 'seo_description' ] as $field ) { $originals[ $field ] = array_values( array_unique( array_filter( $originals[ $field ], 'strlen' ) ) ); }
        }
        $html->clear();
        return $originals;
    }

    private static function custom_field_pairs( $html, $source, $translated, string $field ) {
        if ( is_array( $translated ) ) {
            if ( ! is_array( $source ) || array_diff( array_keys( $translated ), array_keys( $source ) ) ) {
                return new WP_Error( 'nova_trp_invalid_custom_field', 'Custom-field translations must match existing source keys.', [ 'status' => 422 ] );
            }
            $pairs = [];
            foreach ( $translated as $key => $value ) {
                $found = self::custom_field_pairs( $html, $source[ $key ], $value, $field . ':' . $key );
                if ( is_wp_error( $found ) ) { return $found; }
                $pairs = array_merge( $pairs, $found );
            }
            return $pairs;
        }
        if ( ! is_string( $source ) || ! is_string( $translated ) || ! trim( $source ) ) {
            return new WP_Error( 'nova_trp_invalid_custom_field', 'Supply strings for existing rendered custom-field text.', [ 'status' => 422 ] );
        }
        $rich = $source !== wp_strip_all_tags( $source );
        if ( $rich ) {
            $nodes = $html->find( '.nova-trp-field-' . md5( $field ) );
            if ( ! $nodes ) { return new WP_Error( 'nova_trp_unrendered_custom_field', 'Native custom-field block is absent from the public page: ' . $field, [ 'status' => 422 ] ); }
            $pairs = [];
            foreach ( $nodes as $node ) {
                $original = trp_full_trim( $node->innertext );
                if ( $original ) { $pairs[ $original ] = [ 'original' => $original, 'translated' => trp_sanitize_string( $translated ), 'field' => $field, 'block_type' => 1 ]; }
            }
            return $pairs ? array_values( $pairs ) : new WP_Error( 'nova_trp_unrendered_custom_field', 'Custom-field block has no translatable original: ' . $field, [ 'status' => 422 ] );
        }
        $candidates = [ trp_full_trim( esc_html( $source ) ), trp_full_trim( esc_html( wptexturize( $source ) ) ) ];
        $pairs = [];
        foreach ( $html->find( 'body trptext' ) as $node ) {
            $original = trp_full_trim( $node->outertext );
            if ( ! in_array( $original, $candidates, true ) || $node->find_ancestor_tag( 'script' ) || $node->find_ancestor_tag( 'style' ) ) { continue; }
            $pairs[ $original ] = [ 'original' => $original, 'translated' => trp_sanitize_string( esc_html( sanitize_text_field( $translated ) ) ),
                'field' => $field, 'block_type' => 0 ];
        }
        return $pairs ? array_values( $pairs ) : new WP_Error( 'nova_trp_unrendered_custom_field', 'Custom field is absent from the public page or its HTML structure is unsupported: ' . $field, [ 'status' => 422 ] );
    }

    public static function save( int $id, array $input ) {
        $post = self::source( $id, isset( $input['content'] ) || ! empty( $input['custom_fields'] ) );
        if ( is_wp_error( $post ) ) { return $post; }
        if ( ! self::translation_allowed( $post ) ) {
            return new WP_Error( 'nova_trp_source_excluded', 'TranslatePress excludes this source URL from translation.', [ 'status' => 409 ] );
        }
        if ( ( $post->post_status !== 'publish' || $post->post_password ) ) {
            return new WP_Error( 'nova_trp_source_not_public', 'Publish the source without a password before importing native translations.', [ 'status' => 422 ] );
        }
        $settings = self::settings();
        $query = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
        $locale = self::language( $input['language'] );
        if ( ! $locale || $locale === $settings['default-language'] || ! in_array( $locale, $settings['publish-languages'] ?? [], true ) ) {
            return new WP_Error( 'nova_trp_invalid_translation', 'Choose a published secondary TranslatePress language.' );
        }
        $seo = isset( $input['seo_title'] ) || isset( $input['seo_description'] );
        if ( $seo && ! self::seo_available() ) { return new WP_Error( 'nova_trp_invalid_translation', 'Enable TranslatePress SEO Pack for mapped SEO fields.' ); }
        $originals = self::originals( $post, array_key_exists( 'content', $input ), $seo, $input['custom_fields'] ?? [] );
        if ( is_wp_error( $originals ) ) { return $originals; }
        $error = '';
        $pairs = [];
        foreach ( [ 'title', 'content', 'excerpt', 'seo_title', 'seo_description' ] as $field ) {
            if ( ! array_key_exists( $field, $input ) ) { continue; }
            $translated = trp_sanitize_string( $field === 'content' ? $input[ $field ] : esc_html( sanitize_text_field( $input[ $field ] ) ) );
            if ( empty( $originals[ $field ] ) ) { $error = 'No rendered source string exists for ' . $field . '.'; break; }
            foreach ( (array) $originals[ $field ] as $key => $original ) {
                if ( isset( $pairs[ $original ] ) && $pairs[ $original ]['translated'] !== $translated ) {
                    $error = 'A supplied field has no translatable original or conflicts with another shared original.';
                    break 2;
                }
                $fields = $pairs[ $original ]['fields'] ?? [];
                $fields[] = strpos( $field, 'seo_' ) === 0 ? $field . ':' . $key : $field;
                $pairs[ $original ] = [ 'translated' => $translated, 'fields' => $fields,
                'block_type' => $field === 'content' ? $query->get_constant_block_type_active() : $query->get_constant_block_type_regular_string() ];
            }
        }
        foreach ( $originals['custom_fields'] ?? [] as $pair ) {
            $original = $pair['original'];
            if ( isset( $pairs[ $original ] ) && ( $pairs[ $original ]['translated'] !== $pair['translated'] || $pairs[ $original ]['block_type'] !== $pair['block_type'] ) ) {
                $error = 'Conflicting translations for a shared native original.';
                break;
            }
            $pairs[ $original ] = [ 'translated' => $pair['translated'], 'block_type' => $pair['block_type'],
                'fields' => array_merge( $pairs[ $original ]['fields'] ?? [], [ $pair['field'] ] ) ];
        }
        if ( $error ) {
            return new WP_Error( 'nova_trp_invalid_original', $error );
        }
        return self::save_pairs( $id, $locale, $pairs, $query, $settings );
    }

    private static function collision() {
        return new WP_Error( 'nova_trp_shared_translation', 'An existing TranslatePress string has another translation or owner. Review it in the native translation editor.', [ 'status' => 409 ] );
    }

    private static function witness( $row ): string {
        return hash( 'sha256', serialize( [ (int) $row->id, (string) $row->original, (string) $row->translated, (int) $row->status, (int) $row->block_type, (int) $row->original_id ] ) );
    }

    /** TranslatePress tracks known uses separately from dictionary rows; shared rows have several parents. */
    private static function can_change_row( $query, $row, int $id, bool $untranslated ): bool {
        global $wpdb;
        if ( ! is_callable( [ $query, 'get_table_name_for_original_meta' ] ) || ! is_callable( [ $query, 'get_meta_key_for_post_parent_id' ] ) ) { return false; }
        $table = $query->get_table_name_for_original_meta();
        if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) || (int) $row->original_id < 1 ) { return false; }
        $parents = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM `$table` WHERE original_id = %d AND meta_key = %s", $row->original_id, $query->get_meta_key_for_post_parent_id() ) );
        if ( ! is_array( $parents ) || ! empty( $wpdb->last_error ) ) { return false; }
        return ( $untranslated && ! $parents ) || [ (string) $id ] === array_map( 'strval', $parents );
    }

    /** An interrupted native upsert may still have either witnessed value when the same operation resumes. */
    private static function checkpoint( int $id, string $key, array $owned ): bool {
        update_post_meta( $id, $key, $owned );
        return get_post_meta( $id, $key, true ) === $owned;
    }

    private static function save_pairs( int $id, string $locale, array $pairs, $query, array $settings ) {
        $query->check_table( $settings['default-language'], $locale );
        $key = '_nova_trp_writes_' . hash( 'sha256', $locale );
        $owned = get_post_meta( $id, $key, true );
        $owned = is_array( $owned ) ? $owned : [];
        $updates = $index = $expected = [];
        foreach ( $pairs as $original => $pair ) {
            $original = (string) $original;
            $rows = $query->get_string_rows( [], [ $original ], $locale );
            if ( [] === $rows ) {
                $query->insert_strings( [ $original ], $locale, $pair['block_type'] );
                $rows = $query->get_string_rows( [], [ $original ], $locale );
            }
            if ( ! is_array( $rows ) || ! $rows ) { return new WP_Error( 'nova_trp_write_failed', 'Native dictionary lookup failed.' ); }
            if ( count( $rows ) !== 1 ) { return self::collision(); }
            $row = reset( $rows );
            if ( $row->original !== $original ) { return self::collision(); }
            $row_id = (int) $row->id;
            if ( $row_id < 1 || ( isset( $owned[$row_id] ) && ! is_array( $owned[$row_id] ) ) ) { return self::collision(); }
            foreach ( $pair['fields'] as $field ) { $index[$field] = $row_id; }
            $current = self::witness( $row );
            $expected[$row_id] = $current;
            if ( (int) $row->status !== $query->get_constant_not_translated() && $row->translated === $pair['translated'] && (int) $row->block_type === $pair['block_type'] ) { continue; }
            $untranslated = (int) $row->status === $query->get_constant_not_translated() && '' === (string) $row->translated;
            $first_write = $untranslated && ! isset( $owned[$row_id] );
            if ( ( ! $first_write && ! in_array( $current, $owned[$row_id] ?? [], true ) ) || ! self::can_change_row( $query, $row, $id, $untranslated ) ) { return self::collision(); }
            $update = [ 'id' => $row_id, 'original' => $original, 'translated' => $pair['translated'], 'status' => $query->get_constant_human_reviewed(), 'block_type' => $pair['block_type'] ];
            $updates[] = $update;
            $expected[$row_id] = self::witness( (object) ( $update + [ 'original_id' => $row->original_id ] ) );
            $owned[$row_id] = [ $current, $expected[$row_id] ];
        }
        if ( $updates ) {
            if ( ! self::checkpoint( $id, $key, $owned ) ) { return new WP_Error( 'nova_trp_write_failed', 'Could not retain the planned dictionary update.' ); }
            $query->update_strings( $updates, $locale, [ 'id', 'translated', 'status', 'block_type' ] );
        }
        $rows = $query->get_string_rows( array_keys( $expected ), [], $locale );
        foreach ( $expected as $row_id => $digest ) {
            if ( ! isset( $rows[$row_id] ) || self::witness( $rows[$row_id] ) !== $digest ) { return new WP_Error( 'nova_trp_write_failed', 'Native dictionary verification failed.' ); }
            if ( in_array( $digest, $owned[$row_id] ?? [], true ) ) { $owned[$row_id] = [ $digest ]; }
        }
        if ( $owned && ! self::checkpoint( $id, $key, $owned ) ) { return new WP_Error( 'nova_trp_write_failed', 'Could not verify dictionary ownership.' ); }
        $query->set_original_string_meta_post_id( array_column( $rows, 'original_id' ), array_fill( 0, count( $rows ), $id ) );
        wp_cache_delete( 'get_all_translation_blocks_' . md5( $locale ), 'trp' );
        if ( $updates ) { do_action( 'trp_save_editor_translations_regular_strings', [ $locale => $updates ], $settings ); }
        // Bookkeeping contains row IDs only; all translation text lives in TranslatePress.
        $stored = get_metadata( 'post', $id, self::INDEX, true );
        $stored = is_array( $stored ) ? $stored : [];
        $stored[ $locale ] = array_merge( $stored[ $locale ] ?? [], $index );
        update_metadata( 'post', $id, self::INDEX, $stored );
        $translated_url = self::url( get_permalink( $id ), $locale );
        foreach ( array_unique( [ $translated_url, get_permalink( $id ) ] ) as $purge_url ) {
            if ( is_string( $purge_url ) ) { do_action( 'litespeed_purge_url', $purge_url ); }
        }
        return $index;
    }

    /** Read editor-visible dictionary values, including edits made outside NOVA. */
    public static function evidence( int $id, string $locale, array $fields ) {
        if ( ! $fields ) { return []; }
        $query = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
        $rows = $query->get_string_rows( array_values( $fields ), [], $locale );
        $proof = [];
        foreach ( $fields as $field => $key ) {
            if ( ! isset( $rows[$key] ) ) {
                return new WP_Error( 'nova_translation_readback', 'A TranslatePress dictionary entry is missing.' );
            }
            $row = $rows[$key];
            $proof[$field] = [ (int) $row->id, (string) $row->original, (string) $row->translated, (int) $row->status, (int) $row->block_type ];
        }
        return $proof;
    }
}
