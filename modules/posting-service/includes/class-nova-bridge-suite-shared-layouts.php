<?php
/** Transfer approved stored layouts between WordPress installations sharing a NOVA credential. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Shared_Layouts {
    public const FORMAT = 'nova.shared-layouts/v1';
    public const MAX_BYTES = 8388608;
    public const MAX_LAYOUTS = 32;
    private const RECORD_KEYS = [ 'id', 'revision', 'resource_id', 'posting_page_type', 'label', 'builder', 'mode', 'recipe' ];

    private static function error( string $code, string $message, int $status = 409 ): WP_Error {
        return new WP_Error( 'nova_shared_layout_' . $code, $message, [ 'status' => $status ] );
    }
    private static function origin( array $connection ): string {
        $origin = strtolower( rtrim( (string) ( $connection['base_url'] ?? '' ), '/' ) );
        return (string) preg_replace( '/:443$/D', '', $origin );
    }
    private static function access( array $connection ) {
        if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'permission', 'Only an administrator can share native WordPress layouts.', 403 ); }
        if ( empty( $connection['enabled'] ) ) { return self::error( 'connection', 'Save the NOVA connection before sharing layouts.' ); }
        return ( new Nova_Bridge_Suite_Posting_Client( $connection ) )->connection_error();
    }
    private static function json( $data ): string { return Nova_Bridge_Suite_Writing_Adapter::canonical_json( $data ); }
    private static function digest( $data ): string { return hash( 'sha256', self::json( $data ) ); }
    private static function signature( array $file, array $connection ): string {
        $key = hash_hmac( 'sha256', 'nova.wordpress.shared-layouts/v1', $connection['token'], true );
        return hash_hmac( 'sha256', self::json( $file ), $key );
    }
    private static function index_key( array $connection ): string {
        return 'nova_shared_layout_index_' . hash( 'sha256', self::origin( $connection ) . "\n" . $connection['site_id'] );
    }
    private static function index( array $connection ) {
        $entries = get_option( self::index_key( $connection ), [] );
        return is_array( $entries ) ? $entries : self::error( 'storage', 'The imported layout index needs recovery.' );
    }
    private static function blob( array $entry ) {
        $digest = $entry['digest'] ?? '';
        if ( ! is_string( $digest ) || ! preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) { return self::error( 'storage', 'The saved layout has an invalid digest.' ); }
        $blob = get_option( 'nova_shared_layout_data_' . $digest, null );
        return is_array( $blob ) && hash_equals( $digest, self::digest( $blob ) ) ? $blob : self::error( 'storage', 'The saved layout could not be verified. No replacement was selected.' );
    }
    private static function identity( $id, $revision ): bool {
        return Nova_Bridge_Suite_Posting_Protocol::uuid( $id ) && is_int( $revision ) && $revision > 0;
    }
    public static function installed_profile( array $connection, string $site, string $id, int $revision ) {
        if ( $site !== ( $connection['site_id'] ?? null ) || ! self::origin( $connection ) ) { return null; }
        $index = self::index( $connection );
        if ( is_wp_error( $index ) ) { return $index; }
        $entry = $index[ $id . ':' . $revision ] ?? null;
        if ( null === $entry ) { return null; }
        if ( ! is_array( $entry ) ) { return self::error( 'storage', 'The imported layout index is invalid.' ); }
        $blob = self::blob( $entry );
        return is_wp_error( $blob ) ? $blob : $blob['profile'];
    }
    public static function has_imports( array $connection, string $site ): bool {
        if ( $site !== ( $connection['site_id'] ?? null ) || ! self::origin( $connection ) ) { return false; }
        $index = self::index( $connection );
        return is_array( $index ) && ! empty( $index );
    }
    /** The first installed revision is also the durable role marker for this connection. */
    public static function is_subscriber( array $connection ): bool {
        if ( ! empty( $connection['receive_shared_layouts'] ) ) { return true; }
        if ( empty( $connection['site_id'] ) || ! self::origin( $connection ) ) { return false; }
        // A damaged nonempty index must never silently turn a receiving installation into a publisher.
        return [] !== get_option( self::index_key( $connection ), [] );
    }
    public static function subscriber_error(): WP_Error {
        return self::error( 'subscriber', 'This installation receives shared layouts. Edit mappings and defaults on the installation that exports them, then import its updated file here.' );
    }
    private static function clear_index_cache( array $connection ): void {
        wp_cache_delete( self::index_key( $connection ), 'options' );
        wp_cache_delete( 'notoptions', 'options' );
    }
    /** Serialize an outbound configuration write with the first successful import. Receipts never take this lock. */
    private static function with_role_lock( array $connection, $locks, callable $operation ) {
        $lock = 'shared-layout-authority:' . self::index_key( $connection );
        $owned = $locks->lock( $lock ); if ( is_wp_error( $owned ) ) { return $owned; }
        try {
            self::clear_index_cache( $connection );
            return $operation();
        } finally { $locks->unlock( $lock ); }
    }
    public static function publish_configuration( array $connection, callable $send ) {
        if ( self::is_subscriber( $connection ) ) { return self::subscriber_error(); }
        return self::with_role_lock( $connection, new Nova_Bridge_Suite_Posting_Jobs(), static function () use ( $connection, $send ) {
            if ( self::is_subscriber( $connection ) ) { return self::subscriber_error(); }
            if ( defined( 'Nova_Bridge_Suite_Posting_Settings::OPTION' ) ) {
                wp_cache_delete( Nova_Bridge_Suite_Posting_Settings::OPTION, 'options' );
                $current = Nova_Bridge_Suite_Posting_Settings::connection();
                if ( self::origin( $current ) === self::origin( $connection ) && ( $current['site_id'] ?? '' ) === $connection['site_id'] && ! empty( $current['receive_shared_layouts'] ) ) { return self::subscriber_error(); }
            }
            return $send();
        } );
    }
    public static function imported_summaries( array $connection ): array {
        if ( empty( $connection['site_id'] ) || ! self::origin( $connection ) ) { return []; }
        $index = self::index( $connection );
        return is_array( $index ) ? array_column( $index, 'summary' ) : [];
    }
    private static function summary( array $template, array $record, string $origin ): array {
        return [
            'template_id' => $template['id'], 'revision' => $template['revision'], 'label' => $template['name'],
            'page_type' => $template['page_type'], 'builder' => $record['builder'], 'resource_id' => $record['resource_id'],
            'recipe_id' => $record['id'], 'recipe_revision' => $record['revision'], 'origin' => $origin,
        ];
    }
    private static function native_recipe( $profile ) {
        $layout = $profile['local']['stored_layout'] ?? [];
        if ( 'stored_layout_v3' !== ( $profile['mode'] ?? '' ) || 'nova.stored-recipe/v1' !== ( $layout['format'] ?? '' )
            || ! preg_match( '/^([0-9a-f-]{36}):([1-9][0-9]*)$/iD', (string) ( $profile['local']['revision'] ?? '' ), $parts ) ) {
            return self::error( 'unsupported', 'Choose an approved fixed layout saved from the Layouts screen.' );
        }
        return [ 'id' => $parts[1], 'revision' => (int) $parts[2], 'builder' => $layout['builder'], 'resource_id' => $layout['resource_id'] ];
    }
    /** Include existing 1.0.6 approvals, whose option names are hashes and have no index. */
    public static function catalog( array $connection ) {
        $denied = self::access( $connection ); if ( is_wp_error( $denied ) ) { return $denied; }
        $index = self::index( $connection ); if ( is_wp_error( $index ) ) { return $index; }
        $entries = array_column( $index, 'summary' );
        $seen = [];
        foreach ( $entries as $row ) { $seen[$row['template_id'] . ':' . $row['revision']] = true; }
        foreach ( Nova_Bridge_Suite_Layout_Recipes::saved_recipe_ids() as $recipe_id ) {
            $state = Nova_Bridge_Suite_Mapping_Sync::scope_state( $connection['site_id'], 'recipe:' . $recipe_id );
            $current = $state['template'] ?? [];
            if ( ! self::identity( $current['id'] ?? null, $current['revision'] ?? null ) ) { continue; }
            // An editor recipe retains at most 50 immutable revisions.
            for ( $revision = $current['revision']; $revision > max( 0, $current['revision'] - 50 ); --$revision ) {
                $key = $current['id'] . ':' . $revision;
                if ( isset( $seen[$key] ) ) { continue; }
                $profile = Nova_Bridge_Suite_Mapping_Sync::authored_profile( $connection['site_id'], $current['id'], $revision );
                $recipe = self::native_recipe( $profile );
                if ( is_wp_error( $recipe ) || $recipe_id !== $recipe['id'] ) { continue; }
                $entries[] = self::summary( $profile['template'], $recipe, 'local' );
                $seen[$key] = true;
            }
        }
        require_once __DIR__ . '/class-nova-bridge-suite-shared-layout-dependencies.php';
        foreach ( $entries as $i => $entry ) {
            $entries[$i]['shareable'] = Nova_Bridge_Suite_Shared_Layout_Dependencies::supports_builder( $entry['builder'] ?? '' );
        }
        usort( $entries, static function ( $a, $b ) { return [ $a['label'], $a['template_id'], $b['revision'] ] <=> [ $b['label'], $b['template_id'], $a['revision'] ]; } );
        return [ 'templates' => $entries ];
    }
    private static function original( array $connection, string $id, int $revision, array $index ) {
        $key = $id . ':' . $revision;
        if ( isset( $index[$key] ) ) {
            $blob = self::blob( $index[$key] ); return is_wp_error( $blob ) ? $blob : $blob['layout'];
        }
        $profile = Nova_Bridge_Suite_Mapping_Sync::authored_profile( $connection['site_id'], $id, $revision );
        $identity = self::native_recipe( $profile ); if ( is_wp_error( $identity ) ) { return $identity; }
        $record = Nova_Bridge_Suite_Layout_Recipes::delivery_record( $identity['id'], $identity['revision'] );
        if ( is_wp_error( $record ) ) { return $record; }
        $record = array_intersect_key( $record, array_flip( self::RECORD_KEYS ) );
        $draft = Nova_Bridge_Suite_Stored_Layout::from_recipe( $record ); if ( is_wp_error( $draft ) ) { return $draft; }
        if ( ( $profile['site_id'] ?? '' ) !== $connection['site_id'] || ( $profile['template']['id'] ?? '' ) !== $id || ( $profile['template']['revision'] ?? 0 ) !== $revision
            || self::digest( $draft ) !== ( $profile['profile_digest'] ?? '' ) || self::digest( $profile['local'] ) !== self::digest( $draft ) ) {
            return self::error( 'source_changed', 'The saved recipe no longer reproduces the originally approved native layout for this revision.' );
        }
        require_once __DIR__ . '/class-nova-bridge-suite-shared-layout-dependencies.php';
        $dependencies = Nova_Bridge_Suite_Shared_Layout_Dependencies::capture( $record ); if ( is_wp_error( $dependencies ) ) { return $dependencies; }
        return [ 'template' => $profile['template'], 'record' => $record, 'dependencies' => $dependencies ];
    }
    public static function export_selected( array $connection, array $versions ) {
        $denied = self::access( $connection ); if ( is_wp_error( $denied ) ) { return $denied; }
        if ( ! $versions || count( $versions ) > self::MAX_LAYOUTS ) { return self::error( 'selection', 'Select between 1 and 32 layout revisions.', 400 ); }
        $index = self::index( $connection ); if ( is_wp_error( $index ) ) { return $index; }
        $file = [ 'format' => self::FORMAT, 'service_origin' => self::origin( $connection ), 'site_id' => $connection['site_id'], 'layouts' => [] ];
        $seen = []; $size = 0;
        foreach ( $versions as $version ) {
            $id = $version['template_id'] ?? null; $revision = $version['revision'] ?? null;
            if ( ! self::identity( $id, $revision ) || isset( $seen[$id . ':' . $revision] ) ) { return self::error( 'selection', 'Select each exact template revision once.', 400 ); }
            $layout = self::original( $connection, $id, $revision, $index );
            if ( is_wp_error( $layout ) ) { return $layout; }
            $size += strlen( self::json( $layout ) );
            if ( $size > self::MAX_BYTES ) { return self::error( 'size', 'The file exceeds 8 MiB. Export fewer layouts.', 413 ); }
            $file['layouts'][] = $layout; $seen[$id . ':' . $revision] = true;
        }
        $file['signature'] = self::signature( $file, $connection );
        // The REST download may escape Unicode and slashes that canonical signing leaves unescaped.
        if ( strlen( (string) wp_json_encode( $file ) ) > self::MAX_BYTES ) { return self::error( 'size', 'The file exceeds 8 MiB. Export fewer layouts.', 413 ); }
        return $file;
    }
    private static function verify_file( array $file, array $connection ) {
        if ( ( $file['format'] ?? null ) !== self::FORMAT || ( $file['site_id'] ?? null ) !== $connection['site_id'] || ( $file['service_origin'] ?? null ) !== self::origin( $connection ) ) {
            return self::error( 'scope', 'Choose a layout file exported for this NOVA site and posting service.' );
        }
        if ( ! is_array( $file['layouts'] ?? null ) || ! $file['layouts'] || count( $file['layouts'] ) > self::MAX_LAYOUTS || array_values( $file['layouts'] ) !== $file['layouts']
            || array_diff( array_keys( $file ), [ 'format', 'site_id', 'service_origin', 'signature', 'layouts' ] ) ) { return self::error( 'format', 'The shared layout file must contain between 1 and 32 revisions.', 400 ); }
        if ( strlen( self::json( $file ) ) > self::MAX_BYTES ) { return self::error( 'size', 'The shared layout file exceeds 8 MiB.', 413 ); }
        $signature = $file['signature'] ?? null; unset( $file['signature'] );
        if ( ! is_string( $signature ) || ! hash_equals( self::signature( $file, $connection ), $signature ) ) {
            return self::error( 'signature', 'The file could not be verified with this site credential. Use the same credential and export a fresh file if it changed.', 403 );
        }
        return true;
    }
    private static function prepare( array $layout, array $connection, array $resolutions ) {
        $template = $layout['template']; $record = $layout['record'];
        if ( array_diff( array_keys( $record ), self::RECORD_KEYS ) || ! self::identity( $record['id'] ?? null, $record['revision'] ?? null )
            || 'static' !== ( $record['mode'] ?? null ) || ! is_array( $record['recipe'] ?? null ) ) {
            return self::error( 'unsupported', 'Only fixed native creation recipes with supported editorial bindings can be imported.' );
        }
        require_once __DIR__ . '/class-nova-bridge-suite-shared-layout-dependencies.php';
        $native = Nova_Bridge_Suite_Shared_Layout_Dependencies::resolve( $record, $layout['dependencies'], $resolutions );
        if ( is_wp_error( $native ) ) { return $native; }
        $draft = Nova_Bridge_Suite_Stored_Layout::from_recipe( $native ); if ( is_wp_error( $draft ) ) { return $draft; }
        $fields = Nova_Bridge_Suite_Layout_Custom_Fields::verify_registration( $native['recipe'], $draft['stored_layout']['post_type'] );
        if ( is_wp_error( $fields ) ) { return $fields; }
        $input = Nova_Bridge_Suite_Writing_Adapter::template_input( $draft );
        if ( is_wp_error( $input ) ) { return $input; }
        unset( $input['enabled'] );
        $expected = $input + [ 'id' => $template['id'], 'revision' => $template['revision'] ];
        if ( self::json( $template ) !== self::json( $expected ) ) { return self::error( 'projection', 'The local writer does not reproduce the exact exported template definition and field mapping.' ); }
        $probe = Nova_Bridge_Suite_Stored_Layout_Writer::probe_mapping( $template, $template['mapping'], $draft, [ 'actor_user_id' => (int) ( $connection['actor_user_id'] ?? 0 ) ] );
        if ( is_wp_error( $probe ) ) { return $probe; }
        return [
            'contract' => Nova_Bridge_Suite_Mapping_Sync::CONTRACT, 'site_id' => $connection['site_id'],
            'template' => $template, 'remote' => $template, 'local' => $draft, 'profile_digest' => self::digest( $draft ), 'mode' => 'stored_layout_v3',
        ];
    }
    /** Preflight every member before making any new revision visible to delivery workers. */
    public static function import_selected( array $connection, array $file, array $resolutions = [], $locks = null ) {
        $denied = self::access( $connection ); if ( is_wp_error( $denied ) ) { return $denied; }
        $valid = self::verify_file( $file, $connection ); if ( is_wp_error( $valid ) ) { return $valid; }
        $locks = $locks ?: new Nova_Bridge_Suite_Posting_Jobs();
        $lock = 'stored-recipe-editor:' . $connection['site_id']; $owned = $locks->lock( $lock );
        if ( is_wp_error( $owned ) ) { return $owned; }
        try {
            self::clear_index_cache( $connection );
            $index = self::index( $connection ); if ( is_wp_error( $index ) ) { return $index; }
            $prepared = []; $results = []; $seen = [];
            foreach ( $file['layouts'] as $layout ) {
                $template = $layout['template'] ?? null; $record = $layout['record'] ?? null;
                if ( ! is_array( $template ) || ! self::identity( $template['id'] ?? null, $template['revision'] ?? null )
                    || ! is_array( $record ) || ! is_array( $layout['dependencies'] ?? null ) || array_diff( array_keys( $layout ), [ 'template', 'record', 'dependencies' ] ) ) {
                    return self::error( 'format', 'The file contains an incomplete native layout revision.', 400 );
                }
                $key = $template['id'] . ':' . $template['revision'];
                if ( isset( $seen[$key] ) || ( isset( $resolutions[$key] ) && ! is_array( $resolutions[$key] ) ) ) { return self::error( 'selection', 'Each revision must occur once and have its own dependency choices.', 400 ); }
                $seen[$key] = true;
                $prior = isset( $index[$key] ) ? self::blob( $index[$key] ) : null;
                if ( is_wp_error( $prior ) ) { return $prior; }
                $same_source = $prior && self::digest( $prior['layout'] ) === self::digest( $layout );
                $remembered = $same_source ? ( $prior['resolutions'] ?? [] ) : [];
                $explicit = $resolutions[$key] ?? [];
                $choices = array_replace( $remembered, $explicit );
                $profile = self::prepare( $layout, $connection, $choices );
                // Old manual IDs are preferences for the same file. If an object moved or was
                // replaced, try its original fingerprint again. Never ignore a new explicit choice.
                if ( $remembered && is_wp_error( $profile ) && 'nova_shared_layout_dependencies' === $profile->get_error_code() ) {
                    $retry_choices = $choices;
                    foreach ( $profile->get_error_data()['issues'] ?? [] as $issue ) {
                        $dependency = $issue['key'];
                        if ( isset( $remembered[$dependency] ) && ! array_key_exists( $dependency, $explicit ) ) { unset( $retry_choices[$dependency] ); }
                    }
                    if ( $retry_choices !== $choices ) {
                        $choices = $retry_choices;
                        $profile = self::prepare( $layout, $connection, $choices );
                    }
                }
                if ( is_wp_error( $profile ) ) {
                    $data = $profile->get_error_data();
                    return new WP_Error( $profile->get_error_code(), $profile->get_error_message(), [ 'version_key' => $key ] + ( is_array( $data ) ? $data : [] ) );
                }
                $authored = Nova_Bridge_Suite_Mapping_Sync::authored_profile( $connection['site_id'], $template['id'], $template['revision'] );
                $active = $prior['profile'] ?? $authored;
                $identical = null !== $active && self::digest( $active ) === self::digest( $profile );
                $summary = self::summary( $template, $record, 'imported' );
                $status = $identical && ( ! $prior || $same_source ) ? 'unchanged' : ( null !== $active ? 'replaced' : 'imported' );
                $results[] = $summary + [ 'status' => $status ];
                if ( ! $prior || 'unchanged' !== $status || $choices !== $remembered ) { $prepared[$key] = [ 'blob' => [ 'layout' => $layout, 'profile' => $profile, 'resolutions' => $choices ], 'summary' => $summary ]; }
            }
            if ( array_diff_key( $resolutions, $seen ) ) { return self::error( 'selection', 'A dependency choice belongs to a revision outside this file.', 400 ); }
            // Blobs are inert until every write succeeds and the single index option is published.
            foreach ( $prepared as $key => $entry ) {
                $digest = self::digest( $entry['blob'] ); $name = 'nova_shared_layout_data_' . $digest;
                if ( ! add_option( $name, $entry['blob'], '', false ) && self::digest( get_option( $name, null ) ) !== $digest ) { return self::error( 'storage', 'The shared layout could not be stored. Retry with the same file.', 500 ); }
                $index[$key] = [ 'digest' => $digest, 'summary' => $entry['summary'] ];
            }
            if ( $prepared ) {
                $activated = self::with_role_lock( $connection, $locks, static function () use ( $connection, $index ) {
                    $key = self::index_key( $connection );
                    return update_option( $key, $index, false ) || $index === get_option( $key, [] );
                } );
                if ( is_wp_error( $activated ) ) { return $activated; }
                if ( ! $activated ) { return self::error( 'storage', 'The new revisions could not be activated. Retry with the same file.', 500 ); }
            }
            $counts = array_count_values( array_column( $results, 'status' ) );
            return [ 'layouts' => $results, 'imported' => $counts['imported'] ?? 0, 'replaced' => $counts['replaced'] ?? 0, 'unchanged' => $counts['unchanged'] ?? 0 ];
        } finally { $locks->unlock( $lock ); }
    }
}
