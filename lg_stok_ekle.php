<?php
declare(strict_types=1);
include_once(__DIR__ . "/ayr.php");
include(__DIR__ . "/kontrol.php");
include_once(__DIR__ . "/log_ip.php");

// URL parametrelerini session'a aktar ve temiz URL'ye yönlendir
migrateUrlToSession(['stok', 'fisid', 'return_to']);

$resmiKdvZorunlu = defined('RESMI_FORCE_KDV');
$resmiKdvOrani = $resmiKdvZorunlu ? (float) RESMI_FORCE_KDV : 0.0;

function format5(float|int|string|null $sayi): string
{
  // Geçersiz veya boşsa sıfıra çevir
  if (!is_numeric($sayi)) {
    $sayi = 0;
  }
  // (float) dönüşümü, number_format öncesi gerekliyse kullanılır
  return number_format((float) $sayi, 5, ',', '.');
}

// Görünüm için 2 ondalık basamaklı format (standart yuvarlama, Türkçe format)
function format2(float|int|string|null $sayi): string
{
  if (!is_numeric($sayi)) {
    $sayi = 0;
  }
  // Standart yuvarlama (2 ondalık basamağa)
  $yuvarlanmis = round((float) $sayi, 2);
  return number_format($yuvarlanmis, 2, ',', '.');
}

// Value için 5 ondalık basamaklı format (standart yuvarlama, noktalı format - JavaScript/PHP uyumlu)
function format2_value(float|int|string|null $sayi): string
{
  if (!is_numeric($sayi)) {
    $sayi = 0;
  }
  // Standart yuvarlama (5 ondalık basamağa)
  $yuvarlanmis = round((float) $sayi, 5);
  return number_format($yuvarlanmis, 5, '.', '');
}
$fiyatdeğiştirme = "readonly";
$miktargorme = "invisible";
$fiyatgorme = "invisible";
if (m_p_yetki($terminalkullanici, 'ST1') == 1 || $yetkidurum == 0) {
  echo $miktargorme = "";
}
if (m_p_yetki($terminalkullanici, 'ST2') == 1 || $yetkidurum == 0) {
  echo $fiyatgorme = "";
}
if (m_p_yetki($terminalkullanici, 'SP1') == 1 || $yetkidurum == 0) {
  $fiyatdeğiştirme = "";
}

// Session bazlı parametre sistemi
$stokid = getPageParamInt('stok');
$fisid = getPageParamInt('fisid');
$lgStokEkleBackUrl = safeLocalReturnUrl(
  getPageParamString('return_to', ''),
  'lg_fis.php?stokhareket=' . $fisid
);

if ($stokid > 0 && $fisid > 0) {
  include_once(__DIR__ . "/fiyatgrup.php");

  $stmt = $dbh->prepare("SELECT LOGICALREF FROM " . $firmadonem . "ORFLINE WHERE STOCKREF = :stokid AND ORDFICHEREF = :fisid");
  $stmt->execute([':stokid' => $stokid, ':fisid' => $fisid]);
  if ($sipvarmi = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // lg_stok_duzenle.php için session'a kaydet ve temiz URL'ye yönlendir
    setPageParam('stokid', $stokid, 'lg_stok_duzenle');
    setPageParam('stokhareket', $fisid, 'lg_stok_duzenle');
    setPageParam('return_to', $lgStokEkleBackUrl, 'lg_stok_duzenle');
    echo '<script>window.location="lg_stok_duzenle.php";</script>';
    exit;
  }
} else {
  exit;
}

$stmt = $dbh->prepare("SELECT CLIENTREF FROM " . $firmadonem . "ORFICHE WHERE LOGICALREF = :fisid");
$stmt->execute([':fisid' => $fisid]);
$caribul = $stmt->fetch(PDO::FETCH_ASSOC);
$cariid = isset($caribul['CLIENTREF']) ? (int) $caribul['CLIENTREF'] : 0;
$stmt = $dbh->prepare("SELECT
URUN.CODE AS 'URUN KODU',
URUN.NAME AS 'URUN ADI',
URUN.SELLVAT AS 'KDV',
URUN.LOGICALREF AS 'URUN ID',
BIRIM.CODE AS 'BIRIM',
BIRIM.UNITSETREF AS 'BIRIMID',
'MIKTAR'= CASE WHEN (AMBARM.MIKTAR IS NULL) THEN '0' WHEN (AMBARM.MIKTAR IS NOT NULL) THEN AMBARM.MIKTAR END,
'S.FIYAT'= CASE WHEN (SATIS.MIKTAR IS NULL) THEN '0' WHEN (SATIS.MIKTAR IS NOT NULL) THEN SATIS.MIKTAR END
FROM
{oj " . $firma . "ITEMS URUN
LEFT JOIN (SELECT SUM(ONHAND) MIKTAR,STOCKREF FROM " . $firmadonemx . "STINVTOT WHERE INVENNO=-1 GROUP BY STOCKREF)
AMBARM ON URUN.LOGICALREF = AMBARM.STOCKREF
LEFT JOIN ((SELECT MAX(PRICE) AS 'MIKTAR',CARDREF FROM " . $firma . "PRCLIST WHERE PTYPE=2  GROUP BY CARDREF))
SATIS ON URUN.LOGICALREF = SATIS.CARDREF
LEFT JOIN " . $firma . "UNITSETL BIRIM ON URUN.UNITSETREF=BIRIM.UNITSETREF
}
WHERE URUN.CARDTYPE<>'22' AND BIRIM.LINENR=1 AND
URUN.LOGICALREF = :stokid AND URUN.ACTIVE=0
ORDER BY URUN.CODE");
$stmt->execute([':stokid' => $stokid]);
$stokara = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($stokara) || $stokara === []) {
  header('Location: ' . APP_ROOT_URL . '/lg_fis.php?stokhareket=' . $fisid . '&hata=stok_bulunamadi', true, 303);
  exit;
}

if ($resmiKdvZorunlu) {
  $stokara['KDV'] = $resmiKdvOrani;
}
$fiyati = (float)$stokara['S.FIYAT'];
if ($fiyatgruplu == 1) {
  $odemekodu = isset($_SESSION['fiyatgrup']) ? (int) $_SESSION['fiyatgrup'] : 0;
  if ($odemekodu > 0) {
    $stmt = $dbh->prepare("SELECT PRICE FROM " . $firma . "PRCLIST WHERE CARDREF = :stokid AND PTYPE = 2 AND PAYPLANREF = :odemekodu ORDER BY LOGICALREF DESC");
    $stmt->execute([':stokid' => $stokid, ':odemekodu' => $odemekodu]);
    $fiyatx = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($fiyatx) {
      $fiyati = (float)$fiyatx['PRICE'];
    }
  }
}

// İskontolu fiyatları hesapla (5 ondalık basamak hassasiyetinde)
$iskonto1Fiyat = ($iskontolu > 0) ? $fiyati - (($fiyati * $iskontolu) / 100) : null;
$iskonto2Fiyat = ($iskontolu2 > 0) ? $fiyati - (($fiyati * $iskontolu2) / 100) : null;
$iskonto3Fiyat = ($iskontolu3 > 0) ? $fiyati - (($fiyati * $iskontolu3) / 100) : null;

