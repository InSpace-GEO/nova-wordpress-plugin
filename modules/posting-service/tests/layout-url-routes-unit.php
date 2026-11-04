<?php
/** Exercise the real save/sync/approval against both versions of the defaults contract. */
require __DIR__ . '/stored-recipe-sync-unit.php';

class Routing_Test_Rewrites {
    public $extra = [ 'masterclass' => '/masterclass/%masterclass%', 'second' => '/%second%', 'overlap' => '/masterclass/%overlap%' ];
    public function get_page_permastruct() { return '/%pagename%'; }
    public function get_extra_permastruct( $name ) { return $this->extra[$name] ?? false; }
}
function get_post_type_object( $name ) { return (object) [ 'hierarchical' => $name === 'page' ]; }
function get_post_status( $id ) { return 'publish'; }
function get_post_type( $id ) { return 'masterclass'; }
$wp_rewrite = new Routing_Test_Rewrites();
Nova_Bridge_Suite_Stored_Layout_Writer::$failure = null;

class Routing_Test_Client extends Nova_Bridge_Suite_Posting_Client {
    public $defaults = []; public $routes = []; public $modern = true; public $revision = 1;
    public $templates = []; public $mutations = []; public $race = false;
    public function site_request( string $method, string $suffix, $body = null, array $headers = [] ) {
        if ( 'GET' !== $method ) { $this->mutations[] = $suffix; }
        if ( '/template-defaults' === $suffix ) {
            if ( 'PUT' === $method ) {
                check( $headers === [ 'If-Match' => '"' . $this->revision . '"' ], 'Both selectors must change against the observed revision.' );
                if ( $this->race ) { return new WP_Error( 'stale_revision', 'The routes changed elsewhere.', [ 'status' => 412 ] ); }
                $this->defaults = (array) $body['defaults'];
                if ( array_key_exists( 'routes', $body ) ) { check( $this->modern, 'Legacy endpoint received unsupported routes.' ); $this->routes = array_reverse( $body['routes'] ); }
                ++$this->revision;
            }
            return reply( [ 'defaults' => $this->defaults, 'revision' => $this->revision ] + ( $this->modern ? [ 'routes' => $this->routes ] : [] ) );
        }
        if ( '/templates' === $suffix && 'POST' === $method ) {
            $id = sprintf( '00000000-0000-4000-8000-%012d', count( $this->templates ) + 1 );
            $this->templates[$id] = $body + [ 'id' => $id, 'revision' => 1, 'retired_at' => null, 'created_at' => '2026-10-26T12:00:00Z', 'updated_at' => '2026-10-26T12:00:00Z' ];
            return reply( $this->templates[$id], 201 );
        }
        $id = substr( $suffix, strlen( '/templates/' ) );
        if ( 'GET' === $method && isset( $this->templates[$id] ) ) { return reply( $this->templates[$id] ); }
        throw new RuntimeException( "$method $suffix" );
    }
}
function route_recipe( array $record, string $resource, int $number ): array {
    $record['id'] = sprintf( '11111111-0000-4000-8000-%012d', $number ); $record['revision'] = 1;
    $record['resource_id'] = 'post_type:' . $resource; $record['posting_page_type'] = 'service'; $record['label'] = $resource;
    Nova_Bridge_Suite_Layout_Recipes::$history[$record['id']][1] = $record;
    return $record;
}
function sync_route( $client, $connection, $recipe ): array {
    Nova_Bridge_Suite_Layout_Recipes::$record = $recipe;
    return stored_ok( stored_sync( $client, $connection ) )['posting_sync'];
}
$page = route_recipe( $record, 'page', 1 ); $masterclass = route_recipe( $record, 'masterclass', 2 );
$root_cpt = route_recipe( $record, 'second', 3 ); $overlap = route_recipe( $record, 'overlap', 4 );
$initial = $GLOBALS['wpdb']->rows;
foreach ( [ 'masterclass-first', 'pages-first', 'legacy-masterclass-first', 'legacy-pages-first' ] as $order ) {
    $GLOBALS['wpdb']->rows = $initial;
    $client = new Routing_Test_Client( $connection );
    $client->modern = false === strpos( $order, 'legacy' );
    $client->defaults['informative'] = $other_id;
    $client->routes[] = [ 'page_type' => 'informative', 'path_prefix' => '/blog/', 'template_id' => $other_id ];
    $pages_first = false !== strpos( $order, 'pages-first' );
    $first = sync_route( $client, $connection, $pages_first ? $page : $masterclass );
    check( $first['status'] === 'synced', 'First template is usable alone: ' . $order );
    check( $client->defaults['service'] === $first['template_id'] && $first['default_selected'] && ! $first['route_prefix'], 'A sole native endpoint preserves the existing type default: ' . $order );
    $alone_mutations = $client->mutations;
    check( sync_route( $client, $connection, $pages_first ? $page : $masterclass )['default_selected'] && $client->mutations === $alone_mutations, 'Resyncing the only template does not move the existing default.' );
    if ( ! $client->modern ) {
        $old_service = sync_route( $client, $connection, $pages_first ? $masterclass : $page );
        check( $old_service['code'] === 'nova_stored_recipe_routes_unavailable' && empty( $old_service['default_replacement'] ) && $client->mutations === $alone_mutations, 'Deploying WordPress first leaves both layouts available for migration without offering a destructive replacement.' );
    }
    $client->modern = true;
    $second = sync_route( $client, $connection, $pages_first ? $masterclass : $page );
    check( $second['status'] === 'synced', 'Second distinguishable template synchronizes: ' . $order . ': ' . $second['message'] );
    $page_id = ( $pages_first ? $first : $second )['template_id']; $cpt_id = ( $pages_first ? $second : $first )['template_id'];
    $routes = Nova_Bridge_Suite_Layout_URL_Routes::index( $client->routes );
    check( $client->defaults['service'] === $page_id && $routes['service:/masterclass/']['template_id'] === $cpt_id && $cpt_id !== $page_id, 'Both template UUIDs survive: ' . $order );
    check( $routes['service:/masterclass/']['installation_path'] === '/', 'Both save orders register the root installation path, including migrations.' );
    check( $client->defaults['informative'] === $other_id && isset( $routes['informative:/blog/'] ), 'Unrelated routing survives: ' . $order );
    $cpt_status = Nova_Bridge_Suite_Stored_Recipe_Sync::saved_status( $connection, $masterclass['id'], 1 );
    check( $cpt_status['route_prefix'] === '/masterclass/' && ! $cpt_status['default_selected'], 'Masterclass is shown as scoped, including after implicit migration.' );
    $before = $client->mutations;
    check( sync_route( $client, $connection, $masterclass )['status'] === 'synced' && sync_route( $client, $connection, $page )['status'] === 'synced' && $client->mutations === $before, 'Resaving either unchanged layout is idempotent.' );
    $conflict = sync_route( $client, $connection, $root_cpt );
    check( $conflict['status'] === 'failed' && strpos( $conflict['message'], 'Both WordPress destinations use root URLs' ) !== false && $client->mutations === $before, 'Identical root patterns conflict before mutation.' );
    $conflict = sync_route( $client, $connection, $overlap );
    check( $conflict['status'] === 'failed' && strpos( $conflict['message'], '/masterclass/' ) !== false && $client->mutations === $before, 'Identical fixed prefixes conflict before mutation.' );
}
update_option( 'home', 'https://wordpress.example/eindhoven' );
check( Nova_Bridge_Suite_Layout_URL_Routes::selector( 'post_type:masterclass' ) === [ 'path_prefix' => '/eindhoven/masterclass/', 'installation_path' => '/eindhoven/' ], 'Subdirectory installs provide their canonical origin path separately from their native route.' );
check( Nova_Bridge_Suite_Layout_URL_Routes::selector( 'post_type:page' )['path_prefix'] === '/' && Nova_Bridge_Suite_Layout_URL_Routes::selector( 'post_type:second' )['path_prefix'] === '/', 'Root-style Pages and root-based CPTs remain the catch-all on a subdirectory installation.' );
$GLOBALS['wpdb']->rows = $initial;
update_option( 'home', 'https://wordpress.example/eindhoven' );
$client = new Routing_Test_Client( $connection ); $client->modern = false;
$old_cpt = sync_route( $client, $connection, $masterclass );
$client->modern = true;
$sub_pages = sync_route( $client, $connection, $page );
$sub_route = [ 'page_type' => 'service', 'path_prefix' => '/eindhoven/masterclass/', 'template_id' => $old_cpt['template_id'] ];
check( $sub_pages['default_selected'] && count( $client->routes ) === 1 && $client->routes[0] == $sub_route + [ 'installation_path' => '/eindhoven/' ], 'Upgrading a nested install supplies the installation home for the migrated older CPT without altering its UUID.' );
$other = [ 'page_type' => 'service', 'path_prefix' => '/schiphol/masterclass/', 'installation_path' => '/schiphol/', 'template_id' => $old_cpt['template_id'] ];
$alias = [ 'page_type' => 'service', 'path_prefix' => '/eindhoven/events/', 'installation_path' => '/eindhoven/', 'template_id' => $old_cpt['template_id'] ];
$legacy_other = [ 'page_type' => 'service', 'path_prefix' => '/another/masterclass/', 'template_id' => $old_cpt['template_id'] ];
$client->routes = [ $sub_route, $other, $legacy_other, $alias ];
$before = $client->mutations;
$repaired = sync_route( $client, $connection, $masterclass );
$rows = Nova_Bridge_Suite_Layout_URL_Routes::index( $client->routes );
check( $repaired['verified'] && $client->mutations === array_merge( $before, [ '/template-defaults' ] ) && $rows['service:/eindhoven/masterclass/'] == $sub_route + [ 'installation_path' => '/eindhoven/' ], 'Resync repairs an older active rule in place without a new template or revision.' );
check( $rows['service:/schiphol/masterclass/'] === $other && $rows['service:/another/masterclass/'] === $legacy_other, 'Resync preserves other installation rules even if they use the same template UUID or lack installation metadata.' );
check( $rows['service:/eindhoven/events/'] === $alias, 'Another explicitly configured alias on the same installation is preserved.' );
$before = $client->mutations;
check( sync_route( $client, $connection, $masterclass )['verified'] && $client->mutations === $before, 'Resync after recording the home path does not write again.' );
$client->routes = [ $sub_route + [ 'installation_path' => '/' ], $other ];
$conflict = sync_route( $client, $connection, $masterclass );
check( $conflict['code'] === 'nova_stored_recipe_route_conflict' && $client->mutations === $before, 'A matching absolute prefix explicitly owned by another installation is never silently reclaimed.' );
update_option( 'home', 'https://wordpress.example' );
check( null === Nova_Bridge_Suite_Layout_URL_Routes::selector( 'post_type:masterclass', [ 'reference_id' => 17 ] ), 'An example contradicting its registered permalink is never used to claim that prefix.' );
$wp_rewrite->extra['masterclass'] = '/%year%/%masterclass%';
check( null === Nova_Bridge_Suite_Layout_URL_Routes::selector( 'post_type:masterclass' ), 'Dynamic prefixes cannot be safely distinguished.' );
$wp_rewrite->extra['masterclass'] = '/masterclass/%masterclass%';
$cpt_id = $old_cpt['template_id']; $client->routes = [ [ 'page_type' => 'service', 'path_prefix' => '/masterclass/', 'template_id' => $cpt_id ] ];
$client->defaults['service'] = $cpt_id;
$client->race = true; $failed = sync_route( $client, $connection, $page );
check( $failed['code'] === 'stale_revision' && $client->defaults['service'] === $cpt_id, 'A compare-and-swap race leaves the previous selection intact and the new layout unconfirmed.' );
echo "PASS saved layout URL routes for Pages and CPTs in both orders, upgrade and conflict cases.\n";
