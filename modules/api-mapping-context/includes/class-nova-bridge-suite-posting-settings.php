<?php
/** Private connection settings for the site's posting-service plugin credential. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once dirname( __DIR__, 2 ) . '/posting-service/includes/class-nova-bridge-suite-posting-url-scope.php';
require_once dirname( __DIR__, 2 ) . '/posting-service/includes/class-nova-bridge-suite-posting-actor.php';
require_once dirname( __DIR__, 2 ) . '/posting-service/includes/class-nova-bridge-suite-connection-layout-sync.php';

final class Nova_Bridge_Suite_Posting_Settings {
    public const OPTION = 'nova_bridge_posting_connection';
    private const GROUP = 'nova_bridge_posting_connection_settings';

    public static function bootstrap(): void {
        add_action( 'admin_init', [ __CLASS__, 'register' ] );
        add_action( 'admin_post_nova_posting_save', [ __CLASS__, 'save_form' ] );
        add_filter( 'rest_dispatch_request', [ 'Nova_Bridge_Suite_Posting_Connection', 'dispatch' ], 10, 4 );
        add_filter( 'pre_update_option_' . self::OPTION, [ 'Nova_Bridge_Suite_Posting_Connection', 'protect_update' ], 10, 2 );
        add_action( 'update_option_' . self::OPTION, [ __CLASS__, 'updated' ], 10, 3 );
    }

    public static function register(): void {
        register_setting( self::GROUP, self::OPTION, [ 'type' => 'array', 'show_in_rest' => false, 'sanitize_callback' => [ __CLASS__, 'sanitize' ] ] );
        add_option( self::OPTION, [], '', 'no' );
    }

    public static function connection(): array {
        $value = get_option( self::OPTION, [] );
        return self::effective_connection( is_array( $value ) ? $value : [] );
    }

    public static function effective_connection( array $value ): array {
        $value += [ 'base_url' => '', 'site_id' => '', 'token' => '', 'webhook_secret' => '', 'actor_user_id' => 0, 'actor_user_selected' => false, 'url_scope_enabled' => false, 'url_scope_excluded_paths' => [] ];
        foreach ( [ 'base_url' => 'NOVA_POSTING_SERVICE_URL', 'site_id' => 'NOVA_POSTING_SITE_ID', 'token' => 'NOVA_POSTING_TOKEN', 'webhook_secret' => 'NOVA_POSTING_WEBHOOK_SECRET' ] as $key => $constant ) {
            if ( defined( $constant ) ) { $value[ $key ] = (string) constant( $constant ); }
        }
        $value['site_id'] = strtolower( $value['site_id'] );
        $value['actor_user_id'] = (int) $value['actor_user_id'];
        // Keep the connector's existing interface. Local switches from earlier releases no longer control delivery.
        $value['enabled'] = $value['actor_user_id'] > 0 && is_string( $value['webhook_secret'] ) && '' !== $value['webhook_secret'] && null === ( new Nova_Bridge_Suite_Posting_Client( $value ) )->connection_error();
        $value['paused'] = false;
        $value['url_scope_enabled'] = ! empty( $value['url_scope_enabled'] );
        $value['receive_shared_layouts'] = ! empty( $value['receive_shared_layouts'] );
        return $value;
    }

    public static function updated( $old, $next, $option ): void {
        if ( class_exists( 'Nova_Bridge_Suite_Posting_Connection' ) && Nova_Bridge_Suite_Posting_Connection::saving() ) { return; }
        if ( class_exists( 'Nova_Bridge_Suite_Posting_Delivery' ) ) {
            if ( class_exists( 'Nova_Bridge_Suite_Posting_Connection' ) ) { Nova_Bridge_Suite_Posting_Delivery::guarded_initialize(); }
            else { Nova_Bridge_Suite_Posting_Delivery::initialize(); }
            if ( ! empty( $next['enabled'] ) && ! Nova_Bridge_Suite_Posting_Delivery::ensure_schema() ) { add_settings_error( self::OPTION, 'nova_posting_storage', 'Delivery storage could not be initialized. New deliveries remain unavailable.' ); }
            return;
        }
        if ( ! empty( $next['enabled'] ) && class_exists( 'Nova_Bridge_Suite_Posting_Jobs' ) ) {
            $installed = ( new Nova_Bridge_Suite_Posting_Jobs() )->install();
            if ( true !== $installed ) { add_settings_error( self::OPTION, 'nova_posting_storage', 'Delivery storage could not be initialized. New deliveries remain unavailable.' ); }
        }
    }

    public static function sanitize( $input ): array {
        $old = get_option( self::OPTION, [] );
        $old = is_array( $old ) ? $old : [];
        if ( ! is_array( $input ) || ! current_user_can( 'manage_options' ) ) { return $old; }
        // update_option sanitizes again. The guarded save has already validated this value;
        // preserve its private archive marker rather than rebuilding it from the old option.
        if ( class_exists( 'Nova_Bridge_Suite_Posting_Connection' ) && Nova_Bridge_Suite_Posting_Connection::saving() ) { return $input; }
        $next = $old;
        if ( array_key_exists( 'receive_shared_layouts', $input ) ) {
            $next['receive_shared_layouts'] = in_array( $input['receive_shared_layouts'], [ '1', 1, true ], true );
        }
        $next += [ 'url_scope_enabled' => false, 'url_scope_excluded_paths' => [] ];
        foreach ( [ 'base_url', 'site_id' ] as $key ) {
            if ( isset( $input[ $key ] ) && is_string( $input[ $key ] ) ) { $next[ $key ] = trim( $input[ $key ] ); }
        }
        $next['base_url'] = rtrim( $next['base_url'] ?? '', '/' );
        $next['site_id'] = strtolower( $next['site_id'] ?? '' );
        $parts = wp_parse_url( $next['base_url'] );
        if ( $next['base_url'] && ( ! is_array( $parts ) || ( $parts['scheme'] ?? '' ) !== 'https' || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || ! in_array( $parts['path'] ?? '', [ '', '/' ], true ) ) ) {
            add_settings_error( self::OPTION, 'nova_posting_url', 'Enter the HTTPS service origin without a path, credentials, query parameters or a fragment.' );
            return $old;
        }
        if ( ! empty( $next['site_id'] ) && ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $next['site_id'] ) ) {
            add_settings_error( self::OPTION, 'nova_posting_site', 'Enter the publishing site UUID issued by NOVA.' );
            return $old;
        }
        // Blank password controls retain the saved secret; secrets are never echoed.
        foreach ( [ 'token', 'webhook_secret' ] as $key ) {
            if ( isset( $input[ $key ] ) && is_string( $input[ $key ] ) && '' !== trim( $input[ $key ] ) ) {
                $secret = trim( $input[ $key ] );
                if ( strlen( $secret ) > 4096 || preg_match( '/[\x00-\x20\x7f]/', $secret ) ) {
                    add_settings_error( self::OPTION, 'nova_posting_secret', 'Credentials must be a single token without whitespace.' );
                    return $old;
                }
                $next[ $key ] = $secret;
            }
        }
        if ( array_key_exists( 'actor_user_id', $input ) ) {
            $actor = filter_var( $input['actor_user_id'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
            if ( false === $actor || ! Nova_Bridge_Suite_Posting_Actor::eligible( $actor ) ) {
                add_settings_error( self::OPTION, 'nova_posting_actor', 'Choose a posting user who can publish content on this WordPress site.' );
                return $old;
            }
            $next['actor_user_id'] = $actor;
            $next['actor_user_selected'] = true;
        } elseif ( empty( $old['actor_user_id'] ) && ! empty( $input ) ) {
            $next['actor_user_id'] = Nova_Bridge_Suite_Posting_Actor::default_id();
        }
        // The form always posts the textarea, including when the routing checkbox is unchecked.
        if ( array_key_exists( 'url_scope_excluded_paths', $input ) ) {
            $exclusions = Nova_Bridge_Suite_Posting_Url_Scope::exclusions( $input['url_scope_excluded_paths'] );
            if ( is_wp_error( $exclusions ) ) {
                add_settings_error( self::OPTION, 'nova_posting_url_scope', $exclusions->get_error_message() );
                return $old;
            }
            $next['url_scope_excluded_paths'] = $exclusions;
            $next['url_scope_enabled'] = ! empty( $input['url_scope_enabled'] );
        } elseif ( array_key_exists( 'url_scope_enabled', $input ) ) {
            $next['url_scope_enabled'] = ! empty( $input['url_scope_enabled'] );
        }
        $previous = self::effective_connection( $old );
        $connection = self::effective_connection( $next );
        $origin_changed = $previous['base_url'] !== $connection['base_url'];
        $site_changed = $previous['site_id'] !== $connection['site_id'];
        if ( ( $origin_changed || $site_changed ) && '' !== $previous['site_id'] ) {
            // Cancelling and archiving the previous connection's work is the default; the result is reported after saving.
            if ( ! $connection['enabled'] ) {
                add_settings_error( self::OPTION, 'nova_posting_switch_incomplete', 'Enter the complete new connection and choose a posting user before switching.' );
                return $old;
            }
        }
        if ( ( $origin_changed && '' !== $previous['base_url'] ) || ( $site_changed && '' !== $previous['site_id'] ) ) {
            foreach ( [ 'token' => 'NOVA_POSTING_TOKEN', 'webhook_secret' => 'NOVA_POSTING_WEBHOOK_SECRET' ] as $key => $constant ) {
                if ( ! defined( $constant ) && ! empty( $old[ $key ] ) && ( ! is_string( $input[ $key ] ?? null ) || '' === trim( $input[ $key ] ) ) ) {
                    add_settings_error( self::OPTION, 'nova_posting_destination', 'Enter the credentials issued for the new service or site. Your existing connection has been kept.' );
                    return $old;
                }
            }
        }
        $next['enabled'] = $connection['enabled'];
        $next['paused'] = false;
        return $next;
    }

    public static function save_form(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Administrator access is required.', '', [ 'response' => 403 ] ); }
        check_admin_referer( 'nova_posting_save' );
        $input = isset( $_POST[self::OPTION] ) && is_array( $_POST[self::OPTION] ) ? wp_unslash( $_POST[self::OPTION] ) : [];
        $result = Nova_Bridge_Suite_Posting_Connection::save( $input );
        if ( is_wp_error( $result ) ) { add_settings_error( self::OPTION, $result->get_error_code(), $result->get_error_message() ); }
        elseif ( ! get_settings_errors( self::OPTION ) ) { add_settings_error( self::OPTION, 'nova_posting_saved', 'NOVA connection saved.', 'success' ); }
        set_transient( 'settings_errors', get_settings_errors(), 30 );
        wp_safe_redirect( add_query_arg( 'settings-updated', 'true', wp_get_referer() ?: admin_url( 'options-general.php' ) ) );
        exit;
    }

    /** Asked only when the service URL or website identifier changes; credential updates submit directly. */
    private static function render_switch_dialog(): void {
        $field = static function ( string $key ) { return '<input type="hidden" name="' . esc_attr( self::OPTION . '[' . $key . ']' ) . '" value="0" data-nova-switch="' . esc_attr( $key ) . '">'; };
        echo $field( 'copy_environment_layouts' ) . $field( 'sync_copied_layouts' );
        echo '<dialog id="nova-switch-dialog" aria-labelledby="nova-switch-title" style="max-width:32rem;padding:1.5rem;border:1px solid #c3c4c7;border-radius:8px">';
        echo '<h2 id="nova-switch-title" style="margin-top:0">Switch NOVA connection?</h2>';
        echo '<p>Copy your current layouts and mappings to the new connection and synchronize them?</p>';
        echo '<p class="description">Pending deliveries from the current connection will be cancelled. Their history is kept in an archive.</p>';
        echo '<p style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:0"><button type="button" class="button button-primary" data-nova-choice="copy">Copy and synchronize</button><button type="button" class="button" data-nova-choice="empty">Switch without copying</button><button type="button" class="button-link" data-nova-choice="cancel">Cancel</button></p>';
        echo '</dialog>';
        $script = <<<'JS'
(function () {
    var form = document.getElementById( 'nova-connection-form' ), dialog = document.getElementById( 'nova-switch-dialog' );
    if ( ! form || ! dialog || typeof dialog.showModal !== 'function' ) { return; }
    var chosen = false;
    function origin( value ) { return String( value || '' ).trim().replace( /\/+$/, '' ).toLowerCase().replace( /:443$/, '' ); }
    function changed() {
        var url = form.querySelector( '#nova-posting-base_url' ), site = form.querySelector( '#nova-posting-site_id' );
        return !! url && !! site && ( origin( url.value ) !== origin( form.dataset.baseUrl ) || site.value.trim().toLowerCase() !== String( form.dataset.siteId ).toLowerCase() );
    }
    function set( copy ) { form.querySelectorAll( '[data-nova-switch]' ).forEach( function ( input ) { input.value = copy ? '1' : '0'; } ); }
    form.addEventListener( 'submit', function ( event ) {
        if ( chosen || ! changed() ) { return; }
        event.preventDefault(); dialog.showModal();
    } );
    dialog.addEventListener( 'click', function ( event ) {
        var choice = event.target.getAttribute && event.target.getAttribute( 'data-nova-choice' );
        if ( ! choice ) { return; }
        dialog.close();
        if ( 'cancel' === choice ) { return; }
        set( 'copy' === choice ); chosen = true;
        if ( form.requestSubmit ) { form.requestSubmit(); } else { form.submit(); }
    } );
}());
JS;
        wp_print_inline_script_tag( $script );
    }

    private static function render_sync_dialog( array $settings ): void {
        if ( ! Nova_Bridge_Suite_Connection_Layout_Sync::take_prompt( $settings ) ) { return; }
        echo '<input type="hidden" name="' . esc_attr( self::OPTION . '[sync_layouts_environment]' ) . '" value="' . esc_attr( Nova_WordPress_Environment::identity( $settings ) ) . '">';
        echo '<input type="hidden" name="' . esc_attr( self::OPTION . '[sync_saved_layouts]' ) . '" value="0" data-nova-sync-layouts>';
        echo '<dialog id="nova-sync-layouts-dialog" aria-labelledby="nova-sync-layouts-title" style="max-width:32rem;padding:1.5rem;border:1px solid #c3c4c7;border-radius:8px"><h2 id="nova-sync-layouts-title" style="margin-top:0">Synchronize saved mappings?</h2><p>Your NOVA connection is saved. Some local mappings are not synchronized with posting service. Synchronize them now?</p><p style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:0"><button type="button" class="button button-primary" data-nova-sync-choice="sync">Synchronize now</button><button type="button" class="button" data-nova-sync-choice="later">Later</button></p></dialog>';
        $script = <<<'JS'
(function () {
    var form = document.getElementById( 'nova-connection-form' ), dialog = document.getElementById( 'nova-sync-layouts-dialog' );
    if ( ! form || ! dialog || typeof dialog.showModal !== 'function' ) { return; }
    dialog.showModal();
    dialog.addEventListener( 'click', function ( event ) {
        var choice = event.target.getAttribute && event.target.getAttribute( 'data-nova-sync-choice' );
        if ( ! choice ) { return; }
        dialog.close();
        if ( 'sync' !== choice ) { return; }
        form.querySelector( '[data-nova-sync-layouts]' ).value = '1';
        if ( form.requestSubmit ) { form.requestSubmit(); } else { form.submit(); }
    } );
}());
JS;
        wp_print_inline_script_tag( $script );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $settings = self::connection();
        echo '<details class="nova-mapping-advanced nova-connection-form" open><summary>Connection details</summary><p>Use the connection details issued for this website in NOVA site administration. This shared connection is separate from either layout approach.</p>';
        // After the save redirect WordPress already prints these notices on Settings pages.
        if ( empty( $_GET['settings-updated'] ) ) { settings_errors( self::OPTION ); }
        echo '<form id="nova-connection-form" method="post" action="' . esc_attr( admin_url( 'admin-post.php' ) ) . '" data-base-url="' . esc_attr( (string) $settings['base_url'] ) . '" data-site-id="' . esc_attr( (string) $settings['site_id'] ) . '"><input type="hidden" name="action" value="nova_posting_save">';
        wp_nonce_field( 'nova_posting_save' );
        foreach ( [ 'base_url' => 'NOVA service URL', 'site_id' => 'Website identifier (UUID)', 'token' => 'Website access token', 'webhook_secret' => 'Notification signing secret' ] as $key => $label ) {
            $secret = in_array( $key, [ 'token', 'webhook_secret' ], true );
            echo '<p><label for="nova-posting-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><br><input class="regular-text" id="nova-posting-' . esc_attr( $key ) . '" name="' . esc_attr( self::OPTION . '[' . $key . ']' ) . '" type="' . ( $secret ? 'password' : 'text' ) . '" value="' . ( $secret ? '' : esc_attr( (string) $settings[ $key ] ) ) . '" autocomplete="' . ( $secret ? 'new-password' : 'off' ) . '">';
            if ( $secret ) { echo '<br><span class="description">' . ( ! empty( $settings[ $key ] ) ? 'A credential is configured. Leave blank to keep it.' : 'No credential configured.' ) . '</span>'; }
            echo '</p>';
        }
        $users = Nova_Bridge_Suite_Posting_Actor::choices( $settings['actor_user_selected'] ? $settings['actor_user_id'] : 0 );
        // Older releases recorded the last administrator to save, without a user selection.
        $actor = $settings['actor_user_selected'] ? $settings['actor_user_id'] : Nova_Bridge_Suite_Posting_Actor::default_id( $users );
        echo '<p><label for="nova-posting-actor">Posting user</label><br><select id="nova-posting-actor" name="' . esc_attr( self::OPTION ) . '[actor_user_id]"' . ( $users ? ' required' : ' disabled' ) . '>';
        if ( ! isset( $users[ $actor ] ) ) {
            echo '<option value="" selected>' . ( $users ? 'Choose a posting user' : 'No eligible posting user' ) . '</option>';
        }
        foreach ( $users as $id => $name ) {
            echo '<option value="' . esc_attr( $id ) . '" ' . selected( $id, $actor, false ) . '>' . esc_html( $name ) . '</option>';
        }
        echo '</select><br><span class="description">' . ( $users ? 'Choose an account that can publish the content types you map, then save the connection.' : 'Give a user on this site permission to publish content, then select that user here.' ) . '</span></p>';
        if ( '' !== $settings['site_id'] ) { self::render_switch_dialog(); }
        self::render_sync_dialog( $settings );
        submit_button( 'Save NOVA connection' );
        echo '<details><summary>Multi-site WordPress</summary><p><label><input type="checkbox" name="' . esc_attr( self::OPTION ) . '[url_scope_enabled]" value="1" ' . checked( $settings['url_scope_enabled'], true, false ) . '> Only process deliveries belonging to this WordPress installation</label><br><span class="description">Use this when several installations share a NOVA website identifier. This installation handles deliveries under <code>' . esc_html( get_option( 'home', home_url( '/' ) ) ) . '</code>.</span></p>';
        $imported = is_callable( [ 'Nova_Bridge_Suite_Shared_Layouts', 'has_imports' ] ) && Nova_Bridge_Suite_Shared_Layouts::has_imports( $settings, $settings['site_id'] );
        echo '<input type="hidden" name="' . esc_attr( self::OPTION ) . '[receive_shared_layouts]" value="0">';
        echo '<p><label><input type="checkbox" name="' . esc_attr( self::OPTION ) . '[receive_shared_layouts]" value="1" ' . checked( $imported || $settings['receive_shared_layouts'], true, false ) . ( $imported ? ' disabled' : '' ) . '> Only receive shared layouts on this installation</label><br><span class="description">';
        echo $imported ? 'This connection uses imported layouts. Make mapping changes on the installation that exports them.' : 'Select this before connecting a new receiving installation to prevent automatic template uploads. Importing a layout also enables this protection.';
        echo '</span></p>';
        echo '<p><label for="nova-posting-excluded-paths">Excluded paths, one per line</label><br><textarea class="large-text" rows="4" id="nova-posting-excluded-paths" name="' . esc_attr( self::OPTION ) . '[url_scope_excluded_paths]">' . esc_textarea( implode( "\n", (array) $settings['url_scope_excluded_paths'] ) ) . '</textarea><br><span class="description">Enter paths served by other installations, such as /eindhoven. Each path includes its subpaths.</span></p></details>';
        echo '</form></details><details class="nova-mapping-advanced"><summary>Delivery status and technical details</summary><p>Ready notification URL: <code>' . esc_html( rest_url( 'nova-bridge/v1/posting/ready' ) ) . '</code></p><div id="nova-posting-status"></div></details>';
    }
}
