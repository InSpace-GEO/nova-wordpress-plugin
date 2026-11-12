'use strict';
// ACF fields are found by their value: exactly one element in the main content, never a guess.
const assert = require('node:assert/strict');
const canvas = require('../assets/recipe-canvas.js');
let checks = 0;
function check(value, message) { checks++; assert(value, message); }

function element(tag, attrs = {}, parent = null, text = '') {
  const node = {tagName: tag.toUpperCase(), attrs, parentElement: parent, children: [], ownText: text, excluded: attrs.excluded || null,
    get textContent() { return node.ownText + node.children.map(child => child.textContent).join(''); },
    getAttribute(key) { return Object.prototype.hasOwnProperty.call(node.attrs, key) ? node.attrs[key] : null; },
    closest(selector) { for (let n = node; n; n = n.parentElement) { if (n.excluded && selector.includes(n.excluded)) { return n; } } return null; },
    contains(other) { for (let n = other; n; n = n.parentElement) { if (n === node) { return true; } } return false; },
    querySelectorAll(selector) { const out = []; const walk = n => n.children.forEach(child => { if (selector === 'img,[style*="background-image"]' ? child.tagName === 'IMG' || /background-image/.test(child.attrs.style || '') : !/^(HTML|BODY)$/.test(child.tagName)) { out.push(child); } walk(child); }); walk(node); return out; }};
  if (parent) { parent.children.push(node); }
  return node;
}
function page() {
  const body = element('body');
  const header = element('header', {excluded: 'header'}, body); element('h2', {}, header, 'Example category');
  const main = element('main', {class: 'main'}, body);
  // The theme leaves its breadcrumb section unclosed, so the browser nests the page content inside it.
  const crumbs = element('section', {class: 'breadcrumbs'}, main); const trail = element('ul', {class: 'breadcrumbs__lists', excluded: 'ul[class*="breadcrumb"]'}, crumbs); element('li', {}, trail, 'Example category');
  const intro = element('section', {class: 'intro'}, crumbs);
  element('img', {src: 'https://site.test/wp-content/uploads/Category-photo-scaled.jpg', alt: 'x'}, intro);
  const text = element('div', {}, intro); element('h2', {class: 'title'}, text, 'Example category'); element('p', {}, text, 'Example  category\n intro.');
  const wide = element('section', {class: 'content-wide wysiwyg'}, main); element('h2', {}, wide, 'Example types'); element('p', {}, wide, 'Example overview.');
  const card1 = element('article', {}, main); element('img', {src: 'https://site.test/wp-content/uploads/First-1500x1125.jpeg', srcset: 'https://site.test/wp-content/uploads/First-300x200.jpeg 300w'}, card1); element('h3', {}, card1, 'First product'); element('a', {href: '/x/'}, card1, 'More about it');
  const card2 = element('article', {}, main); element('h3', {}, card2, 'Second product'); element('a', {href: '/y/'}, card2, 'More about it');
  const split = element('div', {style: "background-image: url('https://site.test/wp-content/uploads/Wide-photo-scaled.jpg');"}, main);
  const document = {body, querySelectorAll: selector => selector === 'main,[role="main"]' ? [main] : []};
  return {document, main, text, wide, card1, split};
}
const config = {referenceId: 12};
const field = locator => ({locator: Object.assign({strategy: 'value_match', node_id: 'acf', reference_id: 12}, locator)});
const {document, text, wide, card1, split, main} = page();

let hit = canvas.locate(document, field({text: 'Example category', precision: 'field'}), config);
check(hit && hit.node === text.children[0] && hit.precision === 'field', 'A heading repeated in breadcrumbs and the site header still resolves to the content heading');
check(canvas.locate(document, field({text: 'Example category intro.'}), config).node === text.children[1], 'Whitespace in the theme markup does not matter');
hit = canvas.locate(document, field({text: 'Example types Example overview.', precision: 'region'}), config);
check(hit && hit.node === wide && hit.precision === 'region', 'A multi-paragraph editor field outlines its region');
check(canvas.locate(document, field({text: 'More about it'}), config) === null, 'Repeated button labels are never guessed');
check(canvas.locate(document, field({text: 'Not on this page'}), config) === null, 'A value the theme does not print stays unbound');
check(canvas.locate(document, field({text: 'ab'}), config) === null, 'Very short values are not matched');
check(canvas.locate(document, field({media: 'https://site.test/wp-content/uploads/First.jpeg'}), config).node === card1.children[0], 'An image matches its media file in another generated size');
check(canvas.locate(document, field({media: 'https://site.test/wp-content/uploads/Category-photo.jpg'}), config).node.tagName === 'IMG', 'A scaled original matches its upload');
check(canvas.locate(document, field({media: 'https://site.test/wp-content/uploads/Wide-photo.jpg'}), config).node === split, 'A background image set by the theme matches');
check(canvas.locate(document, field({media: 'https://site.test/wp-content/uploads/Missing.jpg'}), config) === null, 'An image that is not shown stays unbound');
check(canvas.locate(document, field({media: 'javascript:alert(1)'}), config) === null, 'Non-image values never match');
element('img', {src: 'https://site.test/wp-content/uploads/First-768x576.jpeg'}, main);
check(canvas.locate(document, field({media: 'https://site.test/wp-content/uploads/First.jpeg'}), config) === null, 'An image shown twice is never guessed');
check(canvas.locate(document, {locator: {strategy: 'value_match', text: 'First product', reference_id: 99}}, config) === null, 'Another reference never binds');
const curly = String.fromCharCode(0x2019), dash = String.fromCharCode(0x2013);
const quoted = element('p', {}, main, 'Our pergola' + curly + 's ' + dash + ' made to measure');
check(canvas.locate(document, field({text: "Our pergola's - made to measure"}), config).node === quoted, 'Curly quotes and dashes added by WordPress still match the stored text');
console.log(`PASS ${checks} ACF value-match canvas checks`);
