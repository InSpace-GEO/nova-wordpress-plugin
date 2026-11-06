<?php
/** Saved layouts: native title/excerpt destinations and removed elements in automatic publishing. Pure, no storage. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $message; private $code; public function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; } public function get_error_message() { return $this->message; } public function get_error_code() { return $this->code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_kses_post( $value ) { return $value; }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function post_type_supports( $type, $feature ) { return 'page' === $type ? in_array( $feature, [ 'excerpt' ], true ) : false; }
function no_io() { throw new RuntimeException( 'Pure authoring must not touch storage or HTTP.' ); }
function get_post( ...$args ) { no_io(); }
function get_option( ...$args ) { no_io(); }
function update_option( ...$args ) { no_io(); }
require dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-mapping-drafts.php';
require dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-recipe-engine.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-writing-adapter.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-recipe-layout.php';
use Nova_Bridge_Suite_Recipe_Engine as Engine;
use Nova_Bridge_Suite_Recipe_Native_Media as Media;
use Nova_Bridge_Suite_Stored_Recipe_Layout as Stored;
use Nova_Bridge_Suite_Writing_Adapter as Writing;
$checks = 0;
function check( $ok, $message ) { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( $message ); } }
function good( $result ) { check( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : 'result' ); return $result; }
function values( array $by_path ) { $result = []; foreach ( $by_path as $path => $value ) { $result[Writing::field_id( $path )] = $value; } return $result; }

/** A minimal list builder: each element has one text control. */
class List_Builder {
    public function validate_blueprint( $native ) { if ( ! Engine::list_array( $native['document'] ?? null ) ) { return new WP_Error( 'x', 'Invalid list.' ); } return $native; }
    public function describe( $native ) { $fields = []; foreach ( $native['document'] as $index => $node ) { $fields[] = [ 'target' => '/document/' . $index . '/text', 'node_target' => '/document/' . $index, 'label' => 'Text ' . ( $index + 1 ), 'format' => 'text', 'kind' => 'text', 'writable' => true ]; } return [ 'fields' => $fields, 'containers' => [] ]; }
    public function validate_binding( $native, $binding ) { foreach ( $this->describe( $native )['fields'] as $field ) { if ( $field['target'] === $binding['target'] ) { return true; } } return new WP_Error( 'x', 'Unknown field ' . $binding['target'] ); }
    public function validate_region( ...$args ) { return true; }
    public function instantiate( $native, ...$args ) { return $native; }
    public function bind( $native, $binding, $value ) { Engine::put( $native, $binding['target'], $value ); return $native; }
    public function replace_region( $native, ...$args ) { return $native; }
    public function finalize( $native, ...$args ) { $text = implode( '|', array_column( $native['document'], 'text' ) ); return [ 'native_document' => $text, 'writer_payload' => [ 'content' => $text ], 'preview_html' => $text ]; }
}
Engine::register_adapter( 'list', new List_Builder() );
$blueprint = [ 'document' => [ [ 'text' => 'Hero' ], [ 'text' => 'Example testimonial' ], [ 'text' => 'Body' ] ] ];
$recipe = [ 'format' => Engine::FORMAT, 'builder' => 'list', 'mode' => 'static', 'blueprint' => $blueprint, 'regions' => [], 'instructions' => '', 'bindings' => [
    [ 'id' => 'nova_role_rich_text_0000000000000001', 'target' => '/document/2/text', 'source' => '/body', 'format' => 'text', 'required' => true, 'label' => 'Body' ],
    [ 'id' => 'nova_role_title_0000000000000002', 'target' => Media::TITLE, 'source' => '/title', 'format' => 'text', 'required' => false, 'label' => 'Title', 'instructions' => 'Use the H1.' ],
    [ 'id' => 'nova_role_intro_0000000000000003', 'target' => Media::EXCERPT, 'source' => '/intro', 'format' => 'text', 'required' => false, 'label' => 'Introduction' ],
], 'omissions' => [ [ 'target' => '/document/1', 'label' => 'Text 2' ] ] ];
$record = [ 'id' => 'test', 'revision' => 1, 'builder' => 'list', 'resource_id' => 'post_type:page', 'recipe' => $recipe ];

$draft = good( Stored::from_recipe( $record ) );
check( ! isset( $draft['fields']['/title'] ) && isset( $draft['fields'][Media::TITLE] ), 'A mapped native title replaces the implicit content title' );
check( true === $draft['fields'][Media::TITLE]['required'] && 'Use the H1.' === $draft['fields'][Media::TITLE]['instructions'], 'The native title is always required and keeps its instructions' );
check( 'text' === $draft['fields'][Media::EXCERPT]['description']['value_type'] && false === $draft['fields'][Media::EXCERPT]['required'], 'The excerpt is an optional plain-text field' );
check( [ '/document/1' ] === $draft['omissions'] && [ '/document/1' ] === $draft['stored_layout']['omissions'], 'Removed elements are sealed into the saved layout' );
check( [ 'kind' => 'post', 'column' => 'title', 'mode' => 'adapt' ] === $draft['stored_layout']['bindings'][Media::TITLE] && 'excerpt' === $draft['stored_layout']['bindings'][Media::EXCERPT]['column'], 'Title and excerpt are written as post columns' );
good( Stored::validate( $draft ) );
$compiled = good( Stored::compile( $draft, values( [ '/document/2/text' => 'New body', Media::TITLE => 'Paginatitel', Media::EXCERPT => 'Samenvatting' ] ), 'op-1' ) );
check( 'Hero|New body' === $compiled['writer_payload']['content'], 'The removed element is absent and the mapped field is filled' );
check( 'Paginatitel' === $compiled['post_fields']['title'] && 'Samenvatting' === $compiled['post_fields']['excerpt'], 'Native title and excerpt reach the post fields' );

$tampered = $draft; $tampered['omissions'] = []; check( is_wp_error( Stored::validate( $tampered ) ), 'Removing an omission after sealing is detected' );
$overlap = $record; $overlap['recipe']['omissions'] = [ [ 'target' => '/document/2' ] ]; check( is_wp_error( Stored::from_recipe( $overlap ) ), 'A mapped element cannot be removed' );

$plain = $record; unset( $plain['recipe']['omissions'] ); $plain['recipe']['bindings'] = [ $recipe['bindings'][0] ];
$legacy = good( Stored::from_recipe( $plain ) );
check( isset( $legacy['fields']['/title'] ) && ! array_key_exists( 'omissions', $legacy['stored_layout'] ) && ! array_key_exists( 'omissions', $legacy ), 'Layouts without new choices keep the implicit title and their previous sealed shape' );
$compiled = good( Stored::compile( $legacy, values( [ '/title' => 'Kop', '/document/2/text' => 'Body 2' ] ), 'op-2' ) );
check( 'Hero|Example testimonial|Body 2' === $compiled['writer_payload']['content'] && 'Kop' === $compiled['post_fields']['title'], 'Existing layouts publish unchanged' );

$service = $record; $service['resource_id'] = 'post_type:service';
check( is_wp_error( Stored::from_recipe( $service ) ), 'An excerpt cannot be mapped where the post type has no excerpt' );
echo "Stored layout post field checks: {$checks} passed.\n";
