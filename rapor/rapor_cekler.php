<?php

declare(strict_types=1);

/**
 * rapor_cekler.php — "Nakit Akışı Kokpiti" (yeniden tasarım 2026-07-07).
 *
 * Üstte kompakt şerit (Bu Ay Vadesi Gelen + geciken uyarısı) + tam genişlik
 * birleşik VADE ZAMAN ÇİZELGESİ (alınan + kendi çekler tek akışta, vade grubuna
 * göre: Gecikmiş → Bugün → Bu Hafta → Bu Ay → gelecek aylar; grup toplamları +
 * filtre çipleri). Not: "Net pozisyon" kartı + 6 aylık dağılım grafiği kullanıcı
 * geri bildirimiyle KALDIRILDI (2026-07-07) — liste kendini anlatıyor.
 * Veri sorguları değişmedi (CURRSTAT=1 DOC=1 alınan / CURRSTAT=9 kendi).
 * Kimlik: Lumen bordosu (var(--red) vurgu sistemine uyumlu).
 */

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$para = static fn($x): string => number_format((float) $x, 2, ',', '.');
$kisa = static function ($v): string {
    $v = (float) $v;
    if (abs($v) >= 1_000_000) { return number_format($v / 1_000_000, 2, ',', '.') . ' M'; }
    if (abs($v) >= 1_000)     { return number_format($v / 1_000, 0, ',', '.') . ' B'; }
    return number_format($v, 0, ',', '.');
};
$tarihD = static function ($d): string {
    if (!$d) { return '-'; }
    $t = strtotime((string) $d);
    return $t ? date('d.m.Y', $t) : '-';
};

// --- Alınan çekler (portföydeki müşteri çekleri) = ALACAK ---
$alinan = [];
try {
    $sqlA = "
        SELECT CAST(C.DUEDATE AS DATE) AS VADE, C.TRNET AS TUTAR, C.OWING AS KIMDEN,
               C.NEWSERINO AS SERI, C.DOC AS DOC,
               DATEDIFF(DAY, CAST(GETDATE() AS DATE), CAST(C.DUEDATE AS DATE)) AS GUN,
               ISNULL(CL.DEFINITION_, '') AS CARI
        FROM {$firmadonem}CSCARD C WITH(NOLOCK)
        LEFT JOIN (
            SELECT T.CSREF, T.CARDREF, ROW_NUMBER() OVER (PARTITION BY T.CSREF ORDER BY T.LOGICALREF DESC) AS RN
            FROM {$firmadonem}CSTRANS T WITH(NOLOCK)
        ) TX ON TX.CSREF = C.LOGICALREF AND TX.RN = 1
        LEFT JOIN {$firma}CLCARD CL WITH(NOLOCK) ON CL.LOGICALREF = TX.CARDREF
        WHERE C.CURRSTAT = 1 AND C.STATUS IN (0,1) AND C.DOC = 1
        ORDER BY C.DUEDATE ASC
    ";
    $alinan = $dbh->query($sqlA)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('rapor_cekler alinan: ' . $e->getMessage());
}

// --- Kendi çeklerimiz (verdiğimiz çekler) = BORÇ ---
$kendi = [];
try {
    $sqlK = "
        SELECT CAST(C.DUEDATE AS DATE) AS VADE, C.AMOUNT AS TUTAR, C.NEWSERINO AS SERI, C.DOC AS DOC,
               DATEDIFF(DAY, CAST(GETDATE() AS DATE), CAST(C.DUEDATE AS DATE)) AS GUN,
               ISNULL(CL.DEFINITION_, '') AS CARI, ISNULL(R.GENEXP1, '') AS ACIKLAMA
        FROM {$firmadonem}CSCARD C WITH(NOLOCK)
        INNER JOIN (
            SELECT T.CSREF, T.CARDREF, T.ROLLREF, ROW_NUMBER() OVER (PARTITION BY T.CSREF ORDER BY T.LOGICALREF DESC) AS RN
            FROM {$firmadonem}CSTRANS T WITH(NOLOCK)
            WHERE T.TRCODE IN (2,3,4,5,6,7,8)
        ) TX ON TX.CSREF = C.LOGICALREF AND TX.RN = 1
        LEFT JOIN {$firmadonem}CSROLL R WITH(NOLOCK) ON R.LOGICALREF = TX.ROLLREF
        LEFT JOIN {$firma}CLCARD CL WITH(NOLOCK) ON CL.LOGICALREF = TX.CARDREF
        WHERE C.CURRSTAT = 9
        ORDER BY C.DUEDATE ASC
    ";
    $kendi = $dbh->query($sqlK)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('rapor_cekler kendi: ' . $e->getMessage());
}

