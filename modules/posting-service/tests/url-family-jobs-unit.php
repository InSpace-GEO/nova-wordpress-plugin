<?php
/** Production dependency journal methods with in-memory rows; no database is opened. */
require __DIR__ . '/url-family-weglot-unit.php';
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-jobs.php';

final class Family_Jobs_Memory_DB {
    public $prefix = 'fixture_';
    public $last_error = '';
    public $rows = [];
    public $reads = 0;
    public $writes = 0;
    public $read_failure = false;
    public $write_failure = false;
    public $race_id = 0;
    public function prepare( $sql, ...$args ) { return [ $sql, $args ]; }
    public function get_results( $query, $format ) {
        ++$this->reads;
        if ( $this->read_failure ) { $this->last_error = 'fixture read failure'; return null; }
        [ $sql, $args ] = $query;
        check( $format === ARRAY_A && str_contains( $sql, 'site_id=%s AND protocol=%s AND identity_conflict=0' ), 'Journal read filters site, protocol and identity conflicts in SQL' );
        check( str_contains( $sql, "phase='received'" ) && str_contains( $sql, 'lease_until=0' ), 'Journal read excludes planned and leased work in SQL' );
        check( str_contains( $sql, "state='blocked'" ) && str_contains( $sql, "last_error='nova_translation_source_pending'" ), 'Wake reads only parked dependencies' );
        $rows = array_filter( $this->rows, static function ( $row ) use ( $args ) {
            return $row['site_id'] === $args[0] && $row['protocol'] === $args[1] && ! $row['identity_conflict'] &&
                $row['state'] === 'blocked' && $row['phase'] === 'received' && !$row['lease_until'] &&
                $row['last_error'] === 'nova_translation_source_pending';
        } );
        usort( $rows, static fn( $a, $b ) => $a['id'] <=> $b['id'] );
        return $rows;
    }
    public function query( $query ) {
        ++$this->writes;
        if ( $this->write_failure ) { $this->last_error = 'fixture write failure'; return false; }
        [ $sql, $args ] = $query;
        foreach ( [ "state='ready',attempts=0,next_attempt=0,last_error='nova_translation_source_ready'", 'journal_revision=journal_revision+1',
            'site_id=%s AND protocol=%s AND identity_conflict=0', "state='blocked' AND phase='received' AND last_error='nova_translation_source_pending'", 'lease_until=0 AND journal_revision=%d' ] as $clause ) {
            check( str_contains( $sql, $clause ), 'Wake compare-and-swap retains predicate: ' . $clause );
        }
        [ $now, $id, $site, $protocol, $revision ] = $args;
        if ( $id === $this->race_id ) { ++$this->rows[$id]['journal_revision']; }
        $row = $this->rows[$id];
        if ( $row['site_id'] !== $site || $row['protocol'] !== $protocol || $row['identity_conflict'] || $row['state'] !== 'blocked' ||
            $row['phase'] !== 'received' || $row['last_error'] !== 'nova_translation_source_pending' || $row['lease_until'] || $row['journal_revision'] !== $revision ) { return 0; }
        $this->rows[$id] = array_replace( $row, [ 'state' => 'ready', 'attempts' => 0, 'next_attempt' => 0,
            'last_error' => 'nova_translation_source_ready', 'updated_at' => $now, 'journal_revision' => $revision + 1 ] );
        return 1;
    }
}

function family_job_row( int $id, array $content ): array {
    return [ 'id' => $id, 'site_id' => $content['site_id'], 'protocol' => Nova_Bridge_Suite_Posting_Jobs::PROTOCOL,
        'identity_conflict' => 0, 'state' => 'blocked', 'phase' => 'received', 'last_error' => 'nova_translation_source_pending',
        'version' => 1, 'target_id' => 0, 'attempts' => 7, 'next_attempt' => 123, 'lease_until' => 0, 'journal_revision' => 4,
        'created_at' => 1, 'updated_at' => 1, 'payload' => json_encode( [ 'content' => $content, 'native_writer' => 'dictionary_translation' ] ) ];
}
function family_job_payload( array $row, callable $change ): array {
    $payload = json_decode( $row['payload'], true ); $change( $payload ); $row['payload'] = json_encode( $payload ); return $row;
}

