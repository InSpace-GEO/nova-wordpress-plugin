<?php
/** Native BeBuilder fixed layouts. Theme code stays on the installation. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Nova_Bridge_Suite_Recipe_Betheme {
    private const META = [ 'mfn-post-layout', 'mfn-post-sidebar', 'mfn-post-sidebar2', 'mfn-post-hide-content', 'mfn-post-hide-title', 'mfn-post-remove-padding', 'mfn-post-full-width', 'mfn-post-one-page', 'mfn-post-slider', 'mfn-post-slider-layer', 'mfn-post-template', 'mfn-post-custom-layout', 'mfn-post-menu', 'mfn-post-subheader-image', 'mfn-post-header-bg', 'mfn-post-hide-image', 'mfn-post-bg', 'mfn-post-css' ];
    private static function error( $message ) { return new WP_Error( 'nova_recipe_betheme', $message, [ 'status' => 409 ] ); }
    private static function guard( callable $fn ) { try { return $fn(); } catch ( Throwable $e ) { return self::error( $e->getMessage() ); } }
    public static function available(): bool {
        if ( ! defined( 'MFN_THEME_VERSION' ) ) { return false; }
        foreach ( [ 'Mfn_Helper' => '/functions/admin/class-mfn-helper.php', 'Mfn_Builder_Front' => '/functions/builder/class-mfn-builder-front.php' ] as $class => $file ) { if ( ! class_exists( $class ) && function_exists( 'get_template_directory' ) && is_file( get_template_directory() . $file ) ) { require_once get_template_directory() . $file; } }
        return class_exists( 'Mfn_Builder_Front' ) && is_callable( [ 'Mfn_Helper', 'preparePostUpdate' ] );
    }
    public function metadata( string $type = 'page' ): array { $support = $this->publication_support( $type ); return [ 'id' => 'betheme', 'provider' => 'betheme', 'label' => 'BeTheme / BeBuilder', 'available' => self::available(), 'version' => defined( 'MFN_THEME_VERSION' ) ? MFN_THEME_VERSION : '', 'capture' => true, 'starter' => false, 'source_free' => true, 'draft' => $support['supported'], 'modes' => [ 'static' ], 'reason' => $support['reason'] ]; }
    public function capabilities( string $type = 'page' ): array { return $this->metadata( $type ); }
    public static function decode( $raw ): array {
        if ( is_string( $raw ) && $raw !== '' ) { $decoded = base64_decode( $raw, true ); $raw = false === $decoded ? null : @unserialize( $decoded, [ 'allowed_classes' => false ] ); }
        if ( ! is_array( $raw ) || ! Nova_Bridge_Suite_Recipe_Engine::list_array( $raw ) ) { throw new InvalidArgumentException( 'BeBuilder layout data must be a native section list.' ); }
        self::plain( $raw ); return $raw;
    }
    private static function plain( $value, int $depth = 0 ): void { if ( $depth > 40 || is_object( $value ) || is_resource( $value ) ) { throw new InvalidArgumentException( 'Unsupported BeBuilder data.' ); } if ( is_array( $value ) ) { foreach ( $value as $v ) { self::plain( $v, $depth + 1 ); } } }
    private static function nodes( array $nodes, string $path, callable $visit, string $kind = 'section', int $depth = 0 ): void {
        if ( $depth > 20 ) { throw new InvalidArgumentException( 'BeBuilder nesting exceeds the supported limit.' ); }
        foreach ( $nodes as $i => $node ) {
            if ( ! is_int( $i ) || ! is_array( $node ) || ! is_array( $node['attr'] ?? [] ) ) { throw new InvalidArgumentException( 'Invalid BeBuilder node.' ); }
            $p = $path . '/' . $i; $visit( $node, $p, $kind );
            foreach ( [ 'wraps' => 'wrap', 'items' => 'item' ] as $children => $child_kind ) { if ( isset( $node[$children] ) ) { if ( ! is_array( $node[$children] ) ) { throw new InvalidArgumentException( 'Invalid BeBuilder children.' ); } self::nodes( $node[$children], $p . '/' . $children, $visit, $child_kind, $depth + 1 ); } }
        }
    }
    private static function registry(): array {
        if ( ! self::available() ) { throw new RuntimeException( 'Activate BeTheme to map and publish BeBuilder layouts.' ); }
        if ( ! class_exists( 'Mfn_Builder_Fields' ) ) { require_once get_template_directory() . '/functions/builder/class-mfn-builder-fields.php'; }
        static $items; if ( null === $items ) { $items = ( new Mfn_Builder_Fields( false, 'items' ) )->get_items(); } return $items;
    }
    public function capture( int $id ) {
        return self::guard( function () use ( $id ) {
            $post = get_post( $id ); if ( ! $post || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $id ) ) { throw new RuntimeException( 'Choose a BeBuilder example you can edit.' ); }
            $document = self::decode( get_post_meta( $id, 'mfn-page-items', true ) ); if ( ! $document ) { throw new RuntimeException( 'This item has no BeBuilder layout.' ); }
            $meta = []; foreach ( self::META as $key ) { if ( metadata_exists( 'post', $id, $key ) ) { $meta[$key] = get_post_meta( $id, $key, true ); } }
            $blueprint = [ 'document' => $document, 'template' => (string) get_page_template_slug( $id ), 'meta' => $meta, 'content' => (string) $post->post_content ];
            $description = $this->describe( $blueprint ); if ( is_wp_error( $description ) ) { return $description; }
            return [ 'builder' => 'betheme', 'blueprint' => $blueprint, 'suggested_bindings' => [], 'suggested_regions' => [] ] + $description;
        } );
    }
    public function validate_blueprint( array $b ) {
        return self::guard( static function () use ( $b ) {
            if ( array_diff( array_keys( $b ), [ 'document', 'template', 'meta', 'content' ] ) || ! is_string( $b['template'] ?? '' ) || ! is_string( $b['content'] ?? '' ) || ! is_array( $b['meta'] ?? [] ) || array_diff( array_keys( $b['meta'] ?? [] ), self::META ) ) { throw new InvalidArgumentException( 'Use the captured BeBuilder document and settings.' ); }
            self::decode( $b['document'] ?? null ); self::plain( $b );
            foreach ( $b['meta'] ?? [] as $v ) { if ( ! is_scalar( $v ) ) { throw new InvalidArgumentException( 'Invalid BeTheme page setting.' ); } }
            if ( stripos( (string) ( $b['meta']['mfn-post-css'] ?? '' ), '</style' ) !== false ) { throw new InvalidArgumentException( 'Invalid BeTheme custom CSS.' ); }
            self::nodes( $b['document'], '/document', static function ( $n ) { if ( isset( $n['uid'] ) && ! preg_match( '/^[a-zA-Z0-9_-]{1,80}$/D', (string) $n['uid'] ) ) { throw new InvalidArgumentException( 'Invalid BeBuilder node identity.' ); } } ); return $b;
        } );
    }
    public function describe( array $b ) {
        return self::guard( function () use ( $b ) {
            $valid = $this->validate_blueprint( $b ); if ( is_wp_error( $valid ) ) { return $valid; }
            $registry = self::registry(); $fields = []; $protected = [];
            self::nodes( $b['document'], '/document', static function ( $node, $path, $kind ) use ( &$fields, &$protected, $registry ) {
                $shared = ! empty( $node['mfn_global_section_id'] ) || ! empty( $node['attr']['global_wraps_select'] ) || ! empty( $node['mfn_global_wrap_id'] ) || ! empty( $node['mfn_global_item_id'] );
                if ( $shared ) { $protected[] = $path; }
                foreach ( $protected as $prefix ) { if ( $path === $prefix || str_starts_with( $path, $prefix . '/' ) ) { return; } }
                foreach ( $node['attr'] ?? [] as $key => $background ) {
                    $base = $path . '/attr/' . str_replace( [ '~', '/' ], [ '~0', '~1' ], (string) $key ); $values = [];
                    if ( 'bg_image' === $key || str_ends_with( $key, ':background-image' ) ) { if ( is_string( $background ) ) { $values[$base] = $background; } }
                    elseif ( is_array( $background ) && 'background-image' === ( $background['style'] ?? '' ) ) { $v = $background['val'] ?? null; if ( is_string( $v ) ) { $values[$base . '/val'] = $v; } elseif ( is_array( $v ) ) { foreach ( [ 'desktop', 'laptop', 'tablet', 'mobile' ] as $device ) { if ( is_string( $v[$device] ?? null ) ) { $values[$base . '/val/' . $device] = $v[$device]; } } } }
                    foreach ( $values as $target => $value ) { $dynamic = str_contains( $value, '{' ); $field = [ 'target' => $target, 'label' => ucfirst( $kind ) . ' background image', 'format' => 'url', 'kind' => 'image_url', 'writable' => ! $dynamic, 'required' => false, 'value' => $value, 'node_target' => $path, 'native_node_id' => $node['uid'] ?? '', 'locator' => [ 'strategy' => 'betheme', 'node_kind' => $kind, 'kind' => 'background' ] ]; if ( ! $dynamic ) { $field['native_media'] = [ 'kind' => 'betheme', 'id_target' => $target ]; } $fields[] = $field; }
                }
                if ( 'item' !== $kind ) { return; }
                $type = $node['type'] ?? ''; $definition = $registry[$type] ?? null; if ( ! $definition ) { return; }
                $add = static function ( $key, $value, $format, $label, $image = false ) use ( &$fields, $node, $path, $type ) {
                    if ( ! is_scalar( $value ) && null !== $value ) { return; }
                    $dynamic = is_string( $value ) && preg_match( '/\{[a-z_][^{}]*\}/i', $value );
                    $target = $path . '/attr/' . $key;
                    $field = [ 'target' => $target, 'label' => $type . ': ' . strip_tags( $label ), 'format' => $format, 'kind' => $image ? 'image_url' : ( 'html' === $format ? 'body' : 'text' ), 'writable' => ! $dynamic, 'required' => false, 'value' => (string) $value, 'node_target' => $path, 'native_node_id' => $node['uid'] ?? '', 'control_path' => [ $key ], 'locator' => [ 'strategy' => 'betheme', 'kind' => $image ? 'image_url' : $format, 'text' => strip_tags( (string) $value ) ] ];
                    if ( $dynamic ) { $field['reason'] = 'BeTheme dynamic data stays linked. Map the corresponding WordPress field.'; }
                    if ( $image && ! $dynamic ) { $field['native_media'] = [ 'kind' => 'betheme', 'id_target' => $target ]; }
                    $fields[] = $field;
                };
                foreach ( $definition['attr'] ?? [] as $control ) {
                    $key = $control['id'] ?? ''; $t = $control['type'] ?? '';
                    if ( ! $key || ! preg_match( '/^[a-zA-Z0-9_-]+$/D', $key ) || isset( $control['style'] ) || isset( $control['selector'] ) || str_contains( $control['class'] ?? '', 'hidden' ) ) { continue; }
                    $value = $node['attr'][$key] ?? '';
                    if ( 'tabs' === $t && is_array( $value ) ) { foreach ( $value as $index => $row ) { if ( ! is_int( $index ) || ! is_array( $row ) ) { continue; } foreach ( $control['options'] ?? [] as $column => $spec ) { if ( in_array( $spec[0] ?? '', [ 'input', 'textarea', 'upload' ], true ) && preg_match( '/^[a-zA-Z0-9_-]+$/D', $column ) ) { $add( $key . '/' . $index . '/' . $column, $row[$column] ?? '', 'upload' === $spec[0] ? 'url' : ( 'textarea' === $spec[0] ? 'html' : 'text' ), $spec[1] ?? $column, 'upload' === $spec[0] ); } } } continue; }
                    if ( ! in_array( $t, [ 'text', 'textarea', 'visual', 'upload' ], true ) ) { continue; }
                    $editorial = ! empty( $control['dynamic_data'] ) || ! empty( $control['ai'] ) || in_array( $key, [ 'content', 'title', 'text', 'description', 'subtitle', 'caption', 'alt', 'link_title', 'link', 'src', 'image' ], true );
                    if ( ! $editorial || preg_match( '/^(?:css|style|onclick|class|uid|id|padding|margin)/i', $key ) ) { continue; }
                    $image = 'upload' === $t; $url = $image || in_array( $control['dynamic_data'] ?? '', [ 'permalink', 'link' ], true ) || 'link' === $key;
                    $add( $key, $value, $url ? 'url' : ( in_array( $t, [ 'textarea', 'visual' ], true ) ? 'html' : 'text' ), $control['title'] ?? $key, $image );
                }
            } );
            return [ 'fields' => $fields, 'containers' => [], 'warnings' => $protected ? [ 'Shared BeBuilder sections and wraps stay linked and are not mapped. Make them local in BeBuilder to map their content.' ] : [] ];
        } );
    }
    public function fields( array $b ): array { $d = $this->describe( $b ); return is_wp_error( $d ) ? [] : $d['fields']; }
    public function containers( array $b ): array { return []; }
    public function validate_binding( array $b, array $binding ) { foreach ( $this->fields( $b ) as $f ) { if ( $f['target'] === $binding['target'] && $f['writable'] ) { return true; } } return self::error( 'Choose a captured editable BeBuilder field.' ); }
    public static function put( array &$b, string $path, $value ): void {
        $parts = Nova_Bridge_Suite_Recipe_Engine::parts( $path ); $leaf = array_pop( $parts );
        $parent = '/' . implode( '/', array_map( static fn( $part ) => str_replace( [ '~', '/' ], [ '~0', '~1' ], $part ), $parts ) );
        $container = Nova_Bridge_Suite_Recipe_Engine::get( $b, $parent, $found );
        if ( ! $found || ! is_array( $container ) ) { throw new InvalidArgumentException( 'The BeBuilder field container is missing.' ); }
        $container[$leaf] = $value; Nova_Bridge_Suite_Recipe_Engine::put( $b, $parent, $container );
    }
    public function bind( array $b, array $binding, $value, array $context = [] ) { $valid = $this->validate_binding( $b, $binding ); if ( is_wp_error( $valid ) ) { return $valid; } self::put( $b, $binding['target'], $value ); return $b; }
    public function starter( string $mode = 'static' ) { return self::error( 'Choose an existing BeBuilder page.' ); }
    public function validate_region( array $b, array $r ) { return self::error( 'Use fixed BeBuilder fields and existing accordion/tab rows.' ); }
    public function instantiate( array $b, string $seed, array $context = [] ) { return $this->validate_region( $b, [] ); }
    public function replace_region( array $b, array $r, array $children, array $context = [] ) { return $this->validate_region( $b, $r ); }
    public function finalize( array $b, string $seed, array $context = [] ) { $preview = ''; foreach ( $this->fields( $b ) as $f ) { if ( $f['writable'] && 'url' !== $f['format'] ) { $preview .= 'html' === $f['format'] ? wp_kses_post( $f['value'] ) : '<p>' . esc_html( $f['value'] ) . '</p>'; } } return [ 'native_document' => $b['document'], 'writer_payload' => [ 'betheme_document' => $b['document'], 'template' => $b['template'] ?? '', 'meta' => $b['meta'] ?? [], 'content' => $b['content'] ?? '' ], 'preview_html' => $preview, 'warnings' => [], 'summary' => [ 'builder' => 'betheme' ] ]; }
    public function publication_support( string $type ): array { $o = get_post_type_object( $type ); $ok = self::available() && $o && ! in_array( $type, [ 'template', 'attachment', 'revision' ], true ) && current_user_can( $o->cap->create_posts ?? $o->cap->edit_posts ) && current_user_can( $o->cap->edit_posts ); return [ 'supported' => (bool) $ok, 'reason' => $ok ? '' : 'Activate BeTheme and allow the publishing user to edit this post type.' ]; }
    private function blueprint( array $p ): array { return [ 'document' => $p['betheme_document'] ?? [], 'template' => $p['template'] ?? '', 'meta' => $p['meta'] ?? [], 'content' => $p['content'] ?? '' ]; }
    public function publication_probe( string $type, array $p, string $status ) {
        return self::guard( function () use ( $type, $p ) {
            $support = $this->publication_support( $type ); if ( ! $support['supported'] ) { return self::error( $support['reason'] ); }
            $valid = $this->validate_blueprint( $this->blueprint( $p ) ); if ( is_wp_error( $valid ) ) { return $valid; }
            if ( ! empty( $p['meta']['mfn-post-css'] ) && ! current_user_can( 'unfiltered_html' ) ) { return self::error( 'The publishing user cannot apply captured BeTheme custom CSS.' ); }
            $registry = self::registry();
            self::nodes( $p['betheme_document'], '/document', static function ( $node, $path, $kind ) use ( $registry ) {
                foreach ( [ $node['mfn_global_section_id'] ?? 0, $node['attr']['global_wraps_select'] ?? 0 ] as $ref ) { if ( $ref && ( 'publish' !== get_post_status( (int) $ref ) || ! get_post_meta( (int) $ref, 'mfn-page-items', true ) ) ) { throw new RuntimeException( 'A linked BeBuilder section or wrap is unavailable: ' . (int) $ref ); } }
                if ( 'item' === $kind && ! isset( $registry[$node['type'] ?? ''] ) ) { throw new RuntimeException( 'The stored layout uses an unavailable BeBuilder item: ' . ( $node['type'] ?? '' ) ); }
            } );
            return [ 'provider_version' => 'betheme-native-v1', 'betheme' => MFN_THEME_VERSION ];
        } );
    }
    public function publication_snapshot( int $id, array $p ) { return self::guard( static function () use ( $id ) { $post = get_post( $id ); if ( ! $post || ! current_user_can( 'edit_post', $id ) ) { throw new RuntimeException( 'The retained BeBuilder post cannot be edited.' ); } $meta = []; foreach ( self::META as $key ) { if ( metadata_exists( 'post', $id, $key ) ) { $meta[$key] = get_post_meta( $id, $key, true ); } } $raw = get_post_meta( $id, 'mfn-page-items', true ); return [ 'document' => $raw ? self::decode( $raw ) : [], 'template' => (string) get_page_template_slug( $id ), 'meta' => $meta, 'content' => $post->post_content ]; } ); }
    public function publication_apply( int $id, array $p, array $fields ) {
        return self::guard( function () use ( $id, $p, $fields ) {
            $post = get_post( $id ); if ( ! $post || ! current_user_can( 'edit_post', $id ) ) { throw new RuntimeException( 'The retained BeBuilder post cannot be edited.' ); }
            $probe = $this->publication_probe( $post->post_type, $p, $post->post_status ); if ( is_wp_error( $probe ) ) { return $probe; }
            $document = $p['betheme_document']; $flat = [];
            self::nodes( $document, '/document', static function ( $n, $path, $kind ) use ( &$flat ) { unset( $n['wraps'], $n['items'] ); $n['jsclass'] = 'item' === $kind ? ( $n['type'] ?? '' ) : $kind; $flat[] = $n; } );
            $saved = wp_update_post( wp_slash( [ 'ID' => $id, 'post_title' => $fields['title'] ?? $post->post_title, 'post_excerpt' => $fields['excerpt'] ?? $post->post_excerpt, 'post_content' => $p['content'] ?? '', 'page_template' => ( $p['template'] ?? '' ) ?: 'default' ] ), true ); if ( is_wp_error( $saved ) ) { return $saved; }
            foreach ( self::META as $key ) { if ( array_key_exists( $key, $p['meta'] ?? [] ) ) { update_post_meta( $id, $key, wp_slash( $p['meta'][$key] ) ); } else { delete_post_meta( $id, $key ); } }
            $stored = 'encode' === mfn_opts_get( 'builder-storage' ) ? base64_encode( serialize( $document ) ) : wp_slash( $document );
            update_post_meta( $id, 'mfn-page-items', $stored ); update_post_meta( $id, 'mfn-builder-preview', $stored );
            // Classic BeBuilder also removes this editor cache and regenerates derived styles from flattened nodes.
            delete_post_meta( $id, 'mfn-page-object' );
            Mfn_Helper::preparePostUpdate( $flat, $id, 'mfn-page-local-style' ); Mfn_Helper::preparePostUpdate( $flat, $id, 'mfn-builder-preview-local-style' );
            $seo = ''; foreach ( $this->fields( $this->blueprint( $p ) ) as $f ) { if ( 'url' !== $f['format'] ) { $seo .= wp_strip_all_tags( $f['value'] ) . "\n"; } } update_post_meta( $id, 'mfn-page-items-seo', wp_slash( $seo ) );
            return $this->publication_verify( $id, $p );
        } );
    }
    public function publication_verify( int $id, array $p ) { $actual = $this->publication_snapshot( $id, $p ); return is_wp_error( $actual ) ? $actual : ( Nova_Bridge_Suite_Recipe_Engine::digest( $actual ) === Nova_Bridge_Suite_Recipe_Engine::digest( $this->blueprint( $p ) ) ? true : self::error( 'BeBuilder did not retain the fitted native document and page settings.' ) ); }
    public function verify_saved( int $id, array $p ) { return $this->publication_verify( $id, $p ); }
    public function write_draft( array $p, array $fields ) { $probe = $this->publication_probe( $fields['post_type'] ?? 'page', $p, 'draft' ); if ( is_wp_error( $probe ) ) { return $probe; } $id = wp_insert_post( wp_slash( [ 'post_type' => $fields['post_type'] ?? 'page', 'post_status' => 'draft', 'post_title' => $fields['title'] ?? '', 'post_author' => get_current_user_id() ] ), true ); if ( is_wp_error( $id ) ) { return $id; } $saved = $this->publication_apply( $id, $p, $fields ); return is_wp_error( $saved ) ? $saved : [ 'id' => $id, 'post_id' => $id, 'status' => 'draft' ]; }
}
