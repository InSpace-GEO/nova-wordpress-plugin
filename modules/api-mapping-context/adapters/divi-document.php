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

function nova_mapping_divi5_native_create_available() {
    return class_exists( 'WP_Block_Parser' ) && (
        ( defined( 'ET_BUILDER_5' ) && ET_BUILDER_5 ) ||
        ( defined( 'ET_BUILDER_VERSION' ) && 5 === (int) ET_BUILDER_VERSION )
    );
}

/** WordPress normally recovers unbalanced blocks; reject them before writing a different layout. */
function nova_mapping_divi5_validate_create_document( $document ) {
    $error = new WP_Error( 'nova_mapping_divi5_invalid_document', 'Use a complete native Divi 5 block layout.', [ 'status' => 422 ] );
    if ( ! is_string( $document ) || '' === trim( $document ) || strlen( $document ) > 2097152 || false !== strpos( $document, "\0" ) ||
        1 !== preg_match( '//u', $document ) || false !== strpos( $document, '[et_pb_' ) ) { return $error; }
    if ( ! class_exists( 'WP_Block_Parser' ) ) { return new WP_Error( 'missing_dependency', 'The WordPress block parser is unavailable.' ); }

    $parser = new WP_Block_Parser();
    $parser->document = $document;
    $parser->offset = 0;
    $stack = [];
    $roots = [];
    $count = 0;
    while ( true ) {
        list( $type, $name, $attrs, $start, $length ) = $parser->next_token();
        if ( 'no-more-tokens' === $type ) { break; }
        if ( '' !== trim( substr( $document, $parser->offset, $start - $parser->offset ) ) ) { return $error; }
        $parser->offset = $start + $length;
        if ( 'block-closer' === $type ) {
            if ( array_pop( $stack ) !== $name ) { return $error; }
            continue;
        }
        if ( ! in_array( $type, [ 'block-opener', 'void-block' ], true ) || ! is_array( $attrs ) ||
            0 === strpos( $name, 'core/' ) || ++$count > 1000 || count( $stack ) >= 32 ) { return $error; }
        if ( ! $stack ) {
            if ( 0 !== strpos( $name, 'divi/' ) ) { return $error; }
            $roots[] = $name;
        }
        if ( 'divi/placeholder' === $name && ( $stack || 'void-block' === $type ) ) { return $error; }
        if ( 'block-opener' === $type ) { $stack[] = $name; }
    }
    // The Visual Builder also opens unwrapped native roots and supplies its own placeholder.
    if ( $stack || ! $roots || ( count( $roots ) > 1 && in_array( 'divi/placeholder', $roots, true ) ) ||
        '' !== trim( substr( $document, $parser->offset ) ) ) { return $error; }
    return $document;
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

function nova_mapping_divi5_block_is_locked( $attributes ) {
    $lock_sets = array();
    if ( isset( $attributes['locked'] ) && is_array( $attributes['locked'] ) ) {
        $lock_sets[] = $attributes['locked'];
    }
    if ( isset( $attributes['module']['locked'] ) && is_array( $attributes['module']['locked'] ) ) {
        $lock_sets[] = $attributes['module']['locked'];
    }

    foreach ( $lock_sets as $lock_set ) {
        foreach ( $lock_set as $breakpoint ) {
            if ( is_array( $breakpoint ) && isset( $breakpoint['value'] ) && 'on' === $breakpoint['value'] ) {
                return true;
            }
        }
    }
    return false;
}

/** Enumerate only the module's existing editorial values, retaining design and dynamic attributes verbatim. */
function nova_mapping_divi5_editorial_fields( array $block ) {
    if ( ! empty( $block['innerBlocks'] ) || '' !== trim( $block['innerHTML'] ) ) { return []; }

    $controls = [
        'divi/heading' => [ 'title' => [ 'title', 'text' ] ],
        'divi/text' => [ 'body' => [ 'content', 'html' ] ],
        'divi/blurb' => [ 'title' => [ 'title', 'text' ], 'body' => [ 'content', 'html' ] ],
        'divi/accordion-item' => [ 'title' => [ 'title', 'text' ], 'body' => [ 'content', 'html' ] ],
        'divi/toggle' => [ 'title' => [ 'title', 'text' ], 'body' => [ 'content', 'html' ] ],
        'dsm/button' => [
            'button_one_text' => [ 'button_one_text', 'text' ], 'button_one_url' => [ 'button_one_url', 'url' ],
            'button_two_text' => [ 'button_two_text', 'text' ], 'button_two_url' => [ 'button_two_url', 'url' ],
        ],
    ];
    $name = $block['blockName'];
    $fields = [];
    foreach ( $controls[$name] ?? [] as $kind => list( $control, $format ) ) {
        $variants = $block['attrs'][$control]['innerContent'] ?? [];
        if ( ! is_array( $variants ) ) { continue; }
        foreach ( $variants as $screen => $variant ) {
            if ( ! in_array( $screen, [ 'desktop', 'tablet', 'phone', 'tabletWide', 'phoneWide', 'widescreen', 'ultraWide' ], true ) ) { continue; }
            $path = "$control/innerContent/$screen/value";
            $value = $variant['value'] ?? null;
            if ( is_array( $value ) && ! isset( $value['name'] ) && isset( $value['text'] ) ) { $path .= '/text'; $value = $value['text']; }
            if ( is_string( $value ) ) { $fields[] = [ $path, $format, $kind ]; }
        }
    }
    if ( 'divi/blurb' === $name && is_string( $block['attrs']['module']['advanced']['link']['desktop']['value']['url'] ?? null ) ) {
        $fields[] = [ 'module/advanced/link/desktop/value/url', 'url', 'link_url' ];
    }
    return $fields;
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
