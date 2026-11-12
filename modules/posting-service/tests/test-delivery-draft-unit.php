<?php
/** Application test URLs keep every independently publishable native post in draft. */
require __DIR__ . '/stored-layout-generic-writer-unit.php';
$before_checks = $checks;
foreach ( [ 'informative', 'service', 'category' ] as $kind ) {
    $slug = 'lorem-ipsum-demo-' . $kind;
    foreach ( [ '/' . $slug . '/', '/parent/' . $slug . '/child/', '/nl/' . $slug, '/%6corem-ipsum-demo-' . $kind . '/' ] as $path ) {
        check( Nova_Bridge_Suite_Publication_Policy::test_url( 'https://fixture.test' . $path ), 'Exact test path segment is recognized.' );
    }
    reset_native();
    [ $content, $configuration ] = fixture( 'core', 'page' );
    $content['url'] = 'https://fixture.test/' . $slug . '/';
    $policy = ok( Nova_Bridge_Suite_Stored_Layout_Writer::policy( $content, $configuration ) );
    check( $policy['routing']['publication'] === 'draft', 'Test policy overrides a publish mapping.' );
    $plan = ok( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $content, $configuration, [ 'operation_id' => op( 401 ) ] ) );
    $saved = ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 401 ) ) );
    check( $saved['cms_post_status'] === 'draft' && $posts[$saved['post_id']]->post_status === 'draft', 'New test pages remain draft.' );
    $posts[$saved['post_id']]->post_status = 'publish';
    [ $content, $configuration ] = fixture( 'core', 'page', '502', 'preserve' );
    $content['url'] = 'https://fixture.test/' . $slug . '/';
    $plan = ok( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $content, $configuration, [ 'operation_id' => op( 402 ) ] ) );
    $saved = ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 402 ) ) );
    check( $saved['cms_post_status'] === 'draft' && $inserts === 1, 'A test update overrides preserve without another page.' );
}
foreach ( [ '/normal/', '/lorem-ipsum-demo-service-copy/', '/lorem-ipsum-demo-services/', '/normal/?next=/lorem-ipsum-demo-category/', '/normal/#/lorem-ipsum-demo-informative/' ] as $path ) {
    check( ! Nova_Bridge_Suite_Publication_Policy::test_url( 'https://fixture.test' . $path ), 'Ordinary paths retain their publication policy.' );
}
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-translation-writer.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-static-term-writer.php';
$test_content = [ 'url' => '/lorem-ipsum-demo-category/' ];
check( Nova_Bridge_Suite_Translation_Writer::policy( $test_content, [] )->get_error_code() === 'nova_test_delivery_draft_unavailable', 'Shared translated test views are held before publication.' );
check( Nova_Bridge_Suite_Static_Term_Writer::plan( $test_content, [], [] )->get_error_code() === 'nova_test_delivery_draft_unavailable', 'Test categories are held before public term creation.' );
echo 'Application test draft checks: ' . ( $checks - $before_checks ) . " passed.\n";
