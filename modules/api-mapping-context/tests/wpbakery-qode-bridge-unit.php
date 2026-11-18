<?php
/** Bridge-specific capture, field semantics and native writer preflight. */
require __DIR__ . '/recipe-builders-unit.php';

function get_template() { return $GLOBALS['recipe_qode_theme'] ?? ''; }
final class WPBMap {
    public static function getShortCode( $tag ) {
        if ( 'qode_button_v2' !== $tag ) { return null; }
        return [ 'name' => 'Qode button', 'params' => [
            [ 'type' => 'textfield', 'param_name' => 'text' ],
            [ 'type' => 'textfield', 'param_name' => 'link' ],
            [ 'type' => 'dropdown', 'param_name' => 'target' ],
        ] ];
    }
}

$GLOBALS['recipe_qode_theme'] = 'bridge';
$body = '[qode_workflow animate="yes"][qode_workflow_item title="Original process" subtitle="Original label" text="Original body."][/qode_workflow]';
$body .= '[qode_button_v2 text="Original CTA" link="/original/?a=1&amp;b=2" target="_blank"]';
$post = (object) [ 'ID' => 8200, 'post_type' => 'page', 'post_content' => $body ];
$GLOBALS['recipe_posts'][8200] = $post;
$GLOBALS['recipe_meta'][8200] = [ 'qode_page_subtitle' => 'Original &amp; detail', 'qode_show-page-title' => 'no', 'qode_title-height' => '120', 'qode_unrelated_key' => 'Keep outside clones' ];
foreach ( [ 'qode_workflow', 'qode_workflow_item', 'qode_button_v2' ] as $tag ) { add_shortcode( $tag, static fn() => '' ); }

recipe_assert( [ 'wpbakery' ] === Nova_Bridge_Suite_Recipe_Builders::native_carriers( $post ), 'A complete Bridge shortcode tree is a WPBakery carrier without a vc_row' );
$adapter = Nova_Bridge_Suite_Recipe_Builders::adapter( 'wpbakery' );
$capture = recipe_ok( $adapter->capture( 8200 ), 'Capture Bridge-only WPBakery source' );
$meta = $capture['blueprint']['meta'];
recipe_assert( isset( $meta['qode_title-height'] ) && ! isset( $meta['qode_unrelated_key'] ), 'Capture verified Bridge page settings only' );
$fields = array_column( $capture['fields'], null, 'target' );
$subtitle = '/meta/qode_page_subtitle';
$link = '/document/1/attributes/link';
recipe_assert( 'Original & detail' === $fields[$subtitle]['value'] && 'qode_title' === $fields[$subtitle]['locator']['strategy'], 'Expose rendered subtitle text with a Bridge title locator' );
recipe_assert( '/original/?a=1&b=2' === $fields[$link]['value'] && ! isset( $fields['/document/1/attributes/target'] ), 'Expose the rendered button URL and keep its target as a design option' );

$same = recipe_ok( $adapter->bind( $capture['blueprint'], [ 'target' => $subtitle, 'format' => 'text' ], 'Original & detail' ), 'An unchanged subtitle keeps its encoding' );
recipe_assert( $same === $capture['blueprint'], 'No-op retains the exact native document' );
$bound = recipe_ok( $adapter->bind( $same, [ 'target' => $subtitle, 'format' => 'text' ], 'New <label> & detail' ), 'Bind title-area text' );
$bound = recipe_ok( $adapter->bind( $bound, [ 'target' => $link, 'format' => 'url' ], '/new/?a=1&b=2#anchor' ), 'Bind direct Qode URL' );
$compiled = recipe_ok( $adapter->finalize( $bound, 'qode-test' ), 'Serialize Bridge native document' );
$payload = $compiled['writer_payload'];
recipe_assert( 'New &lt;label&gt; &amp; detail' === $payload['meta']['qode_page_subtitle'] && '120' === $payload['meta']['qode_title-height'], 'Escape mapped HTML-sensitive text while retaining height' );
recipe_assert( str_contains( $payload['layout']['raw_shortcodes'], 'link="/new/?a=1&amp;b=2#anchor" target="_blank"' ) && str_contains( $payload['layout']['raw_shortcodes'], 'animate="yes"' ), 'Change only the intended shortcode value' );
recipe_ok( $adapter->publication_probe( 'page', $payload, 'draft' ), 'All required Bridge renderers are available' );
remove_shortcode( 'qode_workflow_item' );
$blocked = $adapter->publication_probe( 'page', $payload, 'draft' );
recipe_assert( is_wp_error( $blocked ) && 'nova_qode_element' === $blocked->get_error_code(), 'Detect a missing nested Bridge dependency before writing' );
$GLOBALS['recipe_qode_theme'] = 'other-theme';
$blocked = $adapter->publication_probe( 'page', $payload, 'draft' );
recipe_assert( is_wp_error( $blocked ) && 'nova_qode_theme' === $blocked->get_error_code(), 'Bridge page settings cannot be replayed on a different theme' );
recipe_assert( [] === Nova_Bridge_Suite_Recipe_Builders::native_carriers( $post ), 'Other themes retain their existing standalone shortcode behavior' );
$GLOBALS['recipe_qode_theme'] = 'bridge';
$post->post_content = '<p>Classic article body.</p>' . $body;
recipe_assert( [] === Nova_Bridge_Suite_Recipe_Builders::native_carriers( $post ), 'An inline Qode shortcode does not take over the classic article body' );
$hidden = $meta;
$hidden['qode_show-page-title'] = 'yes';
recipe_assert( [] === Nova_Mapping_Qode_Bridge::fields( $hidden ), 'Hidden title copy is not offered as a visible title-area field' );
echo "PASS Bridge capture, mapped text and link serialization, dependency preflight and classic behavior.\n";
