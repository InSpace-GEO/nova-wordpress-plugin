<?php
/** Use the real cross-URL source resolver and Weglot writer/render hooks with in-memory provider state. */
$grouped_source = true;
require __DIR__ . '/single-source-locale-unit.php';
use Nova_Bridge_Suite_Translation_Writer as Writer;
$bad = $content; $bad['url_family']['primary_url'] = home_url( '/different/' );
check( Writer::policy( $bad, $profile )->get_error_code() === 'nova_translation_source_identity', 'Primary native URL mismatch is explicit' );
$bad = $content; $bad['url_family']['primary_language'] = 'fr-FR';
check( Writer::policy( $bad, $profile )->get_error_code() === 'nova_translation_source_language', 'Editorial primary cannot substitute for the provider original language' );
$bad = $content; $bad['url_family']['primary_url_id'] = '92';
check( Writer::policy( $bad, $profile )->get_error_code() === 'nova_translation_source_pending', 'Unknown primary cannot attach to another URL record' );
$bad = $content; $bad['site_id'] = 'other-site';
check( Writer::policy( $bad, $profile )->get_error_code() === 'nova_translation_source_pending', 'Another site cannot use the resolved destination' );
$bad_profile = $profile; $bad_profile['local']['routing']['post_type'] = 'page';
check( Writer::policy( $content, $bad_profile )->get_error_code() === 'nova_translation_source_pending', 'A different native post type cannot use the resolved destination' );
$original->post_status = 'draft';
check( Writer::policy( $content, $profile )->get_error_code() === 'nova_translation_source_pending', 'Draft originals remain unavailable for localized publication' );
$original->post_status = 'publish';
$original_identity = $content; $original_identity['url_id'] = '91'; $original_identity['language'] = 'en-US';
$original_identity['url'] = $content['url_family']['primary_url'];
check( Nova_Bridge_Suite_Translation_Source::is_provider_original( $original_identity ), 'Verified provider original can awaken grouped translations' );
check( Nova_Bridge_Suite_Translation_Source::depends_on( $content, $original_identity ), 'Expected secondary is a dependency of its explicit primary' );
$unrelated = $content; $unrelated['site_id'] = 'other-site';
check( ! Nova_Bridge_Suite_Translation_Source::depends_on( $unrelated, $original_identity ), 'Another site cannot be awakened' );
$unrelated = $content; $unrelated['url_family']['url_group_id'] = '99';
check( ! Nova_Bridge_Suite_Translation_Source::depends_on( $unrelated, $original_identity ), 'Another group cannot be awakened' );

// Exercise the actual worker dependency registration without a database or transport.
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-worker.php';
$store = new class {
    public $saved; public $wakes = 0;
    public function save( $job, $changes ) { return $this->saved = array_replace( $job, $changes ); }
    public function wake_translation_dependents( $source ) { ++$this->wakes; return 1; }
};
$worker = new Nova_Bridge_Suite_Posting_Worker( [], $store, new stdClass(), static function () {} );
$wait = new ReflectionMethod( $worker, 'wait_for_original' );
if ( PHP_VERSION_ID < 80100 ) { $wait->setAccessible( true ); }
$job = [ 'phase' => 'received', 'state' => 'running', 'attempts' => 9, 'payload' => [ 'content' => $content, 'configuration' => $profile, 'native_writer' => 'dictionary_translation' ] ];
$original->post_password = 'protected';
check( Writer::policy( $content, $profile )->get_error_code() === 'nova_translation_source_pending', 'Protected source is not publicly ready' );
$result = $wait->invoke( $worker, $job, $content );
check( $result['state'] === 'blocked' && $result['attempts'] === 0 && $result['next_attempt'] === 0 && ! isset( $result['payload']['plan'] ), 'Grouped waiting is parked without publication attempts or native plan' );
check( $store->wakes === 0, 'Protected published source cannot self-wake into a continuation loop' );
$original->post_password = ''; $pending = true;
$wait->invoke( $worker, $job, $content );
check( $store->wakes === 0, 'Absent source remains parked without automatic polling' );
$pending = false;
$wait->invoke( $worker, $job, $content );
check( $store->wakes === 1, 'A source committed during dependency registration is rechecked and wakes the waiter' );
$legacy = $content; unset( $legacy['url_family'] );
$result = $wait->invoke( $worker, $job, $legacy );
check( $result['last_error'] === 'nova_translation_source_pending_retry_limit' && $store->wakes === 1, 'Ungrouped retries retain their existing limit and never join dependency continuation' );
echo "Grouped Weglot writer checks: $checks passed.\n";
