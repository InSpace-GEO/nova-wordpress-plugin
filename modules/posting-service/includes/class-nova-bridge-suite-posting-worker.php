<?php
/** Durable delivery intake, native-write reconciliation and exact connector-event replay. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-nova-bridge-suite-posting-url-scope.php';
require_once __DIR__ . '/class-nova-bridge-suite-posting-actor.php';
require_once __DIR__ . '/class-nova-bridge-suite-publication-policy.php';
require_once __DIR__ . '/class-nova-bridge-suite-translation-writer.php';
require_once __DIR__ . '/class-nova-bridge-suite-translation-source.php';
require_once __DIR__ . '/class-nova-bridge-suite-term-translation-writer.php';

final class Nova_Bridge_Suite_Posting_Worker {
    private const WRITERS = [
        'static_recipe_v1' => 'Nova_Bridge_Suite_Static_Term_Writer',
        'local_rules' => 'Nova_Bridge_Suite_Rule_Writer',
        'stored_layout_v3' => 'Nova_Bridge_Suite_Stored_Layout_Writer',
        'owned_cpt_v3' => 'Nova_Bridge_Suite_Owned_CPT_Writer',
        'dictionary_translation' => 'Nova_Bridge_Suite_Translation_Writer',
        'dictionary_term_translation' => 'Nova_Bridge_Suite_Term_Translation_Writer',
        'fixed_mapping' => 'Nova_Bridge_Suite_Mapped_Writer',
    ];
    private $connection;
    private $jobs;
    private $client;
    private $configuration;
    private $writer;
    private $initial_writer;
    private $default_dispatch;
    private $policy;
    private $recovery_only = false;

    public function __construct( array $connection, $jobs = null, $client = null, $configuration = null, $writer = 'Nova_Bridge_Suite_Mapped_Writer', $policy = null ) {
        $this->connection = $connection;
        $this->jobs = $jobs ?: new Nova_Bridge_Suite_Posting_Jobs();
        $this->client = $client ?: new Nova_Bridge_Suite_Posting_Client( $connection );
        $this->default_dispatch = null === $configuration && null === $policy && 'Nova_Bridge_Suite_Mapped_Writer' === $writer;
        $this->configuration = $configuration ?: [ __CLASS__, 'configuration_for_snapshot' ];
        $this->policy = $policy ?: [ __CLASS__, 'frozen_policy' ];
        $this->writer = $writer;
        $this->initial_writer = $writer;
    }

    /** An explicit unmapped-site opt-in is a separate route, never failed remote-map fallback. */
    public static function configuration_for_snapshot( array $snapshot, string $site_id ) {
        if ( is_callable( [ 'Nova_Bridge_Suite_Mapping_Sync', 'imported_configuration' ] ) ) {
            $imported = Nova_Bridge_Suite_Mapping_Sync::imported_configuration( $snapshot, $site_id );
            if ( null !== $imported ) { return $imported; }
        }
        if ( class_exists( 'Nova_Bridge_Suite_Owned_CPT_Sync' ) ) {
            $owned = Nova_Bridge_Suite_Owned_CPT_Sync::configuration_for_snapshot( $snapshot, $site_id );
            if ( null !== $owned ) { return $owned; }
        }
        if ( class_exists( 'Nova_Bridge_Suite_Static_Recipe_Adapter' ) ) {
            $static = Nova_Bridge_Suite_Static_Recipe_Adapter::configuration_for_snapshot( $snapshot, $site_id );
            if ( null !== $static ) { return $static; }
        }
        if ( array_key_exists( 'configuration', $snapshot ) && null === $snapshot['configuration'] && class_exists( 'Nova_Bridge_Suite_Content_Rules' ) ) {
            return Nova_Bridge_Suite_Content_Rules::configuration_for_snapshot( $snapshot, $site_id );
        }
        return Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( $snapshot, $site_id );
    }

    public static function frozen_policy( $client, array $snapshot, array $configuration ) {
        // configuration_for_snapshot already validated this revision. Keep that choice if an admin imports a replacement during policy selection.
        if ( ( $configuration['mode'] ?? '' ) === 'stored_layout_v3' ) { return Nova_Bridge_Suite_Stored_Layout_Writer::policy( $snapshot, $configuration ); }
        if ( ( $configuration['mode'] ?? '' ) === 'owned_cpt_v3' ) { return Nova_Bridge_Suite_Owned_CPT_Writer::policy( $snapshot, $configuration ); }
        if ( ( $configuration['mode'] ?? '' ) === 'static_recipe_v1' ) { return Nova_Bridge_Suite_Static_Recipe_Adapter::policy( $snapshot, $configuration ); }
        if ( ( $configuration['mode'] ?? '' ) === 'local_rules' ) { return Nova_Bridge_Suite_Rule_Writer::policy( $snapshot, $configuration ); }
        return Nova_Bridge_Suite_Mapping_Sync::frozen_policy( $client, $snapshot, $configuration );
    }

    /** The optional observer is diagnostic only and cannot interrupt delivery work. */
    public function run( ?callable $observe = null ): void {
        if ( empty( $this->connection['enabled'] ) ) { return; }
        $discovery = ( new Nova_Bridge_Suite_Posting_Discovery( $this->connection, $this->jobs, $this->client ) )->run();
        $status = is_wp_error( $discovery ) ? (int) ( $discovery->get_error_data()['status'] ?? 0 ) : 0;
        if ( is_wp_error( $discovery ) ) { self::observe( $observe, [ 'last_error' => $discovery->get_error_code(), 'discovery_http_status' => $status ] ); }
        if ( 401 === $status ) { return; }
        $this->recovery_only = ! empty( $this->connection['paused'] ) || 403 === $status;
        $started = microtime( true );
        $processed = 0;
        $max_claims = empty( $this->connection['url_scope_enabled'] ) ? 3 : 100;
        for ( $i = 0; $i < $max_claims && $processed < 3 && microtime( true ) - $started < 20; ++$i ) {
            $job = $this->jobs->claim( $this->connection['site_id'], $this->recovery_only );
            if ( is_wp_error( $job ) ) { self::observe( $observe, [ 'last_error' => $job->get_error_code() ] ); break; }
            if ( ! $job ) { break; }
            self::observe( $observe, [ 'jobs_claimed' => $i + 1, 'last_job' => self::job_diagnostic( $job ) ] );
            $result = $this->process( $job );
            if ( is_wp_error( $result ) ) { self::observe( $observe, [ 'last_error' => $result->get_error_code() ] ); }
            elseif ( is_array( $result ) ) { self::observe( $observe, [ 'last_job' => self::job_diagnostic( $result ), 'last_error' => $result['last_error'] ?? '' ] ); }
            // Foreign work must not consume this installation's three publishing slots.
            if ( ! is_array( $result ) || 'skipped' !== ( $result['phase'] ?? '' ) ) { ++$processed; }
            if ( is_array( $result ) && in_array( $result['last_error'] ?? '', [ 'authentication_required', 'site_ineligible' ], true ) ) { break; }
        }
        $this->jobs->prune();
    }

    private static function observe( ?callable $observe, array $summary ): void {
        if ( null !== $observe ) { try { $observe( $summary ); } catch ( Throwable $ignored ) { /* Diagnostics cannot change worker behavior. */ } }
    }

    private static function job_diagnostic( array $job ): array {
        return array_merge( array_intersect_key( $job, array_flip( [ 'id', 'content_id', 'version', 'state', 'phase', 'attempts', 'next_attempt', 'last_error' ] ) ), [ 'delivery_id' => $job['payload']['discovery']['id'] ?? null, 'attempt_id' => $job['payload']['attempt_id'] ?? null ] );
    }

    private function block( array $job, string $code ) {
        return $this->jobs->save( $job, [ 'state' => 'blocked', 'last_error' => substr( $code, 0, 100 ) ] );
    }

    private function retry( array $job, string $code, string $retry_after = '' ) {
        if ( $job['attempts'] >= 8 ) { return $this->block( $job, $code . '_retry_limit' ); }
        $delay = min( 3600, 30 * ( 2 ** max( 0, $job['attempts'] - 1 ) ) );
        if ( '' !== $retry_after ) {
            $seconds = ctype_digit( $retry_after ) ? (float) $retry_after : max( 0, ( strtotime( $retry_after ) ?: time() ) - time() );
            if ( $seconds > 86400 ) { return $this->block( $job, 'retry_after_out_of_bounds' ); }
            $delay = max( $delay, (int) $seconds );
        }
        return $this->jobs->save( $job, [ 'state' => 'ready', 'next_attempt' => time() + $delay, 'last_error' => substr( $code, 0, 100 ) ] );
    }

    private function paused( array $job ) {
        return $this->jobs->save( $job, [ 'state' => 'ready', 'next_attempt' => time() + 300, 'attempts' => 0, 'last_error' => 'paused' ] );
    }

    private function wait_for_original( array $job, array $snapshot ) {
        if ( ! isset( $snapshot['url_family'] ) ) { return $this->retry( $job, 'nova_translation_source_pending' ); }
        // A dependency is not a failed publication attempt. A verified original commit
        // wakes only this dependency; normal retries and unrelated blocked work keep their rules.
        $waiting = $this->jobs->save( $job, [ 'state' => 'blocked', 'attempts' => 0, 'next_attempt' => 0, 'last_error' => 'nova_translation_source_pending' ] );
        if ( is_wp_error( $waiting ) ) { return $waiting; }
        // A frozen pre-upgrade original does not hold a group lock. Recheck after
        // registering the dependency so its simultaneous commit cannot lose this wake.
        $ready = Nova_Bridge_Suite_Translation_Writer::policy( $snapshot, $job['payload']['configuration'] );
        if ( is_array( $ready ) ) {
            $original = array_replace( $snapshot, [ 'url_id' => $snapshot['url_family']['primary_url_id'], 'url' => $snapshot['url_family']['primary_url'], 'language' => $snapshot['url_family']['primary_language'] ] );
            if ( Nova_Bridge_Suite_Publication_Policy::test_url( $snapshot['url'] ?? null ) && 'draft' === get_post_status( $ready['reference']['reference_id'] ) ) { $original['draft_demo'] = true; }
            $woken = $this->jobs->wake_translation_dependents( $original );
            if ( is_wp_error( $woken ) ) { return $woken; }
        }
        return $waiting;
    }

    private function remote_error( array $job, WP_Error $error ) {
        $data = $error->get_error_data(); $status = (int) ( $data['status'] ?? 0 );
        if ( 401 === $status || 403 === $status ) {
            ( new Nova_Bridge_Suite_Posting_Discovery( $this->connection, $this->jobs, $this->client ) )->suspend( $error );
            return $this->block( $job, 401 === $status ? 'authentication_required' : 'site_ineligible' );
        }
        if ( 410 === $status ) {
            // Gone is terminal authority, not a reason to replay an old receipt.
            // Keep native plans/results and exact pending bytes as audit evidence.
            return $this->save_payload( $job, [ 'retired' => [ 'reason' => 'remote_gone', 'attempt_id' => $job['payload']['attempt_id'] ?? null ] ], 'superseded', [ 'state' => 'complete', 'next_attempt' => 0, 'last_error' => 'delivery_superseded' ] );
        }
        if ( in_array( $status, [ 409, 412 ], true ) ) { return $this->block( $job, 'remote_identity_conflict' ); }
        if ( 404 === $status || 429 === $status || $status >= 500 || 'nova_posting_transport' === $error->get_error_code() ) {
            return $this->retry( $job, $error->get_error_code(), (string) ( $data['retry_after'] ?? '' ) );
        }
        return $this->block( $job, $error->get_error_code() );
    }

    private function save_payload( array $job, array $payload, string $phase, array $extra = [] ) {
        $saved = $this->jobs->save( $job, array_merge( [ 'phase' => $phase, 'payload' => array_merge( $job['payload'], $payload ), 'last_error' => '' ], $extra ) );
        if ( is_wp_error( $saved ) && 'nova_posting_journal_size' === $saved->get_error_code() ) { $this->block( $job, 'journal_size' ); }
        return $saved;
    }

    private static function path( array $job ): string {
        return '/deliveries/' . rawurlencode( $job['payload']['discovery']['id'] );
    }

    private function fetch( array $job ) {
        // The attempt header can change without changing frozen bytes: always request full 200.
        $reply = $this->client->site_request( 'GET', self::path( $job ) );
        if ( is_wp_error( $reply ) ) { return $reply; }
        $snapshot = Nova_Bridge_Suite_Posting_Protocol::snapshot( $reply, $job );
        if ( is_wp_error( $snapshot ) ) { return $snapshot; }
        if ( isset( $job['payload']['snapshot_raw'] ) && $reply['raw_body'] !== $job['payload']['snapshot_raw'] ) {
            // The unified endpoint can project historical deliveries differently.
            // A final retained receipt may still replay its exact old bytes when
            // the same attempt remains current; this never authorizes a new write.
            if ( 'event_pending' === $job['phase'] && 'complete' === ( $job['payload']['event_continue'] ?? null ) &&
                in_array( $job['payload']['pending_event']['kind'] ?? null, [ 'publication_succeeded', 'publication_failed' ], true ) &&
                $reply['attempt_id'] === ( $job['payload']['attempt_id'] ?? null ) ) {
                return array_intersect_key( $job['payload'], array_flip( [ 'content', 'snapshot_raw', 'delivery_etag', 'attempt_id' ] ) );
            }
            return Nova_Bridge_Suite_Posting_Protocol::error( 'snapshot_changed', 'An immutable delivery changed its bytes.' );
        }
        return [ 'content' => $snapshot, 'snapshot_raw' => $reply['raw_body'], 'delivery_etag' => $reply['etag'], 'attempt_id' => $reply['attempt_id'] ];
    }

    /** New remote authority replaces receipts, never the frozen native operation. */
    private function check_attempt( array $job, array $fetched ) {
        $old = $job['payload']['attempt_id'] ?? null;
        if ( $old === $fetched['attempt_id'] ) {
            return $this->save_payload( $job, [], 'committed', [ 'state' => 'complete' ] );
        }
        $payload = $job['payload'];
        $history = $payload['prior_attempts'] ?? [];
        $history[] = [ 'phase' => $job['phase'], 'operation_id' => $job['operation_id'], 'actor_user_id' => $payload['context']['actor_user_id'] ?? $payload['actor_user_id'] ?? null ] + array_intersect_key( $payload, array_flip( [ 'attempt_id', 'events', 'pending_event', 'event_bytes', 'event_sha256', 'event_continue', 'native_absence_proof', 'result', 'failure_code' ] ) );
        // A retained plan may already own a reserved/committed page. Reconcile it
        // under the target lock instead of planning a second create operation.
        if ( isset( $payload['plan'] ) ) {
            $payload['attempt_resume_phase'] = isset( $payload['result'] ) ? 'committed' : 'applying';
        } else {
            if ( true === ( $payload['native_absence_proof']['no_native_commit'] ?? false ) ) {
                unset( $payload['actor_user_id'] );
            } elseif ( isset( $payload['context']['actor_user_id'] ) ) {
                $payload['actor_user_id'] = $payload['context']['actor_user_id'];
            }
            foreach ( [ 'context', 'configuration', 'selected_policy', 'native_writer', 'result' ] as $key ) { unset( $payload[$key] ); }
        }
        foreach ( [ 'events', 'pending_event', 'event_bytes', 'event_sha256', 'event_continue', 'native_absence_proof', 'failure_code', 'failure_message', 'native_error_detail' ] as $key ) { unset( $payload[ $key ] ); }
        $payload = array_merge( $payload, $fetched, [ 'prior_attempts' => $history ] );
        return $this->jobs->save( $job, [ 'payload' => $payload, 'phase' => 'validated', 'attempts' => 0, 'next_attempt' => 0, 'last_error' => '' ] );
    }

    /** Revalidate after locking/planning and immediately before effects. */
    private function current_authority( array $job ) {
        $fetched = $this->fetch( $job );
        if ( is_wp_error( $fetched ) ) { return $this->remote_error( $job, $fetched ); }
        if ( $fetched['attempt_id'] !== ( $job['payload']['attempt_id'] ?? null ) ) {
            $next = $this->check_attempt( $job, $fetched );
            return is_wp_error( $next ) ? $next : $this->jobs->save( $next, [ 'state' => 'ready', 'next_attempt' => 0 ] );
        }
        return true;
    }

    private static function publication_lock( array $job, array $snapshot ): string {
        // URL identity is shared across delivery versions and writer types. The
        // canonical URL ID also covers relative versus absolute input spellings.
        return 'publication:' . $job['site_id'] . ':' . $snapshot['url_id'];
    }

    private function actor_id( array $job ): int {
        return (int) ( $job['payload']['context']['actor_user_id'] ?? $job['payload']['actor_user_id'] ?? $this->connection['actor_user_id'] ?? 0 );
    }

    /** Fixed mappings can point two URLs at the same native post. Only URL-routed plans can be separated. */
    private function writes_delivery_url( array $job, array $source ): bool {
        $mode = $job['payload']['native_writer'] ?? null;
        if ( null === $mode ) {
            $configuration = $job['payload']['configuration'] ?? call_user_func( $this->configuration, $source, $job['site_id'] );
            if ( ! is_array( $configuration ) ) { return false; }
            $mode = $configuration['mode'] ?? null;
        }
        return in_array( $mode, [ 'stored_layout_v3', 'owned_cpt_v3', 'dictionary_translation' ], true );
    }

    private function reconcile_predecessors( array $job, array $snapshot ) {
        if ( ! method_exists( $this->jobs, 'predecessors' ) ) { return true; }
        $older = $this->jobs->predecessors( $job, $snapshot );
        if ( is_wp_error( $older ) ) { return $this->retry( $job, $older->get_error_code() ); }
        foreach ( $older as $previous ) {
            $source = $previous['payload']['content'] ?? [];
            if ( Nova_Bridge_Suite_Posting_Url_Scope::independent_translations( $source, $snapshot )
                && $this->writes_delivery_url( $previous, $source ) && $this->writes_delivery_url( $job, $snapshot ) ) { continue; }
            if ( ( $source['content_item_id'] ?? null ) !== $snapshot['content_item_id'] || ( $source['version_number'] ?? PHP_INT_MAX ) >= $snapshot['version_number'] ) { return $this->block( $job, 'other_mutation_unreconciled' ); }
            $reply = $this->client->site_request( 'GET', self::path( $previous ) );
            if ( ! is_wp_error( $reply ) || 410 !== (int) ( $reply->get_error_data()['status'] ?? 0 ) ) {
                return $this->retry( $job, 'previous_delivery_not_retired' );
            }
            $evidence = [ 'no_native_commit' => true ];
            if ( isset( $previous['payload']['plan'] ) ) {
                $writer = $this->default_dispatch ? ( self::WRITERS[$previous['payload']['native_writer'] ?? ''] ?? null ) : $this->initial_writer;
                if ( ! is_callable( [ $writer, 'recover' ] ) ) { return $this->block( $job, 'previous_writer_unavailable' ); }
                $method = is_callable( [ $writer, 'supersede' ] ) ? 'supersede' : 'recover';
                $previous_actor = $this->actor_id( $previous );
                if ( ! Nova_Bridge_Suite_Posting_Actor::eligible( $previous_actor ) ) { return $this->block( $job, 'previous_publishing_user_unavailable' ); }
                $current = get_current_user_id();
                wp_set_current_user( $previous_actor );
                try { $evidence = call_user_func( [ $writer, $method ], $previous['payload']['plan'], $previous['operation_id'] ); }
                finally { wp_set_current_user( $current ); }
                if ( is_wp_error( $evidence ) || ! is_array( $evidence ) ||
                    ( 'not_committed' === ( $evidence['state'] ?? null ) && true !== ( $evidence['no_native_commit'] ?? false ) ) ) {
                    return $this->block( $job, 'previous_native_unreconciled' );
                }
            }
            $retired = $this->jobs->retire_predecessor( $previous, $evidence );
            if ( is_wp_error( $retired ) ) { return $this->retry( $job, $retired->get_error_code() ); }
        }
        return true;
    }

    /** Public to allow deterministic process-loss tests at journal/native boundaries. */
    public function process( array $job ) {
        if ( ! class_exists( 'Nova_Bridge_Suite_Recipe_Term_Fields' ) ) { return $this->process_job( $job ); }
        return Nova_Bridge_Suite_Recipe_Term_Fields::for_delivery(
            function () use ( $job ) { return $this->process_job( $job ); },
            static function ( array $field ) use ( $job ) {
                error_log( 'nova_posting_field_skipped ' . json_encode( [
                    'job_id' => $job['id'] ?? null, 'site_id' => $job['site_id'] ?? null,
                    'content_id' => $job['content_id'] ?? null, 'version' => $job['version'] ?? null,
                    'delivery_id' => $job['payload']['discovery']['id'] ?? null,
                    'operation_id' => $job['operation_id'] ?? null,
                ] + $field ) );
            }
        );
    }
    private function process_job( array $job ) {
        if ( 'complete' === $job['state'] && 'skipped' === $job['phase'] ) { return $job; }
        if ( 'superseded' === $job['phase'] || isset( $job['payload']['retired'] ) ) { return $job; }
        $this->writer = $this->initial_writer;
        $retained_writer = $job['payload']['native_writer'] ?? '';
        if ( $this->default_dispatch && '' !== $retained_writer && 'fixed_mapping' !== $retained_writer ) {
            $writer = self::WRITERS[$retained_writer] ?? null;
            $mode = $job['payload']['configuration']['mode'] ?? '';
            $matches = 'dictionary_translation' === $retained_writer ? in_array( $mode, [ 'stored_layout_v3', 'owned_cpt_v3' ], true )
                : ( 'dictionary_term_translation' === $retained_writer ? 'static_recipe_v1' === $mode : $mode === $retained_writer );
            if ( ! $writer || ! $matches || ( isset( $job['payload']['plan'] ) && ( $job['payload']['plan']['format'] ?? '' ) !== $writer::FORMAT ) ) { return $this->block( $job, 'native_writer_identity' ); }
            $this->writer = $writer;
        }
        if ( empty( $this->connection['enabled'] ) ) { return $this->block( $job, 'connection_disabled' ); }
        if ( $job['site_id'] !== ( $this->connection['site_id'] ?? null ) ) { return $this->block( $job, 'installation_mismatch' ); }
        if ( ( $job['payload']['protocol'] ?? null ) !== Nova_Bridge_Suite_Posting_Protocol::SNAPSHOT ) { return $this->block( $job, 'legacy_protocol_requires_review' ); }
        if ( ( $job['payload']['business_result_id'] ?? null ) !== $job['operation_id'] || ! Nova_Bridge_Suite_Posting_Protocol::uuid( $job['operation_id'] ) ) { return $this->block( $job, 'operation_identity_invalid' ); }
        $lock = 'delivery:' . $job['site_id'] . ':' . $job['content_id'];
        if ( is_wp_error( $this->jobs->lock( $lock ) ) ) { return $this->retry( $job, 'delivery_busy' ); }
        $target_lock = null; $publication_lock = null; $group_lock = null; $previous_user = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
        try {
            $actor = $this->actor_id( $job );
            wp_set_current_user( $actor );
            if ( ( $this->recovery_only || ! empty( $this->connection['paused'] ) ) && ! in_array( $job['phase'], [ 'applying', 'committed', 'event_pending' ], true ) ) { return $this->paused( $job ); }
            $fetched = $this->fetch( $job );
            if ( is_wp_error( $fetched ) ) { return $this->remote_error( $job, $fetched ); }
            // Check new work before any receipt, predecessor reconciliation or mapping
            // lookup. Retained receipts/native plans always keep their recovery path.
            $untouched = ! array_intersect( [ 'events', 'pending_event', 'event_bytes', 'plan', 'result', 'prior_attempts' ], array_keys( $job['payload'] ) );
            if ( $untouched && ( in_array( $job['phase'], [ 'accepted', 'validated' ], true ) || ! empty( $job['payload']['scope_skipped'] ) ) ) {
                $owns = Nova_Bridge_Suite_Posting_Url_Scope::owns( $this->connection, $fetched['content']['url'] ?? null, $fetched['content']['language'] ?? '' );
                if ( is_wp_error( $owns ) ) { return $this->block( $job, $owns->get_error_code() ); }
                if ( ! $owns ) { return $this->save_payload( $job, $fetched + [ 'scope_skipped' => true ], 'skipped', [ 'state' => 'complete', 'next_attempt' => 0 ] ); }
                if ( ! empty( $job['payload']['scope_skipped'] ) ) {
                    // A fresh discovery checkpoint may re-evaluate an earlier local
                    // skip after routing changed. It has no receipt/native authority.
                    $payload = array_merge( $job['payload'], $fetched ); unset( $payload['scope_skipped'] );
                    $job = $this->jobs->save( $job, [ 'payload' => $payload, 'phase' => 'validated' ] );
                    if ( is_wp_error( $job ) ) { return $job; }
                }
            }
            $publication_lock = self::publication_lock( $job, $fetched['content'] );
            if ( is_wp_error( $this->jobs->lock( $publication_lock ) ) ) { $publication_lock = null; return $this->retry( $job, 'target_busy' ); }
            $group_lock = Nova_Bridge_Suite_Translation_Source::group_lock( $fetched['content'] );
            if ( null !== $group_lock && is_wp_error( $this->jobs->lock( $group_lock ) ) ) { $group_lock = null; return $this->retry( $job, 'target_busy' ); }
            if ( 'attempt_check' === $job['phase'] || ( isset( $job['payload']['attempt_id'] ) && $job['payload']['attempt_id'] !== $fetched['attempt_id'] ) ) {
                $job = $this->check_attempt( $job, $fetched );
                if ( is_wp_error( $job ) || 'running' !== $job['state'] ) { return $job; }
                $actor = $this->actor_id( $job );
                wp_set_current_user( $actor );
            }
            $previous = $this->reconcile_predecessors( $job, $fetched['content'] );
            if ( true !== $previous ) { return $previous; }
            if ( 'event_pending' === $job['phase'] ) {
                $job = $this->drain_event( $job );
                if ( is_wp_error( $job ) || 'running' !== $job['state'] || 'received' !== $job['phase'] ) { return $job; }
            }
            $paused = $this->recovery_only || ! empty( $this->connection['paused'] );
            if ( $paused && ! in_array( $job['phase'], [ 'applying', 'committed' ], true ) ) { return $this->paused( $job ); }
            if ( 'accepted' === $job['phase'] ) {
                $job = $this->save_payload( $job, $fetched, 'validated' );
                if ( is_wp_error( $job ) ) { return $job; }
            }
            $snapshot = $fetched['content'];
            if ( 'validated' === $job['phase'] ) {
                $committed_attempt = isset( $job['payload']['attempt_resume_phase'], $job['payload']['plan'], $job['payload']['result'] );
                if ( ! $committed_attempt && ! Nova_Bridge_Suite_Posting_Actor::eligible( $actor ) ) {
                    return $this->jobs->save( $job, [ 'state' => 'ready', 'next_attempt' => time() + 300, 'attempts' => 0, 'last_error' => 'publishing_user_unavailable' ] );
                }
                $job = $this->queue_event( $job, $snapshot, 'received_complete', [], 'received', [ 'actor_user_id' => $actor ] );
                if ( is_wp_error( $job ) || 'running' !== $job['state'] || 'received' !== $job['phase'] ) { return $job; }
            }
            if ( 'received' === $job['phase'] && isset( $job['payload']['attempt_resume_phase'], $job['payload']['plan'] ) ) {
                // Older journals also labeled a committed replacement "applying".
                $phase = isset( $job['payload']['result'] ) ? 'committed' : 'applying';
                $payload = $job['payload']; unset( $payload['attempt_resume_phase'] );
                $job = $this->jobs->save( $job, [ 'payload' => $payload, 'phase' => $phase ] );
                if ( is_wp_error( $job ) ) { return $job; }
            }
            $actor_eligible = Nova_Bridge_Suite_Posting_Actor::eligible( $actor );
            if ( ! $actor_eligible && 'committed' !== $job['phase'] ) {
                return 'received' === $job['phase'] ? $this->native_failure( $job, $snapshot, 'publishing_user_unavailable', [ 'no_native_commit' => true, 'boundary' => 'before_apply' ] ) : $this->block( $job, 'publishing_user_unavailable' );
            }
            if ( 'received' === $job['phase'] ) {
                // Once selected, a local rule revision belongs to this job. A later binding edit
                // cannot reinterpret a retained delivery after a crash or a delayed draft publish.
                $retained_local = ( $job['payload']['configuration']['mode'] ?? '' ) === 'local_rules';
                $retained_layout = ( $job['payload']['configuration']['mode'] ?? '' ) === 'stored_layout_v3' && isset( $job['payload']['selected_policy'], $job['payload']['context'] );
                // A fresh authenticated attempt can clear a previously selected writer before planning.
                $retained_writer = $job['payload']['native_writer'] ?? '';
                $retained_translation = in_array( $retained_writer, [ 'dictionary_translation', 'dictionary_term_translation' ], true );
                $configuration = $retained_local || $retained_translation || $retained_layout ? $job['payload']['configuration'] : call_user_func( $this->configuration, $snapshot, $job['site_id'] );
                if ( is_wp_error( $configuration ) ) { return $this->native_failure( $job, $snapshot, $configuration->get_error_code(), [ 'no_native_commit' => true, 'boundary' => 'before_apply' ], $configuration->get_error_message() ); }
                $native_snapshot = Nova_Bridge_Suite_Posting_Protocol::writer_snapshot( $snapshot, $configuration );
                if ( is_wp_error( $native_snapshot ) ) { return $this->native_failure( $job, $snapshot, $native_snapshot->get_error_code(), [ 'no_native_commit' => true, 'boundary' => 'before_apply' ], $native_snapshot->get_error_message() ); }
                // Only the transient writer view contains private local template details.
                // The journal, ETag, attempt authority and receipts keep the exact wire body.
                $snapshot = $native_snapshot;
                $local_rules = ( $configuration['mode'] ?? '' ) === 'local_rules';
                $static_term = ( $configuration['mode'] ?? '' ) === 'static_recipe_v1';
                $stored_layout = ( $configuration['mode'] ?? '' ) === 'stored_layout_v3';
                $owned_cpt = ( $configuration['mode'] ?? '' ) === 'owned_cpt_v3';
                if ( $local_rules && ( ! array_key_exists( 'configuration', $snapshot ) || null !== $snapshot['configuration'] ) ) { return $this->block( $job, 'local_rules_remote_configuration' ); }
                $writer_name = $configuration['mode'] ?? '';
                if ( ! isset( self::WRITERS[$writer_name] ) || 'dictionary_translation' === $writer_name ) { $writer_name = 'fixed_mapping'; }
                if ( $this->default_dispatch && ( '' === $retained_writer || $retained_translation || ( ! $stored_layout && ! $owned_cpt ) ) ) {
                    $provider = Nova_Bridge_Suite_Translation_Writer::provider( $snapshot['language'] );
                    if ( is_wp_error( $provider ) ) { return $this->native_failure( $job, $snapshot, $provider->get_error_code(), [ 'no_native_commit' => true, 'boundary' => 'before_apply' ], $provider->get_error_message() ); }
                    if ( $retained_translation || null !== $provider ) {
                        if ( ! $stored_layout && ! $owned_cpt && ! $static_term ) { return $this->native_failure( $job, $snapshot, 'nova_translation_mapping_unsupported', [ 'no_native_commit' => true, 'boundary' => 'before_apply' ] ); }
                        $writer_name = $static_term ? 'dictionary_term_translation' : 'dictionary_translation';
                        if ( ! $retained_translation ) {
                            $job = $this->save_payload( $job, [ 'configuration' => $configuration, 'native_writer' => $writer_name ], 'received' );
                            if ( is_wp_error( $job ) ) { return $job; }
                        }
                    }
                }
                if ( $this->default_dispatch ) { $this->writer = self::WRITERS[$writer_name]; }
                $shared_translation = in_array( $writer_name, [ 'dictionary_translation', 'dictionary_term_translation' ], true );
                if ( $shared_translation ) {
                    $selected = call_user_func( [ $this->writer, 'policy' ], $snapshot, $configuration );
                } else {
                    $selected = ( $retained_local || $retained_layout ) && isset( $job['payload']['selected_policy'] ) ? $job['payload']['selected_policy'] : call_user_func( $this->policy, $this->client, $snapshot, $configuration );
                }
                if ( is_wp_error( $selected ) && 'nova_translation_source_pending' === $selected->get_error_code() ) { return $this->wait_for_original( $job, $snapshot ); }
                if ( is_wp_error( $selected ) ) { return $this->native_failure( $job, $snapshot, $selected->get_error_code(), [ 'no_native_commit' => true, 'boundary' => 'before_apply' ], $selected->get_error_message() ); }
                if ( ! $static_term && ! $shared_translation && Nova_Bridge_Suite_Publication_Policy::test_url( $snapshot['url'] ?? null ) ) { $selected['routing']['publication'] = 'draft'; }
                $reference = $selected['reference'] ?? []; $routing = $selected['routing'] ?? [];
                $operation = $routing['operation'] ?? null; $target = $reference['reference_id'] ?? 0;
                $source_free = ( $local_rules || $static_term || $stored_layout || $owned_cpt ) && 'create' === $operation && 0 === $target;
                $reference_type = $static_term ? 'term' : 'post';
                $category_update = $static_term && ( $configuration['projection']['format'] ?? '' ) === 'nova.category-recipe/v1';
                if ( ( ! $source_free && ( 'update' !== $operation || $target < 1 ) ) || $reference_type !== ( $reference['reference_type'] ?? null ) || ! is_int( $target ) || ( $static_term && ! $source_free && ! $category_update ) ) { return $this->native_failure( $job, $snapshot, 'routing_unavailable', [ 'no_native_commit' => true ] ); }
                $publication = $routing['publication'] ?? 'preserve';
                if ( ! in_array( $publication, [ 'preserve', 'publish', 'draft' ], true ) || ( $static_term && 'publish' !== $publication ) ) { return $this->native_failure( $job, $snapshot, 'publication_policy_unavailable', [ 'no_native_commit' => true ] ); }
                $desired = 'preserve' === $publication ? ( 'create' === $operation ? 'draft' : get_post_status( $target ) ) : $publication;
                $context = [ 'site_id' => $job['site_id'], 'delivery_id' => $snapshot['id'], 'content_id' => $snapshot['content_item_id'], 'content_item_id' => $snapshot['content_item_id'], 'content_item_version_id' => $snapshot['content_item_version_id'], 'url_id' => $snapshot['url_id'], 'version' => $snapshot['version_number'], 'operation_id' => $job['operation_id'], 'business_result_id' => $job['operation_id'], 'target_post_id' => $target, 'operation' => $operation, 'desired_status' => $desired, 'actor_user_id' => $actor, 'attempt_id' => $fetched['attempt_id'], 'source_sha256' => $snapshot['source_sha256'], 'snapshot_sha256' => hash( 'sha256', $fetched['snapshot_raw'] ), 'delivery_etag' => $fetched['delivery_etag'], 'language' => $snapshot['language'] ];
                $job = $this->save_payload( $job, [ 'configuration' => $configuration, 'selected_policy' => $selected, 'context' => $context, 'native_writer' => $writer_name ], 'received' );
                if ( is_wp_error( $job ) ) { return $job; }
                $plan = call_user_func( [ $this->writer, 'plan' ], $snapshot, $configuration, $context );
                if ( is_wp_error( $plan ) && 'nova_translation_source_pending' === $plan->get_error_code() ) { return $this->wait_for_original( $job, $snapshot ); }
                if ( is_wp_error( $plan ) ) { return $this->native_failure( $job, $snapshot, $plan->get_error_code(), [ 'no_native_commit' => true, 'boundary' => 'before_apply' ], $plan->get_error_message() ); }
                if ( ! is_array( $plan ) ) { return $this->block( $job, 'plan_invalid' ); }
                if ( in_array( $writer_name, [ 'dictionary_translation', 'dictionary_term_translation' ], true ) ) {
                    $owns = Nova_Bridge_Suite_Posting_Url_Scope::owns( $this->connection, $plan['target']['target_url'], $snapshot['language'] );
                    if ( is_wp_error( $owns ) ) { return $this->block( $job, $owns->get_error_code() ); }
                    if ( ! $owns ) { return $this->native_failure( $job, $snapshot, 'nova_translation_url_scope', [ 'no_native_commit' => true, 'boundary' => 'before_apply' ], 'The resolved localized URL is outside this installation\'s publishing scope.' ); }
                }
                $job = $this->save_payload( $job, [ 'plan' => $plan ], 'planned', [ 'target_id' => $target ] );
                if ( is_wp_error( $job ) ) { return $job; }
            }
            if ( ! in_array( $job['phase'], [ 'planned', 'applying', 'committed' ], true ) ) { return $this->block( $job, 'journal_phase_invalid' ); }
            $static_term = in_array( $job['payload']['native_writer'] ?? '', [ 'static_recipe_v1', 'dictionary_term_translation' ], true );
            $weglot_translation = ( $job['payload']['native_writer'] ?? '' ) === 'dictionary_translation'
                && Nova_Bridge_Suite_Publication_Policy::allows_shared_test( $job['payload']['plan']['provider'] ?? null );
            if ( ! $static_term && ! $weglot_translation && Nova_Bridge_Suite_Publication_Policy::test_url( $snapshot['url'] ?? null ) && ( $job['payload']['plan']['desired_status'] ?? $job['payload']['plan']['context']['desired_status'] ?? '' ) !== 'draft' ) {
                return $this->block( $job, 'test_delivery_requires_draft_plan' );
            }
            $native_id_key = $static_term ? 'term_id' : 'post_id';
            $target_lock = $static_term ? 'term-url:' . $job['site_id'] . ':' . hash( 'sha256', $job['payload']['plan']['target']['target_url'] ) : ( ( $job['payload']['native_writer'] ?? '' ) === 'local_rules'
                ? 'rule-url:' . $job['site_id'] . ':' . $snapshot['url_id']
                : ( in_array( $job['payload']['native_writer'] ?? '', [ 'stored_layout_v3', 'owned_cpt_v3' ], true ) ? 'layout-url:' . $job['site_id'] . ':' . hash( 'sha256', $snapshot['url'] ) : 'target:' . $job['site_id'] . ':' . $job['target_id'] ) );
            if ( is_wp_error( $this->jobs->lock( $target_lock ) ) ) { $target_lock = null; return $this->retry( $job, 'target_busy' ); }
            $uncertain = $this->jobs->uncertain_target( $job );
            if ( is_wp_error( $uncertain ) ) { return $this->retry( $job, 'journal_unavailable' ); }
            if ( $uncertain ) { return $this->block( $job, 'other_mutation_unreconciled' ); }
            $authority = $this->current_authority( $job );
            if ( true !== $authority ) { return $authority; }
            if ( 'planned' === $job['phase'] ) {
                $job = $this->save_payload( $job, [], 'applying' );
                if ( is_wp_error( $job ) ) { return $job; }
                $authority = $this->current_authority( $job );
                if ( true !== $authority ) { return $authority; }
                $result = call_user_func( [ $this->writer, 'apply' ], $job['payload']['plan'], $job['operation_id'] );
            } elseif ( 'applying' === $job['phase'] ) {
                $result = call_user_func( [ $this->writer, 'recover' ], $job['payload']['plan'], $job['operation_id'] );
                if ( is_array( $result ) && 'not_committed' === ( $result['state'] ?? null ) && true === ( $result['safe_to_apply'] ?? null ) ) {
                    if ( $paused ) { return $this->paused( $job ); }
                    $authority = $this->current_authority( $job );
                    if ( true !== $authority ) { return $authority; }
                    $result = call_user_func( [ $this->writer, 'apply' ], $job['payload']['plan'], $job['operation_id'] );
                }
            } else { $result = $job['payload']['result'] ?? null; }
            if ( is_wp_error( $result ) ) {
                $job = $this->retain_native_error( $job, $result );
                if ( is_wp_error( $job ) ) { return $job; }
                if ( 'nova_translation_busy' === $result->get_error_code() ) { return $this->retry( $job, 'nova_translation_busy' ); }
                if ( 'nova_writer_ambiguous_commit' === $result->get_error_code() ) { return $this->retry( $job, 'native_commit_reconciliation' ); }
                $proof = call_user_func( [ $this->writer, 'recover' ], $job['payload']['plan'], $job['operation_id'] );
                if ( is_array( $proof ) && 'not_committed' === ( $proof['state'] ?? null ) && true === ( $proof['no_native_commit'] ?? false ) ) { return $this->native_failure( $job, $snapshot, $proof['failure_code'] ?? $result->get_error_code(), $proof, $result->get_error_message() ); }
                return is_array( $proof ) && ! empty( $proof[$native_id_key] ) ? $this->retry( $job, 'native_commit_reconciliation' ) : $this->block( $job, $result->get_error_code() );
            }
            if ( is_array( $result ) && 'not_committed' === ( $result['state'] ?? null ) && true === ( $result['no_native_commit'] ?? false ) && false === ( $result['safe_to_apply'] ?? null ) ) { return $this->native_failure( $job, $snapshot, $result['failure_code'] ?? 'native_target_unavailable', $result ); }
            if ( ! is_array( $result ) || empty( $result[$native_id_key] ) ) { return $this->block( $job, 'mutation_result_unverified' ); }
            if ( 'committed' !== $job['phase'] ) {
                $job = $this->save_payload( $job, [ 'result' => $result, 'native_error_detail' => '' ], 'committed', [ 'target_id' => (int) $result[$native_id_key] ] );
                if ( is_wp_error( $job ) ) { return $job; }
            }
            if ( $actor_eligible && is_callable( [ $this->writer, 'finish' ] ) ) {
                $authority = $this->current_authority( $job );
                if ( true !== $authority ) { return $authority; }
                $result = call_user_func( [ $this->writer, 'finish' ], $job['payload']['plan'], $result );
                if ( is_wp_error( $result ) ) { $saved = $this->retain_native_error( $job, $result ); return is_wp_error( $saved ) ? $saved : $this->retry( $saved, $result->get_error_code() ); }
                $job = $this->save_payload( $job, [ 'result' => $result ], 'committed' );
                if ( is_wp_error( $job ) ) { return $job; }
            }
            // An existing native commitment can be attested after its actor leaves.
            // Only read back the result: finish/recover can still change native content.
            $verified = call_user_func( [ $this->writer, 'verify' ], $job['payload']['plan'], $result, ! $actor_eligible );
            if ( is_wp_error( $verified ) ) { $saved = $this->retain_native_error( $job, $verified ); return is_wp_error( $saved ) ? $saved : $this->retry( $saved, $verified->get_error_code() ); }
            if ( ! is_array( $verified ) || (int) ( $verified[$native_id_key] ?? 0 ) !== (int) $result[$native_id_key] || ! in_array( $verified['cms_post_status'] ?? null, [ 'draft', 'publish', 'future', 'private', 'pending' ], true ) ) { return $this->block( $job, 'verification_result_invalid' ); }
            if ( ! $actor_eligible && ( ! empty( $verified['derived_pending'] ) || 'complete' !== ( $verified['state'] ?? 'complete' ) ) ) { return $this->block( $job, 'publishing_user_unavailable' ); }
            $draft_demo = 'draft' === $verified['cms_post_status'] && Nova_Bridge_Suite_Publication_Policy::test_url( $snapshot['url'] ?? null );
            if ( ( 'publish' === $verified['cms_post_status'] || $draft_demo ) && Nova_Bridge_Suite_Translation_Source::is_provider_original( $snapshot ) && method_exists( $this->jobs, 'wake_translation_dependents' ) ) {
                $woken = $this->jobs->wake_translation_dependents( $draft_demo ? array_replace( $snapshot, [ 'draft_demo' => true ] ) : $snapshot );
                if ( is_wp_error( $woken ) ) { return $this->retry( $job, $woken->get_error_code() ); }
            }
            // In the initial webhook scope, success attests the verified native save,
            // including a draft or scheduled post. It does not attest public visibility.
            $job = $this->save_payload( $job, [ 'result' => $verified ], 'committed' );
            if ( is_wp_error( $job ) ) { return $job; }
            $publication_ref = $static_term ? $verified['publication_ref'] : 'wordpress:post:' . $verified['post_id'] . ';operation:' . $job['operation_id'];
            $evidence = [ 'published_at' => Nova_Bridge_Suite_Posting_Protocol::now(), 'publication_ref' => $publication_ref ];
            $publication = self::publication_report( $snapshot, $verified, $static_term, $job['payload']['native_writer'] ?? '', $job['payload']['plan'] ?? [] );
            if ( null !== $publication ) { $evidence['publication'] = $publication; }
            return $this->queue_event( $job, $snapshot, 'publication_succeeded', $evidence, 'complete' );
        } catch ( Throwable $error ) {
            return $this->retry( $job, 'worker_interrupted' );
        } finally {
            if ( function_exists( 'wp_set_current_user' ) ) { wp_set_current_user( $previous_user ); }
            if ( null !== $target_lock ) { $this->jobs->unlock( $target_lock ); }
            if ( null !== $group_lock ) { $this->jobs->unlock( $group_lock ); }
            if ( null !== $publication_lock ) { $this->jobs->unlock( $publication_lock ); }
            $this->jobs->unlock( $lock );
        }
    }

    /**
     * The URL the verified item actually has, read from WordPress itself rather than from
     * the writer's plan, so WPML/Polylang and date-based permalinks report their real URL.
     * Optional evidence: returns null instead of blocking success when WordPress cannot
     * give an absolute URL that matches the contract.
     */
    private static function publication_report( array $snapshot, array $verified, bool $static_term, string $writer = '', array $plan = [] ): ?array {
        try {
            if ( $static_term ) {
                $term_id = (int) ( $verified['term_id'] ?? 0 );
                $taxonomy = $plan['provider']['taxonomy'] ?? ( Nova_Bridge_Suite_Category_Recipe::taxonomy( $plan['configuration']['record'] ?? [] ) ?: 'product_cat' );
                $term = Nova_Bridge_Suite_Term_Content::with_language( $taxonomy, $term_id, static function () use ( $term_id ) { return get_term( $term_id ); } );
                if ( ! is_object( $term ) || is_wp_error( $term ) ) { return null; }
                $url = get_term_link( $term ); $cms_status = 'publish';
                $native = [ 'system' => 'wordpress', 'type' => (string) $term->taxonomy, 'id' => (string) $term->term_id ];
                if ( 'dictionary_term_translation' === $writer ) {
                    if ( ! is_array( $plan['provider'] ?? null ) || ! is_string( $url ) ) { return null; }
                    $localized = Nova_Bridge_Suite_Translation_Writer::translated_url( $plan['provider'], $url );
                    $url = is_array( $localized ) ? ( $localized['identity'] ?? null ) : null;
                }
            } else {
                $id = (int) ( $verified['post_id'] ?? 0 ); $cms_status = get_post_status( $id );
                // get_permalink() gives the ?p= form for unpublished posts. Build their future URL the way the editor does.
                $url = in_array( $cms_status, [ 'draft', 'pending', 'future', 'auto-draft' ], true ) ? self::sample_permalink( $id ) : get_permalink( $id );
                $native = [ 'system' => 'wordpress', 'type' => (string) get_post_type( $id ), 'id' => (string) $id ];
                // TranslatePress/Weglot translate the original in place: the verified post is the
                // original, and its localized URL comes from the provider, as the writer checks at plan time.
                if ( 'dictionary_translation' === $writer ) {
                    if ( ! is_array( $plan['provider'] ?? null ) || ! is_string( $url ) ) { return null; }
                    $localized = Nova_Bridge_Suite_Translation_Writer::translated_url( $plan['provider'], $url );
                    $url = is_array( $localized ) ? ( $localized['identity'] ?? null ) : null;
                }
            }
            if ( ! is_string( $url ) || ! is_string( $cms_status ) ) { return null; }
            if ( self::query_identified( $url ) ) { return null; }
            $report = [ 'url' => $url, 'url_status' => 'publish' === $cms_status ? 'final' : 'provisional', 'cms_status' => $cms_status, 'native' => $native, 'observed_at' => Nova_Bridge_Suite_Posting_Protocol::now() ];
            $optional = [ 'intended_url' => $snapshot['url'] ?? null, 'language' => is_string( $snapshot['language'] ?? null ) ? str_replace( '_', '-', $snapshot['language'] ) : null ];
            foreach ( $optional as $key => $value ) {
                // Each optional field is checked on its own so one odd value never drops the URL.
                $candidate = $report + [ $key => $value ];
                if ( is_string( $value ) && true === Nova_Bridge_Suite_Posting_Protocol::validate( $candidate, 'ConnectorPublication' ) ) { $report = $candidate; }
            }
            return true === Nova_Bridge_Suite_Posting_Protocol::validate( $report, 'ConnectorPublication' ) ? $report : null;
        } catch ( Throwable $error ) {
            return null;
        }
    }

    /**
     * NOVA stores page URLs as paths, so a URL that only its query identifies (plain permalinks:
     * /?p=123, ?page_id=45, a draft preview) would name the homepage or another page. Omit it.
     */
    private static function query_identified( string $url ): bool {
        $parts = wp_parse_url( $url );
        $query = is_array( $parts ) ? (string) ( $parts['query'] ?? '' ) : '';
        if ( '' === $query ) { return false; }
        $path = (string) ( $parts['path'] ?? '' );
        return '' === $path || '/' === $path || 1 === preg_match( '/(?:^|&)(?:p|page_id|preview)=/i', $query );
    }

    private static function sample_permalink( int $post_id ) {
        if ( ! function_exists( 'get_sample_permalink' ) && defined( 'ABSPATH' ) && is_file( ABSPATH . 'wp-admin/includes/post.php' ) ) { require_once ABSPATH . 'wp-admin/includes/post.php'; }
        if ( ! function_exists( 'get_sample_permalink' ) ) { return null; }
        $sample = get_sample_permalink( $post_id );
        return is_array( $sample ) && is_string( $sample[0] ?? null ) ? str_replace( [ '%pagename%', '%postname%' ], (string) ( $sample[1] ?? '' ), $sample[0] ) : null;
    }

    /** Keep the real check locally even when the operation must retain its reconciliation state. */
    private function retain_native_error( array $job, WP_Error $error ) {
        $detail = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $error->get_error_code() . ': ' . $error->get_error_message() ) ) );
        $detail = function_exists( 'mb_substr' ) ? mb_substr( $detail, 0, 600 ) : substr( $detail, 0, 600 );
        return $this->save_payload( $job, [ 'native_error_detail' => $detail ], $job['phase'] );
    }

    private function native_failure( array $job, array $snapshot, string $code, array $proof, string $message = '' ) {
        $code = preg_match( '/^[a-z0-9_]{1,100}$/D', $code ) ? $code : 'native_failure';
        // The code alone hides which WordPress check failed (e.g. nova_layout_writer_native wraps ~40 of them).
        // Keep the WordPress message locally so the delivery list can show it; the event schema stays unchanged.
        $extra = [ 'native_absence_proof' => $proof, 'failure_code' => $code, 'native_error_detail' => '' ];
        $message = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $message ) ) );
        if ( '' !== $message ) { $extra['failure_message'] = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 500 ) : substr( $message, 0, 500 ); }
        return $this->queue_event( $job, $snapshot, 'publication_failed', [ 'reason' => $code ], 'complete', $extra );
    }

    private function queue_event( array $job, array $snapshot, string $kind, array $evidence, string $continue, array $extra = [] ) {
        if ( 'publication_succeeded' === $kind ) { $extra['native_error_detail'] = ''; $extra['failure_message'] = ''; }
        $event = array_merge( [ 'event_id' => strtolower( wp_generate_uuid4() ), 'kind' => $kind, 'site_id' => $job['site_id'], 'delivery_id' => $snapshot['id'], 'content_item_version_id' => $snapshot['content_item_version_id'], 'version_number' => $snapshot['version_number'], 'source_sha256' => $snapshot['source_sha256'], 'attempt_id' => $job['payload']['attempt_id'], 'delivery_etag' => $job['payload']['delivery_etag'] ], $evidence );
        if ( is_wp_error( Nova_Bridge_Suite_Posting_Protocol::event_input( $event ) ) ) { return $this->block( $job, 'event_schema' ); }
        $bytes = Nova_Bridge_Suite_Receipt_Json::encode( $event );
        $job = $this->save_payload( $job, array_merge( $extra, [ 'pending_event' => $event, 'event_bytes' => $bytes, 'event_sha256' => hash( 'sha256', $bytes ), 'event_continue' => $continue ] ), 'event_pending' );
        if ( is_wp_error( $job ) ) { return $job; }
        try { return $this->drain_event( $job ); }
        catch ( Throwable $error ) { return $this->retry( $job, 'worker_interrupted' ); }
    }

    private function drain_event( array $job ) {
        $bytes = $job['payload']['event_bytes'] ?? null; $event = $job['payload']['pending_event'] ?? [];
        if ( ! is_string( $bytes ) || ! hash_equals( hash( 'sha256', $bytes ), $job['payload']['event_sha256'] ?? '' ) || is_wp_error( Nova_Bridge_Suite_Posting_Protocol::event_input( $event ) ) || Nova_Bridge_Suite_Receipt_Json::encode( $event ) !== $bytes ) { return $this->block( $job, 'event_journal_invalid' ); }
        $snapshot = $job['payload']['content'] ?? [];
        foreach ( [ 'site_id' => $job['site_id'], 'delivery_id' => $job['payload']['discovery']['id'], 'attempt_id' => $job['payload']['attempt_id'], 'delivery_etag' => $job['payload']['delivery_etag'], 'content_item_version_id' => $snapshot['content_item_version_id'] ?? null, 'version_number' => $snapshot['version_number'] ?? null, 'source_sha256' => $snapshot['source_sha256'] ?? null ] as $key => $value ) {
            if ( ( $event[ $key ] ?? null ) !== $value ) { return $this->block( $job, 'event_journal_identity' ); }
        }
        $authority = $this->current_authority( $job );
        if ( true !== $authority ) { return $authority; }
        $response = $this->client->site_request_bytes( 'POST', self::path( $job ) . '/events', $bytes );
        if ( is_wp_error( $response ) ) {
            if ( in_array( (int) ( $response->get_error_data()['status'] ?? 0 ), [ 409, 412 ], true ) ) {
                // Authority may change between GET and POST. Do not strand the
                // replacement behind a rejected callback from the old attempt.
                $authority = $this->current_authority( $job );
                if ( true !== $authority ) { return $authority; }
            }
            return $this->remote_error( $job, $response );
        }
        $accepted = Nova_Bridge_Suite_Posting_Protocol::accepted( $response, $event );
        if ( is_wp_error( $accepted ) ) { return $this->block( $job, $accepted->get_error_code() ); }
        $events = $job['payload']['events'] ?? [];
        $events[ $event['kind'] ] = [ 'bytes' => $bytes, 'sha256' => hash( 'sha256', $bytes ), 'accepted' => $accepted ];
        $continue = $job['payload']['event_continue'] ?? '';
        if ( ! in_array( $continue, [ 'received', 'complete' ], true ) || ( 'received' === $continue ) !== ( 'received_complete' === $event['kind'] ) ) { return $this->block( $job, 'event_continuation_invalid' ); }
        // 202/pending confirms durable acceptance only. Never label it backend-applied.
        return $this->save_payload( $job, [ 'events' => $events ], 'complete' === $continue ? 'committed' : 'received', 'complete' === $continue ? [ 'state' => 'complete', 'last_error' => $job['payload']['failure_code'] ?? '' ] : [] );
    }
}
