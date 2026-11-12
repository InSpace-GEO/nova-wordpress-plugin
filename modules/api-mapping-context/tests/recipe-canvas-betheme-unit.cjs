const {test}=require('node:test'),assert=require('node:assert/strict');
const {locate}=require('../assets/recipe-canvas.js');
function fixture(kind='item') { const leaf={tagName:'H2',textContent:'Mapped title',contains:()=>false}; const owner={closest:()=>null,querySelectorAll:s=>s==='img'?[leaf]:[leaf],contains:n=>n===leaf}; const document={querySelectorAll:s=>s===`.mcb-${kind}-abc123`?[owner]:[]}; return {leaf,owner,document}; }
const config={referenceId:9,referenceType:'post'};
function field(extra={}){return {locator:{strategy:'betheme',node_id:'abc123',reference_id:9,text:'Mapped title',...extra}};}
test('BeBuilder uses native node identity and narrows an exact visible title',()=>{const f=fixture();assert.equal(locate(f.document,field(),config).node,f.leaf);});
test('image controls locate the native image',()=>{const f=fixture();assert.equal(locate(f.document,field({kind:'image_url'}),config).node,f.leaf);});
test('section and wrap backgrounds outline their own native container',()=>{for(const kind of ['section','wrap']){const f=fixture(kind);assert.equal(locate(f.document,field({node_kind:kind,kind:'background',text:''}),config).node,f.owner);}});
test('duplicate native IDs are not guessed',()=>{const f=fixture();f.document.querySelectorAll=()=>[f.owner,f.owner];assert.equal(locate(f.document,field(),config),null);});
test('related loops and site header/footer cannot acquire page fields',()=>{const f=fixture();f.owner.closest=()=>({});assert.equal(locate(f.document,field(),config),null);});
test('reference identity and safe IDs are required',()=>{const f=fixture();assert.equal(locate(f.document,field({reference_id:10}),config),null);assert.equal(locate(f.document,field({node_id:'x] div'}),config),null);});
