<?php
/** Publish a captured ACF document to the item reserved by the existing delivery journal. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Nova_Bridge_Suite_ACF_Publication {
    public function publication_support( string $type ): array {
        $object = get_post_type_object( $type );
        $supported = self::available() && $object && Nova_Bridge_Suite_Content_Transport::supports_post_type( $object )
            && current_user_can( $object->cap->create_posts ?? $object->cap->edit_posts ) && current_user_can( $object->cap->edit_posts );
        return [ 'supported' => (bool) $supported, 'reason' => $supported ? '' : 'Enable ACF/SCF and allow the publishing user to create and edit this destination.' ];
    }

    /** Only the stored local schema owns field keys; delivery values cannot introduce metadata. */
    private function publication_input( array $payload, string $type, int $id = 0 ) {
        if ( ! is_array( $payload['acf_layout'] ?? null ) || ! is_array( $payload['meta_all']['acf'] ?? null )
            || ( $payload['acf_layout']['post_type'] ?? '' ) !== $type || ! empty( $payload['acf_layout']['taxonomy'] ) ) {
            return self::error( 'The retained ACF layout does not belong to this post destination.' );
        }
        $blueprint = $payload['acf_layout']; $blueprint['document'] = $payload['meta_all']['acf'];
        $checked = $this->validate_blueprint( $blueprint ); if ( is_wp_error( $checked ) ) { return $checked; }
        $schema = self::catalog( $type, $id, $blueprint['template'] ?? '' );
        if ( Nova_Bridge_Suite_Recipe_Engine::digest( $schema ) !== $blueprint['schema_hash'] ) { return self::error( 'The target item has a different ACF field schema from the saved layout.' ); }
        foreach ( $schema as $name => $field ) {
            if ( $id && ! current_user_can( 'edit_post_meta', $id, $name ) ) { return self::error( 'The publishing user cannot edit the captured ACF field: ' . $name, 403 ); }
        }
        $bounds = self::validate_constraints( array_values( $schema ), $blueprint['document'], '/document', 0, self::conditions( self::native_schema( $blueprint, $id ) ) ); if ( is_wp_error( $bounds ) ) { return $bounds; }
        return Nova_Bridge_Suite_Content_Transport::validate_named_acf( $blueprint['document'], self::native_schema( $blueprint, $id ) );
    }

    public function publication_probe( string $type, array $payload, string $status ) {
        return self::under_layout_rules( self::legacy_layout( is_array( $payload['acf_layout'] ?? null ) ? $payload['acf_layout'] : [] ), function () use ( $type, $payload, $status ) { return $this->publication_probe_now( $type, $payload, $status ); } );
    }

    private function publication_probe_now( string $type, array $payload, string $status ) {
        $support = $this->publication_support( $type ); if ( ! $support['supported'] ) { return self::error( $support['reason'], 403 ); }
        $input = $this->publication_input( $payload, $type ); if ( is_wp_error( $input ) ) { return $input; }
        return [ 'wordpress' => (string) ( $GLOBALS['wp_version'] ?? '' ), 'provider_version' => 'acf-fields-v1' ];
    }

    private function publication_post( int $id, array $payload ) {
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== ( $payload['acf_layout']['post_type'] ?? '' ) || ! current_user_can( 'edit_post', $id ) ) { return self::error( 'The retained ACF item cannot be edited.', 403 ); }
        if ( is_callable( [ 'Nova_Bridge_Suite_Recipe_Builders', 'native_carriers' ] ) ) {
            $carriers = Nova_Bridge_Suite_Recipe_Builders::native_carriers( $post );
            // Ordinary block markup may be part of the explicitly captured ACF design.
            // Only accept that exact retained body; another native builder is still distinct.
            $captured = (string) $post->post_content === ( $payload['acf_layout']['post_content'] ?? '' );
            if ( $carriers && ( ! $captured || array_diff( $carriers, [ 'gutenberg' ] ) ) ) {
                return self::error( 'The existing item uses a native page builder. Choose its matching layout before replacing its design.' );
            }
        }
        return $post;
    }

    public function publication_snapshot( int $id, array $payload ) {
        return self::under_layout_rules( self::legacy_layout( is_array( $payload['acf_layout'] ?? null ) ? $payload['acf_layout'] : [] ), function () use ( $id, $payload ) { return $this->publication_snapshot_now( $id, $payload ); } );
    }

    private function publication_snapshot_now( int $id, array $payload ) {
        $post = $this->publication_post( $id, $payload ); if ( is_wp_error( $post ) ) { return $post; }
        $input = $this->publication_input( $payload, $post->post_type, $id ); if ( is_wp_error( $input ) ) { return $input; }
        return [ 'fields' => Nova_Bridge_Suite_Content_Transport::read_named_acf( $payload['acf_layout']['schema'], $id ), 'template' => (string) get_page_template_slug( $id ), 'post_content' => (string) $post->post_content ];
    }

    public function publication_apply( int $id, array $payload, array $fields ) {
        return self::under_layout_rules( self::legacy_layout( is_array( $payload['acf_layout'] ?? null ) ? $payload['acf_layout'] : [] ), function () use ( $id, $payload, $fields ) { return $this->publication_apply_now( $id, $payload, $fields ); } );
    }

    private function publication_apply_now( int $id, array $payload, array $fields ) {
        $post = $this->publication_post( $id, $payload ); if ( is_wp_error( $post ) ) { return $post; }
        $input = $this->publication_input( $payload, $post->post_type, $id ); if ( is_wp_error( $input ) ) { return $input; }
        $result = wp_update_post( wp_slash( [ 'ID' => $id, 'post_title' => $fields['title'], 'post_excerpt' => $fields['excerpt'] ?? '', 'post_content' => $payload['acf_layout']['post_content'] ?? '' ] ), true );
        if ( is_wp_error( $result ) ) { return $result; }
        if ( (int) $result !== $id ) { return self::error( 'WordPress did not save the retained ACF item.' ); }
        update_post_meta( $id, '_wp_page_template', $payload['acf_layout']['template'] ?: 'default' );
        $result = Nova_Bridge_Suite_Content_Transport::write_fields( $post, [ 'meta' => [], 'acf' => $input ], true );
        return is_wp_error( $result ) ? $result : $this->publication_verify( $id, $payload );
    }

    public function publication_verify( int $id, array $payload ) {
        return self::under_layout_rules( self::legacy_layout( is_array( $payload['acf_layout'] ?? null ) ? $payload['acf_layout'] : [] ), function () use ( $id, $payload ) { return $this->publication_verify_now( $id, $payload ); } );
    }

    private function publication_verify_now( int $id, array $payload ) {
        $post = $this->publication_post( $id, $payload ); if ( is_wp_error( $post ) ) { return $post; }
        $input = $this->publication_input( $payload, $post->post_type, $id ); if ( is_wp_error( $input ) ) { return $input; }
        $template = (string) get_page_template_slug( $id );
        if ( (string) $post->post_content !== ( $payload['acf_layout']['post_content'] ?? '' ) ) { return self::error( 'WordPress did not retain the captured native body.' ); }
        if ( ( 'default' === $template ? '' : $template ) !== ( $payload['acf_layout']['template'] ?? '' ) ) { return self::error( 'WordPress did not retain the captured page template.' ); }
        return Nova_Bridge_Suite_Content_Transport::verify_named_acf( $input, $id );
    }
}
