<?php
/** Native publishing authority for sites translated by GTranslate. */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-nova-wordpress-localized-post-storage.php';

final class Nova_WordPress_GTranslate {
    use Nova_WordPress_Localized_Post_Storage;
    public const META_PREFIX = '_nova_gtranslate_i18n_';

    private static function settings(): array {
        $settings = get_option( 'GTranslate', [] );
        if ( is_callable( [ 'GTranslate', 'load_defaults' ] ) ) { GTranslate::load_defaults( $settings ); }
        return is_array( $settings ) ? $settings : [];
    }

    public static function catalog(): array {
        $settings = self::settings();
        $source = self::source();
        $codes = [ $source ];
        if ( ! empty( $settings['pro_version'] ) && empty( $settings['enterprise_version'] ) && empty( $settings['custom_domains'] ) ) {
            $look = $settings['widget_look'] ?? 'float';
            $flags = $settings['fincl_langs'] ?? [];
            $dropdown = $settings['incl_langs'] ?? [];
            $flag_widgets = [ 'flags', 'float', 'dropdown_with_flags', 'flags_name', 'flags_code', 'popup', 'popup_search' ];
            $enabled = 'flags_dropdown' === $look ? array_merge( $flags, $dropdown ) :
                ( in_array( $look, $flag_widgets, true ) ? $flags : $dropdown );
            $codes = array_merge( $codes, $enabled );
        }
        $codes = array_map( 'strtolower', $codes );
        $languages = [];
        foreach ( array_keys( GTranslate::$lang_array ) as $code ) {
            if ( in_array( strtolower( $code ), $codes, true ) ) {
                $languages[$code] = [ 'code' => $code ];
            }
        }
        $source = Nova_Bridge_Suite_Translation_Writer::match_language( $source, $languages );
        return [ 'source' => $source, 'languages' => $languages ];
    }

    public static function url( string $original, string $code ): string {
        if ( self::matches_source( $code ) ) { return $original; }
        $home = trailingslashit( get_option( 'home' ) );
        if ( ! isset( self::catalog()['languages'][$code] ) || ! str_starts_with( trailingslashit( $original ), $home ) ) { return ''; }
        return $home . $code . '/' . substr( $original, strlen( $home ) );
    }

    public function get_current_code(): string {
        $header = $_SERVER['HTTP_X_GT_LANG'] ?? '';
        return is_string( $header ) && isset( self::catalog()['languages'][$header] ) ? $header : '';
    }

    public function is_original_language( string $locale ): bool { return self::matches_source( $locale ); }
    public function resolve_destination_code( string $locale ): string {
        return Nova_Bridge_Suite_Translation_Writer::match_language( $locale, self::catalog()['languages'] );
    }
    public function structured_meta_keys(): array { return [ '_elementor_data' ]; }

    /** Vary the original response for every language of a NOVA-managed object. */
    public static function render(): void {
        if ( is_admin() || is_preview() || is_feed() || headers_sent() ) { return; }
        $settings = self::settings();
        if ( empty( $settings['pro_version'] ) ) { return; }
        $term = is_tax( [ 'product_cat', 'product_tag' ] );
        if ( ! $term && ! is_singular() ) { return; }
        $id = (int) get_queried_object_id();
        $type = $term ? 'term' : 'post';
        $managed = $term ? metadata_exists( 'term', $id, '_nova_static_term_operation' ) :
            metadata_exists( 'post', $id, '_nova_stored_layout_owner' ) || metadata_exists( 'post', $id, '_nova_owned_cpt_owner' );
        $catalog = self::catalog();
        foreach ( $catalog['languages'] as $language ) {
            $code = $language['code'];
            $key = $term ? '_nova_localized_term_' . hash( 'sha256', 'gtranslate:' . $code ) :
                self::META_PREFIX . str_replace( '-', '_', strtolower( $code ) );
            if ( $managed || metadata_exists( $type, $id, $key ) ||
                ( ! $term && metadata_exists( 'post', $id, '_nova_localized_' . hash( 'sha256', 'gtranslate:' . $code ) ) ) ) { $managed = true; break; }
        }
        $language = ( new self() )->get_current_code();
        if ( ! $managed && ! has_filter( 'nova_gtranslate_render_html' ) ) { return; }
        nocache_headers();
        header( 'Vary: X-GT-Lang', false );
        if ( '' === $language || self::matches_source( $language ) ) { return; }
        $post_copy = $term ? null : self::get( $id, $language );
        if ( ! $post_copy && ! has_filter( 'nova_gtranslate_render_html' ) ) { return; }
        $title = $term ? ( Nova_Bridge_Suite_Term_Translation_Writer::record( $id, [ 'provider' => 'gtranslate', 'language' => $language ] )['fields']['native']['name']['value'] ?? '' ) :
            ( $post_copy['title'] ?? '' );
        ob_start( static function ( $html ) use ( $title ) {
            $html = apply_filters( 'nova_gtranslate_render_html', $html );
            $tags = new WP_HTML_Tag_Processor( $html );
            $heading = false;
            $replacements = [];
            while ( $tags->next_token() ) {
                $tag = $tags->get_tag();
                if ( 'H1' === $tag ) { $heading = ! $tags->is_tag_closer(); }
                if ( $heading && '#text' === $tags->get_token_type() && '' !== $title && trim( $tags->get_modifiable_text() ) === $title ) {
                    $placeholder = 'NOVA_GT_' . wp_generate_uuid4();
                    $replacements[$placeholder] = '<span class="notranslate" translate="no">' . esc_html( $tags->get_modifiable_text() ) . '</span>';
                    $tags->set_modifiable_text( $placeholder );
                }
                if ( null === $tag || $tags->is_tag_closer() ) { continue; }
                if ( null !== $tags->get_attribute( 'data-wg-notranslate' ) ||
                    ( 'TITLE' === $tags->get_tag() && '' !== $title && str_contains( $tags->get_modifiable_text(), $title ) ) ) {
                    $tags->add_class( 'notranslate' );
                    $tags->set_attribute( 'translate', 'no' );
                }
            }
            return strtr( $tags->get_updated_html(), $replacements );
        } );
    }

