<?php
/** Resolve a translated delivery's local source from the retained NOVA URL identity. */
defined( 'ABSPATH' ) || exit;

final class Nova_Bridge_Suite_Translation_Source {
    private static function language( $value ): string {
        return is_string( $value ) ? strtolower( str_replace( '_', '-', trim( $value ) ) ) : '';
    }

    private static function family_key( array $identity ): string {
        if ( isset( $identity['url_family'] ) ) {
            return 'nova_translation_group_' . hash( 'sha256', $identity['site_id'] . ':' . $identity['url_family']['url_group_id'] );
        }
        return 'nova_translation_family_' . hash( 'sha256', $identity['site_id'] . ':' . $identity['url_id'] );
    }

    /** A group lock supplements, rather than replaces, the per-URL publication lock. */
    public static function group_lock( array $identity ): ?string {
        return isset( $identity['url_family'] ) ? 'publication-group:' . $identity['site_id'] . ':' . $identity['url_family']['url_group_id'] : null;
    }

    private static function group_error( string $message ) {
        return new WP_Error( 'nova_translation_family_conflict', $message );
    }

    private static function group_anchor( array $identity ) {
        $anchor = get_option( self::family_key( $identity ), null );
        if ( isset( $identity['url_family'] ) && null !== $anchor &&
            ( ! is_array( $anchor ) || ( $anchor['primary_url_id'] ?? null ) !== $identity['url_family']['primary_url_id'] || ! is_array( $anchor['members'] ?? null ) ) ) {
            return self::group_error( 'The retained URL group identifies a different original. Review the existing language associations.' );
        }
        return $anchor;
    }

