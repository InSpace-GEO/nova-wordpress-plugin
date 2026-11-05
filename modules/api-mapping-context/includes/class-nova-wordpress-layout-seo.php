<?php
/** Explicit SEO destinations; example metadata is never captured into a layout. */
defined( 'ABSPATH' ) || exit;

final class Nova_WordPress_Layout_SEO {
    private const PROVIDERS = [
        'yoast' => [ 'label' => 'Yoast SEO', 'plugins' => [ 'wordpress-seo/wp-seo.php', 'wordpress-seo-premium/wp-seo-premium.php' ], 'title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc' ],
        'rank_math' => [ 'label' => 'Rank Math', 'plugins' => [ 'seo-by-rank-math/rank-math.php' ], 'title' => 'rank_math_title', 'description' => 'rank_math_description' ],
        'seopress' => [ 'label' => 'SEOPress', 'plugins' => [ 'wp-seopress/seopress.php' ], 'title' => '_seopress_titles_title', 'description' => '_seopress_titles_desc' ],
        'tsf' => [ 'label' => 'The SEO Framework', 'plugins' => [ 'autodescription/autodescription.php' ], 'title' => '_genesis_title', 'description' => '_genesis_description' ],
        'smartcrawl' => [ 'label' => 'SmartCrawl', 'plugins' => [ 'smartcrawl-seo/wpmu-dev-seo.php', 'wpmu-dev-seo/wpmu-dev-seo.php' ], 'title' => '_wds_title', 'description' => '_wds_metadesc' ],
        'slim_seo' => [ 'label' => 'Slim SEO', 'plugins' => [ 'slim-seo/slim-seo.php' ], 'storage' => 'slim_seo' ],
        'aioseo' => [ 'label' => 'All in One SEO', 'plugins' => [ 'all-in-one-seo-pack/all_in_one_seo_pack.php', 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' ], 'storage' => 'aioseo' ],
        'squirrly' => [ 'label' => 'Squirrly SEO', 'plugins' => [ 'squirrly-seo/squirrly.php' ], 'storage' => 'squirrly' ],
    ];

    public static function provider( string $id ): ?array { return self::PROVIDERS[$id] ?? null; }
    public static function path( string $provider, string $field ): string { return '/@nova/seo/' . $provider . '/' . $field; }

    public static function active(): array {
        $plugins = function_exists( 'get_option' ) ? (array) get_option( 'active_plugins', [] ) : [];
        if ( function_exists( 'is_multisite' ) && is_multisite() ) { $plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) ); }
        return array_filter( self::PROVIDERS, static function ( $provider ) use ( $plugins ) { return (bool) array_intersect( $provider['plugins'], $plugins ); } );
    }

    public static function catalog(): array {
        $result = [];
        foreach ( self::active() as $provider => $definition ) {
            foreach ( [ 'title' => 'SEO title', 'description' => 'Meta description' ] as $field => $label ) {
                $result[self::path( $provider, $field )] = [ 'provider' => $provider, 'field' => $field, 'label' => $definition['label'] . ' · ' . $label, 'required' => false, 'instructions' => '' ];
            }
        }
        return $result;
    }

    /** Saved provider identities remain stable even when the plugin is later deactivated. */
    public static function selections( array $recipe ): array {
        $fields = $recipe['seo_fields'] ?? [];
        if ( ! is_array( $fields ) || count( $fields ) > 16 ) { throw new InvalidArgumentException( 'Choose supported SEO title and description fields.' ); }
        foreach ( $fields as $path => $field ) {
            if ( ! is_array( $field ) || ! is_string( $field['provider'] ?? null ) || ! self::provider( $field['provider'] ) || ! in_array( $field['field'] ?? '', [ 'title', 'description' ], true )
                || $path !== self::path( $field['provider'], $field['field'] ) || array_diff( array_keys( $field ), [ 'provider', 'field', 'label', 'required', 'instructions' ] )
                || ( isset( $field['required'] ) && ! is_bool( $field['required'] ) ) ) { throw new InvalidArgumentException( 'The saved SEO destination is unsupported.' ); }
            foreach ( [ 'label' => 200, 'instructions' => 8000 ] as $key => $limit ) {
                $text = $field[$key] ?? '';
                if ( ! is_string( $text ) || strlen( $text ) > $limit || false !== strpos( $text, "\0" ) || 1 !== preg_match( '//u', $text ) ) { throw new InvalidArgumentException( 'SEO labels and instructions must be bounded text.' ); }
            }
        }
        return $fields;
    }

    public static function verify( array $recipe ) {
        try {
            $active = self::active();
            foreach ( self::selections( $recipe ) as $field ) {
                if ( ! isset( $active[$field['provider']] ) ) { throw new InvalidArgumentException( 'The selected SEO plugin is inactive. Restore it or deselect its fields before saving.' ); }
            }
            return true;
        } catch ( InvalidArgumentException $error ) { return new WP_Error( 'nova_layout_seo', $error->getMessage(), [ 'status' => 409 ] ); }
    }
}
