<?php
require __DIR__ . '/layout-url-routes-unit.php';
$GLOBALS['wpdb']->rows = $initial;
$client = new Routing_Test_Client( $connection ); $client->modern = true;
$page['posting_page_types'] = [ 'service', 'category' ];
$masterclass['posting_page_types'] = [ 'service', 'category' ];
foreach ( [ $page, $masterclass ] as $recipe ) { Nova_Bridge_Suite_Layout_Recipes::$history[$recipe['id']][$recipe['revision']] = $recipe; }
$root = sync_route( $client, $connection, $page );
$specific = sync_route( $client, $connection, $masterclass );
check( $root['status'] === 'synced' && $specific['status'] === 'synced', 'Multiple content types coexist with URL-based destination selection.' );
check( $client->defaults['service'] === $root['template_id'] && $client->defaults['category'] === $root['template_id'], 'Pages remain the fallback for both content types.' );
$routes = Nova_Bridge_Suite_Layout_URL_Routes::index( $client->routes );
foreach ( [ 'service', 'category' ] as $type ) { check( $routes[$type . ':/masterclass/']['template_id'] === $specific['template_id'], 'Both types route Masterclass URLs to the same CPT layout.' ); }
$masterclass['revision']++; $masterclass['posting_page_types'] = [ 'category' ];
Nova_Bridge_Suite_Layout_Recipes::$history[$masterclass['id']][$masterclass['revision']] = $masterclass;
$removed = sync_route( $client, $connection, $masterclass );
$routes = Nova_Bridge_Suite_Layout_URL_Routes::index( $client->routes );
check( $removed['status'] === 'synced' && ! isset( $routes['service:/masterclass/'] ) && isset( $routes['category:/masterclass/'] ), 'Deselecting Service removes only the owned Service route.' );
check( $client->defaults['service'] === $root['template_id'], 'Deselecting Service preserves another template\'s fallback.' );
check( $removed['route_prefix'] === '/masterclass/' && ! $removed['default_selected'], 'The remaining Category route is not mislabeled as a Service fallback.' );

echo "PASS multiple content types with URL-scoped CPT routing\n";
