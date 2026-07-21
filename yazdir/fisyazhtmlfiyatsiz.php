<?php
declare(strict_types=1);
include_once(__DIR__ . "/../ayr.php");
require_once __DIR__ . '/../kontrol.php';

// 2025/2026 dönem desteği
include_once(__DIR__ . "/../donem_helper.php");

if (!isset($_GET['stokhareket'])) {
	    echo '<div class="notification msgerror">Lütfen bir fiş numarası belirtin.</div>';
	    exit;
	}

	$stokhareket = (int)$_GET['stokhareket'];
	$yazdir = isset($_GET['yazdir']) ? (string)$_GET['yazdir'] : '';
	$fisyazFiyatsizBackUrl = safeLocalReturnUrl(
	    isset($_GET['return_to']) ? (string) $_GET['return_to'] : '',
	    '../siparis/lg_essiparis.php'
	);

	// Yazdırma işlemini logla
	$stmtFisNo = $dbh->prepare("SELECT FICHENO FROM {$firmadonem}ORFICHE WHERE LOGICALREF = :stokhareket");
	$stmtFisNo->execute([':stokhareket' => $stokhareket]);
	$fisNoLog = $stmtFisNo->fetch(PDO::FETCH_ASSOC);
	if (function_exists('logYazdir') && $fisNoLog) {
	    logYazdir($stokhareket, $fisNoLog['FICHENO'], 'FIYATSIZ', $terminalkullanici, 'Fiyatsız fiş yazdırıldı');
	}

	// Fiş bilgilerini alıyoruz
	$stmtListFis = $dbh->prepare("
	  SELECT CLIENTREF, DATE_, GENEXP1, TOTALVAT, NETTOTAL, GROSSTOTAL, FICHENO, TOTALDISCOUNTS
	  FROM {$firmadonem}ORFICHE
	  WHERE LOGICALREF = :stokhareket
	");
	$stmtListFis->execute([':stokhareket' => $stokhareket]);
	$listfis = $stmtListFis->fetch(PDO::FETCH_ASSOC);
	$cariid = intcevir($listfis['CLIENTREF']);

	// Bakiye bilgisini alıyoruz
	$stmtBakiye = $dbh->prepare("
	  SELECT
	    CLCARD.CODE AS KODU,
	    CLCARD.LOGICALREF AS CARIID,
    CLCARD.DEFINITION_ AS UNVANI,
    SUM((1 - CLFLINE.SIGN) * CLFLINE.AMOUNT)
    - SUM(CLFLINE.SIGN * CLFLINE.AMOUNT) AS BAKIYE
  FROM {$firma}CLCARD CLCARD
  LEFT JOIN {$firmadonem}CLFLINE CLFLINE
    ON CLCARD.LOGICALREF = CLFLINE.CLIENTREF
    AND CLFLINE.CANCELLED = 0
	  GROUP BY
	    CLCARD.CODE, CLCARD.DEFINITION_, CLCARD.ACTIVE, CLCARD.LOGICALREF
	  HAVING
	    CLCARD.LOGICALREF = :cariid
	    AND CLCARD.ACTIVE = 0
	");
	$stmtBakiye->execute([':cariid' => $cariid]);
	$sqlbakiye = $stmtBakiye->fetch(PDO::FETCH_ASSOC);

	$grossTotal = isset($listfis['GROSSTOTAL']) ? (float)$listfis['GROSSTOTAL'] : 0.0;
	$totalDisc  = isset($listfis['TOTALDISCOUNTS']) ? (float)$listfis['TOTALDISCOUNTS'] : 0.0;
	$totalVat   = isset($listfis['TOTALVAT']) ? (float)$listfis['TOTALVAT'] : 0.0;
	$iskontoOrani = $grossTotal > 0 ? ($totalDisc / $grossTotal) * 100 : 0;
	$kdvOrani = $grossTotal > 0 ? ($totalVat / $grossTotal) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fiyatsiz Fis - <?php echo htmlspecialchars((string)($listfis['FICHENO'] ?? '')); ?></title>
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="/tm/css/tailwind.js" onerror="var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);"></script>
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
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        .top-header {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(5, 150, 105, 0.18);
            box-shadow: 0 2px 8px rgba(5, 150, 105, 0.05);
        }
        .header-inner {
            max-width: 1100px; margin: 0 auto;
            padding: 12px 22px;
            display: flex; align-items: center; gap: 12px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 34px; height: 34px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
        }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--emerald); }
        .header-divider { width: 1px; height: 22px; background: var(--border); }
        .header-title {
            font-size: 15px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 7px;
            flex: 1 1 auto;
            min-width: 0;
        }
        .header-title i { color: var(--emerald); font-size: 14px; }
        .header-title .sub {
            font-weight: 500;
            color: var(--text-2);
            font-size: 12px;
            margin-left: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .btn-print {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 14px; background: var(--emerald); color: #fff;
            border: none; border-radius: 9px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12px; font-weight: 700;
            cursor: pointer; transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25);
        }
        .btn-print:hover { background: #047857; transform: translateY(-1px); }

        main { max-width: 1100px; margin: 0 auto; padding: 20px 22px 50px; }

        .glass-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(5, 150, 105, 0.18);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            margin-bottom: 16px;
            overflow: hidden;
            will-change: transform, opacity;
        }
        .glass-card:nth-of-type(2) { animation-delay: 70ms; }
        .glass-card:nth-of-type(3) { animation-delay: 140ms; }
        .glass-card:nth-of-type(4) { animation-delay: 210ms; }
        .glass-card:nth-of-type(5) { animation-delay: 280ms; }

        .hero {
            padding: 22px 24px;
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border-bottom: 1px solid rgba(5, 150, 105, 0.22);
            display: flex; align-items: center; gap: 16px;
            flex-wrap: wrap;
        }
        .hero .hero-ico {
            width: 56px; height: 56px;
            border-radius: 14px;
            background: #fff;
            color: var(--emerald);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.18);
        }
        .hero .hero-text { flex: 1 1 auto; min-width: 0; }
        .hero .hero-text h2 {
            font-size: 19px; font-weight: 700; color: var(--text-1);
            margin: 0;
        }
        .hero .hero-text p {
            margin: 3px 0 0;
            font-size: 12.5px;
            color: var(--emerald);
            font-weight: 600;
            overflow: hidden; text-overflow: ellipsis;
        }
        .hero .hero-amount {
            margin-left: auto;
            text-align: right;
        }
        .hero .hero-amount .lbl {
            font-size: 10px;
            color: var(--emerald);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-weight: 600;
        }
        .hero .hero-amount .val {
            display: block;
            margin-top: 2px;
            font-size: 22px;
            font-weight: 800;
            color: var(--emerald);
            line-height: 1.15;
            font-variant-numeric: tabular-nums;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0;
        }
        .info-item {
            padding: 14px 18px;
            border-right: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }
        .info-item:nth-child(3n) { border-right: none; }
        .info-item.full { grid-column: 1 / -1; border-right: none; }
        .info-item .label {
            font-size: 10px;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 4px;
        }
        .info-item .label i { color: var(--text-3); font-size: 10px; }
        .info-item .val {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-1);
            word-break: break-word;
        }

        .notice-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 18px;
            background: var(--amber-soft);
            border: 1px solid rgba(217, 119, 6, 0.22);
            border-radius: 12px;
            margin-bottom: 16px;
            font-size: 12.5px;
            font-weight: 500;
            color: #92400e;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            animation-delay: 40ms;
            will-change: transform, opacity;
        }
        .notice-bar i {
            color: var(--amber);
            font-size: 14px;
            flex-shrink: 0;
        }

        .card-head {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 9px;
            background: #fafbfc;
        }
        .card-head i { color: var(--emerald); font-size: 13px; }
        .card-head span {
            font-size: 13px; font-weight: 700; color: var(--text-1);
            text-transform: uppercase; letter-spacing: 0.3px;
        }
        .card-head .count-pill {
            margin-left: auto;
            background: var(--emerald-soft);
            color: var(--emerald);
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            text-transform: none;
            letter-spacing: 0;
        }

        .table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .lines-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 720px;
        }
        .lines-table thead th {
            padding: 12px 14px;
            font-size: 10px;
            font-weight: 700;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            background: #f9fafb;
            white-space: nowrap;
        }
        .lines-table tbody td {
            padding: 12px 14px;
            font-size: 13px;
            color: var(--text-1);
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        .lines-table tbody tr:last-child td { border-bottom: none; }
        .lines-table tbody tr:hover { background: var(--emerald-soft); }
        .cell-sira {
            width: 44px;
            text-align: center;
            font-weight: 700;
            color: var(--text-3);
            font-variant-numeric: tabular-nums;
        }
        .cell-code {
            font-weight: 600;
            color: var(--emerald);
            font-family: ui-monospace, "SF Mono", Menlo, monospace;
            font-size: 12px;
            white-space: nowrap;
        }
        .cell-name { color: var(--text-1); min-width: 220px; font-weight: 500; }
        .cell-qty {
            text-align: center;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
            font-weight: 700;
        }
        .cell-unit {
            text-align: left;
            color: var(--text-2);
            font-weight: 500;
            white-space: nowrap;
        }
        .cell-koli {
            text-align: center;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
            font-weight: 600;
            color: var(--text-1);
        }

        .summary-wrap {
            padding: 18px 22px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
        }
        .summary-stat {
            background: var(--emerald-soft);
            border: 1px solid rgba(5, 150, 105, 0.18);
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .summary-stat .s-lbl {
            font-size: 10px;
            font-weight: 700;
            color: var(--emerald);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .summary-stat .s-val {
            font-size: 20px;
            font-weight: 800;
            color: var(--text-1);
            font-variant-numeric: tabular-nums;
            line-height: 1.1;
        }
        .summary-stat .s-sub {
            font-size: 11px;
            color: var(--text-2);
            font-weight: 500;
        }

        .note-card {
            padding: 16px 20px;
            background: var(--sky-soft);
            border-left: 3px solid var(--sky);
        }
        .note-card .n-lbl {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--sky);
            margin-bottom: 6px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .note-card p {
            font-size: 13px;
            color: var(--text-1);
            line-height: 1.55;
            white-space: pre-wrap;
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        @media (max-width: 767px) {
            .header-inner { padding: 10px 14px; gap: 10px; }
            .header-title { font-size: 13px; }
            .header-title .sub { display: none; }
            .btn-print { padding: 10px 14px; font-size: 12px; min-height: 44px; }

            main { padding: 14px 12px 40px; }

            .hero { padding: 16px 18px; gap: 12px; }
            .hero .hero-ico { width: 46px; height: 46px; font-size: 19px; }
            .hero .hero-text h2 { font-size: 16px; }
            .hero .hero-text p { font-size: 12px; }
            .hero .hero-amount { margin-left: 0; width: 100%; text-align: left; padding-top: 10px; border-top: 1px dashed rgba(5, 150, 105, 0.3); }
            .hero .hero-amount .val { font-size: 20px; }

            .info-grid { grid-template-columns: 1fr; }
            .info-item { border-right: none; }

            .summary-wrap { grid-template-columns: 1fr; padding: 14px 16px; }

            .notice-bar { font-size: 12px; padding: 10px 14px; }
        }

        @page { size: A4; margin: 12mm 10mm; }

        @media print {
            body {
                background: #fff;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                font-size: 11px;
            }
            .top-header, .actions, button, .btn-print { display: none !important; }

            main { max-width: none; padding: 0; margin: 0; }

            .glass-card {
                box-shadow: none;
                border: 1px solid #999;
                border-radius: 8px;
                animation: none;
                margin-bottom: 10px;
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
                background: #fff;
            }
            .notice-bar {
                animation: none;
                background: #fffbeb !important;
                border: 1px solid #d97706;
                padding: 8px 12px;
                font-size: 10px;
                margin-bottom: 10px;
            }
            .hero {
                padding: 12px 14px;
                background: #ecfdf5 !important;
            }
            .hero .hero-ico { width: 42px; height: 42px; font-size: 18px; box-shadow: none; }
            .hero .hero-text h2 { font-size: 14px; }
            .hero .hero-text p { font-size: 10px; }
            .hero .hero-amount .val { font-size: 16px; }
            .hero .hero-amount .lbl { font-size: 8px; }

            .info-grid { grid-template-columns: repeat(3, 1fr); }
            .info-item { padding: 8px 12px; }
            .info-item .label { font-size: 8px; }
            .info-item .val { font-size: 11px; }

            .card-head { padding: 8px 12px; }
            .card-head span { font-size: 10px; }
            .card-head .count-pill { font-size: 9px; padding: 2px 8px; }

            .table-wrap { overflow: visible; }
            .lines-table { min-width: 0; }
            .lines-table thead { display: table-header-group; }
            .lines-table tbody tr { page-break-inside: avoid; page-break-after: auto; }
            .lines-table thead th {
                font-size: 9px;
                padding: 5px 6px;
                background: #f0f0f0 !important;
                color: #111 !important;
            }
            .lines-table tbody td {
                font-size: 10px;
                padding: 5px 6px;
            }

            .summary-wrap {
                padding: 8px 12px;
                page-break-inside: avoid;
                grid-template-columns: repeat(3, 1fr);
            }
            .summary-stat {
                padding: 8px 10px;
                background: #ecfdf5 !important;
                border: 1px solid #059669;
            }
            .summary-stat .s-lbl { font-size: 8px; }
            .summary-stat .s-val { font-size: 13px; }
            .summary-stat .s-sub { font-size: 9px; }

            .note-card { padding: 10px 14px; background: #eff6ff !important; }
            .note-card p { font-size: 10px; }
        }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="header-inner">
            <a href="<?php echo htmlspecialchars($fisyazFiyatsizBackUrl, ENT_QUOTES, 'UTF-8'); ?>" class="header-back" title="Geri Don">
                <i class="fa fa-arrow-left"></i>
            </a>
            <div class="header-divider"></div>
            <span class="header-title">
                <i class="fa-solid fa-clipboard-list"></i>
                Fiyatsiz Fis
                <span class="sub">&middot; <?php echo htmlspecialchars((string)($listfis['FICHENO'] ?? '')); ?></span>
            </span>
            <button onclick="window.print()" class="btn-print" type="button">
                <i class="fa-solid fa-print"></i> PDF Kaydet
            </button>
        </div>
    </header>

    <main>

        <div class="notice-bar">
            <i class="fa-solid fa-circle-info"></i>
            <span>Bu belgede fiyat bilgisi bulunmamaktadir &mdash; sevk / hazirlik listesi olarak kullanilir.</span>
        </div>

        <section class="glass-card">
            <div class="hero">
                <span class="hero-ico"><i class="fa-solid fa-boxes-packing"></i></span>
                <div class="hero-text">
                    <h2>Ceki Listesi</h2>
                    <p><?php echo htmlspecialchars(trcevir($sqlbakiye['UNVANI'] ?? '')); ?></p>
                </div>
                <div class="hero-amount">
                    <span class="lbl">Fis No</span>
                    <span class="val"><?php echo htmlspecialchars((string)($listfis['FICHENO'] ?? '-')); ?></span>
                </div>
            </div>

            <div class="info-grid">
                <div class="info-item">
                    <div class="label"><i class="fa-regular fa-calendar"></i>Tarih</div>
                    <div class="val"><?php echo tarihcevir($listfis['DATE_']); ?></div>
                </div>
                <div class="info-item">
                    <div class="label"><i class="fa-solid fa-hashtag"></i>Cari Kodu</div>
                    <div class="val"><?php echo htmlspecialchars((string)($sqlbakiye['KODU'] ?? '-')); ?></div>
                </div>
                <div class="info-item">
                    <div class="label"><i class="fa-solid fa-file-lines"></i>Belge Tipi</div>
                    <div class="val">Siparis Fisi</div>
                </div>
                <div class="info-item full">
                    <div class="label"><i class="fa-solid fa-user"></i>Firma</div>
                    <div class="val"><?php echo htmlspecialchars(trcevir($sqlbakiye['UNVANI'] ?? '')); ?></div>
                </div>
            </div>
        </section>

        <section class="glass-card">
            <div class="card-head">
                <i class="fa-solid fa-list-check"></i>
                <span>Urun Satirlari</span>
                <?php
                  // Satir sayisini onden hesapla (count query)
                  $stmtCount = $dbh->prepare("SELECT COUNT(*) AS C FROM {$firmadonem}ORFLINE WHERE ORDFICHEREF = :r AND LINETYPE = 0");
                  $stmtCount->execute([':r' => $stokhareket]);
                  $cntRow = $stmtCount->fetch(PDO::FETCH_ASSOC);
                  $satirSayisi = (int)($cntRow['C'] ?? 0);
                ?>
                <span class="count-pill"><?php echo $satirSayisi; ?> kalem</span>
            </div>
            <div class="table-wrap">
                <table class="lines-table">
                <thead>
                    <tr>
                        <th class="cell-sira">No</th>
                        <th>Urun Kodu</th>
                        <th>Urun Adi</th>
                        <th class="cell-qty">Miktar</th>
                        <th>Birim</th>
                        <th class="cell-koli">Koli</th>
                        <th>Ambalaj</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $totalKoli = 0;
                    $totalAmount = 0.0;

	                    $stmtList = $dbh->prepare("
	            SELECT
	              ORFLINE.LINENO_,
	              ORFLINE.AMOUNT,
              (TOTAL-DISTDISC)/(AMOUNT*(UINFO2/UINFO1)) AS FIYAT,
              ORFLINE.PRICE,
              ORFLINE.TOTAL,
              ORFLINE.VATAMNT,
              ITEMS.CODE AS SKODU,
              ITEMS.NAME AS SADI,
              UNITSETL.CODE AS BIRIM,
              ui.koli_ici
            FROM {$firmadonem}ORFLINE AS ORFLINE
            LEFT JOIN {$firma}ITEMS     AS ITEMS     ON ORFLINE.STOCKREF = ITEMS.LOGICALREF
            LEFT JOIN {$firma}UNITSETL  AS UNITSETL  ON ORFLINE.UOMREF   = UNITSETL.LOGICALREF
            LEFT JOIN (
              SELECT IA.ITEMREF, MAX(IA.CONVFACT2) AS koli_ici
              FROM {$firma}ITMUNITA IA
              INNER JOIN {$firma}ITEMS IT ON IA.ITEMREF = IT.LOGICALREF
              INNER JOIN {$firma}UNITSETL UL ON IA.UNITLINEREF = UL.LOGICALREF
              WHERE UL.UNITSETREF = IT.UNITSETREF
              GROUP BY IA.ITEMREF
            ) AS ui ON ui.ITEMREF = ITEMS.LOGICALREF
	            WHERE ORFLINE.ORDFICHEREF = :stokhareket AND ORFLINE.LINETYPE=0
	          ");
	                    $stmtList->execute([':stokhareket' => $stokhareket]);
	                    $list = $stmtList;

                    while ($row = $list->fetch(PDO::FETCH_ASSOC)) {
                        $koli_ici = $row['koli_ici'] ?: 1;
                        $koli_say = (int)ceil(((float)$row['AMOUNT']) / (float)$koli_ici);
                        $totalKoli += $koli_say;
                        $totalAmount += (float)$row['AMOUNT'];

                        echo '<tr>';
                        echo '<td class="cell-sira">' . htmlspecialchars((string) $row['LINENO_']) . '</td>';
                        echo '<td class="cell-code">' . htmlspecialchars((string) $row['SKODU']) . '</td>';
                        echo '<td class="cell-name">' . htmlspecialchars((string) $row['SADI']) . '</td>';
                        echo '<td class="cell-qty">' . htmlspecialchars((string) $row['AMOUNT']) . '</td>';
                        echo '<td class="cell-unit">' . htmlspecialchars((string) $row['BIRIM']) . '</td>';
                        echo '<td class="cell-koli">' . $koli_say . '</td>';
                        echo '<td class="cell-unit">Koli</td>';
                        echo '</tr>';
                    }
                    ?>
                </tbody>
                </table>
            </div>
        </section>

        <section class="glass-card">
            <div class="card-head">
                <i class="fa-solid fa-chart-simple"></i>
                <span>Ozet</span>
            </div>
            <div class="summary-wrap">
                <div class="summary-stat">
                    <span class="s-lbl"><i class="fa-solid fa-layer-group"></i>Toplam Kalem</span>
                    <span class="s-val"><?php echo $satirSayisi; ?></span>
                    <span class="s-sub">farkli urun</span>
                </div>
                <div class="summary-stat">
                    <span class="s-lbl"><i class="fa-solid fa-cubes"></i>Toplam Miktar</span>
                    <span class="s-val"><?php echo rtrim(rtrim(number_format($totalAmount, 2, ',', '.'), '0'), ','); ?></span>
                    <span class="s-sub">adet / birim</span>
                </div>
                <div class="summary-stat">
                    <span class="s-lbl"><i class="fa-solid fa-box"></i>Toplam Koli</span>
                    <span class="s-val"><?php echo $totalKoli; ?></span>
                    <span class="s-sub">koli adedi</span>
                </div>
            </div>
        </section>

        <?php if (!empty($listfis['GENEXP1'])): ?>
        <section class="glass-card">
            <div class="note-card">
                <div class="n-lbl"><i class="fa-regular fa-note-sticky"></i>Not</div>
                <p><?php echo htmlspecialchars(trcevir($listfis['GENEXP1'])); ?></p>
            </div>
        </section>
        <?php endif; ?>

    </main>

    <script>
        // requestAnimationFrame reflow fix (iOS/Safari cardIn glitch)
        requestAnimationFrame(function () {
            document.querySelectorAll('.glass-card, .notice-bar').forEach(function (el) {
                void el.offsetHeight;
            });
        });

        <?php if ($yazdir !== ''): ?>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 350);
        });
        <?php endif; ?>
    </script>
</body>
</html>
