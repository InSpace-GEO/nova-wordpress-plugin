<?php
/** Create a missing Weglot original, then commit the delivered locale on that source. */
defined( 'ABSPATH' ) || exit;

final class Nova_WordPress_Weglot_Source_Writer {
    public const FORMAT = 'nova.weglot-original/v1';
    private const WRITERS = [
        'stored_layout_v3' => 'Nova_Bridge_Suite_Stored_Layout_Writer',
        'owned_cpt_v3' => 'Nova_Bridge_Suite_Owned_CPT_Writer',
        'static_recipe_v1' => 'Nova_Bridge_Suite_Static_Term_Writer',
    ];

    private static function error( string $reason, string $message ): WP_Error {
        return new WP_Error( 'nova_weglot_source_' . $reason, $message, [ 'status' => 409 ] );
    }

    private static function digest( $value ): string {
        return hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $value ) );
    }

    public static function eligible( array $content ): bool {
        $provider = Nova_Bridge_Suite_Translation_Writer::provider( $content['language'] ?? '' );
        if ( ! is_array( $provider ) || 'weglot' !== $provider['provider'] ) { return false; }
        $family = $content['url_family'] ?? null;
        if ( ! $family ) { return Nova_Bridge_Suite_Publication_Policy::test_url( $content['url'] ?? null ); }
        return ! Nova_Bridge_Suite_Translation_Writer::same_language_slot( $family['primary_language'], $provider['source'] );
    }

    /** Resolve both URLs through Weglot, including its translated-slug table. */
    public static function descriptor( array $content, array $configuration ) {
        if ( ! self::eligible( $content ) || ! isset( self::WRITERS[$configuration['mode'] ?? ''] ) ||
            ( $configuration['site_id'] ?? '' ) !== ( $content['site_id'] ?? null ) ) {
            return self::error( 'scope', 'A missing original requires a supported, same-site Weglot delivery.' );
        }
        $provider = Nova_Bridge_Suite_Translation_Writer::provider( $content['language'] );
        $pinned = $configuration['local']['routing']['locale'] ?? '';
        if ( '' !== $pinned && strtolower( str_replace( '_', '-', $pinned ) ) !== strtolower( str_replace( '_', '-', $content['language'] ) ) ) {
            return self::error( 'locale', 'The delivery differs from the approved mapping language.' );
        }
        if ( 'draft' === ( $configuration['local']['routing']['publication'] ?? '' ) && ! Nova_Bridge_Suite_Publication_Policy::test_url( $content['url'] ) ) {
            return self::error( 'publication', 'The mapping requires drafts. Review its publication setting before creating a shared public original.' );
        }
        $family = $content['url_family'] ?? null;
        $input = $family['primary_url'] ?? $content['url'];
        $language = $family['primary_language'] ?? $content['language'];
        $valid = Nova_Bridge_Suite_Translation_Writer::delivery_url( $input, $language );
        // Older demo requests use the site's root path for the selected translated locale.
        if ( is_wp_error( $valid ) && ! $family && Nova_Bridge_Suite_Publication_Policy::test_url( $input ) ) {
            $valid = Nova_Bridge_Suite_Translation_Writer::delivery_url( $input, $provider['source'] );
        }
        if ( is_wp_error( $valid ) ) { return $valid; }
        $source = Nova_WordPress_Weglot::url( $valid, $provider['source'] );
        $source = Nova_Bridge_Suite_Translation_Writer::delivery_url( $source, $provider['source'] );
        if ( is_wp_error( $source ) ) { return $source; }
        $localized = Nova_Bridge_Suite_Translation_Writer::translated_url( $provider, $source );
        if ( is_wp_error( $localized ) ) { return $localized; }
        $intended = Nova_Bridge_Suite_Translation_Writer::delivery_url( $content['url'], $content['language'] );
        if ( is_wp_error( $intended ) && ! $family && Nova_Bridge_Suite_Publication_Policy::test_url( $content['url'] ) &&
            Nova_Bridge_Suite_Translation_Writer::delivery_url( $content['url'], $provider['source'] ) === $source ) {
            $intended = $localized['identity'];
        }
        if ( is_wp_error( $intended ) ) { return $intended; }
        if ( Nova_Bridge_Suite_Publication_Policy::test_url( $content['url'] ) && $intended !== $localized['identity'] ) {
            return self::error( 'url', 'The demo URL does not identify the resolved Weglot source.' );
        }
        $term = 'static_recipe_v1' === $configuration['mode'];
        if ( $term && ( $configuration['projection']['format'] ?? '' ) !== Nova_Bridge_Suite_Category_Recipe::FORMAT ) {
            return self::error( 'mapping', 'The original requires a supported scalar taxonomy mapping.' );
        }
        $type = $term ? Nova_Bridge_Suite_Category_Recipe::taxonomy( $configuration['record'] )
            : ( $configuration['local']['routing']['post_type'] ?? $configuration['local']['stored_layout']['post_type'] ?? '' );
        if ( '' === $type ) { return self::error( 'mapping', 'The mapping has no native source destination.' ); }
        $resource = ( $term ? 'taxonomy:' : 'post_type:' ) . $type;
        return [ 'provider' => $provider, 'resource' => $resource, 'type' => $type, 'term' => $term,
            'source_url' => $source, 'target_url' => $localized['identity'], 'site_id' => $content['site_id'],
            'family' => $family ? 'group:' . $family['url_group_id'] : 'demo:' . $source,
            'key' => Nova_WordPress_Environment::key( 'nova_weglot_source_' . hash( 'sha256', $content['site_id'] . ':' . $resource . ':' . $source ) ),
            'test' => Nova_Bridge_Suite_Publication_Policy::test_url( $content['url'] ) ];
    }

    private static function record( string $key ) {
        wp_cache_delete( $key, 'options' );
        return get_option( $key, null );
    }

    private static function save( string $key, array $value ) {
        if ( null === self::record( $key ) ) { add_option( $key, $value, '', false ); }
        else { update_option( $key, $value, false ); }
        return self::record( $key ) === $value ? true : self::error( 'journal', 'The source checkpoint could not be retained.' );
    }

    /** An absent source returns zero. A removed or conflicting retained source is an error. */
    public static function lookup( array $content, array $configuration ) {
        $source = self::descriptor( $content, $configuration );
        if ( is_wp_error( $source ) ) { return $source; }
        $record = self::record( $source['key'] );
        if ( null !== $record && ( ! is_array( $record ) || ( $record['family'] ?? '' ) !== $source['family'] ||
            ( $record['resource'] ?? '' ) !== $source['resource'] || ( $record['site_id'] ?? '' ) !== $source['site_id'] ) ) {
            return self::error( 'ownership', 'This original belongs to a different retained URL group.' );
        }
        if ( $record ) {
            $id = (int) ( $record['native_id'] ?? 0 );
        } else {
            $found = Nova_Bridge_Suite_Mapped_Content_Target::resolve( $source['source_url'], $source['resource'] );
            if ( is_wp_error( $found ) ) {
                if ( 'nova_mapped_target_not_found' !== $found->get_error_code() ) { return $found; }
                $draft = self::draft_source( $source );
                if ( is_wp_error( $draft ) || ! $draft ) { return $draft; }
                $found = [ 'reference_id' => $draft ];
            }
            $id = $found['reference_id'];
        }
        $valid = self::check_source( $source, $id );
        if ( is_wp_error( $valid ) ) { return $valid; }
        if ( ! $record && ! $source['term'] ) {
            foreach ( [ '_nova_stored_layout_owner', '_nova_owned_cpt_owner' ] as $key ) {
                $owner = get_post_meta( $id, $key, true );
                if ( $owner && ( ! is_array( $owner ) || ( $owner['site_id'] ?? '' ) !== $source['site_id'] ||
                    ( $owner['url_id'] ?? '' ) !== ( $content['url_family']['primary_url_id'] ?? $content['url_id'] ) ) ) {
                    return self::error( 'ownership', 'The existing original belongs to another publication.' );
                }
            }
        }
        return $id;
    }

    /** Unpublished WordPress permalinks can hide an existing demo from the URL resolver. */
    private static function draft_source( array $source ) {
        if ( $source['term'] || ! $source['test'] ) { return 0; }
        $path = parse_url( $source['source_url'], PHP_URL_PATH );
        $posts = get_posts( [ 'post_type' => $source['type'], 'post_status' => 'draft',
            'name' => rawurldecode( basename( rtrim( $path, '/' ) ) ), 'posts_per_page' => 101, 'suppress_filters' => true ] );
        if ( count( $posts ) > 100 ) { return self::error( 'ambiguous', 'Too many draft candidates share the demo path.' ); }
        $matches = [];
        foreach ( $posts as $post ) {
            $url = Nova_Bridge_Suite_Translation_Writer::source_permalink( $post );
            $url = is_string( $url ) ? Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $url ) : null;
            if ( is_array( $url ) && $url['identity'] === $source['source_url'] ) { $matches[] = (int) $post->ID; }
        }
        return count( $matches ) > 1 ? self::error( 'ambiguous', 'Several drafts claim the same demo URL.' ) : ( $matches[0] ?? 0 );
    }

    private static function check_source( array $source, int $id ) {
        if ( $source['term'] ) {
            $item = get_term( $id, $source['type'] );
            if ( ! $item || is_wp_error( $item ) || $item->taxonomy !== $source['type'] ) { return self::error( 'missing', 'The retained original term is unavailable.' ); }
            $url = get_term_link( $item );
            $can_edit = current_user_can( 'edit_term', $id );
        } else {
            $item = get_post( $id );
            if ( ! $item || $item->post_type !== $source['type'] || '' !== $item->post_password ||
                ( 'publish' !== $item->post_status && ! ( $source['test'] && 'draft' === $item->post_status ) ) ) {
                return self::error( 'missing', 'The retained original has an incompatible type or publication status.' );
            }
            $url = Nova_Bridge_Suite_Translation_Writer::source_permalink( $item );
            $can_edit = current_user_can( 'edit_post', $id );
        }
        $url = is_wp_error( $url ) ? $url : Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $url );
        if ( is_wp_error( $url ) || ( $url['identity'] !== $source['source_url'] &&
            ! Nova_Bridge_Suite_Mapped_Content_Target::same_native_url( $url['identity'], $source['source_url'], $source['resource'] ) ) ) {
            return self::error( 'url_changed', 'The original URL changed after source selection.' );
        }
        return $can_edit ? true : self::error( 'permission', 'The publishing user cannot edit the original.' );
    }

    public static function policy( array $content, array $configuration ) {
        $source = self::descriptor( $content, $configuration );
        if ( is_wp_error( $source ) ) { return $source; }
        $id = self::lookup( $content, $configuration );
        if ( is_wp_error( $id ) ) { return $id; }
        return [ 'reference' => [ 'reference_type' => $source['term'] ? 'term' : 'post', 'reference_id' => $id ],
            'routing' => [ 'operation' => $id ? 'update' : 'create', 'publication' => $source['term'] || ! $source['test'] ? 'publish' : 'draft' ] ];
    }

    /** Translate editorial slots together, leaving URL/email and scope bindings intact. */
    private static function translate( array $content, array $configuration, array $source ) {
        $template = $configuration['template'];
        $modern = 3 === ( $template['definition']['version'] ?? 0 );
        $values = $modern ? ( $content['destination_values'] ?? $content['content'] ) : $content['content'];
        $mapping = array_column( $template['mapping']['fields'] ?? [], 'source_field', 'field_id' );
        $slots = [];
        $html = '<html><body>';
        foreach ( $template['definition']['fields'] as $field ) {
            $key = $modern ? $field['id'] : ( $mapping[$field['id']] ?? '' );
            $kind = $field['value_type'] ?? $field['kind'] ?? '';
            if ( ! in_array( $kind, [ 'text', 'rich_text', 'html' ], true ) || in_array( $key, [ 'url', 'language', 'page_type_frontend' ], true ) || isset( $slots[$key] ) ) { continue; }
            $value = $values[$key] ?? null;
            if ( ! is_string( $value ) || '' === trim( $value ) ) { continue; }
            // Numeric/boolean companion fields may use a scalar-text transport.
            if ( preg_match( '/^(?:https?:\/\/|mailto:|tel:)/i', $value ) || is_numeric( $value ) || in_array( $value, [ 'true', 'false' ], true ) ) { continue; }
            if ( in_array( substr( ltrim( $value ), 0, 1 ), [ '{', '[' ], true ) && is_array( json_decode( $value, true ) ) ) {
                return self::error( 'structured_field', 'The original contains structured JSON in a text slot. Map its editorial fields separately before source creation.' );
            }
            $slots[$key] = [ 'id' => 'nova-source-' . count( $slots ), 'html' => in_array( $kind, [ 'rich_text', 'html' ], true ) ];
            $html .= '<div id="' . $slots[$key]['id'] . '">' . ( $slots[$key]['html'] ? $value : esc_html( $value ) ) . '</div>';
        }
        if ( ! $slots ) { return self::error( 'content', 'The mapping supplies no editorial text for the original.' ); }
        $html .= '</body></html>';
        try {
            $service = weglot_get_service( 'Parser_Service_Weglot' );
            $client = $service->get_client();
            $parser_class = class_exists( '\Weglot\Parser\TranslatingParser' ) ? '\Weglot\Parser\TranslatingParser' : '\Weglot\Parser\Parser';
            $parser = new $parser_class( $client, new \Weglot\Parser\ConfigProvider\ManualConfigProvider( $source['source_url'], 0 ) );
            $translated = $parser->translate( $html, $source['provider']['language'], $source['provider']['source'] );
            if ( ! is_string( $translated ) || '' === $translated ) { return self::error( 'translation', 'Weglot returned no original-language content.' ); }
        } catch ( Throwable $error ) {
            // SDK errors can contain authenticated URLs. Keep credentials out of journals and receipts.
            return self::error( 'translation', 'Weglot could not translate the original. Check its connection, quota and language pair, then retry.' );
        }
        $document = new DOMDocument();
        $before = libxml_use_internal_errors( true );
        try { $loaded = $document->loadHTML( '<?xml encoding="utf-8" ?>' . $translated, LIBXML_NONET ); }
        finally { libxml_clear_errors(); libxml_use_internal_errors( $before ); }
        if ( ! $loaded ) { return self::error( 'translation', 'The translated original is not readable HTML.' ); }
        $xpath = new DOMXPath( $document );
        foreach ( $slots as $key => $slot ) {
            $nodes = $xpath->query( '//*[@id="' . $slot['id'] . '"]' );
            if ( 1 !== $nodes->length ) { return self::error( 'translation', 'Weglot changed the mapped field boundaries.' ); }
            $node = $nodes->item( 0 );
            $value = $node->textContent;
            if ( $slot['html'] ) {
                $value = '';
                foreach ( $node->childNodes as $child ) { $value .= $document->saveHTML( $child ); }
            }
            if ( '' === trim( $value ) ) { return self::error( 'translation', 'Weglot returned an empty editorial field.' ); }
            $values[$key] = $value;
        }
        $content['content'] = $values;
        if ( $modern ) { $content['destination_values'] = $values; }
        return $content;
    }

    private static function operation( string $id, string $part ): string {
        $hex = hash( 'sha256', $id . ':' . $part );
        return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-4' . substr( $hex, 13, 3 ) . '-a' . substr( $hex, 17, 3 ) . '-' . substr( $hex, 20, 12 );
    }

    /** The site's administration locale can differ from Weglot's content original. */
    private static function in_original_language( array $source, callable $work ) {
        $locale = str_replace( '-', '_', $source['provider']['source'] );
        $filter = static function () use ( $locale ) { return $locale; };
        add_filter( 'locale', $filter, PHP_INT_MAX );
        add_filter( 'pre_option_WPLANG', $filter, PHP_INT_MAX );
        try { return $work(); }
        finally {
            remove_filter( 'locale', $filter, PHP_INT_MAX );
            remove_filter( 'pre_option_WPLANG', $filter, PHP_INT_MAX );
        }
    }

    public static function plan( array $content, array $configuration, array $context ) {
        $source = self::descriptor( $content, $configuration );
        if ( is_wp_error( $source ) ) { return $source; }
        $valid = $source['term'] ? Nova_Bridge_Suite_Static_Recipe_Adapter::validate_profile( $content, $configuration )
            : ( self::digest( $configuration['local'] ?? null ) === ( $configuration['profile_digest'] ?? null ) &&
                self::digest( $configuration['template'] ?? null ) === self::digest( $content['configuration'] ?? null ) );
        if ( is_wp_error( $valid ) ) { return $valid; }
        if ( true !== $valid ) { return self::error( 'mapping', 'The source requires the unchanged approved mapping and template.' ); }
        $id = self::lookup( $content, $configuration );
        if ( is_wp_error( $id ) ) { return $id; }
        if ( $id !== ( $context['target_post_id'] ?? null ) || ! Nova_Bridge_Suite_Posting_Protocol::uuid( $context['operation_id'] ?? '' ) ) {
            return self::error( 'target_changed', 'The original changed after policy selection.' );
        }
        $source_plan = null;
        $writer = self::WRITERS[$configuration['mode']];
        if ( ! $id ) {
            $original = $content;
            unset( $original['url_family'] );
            $original['url'] = $source['source_url'];
            $original['language'] = $source['provider']['source'];
            $original['url_id'] = $content['url_family']['primary_url_id'] ?? $content['url_id'];
            foreach ( [ 'language', 'url' ] as $field ) {
                if ( array_key_exists( $field, $original['content'] ) ) { $original['content'][$field] = $original[$field]; }
            }
            $original_config = $configuration;
            if ( isset( $original_config['local'] ) ) {
                $original_config['local']['routing']['locale'] = $original['language'];
                $original_config['local']['routing']['publication'] = $source['test'] ? 'draft' : 'publish';
                $original_config['profile_digest'] = self::digest( $original_config['local'] );
            }
            $native_policy = self::in_original_language( $source, static function () use ( $source, $original, $original_config, $writer ) {
                return $source['term'] ? Nova_Bridge_Suite_Static_Recipe_Adapter::policy( $original, $original_config ) : $writer::policy( $original, $original_config );
            } );
            if ( is_wp_error( $native_policy ) ) { return $native_policy; }
            if ( 'create' !== $native_policy['routing']['operation'] ) { return self::error( 'target_changed', 'The original acquired a native destination while planning.' ); }
            $original = self::translate( $original, $configuration, $source );
            if ( is_wp_error( $original ) ) { return $original; }
            $native_context = array_replace( $context, [ 'operation_id' => self::operation( $context['operation_id'], 'original' ),
                'business_result_id' => self::operation( $context['operation_id'], 'original' ), 'target_post_id' => 0,
                'operation' => 'create', 'desired_status' => $source['term'] || ! $source['test'] ? 'publish' : 'draft', 'language' => $original['language'], 'url_id' => $original['url_id'] ] );
            $source_plan = self::in_original_language( $source, static function () use ( $writer, $original, $original_config, $native_context ) {
                return $writer::plan( $original, $original_config, $native_context );
            } );
            if ( is_wp_error( $source_plan ) ) { return $source_plan; }
        }
        $content['url'] = $source['target_url'];
        $plan = [ 'format' => self::FORMAT, 'operation_id' => $context['operation_id'], 'source' => $source,
            'source_id' => $id, 'source_plan' => $source_plan, 'source_writer' => $writer,
            'content' => $content, 'configuration' => $configuration, 'context' => $context,
            'provider' => $source['provider'] + ( $source['term'] ? [ 'taxonomy' => $source['type'] ] : [] ),
            'target' => [ 'target_url' => $source['target_url'] ], 'desired_status' => $context['desired_status'] ];
        if ( strlen( wp_json_encode( $plan ) ) > 1572864 ) { return self::error( 'size', 'The original and translation exceed the recoverable journal size.' ); }
        $plan['digest'] = self::digest( $plan );
        return $plan;
    }

    private static function journal_key( string $operation ): string {
        return Nova_WordPress_Environment::key( 'nova_weglot_operation_' . hash( 'sha256', $operation ) );
    }

    private static function validate( array $plan, string $operation ) {
        $unsigned = $plan;
        unset( $unsigned['digest'] );
        if ( ( $plan['format'] ?? '' ) !== self::FORMAT || ( $plan['operation_id'] ?? '' ) !== $operation ||
            ! hash_equals( self::digest( $unsigned ), $plan['digest'] ?? '' ) ) { return self::error( 'plan', 'The original-language plan changed.' ); }
        $source = self::descriptor( $plan['content'], $plan['configuration'] );
        return ! is_wp_error( $source ) && $source === $plan['source'] ? true : self::error( 'configuration_changed', 'The Weglot source or language URL changed.' );
    }

    private static function translation_writer( array $plan ): string {
        return $plan['source']['term'] ? 'Nova_Bridge_Suite_Term_Translation_Writer' : 'Nova_Bridge_Suite_Translation_Writer';
    }

    private static function run( array $plan, string $operation, bool $write ) {
        $valid = self::validate( $plan, $operation );
        if ( is_wp_error( $valid ) ) { return $valid; }
        $key = self::journal_key( $operation );
        $journal = self::record( $key );
        if ( ! $journal ) {
            if ( ! $write ) { return [ 'state' => 'not_committed', 'no_native_commit' => true, 'safe_to_apply' => true ]; }
            $journal = [ 'digest' => $plan['digest'], 'source_id' => $plan['source_id'], 'translation_plan' => null ];
            $saved = self::save( $key, $journal );
            if ( is_wp_error( $saved ) ) { return $saved; }
        }
        if ( ( $journal['digest'] ?? '' ) !== $plan['digest'] ) { return self::error( 'journal', 'Another plan owns the original-language operation.' ); }
        $source = $plan['source'];
        $id_key = $source['term'] ? 'term_id' : 'post_id';
        if ( ! $journal['source_id'] ) {
            $writer = $plan['source_writer'];
            $native = $plan['source_plan'];
            $result = self::in_original_language( $source, static function () use ( $writer, $native ) {
                return $writer::recover( $native, $native['operation_id'] );
            } );
            if ( is_array( $result ) && 'not_committed' === ( $result['state'] ?? '' ) ) {
                if ( ! $write || empty( $result['safe_to_apply'] ) ) { return $result; }
                $result = self::in_original_language( $source, static function () use ( $writer, $native ) {
                    return $writer::apply( $native, $native['operation_id'] );
                } );
            }
            if ( is_wp_error( $result ) ) { return $result; }
            if ( is_callable( [ $writer, 'finish' ] ) ) {
                $result = self::in_original_language( $source, static function () use ( $writer, $native, $result ) {
                    return $writer::finish( $native, $result );
                } );
            }
            if ( is_wp_error( $result ) ) { return $result; }
            $verified = self::in_original_language( $source, static function () use ( $writer, $native, $result ) {
                return $writer::verify( $native, $result );
            } );
            if ( is_wp_error( $verified ) ) { return $verified; }
            $journal['source_id'] = (int) ( $verified[$id_key] ?? 0 );
            if ( ! $journal['source_id'] ) { return self::error( 'readback', 'The original has no verified native identity.' ); }
            $saved = self::save( $key, $journal );
            if ( is_wp_error( $saved ) ) { return $saved; }
        }
        $valid = self::check_source( $source, $journal['source_id'] );
        if ( is_wp_error( $valid ) ) { return $valid; }
        $registered = self::record( $source['key'] );
        $association = array_intersect_key( $source, array_flip( [ 'site_id', 'family', 'resource', 'source_url' ] ) ) + [ 'native_id' => $journal['source_id'] ];
        if ( null !== $registered && $registered !== $association ) { return self::error( 'ownership', 'Another original owns this source URL.' ); }
        if ( null === $registered ) {
            $saved = self::save( $source['key'], $association );
            if ( is_wp_error( $saved ) ) { return $saved; }
        }
        $translator = self::translation_writer( $plan );
        $mutated = null !== $plan['source_plan'];
        if ( ! $journal['translation_plan'] ) {
            if ( ! $write ) { return [ 'state' => 'not_committed', 'no_native_commit' => ! $mutated, 'safe_to_apply' => true, $id_key => $journal['source_id'] ]; }
            $context = array_replace( $plan['context'], [ 'target_post_id' => $journal['source_id'], 'operation' => 'update',
                'desired_status' => $source['term'] ? 'publish' : get_post_status( $journal['source_id'] ) ] );
            $localized = $translator::plan( $plan['content'], $plan['configuration'], $context );
            if ( is_wp_error( $localized ) ) { return $localized; }
            $journal['translation_plan'] = $localized;
            $saved = self::save( $key, $journal );
            if ( is_wp_error( $saved ) ) { return $saved; }
        }
        $result = $translator::recover( $journal['translation_plan'], $operation );
        if ( is_array( $result ) && 'not_committed' === ( $result['state'] ?? '' ) ) {
            if ( ! $write || empty( $result['safe_to_apply'] ) ) {
                $result['no_native_commit'] = ! $mutated && ! empty( $result['no_native_commit'] );
                return $result + [ $id_key => $journal['source_id'] ];
            }
            $result = $translator::apply( $journal['translation_plan'], $operation );
        }
        return $result;
    }

    public static function apply( array $plan, string $operation ) { return self::run( $plan, $operation, true ); }
    public static function recover( array $plan, string $operation ) { return self::run( $plan, $operation, false ); }
    public static function verify( array $plan, array $result, bool $readonly = false ) {
        $valid = self::validate( $plan, $plan['operation_id'] ?? '' );
        if ( is_wp_error( $valid ) ) { return $valid; }
        $journal = self::record( self::journal_key( $plan['operation_id'] ) );
        if ( empty( $journal['translation_plan'] ) ) { return self::error( 'readback', 'The original-language operation has no committed translation.' ); }
        $translator = self::translation_writer( $plan );
        return $translator::verify( $journal['translation_plan'], $result, $readonly );
    }
}
