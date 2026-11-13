<?php
/** Localized editorial fields on a shared WooCommerce category. */
defined( 'ABSPATH' ) || exit;

final class Nova_Bridge_Suite_Term_Translation_Writer {
    public const FORMAT = 'nova.localized-term/v1';

    private static function error( string $reason, string $message ): WP_Error {
        return new WP_Error( 'nova_translation_' . $reason, $message, [ 'status' => 409 ] );
    }

    private static function digest( array $value ): string {
        return hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $value ) );
    }

    private static function key( array $provider ): string {
        return '_nova_localized_term_' . hash( 'sha256', $provider['provider'] . ':' . $provider['language'] );
    }

    public static function record( int $id, array $provider ): array {
        $record = get_term_meta( $id, self::key( $provider ), true );
        return is_array( $record ) ? $record : [];
    }

    private static function target( array $content, array $configuration ) {
        $taxonomy = Nova_Bridge_Suite_Category_Recipe::taxonomy( $configuration['record'] );
        if ( '' === $taxonomy ) { return self::error( 'mapping_unsupported', 'Select a WooCommerce category or product tag mapping.' ); }
        $provider = Nova_Bridge_Suite_Translation_Writer::provider( $content['language'] ?? '' );
        if ( ! is_array( $provider ) ) {
            return is_wp_error( $provider ) ? $provider : self::error( 'provider', 'A shared-category translation requires an active translation provider.' );
        }
        $family = $content['url_family'] ?? [];
        $generated = class_exists( 'Nova_WordPress_Weglot_Source_Writer' ) && Nova_WordPress_Weglot_Source_Writer::eligible( $content );
        if ( $generated ) {
            $id = Nova_WordPress_Weglot_Source_Writer::lookup( $content, $configuration );
            if ( is_wp_error( $id ) ) { return $id; }
            if ( ! $id ) { return self::error( 'source_pending', 'The generated Weglot original term is not ready.' ); }
            $source = Nova_WordPress_Weglot_Source_Writer::descriptor( $content, $configuration );
            if ( is_wp_error( $source ) ) { return $source; }
            $source_url = $source['source_url'];
        } else {
            if ( empty( $family['primary_url'] ) || empty( $family['primary_url_id'] ) ||
                ! Nova_Bridge_Suite_Translation_Writer::same_language_slot( $family['primary_language'] ?? '', $provider['source'] ) ) {
                return self::error( 'source_language', 'A category translation requires the explicit original-language URL group member.' );
            }
            $source_url = Nova_Bridge_Suite_Translation_Writer::delivery_url( $family['primary_url'], $family['primary_language'] );
            if ( is_wp_error( $source_url ) ) { return $source_url; }
            $found = Nova_Bridge_Suite_Mapped_Content_Target::resolve( $source_url, 'taxonomy:' . $taxonomy );
            if ( is_wp_error( $found ) ) {
                return 'nova_mapped_target_not_found' === $found->get_error_code()
                    ? self::error( 'source_pending', 'The original WooCommerce category is not available yet.' ) : $found;
            }
            $id = $found['reference_id'];
        }
        $term = get_term( $id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) || $term->taxonomy !== $taxonomy || ! current_user_can( 'edit_term', $id ) ) {
            return self::error( 'source_permission', 'The publishing user cannot translate the original category.' );
        }
        $canonical = Nova_Bridge_Suite_Mapped_Content_Target::native_url( 'taxonomy:' . $taxonomy, 'term', $id );
        $canonical = is_wp_error( $canonical ) ? $canonical : Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $canonical );
        if ( is_wp_error( $canonical ) || $canonical['identity'] !== $source_url ) {
            return self::error( 'source_identity', 'The original category does not have the URL supplied by its URL group.' );
        }
        $intended = Nova_Bridge_Suite_Translation_Writer::delivery_url( $content['url'] ?? '', $content['language'] );
        if ( is_wp_error( $intended ) ) { return $intended; }
        $localized = Nova_Bridge_Suite_Translation_Writer::translated_url( $provider, $source_url );
        if ( is_wp_error( $localized ) ) { return $localized; }
        $url = Nova_Bridge_Suite_Translation_Writer::delivery_url( $localized['identity'], $content['language'] );
        if ( is_wp_error( $url ) ) { return $url; }
        $test_target = Nova_Bridge_Suite_Publication_Policy::validate_shared_test_target( $content, $intended, $url );
        if ( is_wp_error( $test_target ) ) { return $test_target; }
        return $provider + [ 'taxonomy' => $taxonomy, 'term_id' => $id, 'source_url' => $source_url, 'url' => $url ];
    }

    public static function policy( array $content, array $configuration ) {
        if ( Nova_Bridge_Suite_Publication_Policy::test_url( $content['url'] ?? null )
            && ! Nova_Bridge_Suite_Publication_Policy::allows_shared_test( Nova_Bridge_Suite_Translation_Writer::provider( $content['language'] ?? '' ) ) ) {
            return Nova_Bridge_Suite_Publication_Policy::unsupported();
        }
        $valid = Nova_Bridge_Suite_Static_Recipe_Adapter::validate_profile( $content, $configuration );
        if ( is_wp_error( $valid ) ) { return $valid; }
        if ( ( $configuration['projection']['format'] ?? '' ) !== Nova_Bridge_Suite_Category_Recipe::FORMAT ) {
            return self::error( 'mapping_unsupported', 'A category translation requires an approved scalar category mapping.' );
        }
        $target = self::target( $content, $configuration );
        if ( is_wp_error( $target ) ) { return $target; }
        return [ 'reference' => [ 'reference_type' => 'term', 'reference_id' => $target['term_id'] ],
            'routing' => [ 'operation' => 'update', 'publication' => 'publish' ] ];
    }

    public static function plan( array $content, array $configuration, array $context ) {
        $policy = self::policy( $content, $configuration );
        if ( is_wp_error( $policy ) ) { return $policy; }
        $target = self::target( $content, $configuration );
        if ( is_wp_error( $target ) ) { return $target; }
        if ( ! Nova_Bridge_Suite_Posting_Protocol::uuid( $context['operation_id'] ?? '' ) ||
            ( $context['target_post_id'] ?? 0 ) !== $target['term_id'] || ( $context['operation'] ?? '' ) !== 'update' ||
            ( $context['desired_status'] ?? '' ) !== 'publish' || ( $context['site_id'] ?? '' ) !== $content['site_id'] ) {
            return self::error( 'profile', 'The category translation target changed after policy selection.' );
        }
        $taxonomy = $target['taxonomy'];
        $profile = [ 'profile_id' => $configuration['record']['id'], 'revision' => $configuration['record']['revision'] ];
        $payload = Nova_Bridge_Suite_Category_Recipe::compile( $content, $configuration, $context['operation_id'] );
        if ( is_wp_error( $payload ) ) { return $payload; }
        $fields = Nova_Bridge_Suite_Term_Content::localized_fields( $taxonomy, $target['term_id'], $payload, $profile );
        if ( is_wp_error( $fields ) ) { return $fields; }
        $source = Nova_Bridge_Suite_Term_Content::read( $taxonomy, $target['term_id'], $profile );
        if ( is_wp_error( $source ) ) { return $source; }
        $previous = self::record( $target['term_id'], $target );
        if ( $previous && ( ( $previous['state'] ?? '' ) !== 'committed' ||
            ( $previous['site_id'] ?? '' ) !== $content['site_id'] ||
            ( $previous['content_item_id'] ?? '' ) !== $content['content_item_id'] ||
            ( $previous['version'] ?? 0 ) >= $content['version_number'] ) ) {
            return self::error( 'version', 'A newer, unrelated or unfinished category translation already owns this language.' );
        }
        // An omitted description belongs to the existing destination, including its translation.
        // Retain it in the new record because committing a version replaces that entire record.
        if ( ! array_key_exists( 'description', $fields['native'] ) &&
            isset( $previous['fields']['native']['description'] ) ) {
            $fields['native']['description'] = $previous['fields']['native']['description'];
        }
        $plan = [ 'format' => self::FORMAT, 'operation_id' => $context['operation_id'],
            'provider' => $target, 'target' => [ 'target_url' => $target['url'] ],
            'site_id' => $content['site_id'], 'content_item_id' => $content['content_item_id'],
            'version' => $content['version_number'], 'profile_context' => $profile,
            'fields' => $fields, 'source_digest' => self::digest( $source ), 'previous_digest' => self::digest( $previous ) ];
        $plan['digest'] = self::digest( $plan );
        return $plan;
    }

    private static function validate( array $plan, string $operation ) {
        $unsigned = $plan;
        unset( $unsigned['digest'] );
        if ( ( $plan['format'] ?? '' ) !== self::FORMAT || ( $plan['operation_id'] ?? '' ) !== $operation ||
            ! hash_equals( self::digest( $unsigned ), $plan['digest'] ?? '' ) ) {
            return self::error( 'plan', 'The retained category translation plan changed.' );
        }
        $provider = Nova_Bridge_Suite_Translation_Writer::provider( $plan['provider']['language'] );
        if ( ! is_array( $provider ) || array_intersect_key( $plan['provider'], $provider ) !== $provider ) {
            return self::error( 'source_changed', 'The category translation provider changed.' );
        }
        $id = $plan['provider']['term_id'];
        $taxonomy = $plan['provider']['taxonomy'] ?? 'product_cat';
        $url = Nova_Bridge_Suite_Mapped_Content_Target::native_url( 'taxonomy:' . $taxonomy, 'term', $id );
        $url = is_wp_error( $url ) ? $url : Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $url );
        if ( is_wp_error( $url ) || $url['identity'] !== $plan['provider']['source_url'] ) {
            return self::error( 'source_changed', 'The original category URL changed.' );
        }
        $translated = Nova_Bridge_Suite_Translation_Writer::translated_url( $provider, $url['identity'] );
        if ( is_wp_error( $translated ) || $translated['identity'] !== $plan['provider']['url'] ) {
            return self::error( 'url', 'The provider no longer serves the retained category translation URL.' );
        }
        return true;
    }

    private static function expected_record( array $plan ): array {
        return [ 'state' => 'committed', 'operation_id' => $plan['operation_id'], 'plan_digest' => $plan['digest'],
            'site_id' => $plan['site_id'], 'content_item_id' => $plan['content_item_id'], 'version' => $plan['version'],
            'term_id' => $plan['provider']['term_id'], 'taxonomy' => $plan['provider']['taxonomy'] ?? 'product_cat', 'fields' => $plan['fields'] ];
    }

    private static function receipt( array $plan ): array {
        $id = $plan['provider']['term_id'];
        $taxonomy = $plan['provider']['taxonomy'] ?? 'product_cat';
        return [ 'term_id' => $id, 'native_kind' => 'term', 'cms_post_status' => 'publish',
            'publication_ref' => 'wordpress:term:' . $taxonomy . ':' . $id . ';operation:' . $plan['operation_id'] ];
    }

    public static function apply( array $plan, string $operation ) {
        $valid = self::validate( $plan, $operation );
        if ( is_wp_error( $valid ) ) { return $valid; }
        $id = $plan['provider']['term_id'];
        $taxonomy = $plan['provider']['taxonomy'] ?? 'product_cat';
        if ( ! current_user_can( 'edit_term', $id ) ) {
            return self::error( 'source_permission', 'The publishing user cannot translate this category.' );
        }
        $before = self::record( $id, $plan['provider'] );
        if ( $before === self::expected_record( $plan ) ) { return self::verify( $plan, self::receipt( $plan ) ); }
        if ( self::digest( $before ) !== $plan['previous_digest'] ) {
            return self::error( 'version', 'The category translation changed after planning.' );
        }
        $source = Nova_Bridge_Suite_Term_Content::read( $taxonomy, $id, $plan['profile_context'] );
        if ( is_wp_error( $source ) ) { return $source; }
        if ( self::digest( $source ) !== $plan['source_digest'] ) {
            return self::error( 'source_changed', 'The original category fields changed after planning.' );
        }
        $key = self::key( $plan['provider'] );
        $next = wp_slash( self::expected_record( $plan ) );
        $saved = $before ? update_term_meta( $id, $key, $next, $before ) : add_term_meta( $id, $key, $next, true );
        if ( is_wp_error( $saved ) ) { return $saved; }
        return self::verify( $plan, self::receipt( $plan ) );
    }

    public static function recover( array $plan, string $operation ) {
        $valid = self::validate( $plan, $operation );
        if ( is_wp_error( $valid ) ) { return $valid; }
        $record = self::record( $plan['provider']['term_id'], $plan['provider'] );
        if ( $record === self::expected_record( $plan ) ) { return self::receipt( $plan ); }
        if ( self::digest( $record ) === $plan['previous_digest'] ) {
            return [ 'state' => 'not_committed', 'safe_to_apply' => true, 'no_native_commit' => true ];
        }
        return self::error( 'readback', 'The saved category translation differs from this operation.' );
    }

    public static function verify( array $plan, array $result ) {
        $valid = self::validate( $plan, $plan['operation_id'] ?? '' );
        if ( is_wp_error( $valid ) ) { return $valid; }
        if ( ( $result['term_id'] ?? 0 ) !== $plan['provider']['term_id'] ||
            self::record( $plan['provider']['term_id'], $plan['provider'] ) !== self::expected_record( $plan ) ) {
            return self::error( 'readback', 'The localized category fields could not be verified.' );
        }
        return self::receipt( $plan );
    }
}
