<?php
/** Elementor dynamic tags: internal maps are not fields, and page-field tags point to the native field. */
require __DIR__ . '/recipe-engine-unit.php';
$before_checks = $checks;
$tag = static function ( string $name ): string { return '[elementor-tag id="a1b2c3d" name="' . $name . '" settings="%7B%7D"]'; };
$blueprint = [ 'document' => [ [ 'id' => 'c0ffee1', 'elType' => 'container', 'settings' => [], 'elements' => [
    [ 'id' => 'head001', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Stale fallback', '__dynamic__' => [ 'title' => $tag( 'post-title' ) ], '__globals__' => [ 'title_color' => 'globals/colors?id=primary' ] ], 'elements' => [] ],
    [ 'id' => 'text001', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => [ '__dynamic__' => [ 'editor' => $tag( 'post-excerpt' ) ] ], 'elements' => [] ],
    [ 'id' => 'imag001', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => [ 'image' => [ 'url' => 'https://fixture.example/a.jpg', 'id' => 5 ], '__dynamic__' => [ 'image' => $tag( 'post-featured-image' ) ] ], 'elements' => [] ],
    [ 'id' => 'acf0001', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Fallback', '__dynamic__' => [ 'title' => $tag( 'acf-text' ) ] ], 'elements' => [] ],
    [ 'id' => 'stat001', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Static heading' ], 'elements' => [] ],
] ] ] ];
$adapter = new Nova_Bridge_Suite_Recipe_Elementor();
$description = $adapter->describe( $blueprint );
check( ! is_wp_error( $description ), 'A document with dynamic tags is described' );
$fields = array_column( $description['fields'], null, 'target' );
check( ! array_filter( array_keys( $fields ), static function ( $target ) { return false !== strpos( $target, '__dynamic__' ) || false !== strpos( $target, '__globals__' ); } ), 'Elementor internal tag and global-style maps are not listed as fields' );
$title = $fields['/document/0/elements/0/settings/title'] ?? null;
check( $title && ! $title['writable'] && '/native_post/title' === $title['dynamic']['source'] && false !== strpos( $title['reason'], 'Map “Title”' ), 'A post-title heading points to the native Title field' );
$excerpt = $fields['/document/0/elements/1/settings/editor'] ?? null;
check( $excerpt && ! $excerpt['writable'] && '/native_post/excerpt' === $excerpt['dynamic']['source'] && 'text001' === $excerpt['native_node_id'], 'A dynamic-only excerpt widget is still listed and points to Excerpt' );
$image = $fields['/document/0/elements/2/settings/image/url'] ?? null;
check( $image && ! $image['writable'] && '/native_post/featured_image' === $image['dynamic']['source'], 'A featured-image tag points to the featured image field' );
$acf = $fields['/document/0/elements/3/settings/title'] ?? null;
check( $acf && ! $acf['writable'] && '' === $acf['dynamic']['source'] && false !== strpos( $acf['reason'], 'acf-text' ), 'Other dynamic tags stay protected and name their tag' );
check( ! empty( $fields['/document/0/elements/4/settings/title']['writable'] ), 'Static widgets stay mappable' );
check( 1 === count( array_filter( $description['fields'], static function ( $field ) { return '/document/0/elements/0' === $field['node_target']; } ) ), 'Each dynamic control is listed once' );
check( 'post-title' === Nova_Bridge_Suite_Recipe_Elementor::dynamic_tag( $tag( 'post-title' ) ) && '' === Nova_Bridge_Suite_Recipe_Elementor::dynamic_tag( '[elementor-tag name=post-title]' ), 'Only well-formed Elementor tags are recognized' );
// Removing a widget leaves a valid document.
$recipe = [ 'format' => 'nova-layout-recipe/v1', 'builder' => 'elementor', 'mode' => 'static', 'blueprint' => $blueprint, 'bindings' => [], 'regions' => [], 'omissions' => [ [ 'target' => '/document/0/elements/3' ] ] ];
check( ! is_wp_error( Nova_Bridge_Suite_Recipe_Engine::validate( $recipe ) ), 'An Elementor widget can be removed' );
echo 'Elementor dynamic field checks: ' . ( $checks - $before_checks ) . " passed.\n";
