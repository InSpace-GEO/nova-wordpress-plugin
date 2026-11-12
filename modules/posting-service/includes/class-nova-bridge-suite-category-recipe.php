<?php
/** Project fixed category fields into the shared destination-fitting contract. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-layout-custom-fields.php';

final class Nova_Bridge_Suite_Category_Recipe {
    public const FORMAT = 'nova.category-recipe/v1';

    private static function checked( $value ) {
        if ( is_wp_error( $value ) ) { throw new InvalidArgumentException( $value->get_error_message() ); }
        return $value;
    }

    public static function project( array $record ) {
        try {
            $recipe = $record['recipe'] ?? [];
            if ( ( $record['resource_id'] ?? '' ) !== 'taxonomy:product_cat' || ( $record['builder'] ?? '' ) !== 'core'
                || ( $record['mode'] ?? '' ) !== 'static' || ( $recipe['mode'] ?? '' ) !== 'static'
                || ( $recipe['builder'] ?? '' ) !== 'core' || ! empty( $recipe['regions'] )
                || ( $recipe['blueprint']['taxonomy'] ?? '' ) !== 'product_cat' ) {
                throw new InvalidArgumentException( 'Category publishing requires fixed WordPress category fields with scalar ACF companions.' );
            }
            self::checked( Nova_Bridge_Suite_Recipe_Engine::validate( $recipe ) );
            $draft = [
                'catalog_mode' => 'destination', 'profile_page_type' => 'category', 'label' => $record['label'],
                'guidance_mode' => empty( $recipe['instructions'] ) ? 'clear' : 'set', 'guidance' => $recipe['instructions'] ?? '',
                'fields' => [], 'target_descriptors' => [], 'source_hints' => [], 'repeat_slots' => [], 'destination_groups' => [],
            ];
            $bindings = [];
            $inventory = array_column( Nova_Bridge_Suite_Recipe_Core::describe( $recipe['blueprint'] )['fields'], null, 'target' );
            foreach ( $recipe['bindings'] as $binding ) {
                $target = $binding['target'];
                if ( ! in_array( $target, [ '/document/title', '/document/sections/0/body' ], true )
                    && ! preg_match( '#^/term_fields/values/[^/]+$#D', $target ) ) {
                    throw new InvalidArgumentException( 'Category publishing supports fixed scalar category fields. Nested category values require a separate publishing capability.' );
                }
                self::add_field( $draft, $bindings, $binding, $inventory[$target] ?? [], 'core', $target );
            }
            if ( ! isset( $draft['fields']['/document/title'] ) ) { throw new InvalidArgumentException( 'Select the category title before synchronizing a category creation template.' ); }

            foreach ( Nova_Bridge_Suite_Layout_Custom_Fields::selections( $recipe ) as $path => $field ) {
                if ( $field['provider'] !== 'acf' || ! isset( $recipe['custom_fields'] ) ) { throw new InvalidArgumentException( 'Select category ACF fields through the declared category companion.' ); }
                $target = '/document/' . $field['name'];
                $binding = null;
                foreach ( $recipe['custom_fields']['bindings'] as $candidate ) { if ( $candidate['target'] === $target ) { $binding = $candidate; break; } }
                if ( null === $binding || ( $recipe['custom_fields']['blueprint']['taxonomy'] ?? '' ) !== 'product_cat' ) { throw new InvalidArgumentException( 'The ACF companion must belong to this category taxonomy.' ); }
                self::checked( Nova_Bridge_Suite_Recipe_Engine::validate( $recipe['custom_fields'] ) );
                self::add_field( $draft, $bindings, $binding, [ 'writable' => true, 'label' => $field['label'] ], 'acf', $path );
            }
            $input = self::checked( Nova_Bridge_Suite_Writing_Adapter::template_input( $draft ) );
            return [ 'format' => self::FORMAT, 'input' => $input, 'draft' => $draft, 'bindings' => $bindings ];
        } catch ( InvalidArgumentException $error ) {
            return Nova_Bridge_Suite_Static_Recipe_Adapter::error( 'unsupported_recipe', $error->getMessage() );
        }
    }

    private static function add_field( array &$draft, array &$bindings, array $binding, array $native, string $scope, string $path ): void {
        if ( empty( $native['writable'] ) || ! in_array( $binding['format'] ?? '', [ 'text', 'html', 'url' ], true ) || isset( $draft['fields'][$path] ) ) {
            throw new InvalidArgumentException( 'Each category destination must be one distinct writable scalar field.' );
        }
        $label = $binding['label'] ?? $native['label'];
        $required = $path === '/document/title' || ! empty( $binding['required'] );
        $draft['fields'][$path] = [ 'mode' => 'adapt', 'source_path' => '', 'required' => $required,
            'instructions' => $binding['instructions'] ?? '', 'description' => [ 'label' => $label,
                'purpose' => 'Content for ' . $label . '.', 'value_type' => $binding['format'] === 'html' ? 'rich_text' : ( $binding['format'] === 'url' ? 'url' : 'text' ), 'constraints' => [] ] ];
        $draft['target_descriptors'][$path] = [ 'path' => $path ];
        if ( preg_match( '/^nova_role_(.+)_[a-f0-9]{16}$/D', $binding['id'] ?? '', $match ) ) {
            $hints = [ 'title' => 'h1', 'seo_title' => 'title', 'meta_description' => 'meta_description', 'intro' => 'top_content', 'top_content' => 'top_content', 'bottom_content' => 'bottom_content', 'rich_text' => 'bottom_content' ];
            if ( isset( $hints[$match[1]] ) ) { $draft['source_hints'][$path] = [ $hints[$match[1]] ]; }
        }
        $bindings[$path] = [ 'scope' => $scope, 'target' => $binding['target'], 'required' => $required ];
    }

    public static function compile( array $snapshot, array $profile, string $operation ) {
        try {
            $projection = $profile['projection'];
            if ( ( $projection['format'] ?? '' ) !== self::FORMAT || ( $snapshot['kind'] ?? '' ) !== 'adaptation.delivery' || ( $snapshot['schema_version'] ?? 0 ) !== 2 ) {
                throw new InvalidArgumentException( 'This category requires the fitted values for its exact approved template.' );
            }
            $values = self::checked( Nova_Bridge_Suite_Writing_Adapter::validate_fitted_values( $projection['draft'], $snapshot['destination_values'] ?? [] ) );
            $recipe = $profile['record']['recipe'];
            $main = $recipe; unset( $main['custom_fields'] );
            $main['bindings'] = [];
            $custom = $recipe['custom_fields'] ?? null;
            if ( $custom ) { $custom['bindings'] = []; }
            $content = [ 'fields' => [] ]; $acf_names = []; $native_names = [];
            foreach ( $projection['bindings'] as $path => $spec ) {
                if ( ! array_key_exists( $path, $values ) ) { continue; }
                $source = '/fields/' . Nova_Bridge_Suite_Writing_Adapter::field_id( $path );
                $content['fields'][Nova_Bridge_Suite_Writing_Adapter::field_id( $path )] = $values[$path];
                $scope = $spec['scope'] === 'acf' ? $recipe['custom_fields'] : $recipe;
                foreach ( $scope['bindings'] as $binding ) {
                    if ( $binding['target'] !== $spec['target'] ) { continue; }
                    $binding['source'] = $source; $binding['required'] = $spec['required'];
                    if ( $spec['scope'] === 'acf' ) { $custom['bindings'][] = $binding; $acf_names[] = substr( $binding['target'], strlen( '/document/' ) ); }
                    else { $main['bindings'][] = $binding; $native_names[$path] = true; }
                }
            }
            $compiled = self::checked( Nova_Bridge_Suite_Recipe_Engine::compile( $main, $content, $operation ) );
            $payload = $compiled['writer_payload'];
            if ( ! isset( $native_names['/document/title'] ) ) { unset( $payload['name'] ); }
            if ( ! isset( $native_names['/document/sections/0/body'] ) ) { unset( $payload['description'] ); }
            if ( $custom && $acf_names ) {
                $compiled_acf = self::checked( Nova_Bridge_Suite_Recipe_Engine::compile( $custom, $content, $operation . ':acf' ) );
                $payload['meta_all']['acf'] = array_intersect_key( $compiled_acf['writer_payload']['meta_all']['acf'], array_flip( $acf_names ) );
            }
            return $payload;
        } catch ( InvalidArgumentException $error ) {
            return Nova_Bridge_Suite_Static_Recipe_Adapter::error( 'category_values', $error->getMessage() );
        }
    }

    /** Compare just the destinations in a plan, preserving unrelated native fields. */
    public static function selected_values( array $content, array $payload ): array {
        $out = [];
        foreach ( $payload as $key => $value ) {
            if ( in_array( $key, [ 'meta', 'meta_all' ], true ) && is_array( $value ) ) { $out[$key] = self::selected_values( (array) ( $content[$key] ?? [] ), $value ); }
            elseif ( $key === 'acf' && is_array( $value ) ) { $out[$key] = array_intersect_key( (array) ( $content[$key] ?? [] ), $value ); }
            else { $out[$key] = $content[$key] ?? null; }
        }
        return $out;
    }
}
