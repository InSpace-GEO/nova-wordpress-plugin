<?php
/** Native rejection reasons must survive mapping approval and editorial transport. */
require __DIR__ . '/layout-recipes-api-unit.php';

function acf_get_validation_errors() { return ( $GLOBALS['acf_diagnostic_errors'] ?? [] ) ?: false; }
function acf_validate_value( $value, $field, $input ) {
    $failure = $GLOBALS['acf_diagnostic_failure'] ?? null;
    if ( null === $failure ) {
        if ( empty( $GLOBALS['acf_conditional_native'] ) ) { return true; }
        $GLOBALS['acf_validated_inputs'][] = $input;
        if ( ! empty( $field['required'] ) && empty( $value ) && ! is_numeric( $value ) ) {
            $GLOBALS['acf_diagnostic_errors'][] = [ 'input' => $input, 'message' => $field['label'] . ' value is required' ];
            return false;
        }
        $rows = ( $field['type'] ?? '' ) === 'group' ? [ $value ] : $value;
        if ( in_array( $field['type'], [ 'group', 'repeater', 'flexible_content' ], true ) ) {
            foreach ( $rows as $index => $row ) {
                $children = $field['sub_fields'] ?? [];
                foreach ( $field['layouts'] ?? [] as $layout ) {
                    if ( ( $row['acf_fc_layout'] ?? '' ) === $layout['name'] ) { $children = $layout['sub_fields']; break; }
                }
                foreach ( $children as $child ) {
                    if ( ! isset( $row[$child['key']] ) ) { continue; }
                    $path = $input . ( $field['type'] === 'group' ? '' : '[' . $index . ']' ) . '[' . $child['key'] . ']';
                    acf_validate_value( $row[$child['key']], $child, $path );
                }
            }
        }
        return true;
    }
    $GLOBALS['acf_diagnostic_errors'][] = [ 'input' => $input . ( $failure['suffix'] ?? '' ), 'message' => $failure['message'] ];
    return false;
}

use Nova_Bridge_Suite_Content_Transport as Transport;
$baseline_checks = $checks;
$acf_schema = [ 'hero_title' => af( 'hero_title', 'text', [ 'required' => true ] ) ];
$acf_example_only = false;
$administrator = true;
$denied_caps = [];
$acf_diagnostic_errors = [ [ 'input' => 'acf.other', 'message' => 'An unrelated earlier failure.' ] ];
$before = $acf_diagnostic_errors;
$acf_diagnostic_failure = [ 'message' => '<strong>Hero title</strong> value is required' ];

$error = Transport::validate_named_acf( [ 'hero_title' => '' ], $acf_schema );
check( is_wp_error( $error ) && $error->get_error_message() === 'ACF validation failed for hero_title. Hero title value is required', 'Named ACF validation must include the native reason as plain text.' );
check( $error->get_error_data()['status'] === 400, 'Native rejection must retain the existing validation status.' );
check( $acf_diagnostic_errors[0] === $before[0] && count( $acf_diagnostic_errors ) === 2, 'Diagnostics must preserve ACF errors for other callers.' );

$error = Transport::validate_payload( [ 'acf' => [ 'hero_title' => '' ] ], 'page', 11 );
check( is_wp_error( $error ) && $error->get_error_message() === 'ACF validation failed for hero_title. Hero title value is required', 'Editorial transport must report the same native reason without duplicate punctuation.' );
check( ! str_contains( $error->get_error_message(), 'unrelated' ), 'A rejection must not reuse a prior field error.' );

$acf_diagnostic_failure = [ 'message' => 'Use a shorter heading.' ];
$acf_values[11] = [ $acf_schema['hero_title']['key'] => 'A populated heading' ];
$design = Nova_Bridge_Suite_Recipe_ACF::design( 'page', 11, 'protected-template.php', '', true );
check( ! is_wp_error( $design ), 'Capture must succeed before native publication approval.' );
$adapter = new Nova_Bridge_Suite_Recipe_ACF();
$error = $adapter->finalize( $design['blueprint'], 'approval-diagnostics' );
check( is_wp_error( $error ) && str_contains( $error->get_error_message(), 'Use a shorter heading.' ), 'Saved-layout approval must retain the native custom validation reason.' );

