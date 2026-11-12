const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const php = fs.readFileSync(path.join(__dirname, '../../api-mapping-context/includes/class-nova-bridge-suite-posting-settings.php'), 'utf8');
const method = php.slice(php.indexOf('private static function render_sync_dialog'), php.indexOf('public static function render():'));
const script = method.match(/<<<'JS'\r?\n([\s\S]*?)\r?\nJS;/)[1];

function fixture() {
    let click;
    const flag = { value: '0' };
    const form = { submits: 0, querySelector: () => flag, requestSubmit() { this.submits++; } };
    const dialog = { shown: 0, closed: 0, showModal() { this.shown++; }, close() { this.closed++; }, addEventListener(name, callback) { click = callback; } };
    vm.runInNewContext(script, { document: { getElementById: id => id === 'nova-connection-form' ? form : dialog } });
    return { form, dialog, flag, choose(value) { click({ target: { getAttribute: () => value } }); } };
}

test('post-save prompt displays once without submitting', () => {
    const f = fixture();
    assert.equal(f.dialog.shown, 1);
    assert.equal(f.form.submits, 0);
    assert.equal(f.flag.value, '0');
});
test('Later closes the prompt without synchronization', () => {
    const f = fixture(); f.choose('later');
    assert.equal(f.dialog.closed, 1);
    assert.equal(f.form.submits, 0);
    assert.equal(f.flag.value, '0');
});
test('Synchronize now explicitly submits the saved connection', () => {
    const f = fixture(); f.choose('sync');
    assert.equal(f.dialog.closed, 1);
    assert.equal(f.flag.value, '1');
    assert.equal(f.form.submits, 1);
});
test('absent dialog does not submit or crash', () => {
    assert.doesNotThrow(() => vm.runInNewContext(script, { document: { getElementById: () => null } }));
});
