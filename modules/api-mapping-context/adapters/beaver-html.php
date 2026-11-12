<?php
/** Map scalar fields in Beaver HTML modules using the shared source-preserving HTML parser. */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/gutenberg-html.php';
require_once __DIR__ . '/beaver-document.php';

final class Nova_Mapping_Beaver_HTML {
    private static function block( array $node ): array {
        return [ 'blockName' => 'core/html', 'innerHTML' => $node['settings']['html'],
            'innerBlocks' => [], 'innerContent' => [ $node['settings']['html'] ],
            Nova_Mapping_Gutenberg_HTML::SLOTS => $node[Nova_Mapping_Gutenberg_HTML::SLOTS] ?? [] ];
    }

    public static function capture( array $nodes ): array {
        foreach ( $nodes as &$node ) {
            if ( ! empty( $node['children'] ) ) { $node['children'] = self::capture( $node['children'] ); }
            if ( 'html' !== ( $node['settings']['type'] ?? '' ) || ! is_string( $node['settings']['html'] ?? null ) ) { continue; }
            if ( isset( $node[Nova_Mapping_Gutenberg_HTML::SLOTS] ) ) { continue; }
            try {
                $blocks = Nova_Mapping_Gutenberg_HTML::capture( [ self::block( $node ) ] );
                $node[Nova_Mapping_Gutenberg_HTML::SLOTS] = $blocks[0][Nova_Mapping_Gutenberg_HTML::SLOTS];
            } catch ( InvalidArgumentException $error ) {
                // Preserve an unsupported fragment in full, without inventing editable fields.
            }
        }
        unset( $node );
        return $nodes;
    }

    public static function fields( array $node, string $path, bool $protected ): array {
        if ( ! isset( $node[Nova_Mapping_Gutenberg_HTML::SLOTS] ) ) { return []; }
        return Nova_Mapping_Gutenberg_HTML::fields( self::block( $node ), $path, $protected );
    }

    /** Remove authoring-only values before comparing an example with its saved source. */
    public static function source_nodes( array $nodes ): array {
        foreach ( $nodes as &$node ) {
            unset( $node[Nova_Mapping_Gutenberg_HTML::SLOTS], $node['array_paths'] );
            if ( ! empty( $node['children'] ) ) { $node['children'] = self::source_nodes( $node['children'] ); }
        }
        unset( $node );
        return nova_mapping_bb_compact_tree( $nodes );
    }

    public static function render( array $nodes ): array {
        foreach ( $nodes as &$node ) {
            if ( ! empty( $node['children'] ) ) { $node['children'] = self::render( $node['children'] ); }
            if ( 'html' !== ( $node['settings']['type'] ?? '' ) || ! is_string( $node['settings']['html'] ?? null )
                || ! isset( $node[Nova_Mapping_Gutenberg_HTML::SLOTS] ) ) { continue; }
            $blocks = Nova_Mapping_Gutenberg_HTML::render_blocks( [ self::block( $node ) ] );
            $node['settings']['html'] = $blocks[0]['innerHTML'];
            unset( $node[Nova_Mapping_Gutenberg_HTML::SLOTS] );
        }
        unset( $node );
        return $nodes;
    }

    public static function locator( array $blueprint, array $field ): ?array {
        $path = $field['node_target'] ?? '';
        $node = Nova_Bridge_Suite_Recipe_Engine::get( $blueprint, $path );
        $prefix = $path . '/' . Nova_Mapping_Gutenberg_HTML::SLOTS . '/';
        if ( ! is_array( $node ) || ! str_starts_with( $field['target'], $prefix ) || empty( $node['node_id'] ) ) { return null; }
        if ( 'schema_value' === $field['kind'] ) { return [ 'strategy' => 'unbound', 'reason' => 'Structured data has no visible element on the page.' ]; }
        return [ 'strategy' => 'custom_html', 'marker' => Nova_Mapping_Gutenberg_HTML::marker(
            'beaver/' . $node['node_id'] . '/0', $node['settings']['html'], substr( $field['target'], strlen( $prefix ) ) ), 'precision' => 'field' ];
    }

    public static function preview( string $content, string $type, $settings, $module ): string {
        if ( 'html' !== $type || ! is_string( $settings->html ?? null ) || ! is_string( $module->node ?? null )
            || '' === $settings->html || substr_count( $content, $settings->html ) !== 1 ) { return $content; }
        try {
            $blocks = Nova_Mapping_Gutenberg_HTML::preview_blocks( [ self::block( [ 'settings' => [ 'html' => $settings->html ] ] ) ], 'beaver/' . $module->node );
            return str_replace( $settings->html, $blocks[0]['innerHTML'], $content );
        } catch ( InvalidArgumentException $error ) { return $content; }
    }
}
