<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");
/* --------------------------------------------------------------------------
    Son 30 Gün Ciroya Göre İlk 20 Ürün
    -------------------------------------------------------------------------- */
include_once(__DIR__ . "/../log_ip.php");

/* ---- Sorgu (TOP 20) ----------------------------------------------------- */
$sql = "
;WITH STOK_SATIS AS (
    SELECT
        S.STOCKREF,
        I.CODE AS STOK_KODU,
        I.NAME AS STOK_ADI,
        SUM(CASE WHEN S.TRCODE IN (7,8) THEN S.AMOUNT ELSE -S.AMOUNT END)                        AS TOPLAM_MIKTAR,
        SUM(CASE WHEN S.TRCODE IN (7,8) THEN S.PRICE * S.AMOUNT - S.DISTDISC
                 ELSE -(S.PRICE * S.AMOUNT - S.DISTDISC) END)                                    AS TOPLAM_CIRO
    FROM {$firmadonem}STLINE AS S WITH (NOLOCK)
    JOIN {$firma}ITEMS     AS I ON I.LOGICALREF = S.STOCKREF
    WHERE S.TRCODE IN (2,3,7,8)
      AND I.CODE        LIKE 'AKL%'
      AND S.DATE_       >= DATEADD(DAY,-30,GETDATE())
      AND S.DATE_       <  GETDATE()
      AND S.CANCELLED   = 0
    GROUP BY S.STOCKREF, I.CODE, I.NAME
)
	SELECT TOP (20)
	    STOK_KODU, STOK_ADI, TOPLAM_MIKTAR, TOPLAM_CIRO
	FROM STOK_SATIS
	ORDER BY TOPLAM_CIRO DESC;

	";
	$stmt = $dbh->prepare($sql);
	$stmt->execute();
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---- Dizi hazırlığı ve KPI'lar ---------------------------- */
$labels    = [];
$salesData = [];
$qtyData   = [];
$avgData   = [];
$sumSales  = 0;
$sumQty    = 0;

foreach ($rows as $key => $r) {
    $ortFiyat = empty($r['TOPLAM_MIKTAR']) ? 0 : round($r['TOPLAM_CIRO'] / $r['TOPLAM_MIKTAR'], 2);
    $rows[$key]['ORT_FIYAT'] = $ortFiyat;

    $labels[]    = $r['STOK_KODU'];
    $salesData[] = (float) $r['TOPLAM_CIRO'];
    $qtyData[]   = (float) $r['TOPLAM_MIKTAR'];
    $avgData[]   = (float) $ortFiyat;

    $sumSales += $r['TOPLAM_CIRO'];
    $sumQty   += $r['TOPLAM_MIKTAR'];
}

