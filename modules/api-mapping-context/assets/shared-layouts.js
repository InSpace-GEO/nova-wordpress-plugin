(function () {
    'use strict';
    function element(tag, text) {
        const item = document.createElement(tag);
        if (text !== undefined) item.textContent = text;
        return item;
    }
    function button(label) {
        const item = element('button', label);
        item.type = 'button';
        item.className = 'button';
        return item;
    }
    function mount(host, config) {
        const panel = host.querySelector('[data-shared-layouts-panel]');
        const notice = element('p');
        notice.setAttribute('role', 'status');
        const choices = element('div');
        const download = button('Export selected layouts');
        const file = element('input');
        file.type = 'file';
        file.accept = '.json,application/json';
        const pick = element('label', 'NOVA shared layout file ');
        pick.append(file);
        const upload = button('Import layouts');
        const unresolved = element('div');
        const selectInfo = element('p', 'The newest approved revision of each template is selected. Include earlier revisions for deliveries that are already waiting.');
        panel.append(element('h3', 'Export from this installation'), selectInfo, choices, download,
            element('h3', 'Import on this installation'),
            element('p', 'Importing makes this installation receive-only for this NOVA connection. The file takes precedence for the template IDs and revisions it contains. All references are checked before the revisions become available.'),
            pick, unresolved, upload, notice);
        let busy = false;
        let ready = false;
        let selected = [];
        let resolutions = Object.create(null);
        let countIssues = 0;

        function controls() {
            download.disabled = busy || !selected.some(row => row.check.checked);
            upload.disabled = busy || !file.files.length || countIssues > 0;
            file.disabled = busy;
        }
        async function request(route, body) {
            const options = {credentials: 'same-origin', headers: {'X-WP-Nonce': config.nonce}};
            if (body !== undefined) {
                options.method = 'POST';
                options.headers['Content-Type'] = 'application/json';
                options.body = body;
            }
            const response = await fetch(config.url + '/' + route, options);
            if (response.ok) return response;
            const problem = await response.json().catch(() => ({}));
            const error = new Error(problem.message || 'WordPress could not complete this request.');
            error.details = problem.data;
            throw error;
        }
        function dependencyChoices(details) {
            if (!details || typeof details.version_key !== 'string' || !Array.isArray(details.issues)) return;
            const key = details.version_key;
            resolutions[key] = resolutions[key] || Object.create(null);
            unresolved.replaceChildren(element('h4', 'Resolve references on this installation'));
            const inputs = [];
            function update() {
                countIssues = inputs.some(input => !input.validity.valid) ? 1 : 0;
                controls();
            }
            for (const issue of details.issues) {
                const row = element('p');
                row.append(element('strong', issue.label || issue.key), element('br'), element('span', issue.message || 'This layout reference needs review.'));
                if (issue.remappable) {
                    const label = element('label', ' Local WordPress ID ');
                    const input = element('input');
                    input.type = 'number'; input.min = '1'; input.step = '1';
                    input.value = resolutions[key][issue.key] || issue.suggested_id || '';
                    function changed() {
                        if (input.value && input.validity.valid) resolutions[key][issue.key] = Number(input.value);
                        else delete resolutions[key][issue.key];
                        update();
                    }
                    label.append(input); row.append(element('br'), label);
                    inputs.push(input); input.addEventListener('input', changed); changed();
                } else {
                    // The administrator may fix a dependency elsewhere and retry this same file.
                    row.append(element('span', ' Correct the dependency and retry the import.'));
                }
                unresolved.append(row);
            }
            update();
        }
        async function act(operation) {
            if (busy) return;
            busy = true; controls(); notice.textContent = 'Working…';
            try { await operation(); }
            catch (error) { notice.textContent = error.message; dependencyChoices(error.details); }
            finally { busy = false; controls(); }
        }
        async function load() {
            const data = await (await request('catalog')).json();
            selected = [];
            choices.replaceChildren();
            const newest = new Map();
            for (const row of data.templates) newest.set(row.template_id, Math.max(newest.get(row.template_id) || 0, row.revision));
            for (const row of data.templates) {
                const check = element('input'); check.type = 'checkbox'; check.checked = newest.get(row.template_id) === row.revision;
                check.addEventListener('change', controls);
                selected.push({check, id: row.template_id, revision: row.revision});
                const label = element('label');
                const name = [row.label || row.page_type, row.resource_id, 'revision ' + row.revision].filter(Boolean).join(' · ');
                label.append(check, document.createTextNode(' ' + name + (row.origin === 'imported' ? ' (imported)' : '')));
                const line = element('div'); line.append(label); choices.append(line);
            }
            if (!selected.length) choices.append(element('p', 'No approved layouts available to export. You can still import a file from another installation.'));
            selectInfo.hidden = !selected.length;
            ready = true; controls();
        }
        host.addEventListener('toggle', () => {
            if (host.open && !ready) act(async () => { await load(); notice.textContent = ''; });
        });
        download.addEventListener('click', () => act(async () => {
            const versions = selected.filter(row => row.check.checked).map(row => ({template_id: row.id, revision: row.revision}));
            const blob = await (await request('export', JSON.stringify({versions}))).blob();
            const url = URL.createObjectURL(blob);
            const link = element('a'); link.href = url; link.download = 'nova-shared-layouts.json';
            panel.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
            notice.textContent = 'Exported ' + versions.length + (versions.length === 1 ? ' layout revision.' : ' layout revisions.');
        }));
        file.addEventListener('change', () => {
            resolutions = Object.create(null); unresolved.replaceChildren(); countIssues = 0; notice.textContent = ''; controls();
        });
        upload.addEventListener('click', () => act(async () => {
            const chosen = file.files[0];
            if (!chosen || chosen.size > config.maxBytes) throw new Error('Choose a NOVA shared layout file of up to 8 MiB.');
            const json = await chosen.text();
            try { JSON.parse(json); } catch (err) { throw new Error('This file does not contain valid JSON. Choose a NOVA shared layout export.'); }
            // Keep the original JSON bytes, including numbers in native builder settings.
            const payload = '{"file":' + json + ',"resolutions":' + JSON.stringify(resolutions) + '}';
            const result = await (await request('import', payload)).json();
            unresolved.replaceChildren(); countIssues = 0; resolutions = Object.create(null);
            file.value = '';
            notice.textContent = result.imported + ' imported, ' + (result.replaced || 0) + ' replaced, ' + result.unchanged + ' already available. These revisions are ready for deliveries that name them.';
            const cards = document.getElementById('nova-endpoint-mappings-app');
            if (cards) cards.dispatchEvent(new Event('nova-shared-layouts-changed'));
            await load();
        }));
        controls();
        if (window.location.hash === '#' + host.id) host.open = true;
        if (host.open) act(async () => { await load(); notice.textContent = ''; });
    }
    function start() {
        const host = document.getElementById('nova-shared-layouts');
        if (host && window.NovaSharedLayouts) mount(host, window.NovaSharedLayouts);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
