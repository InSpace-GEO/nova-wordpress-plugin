<?php
/** Native dictionary selection and replay using the same public writer as a real delivery. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    public $code;
    public function __construct( $code, $message ) { $this->code = $code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function get_option( $key, $default ) { return $default; }
function get_post( $id ) { return (object) [ 'ID' => $id, 'post_title' => 'Contact', 'post_excerpt' => 'About', 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '' ]; }
function get_post_type_object( $type ) { return (object) [ 'public' => true, 'publicly_queryable' => true ]; }
function get_the_title( $p ) { return $p->post_title; }
function get_the_excerpt( $p ) { return $p->post_excerpt; }
function wptexturize( $v ) { return $v; }
function esc_html( $v ) { return htmlspecialchars( $v ); }
function trp_full_trim( $v ) { return trim( $v ); }
function trp_sanitize_string( $v ) { return $v; }
function sanitize_text_field( $v ) { return $v; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta( $id, $key, $v ) { $GLOBALS['meta'][$id][$key] = $v; }
function get_metadata( $type, $id, $key, $single ) { return get_post_meta( $id, $key, $single ); }
function update_metadata( $type, $id, $key, $v ) { update_post_meta( $id, $key, $v ); }
function get_permalink( $id ) { return 'https://fixture.invalid/' . $id; }
function wp_cache_delete( ...$args ) {}
function do_action( ...$args ) {}
class TRP_Translate_Press {
    public $rows = [], $parents = [], $writes = 0, $crash = '';
    public static function get_trp_instance() { return $GLOBALS['trp']; }
    public function get_component( $name ) { return $this; }
    public function get_settings() { return [ 'default-language' => 'en_US', 'publish-languages' => [ 'en_US', 'fr_FR' ], 'translation-languages' => [ 'en_US', 'fr_FR' ] ]; }
    public function get_url_for_language( $locale, $url ) { return $url; }
    public function check_table( ...$args ) {}
    public function get_constant_not_translated() { return 0; }
    public function get_constant_human_reviewed() { return 2; }
    public function get_constant_block_type_regular_string() { return 0; }
    public function get_table_name_for_original_meta() { return 'wp_trp_original_meta'; }
    public function get_meta_key_for_post_parent_id() { return 'post_parent_id'; }
    public function get_string_rows( $ids, $texts, $locale ) {
        return array_filter( $this->rows, static function ( $row ) use ( $ids, $texts ) {
            return in_array( $row->id, $ids, true ) || in_array( $row->original, $texts, true );
        } );
    }
    public function insert_strings( $strings, $locale, $type ) {
        foreach ( $strings as $original ) {
            $id = count( $this->rows ) + 1;
            $this->rows[$id] = (object) [ 'id' => $id, 'original_id' => 100 + $id, 'original' => $original, 'translated' => null, 'status' => 0, 'block_type' => $type ];
        }
    }
    public function update_strings( $updates, $locale, $columns ) {
        if ( 'before' === $this->crash ) { throw new RuntimeException( 'lost request' ); }
        if ( 'discard' === $this->crash ) { return; }
        foreach ( $updates as $row ) {
            ++$this->writes;
            foreach ( $columns as $column ) { $this->rows[$row['id']]->$column = $row[$column]; }
        }
        if ( 'after' === $this->crash ) { throw new RuntimeException( 'lost reply' ); }
    }
    public function set_original_string_meta_post_id( $ids, $posts ) {
        foreach ( $ids as $i => $id ) { $this->parents[$id][$posts[$i]] = (string) $posts[$i]; }
    }
}
$wpdb = new class {
    public $last_error = '';
    public function prepare( $sql, ...$args ) { return $args; }
    public function get_col( $args ) { return array_values( $GLOBALS['trp']->parents[$args[0]] ?? [] ); }
};
require_once dirname( __DIR__ ) . '/includes/class-nova-wordpress-translatepress.php';
function reset_dictionary() { $GLOBALS['trp'] = new TRP_Translate_Press(); $GLOBALS['meta'] = []; }
function save_title( $id, $text, $more = [] ) { return Nova_WordPress_TranslatePress::save( $id, [ 'language' => 'fr_FR', 'title' => $text ] + $more ); }
function check( $value, $message ) { ++$GLOBALS['checks']; if ( ! $value ) { throw new RuntimeException( $message ); } }
function conflict( $value ) { return is_wp_error( $value ) && $value->code === 'nova_trp_shared_translation'; }
$checks = 0;
reset_dictionary();
check( save_title( 7, 'Bonjour' ) === [ 'title' => 1 ], 'First native write' );
check( save_title( 7, 'Bienvenue' ) === [ 'title' => 1 ] && $trp->writes === 2, 'New version can update its own witnessed row' );
check( save_title( 7, 'Bienvenue' ) === [ 'title' => 1 ] && $trp->writes === 2, 'Replay performs no provider write' );
check( conflict( save_title( 8, 'Autre' ) ) && $trp->rows[1]->translated === 'Bienvenue', 'Another post cannot overwrite the original' );
check( save_title( 8, 'Bienvenue' ) === [ 'title' => 1 ] && $trp->writes === 2, 'Another post may reuse the exact translation' );
check( conflict( save_title( 7, 'Nouveau' ) ) && $trp->writes === 2, 'Both posts now use this row; even the first writer cannot change it' );
reset_dictionary();
$trp->insert_strings( [ 'Contact' ], 'fr_FR', 0 );
$trp->rows[1]->status = 2; $trp->rows[1]->translated = '';
check( conflict( save_title( 7, 'Nouveau' ) ), 'Manually reviewed empty string is intentional' );
$trp->rows[1]->status = 1; $trp->rows[1]->translated = 'Bonjour';
check( save_title( 7, 'Bonjour' ) === [ 'title' => 1 ] && $trp->rows[1]->status === 1 && ! $trp->writes, 'Identical preexisting translation retains its status' );
check( conflict( save_title( 7, 'Différent' ) ), 'Equal reuse confers no ownership of manual or machine translations' );
$trp->insert_strings( [ 'Contact' ], 'fr_FR', 0 );
check( conflict( save_title( 7, 'Bonjour' ) ) && ! $trp->writes && count( $trp->rows ) === 2, 'Ambiguous rows remain intact' );
reset_dictionary();
save_title( 7, 'Bonjour' ); $trp->rows[1]->translated = 'Éditorial';
check( conflict( save_title( 7, 'Variation' ) ) && $trp->rows[1]->translated === 'Éditorial', 'Manual edits since v1 are protected' );
$trp->rows[1]->translated = null; $trp->rows[1]->status = 0;
check( conflict( save_title( 7, 'Variation' ) ), 'Resetting a previously translated row is also preserved' );
reset_dictionary();
$trp->insert_strings( [ 'About' ], 'fr_FR', 0 ); $trp->rows[1]->translated = 'Manuel'; $trp->rows[1]->status = 2;
check( conflict( save_title( 7, 'Bonjour', [ 'excerpt' => 'Remplacement' ] ) ) && ! $trp->writes && $trp->rows[1]->translated === 'Manuel', 'A later conflicting field does not partially translate earlier fields' );
foreach ( [ 'before', 'after' ] as $stage ) {
    reset_dictionary(); $trp->crash = $stage;
    try { save_title( 7, 'Bonjour' ); throw new LogicException( 'Expected simulated interruption' ); } catch ( RuntimeException $expected ) {}
    $trp->crash = '';
    check( save_title( 7, 'Bonjour' ) === [ 'title' => 1 ] && $trp->writes === 1, 'Idempotent native retry after ' . $stage );
    check( save_title( 7, 'Nouveau' ) === [ 'title' => 1 ] && $trp->writes === 2, 'Recovered row retains v2 update authority' );
}
$wpdb->last_error = 'unavailable';
check( conflict( save_title( 7, 'Nouveau encore' ) ) && $trp->writes === 2, 'Cannot replace without verifying known parents' );
$wpdb->last_error = ''; $trp->crash = 'discard';
$failed = save_title( 7, 'Nouveau encore' );
check( is_wp_error( $failed ) && $failed->code === 'nova_trp_write_failed', 'Lost write cannot produce a success receipt' );
echo "TranslatePress dictionary collision checks: $checks passed.\n";
