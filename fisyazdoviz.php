<?php
declare(strict_types=1);

include_once(__DIR__ . "/ayr.php");
include(__DIR__ . "/kontrol.php");
$doviz = $_SESSION['doviz'];

if (!isset($_GET['stokhareket'])) {
  echo '<div style="padding:12px;text-align:center;background:#fee;color:#b00020;border:1px solid #fbb;">Please specify an order number.</div>';
  exit;
}

$stokhareket = $_GET['stokhareket'];

// Yazdırma işlemini logla
$stokhareket = (int) $stokhareket;
$stmtFisNoLog = $dbh->prepare("SELECT FICHENO FROM {$firmadonem}ORFICHE WHERE LOGICALREF = :stokhareket");
$stmtFisNoLog->execute([':stokhareket' => $stokhareket]);
$fisNoLog = $stmtFisNoLog->fetch(PDO::FETCH_ASSOC);
if (function_exists('logYazdir') && $fisNoLog) {
    logYazdir($stokhareket, $fisNoLog['FICHENO'], 'DOVIZ', $terminalkullanici, 'Dövizli fiş yazdırıldı');
}

$stmtFis = $dbh->prepare("
    SELECT CLIENTREF, DATE_, GENEXP1, TOTALVAT, NETTOTAL, GROSSTOTAL, FICHENO, TOTALDISCOUNTS
    FROM {$firmadonem}ORFICHE
    WHERE LOGICALREF = :stokhareket
");
$stmtFis->execute([':stokhareket' => $stokhareket]);
$listfis = $stmtFis->fetch(PDO::FETCH_ASSOC);

$cariid = intcevir($listfis['CLIENTREF']);
$stmtCari = $dbh->prepare("
    SELECT CLCARD.DEFINITION_ AS UNVANI
    FROM {$firma}CLCARD CLCARD
    WHERE CLCARD.LOGICALREF = :cariid
");
$stmtCari->execute([':cariid' => $cariid]);
$sqlx = $stmtCari->fetch(PDO::FETCH_ASSOC);

$dovizfiyat  = dovizkuru_bul($stokhareket); // örn: TL/1 USD veya TL/1 EUR
$dovizsembol = dovizsembol_bul($doviz);

// Para birimi & sembol (güvenli fallback)
$currencyCode = strtoupper((string)($doviz ?? 'USD')); // Örn: 'USD' veya 'EUR'
$currencySymbol = $dovizsembol ?: ($currencyCode === 'EUR' ? '€' : '$');

// Hacim ve ağırlık hesapla
$hacimAgirlik = fis_hacim_agirlik_hesapla($stokhareket, 'siparis');

// English locale formatter for currency (dot decimal, comma thousands)
if (!function_exists('fx_format_en')) {
    function fx_format_en(float|int|string|null $value): string
    {
        if ($value === '' || is_null($value)) {
            $value = 0.0;
        }
        return number_format((float)$value, 2, '.', ',');
    }
}

// Header icon by currency
$currencyIcon = 'fa-dollar-sign';
if ($currencyCode === 'EUR') {
    $currencyIcon = 'fa-euro-sign';
} elseif ($currencyCode === 'GBP') {
    $currencyIcon = 'fa-sterling-sign';
} elseif (!in_array($currencyCode, ['USD'], true)) {
    $currencyIcon = 'fa-globe';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Foreign Currency Sales Order</title>
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (file_exists(__DIR__ . '/pwa-header.php')) { include_once __DIR__ . '/pwa-header.php'; } ?>
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
            border-bottom: 1px solid rgba(2, 132, 199, 0.18);
            box-shadow: 0 2px 8px rgba(2, 132, 199, 0.04);
        }
        .header-inner {
            max-width: 1120px; margin: 0 auto;
            padding: 12px 22px;
            display: flex; align-items: center; gap: 12px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 34px; height: 34px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
        }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--sky); }
        .header-divider { width: 1px; height: 22px; background: var(--border); }
        .header-title {
            font-size: 15px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 7px;
            flex: 1 1 auto; min-width: 0;
        }
        .header-title i { color: var(--sky); font-size: 14px; }
        .header-sub {
            margin-left: 6px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-2);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .btn-print {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 14px; background: var(--sky); color: #fff;
            border: none; border-radius: 9px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12px; font-weight: 700;
            cursor: pointer; transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.22);
        }
        .btn-print:hover { background: #0369a1; transform: translateY(-1px); }

        main { max-width: 1120px; margin: 0 auto; padding: 20px 22px 50px; }

        .glass-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(2, 132, 199, 0.18);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            will-change: transform, opacity;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            margin-bottom: 16px;
            overflow: hidden;
        }
        .glass-card:nth-of-type(2) { animation-delay: 80ms; }
        .glass-card:nth-of-type(3) { animation-delay: 160ms; }
        .glass-card:nth-of-type(4) { animation-delay: 240ms; }

        .hero {
            padding: 22px 24px;
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            border-bottom: 1px solid rgba(2, 132, 199, 0.22);
            display: flex; align-items: center; gap: 16px;
            flex-wrap: wrap;
        }
        .hero .hero-ico {
            width: 56px; height: 56px;
            border-radius: 14px;
            background: #fff;
            color: var(--sky);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.18);
        }
        .hero .hero-text { flex: 1 1 auto; min-width: 0; }
        .hero .hero-text h2 {
            font-size: 19px; font-weight: 700; color: var(--text-1);
            margin: 0;
        }
        .hero .hero-text p {
            margin: 3px 0 0;
            font-size: 12.5px;
            color: var(--sky);
            font-weight: 600;
            overflow: hidden; text-overflow: ellipsis;
        }
        .hero .hero-amount {
            margin-left: auto;
            text-align: right;
        }
        .hero .hero-amount .lbl {
            font-size: 10px;
            color: var(--sky);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-weight: 600;
        }
        .hero .hero-amount .val {
            display: block;
            margin-top: 2px;
            font-size: 24px;
            font-weight: 800;
            color: var(--sky);
            line-height: 1.15;
            font-variant-numeric: tabular-nums;
        }

        .rate-badge {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 6px 12px;
            margin-top: 6px;
            background: #fff;
            border: 1px solid rgba(2, 132, 199, 0.28);
            color: var(--sky);
            border-radius: 999px;
            font-size: 12px; font-weight: 700;
            font-variant-numeric: tabular-nums;
        }
        .rate-badge i { font-size: 11px; }

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

        .card-head {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 9px;
            background: #fafbfc;
        }
        .card-head i { color: var(--sky); font-size: 13px; }
        .card-head span {
            font-size: 13px; font-weight: 700; color: var(--text-1);
            text-transform: uppercase; letter-spacing: 0.3px;
        }

        .table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .lines-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 820px;
        }
        .lines-table thead th {
            padding: 11px 12px;
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
        .lines-table thead th.num { text-align: right; }
        .lines-table thead th.center { text-align: center; }
        .lines-table tbody td {
            padding: 11px 12px;
            font-size: 12.5px;
            color: var(--text-1);
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        .lines-table tbody tr:last-child td { border-bottom: none; }
        .lines-table tbody tr:hover { background: #f9fafb; }
        .cell-idx { color: var(--text-3); font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .cell-code { font-weight: 600; color: var(--sky); font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 12px; white-space: nowrap; }
        .cell-name { color: var(--text-1); min-width: 200px; }
        .cell-qty { text-align: center; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .cell-unit { text-align: center; color: var(--text-2); font-weight: 500; white-space: nowrap; }
        .cell-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .cell-total { text-align: right; font-weight: 700; color: var(--text-1); font-variant-numeric: tabular-nums; white-space: nowrap; }

        .summary-wrap {
            padding: 18px 20px;
            display: flex;
            justify-content: flex-end;
        }
        .summary-box {
            width: 100%;
            max-width: 420px;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            padding: 8px 0;
            font-size: 13px;
        }
        .summary-row .s-lbl { color: var(--text-2); font-weight: 500; }
        .summary-row .s-val { color: var(--text-1); font-weight: 600; font-variant-numeric: tabular-nums; }
        .summary-row.total {
            margin-top: 6px;
            padding-top: 14px;
            border-top: 2px solid var(--sky);
        }
        .summary-row.total .s-lbl { color: var(--sky); font-weight: 700; font-size: 14px; text-transform: uppercase; letter-spacing: 0.3px; }
        .summary-row.total .s-val { color: var(--sky); font-weight: 800; font-size: 20px; }
        .summary-row.meta {
            margin-top: 2px;
            padding-top: 10px;
            border-top: 1px dashed var(--border);
            font-size: 12px;
        }
        .summary-row.meta .s-lbl,
        .summary-row.meta .s-val { color: var(--text-2); font-weight: 500; }

        .note-bar {
            margin: 0 20px 18px;
            padding: 12px 14px;
            background: var(--amber-soft);
            border: 1px solid rgba(217, 119, 6, 0.22);
            border-radius: 10px;
            color: #78350f;
            font-size: 12.5px;
            display: flex; align-items: flex-start; gap: 10px;
        }
        .note-bar i { color: var(--amber); font-size: 13px; margin-top: 2px; }
        .note-bar strong { color: var(--amber); font-weight: 700; margin-right: 4px; }

        .sign-area {
            padding: 22px 24px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            border-top: 1px dashed var(--border);
        }
        .sign-col .s-lbl {
            font-size: 10px;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-weight: 600;
            margin-bottom: 30px;
        }
        .sign-col .s-line {
            border-bottom: 1px solid var(--text-3);
            min-height: 18px;
        }
        .footer-note {
            padding: 14px 22px;
            font-size: 12px;
            color: var(--text-2);
            text-align: center;
            border-top: 1px solid var(--border);
            background: #fafbfc;
        }
        .footer-note strong { color: var(--text-1); }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        /* Mobile: iOS 16px fix + spacing */
        @media (max-width: 767px) {
            input, select, textarea { font-size: 16px !important; }

            .header-inner { padding: 10px 14px; gap: 10px; }
            .header-title { font-size: 13px; }
            .header-sub { display: none; }
            .btn-print { padding: 7px 12px; font-size: 11.5px; min-height: 44px; }
            .header-back { width: 44px; height: 44px; }

            main { padding: 14px 12px 40px; }

            .hero { padding: 16px 18px; gap: 12px; }
            .hero .hero-ico { width: 46px; height: 46px; font-size: 19px; }
            .hero .hero-text h2 { font-size: 16px; }
            .hero .hero-text p { font-size: 11.5px; }
            .hero .hero-amount { margin-left: 0; width: 100%; text-align: left; padding-top: 8px; border-top: 1px dashed rgba(2, 132, 199, 0.3); }
            .hero .hero-amount .val { font-size: 20px; }

            .info-grid { grid-template-columns: 1fr; }
            .info-item { border-right: none; }

            .summary-wrap { padding: 14px 16px; }
            .summary-box { max-width: 100%; }

            .sign-area { grid-template-columns: 1fr; gap: 18px; padding: 18px 18px; }
        }

        @page {
            size: A4;
            margin: 12mm 10mm;
        }

        @media print {
            body {
                background: #fff;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                font-size: 11px;
            }
            .top-header, .actions, button.btn-print { display: none !important; }
            main { max-width: none; padding: 0; margin: 0; }
            .glass-card {
                box-shadow: none;
                border: 1px solid #ccc;
                border-radius: 8px;
                animation: none;
                margin-bottom: 10px;
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
                background: #fff;
            }
            .hero {
                padding: 12px 14px;
                background: #eff6ff !important;
            }
            .hero .hero-ico { width: 42px; height: 42px; font-size: 18px; box-shadow: none; }
            .hero .hero-text h2 { font-size: 14px; }
            .hero .hero-text p { font-size: 10px; }
            .hero .hero-amount .val { font-size: 16px; }
            .hero .hero-amount .lbl { font-size: 8px; }
            .rate-badge { font-size: 10px; padding: 4px 9px; }

            .info-grid { grid-template-columns: repeat(3, 1fr); }
            .info-item { padding: 8px 12px; }
            .info-item .label { font-size: 8px; }
            .info-item .val { font-size: 11px; }

            .card-head { padding: 8px 12px; }
            .card-head span { font-size: 10px; }

            .table-wrap { overflow: visible; }
            .lines-table { min-width: 0; }
            .lines-table thead { display: table-header-group; }
            .lines-table tbody tr { page-break-inside: avoid; page-break-after: auto; }
            .lines-table thead th {
                font-size: 8px;
                padding: 5px 6px;
                background: #f0f0f0 !important;
                color: #111 !important;
            }
            .lines-table tbody td {
                font-size: 10px;
                padding: 5px 6px;
            }

            .summary-wrap { padding: 8px 12px; page-break-inside: avoid; }
            .summary-box { max-width: 300px; }
            .summary-row { font-size: 10px; padding: 4px 0; }
            .summary-row.total { padding-top: 8px; }
            .summary-row.total .s-lbl { font-size: 11px; }
            .summary-row.total .s-val { font-size: 14px; }

            .note-bar { margin: 0 12px 10px; padding: 8px 10px; font-size: 10px; }
            .sign-area { padding: 14px; gap: 16px; }
            .sign-col .s-lbl { margin-bottom: 22px; font-size: 8px; }
            .footer-note { padding: 8px 14px; font-size: 10px; }
        }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="header-inner">
            <a href="javascript:history.back()" class="header-back" title="Back">
                <i class="fa fa-arrow-left"></i>
            </a>
            <div class="header-divider"></div>
            <span class="header-title">
                <i class="fa-solid <?php echo $currencyIcon; ?>"></i>Foreign Currency Sales Order
                <span class="header-sub">#<?php echo htmlspecialchars((string) $listfis['FICHENO']); ?></span>
            </span>
            <button onclick="window.print()" class="btn-print" type="button">
                <i class="fa-solid fa-print"></i> Print / Save PDF
            </button>
        </div>
    </header>

    <main>
        <section class="glass-card">
            <div class="hero">
                <span class="hero-ico"><i class="fa-solid <?php echo $currencyIcon; ?>"></i></span>
                <div class="hero-text">
                    <h2>Foreign Currency Sales Order</h2>
                    <p><?php echo htmlspecialchars(tr($sqlx['UNVANI'] ?? '')); ?></p>
                    <span class="rate-badge">
                        <i class="fa-solid fa-arrow-right-arrow-left"></i>
                        1 <?php echo htmlspecialchars($currencyCode); ?> = <?php echo fx_format_en($dovizfiyat); ?> TL
                    </span>
                </div>
                <div class="hero-amount">
                    <span class="lbl">Net Total</span>
                    <span class="val"><?php echo htmlspecialchars($currencySymbol); ?> <?php echo fx_format_en(((float)$listfis['NETTOTAL']) / max((float)$dovizfiyat, 0.0000001)); ?></span>
                </div>
            </div>

            <div class="info-grid">
                <div class="info-item">
                    <div class="label"><i class="fa-solid fa-building"></i>Seller</div>
                    <div class="val">Lumen</div>
                </div>
                <div class="info-item">
                    <div class="label"><i class="fa-regular fa-calendar"></i>Date</div>
                    <div class="val"><?php echo tarihcevir($listfis['DATE_']); ?></div>
                </div>
                <div class="info-item">
                    <div class="label"><i class="fa-solid fa-hashtag"></i>Order No</div>
                    <div class="val"><?php echo htmlspecialchars((string) $listfis['FICHENO']); ?></div>
                </div>
                <div class="info-item full">
                    <div class="label"><i class="fa-solid fa-user"></i>Customer</div>
                    <div class="val"><?php echo htmlspecialchars(tr($sqlx['UNVANI'] ?? '')); ?></div>
                </div>
                <div class="info-item">
                    <div class="label"><i class="fa-solid fa-coins"></i>Currency</div>
                    <div class="val"><?php echo htmlspecialchars($currencySymbol); ?> <?php echo htmlspecialchars($currencyCode); ?></div>
                </div>
                <div class="info-item">
                    <div class="label"><i class="fa-solid fa-chart-line"></i>Exchange Rate</div>
                    <div class="val"><?php echo fx_format_en($dovizfiyat); ?> TL / 1 <?php echo htmlspecialchars($currencyCode); ?></div>
                </div>
                <div class="info-item">
                    <div class="label"><i class="fa-solid fa-box"></i>Volume / Weight</div>
                    <div class="val">
                        <?php
                        $vw = [];
                        if (($hacimAgirlik['hacim'] ?? 0) > 0) { $vw[] = htmlspecialchars((string)$hacimAgirlik['hacim_str']); }
                        if (($hacimAgirlik['agirlik'] ?? 0) > 0) { $vw[] = htmlspecialchars((string)$hacimAgirlik['agirlik_str']); }
                        echo $vw ? implode(' / ', $vw) : '-';
                        ?>
                    </div>
                </div>
            </div>

            <?php if (!in_array(trim((string) $listfis['GENEXP1']), ['', '0'], true)) : ?>
            <div class="note-bar" style="margin-top:14px;">
                <i class="fa-solid fa-circle-info"></i>
                <div><strong>Note:</strong><?php echo htmlspecialchars((string) $listfis['GENEXP1']); ?></div>
            </div>
            <?php endif; ?>
        </section>

        <section class="glass-card">
            <div class="card-head">
                <i class="fa-solid fa-list"></i>
                <span>Order Lines</span>
            </div>
            <div class="table-wrap">
                <table class="lines-table">
                    <thead>
                        <tr>
                            <th class="center">#</th>
                            <th>Product Code</th>
                            <th>Description</th>
                            <th class="center">Quantity</th>
                            <th class="center">Unit</th>
                            <th class="num">Unit Price (<?php echo htmlspecialchars($currencyCode); ?>)</th>
                            <th class="num">Net Price (<?php echo htmlspecialchars($currencyCode); ?>)</th>
                            <th class="num">Amount (<?php echo htmlspecialchars($currencyCode); ?>)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $stmtList = $dbh->prepare("
                      SELECT
                        O.LINENO_,
                        O.AMOUNT,
                        O.PRICE,
                        CASE WHEN O.AMOUNT<>0 THEN O.VATMATRAH/O.AMOUNT ELSE 0 END AS NETFIYAT,
                        O.TOTAL,
                        O.VATAMNT,
                        I.CODE AS SKODU,
                        I.NAME AS SADI,
                        U.CODE AS BIRIM,
                        ui.koli_ici
                      FROM {$firmadonem}ORFLINE O
                      LEFT JOIN {$firma}ITEMS    I  ON O.STOCKREF = I.LOGICALREF
                      LEFT JOIN {$firma}UNITSETL U  ON O.UOMREF   = U.LOGICALREF
                      LEFT JOIN (
                        SELECT IA.ITEMREF, MAX(IA.CONVFACT2) AS koli_ici
                        FROM {$firma}ITMUNITA IA
                        INNER JOIN {$firma}ITEMS IT ON IA.ITEMREF = IT.LOGICALREF
                        INNER JOIN {$firma}UNITSETL UL ON IA.UNITLINEREF = UL.LOGICALREF
                        WHERE UL.UNITSETREF = IT.UNITSETREF
                        GROUP BY IA.ITEMREF
                      ) ui ON ui.ITEMREF = I.LOGICALREF
                      WHERE O.ORDFICHEREF = :stokhareket AND O.LINETYPE = 0
                      ORDER BY O.LINENO_
                    ");
                    $stmtList->execute([':stokhareket' => $stokhareket]);
                    while ($row = $stmtList->fetch(PDO::FETCH_ASSOC)) {
                        $total_with_vat = $row['TOTAL'] + $row['VATAMNT'];
                    ?>
                        <tr>
                            <td class="cell-idx"><?php echo htmlspecialchars((string) $row['LINENO_']); ?></td>
                            <td class="cell-code"><?php echo htmlspecialchars((string) $row['SKODU']); ?></td>
                            <td class="cell-name"><?php echo htmlspecialchars(tr((string) $row['SADI'])); ?></td>
                            <td class="cell-qty"><?php echo kusuratadet($row['AMOUNT']); ?></td>
                            <td class="cell-unit"><?php echo htmlspecialchars((string) $row['BIRIM']); ?></td>
                            <td class="cell-num"><?php echo htmlspecialchars($currencySymbol); ?> <?php echo kusuratadet($row['PRICE'] / $dovizfiyat); ?></td>
                            <td class="cell-num"><?php echo htmlspecialchars($currencySymbol); ?> <?php echo kusuratadet($row['NETFIYAT'] / $dovizfiyat); ?></td>
                            <td class="cell-total"><?php echo htmlspecialchars($currencySymbol); ?> <?php echo kusuratadet($total_with_vat / $dovizfiyat); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="glass-card">
            <div class="card-head">
                <i class="fa-solid fa-calculator"></i>
                <span>Summary</span>
            </div>
            <div class="summary-wrap">
                <div class="summary-box">
                    <?php
                    $kur = max((float)$dovizfiyat, 0.0000001);
                    $gross  = (float)$listfis['GROSSTOTAL'] / $kur;
                    $disc   = (float)$listfis['TOTALDISCOUNTS'] / $kur;
                    $vat    = (float)$listfis['TOTALVAT'] / $kur;
                    $net    = (float)$listfis['NETTOTAL'] / $kur;
                    $taxable = $gross - $disc;
                    $discPct = $gross > 0 ? ($disc / $gross) * 100 : 0;
                    ?>
                    <div class="summary-row">
                        <span class="s-lbl">Gross Total</span>
                        <span class="s-val"><?php echo htmlspecialchars($currencySymbol); ?> <?php echo fx_format_en($gross); ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="s-lbl">Discount (<?php echo number_format($discPct, 2, '.', ','); ?>%)</span>
                        <span class="s-val">- <?php echo htmlspecialchars($currencySymbol); ?> <?php echo fx_format_en($disc); ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="s-lbl">Taxable Amount</span>
                        <span class="s-val"><?php echo htmlspecialchars($currencySymbol); ?> <?php echo fx_format_en($taxable); ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="s-lbl">VAT</span>
                        <span class="s-val"><?php echo htmlspecialchars($currencySymbol); ?> <?php echo fx_format_en($vat); ?></span>
                    </div>
                    <div class="summary-row total">
                        <span class="s-lbl">Net Total</span>
                        <span class="s-val"><?php echo htmlspecialchars($currencySymbol); ?> <?php echo fx_format_en($net); ?></span>
                    </div>
                    <?php if (($hacimAgirlik['hacim'] ?? 0) > 0 || ($hacimAgirlik['agirlik'] ?? 0) > 0): ?>
                        <?php if (($hacimAgirlik['hacim'] ?? 0) > 0): ?>
                        <div class="summary-row meta">
                            <span class="s-lbl">Volume</span>
                            <span class="s-val"><?php echo htmlspecialchars((string)$hacimAgirlik['hacim_str']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (($hacimAgirlik['agirlik'] ?? 0) > 0): ?>
                        <div class="summary-row meta" style="border-top: none; padding-top: 4px; margin-top: 0;">
                            <span class="s-lbl">Weight</span>
                            <span class="s-val"><?php echo htmlspecialchars((string)$hacimAgirlik['agirlik_str']); ?></span>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sign-area">
                <div class="sign-col">
                    <div class="s-lbl">Prepared By</div>
                    <div class="s-line"></div>
                </div>
                <div class="sign-col">
                    <div class="s-lbl">Customer Signature</div>
                    <div class="s-line"></div>
                </div>
            </div>

            <div class="footer-note">
                <strong>Lumen</strong> &mdash; Thank you for your business.
            </div>
        </section>
    </main>

    <script>
        // requestAnimationFrame reflow fix for card animations
        window.addEventListener('load', function () {
            requestAnimationFrame(function () {
                document.body.offsetHeight;
            });
        });
    </script>
</body>
</html>
