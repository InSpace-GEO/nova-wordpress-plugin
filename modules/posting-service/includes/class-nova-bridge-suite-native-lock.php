<?php
/** Names for MySQL named locks (GET_LOCK), scoped to one WordPress installation. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Native_Lock {
    /**
     * MySQL named locks are shared by every database on the server, so installations that share
     * a MySQL server must not compute the same name. The database name, together with the table
     * prefix the callers already include in $identity, identifies one installation.
     */
    public static function name( $db, string $namespace, string $identity, int $length ): string {
        return $namespace . substr( hash( 'sha256', self::installation( $db ) . ':' . $identity ), 0, $length );
    }

    private static function installation( $db ): string {
        // wpdb exposes its protected dbname for reading; drop-ins may not, but DB_NAME is always configured.
        $name = is_object( $db ) && isset( $db->dbname ) && is_scalar( $db->dbname ) ? (string) $db->dbname : '';
        if ( '' === $name && defined( 'DB_NAME' ) && is_scalar( DB_NAME ) ) { $name = (string) DB_NAME; }
        return 'db=' . $name;
    }
}
