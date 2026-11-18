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
