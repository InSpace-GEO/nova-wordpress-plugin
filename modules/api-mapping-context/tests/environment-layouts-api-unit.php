<?php
/** Real layout catalog/save APIs across connections; no installed WordPress database. */
require __DIR__ . '/layout-recipes-api-unit.php';
if ( ! class_exists( 'Nova_Bridge_Suite_Posting_Settings' ) ) {
    class Nova_Bridge_Suite_Posting_Settings {
        public static function connection(): array { return $GLOBALS['environment_connection']; }
    }
}
$environment_connection = [ 'base_url' => 'https://test.example', 'site_id' => '11111111-1111-4111-8111-111111111111', 'enabled' => false ];
$environment_connection['layout_legacy_environment'] = Nova_WordPress_Environment::identity( $environment_connection );
$test_connection = $environment_connection;
$before_content = [ $posts, $post_meta, $writes ];
$test_defaults = Nova_Bridge_Suite_Layout_Recipes::defaults();
$test_ids = Nova_Bridge_Suite_Layout_Recipes::saved_recipe_ids();
check( count( $test_ids ) > 1 && count( $test_defaults ) > 0, 'Legacy layouts remain visible on upgrade.' );
$test_store = $options[RECIPE_OPTION];
foreach ( [ [ 'base_url' => 'https://production.example' ], [ 'site_id' => '22222222-2222-4222-8222-222222222222' ] ] as $destination ) {
    $environment_connection = array_replace( $test_connection, $destination );
    check( [] === Nova_Bridge_Suite_Layout_Recipes::defaults() && [] === Nova_Bridge_Suite_Layout_Recipes::saved_recipe_ids(), 'A different origin OR site starts with no old layouts or defaults.' );
    $catalog = data( route( 'catalog' ) );
    check( empty( $catalog['profiles'] ) && empty( $catalog['defaults'] ), 'The WordPress catalog excludes test layout history.' );
    $design = design( 'core', 'static', 'post_type:page', 11 );
    $body = save_body( $design, $design['recipe_preset'], $design['sample'] );
    $saved = data( route( 'save', 'POST', [], $body ) );
    check( 1 === $saved['revision'] && [ $saved['id'] ] === Nova_Bridge_Suite_Layout_Recipes::saved_recipe_ids(), 'A new-environment layout saves at its own first revision.' );
    $body = save_body( $design, $design['recipe_preset'], $design['sample'], 'post_type:page', $saved['id'], 1 );
    $body['label'] = 'Production layout';
    $updated = data( route( 'save', 'POST', [], $body ) );
    check( 2 === $updated['revision'], 'Atomic updates use the same isolated layout store.' );
    $new_connection = $environment_connection;
    $environment_connection = $test_connection;
    check( $test_defaults === Nova_Bridge_Suite_Layout_Recipes::defaults() && $test_ids === Nova_Bridge_Suite_Layout_Recipes::saved_recipe_ids() && $test_store === $options[RECIPE_OPTION], 'Returning to test restores all original layouts without modifying their history.' );
    $environment_connection = $new_connection;
    check( Nova_Bridge_Suite_Layout_Recipes::defaults()['post_type:page']['revision'] === 2, 'Returning to production restores its own latest revision.' );
}
check( $before_content === [ $posts, $post_meta, $writes ], 'Switching and saving layouts never edits existing native content.' );
echo "PASS {$checks} environment layout catalog checks\n";
