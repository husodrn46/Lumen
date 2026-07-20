<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
include(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

// Yetki kontrolü - M17
if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// Yıl seçimi
$selected_year = isset($_GET['yil']) ? intval($_GET['yil']) : 2024;
$start_date = $selected_year . '-01-01';
$end_date = $selected_year . '-12-31';

// Önceki yıl karşılaştırma için
$prev_year = $selected_year - 1;
$prev_start = $prev_year . '-01-01';
$prev_end = $prev_year . '-12-31';

// ==================== SQL SORGULARI ====================

// 1. Aylık Nakit Giriş (Tahsilat)
$sql_giris = "
SELECT
    MONTH(DATE_) AS AY,
    SUM(AMOUNT) AS TAHSILAT
FROM {$firmadonem}CLFLINE
WHERE TRCODE IN (1, 4, 20, 61, 62, 70)
AND SIGN = 1
AND DATE_ >= :start_date
AND DATE_ <= :end_date
AND CANCELLED = 0
GROUP BY MONTH(DATE_)
ORDER BY MONTH(DATE_)
";

$stmt_giris = $dbh->prepare($sql_giris);
$stmt_giris->bindParam(':start_date', $start_date);
$stmt_giris->bindParam(':end_date', $end_date);
$stmt_giris->execute();
$giris_data = $stmt_giris->fetchAll(PDO::FETCH_ASSOC);

// 2. Aylık Nakit Çıkış (Ödeme)
$sql_cikis = "
SELECT
    MONTH(DATE_) AS AY,
    SUM(AMOUNT) AS ODEME
FROM {$firmadonem}CLFLINE
WHERE TRCODE IN (2, 3, 21, 63, 64, 72)
AND SIGN = 0
AND DATE_ >= :start_date
AND DATE_ <= :end_date
AND CANCELLED = 0
GROUP BY MONTH(DATE_)
ORDER BY MONTH(DATE_)
";

$stmt_cikis = $dbh->prepare($sql_cikis);
$stmt_cikis->bindParam(':start_date', $start_date);
$stmt_cikis->bindParam(':end_date', $end_date);
$stmt_cikis->execute();
$cikis_data = $stmt_cikis->fetchAll(PDO::FETCH_ASSOC);

// 3. Toplam Nakit Giriş (Mevcut Yıl)
$sql_toplam_giris = "
SELECT SUM(AMOUNT) AS TOPLAM
FROM {$firmadonem}CLFLINE
WHERE TRCODE IN (1, 4, 20, 61, 62, 70)
AND SIGN = 1
AND DATE_ >= :start_date
AND DATE_ <= :end_date
AND CANCELLED = 0
";

$stmt_toplam_giris = $dbh->prepare($sql_toplam_giris);
$stmt_toplam_giris->bindParam(':start_date', $start_date);
$stmt_toplam_giris->bindParam(':end_date', $end_date);
$stmt_toplam_giris->execute();
$result_giris = $stmt_toplam_giris->fetch(PDO::FETCH_ASSOC);
$toplam_giris = $result_giris['TOPLAM'] ?? 0;

// 4. Toplam Nakit Giriş (Önceki Yıl)
$stmt_toplam_giris->bindParam(':start_date', $prev_start);
$stmt_toplam_giris->bindParam(':end_date', $prev_end);
$stmt_toplam_giris->execute();
$result_prev_giris = $stmt_toplam_giris->fetch(PDO::FETCH_ASSOC);
$prev_giris = $result_prev_giris['TOPLAM'] ?? 0;

// 5. Toplam Nakit Çıkış (Mevcut Yıl)
$sql_toplam_cikis = "
SELECT SUM(AMOUNT) AS TOPLAM
FROM {$firmadonem}CLFLINE
WHERE TRCODE IN (2, 3, 21, 63, 64, 72)
AND SIGN = 0
AND DATE_ >= :start_date
AND DATE_ <= :end_date
AND CANCELLED = 0
";

$stmt_toplam_cikis = $dbh->prepare($sql_toplam_cikis);
$stmt_toplam_cikis->bindParam(':start_date', $start_date);
$stmt_toplam_cikis->bindParam(':end_date', $end_date);
$stmt_toplam_cikis->execute();
$result_cikis = $stmt_toplam_cikis->fetch(PDO::FETCH_ASSOC);
$toplam_cikis = $result_cikis['TOPLAM'] ?? 0;

// 6. Toplam Nakit Çıkış (Önceki Yıl)
$stmt_toplam_cikis->bindParam(':start_date', $prev_start);
$stmt_toplam_cikis->bindParam(':end_date', $prev_end);
$stmt_toplam_cikis->execute();
$result_prev_cikis = $stmt_toplam_cikis->fetch(PDO::FETCH_ASSOC);
$prev_cikis = $result_prev_cikis['TOPLAM'] ?? 0;

// 7. Net Nakit Akışı
$net_akis = $toplam_giris - $toplam_cikis;
$prev_net = $prev_giris - $prev_cikis;

// Yüzde değişim hesaplama
$giris_change = $prev_giris > 0 ? (($toplam_giris - $prev_giris) / $prev_giris) * 100 : 0;
$cikis_change = $prev_cikis > 0 ? (($toplam_cikis - $prev_cikis) / $prev_cikis) * 100 : 0;
$net_change = $prev_net != 0 ? (($net_akis - $prev_net) / abs($prev_net)) * 100 : 0;

// 8. DSO (Days Sales Outstanding) Hesaplama
// Alacak bakiyesi
$sql_alacak = "
SELECT SUM((1 - SIGN) * AMOUNT) - SUM(SIGN * AMOUNT) AS TOPLAM_ALACAK
FROM {$firmadonem}CLFLINE
WHERE CANCELLED = 0
";
$stmt_alacak = $dbh->prepare($sql_alacak);
$stmt_alacak->execute();
$result_alacak = $stmt_alacak->fetch(PDO::FETCH_ASSOC);
$toplam_alacak = $result_alacak['TOPLAM_ALACAK'] ?? 0;

// Yıllık satış (FATURALI SATIŞLAR: Perakende 7 + Toptan 8)
$sql_satis = "
SELECT SUM(NETTOTAL) AS YILLIK_SATIS
FROM {$firmadonem}INVOICE
WHERE TRCODE IN (7,8)
AND YEAR(DATE_) = :selected_year
AND CANCELLED = 0
";
$stmt_satis = $dbh->prepare($sql_satis);
$stmt_satis->bindParam(':selected_year', $selected_year, PDO::PARAM_INT);
$stmt_satis->execute();
$result_satis = $stmt_satis->fetch(PDO::FETCH_ASSOC);
$yillik_satis = $result_satis['YILLIK_SATIS'] ?? 0;

// DSO hesabı
$dso = $yillik_satis > 0 ? ($toplam_alacak / $yillik_satis) * 365 : 0;

// Önceki yıl DSO (karşılaştırma için)
$stmt_alacak->execute(); // Alacak değişmez (güncel)
$stmt_satis->bindParam(':selected_year', $prev_year, PDO::PARAM_INT);
$stmt_satis->execute();
$result_prev_satis = $stmt_satis->fetch(PDO::FETCH_ASSOC);
$prev_yillik_satis = $result_prev_satis['YILLIK_SATIS'] ?? 0;
$prev_dso = $prev_yillik_satis > 0 ? ($toplam_alacak / $prev_yillik_satis) * 365 : 0;
$dso_change = $prev_dso > 0 ? $dso - $prev_dso : 0;

// 9. Vade Bazlı Alacak Dağılımı
$sql_vade = "
SELECT
    VADE_ARALIGI,
    SUM(TUTAR) AS TUTAR,
    SUM(ISLEM_SAYISI) AS ISLEM_SAYISI,
    SIRA
FROM (
    SELECT
        CASE
            WHEN DATEDIFF(DAY, DATE_, GETDATE()) BETWEEN 0 AND 30 THEN '0-30 gün'
            WHEN DATEDIFF(DAY, DATE_, GETDATE()) BETWEEN 31 AND 60 THEN '31-60 gün'
            WHEN DATEDIFF(DAY, DATE_, GETDATE()) BETWEEN 61 AND 90 THEN '61-90 gün'
            ELSE '90+ gün'
        END AS VADE_ARALIGI,
        (1 - SIGN) * AMOUNT AS TUTAR,
        1 AS ISLEM_SAYISI,
        CASE
            WHEN DATEDIFF(DAY, DATE_, GETDATE()) BETWEEN 0 AND 30 THEN 1
            WHEN DATEDIFF(DAY, DATE_, GETDATE()) BETWEEN 31 AND 60 THEN 2
            WHEN DATEDIFF(DAY, DATE_, GETDATE()) BETWEEN 61 AND 90 THEN 3
            ELSE 4
        END AS SIRA
    FROM {$firmadonem}CLFLINE
    WHERE ((1 - SIGN) * AMOUNT) > 0
    AND CANCELLED = 0
) AS SubQuery
GROUP BY VADE_ARALIGI, SIRA
ORDER BY SIRA
";

$stmt_vade = $dbh->prepare($sql_vade);
$stmt_vade->execute();
$vade_data = $stmt_vade->fetchAll(PDO::FETCH_ASSOC);

// 11. Çek Vade Analizi (Müşteriden Alınan Çekler)
$sql_cek_vade = "
SELECT
    VADE_DURUMU,
    SUM(TUTAR) AS TUTAR,
    COUNT(*) AS CEK_SAYISI,
    SIRA
FROM (
    SELECT
        CASE
            WHEN DATEDIFF(DAY, C.SETDATE, C.DUEDATE) <= 30 THEN '0-30 gün vade'
            WHEN DATEDIFF(DAY, C.SETDATE, C.DUEDATE) BETWEEN 31 AND 60 THEN '31-60 gün vade'
            WHEN DATEDIFF(DAY, C.SETDATE, C.DUEDATE) BETWEEN 61 AND 90 THEN '61-90 gün vade'
            WHEN DATEDIFF(DAY, C.SETDATE, C.DUEDATE) BETWEEN 91 AND 180 THEN '91-180 gün vade'
            ELSE '180+ gün vade'
        END AS VADE_DURUMU,
        C.AMOUNT AS TUTAR,
        CASE
            WHEN DATEDIFF(DAY, C.SETDATE, C.DUEDATE) <= 30 THEN 1
            WHEN DATEDIFF(DAY, C.SETDATE, C.DUEDATE) BETWEEN 31 AND 60 THEN 2
            WHEN DATEDIFF(DAY, C.SETDATE, C.DUEDATE) BETWEEN 61 AND 90 THEN 3
            WHEN DATEDIFF(DAY, C.SETDATE, C.DUEDATE) BETWEEN 91 AND 180 THEN 4
            ELSE 5
        END AS SIRA
    FROM {$firmadonem}CSCARD C
    INNER JOIN {$firmadonem}CSTRANS T ON T.CSREF = C.LOGICALREF
    WHERE C.CURRSTAT IN (1, 8, 9)
    AND C.STATUS IN (0, 1)
    AND C.DOC = 1
    AND T.TRCODE IN (1, 2, 3)
    AND YEAR(C.SETDATE) = :selected_year
) AS SubQuery
GROUP BY VADE_DURUMU, SIRA
ORDER BY SIRA
";

$stmt_cek_vade = $dbh->prepare($sql_cek_vade);
$stmt_cek_vade->bindParam(':selected_year', $selected_year, PDO::PARAM_INT);
$stmt_cek_vade->execute();
$cek_vade_data = $stmt_cek_vade->fetchAll(PDO::FETCH_ASSOC);

// ==================== VERİ HAZIRLAMA ====================

// Aylık veri dizileri oluştur (12 ay)
$aylar = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
$aylar_giris = array_fill(1, 12, 0);
$aylar_cikis = array_fill(1, 12, 0);

// Giriş verilerini diziye yerleştir
foreach ($giris_data as $row) {
    $aylar_giris[$row['AY']] = floatval($row['TAHSILAT']);
}

// Çıkış verilerini diziye yerleştir
foreach ($cikis_data as $row) {
    $aylar_cikis[$row['AY']] = floatval($row['ODEME']);
}

// Net akış ve kümülatif hesapla
$aylar_net = [];
$aylar_kumulatif = [];
$kumulatif_toplam = 0;

for ($i = 1; $i <= 12; $i++) {
    $net = $aylar_giris[$i] - $aylar_cikis[$i];
    $aylar_net[$i] = $net;
    $kumulatif_toplam += $net;
    $aylar_kumulatif[$i] = $kumulatif_toplam;
}

// Vade dağılımı için veri hazırla
$vade_labels = [];
$vade_values = [];

foreach ($vade_data as $row) {
    $vade_labels[] = $row['VADE_ARALIGI'];
    $vade_values[] = floatval($row['TUTAR']);
}

// Çek vade analizi için veri hazırla
$cek_vade_labels = [];
$cek_vade_values = [];
$cek_vade_counts = [];

foreach ($cek_vade_data as $row) {
    $cek_vade_labels[] = $row['VADE_DURUMU'];
    $cek_vade_values[] = floatval($row['TUTAR']);
    $cek_vade_counts[] = intval($row['CEK_SAYISI']);
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nakit Akis Raporu - <?php echo $selected_year; ?></title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">

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
        * { box-sizing: border-box; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
            margin: 0;
        }

        /* Sticky header */
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
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 10px;
            color: var(--text-2); text-decoration: none;
            transition: all 0.2s ease; flex-shrink: 0;
        }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--emerald); }
        .header-divider { width: 1px; height: 24px; background: var(--border); flex-shrink: 0; }
        .header-title {
            font-size: 16px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 8px;
            flex: 1 1 auto; min-width: 0; margin: 0;
        }
        .header-title .material-icons { color: var(--emerald); font-size: 20px; }
        .header-title .title-text {
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .header-actions { display: inline-flex; align-items: center; gap: 8px; flex-shrink: 0; }

        .year-select {
            padding: 8px 12px; min-height: 40px;
            border-radius: 10px; border: 1px solid var(--border);
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 13px; font-weight: 600;
            color: var(--text-1); background: #fff;
            transition: all 0.2s ease; cursor: pointer;
        }
        .year-select:focus {
            outline: none;
            border-color: rgba(5, 150, 105, 0.5);
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
        }

        .btn-print, .btn-excel {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 14px; min-height: 40px;
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 12.5px; font-weight: 700;
            cursor: pointer; transition: all 0.2s ease;
            border: none; text-decoration: none; white-space: nowrap;
        }
        .btn-print { background: var(--emerald); color: #fff; box-shadow: 0 4px 12px rgba(5, 150, 105, 0.2); }
        .btn-print:hover { background: #047857; transform: translateY(-1px); }
        .btn-excel { background: var(--sky); color: #fff; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.2); }
        .btn-excel:hover { background: #0369a1; transform: translateY(-1px); }
        .btn-print .material-icons, .btn-excel .material-icons { font-size: 16px; }

        main { max-width: 1280px; margin: 0 auto; padding: 20px 24px 60px; }

        /* Glass card base */
        .glass-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(5, 150, 105, 0.18);
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            will-change: transform, opacity;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            margin-bottom: 16px;
            overflow: hidden;
        }

        /* KPI Grid */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }
        .kpi-card {
            padding: 16px;
        }
        .kpi-card:nth-child(1) { animation-delay: 0ms; }
        .kpi-card:nth-child(2) { animation-delay: 60ms; }
        .kpi-card:nth-child(3) { animation-delay: 120ms; }
        .kpi-card:nth-child(4) { animation-delay: 180ms; }

        .kpi-card .top-row {
            display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;
        }
        .kpi-card .label {
            font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            color: var(--text-2);
        }
        .kpi-card .value {
            font-size: 24px; font-weight: 700;
            color: var(--text-1); margin-top: 6px;
            line-height: 1.2;
            word-break: break-word;
        }
        .kpi-card .trend {
            font-size: 11.5px; font-weight: 600;
            margin-top: 4px;
            display: inline-flex; align-items: center; gap: 4px;
        }
        .kpi-card .trend .material-icons { font-size: 14px; }
        .kpi-card .trend.up { color: var(--emerald); }
        .kpi-card .trend.down { color: var(--red); }
        .kpi-card .trend.neutral { color: var(--text-3); }
        .kpi-card .ico-box {
            width: 40px; height: 40px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .kpi-card .ico-box .material-icons { font-size: 22px; }

        .tone-emerald { border-color: rgba(5, 150, 105, 0.22); background: linear-gradient(180deg, var(--emerald-soft), #fff); }
        .tone-emerald .label { color: var(--emerald); }
        .tone-emerald .ico-box { background: rgba(5, 150, 105, 0.12); }
        .tone-emerald .ico-box .material-icons { color: var(--emerald); }

        .tone-red { border-color: rgba(111, 16, 34, 0.22); background: linear-gradient(180deg, var(--red-soft), #fff); }
        .tone-red .label { color: var(--red); }
        .tone-red .ico-box { background: rgba(111, 16, 34, 0.12); }
        .tone-red .ico-box .material-icons { color: var(--red); }

        .tone-sky { border-color: rgba(2, 132, 199, 0.22); background: linear-gradient(180deg, var(--sky-soft), #fff); }
        .tone-sky .label { color: var(--sky); }
        .tone-sky .ico-box { background: rgba(2, 132, 199, 0.12); }
        .tone-sky .ico-box .material-icons { color: var(--sky); }

        .tone-amber { border-color: rgba(217, 119, 6, 0.22); background: linear-gradient(180deg, var(--amber-soft), #fff); }
        .tone-amber .label { color: var(--amber); }
        .tone-amber .ico-box { background: rgba(217, 119, 6, 0.12); }
        .tone-amber .ico-box .material-icons { color: var(--amber); }

        .tone-indigo { border-color: rgba(79, 70, 229, 0.22); background: linear-gradient(180deg, var(--indigo-soft), #fff); }
        .tone-indigo .label { color: var(--indigo); }
        .tone-indigo .ico-box { background: rgba(79, 70, 229, 0.12); }
        .tone-indigo .ico-box .material-icons { color: var(--indigo); }

        /* Chart grid */
        .chart-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }
        @media (max-width: 1024px) {
            .chart-grid { grid-template-columns: 1fr; }
        }

        /* Section */
        .section-head {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(180deg, #fff, #f0fdf4);
        }
        .section-head h3 {
            margin: 0;
            font-size: 14px; font-weight: 700;
            color: var(--text-1);
            display: inline-flex; align-items: center; gap: 8px;
        }
        .section-head h3 .material-icons { color: var(--emerald); font-size: 18px; }

        /* Chart */
        .chart-wrap { padding: 18px 20px; }
        .chart-inner { position: relative; height: 340px; }
        .chart-inner.sm { height: 280px; }

        /* Tables (gd-table) */
        .table-wrap { padding: 0 4px 14px; overflow-x: auto; }
        .gd-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .gd-table thead { background: linear-gradient(180deg, #fff, #f0fdf4); }
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
            padding: 10px 14px;
            font-size: 12.5px; color: var(--text-1);
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .gd-table tbody tr:hover { background: #f0fdf4; }
        .gd-table .cell-right { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .gd-table .val-green { color: var(--emerald); font-weight: 600; }
        .gd-table .val-red { color: var(--red); font-weight: 600; }
        .gd-table .val-blue { color: var(--sky); font-weight: 700; }
        .gd-table .val-warn { color: var(--amber); font-weight: 700; }
        .gd-table tfoot td {
            padding: 12px 14px;
            font-size: 13px; font-weight: 700;
            border-top: 2px solid var(--border);
            background: linear-gradient(180deg, #f0fdf4, #fff);
        }

        /* Animations */
        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to   { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        @media (max-width: 1024px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 767px) {
            .header-inner { padding: 12px 14px; gap: 10px; }
            .header-title { font-size: 14px; }
            .header-title .material-icons { font-size: 18px; }
            .btn-print, .btn-excel { padding: 7px 10px; font-size: 11.5px; }
            .btn-print span:not(.material-icons), .btn-excel span:not(.material-icons) { display: none; }

            main { padding: 14px 12px 40px; }
            .kpi-grid { gap: 10px; }
            .kpi-card .value { font-size: 18px; }
            .kpi-card .ico-box { display: none; }
            .chart-inner { height: 260px; }
            .chart-inner.sm { height: 240px; }
            .gd-table thead th, .gd-table tbody td, .gd-table tfoot td { padding: 9px 10px; font-size: 11.5px; }
        }

        /* Print */
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .top-header { position: static; box-shadow: none; border-bottom: 2px solid #000; }
            .glass-card { box-shadow: none; border: 1px solid #ccc; animation: none; break-inside: avoid; }
            .kpi-card { background: #fff !important; }
            .chart-inner { height: 260px; }
            .chart-inner.sm { height: 220px; }
            .chart-grid { grid-template-columns: 2fr 1fr; }
            .gd-table { font-size: 10pt; }
        }
    </style>
</head>
<body>

<!-- Header -->
<header class="top-header">
    <div class="header-inner">
        <a href="dashboard.php" class="header-back" title="Geri">
            <span class="material-icons">arrow_back</span>
        </a>
        <div class="header-divider"></div>
        <h1 class="header-title">
            <span class="material-icons">account_balance</span>
            <span class="title-text">Nakit Akis Raporu</span>
        </h1>
        <div class="header-actions no-print">
            <form method="GET" style="display: inline-block;">
                <select name="yil" onchange="this.form.submit()" class="year-select">
                    <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </form>
            <button onclick="window.print()" class="btn-print" type="button">
                <span class="material-icons">print</span>
                <span>Yazdir</span>
            </button>
            <a href="rapor_nakit_akis_excel.php?yil=<?php echo $selected_year; ?>" class="btn-excel">
                <span class="material-icons">download</span>
                <span>Excel</span>
            </a>
        </div>
    </div>
</header>

<main>

    <!-- KPI Kartlari -->
    <div class="kpi-grid">
        <div class="glass-card kpi-card tone-emerald">
            <div class="top-row">
                <div style="min-width: 0;">
                    <div class="label">Toplam Nakit Giris</div>
                    <div class="value"><?php echo number_format($toplam_giris, 0, ',', '.'); ?> &#8378;</div>
                    <div class="trend <?php echo $giris_change >= 0 ? 'up' : 'down'; ?>">
                        <span class="material-icons"><?php echo $giris_change >= 0 ? 'trending_up' : 'trending_down'; ?></span>
                        <?php echo number_format(abs($giris_change), 1); ?>% (<?php echo $prev_year; ?>)
                    </div>
                </div>
                <span class="ico-box"><span class="material-icons">arrow_upward</span></span>
            </div>
        </div>

        <div class="glass-card kpi-card tone-red">
            <div class="top-row">
                <div style="min-width: 0;">
                    <div class="label">Toplam Nakit Cikis</div>
                    <div class="value"><?php echo number_format($toplam_cikis, 0, ',', '.'); ?> &#8378;</div>
                    <div class="trend <?php echo $cikis_change >= 0 ? 'down' : 'up'; ?>">
                        <span class="material-icons"><?php echo $cikis_change >= 0 ? 'trending_up' : 'trending_down'; ?></span>
                        <?php echo number_format(abs($cikis_change), 1); ?>% (<?php echo $prev_year; ?>)
                    </div>
                </div>
                <span class="ico-box"><span class="material-icons">arrow_downward</span></span>
            </div>
        </div>

        <div class="glass-card kpi-card <?php echo $net_akis >= 0 ? 'tone-sky' : 'tone-amber'; ?>">
            <div class="top-row">
                <div style="min-width: 0;">
                    <div class="label">Net Nakit Akisi</div>
                    <div class="value"><?php echo number_format($net_akis, 0, ',', '.'); ?> &#8378;</div>
                    <div class="trend <?php echo $net_change >= 0 ? 'up' : 'down'; ?>">
                        <span class="material-icons"><?php echo $net_change >= 0 ? 'trending_up' : 'trending_down'; ?></span>
                        <?php echo number_format(abs($net_change), 1); ?>% (<?php echo $prev_year; ?>)
                    </div>
                </div>
                <span class="ico-box"><span class="material-icons">savings</span></span>
            </div>
        </div>

        <div class="glass-card kpi-card <?php echo $dso <= 45 ? 'tone-sky' : 'tone-amber'; ?>">
            <div class="top-row">
                <div style="min-width: 0;">
                    <div class="label">Ortalama DSO</div>
                    <div class="value"><?php echo number_format($dso, 0); ?> gun</div>
                    <div class="trend <?php echo $dso_change <= 0 ? 'up' : 'down'; ?>">
                        <span class="material-icons"><?php echo $dso_change <= 0 ? 'trending_down' : 'trending_up'; ?></span>
                        <?php echo number_format(abs($dso_change), 1); ?> gun (<?php echo $prev_year; ?>)
                    </div>
                </div>
                <span class="ico-box"><span class="material-icons">schedule</span></span>
            </div>
        </div>
    </div>

    <!-- Grafikler 2'li grid -->
    <div class="chart-grid">
        <section class="glass-card" style="margin-bottom: 0;">
            <div class="section-head">
                <h3><span class="material-icons">show_chart</span>Aylik Nakit Akis</h3>
            </div>
            <div class="chart-wrap">
                <div class="chart-inner">
                    <canvas id="cashFlowChart"></canvas>
                </div>
            </div>
        </section>

        <section class="glass-card" style="margin-bottom: 0;">
            <div class="section-head">
                <h3><span class="material-icons">pie_chart</span>Vade Dagilimi</h3>
            </div>
            <div class="chart-wrap">
                <div class="chart-inner sm">
                    <canvas id="agingChart"></canvas>
                </div>
            </div>
        </section>
    </div>

    <!-- Cek Vade Grafik -->
    <section class="glass-card">
        <div class="section-head">
            <h3><span class="material-icons">bar_chart</span>Cek Vade Analizi</h3>
        </div>
        <div class="chart-wrap">
            <div class="chart-inner sm">
                <canvas id="cekVadeChart"></canvas>
            </div>
        </div>
    </section>

    <!-- Detayli Aylik Tablo -->
    <section class="glass-card">
        <div class="section-head">
            <h3><span class="material-icons">table_chart</span>Detayli Aylik Tablo</h3>
        </div>
        <div class="table-wrap">
            <table class="gd-table">
                <thead>
                    <tr>
                        <th>Ay</th>
                        <th class="right">Nakit Giris</th>
                        <th class="right">Nakit Cikis</th>
                        <th class="right">Net Akis</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <tr>
                            <td style="font-weight: 600;"><?php echo $aylar[$i-1]; ?></td>
                            <td class="cell-right val-green">
                                <?php echo number_format($aylar_giris[$i], 2, ',', '.'); ?> &#8378;
                            </td>
                            <td class="cell-right val-red">
                                <?php echo number_format($aylar_cikis[$i], 2, ',', '.'); ?> &#8378;
                            </td>
                            <td class="cell-right <?php echo $aylar_net[$i] >= 0 ? 'val-blue' : 'val-warn'; ?>">
                                <?php echo number_format($aylar_net[$i], 2, ',', '.'); ?> &#8378;
                            </td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>TOPLAM</td>
                        <td class="cell-right val-green"><?php echo number_format($toplam_giris, 2, ',', '.'); ?> &#8378;</td>
                        <td class="cell-right val-red"><?php echo number_format($toplam_cikis, 2, ',', '.'); ?> &#8378;</td>
                        <td class="cell-right <?php echo $net_akis >= 0 ? 'val-blue' : 'val-warn'; ?>">
                            <?php echo number_format($net_akis, 2, ',', '.'); ?> &#8378;
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>

</main>

<script>
// Chart.js konfigurasyonu
const chartColors = {
    green: 'rgb(5, 150, 105)',
    greenAlpha: 'rgba(5, 150, 105, 0.12)',
    red: 'rgb(220, 38, 38)',
    redAlpha: 'rgba(111, 16, 34, 0.12)',
    blue: 'rgb(2, 132, 199)',
    blueAlpha: 'rgba(2, 132, 199, 0.2)',
};

Chart.defaults.font.family = 'Montserrat';
Chart.defaults.font.size = 11;

// 1. Aylik Nakit Akis Grafigi
const ctx1 = document.getElementById('cashFlowChart').getContext('2d');
new Chart(ctx1, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($aylar); ?>,
        datasets: [
            {
                label: 'Nakit Giris',
                data: <?php echo json_encode(array_values($aylar_giris)); ?>,
                borderColor: chartColors.green,
                backgroundColor: chartColors.greenAlpha,
                borderWidth: 3,
                fill: true,
                tension: 0.4
            },
            {
                label: 'Nakit Cikis',
                data: <?php echo json_encode(array_values($aylar_cikis)); ?>,
                borderColor: chartColors.red,
                backgroundColor: chartColors.redAlpha,
                borderWidth: 3,
                fill: true,
                tension: 0.4
            },
            {
                label: 'Net Akis',
                data: <?php echo json_encode(array_values($aylar_net)); ?>,
                borderColor: chartColors.blue,
                borderWidth: 2,
                borderDash: [5, 5],
                fill: false,
                tension: 0.4
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top', labels: { padding: 12, font: { size: 12 } } },
            tooltip: {
                mode: 'index',
                intersect: false,
                callbacks: {
                    label: function(context) {
                        let label = context.dataset.label || '';
                        if (label) { label += ': '; }
                        label += new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY' }).format(context.parsed.y);
                        return label;
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: 'rgba(148,163,184,.15)' },
                ticks: {
                    callback: function(value) {
                        return new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY', maximumFractionDigits: 0 }).format(value);
                    }
                }
            },
            x: { grid: { display: false } }
        }
    }
});

// 2. Cek Vade Analizi (Bar)
const ctx2 = document.getElementById('cekVadeChart').getContext('2d');
const cekVadeCounts = <?php echo json_encode($cek_vade_counts); ?>;
new Chart(ctx2, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($cek_vade_labels); ?>,
        datasets: [{
            label: 'Cek Tutari',
            data: <?php echo json_encode($cek_vade_values); ?>,
            backgroundColor: [
                'rgba(5, 150, 105, 0.8)',
                'rgba(2, 132, 199, 0.8)',
                'rgba(217, 119, 6, 0.8)',
                'rgba(124, 58, 237, 0.8)',
                'rgba(111, 16, 34, 0.8)'
            ],
            borderColor: [
                'rgb(5, 150, 105)',
                'rgb(2, 132, 199)',
                'rgb(217, 119, 6)',
                'rgb(124, 58, 237)',
                'rgb(220, 38, 38)'
            ],
            borderWidth: 2,
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const index = context.dataIndex;
                        const tutar = new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY' }).format(context.parsed.y);
                        const adet = cekVadeCounts[index];
                        return ['Tutar: ' + tutar, 'Cek Sayisi: ' + adet + ' adet'];
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: 'rgba(148,163,184,.15)' },
                ticks: {
                    callback: function(value) {
                        return new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY', maximumFractionDigits: 0 }).format(value);
                    }
                }
            },
            x: { grid: { display: false } }
        }
    }
});

// 3. Vade Dagilimi (Doughnut)
const ctx3 = document.getElementById('agingChart').getContext('2d');
new Chart(ctx3, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode($vade_labels); ?>,
        datasets: [{
            data: <?php echo json_encode($vade_values); ?>,
            backgroundColor: [
                'rgb(5, 150, 105)',
                'rgb(217, 119, 6)',
                'rgb(251, 146, 60)',
                'rgb(220, 38, 38)'
            ],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { padding: 10, font: { size: 11 } } },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const label = context.label || '';
                        const value = new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY' }).format(context.parsed);
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const percentage = ((context.parsed / total) * 100).toFixed(1);
                        return label + ': ' + value + ' (' + percentage + '%)';
                    }
                }
            }
        }
    }
});
</script>

</body>
</html>
