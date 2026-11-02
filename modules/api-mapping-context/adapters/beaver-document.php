<?php
/** Native beaver layout helpers used by the recipe adapter. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function nova_mapping_bb_flat_to_tree( $flat ) {
    if ( empty( $flat ) || ! is_array( $flat ) ) {
        return array();
    }

    $by_id    = array();
    $children = array();
    $insert_i = 0;

    foreach ( $flat as $key => $node ) {
        $node_id = is_string( $key ) && '' !== $key ? $key : (string) nova_mapping_bb_setting_get( $node, 'node', '' );
        if ( '' === $node_id ) {
            $node_id = 'nova-tmp-' . $insert_i;
        }

        $parent = nova_mapping_bb_setting_get( $node, 'parent', null );
        $parent = ( null === $parent || '' === $parent || false === $parent ) ? null : (string) $parent;

        $by_id[ $node_id ] = array(
            'node_id'  => $node_id,
            'type'     => (string) nova_mapping_bb_setting_get( $node, 'type', 'module' ),
            'settings' => nova_mapping_bb_setting_get( $node, 'settings', new stdClass() ),
            'orig'     => is_object( $node ) ? $node : null,
            'parent'   => $parent,
            'position' => (int) nova_mapping_bb_setting_get( $node, 'position', 0 ),
            'insert'   => $insert_i++,
        );
    }

    foreach ( $by_id as $node_id => $entry ) {
        $parent = ( null !== $entry['parent'] && isset( $by_id[ $entry['parent'] ] ) ) ? $entry['parent'] : '';
        $children[ $parent ][] = $node_id;
    }

    $sort = function ( $ids ) use ( $by_id ) {
        usort(
            $ids,
            function ( $a, $b ) use ( $by_id ) {
                $pa = $by_id[ $a ]['position'];
                $pb = $by_id[ $b ]['position'];
                if ( $pa === $pb ) {
                    return $by_id[ $a ]['insert'] <=> $by_id[ $b ]['insert'];
                }
                return $pa <=> $pb;
            }
        );
        return $ids;
    };

    $visited = array();

    $build = function ( $node_id ) use ( &$build, &$visited, $by_id, $children, $sort ) {
        $visited[ $node_id ] = true;
        $entry               = $by_id[ $node_id ];

        $kids = array();
        if ( ! empty( $children[ $node_id ] ) ) {
            foreach ( $sort( $children[ $node_id ] ) as $child_id ) {
                if ( isset( $visited[ $child_id ] ) ) {
                    continue; // Cycle guard.
                }
                $kids[] = $build( $child_id );
            }
        }

        return array(
            'node_id'  => $entry['node_id'],
            'type'     => $entry['type'],
            'settings' => $entry['settings'],
            'orig'     => $entry['orig'],
            'children' => $kids,
        );
    };

    $tree = array();
    if ( ! empty( $children[''] ) ) {
        foreach ( $sort( $children[''] ) as $root_id ) {
            if ( ! isset( $visited[ $root_id ] ) ) {
                $tree[] = $build( $root_id );
            }
        }
    }

    // Cycle leftovers: lift to top level rather than dropping them.
    foreach ( array_keys( $by_id ) as $node_id ) {
        if ( ! isset( $visited[ $node_id ] ) ) {
            $tree[] = $build( $node_id );
        }
    }

    return $tree;
}

function nova_mapping_bb_get_layout_nodes( $post_id ) {
    $data = get_post_meta( (int) $post_id, '_fl_builder_data', true );

    if ( empty( $data ) || ! is_array( $data ) ) {
        return array();
    }

    return $data;
}

function nova_mapping_bb_normalize_incoming_nodes( $nodes ) {
    if ( empty( $nodes ) || ! is_array( $nodes ) ) {
        return array();
    }

    $out = array();
    foreach ( $nodes as $key => $node ) {
        $out[ $key ] = nova_mapping_bb_settings_from_json( $node );
    }
    return $out;
}

function nova_mapping_bb_tree_to_flat( $tree, $regenerate_ids = false ) {
    $flat = array();
    $used = array();

    // Reserve kept IDs first so generated ones can't collide with them.
    if ( ! $regenerate_ids ) {
        $reserve = function ( $nodes ) use ( &$reserve, &$used ) {
            foreach ( $nodes as $node ) {
                if ( ! empty( $node['node_id'] ) ) {
                    $used[ (string) $node['node_id'] ] = true;
                }
                if ( ! empty( $node['children'] ) ) {
                    $reserve( $node['children'] );
                }
            }
        };
        $reserve( $tree );
    }

    $walk = function ( $nodes, $parent_id ) use ( &$walk, &$flat, &$used, $regenerate_ids ) {
        $position = 0;

        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }

            $node_id = ( ! $regenerate_ids && ! empty( $node['node_id'] ) && 0 !== strpos( (string) $node['node_id'], 'nova-tmp-' ) )
                ? (string) $node['node_id']
                : nova_mapping_bb_generate_node_id( $used );

            $node_type = isset( $node['type'] ) ? (string) $node['type'] : 'module';
            $settings  = isset( $node['settings'] ) ? $node['settings'] : new stdClass();
            if ( is_array( $settings ) ) {
                $settings = nova_mapping_bb_settings_from_json( $settings );
            }
            if ( 'column-group' === $node_type && '' === $settings ) {
                // Beaver stores column-group settings as an empty string.
            } elseif ( ! is_object( $settings ) ) {
                $settings = new stdClass();
            }

            // Preserve unknown properties from the source node object (BB may
            // add fields we don't model); ours override structure/settings.
            if ( isset( $node['orig'] ) && is_object( $node['orig'] ) ) {
                $obj = clone $node['orig'];
            } else {
                $obj = new stdClass();
            }

            $obj->node     = $node_id;
            $obj->type     = $node_type;
            $obj->parent   = $parent_id;
            $obj->position = $position;
            $obj->settings = $settings;

            $flat[ $node_id ] = $obj;
            $position++;

            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $walk( $node['children'], $node_id );
            }
        }
    };

    $walk( $tree, null );

    return $flat;
}

function nova_mapping_bb_slash_layout_data( $value ) {
    if ( is_string( $value ) ) {
        return addslashes( $value );
    }

    if ( is_array( $value ) ) {
        return array_map( 'nova_mapping_bb_slash_layout_data', $value );
    }

    if ( is_object( $value ) ) {
        $out = clone $value;
        foreach ( get_object_vars( $out ) as $k => $v ) {
            $out->{$k} = nova_mapping_bb_slash_layout_data( $v );
        }
        return $out;
    }

    return $value;
}

function nova_mapping_bb_tree_to_fallback_html( $tree ) {
    $parts = array();

    $walk = function ( $nodes ) use ( &$walk, &$parts ) {
        foreach ( $nodes as $node ) {
            if ( isset( $node['type'] ) && 'module' === $node['type'] ) {
                $settings = isset( $node['settings'] ) ? $node['settings'] : null;
                $module   = nova_mapping_bb_module_slug( $settings );

                switch ( $module ) {
                    case 'heading':
                        $tag  = strtolower( (string) nova_mapping_bb_setting_get( $settings, 'tag', 'h2' ) );
                        $tag  = preg_match( '/^h[1-6]$/', $tag ) ? $tag : 'h2';
                        $text = trim( wp_strip_all_tags( (string) nova_mapping_bb_setting_get( $settings, 'heading', '' ) ) );
                        if ( '' !== $text ) {
                            $parts[] = '<' . $tag . '>' . esc_html( $text ) . '</' . $tag . '>';
                        }
                        break;

                    case 'rich-text':
                        $html = trim( (string) nova_mapping_bb_setting_get( $settings, 'text', '' ) );
                        if ( '' !== $html ) {
                            $parts[] = $html;
                        }
                        break;

                    case 'callout':
                    case 'cta':
                        $title = trim( wp_strip_all_tags( (string) nova_mapping_bb_setting_get( $settings, 'title', '' ) ) );
                        $body  = trim( (string) nova_mapping_bb_setting_get( $settings, 'text', '' ) );
                        if ( '' !== $title ) {
                            $parts[] = '<h3>' . esc_html( $title ) . '</h3>';
                        }
                        if ( '' !== $body ) {
                            $parts[] = $body;
                        }
                        break;

                    case 'accordion':
                    case 'tabs':
                        foreach ( nova_mapping_bb_get_module_items( $settings ) as $item ) {
                            $label   = trim( wp_strip_all_tags( (string) nova_mapping_bb_setting_get( $item, 'label', '' ) ) );
                            $content = trim( (string) nova_mapping_bb_setting_get( $item, 'content', '' ) );
                            if ( '' !== $label ) {
                                $parts[] = '<h3>' . esc_html( $label ) . '</h3>';
                            }
                            if ( '' !== $content ) {
                                $parts[] = $content;
                            }
                        }
                        break;
                }
            }

            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $walk( $node['children'] );
            }
        }
    };

    $walk( $tree );

    return implode( "\n", $parts );
}

function nova_mapping_bb_clear_bb_caches( $post_id ) {
    if ( ! class_exists( 'FLBuilderModel' ) ) {
        return;
    }

    if ( method_exists( 'FLBuilderModel', 'delete_asset_cache' ) ) {
        try {
            FLBuilderModel::delete_asset_cache( (int) $post_id );
        } catch ( \Throwable $e ) { // phpcs:ignore
            // Never let cache clearing break the write.
        }
    }

    if ( method_exists( 'FLBuilderModel', 'delete_all_transients' ) ) {
        try {
            FLBuilderModel::delete_all_transients();
        } catch ( \Throwable $e ) { // phpcs:ignore
            // Ignore.
        }
    }
}

function nova_mapping_bb_setting_get( $settings, $key, $default = '' ) {
    if ( is_object( $settings ) && isset( $settings->{$key} ) ) {
        return $settings->{$key};
    }
    if ( is_array( $settings ) && isset( $settings[ $key ] ) ) {
        return $settings[ $key ];
    }
    return $default;
}

function nova_mapping_bb_settings_from_json( $value ) {
    if ( is_object( $value ) ) {
        $value = get_object_vars( $value );
        $obj   = new stdClass();
        foreach ( $value as $k => $v ) {
            $obj->{$k} = nova_mapping_bb_settings_from_json( $v );
        }
        return $obj;
    }

    if ( is_array( $value ) ) {
        if ( array() === $value ) {
            return array(); // Empty lists (e.g. `items`) must stay arrays.
        }

        $is_list = ( array_keys( $value ) === range( 0, count( $value ) - 1 ) );

        if ( $is_list ) {
            return array_map( 'nova_mapping_bb_settings_from_json', $value );
        }

        $obj = new stdClass();
        foreach ( $value as $k => $v ) {
            $obj->{$k} = nova_mapping_bb_settings_from_json( $v );
        }
        return $obj;
    }

    return $value;
}

function nova_mapping_bb_generate_node_id( &$used = null ) {
    $tries = 0;

    do {
        if ( nova_mapping_bb_builder_active() && method_exists( 'FLBuilderModel', 'generate_node_id' ) ) {
            $id = (string) FLBuilderModel::generate_node_id();
        } else {
            $id = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 13 );
        }
        $tries++;
    } while ( is_array( $used ) && isset( $used[ $id ] ) && $tries < 100 );

    if ( is_array( $used ) ) {
        $used[ $id ] = true;
    }

    return $id;
}

function nova_mapping_bb_get_module_items( $settings ) {
    $items = nova_mapping_bb_setting_get( $settings, 'items', array() );
    return is_array( $items ) ? array_values( $items ) : array();
}

function nova_mapping_bb_module_slug( $settings ) {
    return (string) nova_mapping_bb_setting_get( $settings, 'type', '' );
}

function nova_mapping_bb_builder_active() {
    return class_exists( 'FLBuilderModel' );
}
