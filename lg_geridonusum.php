<?php
declare(strict_types=1);

include_once(__DIR__ . "/ayr.php");
include(__DIR__ . "/kontrol.php");
include_once(__DIR__ . "/log_ip.php");

/**
 * NOT: Bu sayfa; ORFICHE'de NETTOTAL=0 olan, henüz ORFLINE ile eşleşmemiş (ORDFICHEREF),
 *     SALESMANREF = terminal kullanıcısı olan taslak siparişleri listeler.
 *     Özellikler: Canlı arama, kolon sıralama, seçim sayacı, tekli/çoklu silme.
 *
 * GÜVENLİK: Bu sayfa sadece aktif dönemde (2026) çalışır.
 *           2025 dönemi siparişleri bu sayfadan SİLİNEMEZ.
 */

// GÜVENLİK: 2025 dönemi parametresi geldiyse silme işlemini engelle
$donemParam = isset($_GET['donem']) ? $_GET['donem'] : '';
if ($donemParam === '2025') {
    die('<div style="padding:20px;text-align:center;color:red;font-weight:bold;">
        <i class="fa fa-exclamation-triangle"></i> 2025 dönemi siparişleri silinemez!
        <br><a href="lg_essiparis.php">Sipariş listesine dön</a>
    </div>');
}

// YETKI KONTROLÜ: M10 (Sil / Geri Dönüşüm) yetkisi
if (m_p_yetki($terminalkullanici, 'M10') != 1 && (int)($yetkidurum ?? 0) !== 0) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// YONETICI (yetki=0) TUM personelin bos fislerini gorur ve silebilir;
// diger kullanicilar yalnizca kendi taslaklarini.
$isAdmin = (int) ($yetkidurum ?? 1) === 0;

$sil_mesaj = '';
$sil_tip   = ''; // ok | err

// --- CSRF (silme işlemleri için zorunlu) ---
$csrf_ok = true;
$isDeletePost = $_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['bulk_delete']) || isset($_POST['single_delete']));
if ($isDeletePost) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $csrf_ok = false;
        $sil_mesaj = "Güvenlik doğrulaması başarısız (CSRF). Sayfayı yenileyip tekrar deneyin.";
        $sil_tip = 'err';
    } else {
        // Tek kullanımlık yaklaşım: doğrulama sonrası token'ı yenile
        csrf_regenerate();
    }
}

// --- TEK TEK SİLME (POST) ---
if ($csrf_ok && isset($_POST['single_delete'])) {
    $siparisid = (int)$_POST['single_delete'];
    if ($siparisid > 0) {
        // Toplu silme ile aynı güvenlik kriterleri: sadece satırı olmayan, kendi taslağı olan kayıt silinir.
        $sqlOne = "
            DELETE FROM {$firmadonem}ORFICHE
            WHERE LOGICALREF = :id
              AND LOGICALREF NOT IN (SELECT ORDFICHEREF FROM {$firmadonem}ORFLINE)
              " . ($isAdmin ? "" : "AND SALESMANREF = :salesman") . "
              AND TRCODE = 1
              AND NETTOTAL = 0
        ";
        $stmt1 = $dbh->prepare($sqlOne);
        $paramsOne = [':id' => $siparisid];
        if (!$isAdmin) { $paramsOne[':salesman'] = $terminalkullanici; }
        $ok1   = $stmt1->execute($paramsOne);
        $affected = (int) $stmt1->rowCount();

        if ($ok1 && $affected > 0) {
            $sil_mesaj = "Sipariş silme işlemi başarılı.";
            $sil_tip = "ok";
        } else {
            $sil_mesaj = "Sipariş bulunamadı veya silinemedi (kriterler uyuşmuyor olabilir).";
            $sil_tip = "err";
        }
    }
}

