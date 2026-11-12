<?php
/** Signed ready-event intake and administrator delivery recovery. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Posting_Delivery {
    public const CRON_HOOK = 'nova_bridge_posting_drain';
    public const MAX_EVENT_BYTES = 16384;
    public const CLOCK_SKEW_SECONDS = 300;
    public const SCHEMA_OPTION = 'nova_bridge_posting_schema_version';
    public const SCHEMA_VERSION = 4;
    public const BOOTSTRAP_OPTION = 'nova_bridge_posting_webhook_bootstrap';
    public const LAST_RUN_OPTION = 'nova_bridge_posting_last_run';
    public const CONTINUE_OPTION = 'nova_bridge_posting_continuation';
    /** Consecutive self-started batches before the chain hands over to the cron safety net. */
    public const MAX_HOPS = 50;
    private const CONTINUE_TTL = 120;
    private const BACKSTOP_SECONDS = 180;
    private const STALLED_SECONDS = 60;
    private const SAFE_ERRORS = [ 'authentication_required', 'site_ineligible', 'remote_identity_conflict', 'native_commit_reconciliation', 'worker_interrupted', 'target_busy', 'journal_unavailable', 'paused', 'retry_after_out_of_bounds', 'verification_result_invalid', 'mutation_result_unverified', 'other_mutation_unreconciled', 'journal_phase_invalid', 'journal_size', 'nova_posting_transport', 'nova_posting_storage', 'nova_posting_journal', 'nova_posting_journal_size', 'nova_writer_recovery_drift', 'discovery_site', 'discovery_storage', 'discovery_unavailable', 'site_disabled', 'runner_exception' ];
    private static $schema_attempted = null;
    private static $run_queued = false;
    private static $running = false;
    private static $continue = false;
    private static $hops = 0;

    public static function bootstrap(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
        add_action( 'init', [ __CLASS__, 'guarded_initialize' ] );
        add_action( 'shutdown', [ __CLASS__, 'guarded_run_queued' ], 20 );
        add_action( self::CRON_HOOK, [ __CLASS__, 'cron_run' ] );
    }

    // Keep the connection lock for the entire discovery/native-write/receipt batch.
    // All public entry points refresh settings under the same lock as the switch.
    public static function guarded_initialize(): void { Nova_Bridge_Suite_Posting_Connection::guard( [ __CLASS__, 'initialize' ] ); }
    public static function guarded_run_queued(): void {
        if ( ! self::$run_queued ) { return; }
        $ran = Nova_Bridge_Suite_Posting_Connection::guard( [ __CLASS__, 'run_queued' ] );
        // Another batch holds the connection; its own follow-up continues the queue. The
        // backstop only matters if that batch finished its follow-up just before this point.
        if ( is_wp_error( $ran ) ) { self::schedule_backstop( time() + self::STALLED_SECONDS, true ); return; }
        // Start the next batch only after this request released the connection lock.
        if ( self::$continue ) { self::$continue = false; self::dispatch_continuation(); }
    }
    /** A wake that arrives while a batch runs is recorded, not refused; the running chain or the backstop picks it up. */
    public static function guarded_ready( $request ) {
        $answer = Nova_Bridge_Suite_Posting_Connection::guard( static function () use ( $request ) { return self::ready( $request ); } );
        return is_wp_error( $answer ) && 'nova_posting_connection_busy' === $answer->get_error_code() ? self::ready_while_busy( $request ) : $answer;
    }
    public static function guarded_status( $request ) { return Nova_Bridge_Suite_Posting_Connection::guard( static function () use ( $request ) { return self::status( $request ); } ); }
    public static function guarded_resume( $request ) { return Nova_Bridge_Suite_Posting_Connection::guard( static function () use ( $request ) { return self::resume( $request ); } ); }
    public static function guarded_retry_discovery( $request ) { return Nova_Bridge_Suite_Posting_Connection::guard( static function () use ( $request ) { return self::retry_discovery( $request ); } ); }

    /** One catch-up per effective connection; ordinary requests never poll for deliveries. */
    public static function initialize(): void {
        // Releases before 1.3.3 used a recurring drain. Only the single follow-up event remains.
        $legacy = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( self::CRON_HOOK ) : false;
        if ( $legacy && false !== $legacy->schedule ) { wp_clear_scheduled_hook( self::CRON_HOOK ); }
        $connection = Nova_Bridge_Suite_Posting_Settings::connection();
        if ( empty( $connection['enabled'] ) ) { delete_option( self::BOOTSTRAP_OPTION ); if ( wp_next_scheduled( self::CRON_HOOK ) ) { wp_clear_scheduled_hook( self::CRON_HOOK ); } return; }
        if ( ! self::ensure_schema() ) { return; }
        $key = hash( 'sha256', wp_json_encode( [ 'webhook-runner-v1', $connection['base_url'] ?? '', $connection['site_id'] ?? '', $connection['token'] ?? '', $connection['webhook_secret'] ?? '', ! empty( $connection['paused'] ), (int) ( $connection['actor_user_id'] ?? 0 ) ] ) );
        if ( get_option( self::BOOTSTRAP_OPTION, '' ) === $key ) { return; }
        $store = new Nova_Bridge_Suite_Posting_Jobs();
        if ( is_wp_error( $store->lock( 'webhook-bootstrap' ) ) ) { return; }
        try {
            if ( get_option( self::BOOTSTRAP_OPTION, '' ) === $key ) { return; }
            if ( is_wp_error( ( new Nova_Bridge_Suite_Posting_Discovery( $connection, $store ) )->wake() ) ) { return; }
            update_option( self::BOOTSTRAP_OPTION, $key, false );
            self::queue_run();
        } finally { $store->unlock( 'webhook-bootstrap' ); }
    }

    /** Coalesce wakes within this request; the follow-up after each batch continues the queue. */
    private static function queue_run(): void { self::$run_queued = true; }

    /** The single follow-up event: runs one batch, which plans its own follow-up again. */
    public static function cron_run(): void {
        self::$hops = 0;
        self::queue_run();
        self::guarded_run_queued();
    }

    public static function run_queued(): void {
        if ( ! self::$run_queued || self::$running ) { return; }
        self::$run_queued = false;
        self::$running = true;
        $previous = ignore_user_abort( true );
        $started = microtime( true );
        $record = [ 'run_id' => uniqid( '', true ), 'site_id' => null, 'started_at' => time(), 'duration_ms' => null, 'state' => 'running', 'completed' => false, 'successful' => null, 'response_completion_method' => 'unsupported', 'response_completion_returned' => false, 'response_completion_result' => null, 'response_completion_elapsed_ms' => null, 'jobs_claimed' => 0, 'last_job' => null, 'last_error' => '', 'discovery_http_status' => null, 'batch' => self::$hops + 1, 'follow_up' => null, 'next_run_at' => null ];
        try { $record['site_id'] = self::safe_uuid( Nova_Bridge_Suite_Posting_Settings::connection()['site_id'] ?? null ); } catch ( Throwable $ignored ) { /* Correlation is best effort. */ }
        self::save_run( $record, true );
        try {
            // WordPress has emitted its response before shutdown. Finish it before slow CMS work
            // where supported. A successful response does not guarantee the batch will finish.
            if ( function_exists( 'fastcgi_finish_request' ) ) { $record['response_completion_method'] = 'fastcgi'; }
            elseif ( function_exists( 'litespeed_finish_request' ) ) { $record['response_completion_method'] = 'litespeed'; }
            self::save_run( $record );
            if ( 'unsupported' !== $record['response_completion_method'] ) {
                $finished = 'fastcgi' === $record['response_completion_method'] ? fastcgi_finish_request() : litespeed_finish_request();
                $record['response_completion_returned'] = true;
                $record['response_completion_result'] = is_bool( $finished ) ? $finished : null;
                $record['response_completion_elapsed_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
                self::save_run( $record );
            }
            $max_claims = empty( Nova_Bridge_Suite_Posting_Settings::connection()['url_scope_enabled'] ) ? 3 : 100;
            self::drain( static function ( array $summary ) use ( &$record, $max_claims ) {
                if ( isset( $summary['jobs_claimed'] ) ) { $record['jobs_claimed'] = max( 0, min( $max_claims, (int) $summary['jobs_claimed'] ) ); }
                if ( isset( $summary['last_job'] ) && is_array( $summary['last_job'] ) ) { $record['last_job'] = self::safe_job( $summary['last_job'] ); }
                if ( ! empty( $summary['last_error'] ) ) { $record['last_error'] = self::safe_error( $summary['last_error'] ); }
                if ( isset( $summary['discovery_http_status'] ) && $summary['discovery_http_status'] >= 100 && $summary['discovery_http_status'] <= 599 ) { $record['discovery_http_status'] = (int) $summary['discovery_http_status']; }
                self::save_run( $record );
            } );
            $record['state'] = 'finished'; $record['completed'] = true; $record['successful'] = '' === $record['last_error'];
            [ $record['follow_up'], $record['next_run_at'] ] = self::plan_follow_up( (int) $record['jobs_claimed'] );
        } catch ( Throwable $error ) {
            // An interrupted batch still leaves its work to the safety net.
            try { self::schedule_backstop( time() + self::STALLED_SECONDS ); } catch ( Throwable $ignored ) { /* Best effort. */ }
            $record['state'] = 'interrupted'; $record['successful'] = false; $record['last_error'] = 'runner_exception';
            throw $error;
        } finally {
            $record['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
            self::save_run( $record );
            ignore_user_abort( (bool) $previous );
            self::$running = false;
        }
    }

    /**
     * Decide what follows this batch, under the same connection lock. Returns [ follow_up, next_run_at ]:
     * 'continue' starts the next batch in a fresh request now, 'scheduled' leaves it to the single
     * cron event at next_run_at, 'idle' means nothing is pending, 'blocked' means only an
     * administrator can continue (authentication or eligibility).
     */
    private static function plan_follow_up( int $claimed ): array {
        $connection = Nova_Bridge_Suite_Posting_Settings::connection();
        if ( empty( $connection['enabled'] ) ) { self::clear_backstop(); return [ 'idle', null ]; }
        $site = (string) ( $connection['site_id'] ?? '' );
        $store = new Nova_Bridge_Suite_Posting_Jobs();
        $now = time();
        $discovery = $store->discovery_state( $site );
        if ( is_wp_error( $discovery ) ) { return [ 'scheduled', self::schedule_backstop( $now + self::BACKSTOP_SECONDS ) ]; }
        $suspended = ! empty( $discovery['suspended'] );
        if ( $suspended && 403 !== (int) ( $discovery['auth_status'] ?? 0 ) ) { self::clear_backstop(); return [ 'blocked', null ]; }
        // A 403 site may still finish work that already reached the CMS, exactly like a paused connection.
        $work = $store->pending_work( $site, ! empty( $connection['paused'] ) || $suspended );
        if ( is_wp_error( $work ) ) { return [ 'scheduled', self::schedule_backstop( $now + self::BACKSTOP_SECONDS ) ]; }
        $times = [];
        if ( $work['due'] > 0 ) { $times[] = $now; } elseif ( null !== $work['next_due'] ) { $times[] = max( $now, $work['next_due'] ); }
        $discovering = ! $suspended && ( ! empty( $discovery['cycle_started'] ) || (int) ( $discovery['wake_sequence'] ?? 0 ) > (int) ( $discovery['consumed_sequence'] ?? 0 ) );
        $retry_at = (int) ( $discovery['next_attempt'] ?? 0 );
        if ( $discovering && PHP_INT_MAX !== $retry_at ) { $times[] = max( $now, $retry_at ); }
        if ( ! $times ) { self::clear_backstop(); return [ 'idle', null ]; }
        $next = min( $times );
        if ( $next > $now ) { return [ 'scheduled', self::schedule_backstop( $next ) ]; }
        // Continue at once only after a batch that made progress, and only for a bounded chain;
        // otherwise the same work would spin. The backstop covers a continuation that never arrives.
        if ( $claimed > 0 && self::$hops + 1 < self::MAX_HOPS ) {
            self::$continue = true;
            self::schedule_backstop( $now + self::BACKSTOP_SECONDS );
            return [ 'continue', $now ];
        }
        return [ 'scheduled', self::schedule_backstop( $now + self::STALLED_SECONDS ) ];
    }

    /** Keep exactly one follow-up event. With $earlier_only an existing earlier event is kept. */
    private static function schedule_backstop( int $at, bool $earlier_only = false ): int {
        $current = wp_next_scheduled( self::CRON_HOOK );
        if ( $current && ( (int) $current === $at || ( $earlier_only && (int) $current <= $at ) ) ) { return (int) $current; }
        if ( $current ) { wp_clear_scheduled_hook( self::CRON_HOOK ); }
        wp_schedule_single_event( $at, self::CRON_HOOK );
        return $at;
    }

    private static function clear_backstop(): void {
        if ( wp_next_scheduled( self::CRON_HOOK ) ) { wp_clear_scheduled_hook( self::CRON_HOOK ); }
    }

    /** Start the next batch in a new request, the way WordPress spawns cron. A one-time token proves it came from here. */
    private static function dispatch_continuation(): void {
        try {
            $token = bin2hex( random_bytes( 32 ) );
            update_option( self::CONTINUE_OPTION, [ 'hash' => hash( 'sha256', $token ), 'expires' => time() + self::CONTINUE_TTL, 'hops' => self::$hops + 1 ], false );
            wp_remote_post( rest_url( 'nova-bridge/v1/posting/continue' ), [ 'blocking' => false, 'timeout' => 1, 'redirection' => 0, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ), 'headers' => [ 'X-NOVA-Continue' => $token ], 'body' => '' ] );
        } catch ( Throwable $ignored ) { /* The scheduled backstop continues the queue. */ }
    }

    public static function continue_run( $request ) {
        $token = (string) $request->get_header( 'x-nova-continue' );
        $saved = get_option( self::CONTINUE_OPTION, [] );
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) || ! is_array( $saved ) || ! is_string( $saved['hash'] ?? null ) || (int) ( $saved['expires'] ?? 0 ) < time() || ! hash_equals( $saved['hash'], hash( 'sha256', $token ) ) ) {
            return new WP_Error( 'nova_posting_invalid_continuation', 'The continuation could not be accepted.', [ 'status' => 401 ] );
        }
        delete_option( self::CONTINUE_OPTION );
        self::$hops = max( 0, (int) ( $saved['hops'] ?? 0 ) );
        self::queue_run();
        return self::response( [ 'accepted' => true ], 202 );
    }

    /** The batch holding the connection lock cannot be interrupted; record the wake so it is not lost. A connection save still refuses it. */
    private static function ready_while_busy( $request ) {
        return Nova_Bridge_Suite_Posting_Connection::switching( static function () use ( $request ) { return self::record_busy_wake( $request ); } );
    }

    private static function record_busy_wake( $request ) {
        $connection = Nova_Bridge_Suite_Posting_Settings::connection();
        $event = self::authenticate( (string) $request->get_body(), (string) $request->get_header( 'x-nova-timestamp' ), (string) $request->get_header( 'x-nova-signature' ), $connection );
        if ( is_wp_error( $event ) ) { return $event; }
        if ( empty( $connection['enabled'] ) ) { return new WP_Error( 'nova_posting_disabled', 'Delivery intake is disabled.', [ 'status' => 503 ] ); }
        if ( self::SCHEMA_VERSION !== (int) get_option( self::SCHEMA_OPTION, 0 ) ) { return new WP_Error( 'nova_posting_connection_busy', 'A delivery or connection save is running. Please try again shortly.', [ 'status' => 409 ] ); }
        $woken = ( new Nova_Bridge_Suite_Posting_Discovery( $connection ) )->wake();
        if ( is_wp_error( $woken ) ) { return $woken; }
        self::schedule_backstop( time() + self::STALLED_SECONDS, true );
        return self::response( [ 'accepted' => true, 'wake_accepted' => true ], 202 );
    }

    /** Best-effort snapshot: avoid replacing a newer observed run; option reads/writes are not atomic. */
    private static function save_run( array $record, bool $start = false ): void {
        try {
            $current = get_option( self::LAST_RUN_OPTION, [] );
            if ( $start || ( is_array( $current ) && ( $current['run_id'] ?? null ) === $record['run_id'] ) ) { update_option( self::LAST_RUN_OPTION, $record, false ); }
        } catch ( Throwable $ignored ) { /* An unavailable diagnostic store cannot stop a delivery. */ }
    }

    private static function safe_error( $code ): string {
        if ( '' === $code ) { return ''; }
        if ( ! is_string( $code ) ) { return 'unclassified_error'; }
        if ( 'nova_posting_url_scope' === $code || in_array( $code, self::SAFE_ERRORS, true ) ) { return $code; }
        $base = substr( $code, 0, -12 );
        return substr( $code, -12 ) === '_retry_limit' && in_array( $base, self::SAFE_ERRORS, true ) ? $code : 'unclassified_error';
    }

    private static function safe_job( array $job ): array {
        $state = in_array( $job['state'] ?? null, [ 'ready', 'running', 'blocked', 'complete' ], true ) ? $job['state'] : 'unknown';
        $phase = in_array( $job['phase'] ?? null, [ 'accepted', 'attempt_check', 'validated', 'event_pending', 'received', 'configured', 'planned', 'applying', 'committed', 'skipped' ], true ) ? $job['phase'] : 'unknown';
        // The current journal stores a delivery UUID in this historical column; retain legacy decimal IDs too.
        $content_id = $job['content_id'] ?? null;
        $content_id = is_string( $content_id ) && preg_match( '/^[0-9]{1,64}$/D', $content_id ) ? $content_id : self::safe_uuid( $content_id );
        return [ 'id' => max( 0, (int) ( $job['id'] ?? 0 ) ), 'content_id' => $content_id, 'version' => max( 0, (int) ( $job['version'] ?? 0 ) ), 'delivery_id' => self::safe_uuid( $job['delivery_id'] ?? null ), 'attempt_id' => self::safe_uuid( $job['attempt_id'] ?? null ), 'state' => $state, 'phase' => $phase, 'attempts' => max( 0, (int) ( $job['attempts'] ?? 0 ) ), 'next_attempt' => max( 0, (int) ( $job['next_attempt'] ?? 0 ) ), 'error_code' => self::safe_error( $job['last_error'] ?? '' ), 'recovery_action' => self::recovery_action( $state ) ];
    }

    private static function safe_uuid( $value ): ?string {
        return is_string( $value ) && preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $value ) ? strtolower( $value ) : null;
    }

    private static function recovery_action( string $state ): string {
        if ( 'blocked' === $state ) { return 'explicit_resume'; }
        if ( 'ready' === $state ) { return 'automatic_when_due'; }
        if ( 'running' === $state ) { return 'check_run_and_lease_before_catch_up'; }
        return 'none';
    }

    /** Upgrade only an enabled connection; disabled installs perform no schema work. */
    public static function ensure_schema(): bool {
        if ( self::SCHEMA_VERSION === (int) get_option( self::SCHEMA_OPTION, 0 ) ) { return true; }
        if ( null !== self::$schema_attempted ) { return self::$schema_attempted; }
        self::$schema_attempted = false;
        $store = new Nova_Bridge_Suite_Posting_Jobs();
        $locked = $store->lock( 'schema' );
        if ( is_wp_error( $locked ) ) { return false; }
        try {
            if ( true !== $store->install() ) { return false; }
            update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
            self::$schema_attempted = self::SCHEMA_VERSION === (int) get_option( self::SCHEMA_OPTION, 0 );
            return self::$schema_attempted;
        } finally { $store->unlock( 'schema' ); }
    }

    public static function drain( ?callable $observe = null ): void {
        ( new Nova_Bridge_Suite_Posting_Worker( Nova_Bridge_Suite_Posting_Settings::connection() ) )->run( $observe );
    }

    public static function register_routes(): void {
        register_rest_route( 'nova-bridge/v1', '/posting/ready', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'guarded_ready' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( 'nova-bridge/v1', '/posting/continue', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'continue_run' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( 'nova-bridge/v1', '/posting/jobs', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'guarded_status' ], 'permission_callback' => [ __CLASS__, 'can_admin' ] ] );
        register_rest_route( 'nova-bridge/v1', '/posting/jobs/(?P<id>[1-9][0-9]*)/resume', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'guarded_resume' ], 'permission_callback' => [ __CLASS__, 'can_admin' ] ] );
        register_rest_route( 'nova-bridge/v1', '/posting/discovery/retry', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'guarded_retry_discovery' ], 'permission_callback' => [ __CLASS__, 'can_retry_discovery' ] ] );
    }

    public static function can_admin() {
        return current_user_can( 'manage_options' ) ? true : new WP_Error( 'nova_posting_forbidden', 'Administrator access is required.', [ 'status' => is_user_logged_in() ? 403 : 401 ] );
    }

    public static function can_retry_discovery( $request ) {
        $permission = self::can_admin();
        if ( is_wp_error( $permission ) ) { return $permission; }
        return wp_verify_nonce( (string) $request->get_header( 'x-wp-nonce' ), 'wp_rest' ) ? true : new WP_Error( 'nova_posting_nonce', 'Refresh the Mapping page before rechecking access.', [ 'status' => 403 ] );
    }

    public static function retry_discovery( $request ) {
        $permission = self::can_retry_discovery( $request ); if ( is_wp_error( $permission ) ) { return $permission; }
        $connection = Nova_Bridge_Suite_Posting_Settings::connection();
        if ( empty( $connection['enabled'] ) ) { return new WP_Error( 'nova_posting_disabled', 'The connection is disabled.', [ 'status' => 503 ] ); }
        if ( ! self::ensure_schema() ) { return new WP_Error( 'nova_posting_storage', 'Discovery storage is unavailable.', [ 'status' => 503 ] ); }
        $result = ( new Nova_Bridge_Suite_Posting_Discovery( $connection ) )->retry_authentication();
        if ( is_wp_error( $result ) ) { return $result; }
        self::queue_run();
        return self::response( [ 'recheck_scheduled' => true, 'message' => 'Discovery will authenticate again after this response. Blocked deliveries still require an explicit resume.' ] );
    }

    private static function response( array $data, int $status = 200 ) {
        $response = new WP_REST_Response( $data, $status );
        $response->header( 'Cache-Control', 'private, no-store' );
        return $response;
    }

    /** The deployed sender signs the exact UTF-8 body with timestamp + '.' + raw body. */
    public static function authenticate( string $raw, string $timestamp, string $signature, array $connection, ?int $now = null ) {
        $invalid = static function ( int $status = 401 ) { return new WP_Error( 'nova_posting_invalid_notification', 'The notification could not be accepted.', [ 'status' => $status ] ); };
        if ( strlen( $raw ) > self::MAX_EVENT_BYTES ) { return $invalid( 413 ); }
        if ( ! preg_match( '/^[0-9]{1,12}$/D', $timestamp ) || abs( ( $now ?? time() ) - (int) $timestamp ) > self::CLOCK_SKEW_SECONDS || ! preg_match( '/^sha256=[a-f0-9]{64}$/D', $signature ) || ! is_string( $connection['webhook_secret'] ?? null ) || '' === $connection['webhook_secret'] ) { return $invalid(); }
        $expected = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $raw, $connection['webhook_secret'] );
        if ( ! hash_equals( $expected, $signature ) ) { return $invalid(); }
        $event = json_decode( $raw, true, 16 );
        if ( ! is_array( $event ) || count( $event ) !== 4 || count( array_diff( array_keys( $event ), [ 'event', 'content_id', 'site', 'version' ] ) ) || 'content.ready' !== ( $event['event'] ?? null ) || ! is_string( $event['content_id'] ?? null ) || ! preg_match( '/^[0-9]{1,64}$/D', $event['content_id'] ) || ! is_int( $event['version'] ?? null ) || $event['version'] < 1 || ( $event['site'] ?? null ) !== ( $connection['site_id'] ?? null ) ) { return $invalid( 400 ); }
        return $event;
    }

    public static function ready( $request ) {
        $connection = Nova_Bridge_Suite_Posting_Settings::connection();
        $event = self::authenticate( (string) $request->get_body(), (string) $request->get_header( 'x-nova-timestamp' ), (string) $request->get_header( 'x-nova-signature' ), $connection );
        if ( is_wp_error( $event ) ) { return $event; }
        if ( empty( $connection['enabled'] ) ) { return new WP_Error( 'nova_posting_disabled', 'Delivery intake is disabled.', [ 'status' => 503 ] ); }
        if ( ! self::ensure_schema() ) { return new WP_Error( 'nova_posting_storage', 'Discovery storage is unavailable.', [ 'status' => 503 ] ); }
        $woken = ( new Nova_Bridge_Suite_Posting_Discovery( $connection ) )->wake();
        if ( is_wp_error( $woken ) ) { return $woken; }
        self::queue_run();
        // Only the durable wake is acknowledged. Discovery supplies authoritative work identities.
        return self::response( [ 'accepted' => true, 'wake_accepted' => true ], 202 );
    }

    public static function status( $request ) {
        $permission = self::can_admin();
        if ( is_wp_error( $permission ) ) { return $permission; }
        $connection = Nova_Bridge_Suite_Posting_Settings::connection();
        if ( ! empty( $connection['enabled'] ) && ! self::ensure_schema() ) { return new WP_Error( 'nova_posting_storage', 'Discovery storage is unavailable.', [ 'status' => 503 ] ); }
        $store = new Nova_Bridge_Suite_Posting_Jobs();
        $exists = $store->storage_exists(); if ( is_wp_error( $exists ) ) { return $exists; }
        $jobs = $exists ? $store->summaries( $connection['site_id'] ?? '' ) : [];
        if ( is_wp_error( $jobs ) ) { return $jobs; }
        $discovery = self::SCHEMA_VERSION === (int) get_option( self::SCHEMA_OPTION, 0 ) ? $store->discovery_state( $connection['site_id'] ?? '' ) : [];
        $public_state = is_wp_error( $discovery ) ? [ 'last_error' => 'discovery_storage' ] : array_intersect_key( $discovery, array_flip( [ 'cycle_started', 'last_completed', 'next_attempt', 'failures', 'last_error', 'suspended', 'auth_status' ] ) );
        foreach ( $jobs as &$job ) {
            $job['recovery_action'] = self::recovery_action( (string) ( $job['state'] ?? '' ) );
            // Journal creation is first receipt on this installation, not the last retry/update.
            $received = (int) ( $job['created_at'] ?? 0 );
            $job['received_at'] = $received > 0 ? gmdate( 'c', $received ) : '';
            $job['received_at_label'] = $received > 0 ? wp_date( 'Y-m-d H:i:s T', $received ) : '';
            if ( ! is_string( $job['intended_url'] ?? null ) || 'null' === $job['intended_url'] ) { $job['intended_url'] = ''; }
        }
        unset( $job );
        return self::response( [ 'enabled' => ! empty( $connection['enabled'] ), 'paused' => ! empty( $connection['paused'] ), 'next_scheduled' => self::next_scheduled(), 'jobs' => $jobs, 'discovery' => $public_state, 'last_run' => get_option( self::LAST_RUN_OPTION, null ) ] );
    }

    private static function next_scheduled(): ?string {
        $next = wp_next_scheduled( self::CRON_HOOK );
        return $next ? gmdate( 'c', (int) $next ) : null;
    }

    public static function resume( $request ) {
        $permission = self::can_admin();
        if ( is_wp_error( $permission ) ) { return $permission; }
        $connection = Nova_Bridge_Suite_Posting_Settings::connection();
        $result = ( new Nova_Bridge_Suite_Posting_Jobs() )->resume( (int) $request->get_param( 'id' ), $connection['site_id'] ?? '' );
        if ( is_wp_error( $result ) ) { return $result; }
        if ( ! empty( $connection['enabled'] ) ) { self::queue_run(); }
        return self::response( [ 'resumed' => true, 'message' => 'The saved phase is queued for retry while the connection is enabled. An uncertain mutation is reconciled before any new write.' ] );
    }
}
