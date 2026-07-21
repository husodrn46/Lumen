<?php
/**
 * PWA Header Include
 * Bu dosyayi tum sayfalarin <head> bolumune dahil edin
 *
 * Kullanim:
 * <head>
 *     ...
 *     <?php include_once(__DIR__ . '/pwa-header.php'); ?>
 * </head>
 */
?>
<!-- PWA Meta Tags -->
<meta name="theme-color" content="#6F1022">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Lumen">
<meta name="mobile-web-app-capable" content="yes">
<meta name="application-name" content="Lumen">
<meta name="msapplication-TileColor" content="#6F1022">
<meta name="msapplication-config" content="none">
<?php
/**
 * Kişisel görünüm ayarları (ayar/gorunum.php'de seçilir, M_USER_SETTINGS).
 * Vurgu rengi + görünüm sınıfları (büyük yazı / hız modu / kompakt liste) TÜM
 * sayfalara buradan uygulanır. Inline setProperty + erken class, sayfanın kendi
 * stilini ezer. Hiçbir ayar yoksa HİÇBİR ŞEY basılmaz → mevcut görünüm birebir
 * korunur. Hata olursa sessiz geçilir (sayfa normal render).
 */
if (function_exists('kisisel_ayarlar')) {
    try {
        $__ay = kisisel_ayarlar();
        $__js = '';
        $__renk = tema_vurgu_renk();
        if (is_array($__renk) && isset($__renk[0], $__renk[1])) {
            $__th = htmlspecialchars((string) $__renk[0], ENT_QUOTES);
            $__ts = htmlspecialchars((string) $__renk[1], ENT_QUOTES);
            $__js .= "d.style.setProperty('--red','" . $__th . "');"
                   . "d.style.setProperty('--red-soft','" . $__ts . "');"
                   . "d.classList.add('akl-accent');"
                   . 'var m=document.querySelector(\'meta[name="theme-color"]\');'
                   . "if(m){m.setAttribute('content','" . $__th . "');}";
        }
        $__cls = [];
        // Kademeli yazı boyutu (gor_yazi: kucuk/buyuk/cokbuyuk); eski gor_buyuk='1' → "buyuk" (geri uyum)
        $__yz = $__ay['gor_yazi'] ?? '';
        if (!in_array($__yz, ['kucuk', 'buyuk', 'cokbuyuk'], true)) { $__yz = ''; }
        if ($__yz === '' && ($__ay['gor_buyuk'] ?? '') === '1') { $__yz = 'buyuk'; }
        if ($__yz !== '')                                { $__cls[] = 'akl-yazi-' . $__yz; }
        if (($__ay['gor_hiz'] ?? '') === '1')            { $__cls[] = 'akl-hiz'; }
        if (($__ay['gor_yogunluk'] ?? '') === 'kompakt') { $__cls[] = 'akl-kompakt'; }
        if (($__ay['gor_liste'] ?? '') === 'liste')      { $__cls[] = 'akl-liste'; }   // kayıt görünümü: kart→liste (sayfa-özel CSS)
        if (false && ($__ay['gor_koyu'] ?? '') === '1') {   // KOYU TEMA RAFTA — akl-dark basılmıyor (stale session'da da koyu görünmez). Un-shelve: "false && " sil + gorunum.php $koyuTemaAktif=true.
            $__cls[] = 'akl-dark';
            // Koyu modda vurgu-soft rengini koyu tona çevir (aksi halde açık pembe/mavi kalır)
            $__ah = ltrim((is_array($__renk) && isset($__renk[0])) ? (string) $__renk[0] : '#6F1022', '#');
            $__ds = (strlen($__ah) === 6)
                ? 'rgba(' . hexdec(substr($__ah, 0, 2)) . ',' . hexdec(substr($__ah, 2, 2)) . ',' . hexdec(substr($__ah, 4, 2)) . ',.20)'
                : 'rgba(111,16,34,.20)';
            $__js .= "d.style.setProperty('--red-soft','" . $__ds . "');";
        }
        foreach ($__cls as $__c) {
            $__js .= "d.classList.add('" . $__c . "');";
        }
        if ($__js !== '') {
            echo '<script>(function(){try{var d=document.documentElement;' . $__js . '}catch(e){}})();</script>' . "\n";
        }
        // Kişiselleştirme CSS'i HER ZAMAN bas — ilgili sınıf yoksa etkisiz; böylece canlı toggle her sayfada çalışır.
        echo '<style>'
           . 'html.akl-buyuk,html.akl-yazi-buyuk{zoom:1.12;}'
           . 'html.akl-yazi-kucuk{zoom:.92;}'
           . 'html.akl-yazi-cokbuyuk{zoom:1.24;}'
           . '@media print{html.akl-buyuk,html[class*="akl-yazi"]{zoom:1 !important;}}'
           . 'html.akl-hiz .stok-img{display:none !important;}'
           . 'html.akl-kompakt table td,html.akl-kompakt table th{padding-top:6px !important;padding-bottom:6px !important;}'
           . 'html.akl-kompakt .stok-card{padding:9px 12px !important;}'
           . 'html.akl-dark{color-scheme:dark;--bg:#0f172a;--surface:#1e293b;--card:#1e293b;--text-1:#e6e9f0;--text-2:#9aa7bd;--text-3:#7b899f;--t1:#e6e9f0;--t2:#9aa7bd;--t3:#7b899f;--border:#2f3d52;--border-hover:#425068;--emerald-soft:#0e2a22;--indigo-soft:#1a2040;--amber-soft:#2b2113;}'
           . 'html.akl-dark body{background:var(--bg);color:var(--text-1);}'
           . 'html.akl-dark input:not([type=checkbox]):not([type=radio]):not([type=range]):not([type=color]),html.akl-dark select,html.akl-dark textarea{background:#1e293b;color:var(--text-1);border-color:var(--border);}'
           . 'html.akl-dark ::placeholder{color:#6b7a92;opacity:1;}'
           // Legacy glassmorphism sayfaları (lg_bakiye/cari/stok/essiparis/hareket...): sabit-beyaz yüzeyleri koyulaştır (yeni paneller .card/var kullandığı için etkilenmez).
           . 'html.akl-dark .top-header{background:rgba(15,23,42,.92) !important;border-bottom-color:var(--border) !important;}'
           . 'html.akl-dark .search-panel,html.akl-dark .filter-panel,html.akl-dark .list-panel,html.akl-dark .bulk-panel,html.akl-dark .topcari-panel,html.akl-dark .summary-panel,html.akl-dark .firma-card,html.akl-dark .glass-card,html.akl-dark .stat-card,html.akl-dark .table-card,html.akl-dark .summary-card,html.akl-dark .ozet-bandi,html.akl-dark .ozet-band,html.akl-dark .empty-state,html.akl-dark .modal-box,html.akl-dark .modal-dialog,html.akl-dark .akl-modal-dialog,html.akl-dark .ekstre-modal,html.akl-dark .image-modal-dialog,html.akl-dark .print-dropdown,html.akl-dark .suggest-list{background:#1e293b !important;border-color:var(--border) !important;color:var(--text-1);}'
           . 'html.akl-dark .firma-head{background:#232f43 !important;border-bottom-color:var(--border) !important;}'
           . 'html.akl-dark .firma-footer,html.akl-dark .bakiye-box{background:#172033 !important;border-color:var(--border) !important;}'
           . 'html.akl-dark .action-pill,html.akl-dark .suggest-item,html.akl-dark .topcari-item,html.akl-dark .paging-btn,html.akl-dark .odeme-btn{background:#232f43 !important;border-color:var(--border) !important;color:var(--text-1);}'
           . 'html.akl-dark .cek-senet-box{background:#2a2113 !important;border-color:#4a3a1a !important;}'
           . 'html.akl-dark .prev{background:#172033 !important;border-color:var(--border) !important;}'
           . '</style>' . "\n";
    } catch (Throwable $__e) {
        // görünüm uygulanamazsa sessiz geç; sayfa normal render olsun
    }
}
?>

