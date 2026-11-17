<?php
/** Content-driven core blocks, kept separate from the historical recipe fixtures. */
require __DIR__ . '/recipe-builders-unit.php';

$adapter = Nova_Bridge_Suite_Recipe_Builders::adapter( 'gutenberg' );
$starter = recipe_ok( $adapter->starter( 'article' ), 'Article starter' );
$recipe = recipe_for( 'gutenberg', $starter, 'static' );
$recipe['article'] = Nova_Mapping_Gutenberg_Article::defaults();
$source = '<p>Intro with <strong>formatting</strong> and <a href="https://example.com/">a link</a>.</p><h3 id="costs">Costs</h3><table><caption>Comparison</caption><thead><tr><th scope="col">Name</th><th>Price</th></tr></thead><tbody><tr><td>A &amp; B</td><td>10</td></tr></tbody></table><ol start="3"><li>First<ul><li>Nested</li></ul></li><li>Second</li></ol><blockquote><p>A quotation.</p><cite>Author</cite></blockquote><hr><img src="https://example.com/image.jpg" alt="Example">';
$compiled = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, [ 'body' => $source ], 'article-unit' ), 'Compile article' );
$documents = [ 'article' => $compiled['native_document'] ];
recipe_assert( $recipe['article']['theme_displays_title'] === true, 'New article templates use the theme title by default' );
$title_source = "\n<h1><strong>Options &amp; costs</strong><br> explained</h1><p>Introduction.</p><h2>Options</h2><h3>Costs</h3>";
$title_content = [ 'title' => ' Options & costs   explained ', 'body' => $title_source ];
$title_result = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, $title_content, 'theme-title' ), 'Omit duplicate opening title' );
recipe_assert( ! str_contains( $title_result['native_document'], '<h1' ) && str_contains( $title_result['native_document'], '<h2' ) && str_contains( $title_result['native_document'], '<h3' ), 'Only the matching opening H1 is removed' );
recipe_assert( ! $title_result['warnings'], 'Expected title omission needs no warning' );
$documents['theme-title'] = $title_result['native_document'];
$title_legacy = $recipe; unset( $title_legacy['article']['theme_displays_title'] );
$title_before = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $title_legacy, $title_content, 'old-title' ), 'Legacy article title behavior' );
recipe_assert( str_contains( $title_before['native_document'], '<h1' ) && ! $title_before['warnings'], 'Existing profiles preserve the title until explicitly changed' );
$title_disabled = $recipe; $title_disabled['article']['theme_displays_title'] = false;
recipe_assert( recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $title_disabled, $title_content, 'old-title' ), 'Explicit content title' )['native_document'] === $title_before['native_document'], 'Disabling theme title keeps the historical output' );
foreach ( [ '<h1>Different title</h1><p>Body.</p>', '<h2>Options &amp; costs explained</h2><p>Body.</p>', '<p>Opening content.</p><h1>Options &amp; costs explained</h1>', '&lt;note&gt;<h1>Options &amp; costs explained</h1>' ] as $index => $preserved ) {
    $result = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, [ 'title' => $title_content['title'], 'body' => $preserved ], 'preserved-title-' . $index ), 'Preserve other headings' );
    recipe_assert( str_contains( $result['native_document'], $index === 1 ? '<h2' : '<h1' ), 'Nonduplicate or later headings are kept' );
    recipe_assert( (bool) $result['warnings'] === ( $index === 0 ), 'Only a mismatching opening H1 is flagged' );
}
$title_only = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, [ 'title' => 'Only title', 'body' => '<h1>Only title</h1>' ], 'title-only' ), 'Title-only article' );
recipe_assert( count( parse_blocks( $title_only['native_document'] )[0]['innerBlocks'] ) === 0, 'A title-only article leaves a valid empty body' );
$unicode_title = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, [ 'title' => 'Café & advies', 'body' => '<h1>Caf&eacute;&nbsp;&amp; advies</h1><p>Body.</p>' ], 'unicode-title' ), 'Normalize visible Unicode title text' );
recipe_assert( ! str_contains( $unicode_title['native_document'], '<h1' ), 'Entities and nonbreaking spaces match the visible title' );
$media_title = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, [ 'title' => 'Title', 'body' => '<h1><img src="https://example.com/logo.jpg" alt="Logo">Title</h1>' ], 'media-title' ), 'Preserve extra title media' );
recipe_assert( str_contains( $media_title['native_document'], '<img' ) && count( $media_title['warnings'] ) === 1, 'A heading containing extra media is retained for review' );
$bad_title = $recipe; $bad_title['article']['theme_displays_title'] = 'true';
recipe_assert( is_wp_error( Nova_Bridge_Suite_Recipe_Engine::validate( $bad_title ) ), 'Title policy must be an explicit boolean' );
$blocks = parse_blocks( $compiled['native_document'] )[0]['innerBlocks'];
recipe_assert( array_column( $blocks, 'blockName' ) === [ 'core/paragraph', 'core/heading', 'core/table', 'core/list', 'core/quote', 'core/separator', 'core/image' ], 'Article order and native block types' );
recipe_assert( 3 === $blocks[1]['attrs']['level'] && 'costs' === $blocks[1]['attrs']['anchor'], 'Heading level and anchor preserved' );
recipe_assert( str_contains( $blocks[2]['innerHTML'], '<thead>' ) && str_contains( $blocks[2]['innerHTML'], 'A &amp; B' ) && str_contains( $blocks[2]['innerHTML'], 'Comparison' ), 'Table structure and caption preserved' );
recipe_assert( 'core/list' === $blocks[3]['innerBlocks'][0]['innerBlocks'][0]['blockName'], 'Nested list preserved' );
recipe_assert( ! $compiled['warnings'], 'Supported elements need no fallback' );
$recipe['article']['rules']['heading']['level'] = 4;
$recipe['article']['rules']['paragraph']['class_name'] = 'article-copy lead';
$recipe['article']['rules']['table']['style'] = 'stripes';
$recipe['article']['rules']['table']['fixed_layout'] = true;
$styled = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, [ 'body' => $source ], 'article-styled' ), 'Styled article' );
$documents['styled'] = $styled['native_document'];
recipe_assert( str_contains( $styled['native_document'], '<h4' ) && str_contains( $styled['native_document'], 'article-copy lead' ) && str_contains( $styled['native_document'], 'is-style-stripes' ) && str_contains( $styled['native_document'], 'has-fixed-layout' ), 'Configured rules apply' );
$fallback = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, [ 'body' => '<div class="custom"><p>Retained custom structure.</p></div>' ], 'article-fallback' ), 'Fallback article' );
recipe_assert( str_contains( $fallback['native_document'], '<!-- wp:html' ) && count( $fallback['warnings'] ) === 1, 'Unsupported structures remain visible with a warning' );
$recipe['article']['rules']['fallback'] = 'reject';
recipe_assert( is_wp_error( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, [ 'body' => '<div>Review this.</div>' ], 'article-reject' ) ), 'Strict fallback blocks unsupported content' );
foreach ( [ '<!-- wp:heading --><p>Wrong block.</p><!-- /wp:heading -->', '[vc_row]Content[/vc_row]', '<script>alert(1)</script>' ] as $unsafe ) {
    recipe_assert( is_wp_error( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, [ 'body' => $unsafe ], 'article-unsafe' ) ), 'Article mode rejects builder syntax and unsafe content' );
}
$bad = $recipe; $bad['article']['rules']['paragraph']['class_name'] = '" onclick="run()';
recipe_assert( is_wp_error( Nova_Bridge_Suite_Recipe_Engine::validate( $bad ) ), 'Invalid rule classes rejected' );
$bad = $recipe; $bad['builder'] = 'wpbakery';
recipe_assert( is_wp_error( Nova_Bridge_Suite_Recipe_Engine::validate( $bad ) ), 'Article mode cannot reinterpret another builder' );
$plain = $recipe; unset( $plain['article'] );
recipe_assert( str_contains( recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $plain, [ 'body' => '<p>Legacy HTML</p>' ], 'legacy-article' ), 'Legacy recipe' )['native_document'], '<!-- wp:html' ), 'Old HTML recipes retain their representation' );
$cases = [
    'paragraphs' => '<p>One &amp; two 😀 [A] [B].</p><p>Second <em>paragraph</em>.</p>',
    'headings' => '<h1>One</h1><h2>Two</h2><h3>Three</h3><h4>Four</h4><h5>Five</h5><h6>Six</h6>',
    'reversed-list' => '<ol reversed start="9"><li>First</li><li>Second</li></ol>',
    'table-spans' => '<table><thead><tr><th colspan="2">Both</th></tr></thead><tbody><tr><td rowspan="2" data-align="center">A</td><td>B</td></tr><tr><td>C</td></tr></tbody><tfoot><tr><td colspan="2">Footer</td></tr></tfoot></table>',
    'image-size' => '<figure><img src="https://example.com/a.jpg" alt="A &amp; B" width="320" height="200"><figcaption>Caption</figcaption></figure>',
    'inline-content' => 'Opening <strong>bold</strong> text<p>Middle</p>Ending <em>italics</em>',
    'class-names' => '<h2 class="custom-wp-block-heading">Heading</h2><p class="custom">Body</p>',
    'nested-fallback' => '<ul><li>Before<ul class="deep" id="nested"><li>Item</li></ul></li></ul>',
    'list-tail' => '<ul><li>Before<ul><li>Nested</li></ul><img src="https://example.com/a.jpg" alt="after"></li></ul>',
    'custom-html' => '<div class="panel"><p>Retained <strong>markup</strong>.</p></div>',
    'empty' => '<p></p>',
    'separator-classes' => '<hr class="wp-block-separator has-alpha-channel-opacity custom-rule">',
];
$recipe['article'] = Nova_Mapping_Gutenberg_Article::defaults();
foreach ( $cases as $name => $html ) {
    $case = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, [ 'body' => $html ], 'case-' . $name ), 'Article case ' . $name );
    $documents[$name] = $case['native_document'];
}
$overflow = str_repeat( '<p>Block</p>', 1001 );
recipe_assert( is_wp_error( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, [ 'body' => $overflow ], 'too-many-blocks' ) ), 'Excessive block counts fail before publication' );
$missing = $recipe; unset( $missing['article']['rules']['table'] );
recipe_assert( is_wp_error( Nova_Bridge_Suite_Recipe_Engine::validate( $missing ) ), 'Incomplete rendering rules rejected' );
$unknown = $recipe; $unknown['article']['rules']['script'] = [];
recipe_assert( is_wp_error( Nova_Bridge_Suite_Recipe_Engine::validate( $unknown ) ), 'Unknown rendering rules rejected' );
if ( getenv( 'NOVA_ARTICLE_DOCUMENTS' ) ) { file_put_contents( getenv( 'NOVA_ARTICLE_DOCUMENTS' ), json_encode( $documents ) ); }
$mixed_shortcode = '[vc_row columns="1"][vc_column][vc_column_text]<!-- wp:heading --><p><strong>Introduction.</strong></p><!-- /wp:heading -->[/vc_column_text][vc_column_text]<h2><strong>First system</strong></h2>[/vc_column_text][us_image image="5967"][us_btn label="Contact" link="%7B%22url%22%3A%22https%3A%2F%2Fexample.com%2Fcontact%2F%22%7D"][/vc_column][/vc_row]';
$GLOBALS['recipe_posts'][9081] = (object) [ 'ID' => 9081, 'post_type' => 'post', 'post_content' => $mixed_shortcode ];
$vc = Nova_Bridge_Suite_Recipe_Builders::adapter( 'wpbakery' );
$captured = recipe_ok( $vc->capture( 9081 ), 'Capture mixed WPBakery and Gutenberg comments' );
$unchanged = recipe_ok( $vc->finalize( $captured['blueprint'], 'shortcode-regression' ), 'Serialize mixed source' );
recipe_assert( $unchanged['writer_payload']['layout']['raw_shortcodes'] === $mixed_shortcode, 'Mixed shortcode capture preserves exact source and theme elements' );
$gut_capture = $adapter->capture( 9081 );
recipe_assert( is_wp_error( $gut_capture ), 'A shortcode layout is not captured as Gutenberg' );
echo "PASS content-driven Gutenberg assertions\n";
