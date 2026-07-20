<?php
declare(strict_types=1);

include_once(__DIR__ . "/ayr.php");
require_once __DIR__ . '/kontrol.php';
include_once(__DIR__ . "/log_ip.php");

// Yetki kontrolü - M13 (Günlük İşlemler) yetkisi gerekli
if (m_p_yetki($terminalkullanici, 'M13') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// Tarih filtresi
$selected_date = $_GET['tarih'] ?? date('Y-m-d');

// Türkçe tarih gösterimi için
$aylar = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
$gunler = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
$tarih_obj = strtotime((string) $selected_date);
$turkce_tarih = date('d', $tarih_obj) . ' ' . $aylar[date('n', $tarih_obj)] . ' ' . date('Y', $tarih_obj) . ', ' . $gunler[date('w', $tarih_obj)];

// 1. GÜNLÜK SATIŞ ÖZETİ
$sql_sales = "
    SELECT
        COUNT(DISTINCT CLIENTREF) AS MUSTERI_SAYISI,
        COUNT(*) AS ISLEM_SAYISI,
        SUM((1-SIGN)*AMOUNT) AS TOPLAM_SATIS
    FROM " . $firmadonem . "CLFLINE WITH(NOLOCK)
    WHERE CANCELLED=0
        AND TRCODE=38
        AND CAST(DATE_ AS DATE) = :tarih
";

$stmt_sales = $dbh->prepare($sql_sales);
$stmt_sales->bindParam(':tarih', $selected_date, PDO::PARAM_STR);
$stmt_sales->execute();
$sales_summary = $stmt_sales->fetch(PDO::FETCH_ASSOC);

// 2. SATIŞ DETAYLARI
$sql_sales_detail = "
    SELECT
        C.DATE_ AS TARIH,
        CL.CODE AS CARI_KODU,
        CL.DEFINITION_ AS CARI_ADI,
        (1-C.SIGN)*C.AMOUNT AS TUTAR,
        C.TRCODE,
        C.REPORTRATE AS KUR
    FROM " . $firmadonem . "CLFLINE C WITH(NOLOCK)
    LEFT JOIN " . $firma . "CLCARD CL ON CL.LOGICALREF = C.CLIENTREF
    WHERE C.CANCELLED=0
        AND C.TRCODE=38
        AND CAST(C.DATE_ AS DATE) = :tarih
    ORDER BY C.DATE_ DESC
";

$stmt_sales_detail = $dbh->prepare($sql_sales_detail);
$stmt_sales_detail->bindParam(':tarih', $selected_date, PDO::PARAM_STR);
$stmt_sales_detail->execute();
$sales_details = $stmt_sales_detail->fetchAll(PDO::FETCH_ASSOC);

// 3. KASA HAREKETLERİ ÖZETİ
$sql_cash_summary = "
    SELECT
        SUM(CASE WHEN l.SIGN = 0 THEN l.AMOUNT ELSE 0 END) AS GIRIS,
        SUM(CASE WHEN l.SIGN = 1 THEN l.AMOUNT ELSE 0 END) AS CIKIS,
        SUM(CASE WHEN l.SIGN = 0 THEN l.AMOUNT ELSE -l.AMOUNT END) AS NET
    FROM " . $firma . "KSCARD k WITH(NOLOCK)
    LEFT JOIN " . $firmadonem . "KSLINES l ON l.CARDREF = k.LOGICALREF
    WHERE k.ACTIVE = 0
        AND CAST(l.DATE_ AS DATE) = :tarih
";

$stmt_cash_summary = $dbh->prepare($sql_cash_summary);
$stmt_cash_summary->bindParam(':tarih', $selected_date, PDO::PARAM_STR);
$stmt_cash_summary->execute();
$cash_summary = $stmt_cash_summary->fetch(PDO::FETCH_ASSOC);

// 4. KASA HAREKETLERİ DETAY
$sql_cash_detail = "
    SELECT
        k.CODE AS KASA_KODU,
        k.NAME AS KASA_ADI,
        l.DATE_ AS TARIH,
        CASE WHEN l.SIGN = 0 THEN l.AMOUNT ELSE 0 END AS GIRIS,
        CASE WHEN l.SIGN = 1 THEN l.AMOUNT ELSE 0 END AS CIKIS,
        l.LINEEXP AS ACIKLAMA
    FROM " . $firma . "KSCARD k WITH(NOLOCK)
    LEFT JOIN " . $firmadonem . "KSLINES l ON l.CARDREF = k.LOGICALREF
    WHERE k.ACTIVE = 0
        AND CAST(l.DATE_ AS DATE) = :tarih
    ORDER BY l.DATE_ DESC
";

$stmt_cash_detail = $dbh->prepare($sql_cash_detail);
$stmt_cash_detail->bindParam(':tarih', $selected_date, PDO::PARAM_STR);
$stmt_cash_detail->execute();
$cash_details = $stmt_cash_detail->fetchAll(PDO::FETCH_ASSOC);

// 5. ALINAN ÇEKLER (Bugün alınan/kaydedilen)
$sql_checks_received = "
    SELECT
        COUNT(DISTINCT C.LOGICALREF) AS ADET,
        SUM(C.AMOUNT) AS TOPLAM
    FROM " . $firmadonem . "CSCARD C WITH(NOLOCK)
    INNER JOIN " . $firmadonem . "CSTRANS T ON T.CSREF = C.LOGICALREF
    WHERE C.CURRSTAT IN(1)
        AND C.STATUS IN(0,1)
        AND C.DOC = 1
        AND CAST(T.DATE_ AS DATE) = :tarih
        AND T.TRCODE IN (1, 2, 3)
";

$stmt_checks_received = $dbh->prepare($sql_checks_received);
$stmt_checks_received->bindParam(':tarih', $selected_date, PDO::PARAM_STR);
$stmt_checks_received->execute();
$checks_received = $stmt_checks_received->fetch(PDO::FETCH_ASSOC);

// 6. ALINAN ÇEKLER DETAY
$sql_checks_received_detail = "
    SELECT
        C.DUEDATE AS VADE_TARIHI,
        C.SETDATE AS TANZIM_TARIHI,
        C.AMOUNT AS TUTAR,
        C.NEWSERINO AS SERI_NO,
        C.OWING AS BORCLU,
        ISNULL(CL.DEFINITION_, '---') AS CARI,
        T.DATE_ AS KAYIT_TARIHI
    FROM " . $firmadonem . "CSCARD C WITH(NOLOCK)
    INNER JOIN " . $firmadonem . "CSTRANS T ON T.CSREF = C.LOGICALREF
    LEFT JOIN " . $firma . "CLCARD CL ON CL.LOGICALREF = T.CARDREF
    WHERE C.CURRSTAT IN(1)
        AND C.STATUS IN(0,1)
        AND C.DOC = 1
        AND CAST(T.DATE_ AS DATE) = :tarih
        AND T.TRCODE IN (1, 2, 3)
    ORDER BY C.DUEDATE
";

$stmt_checks_received_detail = $dbh->prepare($sql_checks_received_detail);
$stmt_checks_received_detail->bindParam(':tarih', $selected_date, PDO::PARAM_STR);
$stmt_checks_received_detail->execute();
$checks_received_detail = $stmt_checks_received_detail->fetchAll(PDO::FETCH_ASSOC);

// 7. VERİLEN ÇEKLER (Bugün verilen/kaydedilen)
$sql_checks_given = "
    SELECT
        COUNT(DISTINCT C.LOGICALREF) AS ADET,
        SUM(C.AMOUNT) AS TOPLAM
    FROM " . $firmadonem . "CSCARD C WITH(NOLOCK)
    INNER JOIN " . $firmadonem . "CSTRANS T ON T.CSREF = C.LOGICALREF
    WHERE C.CURRSTAT = 9
        AND C.STATUS IN(0,1)
        AND C.DOC = 1
        AND CAST(T.DATE_ AS DATE) = :tarih
        AND T.TRCODE IN (1, 2, 3)
";

$stmt_checks_given = $dbh->prepare($sql_checks_given);
$stmt_checks_given->bindParam(':tarih', $selected_date, PDO::PARAM_STR);
$stmt_checks_given->execute();
$checks_given = $stmt_checks_given->fetch(PDO::FETCH_ASSOC);

// 8. VERİLEN ÇEKLER DETAY
$sql_checks_given_detail = "
    SELECT
        C.DUEDATE AS VADE_TARIHI,
        C.SETDATE AS TANZIM_TARIHI,
        C.AMOUNT AS TUTAR,
        C.NEWSERINO AS SERI_NO,
        C.OWING AS ALACAKLI,
        ISNULL(CL.DEFINITION_, '---') AS CARI,
        T.DATE_ AS KAYIT_TARIHI
    FROM " . $firmadonem . "CSCARD C WITH(NOLOCK)
    INNER JOIN " . $firmadonem . "CSTRANS T ON T.CSREF = C.LOGICALREF
    LEFT JOIN " . $firma . "CLCARD CL ON CL.LOGICALREF = T.CARDREF
    WHERE C.CURRSTAT = 9
        AND C.STATUS IN(0,1)
        AND C.DOC = 1
        AND CAST(T.DATE_ AS DATE) = :tarih
        AND T.TRCODE IN (1, 2, 3)
    ORDER BY C.DUEDATE
";

$stmt_checks_given_detail = $dbh->prepare($sql_checks_given_detail);
$stmt_checks_given_detail->bindParam(':tarih', $selected_date, PDO::PARAM_STR);
$stmt_checks_given_detail->execute();
$checks_given_detail = $stmt_checks_given_detail->fetchAll(PDO::FETCH_ASSOC);

// Null değerleri sıfırla
$sales_summary['MUSTERI_SAYISI'] = $sales_summary['MUSTERI_SAYISI'] ?: 0;
$sales_summary['ISLEM_SAYISI'] = $sales_summary['ISLEM_SAYISI'] ?: 0;
$sales_summary['TOPLAM_SATIS'] = $sales_summary['TOPLAM_SATIS'] ?: 0;

$cash_summary['GIRIS'] = $cash_summary['GIRIS'] ?: 0;
$cash_summary['CIKIS'] = $cash_summary['CIKIS'] ?: 0;
$cash_summary['NET'] = $cash_summary['NET'] ?: 0;

$checks_received['ADET'] = $checks_received['ADET'] ?: 0;
$checks_received['TOPLAM'] = $checks_received['TOPLAM'] ?: 0;

$checks_given['ADET'] = $checks_given['ADET'] ?: 0;
$checks_given['TOPLAM'] = $checks_given['TOPLAM'] ?: 0;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gunluk Islem Ozeti</title>
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
            --indigo: #4f46e5;
            --indigo-soft: #eef2ff;
            --amber: #d97706;
            --amber-soft: #fffbeb;
            --sky: #0284c7;
            --sky-soft: #eff6ff;
            --purple: #7c3aed;
            --purple-soft: #f5f3ff;
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
            border-bottom: 1px solid rgba(2, 132, 199, 0.18);
            box-shadow: 0 2px 8px rgba(2, 132, 199, 0.04);
        }
        .header-inner {
            max-width: 1200px; margin: 0 auto;
            padding: 14px 24px;
            display: flex; align-items: center; gap: 14px;
            flex-wrap: wrap;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
            flex-shrink: 0;
        }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--sky); }
        .header-divider { width: 1px; height: 24px; background: var(--border); flex-shrink: 0; }
        .header-title { min-width: 0; flex: 1 1 auto; }
        .header-title h1 {
            font-size: 16px; font-weight: 700; color: var(--text-1);
            display: flex; align-items: center; gap: 8px; margin: 0;
        }
        .header-title h1 i { color: var(--sky); font-size: 14px; }
        .header-title p {
            margin: 2px 0 0; font-size: 11.5px; color: var(--text-2); font-weight: 500;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .header-title p .kod {
            display: inline-block; padding: 1px 7px;
            background: var(--sky-soft); color: var(--sky);
            border-radius: 100px; font-size: 10px; font-weight: 700; margin-left: 4px;
        }
        .header-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .btn-excel {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 16px; background: var(--emerald); color: #fff;
            border: none; border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12.5px; font-weight: 700;
            cursor: pointer; transition: all 0.2s ease; text-decoration: none;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.22);
        }
        .btn-excel:hover { background: #047857; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(5, 150, 105, 0.3); }
        .btn-print {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 16px; background: var(--sky); color: #fff;
            border: none; border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12.5px; font-weight: 700;
            cursor: pointer; transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.22);
        }
        .btn-print:hover { background: #0369a1; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(2, 132, 199, 0.3); }

        .filter-panel {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(2, 132, 199, 0.18);
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            padding: 16px 18px;
            margin-bottom: 16px;
            animation: cardIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
            align-items: end;
        }
        .field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .field label {
            font-size: 10.5px; color: var(--text-2); font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.4px;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .field label i { color: var(--sky); font-size: 11px; }
        .field input {
            width: 100%;
            padding: 11px 14px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14px;
            color: var(--text-1);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            outline: none;
            transition: all 0.2s ease;
        }
        .field input:focus {
            border-color: rgba(2, 132, 199, 0.5);
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.1);
        }
        .btn-apply {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 11px 18px; background: var(--sky); color: #fff;
            border: none; border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12.5px; font-weight: 700;
            cursor: pointer; transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.22);
            white-space: nowrap;
        }
        .btn-apply:hover { background: #0369a1; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(2, 132, 199, 0.3); }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }
        .stat-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            padding: 16px 18px;
            display: flex; align-items: center; gap: 14px;
            position: relative; overflow: hidden;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
        }
        .stat-card:nth-child(2) { animation-delay: 60ms; }
        .stat-card:nth-child(3) { animation-delay: 120ms; }
        .stat-card:nth-child(4) { animation-delay: 180ms; }
        .stat-card:nth-child(5) { animation-delay: 240ms; }
        .stat-card:nth-child(6) { animation-delay: 300ms; }
        .stat-card::before {
            content: ''; position: absolute;
            top: 0; left: 0; bottom: 0;
            width: 4px;
        }
        .stat-card.red::before     { background: var(--red); }
        .stat-card.indigo::before  { background: var(--indigo); }
        .stat-card.sky::before     { background: var(--sky); }
        .stat-card.emerald::before { background: var(--emerald); }
        .stat-card.amber::before   { background: var(--amber); }
        .stat-card.purple::before  { background: var(--purple); }
        .stat-card .icon-box {
            width: 48px; height: 48px;
            flex-shrink: 0;
            border-radius: 12px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 18px;
        }
        .stat-card.red .icon-box     { background: var(--red-soft); color: var(--red); }
        .stat-card.indigo .icon-box  { background: var(--indigo-soft); color: var(--indigo); }
        .stat-card.sky .icon-box     { background: var(--sky-soft); color: var(--sky); }
        .stat-card.emerald .icon-box { background: var(--emerald-soft); color: var(--emerald); }
        .stat-card.amber .icon-box   { background: var(--amber-soft); color: var(--amber); }
        .stat-card.purple .icon-box  { background: var(--purple-soft); color: var(--purple); }
        .stat-card .stat-body { flex: 1 1 auto; min-width: 0; }
        .stat-card .stat-label {
            font-size: 10.5px; color: var(--text-2); font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.4px;
            display: block;
        }
        .stat-card .stat-value {
            display: block; margin-top: 3px;
            font-size: 18px; font-weight: 700; color: var(--text-1);
            line-height: 1.2;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .stat-card .stat-sub {
            display: block; margin-top: 2px;
            font-size: 11px; color: var(--text-3); font-weight: 500;
        }
        .stat-card.red .stat-value     { color: var(--red); }
        .stat-card.indigo .stat-value  { color: var(--indigo); }
        .stat-card.sky .stat-value     { color: var(--sky); }
        .stat-card.emerald .stat-value { color: var(--emerald); }
        .stat-card.amber .stat-value   { color: var(--amber); }
        .stat-card.purple .stat-value  { color: var(--purple); }

        .section-block { margin-bottom: 18px; }
        .table-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            overflow: hidden;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) 0.18s both;
        }
        .table-card.red     { border-color: rgba(111, 16, 34, 0.18); }
        .table-card.emerald { border-color: rgba(5, 150, 105, 0.18); }
        .table-card.purple  { border-color: rgba(124, 58, 237, 0.18); }
        .table-card.amber   { border-color: rgba(217, 119, 6, 0.18); }

        .section-head {
            display: flex; align-items: center; gap: 12px;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
        }
        .section-head .icon-box {
            width: 42px; height: 42px;
            flex-shrink: 0;
            border-radius: 11px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 16px;
        }
        .section-head.red    .icon-box { background: var(--red-soft);    color: var(--red); }
        .section-head.emerald .icon-box { background: var(--emerald-soft); color: var(--emerald); }
        .section-head.purple .icon-box { background: var(--purple-soft); color: var(--purple); }
        .section-head.amber  .icon-box { background: var(--amber-soft);  color: var(--amber); }
        .section-head .head-body { min-width: 0; flex: 1 1 auto; }
        .section-head h2 {
            margin: 0; font-size: 14px; font-weight: 700; color: var(--text-1);
        }
        .section-head p {
            margin: 2px 0 0; font-size: 11.5px; color: var(--text-2); font-weight: 500;
        }
        .section-head .count-pill {
            display: inline-block; padding: 4px 10px;
            background: #f3f4f6; color: var(--text-2);
            border-radius: 100px; font-size: 10.5px; font-weight: 700;
            flex-shrink: 0;
        }

        .gd-table { width: 100%; border-collapse: collapse; }
        .gd-table thead { background: linear-gradient(180deg, #fff, #fafafa); }
        .gd-table thead th {
            padding: 12px 16px;
            font-size: 10px; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: 0.5px;
            text-align: left; border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .gd-table thead th.right { text-align: right; }
        .gd-table tbody td {
            padding: 12px 16px;
            font-size: 12.5px; color: var(--text-1);
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .gd-table tbody tr { transition: background 0.15s ease; }
        .gd-table tbody tr:last-child td { border-bottom: none; }
        .table-card.red     .gd-table tbody tr:hover { background: var(--red-soft); }
        .table-card.emerald .gd-table tbody tr:hover { background: var(--emerald-soft); }
        .table-card.purple  .gd-table tbody tr:hover { background: var(--purple-soft); }
        .table-card.amber   .gd-table tbody tr:hover { background: var(--amber-soft); }

        .gd-table td.date   { color: var(--text-2); font-size: 12px; white-space: nowrap; }
        .gd-table td.kodu   { color: var(--text-3); font-size: 11.5px; font-weight: 600; white-space: nowrap; }
        .gd-table td.cari   { color: var(--text-1); font-weight: 600; }
        .gd-table td.aciklama {
            color: var(--text-2); font-size: 12px;
            max-width: 280px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .gd-table td.tutar {
            text-align: right; font-weight: 700; color: var(--text-1);
            white-space: nowrap;
        }
        .gd-table td.tutar.giris  { color: var(--emerald); }
        .gd-table td.tutar.cikis  { color: var(--red); }
        .gd-table td.seri { color: var(--text-1); font-weight: 600; font-size: 12px; }

        .pill {
            display: inline-flex; align-items: center;
            padding: 3px 10px;
            border-radius: 100px;
            font-size: 11px; font-weight: 700;
            white-space: nowrap;
        }
        .pill.giris  { background: var(--emerald-soft); color: var(--emerald); border: 1px solid rgba(5, 150, 105, 0.2); }
        .pill.cikis  { background: var(--red-soft); color: var(--red); border: 1px solid rgba(111, 16, 34, 0.2); }
        .muted { color: var(--text-3); }

        .empty-row td {
            text-align: center; padding: 40px 20px;
            color: var(--text-3); font-size: 13px;
        }
        .empty-row i {
            display: block; font-size: 28px; margin-bottom: 10px; color: var(--text-3);
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        @media (max-width: 767px) {
            .header-inner { padding: 12px 14px; gap: 10px; }
            .header-title h1 { font-size: 14px; }
            .header-title p { font-size: 10.5px; }
            .header-back { width: 30px; height: 30px; border-radius: 8px; }
            .header-actions { width: 100%; justify-content: flex-start; }
            .btn-print, .btn-excel { padding: 8px 14px; font-size: 12px; }

            main { padding: 14px 12px 40px !important; }

            .filter-panel { padding: 14px; }
            .filter-grid { grid-template-columns: 1fr; gap: 10px; }
            .field input { font-size: 16px; } /* iOS zoom fix */
            .btn-apply { width: 100%; justify-content: center; }

            .stat-grid { grid-template-columns: 1fr; gap: 10px; margin-bottom: 14px; }
            .stat-card { padding: 14px 16px; }
            .stat-card .icon-box { width: 42px; height: 42px; font-size: 16px; }
            .stat-card .stat-value { font-size: 16px; }

            .section-head { padding: 14px 16px; gap: 10px; }
            .section-head h2 { font-size: 13px; }
            .section-head .icon-box { width: 38px; height: 38px; font-size: 14px; }

            .gd-table thead { display: none; }
            .gd-table, .gd-table tbody, .gd-table tr, .gd-table td {
                display: block; width: 100%;
            }
            .gd-table tbody tr {
                padding: 14px 16px;
                border-bottom: 1px solid #f3f4f6;
                display: grid;
                grid-template-columns: 1fr auto;
                gap: 4px 10px;
            }
            .gd-table tbody tr:last-child { border-bottom: none; }
            .gd-table tbody td { padding: 0; border-bottom: none; }
            .gd-table td.date  { grid-column: 2; grid-row: 1; text-align: right; font-size: 10.5px; }
            .gd-table td.kodu  { grid-column: 1; grid-row: 1; font-size: 10.5px; }
            .gd-table td.cari  { grid-column: 1 / -1; font-size: 13px; }
            .gd-table td.seri  { grid-column: 1 / -1; font-size: 12px; }
            .gd-table td.aciklama { grid-column: 1 / -1; max-width: none; white-space: normal; font-size: 11px; }
            .gd-table td.tutar { grid-column: 1 / -1; text-align: right; font-size: 14px; }
            .gd-table td.tutar::before { content: attr(data-label) ' '; color: var(--text-3); font-weight: 400; font-size: 10.5px; }
        }

        @media print {
            body { background: #fff; }
            .top-header { position: static; box-shadow: none; border-bottom: 2px solid #000; }
            .header-actions, .no-print { display: none !important; }
            .filter-panel { display: none !important; }
            .stat-card, .table-card { box-shadow: none; border: 1px solid #ccc; animation: none; break-inside: avoid; }
            .gd-table tbody tr:hover { background: transparent !important; }
            main { padding: 0 !important; }
        }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="header-inner">
            <a href="index.php" class="header-back" title="Ana Sayfa">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div class="header-divider"></div>
            <div class="header-title">
                <h1><i class="fa-solid fa-calendar-day"></i>Gunluk Islem Ozeti</h1>
                <p>
                    <?php echo htmlspecialchars((string) $turkce_tarih); ?>
                    <span class="kod"><?php echo htmlspecialchars((string) date('d.m.Y', (int) $tarih_obj)); ?></span>
                </p>
            </div>
            <div class="header-actions no-print">
                <a href="javascript:void(0);" onclick="exportExcel()" class="btn-excel" title="Excel Indir">
                    <i class="fa-solid fa-file-excel"></i> Excel
                </a>
                <button onclick="window.print()" class="btn-print" type="button" title="Yazdir">
                    <i class="fa-solid fa-print"></i> Yazdir
                </button>
            </div>
        </div>
    </header>

    <main style="max-width:1200px;margin:0 auto;padding:22px 24px 60px;">

        <!-- Filter Panel -->
        <div class="filter-panel no-print">
            <div class="filter-grid">
                <div class="field">
                    <label for="tarih_secimi"><i class="fa-solid fa-calendar"></i> Tarih Secimi</label>
                    <input type="date" id="tarih_secimi" value="<?php echo htmlspecialchars((string) $selected_date); ?>">
                </div>
                <button type="button" class="btn-apply" onclick="tarihFiltrele()">
                    <i class="fa-solid fa-magnifying-glass"></i> Filtrele
                </button>
            </div>
        </div>

        <!-- Stat Cards -->
        <div class="stat-grid">
            <div class="stat-card red">
                <span class="icon-box"><i class="fa-solid fa-lira-sign"></i></span>
                <div class="stat-body">
                    <span class="stat-label">Toplam Satis</span>
                    <span class="stat-value"><?php echo number_format((float)($sales_summary['TOPLAM_SATIS'] ?? 0), 2, ',', '.'); ?> &#8378;</span>
                    <span class="stat-sub"><?php echo (int) $sales_summary['ISLEM_SAYISI']; ?> islem</span>
                </div>
            </div>
            <div class="stat-card indigo">
                <span class="icon-box"><i class="fa-solid fa-users"></i></span>
                <div class="stat-body">
                    <span class="stat-label">Musteri Sayisi</span>
                    <span class="stat-value"><?php echo (int) $sales_summary['MUSTERI_SAYISI']; ?></span>
                    <span class="stat-sub">farkli musteri</span>
                </div>
            </div>
            <div class="stat-card emerald">
                <span class="icon-box"><i class="fa-solid fa-arrow-down"></i></span>
                <div class="stat-body">
                    <span class="stat-label">Kasa Giris</span>
                    <span class="stat-value"><?php echo number_format((float)($cash_summary['GIRIS'] ?? 0), 2, ',', '.'); ?> &#8378;</span>
                    <span class="stat-sub">toplanan odeme</span>
                </div>
            </div>
            <div class="stat-card amber">
                <span class="icon-box"><i class="fa-solid fa-arrow-up"></i></span>
                <div class="stat-body">
                    <span class="stat-label">Kasa Cikis</span>
                    <span class="stat-value"><?php echo number_format((float)($cash_summary['CIKIS'] ?? 0), 2, ',', '.'); ?> &#8378;</span>
                    <span class="stat-sub">yapilan odeme</span>
                </div>
            </div>
            <div class="stat-card purple">
                <span class="icon-box"><i class="fa-solid fa-money-check-dollar"></i></span>
                <div class="stat-body">
                    <span class="stat-label">Alinan Cekler</span>
                    <span class="stat-value"><?php echo number_format((float)($checks_received['TOPLAM'] ?? 0), 2, ',', '.'); ?> &#8378;</span>
                    <span class="stat-sub"><?php echo (int) $checks_received['ADET']; ?> adet cek</span>
                </div>
            </div>
            <div class="stat-card sky">
                <span class="icon-box"><i class="fa-solid fa-file-invoice-dollar"></i></span>
                <div class="stat-body">
                    <span class="stat-label">Verilen Cekler</span>
                    <span class="stat-value"><?php echo number_format((float)($checks_given['TOPLAM'] ?? 0), 2, ',', '.'); ?> &#8378;</span>
                    <span class="stat-sub"><?php echo (int) $checks_given['ADET']; ?> adet cek</span>
                </div>
            </div>
        </div>

        <!-- Satis Islemleri -->
        <div class="section-block">
            <div class="table-card red">
                <div class="section-head red">
                    <span class="icon-box"><i class="fa-solid fa-bag-shopping"></i></span>
                    <div class="head-body">
                        <h2>Satis Islemleri Detayi</h2>
                        <p>Gun icindeki satis kalemleri</p>
                    </div>
                    <span class="count-pill"><?php echo count($sales_details); ?> kayit</span>
                </div>
                <div style="overflow-x:auto;">
                    <table class="gd-table">
                        <thead>
                            <tr>
                                <th>Tarih</th>
                                <th>Musteri Kodu</th>
                                <th>Musteri Adi</th>
                                <th class="right">Tutar</th>
                                <th class="right">Kur</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($sales_details) > 0): ?>
                                <?php foreach ($sales_details as $sale): ?>
                                    <tr>
                                        <td class="date"><?php echo date('d.m.Y H:i', strtotime((string) $sale['TARIH'])); ?></td>
                                        <td class="kodu"><?php echo htmlspecialchars((string) $sale['CARI_KODU']); ?></td>
                                        <td class="cari"><?php echo htmlspecialchars((string) $sale['CARI_ADI']); ?></td>
                                        <td class="tutar" data-label="Tutar"><?php echo number_format((float)($sale['TUTAR'] ?? 0), 2, ',', '.'); ?> &#8378;</td>
                                        <td class="tutar" data-label="Kur"><span class="muted"><?php echo number_format((float)($sale['KUR'] ?? 0), 4, ',', '.'); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr class="empty-row">
                                    <td colspan="5">
                                        <i class="fa-solid fa-box-open"></i>
                                        Bu tarih icin satis islemi bulunamadi.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Kasa Hareketleri -->
        <div class="section-block">
            <div class="table-card emerald">
                <div class="section-head emerald">
                    <span class="icon-box"><i class="fa-solid fa-cash-register"></i></span>
                    <div class="head-body">
                        <h2>Kasa Hareketleri</h2>
                        <p>Gun icindeki giris ve cikislar</p>
                    </div>
                    <span class="count-pill"><?php echo count($cash_details); ?> kayit</span>
                </div>
                <div style="overflow-x:auto;">
                    <table class="gd-table">
                        <thead>
                            <tr>
                                <th>Tarih</th>
                                <th>Kasa</th>
                                <th>Aciklama</th>
                                <th class="right">Giris</th>
                                <th class="right">Cikis</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($cash_details) > 0): ?>
                                <?php foreach ($cash_details as $cash): ?>
                                    <?php
                                    $aciklama = (isset($cash['ACIKLAMA']) && $cash['ACIKLAMA'] !== '') ? $cash['ACIKLAMA'] : '-';
                                    ?>
                                    <tr>
                                        <td class="date"><?php echo date('d.m.Y H:i', strtotime((string) $cash['TARIH'])); ?></td>
                                        <td class="cari"><?php echo htmlspecialchars((string) $cash['KASA_ADI']); ?></td>
                                        <td class="aciklama"><?php echo htmlspecialchars((string) $aciklama); ?></td>
                                        <td class="tutar" data-label="Giris">
                                            <?php if ((float) $cash['GIRIS'] > 0): ?>
                                                <span class="pill giris">+<?php echo number_format((float) $cash['GIRIS'], 2, ',', '.'); ?> &#8378;</span>
                                            <?php else: ?>
                                                <span class="muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="tutar" data-label="Cikis">
                                            <?php if ((float) $cash['CIKIS'] > 0): ?>
                                                <span class="pill cikis">-<?php echo number_format((float) $cash['CIKIS'], 2, ',', '.'); ?> &#8378;</span>
                                            <?php else: ?>
                                                <span class="muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr class="empty-row">
                                    <td colspan="5">
                                        <i class="fa-solid fa-inbox"></i>
                                        Bu tarih icin kasa hareketi bulunamadi.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Alinan Cekler -->
        <div class="section-block">
            <div class="table-card purple">
                <div class="section-head purple">
                    <span class="icon-box"><i class="fa-solid fa-money-check"></i></span>
                    <div class="head-body">
                        <h2>Alinan Cekler</h2>
                        <p>Bugun kaydedilen cek detaylari</p>
                    </div>
                    <span class="count-pill"><?php echo count($checks_received_detail); ?> kayit</span>
                </div>
                <div style="overflow-x:auto;">
                    <table class="gd-table">
                        <thead>
                            <tr>
                                <th>Seri No</th>
                                <th>Musteri</th>
                                <th>Borclu</th>
                                <th>Tanzim</th>
                                <th>Vade</th>
                                <th class="right">Tutar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($checks_received_detail) > 0): ?>
                                <?php foreach ($checks_received_detail as $check): ?>
                                    <tr>
                                        <td class="seri"><?php echo htmlspecialchars((string) $check['SERI_NO']); ?></td>
                                        <td class="cari"><?php echo htmlspecialchars((string) $check['CARI']); ?></td>
                                        <td class="aciklama"><?php echo htmlspecialchars((string) $check['BORCLU']); ?></td>
                                        <td class="date"><?php echo date('d.m.Y', strtotime((string) $check['TANZIM_TARIHI'])); ?></td>
                                        <td class="date"><?php echo date('d.m.Y', strtotime((string) $check['VADE_TARIHI'])); ?></td>
                                        <td class="tutar" data-label="Tutar"><?php echo number_format((float)($check['TUTAR'] ?? 0), 2, ',', '.'); ?> &#8378;</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr class="empty-row">
                                    <td colspan="6">
                                        <i class="fa-solid fa-inbox"></i>
                                        Bu tarih icin alinan cek bulunamadi.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Verilen Cekler -->
        <div class="section-block">
            <div class="table-card amber">
                <div class="section-head amber">
                    <span class="icon-box"><i class="fa-solid fa-file-invoice-dollar"></i></span>
                    <div class="head-body">
                        <h2>Verilen Cekler</h2>
                        <p>Bugun duzenlenen cek detaylari</p>
                    </div>
                    <span class="count-pill"><?php echo count($checks_given_detail); ?> kayit</span>
                </div>
                <div style="overflow-x:auto;">
                    <table class="gd-table">
                        <thead>
                            <tr>
                                <th>Seri No</th>
                                <th>Cari</th>
                                <th>Alacakli</th>
                                <th>Tanzim</th>
                                <th>Vade</th>
                                <th class="right">Tutar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($checks_given_detail) > 0): ?>
                                <?php foreach ($checks_given_detail as $check): ?>
                                    <tr>
                                        <td class="seri"><?php echo htmlspecialchars((string) $check['SERI_NO']); ?></td>
                                        <td class="cari"><?php echo htmlspecialchars((string) $check['CARI']); ?></td>
                                        <td class="aciklama"><?php echo htmlspecialchars((string) $check['ALACAKLI']); ?></td>
                                        <td class="date"><?php echo date('d.m.Y', strtotime((string) $check['TANZIM_TARIHI'])); ?></td>
                                        <td class="date"><?php echo date('d.m.Y', strtotime((string) $check['VADE_TARIHI'])); ?></td>
                                        <td class="tutar" data-label="Tutar"><?php echo number_format((float)($check['TUTAR'] ?? 0), 2, ',', '.'); ?> &#8378;</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr class="empty-row">
                                    <td colspan="6">
                                        <i class="fa-solid fa-inbox"></i>
                                        Bu tarih icin verilen cek bulunamadi.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script>
        function tarihFiltrele() {
            var tarih = document.getElementById('tarih_secimi').value;
            if (tarih) {
                window.location.href = 'gunluk_islemler.php?tarih=' + tarih;
            }
        }

        function exportExcel() {
            var tarih = document.getElementById('tarih_secimi').value;
            window.location.href = 'gunluk_islemler_excel.php?tarih=' + tarih;
        }

        document.getElementById('tarih_secimi').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                tarihFiltrele();
            }
        });
    </script>
</body>
</html>
