'use strict';
const assert = require('node:assert/strict');
const canvas = require('../assets/recipe-canvas.js');
let checks = 0;
function check(value, message) { checks++; assert(value, message); }

// A small selector-aware element double: each element answers the selectors registered for it.
function element(tag, attrs = {}, css = {}, parent = null) {
  const node = {tagName: tag.toUpperCase(), attrs: {...attrs}, css, parentElement: parent, children: [], selectors: new Map(), excluded: false, properties: {}, textContent: attrs.text || '',
    style: {backgroundImage: '', setProperty(key, value) { node.properties[key] = value; }},
    getAttribute(key) { return Object.prototype.hasOwnProperty.call(node.attrs, key) ? node.attrs[key] : null; },
    setAttribute(key, value) { node.attrs[key] = String(value); }, removeAttribute(key) { delete node.attrs[key]; },
    closest(selector) { if (node.excluded && /nav|header|footer|hidden/.test(selector)) { return node; } return node.parentElement ? node.parentElement.closest(selector) : null; },
    matches(selector) { return (node.matchList || []).includes(selector); },
    contains(other) { for (let n = other; n; n = n.parentElement) { if (n === node) { return true; } } return false; },
    querySelectorAll(selector) { return node.selectors.get(selector) || []; },
    getBoundingClientRect: () => ({width: 1200, height: 800}), getClientRects: () => [1], clientHeight: 800, scrollHeight: 800};
  if (parent) { parent.children.push(node); }
  return node;
}
const html = element('html'), body = element('body', {}, {}, html), main = element('main', {}, {}, body);
const lazy = element('img', {src: 'data:image/svg+xml;charset=utf-8,%3Csvg%3E', 'data-nectar-img-src': 'https://site.test/wp-content/uploads/photo.jpg', 'data-nectar-img-srcset': 'https://site.test/a-600.jpg 600w, https://site.test/a-1200.jpg 1200w'}, {}, main);
const real = element('img', {src: 'https://site.test/real.jpg', 'data-src': 'https://site.test/other.jpg'}, {}, main);
const unsafe = element('img', {src: '', 'data-src': 'javascript:alert(1)'}, {}, main);
const box = element('div', {'data-nectar-img-src': 'https://site.test/wp-content/uploads/box.jpg'}, {}, main);
const animated = element('img', {class: 'img-with-animation'}, {opacity: '0', display: 'block'}, main);
const slide = element('div', {class: 'carousel-fade'}, {opacity: '0', display: 'block'}, main);
slide.closest = selector => /carousel/.test(selector) ? slide : null;
animated.closest = selector => /carousel|slider/.test(selector) ? body : null;
main.selectors.set('img', [lazy, real, unsafe, animated]);
main.querySelectorAll = selector => selector === 'img' ? [lazy, real, unsafe, animated] : /data-nectar-img-src/.test(selector) && selector.startsWith(':not(img)') ? [box] : /\[class\*="anim"\]/.test(selector) ? [animated, slide] : [];
const doc = {documentElement: html, body, baseURI: 'https://site.test/page/', querySelectorAll: () => [main]};
const win = {innerWidth: 1200, innerHeight: 800, getComputedStyle: node => ({display: 'block', visibility: 'visible', opacity: '1', position: 'static', ...node.css})};
canvas.prepareStaticLayout(win, doc);
check(lazy.attrs.src === 'https://site.test/wp-content/uploads/photo.jpg', 'A lazy-loaded image shows its real source');
check(lazy.attrs.srcset === 'https://site.test/a-600.jpg 600w, https://site.test/a-1200.jpg 1200w', 'A lazy-loaded responsive source set is restored');
check(real.attrs.src === 'https://site.test/real.jpg', 'An image that already has a real source is unchanged');
check(unsafe.attrs.src === '', 'Script URLs are never used as image sources');
check(box.properties['background-image'] === 'url("https://site.test/wp-content/uploads/box.jpg")', 'A lazy background image is shown');
check(animated.properties.opacity === '1', 'Content hidden for an entrance animation is shown');
check(slide.properties.opacity === undefined, 'Hidden carousel slides keep their state');
check(canvas.prepareStaticLayout(win, doc) === 0, 'Preparing twice changes nothing');

