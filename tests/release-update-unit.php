<?php
define( 'ABSPATH', __DIR__ );
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function add_filter( $name, $callback, $priority, $argc ) { $GLOBALS['registered'] = [ $name, $callback, $argc ]; }
function wp_safe_remote_get( $url, $args ) {
    $GLOBALS['requests'][] = [ $url, $args ];
    return $GLOBALS['release_response'];
}
function is_wp_error( $value ) { return $value instanceof Exception; }
function wp_remote_retrieve_response_code( $value ) { return $value['code']; }
function wp_remote_retrieve_body( $value ) { return $value['body']; }
function check_update( $condition, $message ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}
require dirname( __DIR__ ) . '/includes/updates.php';
$repo = 'https://github.com/InSpace-GEO/nova-wordpress-plugin';
$plugin = 'nova-wordpress-plugin/nova-wordpress-plugin.php';
$headers = [ 'UpdateURI' => $repo, 'Version' => '1.0.1' ];
$requests = [];
check_update( [ 'update_plugins_github.com', 'nova_wordpress_release_update', 3 ] === $registered, 'WordPress filter mismatch.' );
check_update( 'other' === nova_wordpress_release_update( 'other', $headers, 'other/other.php' ), 'Overrode another plugin.' );
check_update( false === nova_wordpress_release_update( false, [ 'UpdateURI' => 'https://github.com/example/test' ], $plugin ) && ! $requests, 'Requested an unrelated plugin.' );
$release_response = [ 'code' => 200, 'body' => '{"version":"1.0.2","requires":"6.9","requires_php":"8.0","package":"https://example.org/untrusted.zip"}' ];
$offer = nova_wordpress_release_update( false, $headers, $plugin );
check_update( $repo . '/releases/latest/download/wordpress-update.json' === $requests[0][0], 'Wrong release manifest.' );
check_update( ! isset( $requests[0][1]['headers']['Authorization'] ), 'Unexpected credential header.' );
check_update( '1.0.2' === $offer['version'] && '6.9' === $offer['requires'] && '8.0' === $offer['requires_php'], 'Wrong update metadata.' );
check_update( $repo . '/releases/download/v1.0.2/nova-wordpress-plugin-1.0.2.zip' === $offer['package'], 'Unexpected install package.' );
foreach ( [ new Exception(), [ 'code' => 404, 'body' => '' ], [ 'code' => 200, 'body' => '{' ],
    [ 'code' => 200, 'body' => '{"version":"1.0.3-beta","requires":"6.9","requires_php":"8.0"}' ],
    [ 'code' => 200, 'body' => '{"version":"1.0.3"}' ] ] as $release_response ) {
    check_update( false === nova_wordpress_release_update( false, $headers, $plugin ), 'Invalid release offered for install.' );
}
echo "PASS public WordPress update offer\n";
