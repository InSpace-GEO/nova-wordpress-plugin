<?php
/** Serialize connection changes with delivery, and retain retired work outside the active journal. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Posting_Connection {
    private static $depth = 0;
    private static $saving = false;

    public static function saving(): bool { return self::$saving; }

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
            $transaction = false;
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
                    self::archive( $wpdb, $previous );
                    $next['history_archived'] = true;
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
            return true;
        } );
    }

    private static function archive( $db, array $previous ): void {
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
        foreach ( $queries as $query ) { if ( false === $db->query( $query ) ) { throw new RuntimeException( 'archive_storage' ); } }
    }
}
