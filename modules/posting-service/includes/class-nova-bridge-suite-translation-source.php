<?php
/** Resolve a translated delivery's local source from the retained NOVA URL identity. */
defined( 'ABSPATH' ) || exit;

final class Nova_Bridge_Suite_Translation_Source {
    private static function language( $value ): string {
        return is_string( $value ) ? strtolower( str_replace( '_', '-', trim( $value ) ) ) : '';
    }

    private static function family_key( array $identity ): string {
        return 'nova_translation_family_' . hash( 'sha256', $identity['site_id'] . ':' . $identity['url_id'] );
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
        $family = $identity['site_id'] . ':' . $identity['url_id'];
        $anchor = get_option( self::family_key( $identity ), null );
        if ( is_array( $anchor ) ) { $languages[] = $anchor['language'] ?? ''; }
        $suffixes = [ '' ];
        foreach ( $languages as $language ) {
            $language = self::language( $language );
            if ( '' !== $language ) { $suffixes[] = ':' . $language; }
        }
        $records = [];
        foreach ( [ 'nova_layout_page_', 'nova_cpt_url_' ] as $prefix ) {
            foreach ( array_unique( $suffixes ) as $suffix ) {
                $row = get_option( $prefix . hash( 'sha256', $family . $suffix ), null );
                if ( ! is_array( $row ) || ( $row['site_id'] ?? null ) !== $identity['site_id'] ||
                    ( $row['url_id'] ?? null ) !== $identity['url_id'] || ( $row['state'] ?? '' ) !== 'committed' ) { continue; }
                $id = (int) ( $row['post_id'] ?? 0 );
                if ( $id < 1 || empty( $row['language'] ) || ( '' !== $suffix && ':' . self::language( $row['language'] ) !== $suffix ) ) { continue; }
                $owner = get_post_meta( $id, 'nova_layout_page_' === $prefix ? '_nova_stored_layout_owner' : '_nova_owned_cpt_owner', true );
                // Older CPT records can predate the owner marker. When present it must still agree.
                if ( '' !== $owner && ( ( $owner['site_id'] ?? null ) !== $identity['site_id'] || ( $owner['url_id'] ?? null ) !== $identity['url_id'] ) ) { continue; }
                $records[$id] = $row;
            }
        }
        return $records;
    }

    public static function post_id( array $content, array $configuration, string $source_language ) {
        $source = self::language( $source_language );
        $type = $configuration['local']['routing']['post_type'] ?? $configuration['local']['stored_layout']['post_type'] ?? '';
        $languages = [ $source, explode( '-', $source )[0], get_option( 'WPLANG', '' ) ?: 'en_US' ];
        $matches = [];
        foreach ( self::records( $content, $languages ) as $id => $row ) {
            $language = self::language( $row['language'] );
            if ( $source !== $language && ( explode( '-', $language )[0] !== explode( '-', $source )[0] || ( false !== strpos( $source, '-' ) && false !== strpos( $language, '-' ) ) ) ) { continue; }
            $post = get_post( $id );
            if ( $post && 'publish' === $post->post_status && $post->post_type === $type ) { $matches[] = $id; }
        }
        if ( ! $matches ) { return new WP_Error( 'nova_translation_source_pending', 'Publish the original-language page for this NOVA URL before delivering its translation.' ); }
        if ( count( $matches ) > 1 ) { return new WP_Error( 'nova_translation_source_ambiguous', 'More than one original page matches this NOVA URL and source language.' ); }
        return current_user_can( 'edit_post', $matches[0] ) ? $matches[0] : new WP_Error( 'nova_translation_source_permission', 'The publishing user cannot edit the original page.' );
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
        if ( $link && null === get_option( $key, null ) ) { add_option( $key, [ 'language' => $identity['language'] ], '', false ); }
        if ( ! $native ) { return true; }
        foreach ( self::records( $identity, Nova_Bridge_Suite_Stored_Layout_Language::sibling_locales( $target ) ) as $sibling => $row ) {
            if ( $sibling === $id ) { continue; }
            $post = get_post( $sibling );
            if ( ! $post || 'trash' === $post->post_status || $post->post_type !== $plan['post_type'] ) { continue; }
            if ( ! Nova_Bridge_Suite_Stored_Layout_Language::different_language( $sibling, $target ) ) {
                return new WP_Error( 'nova_translation_family_conflict', 'Another native page for this NOVA URL already owns the intended language.' );
            }
            $linked = $link ? Nova_Bridge_Suite_Stored_Layout_Language::link_sibling( $id, $target, $sibling )
                : Nova_Bridge_Suite_Stored_Layout_Language::check_sibling( $id, $target, $sibling );
            if ( is_wp_error( $linked ) ) { return $linked; }
        }
        return true;
    }
}
