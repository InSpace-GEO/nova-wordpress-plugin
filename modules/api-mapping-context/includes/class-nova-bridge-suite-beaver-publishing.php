<?php
/** Save a compiled Beaver layout onto the post already reserved by the delivery journal. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Beaver_Publishing {
    private const STATE = [ '_fl_builder_enabled', '_fl_builder_data', '_fl_builder_draft', '_fl_builder_draft_settings' ];

    private static function error( string $message ): WP_Error {
        return new WP_Error( 'nova_beaver_publication', $message, [ 'status' => 409 ] );
    }

    public static function support( string $type ): array {
        $object = get_post_type_object( $type );
        $allowed = class_exists( 'FLBuilderModel' ) && $object
            && current_user_can( $object->cap->edit_posts )
            && current_user_can( $object->cap->create_posts ?? $object->cap->edit_posts );
        return [ 'supported' => (bool) $allowed, 'reason' => $allowed ? '' : 'Activate Beaver Builder and allow the publishing user to create and edit this native post type.' ];
    }

    private static function arrays( $value ) {
        if ( is_object( $value ) ) { $value = get_object_vars( $value ); }
        if ( is_array( $value ) ) {
            foreach ( $value as $key => $child ) { $value[$key] = self::arrays( $child ); }
        }
        return $value;
    }

    private static function document( array $payload ) {
        require_once dirname( __DIR__ ) . '/adapters/beaver-document.php';
        $nodes = $payload['layout']['nodes'] ?? null;
        $meta = $payload['meta'] ?? [];
        if ( ! is_array( $nodes ) || ! $nodes || ! is_array( $meta ) || array_diff( array_keys( $meta ), [ '_fl_builder_data_settings' ] ) ) {
            return self::error( 'Synchronize a complete Beaver layout before publishing.' );
        }
        $plain = self::arrays( $nodes );
        foreach ( $plain as $key => $node ) {
            if ( ! is_array( $node ) || ( $node['node'] ?? null ) !== (string) $key
                || ! in_array( $node['type'] ?? '', [ 'row', 'column-group', 'column', 'module', 'box' ], true )
                || ! array_key_exists( 'settings', $node ) ) {
                return self::error( 'The compiled Beaver layout contains an invalid native node.' );
            }
        }
        $native_nodes = nova_mapping_bb_normalize_incoming_nodes( $nodes, $payload['layout']['array_paths'] ?? [] );
        $tree = nova_mapping_bb_flat_to_tree( $native_nodes );
        $adapter = Nova_Bridge_Suite_Recipe_Builders::adapter( 'beaver' );
        $valid = $adapter->validate_blueprint( [ 'document' => self::arrays( $tree ), 'meta' => $meta ] );
        if ( is_wp_error( $valid ) ) { return $valid; }
        return [ 'nodes' => $native_nodes, 'content' => nova_mapping_bb_tree_to_fallback_html( $tree ), 'meta' => $meta ];
    }

    public static function probe( string $type, array $payload, string $status ) {
        $support = self::support( $type );
        if ( ! $support['supported'] ) { return self::error( $support['reason'] ); }
        $document = self::document( $payload );
        if ( is_wp_error( $document ) ) { return $document; }
        return [ 'wordpress' => (string) ( $GLOBALS['wp_version'] ?? '' ), 'provider_version' => 'beaver-known-post-v1',
            'beaver' => defined( 'FL_BUILDER_VERSION' ) ? (string) FL_BUILDER_VERSION : '' ];
    }

    public static function snapshot( int $id, array $payload ) {
        $post = get_post( $id );
        if ( ! $post || ! current_user_can( 'edit_post', $id ) ) { return self::error( 'The retained Beaver post is not editable.' ); }
        $meta = [];
        foreach ( array_unique( array_merge( self::STATE, array_keys( $payload['meta'] ?? [] ) ) ) as $key ) {
            $meta[$key] = [ 'present' => metadata_exists( 'post', $id, $key ), 'value' => self::arrays( get_post_meta( $id, $key, true ) ) ];
        }
        return [ 'content' => (string) $post->post_content, 'meta' => $meta ];
    }

    public static function apply( int $id, array $payload, array $fields ) {
        $post = get_post( $id );
        if ( ! $post || ! current_user_can( 'edit_post', $id ) ) { return self::error( 'The retained Beaver post is not editable.' ); }
        $probe = self::probe( $post->post_type, $payload, $post->post_status );
        if ( is_wp_error( $probe ) ) { return $probe; }
        $document = self::document( $payload );
        $saved = wp_update_post( wp_slash( [ 'ID' => $id, 'post_content' => $document['content'],
            'post_title' => (string) ( $fields['title'] ?? $post->post_title ), 'post_excerpt' => (string) ( $fields['excerpt'] ?? '' ) ] ), true );
        if ( is_wp_error( $saved ) ) { return $saved; }
        if ( (int) $saved !== $id ) { return self::error( 'WordPress did not return the retained Beaver post ID.' ); }
        foreach ( $document['meta'] as $key => $value ) { update_post_meta( $id, $key, wp_slash( $value ) ); }
        update_post_meta( $id, '_fl_builder_enabled', true );
        update_post_meta( $id, '_fl_builder_data', nova_mapping_bb_slash_layout_data( $document['nodes'] ) );
        delete_post_meta( $id, '_fl_builder_draft' );
        delete_post_meta( $id, '_fl_builder_draft_settings' );
        nova_mapping_bb_clear_bb_caches( $id );
        clean_post_cache( $id );
        return self::verify( $id, $payload );
    }

    public static function verify( int $id, array $payload ) {
        $document = self::document( $payload );
        if ( is_wp_error( $document ) ) { return $document; }
        $state = self::snapshot( $id, $payload );
        if ( is_wp_error( $state ) ) { return $state; }
        $meta = $state['meta'];
        if ( $state['content'] !== $document['content'] || empty( $meta['_fl_builder_enabled']['value'] )
            || Nova_Bridge_Suite_Recipe_Engine::digest( $meta['_fl_builder_data']['value'] ) !== Nova_Bridge_Suite_Recipe_Engine::digest( self::arrays( $document['nodes'] ) )
            || $meta['_fl_builder_draft']['present'] || $meta['_fl_builder_draft_settings']['present'] ) {
            return self::error( 'The saved Beaver layout differs from the compiled document. Retain this post for recovery.' );
        }
        foreach ( $document['meta'] as $key => $value ) {
            if ( ! $meta[$key]['present'] || Nova_Bridge_Suite_Recipe_Engine::digest( $meta[$key]['value'] ) !== Nova_Bridge_Suite_Recipe_Engine::digest( self::arrays( $value ) ) ) {
                return self::error( 'The saved Beaver page settings differ from the compiled layout.' );
            }
        }
        return true;
    }
}
