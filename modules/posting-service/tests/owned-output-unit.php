<?php
/** Exercise provenance through real WordPress HTML parsing. */
$argv[1] = 'weglot';
require __DIR__ . '/localized-custom-fields-unit.php';

class Owned_Test_Widget {
    private $settings;
    public function __construct(array $settings) { $this->settings = $settings; }
    public function get_id() { return 'owned_widget'; }
    public function get_settings() { return $this->settings; }
    public function set_settings($key, $value) { $this->settings[$key] = $value; }
}
$settings = ['mapped' => 'Research', 'preserved' => 'Research', 'link' => 'https://lang.test/de/research', 'body' => '<p>Mapped body <strong>with formatting</strong>.</p>'];
$owned = [];
foreach (['mapped'=>'text','link'=>'url','body'=>'html'] as $key=>$type) {
    $owned[] = ['path'=>[$key], 'type'=>$type, 'hash'=>hash('sha256',$settings[$key])];
}
Nova_WordPress_Owned_Output::register(['owned_widget'=>$owned]);
$widget = new Owned_Test_Widget($settings);
Nova_WordPress_Owned_Output::begin($widget);
$during = $widget->get_settings();
$rendered = '<main><h1>' . esc_html($during['mapped']) . '</h1><h2>' . esc_html($during['preserved']) . '</h2>'
    . '<a href="' . esc_url($during['link']) . '">Mapped link</a><a href="' . esc_url($settings['link']) . '">Preserved link</a>' . $during['body'] . '</main>';
$rendered = Nova_WordPress_Owned_Output::finish($rendered,$widget);
verify(str_contains($rendered,'<h1><span data-wg-notranslate>Research</span></h1>'),'Mapped text identical to source text is protected');
verify(str_contains($rendered,'<h2>Research</h2>'),'An identical preserved value in the same widget stays eligible for translation');
verify(!str_contains($rendered,'NOVAOWNED_') && !str_contains($rendered,'#nova-owned-'),'Internal markers are removed before the page is returned');
verify($widget->get_settings()===$settings,'Rendering restores the original widget settings');
verify(str_contains($rendered,'<strong><span data-wg-notranslate>with formatting</span></strong>'),'Rich-text formatting and field protection coexist');
$rewritten = str_replace('https://lang.test/de/research','https://lang.test/translated-by-provider',$rendered);
$restored = Nova_WordPress_Owned_Output::restore_attributes($rewritten);
verify(substr_count($restored,'https://lang.test/de/research')===1,'Only the mapped URL receives its final authored value');
verify(str_contains($restored,'https://lang.test/translated-by-provider'),'Preserved links keep provider URL translation');
verify(!str_contains($restored,'data-nova-owned-attrs'),'Temporary attribute bookkeeping is removed');
verify(Nova_WordPress_Owned_Output::value('Native plain value')==='Native plain value','Values outside a rendering scope remain plain strings');

$widget = new Owned_Test_Widget(['mapped'=>'Human edited value','preserved'=>'Research','link'=>$settings['link'],'body'=>$settings['body']]);
Nova_WordPress_Owned_Output::begin($widget);
verify($widget->get_settings()['mapped']==='Human edited value','Changed settings lose obsolete mapped ownership');
Nova_WordPress_Owned_Output::cleanup($widget);

