<?php
/** Retain localized editorial values from explicitly linked native reference pages. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Layout_Locales {
    private static function normalize( string $locale ): string { return strtolower( str_replace( '_', '-', $locale ) ); }

    private static function fields( string $builder, array $blueprint ): array {
        $adapter = Nova_Bridge_Suite_Recipe_Engine::adapter( $builder );
        if ( is_wp_error( $adapter ) || ! is_callable( [ $adapter, 'describe' ] ) ) { return []; }
        $description = $adapter->describe( $blueprint );
        if ( is_wp_error( $description ) ) { return []; }
        $fields = [];
        foreach ( $description['fields'] ?? [] as $field ) {
            if ( empty( $field['writable'] ) || ! is_string( $field['target'] ?? null ) || ! is_string( $field['value'] ?? null ) || ! empty( $field['native_post'] ) ) { continue; }
            $format = $field['format'] ?? '';
            if ( ! in_array( $format, [ 'text', 'html', 'url' ], true ) ) { continue; }
            $fields[$field['target']] = $field;
        }
        return $fields;
    }

    /** A matching field must retain its native control and position within the reference structure. */
    private static function shape( array $fields ): array {
        $shape = [];
        foreach ( $fields as $path => $field ) {
            $shape[$path] = array_intersect_key( $field, array_flip( [ 'format', 'kind', 'element_type', 'node_target' ] ) );
        }
        ksort( $shape );
        return $shape;
    }

    private static function field_identity( array $field ): string {
        $id = $field['native_node_id'] ?? $field['locator']['node_id'] ?? '';
        $node = $field['node_target'] ?? '';
        if ( ! is_string( $id ) || '' === $id || ! is_string( $node ) || '' === $node ) { return ''; }
        return wp_json_encode( [ $id, $field['element_type'] ?? '', $field['kind'] ?? '', $field['format'], substr( $field['target'], strlen( $node ) ) ] );
    }

    private static function translations( int $id, string $type ): array {
        if ( function_exists( 'pll_get_post_translations' ) && function_exists( 'pll_get_post_language' ) ) {
            $rows = [];
            foreach ( (array) pll_get_post_translations( $id ) as $code => $post_id ) {
                $rows[] = [ 'reference_id' => (int) $post_id, 'code' => (string) $code, 'locale' => (string) pll_get_post_language( $post_id, 'locale' ) ];
            }
            return [ 'provider' => 'polylang', 'source_locale' => (string) pll_get_post_language( $id, 'locale' ), 'rows' => $rows ];
        }
        if ( defined( 'ICL_SITEPRESS_VERSION' ) || ( function_exists( 'has_filter' ) && has_filter( 'wpml_element_language_details' ) ) ) {
            $details = (array) apply_filters( 'wpml_element_language_details', null, [ 'element_id' => $id, 'element_type' => 'post_' . $type ] );
            $trid = $details['trid'] ?? null;
            $rows = [];
            foreach ( $trid ? (array) apply_filters( 'wpml_get_element_translations', null, $trid, 'post_' . $type ) : [] as $translation ) {
                $translation = (array) $translation;
                $code = (string) ( $translation['language_code'] ?? '' );
                $sitepress = $GLOBALS['sitepress'] ?? null;
                $locale = is_object( $sitepress ) && is_callable( [ $sitepress, 'get_locale' ] ) ? $sitepress->get_locale( $code ) : $code;
                $rows[] = [ 'reference_id' => (int) ( $translation['element_id'] ?? 0 ), 'code' => $code, 'locale' => is_string( $locale ) ? $locale : $code ];
            }
            $source_code = (string) ( $details['language_code'] ?? '' );
            $sitepress = $GLOBALS['sitepress'] ?? null;
            $source_locale = is_object( $sitepress ) && is_callable( [ $sitepress, 'get_locale' ] ) ? $sitepress->get_locale( $source_code ) : $source_code;
            return [ 'provider' => 'wpml', 'source_locale' => (string) $source_locale, 'rows' => $rows ];
        }
        if ( class_exists( 'TRP_Translate_Press' ) ) { return [ 'provider' => 'translatepress', 'source_locale' => '', 'rows' => [] ]; }
        if ( function_exists( 'weglot_get_service' ) ) { return [ 'provider' => 'weglot', 'source_locale' => '', 'rows' => [] ]; }
        return [ 'provider' => 'core', 'source_locale' => '', 'rows' => [] ];
    }

    public static function capture( array $resource, string $builder, array $blueprint, int $id ): array {
        $empty = [ 'version' => 1, 'provider' => 'core', 'source_locale' => '', 'variants' => [], 'unavailable' => [] ];
        if ( ! $id || ( $resource['type'] ?? '' ) === 'taxonomy' || empty( $resource['post_type'] ) ) { return $empty; }
        $translations = self::translations( $id, $resource['post_type'] );
        $result = array_replace( $empty, array_intersect_key( $translations, $empty ) );
        $fields = self::fields( $builder, $blueprint );
        foreach ( $translations['rows'] as $row ) {
            if ( $row['reference_id'] === $id ) { continue; }
            $post = get_post( $row['reference_id'] );
            if ( ! $post || $post->post_type !== $resource['post_type'] || ! current_user_can( 'edit_post', $post->ID ) ) {
                $result['unavailable'][] = [ 'locale' => $row['locale'], 'reason' => 'The linked reference cannot be read.' ]; continue;
            }
            $detected = Nova_Bridge_Suite_Recipe_Visual::detected_builder( $resource, $post->ID );
            if ( is_wp_error( $detected ) || $detected !== $builder ) {
                $result['unavailable'][] = [ 'locale' => $row['locale'], 'reason' => 'The linked reference uses a different native design.' ]; continue;
            }
            $design = 'core' === $builder ? Nova_Bridge_Suite_Recipe_Core::design( $resource, $post->ID, 'static' )
                : ( 'acf' === $builder ? Nova_Bridge_Suite_Recipe_ACF::design( $resource['post_type'], $post->ID, (string) get_page_template_slug( $post->ID ), '', true ) : Nova_Bridge_Suite_Recipe_Builders::capture( $builder, $post->ID ) );
            $translated = is_wp_error( $design ) ? [] : self::fields( $builder, $design['blueprint'] );
            $same_structure = self::shape( $fields ) === self::shape( $translated );
            $identities = [];
            foreach ( $translated as $field ) {
                $identity = self::field_identity( $field );
                if ( '' !== $identity ) { $identities[$identity][] = $field; }
            }
            $values = []; $matched = 0;
            foreach ( $fields as $path => $field ) {
                $matches = $identities[self::field_identity( $field )] ?? [];
                $match = count( $matches ) === 1 ? $matches[0] : ( $same_structure ? ( $translated[$path] ?? null ) : null );
                if ( $match ) {
                    ++$matched;
                    if ( $field['value'] !== $match['value'] ) { $values[$path] = [ 'format' => $field['format'], 'value' => $match['value'] ]; }
                }
            }
            if ( $matched ) { $result['variants'][] = array_merge( $row, [ 'values' => $values ] ); }
            if ( $matched !== count( $fields ) ) {
                $result['unavailable'][] = [ 'locale' => $row['locale'], 'reason' => 'Some fields have no matching native control in the linked reference.' ];
            }
        }
        return $result;
    }

    private static function overlaps( string $path, array $targets ): bool {
        foreach ( $targets as $target ) {
            if ( $path === $target || 0 === strpos( $path, $target . '/' ) || 0 === strpos( $target, $path . '/' ) ) { return true; }
        }
        return false;
    }

    public static function retained( array $snapshot, array $recipe ): array {
        $targets = array_column( $recipe['bindings'] ?? [], 'target' );
        $targets = array_merge( $targets, array_column( $recipe['omissions'] ?? [], 'target' ) );
        foreach ( $snapshot['variants'] as &$variant ) {
            foreach ( array_keys( $variant['values'] ) as $path ) { if ( self::overlaps( $path, $targets ) ) { unset( $variant['values'][$path] ); } }
        }
        unset( $variant );
        return $snapshot;
    }

    /** Validate frozen values without reading the reference or depending on its continued existence. */
    public static function validate( array $snapshot, string $builder, array $blueprint, array $mapped ): void {
        if ( ( $snapshot['version'] ?? null ) !== 1 || ! in_array( $snapshot['provider'] ?? '', [ 'polylang', 'wpml', 'translatepress', 'weglot', 'core' ], true ) || ! is_array( $snapshot['variants'] ?? null ) ) { throw new InvalidArgumentException( 'The saved locale variants are invalid.' ); }
        $fields = self::fields( $builder, $blueprint ); $seen = [];
        foreach ( $snapshot['variants'] as $variant ) {
            if ( ! is_string( $variant['locale'] ?? null ) || ! preg_match( '/^[a-z]{2,3}(?:[-_][a-z0-9]{2,8})*$/iD', $variant['locale'] ) || ! is_string( $variant['code'] ?? null ) || ! preg_match( '/^[a-z]{2,3}(?:[-_][a-z0-9]{2,8})*$/iD', $variant['code'] ) || ! is_int( $variant['reference_id'] ?? null ) || $variant['reference_id'] < 1 || ! is_array( $variant['values'] ?? null ) ) { throw new InvalidArgumentException( 'A saved locale variant has invalid native identity.' ); }
            foreach ( array_unique( [ self::normalize( $variant['locale'] ), self::normalize( $variant['code'] ) ] ) as $alias ) {
                if ( isset( $seen[$alias] ) ) { throw new InvalidArgumentException( 'Saved locale variants have ambiguous language identities.' ); } $seen[$alias] = true;
            }
            foreach ( $variant['values'] as $path => $entry ) {
                if ( ! isset( $fields[$path] ) || self::overlaps( $path, $mapped ) || ! is_array( $entry ) || ( $entry['format'] ?? '' ) !== $fields[$path]['format'] || ! is_string( $entry['value'] ?? null ) ) { throw new InvalidArgumentException( 'A saved locale value no longer matches a preserved native field.' ); }
            }
        }
    }

    public static function apply( array $snapshot, string $locale, array $blueprint, $adapter ): array {
        foreach ( $snapshot['variants'] as $variant ) {
            if ( ! in_array( self::normalize( $locale ), [ self::normalize( $variant['locale'] ), self::normalize( $variant['code'] ) ], true ) ) { continue; }
            foreach ( $variant['values'] as $path => $entry ) {
                $blueprint = $adapter->bind( $blueprint, [ 'target' => $path, 'format' => $entry['format'] ], $entry['value'] );
                if ( is_wp_error( $blueprint ) ) { throw new InvalidArgumentException( $blueprint->get_error_message() ); }
            }
            break;
        }
        return $blueprint;
    }

    public static function summary( array $snapshot, bool $saved = false ): array {
        $summary = [ 'provider' => $snapshot['provider'], 'source_locale' => $snapshot['source_locale'], 'saved' => $saved, 'locales' => [], 'fields' => [], 'unavailable' => $snapshot['unavailable'] ?? [] ];
        foreach ( $snapshot['variants'] as $variant ) {
            $summary['locales'][] = [ 'locale' => $variant['locale'], 'reference_id' => $variant['reference_id'], 'fields' => count( $variant['values'] ) ];
            foreach ( $variant['values'] as $path => $entry ) { $summary['fields'][$path][] = $variant['locale']; }
        }
        return $summary;
    }
}
