<?php
/** Save the fitted document to the post already reserved by the delivery worker. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Nova_Bridge_Suite_Cornerstone_Publication {
    private static function native_document( int $id ) {
        $document = \Themeco\Cornerstone\Documents\Document::locate( $id );
        if ( ! $document instanceof \Themeco\Cornerstone\Documents\Content || $document->id() !== $id ) {
            throw new RuntimeException( 'Cornerstone could not open the retained content document.' );
        }
        return $document;
    }

    private static function design_settings( array $settings ): array {
        return array_intersect_key( $settings, array_flip( [ 'layoutSingle', 'layoutHeader', 'layoutFooter', 'customCSS' ] ) );
    }

    private static function valid_design_settings( array $settings ): bool {
        if ( self::design_settings( $settings ) !== $settings ) { return false; }
        foreach ( $settings as $key => $value ) {
            if ( 'customCSS' === $key ) {
                if ( ! is_string( $value ) || false !== stripos( $value, '</style' ) ) { return false; }
            } elseif ( ! is_string( $value ) && ! is_int( $value ) ) { return false; }
        }
        return true;
    }

    public function publication_support( string $type ): array {
        $object = get_post_type_object( $type );
        $supported = self::available() && $object && ! str_starts_with( $type, 'cs_' )
            && current_user_can( $object->cap->create_posts ?? $object->cap->edit_posts )
            && current_user_can( $object->cap->edit_posts ) && cornerstone( 'Permissions' )->userCanAccessPostType( $type );
        return [ 'supported' => $supported, 'reason' => $supported ? '' : 'Enable Cornerstone for this post type and allow the publishing user to create and edit its documents.' ];
    }

    private function validate_payload( array $payload ): array {
        if ( ! is_array( $payload['cornerstone_document'] ?? null ) || ! is_string( $payload['template'] ?? null )
            || ! is_array( $payload['settings'] ?? null ) || ! self::valid_design_settings( $payload['settings'] )
            || ! is_array( $payload['meta'] ?? [] ) || ! self::valid_presentation_meta( $payload['meta'] ?? [] ) ) {
            throw new RuntimeException( 'The saved Cornerstone document or its settings are invalid.' );
        }
        $source = Nova_Bridge_Suite_Cornerstone_Document::source( $payload['cornerstone_document'] );
        // The native controller receives associative arrays from its JSON request.
        return json_decode( $source, true, 64, JSON_THROW_ON_ERROR );
    }

    public function publication_probe( string $type, array $payload, string $status ) {
        return self::guard( function () use ( $type, $payload ) {
            $support = $this->publication_support( $type );
            if ( ! $support['supported'] ) { return self::error( $support['reason'] ); }
            $elements = $this->validate_payload( $payload );
            if ( ! empty( $payload['settings']['customCSS'] ) && ! current_user_can( 'unfiltered_html' ) ) {
                return self::error( 'The publishing user cannot apply the stored Cornerstone custom CSS.', 403 );
            }
            // Cornerstone substitutes an undefined element for a missing extension. Detect it before reserving a post.
            $registry = cornerstone( 'Elements' );
            $types = [];
            $collect = static function ( array $nodes ) use ( &$collect, &$types ) {
                foreach ( $nodes as $node ) {
                    $types[$node['_type']] = true;
                    if ( ! empty( $node['_modules'] ) ) { $collect( $node['_modules'] ); }
                }
            };
            $collect( $elements );
            foreach ( array_keys( $types ) as $name ) {
                $definition = $registry->get_element( $name );
                if ( ! $definition || 'undefined' === $definition->get_type() ) {
                    return self::error( 'The stored layout uses an unavailable Cornerstone element: ' . $name );
                }
            }
            return [ 'wordpress' => (string) ( $GLOBALS['wp_version'] ?? '' ), 'provider_version' => 'cornerstone-content-v1', 'cornerstone' => (string) CS_VERSION ];
        } );
    }

    private static function editable_document( int $id ) {
        $post = get_post( $id );
        if ( ! $post || ! current_user_can( 'edit_post', $id ) || ! self::available() ) {
            throw new RuntimeException( 'The retained Cornerstone item is not editable.' );
        }
        if ( is_callable( [ 'Nova_Bridge_Suite_Recipe_Builders', 'native_carriers' ] )
            && array_diff( Nova_Bridge_Suite_Recipe_Builders::native_carriers( $post ), [ 'cornerstone', 'gutenberg' ] ) ) {
            throw new RuntimeException( 'The existing item uses a different native builder.' );
        }
        $document = self::native_document( $id );
        if ( ! $document->isAllowed() ) { throw new RuntimeException( 'Cornerstone does not allow the publishing user to edit this document.' ); }
        return $document;
    }

    /** Cornerstone generates these two coordinates during shortcode compilation and can omit them on reload. */
    private static function editor_elements( array $elements ): array {
        foreach ( $elements as &$element ) {
            unset( $element['_id'], $element['_region'] );
            if ( isset( $element['_modules'] ) ) { $element['_modules'] = self::editor_elements( $element['_modules'] ); }
        }
        return $elements;
    }

    public function publication_snapshot( int $id, array $payload ) {
        return self::guard( static function () use ( $id, $payload ) {
            $document = self::editable_document( $id );
            $post = get_post( $id );
            return [
                'elements' => self::editor_elements( $document->data()['elements'] ),
                'settings' => self::design_settings( $document->settings() ),
                'meta' => self::presentation_meta( $id, array_keys( $payload['meta'] ?? [] ) ),
                'template' => (string) get_page_template_slug( $id ),
                'active' => cs_uses_cornerstone( $post ) && ! get_post_meta( $id, '_cornerstone_override', true ),
                'content' => (string) $post->post_content,
            ];
        } );
    }

    public function publication_apply( int $id, array $payload, array $fields ) {
        return self::guard( function () use ( $id, $payload, $fields ) {
            $document = self::editable_document( $id );
            $post = get_post( $id );
            $probe = $this->publication_probe( $post->post_type, $payload, $post->post_status );
            if ( is_wp_error( $probe ) ) { return $probe; }
            $elements = $this->validate_payload( $payload );
            $settings = $payload['settings'];
            $settings['general_manual_excerpt'] = (string) ( $fields['excerpt'] ?? '' );
            $settings['general_page_template'] = $payload['template'] ?: 'default';
            $previous = $GLOBALS['post'] ?? null;
            $had_post = array_key_exists( 'post', $GLOBALS );
            try {
                $GLOBALS['post'] = $post;
                foreach ( $payload['meta'] ?? [] as $key => $entry ) {
                    if ( $entry['present'] ) { update_post_meta( $id, $key, wp_slash( $entry['value'] ) ); }
                    else { delete_post_meta( $id, $key ); }
                }
                // The HTML save path asks Resolver for the same ID. A cached pre-update document would output old content.
                cornerstone( 'Resolver' )->clearDocumentCache( $id );
                $saved = $document->update( [ 'elements' => $elements, 'settings' => $settings, 'title' => $fields['title'] ?? $post->post_title ] )->save();
            } finally {
                cornerstone( 'Resolver' )->clearDocumentCache( $id );
                if ( $had_post ) { $GLOBALS['post'] = $previous; } else { unset( $GLOBALS['post'] ); }
            }
            if ( is_wp_error( $saved ) ) { return $saved; }
            if ( ( $saved['id'] ?? null ) !== $id ) { return self::error( 'Cornerstone did not confirm the retained post ID.' ); }
            // Content::save only applies excerpts and templates for post types declaring the corresponding editor features.
            $current = get_post( $id );
            $changes = [];
            if ( $current->post_excerpt !== $settings['general_manual_excerpt'] ) { $changes['post_excerpt'] = $settings['general_manual_excerpt']; }
            if ( $current->post_title !== ( $fields['title'] ?? $post->post_title ) ) { $changes['post_title'] = $fields['title'] ?? $post->post_title; }
            if ( (string) get_page_template_slug( $id ) !== $payload['template'] ) { $changes['page_template'] = $settings['general_page_template']; }
            if ( $changes ) {
                $updated = wp_update_post( wp_slash( [ 'ID' => $id ] + $changes ), true );
                if ( is_wp_error( $updated ) ) { return $updated; }
                if ( $updated !== $id ) { return self::error( 'WordPress could not save the Cornerstone post fields.' ); }
            }
            return $this->publication_verify( $id, $payload );
        } );
    }

    public function publication_verify( int $id, array $payload ) {
        return self::guard( function () use ( $id, $payload ) {
            $elements = self::editor_elements( $this->validate_payload( $payload ) );
            $saved = $this->publication_snapshot( $id, $payload );
            if ( is_wp_error( $saved ) ) { return $saved; }
            if ( ! $saved['active'] || $saved['template'] !== $payload['template'] || $saved['meta'] !== ( $payload['meta'] ?? [] )
                || ! ( cs_uses_cornerstone_html( $saved['content'] ) || cs_uses_cornerstone_shortcodes( $saved['content'] ) )
                || Nova_Bridge_Suite_Recipe_Engine::digest( $saved['elements'] ) !== Nova_Bridge_Suite_Recipe_Engine::digest( $elements ) ) {
                return self::error( 'Cornerstone could not reopen the fitted layout from the retained post and its frontend content.' );
            }
            foreach ( $payload['settings'] as $key => $value ) {
                if ( ( $saved['settings'][$key] ?? null ) !== $value ) { return self::error( 'Cornerstone did not retain the saved page setting: ' . $key ); }
            }
            return true;
        } );
    }

    public function verify_saved( int $id, array $payload ) { return $this->publication_verify( $id, $payload ); }

    public function write_draft( array $payload, array $fields ) {
        $type = $fields['post_type'] ?? 'page';
        $probe = $this->publication_probe( $type, $payload, 'draft' );
        if ( is_wp_error( $probe ) ) { return $probe; }
        $data = [ 'post_type' => $type, 'post_status' => 'draft', 'post_author' => get_current_user_id(), 'post_title' => (string) ( $fields['title'] ?? '' ) ];
        if ( isset( $fields['slug'] ) ) { $data['post_name'] = $fields['slug']; }
        if ( isset( $fields['parent_id'] ) ) { $data['post_parent'] = (int) $fields['parent_id']; }
        $id = wp_insert_post( wp_slash( $data ), true );
        if ( is_wp_error( $id ) ) { return $id; }
        $result = $this->publication_apply( $id, $payload, $fields );
        if ( is_wp_error( $result ) ) { return new WP_Error( 'nova_recipe_cornerstone', $result->get_error_message(), [ 'status' => 409, 'post_id' => $id ] ); }
        return [ 'post_id' => $id, 'status' => 'draft', 'builder' => 'cornerstone', 'edit_url' => get_edit_post_link( $id, 'raw' ), 'preview_url' => get_preview_post_link( $id ) ];
    }
}
