<?php
require __DIR__ . '/custom-html-writer-unit.php';

// An imported article may occupy one native string in either editor.
$article = '<article><div><a href="/sample.jpg"><img src="/sample.jpg" alt="Original" width="560"></a></div><br><p>Old.</p><style>.example{color:red}</style><table><tr><td>Old.</td></tr></table></article>';
$source = '<article><div><a href="/full.jpg"><img src="/new.jpg" alt="A &amp; B" width="560"></a></div><br><h2>New</h2><p>Two paragraphs.</p></article>';
foreach ( [ 'core', 'elementor' ] as $builder ) {
    $adapter = ok( Nova_Bridge_Suite_Recipe_Engine::adapter( $builder ) );
    $owner = 'core' === $builder ? '/document/sections/0/body' : '/document/0/settings/editor';
    $blueprint = 'core' === $builder
        ? [ 'type' => 'post_type', 'post_type' => 'post', 'resource_id' => 'post_type:post', 'document' => [ 'title' => '', 'sections' => [ [ 'body' => $article ] ], 'faqs' => [] ] ]
        : [ 'document' => [ [ 'id' => 'a000001', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => [ 'editor' => $article ], 'elements' => [] ] ] ];
    $fields = array_column( ok( $adapter->describe( $blueprint ) )['fields'], null, 'target' );
    $values = [ 'article_body' => '<h2>New</h2><p>Two paragraphs.</p>', 'link_url' => '/full.jpg', 'image_alt' => 'A & B', 'image_url' => '/new.jpg' ];
    $native = $blueprint;
    $bindings = [];
    foreach ( $values as $name => $value ) {
        $path = $owner . '/article_fields/' . $name;
        check( ! empty( $fields[$path]['writable'] ), 'Embedded field is independently writable.' );
        $binding = [ 'target' => $path, 'format' => $fields[$path]['format'] ];
        $bindings[] = $binding;
        $native = ok( $adapter->bind( $native, $binding, $value ) );
    }
    check( is_wp_error( $adapter->validate_plan_scope( $blueprint, array_merge( $bindings, [ [ 'target' => $owner ] ] ), [] ) ), 'Overlapping whole and nested article mappings rejected.' );
    $result = ok( $adapter->finalize( $native, 'embedded-article', [ 'recipe' => [ 'blueprint' => $blueprint ] ] ) );
    $html = 'core' === $builder ? $result['writer_payload']['content'] : $result['writer_payload']['elementor_data'][0]['settings']['editor'];
    check( $source === $html, 'Image attributes, linked URL and body render in either binding order.' );
    $original = ok( $adapter->finalize( $blueprint, 'embedded-article', [ 'recipe' => [ 'blueprint' => $blueprint ] ] ) );
    check( $article === ( 'core' === $builder ? $original['writer_payload']['content'] : $original['writer_payload']['elementor_data'][0]['settings']['editor'] ), 'Untouched native article is byte-identical.' );
}
foreach ( [ '<div><img src="/x" srcset="/y 2x"><p>Copy</p></div>', '<div><p>Copy</p><img src="/x"></div>', '<div><img src="/x"></div><p>Outside</p>' ] as $opaque ) {
    check( ! Nova_Mapping_Embedded_Article::fields( $opaque, '/body' ), 'Ambiguous image or body stays in the original whole-content field.' );
}
