<?php
/** Real worker, source resolver, translation writer and receipts with simulated WordPress/Weglot APIs. */
require __DIR__ . '/delivery-unit.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-mapped-content-target.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout-language.php';

function get_option( $key, $fallback = false ) { return 'home' === $key ? 'https://localized.fixture.invalid' : ( $GLOBALS['associations'][$key] ?? $fallback ); }
function home_url( $path = '' ) { return get_option( 'home' ) . $path; }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function get_post( $id ) { return $GLOBALS['original']; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['metadata'][$id][$key] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['metadata'][$id][$key] = $value; return true; }
function current_user_can( ...$args ) { return true; }
function wp_slash( $value ) { return $value; }
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function wp_kses_post( $value ) { return $value; }
function wp_cache_delete( ...$args ) {}
function weglot_get_service( $name ) {
    return new class {
        public function get_original_language() { return new Resolved_Url_Language( 'en' ); }
        public function get_destination_languages() { return [ new Resolved_Url_Language( 'de' ) ]; }
        public function get_language_from_internal( $code ) { return new Resolved_Url_Language( $code ); }
    };
}
class Resolved_Url_Language {
    public $code;
    public function __construct( $code ) { $this->code = $code; }
    public function getInternalCode() { return $this->code; }
    public function getExternalCode() { return $this->code; }
}
function weglot_create_url_object( $url ) {
    return new class( $url ) {
        private $url;
        public function __construct( $url ) { $this->url = $url; }
        public function getForLanguage( $language ) {
            return str_replace( home_url(), home_url( 'en' === $language->code ? '' : '/' . $language->code ), $this->url );
        }
    };
}
class Nova_Bridge_Suite_Owned_CPT_Sync {
    public static function configuration_for_snapshot( $snapshot, $site ) { return $GLOBALS['configuration']; }
}
class Nova_Bridge_Suite_Writing_Adapter {
    public static function canonical_json( $value ) { return json_encode( $value ); }
    public static function adaptation_draft( $value ) { return $value; }
}
class Nova_Bridge_Suite_Stored_Layout {
    public static function compile( $draft, $values, $seed ) {
        return [ 'builder' => 'core', 'post_fields' => [ 'title' => $values['heading'] ], 'writer_payload' => [ 'content' => '<p>German authored content.</p>' ] ];
    }
}

$wpdb = new class {
    public $posts = 'wp_posts';
    public function prepare( $sql, ...$args ) { return $sql; }
    public function get_var( $sql ) { return '1'; }
};
$checks = 0;
foreach ( [ [ 'magnesium-rich-foods', 'magnesium-lebensmittel' ], [ 'omega-3-food-sources', 'omega-3-lebensmittel' ] ] as [ $source_slug, $intended_slug ] ) {
    $original = (object) [ 'ID' => 77, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'post_title' => 'English title', 'post_excerpt' => '', 'post_content' => 'English content' ];
    $fixture_permalink = home_url( '/' . $source_slug . '/' );
    $metadata = [];
    $snapshot = nova_contract_fixture()['snapshot'];
    $snapshot['language'] = 'de-DE';
    $snapshot['url'] = home_url( '/de/' . $intended_slug . '/' );
    $snapshot['url_family'] = [ 'url_group_id' => '50', 'primary_url_id' => '100', 'primary_url' => $fixture_permalink, 'primary_language' => 'en-US' ];
    $configuration = nova_contract_fixture()['configuration'];
    $configuration['mode'] = 'stored_layout_v3';
    $configuration['local'] = [ 'routing' => [ 'post_type' => 'post', 'publication' => 'publish' ],
        'stored_layout' => [ 'bindings' => [ [ 'kind' => 'post', 'column' => 'title', 'mode' => 'adapt' ], [ 'kind' => 'recipe', 'mode' => 'adapt' ] ] ] ];
    $configuration['profile_digest'] = hash( 'sha256', json_encode( $configuration['local'] ) );
    $fixture = nova_contract_fixture( [ 'content' => $snapshot, 'configuration' => $configuration ] );
    $source_key = 'nova_layout_page_' . hash( 'sha256', $snapshot['site_id'] . ':100:en-us' );
    $source_row = [ 'site_id' => $snapshot['site_id'], 'url_id' => '100', 'post_id' => 77, 'language' => 'en-US', 'state' => 'committed' ];
    $associations = [];
    $store = new class extends Contract_Test_Store {
        public $wakes = 0;
        public function wake_translation_dependents( $original ) { ++$this->wakes; return 1; }
    };
    $server = new Contract_Test_Server( $fixture );
    $worker = new Nova_Bridge_Suite_Posting_Worker( $fixture['connection'], $store, new Nova_Bridge_Suite_Posting_Client( $fixture['connection'] ) );
    $waiting = $worker->process( $fixture['job'] );
    delivery_check( $waiting['state'] === 'blocked' && $waiting['last_error'] === 'nova_translation_source_pending' && empty( $metadata ), 'The localized delivery waits for its original without writing.' );
    $associations[$source_key] = $source_row;
    $server->lose = 'publication_succeeded';
    $pending = $worker->process( delivery_resume( $waiting ) );
    delivery_check( $pending['phase'] === 'event_pending' && $pending['state'] === 'ready', 'A lost success response retains the publication event for retry.' );
    $before_retry = $metadata;
    $done = $worker->process( delivery_resume( $pending ) );
    delivery_check( $done['state'] === 'complete' && $done['last_error'] === '' && $metadata === $before_retry && $store->locks === [], 'Retry completes without repeating the native write or retaining locks.' );
    $events = array_map( static function ( $bytes ) { return json_decode( $bytes, true ); }, $server->posts );
    $success = $events[count( $events ) - 1];
    delivery_check( $success['kind'] === 'publication_succeeded' && $success['publication']['url'] === home_url( '/de/' . $source_slug ) && $success['publication']['intended_url'] === $snapshot['url'], 'The existing publication object reports actual and intended URLs.' );
    delivery_check( $success['publication']['url_status'] === 'final' && $success['publication']['language'] === 'de-DE' && $success['publication']['native']['id'] === '77', 'The URL report preserves native source and language evidence.' );
    delivery_check( $done['payload']['snapshot_raw'] === $fixture['raw'] && $done['payload']['content']['url'] === $snapshot['url'] && $success['source_sha256'] === $snapshot['source_sha256'] && $success['delivery_etag'] === $fixture['etag'], 'Resolved URLs do not modify frozen delivery bytes, hashes or ETags.' );
    delivery_check( $metadata[77]['_nova_weglot_i18n_de']['title'] === $snapshot['content']['title'] && $original->post_title === 'English title' && $original->post_content === 'English content', 'German authored content is stored on the verified source while English content is preserved.' );

    $metadata = [];
    $connection = $fixture['connection'] + [ 'url_scope_enabled' => true, 'url_scope_excluded_paths' => [ '/de/' . $source_slug ] ];
    $server = new Contract_Test_Server( $fixture );
    $excluded = ( new Nova_Bridge_Suite_Posting_Worker( $connection, new Contract_Test_Store(), new Nova_Bridge_Suite_Posting_Client( $connection ) ) )->process( $fixture['job'] );
    delivery_check( ( $excluded['payload']['failure_code'] ?? '' ) === 'nova_translation_url_scope' && empty( $metadata ) && ! isset( $excluded['payload']['plan'] ), 'An excluded resolved URL cannot bypass the installation scope through a different intended slug.' );
}
echo "Resolved localized worker URLs: $checks checks passed.\n";
