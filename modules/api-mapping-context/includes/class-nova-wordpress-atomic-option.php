<?php
/** Atomic writes for NOVA's versioned local option stores. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_WordPress_Atomic_Option {
    // Hash the stored bytes before the connection can convert the value's character set.
    // MariaDB builds without TLS support can return NULL from SHA2.
    private const FINGERPRINT = 'COALESCE(SHA2(CAST(option_value AS BINARY), 256), SHA1(CAST(option_value AS BINARY)))';

    public static function available(): bool {
        global $wpdb;
        return isset( $wpdb->options ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->options );
    }

    /** Read the decoded state and its write condition from the same database row. Null means absent. */
    public static function read( string $option ): array|false|null {
        global $wpdb;
        if ( ! self::available() ) { return false; }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT option_value, " . self::FINGERPRINT . " AS fingerprint FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1",
            $option
        ) );
        if ( null === $row ) { return empty( $wpdb->last_error ) ? null : false; }
        if ( ! is_string( $row->fingerprint ?? null ) ) { return false; }

        return [ 'value' => maybe_unserialize( $row->option_value ), 'fingerprint' => $row->fingerprint ];
    }

    /** Return 1 for a write, 0 for a competing write, or false for a database error. */
    public static function write( string $option, ?string $fingerprint, string $value, bool $disable_autoload = false ): int|false {
        global $wpdb;
        if ( ! self::can_store( $value ) ) { return false; }
        if ( null === $fingerprint ) {
            // A first write must never replace a row inserted after our read.
            $suppressed = $wpdb->suppress_errors( true );
            try {
                $changed = $wpdb->query( $wpdb->prepare(
                    "INSERT INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                    $option, $value
                ) );
            } finally {
                $wpdb->suppress_errors( $suppressed );
            }
            if ( false === $changed && '1' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1", $option ) ) ) {
                $changed = 0;
            }
        } else {
            $autoload = $disable_autoload ? ", autoload = 'no'" : '';
            $changed = $wpdb->query( $wpdb->prepare(
                "UPDATE `{$wpdb->options}` SET option_value = %s{$autoload} WHERE option_name = %s AND " . self::FINGERPRINT . ' = %s',
                $value, $option, $fingerprint
            ) );
        }

        if ( false !== $changed ) {
            wp_cache_delete( $option, 'options' );
            wp_cache_delete( 'alloptions', 'options' );
            wp_cache_delete( 'notoptions', 'options' );
        }
        return $changed;
    }

    private static function can_store( string $value ): bool {
        global $wpdb;
        if ( ! preg_match( '/[\x80-\xff]/', $value ) ) { return true; }

        $charset = $wpdb->get_col_charset( $wpdb->options, 'option_value' );
        if ( false === $charset || 'utf8mb4' === $charset || 'binary' === $charset ) { return true; }
        if ( ! is_string( $charset ) || ! preg_match( '/^[a-z0-9_]+$/D', $charset ) ) { return false; }

        // WordPress can run without strict SQL mode. Reject lossy conversion before it corrupts serialized string lengths.
        // Keep the validation query ASCII so wpdb cannot mistake serialized text for a table reference.
        $round_trip = $wpdb->get_var( $wpdb->prepare(
            "SELECT SHA1(CAST(CAST(CONVERT(CAST(UNHEX(%s) AS CHAR) USING {$charset}) AS CHAR) AS BINARY))",
            bin2hex( $value )
        ) );
        return sha1( $value ) === $round_trip;
    }
}
