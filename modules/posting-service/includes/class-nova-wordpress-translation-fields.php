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
        $compiled = Nova_Bridge_Suite_Stored_Layout::compile( Nova_Bridge_Suite_Writing_Adapter::adaptation_draft( $local ), $values, $content['site_id'] . ':' . $content['url_id'], '', 'weglot' === $target['provider'] );
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
        if ( 'weglot' === $target['provider'] ) {
            $previous = Nova_WordPress_Weglot::get( $target['post_id'], $target['language'] ) ?? [];
            $ownership = [ 'format' => 'nova.owned-output/v1', 'elements' => [] ];
            if ( is_array( $compiled['ownership_ranges'] ?? null ) && isset( $fields['content'] ) ) {
                $ownership['content'] = [ 'hash' => hash( 'sha256', $fields['content'] ), 'ranges' => $compiled['ownership_ranges'] ];
            }
            if ( ! empty( $compiled['identity_map'] ) ) {
                $tree = $payload['elementor_data'];
                $old_tree = json_decode( $previous['meta']['_elementor_data'] ?? '[]', true );
                $replaced = [];
                foreach ( $bindings as $path => $binding ) {
                    $pointer = $binding['target'] ?? '';
                    $split = strpos( $pointer, '/settings/' );
                    if ( false === $split ) { continue; }
                    $node = Nova_Bridge_Suite_Recipe_Engine::get( $local['stored_layout']['blueprint'], substr( $pointer, 0, $split ), $found );
                    $id = $found ? ( $compiled['identity_map'][$node['id'] ?? ''] ?? null ) : null;
                    if ( ! is_string( $id ) ) { continue; }
                    $parts = array_map( static function ( $part ) { return str_replace( [ '~1', '~0' ], [ '/', '~' ], $part ); }, explode( '/', substr( $pointer, $split + 10 ) ) );
                    $actual = self::element_setting( $tree, $id, $parts );
                    if ( 'protected' !== $binding['mode'] ) { $replaced[$id][wp_json_encode( $parts )] = true; }
                    if ( 'protected' === $binding['mode'] ) {
                        $preserved = $previous['ownership']['elements'][$id] ?? [];
                        $old_value = self::element_setting( $old_tree ?? [], $id, $parts );
                        if ( ! isset( $previous['ownership'] ) && is_string( $old_value ) && $old_value !== $actual ) {
                            $preserved[] = [ 'path' => $parts, 'type' => $binding['format'] ?? 'text', 'hash' => hash( 'sha256', $old_value ) ];
                        }
                        foreach ( $preserved as $kept ) {
                            if ( $kept['path'] !== $parts ) { continue; }
                            $value = self::element_setting( $old_tree ?? [], $id, $parts );
                            if ( is_string( $value ) && hash( 'sha256', $value ) === $kept['hash'] ) {
                                self::replace_element_setting( $tree, $id, $parts, $value );
                                $ownership['elements'][$id][] = $kept;
                            }
                        }
                    } elseif ( isset( $values[Nova_Bridge_Suite_Writing_Adapter::field_id( $path )] ) && is_string( $actual ) ) {
                        $ownership['elements'][$id][] = [ 'path' => $parts, 'type' => $binding['format'] ?? 'text', 'hash' => hash( 'sha256', $actual ) ];
                    }
                }
                foreach ( $previous['ownership']['elements'] ?? [] as $id => $kept_fields ) {
                    foreach ( $kept_fields as $kept ) {
                        if ( isset( $replaced[$id][wp_json_encode( $kept['path'] )] ) ) { continue; }
                        $value = self::element_setting( $old_tree ?? [], $id, $kept['path'] );
                        if ( is_string( $value ) && hash( 'sha256', $value ) === $kept['hash'] && null !== self::element_setting( $tree, $id, $kept['path'] ) ) {
                            self::replace_element_setting( $tree, $id, $kept['path'], $value );
                            if ( ! in_array( $kept, $ownership['elements'][$id] ?? [], true ) ) { $ownership['elements'][$id][] = $kept; }
                        }
                    }
                }
                $fields['meta']['_elementor_data'] = wp_json_encode( $tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            }
            if ( 'gutenberg' === $builder && isset( $compiled['compiled_blueprint'] ) ) {
                $blueprint = $compiled['compiled_blueprint']; $block_fields = []; $replaced = [];
                $adapter = Nova_Bridge_Suite_Recipe_Builders::adapter( 'gutenberg' );
                foreach ( $bindings as $path => $binding ) {
                    if ( 'recipe' !== $binding['kind'] ) { continue; }
                    if ( 'protected' !== $binding['mode'] ) { $replaced[$binding['target']] = true; }
                    $value = $values[Nova_Bridge_Suite_Writing_Adapter::field_id( $path )] ?? null;
                    if ( 'protected' === $binding['mode'] ) {
                        foreach ( $previous['ownership']['blocks']['fields'] ?? [] as $kept ) {
                            if ( $kept['binding']['target'] !== $binding['target'] ) { continue; }
                            $value = $kept['value'];
                            $blueprint = $adapter->bind( $blueprint, $binding, $value );
                            if ( is_wp_error( $blueprint ) ) { return $blueprint; }
                        }
                    }
                    if ( is_string( $value ) ) { $block_fields[] = [ 'binding' => $binding, 'value' => $value ]; }
                }
                foreach ( $previous['ownership']['blocks']['fields'] ?? [] as $kept ) {
                    if ( isset( $replaced[$kept['binding']['target']] ) ) { continue; }
                    Nova_Bridge_Suite_Recipe_Engine::get( $blueprint, $kept['binding']['target'], $found );
                    if ( ! $found ) { continue; }
                    $retained = $adapter->bind( $blueprint, $kept['binding'], $kept['value'] );
                    if ( is_wp_error( $retained ) ) { continue; }
                    $blueprint = $retained;
                    if ( ! in_array( $kept, $block_fields, true ) ) { $block_fields[] = $kept; }
                }
                $rendered = $adapter->finalize( $blueprint, $content['site_id'] . ':' . $content['url_id'] );
                if ( is_wp_error( $rendered ) ) { return $rendered; }
                $fields['content'] = wp_kses_post( do_blocks( $rendered['writer_payload']['native_document'] ) );
                $ownership['blocks'] = [ 'blueprint' => $blueprint, 'fields' => $block_fields, 'hash' => hash( 'sha256', $fields['content'] ) ];
            }
            $fields['ownership'] = $ownership;
            $fields = self::preserve_owned_content( $fields, $previous );
        }
        return $fields;
    }

    private static function element_setting( array $tree, string $id, array $path ) {
        foreach ( $tree as $node ) {
            if ( ( $node['id'] ?? '' ) === $id ) {
                $value = $node['settings'] ?? [];
                foreach ( $path as $part ) { $value = is_array( $value ) ? ( $value[$part] ?? null ) : null; }
                return $value;
            }
            $value = self::element_setting( $node['elements'] ?? [], $id, $path );
            if ( null !== $value ) { return $value; }
        }
        return null;
    }

    private static function replace_element_setting( array &$tree, string $id, array $path, string $value ): void {
        foreach ( $tree as &$node ) {
            if ( ( $node['id'] ?? '' ) === $id ) {
                $setting = &$node['settings'];
                foreach ( $path as $part ) { $setting = &$setting[$part]; }
                $setting = $value;
                return;
            }
            if ( isset( $node['elements'] ) ) { self::replace_element_setting( $node['elements'], $id, $path, $value ); }
        }
    }

    private static function preserve_owned_content( array $fields, array $previous ): array {
        $before = $previous['ownership']['content'] ?? [];
        $after = $fields['ownership']['content'] ?? [];
        if ( ! $before || ! $after || ( $before['hash'] ?? '' ) !== hash( 'sha256', $previous['content'] ?? '' ) ) { return $fields; }
        $old = [];
        foreach ( $before['ranges'] ?? [] as $range ) { if ( ! empty( $range['owned'] ) ) { $old[$range['path']] = $range; } }
        $content = ''; $cursor = 0;
        foreach ( $after['ranges'] as &$range ) {
            $start = $range['offset']; $length = $range['length'];
            $content .= substr( $fields['content'], $cursor, $start - $cursor );
            $fragment = substr( $fields['content'], $start, $length );
            if ( ! empty( $range['preserve'] ) && isset( $old[$range['path']] ) ) {
                $kept = $old[$range['path']];
                $fragment = substr( $previous['content'], $kept['offset'], $kept['length'] );
                $range['owned'] = true;
            }
            $range['offset'] = strlen( $content ); $range['length'] = strlen( $fragment );
            $content .= $fragment; $cursor = $start + $length;
        }
        unset( $range );
        $fields['content'] = $content . substr( $fields['content'], $cursor );
        $after['hash'] = hash( 'sha256', $fields['content'] );
        $fields['ownership']['content'] = $after;
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
