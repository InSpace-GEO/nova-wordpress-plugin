<?php
/** Reusable Elementor content: protected live references or explicitly captured native copies. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Elementor_Reusable {
    public const COPY = 'nova_reusable_copy';
    private static function fail( string $message ): void { throw new InvalidArgumentException( $message ); }
    private static function id( $value ): int {
        return ( is_int( $value ) || is_string( $value ) ) && (string) (int) $value === (string) $value && (int) $value > 0 ? (int) $value : 0;
    }
    /** null denotes an ordinary node; malformed reusable references still have a kind. */
    public static function reference( array $node ): ?array {
        if ( ! empty( $node['templateID'] ) || 'global' === ( $node['widgetType'] ?? '' ) ) {
            return [ 'kind' => 'global', 'id' => self::id( $node['templateID'] ?? null ) ];
        }
        if ( 'template' === ( $node['widgetType'] ?? '' ) ) {
            return [ 'kind' => 'template', 'id' => self::id( $node['settings']['template_id'] ?? null ) ];
        }
        if ( 'shortcode' === ( $node['widgetType'] ?? '' ) && is_string( $node['settings']['shortcode'] ?? null )
            && preg_match( '~^\s*\[elementor-template\s+id\s*=\s*(?:"([1-9][0-9]*)"|\x27([1-9][0-9]*)\x27|([1-9][0-9]*))\s*\]\s*$~iD', $node['settings']['shortcode'], $m ) ) {
            return [ 'kind' => 'shortcode', 'id' => (int) ( ( $m[1] ?? '' ) ?: ( ( $m[2] ?? '' ) ?: $m[3] ) ) ];
        }
        return null;
    }
    private static function name( array $ref ): string { return ( 'global' === $ref['kind'] ? 'Global widget' : 'Saved template' ) . ' #' . $ref['id']; }
    public static function library( array $ref, bool $editing = false ): array {
        $post = $ref['id'] && function_exists( 'get_post' ) ? get_post( $ref['id'] ) : null;
        $label = self::name( $ref );
        if ( ! $post || 'elementor_library' !== $post->post_type || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
            self::fail( $label . ' is missing or is not a published Elementor library component. Restore it or choose another example.' );
        }
        if ( $editing && ! current_user_can( 'edit_post', $ref['id'] ) ) { self::fail( 'You cannot capture ' . $label . '. Choose an editable library component.' ); }
        $kind = get_post_meta( $ref['id'], '_elementor_template_type', true );
        if ( ( 'global' === $ref['kind'] && 'widget' !== $kind ) || ( 'global' !== $ref['kind'] && ! in_array( $kind, [ 'section', 'container', 'page', 'widget' ], true ) ) ) {
            self::fail( $label . ' has an unsupported library type. Use a global widget, section, container or page template.' );
        }
        $raw = get_post_meta( $ref['id'], '_elementor_data', true ); $nodes = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
        if ( ! is_array( $nodes ) || ! $nodes || array_keys( $nodes ) !== range( 0, count( $nodes ) - 1 ) || count( $nodes ) > 2000 ) { self::fail( $label . ' has no usable native Elementor layout.' ); }
        if ( 'global' === $ref['kind'] && ( count( $nodes ) !== 1 || 'widget' !== ( $nodes[0]['elType'] ?? '' ) ) ) { self::fail( $label . ' must contain one native widget.' ); }
        $settings = get_post_meta( $ref['id'], '_elementor_page_settings', true );
        return [ 'nodes' => $nodes, 'label' => $post->post_title ?: $label, 'kind' => $kind, 'custom_css' => is_string( $settings['custom_css'] ?? null ) ? $settings['custom_css'] : '' ];
    }
    /** Scan copy where each kept-shared component is one opaque, already verified node. */
    public static function shallow_blueprint( array $blueprint ): array {
        $walk = static function ( array $nodes ) use ( &$walk ): array {
            foreach ( $nodes as &$node ) {
                if ( ! is_array( $node ) ) { continue; }
                $ref = self::reference( $node );
                if ( $ref && $ref['id'] < 1 ) { self::fail( ( 'global' === $ref['kind'] ? 'A global widget' : 'An Elementor template widget' ) . ' has no library component selected. Choose one in Elementor or remove the widget.' ); }
                if ( $ref ) { $node = [ 'id' => $node['id'] ?? '', 'elType' => 'widget', 'widgetType' => 'nova-verified-shared', 'settings' => [], 'elements' => [] ]; }
                elseif ( is_array( $node['elements'] ?? null ) ) { $node['elements'] = $walk( $node['elements'] ); }
            } unset( $node ); return $nodes;
        };
        if ( is_array( $blueprint['document'] ?? null ) ) { $blueprint['document'] = $walk( $blueprint['document'] ); }
        return $blueprint;
    }
    /** Leave out kept-shared components that were deleted or unpublished since the mapping was saved. */
    public static function without_missing( array $nodes, array &$removed ): array {
        $kept = [];
        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) { $kept[] = $node; continue; }
            $ref = self::reference( $node );
            if ( $ref && ! isset( $node[self::COPY] ) ) {
                try { self::library( $ref ); } catch ( InvalidArgumentException $e ) { $removed[] = self::name( $ref ); continue; }
            } elseif ( is_array( $node['elements'] ?? null ) ) { $node['elements'] = self::without_missing( $node['elements'], $removed ); }
            $kept[] = $node;
        }
        return $kept;
    }
    /** Validate dependencies while keeping the persisted blueprint unchanged. */
    public static function validation_blueprint( array $blueprint ): array {
        $count = 0;
        $walk = static function ( array $nodes, array $stack = [], int $depth = 0 ) use ( &$walk, &$count ): array {
            if ( $depth > 32 || count( $stack ) > 16 ) { self::fail( 'Reusable Elementor components exceed the supported nesting depth.' ); }
            foreach ( $nodes as &$node ) {
                if ( ++$count > 2000 ) { self::fail( 'Reusable Elementor components exceed 2000 native elements.' ); }
                unset( $node[self::COPY] );
                $ref = self::reference( $node );
                if ( $ref ) {
                    if ( in_array( $ref['id'], $stack, true ) ) { self::fail( self::name( $ref ) . ' contains a circular template reference. Remove that circular link in Elementor.' ); }
                    $library = self::library( $ref );
                    $children = $walk( $library['nodes'], array_merge( $stack, [ $ref['id'] ] ), $depth + 1 );
                    // Only the scan copy loses its known reference. Normal publishing keeps the native reference.
                    unset( $node['templateID'], $node['originalWidgetType'], $node['settings']['template_id'], $node['settings']['shortcode'] );
                    $node['widgetType'] = 'nova-verified-shared'; $node['elements'] = $children;
                } else { $node['elements'] = $walk( $node['elements'] ?? [], $stack, $depth + 1 ); }
            } unset( $node ); return $nodes;
        };
        $blueprint['document'] = $walk( $blueprint['document'] ?? [] ); return $blueprint;
    }
    public static function inventory( array $blueprint ): array {
        $items = [];
        $walk = static function ( array $nodes, string $path ) use ( &$walk, &$items ) {
            foreach ( $nodes as $i => $node ) {
                $target = $path . '/' . $i; $ref = self::reference( $node ); $copy = $node[self::COPY] ?? null;
                if ( $copy ) {
                    $items[] = [ 'target' => $target, 'label' => ( 'global' === $copy['kind'] ? 'Global widget' : 'Saved template' ) . ' · ' . $copy['label'], 'kind' => $copy['kind'], 'mode' => 'captured', 'expandable' => false, 'reason' => 'Independent native copy. NOVA fills each delivered page separately; the original shared component is never edited.' ];
                } elseif ( $ref ) {
                    $label = self::name( $ref ); $reason = ! empty( $node['draft'] ) ? 'This example has local draft changes. Keep shared uses the published library content; create an independent copy to retain these draft values.' : ''; $valid = true;
                    try { $library = self::library( $ref ); $label .= ' · ' . $library['label']; self::validation_blueprint( [ 'document' => [ $node ] ] ); }
                    catch ( InvalidArgumentException $e ) { $reason = $e->getMessage(); $valid = false; }
                    $items[] = [ 'target' => $target, 'label' => $label, 'kind' => $ref['kind'], 'mode' => 'linked', 'expandable' => $valid, 'reason' => $reason ];
                }
                if ( ! $ref ) { $walk( $node['elements'] ?? [], $target . '/elements' ); }
            }
        }; $walk( $blueprint['document'] ?? [], '/document' ); return $items;
    }
    /** Provenance only: copied content stays frozen even if its former library record changes or disappears. */
    public static function linked_blueprint( array $blueprint ): array {
        $walk = static function ( array $nodes ) use ( &$walk ): array {
            foreach ( $nodes as &$node ) {
                if ( isset( $node[self::COPY] ) ) { $node = $node[self::COPY]['original']; }
                elseif ( isset( $node['elements'] ) ) { $node['elements'] = $walk( $node['elements'] ); }
            } unset( $node ); return $nodes;
        }; $blueprint['document'] = $walk( $blueprint['document'] ); return $blueprint;
    }
    public static function native_nodes( array $nodes ): array {
        foreach ( $nodes as &$node ) {
            unset( $node[self::COPY] );
            $ref = self::reference( $node );
            if ( $ref && 'global' === $ref['kind'] ) {
                // Pro replaces stale instance settings from the library and removes its editor-only hint on save.
                $library = self::library( $ref );
                $node['settings'] = $library['nodes'][0]['settings']; $node['widgetType'] = 'global';
                unset( $node['originalWidgetType'], $node['draft'] );
            }
            $node['elements'] = self::native_nodes( $node['elements'] ?? [] );
        } unset( $node ); return $nodes;
    }
    public static function validate_copy( array $node ): void {
        if ( ! isset( $node[self::COPY] ) ) { return; }
        $copy = $node[self::COPY];
        if ( ! is_array( $copy ) || ( $copy['format'] ?? '' ) !== 'nova.elementor-copy/v1' || ! is_array( $copy['original'] ?? null ) || ! self::reference( $copy['original'] ) || self::reference( $node ) || ! is_string( $copy['label'] ?? null ) || ! in_array( $copy['kind'] ?? '', [ 'global', 'template', 'shortcode' ], true ) ) { self::fail( 'The independent Elementor copy has incomplete provenance. Capture that component again.' ); }
    }
    /** Copy one occurrence in place, preserving all other binding paths. */
    public static function expand( array $blueprint, string $target, Nova_Bridge_Suite_Recipe_Elementor $adapter ): array {
        $original = Nova_Bridge_Suite_Recipe_Engine::get( $blueprint, $target, $found );
        if ( ! $found || ! is_array( $original ) || ! self::reference( $original ) ) { self::fail( 'Choose a shared Elementor component from this captured layout.' ); }
        // Check the entire referenced graph before reading any native copy into the layout.
        self::validation_blueprint( [ 'document' => [ $original ] ] );
        $expand = static function ( array $node, string $seed ) use ( &$expand, $adapter ): array {
            $ref = self::reference( $node );
            if ( ! $ref ) { foreach ( $node['elements'] ?? [] as $i => $child ) { $node['elements'][$i] = $expand( $child, $seed . '/' . $i ); } return $node; }
            $library = self::library( $ref, true );
            $children = $library['nodes'];
            if ( 'global' === $ref['kind'] ) {
                $copy = $children[0];
                // Elementor's draft global instances can hold intentional local settings.
                if ( ! empty( $node['draft'] ) ) { $copy['settings'] = $node['settings']; }
            } else {
                $settings = $node['settings']; unset( $settings['template_id'], $settings['shortcode'] );
                // Keep the template widget's advanced wrapper controls on a full-width native container.
                $rename = static function ( array $controls ): array {
                    foreach ( [ '_margin', '_padding', '_background', '_border', '_box_shadow' ] as $from ) {
                        foreach ( array_keys( $controls ) as $key ) { if ( $key === $from || str_starts_with( $key, $from . '_' ) ) { $controls[substr( $key, 1 )] = $controls[$key]; unset( $controls[$key] ); } }
                    } return $controls;
                };
                $settings = $rename( $settings );
                foreach ( [ '__globals__', '__dynamic__' ] as $map ) { if ( isset( $settings[$map] ) ) { $settings[$map] = $rename( $settings[$map] ); } }
                if ( '' !== $library['custom_css'] ) {
                    $css = preg_replace( '/\.elementor-(?:page-)?' . $ref['id'] . '\b/', 'selector', $library['custom_css'] );
                    $settings['custom_css'] = trim( ( $settings['custom_css'] ?? '' ) . "\n" . $css );
                }
                $settings += [ 'content_width' => 'full', 'flex_direction' => 'column', 'flex_gap' => [ 'column' => '0', 'row' => '0', 'isLinked' => true, 'unit' => 'px', 'size' => 0 ], 'padding' => [ 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => true ] ];
                $copy = [ 'id' => substr( hash( 'sha256', $seed ), 0, 7 ), 'elType' => 'container', 'settings' => $settings, 'elements' => $children ];
            }
            // Rebase the wrapper and its document CSS together, then expand nested occurrences separately.
            $copy = $adapter->independent_elements( [ $copy ], $seed . '/' . $ref['id'] )[0];
            foreach ( $copy['elements'] ?? [] as $i => $child ) { $copy['elements'][$i] = $expand( $child, $seed . '/' . $i ); }
            $copy[self::COPY] = [ 'format' => 'nova.elementor-copy/v1', 'original' => $node, 'label' => $library['label'], 'kind' => $ref['kind'] ];
            return $copy;
        };
        Nova_Bridge_Suite_Recipe_Engine::put( $blueprint, $target, $expand( $original, 'nova-copy:' . $target . ':' . Nova_Bridge_Suite_Recipe_Engine::digest( $original ) ) );
        return $blueprint;
    }
}
