<?php
/**
 * ux_katman.php — Ortak UX katmani (toast + skeleton + bos durum).
 * Kullanim: sayfanin </body> oncesine  include_once(__DIR__ . '/ux_katman.php');
 * JS: toast('Kaydedildi');  toast('Hata olustu', 'error');  ('success'|'error'|'info'|'warning')
 * CSS sinif: .ux-skel (iskelet), .ux-empty (.baslik/.aciklama) bos durum.
 * Font Awesome + Montserrat sayfada zaten yuklu varsayilir.
 */
?>
<div id="uxToastWrap" aria-live="polite" aria-atomic="true"
     style="position:fixed;top:16px;right:16px;z-index:99999;display:flex;flex-direction:column;gap:8px;pointer-events:none;max-width:90vw;"></div>
<style>
.ux-toast { display:flex; align-items:center; gap:9px; min-width:210px; max-width:360px; padding:12px 16px; border-radius:12px; font-family:'Avenir Next','Montserrat',sans-serif; font-size:13.5px; font-weight:600; color:#fff; box-shadow:0 12px 32px -10px rgba(0,0,0,0.35); opacity:0; transform:translateX(24px); transition:opacity .28s ease, transform .28s cubic-bezier(0.22,1,0.36,1); pointer-events:auto; }
.ux-toast.show { opacity:1; transform:translateX(0); }
.ux-toast.hide { opacity:0; transform:translateX(24px); }
.ux-toast i { font-size:16px; flex-shrink:0; }
.ux-toast.success { background:#059669; }
.ux-toast.error   { background:var(--red,#6F1022); }
.ux-toast.info    { background:#2563eb; }
.ux-toast.warning { background:#d97706; }
.ux-toast .ux-toast-x { margin-left:auto; cursor:pointer; opacity:.8; font-size:13px; }
.ux-toast .ux-toast-x:hover { opacity:1; }

.ux-skel { background:linear-gradient(90deg,#eef0f2 25%,#e3e6e9 37%,#eef0f2 63%); background-size:400% 100%; animation:uxSkel 1.2s ease-in-out infinite; border-radius:8px; }
@keyframes uxSkel { 0%{background-position:100% 0} 100%{background-position:-100% 0} }

.ux-empty { padding:48px 22px; text-align:center; color:#9ca3af; font-family:'Avenir Next','Montserrat',sans-serif; }
.ux-empty > i { font-size:40px; display:block; margin-bottom:14px; opacity:.5; }
.ux-empty .baslik { font-size:15px; font-weight:600; color:#6b7280; }
.ux-empty .aciklama { font-size:13px; margin-top:4px; }
@media (prefers-reduced-motion: reduce) { .ux-toast { transition:opacity .15s; transform:none; } .ux-skel { animation:none; } }
</style>
<script>
(function(){
    var ikonlar = { success:'fa-circle-check', error:'fa-circle-exclamation', info:'fa-circle-info', warning:'fa-triangle-exclamation' };
    window.toast = function(mesaj, tip, sure){
        tip = tip || 'success';
        sure = (typeof sure === 'number') ? sure : 3000;
        var wrap = document.getElementById('uxToastWrap');
        if (!wrap) { return; }
        var el = document.createElement('div');
        el.className = 'ux-toast ' + tip;
        el.setAttribute('role', 'status');
        var ico = document.createElement('i');
        ico.className = 'fa-solid ' + (ikonlar[tip] || ikonlar.success);
        ico.setAttribute('aria-hidden', 'true');
        var sp = document.createElement('span');
        sp.textContent = String(mesaj == null ? '' : mesaj);
        var x = document.createElement('span');
        x.className = 'ux-toast-x';
        x.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';
        el.appendChild(ico); el.appendChild(sp); el.appendChild(x);
        wrap.appendChild(el);
        requestAnimationFrame(function(){ el.classList.add('show'); });
        var kapat = function(){
            el.classList.remove('show'); el.classList.add('hide');
            setTimeout(function(){ if (el.parentNode) { el.remove(); } }, 320);
        };
        x.addEventListener('click', kapat);
        if (sure > 0) { setTimeout(kapat, sure); }
    };
})();
</script>
