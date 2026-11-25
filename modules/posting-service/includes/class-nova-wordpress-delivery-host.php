<?php
/** Use the registered WordPress host for a delivery sent to its www alias. */
defined( 'ABSPATH' ) || exit;

final class Nova_WordPress_Delivery_Host {
    /** Use WordPress's effective HTTPS scheme only for the same configured single-site root. */
    public static function routing_home( $home ) {
        if ( ! is_string( $home ) || 0 !== stripos( $home, 'http://' ) || is_multisite() ) { return $home; }
        $parts = wp_parse_url( $home );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) || array_diff( array_keys( $parts ), [ 'scheme', 'host', 'path' ] ) ) { return $home; }

        // Compare the entire root. A filtered home for another domain or language directory cannot authorize a write.
        $https = 'https://' . substr( $home, 7 );
        $effective = home_url( '/' );
        return is_string( $effective ) && trailingslashit( $https ) === $effective ? $https : $home;
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
