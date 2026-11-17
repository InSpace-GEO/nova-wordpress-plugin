<?php
/** Missing-original identity and recovery checks, independent of the remote API. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    public $code;
    public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
class WP_Post {
    public $ID = 41;
    public $post_type = 'page';
    public $post_password = '';
    public $post_status = 'publish';
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_cache_delete( ...$args ) {}
function add_filter( ...$args ) {}
function remove_filter( ...$args ) {}
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function add_option( $key, $value, ...$args ) {
    if ( isset( $GLOBALS['options'][$key] ) ) { return false; }
    $GLOBALS['options'][$key] = $value;
    return true;
}
function update_option( $key, $value, ...$args ) { $GLOBALS['options'][$key] = $value; return true; }
function get_post( $id ) { return $GLOBALS['native'][$id] ?? null; }
function get_posts( $query ) { return $GLOBALS['drafts']; }
function get_post_status( $id ) { return get_post( $id )->post_status; }
function get_post_meta( ...$args ) { return ''; }
function get_term( $id, $taxonomy ) { return $GLOBALS['native'][$id] ?? null; }
function get_term_by( $field, $value, $taxonomy ) { return $GLOBALS['matching_tags'][$value] ?? false; }
function get_term_link( $term, $taxonomy = '' ) { return isset( $term->slug ) ? 'https://example.test/product-tag/' . $term->slug : 'https://example.test/topic'; }
function current_user_can( ...$args ) { return $GLOBALS['permitted']; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function sanitize_title( $value ) { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $value ) ), '-' ); }
function _truncate_post_slug( $value, $length ) { return substr( $value, 0, $length ); }
function wp_unique_post_slug( $slug, ...$args ) { return $GLOBALS['slug_taken'] ? $slug . '-2' : $slug; }
function wp_unique_term_slug( $slug, ...$args ) { return wp_unique_post_slug( $slug ); }
class Nova_WordPress_Environment {
    public static function key( $key ) { return $key; }
}
class Nova_Bridge_Suite_Writing_Adapter {
    public static function canonical_json( $value ) { return json_encode( $value ); }
}
class Nova_Bridge_Suite_Posting_Protocol {
    public static function uuid( $value ) { return is_string( $value ) && strlen( $value ) === 36; }
}
class Nova_Bridge_Suite_Category_Recipe {
    public const FORMAT = 'nova.category-recipe/v1';
    public static function taxonomy( $record ) { return 'product_tag'; }
}
class Nova_WordPress_Weglot {
    public static function url( $url, $language ) {
        return preg_replace( '#/de/#', '/', $url );
    }
}
class Nova_Bridge_Suite_Mapped_Content_Target {
    public static function normalize_url( $url ) { return [ 'identity' => rtrim( $url, '/' ) ]; }
    public static function resolve( $url, $resource, $language = null ) {
        return $GLOBALS['existing'] ? [ 'reference_id' => 41 ] : new WP_Error( 'nova_mapped_target_not_found' );
    }
}
class Nova_Bridge_Suite_Translation_Writer {
    public static function provider( $language ) {
        return str_starts_with( $language, 'en' ) ? null : [ 'provider' => $GLOBALS['provider'], 'source' => 'en', 'language' => substr( $language, 0, 2 ) ];
    }
    public static function same_language_slot( $a, $b ) { return substr( $a, 0, 2 ) === substr( $b, 0, 2 ); }
    public static function delivery_url( $url, $language ) {
        if ( ! str_starts_with( $url, 'https://example.test/' ) ) { return new WP_Error( 'foreign' ); }
        return rtrim( $url, '/' );
    }
    public static function translated_url( $provider, $url ) {
        return [ 'identity' => str_replace( 'https://example.test/', 'https://example.test/' . $provider['language'] . '/', $url ) ];
    }
    public static function source_permalink( $post ) { return $GLOBALS['native_url']; }
    public static function plan( $content, $configuration, $context ) {
        if ( $GLOBALS['fail_localization'] ) { return new WP_Error( 'localization_temporarily_unavailable' ); }
        return [ 'operation_id' => $context['operation_id'], 'id' => $context['target_post_id'], 'term' => $configuration['mode'] === 'static_recipe_v1' ];
    }
    public static function recover( $plan, $operation ) {
        return $GLOBALS['localized'][$operation] ?? [ 'state' => 'not_committed', 'no_native_commit' => true, 'safe_to_apply' => true ];
    }
    public static function apply( $plan, $operation ) {
        ++$GLOBALS['locale_writes'];
        $result = [ $plan['term'] ? 'term_id' : 'post_id' => $plan['id'], 'cms_post_status' => 'publish' ];
        return $GLOBALS['localized'][$operation] = $result;
    }
    public static function verify( $plan, $result, $readonly = false ) { return $result; }
}
class Nova_Bridge_Suite_Term_Translation_Writer extends Nova_Bridge_Suite_Translation_Writer {}
class Source_Test_Writer {
    public static function recover( $plan, $operation ) {
        return $GLOBALS['originals'][$operation] ?? [ 'state' => 'not_committed', 'no_native_commit' => true, 'safe_to_apply' => true ];
    }
    public static function apply( $plan, $operation ) {
        ++$GLOBALS['creates'];
        if ( isset( $plan['target']['target_url'] ) ) { $GLOBALS['native_url'] = $plan['target']['target_url']; }
        $item = new WP_Post();
        if ( $plan['term'] ) { $item = (object) [ 'term_id' => 41, 'taxonomy' => 'product_tag' ]; }
        $GLOBALS['native'][41] = $item;
        $result = [ $plan['term'] ? 'term_id' : 'post_id' => 41, 'cms_post_status' => 'publish' ];
        return $GLOBALS['originals'][$operation] = $result;
    }
    public static function verify( $plan, $result ) { return $result; }
}
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-publication-policy.php';
require dirname( __DIR__ ) . '/includes/class-nova-wordpress-weglot-source-writer.php';
use Nova_WordPress_Weglot_Source_Writer as Writer;
$checks = 0;
function check( $value, $message ) {
    if ( ! $value ) { throw new RuntimeException( $message ); }
    ++$GLOBALS['checks'];
}
function reset_state() {
    $GLOBALS['options'] = $GLOBALS['native'] = $GLOBALS['localized'] = $GLOBALS['originals'] = [];
    $GLOBALS['creates'] = $GLOBALS['locale_writes'] = 0;
    $GLOBALS['existing'] = $GLOBALS['fail_localization'] = false;
    $GLOBALS['slug_taken'] = false;
    $GLOBALS['permitted'] = true;
    $GLOBALS['provider'] = 'weglot';
    $GLOBALS['drafts'] = [];
    $GLOBALS['matching_tags'] = [];
    $GLOBALS['native_url'] = 'https://example.test/topic';
}
function fixture( $term = false, $operation = '00000000-0000-4000-a000-000000000001' ) {
    $content = [ 'site_id' => 'site', 'url_id' => '10', 'language' => 'de-DE', 'url' => 'https://example.test/de/topic',
        'url_family' => [ 'url_group_id' => '20', 'primary_url_id' => '10', 'primary_language' => 'de-DE', 'primary_url' => 'https://example.test/de/topic' ] ];
    $configuration = [ 'site_id' => 'site', 'mode' => $term ? 'static_recipe_v1' : 'stored_layout_v3', 'local' => [ 'routing' => [ 'post_type' => 'page' ] ], 'record' => [],
        'projection' => ['format' => Nova_Bridge_Suite_Category_Recipe::FORMAT] ];
    $source = Writer::descriptor( $content, $configuration );
    $plan = [ 'format' => Writer::FORMAT, 'operation_id' => $operation, 'source' => $source, 'source_id' => 0,
        'source_plan' => [ 'operation_id' => $operation . '-original', 'term' => $term ], 'source_writer' => Source_Test_Writer::class,
        'content' => $content, 'configuration' => $configuration, 'context' => [ 'operation_id' => $operation ],
        'provider' => $source['provider'], 'target' => [ 'target_url' => $source['target_url'] ], 'desired_status' => 'publish' ];
    $plan['digest'] = hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $plan ) );
    return $plan;
}
reset_state();
$plan = fixture();
check( Writer::eligible( $plan['content'] ), 'German-primary group enables source creation' );
$original = $plan['content']; $original['url_family']['primary_language'] = 'en-GB';
check( ! Writer::eligible( $original ), 'An English-primary group keeps its existing flow' );
$GLOBALS['provider'] = 'translatepress';
check( ! Writer::eligible( $plan['content'] ), 'TranslatePress keeps its existing flow' );
$GLOBALS['provider'] = 'weglot';
$ungrouped = $plan['content']; unset( $ungrouped['url_family'] );
check( Writer::eligible( $ungrouped ), 'Ungrouped translated deliveries can create a source for their stable URL identity' );
check( Writer::descriptor( $ungrouped, $plan['configuration'] )['family'] === 'url:10', 'Ungrouped source ownership uses its stable URL ID' );
$invalid_ungrouped = $ungrouped; unset( $invalid_ungrouped['url_id'] );
check( ! Writer::eligible( $invalid_ungrouped ), 'Ordinary source creation requires a stable positive URL ID' );
$ungrouped['url'] = 'https://example.test/de/lorem-ipsum-demo-informative/';
check( Writer::eligible( $ungrouped ), 'A translated demo can create its source without group metadata' );
reset_state();
$single = fixture();
unset( $single['content']['url_family'] );
$single['source'] = Writer::descriptor( $single['content'], $single['configuration'] );
$single['target']['target_url'] = $single['source']['target_url'];
unset( $single['digest'] );
$single['digest'] = hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $single ) );
check( !is_wp_error( Writer::apply( $single, $single['operation_id'] ) ), 'An ungrouped translation creates and populates one original' );
check( !is_wp_error( Writer::recover( $single, $single['operation_id'] ) ) && $GLOBALS['creates'] === 1, 'Ungrouped retries reuse the original without duplication' );
$moved = $single['content']; $moved['url'] = 'https://example.test/de/resolved-topic';
check( Writer::lookup( $moved, $single['configuration'] ) === 41, 'URL reconciliation retains ungrouped source identity' );
reset_state(); $plan = fixture();
$bad = $plan['content']; $bad['url_family']['primary_url'] = 'https://elsewhere.test/de/topic';
check( is_wp_error( Writer::descriptor( $bad, $plan['configuration'] ) ), 'Foreign source URL fails before creation' );
check( Writer::recover( $plan, $plan['operation_id'] )['no_native_commit'], 'Untouched operation has an absence proof' );
foreach ( [ false, true ] as $term ) {
    reset_state(); $plan = fixture( $term );
    $result = Writer::apply( $plan, $plan['operation_id'] );
    check( ! is_wp_error( $result ) && $GLOBALS['creates'] === 1, 'Source creation and localization complete' );
    check( $GLOBALS['locale_writes'] === 1, 'One localized commit is made' );
    check( Writer::apply( $plan, $plan['operation_id'] ) === $result, 'Repeated apply returns the committed result' );
    check( Writer::recover( $plan, $plan['operation_id'] ) === $result, 'Recovery returns the same native result' );
    check( $GLOBALS['creates'] === 1 && $GLOBALS['locale_writes'] === 1, 'Retry makes no duplicate writes' );
    check( Writer::lookup( $plan['content'], $plan['configuration'] ) === 41, 'Group lookup reuses its original' );
    $other = $plan['content']; $other['url_family']['url_group_id'] = '21';
    check( is_wp_error( Writer::lookup( $other, $plan['configuration'] ) ), 'A different group cannot adopt the retained original' );
    $GLOBALS['native'] = [];
    check( is_wp_error( Writer::apply( $plan, $plan['operation_id'] ) ), 'Deleted original is not silently recreated' );
    check( $GLOBALS['creates'] === 1, 'Deletion failure preserves single creation' );
}
reset_state(); $plan = fixture(); $GLOBALS['fail_localization'] = true;
check( is_wp_error( Writer::apply( $plan, $plan['operation_id'] ) ), 'Localization failure is visible' );
$proof = Writer::recover( $plan, $plan['operation_id'] );
check( $proof['safe_to_apply'] && ! $proof['no_native_commit'] && $proof['post_id'] === 41, 'Partial source commit is reported honestly' );
$GLOBALS['fail_localization'] = false;
check( ! is_wp_error( Writer::apply( $plan, $plan['operation_id'] ) ) && $GLOBALS['creates'] === 1, 'Localization resumes on the same original' );
$tampered = $plan; $tampered['source']['source_url'] = 'https://example.test/another';
check( is_wp_error( Writer::apply( $tampered, $plan['operation_id'] ) ), 'Tampered plan is rejected' );
$wrong_mapping = $plan['configuration'];
$wrong_mapping['local']['routing']['locale'] = 'fr-FR';
check( Writer::descriptor( $plan['content'], $wrong_mapping )->code === 'nova_weglot_source_locale', 'Pinned mapping language is enforced' );
$draft_mapping = $plan['configuration'];
$draft_mapping['local']['routing']['publication'] = 'draft';
check( Writer::descriptor( $plan['content'], $draft_mapping )->code === 'nova_weglot_source_publication', 'A draft mapping cannot silently publish a production source' );
$bad_profile = $plan['configuration'];
$bad_profile['profile_digest'] = 'changed';
check( Writer::plan( $plan['content'], $bad_profile, ['operation_id' => $plan['operation_id']] )->code === 'nova_weglot_source_mapping', 'Changed profile is rejected before translation or creation' );
$method = new ReflectionMethod( Writer::class, 'translate' );
if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible( true ); }
$template = ['template' => ['definition' => ['version' => 3, 'fields' => [['id' => 'text', 'value_type' => 'text']]]]];
$json = $method->invoke( null, ['content' => ['text' => '{"id":"opaque-value"}']], $template, $plan['source'] );
check( $json->code === 'nova_weglot_source_structured_field', 'Undeclared JSON is rejected instead of translating structural identifiers' );
$template['template']['definition']['fields'][0]['value_type'] = 'url';
$no_text = $method->invoke( null, ['content' => ['text' => 'https://example.test/image.png']], $template, $plan['source'] );
check( $no_text->code === 'nova_weglot_source_content', 'URL-only data is not sent as editorial content' );
reset_state(); $plan = fixture();
$demo = $plan['content'];
unset( $demo['url_family'] );
$demo['url'] = 'https://example.test/de/lorem-ipsum-demo-informative';
$GLOBALS['native_url'] = 'https://example.test/lorem-ipsum-demo-informative';
$draft = new WP_Post(); $draft->post_status = 'draft';
$GLOBALS['native'][41] = $draft; $GLOBALS['drafts'] = [$draft];
check( Writer::lookup( $demo, $plan['configuration'] ) === 41, 'Existing draft is found by its exact future permalink' );
$other = clone $draft; $other->ID = 42;
$GLOBALS['drafts'][] = $other;
check( Writer::lookup( $demo, $plan['configuration'] )->code === 'nova_weglot_source_ambiguous', 'Ambiguous draft originals prevent creation' );

reset_state(); $plan = fixture();
$generate = new ReflectionMethod( Writer::class, 'generated_url' );
$relocate = new ReflectionMethod( Writer::class, 'relocate' );
$native = [ 'post_fields' => [ 'title' => 'Peptide Modifications' ], 'target' => [ 'parent' => 0 ] ];
$url = $generate->invoke( null, $plan['source'], $native );
check( $url === 'https://example.test/peptide-modifications', 'A new original uses its translated title slug' );
check( strlen( basename( $generate->invoke( null, $plan['source'], [ 'title' => str_repeat( 'Long title ', 50 ) ] ) ) ) <= 200, 'Generated slugs fit the native storage limit' );
check( is_wp_error( $generate->invoke( null, $plan['source'], [ 'title' => '' ] ) ), 'An empty translated title cannot create a source slug' );
$GLOBALS['slug_taken'] = true;
check( $generate->invoke( null, $plan['source'], $native ) === 'https://example.test/peptide-modifications-2', 'WordPress chooses a unique slug for an occupied title' );
$GLOBALS['slug_taken'] = false;
$term = $plan['source']; $term['term'] = true; $term['type'] = 'product_tag'; $term['source_url'] = 'https://example.test/product-tag/beispiel';
check( $generate->invoke( null, $term, [ 'payload' => [ 'name' => 'Research Peptides' ] ] ) === 'https://example.test/product-tag/research-peptides', 'A taxonomy original uses the translated term title and native base' );
$matching = new ReflectionMethod( Writer::class, 'existing_tag_url' );
$GLOBALS['matching_tags']['ss-31'] = (object) [ 'term_id' => 60, 'slug' => 'ss-31' ];
check( $matching->invoke( null, 'https://example.test/product-tag/buy-ss-31', 'en' ) === 'https://example.test/product-tag/ss-31', 'Buy aliases reuse the existing product tag' );
check( $matching->invoke( null, 'https://example.test/product-tag/ss-31-kaufen', 'en' ) === 'https://example.test/product-tag/ss-31', 'Localized buy suffixes reuse the native product tag' );
check( $matching->invoke( null, 'https://example.test/product-tag/buy-new-tag', 'en' ) === 'https://example.test/product-tag/buy-new-tag', 'A missing tag retains the normal creation path' );
$GLOBALS['matching_tags']['buy-ss-31'] = (object) [ 'term_id' => 61, 'slug' => 'buy-ss-31' ];
check( $matching->invoke( null, 'https://example.test/product-tag/buy-ss-31', 'en' ) === 'https://example.test/product-tag/buy-ss-31', 'An explicit existing URL keeps its exact destination' );
$GLOBALS['matching_tags']['topic-kaufen'] = (object) [ 'term_id' => 70, 'slug' => 'topic-kaufen' ];
$GLOBALS['matching_tags']['buy-topic'] = (object) [ 'term_id' => 71, 'slug' => 'buy-topic' ];
check( is_wp_error( $matching->invoke( null, 'https://example.test/product-tag/buy-topic-kaufen', 'en' ) ), 'Ambiguous commercial aliases cannot select or create a tag' );
$GLOBALS['matching_tags'] = [];
$plan['source'] = $relocate->invoke( null, $plan['source'], $url );
$plan['generated_source_url'] = $url;
$plan['source_plan']['target']['target_url'] = $url;
$plan['target']['target_url'] = $plan['source']['target_url'];
unset( $plan['digest'] ); $plan['digest'] = hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $plan ) );
check( ! is_wp_error( Writer::apply( $plan, $plan['operation_id'] ) ), 'A generated native URL survives first apply validation' );
check( Writer::lookup( $plan['content'], $plan['configuration'] ) === 41, 'The original translated slug still finds the created source' );
$sibling = $plan['content']; $sibling['language'] = 'fr-FR'; $sibling['url'] = 'https://example.test/fr/modifications-peptides';
check( Writer::lookup( $sibling, $plan['configuration'] ) === 41, 'A sibling with its own slug reuses the retained original' );
$reported = $plan['content']; $reported['url_family']['primary_url'] = 'https://example.test/de/peptide-modifications';
check( Writer::lookup( $reported, $plan['configuration'] ) === 41, 'A reported canonical primary URL reuses the same source' );
check( ! is_wp_error( Writer::recover( $plan, $plan['operation_id'] ) ) && $GLOBALS['creates'] === 1, 'A generated URL remains recoverable after association registration' );
echo "$checks missing-original checks passed\n";
