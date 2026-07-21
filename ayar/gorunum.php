<?php

declare(strict_types=1);

/**
 * ayar/gorunum.php — Kişiselleştirme merkezi (kullanıcı bazlı görünüm ayarları).
 * Personele açık (admin_guard YOK); her kullanıcı yalnızca kendi tercihini yönetir.
 * Ayarlar M_USER_SETTINGS(USER_CODE, <key>, <value>)'a yazılır; pwa-header.php
 * bunları tüm sayfalarda uygular (renk → --red; gor_* → html sınıfları).
 * Açılış ekranı: index.php login sonrası (?giris=basarili) yönlendirir.
 */

include_once(__DIR__ . "/../ayr.php");
include(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

$palet = tema_vurgu_paleti();
$renkAd = [
    'kirmizi' => 'Kırmızı', 'mavi' => 'Mavi', 'mor' => 'Mor', 'yesil' => 'Yeşil',
    'turuncu' => 'Turuncu', 'teal' => 'Teal', 'pembe' => 'Pembe', 'lacivert' => 'Lacivert',
];
$acilisSecenek = [
    ''           => ['Ana Sayfa', 'fa-house'],
    'siparisler' => ['Sipariş Listesi', 'fa-list-check'],
    'stok'       => ['Stok Ara', 'fa-magnifying-glass'],
    'bekleyen'   => ['Bekleyen Siparişler', 'fa-dolly'],
];

// İzinli değerler (whitelist — güvenlik)
$gecerli = [
    'tema_vurgu'   => array_keys($palet),
    'gor_buyuk'    => ['', '1'],
    'gor_yazi'     => ['', 'kucuk', 'buyuk', 'cokbuyuk'],
    'gor_hiz'      => ['', '1'],
    'gor_yogunluk' => ['', 'kompakt'],
    'gor_liste'    => ['', 'liste'],
    'gor_koyu'     => ['', '1'],
    'gor_acilis'   => array_keys($acilisSecenek),
];

// ── AJAX kaydet (web session + CSRF; tek generic uç) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        echo json_encode(['ok' => false, 'mesaj' => 'Güvenlik doğrulaması başarısız.']);
        exit;
    }
    $anahtar = (string) ($_POST['anahtar'] ?? '');
    $deger   = (string) ($_POST['deger'] ?? '');
    if (!isset($gecerli[$anahtar]) || !in_array($deger, $gecerli[$anahtar], true)) {
        echo json_encode(['ok' => false, 'mesaj' => 'Geçersiz ayar.']);
        exit;
    }
    $kod = tema_kullanici_kodu();
    if ($kod === '') {
        echo json_encode(['ok' => false, 'mesaj' => 'Kullanıcı bulunamadı.']);
        exit;
    }
    try {
        $var = $dbh->prepare("SELECT COUNT(*) FROM M_USER_SETTINGS WHERE USER_CODE = :c AND SETTING_KEY = :k");
        $var->execute([':c' => $kod, ':k' => $anahtar]);
        if ((int) $var->fetchColumn() > 0) {
            $dbh->prepare("UPDATE M_USER_SETTINGS SET SETTING_VALUE = :v WHERE USER_CODE = :c AND SETTING_KEY = :k")
                ->execute([':v' => $deger, ':c' => $kod, ':k' => $anahtar]);
        } else {
            $dbh->prepare("INSERT INTO M_USER_SETTINGS (USER_CODE, SETTING_KEY, SETTING_VALUE) VALUES (:c, :k, :v)")
                ->execute([':c' => $kod, ':k' => $anahtar, ':v' => $deger]);
        }
        if (isset($_SESSION['_kis_ayar']) && is_array($_SESSION['_kis_ayar'])) {
            $_SESSION['_kis_ayar'][$anahtar] = $deger;   // cache güncelle → diğer sayfalar anında görür
        }
        $resp = ['ok' => true];
        if ($anahtar === 'tema_vurgu' && isset($palet[$deger])) {
            $resp['renk'] = $palet[$deger][0];
            $resp['soft'] = $palet[$deger][1];
        }
        echo json_encode($resp);
    } catch (Throwable $e) {
        error_log('gorunum kaydet: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'mesaj' => 'Kaydedilemedi.']);
    }
    exit;
}

