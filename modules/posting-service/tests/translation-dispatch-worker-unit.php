<?php
/** Default worker dispatch with an active single-source provider and an unpublished original. */
require __DIR__ . '/delivery-unit.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-mapped-content-target.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-translation-source.php';

function get_option( $key, $fallback = false ) { return 'home' === $key ? 'https://en.fixture.invalid/site' : $fallback; }
function home_url( $path ) { return get_option( 'home' ) . $path; }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function wp_cache_delete( $key, $group ) {}
class TRP_Translate_Press {
    public static function get_trp_instance() { return new self(); }
    public function get_component( $name ) { return $this; }
    public function get_settings() { return [ 'default-language' => 'en_US', 'publish-languages' => [ 'en_US', 'fr_FR', 'fr_CA' ], 'url-slugs' => [ 'fr_FR' => 'fr', 'fr_CA' => 'fr-ca' ] ]; }
    public function get_url_for_language( $locale, $url ) {
        $hosts = [ 'fr_FR' => 'fr.fixture.invalid', 'fr_CA' => 'fr-ca.fixture.invalid' ];
        return isset( $hosts[$locale] ) ? str_replace( 'en.fixture.invalid', $hosts[$locale], $url ) : $url;
    }
}
class Nova_Bridge_Suite_Owned_CPT_Sync {
    public static $mode = 'owned_cpt_v3';
    public static function configuration_for_snapshot( $snapshot, $site ) {
        return [ 'mode' => self::$mode, 'site_id' => $site, 'local' => [ 'routing' => [ 'post_type' => 'post' ] ] ];
    }
}

$checks = 0;
$scope = [ 'url_scope_enabled' => true, 'url_scope_excluded_paths' => [ '/site/private' ] ];
foreach ( [
    [ 'https://fr.fixture.invalid/site/article/', true ],
    [ 'article/', true ],
    [ '/site/article/', true ],
    [ '/another-installation/article/', false ],
    [ '/site/private/article/', false ],
    [ 'https://third-party.invalid/site/article/', false ],
] as [ $target, $expected ] ) {
    delivery_check( $expected === Nova_Bridge_Suite_Posting_Url_Scope::owns( $scope, $target, 'fr-FR' ), 'A language domain still obeys installation ownership and exclusions: ' . $target );
}

$snapshot = nova_contract_fixture()['snapshot'];
$snapshot['language'] = 'fr-FR';
$snapshot['url'] = 'https://fr.fixture.invalid/site/article/';
$fixture = nova_contract_fixture( [ 'content' => $snapshot ] );
$store = new class extends Contract_Test_Store {
    public $earlier;
    public function predecessors( $job, $snapshot ) { return [ $this->earlier ]; }
};
$server = new Contract_Test_Server( $fixture );
$GLOBALS['server'] = $server;
$client = new Nova_Bridge_Suite_Posting_Client( $fixture['connection'] );
$worker = new Nova_Bridge_Suite_Posting_Worker( $fixture['connection'], $store, $client );
$previous = $snapshot;
$previous['content_item_id'] = '999';
$previous['language'] = 'fr';
$previous['url'] = 'https://fr.fixture.invalid/site/earlier/';
$store->earlier = [ 'payload' => [ 'native_writer' => 'owned_cpt_v3', 'content' => $previous ] ];
$same = $worker->process( $fixture['job'] );
delivery_check( 'blocked' === $same['state'] && 'other_mutation_unreconciled' === $same['last_error'] && ! $server->posts, 'French and its explicit local alias share one route even when the article paths differ.' );
$store->earlier['payload']['content']['language'] = 'fr-CA';
$store->earlier['payload']['content']['url'] = 'https://fr-ca.fixture.invalid/site/earlier/';
$waiting = $worker->process( $fixture['job'] );
delivery_check( $waiting['state'] === 'ready' && 'received' === $waiting['phase'] && 'nova_translation_source_pending' === $waiting['last_error'], 'Distinct configured regional homes can proceed independently to source resolution.' );
delivery_check( $waiting['payload']['native_writer'] === 'dictionary_translation' && ! isset( $waiting['payload']['plan'] ) && 1 === count( $server->posts ), 'Before a source is available only the received receipt exists and the translation writer is pinned.' );
$waiting['state'] = 'running';
$waiting['attempts'] = 8;
$final = ( new Nova_Bridge_Suite_Posting_Worker( $fixture['connection'], $store, $client ) )->process( $waiting );
delivery_check( 'blocked' === $final['state'] && 'nova_translation_source_pending_retry_limit' === $final['last_error'] && 1 === count( $server->posts ), 'If the original stays unavailable the operator can resume with the reason intact.' );
Nova_Bridge_Suite_Owned_CPT_Sync::$mode = 'fixed_mapping';
$server = new Contract_Test_Server( $fixture );
$GLOBALS['server'] = $server;
$worker = new Nova_Bridge_Suite_Posting_Worker( $fixture['connection'], new Contract_Test_Store(), $client );
$rejected = $worker->process( $fixture['job'] );
delivery_check( 'nova_translation_mapping_unsupported' === ( $rejected['payload']['failure_code'] ?? '' ) && empty( $rejected['payload']['plan'] ), 'A secondary locale cannot use a fixed mapping to overwrite the shared original.' );

[ $job, $store, $server, $worker ] = delivery_fixture();
Contract_Test_Writer::$apply_error = new WP_Error( 'nova_translation_busy' );
Contract_Test_Writer::$recover_value = [ 'state' => 'not_committed', 'no_native_commit' => true, 'safe_to_apply' => true ];
$waiting = $worker->process( $job );
delivery_check( 'ready' === $waiting['state'] && 'nova_translation_busy' === $waiting['last_error'] && 1 === count( $server->posts ), 'Contention does not emit a terminal receipt.' );
Contract_Test_Writer::$apply_error = null;
$done = $worker->process( delivery_resume( $waiting ) );
delivery_check( 'complete' === $done['state'] && isset( $done['payload']['events']['publication_succeeded'] ), 'The same operation finishes when the translation lock becomes available.' );
echo "Translation worker dispatch: $checks checks passed.\n";
