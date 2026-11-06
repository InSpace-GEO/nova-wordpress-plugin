<?php
/** Standalone connection boundary checks; no WordPress or network mutations. */
if ( defined( 'ABSPATH' ) ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ );
class WP_Error { public function __construct( $code = '', $message = '' ) { $this->message = $message; } public $message; public function get_error_message() { return $this->message; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class Nova_Bridge_Suite_Posting_Jobs {
    public static $unfinished = false;
    public static function has_unfinished_for_site( $site ) { return self::$unfinished; }
}
class Nova_Bridge_Suite_Posting_Delivery {
    public static $initializations = 0; public static $storage = true;
    public static function initialize() { ++self::$initializations; }
    public static function ensure_schema() { return self::$storage; }
}
$saved = []; $admin = true; $errors = [];
$users = [ 17 => (object) [ 'ID' => 17, 'display_name' => 'Site editor' ], 28 => (object) [ 'ID' => 28, 'display_name' => 'NOVA <author>' ] ];
function get_option( $key, $default ) { return Nova_Bridge_Suite_Posting_Settings::OPTION === $key ? $GLOBALS['saved'] : $default; }
function current_user_can( $cap ) { return $GLOBALS['admin']; }
function get_current_user_id() { return 17; }
function get_current_blog_id() { return 1; }
function is_multisite() { return false; }
function get_post_types( $args, $output ) {
    return [ 'post' => (object) [ 'name' => 'post', 'public' => true, 'show_ui' => true, 'show_in_rest' => true, 'cap' => (object) [ 'edit_posts' => 'edit_posts', 'create_posts' => 'edit_posts', 'publish_posts' => 'publish_posts' ] ] ];
}
function get_users( $args ) { return array_values( $GLOBALS['users'] ); }
function get_userdata( $id ) { return $GLOBALS['users'][ $id ] ?? false; }
function is_user_member_of_blog( $id, $blog_id ) { return 1 === $blog_id && isset( $GLOBALS['users'][ $id ] ); }
function user_can( $user, $cap ) { return isset( $GLOBALS['users'][ is_object( $user ) ? $user->ID : $user ] ); }
function count_many_users_posts( $ids, $types, $public_only ) { return [ 17 => 1, 28 => 9 ]; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function add_settings_error( $option, $code, $message ) { $GLOBALS['errors'][] = $code; }
function settings_errors( $option ) {}
function settings_fields( $group ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '">'; }
function admin_url( $path ) { return 'https://wordpress.example/wp-admin/' . $path; }
function wp_nonce_field( $action ) { echo '<input name="_wpnonce" value="fixture-nonce">'; }
function submit_button( $label ) { echo '<button>' . esc_html( $label ) . '</button>'; }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_html( $value ) { return esc_attr( $value ); }
function esc_textarea( $value ) { return esc_html( $value ); }
function checked( $first, $second, $display ) { return $first === $second ? 'checked="checked"' : ''; }
function selected( $first, $second, $display ) { return $first === $second ? 'selected="selected"' : ''; }
function home_url( $path ) { return 'https://fixture.invalid' . $path; }
function rest_url( $path ) { return home_url( '/wp-json/' . $path ); }
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-client.php';
require dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-posting-settings.php';
$checks = 0;
function check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } ++$GLOBALS['checks']; }
$input = [ 'base_url' => 'https://posting.example', 'site_id' => '12345678-1234-4234-8234-123456789abc', 'token' => 'nova_pk_fixture1234567890', 'webhook_secret' => 'fixture-signing-secret' ];
check( ! Nova_Bridge_Suite_Posting_Settings::connection()['enabled'], 'An unconfigured site stays idle.' );
$first = Nova_Bridge_Suite_Posting_Settings::sanitize( $input );
check( $first['enabled'] && ! $first['paused'] && 28 === $first['actor_user_id'], 'A configured site starts compatible delivery with the recommended actor.' );
foreach ( array_keys( $input ) as $field ) {
    $saved = $first; unset( $saved[ $field ] );
    check( ! Nova_Bridge_Suite_Posting_Settings::connection()['enabled'], 'An incomplete site stays idle even if the old delivery flag is on.' );
}
$saved = array_merge( $first, [ 'enabled' => false, 'paused' => true ] );
check( Nova_Bridge_Suite_Posting_Settings::connection()['enabled'] && ! Nova_Bridge_Suite_Posting_Settings::connection()['paused'], 'Old local switches do not prevent delivery on an already configured site.' );
$saved = $first;
$blank = array_merge( $input, [ 'token' => '', 'webhook_secret' => '' ] );
$next = Nova_Bridge_Suite_Posting_Settings::sanitize( $blank );
check( $next['token'] === $saved['token'] && $next['webhook_secret'] === $saved['webhook_secret'] && 28 === $next['actor_user_id'], 'Blank secret controls retain stored values and omitted actor input preserves the selection.' );
$selected = Nova_Bridge_Suite_Posting_Settings::sanitize( $blank + [ 'actor_user_id' => '17' ] );
check( 17 === $selected['actor_user_id'] && $selected['actor_user_selected'], 'An administrator can choose a different eligible user.' );
foreach ( [ '999', '28abc', -28, [ 28 ] ] as $invalid_actor ) {
    check( Nova_Bridge_Suite_Posting_Settings::sanitize( $blank + [ 'actor_user_id' => $invalid_actor ] ) === $saved && end( $errors ) === 'nova_posting_actor', 'An invalid selection cannot replace the saved actor.' );
}
$admin = false;
check( Nova_Bridge_Suite_Posting_Settings::sanitize( $input ) === $saved, 'Nonadministrator cannot replace connection.' );
$admin = true;
check( Nova_Bridge_Suite_Posting_Settings::sanitize( $input + [ 'receive_shared_layouts' => '1' ] )['receive_shared_layouts'] === true, 'A new receiver can prevent mapping uploads on its first connection save.' );
foreach ( [ 'http://posting.example', 'https://user:password@posting.example', 'https://posting.example/path', 'https://posting.example?token=value', 'https://posting.example/#fragment' ] as $url ) {
    check( Nova_Bridge_Suite_Posting_Settings::sanitize( array_merge( $input, [ 'base_url' => $url ] ) ) === $saved, 'Only a credential-free HTTPS origin is accepted.' );
}
check( Nova_Bridge_Suite_Posting_Settings::sanitize( array_merge( $input, [ 'site_id' => 'another-site' ] ) ) === $saved, 'Site is a UUID.' );
check( Nova_Bridge_Suite_Posting_Settings::sanitize( array_merge( $input, [ 'token' => "secret\r\nheader" ] ) ) === $saved, 'Credential cannot inject headers.' );
Nova_Bridge_Suite_Posting_Jobs::$unfinished = true;
check( Nova_Bridge_Suite_Posting_Settings::sanitize( array_merge( $input, [ 'base_url' => 'https://other.example' ] ) ) === $saved && end( $errors ) === 'nova_posting_switch_confirmation', 'An environment switch needs explicit cancellation confirmation.' );
Nova_Bridge_Suite_Posting_Jobs::$unfinished = new WP_Error();
check( Nova_Bridge_Suite_Posting_Settings::sanitize( array_merge( $input, [ 'site_id' => '12345678-1234-4234-8234-123456789abd' ] ) ) === $saved, 'Site changes also require cancellation confirmation.' );
Nova_Bridge_Suite_Posting_Jobs::$unfinished = false;
check( Nova_Bridge_Suite_Posting_Settings::sanitize( array_merge( $blank, [ 'base_url' => 'https://other.example', 'confirm_environment_switch' => '1' ] ) ) === $saved && end( $errors ) === 'nova_posting_destination', 'A different origin cannot reuse credentials from blank password fields.' );
$changed = Nova_Bridge_Suite_Posting_Settings::sanitize( array_merge( $input, [ 'base_url' => 'https://other.example', 'token' => 'other-fixture-token', 'webhook_secret' => 'other-fixture-secret', 'enabled' => 0, 'paused' => 1, 'confirm_environment_switch' => '1' ] ) );
check( $changed['enabled'] && ! $changed['paused'] && 'https://other.example' === $changed['base_url'] && ! isset( $changed['confirm_environment_switch'] ), 'A confirmed destination switch validates without persisting the confirmation.' );
Nova_Bridge_Suite_Posting_Jobs::$unfinished = true;
check( Nova_Bridge_Suite_Posting_Settings::sanitize( array_merge( $input, [ 'base_url' => 'https://other.example', 'confirm_environment_switch' => '1' ] ) )['base_url'] === 'https://other.example', 'Pending deliveries no longer prevent a confirmed switch.' );
check( Nova_Bridge_Suite_Posting_Settings::sanitize( array_merge( $input, [ 'base_url' => '', 'confirm_environment_switch' => '1' ] ) ) === $saved, 'An incomplete destination cannot cancel the old work.' );
check( Nova_Bridge_Suite_Posting_Settings::sanitize( array_merge( $input, [ 'token' => 'rotated-token' ] ) )['token'] === 'rotated-token', 'Credential rotation does not need switch confirmation.' );
Nova_Bridge_Suite_Posting_Settings::updated( $first, $next, Nova_Bridge_Suite_Posting_Settings::OPTION );
check( 1 === Nova_Bridge_Suite_Posting_Delivery::$initializations, 'Saving connection settings invokes webhook catch-up initialization.' );
Nova_Bridge_Suite_Posting_Settings::updated( $next, [ 'enabled' => false ], Nova_Bridge_Suite_Posting_Settings::OPTION );
check( 2 === Nova_Bridge_Suite_Posting_Delivery::$initializations, 'An incomplete connection also initializes cleanup of the old delivery cron and catch-up marker.' );
Nova_Bridge_Suite_Posting_Delivery::$storage = false;
Nova_Bridge_Suite_Posting_Settings::updated( $first, $next, Nova_Bridge_Suite_Posting_Settings::OPTION );
check( end( $errors ) === 'nova_posting_storage', 'A storage failure remains visible to the administrator saving settings.' );
check( ! $first['url_scope_enabled'] && [] === $first['url_scope_excluded_paths'], 'Existing connections have routing disabled by default.' );
$routed = Nova_Bridge_Suite_Posting_Settings::sanitize( array_merge( $blank, [ 'url_scope_enabled' => 1, 'url_scope_excluded_paths' => "/eindhoven/\n/schiphol\n/eindhoven" ] ) );
check( $routed['url_scope_enabled'] && [ '/eindhoven', '/schiphol' ] === $routed['url_scope_excluded_paths'], 'An administrator can opt into local ownership and excluded path prefixes.' );
check( $routed['site_id'] === $saved['site_id'] && $routed['token'] === $saved['token'] && $routed['webhook_secret'] === $saved['webhook_secret'], 'Routing settings do not change the shared site identity or credentials.' );
check( Nova_Bridge_Suite_Posting_Settings::sanitize( array_merge( $blank, [ 'url_scope_enabled' => 1, 'url_scope_excluded_paths' => 'https://wrong.invalid/path' ] ) ) === $saved, 'Invalid routing settings retain the previous connection.' );
function render_form() { ob_start(); Nova_Bridge_Suite_Posting_Settings::render(); return ob_get_clean(); }
$saved = $selected;
check( str_contains( render_form(), 'value="17" selected="selected"' ), 'An explicit selection wins over the recommended author after reloading.' );
unset( $saved['actor_user_selected'] );
check( 17 === Nova_Bridge_Suite_Posting_Settings::connection()['actor_user_id'] && str_contains( render_form(), 'value="28" selected="selected"' ), 'On upgrade the legacy administrator remains the runtime actor while the form recommends an author.' );
check( 17 === Nova_Bridge_Suite_Posting_Settings::sanitize( $blank )['actor_user_id'] && empty( Nova_Bridge_Suite_Posting_Settings::sanitize( $blank )['actor_user_selected'] ), 'An older client cannot implicitly change the active actor or confirm the old administrator as a selection.' );
$saved = $routed; $saved['actor_user_selected'] = true; $markup = render_form();
$partial = Nova_Bridge_Suite_Posting_Settings::sanitize( $blank );
check( $partial['url_scope_enabled'] && $partial['url_scope_excluded_paths'] === $routed['url_scope_excluded_paths'], 'A credential-only save cannot widen routing on a shared website.' );
$unchecked = Nova_Bridge_Suite_Posting_Settings::sanitize( $blank + [ 'url_scope_excluded_paths' => '/eindhoven' ] );
check( ! $unchecked['url_scope_enabled'] && [ '/eindhoven' ] === $unchecked['url_scope_excluded_paths'], 'Unchecking the visible routing setting still takes effect when its form is submitted.' );
check( 1 === preg_match( '~<details><summary>Multi-site WordPress</summary>(.*?)</details>~s', $markup, $match ) && str_contains( $match[1], '[url_scope_enabled]' ) && str_contains( $match[1], '[url_scope_excluded_paths]' ) && str_contains( $match[1], "/eindhoven\n/schiphol" ), 'The collapsed Multi-site WordPress disclosure contains both populated routing fields.' );
foreach ( [ '[paused]', '[enabled]', 'Confirm service compatibility', 'Publishing uses the WordPress permissions', $saved['token'], $saved['webhook_secret'] ] as $hidden ) {
    check( ! str_contains( $markup, $hidden ), 'Removed switches, obsolete instructions and saved credentials stay out of the form.' );
}
check( str_contains( $markup, 'for="nova-posting-actor">Posting user</label>' ) && str_contains( $markup, 'value="28" selected="selected">NOVA &lt;author&gt;</option>' ), 'The form selects and escapes the saved posting user.' );
unset( $users[28] ); $markup = render_form();
check( str_contains( $markup, '<option value="" selected>Choose a posting user' ) && ! str_contains( $markup, 'value="17" selected' ), 'A revoked user does not silently switch accounts on render.' );
$users = []; $markup = render_form();
check( str_contains( $markup, '[actor_user_id]" disabled' ) && str_contains( $markup, 'Give a user on this site permission to publish content' ), 'An empty candidate list explains how to resolve publishing access.' );
check( 28 === Nova_Bridge_Suite_Posting_Settings::sanitize( $blank )['actor_user_id'], 'A credential-only save preserves a previously selected, now unavailable user.' );
$saved = []; define( 'NOVA_POSTING_SERVICE_URL', $input['base_url'] ); define( 'NOVA_POSTING_SITE_ID', strtoupper( $input['site_id'] ) ); define( 'NOVA_POSTING_TOKEN', $input['token'] ); define( 'NOVA_POSTING_WEBHOOK_SECRET', $input['webhook_secret'] );
check( ! Nova_Bridge_Suite_Posting_Settings::connection()['enabled'], 'An otherwise complete connection with no saved actor stays idle until a posting user is chosen.' );
$saved = [ 'actor_user_id' => 17 ];
$effective = Nova_Bridge_Suite_Posting_Settings::connection();
check( $effective['enabled'] && $effective['site_id'] === $input['site_id'] && [ 'actor_user_id' => 17 ] === $saved, 'A complete wp-config connection with a saved actor is recognized without persisting its credentials.' );
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-connection.php';
$saving = new ReflectionProperty( Nova_Bridge_Suite_Posting_Connection::class, 'saving' );
if ( PHP_VERSION_ID < 80100 ) { $saving->setAccessible( true ); }
$saving->setValue( null, true );
$trusted = $changed + [ 'history_archived' => true ];
check( Nova_Bridge_Suite_Posting_Settings::sanitize( $trusted ) === $trusted, 'WordPress resanitization preserves the guarded save and its private archive marker.' );
$saving->setValue( null, false );
echo "PASS {$checks} posting connection checks\n";
