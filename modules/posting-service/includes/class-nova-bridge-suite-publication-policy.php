<?php
/** Application test URLs use drafts for native post destinations. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Publication_Policy {
    public static function test_url( $url ): bool {
        if ( ! is_string( $url ) || '' === $url ) { return false; }
        $path = parse_url( $url, PHP_URL_PATH );
        return is_string( $path ) && 1 === preg_match( '#/(?:lorem-ipsum-demo-informative|lorem-ipsum-demo-service|lorem-ipsum-demo-category)(?:/|$)#i', rawurldecode( $path ) );
    }

    public static function publication( array $content, string $configured, string $existing_status = '' ): string {
        $test = self::test_url( $content['url'] ?? null );
        if ( ! array_key_exists( 'publication_status', $content ) ) { return $test ? 'draft' : $configured; }
        $requested = $content['publication_status'];
        if ( ! in_array( $requested, [ 'draft', 'publish' ], true ) ) { return 'unsupported'; }
        // Draft intent preserves existing pages, including status-bearing demo retries.
        if ( '' !== $existing_status && ( $test || 'draft' === $requested ) ) { return $existing_status; }
        return $test ? 'draft' : $requested;
    }

    public static function allows_shared_test( $provider ): bool {
        return is_array( $provider ) && in_array( $provider['provider'] ?? '', [ 'weglot', 'gtranslate' ], true );
    }

    public static function validate_shared_test_target( array $content, string $intended, string $resolved ) {
        if ( self::test_url( $content['url'] ?? null ) && $intended !== $resolved ) {
            return new WP_Error( 'nova_translation_test_url', 'A shared-language test requires an exact match with the resolved demo URL.', [ 'status' => 409 ] );
        }
        return true;
    }

    public static function unsupported(): WP_Error {
        return new WP_Error( 'nova_test_delivery_draft_unavailable', 'This test URL requires a draft. Shared translated views have no independent draft state, so this delivery was held before public content could change.', [ 'status' => 409 ] );
    }
}
