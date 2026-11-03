<?php
/** Capture and compile an immutable Cornerstone recipe using the builder's content interfaces in memory. */
namespace Themeco\Cornerstone\Documents {
    class Document {
        public static function locate( int $id ) { return new Content( $id ); }
    }
    class Content extends Document {
        public function __construct( private int $post ) {}
        public function id() { return $this->post; }
        public function data() { return [ 'elements' => json_decode( $GLOBALS['raw'], true )['data'] ]; }
        public function settings() { return [ 'layoutHeader' => 'default', 'customCSS' => '.example{color:red}', 'customJS' => 'originalOnly()', 'general_post_title' => 'Original' ]; }
        public function update( $change ) {}
        public function save() {}
    }
}
namespace {
    define( 'ABSPATH', __DIR__ );
    define( 'CS_VERSION', '7.9.7' );
    class WP_Error {
        public function __construct( public string $code, public string $message = '', public array $data = [] ) {}
        public function get_error_message() { return $this->message; }
    }
    function is_wp_error( $value ) { return $value instanceof WP_Error; }
    function wp_json_encode( $value, $options = 0 ) { return json_encode( $value, $options ); }
    function wp_kses_post( $markup ) { return strip_tags( $markup, '<p><strong><em>' ); }
    function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false ); }
    function current_user_can( $cap, ...$args ) { return true; }
    function get_post( $id ) { return $GLOBALS['example'] ?? null; }
    function get_template() { return 'x'; }
    function get_page_template_slug( $id ) { return 'template-blank-no-container.php'; }
    function get_post_type_object( $name ) { return (object) [ 'cap' => (object) [ 'edit_posts' => 'edit_posts' ] ]; }
    function metadata_exists( $type, $id, $key ) { return array_key_exists( $key, $GLOBALS['meta'] ); }
    function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $key ] ?? ''; }
    function cs_render_document_html( $id ) { return ''; }
    function cornerstone( $name ) {
        return new class {
            public function userCanAccessPostType( $type ) { return 'page' === $type || 'post' === $type; }
            public function get_element( $type ) { return $this; }
            public function get_defaults() { return [ 'image_src' => '', 'image_alt' => '' ]; }
        };
    }
    function check( $condition, $message ) { if ( ! $condition ) { throw new \RuntimeException( $message ); } }
    function good( $result ) { if ( is_wp_error( $result ) ) { throw new \RuntimeException( $result->message ); } return $result; }
    require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-engine.php';
    require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-cornerstone.php';

    $raw = '{"data":[{"_type":"section","_p_local":{},"_modules":[{"_type":"headline","text_content":"Reference"},{"_type":"text","text_content":"<p>Original body</p>"},{"_type":"image"}]}]}';
    $example = (object) [ 'ID' => 30, 'post_type' => 'page' ];
    $meta = [ '_cornerstone_data' => $raw, '_x_entry_disable_page_title' => 'on', '_x_entry_body_css_class' => 'service', '_x_entry_alternate_index_title' => 'private title' ];
    $adapter = new Nova_Bridge_Suite_Recipe_Cornerstone();
    $engine = Nova_Bridge_Suite_Recipe_Engine::class;
    $engine::register_adapter( 'cornerstone', $adapter );
    $design = good( $adapter->capture( 30 ) );
    $f = array_column( $design['fields'], null, 'kind' );
    check( '2' === $f['heading']['native_node_id'] && 'cornerstone' === $f['heading']['locator']['strategy'], 'Modern elements expose the preorder ID calculated by Cornerstone.' );
    check( 'cornerstone' === $f['image_url']['native_media']['kind'] && '' === $f['image_url']['value'], 'An empty image widget stays selectable through the registered default.' );
    $b = $design['blueprint'];
    check( 'template-blank-no-container.php' === $b['template'] && $b['meta']['_x_entry_disable_page_title']['present'] && ! $b['meta']['_x_post_layout']['present'], 'Store only explicitly supported X layout keys, including absence.' );
    check( ! isset( $b['settings']['customJS'] ) && ! isset( $b['meta']['_x_entry_alternate_index_title'] ), 'A saved mapping must not copy executable page settings or unrelated editorial data.' );
    $recipe = [ 'format' => $engine::FORMAT, 'builder' => 'cornerstone', 'mode' => 'static', 'blueprint' => $b, 'bindings' => [], 'regions' => [] ];
    foreach ( [ 'heading' => 'title', 'body' => 'article' ] as $kind => $source ) {
        $recipe['bindings'][] = [ 'target' => $f[ $kind ]['target'], 'source' => '/' . $source, 'format' => $f[ $kind ]['format'], 'required' => true ];
    }
    unset( $GLOBALS['raw'], $GLOBALS['meta'], $GLOBALS['example'] );
    $compiled = good( $engine::compile( $recipe, [ 'title' => 'New title', 'article' => '<p>Fitted <strong>body</strong>.</p>' ], 'delivery:new' ) );
    $native = json_decode( $compiled['native_document'] );
    check( $native[0]->_p_local instanceof \stdClass, 'An empty native control object survives the REST recipe round trip.' );
    check( 'New title' === $native[0]->_modules[0]->text_content && '<p>Fitted <strong>body</strong>.</p>' === $native[0]->_modules[1]->text_content, 'A fitted native document compiles after the example was removed.' );
    check( $compiled['writer_payload']['meta'] === $b['meta'] && $compiled['writer_payload']['settings'] === $b['settings'], 'The writer receives the saved native presentation.' );
    echo "Cornerstone capture, native controls and source-independent recipe compilation passed.\n";
}
