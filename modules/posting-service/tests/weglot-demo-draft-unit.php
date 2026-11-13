<?php
/** Draft translations inherit the verified Weglot original's publication state. */
require __DIR__ . '/url-family-weglot-unit.php';
use Nova_Bridge_Suite_Translation_Writer as Writer;
$before_checks = $checks;

class WP_Post {
    public $ID;
    public $post_type;
    public $post_status;
    public $post_password;
    public $post_title;
    public $post_excerpt;
    public $post_content;
    public function __construct( array $data ) {
        foreach ( $data as $key => $value ) { $this->$key = $value; }
    }
}
function get_sample_permalink( $id ) {
    return [ home_url( '/%pagename%/' ), 'lorem-ipsum-demo-informative' ];
}

$original = new WP_Post( [
    'ID' => 41, 'post_type' => 'blog', 'post_status' => 'draft', 'post_password' => '',
    'post_title' => 'English draft', 'post_excerpt' => '', 'post_content' => 'English draft body',
] );
$meta = [];
$pending = false;
$directories = true;
$content['url'] = home_url( '/de/lorem-ipsum-demo-informative/' );
$content['url_family']['primary_url'] = home_url( '/lorem-ipsum-demo-informative/' );
$context = [ 'target_post_id' => 41, 'desired_status' => 'draft', 'operation_id' => 'draft-operation' ];
$before = clone $original;

$selected = ok( Writer::policy( $content, $profile ) );
check( $selected['routing']['publication'] === 'preserve', 'A Weglot test inherits the original publication state' );
$plan = ok( Writer::plan( $content, $profile, $context ) );
check( $plan['desired_status'] === 'draft' && $plan['provider']['source_status'] === 'draft', 'The retained plan identifies the original draft' );
check( $plan['target']['target_url'] === rtrim( $content['url'], '/' ), 'Draft sample permalink resolves to the requested language URL' );
$saved = ok( Writer::apply( $plan, 'draft-operation' ) );
check( $saved['cms_post_status'] === 'draft' && $saved['post_id'] === 41, 'The translation receipt reports the shared draft' );
check( $original == $before, 'The original title, body and publication state remain intact' );
check( ( Nova_WordPress_Weglot::get( 41, 'de' )['title'] ?? null ) === 'Stein', 'The German copy is stored on the draft' );
$after = $meta;
check( ok( Writer::recover( $plan, 'draft-operation' ) ) === $saved, 'Draft recovery returns the same receipt' );
check( ok( Writer::apply( $plan, 'draft-operation' ) ) === $saved && $meta === $after, 'Replaying the draft does not repeat native writes' );

$original->post_status = 'publish';
check( is_wp_error( Writer::recover( $plan, 'draft-operation' ) ), 'A draft plan detects a changed original publication state' );
$original->post_status = 'draft';
$original->post_content = 'Edited outside NOVA';
check( is_wp_error( Writer::verify( $plan, $saved ) ), 'A changed original body invalidates the draft receipt' );
$original->post_content = $before->post_content;

$bad = $content;
$bad['url'] = home_url( '/de/lorem-ipsum-demo-service/' );
check( Writer::policy( $bad, $profile )->get_error_code() === 'nova_translation_test_url', 'A different demo destination cannot reuse the source' );
$bad = $content;
unset( $bad['url_family'] );
check( is_wp_error( Writer::policy( $bad, $profile ) ), 'An unassociated draft cannot be selected by a demo slug alone' );
$bad = $content;
$bad['url'] = home_url( '/de/ordinary-article/' );
check( is_wp_error( Writer::policy( $bad, $profile ) ), 'Ordinary content retains its published-original requirement' );

$original->post_password = 'protected';
check( is_wp_error( Writer::policy( $content, $profile ) ), 'A protected original is rejected' );
$original->post_password = '';
$deny = true;
check( is_wp_error( Writer::policy( $content, $profile ) ), 'The translation actor still requires editing permission' );
$deny = false;

$draft_configuration = $profile;
$draft_configuration['local']['routing']['publication'] = 'draft';
$draft_configuration['profile_digest'] = hash( 'sha256', json_encode( $draft_configuration['local'] ) );
check( ! is_wp_error( Writer::policy( $content, $draft_configuration ) ), 'An explicit draft mapping can translate a Weglot test draft' );

$source_delivery = $content;
$source_delivery['url_id'] = $content['url_family']['primary_url_id'];
$source_delivery['url'] = $content['url_family']['primary_url'];
$source_delivery['language'] = $content['url_family']['primary_language'];
$source_delivery['draft_demo'] = true;
check( Nova_Bridge_Suite_Translation_Source::depends_on( $content, $source_delivery ), 'Completing a demo draft wakes its matching Weglot translation' );
$ordinary = $content;
$ordinary['url'] = home_url( '/de/ordinary-article/' );
check( ! Nova_Bridge_Suite_Translation_Source::depends_on( $ordinary, $source_delivery ), 'A draft completion does not wake ordinary translations' );
check( ! Nova_Bridge_Suite_Publication_Policy::allows_shared_test( [ 'provider' => 'translatepress' ] ), 'TranslatePress keeps its current test policy' );
check( ! Nova_Bridge_Suite_Publication_Policy::allows_shared_test( null ), 'Unavailable providers do not acquire a draft exception' );

echo 'Weglot demo draft checks: ' . ( $checks - $before_checks ) . " passed.\n";