// --- Birleşik akış + özetler ---
$ayBasi = date('Y-m-01');
$aySonu = date('Y-m-t');
$buYilAy = date('Y-m');

$tum = [];
$alacakTop = $borcTop = $alacakBuAy = $borcBuAy = 0.0;
$gecikenA = ['adet' => 0, 'tutar' => 0.0];
$gecikenB = ['adet' => 0, 'tutar' => 0.0];

foreach ($alinan as $a) {
    $t = (float) $a['TUTAR'];
    $alacakTop += $t;
    if (!empty($a['VADE']) && $a['VADE'] >= $ayBasi && $a['VADE'] <= $aySonu) { $alacakBuAy += $t; }
    if ((int) $a['GUN'] < 0) { $gecikenA['adet']++; $gecikenA['tutar'] += $t; }
    $tum[] = ['TIP' => 'A', 'VADE' => $a['VADE'], 'GUN' => (int) $a['GUN'], 'TUTAR' => $t,
              'AD' => $a['KIMDEN'] ?: ($a['CARI'] ?: 'Bilinmiyor'), 'SERI' => (string) $a['SERI']];
}
foreach ($kendi as $k) {
    $t = (float) $k['TUTAR'];
    $borcTop += $t;
    if (!empty($k['VADE']) && $k['VADE'] >= $ayBasi && $k['VADE'] <= $aySonu) { $borcBuAy += $t; }
    if ((int) $k['GUN'] < 0) { $gecikenB['adet']++; $gecikenB['tutar'] += $t; }
    $tum[] = ['TIP' => 'B', 'VADE' => $k['VADE'], 'GUN' => (int) $k['GUN'], 'TUTAR' => $t,
              'AD' => $k['CARI'] ?: ($k['ACIKLAMA'] ?: ((int) $k['DOC'] === 2 ? 'Borç Senedi' : 'Kendi Çekimiz')),
              'SERI' => (string) $k['SERI']];
}

// Vadeye göre sırala (tarihsizler sona)
usort($tum, static function ($x, $y) {
    $vx = $x['VADE'] ?: '9999-12-31';
    $vy = $y['VADE'] ?: '9999-12-31';
    return strcmp($vx, $vy);
});

// Vade grupları: gecikmiş → bugün → bu hafta → bu ay → her gelecek ay → tarihsiz
$ayAdlari = [1=>'Ocak',2=>'Şubat',3=>'Mart',4=>'Nisan',5=>'Mayıs',6=>'Haziran',7=>'Temmuz',8=>'Ağustos',9=>'Eylül',10=>'Ekim',11=>'Kasım',12=>'Aralık'];
$gruplar = [];   // key => ['baslik','ikon','sinif','satirlar'=>[],'a'=>0,'b'=>0]
$grupEkle = static function (array $c) use (&$gruplar, $ayAdlari, $buYilAy): void {
    $gun = $c['GUN'];
    if (empty($c['VADE'])) {
        $key = 'z-tarihsiz'; $baslik = 'Vadesi Belirsiz'; $ikon = 'fa-circle-question'; $sinif = 'gri';
    } elseif ($gun < 0) {
        $key = '0-gecikmis'; $baslik = 'Vadesi Geçmiş'; $ikon = 'fa-triangle-exclamation'; $sinif = 'tehlike';
    } elseif ($gun === 0) {
        $key = '1-bugun'; $baslik = 'Bugün'; $ikon = 'fa-bolt'; $sinif = 'sicak';
    } elseif ($gun <= 7) {
        $key = '2-buhafta'; $baslik = 'Bu Hafta'; $ikon = 'fa-hourglass-half'; $sinif = 'sicak';
    } elseif (substr((string) $c['VADE'], 0, 7) === $buYilAy) {
        $key = '3-buay'; $baslik = 'Bu Ay'; $ikon = 'fa-calendar-day'; $sinif = 'normal';
    } else {
        $ay = (int) substr((string) $c['VADE'], 5, 2);
        $yil = substr((string) $c['VADE'], 0, 4);
        $key = '4-' . substr((string) $c['VADE'], 0, 7);
        $baslik = $ayAdlari[$ay] . ' ' . $yil; $ikon = 'fa-calendar'; $sinif = 'normal';
    }
    if (!isset($gruplar[$key])) {
        $gruplar[$key] = ['baslik' => $baslik, 'ikon' => $ikon, 'sinif' => $sinif, 'satirlar' => [], 'a' => 0.0, 'b' => 0.0];
    }
    $gruplar[$key]['satirlar'][] = $c;
    if ($c['TIP'] === 'A') { $gruplar[$key]['a'] += $c['TUTAR']; } else { $gruplar[$key]['b'] += $c['TUTAR']; }
};
foreach ($tum as $c) { $grupEkle($c); }
ksort($gruplar);

