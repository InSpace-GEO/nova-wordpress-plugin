<?php
/**
 * Shared route/base slug conflict detection for NOVA-managed CPT settings.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nova_bridge_suite_normalize_route_base' ) ) {
	function nova_bridge_suite_normalize_route_base( $value ): string {
		$value = trim( (string) $value );
		$value = trim( $value, "/ \t\n\r\0\x0B" );
		$value = sanitize_title_with_dashes( $value );

		return trim( $value, "/ \t\n\r\0\x0B" );
	}
}

if ( ! function_exists( 'nova_bridge_suite_route_base_matches' ) ) {
	function nova_bridge_suite_route_base_matches( string $candidate, string $base ): bool {
		$candidate = nova_bridge_suite_normalize_route_base( $candidate );
		$base      = nova_bridge_suite_normalize_route_base( $base );

		return '' !== $candidate && '' !== $base && $candidate === $base;
	}
}

if ( ! function_exists( 'nova_bridge_suite_find_route_base_conflict' ) ) {
	function nova_bridge_suite_find_route_base_conflict( string $base, array $args = [] ): ?array {
		$base = nova_bridge_suite_normalize_route_base( $base );
		if ( '' === $base ) {
			return [
				'type'    => 'empty',
				'message' => __( 'The CPT base slug cannot be empty.', 'nova-bridge-suite' ),
			];
		}

		$exclude_post_types = array_fill_keys(
			array_filter( array_map( 'sanitize_key', (array) ( $args['exclude_post_types'] ?? [] ) ) ),
			true
		);
		$exclude_taxonomies = array_fill_keys(
			array_filter( array_map( 'sanitize_key', (array) ( $args['exclude_taxonomies'] ?? [] ) ) ),
			true
		);

		$reserved = [
			'admin',
			'api',
			'attachment',
			'author',
			'category',
			'comment-page',
			'comments',
			'embed',
			'feed',
			'index.php',
			'page',
			'paged',
			'search',
			'tag',
			'trackback',
			'wp-admin',
			'wp-content',
			'wp-includes',
			'wp-json',
		];

		global $wp_rewrite;
		if ( $wp_rewrite instanceof WP_Rewrite ) {
			foreach ( [ 'author_base', 'comments_pagination_base', 'pagination_base', 'search_base' ] as $property ) {
				if ( isset( $wp_rewrite->{$property} ) && is_string( $wp_rewrite->{$property} ) ) {
					$reserved[] = $wp_rewrite->{$property};
				}
			}

			if ( is_array( $wp_rewrite->feeds ) ) {
				$reserved = array_merge( $reserved, $wp_rewrite->feeds );
			}
		}

		foreach ( array_unique( $reserved ) as $reserved_base ) {
			if ( nova_bridge_suite_route_base_matches( (string) $reserved_base, $base ) ) {
				return [
					'type'    => 'reserved',
					'message' => sprintf(
						/* translators: %s: attempted CPT base slug. */
						__( 'The CPT base slug "%s" is reserved by WordPress. Choose a unique base slug.', 'nova-bridge-suite' ),
						$base
					),
				];
			}
		}

		if ( post_type_exists( $base ) && empty( $exclude_post_types[ $base ] ) ) {
			return [
				'type'    => 'post_type',
				'message' => sprintf(
					/* translators: 1: attempted CPT base slug, 2: post type key. */
					__( 'The CPT base slug "%1$s" is already used by the "%2$s" post type. Choose a unique base slug.', 'nova-bridge-suite' ),
					$base,
					$base
				),
			];
		}

		$public_post_types = get_post_types( [ 'public' => true ], 'names' );
		$public_post_types = array_values(
			array_filter(
				(array) $public_post_types,
				static function ( $post_type ) use ( $exclude_post_types ): bool {
					$post_type = sanitize_key( (string) $post_type );
					return '' !== $post_type && empty( $exclude_post_types[ $post_type ] ) && 'attachment' !== $post_type;
				}
			)
		);

		if ( ! empty( $public_post_types ) ) {
			$post = get_page_by_path( $base, OBJECT, $public_post_types );
			if ( $post instanceof WP_Post && ! in_array( $post->post_status, [ 'auto-draft', 'inherit', 'trash' ], true ) ) {
				$title = get_the_title( $post );
				return [
					'type'    => 'post',
					'id'      => (int) $post->ID,
					'message' => sprintf(
						/* translators: 1: attempted CPT base slug, 2: post type, 3: post title, 4: post ID. */
						__( 'The CPT base slug "%1$s" is already used by an existing %2$s: "%3$s" (#%4$d). Choose a unique base slug.', 'nova-bridge-suite' ),
						$base,
						$post->post_type,
						'' !== $title ? $title : $post->post_name,
						(int) $post->ID
					),
				];
			}
		}

		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $post_type => $post_type_object ) {
			$post_type = sanitize_key( (string) $post_type );
			if ( '' === $post_type || ! empty( $exclude_post_types[ $post_type ] ) ) {
				continue;
			}

			$candidates = [];
			if ( is_array( $post_type_object->rewrite ) && ! empty( $post_type_object->rewrite['slug'] ) ) {
				$candidates[] = (string) $post_type_object->rewrite['slug'];
			}
			if ( is_string( $post_type_object->has_archive ) && '' !== $post_type_object->has_archive ) {
				$candidates[] = $post_type_object->has_archive;
			} elseif ( true === $post_type_object->has_archive ) {
				$candidates[] = ! empty( $candidates ) ? (string) reset( $candidates ) : $post_type;
			}

			foreach ( $candidates as $candidate ) {
				if ( nova_bridge_suite_route_base_matches( $candidate, $base ) ) {
					return [
						'type'    => 'post_type',
						'message' => sprintf(
							/* translators: 1: attempted CPT base slug, 2: post type key. */
							__( 'The CPT base slug "%1$s" is already used by the "%2$s" post type. Choose a unique base slug.', 'nova-bridge-suite' ),
							$base,
							$post_type
						),
					];
				}
			}
		}

		foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $taxonomy => $taxonomy_object ) {
			$taxonomy = sanitize_key( (string) $taxonomy );
			if ( '' === $taxonomy || ! empty( $exclude_taxonomies[ $taxonomy ] ) || ! is_array( $taxonomy_object->rewrite ) ) {
				continue;
			}

			$taxonomy_base = isset( $taxonomy_object->rewrite['slug'] ) ? (string) $taxonomy_object->rewrite['slug'] : '';
			if ( nova_bridge_suite_route_base_matches( $taxonomy_base, $base ) ) {
				return [
					'type'    => 'taxonomy',
					'message' => sprintf(
						/* translators: 1: attempted CPT base slug, 2: taxonomy key. */
						__( 'The CPT base slug "%1$s" is already used by the "%2$s" taxonomy. Choose a unique base slug.', 'nova-bridge-suite' ),
						$base,
						$taxonomy
					),
				];
			}
		}

		if ( $wp_rewrite instanceof WP_Rewrite ) {
			$rules = $wp_rewrite->wp_rewrite_rules();
			if ( is_array( $rules ) ) {
				foreach ( $rules as $pattern => $query ) {
					if ( ! is_string( $pattern ) ) {
						continue;
					}

					$normalized_pattern = ltrim( $pattern, '^' );
					if ( 0 !== strpos( $normalized_pattern, $base ) ) {
						continue;
					}

					$next_char = substr( $normalized_pattern, strlen( $base ), 1 );
					if ( ! in_array( $next_char, [ '', '/', '\\', '(', '?' ], true ) ) {
						continue;
					}

					$query = is_string( $query ) ? $query : '';
					$owned_by_excluded_post_type = false;
					foreach ( array_keys( $exclude_post_types ) as $excluded_post_type ) {
						if ( false !== strpos( $query, 'post_type=' . $excluded_post_type ) ) {
							$owned_by_excluded_post_type = true;
							break;
						}
					}

					if ( $owned_by_excluded_post_type ) {
						continue;
					}

					return [
						'type'    => 'rewrite',
						'message' => sprintf(
							/* translators: %s: attempted CPT base slug. */
							__( 'The CPT base slug "%s" is already used by an existing rewrite route. Choose a unique base slug.', 'nova-bridge-suite' ),
							$base
						),
					];
				}
			}
		}

		return null;
	}
}
