<?php
/** Project JSON-LD from explicitly mapped scalar values. Retain only their structural ancestors. */
defined( 'ABSPATH' ) || exit;

final class Nova_Mapping_Gutenberg_Schema {
    public static function custom_purpose( array $binding ): bool {
        return str_starts_with( (string) ( $binding['id'] ?? '' ), 'nova_role_custom_' )
            && '' !== trim( (string) ( $binding['label'] ?? '' ) )
            && '' !== trim( (string) ( $binding['instructions'] ?? '' ) );
    }

    public static function document( string $json ) {
        return json_decode( $json, false, 64 );
    }

    public static function project( $node, string $path, string $label, callable $leaf ) {
        if ( is_array( $node ) ) {
            $items = [];
            foreach ( $node as $index => $child ) {
                $mapped = self::project( $child, $path . '/' . $index, $label . ' ' . ( $index + 1 ), $leaf );
                if ( null !== $mapped ) { $items[] = $mapped; }
            }
            return $items ?: null;
        }
        if ( is_object( $node ) ) {
            $object = new stdClass();
            $type = $node->{'@type'} ?? null;
            if ( is_string( $type ) ) { $label = trim( $label . ' ' . $type ); }
            foreach ( get_object_vars( $node ) as $key => $child ) {
                $key = (string) $key;
                if ( '' === $key || ( '@' === $key[0] && ! in_array( $key, [ '@graph', '@id' ], true ) ) ) { continue; }
                $pointer = $path . '/' . str_replace( [ '~', '/' ], [ '~0', '~1' ], $key );
                $mapped = self::project( $child, $pointer, $label . ' › ' . $key, $leaf );
                if ( null !== $mapped ) { $object->$key = $mapped; }
            }
            if ( ! get_object_vars( $object ) ) { return null; }
            foreach ( [ '@context', '@type' ] as $structural ) {
                if ( isset( $node->$structural ) ) { $object->$structural = $node->$structural; }
            }
            return $object;
        }
        if ( ! is_scalar( $node ) ) { return null; }
        $value = $leaf( substr( hash( 'sha256', $path ), 0, 24 ), trim( $label ), $node );
        if ( null === $value || '' === $value ) { return null; }
        if ( is_string( $node ) ) { return $value; }
        $typed = json_decode( $value );
        if ( ( is_bool( $node ) && is_bool( $typed ) ) || ( ! is_bool( $node ) && ( is_int( $typed ) || is_float( $typed ) ) ) ) { return $typed; }
        throw new InvalidArgumentException( 'Supply a JSON ' . ( is_bool( $node ) ? 'boolean' : 'number' ) . ' for ' . $label . '.' );
    }
}
