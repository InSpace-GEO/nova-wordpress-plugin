<?php
/** A field group shown on "Default template" pages applies to a new page as well as to an existing one. */
$core = getenv( 'NOVA_WP_CORE_FIXTURE' );
if ( ! $core || ! is_file( $core . '/plugin.php' ) ) { throw new RuntimeException( 'Set NOVA_WP_CORE_FIXTURE to a WordPress core fixture.' ); }
define( 'ABSPATH', dirname( $core ) . '/' ); define( 'WPINC', basename( $core ) );
require_once $core . '/plugin.php';
function __( $text ) { return $text; }
class WP_REST_Posts_Controller {}
class WP_Post_Type { public $name = 'page'; }
function get_post_type_object( $type ) { return 'page' === $type ? new WP_Post_Type() : null; }
$stored_templates = [ 11 => '', 12 => 'templates/landing.php' ];
// WordPress returns false for post 0 and '' for a post without a stored template.
function get_post_meta( $id, $key = '', $single = false ) { global $stored_templates; return $id ? ( $stored_templates[ $id ] ?? '' ) : false; }
function get_post_type( $id ) { return 'page'; }
function post_type_supports( $type, $feature ) { return true; }
function get_registered_meta_keys( ...$args ) { return []; }
function update_field() {} function get_field() {} function acf_get_fields( $group ) { return $group['fields']; }
// ACF's post_template location rule (ACF_Location_Post_Template::match), for a post type that has templates.
function acf_template_matches( array $screen, string $value ): bool {
    if ( isset( $screen['page_template'] ) ) { $template = $screen['page_template']; }
    elseif ( isset( $screen['post_id'] ) ) { $template = get_post_meta( $screen['post_id'], '_wp_page_template', true ); }
    else { $template = ''; }
    if ( '' === $template ) { $template = 'default'; }
    return $template === $value;
}
function acf_get_field_groups( $screen = [] ) {
    $groups = [];
    if ( acf_template_matches( $screen, 'default' ) ) { $groups[] = [ 'key' => 'group_content', 'active' => true, 'fields' => [ [ 'key' => 'field_content', 'name' => 'content', 'label' => 'Content', 'type' => 'text' ] ] ]; }
    if ( acf_template_matches( $screen, 'templates/landing.php' ) ) { $groups[] = [ 'key' => 'group_landing', 'active' => true, 'fields' => [ [ 'key' => 'field_hero', 'name' => 'hero', 'label' => 'Hero', 'type' => 'text' ] ] ]; }
    return $groups;
}
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-content-transport.php';
$checks = 0;
function check( $value, string $message ) { global $checks; $checks++; if ( ! $value ) { throw new RuntimeException( $message ); } }
$names = static function ( int $id, string $template ): array { return array_keys( Nova_Bridge_Suite_Content_Transport::field_catalog( 'page', $id, $template )['acf'] ); };

check( $names( 11, '' ) === [ 'content' ], 'The default-template example page did not get its field group.' );
check( $names( 0, '' ) === [ 'content' ], 'A new page did not get the field group for the default template.' );
check( $names( 12, 'templates/landing.php' ) === [ 'hero' ] && $names( 0, 'templates/landing.php' ) === [ 'hero' ], 'A named template no longer chose its own field group.' );
check( $names( 12, '' ) === [ 'hero' ], 'An existing page lost its stored template.' );
echo "PASS {$checks} new-item template checks\n";
