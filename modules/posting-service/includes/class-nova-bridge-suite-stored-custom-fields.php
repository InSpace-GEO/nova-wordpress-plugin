<?php
/** Companion fields are local schema bindings, never arbitrary keys supplied by a delivery. */
defined( 'ABSPATH' ) || exit;
require_once dirname( __DIR__, 3 ) . '/includes/class-nova-wordpress-meta-permission.php';
require_once __DIR__ . '/class-nova-wordpress-stored-seo.php';

final class Nova_Bridge_Suite_Stored_Custom_Fields {
    private static function error( string $message ): WP_Error {
        return new WP_Error( 'nova_stored_custom_field', $message, [ 'status' => 409 ] );
    }

    private static function input( string $type, int $id, string $template, array $fields, bool $writing ) {
        if ( $id ) {
            $post = get_post( $id );
            if ( ! $post || $post->post_type !== $type ) { return self::error( 'The native post type differs from the saved custom-field mapping.' ); }
        }
        if ( ! class_exists( 'Nova_Bridge_Suite_Content_Transport' ) ) {
            require_once dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-content-transport.php';
        }
        $catalog = Nova_Bridge_Suite_Content_Transport::field_catalog( $type, $id, $template );
        $input = [];
        foreach ( $fields as $name => $binding ) {
            $provider = $binding['provider'] ?? '';
            if ( 'seo' === $provider ) { $checked = Nova_WordPress_Stored_SEO::check( $name, $binding, $id ); if ( is_wp_error( $checked ) ) { return $checked; } continue; }
            if ( ! in_array( $provider, [ 'acf', 'meta' ], true ) || ! is_string( $binding['value'] ?? null ) || ! isset( $catalog[$provider][$name] ) || is_protected_meta( $name, 'post' ) ) {
                return self::error( 'A mapped custom field is no longer available on the target post type: ' . $name );
            }
            $field = $catalog[$provider][$name];
            if ( ! empty( $field['readonly'] ) || ! empty( $field['disabled'] ) || ! empty( $field['multiple'] ) ) { return self::error( 'The mapped field no longer accepts an editable scalar value: ' . $name ); }
            if ( 'acf' === $provider ) {
                if ( $field['key'] !== ( $binding['key'] ?? null ) || $field['type'] !== ( $binding['type'] ?? null ) || ! in_array( $field['type'], [ 'text', 'textarea', 'wysiwyg', 'url', 'email' ], true ) ) {
                    return self::error( 'The mapped ACF field key or type changed: ' . $name );
                }
                $input['acf'][$name] = $binding['value'];
            } else {
                if ( $binding['key'] !== $name || 'string' !== $binding['type'] || 'string' !== ( $field['type'] ?? null ) || empty( $field['single'] ) || empty( $field['show_in_rest'] ) ) {
                    return self::error( 'The mapped REST field registration changed: ' . $name );
                }
                $input[$name] = $binding['input'] ?? $binding['value'];
            }
            if ( $id && ! Nova_WordPress_Meta_Permission::can_edit( 'post', $id, $name ) ) { return self::error( 'The publishing user cannot access the mapped field: ' . $name ); }
            if ( $writing ) {
                $conflict = self::sync_conflict( $name, $field, $id );
                if ( $conflict ) { return self::error( $conflict ); }
            }
        }
        return $input;
    }

    private static function sync_conflict( string $name, array $field, int $id ): string {
        $provider = Nova_Bridge_Suite_Stored_Layout_Language::provider();
        if ( 'polylang' === $provider ) {
            $options = get_option( 'polylang', [] );
            $default = in_array( 'post_meta', (array) ( $options['sync'] ?? [] ), true ) ? [ $name ] : [];
            // Respect the site's field-level exceptions whenever genuine source and target IDs exist.
            $siblings = $id ? array_diff( (array) pll_get_post_translations( $id ), [ $id ] ) : [];
            $copied = ! $siblings && $default;
            foreach ( $siblings as $language => $sibling ) {
                if ( in_array( $name, (array) apply_filters( 'pll_copy_post_metas', $default, true, $id, $sibling, $language ), true ) ) { $copied = true; break; }
            }
            return $copied ? 'Polylang synchronizes the mapped field ' . $name . '. Exclude it from custom-field synchronization to publish a different value per language.' : '';
        }
        if ( 'wpml' !== $provider ) { return ''; }
        global $sitepress;
        $settings = is_callable( [ $sitepress, 'get_setting' ] ) ? $sitepress->get_setting( 'translation-management' ) : [];
        // WPML/ACFML 1 = Copy; 3 = Copy Once at initial translation linkage.
        foreach ( [ $settings['custom_fields_translation'][$name] ?? null, $field['wpml_cf_preferences'] ?? null ] as $preference ) {
            if ( '1' === (string) $preference ) { return 'WPML is configured to copy ' . $name . ' between translations. Set this field to Translate to publish independent values.'; }
            if ( '3' === (string) $preference ) {
                $type = $id ? get_post_type( $id ) : '';
                $trid = $type ? apply_filters( 'wpml_element_trid', null, $id, 'post_' . $type ) : null;
                $group = $trid ? apply_filters( 'wpml_get_element_translations', null, $trid, 'post_' . $type ) : [];
                if ( count( (array) $group ) < 2 ) { return 'WPML uses Copy Once for ' . $name . '. Select Translate before creating or linking new translations.'; }
            }
        }
        return '';
    }

