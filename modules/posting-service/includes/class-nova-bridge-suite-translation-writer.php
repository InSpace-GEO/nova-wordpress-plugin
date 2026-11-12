<?php
/** A TranslatePress or Weglot translation shares a native post with the original. */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-nova-bridge-suite-publication-policy.php';
require_once __DIR__ . '/class-nova-bridge-suite-native-lock.php';
require_once __DIR__ . '/class-nova-wordpress-delivery-host.php';
require_once __DIR__ . '/class-nova-wordpress-weglot.php';
require_once __DIR__ . '/class-nova-wordpress-translatepress.php';
require_once __DIR__ . '/class-nova-bridge-suite-stored-custom-fields.php';

final class Nova_Bridge_Suite_Translation_Writer {
    public const FORMAT = 'nova.localized-source/v1';

    private static function error( string $reason, string $message ) {
        return new WP_Error( 'nova_translation_' . $reason, $message, [ 'status' => 409 ] );
    }

    private static function norm( string $locale ): string { return strtolower( str_replace( '_', '-', $locale ) ); }

    public static function match_language( string $input, array $languages ): string {
        $input = self::norm( $input ); $exact = []; $primary = [];
        if ( ! preg_match( '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/D', $input ) ) { return ''; }
        foreach ( $languages as $row ) {
            $code = self::norm( $row['code'] ); $alias = self::norm( (string) ( $row['alias'] ?? '' ) );
            if ( $input === $code || $input === $alias ) { $exact[] = $row['code']; }
            if ( strtok( $input, '-' ) === strtok( $code, '-' ) && ( false === strpos( $input, '-' ) || false === strpos( $code, '-' ) ) ) { $primary[] = $row['code']; }
        }
        $matches = array_unique( $exact ?: $primary );
        return count( $matches ) === 1 ? reset( $matches ) : '';
    }

