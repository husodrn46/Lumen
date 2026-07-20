<?php
declare(strict_types=1);

/**
 * Döviz Modülü - Müşteri Seçimi
 * Dövizli sipariş için müşteri ve döviz tipi seçimi
 */

include_once(__DIR__ . "/../ayr.php");
include(__DIR__ . "/../kontrol.php");
require_once __DIR__ . '/doviz_guard.php';

doviz_require_m21($terminalkullanici);

// LOGO döviz kodları: 1=USD, 20=EUR
$dovizTipleri = [
    1  => ['kod' => 'USD', 'sembol' => '$', 'ad' => 'Amerikan Doları'],
    20 => ['kod' => 'EUR', 'sembol' => '€', 'ad' => 'Euro'],
];

// Güncel kurları çek (CRTYPE = döviz kodu, LREF = sıra için ORDER BY)
$guncelKurlar = [];
try {
    // USD kurunu çek (CRTYPE=1)
    $stmtKurUSD = $dbh->prepare("SELECT TOP 1 RATES2 AS KUR FROM L_DAILYEXCHANGES WHERE CRTYPE = 1 ORDER BY LREF DESC");
    $stmtKurUSD->execute();
    $kurUSD = $stmtKurUSD->fetch(PDO::FETCH_ASSOC);
    if ($kurUSD) {
        $guncelKurlar[1] = (float)$kurUSD['KUR'];
    }

    // EUR kurunu çek (CRTYPE=20)
    $stmtKurEUR = $dbh->prepare("SELECT TOP 1 RATES2 AS KUR FROM L_DAILYEXCHANGES WHERE CRTYPE = 20 ORDER BY LREF DESC");
    $stmtKurEUR->execute();
    $kurEUR = $stmtKurEUR->fetch(PDO::FETCH_ASSOC);
    if ($kurEUR) {
        $guncelKurlar[20] = (float)$kurEUR['KUR'];
    }
} catch (Exception $e) {
    error_log("Döviz kur çekme hatası: " . $e->getMessage());
}

// Müşteri arama
$aramaMetni = '';
$musteriler = [];

