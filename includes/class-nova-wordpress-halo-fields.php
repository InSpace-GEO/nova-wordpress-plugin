<?php
/** SiteJob/HALO's public REST names are the contract; storage keys remain site-local. */
defined( 'ABSPATH' ) || exit;

final class Nova_WordPress_Halo_Fields {
    private const TYPES = [
        'seo_title_text' => 'string', 'seo_meta_description_text' => 'string',
        'seo_canonical_url' => 'string', 'seo_primary_search_query_text' => 'string',
        'article_call_to_action_buttons' => 'array', 'article_reference_sources' => 'array',
        'article_faq_questions' => 'array', 'display_intake_form_boolean' => 'boolean',
        'secondary_small_rich_text' => 'string', 'optional_second_image_id' => 'integer',
        'optional_large_third_rich_text' => 'string', 'service_intro_rich_text' => 'string',
        'service_process_steps' => 'array',
    ];
    private const LABELS = [
        'seo_title_text' => 'SEO title', 'seo_meta_description_text' => 'Meta description',
        'seo_canonical_url' => 'Canonical URL', 'seo_primary_search_query_text' => 'Primary search query',
        'article_call_to_action_buttons' => 'Action buttons', 'article_reference_sources' => 'Sources',
        'article_faq_questions' => 'Frequently asked questions', 'display_intake_form_boolean' => 'Show inquiry form',
        'secondary_small_rich_text' => 'Supplementary text', 'optional_second_image_id' => 'Second image',
        'optional_large_third_rich_text' => 'Extended supplementary text',
        'service_intro_rich_text' => 'Service introduction', 'service_process_steps' => 'Service process steps',
    ];

    public static function catalog( string $type, int $id = 0 ): array {
        if ( ! function_exists( 'get_registered_meta_keys' ) ) { return []; }
        $fields = []; $duplicates = [];
        foreach ( array_merge( get_registered_meta_keys( 'post' ), get_registered_meta_keys( 'post', $type ) ) as $key => $args ) {
            $rest = $args['show_in_rest'] ?? false;
            $name = is_array( $rest ) ? ( $rest['name'] ?? $key ) : $key;
            if ( ! $rest || empty( $args['single'] ) || ! isset( self::TYPES[$name] ) || ( $args['type'] ?? '' ) !== self::TYPES[$name] ) { continue; }
            $schema = ( is_array( $rest ) ? ( $rest['schema'] ?? [] ) : [] ) + [ 'type' => $args['type'], 'description' => $args['description'] ?? '' ];
            if ( ! empty( $schema['readonly'] ) || ( isset( $schema['context'] ) && ! in_array( 'edit', $schema['context'], true ) ) ) { continue; }
            if ( 'array' === $schema['type'] && empty( $schema['items']['properties'] ) ) { continue; }
            if ( $id && ! current_user_can( 'edit_post_meta', $id, $key ) ) { continue; }
            if ( isset( $fields[$name] ) ) { $duplicates[$name] = true; }
            $fields[$name] = [ 'provider' => 'halo', 'name' => $name, 'key' => $key, 'type' => $args['type'], 'schema' => $schema,
                'label' => 'HALO · ' . self::LABELS[$name], 'instructions' => $schema['description'] ?? '' ];
        }
        foreach ( $duplicates as $name => $unused ) { unset( $fields[$name] ); }
        // Pages may expose only SEO while the site's article contract lives on posts.
        $signature = $fields;
        foreach ( get_registered_meta_keys( 'post', 'post' ) as $key => $args ) {
            $rest = $args['show_in_rest'] ?? false;
            $name = is_array( $rest ) ? ( $rest['name'] ?? $key ) : $key;
            if ( $rest && ! empty( $args['single'] ) && isset( self::TYPES[$name] ) && self::TYPES[$name] === ( $args['type'] ?? '' ) ) { $signature[$name] = true; }
        }
        if ( $duplicates || ! isset( $signature['seo_title_text'], $signature['seo_meta_description_text'], $signature['article_faq_questions'] ) ) { return []; }
        return $fields;
    }

