<?php
/** Serialize connection changes with delivery, and retain retired work outside the active journal. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-nova-wordpress-environment-copy.php';

final class Nova_Bridge_Suite_Posting_Connection {
    private static $depth = 0;
    private static $authoring_depth = 0;
    private static $saving = false;
    // Separate from the delivery lock: deliveries never block layout editing, only switching does.
    private const AUTHORING_LOCK = 'connection-authoring';
    private const AUTHORING_WAIT = 15;
    // Held for a whole connection save. A wake recorded beside a running batch takes it too, so it never interleaves with a switch.
    private const SWITCH_LOCK = 'connection-switch';

    public static function saving(): bool { return self::$saving; }

    /** REST permissions run before this filter. Layout writes and switching are mutually exclusive; reads take no lock. */
    public static function dispatch( $result, $request, $route, $handler ) {
        if ( null !== $result || ! preg_match( '#^/nova-bridge/v1/(?:mapping|strategy)(?:/|$)#', $request->get_route() ) ) { return $result; }
        $write = ! in_array( $request->get_method(), [ 'GET', 'HEAD', 'OPTIONS' ], true );
        $run = static function () use ( $request, $handler, $write ) {
            self::clear_cache();
            $connection = Nova_Bridge_Suite_Posting_Settings::connection();
            $expected = (string) $request->get_header( 'x-nova-environment' );
            if ( ( '' !== $expected && ! hash_equals( Nova_WordPress_Environment::identity( $connection ), $expected ) ) || ( $write && '' === $expected && ! empty( $connection['history_archived'] ) ) ) {
                return new WP_Error( 'nova_posting_environment_changed', 'The NOVA connection changed. Reload this screen to work with its current layouts.', [ 'status' => 409 ] );
            }
            return call_user_func( $handler['callback'], $request );
        };
        return $write ? self::authoring( $run, self::AUTHORING_WAIT ) : $run();
    }

    /** Serializes local layout changes with connection switches; waits briefly instead of failing. */
    public static function authoring( callable $callback, int $wait = 0 ) {
        if ( self::$authoring_depth ) { return $callback(); }
        $store = new Nova_Bridge_Suite_Posting_Jobs();
        if ( is_wp_error( $store->lock( self::AUTHORING_LOCK, $wait ) ) ) {
            return new WP_Error( 'nova_posting_authoring_busy', 'Another layout change or connection switch is in progress. Please try again shortly.', [ 'status' => 409 ] );
        }
        ++self::$authoring_depth;
        try { return $callback(); }
        finally { --self::$authoring_depth; $store->unlock( self::AUTHORING_LOCK ); }
    }

    public static function guard( callable $callback ) {
        if ( self::$depth ) { return $callback(); }
        $store = new Nova_Bridge_Suite_Posting_Jobs();
        if ( is_wp_error( $store->lock( 'connection' ) ) ) {
            return new WP_Error( 'nova_posting_connection_busy', 'A delivery or connection save is running. Please try again shortly.', [ 'status' => 409 ] );
        }
        ++self::$depth;
        try {
            // A request may have loaded these options before another request switched environments.
            self::clear_cache();
            return $callback();
        } finally { --self::$depth; $store->unlock( 'connection' ); }
    }

    private static function clear_cache(): void {
        foreach ( [ Nova_Bridge_Suite_Posting_Settings::OPTION, Nova_Bridge_Suite_Posting_Delivery::BOOTSTRAP_OPTION, Nova_Bridge_Suite_Posting_Delivery::LAST_RUN_OPTION, 'alloptions', 'notoptions' ] as $key ) { wp_cache_delete( $key, 'options' ); }
    }

    public static function changed( array $old, array $next ): bool {
        return ! empty( $old['site_id'] ) && ( ( $old['base_url'] ?? '' ) !== ( $next['base_url'] ?? '' ) || $old['site_id'] !== ( $next['site_id'] ?? '' ) );
    }

    /** Prevent programmatic settings writes from bypassing the guarded form workflow. */
    public static function protect_update( $next, $old ) {
        if ( ! self::$saving && is_array( $old ) && is_array( $next ) && self::changed( $old, $next ) ) {
            add_settings_error( Nova_Bridge_Suite_Posting_Settings::OPTION, 'nova_posting_switch_form', 'Use Save NOVA connection to confirm an environment switch.' );
            return $old;
        }
        if ( ! self::$saving && is_array( $old ) && is_array( $next ) ) {
            foreach ( [ 'layout_legacy_environment', 'history_archived' ] as $key ) {
                if ( array_key_exists( $key, $old ) ) { $next[$key] = $old[$key]; }
            }
        }
        return $next;
    }

    public static function install_archive( $db ): bool {
        $table = $db->prefix . 'nova_posting_archive';
        $collation = $db->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            batch_id varchar(64) NOT NULL,
            source_origin varchar(255) NOT NULL,
            site_id varchar(64) NOT NULL,
            delivery_id varchar(64) NOT NULL DEFAULT '',
            record_type varchar(16) NOT NULL,
            state varchar(24) NOT NULL,
            record longtext NOT NULL,
            archived_at bigint unsigned NOT NULL,
            PRIMARY KEY  (id),
            KEY retired_delivery (site_id,delivery_id),
            KEY batch (batch_id)
        ) ENGINE=InnoDB {$collation};" );
        return $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $db->esc_like( $table ) ) ) === $table;
    }

    /** Archived work is never re-admitted when the administrator later reconnects to that environment. */
    public static function archived( $db, string $site, string $delivery ) {
        $connection = Nova_Bridge_Suite_Posting_Settings::connection();
        if ( empty( $connection['history_archived'] ) ) { return false; }
        $table = $db->prefix . 'nova_posting_archive';
        $found = $db->get_var( $db->prepare( "SELECT id FROM {$table} WHERE site_id=%s AND delivery_id=%s AND source_origin=%s AND record_type='job' LIMIT 1", $site, $delivery, $connection['base_url'] ) );
        return ! empty( $db->last_error ) ? new WP_Error( 'nova_posting_archive_read', 'Retired delivery history could not be checked.' ) : null !== $found;
    }

    public static function save( array $input ) {
        // Wait for an in-flight layout change; never switch underneath it.
        return self::authoring( static function () use ( $input ) { return self::switching( static function () use ( $input ) { return self::save_guarded( $input ); }, self::AUTHORING_WAIT ); }, self::AUTHORING_WAIT );
    }

    public static function switching( callable $callback, int $wait = 0 ) {
        $store = new Nova_Bridge_Suite_Posting_Jobs();
        if ( is_wp_error( $store->lock( self::SWITCH_LOCK, $wait ) ) ) {
            return new WP_Error( 'nova_posting_connection_busy', 'A delivery or connection save is running. Please try again shortly.', [ 'status' => 409 ] );
        }
        try {
            self::clear_cache();
            return $callback();
        } finally { $store->unlock( self::SWITCH_LOCK ); }
    }

    private static function save_guarded( array $input ) {
        return self::guard( static function () use ( $input ) {
            global $wpdb;
            $old = get_option( Nova_Bridge_Suite_Posting_Settings::OPTION, [] );
            $previous = Nova_Bridge_Suite_Posting_Settings::connection();
            $next = Nova_Bridge_Suite_Posting_Settings::sanitize( $input );
            if ( $next === $old ) { return true; }
            $effective = Nova_Bridge_Suite_Posting_Settings::effective_connection( $next );
            $switch = self::changed( $previous, $effective );
            if ( $switch && ! Nova_Bridge_Suite_Posting_Delivery::ensure_schema() ) { return new WP_Error( 'nova_posting_storage', 'Delivery storage is unavailable. The connection was not changed.' ); }
            if ( $switch ) {
                // An already-running request from the previous plugin release has no connection lock.
                $live = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}nova_posting_jobs WHERE state='running' AND lease_until>=%d", time() ) );
                if ( null === $live || ! empty( $wpdb->last_error ) ) { return new WP_Error( 'nova_posting_storage', 'Delivery storage could not be checked. The connection was not changed.' ); }
                if ( (int) $live > 0 ) { return new WP_Error( 'nova_posting_connection_busy', 'A delivery is still running. Please try again after its current batch finishes.', [ 'status' => 409 ] ); }
            }
            $copy = null;
            if ( $switch && in_array( $input['copy_environment_layouts'] ?? null, [ '1', 1, true ], true ) ) {
                $copy = Nova_WordPress_Environment_Copy::plan( $previous, $effective );
                if ( is_wp_error( $copy ) ) { return $copy; }
            }
            $transaction = false; $cancelled = 0;
            self::$saving = true;
            try {
                if ( $switch ) {
                    $tables = [ $wpdb->options, $wpdb->prefix . 'nova_posting_jobs', $wpdb->prefix . 'nova_posting_discovery', $wpdb->prefix . 'nova_posting_archive' ];
                    foreach ( $tables as $table ) {
                        $engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
                        if ( 'INNODB' !== strtoupper( (string) $engine ) ) { throw new RuntimeException( 'transaction_storage' ); }
                    }
                    if ( false === $wpdb->query( 'START TRANSACTION' ) ) { throw new RuntimeException( 'transaction_start' ); }
                    $transaction = true;
                    $cancelled = self::archive( $wpdb, $previous );
                    $next['layout_legacy_environment'] = Nova_WordPress_Environment::legacy_owner( $previous );
                    $next['history_archived'] = true;
                    if ( $copy ) { Nova_WordPress_Environment_Copy::apply( $copy, $effective ); }
                    // Do not carry diagnostics or a discovery bootstrap from the retired environment.
                    delete_option( Nova_Bridge_Suite_Posting_Delivery::BOOTSTRAP_OPTION );
                    if ( ! empty( $wpdb->last_error ) ) { throw new RuntimeException( 'bootstrap_reset' ); }
                    delete_option( Nova_Bridge_Suite_Posting_Delivery::LAST_RUN_OPTION );
                    if ( ! empty( $wpdb->last_error ) ) { throw new RuntimeException( 'diagnostics_reset' ); }
                }
                if ( ! update_option( Nova_Bridge_Suite_Posting_Settings::OPTION, $next, false ) ) { throw new RuntimeException( 'settings_save' ); }
                if ( $transaction && false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'transaction_commit' ); }
                $transaction = false;
            } catch ( Throwable $error ) {
                if ( $transaction ) { $wpdb->query( 'ROLLBACK' ); }
                self::clear_cache();
                return new WP_Error( 'nova_posting_switch_failed', 'The connection could not be saved safely. Its previous settings and delivery history have been retained.' );
            } finally { self::$saving = false; }
            Nova_Bridge_Suite_Posting_Delivery::initialize();
            if ( $switch ) {
                $note = 0 === $cancelled ? 'Switched to the new NOVA connection. There were no pending deliveries to cancel.' : sprintf( 'Switched to the new NOVA connection. %d pending %s from the previous connection %s cancelled.', $cancelled, 1 === $cancelled ? 'delivery' : 'deliveries', 1 === $cancelled ? 'was' : 'were' );
                add_settings_error( Nova_Bridge_Suite_Posting_Settings::OPTION, 'nova_posting_switched', $note . ' The previous delivery history is archived. Existing WordPress content is unchanged.', 'info' );
            }
            if ( $copy ) {
                // The switch is committed; synchronization failures are reported, never rolled back.
                self::clear_cache();
                $results = in_array( $input['sync_copied_layouts'] ?? null, [ '1', 1, true ], true ) ? Nova_WordPress_Environment_Copy::synchronize( $copy ) : null;
                foreach ( Nova_WordPress_Environment_Copy::messages( $copy, $results ) as $index => $message ) {
                    add_settings_error( Nova_Bridge_Suite_Posting_Settings::OPTION, 'nova_environment_copy_' . $index, $message[1], $message[0] );
                }
            }
            return true;
        } );
    }

    /** Returns how many unfinished deliveries were cancelled. */
    private static function archive( $db, array $previous ): int {
        $jobs = $db->prefix . 'nova_posting_jobs'; $discovery = $db->prefix . 'nova_posting_discovery'; $archive = $db->prefix . 'nova_posting_archive';
        $batch = wp_generate_uuid4(); $now = time();
        $columns = [ 'id', 'identity', 'site_id', 'content_id', 'version', 'protocol', 'identity_conflict', 'state', 'phase', 'target_id', 'operation_id', 'attempts', 'next_attempt', 'lease_token', 'lease_until', 'journal_revision', 'last_error', 'payload', 'created_at', 'updated_at' ];
        $record = implode( ',', array_map( static function ( $key ) { return "'{$key}',{$key}"; }, $columns ) );
        // Preserve exact payload bytes and previous state in the record; cancellation is archive metadata.
        $queries = [
            $db->prepare( "INSERT INTO {$archive} (batch_id,source_origin,site_id,delivery_id,record_type,state,record,archived_at) SELECT %s,CASE WHEN site_id=%s THEN %s ELSE '' END,site_id,content_id,'job',CASE WHEN state='complete' THEN 'complete' ELSE 'cancelled' END,JSON_OBJECT({$record}),%d FROM {$jobs}", $batch, $previous['site_id'], $previous['base_url'], $now ),
            $db->prepare( "INSERT INTO {$archive} (batch_id,source_origin,site_id,record_type,state,record,archived_at) SELECT %s,%s,site_id,'discovery','cancelled',JSON_OBJECT('site_id',site_id,'wake_sequence',wake_sequence,'payload',payload,'updated_at',updated_at),%d FROM {$discovery}", $batch, $previous['base_url'], $now ),
            "DELETE FROM {$jobs}", "DELETE FROM {$discovery}",
        ];
        $unfinished = $db->get_var( "SELECT COUNT(*) FROM {$jobs} WHERE state<>'complete'" );
        if ( null === $unfinished || ! empty( $db->last_error ) ) { throw new RuntimeException( 'archive_storage' ); }
        foreach ( $queries as $query ) { if ( false === $db->query( $query ) ) { throw new RuntimeException( 'archive_storage' ); } }
        return (int) $unfinished;
    }
}
