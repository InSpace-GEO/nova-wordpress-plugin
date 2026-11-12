<?php
/** Exercise the actual registered-field transport with isolated WordPress/ACF storage. */
define( 'ABSPATH', __DIR__ );
class WP_Error { public function __construct( $name, $message, ...$args ) { $this->message = $message; } public $message; }
class WP_Post_Type { public $name = 'page'; public $cap; public function __construct() { $this->cap = (object) [ 'create_posts'=>'edit_pages' ]; } }
class WP_Post { public $ID, $post_type = 'page'; public function __construct( $id ) { $this->ID = $id; } }
class WP_REST_Posts_Controller {}
class Nova_Bridge_Suite_Stored_Layout_Language { public static function provider() { return $GLOBALS['provider']; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_post( $id ) { return new WP_Post( $id ); }
function get_post_type( $id ) { return 'page'; }
function get_post_type_object( $type ) { return new WP_Post_Type(); }
function get_registered_meta_keys( $kind, $type = '' ) { return $type ? $GLOBALS['registered'] : []; }
function current_user_can( $cap, ...$args ) { return $GLOBALS['allowed'] && ( 'edit_post_meta' !== $cap || empty( $GLOBALS['deny_seo_meta'] ) ); }
function has_filter( $name ) { return ! empty( $GLOBALS['authorization_hooks'][$name] ); }
function is_multisite() { return ! empty( $GLOBALS['network_plugins'] ); }
function get_site_option( $name, $fallback = false ) { return $GLOBALS['network_plugins'] ?? $fallback; }
class SEORankHelper { public static function has_cap( $cap ) { return 'onpage_snippet' === $cap && empty( $GLOBALS['deny_rank_snippet'] ); } }
class_alias( SEORankHelper::class, 'RankMath\\Helper' );
function get_current_user_id() { return 1; }
function is_protected_meta( $name, $kind ) { return $name[0] === '_'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_kses_post( $value ) { return strip_tags( $value, '<p><strong>' ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_meta( $name, $value, $kind, $type ) { return 'deck' === $name && ! empty( $GLOBALS['prefix'] ) ? 'Site: ' . trim( $value ) : trim( $value ); }
function rest_validate_value_from_schema( $value, $schema, $name ) { return is_string( $value ); }
function rest_sanitize_value_from_schema( $value, $schema, $name ) { return $value; }
function wp_slash( $value ) { return is_array($value) ? array_map('wp_slash',$value) : addslashes($value); }
function fixture_unslash($value) { return is_array($value) ? array_map('fixture_unslash',$value) : stripslashes($value); }
function get_post_meta( $id, $name, $single = false ) { $rows = $GLOBALS['store'][$id][$name] ?? []; return $single ? ( $rows[0] ?? '' ) : $rows; }
function update_post_meta( $id, $name, $value ) { $value = fixture_unslash( $value ); $GLOBALS['store'][$id][$name] = [ isset( $GLOBALS['registered'][$name] ) ? sanitize_meta( $name, $value, 'post', 'page' ) : $value ]; return true; }
function acf_get_field_groups( $screen ) { return ( $screen['page_template'] ?? '' ) === 'landing.php' ? [ [ 'key'=>'group_example' ] ] : []; }
function acf_get_fields( $group ) { return [ $GLOBALS['field'] ]; }
function update_field( $key, $value, $id ) {
    if ( $key !== $GLOBALS['field']['key'] ) { throw new RuntimeException( 'ACF must receive the declared key' ); }
    if ( $GLOBALS['drop'] ) { return false; }
    update_post_meta( $id, 'caption', $value );
    if ( ! empty( $GLOBALS['drop_reference'] ) ) { return false; }
    return update_post_meta( $id, '_caption', $key );
}
function get_field( $key, $id, $format ) { return get_post_meta( $id, 'caption', true ); }
function get_option( $key, $default = false ) { return $key === 'active_plugins' ? ($GLOBALS['seo_plugins'] ?? []) : ($key === 'polylang' ? $GLOBALS['pll_options'] : $default); }
function pll_get_post_translations( $id ) { return [ 'fr'=>10,'nl'=>20 ]; }
function apply_filters( $name, $value, ...$args ) {
    if ( $name === 'wpml_element_trid' ) { return 50; }
    if ( $name === 'wpml_get_element_translations' ) { return ['de'=>10,'en'=>20]; }
    return $name === 'pll_copy_post_metas' ? $GLOBALS['pll_extra_sync'] : $value;
}
require dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-content-transport.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-custom-fields.php';
use Nova_Bridge_Suite_Stored_Custom_Fields as Fields;
$checks = 0;
function verify( $ok, $description ) { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( $description ); } }
$field = [ 'name'=>'caption','key'=>'field_caption','type'=>'wysiwyg' ];
$registered = [ 'deck'=>[ 'type'=>'string','single'=>true,'show_in_rest'=>true,'auth_callback'=>static function() { return true; } ] ];
$allowed = true; $drop = false; $provider = 'core'; $pll_options = []; $pll_extra_sync = [];
$store = [ 10=>[ 'unselected'=>['unchanged'] ], 20=>['caption'=>['Dutch'], '_caption'=>['field_caption']] ];
$fields = [ 'caption'=>[ 'provider'=>'acf','key'=>'field_caption','type'=>'wysiwyg','value'=>'<p>French <strong>copy</strong>.</p>' ], 'deck'=>[ 'provider'=>'meta','key'=>'deck','type'=>'string','value'=>'  French summary  ' ] ];
$frozen = Fields::prepare( 'page', 0, 'landing.php', $fields );
verify( ! is_wp_error( $frozen ) && $frozen['deck']['value'] === 'French summary', 'Create uses native field and REST normalization' );
$plan = [ 'post_type'=>'page','custom_template'=>'landing.php','custom_fields'=>$frozen ];
$before = Fields::snapshot( 10, $frozen );
verify( true === Fields::finish( $plan, 10, $before, true ) && true === Fields::finish( $plan, 10, [], false ), 'First native save writes and verifies field keys and values' );
verify( $store[10]['_caption'] === ['field_caption'] && $store[20]['caption'] === ['Dutch'] && $store[10]['unselected'] === ['unchanged'], 'Unmapped fields and other language stay independent' );
verify( Fields::read( 'page', 10, 'landing.php', $fields )['caption'] === $fields['caption']['value'], 'Dictionary source reads the original without mutating it' );
$replacement = $fields; $replacement['caption']['value'] = '<p>Updated</p>'; $replacement['deck']['value'] = 'New summary';
$changed = $plan; $changed['custom_fields'] = Fields::prepare( 'page', 10, 'landing.php', $replacement );
$before = Fields::snapshot( 10, $frozen ); $drop = true;
verify( is_wp_error( Fields::finish( $changed, 10, $before, true ) ) && $store[10]['deck'] === ['New summary'], 'Lost second write is detected after first write' );
$drop = false;
verify( true === Fields::finish( $changed, 10, $before, true ) && true === Fields::finish( $changed, 10, $before, true ), 'Same operation completes only remaining values and repeats idempotently' );
$store[10]['caption'] = ['Edited by an author'];
verify( is_wp_error( Fields::finish( $changed, 10, $before, true ) ) && $store[10]['caption'] === ['Edited by an author'], 'Recovery does not overwrite a third value' );
$store[10]['_caption'] = ['field_someone_else'];
verify( is_wp_error( Fields::read( 'page', 10, 'landing.php', $fields ) ), 'Wrong hidden ACF reference is never adopted' );
$quoted = 'The author\'s "C:\Templates" and \\'.'\\share';
$literal_plan = $changed;
$literal_plan['custom_fields']['deck']['value'] = $literal_plan['custom_fields']['caption']['value'] = $quoted;
$literal_before = Fields::snapshot( 30, $literal_plan['custom_fields'], true ); $drop_reference = true;
verify( is_wp_error( Fields::finish( $literal_plan, 30, $literal_before, true ) ) && $store[30]['caption'] === [$quoted] && $store[30]['deck'] === [$quoted], 'Native ACF/REST storage preserves literal backslashes and quotes even if reference write is interrupted' );
verify( is_wp_error( Fields::snapshot( 30, $literal_plan['custom_fields'], true ) ), 'A later operation cannot adopt a raw meta value missing its ACF reference' );
$drop_reference = false;
verify( true === Fields::finish( $literal_plan, 30, $literal_before, true ) && $store[30]['_caption'] === ['field_caption'], 'The original journal can resume after raw ACF value but before the reference was written' );
$prefix = true; $native_sanitizer = $plan; $native_sanitizer['custom_fields'] = Fields::prepare( 'page', 0, 'landing.php', [ 'deck' => $fields['deck'] ] );
verify( Fields::prepare( 'page', 40, 'landing.php', $native_sanitizer['custom_fields'] ) === $native_sanitizer['custom_fields'] && true === Fields::finish( $native_sanitizer, 40, Fields::snapshot( 40, $native_sanitizer['custom_fields'] ), true ) && $store[40]['deck'] === [ 'Site: French summary' ], 'Native REST sanitizer runs on the original input even when it is not idempotent' );
$prefix = false;
verify( is_wp_error( Fields::prepare( 'page', 0, 'different.php', $fields ) ), 'Template-specific field must apply to destination' );
$allowed = false;
verify( is_wp_error( Fields::prepare( 'page', 10, 'landing.php', $fields ) ), 'Per-post field permissions checked' );
$allowed = true; $provider = 'wpml'; $copy = 1;
$sitepress = new class { public function get_setting( $key ) { return ['custom_fields_translation'=>['caption'=>$GLOBALS['copy']]]; } };
foreach ( [ 1, 3 ] as $copy ) { verify( is_wp_error( Fields::prepare( 'page', 0, 'landing.php', $fields ) ), 'WPML copy policy blocks before native insert' ); }
verify( ! is_wp_error( Fields::prepare( 'page', 10, 'landing.php', $fields ) ), 'Existing WPML translation may edit a Copy Once field' );
$copy = 2; verify( ! is_wp_error( Fields::prepare( 'page', 0, 'landing.php', $fields ) ), 'WPML translated fields accepted' );
$field['wpml_cf_preferences'] = 1; verify( is_wp_error( Fields::prepare( 'page', 0, 'landing.php', $fields ) ), 'ACFML explicit copy policy blocks' );
$provider = 'polylang'; $pll_options = ['sync'=>['post_meta']];
verify( is_wp_error( Fields::prepare( 'page', 0, 'landing.php', $fields ) ), 'Polylang copy synchronization blocks before native insert' );
verify( ! is_wp_error( Fields::prepare( 'page', 10, 'landing.php', $fields ) ), 'Field-level Polylang exclusions override global sync for an existing translation' );
$pll_options = []; $pll_extra_sync = ['deck']; verify( is_wp_error( Fields::prepare( 'page', 10, 'landing.php', $fields ) ), 'Custom Polylang field synchronization blocks when editing siblings' );
$provider = 'core'; $allowed = true; $pll_extra_sync = []; $drop = false; $drop_reference = false;
foreach ( [ 'yoast'=>['wordpress-seo/wp-seo.php','_yoast_wpseo_title'], 'rank_math'=>['seo-by-rank-math/rank-math.php','rank_math_title'], 'seopress'=>['wp-seopress/seopress.php','_seopress_titles_title'], 'tsf'=>['autodescription/autodescription.php','_genesis_title'], 'smartcrawl'=>['smartcrawl-seo/wpmu-dev-seo.php','_wds_title'], 'slim_seo'=>['slim-seo/slim-seo.php','slim_seo'] ] as $seo_provider=>$config ) {
    $seo_plugins = [$config[0]]; $path = '/@nova/seo/'.$seo_provider.'/title';
    $store[10][$config[1]] = [$seo_provider === 'slim_seo' ? ['title'=>'Old SEO','description'=>'Keep description','canonical'=>'/keep/'] : 'Old SEO'];
    $seo = [$path=>['provider'=>'seo','key'=>$seo_provider,'type'=>'title','value'=>'New search title']];
    $prepared = Fields::prepare( 'page',10,'landing.php',$seo ); verify(!is_wp_error($prepared),'Supported SEO field prepares');
    $seo_plan = ['post_type'=>'page','custom_template'=>'landing.php','custom_fields'=>$prepared];
    $old = Fields::snapshot(10,$prepared);
    verify( true === Fields::finish($seo_plan,10,$old,true) && true === Fields::finish($seo_plan,10,[],false),'SEO writes verify and are idempotent');
    if($seo_provider === 'slim_seo') verify($store[10]['slim_seo'][0]===['title'=>'New search title','description'=>'Keep description','canonical'=>'/keep/'],'Slim SEO merges only the mapped leaf');
    $seo_plugins=[]; verify(is_wp_error(Fields::prepare('page',10,'landing.php',$seo)),'Inactive SEO provider fails before writing');
}
$seo_plugins=['wordpress-seo/wp-seo.php'];
$unknown=['/@nova/seo/yoast/canonical'=>['provider'=>'seo','key'=>'yoast','type'=>'canonical','value'=>'/wrong/']];
verify(is_wp_error(Fields::prepare('page',10,'landing.php',$unknown)),'Arbitrary metadata and canonical mutations are not exposed');
$allowed=false;verify(is_wp_error(Fields::prepare('page',10,'landing.php',['/@nova/seo/yoast/title'=>['provider'=>'seo','key'=>'yoast','type'=>'title','value'=>'Wrong']])),'SEO honors native post permissions');$allowed=true;
$rank = [ '/@nova/seo/rank_math/title'=>['provider'=>'seo','key'=>'rank_math','type'=>'title','value'=>'Allowed title'] ];
$seo_plugins=['seo-by-rank-math/rank-math.php']; $deny_seo_meta=true;
verify(!is_wp_error(Fields::prepare('page',10,'landing.php',$rank)), 'Hidden plugin keys use the native snippet permission, not generic Custom Fields visibility');
$deny_rank_snippet=true;verify(is_wp_error(Fields::prepare('page',10,'landing.php',$rank)), 'Rank Math snippet permission cannot be bypassed');$deny_rank_snippet=false;
$authorization_hooks=['auth_post_meta_rank_math_title_for_page'=>true];
verify(is_wp_error(Fields::prepare('page',10,'landing.php',$rank)), 'An explicit native metadata authorization callback is honored');
$authorization_hooks=[];$deny_seo_meta=false;$seo_plugins=[];
$network_plugins=['wordpress-seo-premium/wp-seo-premium.php'=>123];
verify(array_keys(Nova_WordPress_Layout_SEO::active())===['yoast']&&count(Nova_WordPress_Layout_SEO::catalog())===2, 'Network-activated premium Yoast exposes the same stable destinations');$network_plugins=[];
echo "Stored custom field checks: $checks passed.\n";
