<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

// Yetki kontrolü
if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

function raporDateToTimestamp(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    $ts = strtotime((string) $value);
    return ($ts === false) ? null : $ts;
}

// 1) Aylık toplam tutarlar (Grafik için)
$monthly = [];
$sql = "
SELECT
    CONVERT(char(7), C.DUEDATE, 126) AS AY,
    SUM(C.AMOUNT) AS TUTAR
FROM {$firmadonem}CSCARD C WITH(NOLOCK)
INNER JOIN (
    SELECT
        T.CSREF,
        ROW_NUMBER() OVER (PARTITION BY T.CSREF ORDER BY T.LOGICALREF DESC) AS RN
    FROM {$firmadonem}CSTRANS T WITH(NOLOCK)
    WHERE T.TRCODE IN (2, 3, 4, 5, 6, 7, 8)
) TX ON TX.CSREF = C.LOGICALREF AND TX.RN = 1
WHERE C.CURRSTAT = '9'
GROUP BY CONVERT(char(7), C.DUEDATE, 126)
ORDER BY AY
";
$stmtMonthly = $dbh->prepare($sql);
$stmtMonthly->execute();
while ($r = $stmtMonthly->fetch(PDO::FETCH_ASSOC)) {
    $monthly[$r['AY']] = (float) $r['TUTAR'];
}

// 2) Detaylı liste ve KPI'lar
$details = [];
$totalCount = 0;
$totalAmount = 0.0;
$maxDueTs = null;
$minDueTs = null;
$sql2 = "
SELECT
    C.DUEDATE,
    C.AMOUNT,
    C.NEWSERINO,
    C.SETDATE,
    ISNULL(CL.DEFINITION_, '---') AS CARI, ISNULL(R.GENEXP1, '') AS ACIKLAMA
FROM {$firmadonem}CSCARD C WITH(NOLOCK)
INNER JOIN (
    SELECT
        T.CSREF,
        T.CARDREF,
        T.ROLLREF,
        ROW_NUMBER() OVER (PARTITION BY T.CSREF ORDER BY T.LOGICALREF DESC) AS RN
    FROM {$firmadonem}CSTRANS T WITH(NOLOCK)
    WHERE T.TRCODE IN (2, 3, 4, 5, 6, 7, 8)
) TX ON TX.CSREF = C.LOGICALREF AND TX.RN = 1
LEFT JOIN {$firmadonem}CSROLL R WITH(NOLOCK) ON R.LOGICALREF = TX.ROLLREF
LEFT JOIN {$firma}CLCARD CL WITH(NOLOCK) ON CL.LOGICALREF = TX.CARDREF
WHERE C.CURRSTAT = '9'
ORDER BY C.DUEDATE
";

$stmtDetails = $dbh->prepare($sql2);
$stmtDetails->execute();
while ($r = $stmtDetails->fetch(PDO::FETCH_ASSOC)) {
    $amount = isset($r['AMOUNT']) ? (float) $r['AMOUNT'] : 0.0;
    $dueTs = raporDateToTimestamp($r['DUEDATE'] ?? null);
    $setTs = raporDateToTimestamp($r['SETDATE'] ?? null);

    $r['AMOUNT'] = $amount;
    $r['_DUE_TS'] = $dueTs;
    $r['_DUE_ISO'] = $dueTs !== null ? date('Y-m-d', $dueTs) : '';
    $r['_DUE_FMT'] = $dueTs !== null ? date('d.m.Y', $dueTs) : '-';
    $r['_SET_ISO'] = $setTs !== null ? date('Y-m-d', $setTs) : '';
    $r['_SET_FMT'] = $setTs !== null ? date('d.m.Y', $setTs) : '-';

    $details[] = $r;
    $totalCount++;
    $totalAmount += $amount;

    if ($dueTs !== null && ($maxDueTs === null || $dueTs > $maxDueTs)) {
        $maxDueTs = $dueTs;
    }
    if ($dueTs !== null && ($minDueTs === null || $dueTs < $minDueTs)) {
        $minDueTs = $dueTs;
    }
}