$avgPrice = $sumQty > 0 ? round($sumSales / $sumQty, 2) : 0;
$topProduct = empty($rows) ? ['STOK_KODU' => '-', 'TOPLAM_CIRO' => 0] : $rows[0];

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Son 30 Gun Urun Performansi &ndash; Top 20</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --bg: #f9fafb;
            --text-1: #1f2937;
            --text-2: #6b7280;
            --text-3: #9ca3af;
            --border: #e5e7eb;
            --red: #6F1022;
            --red-soft: #fef2f2;
            --emerald: #059669;
            --emerald-soft: #ecfdf5;
            --indigo: #4f46e5;
            --indigo-soft: #eef2ff;
            --amber: #d97706;
            --amber-soft: #fffbeb;
            --amber-deep: #92400e;
            --sky: #0284c7;
            --sky-soft: #eff6ff;
            --purple: #7c3aed;
            --purple-soft: #f5f3ff;
        }
        * { box-sizing: border-box; margin: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
        }

        .top-header {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(217, 119, 6, 0.22);
            box-shadow: 0 2px 8px rgba(217, 119, 6, 0.05);
        }
        .header-inner {
            max-width: 1280px; margin: 0 auto;
            padding: 14px 24px;
            display: flex; align-items: center; gap: 14px;
            flex-wrap: wrap;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
            flex-shrink: 0;
        }
        .header-back:hover { background: var(--amber-soft); color: var(--amber); }
        .header-divider { width: 1px; height: 24px; background: var(--border); flex-shrink: 0; }
        .header-title { min-width: 0; flex: 1 1 auto; }
        .header-title h1 {
            font-size: 16px; font-weight: 700; color: var(--text-1);
            display: flex; align-items: center; gap: 8px; margin: 0;
        }
        .header-title h1 .material-icons { color: var(--amber); font-size: 22px; }
        .header-title p {
            margin: 2px 0 0; font-size: 11.5px; color: var(--text-2); font-weight: 500;
        }
        .header-title p .badge {
            display: inline-block; padding: 1px 8px;
            background: var(--amber-soft); color: var(--amber-deep);
            border-radius: 100px; font-size: 10.5px; font-weight: 700; margin-left: 4px;
        }
        .header-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .btn-action {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 14px; background: #fff;
            border: 1px solid var(--border); border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12.5px; font-weight: 600;
            color: var(--text-1); cursor: pointer; transition: all 0.2s ease;
            min-height: 44px; text-decoration: none;
        }
        .btn-action:hover { background: var(--amber-soft); color: var(--amber-deep); border-color: rgba(217, 119, 6, 0.35); }
        .btn-action .material-icons { font-size: 18px; }
        .btn-print { background: var(--amber); color: #fff; border-color: var(--amber); box-shadow: 0 4px 12px rgba(217, 119, 6, 0.22); }
        .btn-print:hover { background: #b45309; color: #fff; border-color: #b45309; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(217, 119, 6, 0.32); }
        .btn-excel { background: var(--emerald); color: #fff; border-color: var(--emerald); box-shadow: 0 4px 12px rgba(5, 150, 105, 0.2); }
        .btn-excel:hover { background: #047857; color: #fff; border-color: #047857; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(5, 150, 105, 0.3); }

        main { max-width: 1280px; margin: 0 auto; padding: 22px 24px 60px; }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 14px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            padding: 16px 18px;
            display: flex; align-items: center; gap: 14px;
            position: relative; overflow: hidden;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .stat-card:nth-child(1) { animation-delay: 0ms; }
        .stat-card:nth-child(2) { animation-delay: 60ms; }
        .stat-card:nth-child(3) { animation-delay: 120ms; }
        .stat-card:nth-child(4) { animation-delay: 180ms; }
        .stat-card:nth-child(5) { animation-delay: 240ms; }
        .stat-card::before {
            content: ''; position: absolute;
            top: 0; left: 0; bottom: 0;
            width: 4px;
        }
        .stat-card.indigo::before  { background: var(--indigo); }
        .stat-card.emerald::before { background: var(--emerald); }
        .stat-card.purple::before  { background: var(--purple); }
        .stat-card.amber::before   { background: var(--amber); }
        .stat-card.red::before     { background: var(--red); }
        .stat-card .icon-box {
            width: 44px; height: 44px;
            flex-shrink: 0;
            border-radius: 12px;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .stat-card .icon-box .material-icons { font-size: 22px; }
        .stat-card.indigo  .icon-box { background: var(--indigo-soft);  color: var(--indigo); }
        .stat-card.emerald .icon-box { background: var(--emerald-soft); color: var(--emerald); }
        .stat-card.purple  .icon-box { background: var(--purple-soft);  color: var(--purple); }
        .stat-card.amber   .icon-box { background: var(--amber-soft);   color: var(--amber); }
        .stat-card.red     .icon-box { background: var(--red-soft);     color: var(--red); }
        .stat-card .stat-body { flex: 1 1 auto; min-width: 0; }
        .stat-card .stat-label {
            font-size: 10.5px; color: var(--text-2); font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.4px;
            display: block;
        }
        .stat-card .stat-value {
            display: block; margin-top: 3px;
            font-size: 18px; font-weight: 700; color: var(--text-1);
            line-height: 1.2;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .stat-card .stat-value .unit {
            font-size: 11px; font-weight: 500; color: var(--text-3); margin-left: 4px;
        }

        .content-row {
            display: grid;
            grid-template-columns: 7fr 5fr;
            gap: 18px;
            margin-bottom: 20px;
        }
        .glass-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(217, 119, 6, 0.18);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            padding: 20px 22px;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) 0.18s both;
        }
        .card-title {
            font-size: 13.5px; font-weight: 700; color: var(--text-1);
            display: flex; align-items: center; gap: 8px;
        }
        .card-title .material-icons { color: var(--amber); font-size: 19px; }

        .chart-head {
            display: flex; flex-wrap: wrap; gap: 10px;
            align-items: center; justify-content: space-between;
            margin-bottom: 14px;
        }
        .chart-toggle { display: inline-flex; gap: 0; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
        .chart-toggle button {
            padding: 8px 14px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 12px; font-weight: 600; color: var(--text-2);
            background: #fff; border: none; border-right: 1px solid var(--border);
            cursor: pointer; transition: all 0.15s ease;
            min-height: 36px;
        }
        .chart-toggle button:last-child { border-right: none; }
        .chart-toggle button:hover { background: var(--amber-soft); color: var(--amber-deep); }
        .chart-toggle button.active { background: var(--amber); color: #fff; }

        .chart-box { position: relative; height: 560px; }

        .gd-table { width: 100%; border-collapse: collapse; }
        .gd-table thead { background: linear-gradient(180deg, #fff, #fffdf5); }
        .gd-table thead th {
            padding: 12px 14px;
            font-size: 10px; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: 0.5px;
            text-align: left; border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .gd-table thead th.right { text-align: right; }
        .gd-table tbody td {
            padding: 11px 14px;
            font-size: 12.5px; color: var(--text-1);
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .gd-table tbody tr { transition: background 0.15s ease; }
        .gd-table tbody tr:hover { background: var(--amber-soft); }
        .gd-table tbody tr:last-child td { border-bottom: none; }
        .gd-table tbody tr.top3 { background: #fffdf5; }
        .gd-table tbody tr.top3:hover { background: var(--amber-soft); }
        .gd-table td.rank {
            width: 44px; white-space: nowrap;
        }
        .gd-table td.rank .chip {
            display: inline-flex; align-items: center; justify-content: center;
            width: 26px; height: 26px; border-radius: 8px;
            background: #fff; border: 1px solid var(--border);
            font-size: 11.5px; font-weight: 700; color: var(--text-2);
        }
        .gd-table tbody tr.top3 td.rank .chip {
            background: var(--amber); color: #fff; border-color: var(--amber);
        }
        .gd-table td.code { font-weight: 700; color: var(--amber-deep); white-space: nowrap; font-size: 12px; }
        .gd-table td.name { color: var(--text-1); font-size: 12px; }
        .gd-table td.right { text-align: right; font-weight: 600; white-space: nowrap; font-size: 12px; }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to   { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        @media (max-width: 1023px) {
            .stat-grid { grid-template-columns: repeat(3, 1fr); }
            .content-row { grid-template-columns: 1fr; }
            .chart-box { height: 420px; }
        }
        @media (max-width: 767px) {
            .header-inner { padding: 12px 14px; gap: 10px; }
            .header-title h1 { font-size: 14px; }
            .header-actions { width: 100%; justify-content: flex-start; }
            main { padding: 14px 12px 40px; }

            .stat-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 16px; }
            .stat-card { padding: 14px; gap: 10px; }
            .stat-card .icon-box { width: 38px; height: 38px; }
            .stat-card .icon-box .material-icons { font-size: 18px; }
            .stat-card .stat-value { font-size: 15px; }
            .stat-card:nth-child(5) { grid-column: span 2; }

            .glass-card { padding: 16px; }
            .chart-box { height: 340px; }
        }

        @media print {
            body { background: #fff; }
            .top-header { position: static; box-shadow: none; border-bottom: 2px solid #000; }
            .no-print, .header-actions { display: none !important; }
            .stat-card, .glass-card { box-shadow: none; border: 1px solid #ccc; animation: none; }
            .chart-box { max-height: 320px; }
            .gd-table tbody tr:hover { background: transparent; }
        }
    </style>
</head>
<body>

<header class="top-header no-print">
    <div class="header-inner">
        <a href="dashboard.php" class="header-back" title="Geri">
            <span class="material-icons">arrow_back</span>
        </a>
        <div class="header-divider"></div>
        <div class="header-title">
            <h1><span class="material-icons">emoji_events</span>Son 30 Gun Urun Performansi</h1>
            <p>
                Ciroya gore en iyi 20 urun
                <span class="badge">TOP 20</span>
            </p>
        </div>
        <div class="header-actions">
            <button onclick="window.print()" class="btn-action btn-print" type="button" title="Yazdir">
                <span class="material-icons">print</span>
                <span>Yazdir</span>
            </button>
            <button id="exportCSV" class="btn-action btn-excel" type="button" title="CSV">
                <span class="material-icons">download</span>
                <span>CSV</span>
            </button>
        </div>
    </div>
</header>

<main>

    <!-- KPI Kartlari -->
    <div class="stat-grid">
        <div class="stat-card indigo">
            <span class="icon-box"><span class="material-icons">inventory_2</span></span>
            <div class="stat-body">
                <span class="stat-label">Urun Sayisi</span>
                <span class="stat-value"><?php echo count($rows); ?><span class="unit">Cesit</span></span>
            </div>
        </div>
        <div class="stat-card emerald">
            <span class="icon-box"><span class="material-icons">payments</span></span>
            <div class="stat-body">
                <span class="stat-label">Toplam Ciro</span>
                <span class="stat-value"><?php echo number_format($sumSales, 0, ',', '.'); ?><span class="unit">TL</span></span>
            </div>
        </div>
        <div class="stat-card purple">
            <span class="icon-box"><span class="material-icons">tag</span></span>
            <div class="stat-body">
                <span class="stat-label">Toplam Adet</span>
                <span class="stat-value"><?php echo number_format($sumQty, 0, ',', '.'); ?><span class="unit">Adet</span></span>
            </div>
        </div>
        <div class="stat-card amber">
            <span class="icon-box"><span class="material-icons">price_check</span></span>
            <div class="stat-body">
                <span class="stat-label">Ortalama Fiyat</span>
                <span class="stat-value"><?php echo number_format($avgPrice, 2, ',', '.'); ?><span class="unit">TL</span></span>
            </div>
        </div>
        <div class="stat-card red">
            <span class="icon-box"><span class="material-icons">workspace_premium</span></span>
            <div class="stat-body">
                <span class="stat-label">En Degerli Urun</span>
                <span class="stat-value"><?php echo htmlspecialchars((string) $topProduct['STOK_KODU']); ?></span>
            </div>
        </div>
    </div>

    <!-- Grafik + Tablo -->
    <div class="content-row">
        <div class="glass-card">
            <div class="chart-head">
                <h3 id="chartTitle" class="card-title">
                    <span class="material-icons">bar_chart</span>
                    Ciroya Gore Top 20 Urun
                </h3>
                <div class="chart-toggle no-print" role="group">
                    <button type="button" class="active" data-type="sales">Ciro</button>
                    <button type="button" data-type="qty">Adet</button>
                    <button type="button" data-type="avg">Ort. Fiyat</button>
                </div>
            </div>
            <div class="chart-box">
                <canvas id="performanceChart"></canvas>
            </div>
        </div>

        <div class="glass-card">
            <h3 class="card-title" style="margin-bottom:14px;">
                <span class="material-icons">list_alt</span>
                Detayli Liste
            </h3>
            <div style="overflow-x:auto;">
                <table id="prod_table" class="gd-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Kod</th>
                            <th>Urun Adi</th>
                            <th class="right">Ciro</th>
                            <th class="right">Adet</th>
                            <th class="right">Ort. Fiyat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $r_idx = 1; foreach ($rows as $r): $isTop3 = ($r_idx <= 3); ?>
                            <tr<?php echo $isTop3 ? ' class="top3"' : ''; ?>>
                                <td class="rank"><span class="chip"><?php echo $r_idx++; ?></span></td>
                                <td class="code"><?= htmlspecialchars((string) $r['STOK_KODU']) ?></td>
                                <td class="name"><?= htmlspecialchars((string) $r['STOK_ADI']) ?></td>
                                <td class="right" data-order="<?= number_format((float) $r['TOPLAM_CIRO'], 6, '.', '') ?>"><?= number_format((float) $r['TOPLAM_CIRO'], 2, ',', '.') ?> &#8378;</td>
                                <td class="right" data-order="<?= number_format((float) $r['TOPLAM_MIKTAR'], 6, '.', '') ?>"><?= number_format((float) $r['TOPLAM_MIKTAR'], 0, ',', '.') ?></td>
                                <td class="right" data-order="<?= number_format((float) $r['ORT_FIYAT'], 6, '.', '') ?>"><?= number_format((float) $r['ORT_FIYAT'], 2, ',', '.') ?> &#8378;</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-3);">
                                <span class="material-icons" style="display:block;font-size:32px;margin-bottom:8px;">inbox</span>
                                Son 30 gunde satis verisi bulunamadi
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</main>

<script>
// Data from PHP
const labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
const chartData = {
    sales: <?= json_encode($salesData, JSON_NUMERIC_CHECK) ?>,
    qty:   <?= json_encode($qtyData,   JSON_NUMERIC_CHECK) ?>,
    avg:   <?= json_encode($avgData,   JSON_NUMERIC_CHECK) ?>
};

const AMBER_BG     = 'rgba(217, 119, 6, 0.82)';
const AMBER_BORDER = 'rgba(217, 119, 6, 1)';

// Chart.js Setup (horizontal bar)
const chartCtx = document.getElementById('performanceChart').getContext('2d');
const performanceChart = new Chart(chartCtx, {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [{
            label: 'Ciro (TL)',
            data: chartData.sales,
            backgroundColor: AMBER_BG,
            borderColor: AMBER_BORDER,
            borderWidth: 1,
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: 'y',
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': ' + context.parsed.x.toLocaleString('tr-TR', {
                            style: 'currency',
                            currency: 'TRY'
                        });
                    }
                }
            }
        },
        scales: {
            x: {
                beginAtZero: true,
                grid: { color: 'rgba(148,163,184,.15)' },
                ticks: {
                    callback: function(value) { return value.toLocaleString('tr-TR'); },
                    font: { size: 11, family: 'Montserrat' }
                }
            },
            y: {
                grid: { display: false },
                ticks: { font: { size: 11, family: 'Montserrat' } }
            }
        }
    }
});

// Interactive Chart Toggle
document.querySelectorAll('.chart-toggle button').forEach(button => {
    button.addEventListener('click', function() {
        const dataType = this.getAttribute('data-type');

        document.querySelectorAll('.chart-toggle button').forEach(btn => btn.classList.remove('active'));
        this.classList.add('active');

        let newLabel, newTitle;
        switch(dataType) {
            case 'qty':
                newLabel = 'Adet';
                newTitle = 'Adede Gore Top 20 Urun';
                break;
            case 'avg':
                newLabel = 'Ortalama Fiyat (TL)';
                newTitle = 'Ortalama Fiyata Gore Top 20 Urun';
                break;
            default:
                newLabel = 'Ciro (TL)';
                newTitle = 'Ciroya Gore Top 20 Urun';
                break;
        }
        performanceChart.data.datasets[0].data = chartData[dataType];
        performanceChart.data.datasets[0].label = newLabel;
        document.getElementById('chartTitle').innerHTML = '<span class="material-icons">bar_chart</span> ' + newTitle;
        performanceChart.update();
    });
});

// CSV Export
document.getElementById('exportCSV').addEventListener('click', function() {
    let csv = 'Kod,Urun Adi,Ciro (TL),Adet,Ort. Fiyat (TL)\n';
    <?php foreach ($rows as $r): ?>
    csv += '<?= addslashes((string) $r['STOK_KODU']) ?>,<?= addslashes((string) $r['STOK_ADI']) ?>,<?= $r['TOPLAM_CIRO'] ?>,<?= $r['TOPLAM_MIKTAR'] ?>,<?= $r['ORT_FIYAT'] ?>\n';
    <?php endforeach; ?>

    const blob = new Blob(["\ufeff" + csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'urun_top20_<?= date("Y-m-d") ?>.csv';
    link.click();
});
</script>
</body>
</html>
