// No package install/browser session; execute production submit guard against a minimal DOM double.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const events = {}, windowEvents = {};
const button = {disabled:false};
const form = {dataset:{}, querySelector:()=>({value:'original-key'}), querySelectorAll:()=>[button]};
const unrelated = {dataset:{}, querySelector:()=>null};
vm.runInNewContext(fs.readFileSync(__dirname+'/../siparis/web_intent.js','utf8'), {
  document:{addEventListener:(name,fn)=>events[name]=fn,querySelectorAll:()=>[form,unrelated]},
  window:{addEventListener:(name,fn)=>windowEvents[name]=fn}
});
let prevented=0;
const event={target:form,preventDefault:()=>prevented++};
events.submit(event);
assert.equal(button.disabled,true); assert.equal(form.dataset.sending,'1'); assert.equal(prevented,0);
events.submit(event); assert.equal(prevented,1);
windowEvents.pageshow(); assert.equal(button.disabled,false); assert.equal(form.dataset.sending,'');
events.submit(event); assert.equal(prevented,1);
events.submit({target:unrelated,preventDefault:()=>prevented++}); assert.equal(prevented,1);
console.log('TAMAM: 8 JS submit guard assertion (DOM double; browser rendering değil).');
