<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
include(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

$resmiKdvZorunlu = defined('RESMI_FORCE_KDV');
$resmiKdvOrani = $resmiKdvZorunlu ? (float) RESMI_FORCE_KDV : 0.0;

// Dönem kontrolü kaldırıldı - sadece güncel dönem tabloları kullanılıyor
// Eski dönem session değişkenlerini temizle
unset($_SESSION['siparis_readonly']);
unset($_SESSION['siparis_donem']);

// Hareket silme işlemini yönlendirmeden ÖNCE yap (POST ile CSRF korumalı)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hareketsil'])) {
    $stokhareket = isset($_POST['stokhareket']) ? (int)$_POST['stokhareket'] : 0;
    if ($stokhareket > 0) {
        setPageParam('stokhareket', $stokhareket);
    } else {
        $stokhareket = getPageParamInt('stokhareket');
    }
    if ($stokhareket > 0 && !m_p_siparis_goruntulebilir_mi($dbh, $firmadonem, $firma, $terminalkullanici, $stokhareket)) {
        header('Location: ' . APP_ROOT_URL . '/403.html');
        exit;
    }
    include_once(__DIR__ . "/hareketsil.php");
    exit; // hareketsil.php zaten yönlendirme yapıyor
}

// Stokhareket'i al: önce URL'den, yoksa session'dan
if (isset($_GET['stokhareket']) && (int)$_GET['stokhareket'] > 0) {
    $stokhareket = (int)$_GET['stokhareket'];
    // Session'a kaydet
    $_SESSION['lg_fis_stokhareket'] = $stokhareket;
} else {
    // Session'dan oku
    $stokhareket = isset($_SESSION['lg_fis_stokhareket']) ? (int)$_SESSION['lg_fis_stokhareket'] : 0;
}
if ($stokhareket > 0 && !m_p_siparis_goruntulebilir_mi($dbh, $firmadonem, $firma, $terminalkullanici, $stokhareket)) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

$lgFisBackUrl = '../index.php';
if ($stokhareket > 0) {
    $returnSessionKey = 'lg_fis_return_to_' . $stokhareket;
    if (isset($_GET['return_to'])) {
        $_SESSION[$returnSessionKey] = safeLocalReturnUrl((string) $_GET['return_to'], $lgFisBackUrl);
    }
    if (isset($_SESSION[$returnSessionKey])) {
        $lgFisBackUrl = safeLocalReturnUrl((string) $_SESSION[$returnSessionKey], $lgFisBackUrl);
    }
}

// DÖVİZLİ SİPARİŞLERİ DÖVİZ MODÜLÜNE YÖNLENDİR
// Normal kısım sadece TL siparişleri için kullanılır
// Dövizli siparişler (TRCURR > 0) doviz/fis.php'ye yönlendirilir
if ($stokhareket > 0) {
    try {
        $stmtDovizKontrol = $dbh->prepare("SELECT TRCURR, TRRATE FROM {$firmadonem}ORFICHE WHERE LOGICALREF = :stokhareket");
        $stmtDovizKontrol->execute([':stokhareket' => $stokhareket]);
        $dovizKontrol = $stmtDovizKontrol->fetch(PDO::FETCH_ASSOC);

        if ($dovizKontrol && (int)$dovizKontrol['TRCURR'] > 0 && (float)$dovizKontrol['TRRATE'] > 0) {
            // Dövizli sipariş - doviz modülüne yönlendir
            header("Location: ../doviz/fis.php?id=" . $stokhareket);
            exit;
        }
    } catch (Exception $e) {
        error_log("lg_fis-beta.php - Döviz kontrol hatası: " . $e->getMessage());
    }
}

// Sadece güncel dönem kullanılıyor ($firmadonem = LG_001_02_)
// Bu sayfa artık sadece TL siparişleri için kullanılıyor
// Dövizli siparişler doviz/fis.php'ye yönlendirilir

// TL modu için sabit değerler (döviz devre dışı)
$dovizAktif = false;
$dovizKuru = 1;
$dovizSembol = '₺';
$dovizTipi = 0;

// Düzenleme dosyalarını dahil et
if ($coklustokgiris == "evet") {
   include_once(__DIR__ . "/hareketeklecoklu.php");
}

// Hareket Ekle / Sil / Düzenle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stkid'])) {
   include_once(__DIR__ . "/hareketekle.php");
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stkduzenle'])) {
   include_once(__DIR__ . "/hareketduzenle.php");
}
include_once(__DIR__ . "/iskonto.php");

// KDV İPTAL
if (isset($_POST['kdviptal'])) {
   if (!csrf_verify()) {
      http_response_code(403);
      exit('CSRF dogrulamasi basarisiz.');
   }

   $isKdvAjax = isset($_POST['ajax']) && $_POST['ajax'] === '1';
   $stokhareket = isset($_POST['stokhareket']) ? (int)$_POST['stokhareket'] : 0;
   $yenikdv = $resmiKdvZorunlu
      ? $resmiKdvOrani
      : (isset($_POST['kdviptal']) ? (float)virgul($_POST['kdviptal']) : 0.0);

   if ($stokhareket > 0) {
      try {
         $stmtKdvSatirlar = $dbh->prepare("
            SELECT TOTAL, DISTDISC, LOGICALREF
            FROM {$firmadonem}ORFLINE
            WHERE ORDFICHEREF = :stokhareket AND LINETYPE = 0
        ");
         $stmtKdvSatirlar->bindValue(':stokhareket', $stokhareket, PDO::PARAM_INT);
         $stmtKdvSatirlar->execute();

         $stmtKdvGuncelle = $dbh->prepare("
            UPDATE {$firmadonem}ORFLINE
            SET VAT = :yenikdv, VATAMNT = :vatamnt
            WHERE LOGICALREF = :satirref
        ");

         while ($kdvgncl = $stmtKdvSatirlar->fetch(PDO::FETCH_ASSOC)) {
            $idnohk = (int)$kdvgncl['LOGICALREF'];
            $stoktoplamfy = (float)$kdvgncl['TOTAL'] - (float)$kdvgncl['DISTDISC'];
            $stoktoplamkdvfy = ($stoktoplamfy / 100) * $yenikdv;

            $stmtKdvGuncelle->execute([':yenikdv' => $yenikdv, ':vatamnt' => $stoktoplamkdvfy, ':satirref' => $idnohk]);
         }
      } catch (PDOException $e) {
         error_log('lg_fis-beta.php KDV iptal hatası: ' . $e->getMessage());
      }
   }

   if ($isKdvAjax) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => true, 'msg' => 'KDV guncellendi']);
      exit;
   }

   // Session'a kaydet ve temiz URL'ye yönlendir
   setPageParam('stokhareket', $stokhareket);
   echo '<script>window.location="lg_fis-beta.php";</script>';
}

function paraformat_lg_fis(float|int|string|null $kusurat): string
{
   // Eğer giriş boşsa, sıfır olarak ayarla
   if ($kusurat === "" || is_null($kusurat)) {
      $kusurat = 0;
   }

   // Sayıyı standart yuvarlama ile iki ondalık basamağa getir
   $kusurat = round((float)$kusurat, 2);

   // Sayıyı formatla
   $formattedNumber = number_format($kusurat, 2, ',', '.') . '₺';

   return $formattedNumber;
}

function paraformat_doviz_lg_fis(float|int|string|null $kusurat, string $sembol = '$'): string
{
   // Eğer giriş boşsa, sıfır olarak ayarla
   if ($kusurat === "" || is_null($kusurat)) {
      $kusurat = 0;
   }

   // Sayıyı standart yuvarlama ile iki ondalık basamağa getir
   $kusurat = round((float)$kusurat, 2);

   // Sayıyı formatla (sembol parametresi ile)
   $formattedNumber = number_format($kusurat, 2, ',', '.') . $sembol;

   return $formattedNumber;
}

function netFiyatHesapla(float|int|string $fiyat, float|int|string $indirimOrani): float
{
   $indirimMiktari = $fiyat * ($indirimOrani / 100);
   $netFiyat = $fiyat - $indirimMiktari;
   return round($netFiyat, 2);
}


?>
<!DOCTYPE html>
<html lang="tr">

