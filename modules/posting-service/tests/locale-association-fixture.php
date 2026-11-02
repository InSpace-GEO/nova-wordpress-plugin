<?php
/** Shared public-writer regressions; the host fixtures supply WordPress I/O. */
function check_locale_associations( string $writer, callable $reset, callable $fixture, callable $operation, string $prefix ): void {
    $delivery = static function ( string $locale, string $version = '501', string $path = 'article' ) use ( $fixture ): array {
        [ $content, $configuration ] = $fixture( $version );
        $content['language'] = $locale; $content['url'] = 'https://example.test/' . $path . '/';
        if ( isset( $configuration['local']['routing']['locale'] ) ) {
            $configuration['local']['routing']['locale'] = $locale;
            $configuration['profile_digest'] = hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $configuration['local'] ) );
        }
        if ( $path !== 'article' ) { $content['content_item_id'] = '202'; }
        return [ $content, $configuration ];
    };
    $plan = static function ( string $locale, string $version, string $path, int $op ) use ( $writer, $delivery, $operation ) {
        [ $content, $configuration ] = $delivery( $locale, $version, $path );
        return $writer::plan( $content, $configuration, [ 'operation_id' => $operation( $op ) ] );
    };
    $save = static function ( string $locale, string $version, string $path, int $op ) use ( $writer, $plan, $operation ): array {
        return ok( $writer::apply( ok( $plan( $locale, $version, $path, $op ) ), $operation( $op ) ) );
    };
    $legacy = static function () use ( $prefix ): string {
        foreach ( $GLOBALS['options'] as $key => $value ) {
            if ( strpos( $key, $prefix ) !== 0 ) { continue; }
            unset( $GLOBALS['options'][$key] );
            $key = $prefix . hash( 'sha256', $value['site_id'] . ':' . $value['url_id'] );
            $GLOBALS['options'][$key] = $value; return $key;
        }
        throw new RuntimeException( 'Expected a retained native association.' );
    };

    $reset();
    $first = $save( 'en-US', '501', 'article', 201 );
    $second = $save( 'nl-NL', '501', 'translated', 202 );
    check( $first['post_id'] !== $second['post_id'] && $GLOBALS['inserts'] === 2, 'Two locales sharing a backend URL ID create distinct native pages.' );
    $second_title = get_post( $second['post_id'] )->post_title;
    $updated = $save( 'EN_us', '502', 'article', 203 );
    check( $updated['post_id'] === $first['post_id'] && get_post( $second['post_id'] )->post_title === $second_title, 'Canonical locale aliases update only their own native page.' );
    $first_title = get_post( $first['post_id'] )->post_title;
    $updated = $save( 'NL_nl', '502', 'translated', 204 );
    check( $updated['post_id'] === $second['post_id'] && get_post( $first['post_id'] )->post_title === $first_title && $GLOBALS['inserts'] === 2, 'Each locale updates independently without duplicate pages.' );
    check( is_wp_error( $plan( 'en-us', '501', 'article', 205 ) ), 'Stale content remains blocked within its canonical locale.' );

    $reset(); $first = $save( 'en-US', '501', 'article', 211 ); $key = $legacy(); $retained = $GLOBALS['options'][$key];
    check( is_wp_error( $plan( 'nl-NL', '502', 'article', 210 ) ), 'Another locale cannot rediscover and adopt the legacy page at the same native URL.' );
    $second = $save( 'nl-NL', '501', 'translated', 212 );
    check( $second['post_id'] !== $first['post_id'] && $GLOBALS['options'][$key] === $retained, 'Another locale never adopts or changes the legacy association.' );
    $updated = $save( 'EN_us', '502', 'article', 213 );
    check( $updated['post_id'] === $first['post_id'] && $GLOBALS['inserts'] === 2 && $GLOBALS['options'][$key] === $retained, 'A matching legacy locale upgrades lazily without duplication or changing the old record.' );
    $updated = $save( 'en-US', '503', 'article', 214 );
    check( $updated['post_id'] === $first['post_id'] && $GLOBALS['inserts'] === 2, 'The migrated locale association takes precedence over its retained legacy version.' );

    foreach ( [ 'scalar', 'language', 'site_id', 'url_id' ] as $invalid ) {
        $reset(); $save( 'en-US', '501', 'article', 221 ); $key = $legacy();
        if ( $invalid === 'scalar' ) { $GLOBALS['options'][$key] = 'corrupt'; }
        else { unset( $GLOBALS['options'][$key][$invalid] ); }
        check( is_wp_error( $plan( 'en-US', '502', 'article', 222 ) ) && $GLOBALS['inserts'] === 1, 'Malformed legacy ' . $invalid . ' requires reconciliation.' );
    }

    $reset(); $old = ok( $plan( 'en-US', '501', 'article', 231 ) ); $GLOBALS['crash_insert'] = true;
    check( is_wp_error( $writer::apply( $old, $operation( 231 ) ) ), 'The unfinished original operation exposes its uncertain insert.' );
    $key = $legacy(); $retained = $GLOBALS['options'][$key];
    check( is_wp_error( $plan( 'EN_us', '502', 'article', 232 ) ), 'A matching unfinished legacy association blocks a replacement operation.' );
    $second = $save( 'nl-NL', '501', 'translated', 233 );
    $proof = ok( $writer::recover( $old, $operation( 231 ) ) );
    $first = $proof['state'] === 'not_committed' ? ok( $writer::apply( $old, $operation( 231 ) ) ) : $proof;
    check( $first['post_id'] !== $second['post_id'] && $GLOBALS['inserts'] === 2 && $GLOBALS['options'][$key] === $retained, 'A retained pre-upgrade operation recovers its reserved page while another locale publishes independently.' );
    $updated = $save( 'en-us', '502', 'article', 234 );
    check( $updated['post_id'] === $first['post_id'] && $GLOBALS['inserts'] === 2, 'Recovery promotes the matching legacy reservation without a duplicate.' );
}