    public static function active(): bool {
        return defined( 'GTRANSLATE_VERSION' ) && class_exists( 'GTranslate', false );
    }

    public static function source(): string {
        $settings = self::settings();
        $source = $settings['default_language'] ?? '';
        return is_string( $source ) && preg_match( '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/iD', $source ) ? strtolower( $source ) : '';
    }

    /** A widget language is one slot. Chinese script variants remain distinct. */
    public static function matches_source( string $language ): bool {
        $source = self::source();
        $language = strtolower( str_replace( '_', '-', $language ) );
        return '' !== $source && ( $language === $source || ( ! str_contains( $source, '-' ) &&
            preg_match( '/^[a-z]{2,3}-(?:[a-z]{2}|[0-9]{3})$/D', $language ) && explode( '-', $language )[0] === $source ) );
    }

    /** A retained native writer may never resume against a translated URL. */
    public static function check_delivery( array $snapshot, string $phase, string $writer ) {
        $native = self::native_delivery( $snapshot );
        if ( ! is_wp_error( $native ) || 'nova_gtranslate_secondary_delivery' !== $native->get_error_code() ) { return $native; }
        $fresh = 'received' === $phase && '' === $writer;
        if ( ! $fresh && ! in_array( $writer, [ 'dictionary_translation', 'dictionary_term_translation' ], true ) ) { return $native; }
        $provider = Nova_Bridge_Suite_Translation_Writer::provider( $snapshot['language'] );
        if ( ! is_array( $provider ) || 'gtranslate' !== $provider['provider'] ) { return $native; }
        return Nova_Bridge_Suite_Translation_Writer::delivery_url( $snapshot['url'], $snapshot['language'] );
    }

    public static function native_delivery( array $snapshot ) {
        if ( ! self::active() ) { return true; }
        if ( function_exists( 'pll_get_post_language' ) || defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_element_language_details' ) ||
            class_exists( 'TRP_Translate_Press' ) || defined( 'WEGLOT_VERSION' ) || function_exists( 'weglot_get_service' ) ) {
            return new WP_Error( 'nova_gtranslate_provider_conflict', 'Multiple translation providers are active. The delivery language owner is ambiguous.' );
        }
        if ( ! self::matches_source( (string) ( $snapshot['language'] ?? '' ) ) ) {
            return new WP_Error( 'nova_gtranslate_secondary_delivery', 'This delivery does not match the configured GTranslate source language. Its localized copy requires a verified translated destination.' );
        }
        $settings = self::settings();
        if ( empty( $settings['pro_version'] ) ) { return true; }
        $url = $snapshot['url'] ?? '';
        $path = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
        $base = rtrim( (string) wp_parse_url( get_option( 'home' ), PHP_URL_PATH ), '/' ) . '/';
        $relative = str_starts_with( $path, $base ) ? substr( $path, strlen( $base ) ) : ltrim( $path, '/' );
        $prefix = strtolower( explode( '/', $relative )[0] );
        foreach ( array_keys( GTranslate::$lang_array ) as $code ) {
            if ( strtolower( $code ) === $prefix && $prefix !== self::source() ) {
                return new WP_Error( 'nova_gtranslate_localized_url', 'This URL belongs to a translated GTranslate directory. Supply the source URL for the native write.' );
            }
        }
        return true;
    }
}
