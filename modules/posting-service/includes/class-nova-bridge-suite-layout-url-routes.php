<?php
/** Selectors for native creation layouts. '/' denotes the NOVA page-type default. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-nova-bridge-suite-stored-layout-language.php';

final class Nova_Bridge_Suite_Layout_URL_Routes {
    /** Only a fixed public namespace followed by the native slug can serve as a selector. */
    public static function prefix( string $resource, array $source = [] ): ?string {
        try { return self::registered_prefix( $resource, $source ); }
        catch ( Throwable $error ) { return null; } // An unavailable builder/language provider cannot prove a second route.
    }
    private static function registered_prefix( string $resource, array $source ): ?string {
        global $wp_rewrite;
        if ( 0 !== strpos( $resource, 'post_type:' ) || ! is_object( $wp_rewrite ) ) { return null; }
        $name = substr( $resource, 10 ); $object = get_post_type_object( $name );
        if ( ! $object ) { return null; }
        if ( 'page' === $name ) { $structure = $wp_rewrite->get_page_permastruct(); $token = '%pagename%'; }
        elseif ( 'post' === $name ) { $structure = get_option( 'permalink_structure', '' ); $token = '%postname%'; }
        else { $structure = $wp_rewrite->get_extra_permastruct( $name ); $token = '%' . $name . '%'; }
        if ( ! is_string( $structure ) ) { return null; }
        $parts = explode( '/', trim( $structure, '/' ) );
        if ( array_pop( $parts ) !== $token ) { return null; }
        // A translated slug or language directory can introduce multiple public namespaces for one endpoint.
        $base = in_array( $name, [ 'post', 'page' ], true ) ? '' : (string) ( $object->rewrite['slug'] ?? '' );
        if ( ! Nova_Bridge_Suite_Stored_Layout_Language::same_route_for_languages( $name, $base ) ) { return null; }
        if ( class_exists( 'TRP_Translate_Press' ) ) {
            $settings = Nova_WordPress_TranslatePress::settings();
            if ( empty( $settings['default-language'] ) || array_diff( $settings['publish-languages'] ?? [], [ $settings['default-language'] ] ) || 'yes' === ( $settings['add-subdirectory-to-default-language'] ?? 'no' ) ) { return null; }
        }
        if ( function_exists( 'weglot_get_service' ) || defined( 'WEGLOT_VERSION' ) ) {
            $catalog = Nova_WordPress_Weglot::catalog();
            if ( count( $catalog['languages'] ?? [] ) !== 1 || empty( $catalog['source'] ) ) { return null; }
        }
        $home = wp_parse_url( get_option( 'home', home_url( '/' ) ) );
        if ( ! is_array( $home ) || empty( $home['host'] ) || isset( $home['query'] ) || isset( $home['fragment'] ) ) { return null; }
        $segments = array_merge( array_values( array_filter( explode( '/', trim( $home['path'] ?? '', '/' ) ), 'strlen' ) ), $parts );
        foreach ( $segments as $key => $segment ) {
            if ( '' === $segment || preg_match( '/%(?![a-f0-9]{2})/i', $segment ) ) { return null; }
            $decoded = rawurldecode( $segment );
            if ( in_array( $decoded, [ '.', '..' ], true ) || ! preg_match( '/^[\pL\pN._~-]+$/uD', $decoded ) ) { return null; }
            $segments[$key] = rawurlencode( $decoded );
        }
        $path = '/' . ( $segments ? implode( '/', $segments ) . '/' : '' );
        if ( strlen( $path ) > 512 ) { return null; }
        // A published example can disprove the registered structure (custom permalink filters).
        $id = (int) ( $source['reference_id'] ?? 0 );
        if ( $id && 'publish' === get_post_status( $id ) && get_post_type( $id ) === $name ) {
            $native = wp_parse_url( get_permalink( $id ) ); $native_path = $native['path'] ?? '';
            if ( ( $native['host'] ?? '' ) !== $home['host'] || isset( $native['query'] ) || 0 !== strpos( $native_path, $path ) || '' === trim( substr( $native_path, strlen( $path ) ), '/' ) ) { return null; }
            if ( empty( $object->hierarchical ) && false !== strpos( trim( substr( $native_path, strlen( $path ) ), '/' ), '/' ) ) { return null; }
        }
        // A bare slug is still the fallback on a subdirectory installation. Only an endpoint's own prefix identifies it.
        return $parts ? $path : '/';
    }

    /** Only reclassify a previous default if this installation approved the native layout. */
    public static function previous_default( string $site, string $id, string $type ): ?array {
        foreach ( Nova_Bridge_Suite_Layout_Recipes::saved_recipe_ids() as $recipe_id ) {
            $state = Nova_Bridge_Suite_Mapping_Sync::scope_state( $site, 'recipe:' . $recipe_id );
            if ( 'active' !== ( $state['status'] ?? '' ) || ( $state['template']['id'] ?? '' ) !== $id || ( $state['template']['page_type'] ?? '' ) !== $type ) { continue; }
            $revision = $state['template']['revision'];
            $profile = Nova_Bridge_Suite_Mapping_Sync::authored_profile( $site, $id, $revision );
            if ( 'stored_layout_v3' !== ( $profile['mode'] ?? '' ) || ( $profile['template']['id'] ?? '' ) !== $id || ( $profile['template']['revision'] ?? 0 ) !== $revision ) { return null; }
            $resource = $profile['local']['stored_layout']['resource_id'] ?? '';
            $source = [];
            $default = Nova_Bridge_Suite_Layout_Recipes::defaults()[$resource] ?? null;
            if ( $default && $default['id'] === $recipe_id ) {
                $record = Nova_Bridge_Suite_Layout_Recipes::delivery_record( $recipe_id, $default['revision'] );
                if ( ! is_wp_error( $record ) ) { $source = $record['source_reference'] ?? []; }
            }
            return [ 'prefix' => self::prefix( $resource, $source ), 'recipe_id' => $recipe_id, 'resource_id' => $resource ];
        }
        return null;
    }

    /** Normalize array order so readback does not depend on the server's sort order. */
    public static function index( $rows ) {
        if ( ! is_array( $rows ) ) { return self::invalid(); }
        $result = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || ! is_string( $row['page_type'] ?? null ) || ! is_string( $row['path_prefix'] ?? null ) || ! Nova_Bridge_Suite_Posting_Protocol::uuid( $row['template_id'] ?? null ) ) { return self::invalid(); }
            $key = $row['page_type'] . ':' . $row['path_prefix'];
            if ( isset( $result[$key] ) ) { return self::invalid(); }
            $result[$key] = $row;
        }
        ksort( $result, SORT_STRING ); return $result;
    }
    private static function invalid(): WP_Error { return new WP_Error( 'nova_stored_recipe_routes', 'Posting service returned unreadable template routes. Reload before synchronizing.', [ 'status' => 409 ] ); }
}
