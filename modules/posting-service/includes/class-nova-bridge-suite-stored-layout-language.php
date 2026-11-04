<?php
/** Resolve an authenticated delivery locale against WordPress's installed language provider. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-nova-wordpress-delivery-host.php';
final class Nova_Bridge_Suite_Stored_Layout_Language {
    private static function error( string $message ): WP_Error {
        return new WP_Error( 'nova_stored_layout_language', $message, [ 'status' => 409 ] );
    }

    private static function locale( string $value ): string {
        return strtolower( str_replace( '_', '-', $value ) );
    }

    public static function provider(): string {
        // Polylang can expose compatibility hooks with the same names as WPML's.
        if ( function_exists( 'pll_get_post_language' ) ) { return 'polylang'; }
        if ( defined( 'ICL_SITEPRESS_VERSION' ) || ( function_exists( 'has_filter' ) && has_filter( 'wpml_element_language_details' ) ) ) { return 'wpml'; }
        return 'core';
    }

    private static function native_default() {
        if ( self::provider() === 'polylang' ) { return pll_default_language( 'slug' ); }
        if ( self::provider() === 'wpml' ) { return apply_filters( 'wpml_default_language', null ); }
        $locale = get_option( 'WPLANG', null );
        if ( null === $locale ) { $locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US'; }
        return is_string( $locale ) ? str_replace( '_', '-', '' === $locale ? 'en_US' : $locale ) : self::error( 'The native WordPress locale is unavailable.' );
    }

    private static function site_home(): string {
        $home = get_option( 'home', '' );
        return is_string( $home ) && '' !== $home ? $home : home_url( '/' );
    }

    /** These homes come exclusively from the active plugin. A delivery cannot authorize its own domain. */
    private static function languages( string $provider ) {
        $languages = [];
        if ( 'polylang' === $provider ) {
            if ( ! function_exists( 'pll_languages_list' ) || ! function_exists( 'pll_home_url' ) ) { return self::error( 'The installed Polylang language API is incomplete.' ); }
            $codes = pll_languages_list( [ 'fields' => 'slug', 'hide_empty' => 0 ] );
            $locales = pll_languages_list( [ 'fields' => 'locale', 'hide_empty' => 0 ] );
            if ( ! is_array( $codes ) || ! is_array( $locales ) || count( $codes ) !== count( $locales ) ) { return self::error( 'The Polylang language catalog is unavailable.' ); }
            $locales = array_values( $locales );
            foreach ( array_values( $codes ) as $index => $code ) {
                $languages[] = [ 'code' => $code, 'locale' => $locales[$index], 'home' => pll_home_url( $code ) ];
            }
        } else {
            $records = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
            global $sitepress;
            // WPML's front-end switcher filter can be empty during REST and cron.
            if ( empty( $records ) && is_callable( [ $sitepress, 'get_active_languages' ] ) ) { $records = $sitepress->get_active_languages(); }
            foreach ( (array) $records as $key => $record ) {
                $code = $record['language_code'] ?? $record['code'] ?? $key;
                if ( ! is_string( $code ) || '' === $code ) { continue; }
                $locale = $record['default_locale'] ?? '';
                if ( '' === $locale && is_callable( [ $sitepress, 'get_locale' ] ) ) { $locale = $sitepress->get_locale( $code ); }
                // A switcher's url may point at the current article. Convert the installation home instead.
                $home = is_callable( [ $sitepress, 'language_url' ] ) ? $sitepress->language_url( $code, true ) : apply_filters( 'wpml_permalink', rtrim( self::site_home(), '/' ) . '/', $code );
                $languages[] = [ 'code' => $code, 'locale' => $locale, 'home' => $home ];
            }
        }
        return $languages;
    }

    private static function context( string $language, string $type = '', ?string $url = null, bool $resolve_delivery_host = false ) {
        $provider = self::provider();
        $records = self::languages( $provider );
        if ( is_wp_error( $records ) ) { return $records; }
        $requested = self::locale( $language );
        $matches = [];
        $homes = [];
        foreach ( $records as $record ) {
            $home = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $record['home'] );
            if ( is_wp_error( $home ) || null !== parse_url( $record['home'], PHP_URL_QUERY ) || ! is_string( $record['code'] ) || ! is_string( $record['locale'] ) ) { return self::error( 'This language setup requires verified pretty-permalink homes.' ); }
            $homes[] = [ 'url' => $home, 'code' => $record['code'] ];
            if ( '' !== $requested && in_array( $requested, [ self::locale( $record['code'] ), self::locale( $record['locale'] ) ], true ) ) {
                $matches[] = [ 'provider' => $provider, 'code' => $record['code'], 'locale' => $record['locale'], 'home' => $home['identity'], 'post_type' => $type, 'routing_version' => 2 ];
            }
        }
        if ( count( $matches ) !== 1 ) { return self::error( 'The requested delivery language does not match exactly one configured language.' ); }
        $context = $matches[0];
        if ( $type !== '' ) {
            if ( 'polylang' === $provider && ( ! function_exists( 'pll_is_translated_post_type' ) || ! pll_is_translated_post_type( $type ) ) ) { return self::error( 'Polylang does not translate the mapped post type.' ); }
            if ( 'wpml' === $provider && false === apply_filters( 'wpml_is_translated_post_type', null, $type ) ) { return self::error( 'WPML does not translate the mapped post type.' ); }
        }
        if ( null !== $url ) {
            if ( $resolve_delivery_host ) {
                $url = Nova_WordPress_Delivery_Host::canonicalize( $url, $context['home'], array_column( $records, 'home' ) );
                $context['delivery_url'] = $url;
            }
            $target = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $url );
            if ( is_wp_error( $target ) || null !== parse_url( $url, PHP_URL_QUERY ) ) { return self::error( 'The delivery requires an exact native pretty permalink.' ); }
            // /en/ belongs to English even when the default language also owns the root of this host.
            $owners = [];
            foreach ( $homes as $candidate ) {
                $home = $candidate['url'];
                if ( $home['origin'] !== $target['origin'] || ( '/' !== $home['path'] && $home['path'] !== $target['path'] && 0 !== strpos( $target['path'], $home['path'] . '/' ) ) ) { continue; }
                $owners[strlen( $home['path'] )][] = $candidate['code'];
            }
            if ( ! $owners || $owners[max( array_keys( $owners ) )] !== [ $context['code'] ] ) { return self::error( 'The destination URL does not belong to the configured home for the requested language.' ); }
        }
        return $context;
    }

    public static function language_home( string $language ) {
        if ( 'core' === self::provider() ) { return self::site_home(); }
        $context = self::context( $language );
        return is_wp_error( $context ) ? $context : $context['home'];
    }

    /** A site's registered permastruct can be shared when its native languages use the same path and CPT base. */
    public static function same_route_for_languages( string $post_type, string $base ): bool {
        $provider = self::provider();
        if ( 'core' === $provider ) { return true; }
        $records = self::languages( $provider );
        if ( is_wp_error( $records ) || ! $records ) { return false; }
        $home = wp_parse_url( self::site_home() );
        foreach ( $records as $record ) {
            $url = is_string( $record['home'] ) ? wp_parse_url( $record['home'] ) : null;
            if ( ! is_array( $url ) || empty( $url['host'] ) || isset( $url['query'] ) || rtrim( $url['path'] ?? '', '/' ) !== rtrim( $home['path'] ?? '', '/' ) ) { return false; }
            if ( $base && 'wpml' === $provider && $base !== apply_filters( 'wpml_get_translated_slug', $base, $post_type, $record['code'], 'post' ) ) { return false; }
            // Polylang Pro can localize arbitrary CPT bases; do not assume another language keeps the same slug.
            if ( $base && 'polylang' === $provider && count( $records ) > 1 ) { return false; }
        }
        return true;
    }

    public static function is_multilingual_post_provider(): bool {
        return 'core' !== self::provider();
    }

    public static function sibling_locales( array $target ): array {
        $context = $target['native_language'] ?? [];
        if ( empty( $context['routing_version'] ) || $context['provider'] !== self::provider() ) { return []; }
        $records = self::languages( $context['provider'] );
        if ( is_wp_error( $records ) ) { return []; }
        $locales = [];
        foreach ( $records as $record ) {
            if ( $record['code'] === $context['code'] ) { continue; }
            foreach ( [ $record['code'], $record['locale'] ] as $locale ) {
                if ( is_string( $locale ) && '' !== $locale ) { $locales[] = self::locale( $locale ); }
            }
        }
        return array_values( array_unique( $locales ) );
    }

    public static function draft_url( string $sample, array $target ) {
        if ( ( $target['native_language']['provider'] ?? '' ) !== 'wpml' ) { return $sample; }
        // Core's sample permalink may reflect the admin language even after setting the post language.
        $url = apply_filters( 'wpml_permalink', $sample, $target['native_language']['code'] );
        return is_string( $url ) && '' !== $url ? $url : self::error( 'WPML did not return a sample permalink.' );
    }

    public static function delivery_url( $url, string $language ) {
        // The native original uses WordPress storage, but its public URL belongs to the provider's
        // source language. WordPress's interface locale may be completely different.
        if ( 'core' === self::provider() && ( class_exists( 'TRP_Translate_Press' ) || function_exists( 'weglot_get_service' ) ) ) {
            return Nova_Bridge_Suite_Translation_Writer::delivery_url( $url, $language );
        }
        if ( ! is_string( $url ) || '' === $url || 0 === strpos( $url, '//' ) || in_array( $url[0], [ '?', '#' ], true ) ) { return self::error( 'The delivery URL is invalid.' ); }
        if ( ! preg_match( '/^[a-z][a-z0-9+.-]*:/i', $url ) ) {
            $home = self::language_home( $language );
            if ( is_wp_error( $home ) ) { return $home; }
            $parts = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $home );
            $installation = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( self::site_home() );
            if ( is_wp_error( $parts ) || is_wp_error( $installation ) ) { return self::error( 'The native home URL is unavailable.' ); }
            $url = $parts['origin'] . ( '/' === $url[0] ? $url : rtrim( $installation['path'], '/' ) . '/' . $url );
        }
        if ( self::provider() !== 'core' ) {
            $context = self::context( $language, '', $url, true );
            return is_wp_error( $context ) ? $context : $context['delivery_url'];
        }
        return Nova_WordPress_Delivery_Host::canonicalize( $url, self::site_home() );
    }

    public static function resolve( string $url, ?string $resource, string $language ) {
        if ( self::provider() === 'core' ) { return Nova_Bridge_Suite_Mapped_Content_Target::resolve( $url, $resource ); }
        $context = self::context( $language, '', $url );
        if ( is_wp_error( $context ) ) { return $context; }
        // The third argument to the mapping UI's resolver finds a translation of an existing
        // URL. Here the delivery supplies the final URL already; never pick another page.
        return self::in_language( $context, static function () use ( $url, $resource, $context ) {
            return Nova_Bridge_Suite_Mapped_Content_Target::resolve( $url, $resource, null, $context['home'] );
        } );
    }

    public static function existing( string $resource, int $id, string $url, string $language = '' ) {
        $post = get_post( $id );
        if ( ! $post || $resource !== 'post_type:' . $post->post_type ) { return self::error( 'The saved layout must match the native post type.' ); }
        if ( '' === $language || 'core' === self::provider() ) { $language = self::native_default(); }
        if ( ! is_string( $language ) || '' === $language ) { return self::error( 'The native language is unavailable.' ); }
        $context = 'core' === self::provider() ? [ 'provider' => 'core', 'code' => self::locale( $language ) ] : self::context( $language, $post->post_type, $url );
        if ( is_wp_error( $context ) ) { return $context; }
        $wanted = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $url );
        $native = self::in_language( $context, static function () use ( $resource, $id ) { return Nova_Bridge_Suite_Mapped_Content_Target::native_url( $resource, 'post', $id ); } );
        $actual = is_wp_error( $native ) ? $native : Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $native );
        if ( is_wp_error( $wanted ) || is_wp_error( $actual ) || $actual['identity'] !== $wanted['identity'] || null !== parse_url( $url, PHP_URL_QUERY ) ) { return self::error( 'The existing post must own the exact delivery URL.' ); }
        $target = [ 'slug' => $post->post_name, 'parent' => (int) $post->post_parent, 'target_url' => $wanted['identity'], 'language' => $language, 'native_language' => $context ];
        $verified = self::verify( $resource, $id, $target );
        return is_wp_error( $verified ) ? $verified : $target;
    }

    public static function creation( string $resource, string $url, string $language ) {
        if ( self::provider() === 'core' ) {
            $default = self::native_default();
            return is_wp_error( $default ) ? $default : Nova_Bridge_Suite_Mapped_Content_Target::creation( $resource, $url, $default );
        }
        if ( ! preg_match( '/^post_type:([a-z0-9_-]{1,20})$/D', $resource, $match ) ) { return self::error( 'Choose a registered native post destination.' ); }
        $context = self::context( $language, $match[1], $url );
        if ( is_wp_error( $context ) ) { return $context; }
        $target = self::in_language( $context, static function () use ( $context, $resource, $url, $language ) {
            return Nova_Bridge_Suite_Mapped_Content_Target::creation( $resource, $url, $language, $context );
        } );
        if ( ! is_wp_error( $target ) && $target['parent'] && self::post_language( $target['parent'], $context ) !== $context['code'] ) { return self::error( 'The native parent belongs to another language.' ); }
        return $target;
    }

    private static function in_language( array $context, callable $work ) {
        if ( $context['provider'] !== 'wpml' ) { return $work(); }
        $previous = apply_filters( 'wpml_current_language', null );
        if ( $previous === $context['code'] ) { return $work(); }
        try {
            do_action( 'wpml_switch_language', $context['code'] );
            if ( apply_filters( 'wpml_current_language', null ) !== $context['code'] ) { return self::error( 'WPML could not enter the requested language.' ); }
            return $work();
        } finally { do_action( 'wpml_switch_language', $previous ); }
    }

    public static function with_context( array $target, callable $work ) {
        $saved = $target['native_language'] ?? [];
        if ( ! $saved && ! isset( $target['language'] ) ) { return $work(); } // Previously frozen CPT plan.
        if ( ( $saved['provider'] ?? null ) !== self::provider() ) { return self::error( 'The native language provider changed after planning.' ); }
        if ( empty( $saved['routing_version'] ) ) {
            $default = self::native_default();
            if ( ! is_string( $default ) || self::locale( $saved['code'] ?? '' ) !== self::locale( $default ) ) { return self::error( 'The default language of this historical plan has changed.' ); }
            if ( 'polylang' !== $saved['provider'] ) { return Nova_Bridge_Suite_Mapped_Content_Target::with_creation_language( $target, $work ); }
        }
        $current = self::context( $target['language'], $saved['post_type'] ?? 'page', $target['target_url'] );
        if ( is_wp_error( $current ) || $current['code'] !== $saved['code'] || $current['home'] !== ( $saved['home'] ?? null ) || self::locale( $current['locale'] ) !== self::locale( $saved['locale'] ?? '' ) ) { return self::error( 'The planned language or its native URL changed.' ); }
        return self::in_language( $current, $work );
    }

    private static function wpml_details( int $id, string $type ): array {
        return (array) apply_filters( 'wpml_element_language_details', null, [ 'element_id' => $id, 'element_type' => 'post_' . $type ] );
    }

    private static function post_language( int $id, array $context ) {
        if ( 'polylang' === $context['provider'] ) { return pll_get_post_language( $id, 'slug' ); }
        $details = self::wpml_details( $id, $context['post_type'] ?? 'page' );
        return $details['language_code'] ?? null;
    }

    /** Used only for reservations with the same slug and parent. An unassigned reservation still conflicts. */
    public static function different_language( int $id, array $target ): bool {
        $context = $target['native_language'] ?? [];
        if ( empty( $context['routing_version'] ) || $context['provider'] !== self::provider() ) { return false; }
        $code = self::post_language( $id, $context );
        return is_string( $code ) && '' !== $code && $code !== $context['code'];
    }

    public static function assign( int $id, array $target ) {
        $context = $target['native_language'] ?? [];
        if ( ! $context || 'core' === $context['provider'] || ( 'wpml' === $context['provider'] && empty( $context['routing_version'] ) ) ) { return true; }
        return self::with_context( $target, static function () use ( $id, $target, $context ) {
            if ( ! current_user_can( 'edit_post', $id ) ) { return self::error( 'The publishing user cannot assign this reserved post language.' ); }
            if ( self::post_language( $id, $context ) !== $context['code'] ) {
                if ( 'polylang' === $context['provider'] ) { pll_set_post_language( $id, $context['code'] ); }
                else {
                    // Called only for a newly reserved post. Start its own group if there is no proven sibling yet.
                    do_action( 'wpml_set_element_language_details', [ 'element_id' => $id, 'element_type' => 'post_' . $context['post_type'], 'trid' => false, 'language_code' => $context['code'], 'source_language_code' => null ] );
                }
            }
            return self::verify( 'post_type:' . ( $context['post_type'] ?? 'page' ), $id, $target );
        } );
    }

    public static function verify( string $resource, int $id, array $target ) {
        if ( empty( $target['native_language'] ) && ! isset( $target['language'] ) ) { return true; }
        return self::with_context( $target, static function () use ( $id, $resource, $target ) {
            $context = $target['native_language'];
            if ( 'core' === $context['provider'] || ( 'wpml' === $context['provider'] && empty( $context['routing_version'] ) ) ) { return Nova_Bridge_Suite_Mapped_Content_Target::verify_created_language( $resource, 'post', $id, $target ); }
            if ( $resource !== 'post_type:' . ( $context['post_type'] ?? 'page' ) || self::post_language( $id, $context ) !== $context['code'] ) { return self::error( 'The saved post does not have the requested native language.' ); }
            return true;
        } );
    }

    /** Before reserving a new post, an empty ID checks that the sibling's requested language slot is vacant. */
    public static function check_sibling( int $id, array $target, int $sibling_id ) {
        return self::translation_pair( $id, $target, $sibling_id, false );
    }

    public static function link_sibling( int $id, array $target, int $sibling_id ) {
        if ( ! $id ) { return self::error( 'A saved native post is required to link a translation.' ); }
        return self::translation_pair( $id, $target, $sibling_id, true );
    }

    /** Attach only a verified sibling in this NOVA URL family; preserve every existing translation. */
    private static function translation_pair( int $id, array $target, int $sibling_id, bool $save ) {
        $context = $target['native_language'] ?? [];
        if ( ! $sibling_id || empty( $context['routing_version'] ) ) { return true; }
        $sibling = get_post( $sibling_id );
        if ( ! $sibling || $sibling->post_type !== $context['post_type'] || ! current_user_can( 'edit_post', $sibling_id ) || ( $id && ! current_user_can( 'edit_post', $id ) ) ) { return self::error( 'The translation sibling must be an editable post of the same type.' ); }
        $source_code = self::post_language( $sibling_id, $context );
        $code = $context['code'];
        if ( ! is_string( $source_code ) || '' === $source_code || $source_code === $code || ( $id && self::post_language( $id, $context ) !== $code ) ) { return self::error( 'The native translation pair has incompatible languages.' ); }
        if ( 'polylang' === $context['provider'] ) {
            if ( ! function_exists( 'pll_get_post_translations' ) || ! function_exists( 'pll_save_post_translations' ) ) { return self::error( 'The Polylang translation group API is unavailable.' ); }
            $group = pll_get_post_translations( $sibling_id );
            $current = $id ? pll_get_post_translations( $id ) : [];
            if ( ! is_array( $group ) || ! is_array( $current ) || ( isset( $group[$code] ) && (int) $group[$code] !== $id ) ) { return self::error( 'The translation group already identifies a different post.' ); }
            if ( (int) ( $group[$code] ?? 0 ) === $id && (int) ( $current[$source_code] ?? 0 ) === $sibling_id ) { return true; }
            foreach ( $current as $language => $member ) {
                if ( $language !== $code && (int) ( $group[$language] ?? 0 ) !== (int) $member ) { return self::error( 'The post already belongs to another translation group.' ); }
            }
            if ( ! $save ) { return true; }
            $group[$source_code] = $sibling_id;
            $group[$code] = $id;
            pll_save_post_translations( $group );
            return (int) pll_get_post( $id, $source_code ) === $sibling_id && (int) pll_get_post( $sibling_id, $code ) === $id ? true : self::error( 'Polylang did not confirm the translation group.' );
        }
        $type = $context['post_type'];
        $source = self::wpml_details( $sibling_id, $type );
        if ( empty( $source['trid'] ) ) { return self::error( 'WPML did not identify the translation group.' ); }
        $existing = (int) apply_filters( 'wpml_object_id', $sibling_id, $type, false, $code );
        if ( $existing && $existing !== $id ) { return self::error( 'The translation group already identifies a different post.' ); }
        $original = ( $source['source_language_code'] ?? null ) ?: $source_code;
        if ( ! $existing && $original === $code ) {
            $siblings = apply_filters( 'wpml_get_element_translations', null, $source['trid'], 'post_' . $type );
            if ( ! is_array( $siblings ) || count( $siblings ) !== 1 ) { return self::error( 'WPML cannot move a translation with other linked posts into a different group.' ); }
        }
        if ( ! $id ) { return true; }
        $current = self::wpml_details( $id, $type );
        if ( empty( $current['trid'] ) ) { return self::error( 'WPML did not identify the translation group.' ); }
        if ( (int) $source['trid'] === (int) $current['trid'] ) { return true; }
        $members = apply_filters( 'wpml_get_element_translations', null, $current['trid'], 'post_' . $type );
        if ( ! is_array( $members ) || count( $members ) !== 1 ) { return self::error( 'The post already belongs to another translation group.' ); }
        $link = [ 'element_id' => $id, 'element_type' => 'post_' . $type, 'trid' => (int) $source['trid'], 'language_code' => $code, 'source_language_code' => $original ];
        if ( $original === $code ) {
            // WPML can mark a lone first delivery as a translation of an original that has not arrived yet.
            // Attach that translation to the incoming original's group. Passing its own language to WPML is a no-op.
            if ( ! empty( $current['source_language_code'] ) ) { return self::error( 'WPML did not identify the incoming post as the translation group’s original.' ); }
            $link = [ 'element_id' => $sibling_id, 'element_type' => 'post_' . $type, 'trid' => (int) $current['trid'], 'language_code' => $source_code, 'source_language_code' => $code ];
        }
        if ( ! $save ) { return true; }
        do_action( 'wpml_set_element_language_details', $link );
        return (int) apply_filters( 'wpml_object_id', $sibling_id, $type, false, $code ) === $id && (int) apply_filters( 'wpml_object_id', $id, $type, false, $source_code ) === $sibling_id ? true : self::error( 'WPML did not confirm the translation group.' );
    }
}
