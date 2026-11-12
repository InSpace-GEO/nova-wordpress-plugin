'use strict';
const assert = require('node:assert/strict');
const {prepareStaticLayout} = require('../assets/recipe-canvas.js');
function node(css = {}, parent = null, excluded = false) {
  const properties = {}, attrs = {};
  return {parentElement: parent, css, excluded, clientHeight: 800, scrollHeight: 2000,
    style: {setProperty(key, value, priority) {properties[key] = value; assert.equal(priority, 'important');}}, properties,
    getBoundingClientRect: () => ({width: 1200, height: 800}),
    closest() {return this.excluded || (this.parentElement && this.parentElement.closest()) ? this : null;},
    getAttribute: key => attrs[key], setAttribute(key, value) {attrs[key] = value;}, querySelectorAll: () => []};
}
const html = node(), body = node({}, html), shell = node({position:'fixed',overflowY:'hidden'},body), main = node({},shell);
const reveal = node({opacity:'0',display:'block'}, main), visible = node({opacity:'1',display:'block'},main);
const responsive = node({opacity:'0',display:'none'},main), hidden = node({opacity:'0',display:'block'},main,true);
const hiddenParent = node({visibility:'hidden'},main), hiddenChild = node({opacity:'0',visibility:'hidden',display:'block'},hiddenParent);
const dialog = node({position:'fixed',overflowY:'hidden'},body,true), dialogMain = node({},dialog);
const doc = {documentElement:html,body,querySelectorAll:() => [main,dialogMain]};
main.querySelectorAll = () => [reveal,visible,responsive,hidden,hiddenChild];
const win = {innerWidth:1200,innerHeight:800,getComputedStyle:element => element.css};
assert.equal(prepareStaticLayout(win,doc),2);
assert.equal(shell.properties.position,'relative');assert.equal(shell.properties.height,'auto');assert.equal(shell.properties.overflow,'visible');
assert.equal(html.properties['overflow-y'],'auto');assert.equal(reveal.properties.opacity,'1');
for (const protectedNode of [visible,responsive,hidden,hiddenChild,dialog]) assert.deepEqual(protectedNode.properties,{});
shell.css.position='relative';
assert.equal(prepareStaticLayout(win,doc),0,'The fallback must be idempotent.');
const small = node({position:'fixed',overflowY:'hidden'},body);small.getBoundingClientRect=()=>({width:300,height:300});
const smallMain = node({},small);doc.querySelectorAll=()=>[smallMain];
assert.equal(prepareStaticLayout(win,doc),0,'Small widgets must not be stretched.');
shell.css.position='fixed';shell.scrollHeight=shell.clientHeight;main.querySelectorAll=()=>[];doc.querySelectorAll=()=>[main];
assert.equal(prepareStaticLayout(win,doc),0,'Ordinary non-overflowing content must retain layout.');
shell.scrollHeight=2000;shell.css.position='static';
assert.equal(prepareStaticLayout(win,doc),0,'Normal document flow remains unchanged.');
shell.css.position='fixed';main.css.display='none';
assert.equal(prepareStaticLayout(win,doc),0,'A responsive-hidden main must stay hidden.');
console.log('PASS static preview scrolling/reveal and hidden/responsive/menu regressions');
