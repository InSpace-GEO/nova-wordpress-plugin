<?php
/** Verify native Otter fields and the separate legacy shortcode format. */
require __DIR__ . '/recipe-builders-unit.php';
$core = getenv( 'NOVA_WP_CORE_FIXTURE' );
require_once $core . '/class-wp-token-map.php';
require_once $core . '/compat-utf8.php';
require_once $core . '/utf8.php';
require_once $core . '/html-api/html5-named-character-references.php';
function _wp_can_use_pcre_u() { return true; }
function _doing_it_wrong( ...$args ) { throw new RuntimeException( implode( ' ', $args ) ); }
function wp_kses_uri_attributes() { return [ 'href', 'src' ]; }
function esc_url( $value ) { return esc_attr( $value ); }
spl_autoload_register( static function ( $class ) use ( $core ) {
    if ( str_starts_with( $class, 'WP_HTML_' ) ) { require_once $core . '/html-api/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php'; }
} );

$source = file_get_contents( __DIR__ . '/fixtures/otter-article.html' );
$native = [ 'document' => parse_blocks( $source ), 'meta' => [], 'template' => '' ];
$values = [ 'A sample heading' => 'New <heading> & text', '<p class="sample-body">A sample paragraph.</p>' => '<p>A new paragraph.</p>',
    'Get in touch' => 'Book now', '/contact/?from=article&lang=en' => '/book/?from=new&lang=nl#start',
    'Original item' => '<em>New item</em>', 'Original question?' => 'Updated question?', '<p>Original answer.</p>' => '<p>Updated answer.</p>' ];
$targets = [];
$preview = serialize_blocks( Nova_Mapping_Gutenberg_Otter::preview( $native['document'] ) );
foreach ( $gutenberg->fields( $native ) as $field ) {
    recipe_assert( $field['writable'] && isset( $values[ $field['value'] ] ), 'Otter inventory only exposes expected editorial fields' );
    recipe_assert( 'custom_html' === $field['locator']['strategy'] && str_contains( $preview, $field['locator']['marker'] ), 'Preview has the corresponding editable element marker' );
    $targets[ $field['value'] ] = $field;
}
recipe_assert( count( $targets ) === count( $values ), 'All nested Otter and WordPress controls are exposed' );
$recipe = [ 'format' => 'nova-layout-recipe/v1', 'builder' => 'gutenberg', 'mode' => 'static', 'blueprint' => $native, 'bindings' => [], 'regions' => [] ];
$input = [];
foreach ( $targets as $sample => $field ) {
    $key = 'field_' . count( $input );
    $recipe['bindings'][] = [ 'target' => $field['target'], 'source' => '/' . $key, 'format' => $field['format'], 'required' => true ];
    $input[$key] = $values[$sample];
}
$result = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, $input, 'otter-test' ), 'Compile Otter and Gutenberg fields together' );
$output = $result['writer_payload']['native_document'];
recipe_assert( str_contains( $output, 'New &lt;heading&gt; &amp; text</h3>' ) && str_contains( $output, 'headingColor":"#263859"' ), 'Update heading inside existing styled element' );
recipe_assert( str_contains( $output, 'href="/book/?from=new&amp;lang=nl#start" target="_blank" rel="noopener"' ) && str_contains( $output, 'margin-right"></i><span>Book now</span>' ), 'Button label and URL preserve the icon and link settings' );
recipe_assert( str_contains( $output, '<em>New item</em></p>' ) && str_contains( $output, 'Updated question?</div></summary>' ) && str_contains( $output, '<p>Updated answer.</p>' ), 'Attribute-backed text stays in sync with saved HTML and child blocks' );
recipe_assert( ! str_contains( $output, 'data-nova-preview-fields' ) && serialize_blocks( parse_blocks( $output ) ) === $output, 'No preview markers are persisted and original block format remains valid' );
recipe_assert( ! str_contains( $result['preview_html'], '&lt;h3' ), 'Structure preview displays the heading rather than escaped wrapper markup' );
$locked = $native;
$locked['document'][0]['attrs']['lock'] = [ 'remove' => true ];
recipe_assert( count( $gutenberg->fields( $locked ) ) === count( $targets ) && ! array_filter( $gutenberg->fields( $locked ), static fn( $field ) => $field['writable'] ), 'Otter respects inherited block locks' );
$source_post = (object) [ 'ID' => 8002, 'post_type' => 'post', 'post_content' => '[vc_row][vc_column][vc_column_text]<!-- wp:heading --><p><strong>Legacy introduction</strong></p><!-- /wp:heading -->[/vc_column_text][us_btn label="Contact" link="%7B%22url%22%3A%22%2Fcontact%2F%22%2C%22target%22%3A%22_blank%22%7D"][/vc_column][/vc_row]' ];
recipe_assert( [ 'wpbakery' ] === Nova_Bridge_Suite_Recipe_Builders::native_carriers( $source_post ), 'Block comments inside legacy text do not make it a second builder' );
$source_post->post_content .= '<!-- wp:paragraph --><p>Independent block.</p><!-- /wp:paragraph -->';
recipe_assert( [ 'wpbakery', 'gutenberg' ] === Nova_Bridge_Suite_Recipe_Builders::native_carriers( $source_post ), 'Independent blocks still identify a mixed reference' );
$original = '%7B%22url%22%3A%22%2Fcontact%2F%22%2C%22target%22%3A%22_blank%22%7D';
recipe_assert( [ 'url' => '/new/?a=1&b=2', 'target' => '_blank' ] === nova_mapping_wpb_us_link( nova_mapping_wpb_replace_packed_link_url( $original, '/new/?a=1&b=2' ) ), 'Encoded theme links retain their existing settings' );
echo "PASS Otter native controls, nested preview, compilation and legacy detection.\n";

