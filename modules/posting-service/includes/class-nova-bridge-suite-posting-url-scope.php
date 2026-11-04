<?php
/** Optional installation ownership for installations sharing a NOVA site credential. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-nova-wordpress-delivery-host.php';

final class Nova_Bridge_Suite_Posting_Url_Scope {
    private static function error( string $message ) {
        return new WP_Error( 'nova_posting_url_scope', $message );
    }

    /** Decode path segments separately so encoded separators never widen ownership. */
    private static function path( string $path ) {
        if ( '' === $path ) { return '/'; }
        if ( '/' !== $path[0] || preg_match( '/[\\x00-\\x20\\x7f\\\\?#]/', $path ) || false !== strpos( $path, '//' ) || preg_match( '/%(?![a-f0-9]{2})/i', $path ) ) {
            return self::error( 'Use an unambiguous URL path beginning with /.' );
        }
        $segments = [];
        foreach ( explode( '/', trim( $path, '/' ) ) as $segment ) {
            $decoded = rawurldecode( $segment );
            if ( in_array( $decoded, [ '.', '..' ], true ) || preg_match( '/[\\x00-\\x20\\x7f\\\\\/]/', $decoded ) ) {
                return self::error( 'URL paths cannot contain dot segments or encoded separators.' );
            }
            $segments[] = $decoded;
        }
        return '/' . implode( '/', $segments );
    }

    private static function url( $url ) {
        if ( ! is_string( $url ) || '' === $url || preg_match( '/[\\x00-\\x20\\x7f\\\\]/', $url ) ) { return self::error( 'A valid public URL is required for installation routing.' ); }
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), [ 'https', 'http' ], true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
            return self::error( 'Use an HTTP or HTTPS public URL without credentials or a fragment.' );
        }
        $path = self::path( $parts['path'] ?? '/' );
        if ( is_wp_error( $path ) ) { return $path; }
        $scheme = strtolower( $parts['scheme'] );
        $port = $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 );
        return [ 'origin' => $scheme . '://' . strtolower( $parts['host'] ) . ':' . $port, 'path' => $path ];
    }

    public static function exclusions( $value ) {
        if ( ! is_string( $value ) ) { return self::error( 'Enter excluded paths one per line.' ); }
        $paths = [];
        foreach ( preg_split( '/\\r\\n|\\r|\\n/', $value ) as $line ) {
            $line = trim( $line );
            if ( '' === $line ) { continue; }
            $path = self::path( $line );
            if ( is_wp_error( $path ) ) { return $path; }
            // Retain the URL spelling; decoding is only for comparisons.
            $paths[] = '/' === $line ? '/' : rtrim( $line, '/' );
        }
        return array_values( array_unique( $paths ) );
    }

    private static function contains( string $prefix, string $path ): bool {
        return '/' === $prefix || $path === $prefix || 0 === strpos( $path, $prefix . '/' );
    }

    /** An active provider is the only authority for another origin on this installation. */
    private static function language_route( string $language ) {
        if ( '' === $language ) { return null; }
        foreach ( [ 'Nova_Bridge_Suite_Translation_Writer', 'Nova_Bridge_Suite_Stored_Layout_Language' ] as $provider ) {
            if ( ! is_callable( [ $provider, 'language_home' ] ) ) { continue; }
            if ( 'Nova_Bridge_Suite_Stored_Layout_Language' === $provider && ! $provider::is_multilingual_post_provider() ) { continue; }
            $home = $provider::language_home( $language );
            if ( is_wp_error( $home ) ) { return $home; }
            if ( is_string( $home ) ) { return [ 'provider' => $provider, 'home' => $home ]; }
        }
        return null;
    }

    private static function destination( $target, string $language, ?array $route ) {
        if ( ! is_string( $target ) || '' === $target || 0 === strpos( $target, '//' ) || in_array( $target[0], [ '?', '#' ], true ) ) { return self::error( 'Supply an unambiguous destination.' ); }
        if ( ! preg_match( '/^[a-z][a-z0-9+.-]*:/i', $target ) ) {
            if ( $route && '/' !== $target[0] ) {
                $provider = $route['provider'];
                $target = $provider::delivery_url( $target, $language );
                if ( is_wp_error( $target ) ) { return $target; }
            } else {
                $home = self::url( $route['home'] ?? get_option( 'home', home_url( '/' ) ) );
                if ( is_wp_error( $home ) ) { return $home; }
                $target = $home['origin'] . ( '/' === $target[0] ? $target : rtrim( $home['path'], '/' ) . '/' . $target );
            }
        } else {
            $target = self::canonical_for_route( $target, $language, $route );
        }
        return self::url( $target );
    }

    private static function canonical_for_route( $url, string $language, ?array $route ) {
        $home = $route['home'] ?? get_option( 'home', home_url( '/' ) );
        $canonical = Nova_WordPress_Delivery_Host::canonicalize( $url, $home );
        if ( $canonical === $url || ! $route ) { return $canonical; }
        // Let the provider protect hosts explicitly assigned to a different language.
        $provider = $route['provider'];
        $verified = $provider::delivery_url( $url, $language );
        return is_string( $verified ) ? $verified : $url;
    }

    public static function canonical_site_url( $url, string $language ) {
        $route = self::language_route( $language );
        return is_wp_error( $route ) ? $url : self::canonical_for_route( $url, $language, $route );
    }

    /** Separate siblings only when both destinations and the distinct content identities are known. */
    public static function independent_translations( array $previous, array $current ): bool {
        if ( empty( $previous['content_item_id'] ) || empty( $current['content_item_id'] ) || $previous['content_item_id'] === $current['content_item_id'] ) { return false; }
        $destinations = [];
        $previous_home = null;
        foreach ( [ $previous, $current ] as $snapshot ) {
            $locale = $snapshot['language'] ?? '';
            if ( ! is_string( $locale ) || ! preg_match( '/^[a-z]{2,8}(?:[-_][a-z0-9]{1,8})*$/iD', $locale ) ) { return false; }
            $key = strtolower( str_replace( '_', '-', $locale ) );
            if ( isset( $destinations[$key] ) ) { return false; }
            $route = self::language_route( $locale );
            if ( is_wp_error( $route ) ) { return false; }
            if ( $route ) {
                // A locale and its provider alias can identify the same language despite different article paths.
                $home = self::url( $route['home'] );
                if ( is_wp_error( $home ) || $home === $previous_home ) { return false; }
                $previous_home = $home;
            }
            $target = self::destination( $snapshot['url'] ?? null, $locale, $route );
            if ( is_wp_error( $target ) ) { return false; }
            $destinations[$key] = $target;
        }
        return reset( $destinations ) !== end( $destinations );
    }

    /** True owns the URL, false is a local skip, WP_Error requires local review. */
    public static function owns( array $connection, $target, string $language = '' ) {
        if ( empty( $connection['url_scope_enabled'] ) ) { return true; }
        $route = self::language_route( $language );
        if ( is_wp_error( $route ) ) { return $route; }
        $home_value = $route['home'] ?? get_option( 'home', home_url( '/' ) );
        $home = self::url( $home_value );
        if ( is_wp_error( $home ) ) { return $home; }
        $url = self::destination( $target, $language, $route );
        if ( is_wp_error( $url ) ) { return $url; }
        if ( ! is_array( $connection['url_scope_excluded_paths'] ?? [] ) ) { return self::error( 'Review the excluded paths for this installation.' ); }
        $exclusions = [];
        foreach ( $connection['url_scope_excluded_paths'] ?? [] as $path ) {
            if ( ! is_string( $path ) ) { return self::error( 'Review the excluded paths for this installation.' ); }
            $normalized = self::path( $path );
            if ( is_wp_error( $normalized ) ) { return $normalized; }
            $exclusions[] = $normalized;
        }
        if ( $url['origin'] !== $home['origin'] || ! self::contains( $home['path'], $url['path'] ) ) { return false; }
        foreach ( $exclusions as $path ) { if ( self::contains( $path, $url['path'] ) ) { return false; } }
        return true;
    }
}