// Bugünden sonraki vadeler
$upcomingCount = 0;
$upcomingAmount = 0.0;
$todayTs = strtotime(date('Y-m-d')) ?: time();
foreach ($details as $d) {
    if (($d['_DUE_TS'] ?? null) !== null && (int) $d['_DUE_TS'] >= $todayTs) {
        $upcomingCount++;
        $upcomingAmount += (float) ($d['AMOUNT'] ?? 0);
    }
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kendi Ceklerimiz Raporu</title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">

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
            --sky: #0284c7;
            --sky-soft: #eff6ff;
            --purple: #7c3aed;
            --purple-soft: #f5f3ff;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
            margin: 0;
        }

        /* Sticky header */
        .top-header {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(124, 58, 237, 0.18);
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.04);
        }
        .header-inner {
            max-width: 1280px; margin: 0 auto;
            padding: 14px 24px;
            display: flex; align-items: center; gap: 14px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 10px;
            color: var(--text-2); text-decoration: none;
            transition: all 0.2s ease; flex-shrink: 0;
        }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--purple); }
        .header-divider { width: 1px; height: 24px; background: var(--border); flex-shrink: 0; }
        .header-title {
            font-size: 16px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 8px;
            flex: 1 1 auto; min-width: 0; margin: 0;
        }
        .header-title .material-icons { color: var(--purple); font-size: 20px; }
        .header-title .title-text {
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .header-actions { display: inline-flex; align-items: center; gap: 8px; flex-shrink: 0; }

        .btn-print, .btn-excel {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 14px; min-height: 40px;
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 12.5px; font-weight: 700;
            cursor: pointer; transition: all 0.2s ease;
            border: none; text-decoration: none; white-space: nowrap;
        }
        .btn-print { background: var(--purple); color: #fff; box-shadow: 0 4px 12px rgba(124, 58, 237, 0.2); }
        .btn-print:hover { background: #6d28d9; transform: translateY(-1px); }
        .btn-excel { background: var(--emerald); color: #fff; box-shadow: 0 4px 12px rgba(5, 150, 105, 0.2); }
        .btn-excel:hover { background: #047857; transform: translateY(-1px); }
        .btn-print .material-icons, .btn-excel .material-icons { font-size: 16px; }

        main { max-width: 1280px; margin: 0 auto; padding: 20px 24px 60px; }

        /* Glass card base */
        .glass-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(124, 58, 237, 0.18);
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            will-change: transform, opacity;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            margin-bottom: 16px;
            overflow: hidden;
        }

        /* Stat cards */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }
        .stat-card {
            padding: 14px 16px;
        }
        .stat-card:nth-child(1) { animation-delay: 0ms; }
        .stat-card:nth-child(2) { animation-delay: 60ms; }
        .stat-card:nth-child(3) { animation-delay: 120ms; }
        .stat-card:nth-child(4) { animation-delay: 180ms; }

        .stat-card .top-row {
            display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;
        }
        .stat-card .label {
            font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            color: var(--text-2);
        }
        .stat-card .value {
            font-size: 22px; font-weight: 700;
            color: var(--text-1); margin-top: 6px;
            line-height: 1.2;
        }
        .stat-card .sub {
            font-size: 11px; color: var(--text-3); margin-top: 2px;
        }
        .stat-card .ico-box {
            width: 38px; height: 38px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .stat-card .ico-box .material-icons { font-size: 20px; }

        .stat-card.tone-purple { border-color: rgba(124, 58, 237, 0.22); background: linear-gradient(180deg, var(--purple-soft), #fff); }
        .stat-card.tone-purple .label { color: var(--purple); }
        .stat-card.tone-purple .ico-box { background: rgba(124, 58, 237, 0.12); }
        .stat-card.tone-purple .ico-box .material-icons { color: var(--purple); }

        .stat-card.tone-emerald { border-color: rgba(5, 150, 105, 0.22); background: linear-gradient(180deg, var(--emerald-soft), #fff); }
        .stat-card.tone-emerald .label { color: var(--emerald); }
        .stat-card.tone-emerald .ico-box { background: rgba(5, 150, 105, 0.12); }
        .stat-card.tone-emerald .ico-box .material-icons { color: var(--emerald); }

        .stat-card.tone-amber { border-color: rgba(217, 119, 6, 0.22); background: linear-gradient(180deg, var(--amber-soft), #fff); }
        .stat-card.tone-amber .label { color: var(--amber); }
        .stat-card.tone-amber .ico-box { background: rgba(217, 119, 6, 0.12); }
        .stat-card.tone-amber .ico-box .material-icons { color: var(--amber); }

        .stat-card.tone-indigo { border-color: rgba(79, 70, 229, 0.22); background: linear-gradient(180deg, var(--indigo-soft), #fff); }
        .stat-card.tone-indigo .label { color: var(--indigo); }
        .stat-card.tone-indigo .ico-box { background: rgba(79, 70, 229, 0.12); }
        .stat-card.tone-indigo .ico-box .material-icons { color: var(--indigo); }

        /* Section */
        .section { animation-delay: 240ms; }
        .section-head {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(180deg, #fff, #faf5ff);
        }
        .section-head h3 {
            margin: 0;
            font-size: 14px; font-weight: 700;
            color: var(--text-1);
            display: inline-flex; align-items: center; gap: 8px;
        }
        .section-head h3 .material-icons { color: var(--purple); font-size: 18px; }

        /* Chart */
        .chart-wrap { padding: 18px 20px; }
        .chart-inner { position: relative; height: 320px; }

        /* Table (gd-table) */
        .table-scroll { overflow-x: auto; }
        .gd-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .gd-table thead { background: linear-gradient(180deg, #fff, #faf5ff); }
        .gd-table thead th {
            padding: 11px 14px;
            font-size: 10px; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: 0.5px;
            text-align: left;
            border-bottom: 1px solid var(--border);
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
        .gd-table tbody tr:hover { background: #faf5ff; }
        .gd-table tbody tr.row-past { background: var(--red-soft); }
        .gd-table tbody tr.row-past:hover { background: #fee2e2; }

        .cell-right { text-align: right; }
        .cell-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .cell-mono { font-family: 'JetBrains Mono', 'Courier New', monospace; font-size: 11.5px; }
        .val-strong { font-weight: 700; color: var(--text-1); }
        .date-past { color: var(--red); font-weight: 700; }

        /* DataTables customization */
        .dataTable-top, .dataTable-bottom { padding: 10px 4px; }
        .dataTable-input {
            min-height: 44px; font-size: 16px;
            padding: 8px 12px; border-radius: 10px;
            border: 1px solid var(--border);
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: #fff;
            transition: all 0.2s ease;
        }
        .dataTable-input:focus {
            outline: none;
            border-color: rgba(124, 58, 237, 0.5);
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }
        .dataTable-selector { min-height: 44px; font-size: 16px; border-radius: 8px; padding: 4px 8px; }
        .dataTable-pagination a {
            min-width: 44px; min-height: 44px;
            display: inline-flex; align-items: center; justify-content: center;
            padding: 6px 10px; border-radius: 8px;
            font-size: 12.5px;
            color: var(--text-2);
        }
        .dataTable-pagination a:hover { background: var(--purple-soft); color: var(--purple); }
        .dataTable-pagination .active a {
            background: var(--purple) !important; color: #fff !important;
        }

        /* simple-datatables v2 (kucuk harf class) uyumu */
        .datatable-top, .datatable-bottom { padding: 10px 4px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
        .datatable-input { min-height: 44px; font-size: 16px; padding: 8px 12px; border-radius: 10px; border: 1px solid var(--border); background: #fff; }
        .datatable-input:focus { outline: none; border-color: var(--purple); }
        .datatable-selector { min-height: 36px; border-radius: 8px; padding: 4px 8px; border: 1px solid var(--border); }
        .datatable-info { font-size: 12px; color: var(--text-2); }
        .datatable-pagination ul, .datatable-pagination-list { display: flex; flex-wrap: wrap; gap: 4px; list-style: none; margin: 0; padding: 0; justify-content: flex-end; }
        .datatable-pagination li a, .datatable-pagination li button,
        .datatable-pagination-list li a, .datatable-pagination-list li button {
            min-width: 36px; min-height: 36px; display: inline-flex; align-items: center; justify-content: center;
            padding: 6px 10px; border-radius: 8px; font-size: 12.5px; color: var(--text-2);
            border: 1px solid var(--border); background: #fff; cursor: pointer; text-decoration: none;
        }
        .datatable-pagination li a:hover, .datatable-pagination-list li a:hover { background: #f3f4f6; color: var(--purple); }
        .datatable-pagination li.active a, .datatable-pagination li.active button,
        .datatable-pagination-list li.active a, .datatable-pagination-list li.active button { background: var(--purple); color: #fff; border-color: var(--purple); }
        .datatable-pagination li.disabled a, .datatable-pagination-list li.disabled a { opacity: 0.4; cursor: not-allowed; }

        /* Animations */
        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to   { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        @media (max-width: 1024px) {
            .stat-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 767px) {
            .header-inner { padding: 12px 14px; gap: 10px; }
            .header-title { font-size: 14px; }
            .header-title .material-icons { font-size: 18px; }
            .btn-print, .btn-excel { padding: 7px 10px; font-size: 11.5px; }
            .btn-print span:not(.material-icons), .btn-excel span:not(.material-icons) { display: none; }

            main { padding: 14px 12px 40px; }
            .stat-grid { gap: 10px; }
            .stat-card .value { font-size: 18px; }
            .stat-card .ico-box { display: none; }
            .chart-inner { height: 260px; }
            .gd-table thead th, .gd-table tbody td { padding: 9px 10px; font-size: 11.5px; }
        }

        /* Print */
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .top-header { position: static; box-shadow: none; border-bottom: 2px solid #000; }
            .glass-card { box-shadow: none; border: 1px solid #ccc; animation: none; break-inside: avoid; }
            .stat-card { background: #fff !important; }
            .chart-inner { height: 250px; }
            .gd-table { font-size: 10pt; }
        }
    </style>
</head>
<body>

<!-- Header -->
<header class="top-header">
    <div class="header-inner">
        <a href="dashboard.php" class="header-back" title="Geri">
            <span class="material-icons">arrow_back</span>
        </a>
        <div class="header-divider"></div>
        <h1 class="header-title">
            <span class="material-icons">receipt_long</span>
            <span class="title-text">Kendi Ceklerimiz Raporu</span>
        </h1>
        <div class="header-actions no-print">
            <button onclick="window.print()" class="btn-print" type="button">
                <span class="material-icons">print</span>
                <span>Yazdir</span>
            </button>
            <a href="rapor_kendi_cekler_excel_xlsx.php" class="btn-excel">
                <span class="material-icons">download</span>
                <span>Excel</span>
            </a>
        </div>
    </div>
</header>

<main>

    <!-- KPI Kartlari -->
    <div class="stat-grid">
        <div class="glass-card stat-card tone-purple">
            <div class="top-row">
                <div>
                    <div class="label">Toplam Cek Adedi</div>
                    <div class="value"><?php echo number_format($totalCount, 0, ',', '.'); ?></div>
                    <div class="sub">Tum cek ve senetler</div>
                </div>
                <span class="ico-box"><span class="material-icons">summarize</span></span>
            </div>
        </div>

        <div class="glass-card stat-card tone-emerald">
            <div class="top-row">
                <div style="min-width: 0;">
                    <div class="label">Toplam Tutar</div>
                    <div class="value"><?php echo number_format($totalAmount, 2, ',', '.'); ?> &#8378;</div>
                    <div class="sub">Tum cekler toplami</div>
                </div>
                <span class="ico-box"><span class="material-icons">paid</span></span>
            </div>
        </div>

        <div class="glass-card stat-card tone-amber">
            <div class="top-row">
                <div>
                    <div class="label">Yaklasan Vadeler</div>
                    <div class="value"><?php echo number_format($upcomingCount, 0, ',', '.'); ?></div>
                    <div class="sub"><?php echo number_format($upcomingAmount, 2, ',', '.'); ?> &#8378;</div>
                </div>
                <span class="ico-box"><span class="material-icons">schedule</span></span>
            </div>
        </div>

        <div class="glass-card stat-card tone-indigo">
            <div class="top-row">
                <div>
                    <div class="label">En Gec Vade</div>
                    <div class="value" style="font-size: 15px;">
                        <?php echo $maxDueTs !== null ? date('d.m.Y', $maxDueTs) : '-'; ?>
                    </div>
                    <div class="sub">Ilk vade: <?php echo $minDueTs !== null ? date('d.m.Y', $minDueTs) : '-'; ?></div>
                </div>
                <span class="ico-box"><span class="material-icons">event_upcoming</span></span>
            </div>
        </div>
    </div>

    <!-- Aylik Grafik -->
    <section class="glass-card section">
        <div class="section-head">
            <h3><span class="material-icons">bar_chart</span>Aylik Vade Takvimi</h3>
        </div>
        <div class="chart-wrap">
            <div class="chart-inner">
                <canvas id="monthlyChart"></canvas>
            </div>
        </div>
    </section>

    <!-- Detayli Tablo -->
    <section class="glass-card section">
        <div class="section-head">
            <h3><span class="material-icons">list_alt</span>Detayli Cek Listesi</h3>
        </div>

        <div class="table-scroll" style="padding: 0 4px 12px;">
            <table id="detailsTable" class="gd-table">
                <thead>
                    <tr>
                        <th>Vade Tarihi</th>
                        <th class="right">Tutar</th>
                        <th>Seri No</th>
                        <th>Alim Tarihi</th>
                        <th>Verilen Cari</th>
                        <th>Bordro Aciklamasi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($details as $r):
                        $isPast = (($r['_DUE_TS'] ?? null) !== null && (int) $r['_DUE_TS'] < $todayTs);
                        $rowCls = $isPast ? 'row-past' : '';
                        $dateCls = $isPast ? 'date-past' : '';
                    ?>
                        <tr class="<?php echo $rowCls; ?>">
                            <td class="cell-num" data-order="<?php echo htmlspecialchars((string) $r['_DUE_ISO'], ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="hidden" style="display:none;"><?php echo htmlspecialchars((string) $r['_DUE_ISO'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="<?php echo $dateCls; ?>">
                                    <?php echo htmlspecialchars((string) $r['_DUE_FMT'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td class="cell-right cell-num val-strong">
                                <?php echo number_format((float) $r['AMOUNT'], 2, ',', '.'); ?> &#8378;
                            </td>
                            <td class="cell-mono">
                                <?php echo htmlspecialchars((string) $r['NEWSERINO'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td class="cell-num" data-order="<?php echo htmlspecialchars((string) $r['_SET_ISO'], ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="hidden" style="display:none;"><?php echo htmlspecialchars((string) $r['_SET_ISO'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php echo htmlspecialchars((string) $r['_SET_FMT'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars((string) $r['CARI'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars((string) $r['ACIKLAMA'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/simple-datatables@latest"></script>

<script>
// Aylik Grafik
const monthlyData = <?php echo json_encode($monthly); ?>;
const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
new Chart(monthlyCtx, {
    type: 'bar',
    data: {
        labels: Object.keys(monthlyData),
        datasets: [{
            label: 'Aylik Tutar (₺)',
            data: Object.values(monthlyData),
            backgroundColor: 'rgba(124, 58, 237, 0.8)',
            borderColor: 'rgba(124, 58, 237, 1)',
            borderWidth: 2,
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: true, position: 'top', labels: { font: { family: 'Montserrat', size: 12 } } },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': ' + context.parsed.y.toLocaleString('tr-TR', {
                            style: 'currency',
                            currency: 'TRY'
                        });
                    }
                }
            }
        },
        scales: {
            x: { grid: { display: false }, ticks: { font: { family: 'Montserrat', size: 11 } } },
            y: {
                beginAtZero: true,
                grid: { color: 'rgba(148,163,184,.15)' },
                ticks: {
                    callback: function(value) { return value.toLocaleString('tr-TR'); },
                    font: { family: 'Montserrat', size: 11 }
                }
            }
        }
    }
});

// DataTable
if (document.getElementById('detailsTable')) {
    new simpleDatatables.DataTable("#detailsTable", {
        searchable: true,
        fixedHeight: false,
        perPage: 25,
        perPageSelect: [10, 25, 50, 100],
        labels: {
            placeholder: "Ara...",
            perPage: "kayit / sayfa",
            noRows: "Kayit bulunamadi",
            info: "Toplam {rows} kayittan {start} - {end} arasi gosteriliyor"
        }
    });
}
</script>

</body>
</html>
