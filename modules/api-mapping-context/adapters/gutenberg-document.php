<?php
/** Native gutenberg layout helpers used by the recipe adapter. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function nova_mapping_gut_validate_native_document( $document ) {
	if ( ! is_string( $document ) || '' === trim( $document ) || strlen( $document ) > 2097152 || false !== strpos( $document, "\0" ) || 1 !== preg_match( '//u', $document ) ) {
		return new WP_Error( 'nova_mapping_gut_invalid_native_document', 'native_document requires a complete UTF-8 block document of at most 2 MiB.', array( 'status' => 422 ) );
	}
	if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
		return new WP_Error( 'nova_mapping_gut_native_parser_unavailable', 'The native WordPress block parser and serializer are unavailable.', array( 'status' => 503 ) );
	}
	if ( preg_match( '/<!--\s+wp:divi\//i', $document ) ) { return new WP_Error( 'nova_unavailable_builder', 'Divi 5 block layouts cannot be written as Gutenberg documents.' ); }
	$blocks = parse_blocks( $document );
	$count = 0;
	$has_native = false;
	$walk = function ( $nodes, $depth = 0 ) use ( &$walk, &$count, &$has_native ) {
		if ( ! is_array( $nodes ) || $depth > 32 ) { return false; }
		foreach ( $nodes as $block ) {
			if ( ! is_array( $block ) || ++$count > 1000 || ! array_key_exists( 'blockName', $block ) || ! is_array( $block['attrs'] ?? null ) || ! is_array( $block['innerBlocks'] ?? null ) || ! is_array( $block['innerContent'] ?? null ) || ! is_string( $block['innerHTML'] ?? null ) ) { return false; }
			if ( null !== $block['blockName'] ) { $has_native = true; }
			if ( ! $walk( $block['innerBlocks'], $depth + 1 ) ) { return false; }
		}
		return true;
	};
	if ( ! $walk( $blocks ) || ! $has_native || serialize_blocks( $blocks ) !== $document ) {
		return new WP_Error( 'nova_mapping_gut_invalid_native_document', 'Use a complete document from the native WordPress block serializer, within 1000 blocks and 32 levels. Malformed or ambiguous block attributes are not accepted.', array( 'status' => 422 ) );
	}
	return $document;
}
