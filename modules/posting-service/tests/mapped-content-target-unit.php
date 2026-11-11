<?php
/** Native URL seams, no HTTP and no database mutation. Run directly with PHP. */
define('ABSPATH', __DIR__); $checks=0; $denied=[]; $options=['home'=>'https://site.example/sub','permalink_structure'=>'/blog/%postname%/'];
class WP_Error {private $code;private $message;private $data;function __construct($code,$message,$data=[]){$this->code=$code;$this->message=$message;$this->data=$data;} function get_error_code(){return $this->code;} function get_error_message(){return $this->message;} function get_error_data(){return $this->data;} }
function is_wp_error($v){return $v instanceof WP_Error;} function current_user_can($cap,...$args){return !in_array($cap.':'.implode(':',$args),$GLOBALS['denied'],true);}
function wp_parse_url($v){return parse_url($v);} function get_option($key,$default=false){return $GLOBALS['options'][$key] ?? $default;} function home_url($path='/'){return 'https://site.example/sub'. $path;} function get_locale(){return 'nl_NL';}
function has_filter($name){return !empty($GLOBALS['wpml']) && $name==='wpml_element_language_details';}
function sanitize_title($v){return trim(preg_replace('/[^a-z0-9]+/','-',strtolower($v)),'-');}
$links=[1=>'https://site.example/sub/parent/shared/',2=>'https://site.example/sub/other/shared/',3=>'https://site.example/sub/parent/',4=>'https://site.example/sub/en/parent/shared/'];$term_links=[10=>'https://site.example/sub/product-category/floor/',11=>'https://site.example/sub/product-category/floor/wood/'];
function get_posts($args){$ids=($args['post_type']==='page')?array_keys($GLOBALS['links']):[];return array_slice($ids,$args['offset'], $args['posts_per_page']);}
function get_terms($args){return array_slice(array_keys($GLOBALS['term_links']),$args['offset'],$args['number']);}
function get_permalink($id){return $GLOBALS['links'][$id]??'';} function get_term_link($id,$taxonomy=''){return $GLOBALS['term_links'][$id]??'';}
function get_term($id,$taxonomy=''){return (object)['term_id'=>$id,'term_taxonomy_id'=>$id+100];}
function get_taxonomy($name){return $name==='product_cat' ? (object)['rewrite'=>['hierarchical'=>true]] : null;}
function get_post_type_object($name){return in_array($name,['page','post'],true)?(object)['hierarchical'=>$name==='page']:null;}
class Rewrite {function get_extra_permastruct($name){return '/product-category/%product_cat%/';}function get_page_permastruct(){return '/%pagename%/';}} $wp_rewrite=new Rewrite();
class Nova_Bridge_Suite_Document_Mapping {static function resources(){return [['id'=>'post_type:page','type'=>'post_type','post_type'=>'page','availability'=>'available','can_inspect_existing'=>true],['id'=>'taxonomy:product_cat','type'=>'taxonomy','taxonomy'=>'product_cat','availability'=>'available','can_inspect_existing'=>true]];}}
function apply_filters($name,$v,...$args){
 if($name==='wpml_permalink'){return $args[0]==='en'?'https://site.example/sub/en/parent/shared/':$v;}
 if($name==='wpml_active_languages'){return ['nl'=>['default_locale'=>'nl_NL'],'en'=>['default_locale'=>'en_GB']];}
 if($name==='wpml_element_language_details'){$id=$args[0]['element_id'];return (object)['language_code'=>$id===4?'en':'nl'];}
 if($name==='wpml_object_id'){[$type,$fallback,$language]=$args;return $v===1&&$language==='en'?4:($v===4&&$language==='nl'?1:($language==='nl'?$v:0));}return $v;
}
function check($yes,$message){++$GLOBALS['checks'];if(!$yes){throw new RuntimeException($message);}}
function good($v,$message){check(!is_wp_error($v),$message.(is_wp_error($v)?': '.$v->get_error_message():''));return $v;}
function bad($v,$code){check(is_wp_error($v)&&$v->get_error_code()==='nova_mapped_'.$code,'Expected '.$code.(is_wp_error($v)?', got '.$v->get_error_code():''));}
require dirname(__DIR__).'/includes/class-nova-bridge-suite-mapped-content-target.php';use Nova_Bridge_Suite_Mapped_Content_Target as Target;
$a=good(Target::normalize_url('https://SITE.example:443/sub/%C3%A9/?b=two+words&a=%21'),'Normalize canonical URL.');check($a['identity']==='https://site.example/sub/%C3%A9?a=%21&b=two%20words','Stable service-compatible path/query canonicalization.');
foreach(['https://x.example/a%2fb','https://x.example/a//b','https://x.example/%2e%2e/x','https://u:p@x.example/a','https://x.example/a#x','http://x.example/a','https://x.example/a?a=1&a=2'] as $url){bad(Target::normalize_url($url),'target_url');}
$item=good(Target::resolve('https://SITE.example:443/sub/parent/shared'),'Resolve exact hierarchy.');check($item['reference_id']===1 && $item['requested_url']==='https://site.example/sub/parent/shared','Parent hierarchy keeps similarly named sibling distinct.');
bad(Target::resolve('https://site.example/sub/shared/'),'target_not_found');bad(Target::resolve('https://foreign.example/sub/parent/shared/'),'foreign_target_url');bad(Target::resolve('https://site.example/submarine/parent/shared/'),'foreign_target_url');bad(Target::resolve('https://site.example/sub/parent/shared/','taxonomy:product_cat'),'target_resource_mismatch');
$denied=['edit_post:1'];bad(Target::resolve($links[1]),'target_permission');$denied=[];
require_once __DIR__ . '/posting-actor-worker-fixture.php';
function get_current_user_id() { return 2; }
function get_taxonomies( $args ) { return [ 'product_cat' => 'product_cat' ]; }
$denied = [ 'manage_options:' ];
good( Target::creation( 'post_type:page', 'https://site.example/sub/parent/new-page/' ), 'A site editor can resolve a new page using native URLs without access to admin settings.' );
$denied[] = 'edit_post:1'; bad( Target::resolve( $links[1] ), 'target_permission' );
$denied[] = 'edit_term:11'; bad( Target::resolve( $term_links[11] ), 'target_permission' );
$revoked_actor = 2; bad( Target::resolve( $links[1] ), 'permission' ); unset( $revoked_actor );
$denied = [];
$links[5]=$links[1];bad(Target::resolve($links[1]),'target_ambiguous');unset($links[5]);
$term=good(Target::resolve($term_links[11]),'Resolve actual term link.');check($term['reference_id']===11 && $term['resource_id']==='taxonomy:product_cat','Taxonomy ownership is native and exact.');
good(Target::resolve($links[1],null,'nl'),'Single-locale site accepts its own language.');bad(Target::resolve($links[1],null,'en'),'target_language_unsupported');
$wpml=true;$links[4]=$links[1];$translated=good(Target::resolve($links[1],null,'en-GB'),'WPML resolves a proven translated object even when plain permalinks reflect the request language.');check($translated['reference_id']===4 && $translated['url']==='https://site.example/sub/en/parent/shared/' && $translated['requested_url']==='https://site.example/sub/parent/shared' && $translated['language']==='en-GB','Translation preserves requested source URL while exporting the proven target URL.');bad(Target::resolve($links[2],null,'en'),'target_translation_missing');$wpml=false;$links[4]='https://site.example/sub/en/parent/shared/';
$new=good(Target::creation('post_type:page','https://site.example/sub/parent/new-page/'),'Plan a new page under a locally resolved native parent.');check($new['parent']===3 && $new['slug']==='new-page','No caller-supplied native parent ID needed.');
$new=good(Target::creation('taxonomy:product_cat','https://site.example/sub/product-category/floor/new-category/'),'Plan new category hierarchy.');check($new['parent']===10 && $new['slug']==='new-category','Category parent comes from the actual native URL.');
bad(Target::creation('taxonomy:product_cat','https://site.example/sub/wrong/new-category/'),'create_route');bad(Target::creation('post_type:page',$links[1]),'target_exists');bad(Target::creation('post_type:page','https://site.example/sub/missing/new/'),'target_not_found');bad(Target::creation('post_type:page','https://site.example/sub/new/', 'en'),'create_language_unsupported');
$post=good(Target::creation('post_type:post','https://site.example/sub/blog/new-post/'),'Plan native fixed-prefix post permalinks.');check($post['slug']==='new-post' && $post['parent']===0,'Static blog route is not mistaken for a page parent.');$options['permalink_structure']='/%year%/%postname%/';bad(Target::creation('post_type:post','https://site.example/sub/2026/new-post/'),'create_route');
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout-language.php';
$options['home'] = 'https://www.site.example';
$options['permalink_structure'] = '/%postname%/';
$delivery_url = Nova_Bridge_Suite_Stored_Layout_Language::delivery_url( 'https://site.example/test-article/', 'nl-NL' );
$post = good( Target::creation( 'post_type:post', $delivery_url, 'nl-NL' ), 'Create a native Post from a bare-host delivery on a www installation.' );
check( $post['target_url'] === 'https://www.site.example/test-article' && $post['parent'] === 0, 'Native Post routing uses the canonical permalink and retains the supplied path.' );
// Dated post permalinks: NOVA delivers the undated slug URL; WordPress serves the post at /<date>/<slug>/.
$options['permalink_structure'] = '/%year%/%monthnum%/%day%/%postname%/';
$dated = good( Target::creation( 'post_type:post', 'https://www.site.example/dated-article/', 'nl-NL', null, true ), 'Plan a post at its slug under a dated post permalink structure.' );
check( $dated['slug'] === 'dated-article' && $dated['parent'] === 0 && $dated['target_url'] === 'https://www.site.example/dated-article', 'A dated post keeps the undated delivery URL as its identity.' );
bad( Target::creation( 'post_type:post', 'https://www.site.example/2026/11/11/dated-article/', 'nl-NL', null, true ), 'create_route' );
check( Target::same_native_url( 'https://www.site.example/2026/11/11/dated-article', 'https://www.site.example/dated-article', 'post_type:post' ), 'The dated permalink is the delivery URL of that post.' );
check( ! Target::same_native_url( 'https://www.site.example/2026/11/11/other-article', 'https://www.site.example/dated-article', 'post_type:post' ), 'A renamed slug is not the delivery URL.' );
check( ! Target::same_native_url( 'https://www.site.example/2026/11/dated-article', 'https://www.site.example/dated-article', 'post_type:post' ), 'The date must fill the whole configured structure.' );
check( ! Target::same_native_url( 'https://www.site.example/2026/11/11/dated-article', 'https://www.site.example/dated-article', 'post_type:page' ), 'Pages still need their exact URL.' );
check( ! Target::same_native_url( 'https://other.example/2026/11/11/dated-article', 'https://www.site.example/dated-article', 'post_type:post' ), 'Another host is never the delivery URL.' );
bad( Target::creation( 'post_type:post', 'https://www.site.example/dated-article/', 'nl-NL' ), 'create_route' );
$options['permalink_structure'] = '/%category%/%postname%/';
check( null === Target::dated_post_structure(), 'Only post-date tags qualify as a dated structure.' );
bad( Target::creation( 'post_type:post', 'https://www.site.example/dated-article/', 'nl-NL', null, true ), 'create_route' );
echo "PASS $checks mapped URL ownership and routing checks.\n";
