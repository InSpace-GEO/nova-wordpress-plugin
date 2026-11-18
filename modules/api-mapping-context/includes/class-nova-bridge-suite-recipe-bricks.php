<?php
/** Fixed Bricks layouts retain their flat element list and parent/child IDs. */
defined( 'ABSPATH' ) || exit;

final class Nova_Bridge_Suite_Recipe_Bricks {
    private const CONTENT = '_bricks_page_content_2';
    private const SETTINGS = '_bricks_page_settings';
    private const MODE = '_bricks_editor_mode';

    private function error( string $message ) {
        return new WP_Error( 'nova_recipe_bricks', $message, [ 'status' => 409 ] );
    }

    public function metadata( string $type = 'page' ): array {
        $available = defined( 'BRICKS_VERSION' ) && class_exists( 'Bricks\Elements' );
        $support = $this->publication_support( $type );
        return [ 'id' => 'bricks', 'label' => 'Bricks', 'provider' => 'bricks', 'available' => $available,
            'version' => $available ? BRICKS_VERSION : '', 'capture' => true, 'starter' => false, 'source_free' => true,
            'draft' => $support['supported'], 'reason' => $support['reason'], 'modes' => [ 'static' ] ];
    }

    public function capabilities( string $type = 'page' ): array { return $this->metadata( $type ); }
    public function starter( string $mode ) { return $this->error( 'Choose an existing Bricks layout.' ); }
    public function containers( array $layout ): array { return []; }
    public function validate_region( array $layout, array $region ) { return $this->error( 'Bricks supports fixed layouts.' ); }

    public function instantiate( array $layout, string $seed, array $context = [] ) { return $this->validate_region( $layout, [] ); }
    public function replace_region( array $layout, array $region, array $children, array $context = [] ) { return $this->validate_region( $layout, $region ); }

    // Only a locally captured layout may carry opaque theme data, including signed code elements.
    private function signature( array $layout ): string {
        return hash_hmac( 'sha256', 'nova-bricks:' . Nova_Bridge_Suite_Recipe_Engine::digest( [
            $layout['document'], $layout['settings'], $layout['template'],
        ] ), wp_salt( 'auth' ) );
    }

    public function validate_blueprint( array $layout ) {
        if ( ! is_array( $layout['document'] ?? null ) || ! is_array( $layout['settings'] ?? null ) || ! is_string( $layout['template'] ?? null )
            || ! is_string( $layout['signature'] ?? null ) || ! hash_equals( $this->signature( $layout ), $layout['signature'] ) ) {
            return $this->error( 'Recapture the original Bricks layout on this installation.' );
        }
        return $layout;
    }