    public static function valid( array $field ): bool {
        return ( $field['provider'] ?? '' ) === 'halo' && isset( self::TYPES[$field['name'] ?? ''] )
            && self::TYPES[$field['name']] === ( $field['type'] ?? '' ) && is_string( $field['key'] ?? null )
            && preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,190}$/D', $field['key'] )
            && is_array( $field['schema'] ?? null ) && ( $field['schema']['type'] ?? '' ) === $field['type'];
    }

    public static function check( string $type, int $id, array $field ) {
        $current = self::catalog( $type, $id )[$field['name'] ?? ''] ?? null;
        if ( ! self::valid( $field ) || ! $current || $current['key'] !== $field['key'] || $current['schema'] !== $field['schema'] ) {
            return new WP_Error( 'nova_halo_schema_changed', 'The registered HALO field or its permissions changed. Review the saved mapping.', [ 'status' => 409 ] );
        }
        if ( ! $id ) {
            // HALO callbacks authorize a real post ID. The native REST write checks them after creation.
            if ( ! current_user_can( get_post_type_object( $type )->cap->create_posts ) ) {
                return new WP_Error( 'nova_halo_permission', 'The publishing account cannot create this HALO field.', [ 'status' => 403 ] );
            }
        }
        return true;
    }

    public static function value_type( array $field ): string {
        return 'array' === $field['type'] || str_contains( $field['name'], 'rich_text' ) ? 'rich_text' : 'text';
    }

    public static function instructions( array $field ): string {
        $text = $field['schema']['description'] ?? '';
        if ( 'array' === $field['type'] ) {
            $text .= ' Return only a JSON array matching this schema, without a Markdown code fence: ' . wp_json_encode( $field['schema'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        } elseif ( 'boolean' === $field['type'] ) {
            $text .= ' Return exactly true or false.';
        } elseif ( 'integer' === $field['type'] ) {
            $text .= ' Return an existing image attachment ID, 0 to clear, or an exact supplied image URL for import. Never invent an attachment ID.';
        }
        return $text . ' Omit an optional value to preserve the destination value.';
    }

    /** Scalar fitting carries JSON lists; native writes retain their registered WordPress types. */
    public static function decode( $value, array $field, bool $media_url = true ) {
        if ( ! self::valid( $field ) ) { return new WP_Error( 'nova_halo_field', 'Invalid HALO field binding.' ); }
        if ( 'string' !== $field['type'] && is_string( $value ) ) {
            if ( $media_url && 'optional_second_image_id' === $field['name'] && preg_match( '#^https?://#i', $value ) ) {
                return wp_http_validate_url( $value ) ? $value : new WP_Error( 'nova_halo_image', 'Use an accessible HTTP(S) image URL.' );
            }
            $decoded = json_decode( $value, true );
            if ( JSON_ERROR_NONE !== json_last_error() ) { return new WP_Error( 'nova_halo_value', 'Supply valid JSON for ' . $field['name'] . '.' ); }
            $value = $decoded;
        }
        $valid = rest_validate_value_from_schema( $value, $field['schema'], $field['name'] );
        if ( is_wp_error( $valid ) ) { return $valid; }
        if ( 'integer' === $field['type'] && $value && ( ! wp_attachment_is_image( $value ) || ! current_user_can( 'read_post', $value ) ) ) {
            return new WP_Error( 'nova_halo_image', 'Choose an accessible image attachment.' );
        }
        return rest_sanitize_value_from_schema( $value, $field['schema'] );
    }

    public static function normalize( $value, array $field, string $type ) {
        $decoded = self::decode( $value, $field );
        if ( is_wp_error( $decoded ) || ( 'integer' === $field['type'] && is_string( $decoded ) ) ) { return $decoded; }
        $clean = sanitize_meta( $field['key'], $decoded, 'post', $type );
        if ( 'array' === $field['type'] && ( ! is_array( $clean ) || count( $clean ) !== count( $decoded ) ) ) { return new WP_Error( 'nova_halo_value', 'HALO rejected an incomplete list entry.' ); }
        return self::decode( $clean, $field, false );
    }

    public static function snapshot( int $id, array $field ) {
        if ( ! metadata_exists( 'post', $id, $field['key'] ) ) { return [ 'value' => null, 'reference' => null ]; }
        $values = get_post_meta( $id, $field['key'], false );
        if ( count( $values ) > 1 ) { return new WP_Error( 'nova_halo_storage', 'The HALO field has ambiguous storage.' ); }
        return [ 'value' => $values ? $values[0] : null, 'reference' => null ];
    }

    public static function stored_value( $value, array $field ) {
        return in_array( $field['type'], [ 'array', 'object' ], true ) ? $value : (string) $value;
    }

    public static function matches( array $snapshot, $value, array $field, string $type ): bool {
        if ( $snapshot['value'] === self::stored_value( $value, $field ) ) { return true; }
        if ( null !== $snapshot['value'] || 'article_faq_questions' === $field['name'] ) { return false; }
        $args = array_merge( get_registered_meta_keys( 'post' ), get_registered_meta_keys( 'post', $type ) )[$field['key']] ?? [];
        return array_key_exists( 'default', $args ) && $value === $args['default'];
    }

    public static function content_schema( array $recipe, array $schema ): array {
        foreach ( $recipe['posting_fields'] ?? [] as $field ) {
            if ( 'halo' !== ( $field['provider'] ?? '' ) ) { continue; }
            $native = $field['schema'];
            unset( $native['context'] );
            if ( 'optional_second_image_id' === $field['name'] ) { $native = [ 'anyOf' => [ $native, [ 'type' => 'string', 'format' => 'uri' ] ] ]; }
            $schema['properties']['halo']['type'] = 'object';
            $schema['properties']['halo']['properties'][$field['name']] = $native;
            if ( ! empty( $field['required'] ) ) { $schema['properties']['halo']['required'][] = $field['name']; $schema['required'][] = 'halo'; }
        }
        if ( isset( $schema['required'] ) ) { $schema['required'] = array_values( array_unique( $schema['required'] ) ); }
        return $schema;
    }

    public static function compile( array $recipe, array $content ) {
        $fields = [];
        foreach ( $recipe['posting_fields'] ?? [] as $field ) {
            if ( 'halo' !== ( $field['provider'] ?? '' ) ) { continue; }
            if ( ! array_key_exists( $field['name'], $content['halo'] ?? [] ) ) {
                if ( ! empty( $field['required'] ) ) { return new WP_Error( 'nova_halo_required', 'Provide ' . $field['name'] . '.' ); }
                continue;
            }
            $value = self::decode( $content['halo'][$field['name']], $field );
            if ( is_wp_error( $value ) ) { return $value; }
            $fields[$field['name']] = $field + [ 'value' => $value ];
        }
        return $fields;
    }

    /** The structure preview exposes every supplied value. The native draft uses the site's theme. */
    public static function preview( array $fields ): string {
        $html = '';
        foreach ( $fields as $name => $field ) {
            $value = $field['value'];
            $html .= '<section class="nova-halo-field"><h3>' . esc_html( $field['label'] ?? $name ) . '</h3>';
            if ( 'array' === $field['type'] ) {
                foreach ( $value as $row ) {
                    $html .= '<div>';
                    foreach ( $row as $key => $part ) { $html .= '<p><strong>' . esc_html( $key ) . '</strong> ' . wp_kses_post( (string) $part ) . '</p>'; }
                    $html .= '</div>';
                }
            } elseif ( 'boolean' === $field['type'] ) { $html .= '<p>' . ( $value ? 'Enabled' : 'Disabled' ) . '</p>'; }
            elseif ( 'optional_second_image_id' === $name ) {
                $url = is_string( $value ) ? $value : ( $value ? wp_get_attachment_image_url( $value, 'large' ) : '' );
                $html .= $url ? '<img src="' . esc_url( $url ) . '" alt="">' : '<p>No second image</p>';
            } else { $html .= str_contains( $name, 'rich_text' ) ? wp_kses_post( $value ) : '<p>' . esc_html( $value ) . '</p>'; }
            $html .= '</section>';
        }
        return $html;
    }

    public static function write( int $id, array $fields ) {
        $route = rest_get_route_for_post( $id );
        if ( ! $route ) { return new WP_Error( 'nova_halo_route', 'The HALO destination has no native REST route.' ); }
        $request = new WP_REST_Request( 'POST', $route );
        $request->set_param( 'meta', $fields );
        $response = rest_do_request( $request );
        return $response->is_error() ? $response->as_error() : true;
    }
}
