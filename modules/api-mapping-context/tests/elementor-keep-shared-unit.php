<?php
/** Creation mappings keep shared Elementor components linked; deleted ones are left out of written pages. */
require __DIR__ . '/recipe-engine-unit.php';
require_once dirname( __DIR__, 2 ) . '/posting-service/includes/class-nova-bridge-suite-stored-layout.php';
use Nova_Bridge_Suite_Elementor_Reusable as Reusable;
use Nova_Bridge_Suite_Stored_Layout as Layout;
function shared_library( int $id, string $kind, array $nodes ): void {
    $GLOBALS['posts'][$id] = (object) [ 'ID' => $id, 'post_type' => 'elementor_library', 'post_status' => 'publish', 'post_title' => 'Shared component ' . $id, 'post_password' => '' ];
    $GLOBALS['metadata'][$id] = [ '_elementor_template_type' => $kind, '_elementor_data' => json_encode( $nodes ) ];
}
function creation_check( array $document ): string {
    try { Layout::assert_independent( 'elementor', [ 'document' => $document ] ); return 'accepted'; }
    catch ( InvalidArgumentException $e ) { return $e->getMessage(); }
}
shared_library( 801, 'widget', [ widget( 'cta', 'button', [ 'text' => 'Call us', 'link' => [ 'url' => '/contact/' ] ] ) ] );
// A shared container template with ordinary plugin shortcodes inside (reviews, FAQ), as on a real site.
shared_library( 802, 'container', [ container( 'shared-box', [ widget( 'reviews', 'shortcode', [ 'shortcode' => '[google_reviews]' ] ), widget( 'faq', 'shortcode', [ 'shortcode' => '[acf_faq]' ] ) ] ) ] );
$global = widget( 'g1', 'global', [] ) + [ 'templateID' => 801 ];
$template = widget( 't1', 'template', [ 'template_id' => '802' ] );
$embedded = widget( 's1', 'shortcode', [ 'shortcode' => '[elementor-template id="802"]' ] );
$plain = widget( 's2', 'shortcode', [ 'shortcode' => '[contact-form-7 id="5"]' ] );

check( 'accepted' === creation_check( [ container( 'page', [ $global, $template, $embedded, $plain ] ) ] ), 'A creation mapping keeps shared globals, templates, embedded template shortcodes and plain shortcodes' );
check( 'accepted' === creation_check( [ $template ] ), 'Shortcodes inside a kept-shared template are not checked as page content' );
check( str_contains( creation_check( [ widget( 'g0', 'global', [] ) ] ), 'no library component' ), 'A global widget without a library ID is still refused' );
check( str_contains( creation_check( [ widget( 'x', 'text-editor', [ 'editor' => '<p>[elementor-template id="802"]</p>' ] ) ] ), 'external native template' ), 'A template shortcode typed into ordinary text is still refused' );
check( str_contains( creation_check( [ widget( 'loop', 'loop-grid', [ 'template_id' => '999' ] ) ] ), 'Loop Item' ), 'Loop widgets still need a published Loop Item' );
unset( $GLOBALS['posts'][802], $GLOBALS['metadata'][802] );
check( 'accepted' === creation_check( [ $global, $template ] ), 'A component deleted after saving does not block later deliveries' );

// Writing a page: present components stay linked, deleted ones are left out, the rest is untouched.
shared_library( 802, 'container', [ container( 'shared-box', [ widget( 'h', 'heading', [ 'title' => 'Shared heading' ] ) ] ) ] );
$deleted_global = widget( 'g2', 'global', [] ) + [ 'templateID' => 899 ];
$deleted_template = widget( 't2', 'template', [ 'template_id' => '898' ] );
$blueprint = [ 'document' => [ container( 'page', [ widget( 'title', 'heading', [ 'title' => 'Page title' ] ), $global, $deleted_global, $template, $deleted_template, $plain ] ) ], 'meta' => [], 'template' => '' ];
$compiled = Nova_Bridge_Suite_Recipe_Engine::compile( recipe( $blueprint, [ binding( '/document/0/elements/0/settings/title', '/title', 'text' ) ], [], 'static' ), [ 'title' => 'Delivered title' ], 'keep-shared-test' );
check( ! is_wp_error( $compiled ), 'A page with a deleted shared component is still written: ' . ( is_wp_error( $compiled ) ? $compiled->get_error_message() : '' ) );
$children = $compiled['writer_payload']['elementor_data'][0]['elements'];
$types = array_map( static function ( $n ) { return $n['widgetType']; }, $children );
check( [ 'heading', 'global', 'template', 'shortcode' ] === $types, 'Only the deleted components are left out: ' . json_encode( $types ) );
check( 'Delivered title' === $children[0]['settings']['title'] && 801 === (int) $children[1]['templateID'] && '802' === (string) $children[2]['settings']['template_id'] && '[contact-form-7 id="5"]' === $children[3]['settings']['shortcode'], 'Mapped content is filled and present components keep their live references' );
$warnings = implode( ' ', $compiled['warnings'] );
check( str_contains( $warnings, 'Global widget #899 no longer exists' ) && str_contains( $warnings, 'Saved template #898 no longer exists' ), 'The draft reports which components were left out' );
$removed = [];
check( [] === Reusable::without_missing( [ $deleted_global ], $removed ) && [ 'Global widget #899' ] === $removed, 'Removal is reported by component' );
echo "Keep-shared creation: all checks passed\n";
