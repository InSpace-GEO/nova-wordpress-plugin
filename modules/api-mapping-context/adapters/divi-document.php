<?php
/** Native divi layout helpers used by the recipe adapter. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function nova_mapping_divi_content_format( $content ) {
    $content        = (string) $content;
    $has_shortcodes = false !== strpos( $content, '[et_pb_' );
    $has_blocks     = 1 === preg_match( '/<!--\s+wp:divi\//i', $content );

    if ( $has_shortcodes && $has_blocks ) {
        return 'hybrid';
    }
    if ( $has_blocks ) {
        return 'divi5-blocks';
    }
    if ( $has_shortcodes ) {
        return 'divi4-shortcodes';
    }
    return 'plain';
}

function nova_mapping_divi_is_structural_container_tag( $tag ) {
    return in_array(
        (string) $tag,
        array(
            'et_pb_section',
            'et_pb_row',
            'et_pb_row_inner',
            'et_pb_column',
            'et_pb_column_inner',
            'et_pb_accordion',
            'et_pb_tabs',
            'et_pb_slider',
            'et_pb_video_slider',
            'et_pb_pricing_tables',
            'et_pb_counters',
            'et_pb_contact_form',
            'et_pb_social_media_follow',
            'et_pb_map',
            'et_pb_fullwidth_slider',
        ),
        true
    );
}

function nova_mapping_divi_decode_attr( $value ) {
    return str_replace(
        array( '%22', '%92', '%91', '%93' ),
        array( '"', '\\', '[', ']' ),
        (string) $value
    );
}

function nova_mapping_divi_encode_attr( $value ) {
    return str_replace(
        array( '\\', '"', '[', ']' ),
        array( '%92', '%22', '%91', '%93' ),
        (string) $value
    );
}

function nova_mapping_divi_clear_divi_caches() {
    if ( class_exists( 'ET_Core_PageResource' ) && method_exists( 'ET_Core_PageResource', 'remove_static_resources' ) ) {
        try {
            ET_Core_PageResource::remove_static_resources( 'all', 'all' );
        } catch ( \Throwable $e ) { // phpcs:ignore
            // Never let cache clearing break the write.
        }
    }

    if ( function_exists( 'et_core_clear_transients' ) ) {
        try {
            et_core_clear_transients();
        } catch ( \Throwable $e ) { // phpcs:ignore
            // Ignore.
        }
    }
}
