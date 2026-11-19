<?php
/** Editorial values stored in registered ACF blocks' native flat data. */
defined( 'ABSPATH' ) || exit;

final class Nova_Mapping_Gutenberg_ACF {
    public static function fields( array $block, string $path, bool $protected ): array {
        $name = $block['blockName'] ?? '';
        if ( ! str_starts_with( $name, 'acf/' ) || ! function_exists( 'acf_get_block_type' ) || ! function_exists( 'acf_get_block_fields' ) || ! acf_get_block_type( $name ) ) { return []; }
        $data = $block['attrs']['data'] ?? null;
        if ( ! is_array( $data ) || ( isset( $block['attrs']['name'] ) && $block['attrs']['name'] !== $name ) ) { return []; }
        $fields = [];
        self::walk( acf_get_block_fields( [ 'name' => $name ] ), $data, '', $path . '/attrs/data', 'ACF block', $protected, $fields );
        return $fields;
    }

    /** Capture declared post fields beside a block document, including row/reference metadata. */
    public static function capture_meta( int $post_id ): array {
        if ( ! class_exists( 'Nova_Bridge_Suite_Content_Transport' ) || ! function_exists( 'acf_get_field' ) ) { return []; }
        $post = get_post( $post_id );
        $schema = Nova_Bridge_Suite_Content_Transport::field_catalog( $post->post_type, $post_id, (string) get_page_template_slug( $post_id ) )['acf'];
        $stored = get_post_meta( $post_id ); $data = [];
        foreach ( $schema as $name => $field ) {
            if ( ! Nova_WordPress_Meta_Permission::can_edit( 'post', $post_id, $name ) || ( $stored[ '_' . $name ][0] ?? null ) !== $field['key'] ) { continue; }
            $keys = self::schema_keys( $field );
            foreach ( $stored as $key => $rows ) {
                $plain = ltrim( $key, '_' );
                if ( count( $rows ) === 1 && isset( $keys[ $stored[ '_' . $plain ][0] ?? '' ] ) && ( $plain === $name || str_starts_with( $plain, $name . '_' ) ) ) { $data[$key] = maybe_unserialize( $rows[0] ); }
            }
        }
        return $data;
    }

    private static function schema_keys( array $field ): array {
        $keys = [ $field['key'] => true ];
        foreach ( $field['sub_fields'] ?? [] as $child ) { $keys += self::schema_keys( $child ); }
        foreach ( $field['layouts'] ?? [] as $layout ) { foreach ( $layout['sub_fields'] ?? [] as $child ) { $keys += self::schema_keys( $child ); } }
        return $keys;
    }

    public static function has_blocks( array $blocks ): bool {
        foreach ( $blocks as $block ) { if ( str_starts_with( $block['blockName'] ?? '', 'acf/' ) || self::has_blocks( $block['innerBlocks'] ?? [] ) ) { return true; } }
        return false;
    }

    public static function flush_meta( int $post_id, array $meta ): void {
        if ( ! function_exists( 'acf_flush_value_cache' ) ) { return; }
        foreach ( $meta as $name => $value ) {
            if ( is_string( $name ) && ! str_starts_with( $name, '_' ) && str_starts_with( (string) ( $meta[ '_' . $name ] ?? '' ), 'field_' ) ) { acf_flush_value_cache( $post_id, $name ); }
        }
    }

    public static function meta_fields( array $data ): array {
        if ( ! function_exists( 'acf_get_field' ) ) { return []; }
        $schema = [];
        foreach ( $data as $name => $value ) {
            if ( ! is_string( $name ) || str_starts_with( $name, '_' ) || ! is_string( $data[ '_' . $name ] ?? null ) ) { continue; }
            $field = acf_get_field( $data[ '_' . $name ] );
            if ( is_array( $field ) && ( $field['name'] ?? null ) === $name ) { $schema[] = $field; }
        }
        $fields = []; self::walk( $schema, $data, '', '/meta', 'ACF', false, $fields );
        return $fields;
    }

    public static function validate_value( array $field, $value ) {
        $schema = function_exists( 'acf_get_field' ) ? acf_get_field( $field['acf_key'] ) : null;
        if ( ! is_array( $schema ) || ( $schema['type'] ?? '' ) !== $field['kind'] || ! is_string( $value ) ) {
            return new WP_Error( 'nova_acf_block_schema', 'The mapped ACF field definition changed. Capture the design again.' );
        }
        if ( ( ! empty( $schema['required'] ) && '' === trim( $value ) )
            || ( ! empty( $schema['maxlength'] ) && preg_match_all( '/./us', $value ) > (int) $schema['maxlength'] )
            || ( 'email' === $schema['type'] && '' !== $value && ! filter_var( $value, FILTER_VALIDATE_EMAIL ) )
            || ( function_exists( 'acf_validate_value' ) && ! acf_validate_value( $value, $schema, $field['target'] ) ) ) {
            return new WP_Error( 'nova_acf_block_value', 'The mapped value does not satisfy the installed ACF field rules for ' . $field['label'] . '.' );
        }
        return true;
    }

