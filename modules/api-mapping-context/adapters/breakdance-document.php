<?php
/** Native breakdance layout helpers used by the recipe adapter. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Mapping_Breakdance_Document {
    public static function decode_breakdance_tree( $raw ) {
        if ( empty( $raw ) ) {
            return null;
        }

        $original_raw = $raw;
        $decoded      = null;

        // 1) If it's already an array, trust it.
        if ( is_array( $raw ) ) {
            $decoded = $raw;
        } else {
            // 2) Try WordPress-style serialization.
            $maybe_unserialized = maybe_unserialize( $raw );
            if ( is_array( $maybe_unserialized ) ) {
                $decoded = $maybe_unserialized;
            } else {
                // 3) Treat as JSON (with some forgiveness).
                $json_candidate = (string) $maybe_unserialized;
                if ( '' === trim( $json_candidate ) ) {
                    return null;
                }

                $decoded = json_decode( $json_candidate, true );

                if ( ! is_array( $decoded ) ) {
                    // Try again after un-slashing and trimming quotes.
                    $stripped = trim( $json_candidate, "\"'" );
                    $stripped = stripslashes( $stripped );
                    $decoded  = json_decode( $stripped, true );
                }

                if ( ! is_array( $decoded ) ) {
                    return null;
                }
            }
        }

        // Case 1: tree_json_string wrapper (most common).
        if ( isset( $decoded['tree_json_string'] ) && is_string( $decoded['tree_json_string'] ) ) {
            $inner_json = $decoded['tree_json_string'];
            $inner      = json_decode( $inner_json, true );

            if ( ! is_array( $inner ) ) {
                $inner = json_decode( stripslashes( $inner_json ), true );
            }

            if ( ! is_array( $inner ) || ! isset( $inner['root'] ) || ! is_array( $inner['root'] ) ) {
                return null;
            }

            return array(
                'raw'         => $original_raw,
                'decoded'     => $decoded,
                'inner'       => $inner,
                'root'        => $inner['root'],
                'root_source' => 'tree_json_string',
            );
        }

        // Case 2: plain { "root": { ... } }.
        if ( isset( $decoded['root'] ) && is_array( $decoded['root'] ) ) {
            return array(
                'raw'         => $original_raw,
                'decoded'     => $decoded,
                'root'        => $decoded['root'],
                'root_source' => 'root',
            );
        }

        // Case 3: single element export.
        if ( isset( $decoded['element'] ) && is_array( $decoded['element'] ) ) {
            $root = array(
                'id'       => 1,
                'data'     => array(
                    'type'       => 'root',
                    'properties' => array(),
                ),
                'children' => $decoded['element'],
            );

            return array(
                'raw'         => $original_raw,
                'decoded'     => $decoded,
                'root'        => $root,
                'root_source' => 'element',
            );
        }

        return null;
    }

    public static function get_raw_breakdance_data( $post_id ) {
        $raw = get_post_meta( $post_id, '_breakdance_data', true );
        if ( ! $raw ) {
            $raw = get_post_meta( $post_id, 'breakdance_data', true );
        }
        return $raw; // can be array or string
    }

    public static function encode_breakdance_tree( $wrapper, $new_root ) {
        if ( ! is_array( $wrapper ) || ! isset( $wrapper['root_source'] ) ) {
            $wrapper = self::build_empty_document_wrapper();
        }

        $decoded = isset( $wrapper['decoded'] ) && is_array( $wrapper['decoded'] ) ? $wrapper['decoded'] : array();
        $raw     = isset( $wrapper['raw'] ) ? $wrapper['raw'] : null;
        $source  = $wrapper['root_source'];

        if ( 'tree_json_string' === $source ) {
            $inner = isset( $wrapper['inner'] ) && is_array( $wrapper['inner'] )
                ? $wrapper['inner']
                : array(
                    'root'        => $new_root,
                    '_nextNodeId' => 2,
                    'status'      => 'exported',
                );

            $inner['root']               = $new_root;
            $decoded['tree_json_string'] = wp_json_encode( $inner );

        } elseif ( 'root' === $source ) {
            $decoded['root'] = $new_root;

        } elseif ( 'element' === $source ) {
            $decoded = array(
                'root' => $new_root,
            );

        } else {
            // Fallback.
            $decoded = array(
                'root' => $new_root,
            );
        }

        // Preserve original type.
        if ( is_array( $raw ) ) {
            return $decoded;
        }

        return wp_json_encode( $decoded );
    }

    public static function update_breakdance_meta( $post_id, $raw_value ) {
        $normalized = self::normalize_breakdance_meta_value( $raw_value );

        if ( '' === $normalized ) {
            return;
        }

        update_post_meta( $post_id, '_breakdance_data', $normalized );
        update_post_meta( $post_id, 'breakdance_data', $normalized );
    }

    public static function build_empty_document_wrapper() {
        $root = array(
            'id'       => 1,
            'data'     => array(
                'type'       => 'root',
                'properties' => array(),
            ),
            'children' => array(),
        );

        $inner = array(
            'root'        => $root,
            '_nextNodeId' => 2,
            'status'      => 'exported',
        );

        $decoded = array(
            'tree_json_string' => wp_json_encode( $inner ),
        );

        return array(
            'raw'         => $decoded, // note: array
            'decoded'     => $decoded,
            'inner'       => $inner,
            'root'        => $root,
            'root_source' => 'tree_json_string',
        );
    }

    public static function normalize_breakdance_meta_value( $value ) {
        // Convert arrays or serialized arrays to JSON.
        if ( is_array( $value ) ) {
            $value = wp_json_encode( $value );
        } elseif ( is_string( $value ) ) {
            $maybe_unserialized = maybe_unserialize( $value );

            if ( is_array( $maybe_unserialized ) ) {
                $value = wp_json_encode( $maybe_unserialized );
            } else {
                $decoded = json_decode( $value, true );
                if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
                    $value = wp_json_encode( $decoded );
                }
            }
        }

        if ( ! is_string( $value ) || '' === trim( $value ) ) {
            return '';
        }

        return wp_slash( $value );
    }
}
