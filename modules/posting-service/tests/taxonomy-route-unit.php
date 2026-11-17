<?php
/** Taxonomy placement follows the registered route and provider language. */
define('ABSPATH', __DIR__);
class WP_Error { public $code; public function __construct($code, $message = '') { $this->code = $code; } }
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_option($key, $default = false) { return $key === 'home' ? 'https://example.test' : $default; }
function home_url($path = '') { return 'https://example.test' . $path; }
function get_taxonomy($name) { return (object) ['rewrite' => ['hierarchical' => $name === 'product_cat']]; }
function weglot_get_service($name) {}
class Nova_WordPress_Weglot {
    public static function catalog() { return ['source' => 'en', 'languages' => ['en', 'sv', 'de']]; }
    public static function url($url, $code) {
        $url = preg_replace('#https://example.test/(sv|de)/#', 'https://example.test/', $url);
        return $code === 'en' ? $url : str_replace('https://example.test/', 'https://example.test/' . $code . '/', $url);
    }
}
class Nova_Bridge_Suite_Translation_Writer {
    public static function match_language($language, $languages) { return substr($language, 0, 2); }
    public static function delivery_url($url, $language) {
        if ($url[0] === '/') { $url = 'https://example.test' . $url; }
        return str_starts_with($url, 'https://example.test/') ? $url : new WP_Error('foreign');
    }
}
class Nova_Bridge_Suite_Mapped_Content_Target {
    public static function normalize_url($url) {
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || str_contains($parts['path'] ?? '', '..')) { return new WP_Error('invalid'); }
        return ['origin' => 'https://' . $parts['host'], 'path' => rtrim($parts['path'] ?? '/', '/') ?: '/', 'identity' => rtrim($url, '/')];
    }
}
$wp_rewrite = new class {
    public $tag = '/product-tag/%product_tag%';
    public function get_extra_permastruct($taxonomy) { return $taxonomy === 'product_tag' ? $this->tag : '/product-category/%product_cat%'; }
};
require dirname(__DIR__) . '/includes/class-nova-wordpress-taxonomy-route.php';
$checks = 0;
function check($input, $taxonomy, $language, $expected) {
    $actual = Nova_WordPress_Taxonomy_Route::url($input, $taxonomy, $language);
    if ($actual !== $expected) { throw new RuntimeException('Unexpected taxonomy URL: ' . (is_wp_error($actual) ? $actual->code : $actual)); }
    ++$GLOBALS['checks'];
}
check('/sv/kop-example/', 'product_tag', 'sv-SE', 'https://example.test/sv/product-tag/kop-example');
check('https://example.test/sv/product-tag/kop-example/', 'product_tag', 'sv-SE', 'https://example.test/sv/product-tag/kop-example');
check('/de/beispiel/', 'product_tag', 'de-DE', 'https://example.test/de/product-tag/beispiel');
check('https://example.test/example', 'product_tag', 'en-GB', 'https://example.test/product-tag/example');
check('/de/parent/child/', 'product_cat', 'de-DE', 'https://example.test/de/product-category/parent/child');
check('/de/product-category/example/', 'product_tag', 'de-DE', 'https://example.test/de/product-tag/example');
check('/de/produktkategorie/example/', 'product_tag', 'de-DE', 'https://example.test/de/product-tag/example');
$wp_rewrite->tag = '/research-tags/%product_tag%';
check('/sv/kop-example/', 'product_tag', 'sv-SE', 'https://example.test/sv/research-tags/kop-example');
foreach (['https://foreign.test/example', '/sv/../example', '/sv/example?edit=1'] as $url) {
    if (!is_wp_error(Nova_WordPress_Taxonomy_Route::url($url, 'product_tag', 'sv-SE'))) { throw new RuntimeException('Invalid route was accepted'); }
    ++$checks;
}
echo "$checks taxonomy routing checks passed\n";
