<?php
declare(strict_types=1);

/**
 * rapor_urun_performans.php — "Ürün Ligi" (yeniden tasarım 2026-07-07).
 *
 * Eski: indigo kimlik + Chart.js top-30 + ayrı Top10 + tablo.
 * Yeni: grafik YOK (liste konuşur) — PODYUM (ilk 3) + hücre-içi dolgulu LİG TABLOSU
 * + PARETO sütunu (kümülatif ciro payı; %80 eşiği rozetli). Metrik çipleri
 * (Ciro/Adet/Ort.Fiyat) tabloyu CANLI yeniden sıralar. Kimlik: Lumen bordosu.
 *
 * Veri tanımı (2026-07-09, kullanıcı isteği): NET satış — satış (TRCODE 7,8)
 * eksi satış iadesi (TRCODE 2,3); yalnız Firma 1 AKL%. Satır tutarı
 * PRICE*AMOUNT-DISTDISC. Excel çıktısı aynı tanımı kullanır.
 * Yıl <= 2025 eski dönem tablosundan (LG_001_01_) okunur.
 */

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

$currentYear = (int) date('Y');
$year = (isset($_GET['year']) && is_numeric($_GET['year'])) ? (int) $_GET['year'] : $currentYear;

// Yıl <= 2025 verisi eski dönem tablosunda (rapor_satis_aylik ile aynı eşleme)
$donemTablo = ($year <= 2025) ? 'LG_001_01_' : $firmadonem;

$urunKosulu = urun_kodu_kosulu('I');

$sql = "
    SELECT
        I.CODE AS URUN_KODU,
        I.NAME AS URUN_ADI,
        SUM(CASE WHEN S.TRCODE IN (7,8) THEN S.AMOUNT ELSE -S.AMOUNT END) AS SATILAN_ADET,
        SUM(CASE WHEN S.TRCODE IN (7,8) THEN S.PRICE * S.AMOUNT - S.DISTDISC
                 ELSE -(S.PRICE * S.AMOUNT - S.DISTDISC) END) AS SATILAN_TUTAR
    FROM {$donemTablo}STLINE S WITH(NOLOCK)
    JOIN {$firma}ITEMS I ON S.STOCKREF = I.LOGICALREF
    WHERE {$urunKosulu}
      AND S.TRCODE IN (2,3,7,8)
      AND YEAR(S.DATE_) = :y
      AND S.CANCELLED = 0
    GROUP BY I.CODE, I.NAME
    HAVING SUM(CASE WHEN S.TRCODE IN (7,8) THEN S.PRICE * S.AMOUNT - S.DISTDISC
                    ELSE -(S.PRICE * S.AMOUNT - S.DISTDISC) END) > 0
    ORDER BY SATILAN_TUTAR DESC
";
$stmt = $dbh->prepare($sql);
$stmt->execute(['y' => $year]);

$allRows = [];
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $r['SATILAN_ADET'] = (float) ($r['SATILAN_ADET'] ?? 0);
    $r['SATILAN_TUTAR'] = (float) ($r['SATILAN_TUTAR'] ?? 0);
    $r['ORT_FIYAT'] = $r['SATILAN_ADET'] > 0 ? round($r['SATILAN_TUTAR'] / $r['SATILAN_ADET'], 2) : 0.0;
    $allRows[] = $r;
}

$totalProducts = count($allRows);
$sumSales = (float) array_sum(array_column($allRows, 'SATILAN_TUTAR'));
$sumQty = (float) array_sum(array_column($allRows, 'SATILAN_ADET'));
$avgPriceAll = $sumQty > 0 ? round($sumSales / $sumQty, 2) : 0.0;

// Dolgu oranları için zirveler + pay/kümülatif pay (ciro sırasına göre)
$maxTutar = 1.0; $maxAdet = 1.0; $maxOrt = 1.0;
foreach ($allRows as $r) {
    $maxTutar = max($maxTutar, $r['SATILAN_TUTAR']);
    $maxAdet  = max($maxAdet, $r['SATILAN_ADET']);
    $maxOrt   = max($maxOrt, $r['ORT_FIYAT']);
}
$kum = 0.0;
$pareto80 = 0;   // cironun %80'ini taşıyan ürün sayısı
foreach ($allRows as $i => $r) {
    $pay = $sumSales > 0 ? 100 * $r['SATILAN_TUTAR'] / $sumSales : 0.0;
    $kum += $pay;
    $allRows[$i]['PAY'] = $pay;
    $allRows[$i]['KUM'] = $kum;
    if ($pareto80 === 0 && $kum >= 80.0) { $pareto80 = $i + 1; }
}
if ($pareto80 === 0) { $pareto80 = $totalProducts; }