$acf_diagnostic_failure = [ 'suffix' => '[field_nested_title]', 'message' => 'Nested title is invalid.' ];
$error = Transport::validate_named_acf( [ 'hero_title' => 'Heading' ], $acf_schema );
check( is_wp_error( $error ) && str_contains( $error->get_error_message(), 'Nested title is invalid.' ), 'A native child field error must be included for its parent.' );

$acf_diagnostic_failure = [ 'suffix' => '_unrelated', 'message' => 'Another field is invalid.' ];
$error = Transport::validate_named_acf( [ 'hero_title' => 'Heading' ], $acf_schema );
check( is_wp_error( $error ) && $error->get_error_message() === 'ACF validation failed for hero_title', 'Errors with a shared name prefix must not be attributed to this field.' );

$acf_diagnostic_failure = [ 'message' => '' ];
$error = Transport::validate_named_acf( [ 'hero_title' => 'Heading' ], $acf_schema );
check( is_wp_error( $error ) && $error->get_error_message() === 'ACF validation failed for hero_title', 'A provider without a detailed message must keep the original fallback.' );

$acf_diagnostic_failure = null;
$before = $acf_diagnostic_errors;
$valid = Transport::validate_named_acf( [ 'hero_title' => 'Valid heading' ], $acf_schema );
check( ! is_wp_error( $valid ) && $valid['hero_title']['value'] === 'Valid heading', 'Earlier errors must not reject a valid current field.' );
check( $acf_diagnostic_errors === $before, 'Successful validation must leave existing error state untouched.' );

$acf_conditional_native = true;
$toggle = af( 'header_mode', 'select', [ 'choices' => [ 'none' => 'None', 'banner' => 'Banner', 'overlay' => 'Overlay' ] ] );
$heading = af( 'heading', 'text', [ 'required' => true, 'conditional_logic' => [
    [ [ 'field' => $toggle['key'], 'operator' => '==', 'value' => 'banner' ] ],
    [ [ 'field' => $toggle['key'], 'operator' => '==', 'value' => 'overlay' ] ],
] ] );
$acf_schema = [ 'header_mode' => $toggle, 'heading' => $heading ];
$hidden = [ 'header_mode' => 'none', 'heading' => '' ];
$schema_before = $acf_schema;
$acf_validated_inputs = [];
$valid = Transport::validate_named_acf( $hidden, $acf_schema );
check( ! is_wp_error( $valid ) && $valid['heading']['value'] === '', 'A conditionally hidden required heading must remain empty in the retained value.' );
check( $valid['heading']['field'] === $heading && $acf_schema === $schema_before, 'Conditional validation must leave registered definitions and retained field keys unchanged.' );
check( ! in_array( 'acf.heading', $acf_validated_inputs, true ), 'Hidden fields must be omitted from native validation as in the ACF editor.' );
check( ! is_wp_error( Transport::validate_payload( [ 'acf' => $hidden ], 'page', 11 ) ), 'Editorial transport must honor the same conditional visibility.' );
foreach ( [ 'banner', 'overlay' ] as $mode ) {
    check( is_wp_error( Transport::validate_named_acf( [ 'header_mode' => $mode, 'heading' => '' ], $acf_schema ) ), 'Each visible branch must still require the heading.' );
}
check( is_wp_error( Transport::validate_named_acf( [ 'heading' => '' ], $acf_schema ) ), 'A partial update without the controlling value must retain required validation.' );
$unknown = $acf_schema;
$unknown['heading']['conditional_logic'][0][0]['operator'] = 'unsupported';
check( is_wp_error( Transport::validate_named_acf( $hidden, $unknown ) ), 'An unevaluable condition must retain required validation.' );
$text_toggle = af( 'mode' );
$text_heading = af( 'heading', 'text', [ 'required' => true, 'conditional_logic' => [ [ [ 'field' => $text_toggle['key'], 'operator' => '==', 'value' => 'show' ] ] ] ] );
check( is_wp_error( Transport::validate_named_acf( [ 'mode' => '<b>show</b>', 'heading' => '' ], [ 'mode' => $text_toggle, 'heading' => $text_heading ] ) ), 'Sanitizing a controller must not make a required field visible only after validation.' );

