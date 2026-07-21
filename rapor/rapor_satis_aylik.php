<?php
declare(strict_types=1);

/**
 * Aylık Satış Raporu — yıl karnesi görünümü (yeniden tasarım 2026-07-07).
 *
 * Veri tanımı: STLINE satış(7,8) − iade(2,3) + ürün kodu ön eki filtresi + LINETYPE=0; NET = LINENET+VATAMNT (KDV dahil, iadeler düşülmüş).
 * Geçen yıl karşılaştırması DOĞRU dönem tablosundan (yıl<=2025 → LG_001_01_) — eski
 * "Karşılaştırmalı Satış" raporu buraya birleştirilmiştir (2026-07-07, git geçmişinde).
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

$monthly = [];
for ($i = 1; $i <= 12; $i++) {
    $monthly[$i] = ['C' => 0, 'QTY' => 0.0, 'NET' => 0.0];
}

$urunKosulu = urun_kodu_kosulu('ITM');

$sql = "
    SELECT
        MONTH(SL.DATE_) AS M,
        COUNT(DISTINCT CASE WHEN SL.TRCODE IN (7,8) THEN SL.INVOICEREF END) AS C,
        SUM(CASE WHEN SL.TRCODE IN (7,8) THEN SL.AMOUNT ELSE -SL.AMOUNT END) AS QTY,
        SUM(CASE WHEN SL.TRCODE IN (7,8)
                 THEN ISNULL(SL.LINENET, (SL.PRICE * SL.AMOUNT - SL.DISTDISC)) + ISNULL(SL.VATAMNT, 0)
                 ELSE -(ISNULL(SL.LINENET, (SL.PRICE * SL.AMOUNT - SL.DISTDISC)) + ISNULL(SL.VATAMNT, 0)) END) AS NET
    FROM {$firmadonem}STLINE SL WITH(NOLOCK)
    JOIN {$firma}ITEMS ITM ON SL.STOCKREF = ITM.LOGICALREF
    WHERE {$urunKosulu}
      AND SL.TRCODE IN (2,3,7,8)
      AND SL.DATE_ >= :startDate
      AND SL.DATE_ < :endDate
      AND SL.CANCELLED = 0
      AND SL.LINETYPE = 0
    GROUP BY MONTH(SL.DATE_);
";

$stmt = $dbh->prepare($sql);
$stmt->execute([
    'startDate' => sprintf('%04d-01-01', $year),
    'endDate' => sprintf('%04d-01-01', $year + 1),
]);
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $m = (int) $r['M'];
    $monthly[$m]['C'] = (int) $r['C'];
    $monthly[$m]['QTY'] = (float) $r['QTY'];
    $monthly[$m]['NET'] = (float) $r['NET'];
}

// ── GEÇEN YIL (aynı tanım, DOĞRU dönem tablosu: yıl<=2025 → LG_001_01_) ──
$prevYear = $year - 1;
$prevDonem = ($prevYear <= 2025) ? 'LG_001_01_' : $firmadonem;
$prevMonthly = array_fill(1, 12, 0.0);
try {
    $stmtP = $dbh->prepare("
        SELECT MONTH(SL.DATE_) AS M,
               SUM(CASE WHEN SL.TRCODE IN (7,8)
                        THEN ISNULL(SL.LINENET, (SL.PRICE * SL.AMOUNT - SL.DISTDISC)) + ISNULL(SL.VATAMNT, 0)
                        ELSE -(ISNULL(SL.LINENET, (SL.PRICE * SL.AMOUNT - SL.DISTDISC)) + ISNULL(SL.VATAMNT, 0)) END) AS NET
        FROM {$prevDonem}STLINE SL WITH(NOLOCK)
        JOIN {$firma}ITEMS ITM ON SL.STOCKREF = ITM.LOGICALREF
        WHERE {$urunKosulu}
          AND SL.TRCODE IN (2,3,7,8) AND SL.CANCELLED = 0 AND SL.LINETYPE = 0
          AND SL.DATE_ >= :startDate AND SL.DATE_ < :endDate
        GROUP BY MONTH(SL.DATE_)");
    $stmtP->execute([
        'startDate' => sprintf('%04d-01-01', $prevYear),
        'endDate'   => sprintf('%04d-01-01', $prevYear + 1),
    ]);
    foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $rp) { $prevMonthly[(int) $rp['M']] = (float) $rp['NET']; }
} catch (Throwable $e) {
    error_log('rapor_satis_aylik prevYear: ' . $e->getMessage());
}

$monthNames  = [1 => 'Ocak', 2 => 'Subat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayis', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Agustos', 9 => 'Eylul', 10 => 'Ekim', 11 => 'Kasim', 12 => 'Aralik'];
$monthShort  = [1 => 'Oca', 2 => 'Sub', 3 => 'Mar', 4 => 'Nis', 5 => 'May', 6 => 'Haz', 7 => 'Tem', 8 => 'Agu', 9 => 'Eyl', 10 => 'Eki', 11 => 'Kas', 12 => 'Ara'];

$chartLabels = $chartValues = $prevChartValues = $monthsData = [];
$yearlyTotal = 0.0;
$totalMonthsWithSales = 0;
$mx = ['M' => 0, 'NET' => 0.0];
$mt = ['M' => 0, 'C' => 0];

for ($m = 1; $m <= 12; $m++) {
    $d = $monthly[$m];
    $chartLabels[] = $monthShort[$m];
    $chartValues[] = round($d['NET'], 2);
    $prevChartValues[] = round($prevMonthly[$m], 2);
    $monthsData[] = ['M' => $m, 'C' => $d['C'], 'V' => $d['NET'], 'P' => $prevMonthly[$m]];

    if ($d['NET'] > 0) {
        $totalMonthsWithSales++;
        if ($d['NET'] > $mx['NET']) { $mx = ['M' => $m, 'NET' => $d['NET']]; }
    }
    if ($d['C'] > $mt['C']) { $mt = ['M' => $m, 'C' => $d['C']]; }
    $yearlyTotal += $d['NET'];
}

$avgMonth    = $totalMonthsWithSales !== 0 ? $yearlyTotal / $totalMonthsWithSales : 0;
$toplamIslem = (int) array_sum(array_column($monthsData, 'C'));
$ortSepet    = $toplamIslem > 0 ? $yearlyTotal / $toplamIslem : 0.0;
$prevTotal   = (float) array_sum($prevMonthly);
$yoyToplam   = $prevTotal > 0.005 ? 100 * ($yearlyTotal - $prevTotal) / $prevTotal : null;
$maxNet      = max(1.0, $mx['NET']);

// Kısa para: 81.261.654 → 81,26M
$kisa = static function (float $v): string {
    if (abs($v) >= 1_000_000) { return number_format($v / 1_000_000, 2, ',', '.') . ' M'; }
    if (abs($v) >= 1_000)     { return number_format($v / 1_000, 0, ',', '.') . ' B'; }
    return number_format($v, 0, ',', '.');
};

// Hero sparkline (SVG alan grafiği) — 12 ay, PHP'de path üretilir
$spW = 320; $spH = 88; $spPad = 6;
$spPts = [];
for ($m = 1; $m <= 12; $m++) {
    $x = $spPad + ($m - 1) * ($spW - 2 * $spPad) / 11;
    $yv = $spH - $spPad - ($monthly[$m]['NET'] / $maxNet) * ($spH - 2 * $spPad - 14);
    $spPts[] = number_format($x, 1, '.', '') . ',' . number_format($yv, 1, '.', '');
}
$spLine = implode(' ', $spPts);
$spArea = $spLine . ' ' . ($spW - $spPad) . ',' . ($spH - $spPad) . ' ' . $spPad . ',' . ($spH - $spPad);

$yoyHtml = static function (float $simdiki, float $onceki): string {
    if ($onceki < 0.005) { return '<span class="yoy notr">&mdash;</span>'; }
    $v = 100 * ($simdiki - $onceki) / $onceki;
    $s = number_format(abs($v), 1, ',', '.') . '%';
    if ($v > 0.05)  { return '<span class="yoy up"><i class="fa-solid fa-arrow-trend-up"></i>' . $s . '</span>'; }
    if ($v < -0.05) { return '<span class="yoy down"><i class="fa-solid fa-arrow-trend-down"></i>' . $s . '</span>'; }
    return '<span class="yoy notr">0%</span>';
};
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aylik Satis Raporu – <?php echo $year; ?></title>
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once(__DIR__ . '/../pwa-header.php'); } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f6f8fb;
            --card: #ffffff;
            --text-1: #0f172a;
            --text-2: #5b6b83;
            --text-3: #94a3b8;
            --border: #e5eaf1;
            --red: #6F1022;
            --emerald: #059669;
            --emerald-2: #10b981;
            --emerald-soft: #ecfdf5;
            --amber: #d97706;  --amber-soft: #fffbeb;
            --indigo: #4f46e5; --indigo-soft: #eef2ff;
            --sky: #0284c7;    --sky-soft: #f0f9ff;
            --golge: 0 10px 30px -18px rgba(15, 23, 42, .25);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            color: var(--text-1);
            min-height: 100vh;
            background:
                radial-gradient(900px 300px at 85% -60px, rgba(16, 185, 129, .10), transparent 60%),
                radial-gradient(700px 260px at -10% 0, rgba(2, 132, 199, .07), transparent 55%),
                var(--bg);
        }

        /* ── Üst çubuk ── */
        .top {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255, 255, 255, .82);
            backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
        }
        .top-in {
            max-width: 1180px; margin: 0 auto; padding: 12px 22px;
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
        }
        .geri {
            width: 38px; height: 38px; border-radius: 11px; flex-shrink: 0;
            display: inline-flex; align-items: center; justify-content: center;
            color: var(--text-2); background: #fff; border: 1px solid var(--border);
            text-decoration: none; transition: .18s;
        }
        .geri:hover { color: var(--emerald); border-color: var(--emerald); transform: translateX(-2px); }
        .t-ico {
            width: 40px; height: 40px; border-radius: 12px; flex-shrink: 0;
            display: inline-flex; align-items: center; justify-content: center; font-size: 17px;
            background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #047857;
        }
        .t-baslik { min-width: 0; }
        .t-baslik h1 { font-size: 16px; font-weight: 700; line-height: 1.2; }
        .t-baslik p  { font-size: 11.5px; color: var(--text-2); }
        .aksiyon { margin-left: auto; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .yil-sec {
            font-family: inherit; font-size: 13.5px; font-weight: 700; color: var(--text-1);
            background: #fff; border: 1px solid var(--border); border-radius: 11px;
            padding: 9px 12px; cursor: pointer; outline: none; transition: .18s;
        }
        .yil-sec:focus { border-color: var(--emerald); box-shadow: 0 0 0 3px rgba(5, 150, 105, .12); }
        .btn {
            display: inline-flex; align-items: center; gap: 7px;
            font-family: inherit; font-size: 12.5px; font-weight: 600;
            padding: 10px 14px; border-radius: 11px; cursor: pointer;
            text-decoration: none; border: 1px solid var(--border);
            background: #fff; color: var(--text-2); transition: .18s;
        }
        .btn:hover { color: var(--emerald); border-color: var(--emerald); transform: translateY(-1px); }
        .btn.birincil { background: var(--emerald); border-color: var(--emerald); color: #fff; box-shadow: 0 6px 16px -8px rgba(5, 150, 105, .5); }
        .btn.birincil:hover { background: #047857; color: #fff; }

        main { max-width: 1180px; margin: 0 auto; padding: 22px 22px 46px; }

        /* ── HERO ── */
        .hero {
            position: relative; overflow: hidden;
            background: linear-gradient(120deg, #052e22 0%, #064e3b 45%, #065f46 100%);
            border-radius: 20px; color: #fff;
            padding: 26px 28px;
            display: flex; align-items: center; gap: 28px; flex-wrap: wrap;
            box-shadow: 0 18px 40px -22px rgba(6, 78, 59, .55);
            animation: giris .5s cubic-bezier(.22, 1, .36, 1) both;
        }
        .hero::after {
            content: ''; position: absolute; inset: 0; pointer-events: none;
            background: radial-gradient(420px 180px at 88% 0%, rgba(255, 255, 255, .14), transparent 60%);
        }
        .hero-sol { min-width: 240px; }
        .hero-eyeb { font-size: 11px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: #6ee7b7; display: flex; align-items: center; gap: 7px; }
        .hero-ciro { font-size: clamp(30px, 4.4vw, 44px); font-weight: 800; line-height: 1.08; margin-top: 6px; letter-spacing: -.5px; }
        .hero-ciro small { font-size: .48em; font-weight: 700; color: #a7f3d0; margin-left: 2px; }
        .hero-tam { font-size: 12.5px; color: rgba(236, 253, 245, .75); margin-top: 3px; }
        .hero-rozetler { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
        .rozet {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 11.5px; font-weight: 700; padding: 6px 11px; border-radius: 999px;
            background: rgba(255, 255, 255, .12); border: 1px solid rgba(255, 255, 255, .16);
            backdrop-filter: blur(4px);
        }
        .rozet.up   { background: rgba(52, 211, 153, .22); border-color: rgba(110, 231, 183, .45); color: #d1fae5; }
        .rozet.down { background: rgba(248, 113, 113, .2); border-color: rgba(252, 165, 165, .4); color: #fecaca; }
        .hero-sag { margin-left: auto; min-width: 260px; flex: 0 1 340px; }
        .spark-baslik { display: flex; justify-content: space-between; font-size: 10.5px; font-weight: 600; letter-spacing: .6px; text-transform: uppercase; color: rgba(209, 250, 229, .7); margin-bottom: 4px; }
        .hero svg { width: 100%; height: auto; display: block; }

        /* ── KPI şeridi ── */
        .kpiler {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(215px, 1fr));
            gap: 12px; margin: 18px 0;
        }
        .kpi {
            background: var(--card); border: 1px solid var(--border); border-radius: 15px;
            padding: 14px 16px; display: flex; align-items: center; gap: 13px;
            box-shadow: var(--golge);
            animation: giris .5s cubic-bezier(.22, 1, .36, 1) both;
        }
        .kpi:nth-child(2) { animation-delay: 50ms; }
        .kpi:nth-child(3) { animation-delay: 100ms; }
        .kpi:nth-child(4) { animation-delay: 150ms; }
        .kpi-ico { width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; font-size: 17px; }
        .kpi.altin  .kpi-ico { background: var(--amber-soft); color: var(--amber); }
        .kpi.mor    .kpi-ico { background: var(--indigo-soft); color: var(--indigo); }
        .kpi.mavi   .kpi-ico { background: var(--sky-soft); color: var(--sky); }
        .kpi.yesil  .kpi-ico { background: var(--emerald-soft); color: var(--emerald); }
        .kpi-l { font-size: 10.5px; font-weight: 700; letter-spacing: .4px; text-transform: uppercase; color: var(--text-3); }
        .kpi-v { font-size: 17px; font-weight: 800; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .kpi-s { font-size: 11px; color: var(--text-3); margin-top: 1px; }

        /* ── İçerik ızgarası ── */
        .izgara { display: grid; grid-template-columns: minmax(0, 7fr) minmax(0, 5fr); gap: 16px; align-items: start; }
        .kutu {
            background: var(--card); border: 1px solid var(--border); border-radius: 16px;
            box-shadow: var(--golge); overflow: hidden;
            animation: giris .5s cubic-bezier(.22, 1, .36, 1) .12s both;
        }
        .kutu-bas {
            display: flex; align-items: center; gap: 9px;
            padding: 15px 18px; border-bottom: 1px solid var(--border);
            font-size: 13.5px; font-weight: 700;
        }
        .kutu-bas i { color: var(--emerald); }
        .kutu-bas .sag { margin-left: auto; font-size: 11px; font-weight: 600; color: var(--text-3); display: inline-flex; gap: 12px; }
        .kutu-bas .sag .nk { display: inline-flex; align-items: center; gap: 5px; }
        .kutu-bas .sag .nk::before { content: ''; width: 9px; height: 9px; border-radius: 3px; background: var(--emerald-2); }
        .kutu-bas .sag .nk.gri::before { background: #cbd5e1; }
        .grafik-alan { padding: 14px 16px 10px; height: 330px; }

        /* ── Ay listesi (tablo yerine satır kartları) ── */
        .aylar { padding: 6px 10px 10px; }
        .ay-satir {
            display: grid;
            grid-template-columns: 52px minmax(0, 1fr) auto;
            gap: 4px 14px; align-items: center;
            padding: 9px 10px; border-radius: 12px; transition: background .15s;
        }
        .ay-satir:hover { background: #f8fafc; }
        .ay-satir + .ay-satir { border-top: 1px solid #f1f5f9; }
        .ay-ad { font-size: 12.5px; font-weight: 700; color: var(--text-2); }
        .ay-ad .yildiz { color: var(--amber); margin-left: 3px; }
        .ay-orta { min-width: 0; }
        .ay-bar { position: relative; height: 8px; border-radius: 99px; background: #eef2f7; overflow: hidden; }
        .ay-bar .dolu { position: absolute; inset: 0 auto 0 0; border-radius: 99px; background: linear-gradient(90deg, var(--emerald-2), var(--emerald)); }
        .ay-bar .onceki { position: absolute; top: -3px; bottom: -3px; width: 2px; background: #94a3b8; border-radius: 2px; }
        .ay-alt { display: flex; gap: 10px; font-size: 10.5px; color: var(--text-3); margin-top: 4px; }
        .ay-deger { text-align: right; }
        .ay-tutar { font-size: 13.5px; font-weight: 800; white-space: nowrap; }
        .ay-yoy { margin-top: 1px; }
        .yoy { font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
        .yoy.up { color: var(--emerald); }
        .yoy.down { color: var(--red); }
        .yoy.notr { color: var(--text-3); font-weight: 500; }
        .aylar-toplam {
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            padding: 13px 20px; border-top: 2px solid var(--border); background: #fafbfd;
            font-size: 13px; font-weight: 800; flex-wrap: wrap;
        }
        .aylar-toplam .etiket { color: var(--text-2); font-weight: 700; font-size: 11.5px; text-transform: uppercase; letter-spacing: .5px; }
        .aylar-toplam .kiyas { font-size: 11px; color: var(--text-3); font-weight: 600; }

        .bos {
            padding: 60px 20px; text-align: center; color: var(--text-3);
        }
        .bos i { font-size: 40px; margin-bottom: 12px; display: block; color: #cbd5e1; }
        .bos b { display: block; color: var(--text-2); font-size: 15px; margin-bottom: 4px; }

        @keyframes giris { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }

        /* ── Mobil ── */
        @media (max-width: 900px) {
            .izgara { grid-template-columns: 1fr; }
            .grafik-alan { height: 260px; }
        }
        @media (max-width: 640px) {
            main { padding: 14px 14px 40px; }
            .hero { padding: 20px 18px; gap: 16px; }
            .hero-sag { min-width: 0; flex: 1 1 100%; margin-left: 0; }
            .aksiyon { margin-left: 0; width: 100%; }
            .aksiyon .btn span { display: none; }         /* mobilde ikon-buton */
            .aksiyon .btn, .yil-sec { flex: 0 0 auto; }
            .ay-satir { grid-template-columns: 44px minmax(0, 1fr) auto; padding: 9px 6px; }
        }

        /* ── Yazdırma ── */
        @media print {
            body { background: #fff; }
            .top, .no-print { display: none !important; }
            main { padding: 0; max-width: none; }
            .hero { background: #065f46 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; border-radius: 10px; box-shadow: none; }
            .kutu, .kpi { box-shadow: none; break-inside: avoid; }
            .izgara { grid-template-columns: 1fr; }
            .grafik-alan { height: 300px; }
        }
        /* Koyu tema hazırlığı (raf açılınca otomatik) */
        html.akl-dark body { background: var(--bg); }
        html.akl-dark .kutu, html.akl-dark .kpi, html.akl-dark .geri, html.akl-dark .btn, html.akl-dark .yil-sec { background: var(--card); }
    </style>
</head>
<body>

<header class="top no-print">
    <div class="top-in">
        <a href="dashboard.php" class="geri" title="Rapor listesine dön"><i class="fa-solid fa-arrow-left"></i></a>
        <span class="t-ico"><i class="fa-solid fa-calendar-days"></i></span>
        <div class="t-baslik">
            <h1>Aylık Satış Raporu</h1>
            <p>KDV dahil net ciro (iadeler düşülmüş) · geçen yıl kıyası</p>
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
            <a href="rapor_satis_aylik_excel_xlsx.php?year=<?php echo (int) $year; ?>" class="btn birincil" title="Excel indir"><i class="fa-solid fa-file-excel"></i><span>Excel</span></a>
        </div>
    </div>
</header>

<main>
    <!-- HERO: yılın karnesi -->
    <section class="hero">
        <div class="hero-sol">
            <div class="hero-eyeb"><i class="fa-solid fa-chart-line"></i> <?php echo $year; ?> Toplam Ciro</div>
            <div class="hero-ciro"><?php echo $kisa($yearlyTotal); ?><small>₺</small></div>
            <div class="hero-tam"><?php echo number_format($yearlyTotal, 2, ',', '.'); ?> ₺ · KDV dahil</div>
            <div class="hero-rozetler">
                <?php if ($yoyToplam !== null): ?>
                    <span class="rozet <?php echo $yoyToplam >= 0 ? 'up' : 'down'; ?>">
                        <i class="fa-solid fa-arrow-trend-<?php echo $yoyToplam >= 0 ? 'up' : 'down'; ?>"></i>
                        <?php echo number_format(abs($yoyToplam), 1, ',', '.'); ?>% · <?php echo $prevYear; ?>'e göre
                    </span>
                <?php else: ?>
                    <span class="rozet"><i class="fa-regular fa-circle-question"></i> <?php echo $prevYear; ?> verisi yok</span>
                <?php endif; ?>
                <span class="rozet"><i class="fa-solid fa-file-invoice"></i> <?php echo number_format($toplamIslem, 0, ',', '.'); ?> işlem</span>
                <span class="rozet"><i class="fa-solid fa-basket-shopping"></i> ort. <?php echo number_format($ortSepet, 0, ',', '.'); ?> ₺</span>
            </div>
        </div>
        <div class="hero-sag">
            <div class="spark-baslik"><span>Yılın Nabzı</span><span>Oca → Ara</span></div>
            <svg viewBox="0 0 <?php echo $spW; ?> <?php echo $spH; ?>" preserveAspectRatio="none" aria-hidden="true">
                <defs>
                    <linearGradient id="spg" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0" stop-color="#6ee7b7" stop-opacity=".55"/>
                        <stop offset="1" stop-color="#6ee7b7" stop-opacity="0"/>
                    </linearGradient>
                </defs>
                <polygon points="<?php echo $spArea; ?>" fill="url(#spg)"/>
                <polyline points="<?php echo $spLine; ?>" fill="none" stroke="#a7f3d0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
                <?php if ($mx['M'] > 0): $pk = explode(',', $spPts[$mx['M'] - 1]); ?>
                    <circle cx="<?php echo $pk[0]; ?>" cy="<?php echo $pk[1]; ?>" r="4" fill="#fff"/>
                    <circle cx="<?php echo $pk[0]; ?>" cy="<?php echo $pk[1]; ?>" r="7" fill="none" stroke="rgba(255,255,255,.5)" stroke-width="1.5"/>
                <?php endif; ?>
            </svg>
        </div>
    </section>

    <!-- KPI şeridi -->
    <section class="kpiler">
        <div class="kpi altin">
            <span class="kpi-ico"><i class="fa-solid fa-trophy"></i></span>
            <div>
                <div class="kpi-l">En İyi Ay</div>
                <div class="kpi-v"><?php echo $mx['M'] ? $monthNames[$mx['M']] : '—'; ?></div>
                <div class="kpi-s"><?php echo $mx['M'] ? $kisa($mx['NET']) . ' ₺' : 'veri yok'; ?></div>
            </div>
        </div>
        <div class="kpi mor">
            <span class="kpi-ico"><i class="fa-solid fa-chart-simple"></i></span>
            <div>
                <div class="kpi-l">Aylık Ortalama</div>
                <div class="kpi-v"><?php echo $kisa($avgMonth); ?> ₺</div>
                <div class="kpi-s"><?php echo $totalMonthsWithSales; ?> satışlı ay</div>
            </div>
        </div>
        <div class="kpi mavi">
            <span class="kpi-ico"><i class="fa-solid fa-bolt"></i></span>
            <div>
                <div class="kpi-l">En Hareketli Ay</div>
                <div class="kpi-v"><?php echo $mt['M'] ? $monthNames[$mt['M']] : '—'; ?></div>
                <div class="kpi-s"><?php echo number_format($mt['C'], 0, ',', '.'); ?> işlem</div>
            </div>
        </div>
        <div class="kpi yesil">
            <span class="kpi-ico"><i class="fa-solid fa-clock-rotate-left"></i></span>
            <div>
                <div class="kpi-l"><?php echo $prevYear; ?> Toplamı</div>
                <div class="kpi-v"><?php echo $prevTotal > 0 ? $kisa($prevTotal) . ' ₺' : '—'; ?></div>
                <div class="kpi-s"><?php echo $yoyHtml($yearlyTotal, $prevTotal); ?></div>
            </div>
        </div>
    </section>

    <?php if ($yearlyTotal <= 0 && $prevTotal <= 0): ?>
        <div class="kutu"><div class="bos">
            <i class="fa-regular fa-folder-open"></i>
            <b><?php echo $year; ?> için satış kaydı yok</b>
            Üstteki yıl seçiciden başka bir yıl deneyin.
        </div></div>
    <?php else: ?>
    <div class="izgara">
        <!-- Grafik -->
        <section class="kutu">
            <div class="kutu-bas">
                <i class="fa-solid fa-chart-column"></i> Aylık Satış Dağılımı
                <span class="sag"><span class="nk"><?php echo $year; ?></span><span class="nk gri"><?php echo $prevYear; ?></span></span>
            </div>
            <div class="grafik-alan"><canvas id="grafik"></canvas></div>
        </section>

        <!-- Ay listesi -->
        <section class="kutu">
            <div class="kutu-bas"><i class="fa-solid fa-list-ul"></i> Aylık Detaylar
                <span class="sag" style="font-weight:600;">| çentik = <?php echo $prevYear; ?></span>
            </div>
            <div class="aylar">
                <?php foreach ($monthsData as $d):
                    $isBest = ($d['M'] === $mx['M'] && $d['V'] > 0);
                    $w  = 100 * $d['V'] / $maxNet;
                    $pw = min(100, 100 * $d['P'] / $maxNet);
                ?>
                <div class="ay-satir">
                    <div class="ay-ad"><?php echo $monthShort[$d['M']]; ?><?php if ($isBest): ?><i class="fa-solid fa-star yildiz" title="En iyi ay"></i><?php endif; ?></div>
                    <div class="ay-orta">
                        <div class="ay-bar">
                            <div class="dolu" style="width:<?php echo number_format(max($d['V'] > 0 ? 1.5 : 0, $w), 1, '.', ''); ?>%;"></div>
                            <?php if ($d['P'] > 0): ?><div class="onceki" style="left:<?php echo number_format($pw, 1, '.', ''); ?>%;" title="<?php echo $prevYear; ?>: <?php echo number_format($d['P'], 2, ',', '.'); ?> ₺"></div><?php endif; ?>
                        </div>
                        <div class="ay-alt">
                            <span><i class="fa-regular fa-file-lines"></i> <?php echo number_format($d['C'], 0, ',', '.'); ?> işlem</span>
                            <?php if ($d['P'] > 0): ?><span><?php echo $prevYear; ?>: <?php echo $kisa((float) $d['P']); ?> ₺</span><?php endif; ?>
                        </div>
                    </div>
                    <div class="ay-deger">
                        <div class="ay-tutar"><?php echo number_format($d['V'], 2, ',', '.'); ?> ₺</div>
                        <div class="ay-yoy"><?php echo $yoyHtml((float) $d['V'], (float) $d['P']); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="aylar-toplam">
                <span class="etiket">Toplam</span>
                <span class="kiyas"><?php echo $prevYear; ?>: <?php echo number_format($prevTotal, 2, ',', '.'); ?> ₺</span>
                <span><?php echo number_format($yearlyTotal, 2, ',', '.'); ?> ₺ <?php echo $yoyHtml($yearlyTotal, $prevTotal); ?></span>
            </div>
        </section>
    </div>
    <?php endif; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    var el = document.getElementById('grafik');
    if (!el || typeof Chart === 'undefined') { return; }
    var etiketler = <?php echo json_encode($chartLabels); ?>;
    var buYil     = <?php echo json_encode($chartValues, JSON_NUMERIC_CHECK); ?>;
    var gecenYil  = <?php echo json_encode($prevChartValues, JSON_NUMERIC_CHECK); ?>;
    var tl = function (v) { return v.toLocaleString('tr-TR', { style: 'currency', currency: 'TRY', maximumFractionDigits: 0 }); };
    var kisa = function (v) {
        if (v >= 1000000) return (v / 1000000).toLocaleString('tr-TR', { maximumFractionDigits: 1 }) + 'M';
        if (v >= 1000) return (v / 1000).toLocaleString('tr-TR', { maximumFractionDigits: 0 }) + 'B';
        return v.toLocaleString('tr-TR');
    };
    new Chart(el.getContext('2d'), {
        type: 'bar',
        data: {
            labels: etiketler,
            datasets: [
                {
                    label: '<?php echo $year; ?>',
                    data: buYil,
                    backgroundColor: function (ctx) {
                        var c = ctx.chart.ctx, a = ctx.chart.chartArea;
                        if (!a) { return 'rgba(16,185,129,.85)'; }
                        var g = c.createLinearGradient(0, a.top, 0, a.bottom);
                        g.addColorStop(0, 'rgba(16,185,129,.95)');
                        g.addColorStop(1, 'rgba(5,150,105,.65)');
                        return g;
                    },
                    borderRadius: 7,
                    borderSkipped: false,
                    maxBarThickness: 34,
                    order: 1
                },
                {
                    label: '<?php echo $prevYear; ?>',
                    data: gecenYil,
                    backgroundColor: 'rgba(148,163,184,.38)',
                    borderRadius: 6,
                    borderSkipped: false,
                    maxBarThickness: 34,
                    order: 2
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(15,23,42,.92)',
                    padding: 12,
                    cornerRadius: 10,
                    titleFont: { family: 'Montserrat', weight: '700' },
                    bodyFont: { family: 'Montserrat' },
                    callbacks: {
                        label: function (c) { return ' ' + c.dataset.label + ': ' + tl(c.parsed.y); }
                    }
                }
            },
            scales: {
                x: { ticks: { font: { size: 11, family: 'Montserrat', weight: '600' }, color: '#94a3b8' }, grid: { display: false }, border: { display: false } },
                y: {
                    beginAtZero: true,
                    ticks: { callback: function (v) { return kisa(v); }, font: { size: 11, family: 'Montserrat' }, color: '#94a3b8', maxTicksLimit: 6 },
                    grid: { color: 'rgba(15,23,42,.05)' }, border: { display: false }
                }
            }
        }
    });
})();
</script>

</body>
</html>