<head>
   <meta charset="UTF-8" />
   <meta name="viewport" content="width=device-width, initial-scale=1.0" />
   <title>Stok Hareket</title>
   <?php include_once(__DIR__ . '/../pwa-header.php'); ?>
   <script src="/tm/css/tailwind.js"></script>
   <script>
      if (typeof tailwind !== 'undefined') {
         tailwind.config = {
            corePlugins: { preflight: false }
         };
      }
   </script>
   <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
   <script src="/tm/js/jquery-3.7.1.min.js"></script>
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
   <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
   <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
   <style>
      :root {
         --bg: #f9fafb;
         --surface: #ffffff;
         --text-1: #1f2937;
         --text-2: #6b7280;
         --text-3: #9ca3af;
         --border: #e5e7eb;
         --red: #ef4444;
         --red-soft: #fef2f2;
         --red-border: rgba(248, 113, 113, 0.25);
         --amber: #f59e0b;
         --emerald: #10b981;
         --indigo: #6366f1;
      }

      * { box-sizing: border-box; margin: 0; }

      body {
         font-family: 'Avenir Next', 'Montserrat', 'Segoe UI', sans-serif;
         background: var(--bg);
         color: var(--text-1);
         min-height: 100vh;
      }

      /* ═══════════ ANIMATIONS ═══════════ */
      @keyframes cardIn {
         from { opacity: 0; transform: translate3d(0, 18px, 0) scale(0.985); }
         to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
      }

      @keyframes headerSlide {
         from { opacity: 0; transform: translateY(-100%); }
         to { opacity: 1; transform: translateY(0); }
      }

      @keyframes fadeUp {
         from { opacity: 0; transform: translateY(12px); }
         to { opacity: 1; transform: translateY(0); }
      }

      /* ═══════════ STICKY HEADER ═══════════ */
      .beta-header {
         position: sticky;
         top: 0;
         z-index: 50;
         height: 64px;
         background: rgba(255, 255, 255, 0.88);
         backdrop-filter: blur(6px);
         -webkit-backdrop-filter: blur(6px);
         border-bottom: 1px solid rgba(248, 113, 113, 0.18);
         box-shadow: 0 2px 8px rgba(111, 16, 34, 0.04);
         animation: headerSlide 0.46s cubic-bezier(0.22, 1, 0.36, 1) both;
      }

      .beta-header-inner {
         max-width: 1400px;
         margin: 0 auto;
         height: 100%;
         display: flex;
         align-items: center;
         gap: 14px;
         padding: 0 20px;
      }

      /* ═══════════ FLAT BUTTONS ═══════════ */
      .btn-flat {
         display: inline-flex;
         align-items: center;
         justify-content: center;
         gap: 6px;
         padding: 8px 16px;
         border-radius: 10px;
         font-size: 13px;
         font-weight: 600;
         font-family: 'Avenir Next', 'Montserrat', sans-serif;
         border: none;
         cursor: pointer;
         text-decoration: none;
         transition: all 0.2s ease;
         box-shadow: 0 1px 3px rgba(0,0,0,0.08);
      }

      .btn-flat:hover {
         transform: translateY(-1px);
         box-shadow: 0 4px 12px rgba(0,0,0,0.12);
      }

      .btn-flat:active {
         transform: translateY(0);
      }

      .btn-red { background: var(--red,#ef4444); color: #fff; }
      .btn-red:hover { background: var(--red,#6F1022); color: #fff; }

      .btn-amber { background: #f59e0b; color: #fff; }
      .btn-amber:hover { background: #d97706; color: #fff; }

      .btn-emerald { background: #10b981; color: #fff; }
      .btn-emerald:hover { background: #059669; color: #fff; }

      .btn-indigo { background: #6366f1; color: #fff; }
      .btn-indigo:hover { background: #4f46e5; color: #fff; }

      .btn-slate { background: #64748b; color: #fff; }
      .btn-slate:hover { background: #475569; color: #fff; }

      .btn-light {
         background: #fff;
         color: #6b7280;
         border: 1px solid #e5e7eb;
      }
      .btn-light:hover {
         background: #f9fafb;
         color: #374151;
         border-color: #d1d5db;
      }

      .btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 8px; }

      /* ═══════════ GLASS CARD ═══════════ */
      .glass-card {
         background: rgba(255, 255, 255, 0.88);
         backdrop-filter: blur(6px);
         -webkit-backdrop-filter: blur(6px);
         border: 1px solid rgba(248, 113, 113, 0.18);
         border-radius: 16px;
         box-shadow: 0 4px 16px rgba(0,0,0,0.04);
         will-change: transform, opacity;
         animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
         position: relative;
         overflow: hidden;
      }

      /* Shimmer hover effect */
      .glass-card::before {
         content: "";
         position: absolute;
         inset: 0;
         background: linear-gradient(120deg, transparent 15%, rgba(255, 255, 255, 0.5) 45%, transparent 75%);
         transform: translateX(-130%);
         transition: transform 0.75s ease;
         pointer-events: none;
         z-index: 1;
      }

      .glass-card:hover::before {
         transform: translateX(130%);
      }

      .glass-card-body {
         padding: 20px 24px;
         position: relative;
         z-index: 2;
      }

      /* ═══════════ SEARCH INPUT ═══════════ */
      .search-input {
         flex: 1;
         min-width: 0;
         width: 100%;
         max-width: 480px;
         padding: 10px 16px;
         border-radius: 10px;
         border: 1px solid var(--border);
         background: #fff;
         font-family: 'Avenir Next', 'Montserrat', sans-serif;
         font-size: 14px;
         color: var(--text-1);
         outline: none;
         transition: all 0.2s ease;
      }

      .search-input::placeholder { color: var(--text-3); }

      .search-input:focus {
         border-color: rgba(239, 68, 68, 0.5);
         box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
      }

      .search-form {
         flex: 1;
         min-width: 0;
         display: flex;
         gap: 8px;
         max-width: 620px;
      }

      .search-submit {
         white-space: nowrap;
         padding: 12px 20px;
         font-size: 15px;
         flex-shrink: 0;
      }

      .header-back-btn {
         padding: 12px 16px;
         border-radius: 12px;
         flex-shrink: 0;
      }

      /* ═══════════ CHIPS ═══════════ */
      .info-chip {
         display: inline-flex;
         align-items: center;
         gap: 6px;
         padding: 6px 14px;
         border-radius: 100px;
         font-size: 12.5px;
         font-weight: 600;
         transition: all 0.25s ease;
      }

      .info-chip i { font-size: 11px; }

      .info-chip:hover {
         transform: translateY(-1px);
         box-shadow: 0 4px 12px rgba(0,0,0,0.08);
      }

      .chip-blue { background: #eff6ff; color: #1d4ed8; border: 1px solid rgba(59, 130, 246, 0.2); }
      .chip-purple { background: #faf5ff; color: #7c3aed; border: 1px solid rgba(147, 51, 234, 0.2); }
      .chip-orange { background: #fff7ed; color: #c2410c; border: 1px solid rgba(251, 146, 60, 0.2); }
      .chip-indigo { background: #eef2ff; color: #4338ca; border: 1px solid rgba(99, 102, 241, 0.2); }
      .chip-green { background: #f0fdf4; color: #166534; border: 1px solid rgba(34, 197, 94, 0.2); }
      .chip-red { background: #fef2f2; color: #991b1b; border: 1px solid rgba(248, 113, 113, 0.2); }
      .chip-gray { background: #f9fafb; color: #374151; border: 1px solid #e5e7eb; }

      /* ═══════════ TABLE ═══════════ */
      .beta-table-wrap {
         overflow-x: auto;
         border-radius: 16px;
         border: 1px solid rgba(248, 113, 113, 0.18);
         background: #fff;
         box-shadow: 0 4px 16px rgba(0,0,0,0.04);
         will-change: transform, opacity;
         animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
      }

      .beta-table {
         width: 100%;
         border-collapse: collapse;
         font-size: 13.5px;
      }

      .beta-table thead th {
         background: var(--red,#ef4444);
         color: #fff;
         font-size: 11px;
         font-weight: 700;
         letter-spacing: 0.06em;
         text-transform: uppercase;
         padding: 12px 14px;
         white-space: nowrap;
         border: none;
      }

      .beta-table thead th:first-child { border-radius: 0; }
      .beta-table thead th:last-child { border-radius: 0; }

      .beta-table tbody td {
         padding: 10px 14px;
         border-bottom: 1px solid #f3f4f6;
         color: var(--text-1);
         white-space: nowrap;
         vertical-align: middle;
      }

      .beta-table tbody tr {
         transition: all 0.18s ease;
      }

      .beta-table tbody tr:hover {
         background: rgba(254, 242, 242, 0.5);
         transform: translateY(-1px);
         box-shadow: 0 4px 12px rgba(239, 68, 68, 0.06);
      }

      .beta-table tbody tr:last-child td {
         border-bottom: none;
      }

      .beta-table .num {
         font-variant-numeric: tabular-nums;
      }

      .beta-table .name-cell {
         white-space: normal;
         max-width: 300px;
      }

      .row-num {
         display: inline-flex;
         align-items: center;
         justify-content: center;
         width: 26px;
         height: 26px;
         border-radius: 8px;
         background: #fef2f2;
         color: var(--red,#ef4444);
         font-size: 11px;
         font-weight: 700;
         border: 1px solid rgba(248, 113, 113, 0.2);
      }

      .unit-badge {
         display: inline-block;
         margin-top: 2px;
         font-size: 10px;
         text-transform: uppercase;
         letter-spacing: 0.08em;
         color: var(--text-3);
         font-weight: 600;
      }

      /* ═══════════ SUMMARY METRICS ═══════════ */
      .summary-panel {
         will-change: transform, opacity;
         animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) 0.08s both;
      }

      .metric-row {
         display: flex;
         justify-content: space-between;
         align-items: center;
         padding: 10px 0;
      }

      .metric-row + .metric-row {
         border-top: 1px solid #f3f4f6;
      }

      .metric-label {
         font-size: 13px;
         color: var(--text-2);
         font-weight: 500;
         display: flex;
         align-items: center;
         gap: 8px;
      }

      .metric-label i { font-size: 14px; }

      .metric-value {
         font-size: 15px;
         font-weight: 700;
         color: var(--text-1);
      }

      .metric-divider {
         height: 2px;
         background: linear-gradient(90deg, transparent, rgba(248, 113, 113, 0.3), transparent);
         margin: 8px 0;
         border: none;
      }

      .grand-total-value {
         font-size: 24px;
         font-weight: 700;
         color: #166534;
      }

      /* ═══════════ ISKONTO / KDV SECTION ═══════════ */
      .settings-section {
         border-top: 1px solid #f3f4f6;
         padding-top: 16px;
         margin-top: 16px;
      }

      .settings-title {
         font-size: 11px;
         font-weight: 700;
         letter-spacing: 0.08em;
         text-transform: uppercase;
         color: var(--text-3);
         margin-bottom: 12px;
         display: flex;
         align-items: center;
         gap: 6px;
      }

      .isk-input-group {
         display: flex;
         align-items: stretch;
         border-radius: 8px;
         overflow: hidden;
         border: 1px solid var(--border);
      }

      .isk-input-group .isk-label {
         display: flex;
         align-items: center;
         padding: 0 10px;
         background: #f9fafb;
         font-size: 11px;
         font-weight: 700;
         color: var(--text-2);
         border-right: 1px solid var(--border);
      }

      .isk-input-group input {
         width: 56px;
         text-align: center;
         border: none;
         padding: 6px 8px;
         font-size: 13px;
         font-weight: 600;
         font-family: 'Avenir Next', 'Montserrat', sans-serif;
         outline: none;
         color: var(--text-1);
      }

      .isk-input-group input:focus {
         background: #eff6ff;
      }

      .isk-input-group .isk-suffix {
         display: flex;
         align-items: center;
         padding: 0 10px;
         background: #f9fafb;
         font-size: 12px;
         color: var(--text-3);
         border-left: 1px solid var(--border);
      }

      /* ═══════════ DROPDOWN ═══════════ */
      .dropdown-menu {
         background: rgba(255, 255, 255, 0.96);
         backdrop-filter: blur(8px);
         border: 1px solid rgba(248, 113, 113, 0.15);
         border-radius: 12px;
         box-shadow: 0 12px 32px rgba(0, 0, 0, 0.12);
         overflow: hidden;
      }

      .dropdown-menu a {
         display: flex;
         align-items: center;
         gap: 10px;
         padding: 10px 16px;
         font-size: 13px;
         color: var(--text-1);
         text-decoration: none;
         transition: background 0.15s ease;
      }

      .dropdown-menu a:hover {
         background: #fef2f2;
      }

      .dropdown-menu a i {
         width: 18px;
         text-align: center;
         font-size: 14px;
      }

      .dropdown-divider {
         height: 1px;
         background: #f3f4f6;
         margin: 4px 0;
      }

      .dropdown-actions { right: 0; left: auto; }
      @media (max-width: 767px) {
         .dropdown-actions { right: auto; left: 0; transform: translateX(-40%); }
      }

      /* ═══════════ RESPONSIVE ═══════════ */
      @media (max-width: 768px) {
         .beta-header { height: 50px; }
         .beta-header-inner { padding: 0 12px; gap: 8px; }
         .search-input { max-width: 100%; font-size: 16px; padding: 9px 12px; }
         .search-submit { padding: 10px 12px; min-width: 44px; min-height: 44px; }
         .search-submit span { display: none; }
         .header-back-btn { padding: 12px 14px; }
         .glass-card-body { padding: 14px 16px; }
         .beta-table td { font-size: 12px; padding: 8px 10px; }
         .beta-table thead th { font-size: 10px; padding: 10px 10px; }
         .grand-total-value { font-size: 20px; }
         .btn-flat { padding: 7px 12px; font-size: 12px; }
         /* Mobil dokunma + iOS zoom (font<16px) onlemleri */
         .btn-sm { padding: 10px 14px; min-height: 44px; }
         .isk-input-group input { font-size: 16px; }
      }
   </style>
</head>

<body>

   <?php if (isset($_SESSION['uyari_mesaj'])): ?>
   <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-0" role="alert" style="animation: fadeUp 0.4s ease both;">
      <div class="flex items-center">
         <i class="fa-solid fa-exclamation-triangle mr-3"></i>
         <p><?php echo htmlspecialchars($_SESSION['uyari_mesaj']); ?></p>
      </div>
   </div>
   <?php unset($_SESSION['uyari_mesaj']); endif; ?>

   <!-- ═══════════ STICKY HEADER ═══════════ -->
   <header class="beta-header">
      <div class="beta-header-inner">
         <!-- Back Button -->
         <a href="<?php echo htmlspecialchars($lgFisBackUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn-flat btn-red header-back-btn" title="Geri Don">
            <i class="fa fa-arrow-left" style="font-size: 18px;"></i>
         </a>

         <!-- Search Form -->
         <form name="form" method="GET" action="../stok/lg_stok_bul.php" class="search-form">
            <input type="text" name="barkod" id="barkod" class="search-input"
               autocomplete="off" aria-label="Urun veya barkod ara"
               placeholder="🔍 Ürün ara veya barkod okut...">
            <input type="hidden" name="fisid" value="<?php echo $stokhareket; ?>">
            <button type="submit" class="btn-flat btn-red search-submit">
               <i class="fa fa-search"></i>
               <span class="hidden md:inline">Ara</span>
            </button>
         </form>

      </div>
   </header>

   <!-- ═══════════ MAIN CONTENT ═══════════ -->
   <div style="max-width: 1400px; margin: 0 auto; padding: 20px 20px 60px;">

      <!-- ═══════════ CUSTOMER INFO + ACTIONS ═══════════ -->
      <?php
      // Stok hareket fişinden cari referansı al
      $clientQuery = $dbh->prepare("SELECT CLIENTREF FROM {$firmadonem}ORFICHE WHERE LOGICALREF = :stokhareket");
      $clientQuery->bindParam(':stokhareket', $stokhareket, PDO::PARAM_INT);
      $clientQuery->execute();
      $clientResult = $clientQuery->fetch(PDO::FETCH_ASSOC);
      $clientRef = $clientResult ? $clientResult['CLIENTREF'] : 0;
      $sql = "
   WITH
      SonFatura AS (
         SELECT TOP 1
               F.LOGICALREF, F.CLIENTREF, F.FICHENO, F.DATE_, F.NETTOTAL,
               F.GROSSTOTAL, F.TOTALVAT, F.TOTALDISCOUNTS, F.SALESMANREF
         FROM {$firmadonem}INVOICE F WITH(NOLOCK)
         WHERE F.TRCODE = 8 AND F.CLIENTREF = :clientRef1
         ORDER BY F.DATE_ DESC, F.LOGICALREF DESC
      )
   SELECT
      C.DEFINITION_ AS MusteriAdi, C.CITY, C.TELNRS1,
      SF.FICHENO, SF.DATE_, SF.NETTOTAL, SF.GROSSTOTAL, SF.TOTALDISCOUNTS, SF.TOTALVAT,
      ROUND(CASE WHEN SF.GROSSTOTAL > 0 THEN (SF.TOTALDISCOUNTS / SF.GROSSTOTAL) * 100 ELSE 0 END, 2) AS ISKONTO_YUZDESI,
      ROUND(CASE WHEN (SF.GROSSTOTAL - SF.TOTALDISCOUNTS) > 0 THEN (SF.TOTALVAT / (SF.GROSSTOTAL - SF.TOTALDISCOUNTS)) * 100 ELSE 0 END, 2) AS KDV_YUZDESI,
      (ISNULL(G.DEBIT, 0) - ISNULL(G.CREDIT, 0)) AS BAKIYE,
      SL.CODE AS SALESMAN_KODU, SL.DEFINITION_ AS SALESMAN_ADI
   FROM {$firma}CLCARD C WITH(NOLOCK)
   LEFT JOIN SonFatura SF ON SF.CLIENTREF = C.LOGICALREF
   LEFT JOIN {$firmadonemx}GNTOTCL G WITH(NOLOCK) ON G.CARDREF = C.LOGICALREF AND G.TOTTYP = 1
   LEFT JOIN LG_SLSMAN SL WITH(NOLOCK) ON SL.LOGICALREF = SF.SALESMANREF
   WHERE C.ACTIVE = 0 AND C.LOGICALREF = :clientRef2;
   ";
      $stmt = $dbh->prepare($sql);
      $stmt->bindParam(':clientRef1', $clientRef, PDO::PARAM_INT);
      $stmt->bindParam(':clientRef2', $clientRef, PDO::PARAM_INT);
      $stmt->execute();
      $musteriBilgisi = $stmt->fetch(PDO::FETCH_ASSOC);

      $musteriAdi = ''; $city = ''; $iskontoYuzde = 0; $kdvYuzde = 0; $bakiye = 0.0;
      if ($musteriBilgisi) {
         $musteriAdi   = $musteriBilgisi['MusteriAdi'] ?? '';
         $city         = $musteriBilgisi['CITY'] ?? '';
         $iskontoYuzde = (float)($musteriBilgisi['ISKONTO_YUZDESI'] ?? 0);
         $kdvYuzde     = (float)($musteriBilgisi['KDV_YUZDESI'] ?? 0);
         $bakiye       = (float)($musteriBilgisi['BAKIYE'] ?? 0);
      }
      // Renk kuralı (2026-07-07, her yerde aynı): BORÇLU=yeşil (tahsil edilecek), ALACAKLI=kırmızı
      $bakiyeClass = ($bakiye > 0) ? 'color:#059669;' : (($bakiye < 0) ? 'color:var(--red,#6F1022);' : 'color:#6b7280;');
      $bakiyeLabel = ($bakiye < 0) ? 'Alacaklı' : (($bakiye > 0) ? 'Borçlu' : '');
      ?>
      <div class="glass-card" style="margin-bottom: 16px;overflow:visible;position:relative;z-index:30;">
         <div class="glass-card-body" style="padding:0;overflow:visible;">
            <!-- Üst: Müşteri Adı + Butonlar -->
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 18px;border-bottom:1px solid rgba(0,0,0,0.05);flex-wrap:wrap;">
               <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                  <div style="width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#eef2ff,#e0e7ff);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                     <i class="fa-solid fa-building" style="color:#6366f1;font-size:16px;"></i>
                  </div>
                  <div style="min-width:0;">
                     <div style="font-size:16px;font-weight:700;color:var(--text-1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?php echo htmlspecialchars((string)$musteriAdi, ENT_QUOTES, 'UTF-8'); ?>
                     </div>
                     <?php if ($city !== ''): ?>
                        <div style="font-size:12px;color:var(--text-3);margin-top:2px;">
                           <i class="fa-solid fa-map-marker-alt" style="margin-right:3px;"></i><?php echo htmlspecialchars((string)$city, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                     <?php endif; ?>
                  </div>
               </div>
               <!-- Butonlar -->
               <div style="display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap;">
                  <a href="../yazdir/yeni_dizayn.php?stokhareket=<?php echo $stokhareket; ?>&tipdurum=1"
                     class="btn-flat btn-amber" title="Yazdır" style="padding:8px 14px;">
                     <i class="fa fa-print"></i>
                     <span class="hidden sm:inline">Yazdır</span>
                  </a>
                  <button id="btnFastPrint" type="button"
                     class="btn-flat btn-red" style="padding:8px 14px;"
                     data-url="../yazdir/hizli_yazdir.php?stokhareket=<?php echo $stokhareket; ?>&tip=1&ajax=1"
                     title="Hızlı Yazdır">
                     <i class="fa fa-bolt"></i><i class="fa fa-print"></i>
                  </button>
                  <div class="relative" x-data="{ open: false }">
                     <button @click="open = !open" @click.away="open = false" type="button"
                        class="btn-flat btn-light" title="Diğer İşlemler" style="padding:8px 12px;">
                        <i class="fa fa-ellipsis-v"></i>
                     </button>
                     <div x-show="open" x-transition
                        class="dropdown-menu dropdown-actions absolute mt-2 w-52"
                        style="z-index:999;max-height:70vh;overflow-y:auto;">
                        <a href="../stok/stok_hareket_excel.php?stokhareket=<?php echo $stokhareket; ?>">
                           <i class="fa fa-file-excel" style="color:#059669;"></i> Excel'e Aktar
                        </a>
                        <a href="../stok/stok_hareket_pdf.php?stokhareket=<?php echo $stokhareket; ?>">
                           <i class="fa fa-file-pdf" style="color:var(--red,#ef4444);"></i> PDF'e Aktar
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="../stok/stok_hareket_aktar.php?stokhareket=<?php echo $stokhareket; ?>">
                           <i class="fa fa-exchange-alt" style="color:#3b82f6;"></i> Başka Cariye Aktar
                        </a>
                        <?php if (m_p_yetki($terminalkullanici, 'M12') == 1): ?>
                        <a href="javascript:void(0)" @click="open=false; ycaAc()">
                           <i class="fa fa-user-plus" style="color:#10b981;"></i> Yeni Cari Aç &amp; Aktar
                        </a>
                        <?php endif; ?>
                        <a href="../stok/stok_hareket_tarih_degistir.php?stokhareket=<?php echo $stokhareket; ?>">
                           <i class="fa fa-calendar" style="color:#8b5cf6;"></i> Tarih Değiştir
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="resimsizteklif.php?stokhareket=<?php echo $stokhareket; ?>&yazdir=yazdir">
                           <i class="fa fa-file-alt" style="color:#6b7280;"></i> Resimsiz Teklif
                        </a>
                        <a href="resimliteklif.php?stokhareket=<?php echo $stokhareket; ?>&yazdir=yazdir">
                           <i class="fa fa-image" style="color:#6366f1;"></i> Resimli Teklif
                        </a>
                     </div>
                  </div>
               </div>
            </div>
            <!-- Alt: Bilgi satırı (tek sıra) -->
            <div style="display:flex;align-items:center;gap:0;padding:0;border-top:1px solid rgba(0,0,0,0.05);">
               <div style="flex:1;padding:10px 16px;border-right:1px solid rgba(0,0,0,0.05);text-align:center;">
                  <span style="font-size:10px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:var(--text-3);"><i class="fa-solid fa-percent" style="margin-right:2px;"></i>İskonto</span>
                  <div style="font-size:14px;font-weight:700;color:#d97706;margin-top:2px;">%<?php echo number_format($iskontoYuzde, 2, ',', '.'); ?></div>
               </div>
               <div style="flex:1;padding:10px 16px;border-right:1px solid rgba(0,0,0,0.05);text-align:center;">
                  <span style="font-size:10px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:var(--text-3);"><i class="fa-solid fa-file-invoice-dollar" style="margin-right:2px;"></i>KDV</span>
                  <div style="font-size:14px;font-weight:700;color:#6366f1;margin-top:2px;">%<?php echo number_format($kdvYuzde, 2, ',', '.'); ?></div>
               </div>
               <div style="flex:1;padding:10px 16px;text-align:center;">
                  <span style="font-size:10px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:var(--text-3);"><i class="fa-solid fa-wallet" style="margin-right:2px;"></i>Bakiye <?php echo $bakiyeLabel; ?></span>
                  <?php if (m_p_yetki($terminalkullanici, 'CR1') == 1): ?>
                     <div style="font-size:14px;font-weight:700;<?php echo $bakiyeClass; ?>margin-top:2px;"><?php echo paraformat_lg_fis(abs($bakiye)); ?></div>
                  <?php else: ?>
                     <div style="font-size:14px;font-weight:700;color:var(--text-3);margin-top:2px;"><i class="fa-solid fa-lock" style="font-size:11px;"></i> ***</div>
                  <?php endif; ?>
               </div>
            </div>
         </div>
      </div>

      <!-- ═══════════ PRODUCT TABLE ═══════════ -->
      <div class="beta-table-wrap">
         <table class="beta-table">
            <colgroup>
               <col style="width:56px" />
               <col style="width:150px" />
               <col />
               <col style="width:130px" />
               <col style="width:130px" />
               <col style="width:130px" />
               <col style="width:150px" />
               <?php if ($dovizAktif): ?>
               <col style="width:130px" />
               <?php endif; ?>
            </colgroup>
            <thead>
               <tr>
                  <th class="text-center">No</th>
                  <th>Kodu</th>
                  <th>Adı</th>
                  <th class="text-right">Miktar</th>
                  <th class="text-right">Fiyat</th>
                  <th class="text-right">Net Fiyat</th>
                  <th class="text-right">
                    <i class="fa-solid fa-turkish-lira-sign mr-1"></i>Toplam (TL)
                  </th>
                  <?php if ($dovizAktif):
                    $dovizIcon = 'fa-dollar-sign';
                    if ($dovizSembol == '€') {
                        $dovizIcon = 'fa-euro-sign';
                    } elseif ($dovizSembol == '£') {
                        $dovizIcon = 'fa-sterling-sign';
                    }
                  ?>
                  <th class="text-right" style="color: rgba(255,255,255,0.9);">
                    <i class="fa-solid <?php echo $dovizIcon; ?> mr-1"></i>Toplam (<?php echo htmlspecialchars((string) $dovizSembol, ENT_QUOTES, 'UTF-8'); ?>)
                  </th>
                  <?php endif; ?>
               </tr>
            </thead>
            <tbody>
               <?php
               $toplam = '';
               $gtoplam = '';
               $gisk = 0;
               $tanimlikilo = 0;
               $tanimlimetrekup = 0;
               $silmes = "Seçtiğiniz ürün silinecek";
               $toplamKoliAdedi = 0;

               // Satırları listele
               $stmtList = $dbh->prepare("
      SELECT
          HRKT.LINENO_,
          HRKT.PRICE,
          HRKT.AMOUNT,
          HRKT.VATMATRAH,
          HRKT.TOTAL,
          HRKT.STOCKREF,
          HRKT.LOGICALREF,
          HRKT.ORDFICHEREF,
          HRKT.LINEEXP,
          STK.CODE AS KODU,
          STK.NAME AS ADI,
          BR.CODE AS BIRIM,
          ui.koli_ici
      FROM {$firmadonem}ORFLINE HRKT
      LEFT JOIN {$firma}ITEMS STK
          ON HRKT.STOCKREF=STK.LOGICALREF
      LEFT JOIN {$firma}UNITSETL BR
          ON HRKT.UOMREF=BR.LOGICALREF
      LEFT JOIN (
          SELECT IA.ITEMREF, MAX(IA.CONVFACT2) AS koli_ici
          FROM {$firma}ITMUNITA IA
          INNER JOIN {$firma}ITEMS IT ON IA.ITEMREF = IT.LOGICALREF
          INNER JOIN {$firma}UNITSETL UL ON IA.UNITLINEREF = UL.LOGICALREF
          WHERE UL.UNITSETREF = IT.UNITSETREF
          GROUP BY IA.ITEMREF
      ) AS ui ON ui.ITEMREF = STK.LOGICALREF
      WHERE HRKT.ORDFICHEREF = :stokhareket AND HRKT.LINETYPE=0
      ORDER BY HRKT.LOGICALREF DESC
   ");
               $stmtList->bindValue(':stokhareket', $stokhareket, PDO::PARAM_INT);
               $stmtList->execute();
               $list = $stmtList;

               // Toplam, iskonto, kdv - TEK SORGUDA (3 sorgu yerine 1)
               $stmtToplamlar = $dbh->prepare("
      SELECT
          SUM(VATAMNT) AS KDVTUTAR,
          SUM(TOTAL) AS TUTAR,
          SUM(DISTDISC) AS ISK
      FROM {$firmadonem}ORFLINE
      WHERE ORDFICHEREF = :stokhareket AND LINETYPE=0
   ");
               $stmtToplamlar->execute([':stokhareket' => $stokhareket]);
               $toplamlar = $stmtToplamlar->fetch(PDO::FETCH_ASSOC);

               $bakiyk = isset($toplamlar['KDVTUTAR']) ? round((float)$toplamlar['KDVTUTAR'], 2) : 0;
               $bakiye_toplam = isset($toplamlar['TUTAR']) ? round((float)$toplamlar['TUTAR'], 2) : 0;
               $bakiskoto = isset($toplamlar['ISK']) ? round((float)$toplamlar['ISK'], 2) : 0;

               $bakiyebrut = round($bakiye_toplam, 2);
               $bakiye = round($bakiyebrut - $bakiskoto, 2);
               $bakiyekdv = round($bakiye + $bakiyk, 2);

               // KDV yüzdesini hesapla (gerçek sipariş KDV'si)
               $siparisKdvYuzde = 0;
               if ($bakiye > 0 && $bakiyk > 0) {
                  $siparisKdvYuzde = ($bakiyk / $bakiye) * 100;
               }

               // İskonto yüzdesini hesapla
               $iskontoYuzdesi = 0;
               if ($bakiye_toplam != 0) {
                  $iskontoYuzdesi = ($bakiskoto / $bakiye_toplam) * 100;
               }
               $indirimOrani = $iskontoYuzdesi;

               // Fiş tablosuna güncelleme
               $stmtGuncelleFiche = $dbh->prepare("
      UPDATE {$firmadonem}ORFICHE
      SET ADDDISCOUNTS=:adddiscounts,
          TOTALDISCOUNTS=:totaldiscounts,
          TOTALDISCOUNTED=:totaldiscounted,
          TOTALVAT=:totalvat,
          GROSSTOTAL=:grosstotal,
          NETTOTAL=:nettotal,
          REPORTNET=:reportnet,
          TRNET=:trnet
      WHERE LOGICALREF=:stokhareket
   ");
               $stmtGuncelleFiche->execute([':adddiscounts' => $bakiskoto, ':totaldiscounts' => $bakiskoto, ':totaldiscounted' => $bakiyebrut, ':totalvat' => $bakiyk, ':grosstotal' => $bakiyebrut, ':nettotal' => $bakiyekdv, ':reportnet' => $bakiyekdv, ':trnet' => $bakiyekdv, ':stokhareket' => $stokhareket]);

               $stmtBakiyex = $dbh->prepare("
      SELECT *
      FROM {$firmadonem}ORFICHE
      WHERE LOGICALREF = :stokhareket
   ");
               $stmtBakiyex->execute([':stokhareket' => $stokhareket]);
               $bakiyex = $stmtBakiyex->fetch(PDO::FETCH_ASSOC) ?: [];

               while ($liste = $list->fetch(PDO::FETCH_ASSOC)) {
                  $stokhareketid = intcevir($liste['LOGICALREF']);
                  $stokhid = intcevir($liste['STOCKREF']);

                  // Koli hesaplama (yazdir/fisyazhtmlfiyatsiz.php ile aynı mantık)
                  $koli_ici = isset($liste['koli_ici']) && $liste['koli_ici'] > 0 ? $liste['koli_ici'] : 1;
                  $koli_say = ceil($liste['AMOUNT'] / $koli_ici);
                  $toplamKoliAdedi += $koli_say;

                  if ($tanimlialantoplam == 1) {
                     $tanimlik = tanimlialantoplam($stokhid);
                     $tanimlikilo += $tanimlik[1];

                     $tanimlikm = tanimlialantoplam($stokhid);
                     $tanimlimetrekup += $tanimlikm[0];
                  }
                  // Net fiyat hesapla
                  $netFiyat = netFiyatHesapla($liste['PRICE'], $indirimOrani);
                  echo '
      <tr>
        <td class="text-center">
          <span class="row-num">' . $liste['LINENO_'] . '</span>
        </td>
        <td>
          <form method="POST" action="?stokhareket=' . $stokhareket . '" style="display:inline;" onsubmit="return confirm(' . htmlspecialchars(json_encode(trcevir($liste['ADI']) . ' ' . $silmes), ENT_QUOTES, 'UTF-8') . ')">
            ' . csrf_field() . '
            <input type="hidden" name="hareketsil" value="' . $stokhareketid . '">
            <input type="hidden" name="stokhareket" value="' . $stokhareket . '">
            <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-family:Montserrat,sans-serif;font-size:12.5px;font-weight:600;color:var(--red,#ef4444);display:inline-flex;align-items:center;gap:4px;">
              <i class="fa fa-trash-alt" style="font-size:11px;"></i>' . e_tr($liste['KODU']) . '
            </button>
          </form>
        </td>
        <td class="name-cell" style="max-width:300px;" title="' . e_tr($liste['LINEEXP']) . '">
          <a style="text-decoration:none;color:var(--text-1);font-weight:600;display:flex;align-items:center;gap:6px;font-size:13px;" href="../stok/lg_stok_duzenle.php?stokid=' . $stokhid . '&stokhareket=' . $stokhareket . '">
            <i class="fa fa-edit" style="font-size:11px;color:var(--text-3);"></i>
            <span style="overflow:hidden;text-overflow:ellipsis;">' . e_tr($liste['ADI']) . '</span>
          </a>
          ' . ($liste['LINEEXP'] ? '<div style="margin-top:3px;"><small style="font-size:10.5px;color:var(--text-3);"><i class="fa fa-comment mr-1"></i>' . e_tr($liste['LINEEXP']) . '</small></div>' : '') . '
        </td>
        <td class="text-right num">
          <div style="font-weight:700;font-size:13.5px;">' . (m_p_yetki($terminalkullanici, 'ST1') == 1 ? kusuratsifir($liste['AMOUNT']) : '<i class="fa fa-lock" style="color:var(--text-3);"></i> ***') . '</div>
          <span class="unit-badge">' . e_tr($liste['BIRIM']) . '</span>
        </td>
        <td class="text-right num" style="font-weight:600;">' . (m_p_yetki($terminalkullanici, 'ST2') == 1 ? paraformat_lg_fis($liste['PRICE']) : '<i class="fa fa-lock" style="color:var(--text-3);"></i> ***') . '</td>
        <td class="text-right num" style="color:#059669;font-weight:600;">' . (m_p_yetki($terminalkullanici, 'ST2') == 1 ? paraformat_lg_fis($netFiyat) : '<i class="fa fa-lock" style="color:var(--text-3);"></i> ***') . '</td>
        <td class="text-right num" style="font-size:14px;font-weight:700;color:#166534;">' . (m_p_yetki($terminalkullanici, 'ST2') == 1 ? paraformat_lg_fis($liste['TOTAL']) : '<i class="fa fa-lock" style="color:var(--text-3);"></i> ***') . '</td>';
                  if ($dovizAktif && m_p_yetki($terminalkullanici, 'ST2') == 1) {
                     $toplamDoviz = $liste['TOTAL'] / $dovizKuru;
                     echo '<td class="text-right num" style="font-size:14px;font-weight:700;color:#2563eb;">' . paraformat_doviz_lg_fis($toplamDoviz, $dovizSembol) . '</td>';
                  } elseif ($dovizAktif) {
                     echo '<td class="text-right num"><i class="fa fa-lock" style="color:var(--text-3);"></i> ***</td>';
                  }
                  echo '</tr>';
               }
               ?>
            </tbody>
         </table>
      </div>

      <!-- ═══════════ ISKONTO QUERIES ═══════════ -->
      <?php
      $stmtIsk1 = $dbh->prepare("
      SELECT TOP 1 LOGICALREF, DISCPER, LINENO_
      FROM {$firmadonem}ORFLINE
      WHERE ORDFICHEREF = :stokhareket
        AND LINETYPE=2
      ORDER BY LINENO_ ASC
   ");
      $stmtIsk1->execute([':stokhareket' => $stokhareket]);
      $iskvarmic1 = $stmtIsk1->fetch(PDO::FETCH_ASSOC) ?: [];

      $lineno = $iskvarmic1['LINENO_'] ?? 0;

      $stmtIsk2 = $dbh->prepare("
      SELECT TOP 1 LOGICALREF, DISCPER
      FROM {$firmadonem}ORFLINE
      WHERE ORDFICHEREF = :stokhareket
        AND LINETYPE=2
        AND LINENO_ > :lineno
      ORDER BY LINENO_
   ");
      $stmtIsk2->execute([':stokhareket' => $stokhareket, ':lineno' => (int)$lineno]);
      $iskvarmic2 = $stmtIsk2->fetch(PDO::FETCH_ASSOC) ?: [];

      $iskvr1 = $iskvarmic1['DISCPER'] ?? null;
      $iskvr2 = $iskvarmic2['DISCPER'] ?? null;

      // Ikinci iskonto varsa yazdir/yeni_dizayn.php icin session'a kaydet
      if ($iskvr2 !== null && (float) $iskvr2 > 0) {
         setPageParam('iskonto2_var', 1, 'yeni_dizayn');
      } else {
         setPageParam('iskonto2_var', 0, 'yeni_dizayn');
      }
      ?>

      <!-- ═══════════ SUMMARY SECTION ═══════════ -->
      <div style="margin-top: 16px; display: flex; flex-wrap: wrap;">
         <?php
            // Hacim ve ağırlık hesapla
            $hacimAgirlik = fis_hacim_agirlik_hesapla($stokhareket, 'siparis');
            ?>
         <div style="width: 100%; max-width: 480px; margin-left: auto;">
            <div class="glass-card summary-panel">
               <div class="glass-card-body">
                  <!-- Title -->
                  <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #f3f4f6;">
                     <i class="fa-solid fa-calculator" style="color: var(--red,#ef4444); font-size: 18px;"></i>
                     <h5 style="font-size: 16px; font-weight: 700; color: var(--text-1); margin: 0;">Sipariş Özeti</h5>
                  </div>

                  <!-- Metrics List -->
                  <!-- Toplam Koli -->
                  <div class="metric-row">
                     <span class="metric-label">
                        <i class="fa-solid fa-box-open" style="color: #f59e0b;"></i>Toplam Koli
                     </span>
                     <span class="metric-value"><?php echo number_format($toplamKoliAdedi, 0, ',', '.'); ?> Koli</span>
                  </div>

                  <!-- Toplam Hacim -->
                  <?php if ($hacimAgirlik['hacim'] > 0): ?>
                  <div class="metric-row">
                     <span class="metric-label">
                        <i class="fa-solid fa-cube" style="color: #3b82f6;"></i>Toplam Hacim
                     </span>
                     <span class="metric-value" style="color: #2563eb;"><?php echo $hacimAgirlik['hacim_str']; ?></span>
                  </div>
                  <?php endif; ?>

                  <!-- Toplam Ağırlık -->
                  <?php if ($hacimAgirlik['agirlik'] > 0): ?>
                  <div class="metric-row">
                     <span class="metric-label">
                        <i class="fa-solid fa-gauge-high" style="color: #8b5cf6;"></i>Toplam Ağırlık
                     </span>
                     <span class="metric-value" style="color: #7c3aed;"><?php echo $hacimAgirlik['agirlik_str']; ?></span>
                  </div>
                  <?php endif; ?>

                  <!-- Ara Toplam -->
                  <div class="metric-row">
                     <span class="metric-label">
                        <i class="fa-solid fa-turkish-lira-sign" style="color: #059669;"></i>Ara Toplam (TL)
                     </span>
                     <span class="metric-value" style="color: #166534;"><?php echo paraformat_lg_fis($bakiye_toplam); ?></span>
                  </div>

                  <!-- İskonto -->
                  <div class="metric-row">
                     <span class="metric-label">
                        <i class="fa-solid fa-percent" style="color: var(--red,#ef4444);"></i>İskonto
                        <?php
                        // Iskonto satirlarindaki gercek oranlari (DISCPER) goster - kademeli iskonto icin
                        $isk1Oran = (float)($iskvarmic1['DISCPER'] ?? 0);
                        $isk2Oran = (float)($iskvarmic2['DISCPER'] ?? 0);
                        $oranParcalari = [];
                        if ($isk1Oran > 0) {
                           $oranParcalari[] = '%' . rtrim(rtrim(number_format($isk1Oran, 2, ',', '.'), '0'), ',');
                        }
                        if ($isk2Oran > 0) {
                           $oranParcalari[] = '%' . rtrim(rtrim(number_format($isk2Oran, 2, ',', '.'), '0'), ',');
                        }
                        if (!empty($oranParcalari)) {
                           echo '<span style="font-size:11px;color:var(--text-3);">(' . implode(' + ', $oranParcalari) . ')</span>';
                        }
                        ?>
                     </span>
                     <span class="metric-value" style="color: var(--red,#6F1022);">-<?php echo paraformat_lg_fis($bakiskoto); ?></span>
                  </div>

                  <!-- KDV -->
                  <div class="metric-row">
                     <span class="metric-label">
                        <i class="fa-solid fa-receipt" style="color: #059669;"></i>KDV
                        <?php
                        if ($siparisKdvYuzde > 0) {
                           echo '<span style="font-size:11px;color:var(--text-3);">(%' . number_format($siparisKdvYuzde, 0) . ')</span>';
                        }
                        ?>
                     </span>
                     <span class="metric-value" style="color: #059669;">+<?php echo paraformat_lg_fis($bakiyk); ?></span>
                  </div>

                  <!-- Divider -->
                  <hr class="metric-divider" />

                  <!-- Genel Toplam -->
                  <div class="metric-row">
                     <span class="metric-label" style="font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; font-size: 12px;">
                        <i class="fa-solid fa-turkish-lira-sign" style="color: #059669;"></i>Genel Toplam (TL)
                     </span>
                     <span class="grand-total-value"><?php echo paraformat_lg_fis($bakiyekdv); ?></span>
                  </div>

                  <?php if ($dovizAktif):
                     $dovizIcon = 'fa-dollar-sign';
                     if ($dovizSembol == '€') {
                         $dovizIcon = 'fa-euro-sign';
                     } elseif ($dovizSembol == '£') {
                         $dovizIcon = 'fa-sterling-sign';
                     }
                  ?>
                        <!-- Döviz Karşılığı -->
                        <hr class="metric-divider" />

                        <div class="metric-row">
                           <span class="metric-label">
                              <i class="fa-solid <?php echo $dovizIcon; ?>" style="color: #2563eb;"></i>Ara Toplam (<?php echo htmlspecialchars((string) $dovizSembol, ENT_QUOTES, 'UTF-8'); ?>)
                           </span>
                           <span class="metric-value" style="color: #2563eb;"><?php echo paraformat_doviz_lg_fis($bakiye_toplam / $dovizKuru, $dovizSembol); ?></span>
                        </div>

                        <div class="metric-row">
                           <span class="metric-label">
                              <i class="fa-solid fa-percent" style="color: var(--red,#ef4444);"></i>İskonto (<?php echo htmlspecialchars((string) $dovizSembol, ENT_QUOTES, 'UTF-8'); ?>)
                           </span>
                           <span class="metric-value" style="color: var(--red,#6F1022);">-<?php echo paraformat_doviz_lg_fis($bakiskoto / $dovizKuru, $dovizSembol); ?></span>
                        </div>

                        <div class="metric-row">
                           <span class="metric-label">
                              <i class="fa-solid fa-receipt" style="color: #059669;"></i>KDV (<?php echo htmlspecialchars((string) $dovizSembol, ENT_QUOTES, 'UTF-8'); ?>)
                           </span>
                           <span class="metric-value" style="color: #059669;">+<?php echo paraformat_doviz_lg_fis($bakiyk / $dovizKuru, $dovizSembol); ?></span>
                        </div>

                        <hr class="metric-divider" />

                        <div class="metric-row">
                           <span class="metric-label" style="font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; font-size: 12px;">
                              <i class="fa-solid <?php echo $dovizIcon; ?>" style="color: #2563eb;"></i>Genel Toplam (<?php echo htmlspecialchars((string) $dovizSembol, ENT_QUOTES, 'UTF-8'); ?>)
                           </span>
                           <span class="grand-total-value" style="color: #2563eb;"><?php echo paraformat_doviz_lg_fis($bakiyekdv / $dovizKuru, $dovizSembol); ?></span>
                        </div>
                  <?php endif; ?>

                  <?php if ($topluiskonto == 1): ?>
                     <div class="settings-section">
                        <div class="settings-title">
                           <i class="fa-solid fa-percent"></i>
                           <span>İskonto Ayarları</span>
                        </div>

                        <?php
                        // Hızlı iskonto kısayolları — _bilgi_.inc: $hizli_iskontolar (ayar/hizli_iskonto.php'den yönetilir)
                        $hizli_iskontolar = (isset($hizli_iskontolar) && is_array($hizli_iskontolar)) ? $hizli_iskontolar : [];
                        if ($hizli_iskontolar):
                        ?>
                        <div style="display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
                           <?php foreach ($hizli_iskontolar as $hi):
                              $hiAd = trim((string) ($hi['ad'] ?? ''));
                              $hiOran = (float) ($hi['oran'] ?? 0);
                              if ($hiAd === '' || $hiOran <= 0) { continue; }
                              $hiOranStr = rtrim(rtrim(number_format($hiOran, 2, '.', ''), '0'), '.');
                           ?>
                           <button type="button" class="btn-flat btn-red btn-sm hizli-isk-btn" data-oran="<?php echo $hiOran; ?>" title="<?php echo htmlspecialchars($hiAd, ENT_QUOTES, 'UTF-8'); ?>: %<?php echo $hiOranStr; ?> iskonto uygula">
                              <i class="fa-solid fa-percent"></i><span class="hidden sm:inline"><?php echo htmlspecialchars($hiAd, ENT_QUOTES, 'UTF-8'); ?> %<?php echo $hiOranStr; ?></span>
                           </button>
                           <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- İSK1 -->
                        <div style="margin-bottom: 12px;">
                           <?php if ($iskvr1): ?>
                              <form action="iskonto.php" method="POST" class="iskonto-form" style="display: flex; align-items: center; gap: 8px;">
                                 <?php echo csrf_field(); ?>
                                 <div class="isk-input-group">
                                    <span class="isk-label">İSK1</span>
                                    <input type="text" name="isk1b" id="isk1b"
                                       inputmode="decimal" aria-label="1. Iskonto Yuzdesi"
                                       value="<?php echo intcevir($iskvarmic1['DISCPER'] ?? 0); ?>" />
                                    <span class="isk-suffix">%</span>
                                 </div>
                                 <input type="hidden" name="isk2bx" value="<?php echo intcevir($iskvarmic2['DISCPER'] ?? 0); ?>" />
                                 <input type="hidden" name="iskidno" value="<?php echo intcevir($iskvarmic1['LOGICALREF'] ?? 0); ?>" />
                                 <input type="hidden" name="stokhareket" value="<?php echo $stokhareket; ?>" />
                                 <input type="hidden" name="cari" value="<?php echo intcevir($bakiyex['CLIENTREF'] ?? 0); ?>" />
                                 <button type="submit" class="btn-flat btn-indigo btn-sm">
                                    <i class="fa-solid fa-circle-check"></i>Güncelle
                                 </button>
                              </form>
                           <?php else: ?>
                              <form action="iskonto.php" method="POST" class="iskonto-form" style="display: flex; align-items: center; gap: 8px;">
                                 <?php echo csrf_field(); ?>
                                 <div class="isk-input-group">
                                    <span class="isk-label">İSK1</span>
                                    <input type="text" name="isk1" id="isk1" inputmode="decimal" aria-label="1. Iskonto Yuzdesi" value="0" />
                                    <span class="isk-suffix">%</span>
                                 </div>
                                 <input type="hidden" name="stokhareket" value="<?php echo $stokhareket; ?>" />
                                 <input type="hidden" name="cari" value="<?php echo intcevir($bakiyex['CLIENTREF'] ?? 0); ?>" />
                                 <button type="submit" class="btn-flat btn-indigo btn-sm">
                                    <i class="fa-solid fa-circle-plus"></i>Ekle
                                 </button>
                              </form>
                           <?php endif; ?>
                        </div>

                        <!-- İSK2 -->
                        <div style="margin-bottom: 12px;">
                           <?php if ($iskvr2): ?>
                              <form action="iskonto.php" method="POST" class="iskonto-form" style="display: flex; align-items: center; gap: 8px;">
                                 <?php echo csrf_field(); ?>
                                 <div class="isk-input-group">
                                    <span class="isk-label">İSK2</span>
                                    <input type="text" name="isk2b" id="isk2b"
                                       inputmode="decimal" aria-label="2. Iskonto Yuzdesi"
                                       value="<?php echo intcevir($iskvarmic2['DISCPER'] ?? 0); ?>" />
                                    <span class="isk-suffix">%</span>
                                 </div>
                                 <input type="hidden" name="isk1bx" value="<?php echo intcevir($iskvarmic1['DISCPER'] ?? 0); ?>" />
                                 <input type="hidden" name="iskidno2" value="<?php echo intcevir($iskvarmic2['LOGICALREF'] ?? 0); ?>" />
                                 <input type="hidden" name="stokhareket" value="<?php echo $stokhareket; ?>" />
                                 <input type="hidden" name="cari" value="<?php echo intcevir($bakiyex['CLIENTREF'] ?? 0); ?>" />
                                 <button type="submit" class="btn-flat btn-indigo btn-sm">
                                    <i class="fa-solid fa-circle-check"></i>Güncelle
                                 </button>
                              </form>
                           <?php else: ?>
                              <form action="iskonto.php" method="POST" class="iskonto-form" style="display: flex; align-items: center; gap: 8px;">
                                 <?php echo csrf_field(); ?>
                                 <div class="isk-input-group">
                                    <span class="isk-label">İSK2</span>
                                    <input type="text" name="isk2" id="isk2" inputmode="decimal" aria-label="2. Iskonto Yuzdesi" value="0" />
                                    <span class="isk-suffix">%</span>
                                 </div>
                                 <input type="hidden" name="stokhareket" value="<?php echo $stokhareket; ?>" />
                                 <input type="hidden" name="cari" value="<?php echo intcevir($bakiyex['CLIENTREF'] ?? 0); ?>" />
                                 <button type="submit" class="btn-flat btn-indigo btn-sm">
                                    <i class="fa-solid fa-circle-plus"></i>Ekle
                                 </button>
                              </form>
                           <?php endif; ?>
                        </div>
                     </div>
                  <?php endif; ?>

                  <!-- KDV İptal -->
                  <?php if ($toplukdv == 1): ?>
                     <div class="settings-section">
                        <div class="settings-title">
                           <i class="fa-solid fa-receipt"></i>
                           <span>KDV Ayarları</span>
                        </div>
                        <?php if ($resmiKdvZorunlu): ?>
                           <div style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 8px; border: 1px solid rgba(16,185,129,0.3); background: #ecfdf5; color: #065f46; font-size: 13px; font-weight: 600;">
                              <i class="fa-solid fa-shield-check"></i>
                              <span>Resmi modda KDV sabit: %<?php echo rtrim(rtrim(number_format($resmiKdvOrani, 2, '.', ''), '0'), '.'); ?></span>
                           </div>
                        <?php else: ?>
                           <?php
                              // Mevcut KDV oranini satirlardan dogrudan cek (iskonto inputu gibi)
                              $mevcutKdvOrani = 0.0;
                              try {
                                  $stmtKdvOran = $dbh->prepare("SELECT MAX(VAT) AS KDV FROM {$firmadonem}ORFLINE WHERE ORDFICHEREF = :ref AND LINETYPE = 0");
                                  $stmtKdvOran->bindValue(':ref', $stokhareket, PDO::PARAM_INT);
                                  $stmtKdvOran->execute();
                                  $kdvRow = $stmtKdvOran->fetch(PDO::FETCH_ASSOC);
                                  $mevcutKdvOrani = (float)($kdvRow['KDV'] ?? 0);
                              } catch (Throwable $e) {}
                              $kdvInputValue = $mevcutKdvOrani > 0 ? rtrim(rtrim(number_format($mevcutKdvOrani, 2, '.', ''), '0'), '.') : '0';
                              $kdvButtonAktif = $mevcutKdvOrani > 0;
                           ?>
                           <form action="" method="POST" class="kdv-form" style="display: flex; align-items: center; gap: 8px;">
                              <?php echo csrf_field(); ?>
                              <div class="isk-input-group">
                                 <span class="isk-label">KDV</span>
                                 <input type="text" name="kdviptal" id="kdviptal" inputmode="decimal" aria-label="KDV Yuzdesi" value="<?php echo $kdvInputValue; ?>" />
                                 <span class="isk-suffix">%</span>
                              </div>
                              <input type="hidden" name="stokhareket" value="<?php echo $stokhareket; ?>" />
                              <button type="submit" class="btn-flat <?php echo $kdvButtonAktif ? 'btn-indigo' : 'btn-red'; ?> btn-sm">
                                 <i class="fa-solid <?php echo $kdvButtonAktif ? 'fa-circle-check' : 'fa-rotate'; ?>"></i><?php echo $kdvButtonAktif ? 'Güncelle' : 'Uygula'; ?>
                              </button>
                           </form>
                        <?php endif; ?>
                     </div>
                  <?php endif; ?>

               </div>
            </div>
         </div>
      </div>
   </div>

   <!-- ═══════════ JAVASCRIPT ═══════════ -->
   <script>
      // Animasyon render bug fix: backdrop-filter + animation bazen
      // initial render'da tetiklenmiyor. Force reflow tetikliyoruz.
      (function() {
         function forceReflow() {
            document.querySelectorAll('.glass-card, .beta-table-wrap, .summary-panel').forEach(function(el) {
               void el.offsetHeight;
            });
         }
         if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() { requestAnimationFrame(forceReflow); });
         } else {
            requestAnimationFrame(forceReflow);
         }
      })();

      // ========== ISKONTO AJAX (sayfa yenilemeden) ==========
      (function() {
         function showIskontoToast(msg, type) {
            var toast = document.createElement('div');
            toast.textContent = msg;
            toast.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(20px);' +
               'padding:12px 20px;border-radius:10px;font-family:Montserrat,sans-serif;font-size:13px;font-weight:600;' +
               'color:#fff;z-index:9999;opacity:0;transition:all 0.3s cubic-bezier(0.22,1,0.36,1);' +
               'box-shadow:0 8px 24px rgba(0,0,0,0.18);' +
               'background:' + (type === 'error' ? '#6F1022' : '#059669') + ';';
            document.body.appendChild(toast);
            requestAnimationFrame(function() {
               toast.style.opacity = '1';
               toast.style.transform = 'translateX(-50%) translateY(0)';
            });
            setTimeout(function() {
               toast.style.opacity = '0';
               toast.style.transform = 'translateX(-50%) translateY(20px)';
               setTimeout(function() { toast.remove(); }, 320);
            }, 2500);
         }

         // Sayfayi tekrar fetch edip sadece ilgili bolumleri guncelle
         function refreshIskontoBolumu() {
            var scrollY = window.scrollY;
            return fetch(window.location.href, {
               credentials: 'same-origin',
               headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
               .then(function(r) { return r.text(); })
               .then(function(html) {
                  var parser = new DOMParser();
                  var doc = parser.parseFromString(html, 'text/html');

                  // Guncellenecek bolumleri replace et
                  var selectors = [
                     '.settings-section',       // iskonto + kdv ayarlari
                     '.summary-panel',           // ozet panel (grand total, iskonto, kdv)
                     '.beta-table-wrap',         // urun tablosu (linenet degerleri degisir)
                     '.glass-card[style*="z-index:30"]' // chip/summary kartlari (musteri + toplam)
                  ];
                  selectors.forEach(function(sel) {
                     var oldEls = document.querySelectorAll(sel);
                     var newEls = doc.querySelectorAll(sel);
                     oldEls.forEach(function(oldEl, i) {
                        if (newEls[i]) {
                           oldEl.innerHTML = newEls[i].innerHTML;
                        }
                     });
                  });

                  // Scroll pozisyonunu koru
                  window.scrollTo(0, scrollY);
               });
         }

         document.addEventListener('submit', function(e) {
            var form = e.target;
            var isIskonto = form.classList && form.classList.contains('iskonto-form');
            var isKdv = form.classList && form.classList.contains('kdv-form');
            if (!isIskonto && !isKdv) return;

            e.preventDefault();

            var submitBtn = form.querySelector('button[type="submit"]');
            var originalBtnHtml = submitBtn ? submitBtn.innerHTML : '';
            if (submitBtn) {
               submitBtn.disabled = true;
               submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
            }

            var fd = new FormData(form);
            fd.append('ajax', '1');

            // Iskonto -> iskonto.php, KDV -> mevcut sayfa (lg_fis.php POST handler)
            var endpoint = isIskonto ? 'iskonto.php' : window.location.pathname + window.location.search;

            fetch(endpoint, {
               method: 'POST',
               body: fd,
               credentials: 'same-origin',
               headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
               .then(function(r) {
                  var ct = r.headers.get('Content-Type') || '';
                  if (ct.indexOf('application/json') !== -1) {
                     return r.json();
                  }
                  return { ok: false, msg: 'Beklenmedik sunucu cevabi' };
               })
               .then(function(data) {
                  if (data && data.ok) {
                     showIskontoToast(data.msg || 'Uygulandi', 'success');
                     return refreshIskontoBolumu();
                  } else {
                     showIskontoToast((data && data.msg) || 'Hata olustu', 'error');
                  }
               })
               .catch(function(err) {
                  console.error('Form AJAX hatasi:', err);
                  showIskontoToast('Baglanti hatasi', 'error');
               })
               .finally(function() {
                  if (submitBtn) {
                     submitBtn.disabled = false;
                     submitBtn.innerHTML = originalBtnHtml;
                  }
               });
         });
      })();

      // ========== HIZLI YAZDIR + HIZLI ISKONTO (jQuery'den BAGIMSIZ, garanti calisir) ==========
      // jQuery CDN'i yuklenemezse $(function) bloklari calismaz; bu iki kritik islevi
      // bu yuzden saf JS + document delegation ile yaziyoruz.
      (function() {
         function fpEscapeHtml(text) {
            if (text === null || text === undefined) return '';
            var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
         }
         function fpShowToast(message, variant) {
            var toastEl = document.getElementById('fpToast');
            var bodyEl = document.getElementById('fpToastBody');
            if (!toastEl || !bodyEl) { alert(String(message).replace(/<[^>]+>/g, '')); return; }
            toastEl.classList.remove('hidden', 'bg-green-600', 'bg-red-600', 'bg-amber-600');
            toastEl.classList.add(variant === 'danger' ? 'bg-red-600' : (variant === 'warning' ? 'bg-amber-600' : 'bg-green-600'));
            bodyEl.innerHTML = message;
            if (window.fpToastTimer) { clearTimeout(window.fpToastTimer); }
            window.fpToastTimer = setTimeout(function() { toastEl.classList.add('hidden'); }, 2200);
         }
         // "Hizli Yazdir": fisi yazici kuyruguna (mobilyazlogo.exe) ekler.
         function fastPrint(btn) {
            if (btn.getAttribute('data-busy') === '1') return;
            btn.setAttribute('data-busy', '1');
            var originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
            var url = btn.getAttribute('data-url');
            if (!url) { fpShowToast('<i class="fa fa-triangle-exclamation"></i> Gecersiz istek.', 'danger'); btn.removeAttribute('data-busy'); btn.innerHTML = originalHTML; return; }
            if (url.indexOf('ajax=1') === -1) { url += (url.indexOf('?') > -1 ? '&' : '?') + 'ajax=1'; }
            url += (url.indexOf('?') > -1 ? '&' : '?') + '_ts=' + (new Date().getTime());
            fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
               .then(function(res) { return res.text().then(function(t) { return { status: res.status, text: t }; }); })
               .then(function(resp) {
                  var data = null; try { data = JSON.parse(resp.text); } catch (e) {}
                  if ((data && data.ok) || resp.status === 200) {
                     btn.innerHTML = '<i class="fa fa-check-circle"></i>';
                     fpShowToast('<i class="fa fa-check-circle"></i> Yazdirma kuyruguna eklendi.', 'success');
                  } else {
                     fpShowToast('<i class="fa fa-triangle-exclamation"></i> Eklenemedi. ' + fpEscapeHtml((resp.text || '').slice(0, 120)), 'danger');
                  }
               })
               .catch(function(err) { console.error(err); fpShowToast('<i class="fa fa-triangle-exclamation"></i> Baglanti/yanit hatasi.', 'danger'); })
               .finally(function() { setTimeout(function() { btn.removeAttribute('data-busy'); btn.innerHTML = originalHTML; }, 1200); });
         }
         // "Nakit %30 / Kart %20": ISK1 alanina orani yazip mevcut iskonto formunu gonderir.
         function hizliIskonto(btn) {
            var oran = btn.getAttribute('data-oran');
            var inp = document.getElementById('isk1b') || document.getElementById('isk1');
            if (!inp) { fpShowToast('Iskonto alani gorunur degil.', 'warning'); return; }
            inp.value = oran;
            var form = inp.closest('form.iskonto-form');
            if (form) {
               if (typeof form.requestSubmit === 'function') { form.requestSubmit(); }
               else { form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); }
            }
         }
         document.addEventListener('click', function(e) {
            if (!e.target || !e.target.closest) return;
            var fp = e.target.closest('#btnFastPrint');
            if (fp) { e.preventDefault(); fastPrint(fp); return; }
            var hi = e.target.closest('.hizli-isk-btn');
            if (hi) { e.preventDefault(); hizliIskonto(hi); return; }
            var tc = e.target.closest('#fpToastClose');
            if (tc) { var el = document.getElementById('fpToast'); if (el) { el.classList.add('hidden'); } return; }
         });
      })();

      $(function() {
         // Ürünü ekle: tekleştirilmiş sınıf, delegation
         $(document).on('click', '.btn-add-oneri, .btn-ekle-oneri', function() {
            var stokKodu = $(this).data('stok-kodu');
            $('#barkod').val(stokKodu).trigger('input');
            $('form[name="form"]').trigger('submit');
         });

         // (Hizli iskonto butonlari ve Hizli Yazdir artik yukaridaki saf-JS blogunda islenir)

         var toastCloseBtn = document.getElementById('fpToastClose');
         if (toastCloseBtn) {
            toastCloseBtn.addEventListener('click', function() {
               var toastEl = document.getElementById('fpToast');
               if (toastEl) {
                  toastEl.classList.add('hidden');
               }
            });
         }
      });
   </script>

   <!-- Toast Notification -->
   <div style="position: fixed; top: 0; right: 0; padding: 12px; z-index: 1080;">
      <div id="fpToast" class="hidden" style="min-width: 280px; max-width: 360px; border-radius: 12px; color: #fff; box-shadow: 0 14px 34px -18px rgba(15,23,42,0.6); overflow: hidden;" role="status" aria-live="polite" aria-atomic="true">
         <div style="display: flex;">
            <div id="fpToastBody" style="padding: 12px 16px; font-size: 13px; line-height: 1.5;"></div>
            <button type="button" id="fpToastClose" style="margin-left: 8px; margin: auto 8px auto 0; padding: 4px; color: rgba(255,255,255,0.9); background: none; border: none; cursor: pointer; font-size: 14px;" aria-label="Kapat">
               <i class="fa fa-times"></i>
            </button>
         </div>
      </div>
   </div>

   <?php if (m_p_yetki($terminalkullanici, 'M12') == 1): ?>
   <!-- Yeni Cari Olustur & Fise Aktar -->
   <div id="ycaModal" class="yca-overlay">
      <div class="yca-dialog">
         <div class="yca-head">
            <h3><i class="fa-solid fa-user-plus" style="color:#10b981;"></i> Yeni Cari Aç &amp; Fişe Aktar</h3>
            <button type="button" onclick="ycaKapat()" class="yca-x" aria-label="Kapat">&times;</button>
         </div>
         <div class="yca-body">
            <div>
               <label class="yca-label">Cari Unvanı *</label>
               <input id="ycaUnvan" type="text" class="yca-input" placeholder="Firma / kişi adı" maxlength="200">
            </div>
            <div>
               <label class="yca-label">Telefon</label>
               <input id="ycaTel" type="tel" class="yca-input" placeholder="05xx..." maxlength="30">
            </div>
            <div style="display:flex;gap:10px;">
               <div style="flex:1;">
                  <label class="yca-label">Şehir</label>
                  <input id="ycaSehir" type="text" class="yca-input" maxlength="30">
               </div>
               <div style="flex:1;">
                  <label class="yca-label">İlçe</label>
                  <input id="ycaIlce" type="text" class="yca-input" maxlength="30">
               </div>
            </div>
            <div id="ycaHata" class="yca-hata"></div>
            <p style="font-size:11.5px;color:#9ca3af;margin:0;">Cari kodu, ayarlardaki ön eke göre otomatik atanır; bu fiş yeni cariye aktarılır.</p>
         </div>
         <div class="yca-foot">
            <button type="button" onclick="ycaKapat()" class="yca-btn yca-btn-iptal">İptal</button>
            <button type="button" id="ycaKaydet" onclick="ycaGonder()" class="yca-btn yca-btn-kaydet"><i class="fa-solid fa-check"></i> Oluştur &amp; Aktar</button>
         </div>
      </div>
   </div>
   <style>
      .yca-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.45); z-index:9999; align-items:center; justify-content:center; padding:16px; }
      .yca-overlay.acik { display:flex; }
      .yca-dialog { background:#fff; border-radius:16px; max-width:440px; width:100%; box-shadow:0 20px 50px rgba(0,0,0,0.25); overflow:hidden; font-family:'Avenir Next','Montserrat',sans-serif; }
      .yca-head { padding:15px 18px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; }
      .yca-head h3 { font-size:15px; font-weight:700; color:#1f2937; display:flex; align-items:center; gap:8px; margin:0; }
      .yca-x { background:none; border:none; font-size:24px; line-height:1; color:#6b7280; cursor:pointer; }
      .yca-body { padding:18px; display:flex; flex-direction:column; gap:13px; }
      .yca-label { font-size:12px; font-weight:600; color:#6b7280; display:block; margin-bottom:5px; }
      .yca-input { width:100%; padding:11px 13px; font-family:'Avenir Next','Montserrat',sans-serif; font-size:16px; color:#1f2937; background:#fff; border:1px solid #e5e7eb; border-radius:10px; outline:none; }
      .yca-input:focus { border-color:rgba(16,185,129,0.5); box-shadow:0 0 0 3px rgba(16,185,129,0.12); }
      .yca-hata { display:none; color:var(--red,#6F1022); font-size:13px; font-weight:600; }
      .yca-foot { padding:13px 18px; border-top:1px solid #e5e7eb; background:#fafafa; display:flex; gap:8px; justify-content:flex-end; }
      .yca-btn { padding:11px 18px; border-radius:10px; font-family:'Avenir Next','Montserrat',sans-serif; font-size:13.5px; font-weight:600; cursor:pointer; border:1px solid transparent; }
      .yca-btn-iptal { background:#fff; color:#6b7280; border-color:#e5e7eb; }
      .yca-btn-kaydet { background:#10b981; color:#fff; }
      .yca-btn-kaydet:disabled { opacity:0.6; cursor:not-allowed; }
   </style>
   <script>
      (function(){
         var YCA_STOK = <?php echo (int) $stokhareket; ?>;
         var YCA_CSRF = <?php echo json_encode(function_exists('csrf_token') ? csrf_token() : '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
         window.ycaAc = function(){ document.getElementById('ycaModal').classList.add('acik'); setTimeout(function(){ var u=document.getElementById('ycaUnvan'); if(u){ u.focus(); } }, 60); };
         window.ycaKapat = function(){ document.getElementById('ycaModal').classList.remove('acik'); };
         window.ycaGonder = function(){
            var unvan = document.getElementById('ycaUnvan').value.trim();
            var hata = document.getElementById('ycaHata');
            hata.style.display = 'none';
            if(!unvan){ hata.textContent = 'Cari unvanı zorunludur.'; hata.style.display = 'block'; return; }
            var btn = document.getElementById('ycaKaydet'); var eski = btn.innerHTML; btn.disabled = true; btn.innerHTML = 'Oluşturuluyor...';
            var fd = new FormData();
            fd.append('stokhareket', YCA_STOK);
            fd.append('unvan', unvan);
            fd.append('telefon', document.getElementById('ycaTel').value.trim());
            fd.append('sehir', document.getElementById('ycaSehir').value.trim());
            fd.append('ilce', document.getElementById('ycaIlce').value.trim());
            fd.append('csrf_token', YCA_CSRF);
            fetch('../cari/cari_olustur_ve_aktar.php', { method:'POST', body:fd, credentials:'same-origin' })
               .then(function(r){ return r.json(); })
               .then(function(j){
                  if(j.ok){ location.reload(); }
                  else { hata.textContent = j.mesaj || 'Hata oluştu.'; hata.style.display = 'block'; btn.disabled = false; btn.innerHTML = eski; }
               })
               .catch(function(){ hata.textContent = 'Bağlantı hatası.'; hata.style.display = 'block'; btn.disabled = false; btn.innerHTML = eski; });
         };
         var ov = document.getElementById('ycaModal');
         if(ov){ ov.addEventListener('click', function(e){ if(e.target === this){ window.ycaKapat(); } }); }
      })();
   </script>
   <?php endif; ?>

</body>

</html>
