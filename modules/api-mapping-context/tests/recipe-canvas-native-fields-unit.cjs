'use strict';
// A singular template has a different post ID and generic modules. Related cards repeat its native fields.
const assert = require('node:assert/strict');
const canvas = require('../assets/recipe-canvas.js');
let checks = 0;
function check(value, message) { checks++; assert(value, message); }
function element(tag, attrs = {}, parent = null, text = '') {
  const node = {tagName: tag.toUpperCase(), attrs, parentElement: parent, children: [], ownText: text,
    get textContent() { return node.ownText + node.children.map(child => child.textContent).join(''); },
    getAttribute(key) { return Object.prototype.hasOwnProperty.call(attrs, key) ? attrs[key] : null; },
    closest(selector) { for (let n = node; n; n = n.parentElement) { if (selector.split(',').some(s => s === n.tagName.toLowerCase() || (s.startsWith('.') && String(n.attrs.class || '').split(' ').includes(s.slice(1))) || s === `[role="${n.attrs.role}"]` || (s === '[hidden]' && n.attrs.hidden))) { return n; } } return null; },
    contains(other) { for (let n = other; n; n = n.parentElement) { if (n === node) { return true; } } return false; },
    getClientRects() { for (let n = node; n; n = n.parentElement) { if (n.attrs.hidden) { return []; } } return [1]; },
    querySelectorAll(selector) { const out = []; const walk = n => n.children.forEach(child => { if (selector === 'img,[style*="background-image"]' ? child.tagName === 'IMG' || /background-image/.test(child.attrs.style || '') : selector.split(',').includes(child.tagName.toLowerCase())) { out.push(child); } walk(child); }); walk(node); return out; }};
  if (parent) { parent.children.push(node); }
  return node;
}
const excerpt = 'Contant geld brengt risico’s met zich mee. Ontdek hoe je ze beperkt.';
const media = 'https://site.test/wp-content/uploads/2026/11/cash-risks.jpg';
function page() {
  const body = element('body'), siteHeader = element('header', {}, body), main = element('main', {}, body);
  element('p', {}, siteHeader, excerpt); element('img', {src: media, class: 'wp-image-3537'}, siteHeader);
  const template = element('div', {class: 'fl-builder-content fl-builder-content-2206', 'data-post-id': '2206'}, main);
  const header = element('header', {class: 'entry-header'}, template), intro = element('div', {class: 'fl-module fl-module-rich-text'}, header);
  const text = element('p', {}, intro, excerpt);
  const photo = element('div', {class: 'fl-module fl-module-photo'}, template), image = element('img', {src: media.replace('.jpg', '-1024x683.jpg'), class: 'fl-photo-img wp-image-3537'}, photo);
  const related = element('div', {class: 'pp-content-post'}, template);
  element('p', {class: 'post-excerpt'}, related, excerpt); element('img', {src: media, class: 'wp-post-image wp-image-3537'}, related);
  const document = {body, baseURI: 'https://site.test/article/', defaultView: {getComputedStyle: () => ({display: 'block', visibility: 'visible', opacity: '1'})}, querySelectorAll: selector => selector === 'main,[role="main"]' ? [main] : body.querySelectorAll(selector)};
  return {document, main, template, text, image, photo, related};
}
const config = {referenceId: 3538, referenceType: 'post'};
const field = (control, locator = {}) => ({locator: {strategy: 'core', control_path: [control], reference_id: 3538, ...locator}});
const introField = field('excerpt', {text: excerpt.replace('risico’s', "risico's")});
const imageField = field('featured_image', {media, attachment_id: 3537});
let p = page();
check(canvas.locate(p.document, introField, config).node === p.text, 'The native excerpt matches a generic Themer module, including curled quotes, while the repeated card is excluded');
check(canvas.locate(p.document, imageField, config).node === p.image, 'The native featured image matches the singular template, not its related-card wp-post-image duplicate');
check(canvas.locate(p.document, introField, {...config, referenceType: 'term'}) === null, 'Post excerpt cannot bind a term preview');
check(canvas.locate(p.document, {...imageField, locator: {...imageField.locator, reference_id: 999}}, config) === null, 'A different source reference cannot bind');
check(canvas.locate(p.document, field('excerpt', {text: ''}), config) === null, 'An absent manual excerpt does not guess an auto excerpt');
check(canvas.locate(p.document, field('excerpt', {text: 'ab'}), config) === null, 'A very short excerpt stays in the field list');
check(canvas.locate(p.document, field('featured_image'), config) === null, 'A generic wp-post-image alone is not proof of a featured-image identity');
p.text.ownText = 'Another excerpt'; p.image.attrs.class = 'wp-image-987';
check(canvas.locate(p.document, introField, config) === null, 'When only a related card has the excerpt no wrong outline is shown');
check(canvas.locate(p.document, imageField, config) === null, 'A conflicting attachment ID and a related copy never bind');
p.text.parentElement.children = [];
check(canvas.locate(p.document, introField, config) === null, 'An unclassified template wrapper cannot inherit its only related card excerpt');
p = page(); delete p.image.attrs.class;
check(canvas.locate(p.document, imageField, config).node === p.image, 'A generated image size matches the exact attachment URL when the theme omits its ID class');
p.image.attrs.src = media.replace('/2026/11/', '/2025/06/');
check(canvas.locate(p.document, imageField, config) === null, 'The same filename in another upload directory is not the same media');
p.image.attrs.src = media.replace('site.test', 'another.test');
check(canvas.locate(p.document, imageField, config) === null, 'The same path on an unrelated host is not guessed');
p.image.attrs.src = 'data:image/gif;base64,blank'; p.image.attrs['data-lazy-srcset'] = `${media.replace('.jpg', '-768x512.jpg')} 768w`;
check(canvas.locate(p.document, imageField, config).node === p.image, 'Lazy responsive image sources retain attachment identity');
p.image.attrs.class = 'wp-image-3537'; delete p.image.attrs['data-lazy-srcset']; p.image.attrs.src = 'https://cdn.test/rewritten-image.avif';
check(canvas.locate(p.document, imageField, config).node === p.image, 'A native attachment class can identify media rewritten by a CDN');
p = page(); element('p', {}, p.main, excerpt); element('img', {src: media}, p.main);
check(canvas.locate(p.document, introField, config) === null, 'Two visible singular excerpts are ambiguous');
check(canvas.locate(p.document, imageField, config) === null, 'Two visible singular featured images are ambiguous');
p.main.children.at(-1).attrs.hidden = true; p.main.children.at(-2).attrs.hidden = true;
check(canvas.locate(p.document, introField, config).node === p.text && canvas.locate(p.document, imageField, config).node === p.image, 'Hidden responsive copies do not compete with the visible fields');
p = page(); p.photo.children = []; const background = element('div', {style: `background-image: url('${media}')`}, p.photo);
check(canvas.locate(p.document, imageField, config).node === background, 'A featured image rendered as an inline background can be located by exact URL');
console.log(`PASS ${checks} native template field preview checks`);
