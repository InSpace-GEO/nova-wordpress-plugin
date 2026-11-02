<?php
defined( 'ABSPATH' ) || exit;

function nova_bridge_suite_module_definitions(): array {
    return [
        'custom_post_types' => [ 'label' => 'NOVA Blog', 'path' => 'modules/cpt/quarantined-cpt.php' ],
        'service_page_cpt' => [ 'label' => 'NOVA Service Pages', 'path' => 'modules/service-page-cpt/service-page-cpt.php' ],
        'woocommerce_rich_text' => [ 'label' => 'WooCommerce category content below products', 'path' => 'modules/woocommerce/wc-content-below-products.php' ],
    ];
}
function nova_bridge_suite_default_settings(): array {
    return array_fill_keys( array_keys( nova_bridge_suite_module_definitions() ), 0 );
}
function nova_wordpress_module_choices( $value ): array {
    $choices = nova_bridge_suite_default_settings();
    foreach ( $choices as $key => $unused ) {
        $choices[ $key ] = ! empty( $value[ $key ] ) ? 1 : 0;
    }
    return $choices;
}
function nova_bridge_suite_get_settings(): array {
    $value = get_option( NOVA_BRIDGE_SUITE_OPTION, null );
    if ( null === $value ) {
        $value = get_option( 'nova_bridge_settings', [] );
    }
    return nova_wordpress_module_choices( is_array( $value ) ? $value : [] );
}
function nova_bridge_suite_get_active_plugins(): array {
    $plugins = (array) get_option( 'active_plugins', [] );

    if ( is_multisite() ) {
        $network_active = (array) get_site_option( 'active_sitewide_plugins', [] );
        $plugins        = array_merge( $plugins, array_keys( $network_active ) );
    }

    $normalized = [];
    foreach ( $plugins as $plugin ) {
        $plugin = str_replace( '\\', '/', (string) $plugin );
        if ( '' !== $plugin ) {
            $normalized[] = $plugin;
        }
    }

    return array_values( array_unique( $normalized ) );
}

function nova_bridge_suite_find_active_plugin_by_filename( string $filename ): string {
    $filename = ltrim( $filename, '/' );

    if ( '' === $filename ) {
        return '';
    }

    foreach ( nova_bridge_suite_get_active_plugins() as $plugin ) {
        if ( basename( $plugin ) === $filename ) {
            return $plugin;
        }
    }

    return '';
}

function nova_bridge_suite_get_module_conflicts(): array {
    static $conflicts = null;

    if ( null !== $conflicts ) {
        return $conflicts;
    }

    $conflicts = [];

    foreach ( nova_bridge_suite_module_definitions() as $key => $module ) {
        $filenames = $module['standalone_filenames'] ?? [];

        if ( empty( $filenames ) && ! empty( $module['path'] ) ) {
            $filenames = [ basename( $module['path'] ) ];
        }

        foreach ( $filenames as $filename ) {
            $active_plugin = nova_bridge_suite_find_active_plugin_by_filename( $filename );
            if ( '' !== $active_plugin ) {
                $conflicts[ $key ] = $active_plugin;
                break;
            }
        }
    }

    return $conflicts;
}

function nova_bridge_suite_get_module_conflict( string $setting_key ): string {
    $conflicts = nova_bridge_suite_get_module_conflicts();

    return $conflicts[ $setting_key ] ?? '';
}

function nova_bridge_suite_is_module_blocked( string $setting_key ): bool {
    return '' !== nova_bridge_suite_get_module_conflict( $setting_key );
}

function nova_bridge_suite_register_settings_page(): void {
    add_options_page(
        'NOVA Settings',
        'NOVA Settings',
        'manage_options',
        'nova-settings',
        'nova_bridge_suite_render_settings_page'
    );
}

add_action( 'admin_menu', 'nova_bridge_suite_register_settings_page' );

add_action( 'admin_init', static function () {
    register_setting( 'nova_wordpress_modules', NOVA_BRIDGE_SUITE_OPTION, [ 'sanitize_callback' => 'nova_wordpress_module_choices' ] );
} );

function nova_bridge_suite_get_settings_tabs(): array {
    $base_tabs = [
        'modules' => [
            'label'           => __( 'Modules', 'nova-bridge-suite' ),
            'render_callback' => null,
            'legacy_slugs'    => [],
        ],
    ];

    $filtered = apply_filters( 'nova_bridge_suite_settings_tabs', $base_tabs );
    if ( ! is_array( $filtered ) ) {
        return $base_tabs;
    }

    $tabs = [];
    foreach ( $filtered as $slug => $tab ) {
        $slug = sanitize_key( (string) $slug );
        if ( '' === $slug || ! is_array( $tab ) ) {
            continue;
        }

        $label = isset( $tab['label'] ) && is_scalar( $tab['label'] )
            ? sanitize_text_field( (string) $tab['label'] )
            : '';
        if ( '' === $label ) {
            continue;
        }

        $callback = $tab['render_callback'] ?? null;
        if ( 'modules' === $slug ) {
            if ( null !== $callback && ! is_callable( $callback ) ) {
                continue;
            }
        } elseif ( ! is_callable( $callback ) ) {
            continue;
        }

        $legacy_slugs = [];
        foreach ( (array) ( $tab['legacy_slugs'] ?? [] ) as $legacy_slug ) {
            $legacy_slug = sanitize_key( (string) $legacy_slug );
            if ( '' !== $legacy_slug && $legacy_slug !== $slug ) {
                $legacy_slugs[] = $legacy_slug;
            }
        }

        $tabs[ $slug ] = [
            'label'           => $label,
            'render_callback' => $callback,
            'legacy_slugs'    => array_values( array_unique( $legacy_slugs ) ),
            'nav_parent'      => is_scalar( $tab['nav_parent'] ?? '' ) ? sanitize_key( (string) ( $tab['nav_parent'] ?? '' ) ) : '',
        ];
    }

    if ( ! isset( $tabs['modules'] ) ) {
        $tabs = [ 'modules' => $base_tabs['modules'] ] + $tabs;
    }

    return $tabs;
}

