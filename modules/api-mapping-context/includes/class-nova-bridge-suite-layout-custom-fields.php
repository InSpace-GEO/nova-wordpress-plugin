<?php
/** Editorial fields explicitly selected alongside a fixed native post layout. */
defined( 'ABSPATH' ) || exit;

final class Nova_Bridge_Suite_Layout_Custom_Fields {
    private const ACF = [ 'text', 'textarea', 'wysiwyg', 'url', 'email' ];

    public static function path( string $provider, string $name ): string { return '/@nova/custom-fields/' . $provider . '/' . $name; }

    public static function value_type( string $type ): string {
        if ( 'wysiwyg' === $type ) { return 'rich_text'; }
        return in_array( $type, [ 'url', 'email' ], true ) ? $type : 'text';
    }

    private static function valid( array $field ): bool {
        $name = $field['name'] ?? ''; $provider = $field['provider'] ?? ''; $key = $field['key'] ?? '';
        if ( ! is_string( $name ) || ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,190}$/D', $name ) || ctype_digit( $name ) || ! self::safe_key( $name ) || in_array( strtolower( $name ), [ 'constructor', 'prototype' ], true ) ) { return false; }
        if ( 'acf' === $provider ) { return is_string( $key ) && preg_match( '/^field_[A-Za-z0-9_-]+$/D', $key ) && in_array( $field['type'] ?? null, self::ACF, true ); }
        return 'meta' === $provider && $key === $name && 'string' === ( $field['type'] ?? '' ) && ! preg_match( '/^(?:nova|wp|wpseo|yoast|rank_math|aioseo|elementor|woocommerce|acf|pyre|fusion|breakdance|et_pb|fl_builder|wpb)[_-]/i', $name );
    }

    public static function safe_key( $name ): bool {
        return is_string( $name ) && '' !== $name && strlen( $name ) <= 191 && '_' !== $name[0]
            && ! preg_match( '/[.\[\]\/\\\\\x00-\x20]|(?:password|passwd|secret|token|credential|api[_-]?key|private[_-]?key|session|nonce)/i', $name )
            && ! in_array( $name, [ 'acf', 'wpbakery' ], true );
    }

    /** No example values are read or shipped; the native field registration is the authority. */
    public static function catalog( string $type, string $template = '', int $example = 0, array $retained_meta = [] ): array {
        if ( ! function_exists( 'get_registered_meta_keys' ) || ! class_exists( 'WP_Post_Type' ) ) { return []; }
        if ( ! class_exists( 'Nova_Bridge_Suite_Content_Transport' ) && class_exists( 'WP_REST_Posts_Controller' ) ) { require_once __DIR__ . '/class-nova-bridge-suite-content-transport.php'; }
        if ( ! is_callable( [ 'Nova_Bridge_Suite_Content_Transport', 'field_catalog' ] ) ) { return []; }
        $catalog = Nova_Bridge_Suite_Content_Transport::field_catalog( $type, 0, $template );
        $source = $example ? Nova_Bridge_Suite_Content_Transport::field_catalog( $type, $example, $template ) : $catalog;
        $fields = []; $names = []; $retained_meta = array_change_key_case( $retained_meta, CASE_LOWER );
        foreach ( $catalog as $provider => $registrations ) {
            foreach ( $registrations as $name => $registration ) {
                if ( array_key_exists( strtolower( (string) $name ), $retained_meta ) ) { continue; }
                if ( ! isset( $source[$provider][$name] ) || ( $source[$provider][$name]['key'] ?? $name ) !== ( $registration['key'] ?? $name ) || ( $source[$provider][$name]['type'] ?? '' ) !== ( $registration['type'] ?? '' ) ) { continue; }
                if ( ! empty( $registration['readonly'] ) || ! empty( $registration['disabled'] ) || ! empty( $registration['multiple'] ) ) { continue; }
                if ( 'meta' === $provider && ( empty( $registration['single'] ) || empty( $registration['show_in_rest'] ) ) ) { continue; }
                if ( function_exists( 'is_protected_meta' ) && is_protected_meta( $name, 'post' ) ) { continue; }
                if ( $example && ! current_user_can( 'edit_post_meta', $example, $name ) ) { continue; }
                $field = [ 'provider' => $provider, 'name' => $name, 'key' => 'acf' === $provider ? ( $registration['key'] ?? '' ) : $name, 'type' => $registration['type'] ?? '' ];
                if ( ! self::valid( $field ) ) { continue; }
                $field['label'] = (string) ( $registration['label'] ?? $name );
                $field['instructions'] = (string) ( $registration['instructions'] ?? $registration['description'] ?? '' );
                $path = self::path( $provider, $name ); $identity = strtolower( $name );
                if ( isset( $names[$identity] ) ) { unset( $fields[$names[$identity]] ); continue; }
                $names[$identity] = $path; $fields[$path] = $field;
            }
        }
        return $fields;
    }

