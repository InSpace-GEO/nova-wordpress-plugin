<?php
/** Native wpbakery layout helpers used by the recipe adapter. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function nova_mapping_wpb_parse_shortcodes_to_compact( $content ) {
	$content = (string) $content;
	$nodes   = array();

	if ( '' === $content ) {
		return $nodes;
	}

	$tags = nova_mapping_wpb_discover_shortcode_tags( $content );
	if ( empty( $tags ) ) {
		return $nodes;
	}

	$pattern = get_shortcode_regex( $tags );

	if ( ! preg_match_all( '/' . $pattern . '/s', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		return $nodes;
	}

	$cursor = 0;

	foreach ( $matches as $m ) {
		// WP core uses:
		// [1] = '[' escape, [2] = tag, [3] = attrs, [4] = selfclosing '/', [5] = inner, [6] = ']' escape
		$full         = isset( $m[0][0] ) ? (string) $m[0][0] : '';
		$match_offset = isset( $m[0][1] ) ? (int) $m[0][1] : $cursor;
		$tag          = isset( $m[2][0] ) ? (string) $m[2][0] : '';
		$atts_str     = isset( $m[3][0] ) ? (string) $m[3][0] : '';
		$inner        = isset( $m[5][0] ) ? (string) $m[5][0] : '';

		// Ignore escaped shortcodes like [[vc_row]] (rare in builder content).
		if ( isset( $m[1][0], $m[6][0] ) && '[' === $m[1][0] && ']' === $m[6][0] ) {
			continue;
		}
		if ( '' === $tag || '' === $full ) {
			continue;
		}

		$attributes = shortcode_parse_atts( $atts_str );
		if ( ! is_array( $attributes ) ) {
			$attributes = array();
		}

		// ✅ Correct self-closing detection: group 4 (NOT group 6).
		$explicit_self_closing = isset( $m[4][0] ) && '/' === $m[4][0];
		$has_closing_tag       = (bool) preg_match( '/\[\/' . preg_quote( $tag, '/' ) . '\]\]?$/', $full );
		$syntax                = $has_closing_tag ? 'paired' : ( $explicit_self_closing ? 'self_closing' : 'standalone' );
		$children              = nova_mapping_wpb_parse_shortcodes_to_compact( $inner );
		$raw_before            = substr( $content, $cursor, max( 0, $match_offset - $cursor ) );
		$raw_open              = $full;
		$raw_close             = '';
		if ( $has_closing_tag && preg_match( '/\[\/' . preg_quote( $tag, '/' ) . '\]\]?$/', $full, $closing, PREG_OFFSET_CAPTURE ) ) {
			$raw_close   = (string) $closing[0][0];
			$open_length = (int) $closing[0][1] - strlen( $inner );
			if ( $open_length >= 0 ) {
				$raw_open = substr( $full, 0, $open_length );
			}
		}
		$cursor                = $match_offset + strlen( $full );

		$nodes[] = array(
			'tag'                 => $tag,
			'attributes'          => $attributes,
			'text'                => empty( $children ) ? $inner : '',
			'self_closing'        => $explicit_self_closing,
			'syntax'              => $syntax,
			'children'            => $children,
			'raw_before'          => $raw_before,
			'raw_after'           => '',
			'raw_open'            => $raw_open,
			'raw_close'           => $raw_close,
			'original_tag'        => $tag,
			'original_attributes' => $attributes,
			'original_syntax'     => $syntax,
		);
	}

	if ( ! empty( $nodes ) ) {
		$last_index = count( $nodes ) - 1;
		$nodes[ $last_index ]['raw_after'] = substr( $content, $cursor );
	}

	return $nodes;
}

function nova_mapping_wpb_compact_to_shortcodes( $compact ) {
	$build = function ( $nodes ) use ( &$build ) {
		$out = '';

		foreach ( $nodes as $node ) {
			$tag        = isset( $node['tag'] ) ? (string) $node['tag'] : '';
			$attributes = isset( $node['attributes'] ) && is_array( $node['attributes'] ) ? $node['attributes'] : array();
			$children   = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
			$text       = isset( $node['text'] ) ? (string) $node['text'] : '';
			$raw_before = isset( $node['raw_before'] ) ? (string) $node['raw_before'] : '';
			$raw_after  = isset( $node['raw_after'] ) ? (string) $node['raw_after'] : '';

			$out .= $raw_before;
			if ( '' === $tag || ! preg_match( '/^[^\x00-\x20<>&\/\[\]=]+$/', $tag ) ) {
				$out .= $raw_after;
				continue;
			}

			$atts_str = '';
			foreach ( $attributes as $key => $value ) {
				$key = (string) $key;
				if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $key ) ) {
					continue;
				}
				$value = nova_mapping_wpb_scalar_attribute_value( $value );
				$atts_str .= ' ' . $key . '="' . esc_attr( $value ) . '"';
			}

			$inner = '';
			if ( ! empty( $children ) ) {
				$inner .= $build( $children );
			}
			if ( '' !== $text ) {
				$inner .= $text;
			}

			$syntax = isset( $node['syntax'] ) ? (string) $node['syntax'] : '';
			if ( '' !== $inner || 'paired' === $syntax ) {
				$target_syntax = 'paired';
			} elseif ( 'standalone' === $syntax ) {
				$target_syntax = 'standalone';
			} elseif ( 'self_closing' === $syntax || ! empty( $node['self_closing'] ) || nova_mapping_wpb_is_known_self_closing_tag( $tag ) ) {
				$target_syntax = 'self_closing';
			} else {
				$target_syntax = 'paired';
			}

			$opening         = null;
			$original_tag    = isset( $node['original_tag'] ) ? (string) $node['original_tag'] : '';
			$original_syntax = isset( $node['original_syntax'] ) ? (string) $node['original_syntax'] : '';
			if (
				$tag === $original_tag
				&& $target_syntax === $original_syntax
				&& isset( $node['raw_open'], $node['original_attributes'] )
			) {
				$opening = nova_mapping_wpb_patch_raw_shortcode_opening(
					(string) $node['raw_open'],
					$tag,
					$target_syntax,
					$node['original_attributes'],
					$attributes
				);
			}
			if ( null === $opening ) {
				$opening = '[' . $tag . $atts_str . ( 'self_closing' === $target_syntax ? ' /]' : ']' );
			}

			$out .= $opening;
			if ( 'paired' === $target_syntax ) {
				$out .= $inner;
				if ( $tag === $original_tag && 'paired' === $original_syntax && isset( $node['raw_close'] ) && '' !== (string) $node['raw_close'] ) {
					$out .= (string) $node['raw_close'];
				} else {
					$out .= '[/' . $tag . ']';
				}
			}

			$out .= $raw_after;
		}

		return $out;
	};

	return $build( $compact );
}

function nova_mapping_wpb_packed_link_url( $value, &$found = null ) {
	$found = false;
	if ( ! is_scalar( $value ) || ! preg_match( '/(^|\|)url:([^|]*)/', (string) $value, $match ) ) {
		return '';
	}

	$found = true;
	return urldecode( (string) $match[2] );
}

function nova_mapping_wpb_regenerate_shortcodes_custom_css_meta( $post_id, $shortcodes ) {
	$post_id    = (int) $post_id;
	$shortcodes = (string) $shortcodes;

	// The installed builder knows theme parameters and default/responsive CSS. A regex only
	// understands vc_custom_* rules and silently loses other registered design controls.
	if ( function_exists( 'wpbakery' ) ) {
		$base = wpbakery();
		if ( is_object( $base ) && is_callable( [ $base, 'buildShortcodesCss' ] ) ) {
			$base->buildShortcodesCss( $post_id, 'default' );
			$base->buildShortcodesCss( $post_id, 'custom' );
			return;
		}
		if ( is_object( $base ) && is_callable( [ $base, 'buildShortcodesCustomCss' ] ) ) { $base->buildShortcodesCustomCss( $post_id ); return; }
	}

	preg_match_all( '/\.vc_custom_\d+\s*\{[^}]*\}/s', $shortcodes, $m );
	$rules = array_values( array_unique( $m[0] ?? array() ) );
	$css   = implode( "\n", $rules );

	update_post_meta( $post_id, '_wpb_shortcodes_custom_css', $css );
}

function nova_mapping_wpb_discover_shortcode_tags( $content ) {
	$content = (string) $content;
	if ( '' === $content ) {
		return array();
	}

	if ( ! preg_match_all( '/(?<!\[)\[([^\x00-\x20<>&\/\[\]=]+)(?=[\x00-\x20\/\]])/', $content, $matches ) ) {
		return array();
	}

	return array_values( array_unique( array_map( 'strval', $matches[1] ) ) );
}

function nova_mapping_wpb_is_known_self_closing_tag( $tag ) {
	$tag = (string) $tag;
	return in_array(
		$tag,
		array(
			'vc_empty_space',
			'vc_single_image',
			'vc_custom_heading',
			'line_solid',
			'info_apps2',
			'heading',
			'button',
		),
		true
	);
}

function nova_mapping_wpb_patch_raw_shortcode_opening( $raw_open, $tag, $syntax, $original_attributes, $attributes ) {
	$index = nova_mapping_wpb_raw_shortcode_attribute_spans( $raw_open, $tag, $syntax );
	if ( null === $index || ! is_array( $original_attributes ) || ! is_array( $attributes ) ) {
		return null;
	}

	$replacements = array();
	$additions    = array();
	$keys         = array_unique( array_merge( array_keys( $original_attributes ), array_keys( $attributes ) ) );
	foreach ( $keys as $key ) {
		if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z0-9_-]+$/', $key ) ) {
			$had_original = array_key_exists( $key, $original_attributes );
			$has_current  = array_key_exists( $key, $attributes );
			if (
				$had_original !== $has_current
				|| ( $had_original && nova_mapping_wpb_scalar_attribute_value( $original_attributes[ $key ] ) !== nova_mapping_wpb_scalar_attribute_value( $attributes[ $key ] ) )
			) {
				return null;
			}
			continue;
		}

		$had_original = array_key_exists( $key, $original_attributes );
		$has_current  = array_key_exists( $key, $attributes );
		$old_value    = $had_original ? nova_mapping_wpb_scalar_attribute_value( $original_attributes[ $key ] ) : null;
		$new_value    = $has_current ? nova_mapping_wpb_scalar_attribute_value( $attributes[ $key ] ) : null;
		if ( $had_original === $has_current && $old_value === $new_value ) {
			continue;
		}

		if ( $had_original ) {
			if ( ! isset( $index['spans'][ $key ] ) ) {
				return null;
			}
			$span = $index['spans'][ $key ];
			if ( ! $has_current ) {
				$replacements[] = array( $span['key_start'], $span['token_end'], '' );
				continue;
			}

			$encoded = esc_attr( $new_value );
			if ( '' === $span['quote'] && ( '' === $encoded || preg_match( '/[\s\'"=<>`\]]/', $encoded ) ) ) {
				$encoded = '"' . $encoded . '"';
			}
			$replacements[] = array( $span['value_start'], $span['value_end'], $encoded );
			continue;
		}

		$additions[] = $key . '="' . esc_attr( $new_value ) . '"';
	}

	if ( ! empty( $additions ) ) {
		$insert_at  = (int) $index['attributes_end'];
		$has_space  = $insert_at > 0 && preg_match( '/\s/', $raw_open[ $insert_at - 1 ] );
		$is_closing = 'self_closing' === $syntax;
		$insert     = ( $has_space ? '' : ' ' ) . implode( ' ', $additions ) . ( $is_closing && $has_space ? ' ' : '' );
		$replacements[] = array( $insert_at, $insert_at, $insert );
	}

	usort(
		$replacements,
		function ( $a, $b ) {
			return $b[0] <=> $a[0];
		}
	);
	foreach ( $replacements as $replacement ) {
		$raw_open = substr_replace( $raw_open, $replacement[2], $replacement[0], $replacement[1] - $replacement[0] );
	}

	return $raw_open;
}

function nova_mapping_wpb_scalar_attribute_value( $value ) {
	if ( is_array( $value ) || is_object( $value ) ) {
		return wp_json_encode( $value );
	}
	if ( is_bool( $value ) ) {
		return $value ? 'true' : 'false';
	}
	return null === $value ? '' : (string) $value;
}

function nova_mapping_wpb_raw_shortcode_attribute_spans( $raw_open, $tag, $syntax ) {
	$raw_open = (string) $raw_open;
	$tag      = (string) $tag;
	$prefix   = '[' . $tag;

	if ( 0 !== strpos( $raw_open, $prefix ) ) {
		return null;
	}
	$prefix_length = strlen( $prefix );
	$boundary      = isset( $raw_open[ $prefix_length ] ) ? $raw_open[ $prefix_length ] : '';
	if ( '' !== $boundary && ! preg_match( '/[\s\/\]]/', $boundary ) ) {
		return null;
	}

	$end = strrpos( $raw_open, ']' );
	if ( false === $end ) {
		return null;
	}
	if ( 'self_closing' === $syntax && preg_match( '/\/\s*\]$/', $raw_open, $slash, PREG_OFFSET_CAPTURE ) ) {
		$end = (int) $slash[0][1];
	}

	$spans  = array();
	$length = strlen( $raw_open );
	$i      = $prefix_length;
	while ( $i < $end && $i < $length ) {
		while ( $i < $end && preg_match( '/\s/', $raw_open[ $i ] ) ) {
			$i++;
		}
		if ( $i >= $end ) {
			break;
		}

		$key_start = $i;
		while ( $i < $end && preg_match( '/[A-Za-z0-9_-]/', $raw_open[ $i ] ) ) {
			$i++;
		}
		if ( $i === $key_start ) {
			$quote = in_array( $raw_open[ $i ], array( '"', "'" ), true ) ? $raw_open[ $i ] : '';
			$i++;
			while ( $i < $end && ( '' !== $quote ? $raw_open[ $i ] !== $quote : ! preg_match( '/\s/', $raw_open[ $i ] ) ) ) {
				$i++;
			}
			if ( '' !== $quote && $i < $end ) {
				$i++;
			}
			continue;
		}

		$key = substr( $raw_open, $key_start, $i - $key_start );
		while ( $i < $end && preg_match( '/\s/', $raw_open[ $i ] ) ) {
			$i++;
		}
		if ( $i >= $end || '=' !== $raw_open[ $i ] ) {
			while ( $i < $end && ! preg_match( '/\s/', $raw_open[ $i ] ) ) {
				$i++;
			}
			continue;
		}

		$i++;
		while ( $i < $end && preg_match( '/\s/', $raw_open[ $i ] ) ) {
			$i++;
		}
		$quote       = ( $i < $end && in_array( $raw_open[ $i ], array( '"', "'" ), true ) ) ? $raw_open[ $i ] : '';
		$value_start = '' !== $quote ? ++$i : $i;
		while ( $i < $end && ( '' !== $quote ? $raw_open[ $i ] !== $quote : ! preg_match( '/\s/', $raw_open[ $i ] ) ) ) {
			$i++;
		}
		$value_end = $i;
		if ( '' !== $quote && $i < $end ) {
			$i++;
		}

		$spans[ $key ] = array(
			'key_start'   => $key_start,
			'value_start' => $value_start,
			'value_end'   => $value_end,
			'token_end'   => $i,
			'quote'       => $quote,
		);
	}

	return array(
		'attributes_end' => $end,
		'spans'          => $spans,
	);
}
