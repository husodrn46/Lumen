<?php
declare(strict_types=1);

/**
 * rapor_musteriler_top.php — "Müşteri Ligi" (yeniden tasarım 2026-07-07).
 *
 * Eski: amber kimlik + Chart.js top-10 + simple-datatables.
 * Yeni: grafik YOK (liste konuşur) — PODYUM (ilk 3) + hücre-içi dolgulu LİG TABLOSU
 * + PARETO (kümülatif ciro payı, %80 bayrağı) + müşteriye özgü zekâ: fatura sayısı,
 * ortalama sepet ve SON ALIŞ rozeti (60+ gün sessiz büyük müşteri kırmızı yanar).
 * Metrik çipleri (Ciro/Fatura/Ort.Sepet) tabloyu CANLI yeniden sıralar.
 * Ciro tanımı DEĞİŞMEDİ (faturalı satış 7,8; tüm ürünler; LINENET+VATAMNT).
 */

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

$currentYear = (int) date('Y');
$year = isset($_GET['year']) && is_numeric($_GET['year']) ? (int) $_GET['year'] : $currentYear;

$stmt = $dbh->prepare("
  SELECT
    C.CODE           AS CUSTOMER_CODE,
    C.DEFINITION_    AS CUSTOMER_NAME,
    C.CITY           AS CITY,
    SUM(CASE WHEN L.TRCODE IN (7,8)
             THEN ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)
             ELSE -(ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)) END) AS TOTAL_SALES,
    COUNT(DISTINCT CASE WHEN I.TRCODE IN (7,8) THEN I.LOGICALREF END) AS FATURA_SAYISI,
    MAX(CASE WHEN I.TRCODE IN (7,8) THEN I.DATE_ END) AS SON_TARIH
  FROM {$firmadonem}INVOICE I WITH(NOLOCK)
  JOIN {$firmadonem}STLINE L ON L.INVOICEREF = I.LOGICALREF AND L.TRCODE IN (2,3,7,8)
  JOIN {$firma}CLCARD C ON I.CLIENTREF = C.LOGICALREF
  WHERE C.ACTIVE=0
    AND I.CANCELLED=0
    AND I.TRCODE IN (2,3,7,8)
    AND YEAR(I.DATE_) = :y
  GROUP BY C.CODE, C.DEFINITION_, C.CITY
  HAVING SUM(CASE WHEN L.TRCODE IN (7,8)
                  THEN ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)
                  ELSE -(ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)) END) > 0
  ORDER BY TOTAL_SALES DESC
");
$stmt->execute(['y' => $year]);

$bugun = new DateTimeImmutable('today');
$customers = [];
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $r['TOTAL_SALES'] = (float) $r['TOTAL_SALES'];
    $r['FATURA_SAYISI'] = (int) $r['FATURA_SAYISI'];
    $r['ORT_SEPET'] = $r['FATURA_SAYISI'] > 0 ? $r['TOTAL_SALES'] / $r['FATURA_SAYISI'] : 0.0;
    $sonT = $r['SON_TARIH'] ? new DateTimeImmutable(substr((string) $r['SON_TARIH'], 0, 10)) : null;
    $r['SON_GUN'] = $sonT ? (int) $sonT->diff($bugun)->format('%a') : -1;
    $r['SON_TARIH_TXT'] = $sonT ? $sonT->format('d.m.Y') : '-';
    $customers[] = $r;
}

$totalCustomers = count($customers);
$sumSales = (float) array_sum(array_column($customers, 'TOTAL_SALES'));
$sumFatura = (int) array_sum(array_column($customers, 'FATURA_SAYISI'));
$avgSale = $totalCustomers !== 0 ? $sumSales / $totalCustomers : 0.0;

