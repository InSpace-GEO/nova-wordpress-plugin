<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_WordPress_Native_Language {
    /** Match active WPML and Polylang records while preserving their native code spelling. */
    public static function code( string $language, array $records ): ?string {
        $requested = strtolower( str_replace( '_', '-', $language ) );
        if ( '' === $requested ) { return null; }
        $regional = preg_match( '/^([a-z]{2,3})-(?:[a-z]{2}|[0-9]{3})$/D', $requested, $parts );
        $exact = [];
        $family = [];
        foreach ( $records as $record ) {
            $code = strtolower( str_replace( '_', '-', $record['code'] ) );
            $locale = strtolower( str_replace( '_', '-', $record['locale'] ) );
            if ( $requested === $code || $requested === $locale ) { $exact[] = $record['code']; }
            if ( $regional && ( explode( '-', $code )[0] === $parts[1] || explode( '-', $locale )[0] === $parts[1] ) ) {
                $family[] = $record['code'];
            }
        }
        if ( $exact ) { return count( $exact ) === 1 ? $exact[0] : null; }
        return count( $family ) === 1 && strtolower( $family[0] ) === $parts[1] ? $family[0] : null;
    }
}
