<?php
/** Fixed term relationships for newly created native posts. Slugs travel across installations. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_WordPress_Fixed_Terms {
    public static function taxonomies( string $type ): array {
        $choices = [];
        foreach ( get_object_taxonomies( $type, 'objects' ) as $taxonomy ) {
            if ( $taxonomy->show_ui && ! in_array( $taxonomy->name, [ 'post_format', 'language', 'post_translations' ], true ) && current_user_can( $taxonomy->cap->assign_terms ) ) {
                $choices[$taxonomy->name] = $taxonomy->labels->name;
            }
        }
        return $choices;
    }

    public static function reference_links( int $post_id, array $taxonomies ): array {
        $links = [];
        if ( ! $post_id || ! $taxonomies ) { return $links; }
        $terms = wp_get_object_terms( $post_id, array_keys( $taxonomies ) );
        if ( is_wp_error( $terms ) ) { return $links; }
        foreach ( $terms as $term ) {
            $url = get_term_link( $term );
            if ( ! is_wp_error( $url ) ) { $links[$term->taxonomy][$term->slug] = $url; }
        }
        return $links;
    }

    public static function validate( $rules ): void {
        if ( ! is_array( $rules ) || array_values( $rules ) !== $rules || count( $rules ) > 250 ) { throw new InvalidArgumentException( 'Choose up to 250 fixed terms per layout.' ); }
        foreach ( $rules as $rule ) {
            if ( ! is_array( $rule ) || count( $rule ) !== 3 || ! is_string( $rule['taxonomy'] ?? null ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,32}$/D', $rule['taxonomy'] )
                || ! is_string( $rule['locale'] ?? null ) || ! preg_match( '/^(?:\*|[a-z]{2,3}(?:-[a-z0-9]{2,8})*)$/D', $rule['locale'] )
                || ! is_string( $rule['slug'] ?? null ) || '' === $rule['slug'] || strlen( $rule['slug'] ) > 200 ) {
                throw new InvalidArgumentException( 'Choose a registered term and a valid destination locale.' );
            }
        }
    }

    /** Resolve a single locale before the native mutation. Overrides replace only their own taxonomy. */
    public static function resolve( array $rules, string $type, string $locale, bool $all = false, string $native_code = '' ) {
        self::validate( $rules );
        $locale = strtolower( str_replace( '_', '-', $locale ) );
        if ( ! $all && '' === $native_code && class_exists( 'Nova_Bridge_Suite_Stored_Layout_Language' ) ) {
            $native_code = Nova_Bridge_Suite_Stored_Layout_Language::term_language_code( $locale, $type );
            if ( is_wp_error( $native_code ) ) { return $native_code; }
        }
        $eligible = self::taxonomies( $type );
        $groups = [];
        foreach ( $rules as $rule ) { $groups[$rule['taxonomy']][$rule['locale']][] = $rule['slug']; }
        $result = [];
        foreach ( $groups as $taxonomy => $scopes ) {
            if ( ! isset( $eligible[$taxonomy] ) ) { return new WP_Error( 'fixed_taxonomy_unavailable', 'A selected taxonomy is unavailable for this post type or posting user.' ); }
            $slugs = $all ? array_merge( ...array_values( $scopes ) ) : ( $scopes[$locale] ?? $scopes['*'] ?? [] );
            foreach ( array_unique( $slugs ) as $slug ) {
                $matches = get_terms( [ 'taxonomy' => $taxonomy, 'slug' => $slug, 'hide_empty' => false, 'number' => 2, 'lang' => '' ] );
                if ( is_wp_error( $matches ) ) { return $matches; }
                if ( count( $matches ) !== 1 || $matches[0]->slug !== $slug ) { return new WP_Error( 'fixed_term_missing', 'A selected slug must identify exactly one term in its taxonomy. Review the saved mapping.' ); }
                $id = (int) $matches[0]->term_id;
                if ( '' !== $native_code ) {
                    if ( function_exists( 'pll_is_translated_taxonomy' ) && pll_is_translated_taxonomy( $taxonomy ) ) {
                        $id = (int) pll_get_term( $id, $native_code );
                    } elseif ( apply_filters( 'wpml_is_translated_taxonomy', false, $taxonomy ) ) {
                        $id = (int) apply_filters( 'wpml_object_id', $id, $taxonomy, false, $native_code );
                    }
                    if ( ! $id || ! term_exists( $id, $taxonomy ) ) { return new WP_Error( 'fixed_term_translation_missing', 'The selected term needs a translation for the destination locale.' ); }
                }
                $result[$taxonomy][] = $id;
            }
        }
        foreach ( $result as &$ids ) { $ids = array_values( array_unique( $ids ) ); sort( $ids, SORT_NUMERIC ); } unset( $ids );
        ksort( $result );
        return $result;
    }

    public static function verify( int $id, array $assignments ) {
        foreach ( $assignments as $taxonomy => $expected ) {
            $actual = wp_get_object_terms( $id, $taxonomy, [ 'fields' => 'ids' ] );
            if ( is_wp_error( $actual ) ) { return $actual; }
            sort( $actual, SORT_NUMERIC ); sort( $expected, SORT_NUMERIC );
            if ( array_map( 'intval', $actual ) !== $expected ) { return new WP_Error( 'fixed_terms_changed', 'The WordPress term assignments differ from the saved mapping.' ); }
        }
        return true;
    }

    public static function write( int $id, array $assignments ) {
        foreach ( $assignments as $taxonomy => $ids ) {
            if ( ! isset( self::taxonomies( get_post_type( $id ) )[$taxonomy] ) ) { return new WP_Error( 'fixed_taxonomy_unavailable', 'The posting user cannot assign this taxonomy.' ); }
            foreach ( $ids as $term_id ) { if ( ! term_exists( $term_id, $taxonomy ) ) { return new WP_Error( 'fixed_term_missing', 'A selected term was removed.' ); } }
            $saved = wp_set_object_terms( $id, $ids, $taxonomy );
            if ( is_wp_error( $saved ) ) { return $saved; }
        }
        return self::verify( $id, $assignments );
    }
}