// Dolgu zirveleri + pay/kümülatif (ciro sırasında) + Pareto
$maxCiro = 1.0; $maxFat = 1.0; $maxSep = 1.0;
foreach ($customers as $c) {
    $maxCiro = max($maxCiro, $c['TOTAL_SALES']);
    $maxFat  = max($maxFat, (float) $c['FATURA_SAYISI']);
    $maxSep  = max($maxSep, $c['ORT_SEPET']);
}
$kum = 0.0;
$pareto80 = 0;
foreach ($customers as $i => $c) {
    $pay = $sumSales > 0 ? 100 * $c['TOTAL_SALES'] / $sumSales : 0.0;
    $kum += $pay;
    $customers[$i]['PAY'] = $pay;
    $customers[$i]['KUM'] = $kum;
    if ($pareto80 === 0 && $kum >= 80.0) { $pareto80 = $i + 1; }
}
if ($pareto80 === 0) { $pareto80 = $totalCustomers; }

$top3 = array_slice($customers, 0, 3);
$guncelYilMi = ($year === $currentYear);

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$para = static fn($x): string => number_format((float) $x, 2, ',', '.');
$adetF = static fn($x): string => number_format((float) $x, 0, ',', '.');
$kisa = static function ($v): string {
    $v = (float) $v;
    if (abs($v) >= 1_000_000) { return number_format($v / 1_000_000, 2, ',', '.') . ' M'; }
    if (abs($v) >= 1_000)     { return number_format($v / 1_000, 0, ',', '.') . ' B'; }
    return number_format($v, 0, ',', '.');
};
// Son alış rozeti: yalnız cari yılda renkli sinyal (geçmiş yılda nötr)
$sonRozet = static function (int $gun, bool $guncel): array {
    if ($gun < 0) { return ['gri', '-']; }
    if (!$guncel) { return ['gri', $gun . ' gün']; }
    if ($gun <= 30) { return ['yesil', $gun . ' gün']; }
    if ($gun <= 60) { return ['sari', $gun . ' gün']; }
    return ['kirmizi', $gun . ' gün'];
};
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Müşteri Ligi — <?php echo $year; ?></title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:#f8f6f7; --card:#fff; --text-1:#1c1220; --text-2:#6d6276; --text-3:#9c92a5; --border:#ebe4ea;
            --red:#6F1022; --red-koyu:#7f1d1d; --red-orta:#b91c1c; --red-soft:#fdf0f0;
            --emerald:#059669; --amber:#d97706; --amber-soft:#fffbeb;
            --golge:0 12px 30px -20px rgba(28,18,32,.35);
        }
        * { box-sizing:border-box; margin:0; }
        body {
            font-family:'Avenir Next','Montserrat',sans-serif; color:var(--text-1); min-height:100vh; font-size:14px;
            background:
                radial-gradient(820px 280px at 100% -70px, rgba(111,16,34,.07), transparent 60%),
                radial-gradient(560px 220px at -60px 25%, rgba(245,158,11,.06), transparent 55%),
                var(--bg);
        }

        .top { position:sticky; top:0; z-index:40; background:rgba(255,255,255,.85); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); border-bottom:1px solid var(--border); }
        .top-in { max-width:1180px; margin:0 auto; padding:12px 22px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
        .geri { width:38px; height:38px; border-radius:11px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; color:var(--text-2); background:#fff; border:1px solid var(--border); text-decoration:none; transition:.18s; }
        .geri:hover { color:var(--red); border-color:var(--red); transform:translateX(-2px); }
        .t-ico { width:40px; height:40px; border-radius:12px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; font-size:17px; background:linear-gradient(135deg,var(--red-orta),var(--red-koyu)); color:#fff; box-shadow:0 6px 14px -6px rgba(185,28,28,.5); }
        .t-baslik h1 { font-size:16px; font-weight:700; line-height:1.2; }
        .t-baslik p { font-size:11.5px; color:var(--text-2); }
        .aksiyon { margin-left:auto; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .yil-sec { font-family:inherit; font-size:13.5px; font-weight:700; color:var(--text-1); background:#fff; border:1px solid var(--border); border-radius:11px; padding:9px 12px; cursor:pointer; outline:none; transition:.18s; }
        .yil-sec:focus { border-color:var(--red); box-shadow:0 0 0 3px rgba(111,16,34,.12); }
        .btn { display:inline-flex; align-items:center; gap:7px; font-family:inherit; font-size:12.5px; font-weight:600; padding:10px 14px; border-radius:11px; cursor:pointer; text-decoration:none; border:1px solid var(--border); background:#fff; color:var(--text-2); transition:.18s; }
        .btn:hover { color:var(--red); border-color:var(--red); transform:translateY(-1px); }
        .btn.birincil { background:var(--red); border-color:var(--red); color:#fff; box-shadow:0 6px 14px -8px rgba(111,16,34,.55); }
        .btn.birincil:hover { background:var(--red-orta); color:#fff; }

        main { max-width:1180px; margin:0 auto; padding:20px 22px 56px; }

        .vurgular { display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:12px; margin-bottom:16px; }
        .vurgu { background:var(--card); border:1px solid var(--border); border-radius:15px; padding:13px 16px; display:flex; align-items:center; gap:12px; box-shadow:var(--golge); animation:giris .5s cubic-bezier(.22,1,.36,1) both; }
        .vurgu:nth-child(2) { animation-delay:50ms; } .vurgu:nth-child(3) { animation-delay:100ms; } .vurgu:nth-child(4) { animation-delay:150ms; }
        .vurgu-ico { width:40px; height:40px; border-radius:12px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; font-size:16px; }
        .vurgu.kir .vurgu-ico { background:var(--red-soft); color:var(--red); }
        .vurgu.alt .vurgu-ico { background:var(--amber-soft); color:var(--amber); }
        .vurgu.yes .vurgu-ico { background:#ecfdf5; color:var(--emerald); }
        .vurgu.mor .vurgu-ico { background:#f5f3ff; color:#7c3aed; }
        .vurgu-l { font-size:10px; font-weight:700; letter-spacing:.4px; text-transform:uppercase; color:var(--text-3); }
        .vurgu-v { font-size:16.5px; font-weight:800; margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .vurgu-s { font-size:10.5px; color:var(--text-3); }

        /* PODYUM */
        .podyum { display:grid; grid-template-columns:1fr 1.15fr 1fr; gap:12px; margin-bottom:16px; align-items:end; }
        .pod { position:relative; background:var(--card); border:1px solid var(--border); border-radius:18px; padding:18px 16px 15px; text-align:center; box-shadow:var(--golge); animation:giris .55s cubic-bezier(.22,1,.36,1) both; }
        .pod.p1 { animation-delay:60ms; border-color:rgba(245,158,11,.45); background:linear-gradient(180deg,#fffdf5,#fff); padding-top:24px; padding-bottom:22px; }
        .pod.p2 { animation-delay:120ms; }
        .pod.p3 { animation-delay:180ms; }
        .pod-rozet { position:absolute; top:-13px; left:50%; transform:translateX(-50%); width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:12.5px; font-weight:800; color:#fff; box-shadow:0 4px 10px -3px rgba(28,18,32,.35); }
        .pod.p1 .pod-rozet { background:linear-gradient(135deg,#fbbf24,#d97706); width:36px; height:36px; top:-16px; font-size:15px; }
        .pod.p2 .pod-rozet { background:linear-gradient(135deg,#94a3b8,#64748b); }
        .pod.p3 .pod-rozet { background:linear-gradient(135deg,#d6a67c,#a16207); }
        .pod-ad { font-size:13.5px; font-weight:800; margin-top:4px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .pod.p1 .pod-ad { font-size:15px; }
        .pod-sehir { font-size:10.5px; color:var(--text-3); margin-top:2px; }
        .pod-tutar { font-size:17px; font-weight:800; margin-top:8px; color:var(--red-orta); white-space:nowrap; }
        .pod.p1 .pod-tutar { font-size:21px; color:var(--amber); }
        .pod-alt { display:flex; justify-content:center; gap:10px; margin-top:6px; font-size:10.5px; color:var(--text-3); flex-wrap:wrap; }
        .pod-alt b { color:var(--text-2); }

        /* LİG */
        .lig-kart { background:var(--card); border:1px solid var(--border); border-radius:16px; box-shadow:var(--golge); overflow:hidden; animation:giris .5s cubic-bezier(.22,1,.36,1) .15s both; }
        .lig-bas { padding:14px 18px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .lig-bas .baslik { font-size:13.5px; font-weight:700; display:inline-flex; align-items:center; gap:8px; }
        .lig-bas .baslik i { color:var(--red); }
        .siralayici { display:inline-flex; gap:6px; }
        .cip { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:99px; border:1.5px solid var(--border); background:#fff; color:var(--text-2); font-family:inherit; font-size:12px; font-weight:700; cursor:pointer; transition:.16s; }
        .cip:hover { border-color:var(--red); color:var(--red); }
        .cip.on { background:var(--red); border-color:var(--red); color:#fff; box-shadow:0 5px 12px -7px rgba(111,16,34,.55); }
        .ara-kutu { position:relative; margin-left:auto; flex:0 1 250px; min-width:170px; }
        .ara-kutu i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-3); font-size:12.5px; }
        .ara-kutu input { width:100%; padding:9px 12px 9px 33px; font-family:inherit; font-size:13.5px; border:1px solid var(--border); border-radius:10px; outline:none; transition:.16s; }
        .ara-kutu input:focus { border-color:var(--red); box-shadow:0 0 0 3px rgba(111,16,34,.1); }
        .kayit-not { font-size:11px; color:var(--text-3); font-weight:600; white-space:nowrap; }

        .tw { overflow-x:auto; }
        table.lig { width:100%; border-collapse:collapse; min-width:920px; }
        .lig th { padding:10px 14px; font-size:10px; font-weight:700; letter-spacing:.5px; text-transform:uppercase; color:var(--text-3); background:#fbf8fa; border-bottom:1px solid var(--border); text-align:right; white-space:nowrap; }
        .lig th:nth-child(1), .lig th:nth-child(2) { text-align:left; }
        .lig td { padding:9px 14px; border-bottom:1px solid #f5f1f4; font-size:12.5px; text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; vertical-align:middle; }
        .lig td:nth-child(1) { width:46px; }
        .lig td:nth-child(2) { text-align:left; max-width:330px; }
        .lig tr:hover td { background:#fdfbfc; }
        .sira { display:inline-flex; align-items:center; justify-content:center; min-width:27px; height:27px; padding:0 5px; border-radius:8px; background:#f4eff3; color:var(--text-2); font-size:11px; font-weight:800; }
        tr.ilk3 .sira { background:linear-gradient(135deg,#fbbf24,#d97706); color:#fff; }
        .m-ad { font-weight:700; font-size:12.5px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:320px; }
        .m-alt { font-size:10.5px; color:var(--text-3); display:flex; gap:8px; }
        .deger-hucre { position:relative; display:block; min-width:150px; padding:3px 0; }
        .deger-hucre .dolgu { position:absolute; inset:0 auto 0 0; border-radius:6px; background:rgba(111,16,34,.12); }
        .deger-hucre span { position:relative; padding-right:4px; font-weight:700; }
        .pay-t, .kum-t { color:var(--text-3); font-size:11.5px; }
        .p80 { display:inline-flex; align-items:center; gap:4px; margin-left:6px; font-size:9px; font-weight:800; letter-spacing:.4px; padding:2px 7px; border-radius:99px; background:var(--amber-soft); color:var(--amber); border:1px solid rgba(217,119,6,.3); }
        .son-rozet { display:inline-block; font-size:10px; font-weight:700; padding:2px 8px; border-radius:99px; }
        .son-rozet.yesil { background:#ecfdf5; color:var(--emerald); }
        .son-rozet.sari { background:var(--amber-soft); color:var(--amber); }
        .son-rozet.kirmizi { background:var(--red-soft); color:var(--red); }
        .son-rozet.gri { background:#f3f0f5; color:#6b6472; }
        .lig tfoot td { border-top:2px solid var(--border); background:#fbf8fa; font-weight:800; }

        .sayfalar { display:flex; justify-content:flex-end; gap:6px; padding:12px 16px; flex-wrap:wrap; }
        .sayfalar button { padding:7px 12px; font-family:inherit; font-size:12px; font-weight:700; background:#fff; color:var(--text-2); border:1px solid var(--border); border-radius:9px; cursor:pointer; transition:.14s; }
        .sayfalar button:hover:not(:disabled) { border-color:var(--red); color:var(--red); }
        .sayfalar button.on { background:var(--red); color:#fff; border-color:var(--red); }
        .sayfalar button:disabled { opacity:.4; cursor:not-allowed; }

        .bos-genel { padding:56px 20px; text-align:center; color:var(--text-3); }
        .bos-genel i { font-size:38px; display:block; margin-bottom:12px; color:#e6d8de; }

        @keyframes giris { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:none; } }

        @media (max-width:760px) {
            main { padding:14px 12px 46px; }
            .aksiyon { margin-left:0; width:100%; }
            .aksiyon .btn span { display:none; }
            .podyum { grid-template-columns:1fr; align-items:stretch; }
            .pod.p1 { order:-1; }
            .ara-kutu { flex:1 1 100%; margin-left:0; }
            .lig td:first-child, .lig th:first-child { position:sticky; left:0; background:#fff; z-index:2; }
            .lig th:first-child { background:#fbf8fa; z-index:3; }
        }
        @media print {
            body { background:#fff; }
            .top, .siralayici, .ara-kutu, .sayfalar { display:none !important; }
            .vurgu, .pod, .lig-kart { box-shadow:none; break-inside:avoid; }
            .deger-hucre .dolgu, .son-rozet { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        }
        html.lumen-dark .vurgu, html.lumen-dark .pod, html.lumen-dark .lig-kart, html.lumen-dark .geri, html.lumen-dark .btn, html.lumen-dark .yil-sec, html.lumen-dark .cip { background:var(--card); }
    </style>
</head>
<body>
    <header class="top">
        <div class="top-in">
            <a href="dashboard.php" class="geri" title="Rapor listesine dön"><i class="fa-solid fa-arrow-left"></i></a>
            <span class="t-ico"><i class="fa-solid fa-users-viewfinder"></i></span>
            <div class="t-baslik">
                <h1>Müşteri Ligi</h1>
                <p><?php echo $year; ?> · faturalı ciroya göre sıralama</p>
            </div>
            <div class="aksiyon">
                <form method="get">
                    <select name="year" class="yil-sec" onchange="this.form.submit()" aria-label="Yıl seç">
                        <?php for ($y = $currentYear; $y >= 2020; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </form>
                <button onclick="window.print()" class="btn" title="Yazdır"><i class="fa-solid fa-print"></i><span>Yazdır</span></button>
                <a href="rapor_musteriler_top_excel_xlsx.php?year=<?php echo (int) $year; ?>" class="btn birincil" title="Excel indir"><i class="fa-solid fa-file-excel"></i><span>Excel</span></a>
            </div>
        </div>
    </header>

    <main>
        <div class="vurgular">
            <div class="vurgu kir">
                <span class="vurgu-ico"><i class="fa-solid fa-sack-dollar"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l"><?php echo $year; ?> Net Ciro</div>
                    <div class="vurgu-v"><?php echo $kisa($sumSales); ?> ₺</div>
                    <div class="vurgu-s"><?php echo $adetF($totalCustomers); ?> müşteri · <?php echo $adetF($sumFatura); ?> fatura</div>
                </div>
            </div>
            <div class="vurgu alt">
                <span class="vurgu-ico"><i class="fa-solid fa-chart-pie"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">Cironun %80'i</div>
                    <div class="vurgu-v"><?php echo $adetF($pareto80); ?> müşteride</div>
                    <div class="vurgu-s"><?php echo $totalCustomers > 0 ? 'müşterilerin %' . number_format(100 * $pareto80 / max(1, $totalCustomers), 1, ',', '.') . '\'i' : '—'; ?></div>
                </div>
            </div>
            <div class="vurgu mor">
                <span class="vurgu-ico"><i class="fa-solid fa-basket-shopping"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">Müşteri Başı Ortalama</div>
                    <div class="vurgu-v"><?php echo $kisa($avgSale); ?> ₺</div>
                    <div class="vurgu-s">yıllık ciro / müşteri</div>
                </div>
            </div>
            <div class="vurgu yes">
                <span class="vurgu-ico"><i class="fa-solid fa-crown"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">Lider Müşteri</div>
                    <div class="vurgu-v"><?php echo $top3 !== [] ? $h(mb_substr((string) $top3[0]['CUSTOMER_NAME'], 0, 22)) : '—'; ?></div>
                    <div class="vurgu-s"><?php echo $top3 !== [] ? 'pay %' . number_format($top3[0]['PAY'], 1, ',', '.') : 'veri yok'; ?></div>
                </div>
            </div>
        </div>

        <?php if ($customers === []): ?>
            <div class="lig-kart"><div class="bos-genel">
                <i class="fa-regular fa-folder-open"></i>
                <b><?php echo $year; ?> için faturalı satış yok</b>
            </div></div>
        <?php else: ?>

        <?php if (count($top3) >= 3): ?>
        <div class="podyum">
            <?php foreach ([1, 0, 2] as $pi): $p = $top3[$pi]; $siraNo = $pi + 1; ?>
            <div class="pod p<?php echo $siraNo; ?>">
                <span class="pod-rozet"><?php echo $siraNo === 1 ? '<i class="fa-solid fa-crown"></i>' : $siraNo; ?></span>
                <div class="pod-ad" title="<?php echo $h($p['CUSTOMER_NAME']); ?>"><?php echo $h(mb_substr((string) $p['CUSTOMER_NAME'], 0, 30)); ?></div>
                <div class="pod-sehir"><?php echo $h($p['CUSTOMER_CODE']); ?><?php echo $p['CITY'] !== '' && $p['CITY'] !== null ? ' · ' . $h($p['CITY']) : ''; ?></div>
                <div class="pod-tutar"><?php echo $para($p['TOTAL_SALES']); ?> ₺</div>
                <div class="pod-alt">
                    <span><b><?php echo $adetF($p['FATURA_SAYISI']); ?></b> fatura</span>
                    <span>pay <b>%<?php echo number_format($p['PAY'], 1, ',', '.'); ?></b></span>
                    <span>sepet <b><?php echo $kisa($p['ORT_SEPET']); ?> ₺</b></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <section class="lig-kart">
            <div class="lig-bas">
                <span class="baslik"><i class="fa-solid fa-table-list"></i> Lig Tablosu</span>
                <div class="siralayici" role="group" aria-label="Sıralama ölçütü">
                    <button type="button" class="cip on" data-m="ciro"><i class="fa-solid fa-turkish-lira-sign"></i> Ciro</button>
                    <button type="button" class="cip" data-m="fatura"><i class="fa-solid fa-file-invoice"></i> Fatura</button>
                    <button type="button" class="cip" data-m="sepet"><i class="fa-solid fa-basket-shopping"></i> Ort. Sepet</button>
                </div>
                <div class="ara-kutu">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="ara" placeholder="Ad, kod veya şehir ara..." autocomplete="off">
                </div>
                <span class="kayit-not"><span id="kayitAdet"><?php echo $totalCustomers; ?></span> müşteri</span>
            </div>
            <div class="tw">
                <table class="lig">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Müşteri</th>
                            <th id="anaBaslik">Ciro</th>
                            <th>Pay</th>
                            <th class="kum-bas">Küm. Pay</th>
                            <th>Fatura</th>
                            <th>Ort. Sepet</th>
                            <th>Son Alış</th>
                        </tr>
                    </thead>
                    <tbody id="govde">
                        <?php foreach ($customers as $i => $c):
                            $wC = 100 * $c['TOTAL_SALES'] / $maxCiro;
                            $wF = 100 * $c['FATURA_SAYISI'] / $maxFat;
                            $wS = 100 * $c['ORT_SEPET'] / $maxSep;
                            [$rc, $rt] = $sonRozet($c['SON_GUN'], $guncelYilMi);
                            $paretoBurada = ($i + 1 === $pareto80 && $pareto80 < $totalCustomers); ?>
                        <tr data-ciro="<?php echo number_format($c['TOTAL_SALES'], 4, '.', ''); ?>"
                            data-fatura="<?php echo $c['FATURA_SAYISI']; ?>"
                            data-sepet="<?php echo number_format($c['ORT_SEPET'], 4, '.', ''); ?>"
                            data-wc="<?php echo number_format($wC, 2, '.', ''); ?>"
                            data-wf="<?php echo number_format($wF, 2, '.', ''); ?>"
                            data-ws="<?php echo number_format($wS, 2, '.', ''); ?>"
                            data-ara="<?php echo $h(mb_strtolower($c['CUSTOMER_CODE'] . ' ' . $c['CUSTOMER_NAME'] . ' ' . (string) $c['CITY'], 'UTF-8')); ?>">
                            <td><span class="sira"><?php echo $i + 1; ?></span></td>
                            <td>
                                <div class="m-ad" title="<?php echo $h($c['CUSTOMER_NAME']); ?>"><?php echo $h($c['CUSTOMER_NAME']); ?></div>
                                <div class="m-alt"><span><?php echo $h($c['CUSTOMER_CODE']); ?></span><?php if ($c['CITY'] !== '' && $c['CITY'] !== null): ?><span><i class="fa-solid fa-location-dot" style="margin-right:2px;"></i><?php echo $h($c['CITY']); ?></span><?php endif; ?></div>
                            </td>
                            <td>
                                <span class="deger-hucre">
                                    <span class="dolgu" style="width:<?php echo number_format(max(1.5, $wC), 1, '.', ''); ?>%;"></span>
                                    <span class="ana-deger"><?php echo $para($c['TOTAL_SALES']); ?> ₺</span>
                                </span>
                            </td>
                            <td class="pay-t">%<?php echo number_format($c['PAY'], 2, ',', '.'); ?></td>
                            <td class="kum-t">%<?php echo number_format($c['KUM'], 1, ',', '.'); ?><?php if ($paretoBurada): ?><span class="p80"><i class="fa-solid fa-flag"></i>%80</span><?php endif; ?></td>
                            <td><?php echo $adetF($c['FATURA_SAYISI']); ?></td>
                            <td><?php echo $para($c['ORT_SEPET']); ?> ₺</td>
                            <td><span class="son-rozet <?php echo $rc; ?>" title="Son fatura: <?php echo $h($c['SON_TARIH_TXT']); ?>"><?php echo $h($rt); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td></td>
                            <td>TOPLAM</td>
                            <td style="color:var(--red);"><?php echo $para($sumSales); ?> ₺</td>
                            <td class="pay-t">%100</td>
                            <td class="kum-t"></td>
                            <td><?php echo $adetF($sumFatura); ?></td>
                            <td><?php echo $para($sumFatura > 0 ? $sumSales / $sumFatura : 0); ?> ₺</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="sayfalar" id="sayfalar"></div>
        </section>
        <?php endif; ?>
    </main>

    <script>
    (function () {
        var govde = document.getElementById('govde');
        if (!govde) { return; }
        var satirlar = Array.prototype.slice.call(govde.querySelectorAll('tr'));
        var araInput = document.getElementById('ara');
        var sayfalar = document.getElementById('sayfalar');
        var kayitAdet = document.getElementById('kayitAdet');
        var anaBaslik = document.getElementById('anaBaslik');
        var cipler = document.querySelectorAll('.cip');
        var kumSutun = document.querySelectorAll('.kum-bas, .kum-t');

        var SAYFA = 25, sayfa = 1, metrik = 'ciro', filtreli = satirlar.slice();
        var basliklar = { ciro: 'Ciro', fatura: 'Fatura', sepet: 'Ort. Sepet' };
        var birim = { ciro: ' ₺', fatura: '', sepet: ' ₺' };
        var wAttr = { ciro: 'wc', fatura: 'wf', sepet: 'ws' };
        var trFmt = function (v, dec) { return v.toLocaleString('tr-TR', { minimumFractionDigits: dec, maximumFractionDigits: dec }); };

        function uygula() {
            filtreli.sort(function (a, b) { return parseFloat(b.dataset[metrik]) - parseFloat(a.dataset[metrik]); });
            satirlar.forEach(function (r) { r.style.display = 'none'; });
            var bas = (sayfa - 1) * SAYFA;
            filtreli.slice(bas, bas + SAYFA).forEach(function (r, idx) {
                r.style.display = '';
                govde.appendChild(r);
                r.querySelector('.sira').textContent = bas + idx + 1;
                r.classList.toggle('ilk3', metrik === 'ciro' && bas + idx < 3);
                var v = parseFloat(r.dataset[metrik]);
                r.querySelector('.dolgu').style.width = Math.max(1.5, parseFloat(r.dataset[wAttr[metrik]])) + '%';
                r.querySelector('.ana-deger').textContent = (metrik === 'fatura' ? trFmt(v, 0) : trFmt(v, 2)) + birim[metrik];
            });
            kayitAdet.textContent = filtreli.length;
            anaBaslik.textContent = basliklar[metrik];
            kumSutun.forEach(function (el) { el.style.display = metrik === 'ciro' ? '' : 'none'; });
            ciz();
        }
        function ciz() {
            var toplam = Math.max(1, Math.ceil(filtreli.length / SAYFA));
            sayfalar.innerHTML = '';
            if (toplam <= 1) { return; }
            function buton(txt, aktifMi, tikla, kapali) {
                var b = document.createElement('button');
                b.textContent = txt;
                if (aktifMi) { b.classList.add('on'); }
                if (kapali) { b.disabled = true; }
                b.onclick = tikla;
                sayfalar.appendChild(b);
            }
            buton('‹', false, function () { if (sayfa > 1) { sayfa--; uygula(); } }, sayfa === 1);
            var s = Math.max(1, sayfa - 2), e = Math.min(toplam, s + 4);
            if (e - s < 4) { s = Math.max(1, e - 4); }
            for (var i = s; i <= e; i++) {
                (function (pg) { buton(String(pg), pg === sayfa, function () { sayfa = pg; uygula(); }); })(i);
            }
            buton('›', false, function () { if (sayfa < toplam) { sayfa++; uygula(); } }, sayfa === toplam);
        }
        cipler.forEach(function (cip) {
            cip.addEventListener('click', function () {
                cipler.forEach(function (x) { x.classList.remove('on'); });
                cip.classList.add('on');
                metrik = cip.getAttribute('data-m');
                sayfa = 1;
                uygula();
            });
        });
        araInput.addEventListener('input', function () {
            var q = this.value.trim().toLocaleLowerCase('tr-TR');
            filtreli = q === '' ? satirlar.slice() : satirlar.filter(function (r) {
                return (r.getAttribute('data-ara') || '').indexOf(q) !== -1;
            });
            sayfa = 1;
            uygula();
        });
        uygula();
    })();
    </script>
</body>
</html>
