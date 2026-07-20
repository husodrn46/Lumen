<?php
declare(strict_types=1);

// bekleyen_siparis.php — Uretim plani icin stok ve bekleyen siparis dengesi

// Gerekli yapilandirma ve loglama dosyalarini yukle.
include_once __DIR__ . "/ayr.php";
include_once __DIR__ . "/log_ip.php";

// Guvenli oturum ve yetki kontrolunu zorunlu kil.
require_once __DIR__ . '/kontrol.php';

// YETKI KONTROLU: M8 (Bekleyen Urunler) yetkisi kontrolu
if (m_p_yetki($terminalkullanici, 'M8') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// Veritabani sorgusu. Bu sorgu kullanici girdisi almadigi icin guvenlidir.
$sql = "
SELECT
    b.[URUN KODU] AS UrunKodu, b.[URUN ADI] AS UrunAdi, b.[URUN ID] AS UrunID,
    b.[BIRIM] AS Birim, b.[KOLI_ICI] AS KoliIci, b.MIKTAR AS StokMiktar,
    COALESCE(f.BeklenenMiktar, 0) AS BekleyenMiktar,
    FLOOR(b.MIKTAR / NULLIF(b.[KOLI_ICI], 0)) AS StokKoli,
    CEILING(COALESCE(f.BeklenenMiktar, 0) / NULLIF(b.[KOLI_ICI], 0)) AS BekleyenKoli,
    CASE
        WHEN CEILING(COALESCE(f.BeklenenMiktar, 0) / NULLIF(b.[KOLI_ICI], 0)) > FLOOR(b.MIKTAR / NULLIF(b.[KOLI_ICI], 0))
        THEN CEILING(COALESCE(f.BeklenenMiktar, 0) / NULLIF(b.[KOLI_ICI], 0)) - FLOOR(b.MIKTAR / NULLIF(b.[KOLI_ICI], 0))
        ELSE 0
    END AS UretKoli
FROM (
    SELECT TOP 1000
        URUN.CODE AS [URUN KODU], URUN.NAME AS [URUN ADI], URUN.LOGICALREF AS [URUN ID],
        BIRIM.CODE AS [BIRIM],
        ISNULL(AMBARM.MIKTAR, 0) AS MIKTAR,
        ISNULL(MAX(ICBIRIM.CONVFACT2), 1) AS [KOLI_ICI]
    FROM {oj {$firma}ITEMS URUN
        LEFT JOIN (
            SELECT SUM(ONHAND) AS MIKTAR, STOCKREF FROM {$firmadonemx}STINVTOT WHERE INVENNO = -1 GROUP BY STOCKREF
        ) AMBARM ON URUN.LOGICALREF = AMBARM.STOCKREF
        LEFT JOIN {$firma}UNITSETL BIRIM ON URUN.UNITSETREF = BIRIM.UNITSETREF
        LEFT JOIN {$firma}ITMUNITA ICBIRIM ON URUN.LOGICALREF = ICBIRIM.ITEMREF
    }
    WHERE URUN.CARDTYPE <> '22' AND BIRIM.LINENR = 1 AND URUN.ACTIVE = 0
        AND URUN.CODE LIKE 'AKL%' AND URUN.CODE NOT LIKE 'AKL-PLS%'
    GROUP BY URUN.CODE, URUN.NAME, URUN.LOGICALREF, BIRIM.CODE, AMBARM.MIKTAR
) AS b
LEFT JOIN (
    SELECT STOCKREF, SUM(AMOUNT - SHIPPEDAMOUNT) AS BeklenenMiktar
    FROM {$firmadonem}ORFLINE
    WHERE TRCODE = 1 AND STATUS <> 2 AND CLOSED = 0 AND AMOUNT > SHIPPEDAMOUNT
    GROUP BY STOCKREF
) AS f ON f.STOCKREF = b.[URUN ID]
ORDER BY UrunKodu
";

$stmt = $dbh->prepare($sql);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$toplamSatir = count($rows);
$uretimGerekliSayisi = 0;
$toplamBekleyenKoli = 0.0;
$toplamUretimKoli = 0.0;
foreach ($rows as $row) {
    $uretimKoli = (float) ($row['UretKoli'] ?? 0);
    $toplamBekleyenKoli += (float) ($row['BekleyenKoli'] ?? 0);
    $toplamUretimKoli += $uretimKoli;
    if ($uretimKoli > 0) {
        $uretimGerekliSayisi++;
    }
}
$uretimOrani = $toplamSatir > 0 ? ($uretimGerekliSayisi / $toplamSatir) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bekleyen Siparisler</title>
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (file_exists(__DIR__ . '/pwa-header.php')) { include_once __DIR__ . '/pwa-header.php'; } ?>
    <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/2.0.8/css/dataTables.tailwindcss.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/3.0.2/css/responsive.dataTables.min.css">
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
        * { box-sizing: border-box; margin: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
        }

        /* ── Sticky header ── */
        .top-header {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(217, 119, 6, 0.18);
            box-shadow: 0 2px 8px rgba(217, 119, 6, 0.04);
        }
        .header-inner {
            max-width: 1200px; margin: 0 auto;
            padding: 14px 24px;
            display: flex; align-items: center; gap: 14px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 10px;
            color: var(--text-2); text-decoration: none;
            transition: all 0.2s ease; flex-shrink: 0;
        }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--amber); }
        .header-divider { width: 1px; height: 24px; background: var(--border); flex-shrink: 0; }
        .header-title {
            font-size: 16px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 8px;
            flex: 1 1 auto;
        }
        .header-title i { color: var(--amber); font-size: 14px; }
        .header-title .subtitle {
            margin-left: 8px; font-size: 11px;
            color: var(--text-3); font-weight: 500;
        }

        main { max-width: 1200px; margin: 0 auto; padding: 20px 24px 60px; }

        /* ── Glass card base ── */
        .glass-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            will-change: transform, opacity;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        /* ── Stat cards ── */
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

        .stat-card .label {
            font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            color: var(--text-2);
            display: inline-flex; align-items: center; gap: 6px;
        }
        .stat-card .label i { font-size: 11px; }
        .stat-card .value {
            font-size: 22px; font-weight: 700;
            color: var(--text-1); margin-top: 6px;
        }
        .stat-card .sub {
            font-size: 11px; color: var(--text-3); margin-top: 2px;
        }

        /* Tone variants */
        .stat-card.tone-indigo { border-color: rgba(79, 70, 229, 0.22); background: linear-gradient(180deg, var(--indigo-soft), #fff); }
        .stat-card.tone-indigo .label { color: var(--indigo); }
        .stat-card.tone-amber  { border-color: rgba(217, 119, 6, 0.22);  background: linear-gradient(180deg, var(--amber-soft), #fff); }
        .stat-card.tone-amber  .label { color: var(--amber); }
        .stat-card.tone-amber  .value { color: var(--amber); }
        .stat-card.tone-sky    { border-color: rgba(2, 132, 199, 0.22);  background: linear-gradient(180deg, var(--sky-soft), #fff); }
        .stat-card.tone-sky    .label { color: var(--sky); }
        .stat-card.tone-red    { border-color: rgba(111, 16, 34, 0.22);  background: linear-gradient(180deg, var(--red-soft), #fff); }
        .stat-card.tone-red    .label { color: var(--red); }
        .stat-card.tone-red    .value { color: var(--red); }

        /* Progress bar */
        .progress-wrap {
            margin-top: 8px;
            height: 6px;
            background: rgba(217, 119, 6, 0.12);
            border-radius: 100px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--amber), #f59e0b);
            border-radius: 100px;
            transition: width 0.8s cubic-bezier(0.22, 1, 0.36, 1);
            width: 0;
        }

        /* ── Filter panel ── */
        .filter-panel {
            padding: 14px 16px;
            margin-bottom: 16px;
            display: flex; align-items: center;
            justify-content: space-between;
            gap: 12px; flex-wrap: wrap;
            animation-delay: 220ms;
        }
        .filter-left {
            display: flex; align-items: center;
            gap: 10px; flex-wrap: wrap; flex: 1 1 auto;
        }
        .filter-right {
            display: flex; align-items: center;
            gap: 8px; flex-wrap: wrap;
        }

        /* Search input */
        .search-wrap {
            position: relative;
            min-width: 220px;
        }
        .search-wrap i {
            position: absolute; left: 12px; top: 50%;
            transform: translateY(-50%);
            font-size: 12px; color: var(--text-3);
            pointer-events: none;
            transition: color 0.18s ease;
        }
        .search-wrap input {
            width: 100%;
            padding: 9px 12px 9px 34px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 12.5px; color: var(--text-1);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            outline: none;
            transition: all 0.18s ease;
        }
        .search-wrap input:focus {
            border-color: rgba(217, 119, 6, 0.5);
            box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.1);
        }
        .search-wrap input:focus ~ i,
        .search-wrap input:not(:placeholder-shown) ~ i { color: var(--amber); }

        .legend { display: flex; flex-wrap: wrap; gap: 8px; }
        .legend .chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px;
            border-radius: 100px;
            font-size: 11.5px; font-weight: 600;
        }
        .legend .chip .dot { width: 8px; height: 8px; border-radius: 50%; }
        .legend .chip.ok  { background: var(--emerald-soft); color: var(--emerald); }
        .legend .chip.ok  .dot { background: var(--emerald); }
        .legend .chip.need { background: var(--amber-soft); color: var(--amber); }
        .legend .chip.need .dot { background: var(--amber); }

        .btn-export {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 14px;
            background: var(--emerald); color: #fff;
            border: 1px solid var(--emerald); border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 12.5px; font-weight: 600;
            cursor: pointer; transition: all 0.2s ease;
            white-space: nowrap;
            min-height: 44px;
        }
        .btn-export:hover { background: #047857; border-color: #047857; }
        .btn-export i { font-size: 12px; }

        /* ── Table card ── */
        .table-card {
            border-radius: 16px;
            overflow: hidden;
            animation-delay: 260ms;
        }
        .table-scroll { overflow-x: auto; }

        .gd-table { width: 100%; border-collapse: collapse; }
        .gd-table thead { background: linear-gradient(180deg, #fff, #fffbf3); }
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
        .gd-table tbody tr.uretim-gerekli { background: var(--amber-soft); }
        .gd-table tbody tr.uretim-gerekli:hover { background: #fef3c7; }
        .gd-table tbody tr.uretim-gereksiz:hover { background: #f9fafb; }

        .cell-right { text-align: right; }
        .cell-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .cell-sub { font-size: 10.5px; color: var(--text-3); margin-top: 2px; }
        .cell-unit { font-size: 10.5px; font-weight: 600; color: var(--text-3); }

        .stok-detay {
            background: none; border: none; padding: 0;
            color: var(--amber); font-weight: 700;
            cursor: pointer; font-family: 'JetBrains Mono', 'Courier New', monospace;
            font-size: 12px;
            display: inline-flex; align-items: center; gap: 5px;
            transition: color 0.15s ease;
            min-height: 44px;
        }
        .stok-detay:hover { color: #b45309; text-decoration: underline; }
        .stok-detay i { font-size: 10px; }

        .cell-name { font-weight: 500; max-width: 340px; }

        .badge {
            display: inline-flex; align-items: center;
            padding: 4px 12px;
            border-radius: 100px;
            font-size: 12px; font-weight: 700;
            font-variant-numeric: tabular-nums;
        }
        .badge.ok   { background: var(--emerald-soft); color: var(--emerald); }
        .badge.warn { background: var(--amber-soft); color: var(--amber); border: 1px solid rgba(217, 119, 6, 0.25); }

        .val-strong { font-weight: 700; color: var(--text-1); }
        .val-red    { font-weight: 700; color: var(--red); }

        /* ── Modal ── */
        .modal-backdrop {
            background-color: rgba(31, 41, 55, 0.45);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            position: fixed; inset: 0; z-index: 50;
            padding: 24px 16px;
            overflow-y: auto;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s ease, visibility 0.25s ease;
        }
        .modal-backdrop.open {
            opacity: 1;
            visibility: visible;
        }
        .modal-box {
            max-width: 960px; margin: 0 auto;
            background: #fff;
            border-radius: 16px;
            border: 1px solid var(--border);
            box-shadow: 0 20px 50px rgba(0,0,0,0.25);
            overflow: hidden;
            transform: translate3d(0, 16px, 0) scale(0.97);
            transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
        }
        .modal-backdrop.open .modal-box {
            transform: translate3d(0, 0, 0) scale(1);
        }
        .modal-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 20px;
            background: linear-gradient(135deg, var(--amber) 0%, #b45309 100%);
            color: #fff;
        }
        .modal-head h5 { margin: 0; font-size: 15px; font-weight: 700; }
        .modal-close {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 50%;
            border: 1px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.1);
            color: #fff; cursor: pointer;
            transition: background 0.15s ease;
            font-size: 18px;
        }
        .modal-close:hover { background: rgba(255,255,255,0.22); }
        .modal-body {
            max-height: 72vh; overflow-y: auto;
            padding: 18px;
            background: var(--amber-soft);
        }
        .modal-footer {
            display: flex; justify-content: flex-end;
            padding: 12px 18px;
            background: #fff;
            border-top: 1px solid var(--border);
        }
        .modal-footer-btn {
            background: var(--amber); color: #fff;
            padding: 9px 20px; border-radius: 10px;
            border: none;
            font-weight: 700; font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 12.5px; cursor: pointer;
            transition: background 0.15s ease;
            min-height: 44px;
        }
        .modal-footer-btn:hover { background: #b45309; }

        /* ── DataTables customization ── */
        #urunTablosu_wrapper .dt-search,
        #urunTablosu_wrapper .dt-buttons { display: none !important; }
        .dt-layout-row { gap: 0.75rem; padding: 10px 14px; }
        .dataTables_paginate .paginate_button {
            padding: 0.45rem 0.7rem; margin: 0 0.125rem; border-radius: 0.5rem;
            border: 1px solid var(--border); font-size: 12px;
        }
        .dataTables_paginate .paginate_button.current {
            background-color: var(--amber); color: white; border-color: var(--amber);
        }

        /* ── Animation ── */
        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to   { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        /* ── Responsive ── */
        @media (max-width: 1024px) {
            .stat-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 767px) {
            .header-inner { padding: 12px 14px; gap: 10px; }
            .header-title { font-size: 13.5px; }
            .header-title .subtitle { display: none; }
            .header-back { width: 34px; height: 34px; border-radius: 8px; }

            main { padding: 14px 12px 40px; }

            .stat-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .stat-card { padding: 12px; }
            .stat-card .value { font-size: 18px; }

            .filter-panel {
                padding: 12px;
                flex-direction: column; align-items: stretch;
            }
            .filter-left { flex-direction: column; }
            .search-wrap { min-width: 0; width: 100%; }
            .legend { justify-content: center; }
            .filter-right { justify-content: stretch; }
            .btn-export { width: 100%; justify-content: center; font-size: 13px; }

            /* Stack the table into cards */
            .gd-table thead { display: none; }
            .gd-table, .gd-table tbody, .gd-table tr, .gd-table td { display: block; width: 100%; }
            .gd-table tbody tr {
                padding: 12px 14px;
                border-bottom: 1px solid #f3f4f6;
                display: grid;
                grid-template-columns: 1fr auto;
                gap: 6px 12px;
            }
            .gd-table tbody td { padding: 0; border-bottom: none; }
            .gd-table tbody td.cell-code-wrap  { grid-column: 1; grid-row: 1; }
            .gd-table tbody td.cell-name       { grid-column: 1 / -1; grid-row: 2; max-width: none; font-size: 12.5px; color: var(--text-2); }
            .gd-table tbody td.cell-koli-ici    { display: none; }
            .gd-table tbody td.cell-stok        { grid-column: 1; grid-row: 3; text-align: left; }
            .gd-table tbody td.cell-bekleyen    { grid-column: 2; grid-row: 3; text-align: right; }
            .gd-table tbody td.cell-uretim      { grid-column: 2; grid-row: 1; text-align: right; }

            .cell-right { text-align: left; }

            .modal-backdrop { padding: 12px 8px; }
            .modal-body { max-height: 65vh; padding: 14px; }

            input, select, textarea { font-size: 16px !important; }
        }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="header-inner">
            <a href="index.php" class="header-back" title="Ana Sayfa">
                <i class="fa fa-arrow-left"></i>
            </a>
            <div class="header-divider"></div>
            <span class="header-title">
                <i class="fa-solid fa-hourglass-half"></i>Bekleyen Siparisler
                <span class="subtitle">Uretim plani icin stok ve bekleyen siparis dengesi</span>
            </span>
        </div>
    </header>

    <main>
        <!-- Stat cards -->
        <section class="stat-grid">
            <article class="glass-card stat-card tone-indigo">
                <p class="label"><i class="fa-solid fa-boxes-stacked"></i>Toplam Urun</p>
                <p class="value"><?php echo number_format((float) $toplamSatir, 0, ',', '.'); ?></p>
            </article>
            <article class="glass-card stat-card tone-amber">
                <p class="label"><i class="fa-solid fa-industry"></i>Uretim Gereken</p>
                <p class="value"><?php echo number_format((float) $uretimGerekliSayisi, 0, ',', '.'); ?></p>
                <p class="sub">Oran: %<?php echo number_format((float) $uretimOrani, 1, ',', '.'); ?></p>
                <div class="progress-wrap">
                    <div class="progress-fill" data-width="<?php echo number_format((float) $uretimOrani, 1, '.', ''); ?>"></div>
                </div>
            </article>
            <article class="glass-card stat-card tone-sky">
                <p class="label"><i class="fa-solid fa-clock"></i>Toplam Bekleyen Koli</p>
                <p class="value"><?php echo number_format((float) $toplamBekleyenKoli, 0, ',', '.'); ?></p>
            </article>
            <article class="glass-card stat-card tone-red">
                <p class="label"><i class="fa-solid fa-triangle-exclamation"></i>Toplam Uretim Koli</p>
                <p class="value"><?php echo number_format((float) $toplamUretimKoli, 0, ',', '.'); ?></p>
            </article>
        </section>

        <!-- Filter panel -->
        <div class="glass-card filter-panel">
            <div class="filter-left">
                <div class="search-wrap">
                    <input type="text" id="searchInput" placeholder="Urun kodu veya adi ara..." autocomplete="off">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </div>
                <div class="legend">
                    <span class="chip ok"><span class="dot"></span>Yeterli stok</span>
                    <span class="chip need"><span class="dot"></span>Uretim gerekli</span>
                </div>
            </div>
            <div class="filter-right">
                <button id="btnExportExcel" type="button" class="btn-export">
                    <i class="fa-solid fa-file-excel"></i><span>Excel'e Aktar</span>
                </button>
            </div>
        </div>

        <!-- Table -->
        <div class="glass-card table-card">
            <div class="table-scroll">
                <table id="urunTablosu" class="gd-table">
                    <thead>
                        <tr>
                            <th>Urun Kodu</th>
                            <th>Urun Adi</th>
                            <th class="right">Koli Ici</th>
                            <th class="right">Stok</th>
                            <th class="right">Bekleyen</th>
                            <th class="right">Uretim</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $cls = ($r['UretKoli'] > 0) ? 'uretim-gerekli' : 'uretim-gereksiz';
                    ?>
                        <tr class="<?php echo $cls; ?>">
                            <td class="cell-code-wrap">
                                <button class="stok-detay"
                                        data-stok-id="<?php echo (int)$r['UrunID']; ?>"
                                        title="Rezervasyon detayini goruntule">
                                    <i class="fas fa-info-circle"></i><?php echo htmlspecialchars((string) $r['UrunKodu']); ?>
                                </button>
                            </td>
                            <td class="cell-name"><?php echo htmlspecialchars((string) $r['UrunAdi']); ?></td>
                            <td class="cell-koli-ici cell-right cell-num"><?php echo number_format((float)$r['KoliIci'], 0, ',', '.'); ?></td>
                            <td class="cell-stok cell-right cell-num" data-order="<?php echo (float) $r['StokKoli']; ?>">
                                <div class="val-strong"><?php echo number_format((float)$r['StokKoli'], 0, ',', '.'); ?> <span class="cell-unit">koli</span></div>
                                <div class="cell-sub"><?php echo number_format((float)$r['StokMiktar'], 0, ',', '.'); ?> adet</div>
                            </td>
                            <td class="cell-bekleyen cell-right cell-num" data-order="<?php echo (float) $r['BekleyenKoli']; ?>">
                                <div class="val-red"><?php echo number_format((float)$r['BekleyenKoli'], 0, ',', '.'); ?> <span class="cell-unit">koli</span></div>
                                <div class="cell-sub"><?php echo number_format((float)$r['BekleyenMiktar'], 0, ',', '.'); ?> adet</div>
                            </td>
                            <td class="cell-uretim cell-right" data-order="<?php echo (float)$r['UretKoli']; ?>">
                                <?php if ((float)$r['UretKoli'] > 0): ?>
                                    <span class="badge warn"><?php echo number_format((float)$r['UretKoli'], 0, ',', '.'); ?></span>
                                <?php else: ?>
                                    <span class="badge ok">0</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Rezervasyon Detay Modal -->
    <div id="rezervasyonModal" class="modal-backdrop" aria-hidden="true">
        <div class="modal-box">
            <div class="modal-head">
                <h5>Stok Rezervasyon Detayi (Koli Bazli)</h5>
                <button id="rezervasyonModalClose" type="button" class="modal-close" aria-label="Kapat">&times;</button>
            </div>
            <div class="modal-body" id="rezervasyonModalBody"></div>
            <div class="modal-footer">
                <button id="rezervasyonModalCloseBtn" type="button" class="modal-footer-btn">Kapat</button>
            </div>
        </div>
    </div>

    <script src="/tm/js/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/2.0.8/js/dataTables.js"></script>
    <script src="https://cdn.datatables.net/2.0.8/js/dataTables.tailwindcss.js"></script>
    <script src="https://cdn.datatables.net/responsive/3.0.2/js/dataTables.responsive.js"></script>
    <script src="https://cdn.datatables.net/responsive/3.0.2/js/responsive.tailwindcss.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

    <script>
    (function() {
        'use strict';

        var urunTable = null;
        var searchTimer = null;

        // Reflow fix — force layout before animation
        requestAnimationFrame(function() {
            var cards = document.querySelectorAll('.glass-card');
            for (var i = 0; i < cards.length; i++) {
                void cards[i].offsetHeight;
            }
        });

        // Progress bar animate on load
        requestAnimationFrame(function() {
            setTimeout(function() {
                var fill = document.querySelector('.progress-fill');
                if (fill) {
                    fill.style.width = Math.min(parseFloat(fill.dataset.width) || 0, 100) + '%';
                }
            }, 400);
        });

        // Excel export — tum satirlari DataTables API uzerinden al
        function exportToExcel() {
            if (!urunTable) return;
            var data = [];

            // Baslik satiri
            data.push(['Urun Kodu', 'Urun Adi', 'Koli Ici', 'Stok (Koli)', 'Stok (Adet)', 'Bekleyen (Koli)', 'Bekleyen (Adet)', 'Uretim (Koli)']);

            // {search:'none'} = filtre/arama ne olursa olsun TUM satirlari al
            urunTable.rows({ search: 'none' }).every(function() {
                var tr = this.node();
                var cells = tr.querySelectorAll('td');
                if (cells.length < 6) return;

                var urunKodu = (cells[0].textContent || '').trim();
                var urunAdi = (cells[1].textContent || '').trim();
                var koliIci = parseFloat((cells[2].textContent || '0').replace(/\./g, '').replace(',', '.')) || 0;

                var stokDiv = cells[3].querySelectorAll('div');
                var stokKoli = parseFloat(((stokDiv[0] || {}).textContent || '0').replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.')) || 0;
                var stokAdet = parseFloat(((stokDiv[1] || {}).textContent || '0').replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.')) || 0;

                var bekleyenDiv = cells[4].querySelectorAll('div');
                var bekleyenKoli = parseFloat(((bekleyenDiv[0] || {}).textContent || '0').replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.')) || 0;
                var bekleyenAdet = parseFloat(((bekleyenDiv[1] || {}).textContent || '0').replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.')) || 0;

                var uretimKoli = parseFloat((cells[5].textContent || '0').replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.')) || 0;

                data.push([urunKodu, urunAdi, koliIci, stokKoli, stokAdet, bekleyenKoli, bekleyenAdet, uretimKoli]);
            });

            var wb = XLSX.utils.book_new();
            var ws = XLSX.utils.aoa_to_sheet(data);

            // Sutun genislikleri
            ws['!cols'] = [
                { wch: 18 }, // Urun Kodu
                { wch: 40 }, // Urun Adi
                { wch: 10 }, // Koli Ici
                { wch: 12 }, // Stok Koli
                { wch: 12 }, // Stok Adet
                { wch: 14 }, // Bekleyen Koli
                { wch: 14 }, // Bekleyen Adet
                { wch: 14 }  // Uretim Koli
            ];

            XLSX.utils.book_append_sheet(wb, ws, 'Bekleyen Siparisler');

            var tarih = new Date().toISOString().slice(0, 10);
            XLSX.writeFile(wb, 'Bekleyen_Siparisler_' + tarih + '.xlsx');
        }

        $(document).ready(function() {
            urunTable = $('#urunTablosu').DataTable({
                layout: {
                    topStart: null,
                    topEnd: null,
                    bottomStart: 'info',
                    bottomEnd: 'paging'
                },
                pageLength: 25,
                order: [[5, 'desc']],
                responsive: true,
                autoWidth: false,
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/2.0.8/i18n/tr.json'
                }
            });

            // Search input — debounced for performance
            var searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    var val = this.value;
                    clearTimeout(searchTimer);
                    searchTimer = setTimeout(function() {
                        urunTable.search(val).draw();
                    }, 200);
                });
            }

            // Excel export button
            document.getElementById('btnExportExcel').addEventListener('click', exportToExcel);

            // Rezervasyon detay modal
            function openModal() {
                var modal = document.getElementById('rezervasyonModal');
                modal.classList.add('open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            }

            function closeModal() {
                var modal = document.getElementById('rezervasyonModal');
                modal.classList.remove('open');
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            }

            $(document).on('click', '.stok-detay', function(e) {
                e.preventDefault();
                var stokId = $(this).data('stok-id');
                $('#rezervasyonModalBody').html(
                    '<div style="text-align:center;padding:40px 20px;">' +
                    '<i class="fas fa-spinner fa-spin" style="font-size:36px;color:#d97706;"></i>' +
                    '<p style="margin-top:12px;color:#6b7280;font-size:13px;">Rezervasyon verileri yukleniyor...</p>' +
                    '</div>'
                );
                openModal();

                $.ajax({
                    url: 'rezervasyon_detay.php',
                    type: 'POST',
                    data: { stok_id: stokId },
                    success: function(response) {
                        $('#rezervasyonModalBody').html(response);
                    },
                    error: function() {
                        $('#rezervasyonModalBody').html(
                            '<div style="text-align:center;padding:40px 20px;color:var(--red,#6F1022);">' +
                            '<i class="fas fa-exclamation-triangle" style="font-size:36px;margin-bottom:10px;"></i>' +
                            '<p style="font-size:13px;">Rezervasyon verileri yuklenirken hata olustu.</p>' +
                            '</div>'
                        );
                    }
                });
            });

            // Modal close handlers
            $('#rezervasyonModalClose, #rezervasyonModalCloseBtn').click(closeModal);

            $('#rezervasyonModal').click(function(event) {
                if (event.target === this) closeModal();
            });

            $(document).keyup(function(e) {
                if (e.key === 'Escape') closeModal();
            });
        });
    })();
    </script>
</body>
</html>
