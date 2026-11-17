<?php
/** Native ID-query seam for language-provider unit fixtures. */
function get_post_types( $args, $output = 'names' ) { return array_column( Nova_Bridge_Suite_Document_Mapping::resources(), 'post_type' ); }
function get_taxonomies( $args ) { return []; }
function url_to_postid( $url ) { return 0; }
if ( ! function_exists( 'has_filter' ) ) { function has_filter( $hook ) { return false; } }
class Language_Target_DB {
    public $posts = 'wp_posts';
    public $last_error = '';
    public function prepare( $sql, ...$args ) { return [ $sql, $args ]; }
    public function get_col( $query ) {
        [ $sql, $args ] = $query;
        $limit = end( $args );
        $ids = get_posts( [ 'post_type' => $args[0], 'offset' => 0, 'posts_per_page' => PHP_INT_MAX ] );
        $ids = array_filter( $ids, static function ( $id ) use ( $args ) { return $id > $args[1]; } );
        sort( $ids );
        return array_slice( $ids, 0, $limit );
    }
}
$wpdb = new Language_Target_DB();
