'use strict';
// A post rendered through a Divi Theme Builder body template: no main, article or .entry-content,
// an entrance-animated section around the title and post content, and Divi's Post Content module.
const assert = require('node:assert/strict');
const canvas = require('../assets/recipe-canvas.js');
let checks = 0;
function check(value, message) { checks++; assert(value, message); }

function element(tag, cls = '', css = {}, parent = null) {
  const node = {tagName: tag.toUpperCase(), className: cls, css, parentElement: parent, children: [], properties: {}, attrs: {class: cls},
    style: {backgroundImage: '', setProperty(key, value) { node.properties[key] = value; }},
    getAttribute(key) { return node.attrs[key] ?? null; }, setAttribute(key, value) { node.attrs[key] = String(value); },
    closest() { return null; }, contains(other) { for (let n = other; n; n = n.parentElement) { if (n === node) { return true; } } return false; },
    querySelectorAll(selector) { const out = []; const walk = n => n.children.forEach(child => { if (/\[class\*="anim"\]/.test(selector) && /anim/.test(child.className)) { out.push(child); } walk(child); }); walk(node); return out; },
    getBoundingClientRect: () => ({width: 1200, height: 800}), getClientRects: () => [1], clientHeight: 800, scrollHeight: 800};
  if (parent) { parent.children.push(node); }
  return node;
}
const html = element('html'), body = element('body', 'et_divi_theme', {}, html);
const area = element('div', '', {}, body); area.attrs.id = 'et-main-area';
const mainContent = element('div', '', {}, area); mainContent.attrs.id = 'main-content';
const template = element('div', 'et-l et-l--body', {}, mainContent);
const section = element('div', 'et_pb_section et_pb_section_0_tb_body et_animated', {opacity: '0', display: 'block'}, template);
const titleModule = element('div', 'et_pb_module et_pb_post_title', {}, section); const h1 = element('h1', 'entry-title', {}, titleModule);
const postContent = element('div', 'et_pb_module et_pb_post_content', {}, section);
const roots = {'#main-content': mainContent, '.et-l--body': template};
const doc = {documentElement: html, body, baseURI: 'https://site.test/post/',
  querySelectorAll(selector) {
    if (selector.startsWith('main,')) { return selector.split(',').filter(part => roots[part]).map(part => roots[part]); }
    return {'.entry-title': [h1], '.et_pb_post_content': [postContent]}[selector] || [];
  }};
const win = {innerWidth: 1200, innerHeight: 800, getComputedStyle: node => ({display: 'block', visibility: 'visible', opacity: '1', position: 'static', ...node.css})};

canvas.prepareStaticLayout(win, doc);
check(section.properties.opacity === '1' && section.properties.visibility === 'visible', 'The animated template section around the post is shown');
const config = {referenceId: 12, referenceType: 'post'};
const content = canvas.locate(doc, {locator: {strategy: 'core', control_path: ['content'], reference_id: 12}}, config);
check(content && content.node === postContent && content.precision === 'region', 'The post content is outlined in the Post Content module');
check(canvas.locate(doc, {locator: {strategy: 'core', control_path: ['title'], reference_id: 12}}, config).node === h1, 'The post title is outlined');
const twice = {...doc, querySelectorAll: selector => selector === '.et_pb_post_content' ? [postContent, postContent] : doc.querySelectorAll(selector)};
check(canvas.locate(twice, {locator: {strategy: 'core', control_path: ['content'], reference_id: 12}}, config) === null, 'Two Post Content modules are never guessed');
console.log(`PASS ${checks} Divi Theme Builder preview checks`);