// Mevcut değerler
$aktifRenk = tema_vurgu_key();
$renkGoster = ($aktifRenk !== '' && isset($palet[$aktifRenk])) ? $aktifRenk : 'kirmizi';
[$aktifHex, $aktifSoft] = $palet[$renkGoster];
$yaziBoyut = kisisel_ayar('gor_yazi');
if (!in_array($yaziBoyut, ['kucuk', 'buyuk', 'cokbuyuk'], true)) { $yaziBoyut = ''; }
if ($yaziBoyut === '' && kisisel_ayar('gor_buyuk') === '1') { $yaziBoyut = 'buyuk'; } // geri uyum: eski "büyük yazı" → "Büyük"
$hiz    = kisisel_ayar('gor_hiz') === '1';
$yogun  = kisisel_ayar('gor_yogunluk') === 'kompakt';
$liste  = kisisel_ayar('gor_liste') === 'liste';
$koyu   = kisisel_ayar('gor_koyu') === '1';
$koyuTemaAktif = false;   // Koyu tema RAFA KALDIRILDI (WIP — sabit-beyaz sayfalar eksik). true yapınca toggle + özellik geri gelir; kod/pwa-header katmanı yerinde.
$acilis = kisisel_ayar('gor_acilis');
if (!isset($acilisSecenek[$acilis])) { $acilis = ''; }

