<?php
/** Saved native layouts use the existing publishing-template and default APIs. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-nova-bridge-suite-layout-url-routes.php';

final class Nova_Bridge_Suite_Stored_Recipe_Sync {
    private static function error( string $code, string $message ): WP_Error { return new WP_Error( 'nova_stored_recipe_' . $code, $message, [ 'status' => 409 ] ); }
    private static function same( $a, $b ): bool { return Nova_Bridge_Suite_Writing_Adapter::canonical_json( $a ) === Nova_Bridge_Suite_Writing_Adapter::canonical_json( $b ); }
    private static function status_key( array $connection, string $recipe_id ): string {
        return 'nova_recipe_posting_sync_' . hash( 'sha256', (string) ( $connection['site_id'] ?? '' ) . "\n" . rtrim( (string) ( $connection['base_url'] ?? '' ), '/' ) . "\n" . $recipe_id );
    }
    /** Last observed result only: reading a layout never contacts posting service. */
    public static function saved_status( array $connection, string $recipe_id, int $revision ): ?array {
        if ( empty( $connection['enabled'] ) || empty( $connection['site_id'] ) || empty( $connection['base_url'] ) ) { return null; }
        $status = get_option( self::status_key( $connection, $recipe_id ), null );
        return is_array( $status ) && ( $status['recipe_revision'] ?? null ) === $revision ? $status : null;
    }
    private static function remember( array $connection, string $recipe_id, array $status ): bool {
        $key = self::status_key( $connection, $recipe_id );
        update_option( $key, $status, false );
        return get_option( $key, null ) === $status;
    }
    private static function defaults( $reply ) {
        if ( is_wp_error( $reply ) ) { return $reply; }
        $body = $reply['body'] ?? [];
        if ( ( $reply['status'] ?? 0 ) !== 200 || ! is_array( $body['defaults'] ?? null ) || ! is_int( $body['revision'] ?? null ) || $body['revision'] < 1 || ( $reply['etag'] ?? '' ) !== '"' . $body['revision'] . '"' ) { return self::error( 'defaults', 'Posting service did not return template defaults with their exact revision.' ); }
        foreach ( $body['defaults'] as $id ) { if ( ! Nova_Bridge_Suite_Posting_Protocol::uuid( $id ) ) { return self::error( 'defaults', 'Posting service returned an invalid template default.' ); } }
        return $body['defaults'];
    }
    private static function default_conflict( string $page_type, ?string $selected, int $revision, string $reason = '' ): WP_Error {
        $data = [ 'status' => 409 ];
        if ( $selected ) { $data['default_replacement'] = [ 'page_type' => $page_type, 'template_id' => $selected, 'defaults_revision' => $revision ]; }
        return new WP_Error( 'nova_stored_recipe_default_conflict', ( $reason ?: 'Another template currently handles this NOVA content type.' ) . ' Review and explicitly choose this saved template to replace that default; no remote template was changed.', $data );
    }

    /** Plan before creating/updating remote templates. The final UUID is filled after local writer approval. */
    private static function route_plan( $client, array $record, array $reply, array $defaults, ?string $previous_id, ?array $replace ) {
        $type = $record['posting_page_type'] ?? ( 'post_type:post' === $record['resource_id'] ? 'informative' : 'service' );
        $body = $reply['body']; $selected = $defaults[$type] ?? null; $modern = array_key_exists( 'routes', $body );
        $routes = $modern ? Nova_Bridge_Suite_Layout_URL_Routes::index( $body['routes'] ) : [];
        if ( is_wp_error( $routes ) ) { return $routes; }
        $prefix = Nova_Bridge_Suite_Layout_URL_Routes::prefix( $record['resource_id'], $record['source_reference'] ?? [] );
        // Keep an installation's original single default until there are two destinations to distinguish.
        $has_type_routes = in_array( $type, array_column( $routes, 'page_type' ), true );
        $multiple = $has_type_routes || ( $selected && $selected !== $previous_id );
        $scoped = $modern && $multiple && null !== $prefix && '/' !== $prefix;
        $migration = null;
        if ( null !== $replace && ( $replace['page_type'] !== $type || $replace['template_id'] !== $selected || $replace['defaults_revision'] !== $body['revision'] || $scoped ) ) { return self::default_conflict( $type, $selected, $body['revision'] ); }
        if ( $scoped && isset( $routes[$type . ':' . $prefix] ) && $routes[$type . ':' . $prefix]['template_id'] !== $previous_id ) {
            return self::error( 'route_conflict', 'Another ' . $type . ' layout already handles URLs under ' . $prefix . '. WordPress registers the same URL pattern for both destinations. Give one destination a distinct permalink base before syncing.' );
        }
        $existing_binding = $scoped && $previous_id && ( $routes[$type . ':' . $prefix]['template_id'] ?? null ) === $previous_id;
        if ( $selected && $selected !== $previous_id && ! $existing_binding ) {
            $owner = Nova_Bridge_Suite_Layout_URL_Routes::previous_default( $client->site_id(), $selected, $type );
            $other_prefix = $owner['prefix'] ?? null;
            if ( ! $modern && null !== $prefix && null !== $other_prefix && $prefix !== $other_prefix ) {
                return self::error( 'routes_unavailable', 'The two WordPress destinations have distinct permalink paths. Update posting service to support URL routes, then sync this saved layout again. The existing default was kept.' );
            }
            if ( null === $replace && ( ! $modern || null === $prefix || null === $other_prefix || $prefix === $other_prefix ) ) {
                $reason = $modern ? ( '/' === $prefix && '/' === $other_prefix ? 'Both WordPress destinations use root URLs. NOVA cannot distinguish the two layouts by URL.' : 'WordPress could not distinguish the two destinations by their registered permalink paths.' ) : '';
                if ( $scoped ) { return self::error( 'route_conflict', $reason . ' Keep the current default and review the registered permalink bases before syncing.' ); }
                return self::default_conflict( $type, $selected, $body['revision'], $reason );
            }
            if ( $modern && null !== $other_prefix && '/' !== $other_prefix && $other_prefix !== $prefix ) {
                $key = $type . ':' . $other_prefix;
                if ( isset( $routes[$key] ) && $routes[$key]['template_id'] !== $selected ) { return self::error( 'route_conflict', 'The current default cannot be moved to ' . $other_prefix . ' because another template already uses that route.' ); }
                $routes[$key] = [ 'page_type' => $type, 'path_prefix' => $other_prefix, 'template_id' => $selected ];
                unset( $defaults[$type] );
                $migration = $owner + [ 'template_id' => $selected ];
            }
        }
        foreach ( $routes as $key => $row ) {
            if ( $row['page_type'] !== $type ) { continue; }
            if ( null === $prefix ) { return self::error( 'route_unknown', 'WordPress cannot verify a fixed permalink base alongside existing ' . $type . ' URL routes. The current routing was kept.' ); }
            if ( $row['template_id'] === $previous_id && $row['path_prefix'] !== $prefix ) { unset( $routes[$key] ); }
        }
        if ( $scoped && $selected && $selected === $previous_id ) { unset( $defaults[$type] ); }
        return [ 'type' => $type, 'prefix' => $scoped ? $prefix : null, 'defaults' => $defaults, 'routes' => $routes, 'modern' => $modern, 'migration' => $migration ];
    }

    public static function sync_editor( array $connection, callable $load, bool $saving = false, string $recipe_id = '', $client = null, $locks = null, string $recover_template_id = '', ?array $replace_default = null ) {
        if ( ! current_user_can( 'manage_options' ) || empty( $connection['enabled'] ) ) { return self::error( 'permission', 'Sign in as an administrator and complete the NOVA connection details before synchronizing.' ); }
        if ( Nova_Bridge_Suite_Shared_Layouts::is_subscriber( $connection ) ) { return Nova_Bridge_Suite_Shared_Layouts::subscriber_error(); }
        if ( null !== $replace_default && ( $saving || ! Nova_Bridge_Suite_Posting_Protocol::uuid( $replace_default['template_id'] ?? null ) || ! is_int( $replace_default['defaults_revision'] ?? null ) || $replace_default['defaults_revision'] < 1 || ! in_array( $replace_default['page_type'] ?? '', [ 'service', 'informative', 'category' ], true ) ) ) { return self::error( 'default_replacement', 'Review the saved template and the current default before replacing it.' ); }
        $client = $client ?: new Nova_Bridge_Suite_Posting_Client( $connection );
        $invalid = $client->connection_error(); if ( is_wp_error( $invalid ) ) { return $invalid; }
        $locks = $locks ?: new Nova_Bridge_Suite_Posting_Jobs(); $lock = 'stored-recipe-editor:' . $client->site_id();
        $owned = $locks->lock( $lock ); if ( is_wp_error( $owned ) ) { return $owned; }
        try {
            $previous = $recipe_id ? Nova_Bridge_Suite_Mapping_Sync::scope_state( $client->site_id(), 'recipe:' . $recipe_id ) : null;
            if ( $saving && isset( $previous['pending'] ) ) { return self::error( 'sync_pending', 'Sync the saved revision to reconcile its pending remote operation before saving another revision.' ); }
            $saved = $load(); if ( is_wp_error( $saved ) ) { return $saved; }
            $attempt = [ 'status' => 'pending', 'recipe_revision' => $saved['revision'], 'page_type' => $saved['posting_page_type'] ?? 'service', 'checked_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'message' => 'Posting-service default confirmation has not completed.' ];
            $remembered = self::remember( $connection, $saved['id'], $attempt );
            $record = Nova_Bridge_Suite_Layout_Recipes::delivery_record( $saved['id'], $saved['revision'] );
            $result = ! $remembered ? self::error( 'status_storage', 'The saved layout is local; its synchronization status could not be retained. Retry synchronization.' ) : ( is_wp_error( $record ) ? $record : self::synchronize( $connection, $client, $record, $recover_template_id, $replace_default ) );
            $saved['posting_sync'] = ( is_wp_error( $result ) ? [ 'status' => 'failed', 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ] : $result ) + $attempt;
            if ( is_wp_error( $result ) && is_array( $result->get_error_data()['default_replacement'] ?? null ) ) { $saved['posting_sync']['default_replacement'] = $result->get_error_data()['default_replacement']; }
            if ( ! self::remember( $connection, $saved['id'], $saved['posting_sync'] ) ) { $saved['posting_sync'] = [ 'status' => 'failed', 'code' => 'nova_stored_recipe_status_storage', 'message' => 'The last synchronization result could not be retained. Review and sync this saved revision again.' ] + $attempt; }
            $saved['local_only'] = 'synced' !== $saved['posting_sync']['status'];
            return $saved;
        } finally { $locks->unlock( $lock ); }
    }

    private static function synchronize( array $connection, $client, array $record, string $recover_template_id, ?array $replace_default ) {
        $default = Nova_Bridge_Suite_Layout_Recipes::defaults()[$record['resource_id']] ?? [];
        if ( ( $default['id'] ?? '' ) !== $record['id'] || ( $default['revision'] ?? 0 ) !== $record['revision'] ) { return self::error( 'default_changed', 'Reload the current saved creation default before synchronizing.' ); }
        $draft = Nova_Bridge_Suite_Stored_Layout::from_recipe( $record ); if ( is_wp_error( $draft ) ) { return $draft; }
        $page_type = $draft['profile_page_type'];
        $reply = $client->site_request( 'GET', '/template-defaults' ); $defaults = self::defaults( $reply ); if ( is_wp_error( $defaults ) ) { return $defaults; }
        $scope = 'recipe:' . $record['id']; $previous = Nova_Bridge_Suite_Mapping_Sync::scope_state( $client->site_id(), $scope );
        if ( isset( $previous['template']['page_type'] ) && $previous['template']['page_type'] !== $page_type ) { return self::error( 'page_type_changed', 'Create a separate template for another NOVA content type. The synchronized template keeps its existing default and page type.' ); }
        $plan = self::route_plan( $client, $record, $reply, $defaults, $previous['template']['id'] ?? null, $replace_default );
        if ( is_wp_error( $plan ) ) { return $plan; }
        $sync = new Nova_Bridge_Suite_Mapping_Sync( $client, $scope );
        $state = $sync->synchronize( $draft, $recover_template_id ); if ( is_wp_error( $state ) ) { return $state; }
        $state = $sync->activate( $draft, [ 'actor_user_id' => (int) ( $connection['actor_user_id'] ?? 0 ) ] ); if ( is_wp_error( $state ) ) { return $state; }
        // Approve native authority before selecting a default which can receive deliveries immediately.
        $id = $state['template']['id'];
        $prefix = $plan['prefix'];
        if ( $prefix ) { $plan['routes'][$page_type . ':' . $prefix] = [ 'page_type' => $page_type, 'path_prefix' => $prefix, 'template_id' => $id ]; }
        else { $plan['defaults'][$page_type] = $id; }
        $prior_routes = $plan['modern'] ? Nova_Bridge_Suite_Layout_URL_Routes::index( $reply['body']['routes'] ) : [];
        if ( ! self::same( $defaults, $plan['defaults'] ) || ! self::same( $prior_routes, $plan['routes'] ) ) {
            $input = [ 'defaults' => $plan['defaults'] ?: (object) [] ];
            if ( $plan['modern'] ) { $input['routes'] = array_values( $plan['routes'] ); }
            $changed = $client->site_request( 'PUT', '/template-defaults', $input, [ 'If-Match' => $reply['etag'] ] );
            $confirmed = self::confirm( $changed, $plan ); if ( is_wp_error( $confirmed ) ) { return $confirmed; }
        }
        $readback = $client->site_request( 'GET', '/template-defaults' );
        $confirmed = self::confirm( $readback, $plan ); if ( is_wp_error( $confirmed ) ) { return $confirmed; }
        if ( $plan['migration'] ) {
            $moved = $plan['migration']; $key = self::status_key( $connection, $moved['recipe_id'] ); $old_status = get_option( $key, null );
            if ( ( $old_status['template_id'] ?? '' ) === $moved['template_id'] && 'synced' === ( $old_status['status'] ?? '' ) ) {
                self::remember( $connection, $moved['recipe_id'], array_merge( $old_status, [ 'default_selected' => false, 'route_prefix' => $moved['prefix'], 'defaults_revision' => $readback['body']['revision'], 'message' => 'Posting service confirmed URLs under ' . $moved['prefix'] . ' for this layout.' ] ) );
            }
        }
        return [ 'status' => 'synced', 'verified' => true, 'default_selected' => ! $prefix, 'route_prefix' => $prefix, 'defaults_revision' => $readback['body']['revision'], 'template_id' => $id, 'template_revision' => $state['template']['revision'], 'local_revision' => $record['revision'], 'recipe_revision' => $record['revision'], 'mapping_version' => $state['template']['definition']['version'], 'page_type' => $page_type, 'message' => $prefix ? 'Posting service confirmed URLs under ' . $prefix . ' for this layout.' : 'Posting service confirmed this saved layout as the default for ' . $page_type . ' content.' ];
    }

    private static function confirm( $reply, array $plan ) {
        $defaults = self::defaults( $reply ); if ( is_wp_error( $defaults ) ) { return $defaults; }
        $routes = $plan['modern'] ? Nova_Bridge_Suite_Layout_URL_Routes::index( $reply['body']['routes'] ?? null ) : [];
        if ( is_wp_error( $routes ) ) { return $routes; }
        return self::same( $defaults, $plan['defaults'] ) && self::same( $routes, $plan['routes'] ) ? true : self::error( 'default_conflict', 'Posting service did not confirm the requested routing. Reload and review the current layouts before retrying.' );
    }
}