/** Core content inside provider-rendered ACF blocks uses the native Gutenberg writer. */
$source = '<!-- wp:acf/header {"data":{"image":14}} /-->'
    . '<!-- wp:acf/sidebar {"data":{"show_box":true,"sidebar_image":24}} -->'
    . '<!-- wp:heading {"level":1} --><h1>First</h1><!-- /wp:heading -->'
    . '<!-- wp:heading --><h2>Second<br>line</h2><!-- /wp:heading -->'
    . '<!-- wp:image {"id":25} --><figure><img src="/photo.jpg" class="wp-image-25" alt="Portrait"/></figure><!-- /wp:image -->'
    . '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph --><!-- /wp:acf/sidebar -->'
    . '<!-- wp:acf/form {"data":{"form_id":1}} /-->';
$native = [ 'document' => parse_blocks( $source ), 'meta' => [], 'template' => '' ];
$fields = $gutenberg->fields( $native );
recipe_assert( array_column( $fields, 'kind' ) === [ 'heading', 'heading', 'image_url', 'body' ], 'ACF wrappers expose native child content even without provider schema' );
$preview = serialize_blocks( Nova_Mapping_Gutenberg_Otter::preview( $native['document'] ) );
$recipe['blueprint'] = $native;
$recipe['bindings'] = [];
$input = [ 'New first', 'New second', 'https://example.org/new.jpg', '<p>New body</p>' ];
foreach ( $fields as $index => $field ) {
    recipe_assert( $field['writable'] && substr_count( $preview, $field['locator']['marker'] ) === 1, 'Each editable child has a unique preview marker' );
    $recipe['bindings'][] = [ 'target' => $field['target'], 'source' => '/value_' . $index, 'format' => $field['format'], 'required' => true ];
}
$result = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, array_combine( [ 'value_0', 'value_1', 'value_2', 'value_3' ], $input ), 'acf-children' ), 'Compile nested core fields' );
$draft = recipe_ok( $gutenberg->write_draft( $result['writer_payload'], [ 'title' => 'Nested content' ] ), 'Write nested draft' );
$written = get_post( $draft['post_id'] )->post_content;
foreach ( $input as $value ) { recipe_assert( str_contains( $written, $value ), 'Draft contains each mapped value' ); }
$output = parse_blocks( $written );
recipe_assert( $output[0] === $native['document'][0] && $output[2] === $native['document'][2] && $output[1]['attrs'] === $native['document'][1]['attrs'], 'Keep ACF presentation settings and form references' );
$native['document'][1]['attrs']['templateLock'] = 'all';
recipe_assert( ! array_filter( $gutenberg->fields( $native ), static fn( $field ) => $field['writable'] ), 'Children inherit the ACF wrapper lock' );

$recipe['bindings'][3]['id'] = 'nova_role_rich_text_1234567890abcdef';
$input[3] = '<section id="details"><p>Opening.</p><h2>Details</h2><ul><li>First item</li></ul></section><p>Closing.</p>';
$values = array_combine( [ 'value_0', 'value_1', 'value_2', 'value_3' ], $input );
$expanded = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, $values, 'expanded-body' ), 'Expand a nested Rich text paragraph' );
$blocks = parse_blocks( $expanded['writer_payload']['native_document'] );
$body = $blocks[1]['innerBlocks'][3];
recipe_assert( 'core/group' === $body['blockName'] && [ 'core/group', 'core/paragraph' ] === array_column( $body['innerBlocks'], 'blockName' ), 'Body stays at its original nested position' );
recipe_assert( [ 'core/paragraph', 'core/heading', 'core/list' ] === array_column( $body['innerBlocks'][0]['innerBlocks'], 'blockName' ), 'Section content remains editable as individual blocks' );
recipe_assert( $blocks[2] === $native['document'][2], 'The protected following form survives expansion' );
$recipe['bindings'][3]['id'] = 'nova_role_intro_1234567890abcdef';
$values['value_3'] = '<p>One <strong>introduction</strong>.</p>';
$intro = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, $values, 'single-intro' ), 'Compile a single introduction paragraph' );
$paragraph = parse_blocks( $intro['writer_payload']['native_document'] )[1]['innerBlocks'][3];
recipe_assert( 'core/paragraph' === $paragraph['blockName'] && $paragraph['innerHTML'] === $values['value_3'], 'Keep a single introduction paragraph and its inline markup' );
$values['value_3'] = '<p>First <em>paragraph</em>.</p><p>Second paragraph.</p>';
$intro = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, $values, 'expanded-intro' ), 'Expand a multi-paragraph introduction' );
$output = parse_blocks( $intro['writer_payload']['native_document'] );
recipe_assert( array_column( $output[1]['innerBlocks'][3]['innerBlocks'], 'innerHTML' ) === [ '<p>First <em>paragraph</em>.</p>', '<p>Second paragraph.</p>' ], 'Preserve the supplied introduction paragraphs and inline formatting' );
$recipe['bindings'][3]['id'] = 'nova_role_custom_1234567890abcdef';
recipe_assert( is_wp_error( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, $values, 'inline-custom' ) ), 'Unrelated paragraph roles retain the inline constraint' );