if (isset($_GET['q']) && strlen(trim($_GET['q'])) >= 2) {
    $aramaMetni = trim($_GET['q']);
    $aramaParam = '%' . turkce($aramaMetni) . '%';

    $stmt = $dbh->prepare("
        SELECT TOP 20
            C.LOGICALREF AS ID,
            C.CODE AS KOD,
            C.DEFINITION_ AS ISIM,
            C.CITY AS SEHIR,
            C.TELNRS1 AS TELEFON,
            ISNULL((SELECT SUM(DEBIT - CREDIT) FROM {$firmadonemx}GNTOTCL WHERE CARDREF = C.LOGICALREF), 0) AS BAKIYE
        FROM {$firma}CLCARD C
        WHERE C.ACTIVE = 0
          AND C.CARDTYPE IN (3, 10)
          AND (
              REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                C.DEFINITION_, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')
                LIKE :q1
              OR C.CODE LIKE :q2
          )
        ORDER BY C.DEFINITION_
    ");
    $stmt->execute([':q1' => $aramaParam, ':q2' => $aramaParam]);
    $musteriler = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dövizli Sipariş - Müşteri Seç</title>
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once(__DIR__ . '/../pwa-header.php'); } ?>
    <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f9fafb;
            --text-1: #1f2937;
            --text-2: #6b7280;
            --text-3: #9ca3af;
            --border: #e5e7eb;
            --sky: var(--red,#6F1022);
            --sky-hover: #b91c1c;
            --sky-soft: #fef2f2;
            --sky-border: rgba(248, 113, 113, 0.22);
            --emerald: #059669;
            --emerald-soft: #ecfdf5;
            --amber: #d97706;
            --amber-soft: #fffbeb;
        }
        * { box-sizing: border-box; margin: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
        }

        /* HEADER */
        .top-header {
            position: sticky;
            top: 0;
            z-index: 40;
            height: 64px;
            background: rgba(255,255,255,0.88);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid var(--sky-border);
            box-shadow: 0 2px 8px rgba(2, 132, 199, 0.05);
        }
        .header-inner {
            max-width: 1200px;
            margin: 0 auto;
            height: 100%;
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 0 24px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
        }
        .header-back:hover { background: rgba(2, 132, 199, 0.08); color: var(--sky); }
        .header-divider { width: 1px; height: 24px; background: var(--border); }
        .header-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-1);
            display: inline-flex;
            flex-direction: column;
            line-height: 1.15;
        }
        .header-title .t-main {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .header-title .t-main i { color: var(--sky); font-size: 16px; }
        .header-title .t-sub {
            margin-top: 2px;
            font-size: 11.5px;
            font-weight: 500;
            color: var(--text-2);
            padding-left: 24px;
        }

        /* GLASS CARD */
        .glass-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid var(--sky-border);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
        }

        /* KUR PANEL */
        .kur-panel {
            padding: 14px;
            margin-bottom: 18px;
        }
        .kur-panel .kur-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            padding: 0 4px;
        }
        .kur-panel .kur-head h2 {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-1);
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }
        .kur-panel .kur-head h2 i { color: var(--sky); font-size: 12px; }
        .kur-panel .kur-head .live-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            color: var(--emerald);
            background: var(--emerald-soft);
            padding: 3px 9px;
            border-radius: 999px;
            font-weight: 600;
        }
        .kur-panel .kur-head .live-tag .dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--emerald);
            animation: pulse 1.6s ease-in-out infinite;
        }
        .kur-tiles {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        .kur-tile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            background: var(--sky-soft);
            border: 1px solid var(--sky-border);
            border-radius: 12px;
            transition: all 0.2s ease;
        }
        .kur-tile:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(2, 132, 199, 0.08);
        }
        .kur-tile .sembol {
            flex-shrink: 0;
            width: 40px; height: 40px;
            border-radius: 10px;
            background: #fff;
            color: var(--sky);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 700;
            border: 1px solid var(--sky-border);
        }
        .kur-tile .kur-info {
            flex: 1 1 auto;
            min-width: 0;
        }
        .kur-tile .kod-row {
            display: flex;
            align-items: baseline;
            gap: 6px;
            font-size: 13px;
            font-weight: 700;
            color: var(--text-1);
        }
        .kur-tile .kod-row .ad {
            font-size: 11px;
            font-weight: 500;
            color: var(--text-3);
        }
        .kur-tile .rate {
            margin-top: 2px;
            font-size: 15px;
            font-weight: 700;
            color: var(--sky-hover);
            font-variant-numeric: tabular-nums;
        }
        .kur-tile .rate.no-rate {
            color: var(--amber);
            font-size: 12px;
            font-weight: 600;
        }
        .kur-tile .rate .trl-lbl {
            font-size: 11px;
            font-weight: 500;
            color: var(--text-3);
            margin-left: 3px;
        }

        /* SEARCH PANEL */
        .search-panel {
            padding: 16px;
            margin-bottom: 22px;
        }
        .search-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .search-wrap {
            position: relative;
            flex: 1 1 auto;
        }
        .search-wrap i.fa-search,
        .search-wrap i.fa-magnifying-glass {
            position: absolute;
            top: 50%;
            left: 14px;
            transform: translateY(-50%);
            color: var(--text-3);
            font-size: 14px;
            pointer-events: none;
        }
        .search-input {
            width: 100%;
            padding: 12px 14px 12px 40px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14px;
            color: var(--text-1);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            transition: all 0.2s ease;
            outline: none;
        }
        .search-input:focus {
            border-color: rgba(2, 132, 199, 0.5);
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
        }
        .btn-search {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px;
            padding: 12px 22px;
            background: var(--sky);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            white-space: nowrap;
        }
        .btn-search:hover {
            background: var(--sky-hover);
            box-shadow: 0 4px 10px rgba(2, 132, 199, 0.25);
            transform: translateY(-1px);
        }
        .btn-search:active { transform: translateY(0); }

        /* DOVIZ SELECT (radio cards) */
        .doviz-select {
            padding: 14px;
            margin-bottom: 22px;
        }
        .doviz-select h2 {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-1);
            margin-bottom: 10px;
            padding: 0 4px;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }
        .doviz-select h2 i { color: var(--sky); font-size: 12px; }
        .doviz-options {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        .doviz-radio {
            position: relative;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            background: #fff;
            border: 2px solid var(--border);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            min-height: 64px;
        }
        .doviz-radio:hover {
            border-color: rgba(2, 132, 199, 0.35);
            background: #fafdff;
        }
        .doviz-radio input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .doviz-radio .sembol {
            flex-shrink: 0;
            width: 42px; height: 42px;
            border-radius: 11px;
            background: var(--sky-soft);
            color: var(--sky);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 700;
            transition: all 0.2s ease;
        }
        .doviz-radio .info .kod { font-size: 14px; font-weight: 700; color: var(--text-1); }
        .doviz-radio .info .ad { font-size: 11px; color: var(--text-2); margin-top: 2px; }
        .doviz-radio .check {
            position: absolute;
            top: 10px; right: 10px;
            width: 18px; height: 18px;
            border-radius: 50%;
            border: 2px solid var(--border);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 10px;
            transition: all 0.2s ease;
        }
        .doviz-radio.active {
            border-color: var(--sky);
            background: var(--sky-soft);
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.12);
        }
        .doviz-radio.active .sembol {
            background: var(--sky);
            color: #fff;
        }
        .doviz-radio.active .check {
            background: var(--sky);
            border-color: var(--sky);
        }

        /* GRID & CARDS */
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 16px;
        }
        .cari-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid var(--sky-border);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            will-change: transform, opacity;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            transition: box-shadow 0.25s ease, transform 0.25s ease, border-color 0.25s ease;
        }
        .cari-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(2, 132, 199, 0.12);
            border-color: rgba(2, 132, 199, 0.35);
        }
        .cari-head {
            padding: 16px 18px 14px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(180deg, #fff, #f5fbff);
        }
        .cari-title {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-1);
            line-height: 1.35;
        }
        .cari-title .ico {
            flex-shrink: 0;
            width: 30px; height: 30px;
            border-radius: 9px;
            background: var(--sky-soft);
            color: var(--sky);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
        }
        .cari-title span.name {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            word-break: break-word;
        }
        .cari-meta {
            margin-top: 8px;
            padding-left: 40px;
            font-size: 12px;
            color: var(--text-2);
            display: flex;
            flex-wrap: wrap;
            gap: 4px 14px;
        }
        .cari-meta span { display: inline-flex; align-items: center; gap: 5px; }
        .cari-meta i { color: var(--text-3); font-size: 11px; }

        .cari-body {
            padding: 14px 18px 0;
            flex: 1 1 auto;
        }
        .bakiye-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }
        .bakiye-pill.borclu { background: var(--emerald-soft); color: var(--emerald); }   /* borçlu=yeşil — site geneli kural */
        .bakiye-pill.alacakli { background: #fef2f2; color: var(--red,#6F1022); }
        .bakiye-pill.sifir { background: #f3f4f6; color: var(--text-2); }
        .bakiye-pill i { font-size: 10px; }

        .cari-footer {
            padding: 14px 18px 18px;
        }
        .btn-secim {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            min-height: 44px;
            padding: 12px 16px;
            background: var(--sky);
            color: #fff;
            border-radius: 10px;
            font-weight: 600;
            font-size: 13.5px;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .btn-secim:hover {
            background: var(--sky-hover);
            box-shadow: 0 6px 14px rgba(2, 132, 199, 0.22);
            transform: translateY(-1px);
        }
        .btn-secim:active { transform: translateY(0); }
        .btn-secim.disabled {
            background: #cbd5e1;
            cursor: not-allowed;
            box-shadow: none;
        }
        .btn-secim.disabled:hover { transform: none; box-shadow: none; }
        .btn-secim .cur-mini {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 999px;
            background: rgba(255,255,255,0.18);
            font-size: 11px;
            font-weight: 700;
            margin-left: 4px;
        }

        /* EMPTY STATE */
        .empty-state {
            grid-column: 1 / -1;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px dashed rgba(56, 189, 248, 0.35);
            border-radius: 16px;
            padding: 40px 24px;
            text-align: center;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
        }
        .empty-state .big-icon {
            width: 64px; height: 64px;
            margin: 0 auto 14px;
            border-radius: 16px;
            background: var(--sky-soft);
            color: var(--sky);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .empty-state .big-icon.muted {
            background: #f3f4f6;
            color: var(--text-3);
        }
        .empty-state .title {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-1);
        }
        .empty-state .desc {
            margin-top: 4px;
            font-size: 13px;
            color: var(--text-2);
        }

        /* ANIMATIONS */
        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.55; transform: scale(1.2); }
        }
        @keyframes rateFlash {
            0% { background: rgba(2, 132, 199, 0.18); }
            100% { background: transparent; }
        }
        .rate-flash { animation: rateFlash 0.8s ease-out; border-radius: 6px; padding: 0 4px; margin: 0 -4px; }

        /* MOBILE */
        @media (max-width: 767px) {
            .top-header { height: 56px; }
            .header-inner { padding: 0 12px; gap: 10px; }
            .header-title { font-size: 15px; }
            .header-title .t-sub { font-size: 10.5px; }
            .header-back { width: 30px; height: 30px; border-radius: 8px; min-width: 44px; min-height: 44px; width: auto; }
            .header-divider { height: 18px; }

            main { padding: 14px 12px 40px !important; }

            .kur-panel { padding: 12px; margin-bottom: 14px; }
            .kur-tiles { grid-template-columns: 1fr; gap: 8px; }
            .kur-tile { padding: 11px 12px; }
            .kur-tile .sembol { width: 38px; height: 38px; font-size: 18px; }

            .search-panel { padding: 12px; margin-bottom: 16px; }
            .search-form { gap: 8px; }
            .search-input { font-size: 16px; padding: 11px 14px 11px 38px; min-height: 44px; } /* iOS zoom engeli */
            .btn-search { padding: 11px 16px; font-size: 13px; min-height: 44px; }
            .btn-search .lbl { display: none; }

            .doviz-select { padding: 12px; margin-bottom: 16px; }
            .doviz-options { grid-template-columns: 1fr; gap: 8px; }
            .doviz-radio { min-height: 60px; }

            .card-grid { grid-template-columns: 1fr; gap: 12px; }
            .cari-card:hover { transform: none; }
            .cari-head { padding: 14px 16px 12px; }
            .cari-title { font-size: 13.5px; }
            .cari-body { padding: 12px 16px 0; }
            .cari-footer { padding: 12px 16px 16px; }
            .btn-secim:hover { transform: none; }
        }
        @media (max-width: 360px) {
            .search-input { padding-left: 36px; }
            .search-wrap i.fa-search,
            .search-wrap i.fa-magnifying-glass { left: 12px; }
        }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="header-inner">
            <a href="index.php" class="header-back" title="Geri">
                <i class="fa fa-arrow-left"></i>
            </a>
            <div class="header-divider"></div>
            <span class="header-title">
                <span class="t-main"><i class="fa-solid fa-handshake"></i>Müşteri Seçimi</span>
                <span class="t-sub">Dövizli sipariş için</span>
            </span>
        </div>
    </header>

    <main style="max-width:1200px;margin:0 auto;padding:22px 24px 60px;">

        <!-- KUR PANELI -->
        <section class="glass-card kur-panel" style="animation-delay: 0ms;">
            <div class="kur-head">
                <h2><i class="fa-solid fa-chart-line"></i> Güncel Kurlar</h2>
                <span class="live-tag"><span class="dot"></span>Canlı</span>
            </div>
            <div class="kur-tiles">
                <?php foreach ($dovizTipleri as $dvKod => $dv):
                    $kurX = $guncelKurlar[$dvKod] ?? 0;
                ?>
                <div class="kur-tile" data-doviz="<?php echo (int)$dvKod; ?>">
                    <div class="sembol"><?php echo $dv['sembol']; ?></div>
                    <div class="kur-info">
                        <div class="kod-row">
                            <span><?php echo $dv['kod']; ?></span>
                            <span class="ad"><?php echo htmlspecialchars($dv['ad']); ?></span>
                        </div>
                        <?php if ($kurX > 0): ?>
                            <div class="rate" data-kur-value="<?php echo $kurX; ?>">
                                <?php echo number_format($kurX, 4, ',', '.'); ?><span class="trl-lbl">TRY</span>
                            </div>
                        <?php else: ?>
                            <div class="rate no-rate"><i class="fa-solid fa-triangle-exclamation"></i> Kur girilecek</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- DOVIZ TIPI SECIMI -->
        <section class="glass-card doviz-select" style="animation-delay: 60ms;">
            <h2><i class="fa-solid fa-coins"></i> Döviz Tipi</h2>
            <div class="doviz-options">
                <?php $firstSelected = true; foreach ($dovizTipleri as $dvKod => $dv):
                    $isActive = $firstSelected; $firstSelected = false;
                ?>
                <label class="doviz-radio <?php echo $isActive ? 'active' : ''; ?>" data-doviz="<?php echo (int)$dvKod; ?>">
                    <input type="radio" name="doviz" value="<?php echo (int)$dvKod; ?>" <?php echo $isActive ? 'checked' : ''; ?>>
                    <span class="sembol"><?php echo $dv['sembol']; ?></span>
                    <span class="info">
                        <span class="kod"><?php echo $dv['kod']; ?></span>
                        <span class="ad"><?php echo htmlspecialchars($dv['ad']); ?></span>
                    </span>
                    <span class="check"><i class="fa-solid fa-check"></i></span>
                </label>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ARAMA PANELI -->
        <div class="glass-card search-panel" style="animation-delay: 120ms;">
            <form method="GET" action="" class="search-form" autocomplete="off">
                <div class="search-wrap">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" name="q" id="q" class="search-input"
                        placeholder="Müşteri adı veya kodu ile ara..."
                        value="<?php echo htmlspecialchars($aramaMetni); ?>" autofocus>
                </div>
                <button type="submit" class="btn-search">
                    <i class="fa-solid fa-search"></i>
                    <span class="lbl">Ara</span>
                </button>
            </form>
        </div>

        <!-- CARI KARTLARI -->
        <div class="card-grid">
            <?php if (empty($aramaMetni)): ?>
                <div class="empty-state" style="animation-delay: 160ms;">
                    <div class="big-icon muted"><i class="fa-solid fa-magnifying-glass"></i></div>
                    <div class="title">Müşteri Arayın</div>
                    <div class="desc">Dövizli sipariş açmak için önce müşteri adı veya kodu ile arama yapın.</div>
                </div>
            <?php elseif (empty($musteriler)): ?>
                <div class="empty-state" style="animation-delay: 160ms;">
                    <div class="big-icon"><i class="fa-solid fa-users-slash"></i></div>
                    <div class="title">Müşteri Bulunamadı</div>
                    <div class="desc">"<?php echo htmlspecialchars($aramaMetni); ?>" için kayıt yok.</div>
                </div>
            <?php else: ?>
                <?php $idx = 0; foreach ($musteriler as $musteri):
                    $bakiye = (float)$musteri['BAKIYE'];
                    if ($bakiye > 0)      { $bkClass = 'borclu';   $bkIco = 'fa-arrow-up';   $bkLbl = 'Borçlu'; }
                    elseif ($bakiye < 0)  { $bkClass = 'alacakli'; $bkIco = 'fa-arrow-down'; $bkLbl = 'Alacaklı'; }
                    else                  { $bkClass = 'sifir';    $bkIco = 'fa-equals';     $bkLbl = 'Sıfır'; }
                    $delay = min(180 + $idx * 40, 280);
                    $idx++;
                ?>
                <article class="cari-card" style="animation-delay: <?php echo $delay; ?>ms;"
                         data-cariid="<?php echo (int)$musteri['ID']; ?>">
                    <div class="cari-head">
                        <div class="cari-title">
                            <span class="ico"><i class="fa-regular fa-building"></i></span>
                            <span class="name" title="<?php echo htmlspecialchars($musteri['ISIM']); ?>"><?php echo htmlspecialchars($musteri['ISIM']); ?></span>
                        </div>
                        <div class="cari-meta">
                            <span><i class="fa-solid fa-hashtag"></i><?php echo htmlspecialchars($musteri['KOD']); ?></span>
                            <?php if (!empty($musteri['SEHIR'])): ?>
                            <span><i class="fa-solid fa-location-dot"></i><?php echo htmlspecialchars($musteri['SEHIR']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($musteri['TELEFON'])): ?>
                            <span><i class="fa-solid fa-phone"></i><?php echo htmlspecialchars($musteri['TELEFON']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="cari-body">
                        <span class="bakiye-pill <?php echo $bkClass; ?>">
                            <i class="fa-solid <?php echo $bkIco; ?>"></i>
                            <?php echo number_format(abs($bakiye), 2, ',', '.'); ?> &#8378;
                            <span style="font-weight:500;opacity:0.85;"><?php echo $bkLbl; ?></span>
                        </span>
                    </div>

                    <div class="cari-footer">
                        <a href="#"
                           class="btn-secim js-secim"
                           data-cariid="<?php echo (int)$musteri['ID']; ?>"
                           data-kurusd="<?php echo $guncelKurlar[1] ?? 0; ?>"
                           data-kureur="<?php echo $guncelKurlar[20] ?? 0; ?>">
                            <i class="fa-solid fa-arrow-right-long"></i>
                            <span>Sipariş Aç</span>
                            <span class="cur-mini js-cur-mini">$</span>
                        </a>
                    </div>
                </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <script>
        (function(){
            // Force reflow for animation render bug
            window.addEventListener('load', function(){
                requestAnimationFrame(function(){
                    document.querySelectorAll('.cari-card, .glass-card, .empty-state').forEach(function(el){ void el.offsetHeight; });
                });
            });

            // Döviz radio selection
            var radios = document.querySelectorAll('.doviz-radio');
            var miniLabels = document.querySelectorAll('.js-cur-mini');
            var currentDoviz = 1; // default USD

            function getSymbol(kod){ return kod === 20 ? '\u20AC' : '$'; }

            function updateMini(){
                var sym = getSymbol(currentDoviz);
                miniLabels.forEach(function(el){ el.textContent = sym; });
            }

            radios.forEach(function(label){
                label.addEventListener('click', function(){
                    var dv = parseInt(label.getAttribute('data-doviz'), 10) || 1;
                    currentDoviz = dv;
                    radios.forEach(function(l){ l.classList.remove('active'); });
                    label.classList.add('active');
                    var inp = label.querySelector('input[type=radio]');
                    if (inp) inp.checked = true;
                    updateMini();
                });
            });

            // Set initial from checked
            var checkedInp = document.querySelector('.doviz-radio input[type=radio]:checked');
            if (checkedInp) { currentDoviz = parseInt(checkedInp.value, 10) || 1; }
            updateMini();

            // Cari karti -> kur onay modal'i ac (kur teyit edilmeden fis acilmaz)
            var kmCariid = 0, kmKurUsd = 0, kmKurEur = 0, kmDoviz = currentDoviz;
            window.kurModalDovizSet = function (dv) {
                kmDoviz = dv;
                document.querySelectorAll('.km-dv').forEach(function (b) {
                    b.classList.toggle('aktif', parseInt(b.getAttribute('data-dv'), 10) === dv);
                });
                var k = (dv === 20) ? kmKurEur : kmKurUsd;
                document.getElementById('kmKur').value = (k > 0) ? String(k).replace('.', ',') : '';
                document.getElementById('kmSembol').textContent = (dv === 20) ? '€' : '$';
            };
            window.kurModalKapat = function () { document.getElementById('kmModal').classList.remove('acik'); };
            window.kurModalAcFis = function () {
                var kur = parseFloat(document.getElementById('kmKur').value.replace(',', '.'));
                if (!kur || kur <= 0) {
                    if (window.toast) { toast('Geçerli bir kur giriniz.', 'error'); } else { alert('Geçerli bir kur giriniz.'); }
                    return;
                }
                // POST ile gonder: kur modal'da onaylandi, fisekle direkt fis acar (form tekrar sormaz)
                var f = document.createElement('form');
                f.method = 'POST';
                f.action = 'fisekle.php?cariid=' + encodeURIComponent(kmCariid) + '&doviz=' + encodeURIComponent(kmDoviz) + '&kur=' + encodeURIComponent(kur);
                var inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'kur'; inp.value = String(kur);
                f.appendChild(inp);
                document.body.appendChild(f);
                f.submit();
            };
            document.querySelectorAll('.js-secim').forEach(function (a) {
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    kmCariid = a.getAttribute('data-cariid');
                    kmKurUsd = parseFloat(a.getAttribute('data-kurusd') || '0');
                    kmKurEur = parseFloat(a.getAttribute('data-kureur') || '0');
                    document.getElementById('kmModal').classList.add('acik');
                    kurModalDovizSet(currentDoviz);
                    setTimeout(function () { var i = document.getElementById('kmKur'); if (i) { i.focus(); i.select(); } }, 60);
                });
            });
        })();
    </script>

    <div id="kmModal" class="km-overlay">
        <div class="km-dialog">
            <div class="km-head">
                <h3><i class="fa-solid fa-money-bill-transfer"></i> Döviz &amp; Kur Onayı</h3>
                <button type="button" class="km-x" onclick="kurModalKapat()" aria-label="Kapat">&times;</button>
            </div>
            <div class="km-body">
                <div class="km-dv-row">
                    <button type="button" class="km-dv" data-dv="1" onclick="kurModalDovizSet(1)">$ USD</button>
                    <button type="button" class="km-dv" data-dv="20" onclick="kurModalDovizSet(20)">&euro; EUR</button>
                </div>
                <label class="km-label" for="kmKur">Kur (1 <span id="kmSembol">$</span> = ? ₺)</label>
                <input type="text" id="kmKur" class="km-input" inputmode="decimal" placeholder="0,0000" autocomplete="off">
                <p class="km-hint">Güncel kur otomatik geldi; piyasaya göre düzeltebilirsiniz.</p>
            </div>
            <div class="km-foot">
                <button type="button" class="km-btn km-iptal" onclick="kurModalKapat()">İptal</button>
                <button type="button" class="km-btn km-onay" onclick="kurModalAcFis()"><i class="fa-solid fa-check"></i> Bu Kurla Aç</button>
            </div>
        </div>
    </div>
    <style>
        .km-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.45); z-index:9999; align-items:center; justify-content:center; padding:16px; }
        .km-overlay.acik { display:flex; }
        .km-dialog { background:#fff; border-radius:16px; max-width:380px; width:100%; box-shadow:0 20px 50px rgba(0,0,0,0.25); font-family:'Avenir Next','Montserrat',sans-serif; overflow:hidden; }
        .km-head { display:flex; align-items:center; justify-content:space-between; padding:15px 18px; border-bottom:1px solid #e5e7eb; }
        .km-head h3 { font-size:15px; font-weight:700; color:#1f2937; display:flex; align-items:center; gap:8px; margin:0; }
        .km-head h3 i { color:var(--red,#6F1022); }
        .km-x { background:none; border:none; font-size:24px; line-height:1; color:#6b7280; cursor:pointer; }
        .km-body { padding:18px; }
        .km-dv-row { display:flex; gap:8px; margin-bottom:14px; }
        .km-dv { flex:1; padding:11px; border-radius:10px; border:1px solid #e5e7eb; background:#fff; font-family:inherit; font-size:14px; font-weight:600; color:#6b7280; cursor:pointer; }
        .km-dv.aktif { border-color:var(--red,#6F1022); background:#fef2f2; color:var(--red,#6F1022); }
        .km-label { font-size:12px; font-weight:600; color:#6b7280; display:block; margin-bottom:5px; }
        .km-input { width:100%; padding:12px 14px; font-family:inherit; font-size:18px; font-weight:600; color:#1f2937; border:1px solid #e5e7eb; border-radius:10px; outline:none; text-align:center; }
        .km-input:focus { border-color:var(--red,#6F1022); box-shadow:0 0 0 3px rgba(111,16,34,0.12); }
        .km-hint { font-size:11.5px; color:#9ca3af; margin:6px 0 0; }
        .km-foot { display:flex; gap:8px; padding:13px 18px; border-top:1px solid #e5e7eb; background:#fafafa; }
        .km-btn { flex:1; padding:12px; border-radius:10px; font-family:inherit; font-size:14px; font-weight:600; cursor:pointer; border:1px solid transparent; }
        .km-iptal { background:#fff; color:#6b7280; border-color:#e5e7eb; }
        .km-onay { background:var(--red,#6F1022); color:#fff; }
    </style>
    <?php include_once(__DIR__ . '/../ux_katman.php'); ?>
</body>
</html>