$body = '<p>Mapped paragraph.</p><p>Preserved paragraph.</p>';
$range = ['offset'=>0,'length'=>strlen('<p>Mapped paragraph.</p>'),'owned'=>true];
$output = Nova_WordPress_Owned_Output::content($body,['hash'=>hash('sha256',$body),'ranges'=>[$range]]);
verify(str_contains($output,'<span data-wg-notranslate>Mapped paragraph.</span>'),'Owned core document ranges are protected');
verify(str_contains($output,'<p>Preserved paragraph.</p>'),'Preserved core document ranges stay eligible');
verify(Nova_WordPress_Owned_Output::content($body,['hash'=>'changed','ranges'=>[$range]])===$body,'Changed documents do not use stale ownership ranges');
// Dynamic settings may already be cached by the time a widget reaches before_render.
$post = (object) ['ID'=>41,'post_title'=>'Original title','post_excerpt'=>'Original excerpt'];
$wp_query = (object) ['queried_object'=>$post];
$originalPost = $post;
Nova_WordPress_Owned_Output::native(41,['post_title'=>'Authored title','post_excerpt'=>'Authored excerpt']);
$tree = Nova_WordPress_Owned_Output::builder([['id'=>'owned_widget','settings'=>$settings,'elements'=>[]]]);
verify(str_contains($tree[0]['settings']['mapped'],'NOVAOWNED_'),'Mapped settings are marked before widget construction');
verify($tree[0]['settings']['preserved']==='Research','Preserved settings stay plain before widget construction');
verify(str_contains($post->post_excerpt,'Authored excerpt') && str_contains($post->post_excerpt,'NOVAOWNED_'),'Direct native excerpts are protected before dynamic settings are cached');
$nativeHtml = Nova_WordPress_Owned_Output::finish_builder('<h1>'.esc_html($post->post_title).'</h1><p>'.esc_html($post->post_excerpt).'</p>');
verify(str_contains($nativeHtml,'<span data-wg-notranslate>Authored excerpt</span>'),'A native excerpt cached during construction stays protected');
verify($post===$originalPost && $wp_query->queried_object===$originalPost,'The document render restores original global objects');

Nova_WordPress_Owned_Output::begin(null);
$marked = Nova_WordPress_Owned_Output::value('An excerpt with additional words');
$trimmed = substr($marked,0,strpos($marked,' with'));
$trimmedHtml = Nova_WordPress_Owned_Output::finish_builder('<p>'.$trimmed.'</p>');
verify(!str_contains($trimmedHtml,'NOVAOWNED_') && str_contains($trimmedHtml,'<span data-wg-notranslate>An excerpt</span>'),'A word-trimmed excerpt cannot leak its opening marker');
Nova_WordPress_Owned_Output::begin(null);
$structured = Nova_WordPress_Owned_Output::value(['question'=>'Authored question','answer'=>'<p>Authored answer</p>','number'=>3]);
$output = Nova_WordPress_Owned_Output::finish_builder('<h3>'.$structured['question'].'</h3>'.$structured['answer']);
verify($structured['number']===3 && substr_count($output,'data-wg-notranslate')===2,'Structured mapped values preserve scalar types and protect their text leaves');

Nova_WordPress_Owned_Output::begin(null);
$raw = Nova_WordPress_Owned_Output::value('Raw authored value');
$rawOutput = Nova_WordPress_Owned_Output::finish_builder('<textarea>'.$raw.'</textarea><script type="application/ld+json">{"name":"'.$raw.'"}</script>');
verify(!str_contains($rawOutput,'NOVAOWNED_') && str_contains($rawOutput,'Raw authored value'),'Raw-text output retains content without leaking render markers');

Nova_WordPress_Owned_Output::builder([['id'=>'owned_widget','settings'=>$settings,'elements'=>[]]]);
Nova_WordPress_Owned_Output::content($body,['hash'=>hash('sha256',$body),'ranges'=>[$range]]);
verify(str_contains(Nova_WordPress_Owned_Output::value('Later dynamic field'),'NOVAOWNED_'),'Rendering nested post content keeps the enclosing builder scope active');
Nova_WordPress_Owned_Output::finish_builder('');
verify(Nova_WordPress_Owned_Output::value('Plain field')==='Plain field','Finishing the enclosing builder closes its protection scope');

require_once dirname(__DIR__).'/includes/class-nova-wordpress-translation-fields.php';
$mediaFields=['ownership'=>['elements'=>['media_widget'=>[['path'=>['image','url'],'type'=>'url','hash'=>hash('sha256','https://remote.test/image.jpg')]]]]];
$mediaTree=[['id'=>'media_widget','settings'=>['image'=>['url'=>'https://local.test/uploads/image.jpg']],'elements'=>[]]];
$refreshed=Nova_WordPress_Translation_Fields::refresh_elementor_ownership($mediaFields,$mediaTree);
verify($refreshed['ownership']['elements']['media_widget'][0]['hash']===hash('sha256','https://local.test/uploads/image.jpg'),'Imported images keep ownership of their resolved local URL');
verify(Nova_WordPress_Translation_Fields::refresh_elementor_ownership(['title'=>'Legacy'],$mediaTree)===['title'=>'Legacy'],'Legacy media payloads keep their existing representation');

echo "Mapped-output ownership checks passed.\n";
