<?php
/** HALO aliases, typed fitting values, permission boundaries and omission semantics. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    public function __construct( public $code, public $message, public $data = [] ) {}
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function get_registered_meta_keys( $kind, $type = '' ) { return $GLOBALS['registry'][$type] ?? []; }
function current_user_can( $cap, ...$args ) { return ! in_array( $args[1] ?? '', $GLOBALS['denied'], true ); }
function get_current_user_id() { return 1; }
function get_post_type_object( $type ) { return (object) [ 'cap' => (object) [ 'create_posts' => 'edit_posts' ] ]; }
function wp_http_validate_url( $value ) { return filter_var( $value, FILTER_VALIDATE_URL ) ? $value : false; }
function wp_attachment_is_image( $id ) { return $id === 23; }
function sanitize_meta( $key, $value, $kind, $type ) { return $value; }
function rest_validate_value_from_schema( $value, $schema, $name ) {
    $valid = match ( $schema['type'] ) {
        'string' => is_string( $value ), 'boolean' => is_bool( $value ),
        'integer' => is_int( $value ), 'array' => is_array( $value ) && array_is_list( $value ), default => false,
    };
    if ( ! $valid || ( is_array( $value ) && count( $value ) > ( $schema['maxItems'] ?? 100 ) ) ) { return new WP_Error( 'invalid', $name ); }
    return true;
}
function rest_sanitize_value_from_schema( $value, $schema ) { return $value; }
function metadata_exists( $kind, $id, $key ) { return array_key_exists( $key, $GLOBALS['values'] ); }
function get_post_meta( $id, $key, $single ) { return array_key_exists( $key, $GLOBALS['values'] ) ? [ $GLOBALS['values'][$key] ] : []; }
function maybe_unserialize( $value ) { return $value; }
require __DIR__ . '/../includes/class-nova-wordpress-halo-fields.php';
use Nova_WordPress_Halo_Fields as Halo;
$checks = 0; $denied = []; $values = []; $registry = [];
function check( $condition, $message ) { ++$GLOBALS['checks']; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function register_fixture( $type, $prefix ) {
    foreach ( [ 'seo_title_text' => 'string', 'seo_meta_description_text' => 'string', 'article_faq_questions' => 'array', 'display_intake_form_boolean' => 'boolean', 'optional_second_image_id' => 'integer' ] as $name => $kind ) {
        $schema = [ 'type' => $kind, 'context' => [ 'edit' ] ];
        if ( $kind === 'array' ) { $schema += [ 'maxItems' => 20, 'items' => [ 'type' => 'object', 'properties' => [ 'question' => [ 'type' => 'string' ], 'answer' => [ 'type' => 'string' ] ], 'additionalProperties' => false ] ]; }
        $GLOBALS['registry'][$type][$prefix . $name] = [ 'type' => $kind, 'single' => true, 'show_in_rest' => [ 'name' => $name, 'schema' => $schema ], 'default' => $kind === 'array' ? [] : ( $kind === 'boolean' ? false : '' ), 'auth_callback' => static fn() => true ];
    }
}
register_fixture( 'post', '_ec_' ); register_fixture( 'knowledge', '_different_' );
$fields = Halo::catalog( 'post', 10 );
check( count( $fields ) === 5, 'HALO fields discovered through REST aliases' );
check( $fields['article_faq_questions']['key'] === '_ec_article_faq_questions', 'Local storage identity retained' );
check( Halo::catalog( 'knowledge', 10 )['article_faq_questions']['key'] === '_different_article_faq_questions', 'Alternate site prefix supported' );
check( Halo::catalog( 'page', 10 ) === [], 'Unrelated post types unchanged' );
check( Halo::valid( $fields['article_faq_questions'] ), 'Typed field binding accepted' );
check( ! Halo::valid( array_replace( $fields['article_faq_questions'], [ 'key' => '_credentials/token' ] ) ), 'Arbitrary storage path rejected' );
check( Halo::check( 'post', 10, $fields['article_faq_questions'] ) === true, 'Current schema accepted' );
$changed = $fields['article_faq_questions']; $changed['schema']['maxItems'] = 30;
check( is_wp_error( Halo::check( 'post', 10, $changed ) ), 'Schema drift rejected' );
$denied = [ '_ec_article_faq_questions' ];
check( ! isset( Halo::catalog( 'post', 10 )['article_faq_questions'] ), 'Native authorization applied' );
$denied = [];
check( Halo::decode( '[{"question":"Why?","answer":"Because."}]', $fields['article_faq_questions'] )[0]['answer'] === 'Because.', 'JSON fitting list decoded' );
check( Halo::decode( '[]', $fields['article_faq_questions'] ) === [], 'Explicit empty list retained' );
check( is_wp_error( Halo::decode( 'not json', $fields['article_faq_questions'] ) ), 'Malformed fitting list rejected' );
check( Halo::decode( 'false', $fields['display_intake_form_boolean'] ) === false, 'False remains boolean false' );
check( is_wp_error( Halo::decode( '"false"', $fields['display_intake_form_boolean'] ) ), 'String boolean rejected' );
check( Halo::decode( '23', $fields['optional_second_image_id'] ) === 23, 'Accessible image ID accepted' );
check( is_wp_error( Halo::decode( '24', $fields['optional_second_image_id'] ) ), 'Invalid image ID rejected' );
check( Halo::decode( 'https://example.com/photo.jpg', $fields['optional_second_image_id'] ) === 'https://example.com/photo.jpg', 'Image URL retained until publication' );
$recipe = [ 'posting_fields' => [ $fields['article_faq_questions'], $fields['display_intake_form_boolean'] ] ];
check( Halo::compile( $recipe, [] ) === [], 'Omitted values stay omitted' );
check( Halo::compile( $recipe, [ 'halo' => [ 'article_faq_questions' => [], 'display_intake_form_boolean' => false ] ] )['display_intake_form_boolean']['value'] === false, 'Explicit empty and false survive compilation' );
$recipe['posting_fields'][0]['required'] = true;
check( is_wp_error( Halo::compile( $recipe, [] ) ), 'Required omitted field rejected' );
$schema = Halo::content_schema( $recipe, [ 'type' => 'object', 'properties' => [] ] );
check( $schema['properties']['halo']['properties']['article_faq_questions']['type'] === 'array', 'Preview uses native list schema' );
check( $schema['properties']['halo']['required'] === [ 'article_faq_questions' ], 'Required native field advertised' );
check( Halo::snapshot( 10, $fields['article_faq_questions'] )['value'] === null, 'Missing storage stays distinct from explicit empty' );
$values[$fields['seo_title_text']['key']] = 'a:1:{i:0;s:4:"text";}';
check( Halo::snapshot( 10, $fields['seo_title_text'] )['value'] === $values[$fields['seo_title_text']['key']], 'Native metadata is not unserialized twice' );
check( ! Halo::matches( [ 'value' => null ], [], $fields['article_faq_questions'], 'post' ), 'Missing FAQ does not erase theme fallback' );
check( Halo::matches( [ 'value' => null ], false, $fields['display_intake_form_boolean'], 'post' ), 'Native false default readback accepted' );
$registry['post']['duplicate'] = $registry['post']['_ec_article_faq_questions'];
check( Halo::catalog( 'post' ) === [], 'Ambiguous required alias rejected' );
echo "HALO field checks: $checks passed.\n";
