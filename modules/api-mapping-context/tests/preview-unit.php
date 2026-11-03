<?php
/** Standalone preview lifecycle, authentication and URL regression checks. */
define('ABSPATH', __DIR__);
$hooks = []; $logged = true; $admin = true; $entity = true;
function add_action($hook, $callback, $priority = 10) { $GLOBALS['hooks'][$hook][] = $callback; }
function add_filter(...$args) {}
function remove_action(...$args) {}
function nocache_headers() {}
function is_user_logged_in() { return $GLOBALS['logged']; }
function current_user_can($cap) { return $GLOBALS['admin']; }
function wp_verify_nonce($nonce, $action) { return $nonce === $action; }
function wp_create_nonce($action) { return $action; }
function wp_die($message, $title, $args) { throw new RuntimeException($message, $args['response']); }
function sanitize_key($s) { return $s; }
function wp_unslash($s) { return $s; }
function absint($v) { return abs((int)$v); }
function wp_parse_url($url,$part=-1) { return parse_url($url,$part); }
function admin_url() { return 'https://example.test/wp-admin/'; }
function home_url($path) { return 'https://example.test/shop/'; }
function get_post_status($id) { return $GLOBALS['status'] ?? 'publish'; }
function get_preview_post_link($id) { return $GLOBALS['foreign'] . '?preview=true'; }
function get_post_type($id) { return $GLOBALS['ref_type'] ?? 'page'; }
function apply_filters($hook,$value,...$args) {
 if($hook==='wpml_setting')return $GLOBALS['negotiation'] ?? 0;
 if($hook==='wpml_element_language_code')return $args[0]===['element_id'=>1,'element_type'=>'post_'.get_post_type(1)] ? 'de' : null;
 if($hook==='wpml_permalink')return $args[0]==='de' ? 'https://german.test/shop/' : $value;
 return $value;
}
function wp_generate_uuid4() { static $n = 0; return 'request-' . ++$n; }
function add_query_arg($args, $url) { return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args); }
class Nova_Bridge_Suite_Strategy { static function entity($type, $id) { return $GLOBALS['entity']; } }
require dirname(__DIR__) . '/includes/class-nova-bridge-suite-mapping-preview.php';
function check($ok) { if (!$ok) { throw new RuntimeException('Preview regression failed'); } }
Nova_Bridge_Suite_Mapping_Preview::bootstrap();
check($hooks['wp_loaded'][0][1] === 'prepare' && $hooks['init'][0][1] === 'no_cache');
$_GET = ['nova_mapping_preview'=>'post', 'nova_reference'=>1, '_nova_nonce'=>'nova_mapping_preview:post:1'];
Nova_Bridge_Suite_Mapping_Preview::prepare();
foreach (['logged'=>[401,'login session'], 'admin'=>[403,'Administrator'], 'entity'=>[403,'reference']] as $key=>$expect) {
 $GLOBALS[$key] = false;
 try { Nova_Bridge_Suite_Mapping_Preview::prepare(); throw new RuntimeException('Access unexpectedly allowed'); }
 catch (RuntimeException $e) { check($e->getCode() === $expect[0] && str_contains($e->getMessage(), $expect[1])); }
 $GLOBALS[$key] = true;
}
$_GET['_nova_nonce'] = 'bad';
try { Nova_Bridge_Suite_Mapping_Preview::prepare(); throw new RuntimeException('Invalid nonce allowed'); }
catch (RuntimeException $e) { check($e->getCode() === 403 && str_contains($e->getMessage(), 'Refresh preview')); }
$ref = ['reference_type'=>'post', 'reference_id'=>1, 'url'=>'http://www.example.test/page/?x=1'];
$first = Nova_Bridge_Suite_Mapping_Preview::url($ref);
check(str_starts_with($first, 'https://example.test/page/?x=1&'));
check($first !== Nova_Bridge_Suite_Mapping_Preview::url($ref));
$ref['url'] = 'https://other.test/page/';
check(Nova_Bridge_Suite_Mapping_Preview::url($ref) === '');
$sitepress = new class {
 public $switches = [];
 function switch_lang($code,$cookie=false) { $this->switches[] = [$code,$cookie]; }
};
$foreign = 'https://german.test/shop/same-slug/';
$ref['url'] = $foreign;
$negotiation = 1;
check(Nova_Bridge_Suite_Mapping_Preview::url($ref) === '');
$negotiation = 2;
foreach (['https://german.test.evil.test/shop/', 'https://german.test:8443/shop/', 'https://user@german.test/shop/'] as $untrusted) {
 check(Nova_Bridge_Suite_Mapping_Preview::url(array_merge($ref,['url'=>$untrusted])) === '');
}
$ref_type = 'page';
$url = Nova_Bridge_Suite_Mapping_Preview::url($ref);
check(str_starts_with($url,'https://example.test/shop/?page_id=1&') && !str_contains($url,'same-slug'));
parse_str(parse_url($url,PHP_URL_QUERY),$_GET);
$entity=['post_type'=>'page','url'=>$foreign];
$signed=$_GET;
$_GET['_nova_nonce']='bad';
try { Nova_Bridge_Suite_Mapping_Preview::prepare(); throw new RuntimeException('Invalid language switch'); }
catch(RuntimeException $e){check($e->getCode()===403 && !$sitepress->switches);}
$_GET=$signed;
$_GET['lang']='fr';
Nova_Bridge_Suite_Mapping_Preview::prepare();
check($sitepress->switches===[['de',false]]);
$entity['url']='https://foreign.test/';
try { Nova_Bridge_Suite_Mapping_Preview::prepare(); throw new RuntimeException('Unregistered domain allowed'); }
catch(RuntimeException $e){check($e->getCode()===409 && count($sitepress->switches)===1);}
$status='draft'; $ref_type='service';
$draft=Nova_Bridge_Suite_Mapping_Preview::url($ref);
check(str_starts_with($draft,'https://example.test/shop/?p=1&post_type=service&'));
parse_str(parse_url($draft,PHP_URL_QUERY),$_GET);
$entity=['post_type'=>'service','url'=>$foreign];
Nova_Bridge_Suite_Mapping_Preview::prepare();
check(count($sitepress->switches)===2);
$term=Nova_Bridge_Suite_Mapping_Preview::url(['reference_type'=>'term','reference_id'=>1,'url'=>$foreign]);
check(str_starts_with($term,$foreign) && !str_contains($term,'nova_wpml_domain_preview'));
echo "PASS preview lifecycle, authentication, nonce, reference permissions, origin and fresh URLs\n";