// Dövizli fiyat hesaplama
$dovizAktif = false;
$dovizKuru = 1;
$dovizSembol = '₺';
$dovizTipi = 'TL';

if ($dovizlicalis == '1') {
  $dovizTipi = $_SESSION['doviz'] ?? '0';
  if ($dovizTipi != '0' && $dovizTipi != 'TL') {
    $dovizKuru = dovizkuru_bul($fisid);
    if ($dovizKuru > 0) {
      $dovizAktif = true;
      $dovizSembol = dovizsembol_bul($dovizTipi);
      if (empty($dovizSembol)) {
        $dovizSembol = '$'; // Fallback
      }
    }
  }
}

// Dövizli fiyatlar
$fiyatiDoviz = $dovizAktif ? ($fiyati / $dovizKuru) : null;
$iskonto1FiyatDoviz = ($dovizAktif && $iskonto1Fiyat !== null) ? ($iskonto1Fiyat / $dovizKuru) : null;
$iskonto2FiyatDoviz = ($dovizAktif && $iskonto2Fiyat !== null) ? ($iskonto2Fiyat / $dovizKuru) : null;
$iskonto3FiyatDoviz = ($dovizAktif && $iskonto3Fiyat !== null) ? ($iskonto3Fiyat / $dovizKuru) : null;
?>

