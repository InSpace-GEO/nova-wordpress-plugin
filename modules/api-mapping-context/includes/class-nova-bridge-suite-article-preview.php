<?php
/** Render article samples with the active site's frontend styles, without saving a post. */
defined( 'ABSPATH' ) || exit;

final class Nova_Bridge_Suite_Article_Preview {
    public static function render( string $document, string $post_type ): string {
        $names = [ 'post', 'wp_query', 'wp_the_query', 'wp_styles', 'wp_scripts' ];
        $previous = [];
        foreach ( $names as $name ) { $previous[$name] = [ array_key_exists( $name, $GLOBALS ), $GLOBALS[$name] ?? null ]; }
        $level = ob_get_level();
        $hide_admin_bar = static function () { return false; };
        add_filter( 'show_admin_bar', $hide_admin_bar );
        try {
            // Theme callbacks should see the destination's page context rather than the REST endpoint.
            $post = new WP_Post( (object) [ 'ID' => 0, 'post_type' => $post_type, 'post_status' => 'draft', 'post_title' => '', 'post_content' => $document ] );
            $query = new WP_Query();
            $query->posts = [ $post ]; $query->post = $post; $query->post_count = 1;
            $query->queried_object = $post; $query->queried_object_id = 0;
            $query->is_singular = true; $query->is_page = 'page' === $post_type; $query->is_single = ! $query->is_page;
            $query->query_vars = [ 'post_type' => $post_type ];
            $GLOBALS['post'] = $post; $GLOBALS['wp_query'] = $query; $GLOBALS['wp_the_query'] = $query;
            // Enqueues are isolated from the surrounding admin/REST request, including inline CSS.
            $GLOBALS['wp_styles'] = clone wp_styles();
            foreach ( $GLOBALS['wp_styles']->registered as $handle => $dependency ) { $GLOBALS['wp_styles']->registered[$handle] = clone $dependency; }
            $GLOBALS['wp_styles']->done = [];
            $GLOBALS['wp_scripts'] = clone wp_scripts();
            foreach ( $GLOBALS['wp_scripts']->registered as $handle => $dependency ) { $GLOBALS['wp_scripts']->registered[$handle] = clone $dependency; }
            ob_start();
            do_action( 'wp_enqueue_scripts' );
            $content = do_blocks( $document );
            // On-demand block styles are queued by rendering the actual sample before printing CSS.
            wp_enqueue_style( 'wp-block-library' );
            if ( ! wp_style_is( 'global-styles', 'enqueued' ) ) {
                wp_register_style( 'nova-article-preview-global', false );
                wp_add_inline_style( 'nova-article-preview-global', wp_get_global_stylesheet() );
                wp_enqueue_style( 'nova-article-preview-global' );
            }
            wp_enqueue_stored_styles();
            wp_print_styles();
            wp_print_font_faces();
            wp_custom_css_cb();
            $styles = ob_get_clean();
            $site = wp_parse_url( home_url( '/' ) );
            if ( ( $site['scheme'] ?? '' ) === 'https' ) {
                // CLI and proxied requests can enqueue same-site assets with an HTTP scheme.
                $styles = str_replace( 'http://' . $site['host'] . '/', 'https://' . $site['host'] . '/', $styles );
            }
            $classes = implode( ' ', get_body_class( 'nova-article-preview' ) );
            // Linked styles and font URLs come from the site's own theme enqueues.
            $policy = "default-src 'none'; style-src http: https: 'unsafe-inline'; font-src http: https: data:; img-src http: https: data:; base-uri http: https:; form-action 'none'";
            return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><base href="' . esc_url( home_url( '/' ) ) . '"><meta http-equiv="Content-Security-Policy" content="' . esc_attr( $policy ) . '">' . $styles . '</head>'
                . '<body class="' . esc_attr( $classes ) . '"><div class="wp-site-blocks"><main><div class="entry-content wp-block-post-content has-global-padding is-layout-constrained wp-block-post-content-is-layout-constrained">' . $content . '</div></main></div></body></html>';
        } finally {
            remove_filter( 'show_admin_bar', $hide_admin_bar );
            while ( ob_get_level() > $level ) { ob_end_clean(); }
            foreach ( $previous as $name => $entry ) { if ( $entry[0] ) { $GLOBALS[$name] = $entry[1]; } else { unset( $GLOBALS[$name] ); } }
        }
    }
}
