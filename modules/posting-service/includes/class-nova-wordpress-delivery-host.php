<?php
/** Use the registered WordPress host for a delivery sent to its www alias. */
defined( 'ABSPATH' ) || exit;

final class Nova_WordPress_Delivery_Host {
    /** Use WordPress's effective HTTPS scheme only for the same configured site root. */
    public static function routing_home( $home ) {
        return self::effective_https_home( $home ) ?? $home;
    }

    private static function effective_https_home( $home, bool $recheck = false ): ?string {
        if ( ! is_string( $home ) ) { return null; }
        if ( 0 === stripos( $home, 'http://' ) ) { $home = 'https://' . substr( $home, 7 ); }
        elseif ( ! $recheck || 0 !== stripos( $home, 'https://' ) ) { return null; }
        $parts = wp_parse_url( $home );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) || array_diff( array_keys( $parts ), [ 'scheme', 'host', 'path' ] ) ) { return null; }

        // Compare the entire root. A filtered home for another domain or language directory cannot authorize a write.
        if ( trailingslashit( $home ) !== home_url( '/' ) ) { return null; }
        if ( is_multisite() ) {
            $site = get_site();
            if ( ! $site || 0 !== strcasecmp( $site->domain, $parts['host'] ) || $site->path !== trailingslashit( $parts['path'] ?? '/' ) ) { return null; }
        }
        return $home;
    }

    /** Capture the blog and root that authorized the fallback so a resumed write can check them again. */
    public static function network_route( bool $recheck = false ): ?array {
        if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) { return null; }
        // An administrator may have corrected just the scheme since planning.
        $home = self::effective_https_home( get_option( 'home', '' ), $recheck );
        return null === $home ? null : [ 'blog_id' => get_current_blog_id(), 'home' => trailingslashit( $home ) ];
    }

    /** A network root can contain paths registered to other sites. Check the final URL before using the HTTPS fallback. */
    public static function verify_network_destination( $url, ?array $network ) {
        if ( null === $network ) { return $url; }
        $target = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $url );
        if ( is_wp_error( $target ) ) { return $target; }
        $domain = wp_parse_url( $target['origin'], PHP_URL_HOST );
        $owner = get_site_by_path( $domain, $target['path'] );
        // A language provider may own an additional domain, but cannot claim a registered sibling.
        $registered_domain = strtolower( wp_parse_url( $network['home'], PHP_URL_HOST ) );
        if ( ( $owner && (int) $owner->blog_id !== $network['blog_id'] ) || ( ! $owner && $domain === $registered_domain ) ) {
            return new WP_Error( 'nova_mapped_foreign_target_url', 'This URL does not belong to the connected WordPress site.', [ 'status' => 403, 'error_code' => 'foreign_target_url' ] );
        }
        return $url;
    }

    public static function verify_network_plan( array $plan ) {
        if ( ! isset( $plan['network_https_route'] ) ) { return true; }
        $network = self::network_route( true );
        if ( $network !== $plan['network_https_route'] ) {
            return new WP_Error( 'nova_network_home_changed', 'WordPress cannot confirm the HTTPS home used when planning this delivery.' );
        }
        return self::verify_network_destination( $plan['target']['target_url'], $network );
    }

    public static function canonicalize( $url, $home, array $other_homes = [] ) {
        if ( ! is_string( $url ) || ! is_string( $home ) ) { return $url; }
        // Match the entire authority. Leave credentials, malformed URLs and relative paths to the normal validator.
        $authority = '~\A(https?://)([a-z0-9.-]+)(?::([0-9]+))?(?=[/?#]|$)~iD';
        if ( ! preg_match( $authority, $url, $target ) || ! preg_match( $authority, $home, $site ) ) { return $url; }
        $from = strtolower( $target[2] ); $to = strtolower( $site[2] );
        if ( $from === $to || strcasecmp( $target[1], $site[1] ) !== 0 ) { return $url; }
        $bare = 0 === strpos( $to, 'www.' ) ? substr( $to, 4 ) : $to;
        $alias = $bare === $to ? 'www.' . $to : $bare;
        if ( $from !== $alias || 0 === strpos( $bare, 'www.' ) || false === strpos( $bare, '.' ) || filter_var( $bare, FILTER_VALIDATE_IP ) ) { return $url; }
        $default_port = 0 === strcasecmp( $site[1], 'https://' ) ? '443' : '80';
        if ( ( $target[3] ?? $default_port ) !== ( $site[3] ?? $default_port ) ) { return $url; }

        // An explicitly registered domain may belong to a separate language or network site.
        foreach ( $other_homes as $other_home ) {
            if ( $from === strtolower( (string) parse_url( $other_home, PHP_URL_HOST ) ) ) { return $url; }
        }
        if ( function_exists( 'is_multisite' ) && is_multisite() && get_sites( [ 'domain' => $from, 'site__not_in' => [ get_current_blog_id() ], 'number' => 1, 'fields' => 'ids' ] ) ) { return $url; }

        return substr_replace( $url, $to, strlen( $target[1] ), strlen( $target[2] ) );
    }
}
