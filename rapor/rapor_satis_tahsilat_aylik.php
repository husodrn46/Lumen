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

$currentYear = (int) date('Y');
$year = (isset($_GET['year']) && is_numeric($_GET['year'])) ? (int) $_GET['year'] : $currentYear;
if ($year < 2020 || $year > $currentYear + 1) {
    $year = $currentYear;
}

$startDate = sprintf('%04d-01-01', $year);
$endDate = sprintf('%04d-01-01', $year + 1);

// Varsayılan kasalar
$varsayilan_kasa_ids = [2, 1019, 1020, 1021];
$kasa_secim_uyari = '';

// URL'den seçili kasaları al (?kasa[]=2&kasa[]=1019 ...)
$secili_kasa_ham = $_GET['kasa'] ?? $varsayilan_kasa_ids;
if (!is_array($secili_kasa_ham)) {
    $secili_kasa_ham = [$secili_kasa_ham];
}

$secili_kasa_ids = [];
foreach ($secili_kasa_ham as $kasa_raw) {
    $kasa_id = (int) $kasa_raw;
    if ($kasa_id > 0) {
        $secili_kasa_ids[$kasa_id] = $kasa_id;
    }
}
$secili_kasa_ids = array_values($secili_kasa_ids);

// Tüm kasa kartlarını al (arama kutusu için)
$tum_kasalar = [];
$kasa_isimleri = [];
try {
    $kasa_sorgu = $dbh->prepare("
        SELECT LOGICALREF, CODE, NAME
        FROM {$firma}KSCARD
        ORDER BY CODE
    ");
    $kasa_sorgu->execute();
    while ($kasa = $kasa_sorgu->fetch(PDO::FETCH_ASSOC)) {
        $id = (int) $kasa['LOGICALREF'];
        $tum_kasalar[$id] = ['CODE' => (string) $kasa['CODE'], 'NAME' => (string) $kasa['NAME']];
    }
} catch (PDOException $e) {
    die("Veritabanı hatası (Kasa isimleri): " . $e->getMessage());
}

// Geçerli kasa seçimlerini belirle
if ($tum_kasalar !== []) {
    $kasa_ids = array_values(array_filter($secili_kasa_ids, static fn($id): bool => isset($tum_kasalar[$id])));

    if ($kasa_ids === []) {
        $kasa_ids = array_values(array_filter($varsayilan_kasa_ids, static fn($id): bool => isset($tum_kasalar[$id])));
        if ($kasa_ids === []) {
            $kasa_ids = array_slice(array_keys($tum_kasalar), 0, 4);
        }
    }

    if (count($kasa_ids) > 12) {
        $kasa_ids = array_slice($kasa_ids, 0, 12);
        $kasa_secim_uyari = 'En fazla 12 kasa gösterilebilir. İlk 12 seçim kullanıldı.';
    }

    foreach ($kasa_ids as $kasa_id) {
        $kasa_isimleri[$kasa_id] = $tum_kasalar[$kasa_id];
    }
} else {
    $kasa_ids = [];
    $kasa_secim_uyari = 'Kasa kartı bulunamadı.';
}

$secili_kasa_sayisi = count($kasa_ids);

function formatTutar(float|int|string $tutar, bool $paraBirimi = true): string
{
    $formatli = number_format((float) $tutar, 2, ',', '.');
    return $paraBirimi ? $formatli . " ₺" : $formatli;
}

$monthNames = [1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos', 9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'];

    $salesMonthly = array_fill(1, 12, 0.0);
    $kasaMonthlyTotal = array_fill(1, 12, 0.0);
    $clMonthlyTotal = array_fill(1, 12, 0.0);
    $cekMonthlyTotal = array_fill(1, 12, 0.0);
    $senetMonthlyTotal = array_fill(1, 12, 0.0);
    $kasaBreakdown = [];
    foreach ($kasa_ids as $kasa_id) {
        $kasaBreakdown[$kasa_id] = array_fill(1, 12, 0.0);
    }

// 1) Aylık satış (AKL% ürünleri, TRCODE 7/8, KDV dahil)
$sqlSales = "
    SELECT
        MONTH(SL.DATE_)                                        AS M,
        SUM(ISNULL(SL.LINENET, (SL.PRICE * SL.AMOUNT - SL.DISTDISC)) + ISNULL(SL.VATAMNT, 0)) AS NET
    FROM {$firmadonem}STLINE SL WITH(NOLOCK)
    JOIN {$firma}ITEMS ITM ON SL.STOCKREF = ITM.LOGICALREF
    WHERE ITM.CODE LIKE 'AKL%'
      AND SL.TRCODE IN (7,8)
      AND SL.DATE_ >= :startDate
      AND SL.DATE_ < :endDate
      AND SL.CANCELLED = 0
      AND SL.LINETYPE = 0
    GROUP BY MONTH(SL.DATE_);
";
$stmtSales = $dbh->prepare($sqlSales);
$stmtSales->execute([':startDate' => $startDate, ':endDate' => $endDate]);
while ($r = $stmtSales->fetch(PDO::FETCH_ASSOC)) {
    $m = (int) ($r['M'] ?? 0);
    if ($m >= 1 && $m <= 12) {
        $salesMonthly[$m] = (float) ($r['NET'] ?? 0);
    }
}

// 2) Aylık tahsilat (Kasa bazlı) - SIGN=0, TRCODE=11, CANCELLED=0
if ($kasa_ids !== []) {
    $placeholders = [];
    $params = [':startDate' => $startDate, ':endDate' => $endDate];
    foreach ($kasa_ids as $idx => $kasa_id) {
        $ph = ':kasa' . $idx;
        $placeholders[] = $ph;
        $params[$ph] = (int) $kasa_id;
    }

    $sqlKasa = "
        SELECT
            KSLINES.CARDREF AS KASA_ID,
            MONTH(KSLINES.DATE_) AS M,
            SUM(KSLINES.AMOUNT) AS TUTAR
        FROM {$firmadonem}KSLINES KSLINES WITH(NOLOCK)
        WHERE KSLINES.CARDREF IN (" . implode(',', $placeholders) . ")
          AND KSLINES.SIGN = 0
          AND KSLINES.TRCODE = 11
          AND KSLINES.CANCELLED = 0
          AND KSLINES.DATE_ >= :startDate
          AND KSLINES.DATE_ < :endDate
        GROUP BY KSLINES.CARDREF, MONTH(KSLINES.DATE_);
    ";

    $stmtKasa = $dbh->prepare($sqlKasa);
    $stmtKasa->execute($params);
    while ($r = $stmtKasa->fetch(PDO::FETCH_ASSOC)) {
        $kasaId = (int) ($r['KASA_ID'] ?? 0);
        $m = (int) ($r['M'] ?? 0);
        $t = (float) ($r['TUTAR'] ?? 0);
        if ($kasaId > 0 && $m >= 1 && $m <= 12 && isset($kasaBreakdown[$kasaId])) {
            $kasaBreakdown[$kasaId][$m] = $t;
            $kasaMonthlyTotal[$m] += $t;
        }
    }
}

// 3) Aylık tahsilat (Genel/Cari)
$sqlCl = "
    SELECT
        MONTH(DATE_) AS M,
        SUM(AMOUNT)  AS TUTAR
    FROM {$firmadonem}CLFLINE WITH(NOLOCK)
    WHERE TRCODE IN (1, 4, 20, 61, 62, 70)
      AND SIGN = 1
      AND DATE_ >= :startDate
      AND DATE_ < :endDate
      AND CANCELLED = 0
    GROUP BY MONTH(DATE_);
";
$stmtCl = $dbh->prepare($sqlCl);
$stmtCl->execute([':startDate' => $startDate, ':endDate' => $endDate]);
    while ($r = $stmtCl->fetch(PDO::FETCH_ASSOC)) {
        $m = (int) ($r['M'] ?? 0);
        if ($m >= 1 && $m <= 12) {
            $clMonthlyTotal[$m] = (float) ($r['TUTAR'] ?? 0);
        }
    }

    // 4) Aylık tahsilat (Çek/Senet) - CLFLINE alma tarihi bazlı
    $sqlCekSenet = "
        SELECT
            CF.TRCODE,
            MONTH(CF.DATE_) AS M,
            SUM(CF.AMOUNT) AS TUTAR
        FROM {$firmadonem}CLFLINE CF WITH(NOLOCK)
        WHERE CF.TRCODE IN (61, 62)
          AND CF.SIGN = 1
          AND CF.DATE_ >= :startDate
          AND CF.DATE_ < :endDate
          AND CF.CANCELLED = 0
        GROUP BY CF.TRCODE, MONTH(CF.DATE_);
    ";
    $stmtCekSenet = $dbh->prepare($sqlCekSenet);
    $stmtCekSenet->execute([':startDate' => $startDate, ':endDate' => $endDate]);
    while ($r = $stmtCekSenet->fetch(PDO::FETCH_ASSOC)) {
        $trcode = (int) ($r['TRCODE'] ?? 0);
        $m = (int) ($r['M'] ?? 0);
        $t = (float) ($r['TUTAR'] ?? 0);
        if ($m < 1 || $m > 12) {
            continue;
        }
        if ($trcode === 61) {
            $cekMonthlyTotal[$m] = $t;
        } elseif ($trcode === 62) {
            $senetMonthlyTotal[$m] = $t;
        }
    }

    // KPI hesapları
    $yearlySales = array_sum($salesMonthly);
    $yearlyKasa = array_sum($kasaMonthlyTotal);
    $yearlyCek = array_sum($cekMonthlyTotal);
    $yearlySenet = array_sum($senetMonthlyTotal);
    $yearlyTahsilat = $yearlyKasa + $yearlyCek + $yearlySenet;
    $yearlyCl = array_sum($clMonthlyTotal);
    $ratioTahsilat = $yearlySales > 0 ? ($yearlyTahsilat / $yearlySales) : 0.0;
    $diffTahsilatCl = $yearlyTahsilat - $yearlyCl;

    // Tablo satırları ve chart dizileri
    $chartLabels = [];
    $chartSales = [];
    $chartKasa = [];
    $chartCek = [];
    $chartSenet = [];
    $chartTahsilat = [];
    $chartCl = [];
    $monthsData = [];
    for ($m = 1; $m <= 12; $m++) {
        $kasa = $kasaMonthlyTotal[$m];
        $cek = $cekMonthlyTotal[$m];
        $senet = $senetMonthlyTotal[$m];
        $tahsilat = $kasa + $cek + $senet;

        $chartLabels[] = $monthNames[$m];
        $chartSales[] = $salesMonthly[$m];
        $chartKasa[] = $kasa;
        $chartCek[] = $cek;
        $chartSenet[] = $senet;
        $chartTahsilat[] = $tahsilat;
        $chartCl[] = $clMonthlyTotal[$m];

        $monthsData[] = [
            'M' => $m,
            'S' => $salesMonthly[$m],
            'K' => $kasa,
            'CK' => $cek,
            'SN' => $senet,
            'T' => $tahsilat,
            'C' => $clMonthlyTotal[$m],
            'D' => $tahsilat - $clMonthlyTotal[$m],
        ];
    }

// Chart renk paleti (kasa datasetleri için)
$kasaPalette = [
    ['bg' => 'rgba(59,130,246,0.65)', 'border' => 'rgba(59,130,246,1)'],
    ['bg' => 'rgba(16,185,129,0.65)', 'border' => 'rgba(16,185,129,1)'],
    ['bg' => 'rgba(245,158,11,0.65)', 'border' => 'rgba(245,158,11,1)'],
    ['bg' => 'rgba(239,68,68,0.65)', 'border' => 'rgba(239,68,68,1)'],
    ['bg' => 'rgba(99,102,241,0.65)', 'border' => 'rgba(99,102,241,1)'],
    ['bg' => 'rgba(20,184,166,0.65)', 'border' => 'rgba(20,184,166,1)'],
    ['bg' => 'rgba(168,85,247,0.65)', 'border' => 'rgba(168,85,247,1)'],
    ['bg' => 'rgba(236,72,153,0.65)', 'border' => 'rgba(236,72,153,1)'],
];

$kasaDatasets = [];
$paletteIdx = 0;
foreach ($kasa_ids as $kasa_id) {
    $pal = $kasaPalette[$paletteIdx % count($kasaPalette)];
    $paletteIdx++;
    $kasaLabel = $kasa_isimleri[$kasa_id]['CODE'] . ' - ' . $kasa_isimleri[$kasa_id]['NAME'];
    $kasaDatasets[] = [
        'label' => $kasaLabel,
        'data' => array_values($kasaBreakdown[$kasa_id]),
        'backgroundColor' => $pal['bg'],
        'borderColor' => $pal['border'],
        'borderWidth' => 1,
        'borderRadius' => 6,
    ];
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Aylık Satış &amp; Tahsilat – <?php echo (int) $year; ?></title>

  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
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
      --purple: #7c3aed;
      --purple-soft: #f5f3ff;
      --teal: #0d9488;
      --teal-soft: #f0fdfa;
      --cyan: #0891b2;
      --cyan-soft: #ecfeff;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
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
      border-bottom: 1px solid rgba(5, 150, 105, 0.18);
      box-shadow: 0 2px 8px rgba(5, 150, 105, 0.04);
    }
    .header-inner {
      max-width: 1280px; margin: 0 auto;
      padding: 14px 24px;
      display: flex; align-items: center; gap: 14px;
      flex-wrap: wrap;
    }
    .header-back {
      display: inline-flex; align-items: center; justify-content: center;
      width: 36px; height: 36px; min-width: 36px; border-radius: 10px;
      color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
    }
    .header-back:hover { background: rgba(0,0,0,0.04); color: var(--emerald); }
    .header-divider { width: 1px; height: 24px; background: var(--border); }
    .header-title { min-width: 0; flex: 1 1 auto; }
    .header-title h1 {
      font-size: 16px; font-weight: 700; color: var(--text-1);
      display: flex; align-items: center; gap: 8px; margin: 0;
    }
    .header-title h1 .material-icons { color: var(--emerald); font-size: 18px; }
    .header-title p {
      margin: 2px 0 0; font-size: 11.5px; color: var(--text-2); font-weight: 500;
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .header-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .year-select {
      font-family: 'Avenir Next', 'Montserrat', sans-serif;
      font-size: 14px; font-weight: 600;
      color: var(--text-1); background: #fff;
      border: 1px solid var(--border); border-radius: 10px;
      padding: 8px 12px; min-height: 44px;
      outline: none; cursor: pointer; transition: all 0.2s ease;
    }
    .year-select:focus {
      border-color: rgba(5, 150, 105, 0.5);
      box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
    }
    .btn-ghost-print {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 10px 14px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif;
      font-size: 12.5px; font-weight: 600;
      border-radius: 10px; min-height: 44px;
      background: var(--emerald); color: #fff;
      border: none; cursor: pointer;
      box-shadow: 0 4px 12px rgba(5, 150, 105, 0.22);
      transition: all 0.2s ease;
    }
    .btn-ghost-print:hover { background: #047857; transform: translateY(-1px); }
    .btn-ghost-print .material-icons { font-size: 16px; }

    main {
      max-width: 1280px; margin: 0 auto;
      padding: 20px 24px 40px;
    }

    .glass-card {
      background: rgba(255,255,255,0.94);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      border: 1px solid var(--border);
      border-radius: 16px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.04);
      padding: 18px 20px;
      animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
    }
    .glass-card.emerald-border { border-color: rgba(5, 150, 105, 0.18); }
    .glass-card h3 {
      font-size: 13px; font-weight: 700; color: var(--text-1);
      margin: 0 0 14px; display: flex; align-items: center; gap: 8px;
    }
    .glass-card h3 .material-icons { color: var(--emerald); font-size: 18px; }

    .kasa-panel { margin-bottom: 16px; }
    .kasa-top {
      display: flex; flex-direction: column; gap: 6px;
      margin-bottom: 12px;
    }
    .kasa-top-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
    .kasa-title { font-size: 14px; font-weight: 700; color: var(--text-1); }
    .kasa-hint { font-size: 11px; color: var(--text-2); line-height: 1.5; }
    .kasa-hint strong { color: var(--text-1); font-weight: 600; }
    .kasa-count-pill {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 4px 10px;
      background: var(--emerald-soft); color: var(--emerald);
      border-radius: 999px;
      font-size: 12px; font-weight: 600;
    }
    .kasa-warn {
      margin-top: 8px;
      padding: 10px 14px;
      background: var(--amber-soft); color: var(--amber);
      border: 1px solid rgba(217, 119, 6, 0.24);
      border-radius: 10px;
      font-size: 12px; font-weight: 500;
    }
    details#kasaFilterPanel { margin-top: 10px; }
    details#kasaFilterPanel summary {
      cursor: pointer; user-select: none; list-style: none;
      display: flex; align-items: center; justify-content: space-between;
      padding: 8px 4px;
      font-size: 13px; font-weight: 600; color: var(--text-1);
    }
    details#kasaFilterPanel summary::-webkit-details-marker { display: none; }
    .chev { transition: transform 200ms ease; color: var(--text-2); font-size: 18px; }
    details[open] .chev { transform: rotate(180deg); }
    .kasa-form { margin-top: 10px; display: flex; flex-direction: column; gap: 12px; }
    .kasa-search-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: end; }
    .kasa-search-field { flex: 1 1 200px; min-width: 0; }
    .kasa-search-field label {
      display: block; font-size: 10.5px; color: var(--text-2); font-weight: 600;
      text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px;
    }
    .kasa-search-field input {
      width: 100%; padding: 11px 14px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 14px;
      color: var(--text-1); background: #fff;
      border: 1px solid var(--border); border-radius: 10px;
      outline: none; transition: all 0.2s ease; min-height: 44px;
    }
    .kasa-search-field input:focus {
      border-color: rgba(5, 150, 105, 0.5);
      box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
    }
    .kasa-search-actions { display: flex; gap: 8px; }
    .btn-secondary, .btn-primary {
      padding: 11px 16px; font-family: 'Avenir Next', 'Montserrat', sans-serif;
      font-size: 12.5px; font-weight: 600;
      border-radius: 10px; min-height: 44px; cursor: pointer;
      transition: all 0.2s ease;
    }
    .btn-secondary {
      background: #fff; color: var(--text-1);
      border: 1px solid var(--border);
    }
    .btn-secondary:hover { background: #f3f4f6; }
    .btn-primary {
      background: var(--emerald); color: #fff;
      border: none; box-shadow: 0 4px 12px rgba(5, 150, 105, 0.22);
    }
    .btn-primary:hover { background: #047857; transform: translateY(-1px); }

    .kasa-option-list {
      max-height: 280px; overflow-y: auto;
      border: 1px solid var(--border); border-radius: 10px;
      padding: 8px;
      display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 6px;
    }
    .kasa-option {
      display: flex; align-items: center; gap: 8px;
      padding: 8px 10px;
      border: 1px solid var(--border); border-radius: 8px;
      background: #fff; cursor: pointer; transition: all 0.15s ease;
    }
    .kasa-option:hover { background: var(--emerald-soft); border-color: rgba(5,150,105,0.25); }
    .kasa-option input[type="checkbox"] {
      width: 16px; height: 16px; accent-color: var(--emerald);
    }
    .kasa-option .kasa-code {
      font-size: 11px; color: var(--text-2); font-weight: 700;
    }
    .kasa-option .kasa-name {
      font-size: 12.5px; color: var(--text-1);
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1;
    }

    .stat-grid {
      display: grid;
      grid-template-columns: repeat(6, 1fr);
      gap: 12px;
      margin-bottom: 18px;
    }
    .stat-card {
      background: rgba(255,255,255,0.94);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      border: 1px solid var(--border);
      border-radius: 14px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.04);
      padding: 16px 14px;
      position: relative; overflow: hidden;
      animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
    }
    .stat-card:nth-child(1) { animation-delay: 0ms; }
    .stat-card:nth-child(2) { animation-delay: 45ms; }
    .stat-card:nth-child(3) { animation-delay: 90ms; }
    .stat-card:nth-child(4) { animation-delay: 135ms; }
    .stat-card:nth-child(5) { animation-delay: 180ms; }
    .stat-card:nth-child(6) { animation-delay: 225ms; }
    .stat-card::before {
      content: ''; position: absolute;
      top: 0; left: 0; bottom: 0;
      width: 4px;
    }
    .stat-card.emerald::before { background: var(--emerald); }
    .stat-card.teal::before    { background: var(--teal); }
    .stat-card.sky::before     { background: var(--sky); }
    .stat-card.cyan::before    { background: var(--cyan); }
    .stat-card.amber::before   { background: var(--amber); }
    .stat-card.indigo::before  { background: var(--indigo); }
    .stat-card .stat-top {
      display: flex; align-items: center; justify-content: space-between; gap: 8px;
    }
    .stat-card .stat-label {
      font-size: 10.5px; color: var(--text-2); font-weight: 600;
      text-transform: uppercase; letter-spacing: 0.4px;
    }
    .stat-card .icon-box {
      width: 34px; height: 34px;
      border-radius: 10px;
      display: inline-flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }
    .stat-card .icon-box .material-icons { font-size: 18px; }
    .stat-card.emerald .icon-box { background: var(--emerald-soft); color: var(--emerald); }
    .stat-card.teal .icon-box    { background: var(--teal-soft); color: var(--teal); }
    .stat-card.sky .icon-box     { background: var(--sky-soft); color: var(--sky); }
    .stat-card.cyan .icon-box    { background: var(--cyan-soft); color: var(--cyan); }
    .stat-card.amber .icon-box   { background: var(--amber-soft); color: var(--amber); }
    .stat-card.indigo .icon-box  { background: var(--indigo-soft); color: var(--indigo); }
    .stat-card .stat-value {
      display: block; margin-top: 10px;
      font-size: 18px; font-weight: 700; color: var(--text-1);
      line-height: 1.2;
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .stat-card .stat-sub {
      font-size: 10.5px; color: var(--text-3); font-weight: 500;
      margin-top: 4px;
    }
    .stat-card .stat-sub.pos { color: var(--emerald); font-weight: 600; }
    .stat-card .stat-sub.neg { color: var(--red); font-weight: 600; }

    .grid-charts {
      display: grid;
      grid-template-columns: 7fr 5fr;
      gap: 16px;
      margin-bottom: 16px;
    }
    .chart-wrap { position: relative; height: 400px; }

    .gd-table-wrap { overflow-x: auto; }
    .gd-table { width: 100%; border-collapse: collapse; min-width: 800px; }
    .gd-table thead th {
      padding: 11px 12px;
      font-size: 10px; font-weight: 700; color: var(--text-2);
      text-transform: uppercase; letter-spacing: 0.5px;
      text-align: left;
      background: linear-gradient(180deg, #fff, #f0fdf4);
      border-bottom: 2px solid var(--border);
      white-space: nowrap;
    }
    .gd-table thead th.right { text-align: right; }
    .gd-table tbody td {
      padding: 11px 12px;
      font-size: 12.5px; color: var(--text-1);
      border-bottom: 1px solid #f3f4f6;
      white-space: nowrap;
    }
    .gd-table tbody td.right { text-align: right; font-weight: 600; }
    .gd-table tbody tr:hover { background: var(--emerald-soft); }
    .diff-pos { color: var(--emerald); }
    .diff-neg { color: var(--red); }

    .kasa-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
    .kasa-chip {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 4px 10px;
      background: #f3f4f6; color: var(--text-1);
      border: 1px solid var(--border);
      border-radius: 999px;
      font-size: 11.5px;
    }
    .kasa-chip strong { color: var(--emerald); font-weight: 700; }
    details.kasa-detail summary {
      cursor: pointer; user-select: none;
      font-size: 11.5px; color: var(--text-2);
    }
    details.kasa-detail summary::-webkit-details-marker { display: none; }
    details.kasa-detail summary::before {
      content: '▸ '; transition: all 0.2s ease;
    }
    details.kasa-detail[open] summary::before { content: '▾ '; }

    .caption-footer {
      font-size: 11px; color: var(--text-3); margin-top: 10px;
    }

    @keyframes cardIn {
      from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
      to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
    }

    @media (max-width: 1100px) {
      .stat-grid { grid-template-columns: repeat(3, 1fr); }
      .grid-charts { grid-template-columns: 1fr; }
    }
    @media (max-width: 767px) {
      .header-inner { padding: 12px 14px; gap: 10px; }
      .header-title h1 { font-size: 14px; }
      .header-title p { font-size: 10.5px; }
      main { padding: 14px 12px 40px; }
      .glass-card { padding: 14px 14px; }
      .stat-grid { grid-template-columns: 1fr 1fr; }
      .stat-card { padding: 14px 12px; }
      .stat-card .stat-value { font-size: 15px; }
      .year-select, .kasa-search-field input { font-size: 16px; }
      .chart-wrap { height: 300px; }
    }
    @media print {
      body { background: #fff !important; }
      .no-print, .top-header { display: none !important; }
      main { padding: 10px 0; max-width: 100%; }
      .glass-card, .stat-card { box-shadow: none !important; border-radius: 0 !important; border: 1px solid #ddd !important; }
      .chart-wrap { height: 260px !important; }
    }
  </style>
</head>
<body>

<header class="top-header no-print">
  <div class="header-inner">
    <a href="dashboard.php" class="header-back" title="Rapor Dashboard">
      <span class="material-icons">arrow_back</span>
    </a>
    <div class="header-divider"></div>
    <div class="header-title">
      <h1><span class="material-icons">payments</span> Aylık Satış &amp; Tahsilat</h1>
      <p><?php echo (int) $year; ?> yılı, AKL&#37; satış + tahsilat (kasa + çek + senet)</p>
    </div>
    <div class="header-actions">
      <form method="get" class="inline-block">
        <?php foreach ($kasa_ids as $kid): ?>
          <input type="hidden" name="kasa[]" value="<?php echo (int) $kid; ?>">
        <?php endforeach; ?>
        <select name="year" class="year-select" onchange="this.form.submit()">
          <?php for ($y = $currentYear; $y >= 2020; $y--): ?>
            <option value="<?php echo (int) $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>>
              <?php echo (int) $y; ?>
            </option>
          <?php endfor; ?>
        </select>
      </form>
      <button onclick="window.print()" class="btn-ghost-print">
        <span class="material-icons">print</span>
        <span>Yazdır</span>
      </button>
    </div>
  </div>
</header>

<main>

  <!-- Kasa seçimi -->
  <div class="glass-card emerald-border kasa-panel no-print">
    <div class="kasa-top">
      <div class="kasa-top-row">
        <div class="kasa-title">Kasa Seçimi</div>
        <div class="kasa-count-pill">
          <span class="material-icons" style="font-size:14px;">check_circle</span>
          Seçili: <span id="selectedKasaCount"><?php echo (int) $secili_kasa_sayisi; ?></span> kasa
        </div>
      </div>
      <div class="kasa-hint">
        Tahsilat (kasa): <strong>SIGN=0, TRCODE=11</strong> (iptal hariç).
        Çek/Senet: <strong>CLFLINE (TRCODE=61/62, SIGN=1)</strong>.
        Genel tahsilat: <strong>CLFLINE TRCODE (1,4,20,61,62,70), SIGN=1</strong>.
      </div>
    </div>

    <?php if ($kasa_secim_uyari !== ''): ?>
      <div class="kasa-warn">
        <?php echo htmlspecialchars($kasa_secim_uyari, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>

    <details id="kasaFilterPanel">
      <summary>
        <span>Kasa listesini aç</span>
        <span class="material-icons chev">expand_more</span>
      </summary>

      <form method="get" id="kasaSecimForm" class="kasa-form">
        <input type="hidden" name="year" value="<?php echo (int) $year; ?>">
        <div class="kasa-search-row">
          <div class="kasa-search-field">
            <label>Kasa Ara</label>
            <input type="text" id="kasaSearch" placeholder="Kod / ad ile ara">
          </div>
          <div class="kasa-search-actions">
            <button type="button" id="kasaSecTemizle" class="btn-secondary">Temizle</button>
            <button type="submit" class="btn-primary">Uygula</button>
          </div>
        </div>

        <div id="kasaOptionList" class="kasa-option-list">
          <?php foreach ($tum_kasalar as $kasa_id => $kasa_bilgi):
            $kasa_checked = in_array($kasa_id, $kasa_ids, true);
            $kasa_search = strtolower($kasa_bilgi['CODE'] . ' ' . $kasa_bilgi['NAME']);
          ?>
            <label class="kasa-option" data-search="<?php echo htmlspecialchars($kasa_search, ENT_QUOTES, 'UTF-8'); ?>">
              <input type="checkbox" class="kasa-checkbox" name="kasa[]" value="<?php echo (int) $kasa_id; ?>"
                <?php echo $kasa_checked ? 'checked' : ''; ?>>
              <span class="kasa-code"><?php echo htmlspecialchars($kasa_bilgi['CODE'], ENT_QUOTES, 'UTF-8'); ?></span>
              <span class="kasa-name"><?php echo htmlspecialchars($kasa_bilgi['NAME'], ENT_QUOTES, 'UTF-8'); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </form>
    </details>
  </div>

  <!-- KPI Kartları -->
  <div class="stat-grid">
    <div class="stat-card emerald">
      <div class="stat-top">
        <span class="stat-label">Yıllık Satış</span>
        <div class="icon-box"><span class="material-icons">payments</span></div>
      </div>
      <div class="stat-value"><?php echo number_format($yearlySales, 0, ',', '.'); ?> ₺</div>
      <div class="stat-sub">AKL% — TRCODE 7/8</div>
    </div>

    <div class="stat-card teal">
      <div class="stat-top">
        <span class="stat-label">Toplam Tahsilat</span>
        <div class="icon-box"><span class="material-icons">savings</span></div>
      </div>
      <div class="stat-value"><?php echo number_format($yearlyTahsilat, 0, ',', '.'); ?> ₺</div>
      <div class="stat-sub">Kasa + Çek + Senet • Oran: <?php echo number_format($ratioTahsilat * 100, 1, ',', '.'); ?>%</div>
      <div class="stat-sub <?php echo $diffTahsilatCl > 0 ? 'pos' : ($diffTahsilatCl < 0 ? 'neg' : ''); ?>">
        Toplam &minus; Genel: <?php echo number_format($diffTahsilatCl, 0, ',', '.'); ?> ₺
      </div>
    </div>

    <div class="stat-card sky">
      <div class="stat-top">
        <span class="stat-label">Kasa Tahsilat</span>
        <div class="icon-box"><span class="material-icons">account_balance_wallet</span></div>
      </div>
      <div class="stat-value"><?php echo number_format($yearlyKasa, 0, ',', '.'); ?> ₺</div>
      <div class="stat-sub"><?php echo (int) $secili_kasa_sayisi; ?> kasa</div>
    </div>

    <div class="stat-card cyan">
      <div class="stat-top">
        <span class="stat-label">Çek Tahsilat</span>
        <div class="icon-box"><span class="material-icons">assignment</span></div>
      </div>
      <div class="stat-value"><?php echo number_format($yearlyCek, 0, ',', '.'); ?> ₺</div>
      <div class="stat-sub">CLFLINE (61)</div>
    </div>

    <div class="stat-card amber">
      <div class="stat-top">
        <span class="stat-label">Senet Tahsilat</span>
        <div class="icon-box"><span class="material-icons">description</span></div>
      </div>
      <div class="stat-value"><?php echo number_format($yearlySenet, 0, ',', '.'); ?> ₺</div>
      <div class="stat-sub">CLFLINE (62)</div>
    </div>

    <div class="stat-card indigo">
      <div class="stat-top">
        <span class="stat-label">Genel Tahsilat</span>
        <div class="icon-box"><span class="material-icons">receipt_long</span></div>
      </div>
      <div class="stat-value"><?php echo number_format($yearlyCl, 0, ',', '.'); ?> ₺</div>
      <div class="stat-sub">CLFLINE</div>
    </div>
  </div>

  <!-- Grafikler -->
  <div class="grid-charts">
    <div class="glass-card emerald-border">
      <h3><span class="material-icons">query_stats</span> Aylık Karşılaştırma</h3>
      <div class="chart-wrap">
        <canvas id="compareChart"></canvas>
      </div>
      <div class="caption-footer">
        Satış: AKL% ürünleri (TRCODE 7/8, KDV dahil). Kasa: KSLINES (SIGN=0, TRCODE=11). Çek/Senet ve genel tahsilat: CLFLINE.
      </div>
    </div>

    <div class="glass-card emerald-border">
      <h3><span class="material-icons">stacked_bar_chart</span> Kasa Dağılımı</h3>
      <div class="chart-wrap">
        <canvas id="kasaStackChart"></canvas>
      </div>
      <div class="caption-footer">
        Seçili kasalara göre aylık tahsilat kırılımı.
      </div>
    </div>
  </div>

  <!-- Tablo -->
  <div class="glass-card emerald-border">
    <h3><span class="material-icons">list_alt</span> Aylık Detaylar</h3>
    <div class="gd-table-wrap">
      <table class="gd-table">
        <thead>
          <tr>
            <th>Ay</th>
            <th class="right">Satış</th>
            <th class="right">Kasa</th>
            <th class="right">Çek</th>
            <th class="right">Senet</th>
            <th class="right">Toplam Tahsilat</th>
            <th class="right">Genel Tahsilat</th>
            <th class="right">Fark</th>
            <th>Kasa Kırılımı</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($monthsData as $d):
            $m = (int) $d['M'];
            $diff = (float) $d['D'];
            $diffClass = $diff > 0 ? 'diff-pos' : ($diff < 0 ? 'diff-neg' : '');
          ?>
            <tr>
              <td><strong><?php echo htmlspecialchars($monthNames[$m], ENT_QUOTES, 'UTF-8'); ?></strong></td>
              <td class="right"><?php echo formatTutar($d['S']); ?></td>
              <td class="right"><?php echo formatTutar($d['K']); ?></td>
              <td class="right"><?php echo formatTutar($d['CK']); ?></td>
              <td class="right"><?php echo formatTutar($d['SN']); ?></td>
              <td class="right"><?php echo formatTutar($d['T']); ?></td>
              <td class="right"><?php echo formatTutar($d['C']); ?></td>
              <td class="right <?php echo $diffClass; ?>"><?php echo formatTutar($diff); ?></td>
              <td>
                <?php if ($kasa_ids === []): ?>
                  <span style="font-size:11.5px;color:var(--text-3);">Kasa seçili değil</span>
                <?php else: ?>
                  <details class="kasa-detail">
                    <summary>Göster</summary>
                    <div class="kasa-chips">
                      <?php foreach ($kasa_ids as $kasa_id):
                        $amt = (float) ($kasaBreakdown[$kasa_id][$m] ?? 0);
                        if ($amt == 0.0) {
                            continue;
                        }
                        $k = $kasa_isimleri[$kasa_id] ?? ['CODE' => (string) $kasa_id, 'NAME' => ''];
                      ?>
                        <span class="kasa-chip">
                          <?php echo htmlspecialchars($k['CODE'], ENT_QUOTES, 'UTF-8'); ?>:
                          <strong><?php echo formatTutar($amt); ?></strong>
                        </span>
                      <?php endforeach; ?>
                      <?php if (array_sum(array_map(static fn($id) => (float) ($kasaBreakdown[$id][$m] ?? 0), $kasa_ids)) == 0.0): ?>
                        <span style="font-size:11.5px;color:var(--text-3);">Bu ay tahsilat yok</span>
                      <?php endif; ?>
                    </div>
                  </details>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  const labels = <?php echo json_encode($chartLabels, JSON_UNESCAPED_UNICODE); ?>;
  const sales = <?php echo json_encode(array_values($chartSales), JSON_NUMERIC_CHECK); ?>;
  const kasaTotal = <?php echo json_encode(array_values($chartKasa), JSON_NUMERIC_CHECK); ?>;
  const cekTotal = <?php echo json_encode(array_values($chartCek), JSON_NUMERIC_CHECK); ?>;
  const senetTotal = <?php echo json_encode(array_values($chartSenet), JSON_NUMERIC_CHECK); ?>;
  const tahsilatTotal = <?php echo json_encode(array_values($chartTahsilat), JSON_NUMERIC_CHECK); ?>;
  const clTotal = <?php echo json_encode(array_values($chartCl), JSON_NUMERIC_CHECK); ?>;
  const kasaDatasets = <?php echo json_encode($kasaDatasets, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK); ?>;

  const tlFmt = new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY' });

  // Compare chart: Satış + Toplam tahsilat (bar) + kasa/cek/senet/genel (line)
  const compareCtx = document.getElementById('compareChart').getContext('2d');
  new Chart(compareCtx, {
    data: {
      labels,
      datasets: [
        {
          type: 'bar',
          label: 'Satış (₺)',
          data: sales,
          backgroundColor: 'rgba(5,150,105,0.78)',
          borderColor: 'rgba(5,150,105,1)',
          borderWidth: 1,
          borderRadius: 6
        },
        {
          type: 'bar',
          label: 'Toplam Tahsilat (₺)',
          data: tahsilatTotal,
          backgroundColor: 'rgba(13,148,136,0.70)',
          borderColor: 'rgba(13,148,136,1)',
          borderWidth: 1,
          borderRadius: 6
        },
        {
          type: 'line',
          label: 'Kasa Tahsilat (₺)',
          data: kasaTotal,
          borderColor: 'rgba(2,132,199,1)',
          backgroundColor: 'rgba(2,132,199,0.10)',
          fill: false,
          tension: 0.25,
          pointRadius: 2,
          pointHoverRadius: 4
        },
        {
          type: 'line',
          label: 'Çek Tahsilat (₺)',
          data: cekTotal,
          borderColor: 'rgba(8,145,178,1)',
          backgroundColor: 'rgba(8,145,178,0.10)',
          fill: false,
          tension: 0.25,
          pointRadius: 2,
          pointHoverRadius: 4
        },
        {
          type: 'line',
          label: 'Senet Tahsilat (₺)',
          data: senetTotal,
          borderColor: 'rgba(217,119,6,1)',
          backgroundColor: 'rgba(217,119,6,0.10)',
          fill: false,
          tension: 0.25,
          pointRadius: 2,
          pointHoverRadius: 4
        },
        {
          type: 'line',
          label: 'Genel Tahsilat (₺)',
          data: clTotal,
          borderColor: 'rgba(79,70,229,1)',
          backgroundColor: 'rgba(79,70,229,0.10)',
          borderDash: [6, 4],
          fill: false,
          tension: 0.25,
          pointRadius: 2,
          pointHoverRadius: 4
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { font: { family: 'Montserrat', size: 11 } } },
        tooltip: {
          callbacks: {
            label: (ctx) => `${ctx.dataset.label}: ${tlFmt.format(ctx.parsed.y || 0)}`
          }
        }
      },
      scales: {
        x: { ticks: { font: { family: 'Montserrat', size: 11 } }, grid: { display: false } },
        y: {
          beginAtZero: true,
          ticks: { callback: (v) => new Intl.NumberFormat('tr-TR').format(v), font: { family: 'Montserrat', size: 11 } },
          grid: { color: 'rgba(0,0,0,0.05)' }
        }
      }
    }
  });

  // Stacked chart: kasa bazlı kırılım
  const stackCtx = document.getElementById('kasaStackChart').getContext('2d');
  new Chart(stackCtx, {
    type: 'bar',
    data: { labels, datasets: kasaDatasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { labels: { font: { family: 'Montserrat', size: 11 } } },
        tooltip: {
          callbacks: {
            label: (ctx) => `${ctx.dataset.label}: ${tlFmt.format(ctx.parsed.y || 0)}`
          }
        }
      },
      scales: {
        x: { stacked: true, ticks: { font: { family: 'Montserrat', size: 11 } }, grid: { display: false } },
        y: { stacked: true, beginAtZero: true, ticks: { font: { family: 'Montserrat', size: 11 } }, grid: { color: 'rgba(0,0,0,0.05)' } }
      }
    }
  });

  // Kasa arama + seçili sayısı
  const searchInput = document.getElementById('kasaSearch');
  const optionList = document.getElementById('kasaOptionList');
  const clearBtn = document.getElementById('kasaSecTemizle');
  const formEl = document.getElementById('kasaSecimForm');
  const selectedCountEl = document.getElementById('selectedKasaCount');

  function updateSelectedCount() {
    const checkedCount = optionList.querySelectorAll('input.kasa-checkbox:checked').length;
    selectedCountEl.textContent = checkedCount.toString();
  }

  if (searchInput && optionList) {
    const optionRows = Array.from(optionList.querySelectorAll('.kasa-option'));

    searchInput.addEventListener('input', function() {
      const q = (searchInput.value || '').trim().toLowerCase();
      optionRows.forEach(function(row) {
        const hay = (row.getAttribute('data-search') || '').toLowerCase();
        row.style.display = (q === '' || hay.includes(q)) ? '' : 'none';
      });
    });

    optionList.addEventListener('change', function(ev) {
      const t = ev.target;
      if (t && t.classList && t.classList.contains('kasa-checkbox')) {
        updateSelectedCount();
      }
    });

    clearBtn && clearBtn.addEventListener('click', function() {
      searchInput.value = '';
      optionRows.forEach(function(row) { row.style.display = ''; });
      searchInput.focus();
    });

    formEl && formEl.addEventListener('submit', function(ev) {
      const checkedCount = optionList.querySelectorAll('input.kasa-checkbox:checked').length;
      if (checkedCount < 1) {
        ev.preventDefault();
        alert('En az bir kasa seçmelisiniz.');
      }
    });

    updateSelectedCount();
  }
</script>

</body>
</html>
