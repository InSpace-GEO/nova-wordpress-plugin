<?php
/** Optional copy of local layouts into a new connection, followed by a fresh synchronization. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-nova-wordpress-environment.php';

final class Nova_WordPress_Environment_Copy {
    // Service-independent local configuration only. Sync state, approvals, remote template IDs,
    // delivery journals and page links belong to the old service and are recreated by synchronization.
    public const STORES = [ 'nova_bridge_layout_recipes_v1', 'nova_bridge_endpoint_mappings_v1', 'nova_bridge_content_rules_v1', 'nova_bridge_suite_strategy' ];
    private const LAYOUTS = 'nova_bridge_layout_recipes_v1';
    private const SYNC_BUDGET_SECONDS = 25;

    private static function error( string $code, string $message ): WP_Error {
        return new WP_Error( 'nova_environment_copy_' . $code, $message, [ 'status' => 409 ] );
    }

    private static function has_content( $value ): bool {
        if ( ! is_array( $value ) ) { return false; }
        foreach ( $value as $entry ) { if ( is_array( $entry ) && $entry ) { return true; } }
        return false;
    }

    /** Read-only. Call while the source connection is still the saved connection. */
    public static function plan( array $source, array $target ) {
        if ( is_callable( [ 'Nova_Bridge_Suite_Shared_Layouts', 'is_subscriber' ] ) && Nova_Bridge_Suite_Shared_Layouts::is_subscriber( $source ) ) {
            return self::error( 'receive_only', 'This installation receives shared layouts. Import the layout file for the new connection instead of copying. The connection was not changed.' );
        }
        if ( Nova_WordPress_Environment::identity( $source ) === Nova_WordPress_Environment::identity( $target ) ) { return self::error( 'same_connection', 'Copying is only available when the service URL or website identifier changes.' ); }
        $stores = [];
        foreach ( self::STORES as $legacy ) {
            // Never merge: a connection used before keeps exactly its own configuration.
            if ( self::has_content( get_option( Nova_WordPress_Environment::key( $legacy, $target ), null ) ) ) {
                return self::error( 'destination_configured', 'The new connection already has its own layouts on this site, so nothing was copied or changed. Save again and choose Switch without copying to use them.' );
            }
            $value = get_option( Nova_WordPress_Environment::key( $legacy, $source ), null );
            if ( self::has_content( $value ) ) { $stores[ $legacy ] = $value; }
        }
        $layouts = $stores[ self::LAYOUTS ] ?? null;
        if ( null !== $layouts && ( 'nova-layout-recipes-store/v1' !== ( $layouts['format'] ?? '' ) || ! is_array( $layouts['profiles'] ?? null ) || ! is_array( $layouts['defaults'] ?? null ) ) ) {
            return self::error( 'source_invalid', 'The current layout history needs reconciliation before it can be copied. Nothing was changed.' );
        }
        $defaults = [];
        foreach ( $layouts['defaults'] ?? [] as $resource => $identity ) {
            $id = (string) ( $identity['id'] ?? '' ); $revision = (int) ( $identity['revision'] ?? 0 );
            if ( ! is_array( $layouts['profiles'][ $id ][ $revision ] ?? null ) ) { return self::error( 'source_invalid', 'A default layout revision is missing. Nothing was changed.' ); }
            // Only replicate what is live on the current service; other layouts are copied for review.
            $status = 'taxonomy:product_cat' !== $resource && is_callable( [ 'Nova_Bridge_Suite_Stored_Recipe_Sync', 'saved_status' ] ) ? Nova_Bridge_Suite_Stored_Recipe_Sync::saved_status( $source, $id, $revision ) : null;
            $defaults[] = [ 'resource_id' => (string) $resource, 'id' => $id, 'revision' => $revision, 'label' => (string) ( $layouts['profiles'][ $id ][ $revision ]['label'] ?? '' ), 'live' => 'synced' === ( $status['status'] ?? null ) ];
        }
        return [ 'stores' => $stores, 'defaults' => $defaults, 'layouts' => count( $layouts['profiles'] ?? [] ) ];
    }

    /** Runs inside the switch transaction; any failure rolls the whole switch back. */
    public static function apply( array $plan, array $target ): void {
        foreach ( $plan['stores'] as $legacy => $value ) {
            $name = Nova_WordPress_Environment::key( $legacy, $target );
            wp_cache_delete( $name, 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
            $saved = null === get_option( $name, null ) ? add_option( $name, $value, '', false ) : update_option( $name, $value, false );
            wp_cache_delete( $name, 'options' );
            if ( ! $saved || get_option( $name, null ) !== $value ) { throw new RuntimeException( 'copy_storage' ); }
        }
    }

    /** After the switch: create fresh templates on the new service for defaults that were live. */
    public static function synchronize( array $plan, ?callable $sync = null, ?callable $clock = null ): array {
        $sync = $sync ?: [ __CLASS__, 'sync_layout' ];
        $clock = $clock ?: 'microtime';
        $started = (float) call_user_func( $clock, true ); $results = [];
        foreach ( $plan['defaults'] as $item ) {
            $result = $item;
            if ( 'taxonomy:product_cat' === $item['resource_id'] ) { $result['outcome'] = 'manual'; }
            elseif ( ! $item['live'] ) { $result['outcome'] = 'not_live'; }
            elseif ( (float) call_user_func( $clock, true ) - $started > self::SYNC_BUDGET_SECONDS ) { $result['outcome'] = 'deferred'; }
            else {
                $reply = call_user_func( $sync, $item['id'], $item['revision'] );
                $status = is_array( $reply ) ? ( $reply['status'] ?? '' ) : '';
                $code = is_array( $reply ) ? (string) ( $reply['code'] ?? '' ) : '';
                if ( 'synced' === $status ) { $result['outcome'] = 'synced'; $result['template_id'] = $reply['template_id'] ?? null; }
                elseif ( 'default_conflict' === substr( $code, -16 ) ) { $result['outcome'] = 'conflict'; $result['message'] = $reply['message'] ?? ''; }
                else { $result['outcome'] = 'failed'; $result['message'] = is_array( $reply ) ? (string) ( $reply['message'] ?? 'The synchronization did not complete.' ) : 'The synchronization did not complete.'; }
            }
            $results[] = $result;
        }
        return $results;
    }

    /** The same handler the layout editor uses; never replaces an existing remote default. */
    public static function sync_layout( string $id, int $revision ): array {
        if ( ! class_exists( 'Nova_Bridge_Suite_Layout_Recipes' ) || ! class_exists( 'WP_REST_Request' ) ) { return [ 'status' => 'failed', 'message' => 'The layout module is unavailable.' ]; }
        $request = new WP_REST_Request( 'POST', '/nova-bridge/v1/mapping/layout-recipes/sync' );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [ 'id' => $id, 'revision' => $revision ] ) );
        try { $response = Nova_Bridge_Suite_Layout_Recipes::sync( $request ); }
        catch ( Throwable $error ) { return [ 'status' => 'failed', 'message' => 'The synchronization was interrupted. Use Sync saved revision in Layouts to reconcile it.' ]; }
        if ( is_wp_error( $response ) ) { return [ 'status' => 'failed', 'code' => $response->get_error_code(), 'message' => $response->get_error_message() ]; }
        $data = is_object( $response ) && method_exists( $response, 'get_data' ) ? $response->get_data() : $response;
        return is_array( $data['posting_sync'] ?? null ) ? $data['posting_sync'] : [ 'status' => 'failed', 'message' => 'This layout could not be synchronized automatically.' ];
    }

    /** Administrator-facing summary; layout names come from this site's own saved labels. */
    public static function messages( array $plan, ?array $results ): array {
        $messages = [];
        if ( ! $plan['stores'] ) { return [ [ 'info', 'The previous connection had no layouts or mappings to copy.' ] ]; }
        $messages[] = [ 'success', sprintf( 'Copied %d layout(s) and their mappings from the previous connection, which keeps its own copy.', $plan['layouts'] ) ];
        if ( null === $results ) {
            if ( $plan['defaults'] ) { $messages[] = [ 'warning', 'The copied layouts are not synchronized with the new service yet. Open Layouts and use Sync saved revision for each default before publishing.' ]; }
            return $messages;
        }
        $text = [
            'synced' => 'synchronized with the new service and selected as its default.',
            'conflict' => 'not synchronized: the new service already has a different default for this content type. Open the layout and choose whether to replace it.',
            'failed' => 'not synchronized: %s Open the layout and use Sync saved revision.',
            'not_live' => 'copied only. It was not synchronized on the previous connection, so review it before syncing.',
            'manual' => 'copied only. Synchronize category layouts from the layout editor.',
            'deferred' => 'copied only. Synchronization stopped to keep this request short; use Sync saved revision in Layouts.',
        ];
        foreach ( $results as $item ) {
            $line = sprintf( '%s (revision %d): ', '' !== $item['label'] ? $item['label'] : $item['resource_id'], $item['revision'] ) . sprintf( $text[ $item['outcome'] ], rtrim( (string) ( $item['message'] ?? '' ), '.' ) . '.' );
            $messages[] = [ 'synced' === $item['outcome'] ? 'success' : ( in_array( $item['outcome'], [ 'conflict', 'failed' ], true ) ? 'error' : 'warning' ), $line ];
        }
        return $messages;
    }
}
