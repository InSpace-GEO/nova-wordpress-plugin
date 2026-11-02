<?php
/** Per-language copy on the original post, using the Bridge 2.8 storage and render format. */
defined( 'ABSPATH' ) || exit;

final class Nova_WordPress_Weglot {
    public const META_INDEX = '_nova_weglot_languages';
    public const META_PREFIX = '_nova_weglot_i18n_';

    private static function service() {
        return function_exists( 'weglot_get_service' ) ? weglot_get_service( 'Language_Service_Weglot' ) : null;
    }

    public static function catalog(): array {
        $service = self::service();
        if ( ! $service ) { return []; }
        $original = $service->get_original_language();
        $entries = $service->get_destination_languages();
        if ( ! is_object( $original ) || ! is_array( $entries ) ) { return []; }
        $rows = [];
        foreach ( array_merge( [ $original ], $entries ) as $entry ) {
            if ( ! is_object( $entry ) || ! method_exists( $entry, 'getInternalCode' ) ) { continue; }
            $code = $entry->getInternalCode();
            $rows[$code] = [ 'code' => $code, 'alias' => method_exists( $entry, 'getExternalCode' ) ? $entry->getExternalCode() : $code ];
        }
        return [ 'source' => $original->getInternalCode(), 'languages' => $rows ];
    }

    public static function url( string $url, string $code ) {
        $service = self::service();
        $entry = $service ? $service->get_language_from_internal( $code ) : null;
        $link = function_exists( 'weglot_create_url_object' ) ? weglot_create_url_object( $url ) : null;
        return $entry && $link ? $link->getForLanguage( $entry ) : '';
    }

    public function get_current_code(): string {
        if ( function_exists( 'weglot_get_request_url_service' ) ) {
            // Before onboarding, the SDK may have no original or current language for a visitor yet.
            $request = weglot_get_request_url_service();
            $language = $request ? $request->get_current_language() : null;
            return is_object( $language ) && method_exists( $language, 'getInternalCode' ) ? (string) $language->getInternalCode() : '';
        }
        return function_exists( 'weglot_get_current_language' ) ? (string) weglot_get_current_language() : '';
    }

    public function is_original_language( string $locale ): bool {
        $catalog = self::catalog();
        return $catalog && Nova_Bridge_Suite_Translation_Writer::match_language( $locale, $catalog['languages'] ) === $catalog['source'];
    }

    public function resolve_destination_code( string $locale ): string {
        $catalog = self::catalog();
        return $catalog ? Nova_Bridge_Suite_Translation_Writer::match_language( $locale, $catalog['languages'] ) : '';
    }

    public function structured_meta_keys(): array { return [ '_elementor_data' ]; }

    public static function get( int $id, string $code ): ?array {
        $stored = get_post_meta( $id, self::key( $code ), true );
        return is_array( $stored ) ? $stored : null;
    }

    private static function key( string $code ): string {
        return self::META_PREFIX . str_replace( '-', '_', strtolower( $code ) );
    }

    public static function apply( int $id, string $code, array $fields ) {
        $current = self::get( $id, $code ) ?? [];
        $patch = $fields;
        if ( isset( $patch['meta'] ) ) { $patch['meta'] = array_replace( $current['meta'] ?? [], $patch['meta'] ); }
        $updated = array_replace( $current, $patch, [ 'language' => $code, 'source_post_id' => $id ] );
        update_post_meta( $id, self::key( $code ), wp_slash( $updated ) );
        if ( self::get( $id, $code ) !== $updated ) {
            return new WP_Error( 'nova_translation_readback', 'WordPress did not retain the Weglot translation.' );
        }
        return true;
    }
}
