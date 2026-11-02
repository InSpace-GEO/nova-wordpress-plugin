<?php
/** Update the installed plugin from its public, versioned GitHub release asset. */
defined( 'ABSPATH' ) || exit;

function nova_wordpress_release_update( $update, $plugin_data, $plugin_file ) {
    $repo = 'https://github.com/InSpace-GEO/nova-wordpress-plugin';
    if ( plugin_basename( dirname( __DIR__ ) . '/nova-wordpress-plugin.php' ) !== $plugin_file
        || $repo !== ( $plugin_data['UpdateURI'] ?? '' ) ) {
        return $update;
    }

    $release = wp_safe_remote_get( $repo . '/releases/latest/download/wordpress-update.json', [
        'user-agent'          => 'NOVA-WordPress-Plugin',
        'timeout'             => 5,
        'limit_response_size' => 4096,
    ] );
    if ( is_wp_error( $release ) || 200 !== wp_remote_retrieve_response_code( $release ) ) {
        return $update;
    }
    $manifest = json_decode( wp_remote_retrieve_body( $release ), true );
    foreach ( [ 'version', 'requires', 'requires_php' ] as $key ) {
        if ( ! is_string( $manifest[ $key ] ?? null ) || ! preg_match( '/^\d+\.\d+(?:\.\d+)?$/D', $manifest[ $key ] ) ) {
            return $update;
        }
    }
    $version = $manifest['version'];
    return [
        'slug'         => 'nova-wordpress-plugin',
        'version'      => $version,
        'requires'     => $manifest['requires'],
        'requires_php' => $manifest['requires_php'],
        'url'          => $repo . '/releases/tag/v' . $version,
        'package'      => $repo . '/releases/download/v' . $version . '/nova-wordpress-plugin-' . $version . '.zip',
    ];
}

add_filter( 'update_plugins_github.com', 'nova_wordpress_release_update', 10, 3 );
