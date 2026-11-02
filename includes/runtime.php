<?php
/** Bootstrap mapping on every request so internal REST and worker callbacks see the same providers. */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/route-conflicts.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/class-nova-bridge-suite-wpml-support.php';
Nova_Bridge_Suite_WPML_Support::bootstrap();

// Weglot serves localized views of the original. Its container is available after plugins_loaded.
add_action( 'plugins_loaded', static function () {
    $trp = class_exists( 'TRP_Translate_Press' );
    $weglot_active = function_exists( 'weglot_get_service' );
    if ( $trp || $weglot_active ) {
        require_once NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'modules/posting-service/includes/class-nova-bridge-suite-translation-writer.php';
        require_once NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'modules/posting-service/includes/class-nova-wordpress-localized-fields.php';
        add_action( 'wp', [ 'Nova_WordPress_Localized_Fields', 'on_request' ] );
    }
    if ( $trp ) {
        require_once NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'modules/posting-service/includes/class-nova-wordpress-translatepress-renderer.php';
        add_action( 'wp', [ 'Nova_WordPress_TranslatePress_Renderer', 'on_request' ] );
    }
    if ( ! $weglot_active ) { return; }
    require_once NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'modules/posting-service/includes/class-nova-wordpress-weglot-renderer.php';
    $weglot = new Nova_WordPress_Weglot();
    ( new Nova_WordPress_Weglot_Renderer( $weglot, $weglot ) )->hooks();
}, 20 );

require_once NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'modules/api-mapping-context/includes/class-nova-bridge-suite-layout-setup-admin.php';
require_once NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'modules/api-mapping-context/api-mapping-context.php';
require_once __DIR__ . '/mapping-fields.php';
Nova_Bridge_Suite_Layout_Setup_Admin::bootstrap();

foreach ( nova_bridge_suite_module_definitions() as $key => $module ) {
    if ( nova_bridge_suite_get_settings()[ $key ] && ! nova_bridge_suite_is_module_blocked( $key ) ) {
        require_once NOVA_BRIDGE_SUITE_PLUGIN_DIR . $module['path'];
    }
}
add_action( 'update_option_' . NOVA_BRIDGE_SUITE_OPTION, 'nova_bridge_suite_settings_updated', 10, 3 );
// The Blog module checks the previous version at init 98 when upgrading an active plugin.
add_action( 'init', static function () {
    if ( NOVA_BRIDGE_SUITE_VERSION !== get_option( NOVA_BRIDGE_SUITE_VERSION_OPTION ) ) {
        update_option( NOVA_BRIDGE_SUITE_VERSION_OPTION, NOVA_BRIDGE_SUITE_VERSION );
    }
}, 99 );
add_filter( 'woocommerce_taxonomy_args_product_cat', static function ( $args ) { $args['show_in_rest'] = true; return $args; } );

function nova_bridge_suite_get_managed_blog_post_types(): array {
    $raw = get_option( 'quarantined_cpt_bodyclean_cpts', null );

    if ( null === $raw ) {
        return [ 'blog' ];
    }

    if ( ! is_array( $raw ) ) {
        return [];
    }

    $post_types = [];

    foreach ( $raw as $definition ) {
        if ( ! is_array( $definition ) ) {
            continue;
        }

        $post_type = isset( $definition['type'] ) ? sanitize_key( (string) $definition['type'] ) : '';

        if ( '' === $post_type && isset( $definition['slug'] ) ) {
            $post_type = sanitize_key( sanitize_title( (string) $definition['slug'] ) );
        }

        if ( '' !== $post_type ) {
            $post_types[] = substr( $post_type, 0, 20 );
        }
    }

    return array_values( array_unique( array_filter( $post_types ) ) );
}

function nova_bridge_suite_get_managed_blog_rest_bases(): array {
    $raw = get_option( 'quarantined_cpt_bodyclean_cpts', null );

    if ( null === $raw ) {
        return [ 'blog' ];
    }

    if ( ! is_array( $raw ) ) {
        return [];
    }

    $rest_bases = [];

    foreach ( $raw as $definition ) {
        if ( ! is_array( $definition ) ) {
            continue;
        }

        $rest_base = isset( $definition['slug'] )
            ? sanitize_title_with_dashes( (string) $definition['slug'] )
            : '';

        if ( '' === $rest_base && isset( $definition['type'] ) ) {
            $rest_base = sanitize_title_with_dashes( (string) $definition['type'] );
        }

        if ( '' !== $rest_base ) {
            $rest_bases[] = $rest_base;
        }
    }

    return array_values( array_unique( array_filter( $rest_bases ) ) );
}

