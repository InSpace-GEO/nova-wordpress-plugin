const {test}=require('node:test'), assert=require('node:assert/strict');
const {locate}=require('../assets/recipe-canvas.js');
const config={referenceId:9,referenceType:'post'};
test('Salient header title, subtitle and background resolve only in their native header',()=>{
 for(const [control,selector] of Object.entries({title:'h1',subtitle:'.subheader',image:'.page-header-bg-image'})){
  const leaf={},owner={closest:()=>null,querySelectorAll:s=>s===selector?[leaf]:[]},document={querySelectorAll:s=>s==='#page-header-bg'?[owner]:[]};
  const field={locator:{strategy:'salient_header',control,reference_id:9}};
  assert.equal(locate(document,field,config).node,leaf);
  owner.querySelectorAll=()=>[leaf,{}];assert.equal(locate(document,field,config),null);
  owner.querySelectorAll=()=>[leaf];owner.closest=()=>({});assert.equal(locate(document,field,config),null);
  field.locator.reference_id=10;assert.equal(locate(document,field,config),null);
 }
});
