<?php
/**
 * tur.php — Rehberli ürün turu (vanilla JS, dış bağımlılık yok).
 * Ana ekrana </body> öncesi dahil edilir. İlk girişte bir kez otomatik çalışır
 * (localStorage), header'daki "Tur" butonuyla tekrar açılır: window.lumenTur().
 *
 * Teknik: yarı-saydam backdrop + hedefi halka (ring) ile vurgula ve z-index'le
 * öne çıkar (dev box-shadow "delik" tekniği bazı tarayıcılarda sorun çıkarıyor).
 */
?>
<style>
  .tur-backdrop { position:fixed; inset:0; z-index:100000; background:rgba(17,24,39,.62); display:none; }
  .tur-backdrop.acik { display:block; }
  .tur-vurgu { position:relative; z-index:100001 !important; border-radius:12px;
    box-shadow:0 0 0 3px #6F1022, 0 0 0 7px rgba(111,16,34,.28) !important; transition:box-shadow .2s ease; }
  .tur-tip { position:fixed; z-index:100002; width:300px; max-width:calc(100vw - 32px); background:#fff;
    border-radius:14px; box-shadow:0 20px 50px rgba(0,0,0,.30); padding:18px; display:none;
    font-family:'Avenir Next','Montserrat',sans-serif; letter-spacing:-0.02em;
    transition:left .28s cubic-bezier(.22,1,.36,1), top .28s cubic-bezier(.22,1,.36,1); }
  .tur-tip.acik { display:block; }
  .tur-tip h4 { font-size:15.5px; font-weight:700; color:#1f2937; margin:0 0 6px; }
  .tur-tip p { font-size:13px; color:#6b7280; line-height:1.5; margin:0 0 14px; }
  .tur-tip .tur-alt { display:flex; align-items:center; gap:8px; }
  .tur-tip .tur-ilerleme { font-size:12px; color:#9ca3af; font-weight:600; margin-right:auto; }
  .tur-btn { border:none; border-radius:9px; padding:8px 14px; font-weight:700; font-size:12.5px; cursor:pointer; font-family:inherit; display:inline-flex; align-items:center; gap:6px; }
  .tur-btn.ileri { background:#6F1022; color:#fff; }
  .tur-btn.ileri:hover { filter:brightness(1.1); }
  .tur-btn.geri { background:#f3f4f6; color:#6b7280; }
  .tur-btn.atla { background:transparent; color:#9ca3af; padding:8px 6px; }
  .tur-btn.atla:hover { color:#6F1022; }
  @media (max-width:520px){ .tur-tip { width:calc(100vw - 32px); } }
</style>
<div class="tur-backdrop" id="turBackdrop" aria-hidden="true"></div>
<div class="tur-tip" id="turTip" role="dialog" aria-modal="true">
  <h4 id="turBaslik"></h4>
  <p id="turMetin"></p>
  <div class="tur-alt">
    <span class="tur-ilerleme" id="turIlerleme"></span>
    <button type="button" class="tur-btn atla" id="turAtla">Atla</button>
    <button type="button" class="tur-btn geri" id="turGeri"><i class="fa-solid fa-arrow-left"></i></button>
    <button type="button" class="tur-btn ileri" id="turIleri">İleri</button>
  </div>
</div>
<script>
(function(){
  var SURUM = 'lumen_tur_v1';
  var adimlar = [
    { sel:null, baslik:'Lumen’e Hoş Geldiniz 👋', metin:'Kısa bir turla ana ekranı tanıyalım. İstediğiniz an "Atla" diyebilirsiniz.' },
    { sel:'.mc[data-fav="../cari/cari.php"], .mc[data-fav="../siparis/lg_fis.php"]', baslik:'Yeni Sipariş', metin:'Cari seçip ürün ekleyerek hızlıca sipariş oluşturursunuz.' },
    { sel:'.mc[data-fav="../stok/stok_tara.php"]', baslik:'Stok Arama', metin:'Ürün adı/kodu ile arayıp fiyat ve anlık stok miktarını görürsünüz.' },
    { sel:'.mc[data-fav="../cari/lg_bakiye.php"]', baslik:'Müşteri Bakiye', metin:'Cari bakiye, ekstre ve alınan çek/senet durumu.' },
    { sel:'.mc[data-fav="rapor/dashboard.php"]', baslik:'Raporlar', metin:'Satış, cari yaşlandırma, stok ve çek raporları tek yerde.' },
    { sel:'.header-right a[href*="gorunum"], .header-right a[href*="ayar"]', baslik:'Ayarlar', metin:'Kişiselleştirme, kullanıcı/yetki, logo ve sistem ayarları.' },
    { sel:'#kurulumRehber', baslik:'Başlangıç Adımları', metin:'Kurulumu tamamlamak için kalan adımları buradan yaparsınız.' },
    { sel:null, baslik:'Hazırsınız! 🎉', metin:'Turu dilediğinizde üstteki "Tur" butonundan tekrar açabilirsiniz.' }
  ];

  var bd = document.getElementById('turBackdrop'),
      tip = document.getElementById('turTip'),
      elB = document.getElementById('turBaslik'),
      elM = document.getElementById('turMetin'),
      elI = document.getElementById('turIlerleme'),
      bIleri = document.getElementById('turIleri'),
      bGeri = document.getElementById('turGeri'),
      bAtla = document.getElementById('turAtla');
  if (!bd) return;

  var gecerli = [], idx = 0, sonHedef = null;

  function hedef(a){ return a && a.sel ? document.querySelector(a.sel) : null; }
  function vurguTemizle(){ if (sonHedef){ sonHedef.classList.remove('tur-vurgu'); sonHedef = null; } }

  function konumla(t){
    var r = t.getBoundingClientRect();
    var tipW = tip.offsetWidth || 300, tipH = tip.offsetHeight || 150, m = 14;
    var altBosluk = window.innerHeight - r.bottom;
    var top, left = Math.min(Math.max(12, r.left), window.innerWidth - tipW - 12);
    if (altBosluk > tipH + m + 20) { top = r.bottom + m; }
    else if (r.top > tipH + m + 20) { top = r.top - tipH - m; }
    else { top = Math.max(12, (window.innerHeight - tipH) / 2); }
    tip.style.left = left + 'px';
    tip.style.top = top + 'px';
  }
  function ortala(){
    tip.style.left = Math.max(12, (window.innerWidth - (tip.offsetWidth||300)) / 2) + 'px';
    tip.style.top  = Math.max(12, (window.innerHeight - (tip.offsetHeight||150)) / 2) + 'px';
  }

  function goster(i){
    idx = i;
    var a = gecerli[i], t = hedef(a);
    vurguTemizle();
    elB.textContent = a.baslik;
    elM.textContent = a.metin;
    elI.textContent = (i+1) + ' / ' + gecerli.length;
    bGeri.style.visibility = i === 0 ? 'hidden' : 'visible';
    bIleri.innerHTML = (i === gecerli.length-1) ? 'Bitir' : 'İleri <i class="fa-solid fa-arrow-right"></i>';
    if (t){
      t.classList.add('tur-vurgu'); sonHedef = t;
      t.scrollIntoView({behavior:'smooth', block:'center'});
      setTimeout(function(){ konumla(t); }, 280);
    } else {
      ortala();
    }
  }

  function baslat(){
    gecerli = adimlar.filter(function(a){ return !a.sel || document.querySelector(a.sel); });
    if (!gecerli.length) return;
    bd.classList.add('acik'); tip.classList.add('acik'); bd.setAttribute('aria-hidden','false');
    goster(0);
  }
  function kapat(){ vurguTemizle(); bd.classList.remove('acik'); tip.classList.remove('acik'); bd.setAttribute('aria-hidden','true'); try{ localStorage.setItem(SURUM,'1'); }catch(e){} }

  bIleri.addEventListener('click', function(){ (idx < gecerli.length-1) ? goster(idx+1) : kapat(); });
  bGeri.addEventListener('click', function(){ if (idx>0) goster(idx-1); });
  bAtla.addEventListener('click', kapat);
  bd.addEventListener('click', kapat);
  document.addEventListener('keydown', function(e){ if (!bd.classList.contains('acik')) return; if (e.key==='Escape') kapat(); else if (e.key==='ArrowRight') bIleri.click(); else if (e.key==='ArrowLeft') bGeri.click(); });
  window.addEventListener('resize', function(){ if (bd.classList.contains('acik')){ var t=hedef(gecerli[idx]); t ? konumla(t) : ortala(); } });

  window.lumenTur = baslat;

  try { if (localStorage.getItem(SURUM) !== '1') { setTimeout(baslat, 700); } } catch(e){}
})();
</script>
