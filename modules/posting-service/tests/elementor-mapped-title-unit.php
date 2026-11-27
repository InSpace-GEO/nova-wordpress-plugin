<?php
require __DIR__ . '/stored-layout-generic-writer-unit.php';

class Title_Test_Elementor {
    public static function instance() {
        $manager = new class {
            public function get( $id ) { return null; }
            public function get_element_types( $type ) { return 'container' === $type; }
            public function get_widget_types( $type ) { return 'heading' === $type; }
        };
        return (object) [ 'documents' => $manager, 'elements_manager' => $manager, 'widgets_manager' => $manager ];
    }
}
class_alias( Title_Test_Elementor::class, 'Elementor\Plugin' );
define( 'ELEMENTOR_VERSION', '3.30.3' );

foreach ( [ '', 'Literal example heading' ] as $heading ) {
    reset_native();
    [ $delivery, $config ] = fixture( 'core', 'page' );
    $widget = [ 'id' => 'h000001', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'header_size' => 'h1', 'title' => $heading ], 'elements' => [] ];
    $document = [ $widget ];
    if ( '' === $heading ) {
        $document[0]['settings']['__dynamic__']['title'] = '[elementor-tag id="headline" name="page-title" settings="%7B%7D"]';
        $second = $document[0];
        $second['id'] = 'h000002';
        $document[] = $second;
    }
    $target = Nova_Bridge_Suite_Recipe_Native_Media::TITLE;
    $record = [ 'id' => op( 90 ), 'revision' => 5, 'resource_id' => 'post_type:page', 'builder' => 'elementor',
        'recipe' => [ 'builder' => 'elementor', 'mode' => 'static', 'blueprint' => [ 'document' => $document ],
            'bindings' => [ [ 'target' => $target, 'source' => '/title', 'format' => 'text', 'required' => true ] ], 'regions' => [] ] ];
    $local = ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $record ) );
    check( ! isset( $local['fields']['/title'] ), 'Explicit native title replaces the implicit title field.' );
    $config['local'] = $local;
    $config['profile_digest'] = hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $local ) );
    $delivery['destination_values'] = [ Nova_Bridge_Suite_Writing_Adapter::field_id( $target ) => 'Fitted WordPress title' ];
    $plan = ok( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $delivery, $config, [ 'operation_id' => op( 700 ) ] ) );
    check( 'Fitted WordPress title' === $plan['post_fields']['title'], 'Elementor headings cannot overwrite the mapped WordPress title.' );
}
