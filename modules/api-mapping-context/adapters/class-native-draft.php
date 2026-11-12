<?php
/** Create one draft from a compiled native document. A reference page is never an input. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Native_Layout_Draft {
    public static function create( string $builder, array $payload, array $fields ) {
        $type = $fields['post_type'] ?? 'page';
        $title = trim( (string) ( $fields['title'] ?? '' ) );
        if ( '' === $title ) { return new WP_Error( 'nova_draft_title', 'A draft title is required.' ); }
        $post = [ 'post_type' => $type, 'post_status' => 'draft', 'post_title' => $title,
            'post_excerpt' => (string) ( $fields['excerpt'] ?? '' ), 'post_name' => (string) ( $fields['slug'] ?? '' ) ];
        if ( ! empty( $fields['parent_id'] ) ) {
            $parent = get_post( (int) $fields['parent_id'] );
            if ( ! $parent || $parent->post_type !== $type || ! current_user_can( 'edit_post', $parent->ID ) ) {
                return new WP_Error( 'nova_draft_parent', 'Choose an editable parent of the same post type.' );
            }
            $post['post_parent'] = $parent->ID;
        }
        if ( ! empty( $fields['author'] ) ) {
            $object = get_post_type_object( $type );
            if ( (int) $fields['author'] !== get_current_user_id() && ! current_user_can( $object->cap->edit_others_posts ) ) {
                return new WP_Error( 'nova_draft_author', 'You cannot assign this draft to another author.' );
            }
            $post['post_author'] = (int) $fields['author'];
        }
        $meta = $payload['meta'] ?? [];
        $layout = $payload['layout'] ?? [];
        switch ( $builder ) {
            case 'gutenberg':
                $post['post_content'] = nova_mapping_gut_validate_native_document( $payload['native_document'] ?? null );
                break;
            case 'divi5':
                if ( ! current_user_can( 'unfiltered_html' ) ) { return new WP_Error( 'nova_mapping_divi5_forbidden', 'Native Divi documents require unfiltered HTML permission.' ); }
                $post['post_content'] = nova_mapping_divi5_validate_create_document( $layout['native_document'] ?? '' );
                $meta['_et_pb_use_divi_5'] = 'on';
                $meta['_et_pb_use_builder'] = 'on';
                $meta['_et_pb_show_page_creation'] = 'off';
                break;
            case 'divi4':
            case 'wpbakery':
            case 'avada':
                if ( ! is_string( $layout['raw_shortcodes'] ?? null ) || '' === trim( $layout['raw_shortcodes'] ) ) {
                    return new WP_Error( 'nova_draft_layout', 'The compiled shortcode document is missing.' );
                }
                $post['post_content'] = $layout['raw_shortcodes'];
                if ( 'divi4' === $builder ) { $meta['_et_pb_use_builder'] = 'on'; }
                if ( 'wpbakery' === $builder ) { $meta['_wpb_vc_js_status'] = 'true'; }
                if ( 'avada' === $builder ) {
                    $meta['_fusion_builder_status'] = 'active';
                    $meta['_fusion_builder_shortcodes'] = $post['post_content'];
                    $meta['_fusion_builder_content'] = $post['post_content'];
                }
                break;
            case 'beaver':
                if ( empty( $layout['nodes'] ) || ! is_array( $layout['nodes'] ) ) { return new WP_Error( 'nova_draft_layout', 'The compiled Beaver document is missing.' ); }
                $nodes = nova_mapping_bb_normalize_incoming_nodes( $layout['nodes'], $layout['array_paths'] ?? [] );
                $post['post_content'] = nova_mapping_bb_tree_to_fallback_html( nova_mapping_bb_flat_to_tree( $nodes ) );
                $meta['_fl_builder_enabled'] = true;
                break;
            case 'breakdance':
                if ( ! Nova_Mapping_Breakdance_Document::decode_breakdance_tree( $layout['raw_data'] ?? '' ) ) { return new WP_Error( 'nova_draft_layout', 'The compiled Breakdance document is missing.' ); }
                $post['post_content'] = '';
                break;
            default:
                return new WP_Error( 'nova_draft_builder', 'The native draft provider is unavailable.' );
        }
        if ( is_wp_error( $post['post_content'] ) ) { return $post['post_content']; }
        $id = wp_insert_post( wp_slash( $post ), true );
        if ( is_wp_error( $id ) ) { return $id; }
        foreach ( $meta as $key => $value ) {
            update_post_meta( $id, $key, wp_slash( $value ) );
        }
        if ( 'beaver' === $builder ) {
            update_post_meta( $id, '_fl_builder_data', nova_mapping_bb_slash_layout_data( $nodes ) );
            delete_post_meta( $id, '_fl_builder_draft' );
            delete_post_meta( $id, '_fl_builder_draft_settings' );
            nova_mapping_bb_clear_bb_caches( $id );
        } elseif ( 'breakdance' === $builder ) {
            Nova_Mapping_Breakdance_Document::update_breakdance_meta( $id, $layout['raw_data'] );
        } elseif ( 'wpbakery' === $builder ) {
            nova_mapping_wpb_regenerate_shortcodes_custom_css_meta( $id, $post['post_content'] );
        } elseif ( 'divi4' === $builder ) {
            nova_mapping_divi_clear_divi_caches();
        } elseif ( 'avada' === $builder && function_exists( 'fusion_reset_page_cached_css' ) ) {
            fusion_reset_page_cached_css( $id );
        }
        return (int) $id;
    }
}
