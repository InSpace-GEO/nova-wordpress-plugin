<?php
/** MySQL named locks are server-wide: installations sharing one MySQL server must not share lock names. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $code; public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-protocol.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-discovery.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-jobs.php';

/** Like wpdb: dbname is protected and only readable through the magic accessors. */
class Lock_Fixture_DB {
    public $prefix; public $locks = []; public $released = [];
    protected $dbname;
    public function __construct( string $dbname, string $prefix = 'wp_' ) { $this->dbname = $dbname; $this->prefix = $prefix; }
    public function __get( $name ) { return $this->$name; }
    public function __isset( $name ) { return isset( $this->$name ); }
    public function prepare( $sql, ...$args ) { return [ $sql, $args ]; }
    public function get_var( $query ) {
        [ $sql, $args ] = $query;
        if ( 0 === strpos( $sql, 'SELECT GET_LOCK' ) ) { $this->locks[] = $args[0]; return '1'; }
        if ( 0 === strpos( $sql, 'SELECT RELEASE_LOCK' ) ) { $this->released[] = $args[0]; return '1'; }
        throw new RuntimeException( 'Unexpected query: ' . $sql );
    }
}
class Lock_Fixture_Dropin { public $prefix = 'wp_'; }

$checks = 0;
function lock_check( bool $value, string $message ): void { global $checks; ++$checks; if ( ! $value ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); } }
function connection_lock( $db ): string { $jobs = new Nova_Bridge_Suite_Posting_Jobs( $db ); $jobs->lock( 'connection' ); $jobs->unlock( 'connection' ); return $db->locks[0]; }

$names = [];
foreach ( [ 'site_one', 'site_two', 'site_three', 'site_four', 'site_five' ] as $database ) {
    $db = new Lock_Fixture_DB( $database );
    $names[ $database ] = connection_lock( $db );
    lock_check( $db->released === $db->locks, 'Unlock releases exactly the lock that was taken: ' . $database );
    lock_check( strlen( $db->locks[0] ) <= 64, 'MySQL lock names stay within 64 characters' );
}
lock_check( 5 === count( array_unique( $names ) ), 'Five installations on one MySQL server use five different connection locks' );
lock_check( connection_lock( new Lock_Fixture_DB( 'site_three' ) ) === $names['site_three'], 'Requests on the same installation still share one connection lock' );
lock_check( connection_lock( new Lock_Fixture_DB( 'shared', 'site_a_' ) ) !== connection_lock( new Lock_Fixture_DB( 'shared', 'site_b_' ) ), 'Installations sharing one database with different table prefixes stay apart' );
lock_check( $names['site_one'] !== 'nova:' . substr( hash( 'sha256', 'wp_nova_posting_jobs:connection' ), 0, 58 ), 'The name no longer matches the 1.2.5 server-wide name' );

$a = new Lock_Fixture_DB( 'site_one' ); $b = new Lock_Fixture_DB( 'site_three' );
$jobs_a = new Nova_Bridge_Suite_Posting_Jobs( $a ); $jobs_b = new Nova_Bridge_Suite_Posting_Jobs( $b );
foreach ( [ 'discovery:11111111-1111-4111-8111-111111111111', 'delivery:11111111-1111-4111-8111-111111111111:42', 'admission', 'schema' ] as $identity ) {
    $jobs_a->lock( $identity ); $jobs_b->lock( $identity );
    lock_check( end( $a->locks ) !== end( $b->locks ), 'Installations sharing a NOVA site ID do not share the lock: ' . $identity );
}

// The native writers build their own names from the posts table.
foreach ( [ [ 'nova-rule:', 54 ], [ 'nova-layout:', 50 ], [ 'nova-localize:', 48 ], [ 'nova-cpt:', 50 ] ] as [ $namespace, $length ] ) {
    $one = Nova_Bridge_Suite_Native_Lock::name( $a, $namespace, 'wp_posts:translatepress', $length );
    $two = Nova_Bridge_Suite_Native_Lock::name( $b, $namespace, 'wp_posts:translatepress', $length );
    lock_check( $one !== $two && 0 === strpos( $one, $namespace ) && strlen( $one ) <= 64, 'Writer lock is scoped to the installation: ' . $namespace );
    lock_check( $one === Nova_Bridge_Suite_Native_Lock::name( new Lock_Fixture_DB( 'site_one' ), $namespace, 'wp_posts:translatepress', $length ), 'Writer lock is stable on one installation: ' . $namespace );
}

// A database drop-in without a readable dbname falls back to the configured DB_NAME.
define( 'DB_NAME', 'site_one' );
lock_check( Nova_Bridge_Suite_Native_Lock::name( new Lock_Fixture_Dropin(), 'nova:', 'x', 58 ) === Nova_Bridge_Suite_Native_Lock::name( $a, 'nova:', 'x', 58 ), 'Drop-ins without dbname use DB_NAME' );

echo "PASS $checks installation-scoped lock checks\n";
