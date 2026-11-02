<?php
/** Native Enfold / Avia Advanced Layout Builder authoring and retained-target publication. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-nova-bridge-suite-enfold-document.php';

final class Nova_Bridge_Suite_Recipe_Enfold {
    private static function error( string $message, int $status = 409 ): WP_Error { return new WP_Error( 'nova_recipe_enfold', $message, [ 'status' => $status ] ); }
    private static function guard( callable $run ) { try { return $run(); } catch ( Throwable $e ) { return self::error( $e->getMessage() ); } }
    private static function builder() { return function_exists( 'Avia_Builder' ) ? Avia_Builder() : null; }
    private static function available(): bool { $builder = self::builder(); return $builder && is_callable( [ $builder, 'get_shortcode_class' ] ) && is_callable( [ $builder, 'get_posts_alb_content' ] ); }
    public static function has_document( string $content ): bool { return 1 === preg_match( '~(?<!\[)\[av_[a-zA-Z0-9_]+(?=[\s/\]])~', $content ); }

    public function metadata( string $post_type = 'page' ): array {
        $support = $this->publication_support( $post_type ); $builder = self::builder();
        return [ 'id' => 'enfold', 'label' => 'Enfold / Avia Layout Builder', 'provider' => 'enfold', 'version' => $builder && is_callable( [ $builder, 'get_theme_version' ] ) ? (string) $builder->get_theme_version() : '', 'available' => self::available(), 'source_free' => true, 'capture' => true, 'starter' => self::available(), 'draft' => $support['supported'], 'modes' => [ 'static', 'mixed', 'rules' ], 'reason' => $support['reason'] ];
    }
    public function capabilities( string $post_type = 'page' ): array { return $this->metadata( $post_type ); }
    public function publication_support( string $type ): array {
        $builder = self::builder(); $object = get_post_type_object( $type );
        $supported = self::available() && $object && in_array( $type, $builder->get_supported_post_types(), true ) && current_user_can( $object->cap->edit_posts ) && current_user_can( $object->cap->create_posts ?? $object->cap->edit_posts );
        // Global Enfold element templates/custom layouts are site configuration, not publication destinations.
        if ( function_exists( 'Avia_Element_Templates' ) && Avia_Element_Templates()->get_post_type() === $type || function_exists( 'Avia_Custom_Layout' ) && Avia_Custom_Layout()->get_post_type() === $type ) { $supported = false; }
        return [ 'supported' => (bool) $supported, 'reason' => $supported ? '' : 'Activate Enfold, enable Avia for this post type, and allow the configured actor to create and edit it.' ];
    }
    private function target( $post ) {
        if ( is_callable( [ 'Nova_Bridge_Suite_Recipe_Builders', 'native_carriers' ] ) && array_diff( Nova_Bridge_Suite_Recipe_Builders::native_carriers( $post ), [ 'enfold', 'gutenberg' ] ) ) { return self::error( 'This item uses a different native builder.' ); }
        return true;
    }
    private static function meta( int $id, array $keys ): array {
        $out = []; foreach ( $keys as $key ) { $present = metadata_exists( 'post', $id, $key ); $value = $present ? get_post_meta( $id, $key, true ) : ''; if ( ! is_scalar( $value ) && null !== $value ) { throw new RuntimeException( 'Enfold presentation values must remain scalar: ' . $key ); } $out[$key] = [ 'present' => $present, 'value' => (string) $value ]; }
        return $out;
    }
    public function capture_document( string $source ) {
        return self::guard( static function () use ( $source ) {
            if ( ! self::available() ) { return self::error( 'Activate Enfold and its native Avia controls before capturing a layout.' ); }
            return Nova_Bridge_Suite_Enfold_Document::capture( $source, self::builder() );
        } );
    }
    public function capture( int $id ) {
        return self::guard( function () use ( $id ) {
            $post = get_post( $id ); if ( ! $post || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $id ) ) { return self::error( 'An editable native example is required for Avia capture.', 403 ); }
            $valid = $this->target( $post ); if ( is_wp_error( $valid ) ) { return $valid; }
            if ( ! self::available() ) { return self::error( 'Activate Enfold before capturing its native Avia layout.' ); }
            $builder = self::builder(); $active = 'active' === $builder->get_alb_builder_status( $id );
            $source = $active ? (string) $builder->get_posts_alb_content( $id ) : (string) $post->post_content;
            if ( '' === trim( $source ) || ! self::has_document( $source ) ) { return self::error( 'Choose an example with a saved Avia shortcode layout.' ); }
            $document = $this->capture_document( $source ); if ( is_wp_error( $document ) ) { return $document; }
            return $this->envelope( [ 'document' => $document, 'template' => (string) get_page_template_slug( $id ), 'meta' => self::meta( $id, Nova_Bridge_Suite_Enfold_Document::PRESENTATION ) ], $post->post_type );
        } );
    }
    public function starter( string $mode = 'mixed' ) {
        $doc = $this->capture_document( '[av_section][av_one_full first][av_heading heading="Example heading" tag="h1"][/av_heading][av_textblock]<p>Example service content.</p>[/av_textblock][/av_one_full][/av_section]' );
        return is_wp_error( $doc ) ? $doc : $this->envelope( [ 'document' => $doc, 'template' => '', 'meta' => [] ] );
    }
    private function envelope( array $blueprint, string $type = 'page' ) {
        $inventory = $this->describe( $blueprint ); if ( is_wp_error( $inventory ) ) { return $inventory; }
        $metadata = $this->metadata( $type );
        return [ 'builder' => 'enfold', 'provider' => 'enfold', 'version' => $metadata['version'], 'blueprint' => $blueprint, 'capabilities' => $metadata, 'warnings' => [] ] + $inventory;
    }
    public function validate_blueprint( array $blueprint ) { return self::guard( static function () use ( $blueprint ) { Nova_Bridge_Suite_Enfold_Document::validate( $blueprint ); return $blueprint; } ); }
    public function describe( array $blueprint ) { $valid = $this->validate_blueprint( $blueprint ); return is_wp_error( $valid ) ? $valid : Nova_Bridge_Suite_Enfold_Document::inventory( $blueprint ); }
    public function fields( array $native ): array { return Nova_Bridge_Suite_Enfold_Document::inventory( $native )['fields']; }
    public function containers( array $native ): array { return Nova_Bridge_Suite_Enfold_Document::inventory( $native )['containers']; }
    private function field( array $native, array $binding ): ?array { foreach ( $this->fields( $native ) as $field ) { if ( $field['target'] === ( $binding['target'] ?? '' ) && $field['format'] === ( $binding['format'] ?? '' ) && $field['writable'] ) { return $field; } } return null; }
    public function validate_binding( array $native, array $binding ) { return $this->field( $native, $binding ) ? true : self::error( 'Choose an exposed Avia field and its declared format.' ); }
    public function validate_region( array $native, array $region ) { foreach ( $this->containers( $native ) as $c ) { if ( $c['target'] === ( $region['target'] ?? null ) ) { return true; } } return self::error( 'Choose an exposed Avia child collection.' ); }
    public function instantiate( array $prototype_native, string $seed, array $context = [] ) { return [ 'native' => $prototype_native, 'context' => $context ]; }
    public function bind( array $native, array $binding, $value, array $context = [] ) {
        $field = $this->field( $native, $binding ); if ( ! $field ) { return self::error( 'Choose an exposed writable Avia field.' ); }
        $value = (string) $value; $node = Nova_Bridge_Suite_Recipe_Engine::get( $native, $field['node_target'] );
        if ( 'link_url' === $field['kind'] ) { $value = '' === $value ? '' : 'manually,' . $value; }
        if ( str_ends_with( $binding['target'], '/content' ) && 'text' === $binding['format'] ) {
            $value = esc_html( $value );
            if ( 'shortcode' === $node['kind'] && Nova_Bridge_Suite_Enfold_Document::heading( $node['content'] ) && preg_match( '~^(\s*<h[1-6]\b[^>]*>)[\s\S]*?(</h[1-6]>\s*)$~i', $node['content'], $match ) ) { $value = $match[1] . $value . $match[2]; }
        } elseif ( str_ends_with( $binding['target'], '/content' ) && 'html' === $binding['format'] ) { $value = Nova_Bridge_Suite_Enfold_Document::body_styles( $node['content'], $value ); }
        // Some controls are omitted from native source when empty. Create only this declared attribute.
        if ( str_contains( $binding['target'], '/attributes/' ) ) {
            $key = substr( $binding['target'], strrpos( $binding['target'], '/' ) + 1 ); $node['attributes'][$key] = $value;
            Nova_Bridge_Suite_Recipe_Engine::put( $native, $field['node_target'], $node );
        } else { Nova_Bridge_Suite_Recipe_Engine::put( $native, $binding['target'], $value ); }
        return $native;
    }
    public function replace_region( array $native, array $region, array $children, array $context = [] ) { $valid = $this->validate_region( $native, $region ); if ( is_wp_error( $valid ) ) { return $valid; } Nova_Bridge_Suite_Recipe_Engine::splice( $native, $region['target'], $region['start'], $region['count'], $children ); return $native; }
    public function finalize( array $blueprint, string $seed, array $context = [] ) {
        $valid = $this->validate_blueprint( $blueprint ); if ( is_wp_error( $valid ) ) { return $valid; }
        $preview = ''; $fields = $this->fields( $blueprint );
        foreach ( $fields as $field ) { if ( $field['writable'] && '' !== $field['value'] && 'url' !== $field['format'] ) { $preview .= 'html' === $field['format'] ? wp_kses_post( $field['value'] ) : '<p>' . esc_html( $field['value'] ) . '</p>'; } }
        return [ 'native_document' => Nova_Bridge_Suite_Enfold_Document::source( $blueprint['document'] ), 'writer_payload' => [ 'enfold_document' => $blueprint['document'], 'template' => $blueprint['template'] ?? '', 'meta' => $blueprint['meta'] ?? [] ], 'preview_html' => $preview, 'warnings' => [], 'summary' => [ 'builder' => 'enfold', 'fields' => count( $fields ) ] ];
    }

    private function payload( array $payload ) { return $this->validate_blueprint( [ 'document' => $payload['enfold_document'] ?? null, 'template' => $payload['template'] ?? '', 'meta' => $payload['meta'] ?? [] ] ); }
    public function publication_probe( string $type, array $payload, string $status ) {
        $support = $this->publication_support( $type ); if ( ! $support['supported'] ) { return self::error( $support['reason'] ); }
        $valid = $this->payload( $payload ); if ( is_wp_error( $valid ) ) { return $valid; }
        return [ 'wordpress' => (string) ( $GLOBALS['wp_version'] ?? '' ), 'provider_version' => 'enfold-avia-v1', 'enfold' => (string) self::builder()->get_theme_version() ];
    }
    /** Native Avia parser and CSS/asset helpers use the current post in addition to the explicit ID. */
    private static function on_target( $post, callable $run ) {
        $had = array_key_exists( 'post', $GLOBALS ); $previous = $GLOBALS['post'] ?? null; $GLOBALS['post'] = $post;
        try { return $run(); } finally { if ( $had ) { $GLOBALS['post'] = $previous; } else { unset( $GLOBALS['post'] ); } }
    }
    public function publication_snapshot( int $id, array $payload ) {
        return self::guard( function () use ( $id, $payload ) {
            $post = get_post( $id ); if ( ! $post || ! current_user_can( 'edit_post', $id ) || ! self::available() ) { return self::error( 'The retained Avia item is not available for native inspection.', 403 ); }
            $target = $this->target( $post ); if ( is_wp_error( $target ) ) { return $target; }
            $builder = self::builder();
            return [ 'content' => (string) $post->post_content, 'editor' => (string) $builder->get_posts_alb_content( $id ), 'active' => (string) $builder->get_alb_builder_status( $id ), 'tree' => $builder->get_shortcode_tree( $id ), 'template' => (string) get_page_template_slug( $id ), 'meta' => self::meta( $id, array_keys( $payload['meta'] ?? [] ) ) ];
        } );
    }
    public function publication_apply( int $id, array $payload, array $fields ) {
        return self::guard( function () use ( $id, $payload, $fields ) {
            $post = get_post( $id ); if ( ! $post || ! current_user_can( 'edit_post', $id ) ) { return self::error( 'The retained Avia item is not editable.', 403 ); }
            $valid = $this->publication_probe( $post->post_type, $payload, $post->post_status ); if ( is_wp_error( $valid ) ) { return $valid; }
            $target = $this->target( $post ); if ( is_wp_error( $target ) ) { return $target; }
            $source = Nova_Bridge_Suite_Enfold_Document::source( $payload['enfold_document'], $id );
            $meta = []; foreach ( $payload['meta'] ?? [] as $key => $snapshot ) { if ( $snapshot['present'] ) { $meta[$key] = $snapshot['value']; } }
            $data = [ 'ID' => $id, 'post_content' => $source, 'post_title' => (string) ( $fields['title'] ?? '' ), 'post_excerpt' => (string) ( $fields['excerpt'] ?? '' ), 'page_template' => ( $payload['template'] ?? '' ) ?: 'default', 'meta_input' => $meta ];
            $saved = wp_update_post( wp_slash( $data ), true ); if ( is_wp_error( $saved ) ) { return $saved; } if ( (int) $saved !== $id ) { return self::error( 'WordPress did not return the retained Avia item ID.' ); }
            foreach ( $payload['meta'] ?? [] as $key => $snapshot ) { if ( ! $snapshot['present'] ) { delete_post_meta( $id, $key ); } }
            clean_post_cache( $id ); $current = get_post( $id );
            return self::on_target( $current, function () use ( $id, $payload, $source, $current ) {
                $builder = self::builder(); $builder->set_alb_builder_status( 'active', $id );
                $builder->save_posts_alb_content( $id, wp_slash( $source ) );
                $tree = ShortcodeHelper::build_shortcode_tree( $source ); $builder->save_shortcode_tree( $id, $tree );
                $builder->element_manager()->updated_post_content( $source, $id );
                if ( function_exists( 'AviaPostCss' ) ) { AviaPostCss()->handler_wp_save_post( $id, $current, true ); }
                clean_post_cache( $id ); return $this->publication_verify( $id, $payload );
            } );
        } );
    }
    public function publication_verify( int $id, array $payload ) {
        return self::guard( function () use ( $id, $payload ) {
            $valid = $this->payload( $payload ); if ( is_wp_error( $valid ) ) { return $valid; }
            $state = $this->publication_snapshot( $id, $payload ); if ( is_wp_error( $state ) ) { return $state; }
            $source = Nova_Bridge_Suite_Enfold_Document::source( $payload['enfold_document'], $id );
            $tree = self::on_target( get_post( $id ), static fn() => ShortcodeHelper::build_shortcode_tree( $source ) );
            $expected = [ 'content' => $source, 'editor' => $source, 'active' => 'active', 'tree' => $tree, 'template' => $payload['template'] ?? '', 'meta' => $payload['meta'] ?? [] ];
            return $state === $expected ? true : self::error( 'The saved front-end source, Avia editor data, shortcode tree or page presentation differs from the compiled layout. Retain this same item for recovery.' );
        } );
    }
    public function verify_saved( int $id, array $payload ) { return $this->publication_verify( $id, $payload ); }
    public function write_draft( array $payload, array $fields ) {
        $type = $fields['post_type'] ?? 'page'; $valid = $this->publication_probe( $type, $payload, 'draft' ); if ( is_wp_error( $valid ) ) { return $valid; }
        $data = [ 'post_status' => 'draft', 'post_type' => $type, 'post_author' => get_current_user_id(), 'post_title' => (string) ( $fields['title'] ?? '' ) ];
        if ( isset( $fields['slug'] ) ) { $data['post_name'] = $fields['slug']; } if ( isset( $fields['parent_id'] ) ) { $data['post_parent'] = (int) $fields['parent_id']; }
        $id = wp_insert_post( wp_slash( $data ), true ); if ( is_wp_error( $id ) ) { return $id; }
        $applied = $this->publication_apply( (int) $id, $payload, $fields ); if ( is_wp_error( $applied ) ) { return new WP_Error( 'nova_recipe_enfold', $applied->get_error_message(), [ 'status' => 409, 'post_id' => (int) $id ] ); }
        return [ 'post_id' => (int) $id, 'status' => 'draft', 'builder' => 'enfold', 'edit_url' => get_edit_post_link( $id, 'raw' ), 'preview_url' => get_preview_post_link( $id ) ];
    }
}