    /** Also project explicit scalar bindings in recipes saved with the original ACF companion UI. */
    public static function selections( array $recipe ): array {
        $fields = array_key_exists( 'posting_fields', $recipe ) ? $recipe['posting_fields'] : [];
        if ( ! is_array( $fields ) || count( $fields ) > 64 ) { throw new InvalidArgumentException( 'Choose up to 64 editorial custom fields.' ); }
        if ( ! empty( $recipe['custom_fields'] ) ) {
            $acf = $recipe['custom_fields'];
            if ( ( $acf['builder'] ?? '' ) !== 'acf' || ( $acf['mode'] ?? '' ) !== 'static' || ! is_array( $acf['blueprint']['schema'] ?? null ) || ! is_array( $acf['bindings'] ?? null ) || ! $acf['bindings'] || ! empty( $acf['regions'] ) ) { throw new InvalidArgumentException( 'Automatic delivery requires explicit fixed top-level editorial custom-field bindings.' ); }
            foreach ( $acf['bindings'] as $binding ) {
                if ( ! preg_match( '~^/document/([a-zA-Z0-9][a-zA-Z0-9_-]*)$~D', $binding['target'] ?? '', $m ) ) { throw new InvalidArgumentException( 'Nested custom-field bindings require a separate publishing capability.' ); }
                $schema = $acf['blueprint']['schema'][$m[1]] ?? [];
                $path = self::path( 'acf', $m[1] );
                if ( isset( $fields[$path] ) || empty( $schema ) || ! empty( $schema['readonly'] ) || ! empty( $schema['disabled'] ) || ! empty( $schema['multiple'] ) ) { throw new InvalidArgumentException( 'Choose one writable registration for each custom field.' ); }
                $fields[$path] = [ 'provider' => 'acf', 'name' => $schema['name'] ?? '', 'key' => $schema['key'] ?? '', 'type' => $schema['type'] ?? '', 'label' => $binding['label'] ?? $schema['label'] ?? $m[1], 'instructions' => $binding['instructions'] ?? $schema['instructions'] ?? '', 'required' => ! empty( $binding['required'] ) ];
            }
        }
        if ( count( $fields ) > 64 ) { throw new InvalidArgumentException( 'Choose up to 64 editorial custom fields.' ); }
        $names = array_change_key_case( self::retained_meta( $recipe ), CASE_LOWER );
        foreach ( $fields as $path => $field ) {
            if ( ! is_array( $field ) || ! self::valid( $field ) || $path !== self::path( $field['provider'], $field['name'] ) || array_key_exists( strtolower( $field['name'] ), $names ) || array_diff( array_keys( $field ), [ 'provider', 'key', 'name', 'type', 'label', 'instructions', 'required' ] ) ) { throw new InvalidArgumentException( 'One of the selected custom fields is unsupported, duplicated or already used by the saved design.' ); }
            foreach ( [ 'label' => 200, 'instructions' => 8000 ] as $name => $max ) {
                $v = $field[$name] ?? ''; if ( ! is_string( $v ) || strlen( $v ) > $max || false !== strpos( $v, "\0" ) || 1 !== preg_match( '//u', $v ) ) { throw new InvalidArgumentException( 'Custom field labels and instructions must be bounded text.' ); }
            }
            if ( isset( $field['required'] ) && ! is_bool( $field['required'] ) ) { throw new InvalidArgumentException( 'Use a boolean for a required custom field.' ); }
            $names[strtolower( $field['name'] )] = true;
        }
        return $fields;
    }

    /** Elementor document settings live inside its settings object; theme presentation keys are real native post metadata. */
    public static function retained_meta( array $recipe ): array {
        $blueprint = $recipe['blueprint'] ?? [];
        return (array) ( $blueprint['theme_presentation']['meta'] ?? [] ) + ( 'elementor' === ( $recipe['builder'] ?? '' ) ? [] : (array) ( $blueprint['meta'] ?? [] ) );
    }

    public static function verify_registration( array $recipe, string $type, int $example = 0 ) {
        try {
            $fields = self::selections( $recipe );
            if ( ! $fields ) { return true; }
            $available = self::catalog( $type, (string) ( $recipe['blueprint']['template'] ?? '' ), $example, self::retained_meta( $recipe ) );
            foreach ( $fields as $path => $field ) {
                $current = $available[$path] ?? null;
                if ( ! $current || $current['key'] !== $field['key'] || $current['type'] !== $field['type'] ) { throw new InvalidArgumentException( 'The selected field ' . $field['name'] . ' is unavailable for this post type or page template. Review the mapping.' ); }
            }
            return true;
        } catch ( InvalidArgumentException $error ) { return new WP_Error( 'nova_layout_custom_field', $error->getMessage(), [ 'status' => 409 ] ); }
    }
}
