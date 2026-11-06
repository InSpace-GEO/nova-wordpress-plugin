<?php
/** Local layouts and publishing authority belong to one service origin and site. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_WordPress_Environment {
    public static function connection(): array {
        return is_callable( [ 'Nova_Bridge_Suite_Posting_Settings', 'connection' ] ) ? Nova_Bridge_Suite_Posting_Settings::connection() : [];
    }

    public static function identity( ?array $connection = null ): string {
        $connection = $connection ?? self::connection();
        $origin = strtolower( rtrim( (string) ( $connection['base_url'] ?? '' ), '/' ) );
        $origin = preg_replace( '/:443$/D', '', $origin );
        return hash( 'sha256', $origin . "\n" . strtolower( (string) ( $connection['site_id'] ?? '' ) ) );
    }

    /** Keep the pre-upgrade keys for their original connection, without copying history. */
    public static function legacy_owner( array $connection ): string {
        // The withdrawn build could already have switched without recording the owner.
        // Retain that ambiguous data, but never approve it for another destination.
        return $connection['layout_legacy_environment'] ?? ( ! empty( $connection['history_archived'] ) ? 'unassigned' : self::identity( $connection ) );
    }

    public static function key( string $legacy, ?array $connection = null ): string {
        $active = self::connection();
        $connection = $connection ?? $active;
        // Standalone module consumers without connection settings keep their established keys.
        $owner = self::legacy_owner( $active ?: $connection );
        $prefix = 0 === strpos( $legacy, '_' ) ? '_nova_env_' : 'nova_env_';
        return self::identity( $connection ) === $owner ? $legacy : $prefix . hash( 'sha256', self::identity( $connection ) . "\n" . $legacy );
    }
}
