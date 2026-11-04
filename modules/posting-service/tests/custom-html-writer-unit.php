<?php
/** Native WP block/HTML parser, production mapping and writer, in-memory WP persistence. */
$core = getenv( 'NOVA_WP_CORE_FIXTURE' );
if ( ! $core || ! is_file( $core . '/html-api/class-wp-html-processor.php' ) ) {
    echo "SKIP: provide a full WordPress 6.9+ wp-includes directory in NOVA_WP_CORE_FIXTURE.\n";
    exit( 0 );
}
require __DIR__ . '/stored-layout-generic-writer-unit.php';
require_once $core . '/class-wp-token-map.php';
require_once $core . '/compat-utf8.php';
function _wp_can_use_pcre_u() { return true; }
function wp_kses_uri_attributes() { return [ 'href', 'src' ]; }
function esc_url( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function has_filter( $hook, $callback = false ) { return ( 'content_save_pre' === $hook && 'wp_filter_post_kses' === $callback && ! empty( $GLOBALS['filter_custom_html'] ) ) ? 10 : false; }
function wp_filter_post_kses( $value ) { return wp_slash( wp_kses_post( wp_unslash( $value ) ) ); }
require_once $core . '/utf8.php';
require_once $core . '/html-api/html5-named-character-references.php';
spl_autoload_register( static function ( $class ) use ( $core ) {
    if ( ! str_starts_with( $class, 'WP_HTML_' ) ) { return; }
    $path = $core . '/html-api/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
    if ( is_file( $path ) ) { require_once $path; }
} );
require_once dirname( __DIR__, 2 ) . '/api-mapping-context/adapters/gutenberg-html.php';
$article = <<<'HTML'
<!-- wp:html --><style>.hero > a:hover{color:#cab}.hero [open]{display:grid}</style><!-- /wp:html -->
<!-- wp:html --><section class='hero' data-important="a > b"><!-- design note --><h2 id="lead">A &amp; B</h2><p>  Hello <em class="accent">world</em>!  </p><a class='cta' href="/old/" target='_blank'>Join us</a><img class="cover" src="/cover.jpg" alt="Cover" width="720"><iframe src="/film"></iframe><details><summary>Question?</summary><p>Answer.</p></details><form><label>Private control</label></form></section><!-- /wp:html -->
<!-- wp:html --><script type='application/ld+json'>{"@context":"https://schema.org","@graph":[{"@type":"Course","name":"Previous course","offers":{"@type":"Offer","price":35}},{"@type":"FAQPage","mainEntity":[{"@type":"Question","name":"Example question"}]}]}</script><script>window.keepOriginal = true;</script><!-- /wp:html -->
HTML;
$adapter = Nova_Bridge_Suite_Recipe_Builders::adapter( 'gutenberg' );
$reference = new WP_Post(); $reference->ID = 901; $reference->post_content = $article; $reference->post_type = 'page';
$posts[901] = $reference;
$blueprint = ok( $adapter->capture( 901 ) )['blueprint'];
unset( $posts[901] );
$fields = $adapter->fields( $blueprint );
$indexed = array_column( $fields, null, 'value' );
foreach ( [ 'A & B', 'Hello', 'world', 'Join us', 'Question?', 'Answer.', 'Previous course', '/cover.jpg', '/old/' ] as $expected ) {
    check( isset( $indexed[$expected] ), 'Detected ' . $expected );
}
check( ! isset( $indexed['Private control'] ) && ! in_array( '/film', array_column( $fields, 'value' ), true ), 'Form and embed stay opaque.' );
check( count( array_filter( $fields, static fn( $f ) => 'schema_value' === $f['kind'] ) ) === 3, 'Only JSON-LD scalar values are offered.' );
function html_record( $blueprint, $pairs, $indexed ) {
    $bindings = [];
    foreach ( $pairs as $old => $new ) {
        $field = $indexed[$old];
        $bindings[] = [ 'target' => $field['target'], 'id' => 'nova_role_custom_' . count( $bindings ), 'label' => 'Test ' . $field['label'],
            'source' => '/custom/test' . count( $bindings ), 'format' => $field['format'], 'required' => true, 'instructions' => 'Use the relevant supplied content.' ];
    }
    return [ 'id' => op( 52 ), 'revision' => 1, 'resource_id' => 'post_type:page', 'builder' => 'gutenberg', 'label' => 'Custom HTML',
        'recipe' => [ 'builder' => 'gutenberg', 'mode' => 'static', 'blueprint' => $blueprint, 'bindings' => $bindings, 'regions' => [] ] ];
}
function html_write( $record, $pairs, $indexed, $civ, $operation ) {
    [ $delivery, $config ] = fixture( 'gutenberg', 'page', $civ );
    $local = ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $record ) );
    $local['routing']['publication'] = 'draft';
    $config['local'] = $local;
    $config['profile_digest'] = hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $local ) );
    $delivery['destination_values'] = [ Nova_Bridge_Suite_Writing_Adapter::field_id( '/title' ) => 'New title' ];
    foreach ( $pairs as $old => $new ) { $delivery['destination_values'][Nova_Bridge_Suite_Writing_Adapter::field_id( $indexed[$old]['target'] )] = $new; }
    $plan = ok( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $delivery, $config, [ 'operation_id' => op( $operation ) ] ) );
    $result = ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( $operation ) ) );
    ok( Nova_Bridge_Suite_Stored_Layout_Writer::verify( $plan, $result ) );
    return $result['post_id'];
}
reset_native();
$pairs = [ 'A & B' => 'New & better', 'Hello' => 'Hi', '/old/' => 'https://example.test/new/', 'Cover' => 'New cover', 'Answer.' => 'New answer.' ];
$record = html_record( $blueprint, $pairs, $indexed );
$id = html_write( $record, $pairs, $indexed, '501', 761 );
$expected = str_replace( [ 'A &amp; B', '  Hello ', 'href="/old/"', 'alt="Cover"', '>Answer.<' ], [ 'New &amp; better', '  Hi ', 'href="https://example.test/new/"', 'alt="New cover"', '>New answer.<' ], $article );
$expected = preg_replace( '~<script type=\'application/ld\+json\'>.*?</script>~', '', $expected );
check( $expected === $posts[$id]->post_content, 'Every byte outside the five selected values and omitted schema was preserved.' );
check( serialize_blocks( parse_blocks( $expected ) ) === $expected, 'WordPress reopens the same valid native document.' );
$pairs['Previous course'] = 'Updated course & guidance';
$record = html_record( $blueprint, $pairs, $indexed );
$invalid = $record; $invalid['recipe']['bindings'][5]['instructions'] = '';
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout::from_recipe( $invalid ) ), 'Unguided schema cannot synchronize.' );
$invalid['recipe']['bindings'][5]['instructions'] = 'Write a title.'; $invalid['recipe']['bindings'][5]['id'] = 'nova_role_title_x';
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout::from_recipe( $invalid ) ), 'A standard purpose cannot synchronize a schema value.' );
check( $id === html_write( $record, $pairs, $indexed, '502', 762 ), 'A later delivery updates the same page.' );
$updated = $posts[$id]->post_content; preg_match( "~<script type='application/ld\\+json'>(.*?)</script>~s", $updated, $match );
$schema = json_decode( $match[1], true, 64, JSON_THROW_ON_ERROR );
check( $schema['@context'] === 'https://schema.org' && $schema['@graph'] === [ [ 'name' => 'Updated course & guidance', '@type' => 'Course' ] ], 'Mapped value only, with graph context and type; example offer and FAQ omitted.' );
check( str_replace( $match[0], '', $updated ) === $expected, 'Adding schema changes no other bytes.' );
$GLOBALS['filter_custom_html'] = true;
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Provider::probe( 'gutenberg', 'page', [ 'native_document' => $updated ], 'draft' ) ), 'A WordPress publishing filter that strips markup is detected before reserving a draft.' );
$GLOBALS['filter_custom_html'] = false;
$legacy = [ 'document' => parse_blocks( $article ) ];
$legacyFields = $adapter->fields( $legacy );
check( $legacyFields[1]['target'] === '/document/2/innerHTML' && $adapter->finalize( $legacy, 'legacy' )['native_document'] === $article, 'The original whole-block targets and old schema survive on previously saved revisions.' );
check( Nova_Mapping_Gutenberg_HTML::source_blocks( $blueprint['document'] ) === $legacy['document'], 'Freshness compares original native markup across old and new captures.' );
$inject = $adapter->bind( $blueprint, [ 'target' => $indexed['Previous course']['target'], 'format' => 'text' ], '</script><script>alert(1)</script>' );
$encoded = ok( $adapter->finalize( $inject, 'escape' ) )['native_document'];
check( str_contains( $encoded, '\u003C' ) && ! str_contains( $encoded, 'alert(1)</script>' ), 'Structured text cannot terminate the JSON-LD script element.' );
$bad_url = $adapter->bind( $blueprint, [ 'target' => $indexed['/old/']['target'], 'format' => 'url' ], 'javascript:alert(1)' );
check( is_wp_error( $adapter->finalize( $bad_url, 'unsafe-url' ) ), 'Dangerous link schemes are rejected.' );
echo "Custom HTML capture, writer create/update, markup preservation and JSON-LD checks passed.\n";