    private static function walk( array $schema, array $data, string $prefix, string $path, string $label, bool $protected, array &$fields, int $depth = 0, array $scopes = [], string $row_path = '' ): void {
        if ( $depth > 16 ) { return; }
        foreach ( $schema as $field ) {
            $name = $field['name'] ?? ''; $key = $field['key'] ?? ''; $type = $field['type'] ?? '';
            if ( ! is_string( $name ) || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $name ) || ! is_string( $key ) || ! str_starts_with( $key, 'field_' ) ) { continue; }
            $flat = $prefix . $name;
            if ( ( $data[ '_' . $flat ] ?? null ) !== $key || ! array_key_exists( $flat, $data ) ) { continue; }
            $title = $label . ' › ' . ( $field['label'] ?? $name );
            $locked = $protected || ! empty( $field['readonly'] ) || ! empty( $field['disabled'] );
            if ( 'group' === $type ) {
                self::walk( $field['sub_fields'] ?? [], $data, $flat . '_', $path, $title, $locked, $fields, $depth + 1, $scopes, $row_path );
            } elseif ( in_array( $type, [ 'repeater', 'flexible_content' ], true ) ) {
                $rows = $data[$flat];
                $count = 'flexible_content' === $type && is_array( $rows ) ? count( $rows ) : ( filter_var( $rows, FILTER_VALIDATE_INT ) !== false ? (int) $rows : -1 );
                if ( $count < 0 || $count > 500 ) { continue; }
                $minimum = max( (int) ( $field['min'] ?? 0 ), empty( $field['required'] ) ? 0 : 1 );
                for ( $index = 0; $index < $count; ++$index ) {
                    $children = $field['sub_fields'] ?? []; $row_label = 'Row ' . ( $index + 1 ); $layout_allows = true;
                    if ( 'flexible_content' === $type ) {
                        $children = [];
                        foreach ( $field['layouts'] ?? [] as $layout ) {
                            if ( ( $layout['name'] ?? null ) === ( $rows[$index] ?? null ) ) {
                                $children = $layout['sub_fields'] ?? []; $row_label = $layout['label'] ?? $layout['name'];
                                $layout_allows = count( array_keys( $rows, $layout['name'], true ) ) > (int) ( $layout['min'] ?? 0 );
                                break;
                            }
                        }
                    }
                    $scope_path = $row_path;
                    $row_scopes = $scopes;
                    if ( '/meta' !== $path ) {
                        $parent = $row_path ?: substr( $path, 0, -strlen( '/attrs/data' ) ) . '/acf_rows';
                        $scope_path = $parent . '/' . $flat . '/' . $index;
                        if ( ! $locked && $layout_allows && $count > $minimum && strlen( $scope_path ) <= 1200 ) {
                            $row_scopes[] = [ 'target' => $scope_path, 'label' => $title . ' › ' . $row_label . ( 'flexible_content' === $type ? ' (row ' . ( $index + 1 ) . ')' : '' ) ];
                        }
                    }
                    self::walk( $children, $data, $flat . '_' . $index . '_', $path, $title . ' › ' . $row_label, $locked, $fields, $depth + 1, $row_scopes, $scope_path );
                }
            } elseif ( in_array( $type, [ 'text', 'textarea', 'wysiwyg', 'url', 'email' ], true ) && is_string( $data[$flat] ) ) {
                $sensitive = preg_match( '/<script\b|\[(?:gravityform|wpforms|contact-form|fluentform)|\$variable\(/i', $data[$flat] );
                $writable = ! $locked && ! $sensitive;
                $fields[] = [ 'target' => $path . '/' . $flat, 'label' => $title, 'format' => 'wysiwyg' === $type ? 'html' : ( 'url' === $type ? 'url' : 'text' ),
                    'kind' => $type, 'value' => $data[$flat], 'writable' => $writable, 'protected' => ! $writable, 'acf_key' => $key,
                    'reason' => $writable ? '' : 'This ACF value belongs to a protected or interactive field.' ] + ( $scopes ? [ 'section_scopes' => $scopes ] : [] );
            }
        }
    }

    /** Reindex both values and ACF's paired field-key references after the writer has bound the original rows. */
    public static function omit_row( array $native, string $target ): array {
        [ $path, $row ] = explode( '/acf_rows/', $target, 2 );
        $block = Nova_Bridge_Suite_Recipe_Engine::get( $native, $path );
        $allowed = false;
        foreach ( self::fields( $block ?? [], $path, ! empty( $block['attrs']['lock']['remove'] ) ) as $field ) {
            if ( $field['writable'] && in_array( $target, array_column( $field['section_scopes'] ?? [], 'target' ), true ) ) { $allowed = true; break; }
        }
        if ( ! $allowed ) { throw new InvalidArgumentException( 'The selected ACF row is unavailable or its minimum row count must be preserved.' ); }
        $parts = explode( '/', $row ); $index = (int) array_pop( $parts ); $collection = array_pop( $parts );
        $data = $block['attrs']['data']; $rows = $data[$collection];
        if ( is_array( $rows ) ) { array_splice( $rows, $index, 1 ); $data[$collection] = $rows; }
        else { $data[$collection] = is_string( $rows ) ? (string) ( (int) $rows - 1 ) : $rows - 1; }
        $result = [];
        foreach ( $data as $name => $value ) {
            if ( preg_match( '/^(_?)' . preg_quote( $collection, '/' ) . '_(\d+)(_.+)$/D', $name, $match ) ) {
                $position = (int) $match[2];
                if ( $position === $index ) { continue; }
                if ( $position > $index ) { $name = $match[1] . $collection . '_' . ( $position - 1 ) . $match[3]; }
            }
            $result[$name] = $value;
        }
        Nova_Bridge_Suite_Recipe_Engine::put( $native, $path . '/attrs/data', $result );
        return $native;
    }
}
