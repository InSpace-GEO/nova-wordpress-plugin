<?php
/** Capture and compile fixed Themeco Cornerstone layouts. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-nova-bridge-suite-cornerstone-document.php';
require_once __DIR__ . '/trait-nova-bridge-suite-cornerstone-publication.php';

final class Nova_Bridge_Suite_Recipe_Cornerstone {
    use Nova_Bridge_Suite_Cornerstone_Publication;

    private const X_PRESENTATION = [
        '_x_entry_body_css_class', '_x_entry_disable_page_title', '_x_post_layout',
        '_x_entry_bg_image_full', '_x_entry_bg_image_full_fade', '_x_entry_bg_image_full_duration',
    ];

    private static function error( string $message, int $status = 409 ): WP_Error {
        return new WP_Error( 'nova_recipe_cornerstone', $message, [ 'status' => $status ] );
    }

    private static function guard( callable $action ) {
        try { return $action(); } catch ( Throwable $error ) { return self::error( $error->getMessage() ); }
    }

    public static function available(): bool {
        $content = 'Themeco\\Cornerstone\\Documents\\Content';
        return function_exists( 'cornerstone' ) && function_exists( 'cs_render_document_html' )
            && is_callable( [ $content, 'locate' ] ) && method_exists( $content, 'update' ) && method_exists( $content, 'save' );
    }

    public function metadata( string $post_type = 'page' ): array {
        $support = $this->publication_support( $post_type );
        return [
            'id' => 'cornerstone', 'provider' => 'cornerstone', 'label' => 'Cornerstone (Themeco)',
            'version' => defined( 'CS_VERSION' ) ? CS_VERSION : '', 'available' => self::available(),
            'source_free' => true, 'capture' => true, 'starter' => false, 'draft' => $support['supported'],
            'modes' => [ 'static' ], 'reason' => $support['reason'],
        ];
    }

    public function capabilities( string $post_type = 'page' ): array { return $this->metadata( $post_type ); }

    /** Capture the X theme's presentation separately from native builder settings and post identity. */
    public static function presentation_meta( int $id, array $keys ): array {
        $meta = [];
        foreach ( array_intersect( self::X_PRESENTATION, $keys ) as $key ) {
            $present = metadata_exists( 'post', $id, $key );
            $value = $present ? get_post_meta( $id, $key, true ) : '';
            if ( ! is_scalar( $value ) ) { throw new InvalidArgumentException( 'An X theme layout setting contains an unsupported value.' ); }
            $meta[ $key ] = [ 'present' => $present, 'value' => (string) $value ];
        }
        return $meta;
    }

    public static function valid_presentation_meta( array $meta ): bool {
        if ( array_diff( array_keys( $meta ), self::X_PRESENTATION ) ) { return false; }
        foreach ( $meta as $entry ) {
            if ( ! is_bool( $entry['present'] ?? null ) || ! is_string( $entry['value'] ?? null )
                || ( ! $entry['present'] && '' !== $entry['value'] ) ) { return false; }
        }
        return true;
    }

    public function capture( int $id ) {
        return self::guard( function () use ( $id ) {
            $post = get_post( $id );
            if ( ! $post || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $id ) ) {
                return self::error( 'Choose a Cornerstone example you can edit.', 403 );
            }
            if ( ! self::available() ) { return self::error( 'Activate Themeco Cornerstone to capture this layout.' ); }
            if ( get_post_meta( $id, '_cornerstone_override', true ) ) { return self::error( 'This example uses the WordPress editor. Choose a page currently managed by Cornerstone.' ); }
            $doc = self::native_document( $id );
            if ( is_wp_error( $doc ) ) { return $doc; }
            $elements = $doc->data()['elements'] ?? [];
            $raw = get_post_meta( $id, '_cornerstone_data', true );
            // Prefer the native JSON's original object shapes when it agrees with the migrated builder view.
            if ( is_string( $raw ) ) {
                $raw_data = json_decode( $raw, true );
                if ( ( $raw_data['data'] ?? $raw_data ) === $elements ) {
                    $obj = json_decode( $raw );
                    $elements = $obj->data ?? $obj;
                }
            }
            $registry = cornerstone( 'Elements' );
            $blueprint = [
                'document' => Nova_Bridge_Suite_Cornerstone_Document::capture( $elements, static fn( $type ) => $registry->get_element( $type )->get_defaults() ),
                'template' => (string) get_page_template_slug( $id ), 'settings' => self::design_settings( $doc->settings() ),
                'meta' => function_exists( 'get_template' ) && in_array( get_template(), [ 'x', 'pro' ], true )
                    ? self::presentation_meta( $id, self::X_PRESENTATION ) : [],
            ];
            $description = $this->describe( $blueprint );
            if ( is_wp_error( $description ) ) { return $description; }
            $metadata = $this->metadata( $post->post_type );
            return [
                'builder' => 'cornerstone', 'provider' => 'cornerstone', 'version' => $metadata['version'],
                'blueprint' => $blueprint, 'capabilities' => $metadata, 'warnings' => [],
            ] + $description;
        } );
    }

    public function starter( string $mode = 'static' ) { return self::error( 'Select an existing Cornerstone page or post to capture its native layout.' ); }

    public function validate_blueprint( array $blueprint ) {
        return self::guard( static function () use ( $blueprint ) {
            if ( array_diff( array_keys( $blueprint ), [ 'document', 'settings', 'template', 'meta' ] )
                || ! is_array( $blueprint['document'] ?? null ) || ! is_string( $blueprint['template'] ?? '' )
                || ! is_array( $blueprint['settings'] ?? [] ) || ! self::valid_design_settings( $blueprint['settings'] ?? [] )
                || ! is_array( $blueprint['meta'] ?? [] ) || ! self::valid_presentation_meta( $blueprint['meta'] ?? [] ) ) {
                return self::error( 'Use the captured Cornerstone layout and its page settings.' );
            }
            Nova_Bridge_Suite_Cornerstone_Document::source( $blueprint['document'] );
            return $blueprint;
        } );
    }

    public function describe( array $blueprint ) {
        $valid = $this->validate_blueprint( $blueprint );
        return is_wp_error( $valid ) ? $valid : Nova_Bridge_Suite_Cornerstone_Document::inventory( $blueprint );
    }
    public function fields( array $native ): array { return Nova_Bridge_Suite_Cornerstone_Document::inventory( $native )['fields']; }
    public function containers( array $native ): array { return []; }
    public function validate_binding( array $native, array $binding ) {
        return Nova_Bridge_Suite_Cornerstone_Document::field( $native, $binding ) ? true : self::error( 'Choose a writable field from the Cornerstone example.' );
    }
    public function bind( array $native, array $binding, $value, array $context = [] ) {
        return self::guard( static fn() => Nova_Bridge_Suite_Cornerstone_Document::bind( $native, $binding, $value ) );
    }
    public function validate_region( array $native, array $region ) { return self::error( 'Cornerstone supports mapping fields in a fixed layout.' ); }
    public function instantiate( array $native, string $seed, array $context = [] ) { return $this->validate_region( $native, [] ); }
    public function replace_region( array $native, array $region, array $children, array $context = [] ) { return $this->validate_region( $native, $region ); }

    public function finalize( array $blueprint, string $seed, array $context = [] ) {
        $description = $this->describe( $blueprint );
        if ( is_wp_error( $description ) ) { return $description; }
        $preview = '';
        foreach ( $description['fields'] as $field ) {
            if ( $field['writable'] && '' !== $field['value'] && 'url' !== $field['format'] ) {
                $preview .= 'html' === $field['format'] ? wp_kses_post( $field['value'] ) : '<p>' . esc_html( $field['value'] ) . '</p>';
            }
        }
        return [
            'native_document' => Nova_Bridge_Suite_Cornerstone_Document::source( $blueprint['document'] ),
            'writer_payload' => [
                'cornerstone_document' => $blueprint['document'], 'settings' => $blueprint['settings'] ?? [],
                'template' => $blueprint['template'] ?? '', 'meta' => $blueprint['meta'] ?? [],
            ],
            'preview_html' => $preview, 'warnings' => [], 'summary' => [ 'builder' => 'cornerstone', 'fields' => count( $description['fields'] ) ],
        ];
    }
}
