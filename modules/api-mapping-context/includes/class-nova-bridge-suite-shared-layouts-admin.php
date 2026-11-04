<?php
/** Administrator-only transfer of approved layouts between WordPress installations. */
defined( 'ABSPATH' ) || exit;

final class Nova_Bridge_Suite_Shared_Layouts_Admin {
    private const ROUTE = '/mapping/shared-layouts';
    private const FILE_LIMIT = 8388608;

    public static function bootstrap(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
        // The downloadable file uses the same byte budget as the signed JSON.
        add_filter( 'rest_json_encode_options', static function ( $options, $request ) {
            return '/nova-bridge/v1' . self::ROUTE . '/export' === $request->get_route()
                ? JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE : $options;
        }, 10, 2 );
    }

    public static function assets( string $hook ): void {
        if ( false === strpos( $hook, 'nova-settings' ) || ! current_user_can( 'manage_options' ) ) { return; }
        $asset = 'modules/api-mapping-context/assets/shared-layouts.js';
        wp_enqueue_script( 'nova-shared-layouts', plugins_url( $asset, NOVA_BRIDGE_SUITE_PLUGIN_FILE ), [], NOVA_BRIDGE_SUITE_VERSION . '-' . filemtime( NOVA_BRIDGE_SUITE_PLUGIN_DIR . $asset ), true );
        wp_localize_script( 'nova-shared-layouts', 'NovaSharedLayouts', [
            'url' => esc_url_raw( rest_url( 'nova-bridge/v1' . self::ROUTE ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ), 'maxBytes' => self::FILE_LIMIT,
        ] );
    }

    public static function routes(): void {
        foreach ( [ 'catalog' => 'GET', 'export' => 'POST', 'import' => 'POST' ] as $action => $method ) {
            register_rest_route( 'nova-bridge/v1', self::ROUTE . '/' . $action, [
                'methods' => $method,
                'permission_callback' => [ 'Nova_Bridge_Suite_Layout_Recipes', 'can_admin' ],
                'callback' => static fn( $request ) => self::handle( $action, $request ),
            ] );
        }
    }

    public static function handle( string $action, $request ) {
        $permission = Nova_Bridge_Suite_Layout_Recipes::can_admin( $request );
        if ( is_wp_error( $permission ) ) { return $permission; }
        require_once NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'modules/posting-service/includes/class-nova-bridge-suite-shared-layouts.php';
        $connection = Nova_Bridge_Suite_Posting_Settings::connection();
        if ( 'catalog' === $action ) {
            $result = Nova_Bridge_Suite_Shared_Layouts::catalog( $connection );
        } else {
            // Allow a small margin for the wrapper and dependency choices around the 8 MiB file.
            if ( strlen( $request->get_body() ) > self::FILE_LIMIT + 65536 ) {
                return new WP_Error( 'nova_shared_size', 'Export fewer revisions per file. A shared layout file can contain up to 8 MiB.', [ 'status' => 413 ] );
            }
            $body = $request->get_json_params();
            if ( 'export' === $action && is_array( $body['versions'] ?? null ) ) {
                $result = Nova_Bridge_Suite_Shared_Layouts::export_selected( $connection, $body['versions'] );
            } elseif ( 'import' === $action && is_array( $body['file'] ?? null ) && is_array( $body['resolutions'] ?? [] ) ) {
                $result = Nova_Bridge_Suite_Shared_Layouts::import_selected( $connection, $body['file'], $body['resolutions'] ?? [] );
            } else {
                return new WP_Error( 'nova_shared_input', 'Choose layout revisions to export or a NOVA shared layout file to import.', [ 'status' => 400 ] );
            }
        }
        if ( is_wp_error( $result ) ) { return $result; }
        $response = rest_ensure_response( $result );
        $response->header( 'Cache-Control', 'private, no-store' );
        return $response;
    }
}
