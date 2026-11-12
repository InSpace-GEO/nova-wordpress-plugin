<?php
/** Offer an explicit retry for this connection's unsynchronized saved creation layouts. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-nova-wordpress-environment-copy.php';

final class Nova_Bridge_Suite_Connection_Layout_Sync {
    private static function prompt_key(): string { return 'nova_connection_sync_prompt_' . get_current_user_id(); }

    /** Read only the active defaults, excluding retained history and unsupported writers. */
    public static function pending( array $connection, bool $verify_category = false ): array {
        if ( empty( $connection['enabled'] ) || Nova_WordPress_Environment::identity( $connection ) !== Nova_WordPress_Environment::identity() || ! empty( $connection['receive_shared_layouts'] )
            || ! is_callable( [ 'Nova_Bridge_Suite_Layout_Recipes', 'default_metadata' ] )
            || ( is_callable( [ 'Nova_Bridge_Suite_Shared_Layouts', 'is_subscriber' ] ) && Nova_Bridge_Suite_Shared_Layouts::is_subscriber( $connection ) ) ) { return []; }
        $pending = [];
        foreach ( Nova_Bridge_Suite_Layout_Recipes::defaults() as $resource => $identity ) {
            $layout = Nova_Bridge_Suite_Layout_Recipes::default_metadata( $resource );
            if ( ! is_array( $layout ) || empty( $layout['posting_support']['supported'] ) ) { continue; }
            $status = $layout['posting_sync'] ?? null;
            if ( 'taxonomy:product_cat' === $resource ) {
                if ( ! is_callable( [ 'Nova_Bridge_Suite_Static_Recipe_Adapter', 'saved_status' ] ) ) { continue; }
                $status = Nova_Bridge_Suite_Static_Recipe_Adapter::saved_status( $connection, $identity['id'], $identity['revision'] );
                if ( $verify_category && ( null === $status || 'unknown' === ( $status['status'] ?? '' ) ) ) { $status = Nova_Bridge_Suite_Static_Recipe_Adapter::confirm_status( $connection, $identity['id'], $identity['revision'] ); }
                if ( null === $status || in_array( $status['status'] ?? '', [ 'unknown', 'unsupported' ], true ) ) {
                    if ( $verify_category ) { add_settings_error( Nova_Bridge_Suite_Posting_Settings::OPTION, 'nova_layout_sync_unconfirmed', 'NOVA connection saved. The category mapping could not be confirmed. Open the Layouts page and save this mapping to investigate its synchronization status.', 'warning' ); }
                    continue;
                }
            }
            if ( 'synced' === ( $status['status'] ?? '' ) ) { continue; }
            $pending[] = [ 'id' => $identity['id'], 'revision' => $identity['revision'], 'label' => (string) ( $layout['label'] ?? $resource ) ];
        }
        return $pending;
    }

    public static function take_prompt( array $connection ): bool {
        $key = self::prompt_key();
        $expected = get_transient( $key );
        if ( false === $expected ) { return false; }
        delete_transient( $key );
        return is_string( $expected ) && hash_equals( Nova_WordPress_Environment::identity( $connection ), $expected ) && (bool) self::pending( $connection );
    }

    /** Called under connection/authoring locks, after the connection has been committed. */
    public static function after_save( array $input, array $previous, array $current ): void {
        $requested = in_array( $input['sync_saved_layouts'] ?? null, [ '1', 1, true ], true );
        if ( $requested ) {
            $expected = $input['sync_layouts_environment'] ?? null;
            if ( ! is_string( $expected ) || ! hash_equals( Nova_WordPress_Environment::identity( $current ), $expected ) || Nova_WordPress_Environment::identity( $previous ) !== Nova_WordPress_Environment::identity( $current ) ) {
                add_settings_error( Nova_Bridge_Suite_Posting_Settings::OPTION, 'nova_layout_sync_environment', 'NOVA connection saved. The connection changed while this form was open. Reload it, or save the mapping on the Layouts page to synchronize the current connection.', 'warning' );
                return;
            }
        }
        try { $pending = self::pending( $current, true ); }
        catch ( Throwable $error ) {
            add_settings_error( Nova_Bridge_Suite_Posting_Settings::OPTION, 'nova_layout_sync_failed', 'NOVA connection saved. Mapping synchronization could not be checked. Open the Layouts page and save the mapping to investigate and retry.', 'error' );
            return;
        }
        if ( ! $pending ) { return; }
        if ( ! $requested ) {
            set_transient( self::prompt_key(), Nova_WordPress_Environment::identity( $current ), 300 );
            return;
        }
        delete_transient( self::prompt_key() );
        foreach ( $pending as $index => $item ) {
            try { $reply = Nova_WordPress_Environment_Copy::sync_layout( $item['id'], $item['revision'] ); }
            catch ( Throwable $error ) { $reply = [ 'status' => 'failed', 'message' => 'Synchronization was interrupted.' ]; }
            $synced = 'synced' === ( $reply['status'] ?? '' );
            $label = sprintf( '%s (revision %d)', $item['label'], $item['revision'] );
            $message = $synced ? 'NOVA connection saved. ' . $label . ' synchronized.'
                : 'NOVA connection saved. ' . $label . ' could not be synchronized. ' . ( $reply['message'] ?? '' ) . ' Open the Layouts page and save this mapping to investigate and retry.';
            add_settings_error( Nova_Bridge_Suite_Posting_Settings::OPTION, 'nova_layout_sync_' . $index, $message, $synced ? 'success' : 'error' );
        }
    }
}