<!DOCTYPE html>
<html lang="tr">
<head>
  <link rel="icon" type="image/png" href="icon.png">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Stok Ekle</title>
  <?php if (file_exists(__DIR__ . '/pwa-header.php')) { include_once __DIR__ . '/pwa-header.php'; } ?>
  <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <script src="/tm/js/jquery-3.7.1.min.js"></script>
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
      --indigo: #4f46e5;
      --indigo-soft: #eef2ff;
      --amber: #d97706;
      --amber-soft: #fffbeb;
      --sky: #0284c7;
      --sky-soft: #eff6ff;
    }
    * { box-sizing: border-box; margin: 0; }
    body {
      font-family: 'Avenir Next', 'Montserrat', sans-serif;
      background: var(--bg);
      color: var(--text-1);
      min-height: 100vh;
    }
    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    input[type=number] { -moz-appearance: textfield; }

    /* ═══════ HEADER ═══════ */
    .top-header {
      position: sticky; top: 0; z-index: 40;
      height: 64px;
      background: rgba(255,255,255,0.88);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      border-bottom: 1px solid rgba(248, 113, 113, 0.18);
      box-shadow: 0 2px 8px rgba(111, 16, 34, 0.04);
    }
    .header-inner {
      max-width: 760px; margin: 0 auto;
      height: 100%; display: flex; align-items: center; gap: 14px;
      padding: 0 24px;
    }
    .header-back {
      display: inline-flex; align-items: center; justify-content: center;
      width: 36px; height: 36px; border-radius: 10px;
      color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
    }
    .header-back:hover { background: rgba(0,0,0,0.04); color: var(--red); }
    .header-divider { width: 1px; height: 24px; background: var(--border); }
    .header-title {
      font-size: 18px; font-weight: 700; color: var(--text-1);
      display: inline-flex; align-items: center; gap: 8px;
    }
    .header-title i { color: var(--red,#ef4444); font-size: 16px; }

    /* ═══════ CARD ═══════ */
    .glass-card {
      background: rgba(255,255,255,0.92);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      border: 1px solid rgba(248, 113, 113, 0.18);
      border-radius: 16px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.04);
      will-change: transform, opacity;
      animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
      overflow: hidden;
      margin-bottom: 16px;
    }
    .glass-card:nth-of-type(2) { animation-delay: 60ms; }
    .glass-card:nth-of-type(3) { animation-delay: 120ms; }
    .glass-card:nth-of-type(4) { animation-delay: 180ms; }

    /* Urun hero */
    .urun-hero {
      padding: 18px 20px;
      background: linear-gradient(135deg, #fef2f2, #fee2e2);
      border-bottom: 1px solid rgba(248, 113, 113, 0.25);
    }
    .urun-hero .kod {
      display: inline-block;
      padding: 3px 10px;
      font-size: 11px;
      font-weight: 600;
      color: var(--red);
      background: #fff;
      border: 1px solid rgba(239, 68, 68, 0.25);
      border-radius: 100px;
      letter-spacing: 0.4px;
    }
    .urun-hero .ad {
      margin-top: 8px;
      font-size: 17px;
      font-weight: 700;
      color: var(--text-1);
      line-height: 1.3;
    }

    /* ═══════ ISKONTO PRICE PILLS ═══════ */
    .price-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 10px;
      padding: 16px 20px 4px;
    }
    .price-pill {
      padding: 12px 12px;
      border-radius: 12px;
      text-align: left;
      background: #fff;
      border: 1px solid var(--border);
      cursor: pointer;
      transition: all 0.2s ease;
      font-family: 'Avenir Next', 'Montserrat', sans-serif;
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    }
    .price-pill:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 16px rgba(0,0,0,0.08);
    }
    .price-pill .pct {
      display: block;
      font-size: 10px;
      font-weight: 600;
      color: var(--text-2);
      text-transform: uppercase;
      letter-spacing: 0.4px;
      margin-bottom: 4px;
    }
    .price-pill .val {
      display: block;
      font-size: 15px;
      font-weight: 700;
      color: var(--text-1);
    }
    .price-pill .doviz-sub {
      display: block;
      margin-top: 4px;
      padding-top: 4px;
      border-top: 1px dashed rgba(0,0,0,0.08);
      font-size: 10px;
      font-weight: 500;
      color: var(--text-3);
    }
    .price-pill.list-price { border-color: var(--border); background: #f9fafb; }
    .price-pill.list-price:hover { border-color: #cbd5e1; }
    .price-pill.tone-amber { background: var(--amber-soft); border-color: rgba(217, 119, 6, 0.22); }
    .price-pill.tone-amber .pct, .price-pill.tone-amber .val { color: var(--amber); }
    .price-pill.tone-amber:hover { border-color: rgba(217, 119, 6, 0.45); }
    .price-pill.tone-emerald { background: var(--emerald-soft); border-color: rgba(5, 150, 105, 0.22); }
    .price-pill.tone-emerald .pct, .price-pill.tone-emerald .val { color: var(--emerald); }
    .price-pill.tone-emerald:hover { border-color: rgba(5, 150, 105, 0.45); }
    .price-pill.tone-indigo { background: var(--indigo-soft); border-color: rgba(79, 70, 229, 0.22); }
    .price-pill.tone-indigo .pct, .price-pill.tone-indigo .val { color: var(--indigo); }
    .price-pill.tone-indigo:hover { border-color: rgba(79, 70, 229, 0.45); }

    /* Urun resmi */
    .urun-resim {
      padding: 14px 20px 0;
    }
    .urun-resim img {
      display: block;
      width: 100%;
      max-height: 300px;
      object-fit: contain;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: #fafafa;
      padding: 6px;
    }

    /* Doviz bolumu */
    .doviz-box {
      padding: 14px 20px;
    }
    .doviz-head {
      font-size: 10px;
      font-weight: 700;
      color: var(--text-2);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .doviz-head i { color: var(--sky); }
    .doviz-grid {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 10px;
      background: var(--sky-soft);
      border: 1px solid rgba(14, 165, 233, 0.2);
      border-radius: 12px;
      padding: 12px;
    }
    .doviz-grid .d-item .d-lbl {
      font-size: 10px;
      color: var(--sky);
      text-transform: uppercase;
      letter-spacing: 0.3px;
      font-weight: 600;
      margin-bottom: 4px;
    }
    .doviz-grid .d-item input {
      width: 100%;
      padding: 8px 10px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif;
      font-size: 13px;
      font-weight: 600;
      color: var(--text-1);
      background: #fff;
      border: 1px solid rgba(14, 165, 233, 0.2);
      border-radius: 8px;
      outline: none;
      transition: all 0.15s ease;
    }
    .doviz-grid .d-item input:focus {
      border-color: var(--sky);
      box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.12);
    }
    .doviz-grid .d-item .d-value {
      display: block;
      padding: 8px 10px;
      font-size: 13px;
      font-weight: 700;
      color: var(--sky);
    }
    .doviz-grid .hint { grid-column: 1 / -1; font-size: 10.5px; color: var(--sky); line-height: 1.4; }

    /* Form body */
    .form-body { padding: 20px; }
    .field { display: flex; flex-direction: column; gap: 6px; }
    .field-label {
      font-size: 11px;
      font-weight: 600;
      color: var(--text-2);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .field-control {
      width: 100%;
      padding: 11px 14px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif;
      font-size: 14px;
      font-weight: 600;
      color: var(--text-1);
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 10px;
      transition: all 0.2s ease;
      outline: none;
    }
    .field-control:focus {
      border-color: rgba(239, 68, 68, 0.5);
      box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
    }
    .field-control[readonly] {
      background: #f9fafb;
      color: var(--text-2);
      cursor: default;
    }
    select.field-control {
      background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
      background-position: right 0.6rem center;
      background-repeat: no-repeat;
      background-size: 1.4em 1.4em;
      padding-right: 2.6rem;
      -webkit-appearance: none;
      -moz-appearance: none;
      appearance: none;
      cursor: pointer;
    }
    .field-hint {
      margin-top: 4px;
      font-size: 10px;
      color: var(--text-3);
    }
    .field-hint.warn { color: var(--emerald); font-weight: 600; }
    .field-hint.kdv {
      padding: 5px 8px;
      background: #eff6ff;
      color: #0284c7;
      border-radius: 6px;
      font-weight: 600;
    }

    .fields-grid {
      display: grid;
      grid-template-columns: repeat(12, 1fr);
      gap: 14px;
    }
    .col-2  { grid-column: span 2; }
    .col-3  { grid-column: span 3; }
    .col-4  { grid-column: span 4; }
    .col-6  { grid-column: span 6; }
    .col-12 { grid-column: span 12; }

    /* Chip boxes */
    .chip-box {
      padding: 12px 14px;
      border-radius: 12px;
      text-align: center;
      border: 1px solid var(--border);
      background: #fafafa;
    }
    .chip-box .chip-label {
      font-size: 10.5px;
      font-weight: 600;
      color: var(--text-2);
      text-transform: uppercase;
      letter-spacing: 0.4px;
      display: flex; align-items: center; justify-content: center; gap: 5px;
    }
    .chip-box .chip-value { margin-top: 4px; font-size: 16px; font-weight: 700; line-height: 1.2; }
    .chip-box .chip-note { margin-top: 2px; font-size: 10px; font-weight: 500; }
    .chip-box.sky { background: var(--sky-soft); border-color: rgba(14, 165, 233, 0.2); }
    .chip-box.sky .chip-label, .chip-box.sky .chip-value { color: var(--sky); }
    .chip-box.emerald { background: var(--emerald-soft); border-color: rgba(5, 150, 105, 0.2); }
    .chip-box.emerald .chip-label, .chip-box.emerald .chip-value, .chip-box.emerald .chip-note { color: var(--emerald); }
    .chip-box.amber { background: var(--amber-soft); border-color: rgba(217, 119, 6, 0.25); }
    .chip-box.amber .chip-label, .chip-box.amber .chip-value, .chip-box.amber .chip-note { color: var(--amber); }
    .chip-box.red { background: var(--red-soft); border-color: rgba(239, 68, 68, 0.22); }
    .chip-box.red .chip-label, .chip-box.red .chip-value, .chip-box.red .chip-note { color: var(--red); }

    .stok-chips {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }

    /* Submit */
    .btn-save {
      width: 100%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 13px 20px;
      background: var(--red,#ef4444);
      color: #fff;
      border: none;
      border-radius: 12px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif;
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.2s ease;
      box-shadow: 0 4px 14px rgba(239, 68, 68, 0.2);
    }
    .btn-save:hover { background: var(--red); transform: translateY(-1px); box-shadow: 0 8px 20px rgba(239, 68, 68, 0.3); }
    .btn-save:active { transform: translateY(0); }

    .btn-all-sales {
      width: 100%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 13px 20px;
      background: #fff;
      color: var(--indigo);
      border: 1px solid rgba(79, 70, 229, 0.25);
      border-radius: 12px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .btn-all-sales:hover { background: var(--indigo-soft); border-color: var(--indigo); transform: translateY(-1px); }

    /* ═══════ HISTORY CARD (yeniden tasarim) ═══════ */
    .history-card {
      background: rgba(255,255,255,0.94);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      border: 1px solid var(--border);
      border-radius: 16px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.04);
      animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) 0.24s both;
      margin-bottom: 16px;
      overflow: hidden;
      position: relative;
    }
    .history-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--emerald), #34d399);
    }
    .history-head {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 18px 20px 14px;
      border-bottom: 1px solid var(--border);
    }
    .history-head .ico-box {
      width: 42px; height: 42px;
      border-radius: 12px;
      background: var(--emerald-soft);
      color: var(--emerald);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      flex-shrink: 0;
    }
    .history-head .head-text { flex: 1 1 auto; min-width: 0; }
    .history-head h4 {
      font-size: 14px;
      font-weight: 700;
      color: var(--text-1);
      line-height: 1.25;
      margin: 0;
    }
    .history-head .musteri {
      display: block;
      margin-top: 2px;
      font-size: 11.5px;
      color: var(--text-2);
      font-weight: 500;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .history-head .musteri strong { color: var(--text-1); font-weight: 600; }

    .history-stats {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 10px;
      padding: 16px 20px;
    }
    .history-stat {
      padding: 12px 10px;
      background: #fafafa;
      border: 1px solid var(--border);
      border-radius: 10px;
      text-align: center;
      transition: all 0.2s ease;
    }
    .history-stat:hover {
      background: #fff;
      border-color: rgba(5, 150, 105, 0.25);
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(0,0,0,0.04);
    }
    .history-stat .lbl {
      display: block;
      font-size: 10px;
      color: var(--text-2);
      text-transform: uppercase;
      letter-spacing: 0.4px;
      font-weight: 600;
      margin-bottom: 5px;
    }
    .history-stat .val {
      display: block;
      font-size: 14px;
      font-weight: 700;
      line-height: 1.2;
    }
    .history-stat.blue .val { color: #2563eb; }
    .history-stat.purple .val { color: #7c3aed; }
    .history-stat.green .val { color: var(--emerald); }
    .history-stat .sub {
      display: block;
      margin-top: 3px;
      font-size: 9.5px;
      color: var(--text-3);
      font-weight: 500;
    }

    .history-list {
      padding: 0 20px 18px;
    }
    .history-list-inner {
      background: #fafafa;
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow: hidden;
    }
    .history-list .list-title {
      display: flex;
      align-items: center;
      gap: 6px;
      padding: 10px 14px;
      background: #fff;
      border-bottom: 1px solid var(--border);
      font-size: 10.5px;
      color: var(--text-2);
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.4px;
    }
    .history-list .list-title i { color: var(--emerald); }
    .history-list .h-row {
      display: grid;
      grid-template-columns: auto 1fr auto;
      gap: 12px;
      align-items: center;
      padding: 11px 14px;
      font-size: 12px;
      border-bottom: 1px dashed #e5e7eb;
      transition: background 0.15s ease;
    }
    .history-list .h-row:hover { background: #fff; }
    .history-list .h-row:last-child { border-bottom: none; }
    .history-list .h-row .date {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 9px;
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 100px;
      font-size: 10.5px;
      font-weight: 600;
      color: var(--text-1);
      white-space: nowrap;
    }
    .history-list .h-row .date i { color: var(--text-3); font-size: 9px; }
    .history-list .h-row .qty {
      font-weight: 600;
      color: var(--text-1);
      font-size: 12.5px;
    }
    .history-list .h-row .qty .unit {
      font-size: 10px;
      color: var(--text-3);
      text-transform: uppercase;
      margin-left: 2px;
      letter-spacing: 0.3px;
    }
    .history-list .h-row .price {
      text-align: right;
      font-weight: 700;
      color: var(--emerald);
      font-size: 13px;
      white-space: nowrap;
    }
    .history-list .h-row .sub {
      display: block;
      margin-top: 2px;
      font-size: 10px;
      color: var(--text-3);
      font-weight: 500;
    }

    .empty-history {
      padding: 16px 18px;
      background: rgba(255,255,255,0.92);
      border: 1px dashed var(--border);
      border-radius: 14px;
      text-align: center;
      font-size: 13px;
      color: var(--text-2);
      margin-bottom: 16px;
    }
    .empty-history i { color: var(--text-3); margin-right: 6px; }

    /* Tanimli alan tablosu */
    .custom-fields {
      padding: 18px 20px;
    }
    .custom-fields h4 {
      font-size: 13px;
      font-weight: 700;
      color: var(--text-1);
      margin-bottom: 10px;
      display: flex; align-items: center; gap: 8px;
    }
    .custom-fields h4 i { color: var(--sky); font-size: 12px; }
    .custom-fields-grid {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 10px;
    }
    .custom-fields-grid .cf {
      padding: 10px 12px;
      background: #fafafa;
      border: 1px solid var(--border);
      border-radius: 10px;
    }
    .custom-fields-grid .cf-lbl {
      font-size: 10px;
      font-weight: 600;
      color: var(--text-3);
      text-transform: uppercase;
      letter-spacing: 0.3px;
      margin-bottom: 4px;
    }
    .custom-fields-grid .cf-val {
      font-size: 12px;
      color: var(--text-1);
      font-weight: 500;
      word-break: break-word;
    }

    /* ═══════ MODAL ═══════ */
    .akl-modal {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.42);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      z-index: 60;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 16px;
    }
    .akl-modal.is-open { display: flex; animation: fadeIn 0.2s ease both; }
    .akl-modal-dialog {
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 16px;
      box-shadow: 0 20px 50px rgba(15, 23, 42, 0.22);
      width: 100%;
      max-width: 760px;
      max-height: 88vh;
      display: flex;
      flex-direction: column;
      animation: modalIn 0.28s cubic-bezier(0.22, 1, 0.36, 1) both;
    }
    .akl-modal-head {
      display: flex; align-items: center; justify-content: space-between;
      gap: 10px; padding: 14px 18px;
      border-bottom: 1px solid var(--border);
    }
    .akl-modal-head h5 {
      font-size: 14px; font-weight: 700; color: var(--text-1);
      display: inline-flex; align-items: center; gap: 8px;
    }
    .akl-modal-head h5 i { color: var(--red); font-size: 13px; }
    .modal-close {
      background: transparent; border: none;
      font-size: 22px; line-height: 1;
      color: var(--text-2); cursor: pointer;
      width: 32px; height: 32px; border-radius: 8px;
      transition: all 0.15s ease;
    }
    .modal-close:hover { background: rgba(0,0,0,0.05); color: var(--red); }
    .akl-modal-body {
      padding: 18px; overflow-y: auto;
      font-size: 13px; color: var(--text-1);
    }
    .akl-modal-foot {
      padding: 12px 18px; border-top: 1px solid var(--border);
      background: #fafafa;
      display: flex; justify-content: flex-end;
      border-radius: 0 0 16px 16px;
    }
    .btn-modal-close {
      padding: 9px 20px; background: #64748b; color: #fff;
      border: none; border-radius: 10px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif; font-weight: 600; font-size: 13px;
      cursor: pointer; transition: all 0.15s ease;
    }
    .btn-modal-close:hover { background: #475569; }

    /* ═══════ ANIMATIONS ═══════ */
    @keyframes cardIn {
      from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
      to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
    }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes modalIn {
      from { opacity: 0; transform: translate3d(0, 14px, 0) scale(0.97); }
      to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
    }

    /* ═══════ MOBILE ═══════ */
    @media (max-width: 767px) {
      .top-header { height: 50px; }
      .header-inner { padding: 0 12px; gap: 10px; }
      .header-title { font-size: 15px; }
      .header-back { width: 30px; height: 30px; border-radius: 8px; }

      main { padding: 14px 12px 40px !important; }

      .urun-hero { padding: 14px 16px; }
      .urun-hero .ad { font-size: 15px; }
      .form-body { padding: 16px; }
      .field-control { font-size: 16px; padding: 11px 12px; }

      .price-grid { grid-template-columns: 1fr 1fr; gap: 8px; padding: 14px 14px 2px; }
      .price-pill { padding: 10px; }
      .price-pill .val { font-size: 14px; }

      .fields-grid { gap: 12px; }
      .col-2, .col-3, .col-4 { grid-column: span 6; }
      .col-6 { grid-column: span 12; }

      .urun-resim { padding: 12px 16px 0; }
      .urun-resim img { max-height: 220px; }

      .doviz-box { padding: 12px 16px; }
      .doviz-grid { grid-template-columns: 1fr; }

      .history-card { padding: 16px; }
      .history-stats { grid-template-columns: 1fr 1fr 1fr; gap: 8px; }
      .history-stat { padding: 8px 6px; }
      .history-stat .val { font-size: 12px; }
      .history-list .h-row { font-size: 11px; }
      .custom-fields { padding: 14px 16px; }
      .custom-fields-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 420px) {
      .history-stats { grid-template-columns: 1fr 1fr; }
    }
  </style>
</head>

<body OnLoad="document.frm.stkadt.focus();">

  <header class="top-header">
    <div class="header-inner">
      <a href="<?php echo htmlspecialchars($lgStokEkleBackUrl, ENT_QUOTES, 'UTF-8'); ?>" class="header-back" title="Geri Don">
        <i class="fa fa-arrow-left"></i>
      </a>
      <div class="header-divider"></div>
      <span class="header-title">
        <i class="fa-solid fa-cart-plus"></i>Stok Ekle
      </span>
    </div>
  </header>

  <main style="max-width:760px;margin:0 auto;padding:20px 24px 60px;">

    <!-- Urun Hero + Iskonto Price Pills + Resim + Doviz + Form -->
    <section class="glass-card">
      <div class="urun-hero">
        <span class="kod"><i class="fa-solid fa-barcode" style="margin-right:4px;"></i> <?php echo htmlspecialchars(tr($stokara['URUN KODU']), ENT_QUOTES, 'UTF-8'); ?></span>
        <div class="ad"><?php echo htmlspecialchars(tr($stokara['URUN ADI']), ENT_QUOTES, 'UTF-8'); ?></div>
      </div>

      <?php if (($iskontolu > 0 || $iskontolu2 > 0 || $iskontolu3 > 0) && $fiyatgorme === ''): ?>
      <div class="price-grid">
        <button type="button" onclick="setIndirimFiyat(this.value)" value="<?php echo format2_value($fiyati); ?>" class="price-pill list-price">
          <span class="pct">Liste Fiyati</span>
          <span class="val"><?php echo format2($fiyati); ?> &#8378;</span>
          <?php if ($dovizAktif): ?>
            <span class="doviz-sub"><?php echo htmlspecialchars((string) $dovizSembol, ENT_QUOTES, 'UTF-8'); ?> <?php echo format2($fiyatiDoviz); ?></span>
          <?php endif; ?>
        </button>

        <?php if ($iskonto1Fiyat !== null): ?>
          <button type="button" onclick="setIndirimFiyat(this.value)" value="<?php echo format2_value($iskonto1Fiyat); ?>" class="price-pill tone-amber">
            <span class="pct">%<?php echo $iskontolu; ?> Iskontolu</span>
            <span class="val"><?php echo format2($iskonto1Fiyat); ?> &#8378;</span>
            <?php if ($dovizAktif && $iskonto1FiyatDoviz !== null): ?>
              <span class="doviz-sub"><?php echo htmlspecialchars((string) $dovizSembol, ENT_QUOTES, 'UTF-8'); ?> <?php echo format2($iskonto1FiyatDoviz); ?></span>
            <?php endif; ?>
          </button>
        <?php endif; ?>

        <?php if ($iskonto2Fiyat !== null): ?>
          <button type="button" onclick="setIndirimFiyat(this.value)" value="<?php echo format2_value($iskonto2Fiyat); ?>" class="price-pill tone-emerald">
            <span class="pct">%<?php echo $iskontolu2; ?> Iskontolu</span>
            <span class="val"><?php echo format2($iskonto2Fiyat); ?> &#8378;</span>
            <?php if ($dovizAktif && $iskonto2FiyatDoviz !== null): ?>
              <span class="doviz-sub"><?php echo htmlspecialchars((string) $dovizSembol, ENT_QUOTES, 'UTF-8'); ?> <?php echo format2($iskonto2FiyatDoviz); ?></span>
            <?php endif; ?>
          </button>
        <?php endif; ?>

        <?php if ($iskonto3Fiyat !== null): ?>
          <button type="button" onclick="setIndirimFiyat(this.value)" value="<?php echo format2_value($iskonto3Fiyat); ?>" class="price-pill tone-indigo">
            <span class="pct">%<?php echo $iskontolu3; ?> Iskontolu</span>
            <span class="val"><?php echo format2($iskonto3Fiyat); ?> &#8378;</span>
            <?php if ($dovizAktif && $iskonto3FiyatDoviz !== null): ?>
              <span class="doviz-sub"><?php echo htmlspecialchars((string) $dovizSembol, ENT_QUOTES, 'UTF-8'); ?> <?php echo format2($iskonto3FiyatDoviz); ?></span>
            <?php endif; ?>
          </button>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php
      if ($resimlistoklistesi == 1) {
        $stmt = $dbh->prepare("SELECT * FROM " . $firma . "FIRMDOC WHERE INFOREF = :stokid AND INFOTYP = 20");
        $stmt->execute([':stokid' => $stokid]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);

        $base64_image = '';
        if (is_array($res) && isset($res['LDATA']) && is_string($res['LDATA']) && trim($res['LDATA']) !== '') {
          $hexData = trim($res['LDATA']);
          if (str_starts_with($hexData, '0x') || str_starts_with($hexData, '0X')) {
            $hexData = substr($hexData, 2);
          }
          if ($hexData !== '' && (strlen($hexData) % 2) === 0) {
            $bin = hex2bin($hexData);
            if (is_string($bin) && $bin !== '') {
              $base64_image = base64_encode($bin);
            }
          }
        }
        echo '<div class="urun-resim">';
        if ($base64_image !== '' && $base64_image !== '0') {
          echo '<img src="data:image/jpeg;base64,' . $base64_image . '" alt="Urun Resmi" />';
        } else {
          echo '<img src="tm/rs/urunyok.jpg" alt="Urun Resmi Yok">';
        }
        echo '</div>';
      }
      ?>

      <?php
      $kdvhesapla = "kdvhesapla();";
      $fiyatkapalimi = "text";
      if ($siparistefiyatduzenle == '1') {
        $fiyatkapalimi = "hidden";
      }
      if ($dovizlicalis == '1') {
        $doviz = $_SESSION['doviz'] ?? '0';
        if ($doviz != "0") {
          $dovizfiyat = dovizkuru_bul($fisid);
          if ($dovizfiyat > 0) {
            $dovfiy = $fiyati / $dovizfiyat;
          } else {
            $dovfiy = $fiyati;
            $dovizfiyat = 1;
          }
          $kdvhesapla = "dovizkuruhesapla();";
          ?>
          <div class="doviz-box">
            <div class="doviz-head"><i class="fa-solid fa-money-bill-transfer"></i> Dovizli Fiyat</div>
            <div class="doviz-grid">
              <div class="d-item">
                <div class="d-lbl">Doviz Birim</div>
                <span class="d-value"><?php echo kusuratpara($dovfiy); ?></span>
              </div>
              <div class="d-item">
                <div class="d-lbl">Doviz Kur</div>
                <input type="text" name="dovizkuru" id="dovizkuru" onkeyup="<?php echo $kdvhesapla; ?>" value="<?php echo kusuratpara($dovizfiyat); ?>">
              </div>
              <div class="d-item">
                <div class="d-lbl">Dovizli Fiyat</div>
                <input type="text" name="dovizlif" id="dovizlif" value="<?php echo kusuratpara($dovfiy); ?>">
              </div>
              <div class="hint">
                <span id="dovizlikdvtutar"></span> <span id="dovizlikdvlitutar" style="margin-left:10px;"></span>
              </div>
            </div>
          </div>
        <?php }
      } ?>

      <form name="frm" id="frm" method="POST" action="lg_fis.php?stokhareket=<?php echo $fisid; ?>" onsubmit="return bak()" autocomplete="off">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="stkid" id="stkid" value="<?php echo $stokara['URUN ID']; ?>">
        <input type="hidden" name="kontrol" id="kontrol" value="<?php echo uniqid(); ?>" />
        <input type="hidden" name="cariid" id="cariid" value="<?php echo $cariid; ?>">
        <input type="hidden" name="stkbirim" id="stkbirim" value="<?php echo $stokara['BIRIMID']; ?>">

        <div class="form-body">
          <div class="fields-grid">
            <div class="col-3 field">
              <label class="field-label" for="stkadt">Miktar</label>
              <input type="number" name="stkadt" id="stkadt" onkeyup="<?php echo $kdvhesapla; ?>" value=""
                step="0.01" inputmode="decimal" required autofocus
                class="field-control" aria-label="Miktar">
            </div>

            <div class="col-3 field">
              <label class="field-label" for="stbrm">Birim</label>
              <select name="stbrm" id="stbrm" class="field-control">
                <?php
                $stmt = $dbh->prepare("SELECT BIRIM.CODE AS BIRIM, ICBIRIM.CONVFACT2 AS CARPAN FROM " . $firma . "ITMUNITA ICBIRIM LEFT JOIN " . $firma . "UNITSETL BIRIM ON ICBIRIM.UNITLINEREF = BIRIM.LOGICALREF LEFT JOIN " . $firma . "ITEMS STK ON ICBIRIM.ITEMREF = STK.LOGICALREF WHERE ICBIRIM.ITEMREF = :stokid AND BIRIM.UNITSETREF = STK.UNITSETREF ORDER BY ICBIRIM.LINENR DESC");
                $stmt->execute([':stokid' => $stokid]);
                while ($birimliste = $stmt->fetch(PDO::FETCH_ASSOC)) {
                  ?>
                  <option value="<?php echo htmlspecialchars((string) $birimliste['CARPAN'], ENT_QUOTES, 'UTF-8'); ?>" selected>
                    <?php echo htmlspecialchars(tr($birimliste['BIRIM']), ENT_QUOTES, 'UTF-8') . " = " . kusuratsifir($birimliste['CARPAN']); ?>
                  </option>
                <?php } ?>
              </select>
            </div>

            <div class="col-4 field">
              <label class="field-label" for="stkfyt">Fiyat (TL)</label>
              <input type="text" name="stkfyt" id="stkfyt" onkeyup="<?php echo $kdvhesapla; ?>"
                value="<?php echo format2($fiyati); ?>" inputmode="decimal"
                <?php echo $fiyatdeğiştirme; ?>
                class="field-control" <?php echo $fiyatgorme === 'invisible' ? 'style="visibility:hidden;"' : ''; ?>>
              <?php if ($dovizAktif && $fiyatiDoviz !== null): ?>
                <div class="field-hint kdv">
                  <i class="fa-solid fa-coins" style="margin-right:3px;"></i>
                  <?php echo htmlspecialchars((string) $dovizSembol, ENT_QUOTES, 'UTF-8'); ?>
                  <span id="dovizli_fiyat_gosterim"><?php echo format2($fiyatiDoviz); ?></span>
                  <span style="opacity:0.7;">(Kur: <?php echo number_format($dovizKuru, 4, ',', '.'); ?>)</span>
                </div>
              <?php endif; ?>
            </div>

            <div class="col-2 field">
              <label class="field-label" for="stkkdv">KDV(%)</label>
              <input type="text" name="stkkdv" id="stkkdv" onkeyup="<?php echo $kdvhesapla; ?>"
                value="<?php echo kusuratsifir($resmiKdvZorunlu ? $resmiKdvOrani : $stokara['KDV']); ?>"
                inputmode="decimal" <?php echo $resmiKdvZorunlu ? 'readonly' : ''; ?>
                class="field-control">
              <?php if ($resmiKdvZorunlu): ?>
                <div class="field-hint warn"><i class="fa-solid fa-lock"></i> Resmi mod: %<?php echo rtrim(rtrim(number_format($resmiKdvOrani, 2, '.', ''), '0'), '.'); ?></div>
              <?php endif; ?>
              <div id="kdvtutar" class="field-hint"></div>
              <div id="kdvlitutar" class="field-hint"></div>
            </div>

            <!-- Bek Siparis + Mevcut Stok chip'leri -->
            <?php
            $bekleyenSiparis = bekleyen_siparis($stokid);
            $mevcutStok = $stokara['MIKTAR'];
            $stokDurumu = ''; $stokBoxClass = ''; $stokIcon = '';
            if ($mevcutStok > $bekleyenSiparis) {
              $stokDurumu = 'Yeterli'; $stokBoxClass = 'emerald'; $stokIcon = 'fa-circle-check';
            } elseif ($mevcutStok > 0 && $mevcutStok <= $bekleyenSiparis) {
              $stokDurumu = 'Dusuk'; $stokBoxClass = 'amber'; $stokIcon = 'fa-triangle-exclamation';
            } else {
              $stokDurumu = 'Yetersiz'; $stokBoxClass = 'red'; $stokIcon = 'fa-circle-xmark';
            }
            ?>
            <div class="col-12">
              <div class="stok-chips">
                <div class="chip-box sky">
                  <div class="chip-label"><i class="fa-solid fa-dolly"></i> Bek. Siparis</div>
                  <div class="chip-value"><?php echo kusuratadet($bekleyenSiparis); ?></div>
                </div>
                <div class="chip-box <?php echo $stokBoxClass; ?> <?php echo $miktargorme; ?>">
                  <div class="chip-label"><i class="fa-solid <?php echo $stokIcon; ?>"></i> Mevcut Stok</div>
                  <div class="chip-value"><?php echo kusuratadet($mevcutStok); ?></div>
                  <div class="chip-note">(<?php echo htmlspecialchars($stokDurumu, ENT_QUOTES, 'UTF-8'); ?>)</div>
                </div>
              </div>
            </div>

            <div class="col-12 field">
              <label class="field-label" for="aciklama">Satir Aciklamasi</label>
              <input type="text" name="aciklama" id="aciklama" class="field-control" value=""
                placeholder="Aciklama giriniz..." autocomplete="off">
            </div>

            <div class="col-12">
              <button class="btn-save" type="submit">
                <i class="fa-solid fa-plus"></i> Ekle
                <i class="fa-solid fa-arrow-right" style="margin-left:4px;font-size:11px;opacity:0.85;"></i>
              </button>
            </div>
          </div>
        </div>
      </form>
    </section>

    <?php
    if (m_p_yetki($terminalkullanici, 'SP3') == 1 || $yetkidurum == 0) {
      $stmt = $dbh->prepare("SELECT TOP 5 S.PRICE, S.AMOUNT, S.DATE_ FROM " . $firmadonem . "STLINE S WHERE S.STOCKREF = :stokid AND S.LINETYPE = 0 AND S.CANCELLED = 0 AND S.CLIENTREF = :cariid AND S.TRCODE IN(7,8) ORDER BY S.DATE_ DESC");
      $stmt->execute([':stokid' => $stokid, ':cariid' => $cariid]);
      $musteriSatislari = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (!empty($musteriSatislari)) {
        $toplamMiktar = array_sum(array_column($musteriSatislari, 'AMOUNT'));
        $ortalamaMiktar = $toplamMiktar / count($musteriSatislari);
        $ortalamaFiyat = array_sum(array_column($musteriSatislari, 'PRICE')) / count($musteriSatislari);
        $sonSatis = $musteriSatislari[0];
        ?>
        <div class="history-card">
          <div class="history-head">
            <span class="ico-box"><i class="fa-solid fa-user-tie"></i></span>
            <div class="head-text">
              <h4>Bu Musterinin Satis Gecmisi</h4>
              <span class="musteri"><strong><?php echo htmlspecialchars($caribul['CLIENTREF'] ? cari_bul($cariid) : '', ENT_QUOTES, 'UTF-8'); ?></strong></span>
            </div>
          </div>

          <div class="history-stats">
            <div class="history-stat blue">
              <span class="lbl">Son Satis</span>
              <span class="val"><?php echo date("d.m.Y", strtotime((string) $sonSatis['DATE_'])); ?></span>
            </div>
            <div class="history-stat purple">
              <span class="lbl">Ort. Miktar</span>
              <span class="val"><?php echo kusuratadet($ortalamaMiktar); ?> <?php echo htmlspecialchars((string) trcevir($stokara['BIRIM']), ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="history-stat green">
              <span class="lbl">Ort. Fiyat</span>
              <span class="val"><?php echo format2($ortalamaFiyat); ?> &#8378;</span>
              <?php if ($dovizAktif): ?>
                <span class="sub"><?php echo htmlspecialchars((string) $dovizSembol, ENT_QUOTES, 'UTF-8') . ' ' . format2($ortalamaFiyat / $dovizKuru); ?></span>
              <?php endif; ?>
            </div>
          </div>

          <div class="history-list">
            <div class="history-list-inner">
              <div class="list-title"><i class="fa-solid fa-clock-rotate-left"></i> Son <?php echo count($musteriSatislari); ?> Satis Detayi</div>
              <?php foreach ($musteriSatislari as $satis): ?>
                <div class="h-row">
                  <span class="date"><i class="fa-regular fa-calendar"></i><?php echo date("d.m.Y", strtotime((string) $satis['DATE_'])); ?></span>
                  <span class="qty"><?php echo kusuratadet($satis['AMOUNT']); ?><span class="unit"><?php echo htmlspecialchars((string) trcevir($stokara['BIRIM']), ENT_QUOTES, 'UTF-8'); ?></span></span>
                  <span class="price">
                    <?php echo format2($satis['PRICE']); ?> &#8378;
                    <?php if ($dovizAktif): ?>
                      <span class="sub"><?php echo htmlspecialchars((string) $dovizSembol, ENT_QUOTES, 'UTF-8') . ' ' . format2($satis['PRICE'] / $dovizKuru); ?></span>
                    <?php endif; ?>
                  </span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      <?php } else { ?>
        <div class="empty-history">
          <i class="fa-solid fa-circle-info"></i>
          Bu musteriye daha once bu urunden satis yapilmamis.
        </div>
      <?php } ?>
    <?php } ?>

    <?php if ($miktargorme === ''): ?>
    <button data-id="<?php echo $fisid . ',' . $stokid . ',' . $stokara['BIRIM']; ?>"
            class="userinfo btn-all-sales" type="button">
      <i class="fa-solid fa-list"></i> Tum Satislari Goruntule
    </button>
    <?php endif; ?>

    <?php
    if ($tanimlialan == 1) {
      $stmt = $dbh->prepare("SELECT TEXTFLDS1, TEXTFLDS2, TEXTFLDS3 FROM " . $firma . "DEFNFLDSCARDV WHERE PARENTREF = :stokid AND MODULENR = 6");
      $stmt->execute([':stokid' => $stokid]);
      $tanim = $stmt->fetch(PDO::FETCH_ASSOC);
      $alan1 = is_array($tanim) ? (string) ($tanim['TEXTFLDS1'] ?? '') : '';
      $alan2 = is_array($tanim) ? (string) ($tanim['TEXTFLDS2'] ?? '') : '';
      $alan3 = is_array($tanim) ? (string) ($tanim['TEXTFLDS3'] ?? '') : '';
      ?>
      <div class="glass-card" style="margin-top:16px;">
        <div class="custom-fields">
          <h4><i class="fa-solid fa-tags"></i> Ozel Tanimli Alanlar</h4>
          <div class="custom-fields-grid">
            <div class="cf">
              <div class="cf-lbl">Alan 1</div>
              <div class="cf-val"><?php echo htmlspecialchars((string) $alan1, ENT_QUOTES, 'UTF-8') ?: '-'; ?></div>
            </div>
            <div class="cf">
              <div class="cf-lbl">Alan 2</div>
              <div class="cf-val"><?php echo htmlspecialchars((string) $alan2, ENT_QUOTES, 'UTF-8') ?: '-'; ?></div>
            </div>
            <div class="cf">
              <div class="cf-lbl">Alan 3</div>
              <div class="cf-val"><?php echo htmlspecialchars((string) $alan3, ENT_QUOTES, 'UTF-8') ?: '-'; ?></div>
            </div>
          </div>
        </div>
      </div>
    <?php } ?>

  </main>

  <!-- Modal -->
  <div id="empModal" class="akl-modal" role="dialog" aria-modal="true" aria-labelledby="empModalTitle">
    <div class="akl-modal-dialog">
      <div class="akl-modal-head">
        <h5 id="empModalTitle"><i class="fa-solid fa-chart-line"></i> Stok Satis Detayi</h5>
        <button id="empModalClose" type="button" class="modal-close" aria-label="Kapat">&times;</button>
      </div>
      <div id="empModalBody" class="akl-modal-body">
        <!-- AJAX ile yuklenecek icerik -->
      </div>
      <div class="akl-modal-foot">
        <button id="empModalCloseBtn" type="button" class="btn-modal-close">Kapat</button>
      </div>
    </div>
  </div>

    <?php include_once(__DIR__ . '/ux_katman.php'); ?>
</body>
<script type='text/javascript'>
  // Animation render bug fix
  window.addEventListener('load', function(){
    requestAnimationFrame(function(){
      document.querySelectorAll('.glass-card, .history-card').forEach(function(el){ void el.offsetHeight; });
    });
  });

  $(document).ready(function () {

    $('.userinfo').click(function (e) {
      e.preventDefault();
      var userid = $(this).data('id');

      $('#empModalBody').html('<p style="text-align:center;padding:24px;color:#6b7280;"><i class="fa-solid fa-circle-notch fa-spin" style="margin-right:8px;"></i> Yukleniyor...</p>');
      $('#empModal').addClass('is-open');

      $.ajax({
        url: 'lg_stoksonalisx.php',
        type: 'post',
        data: { userid: userid },
        success: function (response) {
          $('#empModalBody').html(response);
        },
        error: function() {
          $('#empModalBody').html('<p style="text-align:center;padding:24px;color:var(--red,#6F1022);">Veriler yuklenirken bir hata olustu.</p>');
        }
      });
    });

    function closeModal() {
      $('#empModal').removeClass('is-open');
    }

    $('#empModalClose, #empModalCloseBtn').click(closeModal);

    $('#empModal').click(function(event) {
      if ($(event.target).is('#empModal')) {
        closeModal();
      }
    });

    $(document).keyup(function(e) {
      if (e.key === "Escape" && $('#empModal').hasClass('is-open')) {
        closeModal();
      }
    });
  });

  function gizle() {
    document.getElementById("gizli").style.display = 'none';
  }
  function goster() {
    if (document.getElementById("gizli").style.display == 'none') {
      document.getElementById("gizli").style.display = '';
    }
    else if (document.getElementById("gizli").style.display == '') {
      document.getElementById("gizli").style.display = 'none';
    }
  }
  function bak() {

    if (document.getElementById("stkadt").value.length > 7) {
      if (window.toast) { toast("EN FAZLA 7 BASAMAK GİRİNİZ!!!", 'warning'); } else { alert("EN FAZLA 7 BASAMAK GİRİNİZ!!!"); }
      return false;
    }
    if (document.getElementById("stkadt").value == "" || document.getElementById("stkadt").value == 0) {

      if (window.toast) { toast("LÜTFEN MİKTAR GİRİNİZ!!!", 'warning'); } else { alert("LÜTFEN MİKTAR GİRİNİZ!!!"); }
      return false;
    }

    if (document.getElementById("stkfyt").value == "" || document.getElementById("stkfyt").value == 0) {

      if (window.toast) { toast("LÜTFEN FİYAT GİRİNİZ!!!", 'warning'); } else { alert("LÜTFEN FİYAT GİRİNİZ!!!"); }
      return false;
    }
  }

  function iskontoartir() {
    {
      var f = document.getElementById("iskontoartir").value;


      document.getElementById("stkfyt").value = f;
    }
  }

  function degistir1() {
    var f = document.getElementById("yenifiyat1").value;


    document.getElementById("stkfyt").value = f;
  }
  function degistir2() {
    var f = document.getElementById("yenifiyat2").value;


    document.getElementById("stkfyt").value = f;
  }
  function degistir3() {
    var f = document.getElementById("yenifiyat3").value;


    document.getElementById("stkfyt").value = f;
  }
  function degistir4() {
    var f = document.getElementById("yenifiyat4").value;


    document.getElementById("stkfyt").value = f;
  }



  function dovizkuruhesapla() {
    var stokkdv = document.getElementById('stkkdv').value;
    var fiyattl = document.getElementById('stkfyt').value;
    var fiyatdoviz = document.getElementById('dovizkuru').value;
    var stokadet = document.getElementById('stkadt').value;
    var dovizli = (stokadet * fiyattl) / fiyatdoviz;
    document.getElementById('dovizlif').value = (dovizli).toFixed(2);


    var dovizlikdvoran = dovizli * (stokkdv / 100);
    document.getElementById('dovizlikdvtutar').innerHTML = "%" + stokkdv + " kdv =" + (dovizlikdvoran).toFixed(2);
    document.getElementById('dovizlikdvlitutar').innerHTML = "NET=" + (dovizli + dovizlikdvoran).toFixed(2);

    var kdvoran = (stokadet * fiyattl) * (stokkdv / 100);
    document.getElementById('kdvtutar').innerHTML = "%" + stokkdv + " kdv =" + kdvoran.toFixed(2);
    document.getElementById('kdvlitutar').innerHTML = "NET=" + ((stokadet * fiyattl) + kdvoran).toFixed(2);
  }

  function kdvhesapla() {
    var stokkdv = document.getElementById('stkkdv').value;
    if (stokkdv > 0) {
      var fiyattl = document.getElementById('stkfyt').value;
      var stokadet = document.getElementById('stkadt').value;
      var kdvoran = (stokadet * fiyattl) * (stokkdv / 100);
      document.getElementById('kdvtutar').innerHTML = "%" + stokkdv + " kdv =" + kdvoran.toFixed(2);
      document.getElementById('kdvlitutar').innerHTML = "NET=" + ((stokadet * fiyattl) + kdvoran).toFixed(2);
    }

    // Dövizli fiyat güncelleme
    <?php if ($dovizAktif): ?>
    guncelDovizliFiyat();
    <?php endif; ?>
  }

  <?php if ($dovizAktif): ?>
  function guncelDovizliFiyat() {
    var fiyattl = document.getElementById('stkfyt').value;
    var dovizKuru = <?php echo $dovizKuru; ?>;
    if (dovizKuru > 0 && fiyattl > 0) {
      var dovizliFiyat = (parseFloat(fiyattl) / dovizKuru).toFixed(2);
      var gosterimElem = document.getElementById('dovizli_fiyat_gosterim');
      if (gosterimElem) {
        gosterimElem.innerHTML = dovizliFiyat;
      }
    }
  }
  <?php endif; ?>

  function setIndirimFiyat(fiyat) {
    var fiyatNum = parseFloat(String(fiyat).replace(',', '.'));
    if (!isNaN(fiyatNum)) {
      document.getElementById('stkfyt').value = fiyatNum.toFixed(2);
    } else {
      document.getElementById('stkfyt').value = fiyat;
    }
    kdvhesapla();
  }

  // Bootstrap tooltip kaldırıldı (Bootstrap JS yüklü değil, native title attribute kullanılıyor)

  <?php if ($resmiKdvZorunlu): ?>
  document.addEventListener('DOMContentLoaded', function() {
    var kdvInput = document.getElementById('stkkdv');
    if (kdvInput) {
      kdvInput.value = '<?php echo kusuratsifir($resmiKdvOrani); ?>';
      kdvInput.setAttribute('readonly', 'readonly');
    }
    kdvhesapla();
  });
  <?php endif; ?>

  // Masaüstünde Enter tuşu ile otomatik form submit
  $(document).ready(function() {
    // Sadece masaüstü cihazlarda Enter tuşu ile submit (mobilde klavye sorunlarını önlemek için)
    if (window.innerWidth >= 1024) {
      $('#stkadt, #stkfyt, #stkkdv, #aciklama').on('keypress', function(e) {
        if (e.which === 13) { // Enter tuşu
          e.preventDefault();
          $('#frm').submit();
        }
      });
    }
  });
</script>

</html>
