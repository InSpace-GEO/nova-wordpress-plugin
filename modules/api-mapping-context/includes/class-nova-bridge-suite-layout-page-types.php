<?php
/** Source content types are independent of the native WordPress destination. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Layout_Page_Types {
    public const TYPES = [ 'service', 'informative', 'category' ];

    public static function valid( $types ): bool {
        return is_array( $types ) && count( $types ) >= 1 && count( $types ) <= 3
            && array_keys( $types ) === range( 0, count( $types ) - 1 )
            && count( array_filter( $types, 'is_string' ) ) === count( $types )
            && ! array_diff( $types, self::TYPES ) && count( array_unique( $types ) ) === count( $types );
    }

    public static function selected( array $record ): array {
        if ( array_key_exists( 'posting_page_types', $record ) ) {
            return self::valid( $record['posting_page_types'] ) ? $record['posting_page_types'] : [];
        }
        $resource = $record['resource_id'] ?? '';
        return [ $record['posting_page_type'] ?? ( 'post_type:post' === $resource ? 'informative' : ( 'taxonomy:product_cat' === $resource ? 'category' : 'service' ) ) ];
    }

    public static function accepts( array $profile, $type ): bool {
        $types = $profile['local']['profile_page_types'] ?? null;
        // Old retained revisions keep their original single-type contract.
        return null === $types ? $type === ( $profile['template']['page_type'] ?? null )
            : self::valid( $types ) && in_array( $type, $types, true );
    }
}