foreach ( [ 'group', 'repeater', 'flexible_content' ] as $type ) {
    $container = af( 'content', $type, [ 'sub_fields' => [ $toggle, $heading ] ] );
    $row = $hidden;
    if ( $type === 'flexible_content' ) {
        unset( $container['sub_fields'] );
        $container['layouts'] = [ [ 'key' => 'layout_header', 'name' => 'header', 'sub_fields' => [ $toggle, $heading ] ] ];
        $row['acf_fc_layout'] = 'header';
    }
    $rows = $type === 'group' ? $row : [ $row, array_replace( $row, [ 'header_mode' => 'banner', 'heading' => 'Visible heading' ] ) ];
    $valid = Transport::validate_named_acf( [ 'content' => $rows ], [ 'content' => $container ] );
    check( ! is_wp_error( $valid ), 'Nested conditional fields must validate for each ' . $type . ' row.' );
    $first = $type === 'group' ? $valid['content']['value'] : $valid['content']['value'][0];
    check( array_key_exists( $heading['key'], $first ) && $first[$heading['key']] === '', 'Hidden nested values must remain present for ' . $type . ' persistence.' );
    $row['header_mode'] = 'banner';
    $acf_diagnostic_errors = [];
    $invalid = Transport::validate_named_acf( [ 'content' => $type === 'group' ? $row : [ $row ] ], [ 'content' => $container ] );
    check( is_wp_error( $invalid ) && str_contains( $invalid->get_error_message(), 'required' ), 'Native nested required failures must propagate from ' . $type . ' callbacks.' );
}

$choice = af( 'choice', 'select', [ 'required' => true, 'choices' => [ 'a' => 'A' ] ] );
$details = af( 'details', 'group', [ 'sub_fields' => [ $choice ], 'conditional_logic' => $heading['conditional_logic'] ] );
$acf_schema = [ 'header_mode' => $toggle, 'details' => $details ];
$input = [ 'header_mode' => 'none', 'details' => [ 'choice' => '' ] ];
$valid = Transport::validate_named_acf( $input, $acf_schema );
check( ! is_wp_error( $valid ) && $valid['details']['value'][$choice['key']] === '', 'A hidden ancestor must permit an empty required select while retaining its value.' );
check( true === Nova_Bridge_Suite_Recipe_ACF::validate_constraints( array_values( $acf_schema ), $input, '/document', 0, Nova_Bridge_Suite_Recipe_ACF::conditions( $acf_schema ) ), 'Recipe constraints must skip the hidden subtree.' );
$input['header_mode'] = 'banner';
check( is_wp_error( Transport::validate_named_acf( $input, $acf_schema ) ), 'Making the parent visible must restore the child select requirement.' );

foreach ( [ 'group', 'repeater', 'flexible_content' ] as $type ) {
    $child = $heading;
    $container = af( 'details', $type, [ 'sub_fields' => [ $child ] ] );
    $row = [ 'heading' => '' ];
    if ( $type === 'flexible_content' ) {
        unset( $container['sub_fields'] );
        $container['layouts'] = [ [ 'key' => 'layout_ancestor', 'name' => 'ancestor', 'sub_fields' => [ $child ] ] ];
        $row['acf_fc_layout'] = 'ancestor';
    }
    $acf_schema = [ 'header_mode' => $toggle, 'details' => $container ];
    $input = [ 'header_mode' => 'none', 'details' => $type === 'group' ? $row : [ $row ] ];
    check( ! is_wp_error( Transport::validate_named_acf( $input, $acf_schema ) ), 'A nested ' . $type . ' condition may reference its ancestor field.' );
    check( true === Nova_Bridge_Suite_Recipe_ACF::validate_constraints( array_values( $acf_schema ), $input, '/document', 0, Nova_Bridge_Suite_Recipe_ACF::conditions( $acf_schema ) ), 'Constraint checking must share ancestor context for ' . $type . '.' );
    $input['header_mode'] = 'banner';
    check( is_wp_error( Transport::validate_named_acf( $input, $acf_schema ) ), 'The ancestor may make its ' . $type . ' descendant required again.' );
}

echo 'PASS ' . ( $checks - $baseline_checks ) . " ACF validation diagnostics assertions\n";
