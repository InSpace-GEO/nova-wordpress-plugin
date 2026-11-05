<?php
/**
 * Plugin Name: NOVA WordPress Plugin
 * Plugin URI: https://github.com/InSpace-GEO/nova-wordpress-plugin
 * Description: Map NOVA content to native WordPress layouts and receive publishing deliveries.
 * Version: 1.1.5
 * Author: LUNA B.V.
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Update URI: https://github.com/InSpace-GEO/nova-wordpress-plugin
 * License: Proprietary
 * Text Domain: nova-bridge-suite
 */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/includes/updates.php';

// The two distributions share native CPTs, receipt journals and the ready URL.
// Choose one owner before loading either set of shared PHP symbols.
function nova_wordpress_legacy_active(): bool {
    $active = (array) get_option( 'active_plugins', [] );
    if ( is_multisite() ) {
        $active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
    }
    return in_array( 'nova-bridge-suite.php', array_map( 'basename', $active ), true );
}

function nova_wordpress_conflict_message(): string {
    return __( 'NOVA WordPress Plugin and NOVA Bridge Suite use the same layouts and delivery queue. Deactivate the active plugin before switching.', 'nova-bridge-suite' );
}

function nova_wordpress_boot(): void {
    if ( nova_wordpress_legacy_active() || ( defined( 'NOVA_BRIDGE_SUITE_PLUGIN_FILE' ) && NOVA_BRIDGE_SUITE_PLUGIN_FILE !== __FILE__ ) ) {
        add_action( 'admin_notices', static function () {
            echo '<div class="notice notice-warning"><p>' . esc_html( nova_wordpress_conflict_message() ) . '</p></div>';
        } );
        return;
    }
    if ( ! defined( 'NOVA_BRIDGE_SUITE_PLUGIN_FILE' ) ) {
        define( 'NOVA_BRIDGE_SUITE_PLUGIN_FILE', __FILE__ );
        define( 'NOVA_BRIDGE_SUITE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NOVA_BRIDGE_SUITE_VERSION', '1.1.5' );
        define( 'NOVA_BRIDGE_SUITE_OPTION', 'nova_wordpress_plugin_modules' );
        define( 'NOVA_BRIDGE_SUITE_VERSION_OPTION', 'nova_wordpress_plugin_version' );
    }
    require_once __DIR__ . '/includes/runtime.php';
}

add_action( 'plugins_loaded', 'nova_wordpress_boot', 1 );
// The activation API, including WP-CLI, runs this before including the other plugin.
add_filter( 'validate_plugin_requirements', static function ( $result, $plugin ) {
    if ( true === $result && 'nova-bridge-suite.php' === basename( $plugin ) && ! nova_wordpress_legacy_active() ) {
        return new WP_Error( 'nova_plugin_conflict', nova_wordpress_conflict_message() );
    }
    return $result;
}, 10, 2 );
register_activation_hook( __FILE__, static function () {
    if ( nova_wordpress_legacy_active() || ( defined( 'NOVA_BRIDGE_SUITE_PLUGIN_FILE' ) && NOVA_BRIDGE_SUITE_PLUGIN_FILE !== __FILE__ ) ) {
        wp_die( esc_html( nova_wordpress_conflict_message() ) );
    }
    nova_wordpress_boot();
    nova_wordpress_activate_modules();
} );
register_deactivation_hook( __FILE__, static function () {
    if ( function_exists( 'nova_wordpress_deactivate_modules' ) ) {
        nova_wordpress_deactivate_modules();
    }
} );

add_filter( 'map_meta_cap', static function ( $caps, $cap, $user_id, $args ) {
    if ( 'activate_plugin' === $cap && isset( $args[0] ) && 'nova-bridge-suite.php' === basename( (string) $args[0] ) ) {
        return [ 'do_not_allow' ];
    }
    return $caps;
}, 10, 4 );
