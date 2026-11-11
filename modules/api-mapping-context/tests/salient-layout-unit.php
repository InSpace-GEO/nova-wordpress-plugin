<?php
/** Page-local Salient settings and shared native dependencies, without vendor code. */
require __DIR__ . '/wpbakery-layout-unit.php';
function get_template() { return $GLOBALS['salient_theme'] ?? 'salient'; }
function get_term_by( $field, $value, $taxonomy ) { return 'Home slides' === $value ? (object) [ 'term_id' => 19 ] : false; }
function get_posts( $args ) { return empty( $GLOBALS['no_slides'] ) ? [ 888 ] : []; }
$GLOBALS['recipe_meta'][900] = [ '_nectar_header_title' => 'Original header', '_nectar_header_subtitle' => '<p>Original summary</p>', '_nectar_header_bg' => 'https://fixture.example/hero.jpg', '_nectar_full_screen_rows' => 'on', '_force_transparent_header' => 'on', '_vc_post_settings' => [ 'is_hide_title' => true ], '_nectar_portfolio_extra_content' => 'Never copy', '_yoast_wpseo_title' => 'SEO' ];
$salient = recipe_ok( $adapter->capture( 900 ), 'Salient capture' );
recipe_assert( count( $salient['blueprint']['meta'] ) === 6 && $salient['blueprint']['meta']['_nectar_full_screen_rows'] === 'on', 'Header and layout settings survive capture; unrelated content and SEO do not' );
$fields = array_column( $salient['fields'], null, 'target' );
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-visual.php';
$visual = Nova_Bridge_Suite_Recipe_Visual::enrich( $salient, [ 'type'=>'post_type', 'post_type'=>'page', 'id'=>'post_type:page' ] );
foreach ( $visual['preview_fields'] as $field ) { if ( str_starts_with( $field['target'], '/meta/_nectar_header_' ) ) { recipe_assert( 'salient_header' === $field['locator']['strategy'], 'Visual enrichment retains header locators' ); } }
foreach ( [ '_nectar_header_title', '_nectar_header_subtitle', '_nectar_header_bg' ] as $key ) { recipe_assert( $fields['/meta/'.$key]['writable'] && 'salient_header' === $fields['/meta/'.$key]['locator']['strategy'], 'Header fields map with a dedicated native preview locator' ); }
$recipe = [ 'format'=>'nova-layout-recipe/v1', 'builder'=>'wpbakery', 'mode'=>'static', 'blueprint'=>$salient['blueprint'], 'regions'=>[], 'bindings'=>[
    [ 'target'=>'/meta/_nectar_header_title', 'source'=>'/title', 'format'=>'text', 'required'=>true ],
    [ 'target'=>'/meta/_nectar_header_subtitle', 'source'=>'/intro', 'format'=>'html', 'required'=>true ],
    [ 'target'=>'/meta/_nectar_header_bg', 'source'=>'/image', 'format'=>'url', 'native_media'=>true, 'required'=>false ],
] ];
$compiled = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, [ 'title'=>'Mapped header', 'intro'=>'<p>Mapped intro</p>', 'image'=>'https://fixture.example/new.jpg' ], 'salient' ), 'Header compilation' );
recipe_assert( 'Mapped header' === $compiled['writer_payload']['meta']['_nectar_header_title'] && 'salient_header' === $compiled['writer_payload']['nova_media']['/meta/_nectar_header_bg']['kind'], 'Header mapping compiles into native metadata and an import manifest' );
$payload = $compiled['writer_payload']; unset( $payload['nova_media'] );
recipe_ok( $adapter->publication_apply( 901, $payload, [ 'title'=>'Salient page' ] ), 'Salient known-ID writer' );
recipe_assert( get_post_meta( 901, '_nectar_header_title', true ) === 'Mapped header' && get_post_meta( 901, '_vc_post_settings', true ) === [ 'is_hide_title'=>true ], 'Native save retains header and editor settings' );
$GLOBALS['salient_theme'] = 'other'; recipe_assert( is_wp_error( $adapter->publication_probe( 'page', $payload, 'publish' ) ), 'A theme change refuses Salient settings before writing' ); unset( $GLOBALS['salient_theme'] );
WPBMap::$map['nectar_global_section'] = [ 'params'=>[ [ 'type'=>'textfield', 'param_name'=>'title' ] ] ];
WPBMap::$map['nectar_slider'] = [ 'params'=>[ [ 'type'=>'textfield', 'param_name'=>'title' ] ] ];
$shared = nova_mapping_wpb_parse_shortcodes_to_compact( '[vc_row][nectar_global_section id="777" title="Shared"][/nectar_global_section][nectar_slider location="Home slides" title="Slider"][/nectar_slider][/vc_row]' );
$GLOBALS['recipe_posts'][777] = (object) [ 'ID'=>777, 'post_type'=>'salient_g_sections', 'post_status'=>'publish', 'post_content'=>'[vc_row][vc_column_text]Shared content[/vc_column_text][/vc_row]' ];
recipe_assert( true === Nova_Mapping_Salient::probe( $shared ), 'Published shared section and slider location validate' );
foreach ( $adapter->fields( [ 'document'=>$shared ] ) as $field ) { recipe_assert( ! $field['writable'], 'Shared region controls cannot be remapped as page content' ); }
recipe_assert( count( Nova_Mapping_Salient::warnings( $shared ) ) === 1, 'Shared dependencies are explained once' );
$GLOBALS['no_slides'] = true; recipe_assert( is_wp_error( Nova_Mapping_Salient::probe( $shared ) ), 'An empty slider location fails before reservation' ); unset( $GLOBALS['no_slides'] );
$GLOBALS['recipe_posts'][777]->post_status = 'draft'; recipe_assert( is_wp_error( Nova_Mapping_Salient::probe( $shared ) ), 'An unpublished global section fails before reservation' );
$GLOBALS['recipe_posts'][777]->post_status = 'publish'; $GLOBALS['recipe_posts'][777]->post_content = '[nectar_global_section id="777"]';
recipe_assert( is_wp_error( Nova_Mapping_Salient::probe( $shared ) ), 'Circular shared references cannot reach the renderer' );
recipe_assert( is_wp_error( Nova_Mapping_Salient::probe( nova_mapping_wpb_parse_shortcodes_to_compact( '[nectar_missing_element]' ) ) ), 'Missing Salient plugins produce an actionable preflight error' );
WPBMap::$map['nectar_animated_title'] = [ 'params'=>[ [ 'type'=>'textfield', 'param_name'=>'text' ] ] ];
WPBMap::$map['toggle'] = [ 'is_container'=>true, 'params'=>[ [ 'type'=>'textfield', 'param_name'=>'title' ] ] ];
$native = nova_mapping_wpb_parse_shortcodes_to_compact( '[nectar_animated_title text="Heading"][toggle title="Question"]<p>Legacy answer</p>[/toggle]' );
$native_fields = array_column( $adapter->fields( [ 'document'=>$native ] ), null, 'target' );
recipe_assert( 'heading' === $native_fields['/document/0/attributes/text']['kind'], 'The letters cta inside nectar do not classify a title as a button' );
recipe_assert( 'html' === $native_fields['/document/1/text']['format'], 'A legacy toggle with direct inner text retains its answer mapping' );
// Use the installed API when present, including default CSS; do not approximate theme definitions.
if ( ! function_exists( 'wpbakery' ) ) { function wpbakery() { return new class { public function buildShortcodesCss( $id, $type ) { $GLOBALS['css_calls'][] = [ $id, $type ]; } }; } }
nova_mapping_wpb_regenerate_shortcodes_custom_css_meta( 901, '[vc_row]' );
recipe_assert( array_slice( $GLOBALS['css_calls'], -2 ) === [ [ 901, 'default' ], [ 901, 'custom' ] ], 'Native CSS API rebuilds both default and custom styles' );
echo "Salient layout cases passed\n";
