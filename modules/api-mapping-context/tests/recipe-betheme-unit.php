<?php
require __DIR__ . '/layout-recipes-api-unit.php';
define( 'MFN_THEME_VERSION', '29.0.5' );
class Mfn_Builder_Front {}
class Mfn_Helper { public static function preparePostUpdate() {} }
class Mfn_Builder_Fields {
    public function __construct( ...$args ) {}
    public function get_items() { return [
        'column' => [ 'attr' => [ [ 'id' => 'content', 'type' => 'visual', 'dynamic_data' => 'content' ], [ 'id' => 'onclick', 'type' => 'text' ], [ 'id' => 'padding', 'type' => 'text' ], [ 'id' => 'title', 'type' => 'text', 'class' => 'hidden' ] ] ],
        'image' => [ 'attr' => [ [ 'id' => 'src', 'type' => 'upload' ], [ 'id' => 'alt', 'type' => 'text' ] ] ],
        'accordion' => [ 'attr' => [ [ 'id' => 'tabs', 'type' => 'tabs', 'options' => [ 'title' => [ 'input', 'Question' ], 'content' => [ 'textarea', 'Answer' ] ] ] ] ],
    ]; }
}
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-betheme.php';
$a = new Nova_Bridge_Suite_Recipe_Betheme();
Nova_Bridge_Suite_Recipe_Engine::register_adapter( 'betheme', $a );
$b = [ 'document' => [ [ 'uid' => 'section', 'attr' => [], 'wraps' => [ [ 'uid' => 'wrap', 'attr' => [], 'size' => '1/2', 'items' => [
    [ 'uid' => 'text', 'type' => 'column', 'attr' => [ 'content' => '<p>Source text</p>', 'onclick' => 'keep()', 'padding' => '20px', 'title' => 'Editor label' ] ],
    [ 'uid' => 'image', 'type' => 'image', 'attr' => [ 'src' => 'https://site.example/image.jpg' ] ],
    [ 'uid' => 'faq', 'type' => 'accordion', 'attr' => [ 'tabs' => [ [ 'title' => 'Question?', 'content' => 'Answer.' ] ] ] ],
] ] ] ] ], 'template' => '', 'meta' => [], 'content' => '' ];
$d = $a->describe( $b ); check( ! is_wp_error( $d ) && count( $d['fields'] ) === 5, 'Native text/image/alt/FAQ fields are exposed, design and executable controls excluded' );
$fields = array_column( $d['fields'], null, 'target' ); $prefix = '/document/0/wraps/0/items/';
check( $fields[$prefix . '1/attr/src']['native_media']['kind'] === 'betheme', 'Images require native import' );
check( $fields[$prefix . '0/attr/content']['locator']['strategy'] === 'betheme', 'Preview identifies native BeBuilder nodes' );
$recipe = [ 'format' => Nova_Bridge_Suite_Recipe_Engine::FORMAT, 'builder' => 'betheme', 'mode' => 'static', 'blueprint' => $b, 'regions' => [], 'bindings' => [ [ 'target' => $prefix . '0/attr/content', 'source' => '/body', 'format' => 'html', 'required' => true, 'label' => 'Body' ], [ 'target' => $prefix . '1/attr/alt', 'source' => '/alt', 'format' => 'text', 'required' => false, 'label' => 'Alt' ] ] ];
$compiled = Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, [ 'body' => '<p>New body</p>', 'alt' => "Image's description" ], 'test' );
check( ! is_wp_error( $compiled ), 'Native layout compiles' );
$doc = $compiled['writer_payload']['betheme_document'];
check( $doc[0]['wraps'][0]['items'][0]['attr']['content'] === '<p>New body</p>' && $doc[0]['wraps'][0]['items'][1]['attr']['alt'] === "Image's description", 'Mapped values and missing optional native leaves are written' );
check( $doc[0]['wraps'][0]['size'] === '1/2' && $doc[0]['wraps'][0]['items'][0]['attr']['padding'] === '20px', 'Responsive structure and unselected appearance remain' );
check( $a->decode( base64_encode( serialize( $b['document'] ) ) ) === $b['document'], 'Encoded and array storage agree' );
try { $a->decode( base64_encode( serialize( [ new stdClass() ] ) ) ); check( false, 'Object payload accepted' ); } catch ( InvalidArgumentException $e ) {}
$dynamic = $b; $dynamic['document'][0]['wraps'][0]['items'][0]['attr']['content'] = '{post_content}';
check( ! $a->describe( $dynamic )['fields'][0]['writable'], 'Dynamic references remain protected' );
$shared = $b; $shared['document'][0]['mfn_global_section_id'] = 10;
check( $a->describe( $shared )['fields'] === [] && $a->describe( $shared )['warnings'], 'Shared section children cannot be mapped' );
$bad = $recipe; $bad['bindings'][0]['target'] = $prefix . '0/attr/onclick';
check( is_wp_error( Nova_Bridge_Suite_Recipe_Engine::compile( $bad, [ 'body' => 'bad' ], 'test' ) ), 'Forged executable-field binding is refused' );
$image_recipe = $recipe; $image_recipe['bindings'][] = [ 'target' => $prefix . '1/attr/src', 'source' => '/image', 'format' => 'url', 'required' => false, 'native_media' => true ];
$compiled = Nova_Bridge_Suite_Recipe_Engine::compile( $image_recipe, [ 'body' => 'Body', 'image' => 'https://assets.example/new.jpg' ], 'image' );
check( ! is_wp_error( $compiled ) && $compiled['writer_payload']['nova_media'][$prefix . '1/attr/src']['kind'] === 'betheme', 'Mapped images compile to native media manifest' );
echo "PASS BeTheme document and compiler regressions\n";
$shared = $b; $shared['document'][0]['wraps'][0]['attr']['global_wraps_select'] = 42;
check( $a->describe( $shared )['fields'] === [], 'Native global_wraps_select protects linked wrap children' );
$background = $b; $background['document'][0]['attr']['css_background'] = [ 'style' => 'background-image', 'selector' => '.mcb-section-mfnuidelement', 'val' => [ 'desktop' => 'https://site.example/bg.jpg', 'mobile' => '{featured_image}' ] ];
$df = array_column( $a->describe( $background )['fields'], null, 'target' );
$target = '/document/0/attr/css_background/val/desktop';
check( $df[$target]['native_media']['kind'] === 'betheme' && ! $df['/document/0/attr/css_background/val/mobile']['writable'], 'Responsive backgrounds expose image imports while dynamic variants stay protected' );
$updated = $a->bind( $background, [ 'target' => $target ], 'https://site.example/new-bg.jpg' );
check( $updated['document'][0]['attr']['css_background']['val']['desktop'] === 'https://site.example/new-bg.jpg' && $updated['document'][0]['attr']['css_background']['selector'] === '.mcb-section-mfnuidelement', 'Mapping background retains its native selector and other device values' );
$omitted = $recipe; $omitted['bindings'] = []; $omitted['omissions'] = [ [ 'target' => '/document/0/wraps/0/items/0' ] ];
$plan = Nova_Bridge_Suite_Recipe_Engine::compile( $omitted, [], 'remove' );
check( ! is_wp_error( $plan ) && count( $plan['writer_payload']['betheme_document'][0]['wraps'][0]['items'] ) === 2, 'Explicit element removal preserves its siblings' );
