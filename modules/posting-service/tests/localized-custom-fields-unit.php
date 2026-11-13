<?php
/** Exercise both source-shared providers with the real WordPress HTML API and an in-memory post. */
if ( ! isset( $argv[1] ) ) {
    foreach ( [ 'translatepress', 'weglot' ] as $name ) {
        passthru( escapeshellarg( PHP_BINARY ) . ' -n ' . escapeshellarg( __FILE__ ) . ' ' . $name, $status );
        if ( $status ) { exit( $status ); }
    }
    return;
}
$provider = $argv[1];
$core = getenv( 'NOVA_WP_CORE_FIXTURE' );
define( 'ABSPATH', dirname( $core ) . '/' );
define( 'WPINC', basename( $core ) );
// The core HTML decoder uses its mbstring polyfills when the test runs with php -n.
require_once $core . '/compat.php';
require_once $core . '/class-wp-token-map.php';
foreach ( [ 'html5-named-character-references', 'class-wp-html-attribute-token', 'class-wp-html-span', 'class-wp-html-text-replacement', 'class-wp-html-decoder', 'class-wp-html-tag-processor' ] as $dependency ) {
    require_once $core . '/html-api/' . $dependency . '.php';
}
require_once $core . '/formatting.php';
require_once $core . '/kses.php';
require_once $core . '/class-wp-error.php';
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function apply_filters( $name, $v, ...$args ) {
    if ( 'run_wptexturize' === $name ) { return false; }
    foreach ( $GLOBALS['hooks'][$name] ?? [] as $fn ) { $v = $fn( $v, ...$args ); }
    return $v;
}
function add_filter( $name, $fn, ...$unused ) { $GLOBALS['hooks'][$name][] = $fn; }
function add_action( $name, $fn, ...$unused ) { add_filter( $name, $fn, ...$unused ); }
function did_action( $name ) { return 'elementor/loaded' === $name ? 1 : 0; }
function do_action( ...$args ) {}
function wp_cache_delete( ...$unused ) {}
function wp_allowed_protocols() { return [ 'https', 'http', 'mailto' ]; }
// WP 6.9's real tag processor uses both UTF-8 validation and the noncharacter check.
if ( is_file( $core . '/utf8.php' ) ) {
    require_once $core . '/compat-utf8.php';
    require_once $core . '/utf8.php';
} else {
    function wp_is_valid_utf8( $s ) { return true; }
}
function is_utf8_charset( $s = null ) { return true; }
function get_option( $key, $fallback = false ) { return 'active_plugins' === $key ? ( $GLOBALS['seo_plugins'] ?? [] ) : ( 'blog_charset' === $key ? 'UTF-8' : ( 'home' === $key ? home_url() : ( $GLOBALS['associations'][$key] ?? $fallback ) ) ); }
function home_url( $path = '' ) { return 'https://lang.test' . $path; }
function get_post( $id ) { return $GLOBALS['attachment_posts'][$id] ?? $GLOBALS['post']; }
function get_permalink( $id ) { return home_url( '/entry/' ); }
function get_page_template_slug( $id ) { return 'service.php'; }
function get_post_type( $id ) { return 'page'; }
function get_post_meta( $id, $name = '', $single = false ) {
    $filtered = apply_filters( 'get_post_metadata', null, $id, $name, $single );
    if ( '' === $name && null === $filtered ) { return $GLOBALS['data'][$id] ?? []; }
    $values = $filtered ?? ( $GLOBALS['data'][$id][$name] ?? [] ); return $single ? ( $values[0] ?? '' ) : $values;
}
function update_post_meta( $id, $name, $value ) { $GLOBALS['data'][$id][$name] = [ wp_unslash( $value ) ]; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function current_user_can( ...$args ) { return true; }
function is_protected_meta( $name, $type ) { return str_starts_with( $name, '_' ); }
function sanitize_meta( $name, $value, ...$args ) { return $value; }
function maybe_serialize( $value ) { return is_array( $value ) || is_object( $value ) || ( is_string( $value ) && str_starts_with( $value, 'a:0:' ) ) ? serialize( $value ) : $value; }
function is_admin() { return false; }
function is_preview() { return false; }
function is_feed() { return false; }
function is_singular() { return true; }
function is_tax( $taxonomy ) { return in_array( $GLOBALS['test_taxonomy'] ?? '', (array) $taxonomy, true ); }
function wp_doing_ajax() { return false; }
function get_queried_object_id() { return 41; }
function wp_generate_uuid4() { static $i = 0; return 'mock-uuid-' . ++$i; }
function acf_flush_value_cache( $id, $name ) { unset( $GLOBALS['acf_formatted']["$id:$name"] ); }
if ( empty( $grouped_source ) ) {
    class Nova_Bridge_Suite_Stored_Layout_Language { public static function provider() { return 'core'; } }
} else { require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout-language.php'; }
class Nova_Bridge_Suite_Stored_Layout {
    public static function compile( $draft, $values, $seed ) { return $GLOBALS['compiled_layout'] ?? [ 'builder'=>'elementor', 'post_fields'=>['title'=>'Unmapped sample title'], 'writer_payload'=>[], 'custom_fields'=>$values ]; }
}
if ( empty( $grouped_source ) ) {
    class Nova_Bridge_Suite_Translation_Source { public static function post_id( ...$unused ) { return 41; } }
} else { require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-translation-source.php'; }
class Nova_Bridge_Suite_Posting_Protocol { public static function uuid( $s ) { return true; } }
class Nova_Bridge_Suite_Writing_Adapter {
    public static function canonical_json( $v ) { return json_encode( $v ); }
    public static function adaptation_draft( $v ) { return $v; }
}
class Nova_Bridge_Suite_Content_Transport {
    public static function field_catalog( ...$unused ) { return $GLOBALS['catalog']; }
    public static function validate_payload( $input, ...$unused ) {
        $acf = [];
        foreach ( $input['acf'] ?? [] as $name => $value ) { $acf[$name] = [ 'value'=>$value ]; }
        unset( $input['acf'] ); return [ 'acf'=>$acf, 'meta'=>$input ];
    }
}
if ( 'translatepress' === $provider ) {
    class TRP_Translate_Press {
        public static function get_trp_instance() { return new self; }
        public function get_component( $unused ) { return $this; }
        public function get_settings() { return [ 'default-language'=>'en_US','publish-languages'=>['en_US','de_DE','fr_FR'],'url-slugs'=>['en_US'=>'en','de_DE'=>'de','fr_FR'=>'fr'] ]; }
        public function get_url_for_language( $code, $url, ...$unused ) { return str_replace( home_url(), home_url( 'en_US' === $code ? '' : '/' . substr( $code, 0, 2 ) ), $url ); }
    }
} else {
    class LabCustomLanguage {
        public $code;
        public function __construct( $code ) { $this->code = $code; }
        public function getInternalCode() { return $this->code; }
        public function getExternalCode() { return $this->code; }
    }
    function weglot_get_current_language() { return $GLOBALS['visitor']; }
    function weglot_get_service( $unused ) {
        return new class {
            public function get_original_language() { return new LabCustomLanguage( 'en' ); }
            public function get_destination_languages() { return [ new LabCustomLanguage( 'de' ), new LabCustomLanguage( 'fr' ) ]; }
            public function get_language_from_internal( $code ) { return new LabCustomLanguage( $code ); }
        };
    }
    function weglot_create_url_object( $url ) {
        return new class( $url ) {
            private $url;
            public function __construct( $url ) { $this->url = $url; }
            public function getForLanguage( $language ) { return str_replace( home_url(), home_url( 'en' === $language->code ? '' : '/' . $language->code ), $this->url ); }
        };
    }
}
$includes = dirname( __DIR__ ) . '/includes/';
require $includes . 'class-nova-bridge-suite-mapped-content-target.php';
require $includes . 'class-nova-bridge-suite-translation-writer.php';
require $includes . 'class-nova-wordpress-localized-fields.php';
use Nova_Bridge_Suite_Translation_Writer as Writer;
function verify( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } ++$GLOBALS['checks']; }
function success( $v ) { if ( is_wp_error( $v ) ) { throw new RuntimeException( $v->get_error_code() . ': ' . $v->get_error_message() ); } return $v; }
function request_locale( $locale ) {
    $GLOBALS['visitor'] = substr( $locale, 0, 2 ); $GLOBALS['TRP_LANGUAGE'] = $locale; $GLOBALS['hooks'] = [];
    Nova_WordPress_Localized_Fields::on_request();
}
function publish_locale( $locale, $version, $fields ) {
    global $config, $hooks; $hooks = []; // Delivery and frontend are separate WP requests.
    $content = [ 'site_id'=>'site', 'url_id'=>'url', 'url'=>'/' . substr( $locale, 0, 2 ) . '/translated-entry/', 'language'=>$locale,
        'version_number'=>$version, 'configuration'=>$config['template'], 'content_item_id'=>'item', 'destination_values'=>$fields ];
    if ( ! empty( $GLOBALS['grouped_source'] ) ) {
        $content['url_id'] = $locale === 'de_DE' ? '200' : '300';
        $content['url_family'] = [ 'url_group_id'=>'50', 'primary_url_id'=>'100', 'primary_url'=>home_url( '/entry/' ), 'primary_language'=>'en-US' ];
    }
    $op = $locale . $version; $plan = success( Writer::plan( $content, $config, ['target_post_id'=>41,'desired_status'=>'publish','operation_id'=>$op] ) );
    verify( $plan['target']['target_url'] === home_url( '/' . substr( $locale, 0, 2 ) . '/entry' ), 'The localized custom-field plan lost the provider destination' );
    verify( ! isset( $plan['fields']['title'], $plan['fields']['content'] ) && ! isset( $plan['fields']['meta'] ), 'Protected template content entered the localized plan' );
    success( Writer::apply( $plan, $op ) );
    verify( 'complete' === success( Writer::recover( $plan, $op ) )['state'], 'Saved localized custom fields could not be recovered' );
    return $plan;
}
$checks = 0; $hooks = [];
$wpdb = new class { public $posts = 'posts'; public function get_var( $q ) { return '1'; } public function prepare( $q, $name ) { return $q; } };
$post = (object) [ 'ID'=>41,'post_type'=>'page','post_status'=>'publish','post_password'=>'','post_title'=>'Source','post_excerpt'=>'','post_content'=>'Original Elementor' ];
if ( ! empty( $grouped_source ) ) {
    $associations['nova_layout_page_' . hash( 'sha256', 'site:100:en-us' )] = [ 'site_id'=>'site', 'url_id'=>'100', 'language'=>'en-US', 'post_id'=>41, 'state'=>'committed' ];
}
$data = [ 41=>[ 'caption'=>['Source caption'], '_caption'=>['field_caption'], 'link'=>['https://lang.test/en'], '_link'=>['field_link'], 'summary'=>['Original'], '_elementor_data'=>['original structure'], 'other_meta'=>['a:1:{i:0;s:3:"foo";}', 'another raw row'] ],
    42=>['caption'=>['Another page']] ];
$initial = $data[41];
$catalog = [ 'acf'=>['caption'=>['key'=>'field_caption','type'=>'wysiwyg'],'link'=>['key'=>'field_link','type'=>'url'],'hero-Email'=>['key'=>'field_mail','type'=>'email']],
    'meta'=>['summary'=>['type'=>'string','single'=>true,'show_in_rest'=>true]] ];
$fields = [ 'caption'=>['provider'=>'acf','key'=>'field_caption','type'=>'wysiwyg','value'=>'<p>Sicher &amp; gut. <strong>Gut</strong></p>'],
    'link'=>['provider'=>'acf','key'=>'field_link','type'=>'url','value'=>'https://lang.test/de/mehr/?a=1&b=2'],
    'hero-Email'=>['provider'=>'acf','key'=>'field_mail','type'=>'email','value'=>'dienst@example.org'],
    'summary'=>['provider'=>'meta','key'=>'summary','type'=>'string','value'=>'Kurze Übersicht'] ];
$local = ['routing'=>['post_type'=>'page','publication'=>'publish'],'stored_layout'=>['bindings'=>[ ['kind'=>'custom_field','mode'=>'adapt'],['kind'=>'recipe','mode'=>'protected'] ]]];
$config = ['mode'=>'stored_layout_v3','local'=>$local,'profile_digest'=>hash( 'sha256', json_encode( $local ) ),'template'=>['definition'=>['version'=>3]],'site_id'=>'site'];
$de = publish_locale( 'de_DE', 1, $fields );
$french = $fields; $french['summary']['value'] = 'Sommaire'; publish_locale( 'fr_FR', 1, $french );
request_locale( 'en_US' ); verify( 'Original' === get_post_meta( 41, 'summary', true ), 'An original-language read was overridden' );
request_locale( 'fr_FR' ); verify( 'Sommaire' === get_post_meta( 41, 'summary', true ), 'Wrong locale on French page' );
$acf_formatted = [ '41:caption'=>'Stale formatted original', '42:caption'=>'Other post' ];
request_locale( 'de_DE' );
verify( ['42:caption'=>'Other post'] === $acf_formatted, 'Previously formatted ACF values can override localized get_field() or affect another post' );
verify( [''] === get_post_meta( 41, '_elementor_element_cache', false ) && 'disable' === apply_filters( 'option_elementor_element_cache_ttl', 86400 ), 'A language-blind Elementor HTML cache could leak a localized field across visitors' );
verify( ['Kurze Übersicht'] === get_post_meta( 41, 'summary', false ) && 'Another page' === get_post_meta( 42, 'caption', true ), 'WP returned the wrong value/list or post' );
$all = get_post_meta( 41 );
verify( $all['caption'] === [$fields['caption']['value']] && ['Kurze Übersicht'] === $all['summary'] && $all['_caption'] === $initial['_caption'] && $all['other_meta'] === $initial['other_meta'], 'Bulk metadata lost localized values, hidden field references or raw multi-value serialization' );
verify( $all === get_post_meta( 41, '', true ) && get_post_meta( 42 ) === ['caption'=>['Another page']], 'Bulk metadata mishandled single=true or affected another post' );
$with_prior = apply_filters( 'get_post_metadata', ['from_other_filter'=>['yes']], 41, '', false );
verify( $with_prior['from_other_filter'] === ['yes'] && ['Kurze Übersicht'] === $with_prior['summary'], 'Overrode another metadata filter when it provided the bulk map' );
$acf = ['name'=>'caption','key'=>'field_caption','type'=>'wysiwyg'];
verify( $fields['caption']['value'] === apply_filters( 'acf/pre_load_value', null, 41, $acf ), 'Lost unformatted ACF rich text' );
verify( null === apply_filters( 'acf/pre_load_value', null, 'term_41', $acf ) && null === apply_filters( 'acf/pre_load_value', null, 41, ['key'=>'new'] + $acf ), 'Wrong ACF object or key was shadowed' );
$url = get_post_meta( 41, 'link', true ); verify( $url === $fields['link']['value'], 'Opaque URL was decorated' );
$pre = 'translatepress' === $provider ? 'trp_pre_translating_html' : 'weglot_modify_original_content';
$post_hook = 'translatepress' === $provider ? 'trp_translated_html' : 'weglot_render_dom';
$mail = get_post_meta( 41, 'hero-Email', true );
verify( $mail === 'dienst@example.org' && get_post_meta( 41, 'hero-email', false ) === [], 'Email value contains markup or an unapproved key alias' );
$mail_html = apply_filters( $pre, '<main><a href="mailto:' . $mail . '" title="Source contact label">Mail us</a></main>' );
$mail_html = apply_filters( $post_hook, str_replace( 'mailto:dienst@example.org', 'mailto:wrong@example.org', $mail_html ) );
verify( str_contains( $mail_html, 'href="mailto:dienst@example.org"' ) && ! str_contains( $mail_html, 'data-nova-field-attrs' ), 'Provider modified an opaque mapped email link' );
$html = '<nav>Kurze Übersicht</nav><main><article>' . get_post_meta( 41, 'caption', true ) . '<p>Gut</p><p>Kurze Übersicht</p><a href="' . htmlspecialchars( $url ) . '" title="Original label">Source caption</a></article></main>';
$protected = apply_filters( $pre, $html );
verify( str_contains( $protected, '<span data-no-translation data-wg-notranslate>Sicher &amp; gut. </span>' ) && str_contains( $protected, '<p><span data-no-translation data-wg-notranslate>Kurze Übersicht</span></p>' ), 'Exact source-linked frontend copy was left open to a second translation' );
verify( str_contains( $protected, '<strong>Gut</strong>' ) && str_contains( $protected, '<p>Gut</p>' ) && str_contains( $protected, '<nav>Kurze Übersicht</nav>' ), 'An ambiguous label, other element or menu was modified' );
foreach ( [ 'data-elementor-type="wp-page" data-elementor-id="41"', 'data-elementor-type="wp-post"', 'data-elementor-type="single" data-elementor-id="123"', 'data-elementor-type="single-post"', 'data-elementor-type="single-page"', 'role="main"', 'id="post-41"' ] as $area ) {
    $canvas = '<p>Kurze Übersicht</p><div ' . $area . '><div><p title="x > y">Kurze Übersicht</p></div><div data-elementor-type="loop-item"><div><p>Kurze Übersicht</p></div></div><nav>Kurze Übersicht</nav><footer>Kurze Übersicht</footer><script>if (x < 5) "Kurze Übersicht";</script></div><div data-elementor-type="footer"><p>Kurze Übersicht</p></div><div data-elementor-type="wp-post" data-elementor-id="42">Kurze Übersicht</div>';
    $expect = str_replace( 'title="x > y">Kurze Übersicht', 'title="x > y"><span data-no-translation data-wg-notranslate>Kurze Übersicht</span>', $canvas );
    verify( apply_filters( $pre, $canvas ) === $expect, 'Lost the main field or modified other Canvas/template content: ' . $area );
}
$provider_output = preg_replace( '/href="[^"]*"/', 'href="https://wrong.test/"', str_replace( 'Original label', 'Localized label', $protected ) );
$final = apply_filters( $post_hook, $provider_output ); $tags = new WP_HTML_Tag_Processor( $final ); $tags->next_tag( 'A' );
verify( $url === $tags->get_attribute( 'href' ) && 'Localized label' === $tags->get_attribute( 'title' ) && ! str_contains( $final, 'data-nova-field-attrs' ), 'Provider rewrote the target URL, sibling label or escaping' );
$fields['summary']['value'] = 'Aktualisiert'; $updated = publish_locale( 'de_DE', 2, ['summary'=>$fields['summary']] );
request_locale( 'de_DE' ); verify( 'Aktualisiert' === get_post_meta( 41, 'summary', true ) && $url === get_post_meta( 41, 'link', true ), 'Updating one field lost an unchanged mapped field' );
request_locale( 'fr_FR' ); verify( 'Sommaire' === get_post_meta( 41, 'summary', true ), 'Post-local update corrupted another locale' );
$hooks = [];
verify( $initial === array_intersect_key( $data[41], $initial ), 'Native source/ACF reference or Elementor data was changed' );
$data[41]['summary'] = ['Changed outside NOVA'];
verify( is_wp_error( Writer::verify( $updated, [] ) ), 'Source mutation did not invalidate previous evidence' );
$changed = $config; $changed['local']['stored_layout']['bindings'][] = ['kind'=>'recipe','mode'=>'adapt'];
if ( 'translatepress' === $provider ) {
    $bad = Nova_WordPress_Translation_Fields::compile( ['site_id'=>'s','url_id'=>'u','destination_values'=>$fields], $changed, ['post_type'=>'page','post_id'=>41,'provider'=>$provider] );
    verify( is_wp_error( $bad ), 'Unsupported mixed Elementor destination was silently dropped' );
}
$catalog['acf']['caption']['key'] = 'field_new';
request_locale( 'de_DE' ); verify( 'Source caption' === get_post_meta( 41, 'caption', true ), 'Old localized ACF value shadowed a repurposed field' );
// SEO stays in the per-language journal, not in source post meta or generic field reads.
$seo_plugins = [ 'wordpress-seo/wp-seo.php' ]; $hooks = [];
$data[41]['_yoast_wpseo_title'] = [ 'Original search title' ];
$data[41]['_yoast_wpseo_metadesc'] = [ 'Original description' ];
$seo = [ '/@nova/seo/yoast/title'=>['provider'=>'seo','key'=>'yoast','type'=>'title','value'=>'Français & précis'],
    '/@nova/seo/yoast/description'=>['provider'=>'seo','key'=>'yoast','type'=>'description','value'=>'Une description "exacte".'] ];
publish_locale( 'fr_FR', 2, $seo );
$german = $seo; $german['/@nova/seo/yoast/title']['value'] = 'Deutscher Suchtitel';
publish_locale( 'de_DE', 3, $german );
request_locale( 'fr_FR' );
verify( 'Français & précis' === apply_filters( 'wpseo_title', 'Original search title' ), 'Localized SEO title was not rendered for its locale' );
verify( 'Une description "exacte".' === apply_filters( 'wpseo_metadesc', 'Original description' ), 'Localized SEO description was not rendered' );
verify( 'Original search title' === get_post_meta( 41, '_yoast_wpseo_title', true ) && '' === get_post_meta( 41, '/@nova/seo/yoast/title', true ), 'Localized SEO mutated or exposed synthetic source metadata' );
$seo_html = '<html><head><title>Français &amp; précis</title><meta name="description" content="Une description &quot;exacte&quot;."><meta property="og:title" content="Unmapped social title"></head><body>Original body</body></html>';
$marked = apply_filters( $pre, $seo_html );
verify( substr_count( $marked, 'data-nova-seo-field=' ) === 2, 'Localized SEO head was not protected from automatic retranslation' );
$restored = apply_filters( $post_hook, str_replace( [ 'Français &amp; précis', 'Une description &quot;exacte&quot;.' ], [ 'Wrong title', 'Wrong description' ], $marked ) );
verify( str_contains( html_entity_decode( $restored, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'Français & précis' ) && str_contains( $restored, 'Une description &quot;exacte&quot;.' ) && ! str_contains( $restored, 'data-nova-seo-field' ) && str_contains( $restored, '<body>Original body</body>' ), 'Provider retranslation corrupted mapped SEO or unrelated content: ' . $restored );
request_locale( 'de_DE' ); verify( 'Deutscher Suchtitel' === apply_filters( 'wpseo_title', 'Original search title' ), 'German SEO reused French metadata' );
request_locale( 'en_US' ); verify( 'Original search title' === apply_filters( 'wpseo_title', 'Original search title' ), 'Source-language SEO was overridden' );
$seo_plugins = []; request_locale( 'fr_FR' ); verify( 'Native' === apply_filters( 'wpseo_title', 'Native' ), 'Inactive SEO plugin retained a stale localized override' );
class Seo_Native_Typography_Fixture { public static function metadata_content( $text ) { return str_replace( '"quoted"', '“quoted”', $text ); } }
class_alias( Seo_Native_Typography_Fixture::class, 'The_SEO_Framework\\Data\\Filter\\Sanitize' );
$hooks = []; $seo_plugins = [ 'autodescription/autodescription.php' ];
Nova_WordPress_Localized_SEO::on_request( 41, [ '/@nova/seo/tsf/description'=>['key'=>'tsf','type'=>'description','value'=>'A "quoted" description'] ], 'translatepress' === $provider );
verify( 'A "quoted" description' === apply_filters( 'the_seo_framework_custom_field_description', 'Original' ), 'Localized hook bypassed native description formatting' );
$typography = '<html><head><meta name="description" content="A “quoted” description"></head><body>Unmapped</body></html>';
$marked = apply_filters( $pre, $typography );
verify( str_contains( $marked, 'data-nova-seo-field=' ), 'Native description typography was not protected' );
$restored = apply_filters( $post_hook, str_replace( 'A “quoted” description', 'Wrong', $marked ) );
verify( str_contains( $restored, 'A “quoted” description' ) && str_contains( $restored, '<body>Unmapped</body>' ), 'Restoration changed native typography or unrelated content' );
// Elementor product archives need their own scope while product cards keep provider translation.
$archive_fields = [ 'seo_text' => [ 'provider'=>'acf', 'key'=>'field_archive_body', 'type'=>'wysiwyg', 'value'=>'<h2>Archivinhalt</h2><p>Lokaler Haupttext.</p>' ] ];
$archive = new Nova_WordPress_Localized_Fields( 66, $archive_fields, 'term', 'product_cat' );
$archive_body = $archive->value( 'seo_text' );
$archive_html = '<div data-elementor-type="header"><p>Lokaler Haupttext.</p></div><div data-elementor-type="product-archive" data-elementor-id="278">' . $archive_body . '<div data-elementor-type="loop-item"><p>Lokaler Haupttext.</p></div></div><footer>Lokaler Haupttext.</footer>';
$archive_expected = str_replace( $archive_body, '<h2><span data-no-translation data-wg-notranslate>Archivinhalt</span></h2><p><span data-no-translation data-wg-notranslate>Lokaler Haupttext.</span></p>', $archive_html );
verify( $archive->protect( $archive_html ) === $archive_expected, 'Elementor term archive text was unprotected or product cards/header/footer were changed' );
$post_archive = new Nova_WordPress_Localized_Fields( 66, $archive_fields );
$post_archive->value( 'seo_text' );
verify( $post_archive->protect( $archive_html ) === $archive_html, 'A post field unexpectedly claimed a term archive' );

echo "$provider localized custom-field checks: $checks passed.\n";