// Shortcode builders: the server-rendered marker identifies exactly one element.
const config = {referenceId: 12, referenceType: 'post'};
const marker = 'a'.repeat(32), owner = element('div', {'data-nova-preview-node': marker}), image = element('img', {}, {}, owner);
const shortcodeDoc = {querySelectorAll: selector => selector === '[data-nova-preview-node~="' + marker + '"]' ? [owner] : []};
image.closest = selector => selector === '[data-nova-preview-node]' ? owner : null;
owner.selectors.set('img', [image]);
const item = kind => ({locator: {strategy: 'shortcode', marker, kind, reference_id: 12}});
check(canvas.locate(shortcodeDoc, item('image_url'), config).node === image && canvas.locate(shortcodeDoc, item('image_url'), config).precision === 'field', 'A WPBakery image field outlines its image');
check(canvas.locate(shortcodeDoc, item('body'), config).node === owner, 'Other fields outline their element');
check(canvas.locate(shortcodeDoc, {locator: {strategy: 'shortcode', marker: 'x"],body[x="', kind: 'body', reference_id: 12}}, config) === null, 'Malformed markers never become selectors');
const twice = {querySelectorAll: () => [owner, element('div')]};
check(canvas.locate(twice, item('body'), config) === null, 'A marker rendered twice is never guessed');

// Native title: the theme's own H1 is found by its exact text when no title wrapper exists.
const h1 = element('h1', {text: 'About   us'}), other = element('h1', {text: 'Something else'});
h1.textContent = 'About   us'; other.textContent = 'Something else';
const titleDoc = {querySelectorAll: selector => selector === 'h1' ? [h1, other] : []};
const title = {current_value: 'About us', locator: {strategy: 'core', control_path: ['title'], reference_id: 12}};
check(canvas.locate(titleDoc, title, config).node === h1, 'The native title outlines the one matching H1');
check(canvas.locate({querySelectorAll: selector => selector === 'h1' ? [h1, h1] : []}, title, config) === null, 'Ambiguous headings are not guessed');
const normalized = canvas.normalizeItems([{field_id: 'field_1', target: '/document/0/text', label: 'Text', locator: {strategy: 'shortcode', marker, reference_id: 12}, omitted: true}], {...config}, false);
check(normalized[0].omitted === true, 'Removed elements are marked in the canvas');
// Wrappers made transparent by the same disabled scripts, visibility-hidden animations, and empty image layers.
{
  const html2 = element('html'), body2 = element('body', {class: 'has-slider-styles'}, {}, html2), main2 = element('main', {}, {}, body2);
  const wrapper = element('div', {class: 'hover-wrap'}, {opacity: '0', display: 'block'}, main2), image = element('img', {class: 'img-with-animation', src: 'https://site.test/real.jpg'}, {opacity: '0', display: 'block'}, wrapper);
  const title = element('div', {'data-animationtype': 'fadeInLeft'}, {visibility: 'hidden', display: 'block'}, main2);
  const box = element('div', {}, {display: 'block'}, main2); box.getBoundingClientRect = () => ({width: 400, height: 600});
  const layer = element('div', {'data-img': 'https://site.test/layer.jpg'}, {display: 'block'}, box); layer.getBoundingClientRect = () => ({width: 400, height: 0});
  for (const node of [wrapper, image, title]) { node.closest = selector => /slider/.test(selector) ? body2 : null; }
  main2.querySelectorAll = selector => selector === 'img' ? [image] : selector.startsWith(':not(img)') ? [layer] : /data-animationtype/.test(selector) ? [image, title] : [];
  canvas.prepareStaticLayout(win, {documentElement: html2, body: body2, baseURI: 'https://site.test/', querySelectorAll: () => [main2]});
  check(image.properties.opacity === '1' && wrapper.properties.opacity === '1', 'A transparent animation wrapper is shown with its element, despite a "slider" body class');
  check(title.properties.visibility === 'visible', 'Visibility-hidden entrance animations are shown');
  check(box.properties['background-image'] === 'url("https://site.test/layer.jpg")' && layer.properties['background-image'] === undefined, 'An empty image layer paints its sized container');
}
console.log('PASS recipe canvas builder checks: ' + checks);
