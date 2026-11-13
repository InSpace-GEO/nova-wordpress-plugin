<?php
/** Native nested FAQ titles, paired optional rows and existing fixed controls. */
require_once __DIR__ . '/recipe-engine-unit.php';
use Nova_Bridge_Suite_Recipe_Engine as Engine;
$baseline_checks = $checks;
$faq = widget( 'faq', 'nested-accordion', [
    'items' => [
        [ '_id' => 'first', 'item_title' => 'First question', 'element_css_id' => 'first-question' ],
        [ '_id' => 'second', 'item_title' => 'Second question', 'element_css_id' => '' ],
    ],
    'default_state' => 'expanded', 'faq_schema' => 'yes',
] );
$faq['elements'] = [
    container( 'first-panel', [ container( 'first-inner', [ widget( 'first-answer', 'text-editor', [ 'editor' => '<p>First answer</p>' ] ) ] ) ] ),
    container( 'second-panel', [ widget( 'second-answer', 'text-editor', [ 'editor' => '<p>Second answer</p>' ] ) ] ),
];
$faq_native = [ 'document' => [ $faq, widget( 'list', 'icon-list', [ 'icon_list' => [ [ '_id' => 'icon-first', 'text' => 'First benefit', 'link' => [ 'url' => '/benefit', 'is_external' => '' ], 'selected_icon' => [ 'value' => 'fas fa-check', 'library' => 'fa-solid' ] ] ] ] ) ] ];
$faq_fields = array_column( good( $adapter->describe( $faq_native ), 'Describe nested FAQ' )['fields'], null, 'target' );
$question_target = '/document/0/settings/items/0/item_title';
check( isset( $faq_fields[$question_target] ) && $faq_fields[$question_target]['writable'], 'Nested FAQ question is discoverable and writable' );
check( $faq_fields[$question_target]['node_target'] === '/document/0/elements/0', 'Question and answer belong to the same optional native panel' );
check( $faq_fields['/document/1/settings/icon_list/0/text']['writable'], 'Icon-list text is writable' );
check( $faq_fields['/document/1/settings/icon_list/0/link/url']['writable'], 'Icon-list link is writable' );
bad( $adapter->validate_binding( $faq_native, binding( '/document/0/settings/items/0/element_css_id', '/id' ) ), 'FAQ HTML identity remains protected' );
bad( $adapter->validate_binding( $faq_native, binding( '/document/1/settings/icon_list/0/selected_icon/value', '/icon' ) ), 'Icon styling remains protected' );
$changed = good( $adapter->bind( $faq_native, binding( $question_target, '/question', 'text' ), 'New <b>question</b>' ), 'Bind question text' );
check( $changed['document'][0]['settings']['items'][0]['item_title'] === 'New &lt;b&gt;question&lt;/b&gt;', 'Plain question text is escaped' );
check( $changed['document'][0]['settings']['items'][0]['element_css_id'] === 'first-question' && $changed['document'][0]['elements'] === $faq['elements'], 'Question edits preserve identity and answer trees' );
$dynamic = $faq_native; $dynamic['document'][0]['settings']['items'][0]['__dynamic__']['item_title'] = 'provider tag';
bad( $adapter->validate_binding( $dynamic, binding( $question_target, '/question' ) ), 'Dynamic FAQ titles remain protected' );
$invalid = $faq_native; array_pop( $invalid['document'][0]['elements'] );
bad( $adapter->validate_blueprint( $invalid ), 'Mismatched questions and panels cannot compile' );
$defaults = $faq_native; unset( $defaults['document'][0]['settings']['items'] );
good( $adapter->validate_blueprint( $defaults ), 'Unmapped accordion titles inherited from Elementor defaults remain valid' );
$faq_bindings = [
    binding( $question_target, '/question_one', 'text', false ),
    binding( '/document/0/elements/0/elements/0/elements/0/settings/editor', '/answer_one', 'html', false ),
    binding( '/document/0/settings/items/1/item_title', '/question_two', 'text', false ),
    binding( '/document/0/elements/1/elements/0/settings/editor', '/answer_two', 'html', false ),
];
$faq_recipe = recipe( $faq_native, $faq_bindings, [], 'static' );
$second_only = good( Engine::compile( $faq_recipe, [ 'question_two' => 'Retained question', 'answer_two' => '<p>Retained answer</p>' ], 'optional-faq' ), 'Omit an empty FAQ pair' );
$retained = $second_only['native_document']['document'][0];
check( count( $retained['settings']['items'] ) === 1 && count( $retained['elements'] ) === 1, 'Optional row removes its title and panel together' );
check( $retained['settings']['items'][0]['item_title'] === 'Retained question' && $retained['elements'][0]['elements'][0]['settings']['editor'] === '<p>Retained answer</p>', 'Remaining FAQ pairing is preserved' );
check( strpos( $second_only['preview_html'], 'Retained question' ) !== false && strpos( $second_only['preview_html'], 'Retained answer' ) !== false, 'Structure preview includes the paired title and answer' );
$question_only = good( Engine::compile( $faq_recipe, [ 'question_one' => 'Keep this title' ], 'question-only' ), 'Retain a supplied question when its optional answer is absent' );
check( $question_only['native_document']['document'][0]['settings']['items'][0]['item_title'] === 'Keep this title', 'A supplied parent question prevents vacant child pruning from deleting its panel' );
$none = good( Engine::compile( $faq_recipe, [], 'no-faqs' ), 'Omit all empty FAQ pairs' );
check( count( $none['native_document']['document'] ) === 1 && $none['native_document']['document'][0]['widgetType'] === 'icon-list', 'Empty FAQ disappears and unrelated list remains' );
$repeating = region( 'faqs', '/document/0/elements', 0, 2, '/faqs', [ $faq['elements'][1] ], [ binding( '/0/elements/0/settings/editor', '/answer_html', 'html', true ) ] );
$repeating['prototype_start'] = 1; $repeating['prototype_count'] = 1;
$repeat_recipe = recipe( $faq_native, [], [ $repeating ], 'mixed' );
$repeated = good( Engine::compile( $repeat_recipe, [ 'faqs' => [ [ 'question' => 'A?', 'answer' => '<p>A.</p>' ], [ 'question' => 'B?', 'answer' => '<p>B.</p>' ], [ 'question' => 'C?', 'answer' => '<p>C.</p>' ] ] ], 'repeat-faq' ), 'Repeat nested FAQ panels' );
check( array_column( $repeated['native_document']['document'][0]['settings']['items'], 'item_title' ) === [ 'A?', 'B?', 'C?' ], 'Repeated question titles follow their corresponding answers' );
$overlap = $repeat_recipe; $overlap['bindings'] = [ binding( $question_target, '/question' ) ];
bad( Engine::validate( $overlap ), 'Fixed questions cannot overlap generated FAQ panels' );
echo 'PASS nested FAQ mapping: ' . ( $checks - $baseline_checks ) . " checks\n";