<!-- PWA Manifest -->
<link rel="manifest" href="/manifest.json">

<!-- Ortak Asset'ler (tek yukle, tum sayfalarda gecerli) -->
<link rel="stylesheet" href="/assets/fontawesome/css/all.min.css">

<!-- Lumen marka: Montserrat fontu + hafif letter-spacing (tum sayfalara uygulanir) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>body{letter-spacing:-0.02em;}</style>

<!-- PWA Icons -->
<link rel="apple-touch-icon" href="/icon.png">
<link rel="icon" type="image/png" sizes="192x192" href="/icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/icon.png">
<link rel="icon" type="image/png" sizes="16x16" href="/icon.png">

<!-- Service Worker - Devre disi birakildi -->
<script>
// Mevcut Service Worker'i bir kez kaldir; her sayfa yuklemesinde cache temizleme yapma.
(function() {
    var cleanupKey = 'akl_sw_cleanup_done_v1';
    try {
        if (window.localStorage && localStorage.getItem(cleanupKey) === '1') {
            return;
        }
    } catch (e) {}

    var markDone = function() {
        try {
            if (window.localStorage) {
                localStorage.setItem(cleanupKey, '1');
            }
        } catch (e) {}
    };

    if (!('serviceWorker' in navigator)) {
        markDone();
        return;
    }

    navigator.serviceWorker.getRegistrations().then(function(registrations) {
        for (let registration of registrations) {
            registration.unregister();
            console.log('[PWA] Service Worker unregistered');
        }
    });
    // Cache'leri temizle
    if ('caches' in window) {
        caches.keys().then(function(names) {
            for (let name of names) {
                caches.delete(name);
                console.log('[PWA] Cache deleted:', name);
            }
        });
    }
    markDone();
})();
</script>

