<?php
/** Store localized copy alongside its source post. */
defined( 'ABSPATH' ) || exit;

trait Nova_WordPress_Localized_Post_Storage {
    public static function get( int $id, string $code ): ?array {
        $stored = get_post_meta( $id, self::key( $code ), true );
        return is_array( $stored ) ? $stored : null;
    }

    private static function key( string $code ): string {
        return static::META_PREFIX . str_replace( '-', '_', strtolower( $code ) );
    }

    public static function apply( int $id, string $code, array $fields ) {
        $current = self::get( $id, $code ) ?? [];
        $patch = $fields;
        if ( isset( $patch['meta'] ) ) { $patch['meta'] = array_replace( $current['meta'] ?? [], $patch['meta'] ); }
        $updated = array_replace( $current, $patch, [ 'language' => $code, 'source_post_id' => $id ] );
        update_post_meta( $id, self::key( $code ), wp_slash( $updated ) );
        if ( self::get( $id, $code ) !== $updated ) {
            return new WP_Error( 'nova_translation_readback', 'WordPress did not retain the localized copy.' );
        }
        return true;
    }
}