$csrf = csrf_token();
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<?php $__htmlcls = trim(($yaziBoyut !== '' ? 'lumen-yazi-' . $yaziBoyut . ' ' : '') . (($koyuTemaAktif && $koyu) ? 'lumen-dark' : '')); ?>
<html lang="tr"<?php echo $__htmlcls !== '' ? ' class="' . $__htmlcls . '"' : ''; ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ayarlar — Kişiselleştirme</title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once(__DIR__ . '/../pwa-header.php'); } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:#f9fafb; --card:#fff; --t1:#1f2937; --t2:#6b7280; --t3:#9ca3af; --border:#e5e7eb;
            --red:<?php echo $aktifHex; ?>; --red-soft:<?php echo $aktifSoft; ?>;
        }
        * { box-sizing:border-box; margin:0; }
        body { font-family:'Avenir Next','Montserrat',sans-serif; background:var(--bg); color:var(--t1); min-height:100vh; }
        /* Bu sayfada canlı önizleme için görünüm sınıfları (diğer sayfalarda pwa-header basar) */
        html.lumen-buyuk, html.lumen-yazi-buyuk { zoom:1.12; }
        html.lumen-yazi-kucuk { zoom:.92; }
        html.lumen-yazi-cokbuyuk { zoom:1.24; }
        @media print { html.lumen-buyuk, html[class*="lumen-yazi"] { zoom:1 !important; } }
        html.lumen-hiz .stok-img { display:none !important; }
        html.lumen-kompakt table td, html.lumen-kompakt table th { padding-top:6px !important; padding-bottom:6px !important; }
        .top { position:sticky; top:0; z-index:40; height:60px; background:rgba(255,255,255,.9); backdrop-filter:blur(8px); border-bottom:1px solid var(--border); }
        .top-in { max-width:820px; margin:0 auto; height:100%; display:flex; align-items:center; gap:12px; padding:0 20px; }
        .top-in a.geri { width:36px; height:36px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; color:var(--t2); text-decoration:none; border:1px solid var(--border); background:#fff; }
        .top-in a.geri:hover { color:var(--red); border-color:var(--red); }
        .top-ico { width:38px; height:38px; border-radius:11px; background:var(--red-soft); color:var(--red); display:inline-flex; align-items:center; justify-content:center; font-size:16px; }
        .top-title { font-size:16px; font-weight:600; flex:1; }
        .top-title small { display:block; font-size:11.5px; color:var(--t2); font-weight:400; }
        main { max-width:820px; margin:0 auto; padding:20px; }
        .card { background:var(--card); border:1px solid var(--border); border-radius:16px; padding:20px; margin-bottom:16px; }
        .sec { font-size:11px; font-weight:600; color:var(--t3); text-transform:uppercase; letter-spacing:.5px; margin-bottom:14px; display:flex; align-items:center; gap:7px; }
        .sec i { color:var(--red); }
        /* Renk kutucukları */
        .swatches { display:flex; gap:14px; flex-wrap:wrap; }
        .sw { display:flex; flex-direction:column; align-items:center; gap:7px; border:none; background:none; cursor:pointer; font-family:inherit; }
        .sw .dot { width:50px; height:50px; border-radius:14px; border:3px solid transparent; position:relative; transition:transform .12s; }
        .sw:hover .dot { transform:translateY(-2px); }
        .sw.on .dot { border-color:var(--t1); }
        .sw.on .dot::after { content:'\2713'; position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#fff; font-size:19px; font-weight:700; }
        .sw .ad { font-size:11.5px; color:var(--t2); font-weight:600; }
        .sw.on .ad { color:var(--t1); }
        .prev { border:1px dashed #d1d5db; border-radius:14px; padding:16px; background:#fbfbfc; margin-top:16px; }
        .prev-lbl { font-size:10.5px; font-weight:600; color:var(--t3); text-transform:uppercase; letter-spacing:.4px; margin-bottom:12px; }
        .p-head { display:flex; align-items:center; gap:10px; padding:10px 14px; border-radius:12px; background:#fff; border:1px solid #eee; border-left:5px solid var(--red); margin-bottom:14px; }
        .p-head i { color:var(--red); font-size:16px; }
        .p-head b { font-size:14px; }
        .p-row { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
        .p-btn { display:inline-flex; align-items:center; gap:7px; padding:10px 18px; border-radius:10px; background:var(--red); color:#fff; font-size:13px; font-weight:600; }
        .p-chip { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:100px; font-size:12px; font-weight:600; color:var(--red); background:var(--red-soft); }
        /* Satır (toggle / segment) */
        .row { display:flex; align-items:center; gap:14px; padding:13px 0; border-top:1px solid #f1f3f5; }
        .row:first-of-type { border-top:none; }
        .row .r-ico { width:40px; height:40px; border-radius:11px; background:var(--red-soft); color:var(--red); display:inline-flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; }
        .row .r-txt { flex:1; min-width:0; }
        .row .r-txt b { font-size:14px; font-weight:600; display:block; }
        .row .r-txt span { font-size:12px; color:var(--t2); }
        /* Toggle switch */
        .switch { position:relative; width:52px; height:30px; flex-shrink:0; cursor:pointer; }
        .switch input { position:absolute; opacity:0; width:0; height:0; }
        .switch .slider { position:absolute; inset:0; background:#d1d5db; border-radius:999px; transition:.2s; }
        .switch .slider::before { content:''; position:absolute; width:24px; height:24px; left:3px; top:3px; background:#fff; border-radius:50%; transition:.2s; box-shadow:0 1px 3px rgba(0,0,0,.2); }
        .switch input:checked + .slider { background:var(--red); }
        .switch input:checked + .slider::before { transform:translateX(22px); }
        /* Segmented */
        .seg { display:inline-flex; background:#f3f4f6; border-radius:10px; padding:3px; gap:2px; flex-shrink:0; }
        .seg button { border:none; background:transparent; font-family:inherit; font-size:12.5px; font-weight:600; color:var(--t2); padding:7px 14px; border-radius:8px; cursor:pointer; }
        .seg button.on { background:var(--red); color:#fff; }
        @media (max-width:560px){ .row.row-yazi{ flex-wrap:wrap; } .row.row-yazi #seg-yazi{ display:flex; flex-basis:100%; margin-top:8px; } .row.row-yazi #seg-yazi button{ flex:1; padding:8px 4px; } }
        /* Açılış seçenekleri */
        .opt { display:flex; align-items:center; gap:12px; padding:12px 14px; border:1.5px solid var(--border); border-radius:12px; cursor:pointer; margin-bottom:8px; transition:.15s; }
        .opt:hover { border-color:var(--red); }
        .opt.on { border-color:var(--red); background:var(--red-soft); }
        .opt i.lead { width:34px; height:34px; border-radius:9px; background:#fff; border:1px solid var(--border); color:var(--t2); display:inline-flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0; }
        .opt.on i.lead { background:var(--red); color:#fff; border-color:var(--red); }
        .opt b { flex:1; font-size:13.5px; font-weight:600; }
        .opt .tick { width:22px; height:22px; border-radius:50%; border:2px solid var(--border); display:inline-flex; align-items:center; justify-content:center; color:#fff; font-size:10px; flex-shrink:0; }
        .opt.on .tick { background:var(--red); border-color:var(--red); }
        .hint { font-size:12px; color:var(--t2); line-height:1.55; margin-top:12px; }
        .hint b { color:var(--t1); }
        #toast { position:fixed; left:50%; bottom:24px; transform:translateX(-50%) translateY(20px); background:var(--t1); color:#fff; padding:11px 20px; border-radius:12px; font-size:13px; font-weight:600; opacity:0; pointer-events:none; transition:.25s; z-index:60; display:flex; align-items:center; gap:8px; }
        #toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
        @media (max-width:560px){ .swatches { gap:12px; justify-content:space-between; } .sw .dot { width:44px; height:44px; } }
    </style>
</head>
<body>
    <header class="top">
        <div class="top-in">
            <a href="../index.php" class="geri" aria-label="Geri"><i class="fa fa-arrow-left"></i></a>
            <span class="top-ico"><i class="fa-solid fa-sliders"></i></span>
            <span class="top-title">Kişiselleştirme <small>Sadece senin ekranın · yönetici ayarı değil</small></span>
        </div>
    </header>
    <main>
        <!-- RENK -->
        <div class="card">
            <div class="sec"><i class="fa-solid fa-droplet"></i> Vurgu Rengi</div>
            <div class="swatches" id="sws">
                <?php foreach ($palet as $key => [$hex, $soft]): ?>
                <button type="button" class="sw<?php echo $key === $renkGoster ? ' on' : ''; ?>"
                        data-key="<?php echo $h($key); ?>" data-hex="<?php echo $h($hex); ?>" data-soft="<?php echo $h($soft); ?>">
                    <span class="dot" style="background:<?php echo $h($hex); ?>"></span>
                    <span class="ad"><?php echo $h($renkAd[$key] ?? $key); ?></span>
                </button>
                <?php endforeach; ?>
            </div>
            <div class="prev">
                <div class="prev-lbl">Canlı önizleme</div>
                <div class="p-head"><i class="fa-solid fa-file-invoice"></i><b>Sipariş Fişi</b></div>
                <div class="p-row">
                    <span class="p-btn"><i class="fa-solid fa-plus"></i> Fişe Ekle</span>
                    <span class="p-chip"><i class="fa-solid fa-tag"></i> İskonto</span>
                </div>
            </div>
        </div>

        <!-- GÖRÜNÜM -->
        <div class="card">
            <div class="sec"><i class="fa-solid fa-eye"></i> Görünüm</div>
            <?php if ($koyuTemaAktif): ?>
            <div class="row">
                <span class="r-ico"><i class="fa-solid fa-moon"></i></span>
                <span class="r-txt"><b>Koyu tema</b><span>Ekranı koyulaştırır — gece/loş ortamda göz yormaz.</span></span>
                <label class="switch"><input type="checkbox" id="tg-koyu" <?php echo $koyu ? 'checked' : ''; ?>><span class="slider"></span></label>
            </div>
            <?php endif; ?>
            <div class="row row-yazi">
                <span class="r-ico"><i class="fa-solid fa-text-height"></i></span>
                <span class="r-txt"><b>Yazı boyutu</b><span>Ekrandaki tüm yazı ve butonları ölçekler — sahada okuma/dokunma kolaylığı.</span></span>
                <div class="seg" id="seg-yazi">
                    <button type="button" data-val="kucuk" class="<?php echo $yaziBoyut === 'kucuk' ? 'on' : ''; ?>">Küçük</button>
                    <button type="button" data-val="" class="<?php echo $yaziBoyut === '' ? 'on' : ''; ?>">Normal</button>
                    <button type="button" data-val="buyuk" class="<?php echo $yaziBoyut === 'buyuk' ? 'on' : ''; ?>">Büyük</button>
                    <button type="button" data-val="cokbuyuk" class="<?php echo $yaziBoyut === 'cokbuyuk' ? 'on' : ''; ?>">Çok Büyük</button>
                </div>
            </div>
            <div class="row">
                <span class="r-ico"><i class="fa-solid fa-image"></i></span>
                <span class="r-txt"><b>Hız modu (görselleri gizle)</b><span>Listelerde ürün görsellerini kapatır — yavaş bağlantıda daha hızlı.</span></span>
                <label class="switch"><input type="checkbox" id="tg-hiz" <?php echo $hiz ? 'checked' : ''; ?>><span class="slider"></span></label>
            </div>
            <div class="row">
                <span class="r-ico"><i class="fa-solid fa-table-list"></i></span>
                <span class="r-txt"><b>Liste yoğunluğu</b><span>Kompakt: ekrana daha çok satır. Rahat: geniş aralık.</span></span>
                <div class="seg" id="seg-yogun">
                    <button type="button" data-val="" class="<?php echo $yogun ? '' : 'on'; ?>">Rahat</button>
                    <button type="button" data-val="kompakt" class="<?php echo $yogun ? 'on' : ''; ?>">Kompakt</button>
                </div>
            </div>
            <div class="row">
                <span class="r-ico"><i class="fa-solid fa-grip"></i></span>
                <span class="r-txt"><b>Kayıt görünümü</b><span>Kart: geniş/görsel. Liste: kompakt tek satır, ekrana çok kayıt (şimdilik Siparişler sayfasında).</span></span>
                <div class="seg" id="seg-liste">
                    <button type="button" data-val="" class="<?php echo $liste ? '' : 'on'; ?>">Kart</button>
                    <button type="button" data-val="liste" class="<?php echo $liste ? 'on' : ''; ?>">Liste</button>
                </div>
            </div>
        </div>

        <!-- AÇILIŞ EKRANI -->
        <div class="card">
            <div class="sec"><i class="fa-solid fa-door-open"></i> Açılış Ekranı</div>
            <div id="acilis">
                <?php foreach ($acilisSecenek as $key => [$ad, $ico]): ?>
                <div class="opt<?php echo $key === $acilis ? ' on' : ''; ?>" data-val="<?php echo $h($key); ?>">
                    <i class="lead fa-solid <?php echo $h($ico); ?>"></i>
                    <b><?php echo $h($ad); ?></b>
                    <span class="tick"><i class="fa-solid fa-check"></i></span>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="hint">Giriş yaptığında doğrudan bu ekran açılır. <b>Ana Sayfa</b> seçiliyken normal panoya düşersin.</div>
        </div>
    </main>

    <div id="toast"><i class="fa-solid fa-check"></i> <span id="toast-msg">Kaydedildi</span></div>

    <script>
    (function () {
        var csrf = <?php echo json_encode($csrf); ?>;
        var toast = document.getElementById('toast');
        var toastMsg = document.getElementById('toast-msg');
        var toastTimer = null;
        var d = document.documentElement;
        function bildir(msg, hata) {
            toastMsg.textContent = msg;
            toast.style.background = hata ? '#b91c1c' : '#1f2937';
            toast.classList.add('show');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 1700);
        }
        function kaydet(anahtar, deger) {
            var body = new URLSearchParams({ ajax: '1', anahtar: anahtar, deger: deger, csrf_token: csrf });
            return fetch('gorunum.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
                .then(function (r) { return r.json(); })
                .then(function (j) { bildir(j.ok ? 'Kaydedildi' : (j.mesaj || 'Kaydedilemedi'), !j.ok); return j; })
                .catch(function () { bildir('Bağlantı hatası', true); return { ok: false }; });
        }

        // Renk
        document.querySelectorAll('#sws .sw').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('#sws .sw').forEach(function (x) { x.classList.remove('on'); });
                btn.classList.add('on');
                d.style.setProperty('--red', btn.getAttribute('data-hex'));
                d.style.setProperty('--red-soft', btn.getAttribute('data-soft'));
                var m = document.querySelector('meta[name="theme-color"]');
                if (m) { m.setAttribute('content', btn.getAttribute('data-hex')); }
                kaydet('tema_vurgu', btn.getAttribute('data-key'));
            });
        });

        // Yazı boyutu (4 seviyeli segment)
        document.querySelectorAll('#seg-yazi button').forEach(function (b) {
            b.addEventListener('click', function () {
                document.querySelectorAll('#seg-yazi button').forEach(function (x) { x.classList.remove('on'); });
                b.classList.add('on');
                var val = b.getAttribute('data-val');
                d.classList.remove('lumen-yazi-kucuk', 'lumen-yazi-buyuk', 'lumen-yazi-cokbuyuk', 'lumen-buyuk');
                if (val) { d.classList.add('lumen-yazi-' + val); }
                kaydet('gor_yazi', val);
            });
        });
        // Hız modu
        document.getElementById('tg-hiz').addEventListener('change', function () {
            d.classList.toggle('lumen-hiz', this.checked);
            kaydet('gor_hiz', this.checked ? '1' : '');
        });
        // Koyu tema (rafa kaldırıldıysa toggle DOM'da yok)
        var __tkoyu = document.getElementById('tg-koyu');
        if (__tkoyu) __tkoyu.addEventListener('change', function () {
            d.classList.toggle('lumen-dark', this.checked);
            if (this.checked) {
                var hex = (getComputedStyle(d).getPropertyValue('--red') || '#6F1022').trim().replace('#', '');
                var m = hex.match(/^([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i);
                if (m) { d.style.setProperty('--red-soft', 'rgba(' + parseInt(m[1], 16) + ',' + parseInt(m[2], 16) + ',' + parseInt(m[3], 16) + ',.2)'); }
            } else {
                d.style.removeProperty('--red-soft');
            }
            kaydet('gor_koyu', this.checked ? '1' : '');
        });
        // Liste yoğunluğu
        document.querySelectorAll('#seg-yogun button').forEach(function (b) {
            b.addEventListener('click', function () {
                document.querySelectorAll('#seg-yogun button').forEach(function (x) { x.classList.remove('on'); });
                b.classList.add('on');
                var val = b.getAttribute('data-val');
                d.classList.toggle('lumen-kompakt', val === 'kompakt');
                kaydet('gor_yogunluk', val);
            });
        });
        // Kayıt görünümü (kart/liste) — etkiyi ilgili liste sayfalarında gösterir (bu sayfada önizleme yok)
        document.querySelectorAll('#seg-liste button').forEach(function (b) {
            b.addEventListener('click', function () {
                document.querySelectorAll('#seg-liste button').forEach(function (x) { x.classList.remove('on'); });
                b.classList.add('on');
                var val = b.getAttribute('data-val');
                d.classList.toggle('lumen-liste', val === 'liste');
                kaydet('gor_liste', val);
            });
        });
        // Açılış ekranı
        document.querySelectorAll('#acilis .opt').forEach(function (o) {
            o.addEventListener('click', function () {
                document.querySelectorAll('#acilis .opt').forEach(function (x) { x.classList.remove('on'); });
                o.classList.add('on');
                kaydet('gor_acilis', o.getAttribute('data-val'));
            });
        });
    })();
    </script>
</body>
</html>