<!-- Frontend JS hata yakalama -> /js_hata_log.php -> uygulama log dizini (scope: js-error) -->
<script>
(function(){
    var sent = 0, MAX = 10;
    function gonder(p){
        if (sent >= MAX) { return; }
        sent++;
        try {
            fetch('/js_hata_log.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(p),
                keepalive: true
            }).catch(function(){});
        } catch (e) {}
    }
    window.addEventListener('error', function(e){
        if (!e || !e.message) { return; } // resource (img/script) yukleme hatalarini atla
        gonder({
            message: e.message,
            source: e.filename || '',
            line: e.lineno || 0,
            col: e.colno || 0,
            stack: (e.error && e.error.stack) ? String(e.error.stack) : '',
            page: location.pathname + location.search
        });
    });
    window.addEventListener('unhandledrejection', function(e){
        var r = (e && e.reason) || {};
        gonder({
            message: 'UnhandledRejection: ' + (r.message || String(r)),
            source: '', line: 0, col: 0,
            stack: r.stack ? String(r.stack) : '',
            page: location.pathname + location.search
        });
    });
})();
</script>

<!-- ==== "Müşteri yanında" gizli mod — tek dokunuşla para/bakiye gizle (client-side, localStorage, görsel blur; yetkiye dokunmaz) ==== -->
<script>try{if(localStorage.getItem('akl_gizli')==='1')document.documentElement.classList.add('akl-gizli');}catch(e){}</script>
<style>
  /* Bilinen para/bakiye GÖSTERİM sınıfları — yükleme anında blur (flaş önleme). Input değil, geniş seçici yok. */
  html.akl-gizli .amount,html.akl-gizli .oz-val,html.akl-gizli .kasa-value,html.akl-gizli .kasa-amount,
  html.akl-gizli .grand-total-value,html.akl-gizli .cs-tutar,html.akl-gizli .bakiye-box .amount,
  html.akl-gizli .satis-tutar,html.akl-gizli .sk-deger,
  html.akl-gizli .akl-para-gizli{
    filter:blur(7px)!important;-webkit-filter:blur(7px)!important;transition:filter .12s;
    user-select:none;-webkit-user-select:none;pointer-events:none;
  }
  #akl-gizli-btn{position:fixed;right:16px;bottom:16px;z-index:99990;width:46px;height:46px;border-radius:50%;
    border:none;background:rgba(31,41,55,.82);color:#fff;font-size:17px;display:flex;align-items:center;
    justify-content:center;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.28);opacity:.5;
    transition:opacity .15s,background .15s,transform .1s;-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px);}
  #akl-gizli-btn:hover{opacity:1;}
  #akl-gizli-btn:active{transform:scale(.93);}
  html.akl-gizli #akl-gizli-btn{background:#b91c1c;opacity:.92;}
  @media print{#akl-gizli-btn{display:none!important;}}
</style>
<script>
(function(){
  var KEY='akl_gizli', TL='₺';
  function kok(){return document.documentElement;}
  function paraTara(root){
    if(!root)return;
    try{
      var els=root.querySelectorAll('*'),i,el,j,cocukVar;
      for(i=0;i<els.length;i++){
        el=els[i];
        if(el.id==='akl-gizli-btn'||el.classList.contains('akl-para-gizli'))continue;
        if(el.textContent.indexOf(TL)===-1)continue;          /* ₺ yoksa atla */
        cocukVar=false;
        for(j=0;j<el.children.length;j++){ if(el.children[j].textContent.indexOf(TL)!==-1){cocukVar=true;break;} }
        if(!cocukVar) el.classList.add('akl-para-gizli');       /* ₺ içeren EN DERİN öğe = leaf para */
      }
    }catch(e){}
  }
  var gozlemci=null;
  function gozlemBasla(){
    if(gozlemci||!window.MutationObserver||!document.body)return;
    var zaman=null;
    gozlemci=new MutationObserver(function(){clearTimeout(zaman);zaman=setTimeout(function(){paraTara(document.body);},150);});
    gozlemci.observe(document.body,{childList:true,subtree:true});
  }
  function gozlemDur(){if(gozlemci){gozlemci.disconnect();gozlemci=null;}}
  function uygula(acik){
    kok().classList.toggle('akl-gizli',acik);
    var b=document.getElementById('akl-gizli-btn');
    if(b){
      b.innerHTML=acik?'<i class="fa-solid fa-eye-slash"></i>':'<i class="fa-solid fa-eye"></i>';
      b.title=acik?'Gizli mod AÇIK — para/bakiye gizli (kapatmak için dokun)':'Müşteri yanında gizli mod';
    }
    if(acik){paraTara(document.body);gozlemBasla();}else{gozlemDur();}
  }
  function kur(){
    if(document.getElementById('akl-gizli-btn')||!document.body)return;
    if(/giris\.php/i.test(location.pathname))return;   /* giriş ekranında gösterme */
    var b=document.createElement('button');
    b.id='akl-gizli-btn';b.type='button';b.setAttribute('aria-label','Gizli mod');
    b.innerHTML='<i class="fa-solid fa-eye"></i>';
    b.addEventListener('click',function(){
      var yeni=!(localStorage.getItem(KEY)==='1');
      try{localStorage.setItem(KEY,yeni?'1':'0');}catch(e){}
      uygula(yeni);
    });
    document.body.appendChild(b);
    var acik=false;try{acik=localStorage.getItem(KEY)==='1';}catch(e){}
    uygula(acik);
  }
  if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',kur);}else{kur();}
})();
</script>
