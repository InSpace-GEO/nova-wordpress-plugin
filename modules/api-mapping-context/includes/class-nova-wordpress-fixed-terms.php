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

    /** Return one picker choice per translation group, preferring the requested language. */
    public static function picker_term( $term, string $preferred ): array {
        $taxonomy = $term->taxonomy;
        $members = [];
        $main = '';
        if ( function_exists( 'pll_is_translated_taxonomy' ) && pll_is_translated_taxonomy( $taxonomy ) ) {
            $main = (string) pll_default_language();
            foreach ( pll_get_term_translations( $term->term_id ) as $code => $id ) {
                $members[$code] = get_term( $id, $taxonomy );
            }
        } elseif ( apply_filters( 'wpml_is_translated_taxonomy', false, $taxonomy ) ) {
            $main = (string) apply_filters( 'wpml_default_language', null );
            $type = 'tax_' . $taxonomy;
            $trid = apply_filters( 'wpml_element_trid', null, $term->term_taxonomy_id, $type );
            if ( $trid ) {
                foreach ( (array) apply_filters( 'wpml_get_element_translations', [], $trid, $type ) as $translation ) {
                    $id = (int) apply_filters( 'wpml_object_id', $term->term_id, $taxonomy, false, $translation->language_code );
                    $member = $id ? WP_Term::get_instance( $id, $taxonomy ) : null;
                    if ( $member instanceof WP_Term && (int) $member->term_taxonomy_id === (int) $translation->element_id ) {
                        $members[$translation->language_code] = $member;
                    }
                }
            }
        }
        $variants = [];
        foreach ( $members as $code => $member ) {
            if ( ! $member || is_wp_error( $member ) || $member->taxonomy !== $taxonomy ) { continue; }
            $variants[$code] = [ 'slug' => $member->slug, 'label' => $member->name, 'count' => (int) $member->count, 'language' => $code ];
        }
        if ( ! $variants ) { return [ 'slug' => $term->slug, 'label' => $term->name, 'count' => (int) $term->count, 'translations' => [] ]; }
        ksort( $variants );
        $primary = $variants[$preferred] ?? $variants[$main] ?? reset( $variants );
        $locale_only = ! isset( $variants[$main] );
        unset( $variants[$primary['language']] );
        return $primary + [ 'locale_only' => $locale_only, 'translations' => array_values( $variants ) ];
    }

    /** Query across languages even when WPML filters the current admin request. */
    public static function all_language_terms( array $args ) {
        // WPML can rewrite a saved slug to the request language even after switching to "all".
        // Resolve native IDs first, then read their stored slugs before selecting a translation.
        if ( isset( $args['slug'] ) && defined( 'WPML\TaxonomyTermTranslation\Hooks::KEY_SKIP_FILTERS' ) && apply_filters( 'wpml_is_translated_taxonomy', false, $args['taxonomy'] ) ) {
            $ids = get_terms( [ 'fields' => 'ids', \WPML\TaxonomyTermTranslation\Hooks::KEY_SKIP_FILTERS => true ] + $args );
            if ( is_wp_error( $ids ) ) { return $ids; }
            $terms = [];
            foreach ( $ids as $id ) {
                $term = WP_Term::get_instance( (int) $id, $args['taxonomy'] );
                if ( is_wp_error( $term ) ) { return $term; }
                if ( $term ) { $terms[] = $term; }
            }
            return $terms;
        }
        $current = apply_filters( 'wpml_current_language', null );
        if ( ! $current || 'all' === $current ) { return get_terms( $args + [ 'lang' => '' ] ); }
        do_action( 'wpml_switch_language', 'all' );
        try { return get_terms( $args + [ 'lang' => '' ] ); }
        finally { do_action( 'wpml_switch_language', $current ); }
    }

    public static function validate( $rules ): void {
        if ( ! is_array( $rules ) || array_values( $rules ) !== $rules || count( $rules ) > 250 ) { throw new InvalidArgumentException( 'Choose up to 250 fixed terms per layout.' ); }
        foreach ( $rules as $rule ) {
            if ( ! is_array( $rule ) || array_diff( array_keys( $rule ), [ 'taxonomy', 'locale', 'slug', 'excluded_languages' ] ) || ! is_string( $rule['taxonomy'] ?? null ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,32}$/D', $rule['taxonomy'] )
                || ! is_string( $rule['locale'] ?? null ) || ! preg_match( '/^(?:\*|[a-z]{2,3}(?:-[a-z0-9]{2,8})*)$/D', $rule['locale'] )
                || ! is_string( $rule['slug'] ?? null ) || '' === $rule['slug'] || strlen( $rule['slug'] ) > 200 ) {
                throw new InvalidArgumentException( 'Choose a registered term and a valid destination locale.' );
            }
            if ( array_key_exists( 'excluded_languages', $rule ) ) {
                $excluded = $rule['excluded_languages'];
                if ( ! is_array( $excluded ) || array_values( $excluded ) !== $excluded || count( $excluded ) > 250 ) { throw new InvalidArgumentException( 'Choose valid term languages.' ); }
                foreach ( $excluded as $code ) {
                    if ( ! is_string( $code ) || ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,31}$/iD', $code ) ) { throw new InvalidArgumentException( 'Choose a valid term language.' ); }
                }
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
        foreach ( $rules as $rule ) { $groups[$rule['taxonomy']][$rule['locale']][] = $rule; }
        $result = [];
        foreach ( $groups as $taxonomy => $scopes ) {
            if ( ! isset( $eligible[$taxonomy] ) ) { return new WP_Error( 'fixed_taxonomy_unavailable', 'A selected taxonomy is unavailable for this post type or posting user.' ); }
            $selected = $all ? array_merge( ...array_values( $scopes ) ) : ( $scopes[$locale] ?? $scopes['*'] ?? [] );
            foreach ( $selected as $rule ) {
                $slug = $rule['slug'];
                $matches = self::all_language_terms( [ 'taxonomy' => $taxonomy, 'slug' => $slug, 'hide_empty' => false, 'number' => 2, 'lang' => '' ] );
                if ( is_wp_error( $matches ) ) { return $matches; }
                if ( count( $matches ) !== 1 || $matches[0]->slug !== $slug ) { return new WP_Error( 'fixed_term_missing', 'A selected slug must identify exactly one term in its taxonomy. Review the saved mapping.' ); }
                $id = (int) $matches[0]->term_id;
                $code = $native_code;
                if ( ! $all && ! $code && '' === $locale ) {
                    $code = function_exists( 'pll_default_language' ) ? pll_default_language() : apply_filters( 'wpml_default_language', null );
                }
                if ( $code ) {
                    if ( ! $all && in_array( $code, $rule['excluded_languages'] ?? [], true ) ) {
                        $result[$taxonomy] = $result[$taxonomy] ?? [];
                        continue;
                    }
                    if ( function_exists( 'pll_is_translated_taxonomy' ) && pll_is_translated_taxonomy( $taxonomy ) ) {
                        $id = (int) pll_get_term( $id, $code );
                    } elseif ( apply_filters( 'wpml_is_translated_taxonomy', false, $taxonomy ) ) {
                        $id = (int) apply_filters( 'wpml_object_id', $id, $taxonomy, false, $code );
                    }
                    if ( ! $id || ! term_exists( $id, $taxonomy ) ) {
                        // An explicit empty assignment clears any category WordPress added on insert.
                        $result[$taxonomy] = $result[$taxonomy] ?? [];
                        continue;
                    }
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