$rozet = static function (int $gun, $vade): array {
    if (empty($vade)) { return ['gri', 'tarih yok']; }
    if ($gun < 0)  { return ['kirmizi', abs($gun) . ' gün geçti']; }
    if ($gun === 0) { return ['kirmizi', 'BUGÜN']; }
    if ($gun <= 15) { return ['amber', $gun . ' gün kaldı']; }
    return ['gri', $gun . ' gün'];
};
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Çekler — Nakit Akışı</title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:#f7f6f8; --card:#fff; --text-1:#1c1522; --text-2:#6b6472; --text-3:#9b93a3; --border:#eae5ee;
            --red:#6F1022; --red-koyu:#7f1d1d; --red-orta:#b91c1c; --red-soft:#fdf0f0;
            --emerald:#059669; --emerald-soft:#ecfdf5;
            --amber:#d97706; --amber-soft:#fffbeb;
            --golge:0 12px 32px -20px rgba(28,21,34,.35);
        }
        * { box-sizing:border-box; margin:0; }
        body {
            font-family:'Avenir Next','Montserrat',sans-serif; color:var(--text-1); min-height:100vh; font-size:14px;
            background:
                radial-gradient(800px 280px at 100% -80px, rgba(111,16,34,.07), transparent 60%),
                radial-gradient(600px 240px at -60px 30%, rgba(111,16,34,.05), transparent 55%),
                var(--bg);
        }

        .top { position:sticky; top:0; z-index:40; background:rgba(255,255,255,.85); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); border-bottom:1px solid var(--border); }
        .top-in { max-width:1180px; margin:0 auto; padding:12px 22px; display:flex; align-items:center; gap:12px; }
        .geri { width:38px; height:38px; border-radius:11px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; color:var(--text-2); background:#fff; border:1px solid var(--border); text-decoration:none; transition:.18s; }
        .geri:hover { color:var(--red); border-color:var(--red); transform:translateX(-2px); }
        .t-ico { width:40px; height:40px; border-radius:12px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; font-size:17px; background:linear-gradient(135deg,var(--red-orta),var(--red-koyu)); color:#fff; box-shadow:0 6px 14px -6px rgba(185,28,28,.5); }
        .t-baslik h1 { font-size:16px; font-weight:700; line-height:1.2; }
        .t-baslik p { font-size:11.5px; color:var(--text-2); }

        main { max-width:1180px; margin:0 auto; padding:22px 22px 60px; }

        /* ═══ ÜST ŞERİT: bu ay + geciken ═══ */
        .ust-serit { display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:12px; margin-bottom:16px; }
        .kok-kart { background:var(--card); border:1px solid var(--border); border-radius:16px; padding:15px 17px; box-shadow:var(--golge); animation:giris .5s cubic-bezier(.22,1,.36,1) both; }
        .kok-bas { font-size:11px; font-weight:700; letter-spacing:.5px; text-transform:uppercase; color:var(--text-3); display:flex; align-items:center; gap:7px; margin-bottom:10px; }
        .kok-bas i { color:var(--red); }

        .mini-satirlar { display:flex; gap:8px 22px; flex-wrap:wrap; }
        .mini { display:flex; align-items:center; gap:10px; font-size:12px; }
        .mini i { width:30px; height:30px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center; font-size:12px; flex-shrink:0; }
        .mini.yesil i { background:var(--emerald-soft); color:var(--emerald); }
        .mini.kirmizi i { background:var(--red-soft); color:var(--red); }
        .mini.amber i { background:var(--amber-soft); color:var(--amber); }
        .mini b { font-weight:800; white-space:nowrap; }
        .mini .etiket { color:var(--text-2); }

        .uyari-geciken { display:flex; align-items:center; gap:10px; background:var(--red-soft); border:1px solid #f5c6c6; border-radius:14px; padding:12px 14px; font-size:12px; color:#7f1d1d; animation:giris .5s cubic-bezier(.22,1,.36,1) .1s both; }
        .uyari-geciken i { font-size:16px; color:var(--red); }
        .uyari-geciken b { font-weight:800; }

        /* ═══ SAĞ: VADE ZAMAN ÇİZELGESİ ═══ */
        .akis { min-width:0; }
        .filtreler { display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap; animation:giris .5s cubic-bezier(.22,1,.36,1) .08s both; }
        .cip { display:inline-flex; align-items:center; gap:7px; padding:9px 15px; border-radius:99px; border:1.5px solid var(--border); background:#fff; color:var(--text-2); font-family:inherit; font-size:12.5px; font-weight:700; cursor:pointer; transition:.16s; }
        .cip .n { font-size:11px; padding:1px 7px; border-radius:99px; background:#f1edf4; }
        .cip:hover { border-color:var(--red); color:var(--red); }
        .cip.on { background:var(--red); border-color:var(--red); color:#fff; box-shadow:0 6px 14px -8px rgba(111,16,34,.55); }
        .cip.on .n { background:rgba(255,255,255,.22); }

        .zaman { position:relative; padding-left:26px; }
        .zaman::before { content:''; position:absolute; left:8px; top:8px; bottom:8px; width:2px; background:linear-gradient(180deg,var(--red) 0%,#e8d8dc 30%,#e8e2ec 100%); border-radius:2px; }
        .grup { margin-bottom:18px; animation:giris .45s cubic-bezier(.22,1,.36,1) both; }
        .grup-bas { position:relative; display:flex; align-items:center; gap:10px; margin-bottom:8px; flex-wrap:wrap; }
        .grup-nokta { position:absolute; left:-26px; top:50%; transform:translateY(-50%); width:18px; height:18px; border-radius:50%; background:#fff; border:2px solid var(--text-3); display:flex; align-items:center; justify-content:center; }
        .grup-nokta::after { content:''; width:6px; height:6px; border-radius:50%; background:var(--text-3); }
        .grup.tehlike .grup-nokta { border-color:var(--red); } .grup.tehlike .grup-nokta::after { background:var(--red); }
        .grup.sicak .grup-nokta { border-color:var(--amber); } .grup.sicak .grup-nokta::after { background:var(--amber); }
        .grup-adi { font-size:13.5px; font-weight:800; display:flex; align-items:center; gap:8px; }
        .grup.tehlike .grup-adi { color:var(--red); }
        .grup.sicak .grup-adi { color:var(--amber); }
        .grup-toplam { margin-left:auto; display:flex; gap:6px; font-size:11px; font-weight:700; }
        .gt { padding:3px 9px; border-radius:99px; }
        .gt.a { background:var(--emerald-soft); color:var(--emerald); }
        .gt.b { background:var(--red-soft); color:var(--red); }

        .cekler { background:var(--card); border:1px solid var(--border); border-radius:16px; overflow:hidden; box-shadow:var(--golge); }
        .cek {
            display:grid; grid-template-columns:4px minmax(0,1fr) auto; gap:0 14px; align-items:center;
            padding:0 16px 0 0; border-bottom:1px solid #f4f0f6; transition:background .14s;
        }
        .cek:last-child { border-bottom:none; }
        .cek:hover { background:#fbf9fc; }
        .cek .serit { align-self:stretch; }
        .cek.a .serit { background:linear-gradient(180deg,#34d399,#059669); }
        .cek.b .serit { background:linear-gradient(180deg,#f87171,var(--red)); }
        .cek-ic { padding:12px 0 12px 14px; min-width:0; }
        .cek-ad { font-size:13.5px; font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:flex; align-items:center; gap:8px; }
        .yon-etiket { font-size:9.5px; font-weight:800; letter-spacing:.5px; padding:2px 7px; border-radius:6px; flex-shrink:0; }
        .cek.a .yon-etiket { background:var(--emerald-soft); color:var(--emerald); }
        .cek.b .yon-etiket { background:var(--red-soft); color:var(--red); }
        .cek-meta { font-size:11.5px; color:var(--text-3); margin-top:2px; display:flex; gap:12px; flex-wrap:wrap; }
        .cek-sag { text-align:right; padding:12px 0; }
        .cek-tutar { font-size:14.5px; font-weight:800; white-space:nowrap; }
        .cek.a .cek-tutar { color:var(--emerald); }
        .cek.b .cek-tutar { color:var(--red); }
        .rozet { display:inline-block; margin-top:3px; font-size:10px; font-weight:700; padding:2px 8px; border-radius:99px; white-space:nowrap; }
        .rozet.kirmizi { background:var(--red-soft); color:var(--red); }
        .rozet.amber { background:var(--amber-soft); color:var(--amber); }
        .rozet.gri { background:#f3f0f5; color:#6b6472; }

        .bos-genel { background:var(--card); border:1px solid var(--border); border-radius:16px; padding:56px 20px; text-align:center; color:var(--text-3); box-shadow:var(--golge); }
        .bos-genel i { font-size:38px; display:block; margin-bottom:12px; color:#e3d5da; }
        .bos-genel b { display:block; color:var(--text-2); font-size:15px; margin-bottom:4px; }

        @keyframes giris { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:none; } }

        @media (max-width:560px) {
            main { padding:14px 14px 50px; }
            .mini-satirlar { flex-direction:column; }
            .grup-toplam { margin-left:0; }
            .cek { grid-template-columns:4px minmax(0,1fr) auto; }
            .cek-meta { gap:8px; }
        }
        @media print {
            body { background:#fff; }
            .top, .filtreler { display:none !important; }
            .cekler, .kok-kart { box-shadow:none; break-inside:avoid; }
        }
        html.lumen-dark .kok-kart, html.lumen-dark .cekler, html.lumen-dark .cip, html.lumen-dark .geri { background:var(--card); }
    </style>
</head>
<body>
    <header class="top">
        <div class="top-in">
            <a href="dashboard.php" class="geri" title="Rapor listesine dön"><i class="fa-solid fa-arrow-left"></i></a>
            <span class="t-ico"><i class="fa-solid fa-money-check-dollar"></i></span>
            <div class="t-baslik">
                <h1>Çekler — Nakit Akışı</h1>
                <p>alınan + kendi çekler · vade zaman çizelgesi</p>
            </div>
        </div>
    </header>

    <main>
        <!-- ═══ ÜST ŞERİT: bu ay + geciken ═══ -->
        <div class="ust-serit">
            <div class="kok-kart">
                <div class="kok-bas"><i class="fa-regular fa-calendar"></i> Bu Ay Vadesi Gelen</div>
                <div class="mini-satirlar">
                    <div class="mini yesil"><i class="fa-solid fa-arrow-down"></i><span class="etiket">Tahsil edilecek</span><b><?php echo $para($alacakBuAy); ?> ₺</b></div>
                    <div class="mini kirmizi"><i class="fa-solid fa-arrow-up"></i><span class="etiket">Ödenecek</span><b><?php echo $para($borcBuAy); ?> ₺</b></div>
                    <div class="mini amber"><i class="fa-solid fa-equals"></i><span class="etiket">Ay net</span><b><?php echo ($alacakBuAy - $borcBuAy < 0 ? '−' : '') . $para(abs($alacakBuAy - $borcBuAy)); ?> ₺</b></div>
                </div>
            </div>
            <?php if ($gecikenA['adet'] + $gecikenB['adet'] > 0): ?>
            <div class="uyari-geciken">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span><b><?php echo $gecikenA['adet'] + $gecikenB['adet']; ?> çekin vadesi geçti</b><br>
                <?php if ($gecikenA['adet']): ?>alacak: <?php echo $kisa($gecikenA['tutar']); ?> ₺<?php endif; ?>
                <?php if ($gecikenA['adet'] && $gecikenB['adet']): ?> · <?php endif; ?>
                <?php if ($gecikenB['adet']): ?>borç: <?php echo $kisa($gecikenB['tutar']); ?> ₺<?php endif; ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- ═══ VADE ZAMAN ÇİZELGESİ ═══ -->
        <section class="akis">
                <?php if (empty($tum)): ?>
                    <div class="bos-genel">
                        <i class="fa-regular fa-circle-check"></i>
                        <b>Açık çek yok</b>
                        Portföyde alınan çek ya da verilmiş kendi çekimiz bulunmuyor.
                    </div>
                <?php else: ?>
                    <div class="filtreler">
                        <button type="button" class="cip on" data-f="hepsi"><i class="fa-solid fa-layer-group"></i> Tümü <span class="n"><?php echo count($tum); ?></span></button>
                        <button type="button" class="cip" data-f="a"><i class="fa-solid fa-arrow-down"></i> Alınan <span class="n"><?php echo count($alinan); ?></span></button>
                        <button type="button" class="cip" data-f="b"><i class="fa-solid fa-arrow-up"></i> Kendi <span class="n"><?php echo count($kendi); ?></span></button>
                    </div>

                    <div class="zaman">
                        <?php $gi = 0; foreach ($gruplar as $g): ?>
                        <div class="grup <?php echo $h($g['sinif']); ?>" style="animation-delay:<?php echo min(300, $gi * 60); ?>ms;">
                            <div class="grup-bas">
                                <span class="grup-nokta"></span>
                                <span class="grup-adi"><i class="fa-solid <?php echo $h($g['ikon']); ?>"></i> <?php echo $h($g['baslik']); ?></span>
                                <span class="grup-toplam">
                                    <?php if ($g['a'] > 0): ?><span class="gt a">↓ <?php echo $kisa($g['a']); ?> ₺</span><?php endif; ?>
                                    <?php if ($g['b'] > 0): ?><span class="gt b">↑ <?php echo $kisa($g['b']); ?> ₺</span><?php endif; ?>
                                </span>
                            </div>
                            <div class="cekler">
                                <?php foreach ($g['satirlar'] as $c):
                                    [$rc, $rt] = $rozet($c['GUN'], $c['VADE']); ?>
                                <div class="cek <?php echo $c['TIP'] === 'A' ? 'a' : 'b'; ?>" data-tip="<?php echo $c['TIP'] === 'A' ? 'a' : 'b'; ?>">
                                    <span class="serit"></span>
                                    <div class="cek-ic">
                                        <div class="cek-ad">
                                            <span class="yon-etiket"><?php echo $c['TIP'] === 'A' ? 'ALINAN' : 'KENDİ'; ?></span>
                                            <?php echo $h($c['AD']); ?>
                                        </div>
                                        <div class="cek-meta">
                                            <span><i class="fa-regular fa-calendar" style="margin-right:3px;"></i><?php echo $tarihD($c['VADE']); ?></span>
                                            <?php if ($c['SERI'] !== ''): ?><span><i class="fa-solid fa-hashtag" style="margin-right:3px;"></i><?php echo $h($c['SERI']); ?></span><?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="cek-sag">
                                        <div class="cek-tutar"><?php echo ($c['TIP'] === 'A' ? '+' : '−') . $para($c['TUTAR']); ?> ₺</div>
                                        <span class="rozet <?php echo $rc; ?>"><?php echo $rt; ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php $gi++; endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
    </main>

    <script>
    (function () {
        var cipler = document.querySelectorAll('.cip');
        cipler.forEach(function (cip) {
            cip.addEventListener('click', function () {
                cipler.forEach(function (x) { x.classList.remove('on'); });
                cip.classList.add('on');
                var f = cip.getAttribute('data-f');
                document.querySelectorAll('.cek').forEach(function (r) {
                    r.style.display = (f === 'hepsi' || r.getAttribute('data-tip') === f) ? '' : 'none';
                });
                // içi tamamen boşalan grupları gizle
                document.querySelectorAll('.grup').forEach(function (g) {
                    var gorunur = [...g.querySelectorAll('.cek')].some(function (r) { return r.style.display !== 'none'; });
                    g.style.display = gorunur ? '' : 'none';
                });
            });
        });
    })();
    </script>
</body>
</html>
