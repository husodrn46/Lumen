<?php
declare(strict_types=1);

/**
 * Döviz Modülü - Ürün Arama
 * Dövizli sipariş için ürün arama ve ekleme
 */

include_once(__DIR__ . "/../ayr.php");
include(__DIR__ . "/../kontrol.php");
require_once __DIR__ . '/doviz_guard.php';

doviz_require_m21($terminalkullanici);

// Sipariş ID'sini al
$fisId = isset($_GET['fisid']) ? (int)$_GET['fisid'] : 0;
$aramaMetni = isset($_GET['q']) ? trim($_GET['q']) : '';

if ($fisId <= 0) {
    die('<script>alert("Hata: Sipariş ID bulunamadı!"); window.location="siparisler.php";</script>');
}

// Sipariş bilgisini çek
$stmtFis = $dbh->prepare("
    SELECT F.LOGICALREF, F.FICHENO, F.TRCURR, F.TRRATE, F.CLIENTREF,
           C.DEFINITION_ AS CARI_ISIM
    FROM {$firmadonem}ORFICHE F
    LEFT JOIN {$firma}CLCARD C ON C.LOGICALREF = F.CLIENTREF
    WHERE F.LOGICALREF = :id AND F.TRCODE = 1
");
$stmtFis->execute([':id' => $fisId]);
$siparis = $stmtFis->fetch(PDO::FETCH_ASSOC);

if (!$siparis) {
    die('<script>alert("Hata: Sipariş bulunamadı!"); window.location="siparisler.php";</script>');
}

$trcurr = (int)$siparis['TRCURR'];
$trrate = (float)$siparis['TRRATE'];

// LOGO döviz kodları: 1=USD, 20=EUR
$dovizBilgileri = [
    1 => ['kod' => 'USD', 'sembol' => '$', 'icon' => 'fa-dollar-sign'],
    20 => ['kod' => 'EUR', 'sembol' => '€', 'icon' => 'fa-euro-sign'],
];
$doviz = $dovizBilgileri[$trcurr] ?? ['kod' => 'DV', 'sembol' => '?', 'icon' => 'fa-coins'];

// Ürün arama
$urunler = [];
if (strlen($aramaMetni) >= 2) {
    $aramaParam = '%' . turkce($aramaMetni) . '%';

    // Önce barkod tam eşleşme kontrolü
    $stmtBarkod = $dbh->prepare("SELECT ITEMREF FROM {$firma}UNITBARCODE WHERE BARCODE = :barkod");
    $stmtBarkod->execute([':barkod' => $aramaMetni]);
    $barkodData = $stmtBarkod->fetch(PDO::FETCH_ASSOC);

    if ($barkodData) {
        // Barkod bulundu, tek ürün getir
        $stmt = $dbh->prepare("
            SELECT
                I.LOGICALREF AS ID, I.CODE AS KOD, I.NAME AS ADI, I.VAT AS KDV,
                ISNULL((SELECT SUM(ONHAND) FROM {$firmadonemx}STINVTOT WHERE INVENNO = -1 AND STOCKREF = I.LOGICALREF), 0) AS STOK,
                ISNULL((SELECT TOP 1 PRICE FROM {$firma}PRCLIST WHERE CARDREF = I.LOGICALREF AND PTYPE = 2 AND ACTIVE = 0 ORDER BY LOGICALREF DESC), 0) AS FIYAT_TL,
                U.CODE AS BIRIM
            FROM {$firma}ITEMS I
            LEFT JOIN {$firma}UNITSETL U ON U.UNITSETREF = I.UNITSETREF AND U.MAINUNIT = 1
            WHERE I.LOGICALREF = :itemref AND I.ACTIVE = 0
        ");
        $stmt->execute([':itemref' => $barkodData['ITEMREF']]);
    } else {
        // İsim/kod araması
        $stmt = $dbh->prepare("
            SELECT TOP 30
                I.LOGICALREF AS ID, I.CODE AS KOD, I.NAME AS ADI, I.VAT AS KDV,
                ISNULL((SELECT SUM(ONHAND) FROM {$firmadonemx}STINVTOT WHERE INVENNO = -1 AND STOCKREF = I.LOGICALREF), 0) AS STOK,
                ISNULL((SELECT TOP 1 PRICE FROM {$firma}PRCLIST WHERE CARDREF = I.LOGICALREF AND PTYPE = 2 AND ACTIVE = 0 ORDER BY LOGICALREF DESC), 0) AS FIYAT_TL,
                U.CODE AS BIRIM
            FROM {$firma}ITEMS I
            LEFT JOIN {$firma}UNITSETL U ON U.UNITSETREF = I.UNITSETREF AND U.MAINUNIT = 1
            WHERE I.ACTIVE = 0 AND I.CARDTYPE <> 22
              AND (
                  REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                    I.NAME, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')
                    LIKE :q1
                  OR I.CODE LIKE :q2
              )
            ORDER BY I.NAME
        ");
        $stmt->execute([':q1' => $aramaParam, ':q2' => $aramaParam]);
    }
    $urunler = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Ürün ekleme işlemi (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stokid'])) {
    // fis.php'ye yönlendir, orası ekleyecek
    echo '<form id="addForm" method="POST" action="fis.php?id=' . $fisId . '">';
    foreach ($_POST as $key => $val) {
        echo '<input type="hidden" name="' . htmlspecialchars((string)$key) . '" value="' . htmlspecialchars((string)$val) . '">';
    }
    echo '</form>';
    echo '<script>document.getElementById("addForm").submit();</script>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ürün Ara - <?php echo htmlspecialchars((string)$siparis['FICHENO']); ?></title>
  <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once(__DIR__ . '/../pwa-header.php'); } ?>
  <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #f0f9ff;
      --text-1: #0f172a;
      --text-2: #475569;
      --text-3: #94a3b8;
      --border: #e2e8f0;
      --sky: #0ea5e9;
      --sky-soft: #e0f2fe;
      --sky-deep: #0284c7;
      --emerald: #059669;
      --emerald-soft: #ecfdf5;
      --emerald-deep: #047857;
      --amber: #d97706;
      --amber-soft: #fffbeb;
      --red: #6F1022;
      --red-soft: #fef2f2;
      --indigo: #4f46e5;
      --indigo-soft: #eef2ff;
    }
    * { box-sizing: border-box; margin: 0; }
    body {
      font-family: 'Avenir Next', 'Montserrat', sans-serif;
      background: linear-gradient(180deg, #f0f9ff 0%, #f8fafc 100%);
      background-attachment: fixed;
      color: var(--text-1);
      min-height: 100vh;
      letter-spacing: 0.1px;
    }
    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    input[type=number] { -moz-appearance: textfield; }

    /* HEADER */
    .top-header {
      position: sticky;
      top: 0;
      z-index: 40;
      height: 64px;
      background: rgba(255,255,255,0.88);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      border-bottom: 1px solid rgba(14,165,233,0.18);
      box-shadow: 0 2px 8px rgba(14,165,233,0.05);
    }
    .header-inner {
      max-width: 1100px;
      margin: 0 auto;
      height: 100%;
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 0 24px;
    }
    .header-back {
      display: inline-flex; align-items: center; justify-content: center;
      width: 40px; height: 40px; border-radius: 10px;
      color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
      background: rgba(14,165,233,0.06);
    }
    .header-back:hover { background: var(--sky-soft); color: var(--sky-deep); }
    .header-divider { width: 1px; height: 26px; background: var(--border); }
    .header-title-wrap { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
    .header-title {
      font-size: 17px;
      font-weight: 700;
      color: var(--text-1);
      display: inline-flex;
      align-items: center;
      gap: 9px;
      line-height: 1.1;
    }
    .header-title i {
      color: var(--sky);
      font-size: 18px;
      width: 28px; height: 28px;
      display: inline-flex; align-items: center; justify-content: center;
      background: var(--sky-soft);
      border-radius: 8px;
    }
    .header-sub {
      font-size: 11.5px; color: var(--text-3); font-weight: 500;
      display: inline-flex; align-items: center; gap: 8px;
      padding-left: 37px;
    }
    .header-sub .pipe { color: var(--border); }
    .header-chip {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 3px 8px; border-radius: 100px;
      background: var(--emerald-soft); color: var(--emerald-deep);
      font-size: 10.5px; font-weight: 700;
      border: 1px solid rgba(5,150,105,0.22);
    }

    /* INFO BAR */
    .info-bar {
      background: rgba(255,255,255,0.92);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      border: 1px solid rgba(14,165,233,0.18);
      border-radius: 14px;
      box-shadow: 0 4px 16px rgba(14,165,233,0.05);
      padding: 14px 16px;
      margin-bottom: 14px;
      display: flex;
      flex-wrap: wrap;
      gap: 10px 18px;
      align-items: center;
      animation: cardIn 0.45s cubic-bezier(0.22,1,0.36,1) both;
      will-change: transform, opacity;
    }
    .info-item { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .info-label {
      font-size: 10px; font-weight: 600; color: var(--text-3);
      text-transform: uppercase; letter-spacing: 0.6px;
    }
    .info-value {
      font-size: 13.5px; font-weight: 600; color: var(--text-1);
      display: inline-flex; align-items: center; gap: 6px;
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
      max-width: 360px;
    }
    .info-value.mono { font-family: 'Courier New', monospace; color: var(--sky-deep); }
    .info-divider { width: 1px; height: 30px; background: var(--border); }
    .info-doviz-chip {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 5px 11px; border-radius: 100px;
      background: var(--emerald-soft); color: var(--emerald-deep);
      font-size: 12px; font-weight: 700;
      border: 1px solid rgba(5,150,105,0.25);
    }
    .info-kur {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 5px 11px; border-radius: 100px;
      background: var(--amber-soft); color: var(--amber);
      font-size: 11.5px; font-weight: 700;
      border: 1px solid rgba(217,119,6,0.22);
    }

    /* SEARCH WRAP */
    .search-wrap {
      background: rgba(255,255,255,0.92);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      border: 1px solid rgba(14,165,233,0.18);
      border-radius: 14px;
      box-shadow: 0 4px 16px rgba(14,165,233,0.05);
      padding: 12px 14px;
      margin-bottom: 14px;
      animation: cardIn 0.5s cubic-bezier(0.22,1,0.36,1) both;
      animation-delay: 40ms;
      will-change: transform, opacity;
    }
    .search-form { display: flex; gap: 10px; align-items: stretch; }
    .search-input-wrap { position: relative; flex: 1 1 auto; min-width: 0; }
    .search-input-wrap > i.search-icon {
      position: absolute; top: 50%; left: 14px;
      transform: translateY(-50%); color: var(--sky);
      font-size: 14px; pointer-events: none;
    }
    .search-input {
      width: 100%; height: 46px;
      padding: 0 14px 0 40px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 14px; font-weight: 500;
      color: var(--text-1); background: #fff;
      border: 1px solid var(--border); border-radius: 11px;
      outline: none; transition: all 0.2s ease;
    }
    .search-input::placeholder { color: var(--text-3); font-weight: 400; }
    .search-input:focus {
      border-color: rgba(14,165,233,0.55);
      box-shadow: 0 0 0 4px rgba(14,165,233,0.12);
    }
    .search-btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 7px;
      padding: 0 22px; height: 46px;
      background: var(--sky); color: #fff;
      border: none; border-radius: 11px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 13.5px; font-weight: 600;
      cursor: pointer; transition: all 0.2s ease;
      box-shadow: 0 4px 12px rgba(14,165,233,0.25);
      white-space: nowrap;
    }
    .search-btn:hover { background: var(--sky-deep); transform: translateY(-1px); box-shadow: 0 8px 18px rgba(14,165,233,0.32); }
    .search-btn:active { transform: translateY(0); }

    /* RESULTS HEAD */
    .results-head {
      display: flex; align-items: center; gap: 10px;
      padding: 4px 2px 10px;
    }
    .results-head h3 {
      font-size: 13px; font-weight: 700; color: var(--text-2);
      display: inline-flex; align-items: center; gap: 8px;
      text-transform: uppercase; letter-spacing: 0.5px;
    }
    .results-head h3 i { color: var(--sky); font-size: 12px; }
    .results-count {
      display: inline-flex; align-items: center;
      padding: 3px 9px; border-radius: 100px;
      background: var(--sky-soft); color: var(--sky-deep);
      font-size: 11px; font-weight: 700;
      border: 1px solid rgba(14,165,233,0.22);
    }

    /* PRODUCTS GRID */
    .products-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
      gap: 12px;
    }

    .product-card {
      background: rgba(255,255,255,0.94);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      border: 1px solid rgba(14,165,233,0.18);
      border-radius: 14px;
      box-shadow: 0 4px 14px rgba(14,165,233,0.04);
      padding: 14px;
      display: flex; flex-direction: column; gap: 12px;
      animation: cardIn 0.42s cubic-bezier(0.22,1,0.36,1) both;
      will-change: transform, opacity;
      transition: box-shadow 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
    }
    .product-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 28px rgba(14,165,233,0.10);
      border-color: rgba(14,165,233,0.36);
    }

    .prod-head { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
    .prod-kod {
      font-family: 'Courier New', monospace;
      font-size: 11.5px; font-weight: 700;
      color: var(--sky-deep);
      background: var(--sky-soft);
      padding: 3px 8px; border-radius: 6px;
      align-self: flex-start;
      border: 1px solid rgba(14,165,233,0.22);
    }
    .prod-adi {
      font-size: 13.5px; font-weight: 600; color: var(--text-1);
      line-height: 1.35; word-break: break-word;
    }

    .prod-meta {
      display: flex; flex-wrap: wrap; gap: 6px;
      padding-bottom: 10px;
      border-bottom: 1px dashed var(--border);
    }
    .meta-chip {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 4px 9px; border-radius: 100px;
      font-size: 11px; font-weight: 600;
      border: 1px solid var(--border); background: #fff;
    }
    .meta-chip i { font-size: 10px; }
    .meta-chip.stok-on { color: var(--emerald-deep); background: var(--emerald-soft); border-color: rgba(5,150,105,0.22); }
    .meta-chip.stok-off { color: var(--red); background: var(--red-soft); border-color: rgba(111,16,34,0.22); }
    .meta-chip.birim { color: var(--indigo); background: var(--indigo-soft); border-color: rgba(79,70,229,0.2); }
    .meta-chip.kdv { color: var(--amber); background: var(--amber-soft); border-color: rgba(217,119,6,0.22); }

    .prod-prices {
      display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
    }
    .price-block {
      padding: 8px 10px; border-radius: 10px;
      background: #f8fafc; border: 1px solid var(--border);
    }
    .price-block.doviz { background: var(--emerald-soft); border-color: rgba(5,150,105,0.22); }
    .price-lbl {
      font-size: 9.5px; font-weight: 700; color: var(--text-3);
      text-transform: uppercase; letter-spacing: 0.5px;
      display: flex; align-items: center; gap: 4px;
    }
    .price-block.doviz .price-lbl { color: var(--emerald-deep); }
    .price-val {
      margin-top: 2px;
      font-size: 13.5px; font-weight: 700; color: var(--text-1);
      font-variant-numeric: tabular-nums;
    }
    .price-block.doviz .price-val { color: var(--emerald-deep); }

    .prod-actions {
      display: grid; grid-template-columns: 90px 1fr auto; gap: 8px; align-items: end;
    }
    .field-lbl {
      display: block;
      font-size: 9.5px; font-weight: 700; color: var(--text-3);
      text-transform: uppercase; letter-spacing: 0.5px;
      margin-bottom: 4px;
    }
    .qty-input, .fiyat-input {
      width: 100%; height: 40px;
      padding: 0 10px;
      text-align: center;
      font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 13px; font-weight: 700;
      color: var(--text-1); background: #fff;
      border: 1px solid var(--border); border-radius: 10px;
      outline: none; transition: all 0.2s ease;
    }
    .qty-input:focus, .fiyat-input:focus {
      border-color: rgba(14,165,233,0.55);
      box-shadow: 0 0 0 3px rgba(14,165,233,0.12);
    }
    .fiyat-input { color: var(--emerald-deep); }

    .add-btn {
      height: 40px; padding: 0 16px;
      background: var(--emerald); color: #fff;
      border: none; border-radius: 10px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 13px; font-weight: 700;
      cursor: pointer; transition: all 0.2s ease;
      box-shadow: 0 2px 8px rgba(5,150,105,0.22);
      display: inline-flex; align-items: center; justify-content: center; gap: 6px;
      white-space: nowrap;
    }
    .add-btn:hover { background: var(--emerald-deep); transform: translateY(-1px); box-shadow: 0 6px 14px rgba(5,150,105,0.32); }
    .add-btn:active { transform: translateY(0); }

    /* EMPTY STATE */
    .empty-state {
      background: rgba(255,255,255,0.92);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      border: 1px solid rgba(14,165,233,0.18);
      border-radius: 16px;
      padding: 52px 24px;
      text-align: center;
      animation: cardIn 0.5s cubic-bezier(0.22,1,0.36,1) both;
      will-change: transform, opacity;
    }
    .empty-icon {
      width: 88px; height: 88px; margin: 0 auto 18px;
      display: inline-flex; align-items: center; justify-content: center;
      background: var(--sky-soft); color: var(--sky);
      border-radius: 50%;
      font-size: 36px;
      border: 2px dashed rgba(14,165,233,0.32);
    }
    .empty-title { font-size: 17px; font-weight: 700; color: var(--text-1); margin-bottom: 6px; }
    .empty-sub { font-size: 13px; color: var(--text-2); }
    .empty-sub.mini { font-size: 11.5px; color: var(--text-3); margin-top: 6px; }

    /* ANIMATIONS */
    @keyframes cardIn {
      from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
      to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
    }

    /* RESPONSIVE */
    @media (max-width: 767px) {
      .top-header { height: 58px; }
      .header-inner { padding: 0 12px; gap: 10px; }
      .header-title { font-size: 15px; }
      .header-title i { width: 26px; height: 26px; font-size: 15px; }
      .header-sub { display: none; }
      .header-back { width: 38px; height: 38px; }
      main { padding: 12px 12px 40px !important; }

      .info-bar { padding: 12px; gap: 8px 14px; }
      .info-value { max-width: 220px; font-size: 12.5px; }
      .info-divider { display: none; }

      .search-form { flex-direction: column; gap: 8px; }
      .search-input { height: 44px; font-size: 16px; padding: 0 14px 0 38px; } /* iOS zoom */
      .search-input-wrap > i.search-icon { font-size: 13px; }
      .search-btn { height: 44px; width: 100%; }

      .products-grid { grid-template-columns: 1fr; gap: 10px; }
      .product-card { padding: 12px; }
      .product-card:hover { transform: none; }

      .qty-input, .fiyat-input { height: 44px; font-size: 16px; } /* iOS zoom */
      .add-btn { height: 44px; min-width: 44px; font-size: 13px; }
      .add-btn:hover { transform: none; }

      .empty-state { padding: 40px 18px; }
      .empty-icon { width: 72px; height: 72px; font-size: 30px; }
    }
    @media (max-width: 420px) {
      .prod-actions { grid-template-columns: 80px 1fr auto; gap: 6px; }
      .add-btn { padding: 0 12px; }
      .add-btn span { display: none; }
    }
  </style>
</head>
<body>

  <header class="top-header">
    <div class="header-inner">
      <a href="fis.php?id=<?php echo $fisId; ?>" class="header-back" title="Sipariş Fişine Dön" aria-label="Geri">
        <i class="fa-solid fa-arrow-left"></i>
      </a>
      <div class="header-divider"></div>
      <div class="header-title-wrap">
        <span class="header-title">
          <i class="fa-solid fa-magnifying-glass-dollar"></i>Ürün Ara
        </span>
        <span class="header-sub">
          <span><?php echo htmlspecialchars((string)$siparis['FICHENO']); ?></span>
          <span class="pipe">•</span>
          <span class="header-chip"><i class="fa-solid <?php echo $doviz['icon']; ?>"></i><?php echo htmlspecialchars($doviz['kod']); ?></span>
        </span>
      </div>
    </div>
  </header>

  <main style="max-width:1100px;margin:0 auto;padding:18px 24px 60px;">

    <!-- Info Bar -->
    <div class="info-bar">
      <div class="info-item">
        <span class="info-label"><i class="fa-solid fa-hashtag" style="margin-right:3px;"></i>Fiş No</span>
        <span class="info-value mono"><?php echo htmlspecialchars((string)$siparis['FICHENO']); ?></span>
      </div>
      <div class="info-divider"></div>
      <div class="info-item" style="flex: 1 1 220px; min-width: 0;">
        <span class="info-label"><i class="fa-solid fa-user" style="margin-right:3px;"></i>Cari</span>
        <span class="info-value" title="<?php echo htmlspecialchars((string)($siparis['CARI_ISIM'] ?? '-')); ?>">
          <?php echo htmlspecialchars((string)($siparis['CARI_ISIM'] ?? '-')); ?>
        </span>
      </div>
      <div class="info-divider"></div>
      <div class="info-item">
        <span class="info-label">Döviz</span>
        <span class="info-doviz-chip">
          <i class="fa-solid <?php echo $doviz['icon']; ?>"></i>
          <?php echo htmlspecialchars($doviz['kod']); ?>
          <span style="opacity:0.7">(<?php echo $doviz['sembol']; ?>)</span>
        </span>
      </div>
      <div class="info-item">
        <span class="info-label">Kur</span>
        <span class="info-kur">
          <i class="fa-solid fa-arrow-right-arrow-left"></i>
          1 <?php echo htmlspecialchars($doviz['kod']); ?> = <?php echo number_format($trrate, 4, ',', '.'); ?> ₺
        </span>
      </div>
    </div>

    <!-- Search Form -->
    <div class="search-wrap">
      <form method="GET" class="search-form" autocomplete="off">
        <input type="hidden" name="fisid" value="<?php echo $fisId; ?>">
        <div class="search-input-wrap">
          <i class="fa-solid fa-magnifying-glass search-icon"></i>
          <input type="text" name="q" value="<?php echo htmlspecialchars($aramaMetni); ?>"
            class="search-input"
            placeholder="Ürün kodu, adı veya barkod ile ara..." autofocus>
        </div>
        <button type="submit" class="search-btn">
          <i class="fa-solid fa-magnifying-glass"></i> Ara
        </button>
      </form>
    </div>

    <?php if (!empty($aramaMetni)): ?>
      <div class="results-head">
        <h3><i class="fa-solid fa-box"></i> Bulunan Ürünler</h3>
        <span class="results-count"><?php echo count($urunler); ?></span>
      </div>

      <?php if (empty($urunler)): ?>
        <div class="empty-state">
          <div class="empty-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
          <div class="empty-title">Ürün bulunamadı</div>
          <div class="empty-sub">"<?php echo htmlspecialchars($aramaMetni); ?>" için sonuç yok.</div>
          <div class="empty-sub mini">Farklı bir kod, ad veya barkod ile tekrar deneyin.</div>
        </div>
      <?php else: ?>
        <div class="products-grid">
          <?php $i = 0; foreach ($urunler as $urun):
            $tlFiyat = (float)$urun['FIYAT_TL'];
            $dovizFiyat = $trrate > 0 ? $tlFiyat / $trrate : 0;
            $stok = (float)$urun['STOK'];
            $stokClass = $stok > 0 ? 'stok-on' : 'stok-off';
            $delay = min($i * 30, 280);
            $i++;
          ?>
          <form method="POST" class="product-card" style="animation-delay: <?php echo $delay; ?>ms;">
            <input type="hidden" name="stokid" value="<?php echo (int)$urun['ID']; ?>">
            <input type="hidden" name="kdv" value="<?php echo (int)$urun['KDV']; ?>">

            <div class="prod-head">
              <span class="prod-kod"><i class="fa-solid fa-barcode" style="margin-right:4px;"></i><?php echo htmlspecialchars((string)$urun['KOD']); ?></span>
              <span class="prod-adi"><?php echo htmlspecialchars((string)$urun['ADI']); ?></span>
            </div>

            <div class="prod-meta">
              <span class="meta-chip <?php echo $stokClass; ?>">
                <i class="fa-solid fa-warehouse"></i>
                Stok: <?php echo number_format($stok, 0, ',', '.'); ?>
              </span>
              <?php if (!empty($urun['BIRIM'])): ?>
              <span class="meta-chip birim">
                <i class="fa-solid fa-ruler-combined"></i>
                <?php echo htmlspecialchars((string)$urun['BIRIM']); ?>
              </span>
              <?php endif; ?>
              <span class="meta-chip kdv">
                <i class="fa-solid fa-percent"></i>
                KDV %<?php echo (int)$urun['KDV']; ?>
              </span>
            </div>

            <div class="prod-prices">
              <div class="price-block">
                <span class="price-lbl"><i class="fa-solid fa-turkish-lira-sign"></i> TL Fiyat</span>
                <div class="price-val"><?php echo number_format($tlFiyat, 2, ',', '.'); ?> ₺</div>
              </div>
              <div class="price-block doviz">
                <span class="price-lbl"><i class="fa-solid <?php echo $doviz['icon']; ?>"></i> <?php echo htmlspecialchars($doviz['kod']); ?> Fiyat</span>
                <div class="price-val"><?php echo number_format($dovizFiyat, 2, ',', '.'); ?> <?php echo $doviz['sembol']; ?></div>
              </div>
            </div>

            <div class="prod-actions">
              <div>
                <label class="field-lbl">Miktar</label>
                <input type="number" name="miktar" value="1" min="1" step="1" class="qty-input" inputmode="decimal">
              </div>
              <div>
                <label class="field-lbl"><?php echo htmlspecialchars($doviz['kod']); ?> Fiyat</label>
                <input type="text" name="fiyat" value="<?php echo number_format($dovizFiyat, 2, ',', ''); ?>" class="fiyat-input">
              </div>
              <button type="submit" class="add-btn" title="Siparişe Ekle">
                <i class="fa-solid fa-plus"></i><span>Ekle</span>
              </button>
            </div>
          </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon"><i class="fa-solid fa-barcode"></i></div>
        <div class="empty-title">Ürün Arayın</div>
        <div class="empty-sub">Barkod okutun ya da ürün kodu/adı ile arama yapın.</div>
        <div class="empty-sub mini">Fiyatlar otomatik olarak <strong><?php echo htmlspecialchars($doviz['kod']); ?></strong> cinsine çevrilecektir.</div>
      </div>
    <?php endif; ?>

  </main>

  <script>
    // RAF reflow for stagger animations
    (function(){
      if (typeof window === 'undefined' || !window.requestAnimationFrame) return;
      window.requestAnimationFrame(function(){
        document.querySelectorAll('.product-card').forEach(function(el){
          // trigger reflow to ensure animation-delay applies smoothly
          void el.offsetWidth;
        });
      });
    })();
  </script>

</body>
</html>
