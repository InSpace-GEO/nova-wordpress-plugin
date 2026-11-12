'use strict';
// A post rendered through a Beaver Themer singular layout: no .entry-content or .entry-title, the
// title printed by a heading module (with WordPress's curly apostrophe) and the body by Themer's
// Post Content module. The stored title has a straight apostrophe.
const assert = require('node:assert/strict');
const canvas = require('../assets/recipe-canvas.js');
let checks = 0;
function check(value, message) { checks++; assert(value, message); }

function element(tag, cls, text = '', inside = null) {
  return {tagName: tag.toUpperCase(), className: cls, textContent: text, closest: selector => (inside && selector.split(',').some(part => part.trim() === inside) ? {} : null)};
}
function themerPost({headings, modules}) {
  return {baseURI: 'https://site.test/nl/post/',
    querySelectorAll(selector) { return selector === 'h1' ? headings : selector === '.fl-module-fl-post-content' ? modules : []; }};
}
const title = element('h1', 'fl-heading', '\n  Contant geld in de horeca: risico’s en oplossingen ');
const body = element('div', 'fl-module fl-module-fl-post-content fl-node-nxqy8rijt4cb');
const config = {referenceId: 3538, referenceType: 'post'};
const field = (control, value) => ({locator: {strategy: 'core', control_path: [control], reference_id: 3538}, current_value: value});
const doc = themerPost({headings: [title], modules: [body]});

const content = canvas.locate(doc, field('content', '<h2>Intro</h2>'), config);
check(content && content.node === body && content.precision === 'region', 'The post body is outlined in the Themer Post Content module');
check(canvas.locate(doc, field('title', "Contant geld in de horeca: risico's en oplossingen"), config).node === title, 'A stored straight apostrophe matches the curled title on the page');
check(canvas.locate(doc, field('title', 'Contant geld in de horeca: risico&#039;s en oplossingen'), config).node === title, 'An entity-encoded stored title matches');
check(canvas.locate(doc, field('title', 'Another post'), config) === null, 'A different title is not guessed');
const twice = themerPost({headings: [title, element('h1', 'fl-heading', 'Contant geld in de horeca: risico’s en oplossingen')], modules: [body, body]});
check(canvas.locate(twice, field('content', 'x'), config) === null, 'Two Post Content modules are never guessed');
check(canvas.locate(twice, field('title', "Contant geld in de horeca: risico's en oplossingen"), config) === null, 'Two matching headings are never guessed');
const footer = themerPost({headings: [element('h1', 'fl-heading', 'Contant geld in de horeca: risico’s en oplossingen', 'footer')], modules: []});
check(canvas.locate(footer, field('title', "Contant geld in de horeca: risico's en oplossingen"), config) === null, 'A matching heading in the footer is ignored');
console.log(`PASS ${checks} Beaver Themer preview checks`);
