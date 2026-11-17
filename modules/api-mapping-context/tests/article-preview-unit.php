<?php
/** Theme styles and rendering state are isolated from the surrounding request. */
define( 'ABSPATH', __DIR__ );
class WP_Post { public $ID; public $post_type; public $post_status; public $post_title; public $post_content; public function __construct( $data ) { foreach ( (array) $data as $key => $value ) { $this->$key = $value; } } }
class WP_Query { public $posts; public $post; public $post_count; public $queried_object; public $queried_object_id; public $is_singular; public $is_page; public $is_single; public $query_vars; }
class Styles { public $registered = []; public $queue = []; public $done = []; }
function add_filter( $name, $callback ) { $GLOBALS['test_filters'][$name][] = $callback; }
function remove_filter( $name, $callback ) { $GLOBALS['test_filters'][$name] = array_filter( $GLOBALS['test_filters'][$name], static function( $value ) use ( $callback ) { return $value !== $callback; } ); }
function wp_styles() { return $GLOBALS['wp_styles']; }
function wp_scripts() { return $GLOBALS['wp_scripts']; }
function do_action( $hook ) { if ( $hook === 'wp_enqueue_scripts' ) { wp_register_style( 'theme', 'http://site.example/theme.css' ); wp_enqueue_style( 'theme' ); $GLOBALS['wp_styles']->registered['existing']->extra['after'][] = 'preview-only'; } }
function do_blocks( $content ) { if ( ! empty( $GLOBALS['fail_render'] ) ) { echo 'partial'; throw new RuntimeException( 'Rendering failed' ); } wp_register_style( 'wp-block-table', 'https://site.example/table.css' ); wp_enqueue_style( 'wp-block-table' ); return '<p>Rendered sample</p>'; }
function wp_register_style( $handle, $src ) { $GLOBALS['wp_styles']->registered[$handle] = (object) [ 'src' => $src, 'extra' => [ 'after' => [] ] ]; }
function wp_add_inline_style( $handle, $css ) { $GLOBALS['wp_styles']->registered[$handle]->extra['after'][] = $css; }
function wp_enqueue_style( $handle ) { $GLOBALS['wp_styles']->queue[] = $handle; }
function wp_style_is( $name, $status ) { return in_array( $name, $GLOBALS['wp_styles']->queue, true ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_get_global_stylesheet() { return ':root{--wp--style--global--content-size:640px}'; }
function wp_enqueue_stored_styles() { wp_register_style( 'block-supports', false ); wp_add_inline_style( 'block-supports', '.is-layout-constrained{max-width:640px}' ); wp_enqueue_style( 'block-supports' ); }
function wp_print_styles() { foreach ( $GLOBALS['wp_styles']->queue as $name ) { $style = $GLOBALS['wp_styles']->registered[$name] ?? null; if ( ! $style ) { continue; } if ( $style->src ) { echo '<link rel="stylesheet" href="' . $style->src . '">'; } foreach ( $style->extra['after'] as $css ) { echo '<style>' . $css . '</style>'; } } }
function wp_print_font_faces() { echo '<style>@font-face{font-family:Site;src:url(https://site.example/font.woff2)}</style>'; }
function wp_custom_css_cb() { echo '<style>.brand{color:green}</style>'; }
function get_body_class( $class ) { return [ $class, $GLOBALS['wp_query']->is_page ? 'page' : 'single' ]; }
function home_url( $path ) { return 'https://site.example' . $path; }
function esc_url( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-article-preview.php';
$test_filters=[]; $post=(object)['ID'=>77]; $wp_query=(object)['original'=>true]; $wp_the_query=$wp_query; $wp_styles=new Styles(); $wp_scripts=new Styles();
$wp_styles->registered['existing']=(object)['src'=>false,'extra'=>['after'=>['keep']]]; $wp_styles->queue=['existing']; $wp_styles->done=['existing'];
$before=[ $post, $wp_query, $wp_the_query, $wp_styles, $wp_scripts ];
$html=Nova_Bridge_Suite_Article_Preview::render('<!-- wp:paragraph --><p>Sample</p><!-- /wp:paragraph -->','page');
$checks=0;
function check( $value, $message ) { ++$GLOBALS['checks']; if ( ! $value ) { throw new RuntimeException($message); } }
check(str_contains($html,'theme.css') && str_contains($html,'table.css'),'Active theme and on-demand block styles are printed');
check(str_contains($html,'--wp--style--global--content-size') && str_contains($html,'.is-layout-constrained'),'Global settings and block layout styles are printed');
check(str_contains($html,'@font-face') && str_contains($html,'.brand{color:green}'),'Site fonts and custom CSS are printed');
check(str_contains($html,'Rendered sample') && str_contains($html,'wp-block-post-content-is-layout-constrained'),'Rendered content uses the theme content container');
check(!str_contains($html,'http://site.example/'),'Same-site stylesheet URLs use the HTTPS site origin');
check(str_contains($html,'base href="https://site.example/"') && str_contains($html,'font-src'),'Relative theme assets and fonts have a frontend base');
check($before === [ $post, $wp_query, $wp_the_query, $wp_styles, $wp_scripts ],'Global context identities are restored');
check($wp_styles->registered['existing']->extra['after'] === ['keep'] && $wp_styles->done === ['existing'],'Existing stylesheet dependencies remain intact');
check(empty($test_filters['show_admin_bar']),'Preview filter is removed');
$fail_render=true; $level=ob_get_level();
try { Nova_Bridge_Suite_Article_Preview::render('body','post'); throw new RuntimeException('Expected renderer failure'); } catch ( RuntimeException $error ) { check($error->getMessage()==='Rendering failed','Render error is retained'); }
check(ob_get_level()===$level && $before === [ $post, $wp_query, $wp_the_query, $wp_styles, $wp_scripts ],'Failed rendering restores buffers and context');
echo "PASS $checks theme-styled article preview checks\n";
