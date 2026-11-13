<?php
/** In-memory checks for shared-category plans, readback and recovery. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    public $code;
    public function __construct( $code, $message, $data = [] ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can( ...$args ) { return $GLOBALS['permitted']; }
function get_term( $id, $taxonomy ) { return $id === 17 ? (object) [ 'term_id' => 17, 'taxonomy' => $GLOBALS['taxonomy'] ] : false; }
function get_term_meta( $id, $key, $single ) { return $GLOBALS['metadata'][$key] ?? ''; }
function update_term_meta( $id, $key, $next, $before = null ) {
    if ( $before !== null && ( $GLOBALS['metadata'][$key] ?? null ) !== $before ) { return false; }
    $GLOBALS['metadata'][$key] = $next;
    ++$GLOBALS['writes'];
    return true;
}
function add_term_meta( $id, $key, $value, $unique ) {
    if ( array_key_exists( $key, $GLOBALS['metadata'] ) ) { return false; }
    return update_term_meta( $id, $key, $value );
}
function wp_slash( $value ) { return $value; }
class Nova_Bridge_Suite_Writing_Adapter {
    public static function canonical_json( $value ) { return json_encode( $value ); }
}
class Nova_Bridge_Suite_Posting_Protocol {
    public static function uuid( $value ) { return (bool) $value; }
}
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-publication-policy.php';
class Nova_Bridge_Suite_Static_Recipe_Adapter {
    public static function validate_profile( $content, $config ) { return true; }
}
class Nova_Bridge_Suite_Category_Recipe {
    public const FORMAT = 'nova.category-recipe/v1';
    public static function taxonomy($record) { return substr($record['resource_id'],9); }
    public static function compile( $content, $configuration, $operation ) { return $content['values']; }
}
class Nova_Bridge_Suite_Term_Content {
    public static function localized_fields( $taxonomy, $id, $payload, $profile ) { return $payload; }
    public static function read( $taxonomy, $id, $profile ) { return $GLOBALS['source']; }
}
class Nova_Bridge_Suite_Mapped_Content_Target {
    public static function resolve( $url, $resource ) {
        return $url === 'https://category.test/shop/example' ? [ 'reference_id' => 17 ] : new WP_Error( 'nova_mapped_target_not_found', 'Missing' );
    }
    public static function native_url( $resource, $kind, $id ) { return $GLOBALS['native_url']; }
    public static function normalize_url( $url ) { return [ 'identity' => rtrim( $url, '/' ) ]; }
}
class Nova_Bridge_Suite_Translation_Writer {
    public static function provider( $language ) { return [ 'provider' => $GLOBALS['provider'], 'source' => 'en', 'language' => 'de' ]; }
    public static function same_language_slot( $a, $b ) { return substr( $a, 0, 2 ) === substr( $b, 0, 2 ); }
    public static function delivery_url( $url, $language ) {
        return str_starts_with( $url, 'https://category.test/' ) ? rtrim( $url, '/' ) : new WP_Error( 'foreign', 'Foreign URL' );
    }
    public static function translated_url( $provider, $url ) { return [ 'identity' => $GLOBALS['localized_url'] ]; }
}
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-term-translation-writer.php';
use Nova_Bridge_Suite_Term_Translation_Writer as Writer;
$checks = 0;
function expect( $condition, $label ) {
    if ( ! $condition ) { throw new RuntimeException( $label ); }
    ++$GLOBALS['checks'];
}
function good( $value ) {
    if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->code ); }
    return $value;
}
foreach ( [ 'weglot', 'translatepress' ] as $provider ) { foreach ( [ 'product_cat', 'product_tag' ] as $taxonomy ) {
    $metadata = []; $writes = 0; $permitted = true;
    $native_url = 'https://category.test/shop/example/';
    $localized_url = 'https://category.test/de/shop/example';
    $source = [ 'name' => 'English', 'description' => '<p>Original</p>', 'protected' => 'keep' ];
    $content = [ 'site_id' => 'site', 'url' => 'https://category.test/de/shop/beispiel/', 'language' => 'de-DE',
        'content_item_id' => '42', 'version_number' => 1,
        'url_family' => [ 'primary_url_id' => '41', 'primary_url' => $native_url, 'primary_language' => 'en-GB' ],
        'values' => [ 'native' => [ 'name' => [ 'value' => 'Deutsch' ] ], 'meta' => [ 'seo_text' => [ 'value' => '<p>Größe & Qualität</p>' ] ] ] ];
    $config = [ 'projection' => [ 'format' => Nova_Bridge_Suite_Category_Recipe::FORMAT ], 'record' => [ 'id' => 'profile', 'revision' => 1, 'resource_id' => 'taxonomy:' . $taxonomy ] ];
    $context = [ 'operation_id' => 'operation-1', 'site_id' => 'site', 'target_post_id' => 17, 'operation' => 'update', 'desired_status' => 'publish' ];
    expect( good( Writer::policy( $content, $config ) )['reference']['reference_id'] === 17, 'Uses the original category' );
    $plan = good( Writer::plan( $content, $config, $context ) );
    expect( $plan['target']['target_url'] === $localized_url, 'Retains the provider URL' );
    expect( $writes === 0, 'Planning is read-only' );
    $source['description'] = 'Changed';
    expect( Writer::apply( $plan, 'operation-1' )->code === 'nova_translation_source_changed' && $writes === 0, 'Preserves intervening source edits' );
    $source['description'] = '<p>Original</p>';
    $before = $source;
    $result = good( Writer::apply( $plan, 'operation-1' ) );
    expect( $source === $before && $writes === 1, 'Stores localized copy separately' );
    expect( ! isset( Writer::record( 17, $plan['provider'] )['fields']['native']['description'] ),
        'The first translation does not copy the source or example notice into an unmapped field' );
    good( Writer::verify( $plan, $result ) );
    good( Writer::recover( $plan, 'operation-1' ) );
    good( Writer::apply( $plan, 'operation-1' ) );
    expect( $writes === 1, 'A lost response cannot duplicate the write' );
    expect( is_wp_error( Writer::plan( $content, $config, $context ) ), 'Same version cannot become a new operation' );
    // Simulate a notice already stored by an earlier mapped translation.
    $key = array_key_first( $metadata );
    $notice = [ 'provider' => 'native', 'key' => 'description', 'type' => 'wysiwyg',
        'value' => '<p>Nur für Laborforschung. ' . $taxonomy . '</p>' ];
    $metadata[$key]['fields']['native']['description'] = $notice;
    $content['version_number'] = 2; $context['operation_id'] = 'operation-2';
    $next = good( Writer::plan( $content, $config, $context ) );
    good( Writer::apply( $next, 'operation-2' ) );
    expect( $writes === 2, 'Higher versions update the same localized record' );
    expect( $metadata[$key]['fields']['native']['description'] === $notice,
        'An unmapped notice survives a higher localized version byte for byte' );
    good( Writer::apply( $next, 'operation-2' ) );
    expect( $writes === 2, 'Replaying a retained-notice plan is idempotent' );
    $content['version_number'] = 3; $context['operation_id'] = 'operation-3';
    $plan = good( Writer::plan( $content, $config, $context ) );
    $permitted = false;
    expect( Writer::apply( $plan, 'operation-3' )->code === 'nova_translation_source_permission' && $writes === 2, 'Permission checked before writing' );
    $permitted = true; $localized_url .= '-moved';
    expect( is_wp_error( Writer::apply( $plan, 'operation-3' ) ) && $writes === 2, 'Provider URL drift is rejected' );
    $bad = $content; $bad['url_family']['primary_language'] = 'fr';
    expect( is_wp_error( Writer::policy( $bad, $config ) ), 'Rejects another source language' );
    $bad = $content; $bad['url_family']['primary_url'] = 'https://category.test/shop/missing';
    expect( Writer::policy( $bad, $config )->code === 'nova_translation_source_pending', 'Waits for an absent original' );
    $bad = $content; $bad['url'] = 'https://other.test/de/example';
    expect( is_wp_error( Writer::policy( $bad, $config ) ), 'Rejects a foreign intended URL' );
    // Empty retained notices stay empty; an explicitly mapped value still replaces one.
    $localized_url = 'https://category.test/de/shop/example';
    $metadata[$key]['fields']['native']['description']['value'] = '';
    $content['version_number'] = 4; $context['operation_id'] = 'operation-4';
    $empty_plan = good( Writer::plan( $content, $config, $context ) );
    good( Writer::apply( $empty_plan, 'operation-4' ) );
    expect( $metadata[$key]['fields']['native']['description']['value'] === '', 'An empty localized notice remains empty' );
    $content['values']['native']['description'] = $notice;
    $content['version_number'] = 5; $context['operation_id'] = 'operation-5';
    $mapped_plan = good( Writer::plan( $content, $config, $context ) );
    good( Writer::apply( $mapped_plan, 'operation-5' ) );
    expect( $metadata[$key]['fields']['native']['description'] === $notice, 'An explicitly mapped description retains its existing update behavior' );
    $demo = $content; $demo['url'] = 'https://category.test/de/shop/lorem-ipsum-demo-category/';
    $error = Writer::policy( $demo, $config );
    $expected = 'weglot' === $provider ? 'nova_translation_test_url' : 'nova_test_delivery_draft_unavailable';
    expect( is_wp_error( $error ) && $error->code === $expected, 'Rejects a non-demo destination or unsupported provider' );
    $localized_url = rtrim( $demo['url'], '/' );
    $policy = Writer::policy( $demo, $config );
    expect( 'weglot' === $provider ? ( $policy['routing']['publication'] ?? '' ) === 'publish'
        : is_wp_error( $policy ), 'Only Weglot allows a test at the exact localized tag URL' );
}
}
echo "Shared category writer checks: $checks passed.\n";
