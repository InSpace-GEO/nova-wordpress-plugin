<?php
/** Apply the selected taxonomy's registered permalink to a delivery slug. */
defined( 'ABSPATH' ) || exit;

final class Nova_WordPress_Taxonomy_Route {
    public static function url( string $url, string $taxonomy, ?string $language = null ) {
        global $wp_rewrite;
        // Earlier native adapters still perform their own validation when no rewrite API is available.
        if ( ! is_object( $wp_rewrite ) || ! method_exists( $wp_rewrite, 'get_extra_permastruct' ) ) { return $url; }
        if ( class_exists( 'TRP_Translate_Press' ) || function_exists( 'pll_get_post_language' ) || defined( 'ICL_SITEPRESS_VERSION' ) ) { return $url; }
        $catalog = function_exists( 'weglot_get_service' ) ? Nova_WordPress_Weglot::catalog() : [];
        $code = null;
        if ( $catalog && $language ) {
            $code = Nova_Bridge_Suite_Translation_Writer::match_language( $language, $catalog['languages'] );
            $verified = Nova_Bridge_Suite_Translation_Writer::delivery_url( $url, $language );
            if ( is_wp_error( $verified ) ) { return $verified; }
            $url = Nova_WordPress_Weglot::url( $verified, $catalog['source'] );
        }
        $native = self::native_url( $url, $taxonomy );
        if ( is_wp_error( $native ) ) { return $native; }
        if ( $code ) { $native = Nova_WordPress_Weglot::url( $native, $code ); }
        $normalized = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $native );
        return is_wp_error( $normalized ) ? $normalized : $normalized['identity'];
    }

    private static function native_url( string $url, string $taxonomy ) {
        global $wp_rewrite;
        $home = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( get_option( 'home', home_url( '/' ) ) );
        if ( is_wp_error( $home ) ) { return $home; }
        if ( ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) && 0 !== strpos( $url, '//' ) ) {
            $url = $home['origin'] . ( '/' === substr( $url, 0, 1 ) ? $url : rtrim( $home['path'], '/' ) . '/' . $url );
        }
        $input = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $url );
        if ( is_wp_error( $input ) ) { return $input; }
        $root = rtrim( $home['path'], '/' );
        if ( $input['origin'] !== $home['origin'] || ( '' !== $root && 0 !== strpos( $input['path'], $root . '/' ) ) ||
            null !== parse_url( $input['identity'], PHP_URL_QUERY ) ) {
            return new WP_Error( 'nova_mapped_create_route', 'The taxonomy delivery URL is outside this WordPress installation.' );
        }
        $structure = $wp_rewrite->get_extra_permastruct( $taxonomy );
        $token = '%' . $taxonomy . '%';
        if ( ! is_string( $structure ) || 1 !== substr_count( $structure, $token ) || preg_match( '/%[^%]+%/', str_replace( $token, '', $structure ) ) ) {
            return new WP_Error( 'nova_mapped_create_route', 'The selected taxonomy has no supported native permalink structure.' );
        }
        [ $prefix, $suffix ] = explode( $token, $structure, 2 );
        $prefix = $root . '/' . ltrim( $prefix, '/' );
        if ( 0 === strpos( $input['path'], $prefix ) && ( '' === $suffix || substr( $input['path'], -strlen( $suffix ) ) === $suffix ) ) {
            return $input['identity'];
        }
        $slug = ltrim( substr( $input['path'], strlen( $root ) ), '/' );
        $object = get_taxonomy( $taxonomy );
        // Tags have one slug. Older taxonomy bases do not become tag parents.
        if ( empty( $object->rewrite['hierarchical'] ) ) { $slug = basename( $slug ); }
        if ( '' === $slug ) {
            return new WP_Error( 'nova_mapped_create_route', 'The supplied slug does not fit the selected taxonomy permalink.' );
        }
        return $home['origin'] . $prefix . $slug . $suffix;
    }
}