function nova_bridge_suite_render_settings_page(): void {
    $version     = defined( 'NOVA_BRIDGE_SUITE_VERSION' ) ? (string) NOVA_BRIDGE_SUITE_VERSION : '';
    $tabs        = nova_bridge_suite_get_settings_tabs();
    $current_tab = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] )
        ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) )
        : ( isset( $tabs['layouts'] ) ? 'layouts' : 'modules' );

    if ( ! isset( $tabs[ $current_tab ] ) ) {
        foreach ( $tabs as $tab_slug => $tab ) {
            if ( in_array( $current_tab, $tab['legacy_slugs'], true ) ) {
                $current_tab = $tab_slug;
                break;
            }
        }
    }

    if ( ! isset( $tabs[ $current_tab ] ) ) {
        $current_tab = isset( $tabs['layouts'] ) ? 'layouts' : 'modules';
    }

    $navigation_tab = ! empty( $tabs[ $current_tab ]['nav_parent'] ) && isset( $tabs[ $tabs[ $current_tab ]['nav_parent'] ] )
        ? $tabs[ $current_tab ]['nav_parent'] : $current_tab;

    $tab_args = [ 'page' => 'nova-settings' ];
    if ( isset( $_GET['lang'] ) && is_scalar( $_GET['lang'] ) ) {
        $tab_args['lang'] = sanitize_text_field( (string) wp_unslash( $_GET['lang'] ) );
    }

    ?>
    <div class="wrap">
        <h1 class="screen-reader-text"><?php echo esc_html( class_exists( 'Nova_Bridge_Suite_Layout_Setup_Admin' ) ? Nova_Bridge_Suite_Layout_Setup_Admin::screen_title( $current_tab ) : __( 'NOVA Settings', 'nova-bridge-suite' ) ); ?></h1>
        <div class="nova-publishing-app">
        <?php if ( class_exists( 'Nova_Bridge_Suite_Layout_Setup_Admin' ) ) : ?>
            <?php Nova_Bridge_Suite_Layout_Setup_Admin::header( $current_tab ); ?>
        <?php else : ?>
        <h1>
            <?php echo esc_html__( 'NOVA Settings', 'nova-bridge-suite' ); ?>
            <?php if ( '' !== $version ) : ?>
                <span style="margin-left:8px;font-size:13px;font-weight:500;color:#646970;"><?php echo esc_html( 'v' . $version ); ?></span>
            <?php endif; ?>
        </h1>
        <?php endif; ?>
        <nav class="nav-tab-wrapper nova-publishing-nav" aria-label="<?php echo esc_attr__( 'NOVA Settings sections', 'nova-bridge-suite' ); ?>">
            <?php foreach ( $tabs as $tab_slug => $tab ) : ?>
                <?php if ( ! empty( $tab['nav_parent'] ) && isset( $tabs[ $tab['nav_parent'] ] ) ) { continue; } ?>
                <?php $tab_url = add_query_arg( array_merge( $tab_args, [ 'tab' => $tab_slug ] ), admin_url( 'options-general.php' ) ); ?>
                <a href="<?php echo esc_url( $tab_url ); ?>" class="nav-tab <?php echo $tab_slug === $navigation_tab ? 'nav-tab-active' : ''; ?>" <?php echo $tab_slug === $navigation_tab ? 'aria-current="page"' : ''; ?>>
                    <?php echo esc_html( $tab['label'] ); ?>
                </a>
            <?php endforeach; ?>
        </nav>
		<?php if ( 'modules' !== $current_tab ) : ?>
            <?php call_user_func( $tabs[ $current_tab ]['render_callback'] ); ?>
            </div></div>
            <?php
            return;
        endif;
        ?>
        <p>Enable the content types and fields this site uses.</p>
        <form method="post" action="options.php">
            <?php settings_fields( 'nova_wordpress_modules' ); ?>
            <input type="hidden" name="<?php echo esc_attr( NOVA_BRIDGE_SUITE_OPTION ); ?>[_submitted]" value="1">
            <?php foreach ( nova_bridge_suite_module_definitions() as $key => $module ) : ?>
                <p><label><input type="checkbox" name="<?php echo esc_attr( NOVA_BRIDGE_SUITE_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="1"
                    <?php checked( nova_bridge_suite_get_settings()[ $key ] ); ?> <?php disabled( nova_bridge_suite_is_module_blocked( $key ) ); ?>>
                    <?php echo esc_html( $module['label'] ); ?></label>
                    <?php if ( nova_bridge_suite_is_module_blocked( $key ) ) : ?><span class="description">Provided by an active standalone plugin.</span><?php endif; ?>
                </p>
            <?php endforeach; ?>
            <?php submit_button(); ?>
        </form></div></div>
    <?php
}