function nova_bridge_suite_get_service_page_rest_base(): string {
    $rest_base = sanitize_title_with_dashes( (string) get_option( 'service_cpt_slug', 'services' ) );

    return '' === $rest_base ? 'services' : $rest_base;
}

function nova_wordpress_activate_modules(): void {
    add_option( NOVA_BRIDGE_SUITE_OPTION, nova_bridge_suite_get_settings() );
    $settings = nova_bridge_suite_get_settings();

    nova_bridge_suite_handle_cpt_toggle( false, ! empty( $settings['custom_post_types'] ) );
    nova_bridge_suite_handle_service_cpt_toggle( false, ! empty( $settings['service_page_cpt'] ) );
    update_option( NOVA_BRIDGE_SUITE_VERSION_OPTION, NOVA_BRIDGE_SUITE_VERSION );
}

function nova_wordpress_deactivate_modules(): void {
    $settings = nova_bridge_suite_get_settings();
    if ( ! empty( $settings['custom_post_types'] ) ) {
        nova_bridge_suite_handle_cpt_toggle( true, false );
    }

    if ( ! empty( $settings['service_page_cpt'] ) ) {
        nova_bridge_suite_handle_service_cpt_toggle( true, false );
    }
}

function nova_bridge_suite_settings_updated( $old_value, $new_value, string $option ): void {
    $defaults      = nova_bridge_suite_default_settings();
    $old_settings  = wp_parse_args( is_array( $old_value ) ? $old_value : [], $defaults );
    $new_settings  = wp_parse_args( is_array( $new_value ) ? $new_value : [], $defaults );
    $old_enabled   = ! empty( $old_settings['custom_post_types'] );
    $new_enabled   = ! empty( $new_settings['custom_post_types'] );
    $old_service   = ! empty( $old_settings['service_page_cpt'] );
    $new_service   = ! empty( $new_settings['service_page_cpt'] );

    if ( $old_enabled !== $new_enabled ) {
        nova_bridge_suite_handle_cpt_toggle( $old_enabled, $new_enabled );
    }

    if ( $old_service !== $new_service ) {
        nova_bridge_suite_handle_service_cpt_toggle( $old_service, $new_service );
    }
}

function nova_bridge_suite_handle_cpt_toggle( bool $was_enabled, bool $is_enabled ): void {
    if ( nova_bridge_suite_is_module_blocked( 'custom_post_types' ) ) {
        return;
    }

    $cpt_file = NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'modules/cpt/quarantined-cpt.php';
    if ( ! file_exists( $cpt_file ) ) {
        return;
    }

    require_once $cpt_file;

    if ( ! class_exists( '\\SEORAI\\BodycleanCPT\\Plugin' ) ) {
        return;
    }

    if ( ! $was_enabled && $is_enabled ) {
        \SEORAI\BodycleanCPT\Plugin::activate();
        return;
    }

    if ( $was_enabled && ! $is_enabled ) {
        \SEORAI\BodycleanCPT\Plugin::deactivate();
    }
}

function nova_bridge_suite_handle_service_cpt_toggle( bool $was_enabled, bool $is_enabled ): void {
    if ( nova_bridge_suite_is_module_blocked( 'service_page_cpt' ) ) {
        return;
    }

    $cpt_file = NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'modules/service-page-cpt/service-page-cpt.php';
    if ( ! file_exists( $cpt_file ) ) {
        return;
    }

    require_once $cpt_file;

    if ( ! class_exists( '\\SEORAI\\ServicePageCPT\\Plugin' ) ) {
        return;
    }

    if ( ! $was_enabled && $is_enabled ) {
        \SEORAI\ServicePageCPT\Plugin::activate();
        return;
    }

    if ( $was_enabled && ! $is_enabled ) {
        \SEORAI\ServicePageCPT\Plugin::deactivate();
    }
}