    /** Normalize values against the applicable native location, identity, permissions and REST schema. */
    public static function prepare( string $type, int $id, string $template, array $fields ) {
        $input = self::input( $type, $id, $template, $fields, true );
        if ( is_wp_error( $input ) ) { return $input; }
        $payload = Nova_Bridge_Suite_Content_Transport::validate_payload( $input, $type, $id, $template );
        if ( is_wp_error( $payload ) ) { return $payload; }
        foreach ( $fields as $name => &$binding ) {
            if ( 'seo' === $binding['provider'] ) { $binding['value'] = sanitize_text_field( $binding['value'] ); continue; }
            if ( 'acf' === $binding['provider'] ) {
                $binding['value'] = $payload['acf'][$name]['value'];
            } else {
                $value = $payload['meta'][$name];
                $binding['value'] = sanitize_meta( $name, $value, 'post', $type );
                // update_post_meta() runs the registered sanitizer. Keep its input when the native value differs.
                if ( $value !== $binding['value'] ) { $binding['input'] = $value; }
                else { unset( $binding['input'] ); }
            }
            if ( ! is_string( $binding['value'] ) ) { return self::error( 'The registered custom field did not accept a text value: ' . $name ); }
        }
        return $fields;
    }

    /** Native values and ACF's hidden field-key reference distinguish a missing field from an empty one. */
    public static function snapshot( int $id, array $fields, bool $require_reference = false ) {
        $state = [];
        foreach ( $fields as $name => $binding ) {
            if ( 'seo' === $binding['provider'] ) {
                $state[$name] = Nova_WordPress_Stored_SEO::read( $id, $name, $binding ); if ( is_wp_error( $state[$name] ) ) { return $state[$name]; } continue;
            }
            $values = get_post_meta( $id, $name, false );
            $refs = get_post_meta( $id, '_' . $name, false );
            if ( ! is_array( $values ) || count( $values ) > 1 || ( $values && ! is_string( $values[0] ) ) || ! is_array( $refs ) ) {
                return self::error( 'The mapped custom field has ambiguous native storage: ' . $name );
            }
            $acf = 'acf' === $binding['provider'];
            if ( $refs && ( $acf ? $refs !== [ $binding['key'] ] : is_string( $refs[0] ) && 0 === strpos( $refs[0], 'field_' ) ) ) {
                return self::error( 'The existing native field reference belongs to a different field: ' . $name );
            }
            if ( $require_reference && $acf && $values && ! $refs ) { return self::error( 'The existing value is missing its ACF field reference: ' . $name ); }
            $state[$name] = [ 'value' => $values[0] ?? null, 'reference' => $acf ? ( $refs[0] ?? null ) : null ];
        }
        return $state;
    }

    public static function read( string $type, int $id, string $template, array $fields ) {
        $input = self::input( $type, $id, $template, $fields, false );
        if ( is_wp_error( $input ) ) { return $input; }
        $state = self::snapshot( $id, $fields, true );
        if ( is_wp_error( $state ) ) { return $state; }
        $values = [];
        foreach ( $state as $name => $entry ) {
            $values[$name] = $entry['value'] ?? '';
        }
        return $values;
    }

    /** A retry may finish untouched fields; it must preserve an unrelated intervening edit. */
    public static function finish( array $plan, int $id, array $before, bool $write ) {
        $fields = $plan['custom_fields'];
        $type = $plan['post_type'];
        $template = $plan['custom_template'];
        $current = self::snapshot( $id, $fields );
        if ( is_wp_error( $current ) ) { return $current; }
        $input = self::input( $type, $id, $template, $fields, $write );
        if ( is_wp_error( $input ) ) { return $input; }
        $payload = Nova_Bridge_Suite_Content_Transport::validate_payload( $input, $type, $id, $template );
        if ( is_wp_error( $payload ) ) { return $payload; }
        $pending = [ 'acf' => [], 'meta' => [] ]; $seo_pending = [];
        foreach ( $fields as $name => $binding ) {
            $provider = $binding['provider'];
            $expected = [ 'value' => $binding['value'], 'reference' => 'acf' === $provider ? $binding['key'] : null ];
            if ( $current[$name] === $expected ) { continue; }
            // ACF writes the raw value first, then its field-key reference. A first write can stop between them.
            $value_only = 'acf' === $provider && ( $before[$name] ?? null ) === [ 'value' => null, 'reference' => null ] &&
                $current[$name] === [ 'value' => $expected['value'], 'reference' => null ];
            if ( ! $write || ( $current[$name] !== ( $before[$name] ?? null ) && ! $value_only ) ) { return self::error( 'The mapped custom field changed or was not saved: ' . $name ); }
            if ( 'seo' === $provider ) { $seo_pending[$name] = $binding; }
            else { $pending[$provider][$name] = $payload[$provider][$name]; }
        }
        foreach ( $seo_pending as $name => $binding ) { $saved = Nova_WordPress_Stored_SEO::write( $id, $name, $binding ); if ( is_wp_error( $saved ) ) { return $saved; } }
        if ( ! $pending['acf'] && ! $pending['meta'] ) { return true; }
        $saved = Nova_Bridge_Suite_Content_Transport::write_fields( get_post( $id ), $pending, true );
        return is_wp_error( $saved ) ? $saved : self::finish( $plan, $id, $before, false );
    }
}
