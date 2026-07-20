<?php
declare(strict_types=1);
include_once(__DIR__ . "/ayr.php");
require_once __DIR__ . '/kontrol.php';

// 2025/2026 dönem desteği
include_once(__DIR__ . "/donem_helper.php");

if (!isset($_GET['stokhareket'])) {
	  echo '<div style="padding:12px;text-align:center;background:#fee;color:#b00020;border:1px solid #fbb;">Lütfen bir fiş numarası belirtin.</div>';
	  exit;
	}

	$stokhareket = (int)$_GET['stokhareket'];
	$fisyazBackUrl = safeLocalReturnUrl(
	  isset($_GET['return_to']) ? (string) $_GET['return_to'] : '',
	  'lg_essiparis.php'
	);

	// Yazdırma işlemini logla
	$stmtFisNo = $dbh->prepare("SELECT FICHENO FROM {$firmadonem}ORFICHE WHERE LOGICALREF = :stokhareket");
	$stmtFisNo->execute([':stokhareket' => $stokhareket]);
	$fisNoLog = $stmtFisNo->fetch(PDO::FETCH_ASSOC);
	if (function_exists('logYazdir') && $fisNoLog) {
	    logYazdir($stokhareket, $fisNoLog['FICHENO'], 'HTML', $terminalkullanici, 'HTML olarak yazdırıldı');
	}

	// Fiş bilgileri
	$stmtListFis = $dbh->prepare("
	  SELECT CLIENTREF, DATE_, GENEXP1, TOTALVAT, NETTOTAL, GROSSTOTAL, FICHENO, TOTALDISCOUNTS
	  FROM {$firmadonem}ORFICHE
	  WHERE LOGICALREF = :stokhareket
	");
	$stmtListFis->execute([':stokhareket' => $stokhareket]);
	$listfis = $stmtListFis->fetch(PDO::FETCH_ASSOC);

	$cariid = intcevir($listfis['CLIENTREF']);

	// Cari & Bakiye
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

// Güvenli oran hesapları
$grossTotal = isset($listfis['GROSSTOTAL']) ? (float)$listfis['GROSSTOTAL'] : 0.0;
$totalDisc  = isset($listfis['TOTALDISCOUNTS']) ? (float)$listfis['TOTALDISCOUNTS'] : 0.0;
$totalVat   = isset($listfis['TOTALVAT']) ? (float)$listfis['TOTALVAT'] : 0.0;
$netTotal   = isset($listfis['NETTOTAL']) ? (float)$listfis['NETTOTAL'] : 0.0;
$bakiye     = isset($sqlbakiye['BAKIYE']) ? (float)$sqlbakiye['BAKIYE'] : 0.0;


$iskontoOrani = $grossTotal > 0 ? ($totalDisc / $grossTotal) * 100 : 0;
$netBeforeVat = $grossTotal - $totalDisc; // İskonto sonrası tutar (KDV matrahı)
$kdvOrani     = $netBeforeVat > 0 ? ($totalVat / $netBeforeVat) * 100 : 0;

// Hacim ve ağırlık hesapla
$hacimAgirlik = fis_hacim_agirlik_hesapla($stokhareket, 'siparis');

// Para formatı (TR)
function tl(float|int|string|null $v): string
{
  return '₺ ' . number_format((float)$v, 2, ',', '.');
}
function esc(mixed $v): string
{
  return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="tr">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Fiş / Sipariş Yazdırma — <?= esc($listfis['FICHENO']); ?></title>
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
      border-bottom: 1px solid rgba(111, 16, 34, 0.18);
      box-shadow: 0 2px 8px rgba(111, 16, 34, 0.04);
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
    .header-back:hover { background: rgba(0,0,0,0.04); color: var(--red); }
    .header-divider { width: 1px; height: 22px; background: var(--border); }
    .header-title {
      font-size: 15px; font-weight: 700; color: var(--text-1);
      display: inline-flex; align-items: center; gap: 7px;
      flex: 1 1 auto; min-width: 0;
    }
    .header-title i { color: var(--red); font-size: 14px; }
    .header-title small {
      font-size: 12px; color: var(--text-2); font-weight: 500;
      margin-left: 6px;
    }
    .actions {
      display: inline-flex; gap: 8px; align-items: center;
    }
    .btn-ghost {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 12px; background: #fff;
      color: var(--text-1);
      border: 1px solid var(--border); border-radius: 9px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12px; font-weight: 600;
      cursor: pointer; text-decoration: none;
      transition: all 0.2s ease;
    }
    .btn-ghost:hover { background: #f3f4f6; border-color: var(--text-3); }
    .btn-print {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 14px; background: var(--red); color: #fff;
      border: none; border-radius: 9px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12px; font-weight: 700;
      cursor: pointer; transition: all 0.2s ease;
      box-shadow: 0 4px 12px rgba(111, 16, 34, 0.22);
    }
    .btn-print:hover { background: #b91c1c; transform: translateY(-1px); }

    main { max-width: 1100px; margin: 0 auto; padding: 20px 22px 50px; }

    .glass-card {
      background: rgba(255,255,255,0.94);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      border: 1px solid rgba(111, 16, 34, 0.14);
      border-radius: 16px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.04);
      will-change: transform, opacity;
      animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
      margin-bottom: 16px;
      overflow: hidden;
    }
    .glass-card:nth-of-type(1) { animation-delay: 0ms; }
    .glass-card:nth-of-type(2) { animation-delay: 80ms; }
    .glass-card:nth-of-type(3) { animation-delay: 160ms; }
    .glass-card:nth-of-type(4) { animation-delay: 240ms; }
    .glass-card:nth-of-type(5) { animation-delay: 280ms; }

    .hero {
      padding: 12px 16px;
      background: linear-gradient(135deg, var(--red-soft), #fee2e2);
      border-bottom: 1px solid rgba(111, 16, 34, 0.22);
      display: flex; align-items: center; gap: 12px;
      flex-wrap: wrap;
    }
    .hero .hero-ico {
      width: 40px; height: 40px;
      border-radius: 10px;
      background: #fff;
      color: var(--red);
      display: inline-flex; align-items: center; justify-content: center;
      font-size: 17px;
      flex-shrink: 0;
      box-shadow: 0 3px 9px rgba(111, 16, 34, 0.18);
    }
    .hero .hero-text { flex: 1 1 auto; min-width: 0; }
    .hero .hero-text h2 {
      font-size: 15px; font-weight: 700; color: var(--text-1);
      margin: 0;
    }
    .hero .hero-text p {
      margin: 2px 0 0;
      font-size: 11.5px;
      color: var(--red);
      font-weight: 600;
      overflow: hidden; text-overflow: ellipsis;
    }
    .hero .hero-amount {
      margin-left: auto;
      text-align: right;
    }
    .hero .hero-amount .lbl {
      font-size: 9.5px;
      color: var(--red);
      text-transform: uppercase;
      letter-spacing: 0.4px;
      font-weight: 600;
    }
    .hero .hero-amount .val {
      display: block;
      margin-top: 1px;
      font-size: 18px;
      font-weight: 800;
      color: var(--red);
      line-height: 1.15;
      font-variant-numeric: tabular-nums;
    }

    .info-grid {
      display: grid;
      grid-template-columns: 0.9fr 0.9fr 1fr 1fr 1fr;
      gap: 0;
    }
    .info-item {
      padding: 8px 12px;
      border-right: 1px solid var(--border);
      border-bottom: 1px solid var(--border);
    }
    .info-item:nth-child(3n) { border-right: 1px solid var(--border); }
    .info-item:last-child { border-right: none; }
    .info-item:nth-child(5) { border-right: none; }
    .info-item.full { display: none; }
    .info-item .label {
      font-size: 9.5px;
      color: var(--text-2);
      text-transform: uppercase;
      letter-spacing: 0.4px;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 4px;
      margin-bottom: 2px;
    }
    .info-item .label i { color: var(--text-3); font-size: 9.5px; }
    .info-item .val {
      font-size: 12px;
      font-weight: 600;
      color: var(--text-1);
      word-break: break-word;
      font-variant-numeric: tabular-nums;
    }
    .info-item .val.code { font-family: ui-monospace, "SF Mono", Menlo, monospace; color: var(--red); }
    .info-item .val.amount { color: var(--text-1); }
    .info-item .val.amount.positive { color: var(--emerald); }
    .info-item .val.amount.negative { color: var(--red); }

    .card-head {
      padding: 14px 20px;
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; gap: 9px;
      background: #fafbfc;
    }
    .card-head i { color: var(--red); font-size: 13px; }
    .card-head span {
      font-size: 13px; font-weight: 700; color: var(--text-1);
      text-transform: uppercase; letter-spacing: 0.3px;
    }
    .card-head .badge {
      margin-left: auto;
      font-size: 11px;
      color: var(--text-2);
      background: #f3f4f6;
      padding: 3px 8px;
      border-radius: 999px;
      font-weight: 600;
      text-transform: none; letter-spacing: 0;
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
    .lines-table tbody tr:hover { background: #fafbfc; }
    .cell-lineno { text-align: center; color: var(--text-3); font-weight: 600; font-variant-numeric: tabular-nums; width: 36px; }
    .cell-code { font-weight: 600; color: var(--red); font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 12px; white-space: nowrap; }
    .cell-name { color: var(--text-1); min-width: 200px; }
    .cell-qty { text-align: center; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .cell-unit { color: var(--text-2); font-size: 12px; white-space: nowrap; }
    .cell-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .cell-total { text-align: right; font-weight: 700; color: var(--text-1); font-variant-numeric: tabular-nums; white-space: nowrap; }

    .summary-wrap {
      padding: 10px 14px;
      display: flex;
      justify-content: flex-end;
    }
    .summary-card {
      width: min(100%, 440px);
      margin-left: auto;
    }
    .summary-card .card-head {
      padding: 10px 14px;
    }
    .summary-box {
      width: 100%;
      max-width: 100%;
      display: flex;
      flex-direction: column;
      gap: 0;
    }
    .summary-row {
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      padding: 4px 0;
      font-size: 12px;
    }
    .summary-row .s-lbl { color: var(--text-2); font-weight: 500; }
    .summary-row .s-val { color: var(--text-1); font-weight: 600; font-variant-numeric: tabular-nums; }
    .summary-row .s-pct {
      font-size: 10.5px;
      color: var(--text-3);
      font-weight: 500;
      margin-left: 4px;
    }
    .summary-row.matrah {
      padding-top: 6px;
      margin-top: 2px;
      border-top: 1px dashed var(--border);
    }
    .summary-row.total {
      margin-top: 4px;
      padding-top: 8px;
      border-top: 2px solid var(--red);
    }
    .summary-row.total .s-lbl { color: var(--red); font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: 0.3px; }
    .summary-row.total .s-val { color: var(--red); font-weight: 800; font-size: 16px; }
    .summary-row.balance {
      margin-top: 0;
      padding-top: 6px;
      border-top: 1px dashed var(--border);
      font-size: 11.5px;
    }
    .summary-row.balance .s-lbl,
    .summary-row.balance .s-val { color: var(--text-2); font-weight: 500; }

    .hacim-bar {
      display: flex; flex-wrap: wrap; gap: 18px;
      padding: 10px 14px;
      background: var(--sky-soft);
      border: 1px solid rgba(2, 132, 199, 0.18);
      border-radius: 10px;
      margin-bottom: 10px;
      font-size: 12.5px;
    }
    .hacim-bar .hb-item { display: inline-flex; align-items: center; gap: 6px; color: var(--sky); font-weight: 600; }
    .hacim-bar .hb-item i { color: var(--sky); }
    .hacim-bar .hb-item strong { color: var(--text-1); font-weight: 700; }

    .note-card {
      padding: 14px 18px;
      background: var(--amber-soft);
      border: 1px dashed rgba(217, 119, 6, 0.4);
      border-radius: 12px;
      display: flex; gap: 12px; align-items: flex-start;
      margin-bottom: 16px;
    }
    .note-card .note-ico {
      color: var(--amber);
      font-size: 16px;
      flex-shrink: 0;
      margin-top: 1px;
    }
    .note-card .note-body {
      flex: 1 1 auto;
      font-size: 13px;
      color: var(--text-1);
      line-height: 1.5;
    }
    .note-card .note-body strong {
      display: block;
      font-size: 11px;
      color: var(--amber);
      text-transform: uppercase;
      letter-spacing: 0.4px;
      margin-bottom: 3px;
      font-weight: 700;
    }

    .doc-footer {
      margin-top: 16px;
      padding: 16px 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      flex-wrap: wrap;
      font-size: 11px;
      color: var(--text-3);
    }
    .sign-box {
      flex: 0 0 auto;
      min-width: 220px;
      text-align: center;
      padding-top: 12px;
      border-top: 1px dashed var(--border);
      font-size: 11px;
      color: var(--text-2);
      font-weight: 600;
    }

    @keyframes cardIn {
      from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
      to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
    }

    @media (max-width: 767px) {
      input, select, textarea { font-size: 16px; }

      .header-inner { padding: 10px 14px; gap: 10px; }
      .header-title { font-size: 13px; }
      .header-title small { display: none; }
      .btn-print, .btn-ghost { padding: 9px 12px; font-size: 11.5px; min-height: 44px; }

      main { padding: 14px 12px 40px; }

      .hero { padding: 16px 18px; gap: 12px; }
      .hero .hero-ico { width: 46px; height: 46px; font-size: 19px; }
      .hero .hero-text h2 { font-size: 16px; }
      .hero .hero-text p { font-size: 11.5px; }
      .hero .hero-amount { margin-left: 0; width: 100%; text-align: left; padding-top: 10px; border-top: 1px dashed rgba(111, 16, 34, 0.3); }
      .hero .hero-amount .val { font-size: 20px; }

      .info-grid { grid-template-columns: 1fr; }
      .info-item { border-right: none; }
      .info-item:nth-child(3n) { border-right: none; }

      .summary-wrap { padding: 14px 16px; }
      .summary-card { width: 100%; }
      .summary-box { max-width: 100%; }

      .doc-footer { flex-direction: column; text-align: center; }
      .sign-box { min-width: 0; width: 100%; }
    }

    @page {
      size: A4;
      margin: 12mm 10mm;
    }

    @media print {
      html, body {
        background: #fff;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        font-size: 11px;
      }
      .top-header, .actions, button { display: none !important; }
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
        background: #fef2f2 !important;
      }
      .hero .hero-ico { width: 42px; height: 42px; font-size: 18px; box-shadow: none; }
      .hero .hero-text h2 { font-size: 14px; }
      .hero .hero-text p { font-size: 10px; }
      .hero .hero-amount .val { font-size: 16px; }
      .hero .hero-amount .lbl { font-size: 8px; }

      .info-grid { grid-template-columns: 0.9fr 0.9fr 1fr 1fr 1fr; }
      .info-item { padding: 6px 8px; }
      .info-item .label { font-size: 8px; }
      .info-item .val { font-size: 10px; }

      .card-head { padding: 8px 12px; background: #f5f5f5 !important; }
      .card-head span { font-size: 10px; }

      .table-wrap { overflow: visible; }
      .lines-table { min-width: 0; page-break-inside: auto; }
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
      .lines-table tbody tr:hover { background: inherit !important; }

      .summary-card { max-width: 360px; }
      .summary-wrap { padding: 6px 10px; page-break-inside: avoid; }
      .summary-box { max-width: 100%; }
      .summary-row { font-size: 10px; padding: 4px 0; }
      .summary-row.total { padding-top: 8px; }
      .summary-row.total .s-lbl { font-size: 11px; }
      .summary-row.total .s-val { font-size: 14px; }

      .hacim-bar { background: #f8fafc !important; border-color: #ccc; font-size: 9px; padding: 6px 10px; margin-bottom: 6px; }
      .note-card { background: #fffbeb !important; border-color: #d4a574; page-break-inside: avoid; }
      .doc-footer { font-size: 9px; padding: 10px 12px; }
      .sign-box { font-size: 9px; }

      .glass-card, .summary-wrap, .note-card { page-break-inside: avoid; }
    }

    @media print and (orientation: landscape) {
      @page { size: A4 landscape; margin: 10mm; }
      .lines-table thead th { font-size: 9px; }
      .lines-table tbody td { font-size: 9px; padding: 4px 5px; }
    }
  </style>
</head>

<body>

  <header class="top-header">
    <div class="header-inner">
      <a href="<?= esc($fisyazBackUrl); ?>" class="header-back" title="Siparişlere Geri Dön">
        <i class="fa fa-arrow-left"></i>
      </a>
      <div class="header-divider"></div>
      <span class="header-title">
        <i class="fa-solid fa-file-invoice"></i>Fiş / Sipariş Yazdırma
        <small>No: <?= esc($listfis['FICHENO']); ?></small>
      </span>
      <div class="actions">
        <button onclick="window.print()" class="btn-print" type="button">
          <i class="fa-solid fa-print"></i> Yazdır / PDF Kaydet
        </button>
      </div>
    </div>
  </header>

  <main>

    <!-- Hero + Firma Bilgi -->
    <section class="glass-card">
      <div class="hero">
        <span class="hero-ico"><i class="fa-solid fa-file-invoice"></i></span>
        <div class="hero-text">
          <h2>Satış Siparişi</h2>
          <p><?= esc(trcevir($sqlbakiye['UNVANI'] ?? '-')); ?></p>
        </div>
        <div class="hero-amount">
          <span class="lbl">Net Toplam</span>
          <span class="val"><?= tl($netTotal); ?></span>
        </div>
      </div>

      <div class="info-grid">
        <div class="info-item">
          <div class="label"><i class="fa-solid fa-hashtag"></i>Fiş No</div>
          <div class="val code"><?= esc($listfis['FICHENO']); ?></div>
        </div>
        <div class="info-item">
          <div class="label"><i class="fa-regular fa-calendar"></i>Tarih</div>
          <div class="val"><?= esc(tarihcevir($listfis['DATE_'])); ?></div>
        </div>
        <div class="info-item">
          <div class="label"><i class="fa-solid fa-id-badge"></i>Cari Kodu</div>
          <div class="val code"><?= esc($sqlbakiye['KODU'] ?? '-'); ?></div>
        </div>
        <div class="info-item">
          <div class="label"><i class="fa-solid fa-wallet"></i>Mevcut Bakiye</div>
          <div class="val amount <?= $bakiye >= 0 ? 'positive' : 'negative'; ?>"><?= tl($bakiye); ?></div>
        </div>
        <div class="info-item">
          <div class="label"><i class="fa-solid fa-scale-balanced"></i>Son Bakiye</div>
          <div class="val amount"><?= tl($bakiye + $netTotal); ?></div>
        </div>
        <div class="info-item full">
          <div class="label"><i class="fa-solid fa-building"></i>Firma / Ünvan</div>
          <div class="val"><?= esc(trcevir($sqlbakiye['UNVANI'] ?? '-')); ?></div>
        </div>
      </div>
    </section>

    <!-- Sipariş Satırları -->
    <section class="glass-card">
      <div class="card-head">
        <i class="fa-solid fa-list"></i>
        <span>Sipariş Satırları</span>
      </div>
      <div class="table-wrap">
        <table class="lines-table">
          <thead>
            <tr>
              <th class="center">#</th>
              <th>Stok Kodu</th>
              <th>Stok Adı</th>
              <th class="center">Miktar</th>
              <th class="center">Koli</th>
              <th>Birim</th>
              <th class="num">Fiyat</th>
              <th class="num">Net Fiyat</th>
              <th class="num">Genel Toplam</th>
            </tr>
          </thead>
          <tbody>
          <?php
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
	            ORDER BY ORFLINE.LINENO_
	          ");
	          $stmtList->execute([':stokhareket' => $stokhareket]);
	          $list = $stmtList;

          while ($row = $list->fetch(PDO::FETCH_ASSOC)) {
            $toplam   = (float)$row['TOTAL'] + (float)$row['VATAMNT'];
            $koli_ici = (float)($row['koli_ici'] ?: 1);
            $koli_say = $koli_ici > 0 ? ceil($row['AMOUNT'] / $koli_ici) : 0;
          ?>
            <tr>
              <td class="cell-lineno"><?= esc($row['LINENO_']); ?></td>
              <td class="cell-code"><?= esc($row['SKODU']); ?></td>
              <td class="cell-name"><?= esc($row['SADI']); ?></td>
              <td class="cell-qty"><?= kusuratadet($row['AMOUNT']); ?></td>
              <td class="cell-qty"><?= $koli_say; ?></td>
              <td class="cell-unit"><?= esc($row['BIRIM']); ?></td>
              <td class="cell-num"><?= tl($row['PRICE']); ?></td>
              <td class="cell-num"><?= tl($row['FIYAT']); ?></td>
              <td class="cell-total"><?= tl($toplam); ?></td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Not -->
    <?php if (!in_array(trim((string) $listfis['GENEXP1']), ['', '0'], true)) : ?>
    <div class="note-card">
      <i class="fa-solid fa-circle-info note-ico"></i>
      <div class="note-body">
        <strong>Not</strong>
        <?= esc(trcevir($listfis['GENEXP1'])); ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Özet -->
    <section class="glass-card summary-card">
      <div class="card-head">
        <i class="fa-solid fa-calculator"></i>
        <span>Özet</span>
        <?php if ($hacimAgirlik['hacim'] > 0 || $hacimAgirlik['agirlik'] > 0): ?>
        <span class="badge">Hacim &amp; Ağırlık</span>
        <?php endif; ?>
      </div>
      <div class="summary-wrap">
        <div class="summary-box">
          <?php if ($hacimAgirlik['hacim'] > 0 || $hacimAgirlik['agirlik'] > 0): ?>
          <div class="hacim-bar">
            <?php if ($hacimAgirlik['hacim'] > 0): ?>
            <span class="hb-item"><i class="fa-solid fa-cube"></i>Hacim: <strong><?= $hacimAgirlik['hacim_str']; ?></strong></span>
            <?php endif; ?>
            <?php if ($hacimAgirlik['agirlik'] > 0): ?>
            <span class="hb-item"><i class="fa-solid fa-weight-hanging"></i>Ağırlık: <strong><?= $hacimAgirlik['agirlik_str']; ?></strong></span>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <div class="summary-row">
            <span class="s-lbl">Brüt Toplam</span>
            <span class="s-val"><?= tl($grossTotal); ?></span>
          </div>
          <div class="summary-row">
            <span class="s-lbl">İskonto<span class="s-pct">(<?= number_format((float)$iskontoOrani, 2, ',', '.'); ?>%)</span></span>
            <span class="s-val">- <?= tl($totalDisc); ?></span>
          </div>
          <div class="summary-row matrah">
            <span class="s-lbl">KDV Matrahı</span>
            <span class="s-val"><?= tl($netBeforeVat); ?></span>
          </div>
          <div class="summary-row">
            <span class="s-lbl">KDV<span class="s-pct">(<?= number_format((float)$kdvOrani, 2, ',', '.'); ?>%)</span></span>
            <span class="s-val"><?= tl($totalVat); ?></span>
          </div>
          <div class="summary-row total">
            <span class="s-lbl">Net Toplam</span>
            <span class="s-val"><?= tl($netTotal); ?></span>
          </div>
          <div class="summary-row balance">
            <span class="s-lbl">Mevcut Bakiye</span>
            <span class="s-val"><?= tl($bakiye); ?></span>
          </div>
          <div class="summary-row balance">
            <span class="s-lbl">Son Bakiye (Bakiye + Net)</span>
            <span class="s-val"><?= tl($bakiye + $netTotal); ?></span>
          </div>
        </div>
      </div>
    </section>

  </main>

  <script>
    // Animation reflow fix
    window.addEventListener('load', function() {
      document.querySelectorAll('.glass-card').forEach(function(el) { void el.offsetHeight; });
    });
  </script>

  <?php if (isset($_GET['yazdir']) && $_GET['yazdir'] === 'yazdir'): ?>
  <script>
    window.onload = function() {
      setTimeout(function() { window.print(); }, 500);
    };
  </script>
  <?php endif; ?>
</body>

</html>
