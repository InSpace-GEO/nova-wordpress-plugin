<?php
/** Real WordPress parser and native field inventory, no persistent WP records. */
require __DIR__ . '/custom-html-writer-unit.php';
require_once dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-mapping-preview.php';
require_once dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-recipe-visual.php';
function get_the_ID() { return $GLOBALS['preview_post_id'] ?? 0; }
$example = <<<'HTML'
<!-- wp:group --><div class="wp-block-group"><!-- wp:html --><section><h2>Course overview</h2><p>Read the <em>course guide</em> before joining.</p><a href="#schedule">View schedule</a><img src="cover.svg" alt="Course illustration"></section><!-- /wp:html --><!-- wp:html --><section id="schedule"><h2>Schedule</h2><details><summary>What is included?</summary><p id="first-answer">Both sessions.</p></details><details><summary>What is included?</summary><p id="second-answer">Personal feedback.</p></details></section><script type="application/ld+json">{"@type":"Course","name":"Sample course"}</script><!-- /wp:html --></div><!-- /wp:group -->
HTML;
$blocks = parse_blocks( $example );
$bp = [ 'document' => Nova_Mapping_Gutenberg_HTML::capture( $blocks ) ];
$design = [ 'builder' => 'gutenberg', 'blueprint' => $bp, 'fields' => $adapter->fields( $bp ), 'containers' => [] ];
$design = Nova_Bridge_Suite_Recipe_Visual::enrich( $design, [ 'type' => 'post_type', 'post_type' => 'page' ] );
$callback = [ Nova_Bridge_Suite_Mapping_Preview::class, 'mark_custom_html' ];
$GLOBALS['preview_post_id'] = 14;
check( $callback( $example ) === $example, 'Normal rendering keeps the original markup.' );
$canvas = new ReflectionProperty( Nova_Bridge_Suite_Mapping_Preview::class, 'canvas' );
if ( PHP_VERSION_ID < 80100 ) { $canvas->setAccessible( true ); }
$canvas->setValue( null, [ 'referenceId' => 15, 'referenceType' => 'post' ] );
check( $callback( $example ) === $example, 'Other posts in a theme loop are not annotated.' );
$GLOBALS['preview_post_id'] = 15;
$marked = $callback( $example );
$markers = [];
foreach ( $design['preview_fields'] as $field ) {
    $locator = $field['locator'];
    if ( 'schema_value' === $field['kind'] ) {
        check( 'unbound' === $locator['strategy'] && ! isset( $locator['marker'] ), 'JSON-LD is inventory only.' );
        continue;
    }
    check( 'custom_html' === $locator['strategy'] && substr_count( $marked, $locator['marker'] ) === 1, 'Each visible slot resolves exactly once in its own nested block.' );
    $markers[] = $locator['marker'];
}
check( count( $markers ) === count( array_unique( $markers ) ), 'Repeated FAQ text has separate markers.' );
$changed = $callback( str_replace( 'Personal feedback.', 'Changed after capture.', $example ) );
foreach ( $design['fields'] as $field ) {
    if ( 'Personal feedback.' === $field['value'] ) { check( ! str_contains( $changed, $field['locator']['marker'] ), 'An edited block cannot highlight a stale slot.' ); }
}
check( serialize_blocks( Nova_Mapping_Gutenberg_HTML::source_blocks( $bp['document'] ) ) === $example, 'No marker enters the captured native document.' );
echo "Custom HTML preview source, identity and writer isolation checks passed.\n";
