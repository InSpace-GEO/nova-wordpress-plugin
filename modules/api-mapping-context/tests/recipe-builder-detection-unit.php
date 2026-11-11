<?php
/** Auto capture follows native editor flags; retained documents remain protected elsewhere. */
define( 'ABSPATH', __DIR__ . '/no-wordpress/' );
class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_post( $id ) { return 42 === $id ? $GLOBALS['post'] : null; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['meta'][ $key ] ?? ''; }
function current_user_can() { return $GLOBALS['allowed']; }
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-builders.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-visual.php';
$checks = 0; $allowed = true;
function check( $value, $message ) { ++$GLOBALS['checks']; if ( ! $value ) { throw new RuntimeException( $message ); } }
$post = (object) [ 'ID' => 42, 'post_type' => 'page', 'post_content' => '<p>Current WordPress body</p>' ];
$resource = [ 'type' => 'post_type', 'post_type' => 'page' ];
$elementor = '[{"id":"heading","elType":"widget","widgetType":"heading","settings":{"title":"Previous heading"},"elements":[]}]';
$beaver = [ 'heading' => (object) [ 'node' => 'heading', 'type' => 'module', 'settings' => (object) [ 'type' => 'heading', 'heading' => 'Previous heading' ] ] ];
foreach ( [ '', false, '0' ] as $disabled ) {
    $meta = [ '_elementor_data' => $elementor, '_elementor_edit_mode' => $disabled, '_fl_builder_enabled' => '1', '_fl_builder_data' => $beaver ];
    $before = serialize( [ $post, $meta ] );
    check( [ 'beaver' ] === Nova_Bridge_Suite_Recipe_Builders::detect_reference( 42 ), 'Retained disabled Elementor data does not conflict with an enabled Beaver page.' );
    check( 'beaver' === Nova_Bridge_Suite_Recipe_Visual::detected_builder( $resource, 42 ), 'Automatic capture chooses the current Beaver document.' );
    check( [ 'elementor', 'beaver' ] === Nova_Bridge_Suite_Recipe_Builders::native_carriers( $post ), 'Stored-carrier guards still see both native documents.' );
    check( $before === serialize( [ $post, $meta ] ), 'Inspection never edits the example or its retained metadata.' );
    $meta = [ '_elementor_data' => $elementor, '_elementor_edit_mode' => 'builder', '_fl_builder_enabled' => $disabled, '_fl_builder_data' => $beaver ];
    check( 'elementor' === Nova_Bridge_Suite_Recipe_Visual::detected_builder( $resource, 42 ), 'Retained disabled Beaver data does not conflict with an enabled Elementor page.' );
}
$meta = [ '_elementor_data' => $elementor, '_fl_builder_data' => $beaver ];
check( 'core' === Nova_Bridge_Suite_Recipe_Visual::detected_builder( $resource, 42 ), 'A page switched back to WordPress uses its current body despite both retained trees.' );
$post->post_content = '<!-- wp:paragraph --><p>Current block body</p><!-- /wp:paragraph -->';
check( 'gutenberg' === Nova_Bridge_Suite_Recipe_Visual::detected_builder( $resource, 42 ), 'An inactive builder does not hide the current WordPress blocks.' );
$post->post_content = '<p>Current body</p>';
foreach ( [ '[]', ' [ ] ', [] ] as $empty ) {
    $meta = [ '_elementor_data' => $empty, '_fl_builder_enabled' => '1', '_fl_builder_data' => $beaver ];
    check( 'beaver' === Nova_Bridge_Suite_Recipe_Visual::detected_builder( $resource, 42 ), 'Unused empty Elementor metadata cannot claim the example.' );
}
$meta = [ '_elementor_edit_mode' => 'builder', '_elementor_data' => $elementor, '_fl_builder_enabled' => '1', '_fl_builder_data' => $beaver ];
$conflict = Nova_Bridge_Suite_Recipe_Visual::detected_builder( $resource, 42 );
check( is_wp_error( $conflict ) && 'nova_recipe_builder_ambiguous' === $conflict->get_error_code(), 'Competing enabled native documents still require an explicit choice.' );
check( [ 'status' => 422, 'detected_builders' => [ 'elementor', 'beaver' ] ] === $conflict->get_error_data(), 'The ambiguity response identifies the actual candidate builders.' );
check( str_contains( $conflict->get_error_message(), 'Elementor, Beaver Builder' ) && str_contains( $conflict->get_error_message(), 'Advanced: builder detection' ), 'The error names the builders and points to the existing recovery control.' );
$meta = [ '_elementor_edit_mode' => 'builder', '_elementor_data' => 'not valid json' ];
check( 'elementor' === Nova_Bridge_Suite_Recipe_Visual::detected_builder( $resource, 42 ), 'An enabled malformed native document is left to native validation, never captured as plain WordPress.' );
$allowed = false;
check( is_wp_error( Nova_Bridge_Suite_Recipe_Builders::detect_reference( 42 ) ), 'Detection still enforces editor permission.' );
echo 'PASS ' . $checks . " builder detection checks.\n";
