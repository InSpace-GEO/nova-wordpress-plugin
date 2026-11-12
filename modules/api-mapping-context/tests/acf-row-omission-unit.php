<?php
/** Fixed ACF layouts leave out repeater and flexible-content rows whose optional mapped fields were left empty. */
ob_start(); require __DIR__ . '/layout-recipes-api-unit.php'; ob_end_clean();
$baseline_checks = $checks;

function row_schema( array $extra = [], array $text_layout = [] ): array {
    return [
        'content' => af( 'content', 'flexible_content', $extra + [ 'layouts' => [
            $text_layout + [ 'key' => 'layout_text', 'name' => 'text_block', 'label' => 'Text', 'sub_fields' => [ af( 'title' ), af( 'h_type', 'select', [ 'choices' => [ 'h2' => 'H2' ] ] ), af( 'content_left', 'wysiwyg' ) ] ],
            [ 'key' => 'layout_cards', 'name' => 'cards', 'label' => 'Cards', 'sub_fields' => [ af( 'heading' ), af( 'bg', 'select', [ 'choices' => [ 'odd' => 'Odd' ] ] ),
                af( 'cards', 'repeater', [ 'sub_fields' => [ af( 'title' ), af( 'summary', 'textarea' ) ] ] ) ] ],
        ] ] ),
        'video' => af( 'video' ),
    ];
}
function row_native( array $schema, array $rows ): array { return [ 'schema' => $schema, 'document' => [ 'content' => $rows, 'video' => '' ] ]; }
function text_row( string $title, string $body ): array { return [ 'acf_fc_layout' => 'text_block', 'title' => $title, 'h_type' => 'h2', 'content_left' => $body ]; }
function cards_row( string $heading, array $cards ): array { return [ 'acf_fc_layout' => 'cards', 'heading' => $heading, 'bg' => 'odd', 'cards' => $cards ]; }

$rows = [ text_row( 'Intro', '<p>Kept</p>' ), text_row( 'Fixed heading', '' ), cards_row( '', [ [ 'title' => '', 'summary' => '' ], [ 'title' => '', 'summary' => '' ] ] ), text_row( '', '<p>Later</p>' ) ];
$native = row_native( row_schema(), $rows );
$kept = [ '/document/content/0/content_left', '/document/content/3/content_left' ];
$vacant = [ '/document/content/1/content_left', '/document/content/2/cards/0/title', '/document/content/2/cards/0/summary', '/document/content/2/cards/1/title', '/document/content/2/cards/1/summary' ];
$gone = Nova_Bridge_Suite_Recipe_ACF::vacant_rows( $native, $vacant, $kept );
sort( $gone );
check( $gone === [ '/document/content/1', '/document/content/2' ], 'Empty rows, and a cards row left with only its style setting, were not left out exactly: ' . json_encode( $gone ) );

$with_heading = $rows; $with_heading[2]['heading'] = 'Fixed cards heading';
$gone = Nova_Bridge_Suite_Recipe_ACF::vacant_rows( row_native( row_schema(), $with_heading ), $vacant, $kept ); sort( $gone );
check( $gone === [ '/document/content/1', '/document/content/2/cards/0', '/document/content/2/cards/1' ], 'A row still showing its fixed heading was removed with its emptied cards: ' . json_encode( $gone ) );

$gone = Nova_Bridge_Suite_Recipe_ACF::vacant_rows( $native, [ '/document/content/1/content_left' ], [ '/document/content/1/title' ] );
check( $gone === [], 'A row with a filled mapped field was removed.' );
$gone = Nova_Bridge_Suite_Recipe_ACF::vacant_rows( $native, [ '/document/video' ], [] );
check( $gone === [], 'A top-level field took something with it.' );
$gone = Nova_Bridge_Suite_Recipe_ACF::vacant_rows( $native, [], [], [ '/document/content/3' ] );
check( $gone === [ '/document/content/3' ], 'An explicit omission was not passed through.' );

// ACF's minimums: the field's min, a required field and a layout's min keep rows (later rows go first).
$all_text = [ text_row( '', '' ), text_row( '', '' ), text_row( '', '' ) ];
$every = [ '/document/content/0/content_left', '/document/content/1/content_left', '/document/content/2/content_left' ];
$gone = Nova_Bridge_Suite_Recipe_ACF::vacant_rows( row_native( row_schema( [ 'min' => 2 ] ), $all_text ), $every, [] );
check( $gone === [ '/document/content/2' ], 'The field minimum was not kept: ' . json_encode( $gone ) );
$gone = Nova_Bridge_Suite_Recipe_ACF::vacant_rows( row_native( row_schema( [ 'required' => true ] ), $all_text ), $every, [] ); sort( $gone );
check( $gone === [ '/document/content/1', '/document/content/2' ], 'A required field lost its last row: ' . json_encode( $gone ) );
$gone = Nova_Bridge_Suite_Recipe_ACF::vacant_rows( row_native( row_schema( [], [ 'min' => 1 ] ), $all_text ), $every, [] ); sort( $gone );
check( $gone === [ '/document/content/1', '/document/content/2' ], 'A layout minimum was not kept: ' . json_encode( $gone ) );
$gone = Nova_Bridge_Suite_Recipe_ACF::vacant_rows( row_native( row_schema( [ 'min' => 3 ] ), $all_text ), $every, [] );
check( $gone === [], 'Rows below the field minimum were removed.' );

