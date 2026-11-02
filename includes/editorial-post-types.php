<?php
/** Post types which hold public editorial content on this installation. */
defined( 'ABSPATH' ) || exit;

function nova_bridge_suite_is_editorial_post_type( $type ): bool {
    $name = $type->name ?? '';
    return preg_match( '/^[a-z0-9_-]{1,32}$/D', $name ) && ! empty( $type->public ) && ! empty( $type->show_ui )
        && ! in_array( $name, [ 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon', 'shop_webhook', 'shop_subscription' ], true )
        && ! preg_match( '/^(?:acf|acfe|elementor|wc)[_-]|^e-/', $name );
}