    /** Only the explicit primary identity may adopt a pre-group record, after checking its native URL. */
    private static function retained_primary( array $identity, array $row, int $id ): bool {
        $family = $identity['url_family'];
        if ( $row['url_id'] !== $family['primary_url_id'] || ! Nova_Bridge_Suite_Stored_Layout_Language::same_language_slot( $row['language'], $family['primary_language'] ) ) { return false; }
        $expected = Nova_Bridge_Suite_Stored_Layout_Language::delivery_url( $family['primary_url'], $family['primary_language'] );
        if ( is_wp_error( $expected ) ) { return false; }
        $expected = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $expected );
        $post = get_post( $id );
        if ( ! $post ) { return false; }
        $actual = Nova_Bridge_Suite_Mapped_Content_Target::native_url( 'post_type:' . $post->post_type, 'post', $id );
        $actual = is_wp_error( $actual ) ? $actual : Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $actual );
        return ! is_wp_error( $expected ) && ! is_wp_error( $actual ) && $expected['identity'] === $actual['identity'];
    }

    /** Drafts may have no public permalink yet. Only a verified other language may share the slug. */
    public static function reserved( string $type, array $target, string $format, string $owner ) {
        global $wpdb;
        foreach ( [ 'posts', 'postmeta' ] as $table ) {
            if ( ! isset( $wpdb->$table ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->$table ) ) { return new WP_Error( 'nova_translation_storage', 'Native ownership storage is unavailable.' ); }
        }
        $query = 'SELECT p.ID FROM `' . $wpdb->posts . '` p WHERE p.post_type = %s AND p.post_parent = %d AND p.post_name = %s AND p.post_status <> %s AND (p.post_content_filtered LIKE %s OR EXISTS (SELECT 1 FROM `' . $wpdb->postmeta . '` m WHERE m.post_id = p.ID AND m.meta_key = %s))';
        $args = [ $type, (int) $target['parent'], $target['slug'], 'trash', $format . ':%', $owner ];
        $translated = in_array( $target['native_language']['provider'] ?? '', [ 'polylang', 'wpml' ], true );
        $candidates = $translated ? $wpdb->get_col( $wpdb->prepare( $query, ...$args ) ) : array_filter( [ $wpdb->get_var( $wpdb->prepare( $query . ' LIMIT 1', ...$args ) ) ] );
        if ( ! is_array( $candidates ) || ! empty( $wpdb->last_error ) ) { return new WP_Error( 'nova_translation_storage', 'Native ownership could not be inspected.' ); }
        foreach ( $candidates as $id ) {
            if ( ! $translated || ! Nova_Bridge_Suite_Stored_Layout_Language::different_language( (int) $id, $target ) ) { return true; }
        }
        return false;
    }

    /** Look up original pre-locale records and the same locale keys the two native writers use. */
    private static function records( array $identity, array $languages ): array {
        $anchor = get_option( self::family_key( $identity ), null );
        if ( is_array( $anchor ) ) { $languages[] = $anchor['language'] ?? ''; }
        $url_ids = [ $identity['url_id'] ];
        if ( isset( $identity['url_family'] ) ) {
            $url_ids[] = $identity['url_family']['primary_url_id'];
            $languages[] = $identity['url_family']['primary_language'];
            foreach ( $anchor['members'] ?? [] as $member ) {
                $url_ids[] = $member['url_id'];
                $languages[] = $member['language'];
            }
        }
        $suffixes = [ '' ];
        foreach ( $languages as $language ) {
            $language = self::language( $language );
            if ( '' !== $language ) { $suffixes[] = ':' . $language; }
        }
        $records = [];
        foreach ( array_unique( $url_ids ) as $url_id ) {
        $family = $identity['site_id'] . ':' . $url_id;
        foreach ( [ 'nova_layout_page_', 'nova_cpt_url_' ] as $prefix ) {
            foreach ( array_unique( $suffixes ) as $suffix ) {
                $row = get_option( $prefix . hash( 'sha256', $family . $suffix ), null );
                if ( ! is_array( $row ) || ( $row['site_id'] ?? null ) !== $identity['site_id'] ||
                    ( $row['url_id'] ?? null ) !== (string) $url_id || ( $row['state'] ?? '' ) !== 'committed' ) { continue; }
                $id = (int) ( $row['post_id'] ?? 0 );
                if ( $id < 1 || empty( $row['language'] ) || ( '' !== $suffix && ':' . self::language( $row['language'] ) !== $suffix ) ) { continue; }
                $owner = get_post_meta( $id, 'nova_layout_page_' === $prefix ? '_nova_stored_layout_owner' : '_nova_owned_cpt_owner', true );
                // Older CPT records can predate the owner marker. When present it must still agree.
                if ( '' !== $owner && ( ( $owner['site_id'] ?? null ) !== $identity['site_id'] || ( $owner['url_id'] ?? null ) !== (string) $url_id ) ) { continue; }
                if ( isset( $identity['url_family'] ) ) {
                    if ( isset( $row['url_family'] ) ) {
                        if ( $row['url_family']['url_group_id'] !== $identity['url_family']['url_group_id'] ||
                            $row['url_family']['primary_url_id'] !== $identity['url_family']['primary_url_id'] ) { continue; }
                    } elseif ( (string) $url_id !== $identity['url_id'] && ! self::retained_primary( $identity, $row, $id ) ) { continue; }
                }
                $records[$id] = $row;
            }
        }
        }
        return $records;
    }

    public static function post_id( array $content, array $configuration, string $source_language ) {
        $source = self::language( $source_language );
        $type = $configuration['local']['routing']['post_type'] ?? $configuration['local']['stored_layout']['post_type'] ?? '';
        $languages = [ $source, explode( '-', $source )[0], get_option( 'WPLANG', '' ) ?: 'en_US' ];
        $matches = [];
        $lookup = $content;
        if ( isset( $content['url_family'] ) ) {
            if ( ! Nova_Bridge_Suite_Translation_Writer::same_language_slot( $content['url_family']['primary_language'], $source_language ) ) {
                return new WP_Error( 'nova_translation_source_language', 'The URL group primary must use the translation provider original language.' );
            }
            $anchor = self::group_anchor( $content );
            if ( is_wp_error( $anchor ) ) { return $anchor; }
            $lookup['url_id'] = $content['url_family']['primary_url_id'];
        }
        foreach ( self::records( $lookup, $languages ) as $id => $row ) {
            if ( isset( $content['url_family'] ) ) {
                if ( $row['url_id'] !== $content['url_family']['primary_url_id'] ) { continue; }
                if ( ! self::retained_primary( $content, $row, $id ) ) {
                    return new WP_Error( 'nova_translation_source_identity', 'The retained original language or permalink differs from the URL group primary.' );
                }
            }
            $language = self::language( $row['language'] );
            if ( $source !== $language && ( explode( '-', $language )[0] !== explode( '-', $source )[0] || ( false !== strpos( $source, '-' ) && false !== strpos( $language, '-' ) ) ) ) { continue; }
            $post = get_post( $id );
            if ( $post && 'publish' === $post->post_status && $post->post_type === $type ) { $matches[] = $id; }
        }
        if ( ! $matches ) { return new WP_Error( 'nova_translation_source_pending', 'Publish the original-language page for this NOVA URL before delivering its translation.' ); }
        if ( count( $matches ) > 1 ) { return new WP_Error( 'nova_translation_source_ambiguous', 'More than one original page matches this NOVA URL and source language.' ); }
        return current_user_can( 'edit_post', $matches[0] ) ? $matches[0] : new WP_Error( 'nova_translation_source_permission', 'The publishing user cannot edit the original page.' );
    }

    /** Includes an original delivery frozen before URL-family metadata was introduced. */
    public static function is_provider_original( array $content ): bool {
        return ( class_exists( 'TRP_Translate_Press' ) || function_exists( 'weglot_get_service' ) ) &&
            null === Nova_Bridge_Suite_Translation_Writer::provider( $content['language'] ?? '' );
    }

    public static function depends_on( array $waiting, array $original ): bool {
        if ( ! isset( $waiting['url_family'] ) || ! self::is_provider_original( $original ) ) { return false; }
        $expected = Nova_Bridge_Suite_Translation_Writer::delivery_url( $waiting['url_family']['primary_url'], $waiting['url_family']['primary_language'] );
        $actual = Nova_Bridge_Suite_Translation_Writer::delivery_url( $original['url'] ?? '', $original['language'] );
        return ! is_wp_error( $expected ) && ! is_wp_error( $actual ) && $expected === $actual &&
            ( $waiting['site_id'] ?? null ) === ( $original['site_id'] ?? null ) &&
            ( ! isset( $original['url_family'] ) || $waiting['url_family']['url_group_id'] === $original['url_family']['url_group_id'] ) &&
            $waiting['url_family']['primary_url_id'] === $original['url_id'] &&
            Nova_Bridge_Suite_Translation_Writer::same_language_slot( $waiting['url_family']['primary_language'], $original['language'] );
    }

    /** Read-only: a different native translation must never be discovered only after the page was replaced. */
    public static function preflight( array $plan, int $id ) {
        return self::family( $plan, $id, false );
    }

    /** Called under the publication lock after checking the native language and permalink. */
    public static function link( array $plan, int $id ) {
        return self::family( $plan, $id, true );
    }

    private static function family( array $plan, int $id, bool $link ) {
        $target = $plan['target'];
        $native = in_array( $target['native_language']['provider'] ?? '', [ 'wpml', 'polylang' ], true );
        $dictionary = class_exists( 'TRP_Translate_Press' ) || function_exists( 'weglot_get_service' );
        if ( ! $native && ( ! $link || ! $dictionary ) ) { return true; }
        $identity = $plan['delivery_identity'];
        $key = self::family_key( $identity );
        $anchor = self::group_anchor( $identity );
        if ( is_wp_error( $anchor ) ) { return $anchor; }
        if ( ! isset( $identity['url_family'] ) && $link && null === $anchor ) { add_option( $key, [ 'language' => $identity['language'] ], '', false ); }
        if ( ! $native ) { return true; }
        foreach ( self::records( $identity, Nova_Bridge_Suite_Stored_Layout_Language::sibling_locales( $target ) ) as $sibling => $row ) {
            if ( $sibling === $id ) { continue; }
            $post = get_post( $sibling );
            if ( ! $post || 'trash' === $post->post_status ) { continue; }
            if ( $post->post_type !== $plan['post_type'] ) {
                if ( isset( $identity['url_family'] ) ) { return self::group_error( 'Localized items in this URL group must use the same native post type.' ); }
                continue;
            }
            if ( ! Nova_Bridge_Suite_Stored_Layout_Language::different_language( $sibling, $target ) ) {
                return new WP_Error( 'nova_translation_family_conflict', 'Another native page for this NOVA URL already owns the intended language.' );
            }
            $linked = $link ? Nova_Bridge_Suite_Stored_Layout_Language::link_sibling( $id, $target, $sibling )
                : Nova_Bridge_Suite_Stored_Layout_Language::check_sibling( $id, $target, $sibling );
            if ( is_wp_error( $linked ) ) { return $linked; }
        }
        if ( $link && isset( $identity['url_family'] ) ) {
            $next = $anchor ?? [ 'primary_url_id' => $identity['url_family']['primary_url_id'], 'members' => [] ];
            $member = [ 'url_id' => $identity['url_id'], 'language' => self::language( $identity['language'] ) ];
            $next['members'][$member['url_id'] . ':' . $member['language']] = $member;
            if ( null === $anchor ) { add_option( $key, $next, '', false ); }
            elseif ( $anchor !== $next ) { update_option( $key, $next, false ); }
            if ( get_option( $key, null ) !== $next ) { return self::group_error( 'The local URL group could not be retained.' ); }
        }
        return true;
    }
}