$top3 = array_slice($allRows, 0, 3);

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$para = static fn($x): string => number_format((float) $x, 2, ',', '.');
$adetF = static fn($x): string => number_format((float) $x, 0, ',', '.');
$kisa = static function ($v): string {
    $v = (float) $v;
    if (abs($v) >= 1_000_000) { return number_format($v / 1_000_000, 2, ',', '.') . ' M'; }
    if (abs($v) >= 1_000)     { return number_format($v / 1_000, 0, ',', '.') . ' B'; }
    return number_format($v, 0, ',', '.');
};
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ürün Ligi — <?php echo $year; ?></title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:#f8f6f7; --card:#fff; --text-1:#1c1220; --text-2:#6d6276; --text-3:#9c92a5; --border:#ebe4ea;
            --red:#6F1022; --red-koyu:#7f1d1d; --red-orta:#b91c1c; --red-soft:#fdf0f0;
            --emerald:#059669; --amber:#d97706; --amber-soft:#fffbeb; --altin:#f59e0b;
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

        /* ── Vurgular ── */
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

        /* ── PODYUM ── */
        .podyum { display:grid; grid-template-columns:1fr 1.15fr 1fr; gap:12px; margin-bottom:16px; align-items:end; }
        .pod {
            position:relative; background:var(--card); border:1px solid var(--border); border-radius:18px;
            padding:18px 16px 15px; text-align:center; box-shadow:var(--golge);
            animation:giris .55s cubic-bezier(.22,1,.36,1) both;
        }
        .pod.p1 { animation-delay:60ms; border-color:rgba(245,158,11,.45); background:linear-gradient(180deg,#fffdf5,#fff); padding-top:24px; padding-bottom:22px; }
        .pod.p2 { animation-delay:120ms; }
        .pod.p3 { animation-delay:180ms; }
        .pod-rozet {
            position:absolute; top:-13px; left:50%; transform:translateX(-50%);
            width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center;
            font-size:12.5px; font-weight:800; color:#fff; box-shadow:0 4px 10px -3px rgba(28,18,32,.35);
        }
        .pod.p1 .pod-rozet { background:linear-gradient(135deg,#fbbf24,#d97706); width:36px; height:36px; top:-16px; font-size:15px; }
        .pod.p2 .pod-rozet { background:linear-gradient(135deg,#94a3b8,#64748b); }
        .pod.p3 .pod-rozet { background:linear-gradient(135deg,#d6a67c,#a16207); }
        .pod-kod { font-size:14px; font-weight:800; margin-top:4px; }
        .pod.p1 .pod-kod { font-size:16px; }
        .pod-ad { font-size:10.5px; color:var(--text-3); margin-top:2px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .pod-tutar { font-size:17px; font-weight:800; margin-top:8px; color:var(--red-orta); white-space:nowrap; }
        .pod.p1 .pod-tutar { font-size:21px; color:var(--amber); }
        .pod-alt { display:flex; justify-content:center; gap:10px; margin-top:6px; font-size:10.5px; color:var(--text-3); flex-wrap:wrap; }
        .pod-alt b { color:var(--text-2); }

        /* ── LİG TABLOSU ── */
        .lig-kart { background:var(--card); border:1px solid var(--border); border-radius:16px; box-shadow:var(--golge); overflow:hidden; animation:giris .5s cubic-bezier(.22,1,.36,1) .15s both; }
        .lig-bas { padding:14px 18px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .lig-bas .baslik { font-size:13.5px; font-weight:700; display:inline-flex; align-items:center; gap:8px; }
        .lig-bas .baslik i { color:var(--red); }
        .siralayici { display:inline-flex; gap:6px; }
        .cip { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:99px; border:1.5px solid var(--border); background:#fff; color:var(--text-2); font-family:inherit; font-size:12px; font-weight:700; cursor:pointer; transition:.16s; }
        .cip:hover { border-color:var(--red); color:var(--red); }
        .cip.on { background:var(--red); border-color:var(--red); color:#fff; box-shadow:0 5px 12px -7px rgba(111,16,34,.55); }
        .ara-kutu { position:relative; margin-left:auto; flex:0 1 260px; min-width:180px; }
        .ara-kutu i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-3); font-size:12.5px; }
        .ara-kutu input { width:100%; padding:9px 12px 9px 33px; font-family:inherit; font-size:13.5px; border:1px solid var(--border); border-radius:10px; outline:none; transition:.16s; }
        .ara-kutu input:focus { border-color:var(--red); box-shadow:0 0 0 3px rgba(111,16,34,.1); }
        .kayit-not { font-size:11px; color:var(--text-3); font-weight:600; white-space:nowrap; }

        .tw { overflow-x:auto; }
        table.lig { width:100%; border-collapse:collapse; min-width:820px; }
        .lig th { padding:10px 14px; font-size:10px; font-weight:700; letter-spacing:.5px; text-transform:uppercase; color:var(--text-3); background:#fbf8fa; border-bottom:1px solid var(--border); text-align:right; white-space:nowrap; }
        .lig th:nth-child(1), .lig th:nth-child(2) { text-align:left; }
        .lig td { padding:9px 14px; border-bottom:1px solid #f5f1f4; font-size:12.5px; text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; vertical-align:middle; }
        .lig td:nth-child(1) { width:46px; }
        .lig td:nth-child(2) { text-align:left; max-width:340px; }
        .lig tr:hover td { background:#fdfbfc; }
        .sira { display:inline-flex; align-items:center; justify-content:center; min-width:27px; height:27px; padding:0 5px; border-radius:8px; background:#f4eff3; color:var(--text-2); font-size:11px; font-weight:800; }
        tr.ilk3 .sira { background:linear-gradient(135deg,#fbbf24,#d97706); color:#fff; }
        .u-kod { font-weight:800; font-size:12.5px; }
        .u-ad { font-size:10.5px; color:var(--text-3); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:330px; }
        .deger-hucre { position:relative; display:block; min-width:150px; padding:3px 0; }
        .deger-hucre .dolgu { position:absolute; inset:0 auto 0 0; border-radius:6px; background:rgba(111,16,34,.12); }
        .deger-hucre span { position:relative; padding-right:4px; font-weight:700; }
        .pay-t { color:var(--text-3); font-size:11.5px; }
        .kum-t { font-size:11.5px; color:var(--text-3); }
        .p80 { display:inline-flex; align-items:center; gap:4px; margin-left:6px; font-size:9px; font-weight:800; letter-spacing:.4px; padding:2px 7px; border-radius:99px; background:var(--amber-soft); color:var(--amber); border:1px solid rgba(217,119,6,.3); }
        .lig tfoot td { border-top:2px solid var(--border); background:#fbf8fa; font-weight:800; }

        .sayfalar { display:flex; justify-content:flex-end; gap:6px; padding:12px 16px; flex-wrap:wrap; }
        .sayfalar button { padding:7px 12px; font-family:inherit; font-size:12px; font-weight:700; background:#fff; color:var(--text-2); border:1px solid var(--border); border-radius:9px; cursor:pointer; transition:.14s; }
        .sayfalar button:hover:not(:disabled) { border-color:var(--red); color:var(--red); }
        .sayfalar button.on { background:var(--red); color:#fff; border-color:var(--red); }
        .sayfalar button:disabled { opacity:.4; cursor:not-allowed; }

        .dipnot { margin-top:12px; font-size:11px; line-height:1.6; color:var(--text-3); }
        .dipnot b { color:var(--text-2); }

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
            .deger-hucre .dolgu { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        }
        html.akl-dark .vurgu, html.akl-dark .pod, html.akl-dark .lig-kart, html.akl-dark .geri, html.akl-dark .btn, html.akl-dark .yil-sec, html.akl-dark .cip { background:var(--card); }
    </style>
</head>
<body>
    <header class="top">
        <div class="top-in">
            <a href="dashboard.php" class="geri" title="Rapor listesine dön"><i class="fa-solid fa-arrow-left"></i></a>
            <span class="t-ico"><i class="fa-solid fa-ranking-star"></i></span>
            <div class="t-baslik">
                <h1>Ürün Ligi</h1>
                <p><?php echo $year; ?> · Firma 1 · AKL ürünleri net satış (iadeler düşülmüş)</p>
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
                <a href="rapor_urun_performans_excel_xlsx.php?year=<?php echo (int) $year; ?>" class="btn birincil" title="Excel indir"><i class="fa-solid fa-file-excel"></i><span>Excel</span></a>
            </div>
        </div>
    </header>

    <main>
        <!-- Vurgular -->
        <div class="vurgular">
            <div class="vurgu kir">
                <span class="vurgu-ico"><i class="fa-solid fa-sack-dollar"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l"><?php echo $year; ?> Net Ciro</div>
                    <div class="vurgu-v"><?php echo $kisa($sumSales); ?> ₺</div>
                    <div class="vurgu-s"><?php echo $adetF($totalProducts); ?> farklı ürün · iade düşülmüş</div>
                </div>
            </div>
            <div class="vurgu mor">
                <span class="vurgu-ico"><i class="fa-solid fa-cubes"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">Net Adet</div>
                    <div class="vurgu-v"><?php echo $kisa($sumQty); ?></div>
                    <div class="vurgu-s">ort. birim <?php echo $para($avgPriceAll); ?> ₺</div>
                </div>
            </div>
            <div class="vurgu alt">
                <span class="vurgu-ico"><i class="fa-solid fa-chart-pie"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">Cironun %80'i</div>
                    <div class="vurgu-v"><?php echo $adetF($pareto80); ?> üründe</div>
                    <div class="vurgu-s"><?php echo $totalProducts > 0 ? 'çeşidin %' . number_format(100 * $pareto80 / max(1, $totalProducts), 1, ',', '.') . '\'i' : '—'; ?></div>
                </div>
            </div>
            <div class="vurgu yes">
                <span class="vurgu-ico"><i class="fa-solid fa-crown"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">Lider Ürün</div>
                    <div class="vurgu-v"><?php echo $top3 !== [] ? $h($top3[0]['URUN_KODU']) : '—'; ?></div>
                    <div class="vurgu-s"><?php echo $top3 !== [] ? 'pay %' . number_format($top3[0]['PAY'], 1, ',', '.') : 'veri yok'; ?></div>
                </div>
            </div>
        </div>

        <?php if ($allRows === []): ?>
            <div class="lig-kart"><div class="bos-genel">
                <i class="fa-regular fa-folder-open"></i>
                <b><?php echo $year; ?> için satış kaydı yok</b>
            </div></div>
        <?php else: ?>

        <!-- PODYUM -->
        <?php if (count($top3) >= 3): ?>
        <div class="podyum">
            <?php foreach ([1, 0, 2] as $pi): $p = $top3[$pi]; $siraNo = $pi + 1; ?>
            <div class="pod p<?php echo $siraNo; ?>">
                <span class="pod-rozet"><?php echo $siraNo === 1 ? '<i class="fa-solid fa-crown"></i>' : $siraNo; ?></span>
                <div class="pod-kod"><?php echo $h($p['URUN_KODU']); ?></div>
                <div class="pod-ad" title="<?php echo $h($p['URUN_ADI']); ?>"><?php echo $h(mb_substr((string) $p['URUN_ADI'], 0, 42)); ?></div>
                <div class="pod-tutar"><?php echo $para($p['SATILAN_TUTAR']); ?> ₺</div>
                <div class="pod-alt">
                    <span><b><?php echo $adetF($p['SATILAN_ADET']); ?></b> adet</span>
                    <span>pay <b>%<?php echo number_format($p['PAY'], 1, ',', '.'); ?></b></span>
                    <span>ort <b><?php echo $para($p['ORT_FIYAT']); ?> ₺</b></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- LİG TABLOSU -->
        <section class="lig-kart">
            <div class="lig-bas">
                <span class="baslik"><i class="fa-solid fa-table-list"></i> Lig Tablosu</span>
                <div class="siralayici" role="group" aria-label="Sıralama ölçütü">
                    <button type="button" class="cip on" data-m="tutar"><i class="fa-solid fa-turkish-lira-sign"></i> Ciro</button>
                    <button type="button" class="cip" data-m="adet"><i class="fa-solid fa-cubes"></i> Adet</button>
                    <button type="button" class="cip" data-m="ort"><i class="fa-solid fa-tag"></i> Ort. Fiyat</button>
                </div>
                <div class="ara-kutu">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="ara" placeholder="Kod veya ad ara..." autocomplete="off">
                </div>
                <span class="kayit-not"><span id="kayitAdet"><?php echo $totalProducts; ?></span> ürün</span>
            </div>
            <div class="tw">
                <table class="lig">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Ürün</th>
                            <th id="anaBaslik">Ciro</th>
                            <th>Pay</th>
                            <th class="kum-bas">Küm. Pay</th>
                            <th>Adet</th>
                            <th>Ort. Fiyat</th>
                        </tr>
                    </thead>
                    <tbody id="govde">
                        <?php foreach ($allRows as $i => $r):
                            $wT = 100 * $r['SATILAN_TUTAR'] / $maxTutar;
                            $wA = 100 * $r['SATILAN_ADET'] / $maxAdet;
                            $wO = 100 * $r['ORT_FIYAT'] / $maxOrt;
                            $paretoBurada = ($i + 1 === $pareto80 && $pareto80 < $totalProducts); ?>
                        <tr data-tutar="<?php echo number_format($r['SATILAN_TUTAR'], 4, '.', ''); ?>"
                            data-adet="<?php echo number_format($r['SATILAN_ADET'], 4, '.', ''); ?>"
                            data-ort="<?php echo number_format($r['ORT_FIYAT'], 4, '.', ''); ?>"
                            data-wt="<?php echo number_format($wT, 2, '.', ''); ?>"
                            data-wa="<?php echo number_format($wA, 2, '.', ''); ?>"
                            data-wo="<?php echo number_format($wO, 2, '.', ''); ?>"
                            data-ara="<?php echo $h(mb_strtolower($r['URUN_KODU'] . ' ' . $r['URUN_ADI'], 'UTF-8')); ?>">
                            <td><span class="sira"><?php echo $i + 1; ?></span></td>
                            <td>
                                <div class="u-kod"><?php echo $h($r['URUN_KODU']); ?></div>
                                <div class="u-ad" title="<?php echo $h($r['URUN_ADI']); ?>"><?php echo $h($r['URUN_ADI']); ?></div>
                            </td>
                            <td>
                                <span class="deger-hucre">
                                    <span class="dolgu" style="width:<?php echo number_format(max(1.5, $wT), 1, '.', ''); ?>%;"></span>
                                    <span class="ana-deger"><?php echo $para($r['SATILAN_TUTAR']); ?> ₺</span>
                                </span>
                            </td>
                            <td class="pay-t">%<?php echo number_format($r['PAY'], 2, ',', '.'); ?></td>
                            <td class="kum-t">%<?php echo number_format($r['KUM'], 1, ',', '.'); ?><?php if ($paretoBurada): ?><span class="p80"><i class="fa-solid fa-flag"></i>%80</span><?php endif; ?></td>
                            <td><?php echo $adetF($r['SATILAN_ADET']); ?></td>
                            <td><?php echo $para($r['ORT_FIYAT']); ?> ₺</td>
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
                            <td><?php echo $adetF($sumQty); ?></td>
                            <td><?php echo $para($avgPriceAll); ?> ₺</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="sayfalar" id="sayfalar"></div>
        </section>

        <p class="dipnot"><b>Net satış:</b> satış hareketleri − satış iadeleri (iptaller hariç), yalnız Firma 1 AKL ürünleri. Ciro, adet ve ortalama fiyat iade düşülmüş net değerlerdir; bekleyen (faturalanmamış) siparişler dahil değildir.</p>
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

        var SAYFA = 25, sayfa = 1, metrik = 'tutar', filtreli = satirlar.slice();
        var basliklar = { tutar: 'Ciro', adet: 'Adet', ort: 'Ort. Fiyat' };
        var birim = { tutar: ' ₺', adet: '', ort: ' ₺' };
        var trFmt = function (v, dec) { return v.toLocaleString('tr-TR', { minimumFractionDigits: dec, maximumFractionDigits: dec }); };

        function uygula() {
            // metriğe göre sırala + dolgu ve ana değeri güncelle + sıra numarala
            filtreli.sort(function (a, b) { return parseFloat(b.dataset[metrik]) - parseFloat(a.dataset[metrik]); });
            satirlar.forEach(function (r) { r.style.display = 'none'; });
            var bas = (sayfa - 1) * SAYFA;
            filtreli.slice(bas, bas + SAYFA).forEach(function (r, idx) {
                r.style.display = '';
                govde.appendChild(r);   // görünür sırayı DOM'a yaz
                r.querySelector('.sira').textContent = bas + idx + 1;
                r.classList.toggle('ilk3', metrik === 'tutar' && bas + idx < 3);
                var w = r.dataset['w' + metrik.charAt(0)];
                var v = parseFloat(r.dataset[metrik]);
                r.querySelector('.dolgu').style.width = Math.max(1.5, parseFloat(w)) + '%';
                r.querySelector('.ana-deger').textContent = (metrik === 'adet' ? trFmt(v, 0) : trFmt(v, 2)) + birim[metrik];
            });
            kayitAdet.textContent = filtreli.length;
            anaBaslik.textContent = basliklar[metrik];
            // Küm.Pay yalnız ciro sıralamasında anlamlı
            kumSutun.forEach(function (el) { el.style.display = metrik === 'tutar' ? '' : 'none'; });
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
