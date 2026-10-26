// Ağ kullanmadan otomatik alımın bekleme, durma ve sayfadan ayrılma davranışı.
const {readFileSync} = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const kaynak = readFileSync('assets/js/eticaret-al.js', 'utf8');
async function ortam(yanitlar) {
    const alanlar = new Map();
    for (const id of ['al-form','al-hata','al-bekleme','al-devam','al-durdur','al-durum','al-yeni','al-mukerrer','al-incele','al-sayfa']) alanlar.set(id, {textContent:'', hidden:true, addEventListener(){}});
    const istekler = [], zamanlar = [], olaylar = {};
    const ctx = {document:{getElementById:id=>alanlar.get(id)},window:{location:{href:'http://web/eticaret/al.php?kanal=1'},addEventListener:(k,f)=>{olaylar[k]=f;},setTimeout:(f,ms)=>zamanlar.push({f,ms})},
        FormData:class extends Map { constructor(){super([['csrf_token','sentetik-csrf'],['cekim','1']]);} },
        fetch:async (url,secenekler)=>{istekler.push({url,...secenekler});const y=yanitlar.shift();if (y instanceof Error) throw y;return {ok:y.ok!==false,json:async()=>y};}};
    vm.runInNewContext(kaynak,ctx);await new Promise(setImmediate);
    return {alanlar,istekler,zamanlar,olaylar};
}
const sayfa = (ek={}) => ({durum:'suruyor',yeni:1,mukerrer:2,incele:0,sayfa:1,bekle:5,manuel:false,hata:null,...ek});
(async()=>{
    const o=await ortam([sayfa(),sayfa({durum:'tamam',sayfa:2})]);
    assert.equal(o.istekler.length,1);assert.equal(o.istekler[0].method,'POST');assert.equal(o.istekler[0].body.get('csrf_token'),'sentetik-csrf');assert.equal(o.istekler[0].body.get('islem'),'adim');
    assert.equal(o.zamanlar[0].ms,5000);await o.zamanlar.shift().f();assert.equal(o.istekler.length,2);assert.equal(o.zamanlar.length,0);assert.equal(o.alanlar.get('al-durum').textContent,'Alım tamamlandı');
    const b=await ortam([sayfa({bekle:120,hata:'İstek sınırı'})]);assert.equal(b.zamanlar[0].ms,120000);assert.equal(b.alanlar.get('al-hata').hidden,false);
    const m=await ortam([sayfa({manuel:true,hata:'Erişim doğrulanamadı'})]);assert.equal(m.zamanlar.length,0);assert.equal(m.alanlar.get('al-devam').hidden,false);assert.equal(m.alanlar.get('al-devam').value,'tekrar');
    const h=await ortam([new Error('sentetik kesinti')]);assert.equal(h.zamanlar.length,0);assert.equal(h.alanlar.get('al-devam').hidden,false);
    const k=await ortam([sayfa()]);k.olaylar.pagehide();await k.zamanlar.shift().f();assert.equal(k.istekler.length,1);
    console.log('5 tarayıcı alım senaryosu geçti (sentetik fetch; ağ çağrısı yok).');
})().catch(e=>{console.error(e);process.exitCode=1;});
