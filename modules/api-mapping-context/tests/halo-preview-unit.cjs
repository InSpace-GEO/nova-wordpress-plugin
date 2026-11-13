'use strict';
const assert = require('node:assert/strict');
const test = require('node:test');
const ui = require('../assets/layout-recipes.js');

function fixture(request) {
    const retained = [];
    const controller = ui.createController({
        request,
        origin: 'https://example.test',
        rememberDraft: value => retained.push(value),
        forgetDraft: () => retained.push('cleared'),
    });
    Object.assign(controller.state, {
        id: '11111111-1111-4111-8111-111111111111',
        revision: 2,
        resourceId: 'post_type:post',
        label: 'Article',
        design: {seal: 'sealed', fields: []},
        recipe: {builder: 'core', mode: 'static', blueprint: {}, bindings: [], regions: []},
        content: {halo: {article_faq_questions: [{question: 'Question?', answer: '<p>Answer</p>'}], display_intake_form_boolean: false}},
    });
    controller.state.baseline = ui.fingerprint(controller.state);
    return {controller, retained};
}

test('HALO preview sends native typed sample values through the normal preview endpoint', async () => {
    let received;
    const {controller} = fixture(async (path, payload) => {
        assert.equal(path, '/preview');
        received = payload;
        return {preview_document: '<html><body>Sample</body></html>'};
    });
    await controller.previewHalo();
    assert.equal(controller.state.error, false);
    assert.equal(received.input.content.halo.display_intake_form_boolean, false);
    assert.deepEqual(received.input.content.halo.article_faq_questions, [{question: 'Question?', answer: '<p>Answer</p>'}]);
    assert.match(controller.state.haloPreview, /Sample/);
    controller.edit(() => { controller.state.content.halo.article_faq_questions = []; }, false);
    assert.equal(controller.state.haloPreview, null);
});

test('HALO draft retries retain the original operation and payload after response loss', async () => {
    const calls = [];
    const operation = '22222222-2222-4222-8222-222222222222';
    const {controller, retained} = fixture(async (path, payload) => {
        assert.equal(path, '/draft');
        calls.push(structuredClone(payload));
        if (calls.length === 1) { throw new Error('Response lost'); }
        return {id: 42, status: 'draft', operation_id: operation, view_url: 'https://example.test/?p=42&preview=true'};
    });
    await controller.createHaloDraft(operation);
    assert.equal(controller.state.error, true);
    await controller.createHaloDraft('33333333-3333-4333-8333-333333333333');
    assert.deepEqual(calls[0], calls[1]);
    assert.equal(controller.state.reviewDraft.id, 42);
    assert.equal(controller.state.pendingReview, null);
    assert.equal(retained.at(-1), 'cleared');
});

test('unsaved HALO mapping changes cannot create a draft from an older revision', async () => {
    let calls = 0;
    const {controller} = fixture(async () => { calls += 1; });
    controller.state.recipe.instructions = 'Changed mapping';
    await controller.createHaloDraft('22222222-2222-4222-8222-222222222222');
    assert.equal(calls, 0);
    assert.equal(controller.state.error, true);
});

test('successful recovery releases the old payload while failed recovery retains it', async () => {
    const oldOperation = '22222222-2222-4222-8222-222222222222';
    const newOperation = '33333333-3333-4333-8333-333333333333';
    let recoveryFails = true;
    let latest;
    const {controller} = fixture(async (path, payload) => {
        if (path.startsWith('/draft?')) {
            if (recoveryFails) { throw new Error('Temporary failure'); }
            return {id: 42, status: 'draft', operation_id: oldOperation, view_url: 'https://example.test/?p=42&preview=true'};
        }
        latest = structuredClone(payload);
        if (payload.operation_id === oldOperation) { throw new Error('Response lost'); }
        return {id: 43, status: 'draft', operation_id: newOperation, view_url: 'https://example.test/?p=43&preview=true'};
    });
    await controller.createHaloDraft(oldOperation);
    await controller.recoverDraft(oldOperation);
    assert.equal(controller.state.pendingReview.operation_id, oldOperation);
    recoveryFails = false;
    await controller.recoverDraft(oldOperation);
    assert.equal(controller.state.pendingReview, null);
    controller.state.content.halo.display_intake_form_boolean = true;
    await controller.createHaloDraft(newOperation);
    assert.equal(controller.state.error, false);
    assert.equal(latest.operation_id, newOperation);
    assert.equal(latest.content.halo.display_intake_form_boolean, true);
    assert.equal(controller.state.reviewDraft.id, 43);
});