    private static function selection( string $language, bool $include_languages = false ) {
        $trp = class_exists( 'TRP_Translate_Press' );
        $wg = function_exists( 'weglot_get_service' ) || defined( 'WEGLOT_VERSION' );
        if ( ! $trp && ! $wg ) { return null; }
        if ( ( $trp && $wg ) || defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'pll_get_post_language' ) ) {
            return self::error( 'provider_conflict', 'Multiple translation plugins claim the delivery language.' );
        }
        if ( $trp ) {
            $settings = Nova_WordPress_TranslatePress::settings(); $source = $settings['default-language'] ?? ''; $languages = [];
            foreach ( $settings['publish-languages'] ?? [] as $code ) {
                $languages[] = [ 'code' => $code, 'alias' => $settings['url-slugs'][$code] ?? $code ];
            }
        } else {
            $catalog = Nova_WordPress_Weglot::catalog(); $source = $catalog['source'] ?? ''; $languages = $catalog['languages'] ?? [];
        }
        $code = self::match_language( $language, $languages );
        if ( ! $source || ! $code ) { return self::error( 'language_unknown', 'No single active provider language matches the requested locale.' ); }
        $context = [ 'provider' => $trp ? 'translatepress' : 'weglot', 'source' => $source, 'language' => $code ];
        if ( $include_languages ) { $context['languages'] = array_column( $languages, 'code' ); }
        return $context;
    }

    public static function provider( $language ) {
        $context = self::selection( (string) $language );
        return is_array( $context ) && $context['source'] === $context['language'] ? null : $context;
    }

    /** Resolve aliases through the installed provider without collapsing regional slots. */
    public static function same_language_slot( string $first, string $second ): bool {
        $a = self::selection( $first ); $b = self::selection( $second );
        return is_array( $a ) && is_array( $b ) && $a['provider'] === $b['provider'] && $a['language'] === $b['language'];
    }

    public static function translated_url( array $provider, string $original ) {
        $url = 'weglot' === $provider['provider'] ? Nova_WordPress_Weglot::url( $original, $provider['language'] )
            : Nova_WordPress_TranslatePress::url( $original, $provider['language'] );
        if ( ! is_string( $url ) || '' === $url ) { return self::error( 'url', 'The translation provider did not return a localized URL.' ); }
        return Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $url );
    }

    public static function language_home( $locale ) {
        $context = self::selection( (string) $locale );
        if ( ! is_array( $context ) ) { return $context; }
        $url = self::translated_url( $context, trailingslashit( get_option( 'home', home_url( '/' ) ) ) );
        return is_wp_error( $url ) ? $url : $url['identity'];
    }

    public static function delivery_url( $url, $locale ) {
        $context = self::selection( (string) $locale, true );
        if ( ! is_array( $context ) ) { return $context; }
        $home = trailingslashit( get_option( 'home', home_url( '/' ) ) );
        $base = self::translated_url( $context, $home );
        if ( is_wp_error( $base ) ) { return $base; }
        if ( ! is_string( $url ) || '' === $url || 0 === strpos( $url, '//' ) || false !== strpbrk( $url, '?#' ) ) { return self::error( 'url', 'A canonical translated URL is required.' ); }
        if ( ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
            $site = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( get_option( 'home', home_url( '/' ) ) );
            if ( is_wp_error( $site ) ) { return $site; }
            $url = $base['origin'] . ( '/' === $url[0] ? $url : rtrim( $site['path'], '/' ) . '/' . $url );
        }
        $target = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $url );
        if ( is_wp_error( $target ) ) { return $target; }
        $input_origin = $target['origin'];
        $native_url = Nova_WordPress_Delivery_Host::canonicalize( $url, $base['origin'] );
        if ( $native_url !== $url ) {
            $target = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $native_url );
            if ( is_wp_error( $target ) ) { return $target; }
        }
        if ( $target['origin'] !== $base['origin'] || ( '/' !== $base['path'] && $target['path'] !== $base['path'] && 0 !== strpos( $target['path'], rtrim( $base['path'], '/' ) . '/' ) ) ) {
            return self::error( 'url', 'The requested URL does not belong to the configured language.' );
        }
        // A default root can contain another language's prefix. The most specific native home owns the URL.
        foreach ( $context['languages'] as $code ) {
            if ( $code === $context['language'] ) { continue; }
            $sibling = self::translated_url( array_replace( $context, [ 'language' => $code ] ), $home );
            if ( is_wp_error( $sibling ) ) { return $sibling; }
            if ( $native_url !== $url && $sibling['origin'] === $input_origin ) { return self::error( 'url', 'A different configured language owns the requested host.' ); }
            if ( $target['origin'] === $sibling['origin'] && strlen( $sibling['path'] ) >= strlen( $base['path'] ) &&
                ( $target['path'] === $sibling['path'] || 0 === strpos( $target['path'], rtrim( $sibling['path'], '/' ) . '/' ) ) ) {
                return self::error( 'url', 'A different configured language owns the requested URL.' );
            }
        }
        return $target['identity'];
    }

    private static function target( array $content, array $configuration ) {
        $pinned = $configuration['local']['routing']['locale'] ?? '';
        if ( '' !== $pinned && self::norm( $pinned ) !== self::norm( $content['language'] ?? '' ) ) {
            return self::error( 'locale', 'The delivery language differs from the approved mapping.' );
        }
        $context = self::provider( $content['language'] ?? '' );
        if ( ! is_array( $context ) ) { return $context ?: self::error( 'provider', 'The configured translation provider is unavailable.' ); }
        $source = Nova_Bridge_Suite_Translation_Source::post_id( $content, $configuration, $context['source'] );
        if ( is_wp_error( $source ) ) { return $source; }
        $post = get_post( $source );
        $type = $configuration['local']['routing']['post_type'] ?? $configuration['local']['stored_layout']['post_type'] ?? '';
        if ( ! $post || $post->post_type !== $type || 'publish' !== $post->post_status || '' !== $post->post_password ) {
            return self::error( 'source_pending', 'The mapped original must exist as a published, publicly readable post.' );
        }
        if ( ! current_user_can( 'edit_post', $post->ID ) || ( 'translatepress' === $context['provider'] && ! current_user_can( apply_filters( 'trp_translating_capability', 'manage_options' ) ) ) ) {
            return self::error( 'permission', 'The publishing user cannot translate this original.' );
        }
        if ( 'translatepress' === $context['provider'] && ! Nova_WordPress_TranslatePress::translation_allowed( $post ) ) {
            return self::error( 'url', 'TranslatePress excludes this source URL from translation.' );
        }
        $url = self::delivery_url( $content['url'] ?? '', $content['language'] );
        if ( is_wp_error( $url ) ) { return $url; }
        $canonical = self::translated_url( $context, get_permalink( $post->ID ) );
        if ( is_wp_error( $canonical ) ) { return $canonical; }
        // The verified original identifies the page. Keep its provider URL in the
        // native plan; the immutable delivery URL remains available in the receipt.
        $url = self::delivery_url( $canonical['identity'], $content['language'] );
        if ( is_wp_error( $url ) ) { return $url; }
        return $context + [ 'post_id' => (int) $post->ID, 'post_type' => $type, 'url' => $url ];
    }

    public static function policy( array $content, array $configuration ) {
        if ( Nova_Bridge_Suite_Publication_Policy::test_url( $content['url'] ?? null ) ) { return Nova_Bridge_Suite_Publication_Policy::unsupported(); }
        $target = self::target( $content, $configuration );
        if ( is_wp_error( $target ) ) { return $target; }
        if ( 'draft' === ( $configuration['local']['routing']['publication'] ?? '' ) ) { return self::error( 'status', 'A translated view of a published source cannot be saved as a separate draft.' ); }
        return [ 'mode' => 'dictionary_translation', 'reference' => [ 'reference_type' => 'post', 'reference_id' => $target['post_id'] ],
            'routing' => [ 'operation' => 'update', 'publication' => 'preserve' ] ];
    }

    private static function digest( $value ): string { return hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $value ) ); }

    public static function plan( array $content, array $configuration, array $context ) {
        if ( Nova_Bridge_Suite_Publication_Policy::test_url( $content['url'] ?? null ) ) { return Nova_Bridge_Suite_Publication_Policy::unsupported(); }
        $target = self::target( $content, $configuration );
        if ( is_wp_error( $target ) ) { return $target; }
        if ( $target['post_id'] !== ( $context['target_post_id'] ?? null ) || 'publish' !== ( $context['desired_status'] ?? '' ) ||
            $configuration['site_id'] !== $content['site_id'] || self::digest( $configuration['local'] ) !== $configuration['profile_digest'] ||
            self::digest( $configuration['template'] ) !== self::digest( $content['configuration'] ?? null ) || ! Nova_Bridge_Suite_Posting_Protocol::uuid( $context['operation_id'] ?? '' ) ) {
            return self::error( 'profile', 'The original or approved mapping has changed.' );
        }
        $fields = Nova_WordPress_Translation_Fields::compile( $content, $configuration, $target );
        if ( is_wp_error( $fields ) ) { return $fields; }
        $media = $fields['native_media'] ?? [];
        unset( $fields['native_media'] );
        $previous = self::record( $target );
        if ( $previous && ( 'committed' !== ( $previous['state'] ?? '' ) || ( $previous['content_item'] ?? '' ) !== $content['content_item_id'] || ( $previous['version'] ?? 0 ) >= $content['version_number'] ) ) {
            return self::error( 'version', 'A newer or unfinished localization already owns this source and language.' );
        }
        $plan = [ 'format' => self::FORMAT, 'target_post_id' => $target['post_id'], 'target' => [ 'target_url' => $target['url'] ],
            'operation_id' => $context['operation_id'], 'desired_status' => 'publish', 'provider' => $target, 'fields' => $fields,
            'content_item' => $content['content_item_id'], 'version' => $content['version_number'], 'previous' => self::digest( $previous ) ];
        if ( $media ) { $plan['media'] = $media; }
        $plan['source_digest'] = self::source_digest( $target, $fields, $media );
        if ( is_wp_error( $plan['source_digest'] ) ) { return $plan['source_digest']; }
        if ( strlen( wp_json_encode( $plan ) ) > 780000 ) { return self::error( 'size', 'The localization is too large for the WordPress delivery journal.' ); }
        $plan['digest'] = self::digest( $plan );
        return $plan;
    }

    private static function key( array $target ): string { return '_nova_localized_' . hash( 'sha256', $target['provider'] . ':' . $target['language'] ); }
    private static function record( array $target ) { return get_post_meta( $target['post_id'], self::key( $target ), true ); }

    public static function localized_fields( int $id, string $provider, string $language ): array {
        $record = self::record( [ 'post_id' => $id, 'provider' => $provider, 'language' => $language ] );
        return $record['mapped_fields'] ?? [];
    }

    private static function source_digest( array $target, array $fields, array $media = [] ) {
        $id = $target['post_id']; $post = get_post( $id );
        $data = [ $post->post_title, $post->post_excerpt, $post->post_content ];
        foreach ( array_keys( $fields['custom_fields'] ?? $fields['meta'] ?? [] ) as $name ) { $data[$name] = get_post_meta( $id, $name, true ); }
        if ( $media ) {
            require_once __DIR__ . '/class-nova-bridge-suite-native-media.php';
            if ( Nova_Bridge_Suite_Native_Media::has_featured( $media['payload'] ) ) { $data['_thumbnail_id'] = get_post_meta( $id, '_thumbnail_id', true ); }
        }
        if ( ! empty( $fields['mapped_fields'] ) ) {
            $source = Nova_Bridge_Suite_Stored_Custom_Fields::read( $target['post_type'], $id, (string) get_page_template_slug( $id ), $fields['mapped_fields'] );
            if ( is_wp_error( $source ) ) { return $source; }
            $data['mapped_fields'] = $source;
        }
        return self::digest( $data );
    }
    private static function save( array $target, array $record ) {
        update_post_meta( $target['post_id'], self::key( $target ), wp_slash( $record ) );
        return self::record( $target ) === $record ? true : self::error( 'journal', 'The localization checkpoint could not be verified.' );
    }

    private static function check_plan( array $plan, string $op ): bool {
        $digest = $plan['digest'] ?? ''; unset( $plan['digest'] );
        return ( $plan['format'] ?? '' ) === self::FORMAT && ( $plan['operation_id'] ?? '' ) === $op && self::digest( $plan ) === $digest;
    }

    private static function check_source( array $target, bool $write ) {
        $post = get_post( $target['post_id'] );
        $current = self::provider( $target['language'] );
        if ( ! $post || ! is_array( $current ) || $current['provider'] !== $target['provider'] || $current['source'] !== $target['source'] || $post->post_type !== $target['post_type'] ||
            'publish' !== $post->post_status || '' !== $post->post_password || ( $write && ! current_user_can( 'edit_post', $post->ID ) ) ) {
            return self::error( 'source_changed', 'The verified source post or its language configuration has changed.' );
        }
        if ( 'translatepress' === $target['provider'] && ( ! Nova_WordPress_TranslatePress::translation_allowed( $post ) || ( $write && ! current_user_can( apply_filters( 'trp_translating_capability', 'manage_options' ) ) ) ) ) {
            return self::error( 'permission', 'TranslatePress cannot publish a translation for this original.' );
        }
        $url = self::translated_url( $target, get_permalink( $post->ID ) );
        return ! is_wp_error( $url ) && $url['identity'] === $target['url'] ? true : self::error( 'url', 'The provider no longer resolves this original to the retained localized URL.' );
    }

    private static function evidence( array $plan, array $ids, array $mapped ) {
        $t = $plan['provider'];
        $fields = self::resolved_fields( $plan, false );
        if ( is_wp_error( $fields ) ) { return $fields; }
        $proof = [];
        if ( isset( $fields['mapped_fields'] ) ) {
            foreach ( $fields['mapped_fields'] as $name => $expected ) {
                if ( ( $mapped[$name] ?? null ) !== $expected ) { return self::error( 'readback', 'The saved localized custom field differs from the approved mapping.' ); }
            }
            $proof['mapped_fields'] = $fields['mapped_fields'];
            unset( $fields['mapped_fields'] );
        }
        if ( 'translatepress' === $t['provider'] ) {
            if ( ! $fields ) { return $proof; }
            $rows = $ids ? Nova_WordPress_TranslatePress::evidence( $t['post_id'], $t['language'], $ids ) : self::error( 'readback', 'No TranslatePress entries were witnessed.' );
            return is_wp_error( $rows ) ? $rows : $rows + $proof;
        }
        $stored = Nova_WordPress_Weglot::get( $t['post_id'], $t['language'] );
        foreach ( $fields as $key => $value ) {
            if ( 'meta' === $key ) {
                foreach ( $value as $name => $expected ) { if ( ( $stored['meta'][$name] ?? null ) !== $expected ) { return self::error( 'readback', 'A localized Weglot field differs from the saved mapping.' ); } }
            } elseif ( ( $stored[$key] ?? null ) !== $value ) { return self::error( 'readback', 'The localized Weglot copy differs from the saved mapping.' ); }
        }
        return $fields + ( isset( $plan['fields']['mapped_fields'] ) ? [ 'mapped_fields' => $plan['fields']['mapped_fields'] ] : [] );
    }

    private static function resolved_fields( array $plan, bool $allow_import ) {
        $fields = $plan['fields'];
        if ( empty( $plan['media'] ) ) { return $fields; }
        require_once __DIR__ . '/class-nova-bridge-suite-native-media.php';
        $media = $plan['media'];
        $resolved = Nova_Bridge_Suite_Native_Media::resolve( $media['builder'], $plan['provider']['post_id'], $media['payload'], $plan['operation_id'], $allow_import );
        if ( is_wp_error( $resolved ) ) { return $resolved; }
        if ( isset( $fields['meta']['_elementor_data'] ) ) {
            $document = wp_json_encode( $resolved['payload']['elementor_data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            if ( false === $document ) { return self::error( 'field_unsupported', 'The localized Elementor document could not be encoded.' ); }
            $fields['meta']['_elementor_data'] = $document;
        }
        if ( array_key_exists( 'featured', $resolved ) ) { $fields['meta']['_thumbnail_id'] = (string) $resolved['featured']; }
        return $fields;
    }

    public static function apply( array $plan, string $op ) {
        if ( ! self::check_plan( $plan, $op ) ) { return self::error( 'plan', 'The retained localization plan has changed.' ); }
        // Independent deliveries share one post and TranslatePress also shares dictionary rows across posts.
        global $wpdb;
        if ( empty( $wpdb->posts ) ) { return self::error( 'storage', 'The native publication lock is unavailable.' ); }
        $target = $plan['provider'];
        $scope = 'translatepress' === $target['provider'] ? $target['provider'] : 'weglot:' . $target['post_id'];
        $lock = Nova_Bridge_Suite_Native_Lock::name( $wpdb, 'nova-localize:', $wpdb->posts . ':' . $scope, 48 );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { return self::error( 'busy', 'Another native translation is being saved. Retry this delivery shortly.' ); }
        try { return self::apply_locked( $plan, $op ); }
        finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }

    private static function apply_locked( array $plan, string $op ) {
        $target = $plan['provider']; $allowed = self::check_source( $target, true );
        if ( is_wp_error( $allowed ) ) { return $allowed; }
        $record = self::record( $target );
        if ( ( $record['operation_id'] ?? null ) === $op && 'committed' === $record['state'] ) { return self::verify( $plan, [] ); }
        if ( self::source_digest( $target, $plan['fields'], $plan['media'] ?? [] ) !== $plan['source_digest'] ) { return self::error( 'source_changed', 'The original changed after translation planning.' ); }
        if ( ( $record['operation_id'] ?? null ) !== $op ) {
            if ( self::digest( $record ) !== $plan['previous'] ) { return self::error( 'version', 'Another localization has acquired this source.' ); }
            $record = [ 'state' => 'saving', 'operation_id' => $op, 'digest' => $plan['digest'], 'version' => $plan['version'], 'content_item' => $plan['content_item'],
                'mapped_fields' => $record['mapped_fields'] ?? [] ];
            $saved = self::save( $target, $record ); if ( is_wp_error( $saved ) ) { return $saved; }
        }
        if ( $record['digest'] !== $plan['digest'] ) { return self::error( 'plan', 'This localization operation belongs to another plan.' ); }
        $fields = self::resolved_fields( $plan, true );
        if ( is_wp_error( $fields ) ) { return $fields; }
        $result = [];
        $record['mapped_fields'] = array_replace( $record['mapped_fields'] ?? [], $fields['mapped_fields'] ?? [] );
        unset( $fields['mapped_fields'] );
        if ( $fields ) {
            $result = 'weglot' === $target['provider'] ? Nova_WordPress_Weglot::apply( $target['post_id'], $target['language'], $fields )
                : Nova_WordPress_TranslatePress::save( $target['post_id'], [ 'language' => $target['language'] ] + $fields );
            if ( is_wp_error( $result ) ) { return $result; }
        }
        $record['ids'] = is_array( $result ) ? $result : [];
        $proof = self::evidence( $plan, $record['ids'], $record['mapped_fields'] ); if ( is_wp_error( $proof ) ) { return $proof; }
        if ( self::source_digest( $target, $plan['fields'], $plan['media'] ?? [] ) !== $plan['source_digest'] ) { return self::error( 'source_changed', 'The original changed during translation.' ); }
        $record['proof'] = self::digest( $proof ); $record['source_digest'] = $plan['source_digest']; $record['state'] = 'committed';
        $saved = self::save( $target, $record );
        return is_wp_error( $saved ) ? $saved : self::verify( $plan, [] );
    }

    public static function recover( array $plan, string $op ) {
        if ( ! self::check_plan( $plan, $op ) ) { return self::error( 'plan', 'The retained localization plan has changed.' ); }
        $source = self::check_source( $plan['provider'], false );
        if ( is_wp_error( $source ) ) { return $source; }
        if ( self::source_digest( $plan['provider'], $plan['fields'], $plan['media'] ?? [] ) !== $plan['source_digest'] ) { return self::error( 'source_changed', 'The original changed after translation planning.' ); }
        $record = self::record( $plan['provider'] );
        if ( ( $record['operation_id'] ?? null ) !== $op ) {
            return self::digest( $record ) === $plan['previous'] ? [ 'state' => 'not_committed', 'no_native_commit' => true, 'safe_to_apply' => true ] :
                self::error( 'version', 'Another localization acquired the original.' );
        }
        if ( $record['digest'] !== $plan['digest'] ) { return self::error( 'plan', 'The operation digest does not match.' ); }
        return 'committed' === $record['state'] ? self::verify( $plan, [] ) : [ 'state' => 'not_committed', 'no_native_commit' => false, 'safe_to_apply' => true ];
    }

    public static function supersede( array $plan, string $op ) {
        $result = self::recover( $plan, $op );
        if ( ! is_wp_error( $result ) && 'not_committed' === $result['state'] && empty( $result['no_native_commit'] ) ) {
            return self::error( 'reconcile', 'Finish verifying the partial translation before replacing it.' );
        }
        return $result;
    }

    public static function verify( array $plan, array $result, bool $read_only = false ) {
        $target = $plan['provider']; $allowed = self::check_source( $target, false );
        if ( is_wp_error( $allowed ) ) { return $allowed; }
        $record = self::record( $target );
        if ( ! self::check_plan( $plan, $plan['operation_id'] ) || ( $record['state'] ?? '' ) !== 'committed' || $record['digest'] !== $plan['digest'] ) {
            return self::error( 'journal', 'The localized post has no matching completed operation.' );
        }
        $proof = self::evidence( $plan, $record['ids'], $record['mapped_fields'] ?? [] );
        if ( is_wp_error( $proof ) || self::digest( $proof ) !== $record['proof'] || self::source_digest( $target, $plan['fields'], $plan['media'] ?? [] ) !== $record['source_digest'] ) { return self::error( 'readback', 'The source or translation differs from the native values witnessed at publication.' ); }
        return [ 'state' => 'complete', 'operation_id' => $plan['operation_id'], 'post_id' => $target['post_id'], 'cms_post_status' => 'publish', 'url' => $target['url'], 'native_digest' => $record['proof'] ];
    }

    public static function finish( array $plan, array $result ) { return self::verify( $plan, $result ); }
}

require_once __DIR__ . '/class-nova-wordpress-translation-fields.php';