$db = new Family_Jobs_Memory_DB();
$jobs = new Nova_Bridge_Suite_Posting_Jobs( $db );
$base = family_job_row( 1, $content );
$db->rows[1] = $base;
$id = 2;
foreach ( [ ['site_id','other-site'], ['protocol','legacy'], ['identity_conflict',1], ['state','complete'], ['phase','planned'], ['last_error','remote_identity_conflict'], ['lease_until',time()+300] ] as [ $key, $value ] ) {
    $db->rows[$id] = array_replace( $base, [ 'id' => $id, $key => $value ] ); ++$id;
}
foreach ( [
    static function ( &$p ) { $p['plan'] = [ 'frozen' => true ]; },
    static function ( &$p ) { $p['native_writer'] = 'stored_layout_v3'; },
    static function ( &$p ) { $p['content']['site_id'] = 'other-site'; },
    static function ( &$p ) { $p['content']['url_family']['url_group_id'] = '999'; },
    static function ( &$p ) { $p['content']['url_family']['primary_url_id'] = '999'; },
    static function ( &$p ) { $p['content']['url_family']['primary_language'] = 'de-DE'; },
    static function ( &$p ) { $p['content']['url_family']['primary_url'] = home_url( '/different/' ); },
    static function ( &$p ) { unset( $p['content']['url_family'] ); },
] as $change ) {
    $db->rows[$id] = family_job_payload( array_replace( $base, [ 'id' => $id ] ), $change ); ++$id;
}
$before = $db->rows;
check( $jobs->wake_translation_dependents( $original_identity ) === 1, 'Only the exact source dependency is awakened' );
check( $db->rows[1]['payload'] === $before[1]['payload'] && $db->rows[1]['journal_revision'] === 5 && $db->rows[1]['attempts'] === 0 && $db->rows[1]['next_attempt'] === 0, 'Wake preserves frozen content and updates only retry bookkeeping' );
foreach ( $before as $row_id => $row ) { if ( $row_id !== 1 ) { check( $db->rows[$row_id] === $row, 'Unrelated or unsafe row remains unchanged: ' . $row_id ); } }
check( $jobs->wake_translation_dependents( $original_identity ) === 0, 'Wake replay is idempotent' );

$original_before_upgrade = $original_identity; unset( $original_before_upgrade['url_family'] );
$db->rows = [ 1 => $base ];
check( $jobs->wake_translation_dependents( $original_before_upgrade ) === 1, 'A verified pre-upgrade original can wake its explicit grouped dependency' );
$db->rows = [ 1 => family_job_payload( $base, static function ( &$p ) { $p['content']['url_family']['primary_language'] = 'en'; $p['content']['url_family']['primary_url'] = 'stone/'; } ) ];
check( $jobs->wake_translation_dependents( $original_identity ) === 1, 'Configured primary aliases and equivalent site-relative URLs resolve consistently' );
$db->rows = [ 1 => $base ]; $db->race_id = 1;
check( $jobs->wake_translation_dependents( $original_identity ) === 0 && $db->rows[1]['state'] === 'blocked' && $db->rows[1]['journal_revision'] === 5, 'A concurrent journal revision wins over stale wake CAS' );
$db->race_id = 0;
$reads = $db->reads;
check( $jobs->wake_translation_dependents( $content ) === 0 && $db->reads === $reads, 'A translated item cannot wake source dependencies' );

$db->read_failure = true;
check( is_wp_error( $jobs->wake_translation_dependents( $original_identity ) ), 'Wake read errors remain explicit' );
$db->read_failure = false; $db->last_error = ''; $db->write_failure = true; $db->rows = [ 1 => $base ];
check( is_wp_error( $jobs->wake_translation_dependents( $original_identity ) ) && $db->rows[1] === $base, 'Wake write error cannot report success or mutate retained work' );
$db->write_failure = false; $db->last_error = ''; $db->rows[1]['payload'] = '{';
check( $jobs->wake_translation_dependents( $original_identity )->get_error_code() === 'nova_posting_journal_invalid', 'Malformed journal bytes are never awakened' );
echo "Grouped dependency journal checks: $checks passed.\n";