// Image entries follow their row when an earlier row goes; an entry inside a removed row is dropped.
$media = [ '/document/content/3/image' => [ 'kind' => 'acf', 'id_target' => '/document/content/3/image', 'url' => 'https://example.com/a.jpg' ],
    '/document/content/1/image' => [ 'kind' => 'acf', 'id_target' => '/document/content/1/image', 'url' => 'https://example.com/b.jpg' ],
    '/document/other/0/image' => [ 'kind' => 'acf', 'id_target' => '/document/other/0/image', 'url' => 'https://example.com/c.jpg' ],
    '/document/content/4/cards/2/image' => [ 'kind' => 'acf', 'id_target' => '/document/content/4/cards/2/image', 'url' => 'https://example.com/d.jpg' ] ];
$shifted = Nova_Bridge_Suite_Recipe_Engine::shift_pointers( $media, [ '/document/content/1', '/document/content/2', '/document/content/4/cards/0' ] );
check( array_keys( $shifted ) === [ '/document/content/1/image', '/document/other/0/image', '/document/content/2/cards/1/image' ], 'Image entries did not follow their rows: ' . json_encode( array_keys( $shifted ) ) );
check( $shifted['/document/content/1/image']['id_target'] === '/document/content/1/image' && $shifted['/document/content/2/cards/1/image']['id_target'] === '/document/content/2/cards/1/image', 'An image entry kept its old row pointer.' );
check( Nova_Bridge_Suite_Recipe_Engine::shift_pointers( $media, [] ) === $media, 'Image entries changed without removals.' );

// End to end through a captured fixed ACF layout: the empty first row is left out of the compiled document.
$acf_schema = [ 'matrix' => af( 'matrix', 'flexible_content', [ 'layouts' => [
    [ 'key' => 'layout_first', 'name' => 'copy', 'label' => 'Copy', 'sub_fields' => [ af( 'title' ), af( 'body', 'wysiwyg' ) ] ] ] ] ) ];
$acf_values[11] = [ $acf_schema['matrix']['key'] => [ [ 'acf_fc_layout' => 'copy', 'title' => 'One', 'body' => '<p>One</p>' ], [ 'acf_fc_layout' => 'copy', 'title' => 'Two', 'body' => '<p>Two</p>' ] ] ];
$captured = design( 'acf', 'static', 'post_type:page', 11 );
$sources = []; foreach ( $captured['recipe_preset']['bindings'] as $binding ) { $sources[ $binding['target'] ] = substr( $binding['source'], strlen( '/fields/' ) ); }
$sample = $captured['sample'];
$sample['fields'][ $sources['/document/matrix/0/title'] ] = ''; $sample['fields'][ $sources['/document/matrix/0/body'] ] = '<p> </p>';
$sample['fields'][ $sources['/document/matrix/1/title'] ] = 'Delivered'; $sample['fields'][ $sources['/document/matrix/1/body'] ] = '<p>Delivered body</p>';
$compiled = Nova_Bridge_Suite_Recipe_Engine::compile( $captured['recipe_preset'], $sample, 'acf-row-omission' );
check( ! is_wp_error( $compiled ), is_wp_error( $compiled ) ? $compiled->get_error_message() : '' );
check( count( $compiled['native_document']['matrix'] ) === 1 && 'Delivered' === $compiled['native_document']['matrix'][0]['title'] && 1 === ( $compiled['vacated'] ?? 0 ), 'The compiled ACF document kept the empty row: ' . json_encode( $compiled['native_document']['matrix'] ) );
$sample['fields'][ $sources['/document/matrix/0/title'] ] = 'Back';
$compiled = Nova_Bridge_Suite_Recipe_Engine::compile( $captured['recipe_preset'], $sample, 'acf-row-omission' );
check( count( $compiled['native_document']['matrix'] ) === 2 && ! isset( $compiled['vacated'] ), 'A row with one filled field was removed.' );

echo 'PASS ' . ( $checks - $baseline_checks ) . " ACF row omission checks\n";
