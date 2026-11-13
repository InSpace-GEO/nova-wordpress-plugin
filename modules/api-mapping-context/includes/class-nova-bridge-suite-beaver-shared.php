<?php
/** Keep native Beaver global components linked when selecting their translated templates. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Beaver_Shared {
    public static function reference( array $node ): ?array {
        $original = (array) ( $node['orig'] ?? [] );
        $id = $original['global'] ?? null;
        if ( ! ( is_int( $id ) || is_string( $id ) ) || (string) (int) $id !== (string) $id || (int) $id < 1 || empty( $original['template_root_node'] )
            || ! is_string( $original['template_id'] ?? null ) || ! is_string( $original['template_node_id'] ?? null ) ) { return null; }
        return [ 'reference_id' => (int) $id, 'template_id' => $original['template_id'], 'node_id' => $original['template_node_id'] ];
    }

    public static function references( array $nodes, string $prefix = '/document' ): array {
        $result = [];
        foreach ( $nodes as $index => $node ) {
            $path = $prefix . '/' . $index;
            $reference = self::reference( $node );
            if ( $reference ) { $result[$path] = $reference; }
            else { $result += self::references( $node['children'] ?? [], $path . '/children' ); }
        }
        return $result;
    }

    /** Resolve only published, native global templates with an unambiguous root of the same kind. */
    public static function identity( int $id, string $type ): ?array {
        $post = get_post( $id );
        if ( ! $post || 'fl-builder-template' !== $post->post_type || 'publish' !== $post->post_status
            || ! get_post_meta( $id, '_fl_builder_template_global', true ) ) { return null; }
        $template = get_post_meta( $id, '_fl_builder_template_id', true );
        if ( ! is_string( $template ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,128}$/D', $template ) ) { return null; }
        $roots = [];
        foreach ( (array) get_post_meta( $id, '_fl_builder_data', true ) as $node ) {
            $node = (array) $node;
            if ( empty( $node['parent'] ) && ( $node['type'] ?? '' ) === $type ) { $roots[] = $node; }
        }
        if ( count( $roots ) !== 1 || ! is_string( $roots[0]['node'] ?? null ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,128}$/D', $roots[0]['node'] ) ) { return null; }
        return [ 'reference_id' => $id, 'template_id' => $template, 'node_id' => $roots[0]['node'] ];
    }

    public static function validate( array $entries, string $builder, array $blueprint, array $mapped ): void {
        if ( 'beaver' !== $builder || count( $entries ) > 128 ) { throw new InvalidArgumentException( 'The saved shared Beaver components are invalid.' ); }
        $references = self::references( $blueprint['document'] ?? [] ); $seen = [];
        foreach ( $entries as $entry ) {
            $path = $entry['target'] ?? '';
            if ( ! is_string( $path ) || isset( $seen[$path] ) || ! isset( $references[$path] )
                || ( $entry['source'] ?? null ) != $references[$path] || ! is_array( $entry['variants'] ?? null ) ) {
                throw new InvalidArgumentException( 'A shared Beaver component no longer matches its saved native reference.' );
            }
            $seen[$path] = true; $locales = [];
            foreach ( $mapped as $target ) {
                if ( $path === $target || str_starts_with( $target, $path . '/' ) || str_starts_with( $path, $target . '/' ) ) {
                    throw new InvalidArgumentException( 'Keep the shared Beaver component linked and map page-local fields separately.' );
                }
            }
            foreach ( $entry['variants'] as $variant ) {
                $locale = strtolower( str_replace( '_', '-', $variant['locale'] ?? '' ) );
                if ( ! preg_match( '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/D', $locale ) || isset( $locales[$locale] )
                    || ! is_string( $variant['code'] ?? null ) || ! preg_match( '/^[a-z0-9_-]{1,80}$/iD', $variant['code'] )
                    || ! is_int( $variant['reference_id'] ?? null ) || $variant['reference_id'] < 1 ) {
                    throw new InvalidArgumentException( 'A shared Beaver component has an invalid locale identity.' );
                }
                foreach ( array_unique( [ $locale, strtolower( $variant['code'] ) ] ) as $alias ) {
                    if ( isset( $locales[$alias] ) ) { throw new InvalidArgumentException( 'A shared Beaver component has ambiguous locale identities.' ); }
                    $locales[$alias] = true;
                }
                foreach ( [ 'template_id', 'node_id' ] as $key ) {
                    if ( ! is_string( $variant[$key] ?? null ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,128}$/D', $variant[$key] ) ) {
                        throw new InvalidArgumentException( 'A shared Beaver component has an invalid template identity.' );
                    }
                }
            }
        }
    }

    public static function apply( array $entries, string $locale, array $blueprint ): array {
        $locale = strtolower( str_replace( '_', '-', $locale ) );
        foreach ( $entries as $entry ) {
            $identity = $entry['source'];
            foreach ( $entry['variants'] as $variant ) {
                if ( ! in_array( $locale, [ strtolower( str_replace( '_', '-', $variant['locale'] ) ), strtolower( $variant['code'] ) ], true ) ) { continue; }
                $identity = array_intersect_key( $variant, array_flip( [ 'reference_id', 'template_id', 'node_id' ] ) );
                break;
            }
            $node = Nova_Bridge_Suite_Recipe_Engine::get( $blueprint, $entry['target'] );
            if ( self::identity( $identity['reference_id'], $node['type'] ) != $identity ) {
                throw new InvalidArgumentException( 'The selected shared Beaver template is unavailable or changed identity. Review the component and save the layout again.' );
            }
            if ( $identity == $entry['source'] ) { continue; }
            if ( ! empty( $node['children'] ) || ! empty( $node['settings']['dynamic_node_settings'] ) ) {
                throw new InvalidArgumentException( 'This shared Beaver component has instance-specific children or settings. Review its localized template before publishing.' );
            }
            $node['orig']['global'] = $identity['reference_id'];
            $node['orig']['template_id'] = $identity['template_id'];
            $node['orig']['template_node_id'] = $identity['node_id'];
            unset( $node['orig']['template_title'], $node['orig']['template_url'] );
            Nova_Bridge_Suite_Recipe_Engine::put( $blueprint, $entry['target'], $node );
        }
        return $blueprint;
    }

}