// --- TOPLU SİLME (POST) ---
if ($csrf_ok && isset($_POST['bulk_delete']) && !empty($_POST['ids']) && is_array($_POST['ids'])) {
    // ID’leri temizle
		$ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [];
		$ids = array_map('intval', $ids);
		$ids = array_filter($ids, fn($v): bool => $v > 0);
		$ids = array_values(array_unique($ids, SORT_NUMERIC));
		

    if ($ids !== []) {
        // Güvenlik/performans için üst limit
        if (count($ids) > 1000) {
            $ids = array_slice($ids, 0, 1000);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // Bu sayfanın kriterleri korunarak silinir (yanlış kayıtlar gitmesin)
        $sqlDel = "
            DELETE FROM {$firmadonem}ORFICHE
            WHERE LOGICALREF IN ($placeholders)
              AND LOGICALREF NOT IN (SELECT ORDFICHEREF FROM {$firmadonem}ORFLINE)
              " . ($isAdmin ? "" : "AND SALESMANREF = ?") . "
              AND TRCODE = 1
              AND NETTOTAL = 0
        ";

        try {
            $dbh->beginTransaction();
            $params = $isAdmin ? $ids : array_merge($ids, [$terminalkullanici]);
            $stmtDel = $dbh->prepare($sqlDel);
            $okDel   = $stmtDel->execute($params);
            $affected = $stmtDel->rowCount();
            $dbh->commit();
            $sil_mesaj = $okDel
                ? "Seçili " . count($ids) . " kayıttan {$affected} tanesi silindi."
                : "Toplu silme başarısız.";
            $sil_tip = $okDel ? 'ok' : 'err';
        } catch (Exception) {
            $dbh->rollBack();
            $sil_mesaj = "Toplu silme sırasında hata oluştu.";
            $sil_tip = 'err';
        }
    } else {
        $sil_mesaj = "Silmek için satır seçmediniz.";
        $sil_tip = 'err';
    }
}

// --- VERİLER ---
$sql = "
SELECT
    FS.FICHENO,
    FS.DATE_,
    FS.NETTOTAL,
    FS.LOGICALREF,
    CR.DEFINITION_,
    FS.CLIENTREF,
    FS.SALESMANREF,
    SL.DEFINITION_ AS SATISCI
FROM {$firmadonem}ORFICHE FS
LEFT JOIN {$firma}CLCARD CR ON FS.CLIENTREF = CR.LOGICALREF
LEFT JOIN LG_SLSMAN SL WITH(NOLOCK) ON SL.LOGICALREF = FS.SALESMANREF
WHERE FS.LOGICALREF NOT IN (SELECT ST.ORDFICHEREF FROM {$firmadonem}ORFLINE ST)
  " . ($isAdmin ? "" : "AND FS.SALESMANREF = :salesman") . "
  AND FS.TRCODE = 1
  AND FS.NETTOTAL = 0
ORDER BY FS.DATE_ DESC, FS.FICHENO DESC, FS.LOGICALREF DESC
";
$stmt = $dbh->prepare($sql);
$stmt->execute($isAdmin ? [] : [':salesman' => $terminalkullanici]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$initialCount = is_array($rows) ? count($rows) : 0;

// Yardımcı gösterim (mevcut fonksiyonlarını kullan)
function _tarih(string|int|float|null $d): string{ return tarihcevir($d); }
function _tl(float|int|string|null $n): string{ return kusuratpara($n); }
function _str(mixed $s): string{ return trcevir($s); }

?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Taslak Siparisler</title>
  <link rel="icon" type="image/png" href="icon.png">
  <?php if (file_exists(__DIR__ . '/pwa-header.php')) { include_once __DIR__ . '/pwa-header.php'; } ?>
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
      --red: #6F1022;
      --red-soft: #fef2f2;
      --emerald: #059669;
      --emerald-soft: #ecfdf5;
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
      border-bottom: 1px solid rgba(248, 113, 113, 0.18);
      box-shadow: 0 2px 8px rgba(111, 16, 34, 0.04);
    }
    .header-inner {
      max-width: 1100px; margin: 0 auto;
      padding: 14px 24px;
      display: flex; align-items: center; gap: 14px;
    }
    .header-back {
      display: inline-flex; align-items: center; justify-content: center;
      width: 36px; height: 36px; border-radius: 10px;
      color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
      flex-shrink: 0;
    }
    .header-back:hover { background: rgba(0,0,0,0.04); color: var(--red); }
    .header-divider { width: 1px; height: 24px; background: var(--border); flex-shrink: 0; }
    .header-title {
      font-size: 16px; font-weight: 700; color: var(--text-1);
      display: inline-flex; align-items: center; gap: 8px;
      flex: 1 1 auto;
    }
    .header-title i { color: var(--red,#ef4444); font-size: 14px; }
    .header-title .badge-info {
      margin-left: 6px;
      padding: 2px 8px;
      background: var(--red-soft);
      color: var(--red);
      border: 1px solid rgba(239, 68, 68, 0.22);
      border-radius: 100px;
      font-size: 10px;
      font-weight: 700;
    }

    .alert-bar {
      display: flex; align-items: center; gap: 10px;
      padding: 12px 16px;
      border-radius: 12px;
      font-size: 13px; font-weight: 500;
      margin-bottom: 16px;
      animation: cardIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
    }
    .alert-bar.ok { background: var(--emerald-soft); color: var(--emerald); border: 1px solid rgba(5, 150, 105, 0.25); }
    .alert-bar.err { background: var(--red-soft); color: var(--red); border: 1px solid rgba(239, 68, 68, 0.25); }
    .alert-bar i { font-size: 14px; flex-shrink: 0; }

    .filter-panel {
      background: rgba(255,255,255,0.92);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      border: 1px solid rgba(248, 113, 113, 0.18);
      border-radius: 14px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.04);
      padding: 16px;
      margin-bottom: 16px;
      animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
    }
    .filter-grid {
      display: grid;
      grid-template-columns: 2fr 1fr auto;
      gap: 14px;
      align-items: center;
    }
    .search-wrap { position: relative; }
    .search-wrap i.fa-search {
      position: absolute; top: 50%; left: 14px;
      transform: translateY(-50%);
      color: var(--text-3); font-size: 14px;
      pointer-events: none;
    }
    .search-input {
      width: 100%;
      padding: 11px 14px 11px 40px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif;
      font-size: 14px;
      color: var(--text-1);
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 10px;
      outline: none;
      transition: all 0.2s ease;
    }
    .search-input:focus { border-color: rgba(239, 68, 68, 0.5); box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1); }

    .summary-stats { display: flex; flex-direction: column; gap: 4px; font-size: 11.5px; color: var(--text-2); }
    .summary-stats .row { display: flex; justify-content: space-between; gap: 12px; }
    .summary-stats .row .lbl { color: var(--text-2); }
    .summary-stats .row .val { color: var(--text-1); font-weight: 700; }
    .filter-buttons { display: flex; gap: 8px; }
    .btn-ghost {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 9px 14px;
      background: #fff; color: var(--text-2);
      border: 1px solid var(--border); border-radius: 10px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12px; font-weight: 600;
      cursor: pointer; transition: all 0.2s ease;
      white-space: nowrap;
    }
    .btn-ghost:hover { background: #fafafa; border-color: var(--text-3); color: var(--text-1); }
    .btn-ghost i { font-size: 11px; }

    .table-card {
      background: rgba(255,255,255,0.92);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      border: 1px solid rgba(248, 113, 113, 0.18);
      border-radius: 16px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.04);
      overflow: hidden;
      animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) 0.08s both;
    }
    .table-toolbar {
      padding: 12px 16px;
      border-bottom: 1px solid var(--border);
      background: linear-gradient(180deg, #fff, #fffafa);
      display: flex; align-items: center; justify-content: space-between;
      gap: 12px; flex-wrap: wrap;
    }
    .toolbar-left {
      display: inline-flex; align-items: center; gap: 10px;
      cursor: pointer; user-select: none;
    }
    .toolbar-left input[type="checkbox"] {
      width: 16px; height: 16px;
      accent-color: var(--red); cursor: pointer; margin: 0;
    }
    .toolbar-left .label { font-size: 12px; font-weight: 600; color: var(--text-2); }
    .btn-bulk-del {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 9px 16px;
      background: var(--red,#ef4444); color: #fff;
      border: none; border-radius: 10px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12.5px; font-weight: 700;
      cursor: pointer; transition: all 0.2s ease;
      box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
    }
    .btn-bulk-del:hover:not(:disabled) {
      background: var(--red); transform: translateY(-1px);
      box-shadow: 0 6px 16px rgba(239, 68, 68, 0.3);
    }
    .btn-bulk-del:disabled { opacity: 0.45; cursor: not-allowed; box-shadow: none; }

    .gd-table { width: 100%; border-collapse: collapse; }
    .gd-table thead { background: linear-gradient(180deg, #fff, #fffafa); }
    .gd-table thead th {
      padding: 11px 14px;
      font-size: 10px; font-weight: 700; color: var(--text-2);
      text-transform: uppercase; letter-spacing: 0.5px;
      text-align: left;
      border-bottom: 1px solid var(--border);
      cursor: pointer; user-select: none;
      white-space: nowrap;
    }
    .gd-table thead th:not([data-key]) { cursor: default; }
    .gd-table thead th.right { text-align: right; }
    .gd-table thead th .sort-ind { opacity: 0.4; margin-left: 4px; font-size: 9px; }
    .gd-table thead th[data-key]:hover { color: var(--text-1); }
    .gd-table thead th[data-key]:hover .sort-ind { opacity: 0.8; }
    .gd-table tbody td {
      padding: 11px 14px;
      font-size: 12.5px; color: var(--text-1);
      border-bottom: 1px solid #f3f4f6;
      vertical-align: middle;
    }
    .gd-table tbody tr { transition: background 0.15s ease; }
    .gd-table tbody tr:hover { background: var(--red-soft); }
    .gd-table tbody tr:last-child td { border-bottom: none; }
    .gd-table tbody td.sel { width: 36px; }
    .gd-table tbody td.sel input[type="checkbox"] {
      width: 16px; height: 16px; accent-color: var(--red); cursor: pointer; margin: 0;
    }
    .gd-table tbody td.actions { width: 80px; }
    .gd-table tbody td.date { white-space: nowrap; color: var(--text-2); font-size: 12px; }
    .gd-table tbody td.ficheno {
      font-family: 'JetBrains Mono', monospace;
      font-size: 11.5px; color: var(--red); font-weight: 600;
    }
    .gd-table tbody td.firma {
      max-width: 320px;
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
      font-weight: 500;
    }
    .gd-table tbody td.tutar {
      text-align: right; font-weight: 700; color: var(--text-2); white-space: nowrap;
    }
    .gd-table tbody td.personel {
      color: var(--text-2); font-size: 12px; white-space: nowrap;
      max-width: 180px; overflow: hidden; text-overflow: ellipsis;
    }
    .btn-row-del {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 6px 10px;
      background: var(--red-soft); color: var(--red);
      border: 1px solid rgba(239, 68, 68, 0.22);
      border-radius: 8px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 11px; font-weight: 600;
      cursor: pointer; transition: all 0.15s ease;
    }
    .btn-row-del:hover { background: var(--red); color: #fff; border-color: var(--red); }
    .btn-row-del i { font-size: 10px; }

    .empty-row td {
      text-align: center;
      padding: 40px 20px !important;
      color: var(--text-3); font-size: 13px;
    }
    .empty-row i { display: block; font-size: 32px; margin-bottom: 10px; color: var(--text-3); }

    .table-footer {
      padding: 12px 16px;
      background: #fafafa;
      border-top: 1px solid var(--border);
      display: flex; justify-content: space-between; align-items: center;
      font-size: 11.5px; color: var(--text-2);
    }
    .table-footer .lbl { font-weight: 500; }
    .table-footer .val { font-weight: 700; color: var(--text-1); }

    @keyframes cardIn {
      from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
      to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
    }

    @media (max-width: 900px) {
      .filter-grid { grid-template-columns: 1fr; gap: 12px; }
      .filter-buttons { flex-wrap: wrap; }
    }
    @media (max-width: 767px) {
      .header-inner { padding: 12px 14px; gap: 10px; }
      .header-title { font-size: 13.5px; }
      .header-title .badge-info { font-size: 9px; padding: 2px 6px; }
      .header-back { width: 30px; height: 30px; border-radius: 8px; }

      main { padding: 14px 12px 40px !important; }

      .filter-panel { padding: 12px; }
      .search-input { font-size: 16px; padding: 11px 14px 11px 38px; }

      .table-toolbar { padding: 10px 14px; }
      .btn-bulk-del { padding: 8px 12px; font-size: 11.5px; }

      .gd-table thead { display: none; }
      .gd-table, .gd-table tbody, .gd-table tr, .gd-table td { display: block; width: 100%; }
      .gd-table tbody tr {
        padding: 12px 14px;
        border-bottom: 1px solid #f3f4f6;
        display: grid;
        grid-template-columns: auto 1fr auto;
        gap: 4px 10px;
        align-items: center;
      }
      .gd-table tbody td { padding: 0; border-bottom: none; }
      .gd-table tbody td.sel { grid-column: 1; grid-row: 1 / span 2; align-self: center; width: auto; }
      .gd-table tbody td.firma { grid-column: 2; max-width: none; font-size: 13px; }
      .gd-table tbody td.tutar { grid-column: 3; font-size: 13px; color: var(--text-1); }
      .gd-table tbody td.date  { grid-column: 2; font-size: 11px; }
      .gd-table tbody td.ficheno { grid-column: 2; font-size: 10.5px; padding-top: 2px; }
      .gd-table tbody td.ficheno::before {
        content: 'Fis: '; color: var(--text-3); font-weight: 400; font-family: 'Avenir Next', 'Montserrat', sans-serif;
      }
      .gd-table tbody td.personel { grid-column: 2; font-size: 11px; max-width: none; }
      .gd-table tbody td.personel::before {
        content: 'Personel: '; color: var(--text-3); font-weight: 400; font-family: 'Avenir Next', 'Montserrat', sans-serif;
      }
      .gd-table tbody td.actions { grid-column: 3; grid-row: 2; width: auto; }

      .table-footer { flex-direction: column; gap: 4px; align-items: flex-start; }
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
        <i class="fa-solid fa-recycle"></i>Taslak Siparisler
        <span class="badge-info">NETTOTAL = 0</span>
        <?php if ($isAdmin): ?><span class="badge-info" style="background:#eef2ff;color:#4f46e5;border-color:rgba(79,70,229,0.22);"><i class="fa-solid fa-users-gear"></i> Tüm personel</span><?php endif; ?>
      </span>
    </div>
  </header>

  <main style="max-width:1100px;margin:0 auto;padding:20px 24px 60px;">

    <?php if ($sil_mesaj !== '' && $sil_mesaj !== '0'): ?>
      <div class="alert-bar <?php echo $sil_tip === 'ok' ? 'ok' : 'err'; ?>">
        <i class="fa-solid <?php echo $sil_tip === 'ok' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
        <span><?php echo htmlspecialchars($sil_mesaj); ?></span>
      </div>
    <?php endif; ?>

    <div class="filter-panel">
      <div class="filter-grid">
        <div class="search-wrap">
          <i class="fa-solid fa-search"></i>
          <input id="q" type="text" class="search-input" placeholder="Firma, fis no, tarih, tutar..." autocomplete="off">
        </div>
        <div class="summary-stats">
          <div class="row"><span class="lbl">Gorunen Kayit</span><span class="val" id="count"><?php echo number_format((float) $initialCount, 0, ',', '.'); ?></span></div>
          <div class="row"><span class="lbl">Toplam Tutar</span><span class="val" id="sum">&#8378; 0,00</span></div>
          <div class="row"><span class="lbl">Secili</span><span class="val" id="selCount">0</span></div>
        </div>
        <div class="filter-buttons">
          <button id="clearSel" type="button" class="btn-ghost">
            <i class="fa-solid fa-square-xmark"></i>Secimi Temizle
          </button>
          <button id="clearFilter" type="button" class="btn-ghost">
            <i class="fa-solid fa-eraser"></i>Filtreyi Temizle
          </button>
        </div>
      </div>
    </div>

    <form id="bulkForm" method="POST" class="table-card">
      <?php echo csrf_field(); ?>

      <div class="table-toolbar">
        <label class="toolbar-left">
          <input id="chkAll" type="checkbox">
          <span class="label">Gorunur satirlarin tumunu sec</span>
        </label>
        <button type="submit" name="bulk_delete" value="1" id="btnBulkDel" disabled class="btn-bulk-del">
          <i class="fa-solid fa-trash"></i> Secili Satirlari Sil
        </button>
      </div>

      <div style="overflow-x:auto;">
        <table id="tbl" class="gd-table">
          <thead>
            <tr>
              <th style="width:36px;">&nbsp;</th>
              <th style="width:80px;">Islem</th>
              <th data-key="date" data-type="date">Tarih <i class="sort-ind fa-solid fa-sort"></i></th>
              <th data-key="ficheno" data-type="string">Fis No <i class="sort-ind fa-solid fa-sort"></i></th>
              <th data-key="firma" data-type="string">Firma <i class="sort-ind fa-solid fa-sort"></i></th>
              <?php if ($isAdmin): ?>
              <th data-key="satisci" data-type="string">Personel <i class="sort-ind fa-solid fa-sort"></i></th>
              <?php endif; ?>
              <th class="right" data-key="tutar" data-type="number">Tutar <i class="sort-ind fa-solid fa-sort"></i></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
              $isoDate   = date('Y-m-d', strtotime((string) $r['DATE_']));
              $dispDate  = _tarih($r['DATE_']);
              $dispFirm  = _str($r['DEFINITION_']);
              $dispTot   = _tl($r['NETTOTAL']);
              $numeric   = (float)$r['NETTOTAL'];
              $dispPers  = '';
              if ($isAdmin) {
                $pers = trim((string) ($r['SATISCI'] ?? ''));
                $dispPers = $pers !== '' ? _str($pers) : ('#' . (int) $r['SALESMANREF']);
              }
            ?>
              <tr>
                <td class="sel">
                  <input type="checkbox" class="row-chk" name="ids[]" value="<?php echo (int)$r['LOGICALREF']; ?>">
                </td>
                <td class="actions">
                  <button type="submit" name="single_delete" value="<?php echo (int)$r['LOGICALREF']; ?>"
                          onclick="return confirm('Bu siparisi silmek istediginizden emin misiniz?');"
                          class="btn-row-del">
                    <i class="fa-solid fa-trash"></i> Sil
                  </button>
                </td>
                <td class="date" data-key="date" data-order="<?php echo htmlspecialchars($isoDate); ?>">
                  <?php echo htmlspecialchars($dispDate); ?>
                </td>
                <td class="ficheno" data-key="ficheno">
                  <?php echo htmlspecialchars((string) $r['FICHENO']); ?>
                </td>
                <td class="firma" data-key="firma" title="<?php echo htmlspecialchars((string) $dispFirm); ?>">
                  <?php echo htmlspecialchars((string) $dispFirm); ?>
                </td>
                <?php if ($isAdmin): ?>
                <td class="personel" data-key="satisci" data-order="<?php echo htmlspecialchars($dispPers); ?>" title="<?php echo htmlspecialchars($dispPers); ?>">
                  <?php echo htmlspecialchars($dispPers); ?>
                </td>
                <?php endif; ?>
                <td class="tutar" data-key="tutar" data-order="<?php echo htmlspecialchars((string)$numeric); ?>">
                  &#8378; <?php echo htmlspecialchars($dispTot); ?>
                </td>
              </tr>
            <?php endforeach; ?>

            <?php if (empty($rows)): ?>
              <tr class="empty-row">
                <td colspan="<?php echo $isAdmin ? 7 : 6; ?>">
                  <i class="fa-solid fa-box-open"></i>
                  Kayit bulunamadi
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="table-footer">
        <span class="lbl">Goruntulenen kayit sayisi</span>
        <span class="val"><span id="countFoot"><?php echo number_format((float) $initialCount, 0, ',', '.'); ?></span></span>
      </div>
    </form>
  </main>

  <script>
    (function() {
      const q = document.getElementById('q');
      const clearBtn = document.getElementById('clearFilter');
      const clearSelBtn = document.getElementById('clearSel');
      const tbl = document.getElementById('tbl');
      const tbody = tbl.querySelector('tbody');
      const countEl = document.getElementById('count');
      const countFootEl = document.getElementById('countFoot');
      const sumEl = document.getElementById('sum');
      const chkAll = document.getElementById('chkAll');
      const btnBulkDel = document.getElementById('btnBulkDel');
      const selCountEl = document.getElementById('selCount');
      const bulkForm = document.getElementById('bulkForm');

      function trText(tr){ return tr.textContent.toLocaleUpperCase('tr-TR'); }
      function visibleRows(){
        // "Kayıt bulunamadı" gibi bilgilendirme satırları sayaçlara dahil edilmesin.
        return Array.from(tbody.querySelectorAll('tr')).filter(tr => tr.style.display !== 'none' && tr.querySelector('.row-chk'));
      }
      function formatTL(x){
        const n = Number(x) || 0;
        return '₺ ' + n.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2});
      }

      function refreshSummary(){
        const vis = visibleRows();
        const sum = vis.reduce((acc, tr) => {
          const cell = tr.querySelector('[data-key="tutar"]');
          const val = cell ? parseFloat(cell.getAttribute('data-order') || '0') : 0;
          return acc + (isNaN(val) ? 0 : val);
        }, 0);
        const cnt = vis.length;
        countEl.textContent = cnt;
        countFootEl.textContent = cnt;
        if (sumEl) sumEl.textContent = formatTL(sum);
        refreshSelectionUI();
      }

      // --- Arama ---
      q.addEventListener('input', () => {
        const needle = q.value.toLocaleUpperCase('tr-TR');
        Array.from(tbody.querySelectorAll('tr')).forEach(tr => {
          const hay = trText(tr);
          tr.style.display = hay.indexOf(needle) >= 0 ? '' : 'none';
        });
        refreshSummary();
      });

      clearBtn.addEventListener('click', (e) => {
        e.preventDefault();
        q.value = '';
        Array.from(tbody.querySelectorAll('tr')).forEach(tr => tr.style.display = '');
        refreshSummary();
      });

      // --- Sıralama ---
      let lastSort = { key: null, dir: 'desc' };
      function compare(a, b, type){
        if (type === 'number') return (parseFloat(a)||0) - (parseFloat(b)||0);
        if (type === 'date') { if (a===b) return 0; return a < b ? -1 : 1; }
        a=(a||'').toLocaleUpperCase('tr-TR'); b=(b||'').toLocaleUpperCase('tr-TR');
        if (a===b) return 0; return a < b ? -1 : 1;
      }
      function sortBy(key, type){
        let dir = 'asc';
        if (lastSort.key === key && lastSort.dir === 'asc') dir = 'desc';
        lastSort = { key, dir };
        const rows = Array.from(tbody.querySelectorAll('tr')).filter(tr => tr.querySelector('[data-key="'+key+'"]'));
        rows.sort((r1, r2) => {
          const c1 = r1.querySelector('[data-key="'+key+'"]');
          const c2 = r2.querySelector('[data-key="'+key+'"]');
          const v1 = c1 ? (c1.getAttribute('data-order') || c1.textContent.trim()) : '';
          const v2 = c2 ? (c2.getAttribute('data-order') || c2.textContent.trim()) : '';
          const res = compare(v1, v2, type);
          return dir === 'asc' ? res : -res;
        });
        rows.forEach(tr => tbody.appendChild(tr));
        tbl.querySelectorAll('thead th .sort-ind').forEach(i => i.className='sort-ind fa-solid fa-sort');
        const th = tbl.querySelector('thead th[data-key="'+key+'"] .sort-ind');
        if (th) th.className = 'sort-ind fa-solid ' + (dir === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
        refreshSummary();
      }
      tbl.querySelectorAll('thead th[data-key]').forEach(th => {
        th.addEventListener('click', () => sortBy(th.getAttribute('data-key'), th.getAttribute('data-type') || 'string'));
      });

      // --- Seçim mantığı ---
      function selectedCheckboxes(){
        return Array.from(document.querySelectorAll('.row-chk')).filter(ch => ch.checked);
      }
      function refreshSelectionUI(){
        const sel = selectedCheckboxes();
        selCountEl.textContent = sel.length;
        btnBulkDel.disabled = sel.length === 0;

        // Master checkbox durumu (yalnızca görünür satırlar baz alınır)
        const vis = visibleRows();
        const visChecks = vis.map(tr => tr.querySelector('.row-chk')).filter(Boolean);
        const allVisChecked = visChecks.length > 0 && visChecks.every(ch => ch.checked);
        const someVisChecked = visChecks.some(ch => ch.checked);
        chkAll.checked = allVisChecked;
        chkAll.indeterminate = !allVisChecked && someVisChecked;
      }

      // Hepsini seç (görünür satırlar)
      chkAll.addEventListener('change', () => {
        const vis = visibleRows();
        vis.forEach(tr => {
          const ch = tr.querySelector('.row-chk');
          if (ch) ch.checked = chkAll.checked;
        });
        refreshSelectionUI();
      });

      // Satır checkbox’ları
      tbody.addEventListener('change', (e) => {
        if (e.target && e.target.classList.contains('row-chk')) {
          refreshSelectionUI();
        }
      });

      // SHIFT + click ile aralık seçimi
      let lastClickedIndex = null;
      tbody.addEventListener('click', (e) => {
        if (e.target && e.target.classList.contains('row-chk')) {
          const checks = Array.from(tbody.querySelectorAll('.row-chk')).filter(ch => ch.closest('tr').style.display !== 'none');
          const idx = checks.indexOf(e.target);
          if (e.shiftKey && lastClickedIndex !== null && idx !== -1) {
            const [a, b] = [lastClickedIndex, idx].sort((x,y)=>x-y);
            const val = e.target.checked;
            for (let i=a; i<=b; i++) checks[i].checked = val;
          }
          lastClickedIndex = idx;
          refreshSelectionUI();
        }
      });

      // Seçimi temizle
      document.getElementById('clearSel').addEventListener('click', (e)=>{
        e.preventDefault();
        document.querySelectorAll('.row-chk').forEach(ch => ch.checked = false);
        refreshSelectionUI();
      });

      // Toplu silmeden önce onay
      bulkForm.addEventListener('submit', (e) => {
        const sel = selectedCheckboxes();
        if (sel.length === 0) {
          e.preventDefault();
          return;
        }
        const ok = confirm(sel.length + " satır silinecek. Emin misiniz?");
        if (!ok) e.preventDefault();
      });

	      // İlk özet
	      // Sayfa ilk açılışında olası eski filtre/inline display kalıntılarını temizle (bfcache vs.)
	      if (q) q.value = '';
	      Array.from(tbody.querySelectorAll('tr')).forEach(tr => {
	        if (tr.querySelector('.row-chk')) {
	          tr.style.display = '';
	        }
	      });
	      refreshSummary();
	    })();
	  </script>
</body>
</html>
