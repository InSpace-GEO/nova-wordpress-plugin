<?php
/** Mapped native excerpts survive draft creation and interrupted-request reconciliation. */
require __DIR__ . '/layout-recipes-api-unit.php';
$post_field_checks = $checks;
$administrator = true; $nonce_valid = true; $denied_caps = []; $native_fail = false;
$acf_schema = $schema_before; $editorial_meta = [];
$excerpt = "Cashier's summary \\ with unicode — 🛰️";
foreach ( [ 'core', 'gutenberg', 'acf' ] as $provider ) {
    $design = design( $provider, 'static', 'post_type:page', 11 );
    $recipe = $design['recipe_preset'];
    $recipe['bindings'][] = [ 'target' => '/native_post/excerpt', 'source' => '/summary', 'format' => 'text', 'required' => false, 'label' => 'Article summary' ];
    $content = $design['sample']; $content['summary'] = $excerpt;
    if ( 'acf' === $provider ) {
        $recipe['bindings'][] = [ 'target' => '/native_post/title', 'source' => '/mapped_title', 'format' => 'text', 'required' => true, 'label' => 'Native title' ];
        $content['mapped_title'] = 'Mapped publication title';
    }
    $saved = data( route( 'save', 'POST', [], save_body( $design, $recipe, $content ) ) );
    $translated = data( route( 'translate', 'POST', [], [ 'id' => $saved['id'], 'revision' => $saved['revision'], 'content' => $content ] ) );
    check( ( $translated['post_fields']['excerpt'] ?? null ) === $excerpt, 'Mapped excerpt did not compile as native text for ' . $provider );
    if ( 'acf' === $provider ) { check( $translated['post_fields']['title'] === $content['mapped_title'], 'Publication title did not compile independently of the test title' ); }
    $request = draft_body( $saved, $content, "Reviewer's test \\ draft — " . $provider ); $before = $writes;
    $result = data( route( 'draft', 'POST', [], $request ) ); $id = $result['id'];
    check( $writes === $before + 1 && $posts[$id]->post_excerpt === $excerpt, 'Draft lost or incorrectly escaped the native excerpt for ' . $provider );
    check( $posts[$id]->post_title === $request['title'], 'A mapped title overrode the explicit test-draft title for ' . $provider );
    check( $options['nova_bridge_layout_recipe_operations_v1'][$request['operation_id']]['post_fields']['excerpt'] === $excerpt, 'Draft journal lost the excerpt needed for readback verification' );

    // A retained, interrupted write can be verified without rewriting its excerpt or creating another draft.
    $options['nova_bridge_layout_recipe_operations_v1'][$request['operation_id']]['phase'] = 'uncertain'; $before = $writes;
    $recovered = data( route( 'draft', 'GET', [ 'operation_id' => $request['operation_id'] ] ) );
    check( $recovered['id'] === $id && $recovered['reused'] && $writes === $before && $posts[$id]->post_excerpt === $excerpt, 'Recovery did not retain the mapped excerpt and witnessed draft for ' . $provider );

    // A manual excerpt edit during an interrupted operation must block further writes.
    $options['nova_bridge_layout_recipe_operations_v1'][$request['operation_id']]['phase'] = 'uncertain'; $posts[$id]->post_excerpt = 'Human summary';
    rejected( route( 'draft', 'GET', [ 'operation_id' => $request['operation_id'] ] ), 409, 'Interrupted recovery accepted an altered native excerpt for ' . $provider );
    check( $writes === $before && $posts[$id]->post_excerpt === 'Human summary', 'Recovery overwrote a human excerpt for ' . $provider );
    $options['nova_bridge_layout_recipe_operations_v1'][$request['operation_id']]['phase'] = 'complete';
    $completed = data( route( 'draft', 'GET', [ 'operation_id' => $request['operation_id'] ] ) );
    check( $completed['id'] === $id && $writes === $before && $posts[$id]->post_excerpt === 'Human summary', 'Completed recovery stopped preserving subsequent human edits' );

    // Optional omission does not copy the reference excerpt into a new draft.
    unset( $content['summary'] ); $posts[11]->post_excerpt = 'Reference-only summary';
    $empty = data( route( 'draft', 'POST', [], draft_body( $saved, $content ) ) );
    check( $posts[$empty['id']]->post_excerpt === '', 'An omitted optional excerpt copied example content for ' . $provider );
}
$posts[11]->post_excerpt = '';

// A WordPress filter changing the inserted excerpt must leave an uncertain witnessed draft, not success.
$change_excerpt = static function ( $data ) { $data['post_excerpt'] = 'Filtered summary'; return $data; };
add_filter( 'wp_insert_post_data', $change_excerpt, 20 );
$content['summary'] = $excerpt; $filtered_request = draft_body( $saved, $content ); $before = $writes;
rejected( route( 'draft', 'POST', [], $filtered_request ), 409, 'A native excerpt readback mismatch reported a successful draft' );
remove_filter( 'wp_insert_post_data', $change_excerpt, 20 );
check( $writes === $before + 1 && $options['nova_bridge_layout_recipe_operations_v1'][$filtered_request['operation_id']]['phase'] === 'uncertain', 'Excerpt readback failure lost its recoverable native draft' );
rejected( route( 'draft', 'GET', [ 'operation_id' => $filtered_request['operation_id'] ] ), 409, 'Recovery ignored an excerpt rejected by WordPress' );
check( $writes === $before + 1, 'Excerpt readback failure created a duplicate draft' );
echo 'PASS ' . ( $checks - $post_field_checks ) . " native post-field draft/recovery checks (plus API baseline).\n";