    public function capture( int $id ) {
        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $id ) || 'bricks' !== get_post_meta( $id, self::MODE, true ) ) {
            return $this->error( 'Choose an editable Bricks page.' );
        }
        if ( get_post_meta( $id, '_bricks_page_header_2', true ) || get_post_meta( $id, '_bricks_page_footer_2', true ) ) {
            return $this->error( 'This page stores a separate Bricks header or footer. Choose a reference using the site templates.' );
        }
        $layout = $this->state( $id );
        unset( $layout['mode'] );
        if ( ! $layout['document'] || ! Nova_Bridge_Suite_Recipe_Engine::list_array( $layout['document'] ) || ! Nova_Bridge_Suite_Recipe_Native_Adapter::no_secrets( $layout ) ) {
            return $this->error( 'This Bricks document is empty or contains private settings.' );
        }
        $ids = [];
        foreach ( $layout['document'] as $node ) {
            $key = $node['id'] ?? '';
            if ( ! is_string( $key ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,80}$/D', $key ) || isset( $ids[$key] )
                || ! is_string( $node['name'] ?? null ) || ! is_array( $node['settings'] ?? [] ) ) {
                return $this->error( 'The native Bricks element list is invalid.' );
            }
            $ids[$key] = true;
        }
        $layout['signature'] = $this->signature( $layout );
        return [ 'builder' => 'bricks', 'blueprint' => $layout, 'suggested_bindings' => [], 'suggested_regions' => [] ] + $this->describe( $layout );
    }

    public function describe( array $layout ): array {
        return [ 'fields' => $this->fields( $layout ), 'containers' => [], 'warnings' => [] ];
    }

    public function fields( array $layout ): array {
        $nodes = $layout['document'] ?? [];
        $by_id = array_column( $nodes, null, 'id' );
        $fields = [];
        foreach ( $nodes as $index => $node ) {
            $ancestor = $node;
            $seen = [];
            while ( $ancestor ) {
                if ( isset( $seen[$ancestor['id']] ) || ! empty( $ancestor['global'] ) || ! empty( $ancestor['cid'] )
                    || ! empty( $ancestor['settings']['hasLoop'] ) ) { continue 2; }
                $seen[$ancestor['id']] = true;
                $ancestor = $by_id[$ancestor['parent'] ?? 0] ?? null;
            }
            $name = $node['name'];
            $settings = $node['settings'] ?? [];
            $add = static function ( $key, $value, $kind, $format = 'text' ) use ( &$fields, $index, $node, $name ) {
                if ( ! is_string( $value ) || preg_match( '/\{[^}]+\}|\[[a-z][\w-]*(?:\s|\])|<script\b/i', $value ) ) { return; }
                $fields[] = [ 'target' => "/document/$index/settings/$key", 'kind' => $kind, 'format' => $format,
                    'label' => ( $node['label'] ?? ucfirst( $name ) ) . ' · ' . str_replace( '/', ' › ', $key ),
                    'value' => $value, 'writable' => true, 'native_node_id' => $node['id'], 'element_type' => $name,
                    'control_path' => explode( '/', $key ), 'locator' => [ 'strategy' => 'bricks', 'precision' => 'field' ] ];
            };
            if ( in_array( $name, [ 'heading', 'text', 'text-basic', 'button' ], true ) ) {
                $add( 'text', $settings['text'] ?? null, [ 'heading' => 'heading', 'button' => 'button_text' ][$name] ?? 'body', 'text' === $name ? 'html' : 'text' );
            }
            if ( 'image' === $name && empty( $settings['image']['useDynamicData'] ) ) {
                $add( 'image/url', $settings['image']['url'] ?? null, 'image_url', 'url' );
                $add( 'altText', $settings['altText'] ?? null, 'text' );
            }
            if ( 'custom-title' === $name ) {
                foreach ( [ 'title', 'subtitle' ] as $key ) { $add( $key, $settings[$key] ?? null, 'heading' ); }
            }
            if ( 'ba-multi-heading' === $name ) {
                foreach ( $settings['multi_heading_item'] ?? [] as $i => $row ) {
                    if ( ! is_int( $i ) ) { continue; }
                    $add( "multi_heading_item/$i/text", $row['text'] ?? null, 'heading' );
                    if ( 'external' === ( $row['heading_link']['type'] ?? '' ) ) {
                        $add( "multi_heading_item/$i/heading_link/url", $row['heading_link']['url'] ?? null, 'link_url', 'url' );
                    }
                }
            }
            if ( in_array( $name, [ 'button', 'heading', 'text-basic', 'ba-multi-heading', 'icon' ], true ) && 'external' === ( $settings['link']['type'] ?? '' ) ) {
                $add( 'link/url', $settings['link']['url'] ?? null, 'link_url', 'url' );
            }
        }
        return $fields;
    }

    public function validate_binding( array $layout, array $binding ) {
        foreach ( $this->fields( $layout ) as $field ) {
            if ( $field['target'] === $binding['target'] && $field['format'] === $binding['format'] ) { return true; }
        }
        return $this->error( 'Choose a captured Bricks content field with its original format.' );
    }

    public function bind( array $layout, array $binding, $value, array $context = [] ) {
        $valid = $this->validate_blueprint( $layout );
        if ( is_wp_error( $valid ) ) { return $valid; }
        $valid = $this->validate_binding( $layout, $binding );
        if ( is_wp_error( $valid ) ) { return $valid; }
        if ( ! is_string( $value ) || preg_match( '/\{[^}]+\}|\[[a-z][\w-]*(?:\s|\])/i', $value ) ) { return $this->error( 'Supply literal content for this Bricks field.' ); }
        if ( 'text' === $binding['format'] && wp_strip_all_tags( $value ) !== $value ) {
            return $this->error( 'Use plain text in this Bricks control.' );
        }
        $target = $binding['target'];
        if ( str_ends_with( $target, '/image/url' ) ) {
            $path = substr( $target, 0, -4 );
            $image = Nova_Bridge_Suite_Recipe_Engine::get( $layout, $path );
            if ( '' === $value || $image['url'] === $value ) { return $layout; }
            $id = attachment_url_to_postid( $value );
            $image['url'] = $value;
            $image['filename'] = basename( (string) wp_parse_url( $value, PHP_URL_PATH ) );
            if ( $id && wp_attachment_is_image( $id ) ) {
                unset( $image['external'] );
                $image['id'] = $id;
                $image['full'] = wp_get_attachment_image_src( $id, 'full' );
            } else {
                unset( $image['id'] );
                $image['external'] = true;
                $image['full'] = $value;
            }
            Nova_Bridge_Suite_Recipe_Engine::put( $layout, $path, $image );
        } else {
            Nova_Bridge_Suite_Recipe_Engine::put( $layout, $target, $value );
        }
        $layout['signature'] = $this->signature( $layout );
        return $layout;
    }

    public function finalize( array $layout, string $seed, array $context = [] ) {
        $valid = $this->validate_blueprint( $layout );
        if ( is_wp_error( $valid ) ) { return $valid; }
        $preview = '';
        foreach ( $this->fields( $layout ) as $field ) {
            if ( 'url' !== $field['format'] ) { $preview .= 'html' === $field['format'] ? wp_kses_post( $field['value'] ) : '<p>' . esc_html( $field['value'] ) . '</p>'; }
        }
        return [ 'native_document' => $layout['document'], 'writer_payload' => [ 'bricks' => $layout ],
            'preview_html' => $preview, 'warnings' => [], 'summary' => [ 'builder' => 'bricks' ] ];
    }

    public function publication_support( string $type ): array {
        $object = get_post_type_object( $type );
        $ok = defined( 'BRICKS_VERSION' ) && class_exists( 'Bricks\Elements' ) && $object && ! in_array( $type, [ 'bricks_template', 'attachment', 'revision' ], true )
            && current_user_can( $object->cap->create_posts ?? $object->cap->edit_posts ) && current_user_can( $object->cap->edit_posts );
        return [ 'supported' => (bool) $ok, 'reason' => $ok ? '' : 'Activate Bricks and choose a post type the publishing account can edit.' ];
    }

    public function publication_probe( string $type, array $payload, string $status ) {
        $support = $this->publication_support( $type );
        if ( ! $support['supported'] ) { return $this->error( $support['reason'] ); }
        $layout = $this->validate_blueprint( $payload['bricks'] ?? [] );
        if ( is_wp_error( $layout ) ) { return $layout; }
        if ( 'file' === Bricks\Database::get_setting( 'cssLoading' ) && ! is_callable( [ 'Bricks\Assets_Files', 'generate_post_css_file' ] ) ) {
            return $this->error( 'The Bricks external stylesheet writer is unavailable.' );
        }
        foreach ( array_unique( array_column( $layout['document'], 'name' ) ) as $name ) {
            if ( empty( Bricks\Elements::$elements[$name]['class'] ) ) { return $this->error( 'Activate the Bricks element provider for ' . $name . '.' ); }
        }
        return [ 'provider_version' => 'bricks-v1', 'bricks' => BRICKS_VERSION ];
    }

    private function state( int $id ): array {
        return [ 'document' => get_post_meta( $id, self::CONTENT, true ) ?: [], 'settings' => get_post_meta( $id, self::SETTINGS, true ) ?: [],
            'mode' => get_post_meta( $id, self::MODE, true ), 'template' => (string) get_page_template_slug( $id ) ];
    }

    public function publication_snapshot( int $id, array $payload ) {
        return current_user_can( 'edit_post', $id ) ? $this->state( $id ) : $this->error( 'The target post cannot be edited.' );
    }

    public function publication_apply( int $id, array $payload, array $fields ) {
        $post = get_post( $id );
        if ( ! $post || ! current_user_can( 'edit_post', $id ) ) { return $this->error( 'The target post cannot be edited.' ); }
        $checked = $this->publication_probe( $post->post_type, $payload, $post->post_status );
        if ( is_wp_error( $checked ) ) { return $checked; }
        $layout = $payload['bricks'];
        $update = [ 'ID' => $id, 'post_title' => $fields['title'] ?? $post->post_title, 'post_excerpt' => $fields['excerpt'] ?? $post->post_excerpt, 'page_template' => $layout['template'] ?: 'default' ];
        if ( Bricks\Database::get_setting( 'bricks_to_wp' ) && ( ! $post->post_content || has_blocks( $post ) ) ) {
            $update['post_content'] = Bricks\Blocks::serialize_bricks_to_blocks( $layout['document'], $id );
        }
        $saved = wp_update_post( wp_slash( $update ), true );
        if ( is_wp_error( $saved ) ) { return $saved; }
        update_post_meta( $id, self::CONTENT, wp_slash( $layout['document'] ) );
        if ( $layout['settings'] ) { update_post_meta( $id, self::SETTINGS, wp_slash( $layout['settings'] ) ); }
        else { delete_post_meta( $id, self::SETTINGS ); }
        update_post_meta( $id, self::MODE, 'bricks' );
        if ( 'file' === Bricks\Database::get_setting( 'cssLoading' ) ) {
            Bricks\Assets_Files::generate_post_css_file( $id, 'content', $layout['document'] );
        }
        return $this->publication_verify( $id, $payload );
    }

    public function publication_verify( int $id, array $payload ) {
        $state = $this->publication_snapshot( $id, $payload );
        if ( is_wp_error( $state ) ) { return $state; }
        $layout = $payload['bricks'];
        return 'bricks' === $state['mode'] && $state['document'] === $layout['document'] && $state['template'] === $layout['template']
            && $state['settings'] === $layout['settings'] ? true : $this->error( 'Bricks did not retain the mapped layout.' );
    }

    public function verify_saved( int $id, array $payload ) { return $this->publication_verify( $id, $payload ); }

    public function write_draft( array $payload, array $fields ) {
        $type = $fields['post_type'] ?? 'page';
        $checked = $this->publication_probe( $type, $payload, 'draft' );
        if ( is_wp_error( $checked ) ) { return $checked; }
        $id = wp_insert_post( wp_slash( [ 'post_type' => $type, 'post_status' => 'draft', 'post_title' => $fields['title'] ?? '', 'post_author' => get_current_user_id() ] ), true );
        if ( is_wp_error( $id ) ) { return $id; }
        $saved = $this->publication_apply( $id, $payload, $fields );
        if ( is_wp_error( $saved ) ) { return new WP_Error( 'nova_recipe_bricks', $saved->get_error_message(), [ 'status' => 409, 'post_id' => $id ] ); }
        return [ 'id' => $id, 'post_id' => $id, 'status' => 'draft', 'preview_url' => get_preview_post_link( $id ) ];
    }
}
