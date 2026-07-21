<?php
declare(strict_types=1);

include_once(__DIR__ . '/ayr.php');
include(__DIR__ . '/kontrol.php');
include_once(__DIR__ . '/log_ip.php');

$siparisCariAramaRaw = trim((string)($_GET['siparis_cari'] ?? ''));
$seciliSiparisCariId = isset($_GET['siparis_cariid']) ? (int)$_GET['siparis_cariid'] : 0;

$siparisliCariler = [];
$seciliCariSiparisler = [];
$seciliCariUnvan = '';
$seciliCariBakiye = null;
$seciliCariRiskLimit = null;
$riskLimitKolon = '';
$riskDurum = '';
$riskMesaj = '';
$riskOran = null;

$aramaHatasi = '';
$siparisHatasi = '';

if ($siparisCariAramaRaw !== '') {
    $arama = function_exists('turkce') ? turkce($siparisCariAramaRaw) : $siparisCariAramaRaw;
    $like = '%' . $arama . '%';

    $sqlSiparisliCariler = "
        SELECT TOP 50
          C.LOGICALREF AS CARIID,
          C.CODE AS KODU,
          C.DEFINITION_ AS UNVANI,
          C.CITY AS SEHIR,
          COUNT(F.LOGICALREF) AS SIPARIS_SAYISI,
          ISNULL(SUM(ISNULL(F.NETTOTAL, 0)), 0) AS TOPLAM_TUTAR,
          MAX(F.DATE_) AS SON_SIPARIS_TARIHI
        FROM {$firmadonem}ORFICHE F WITH(NOLOCK)
        INNER JOIN {$firma}CLCARD C WITH(NOLOCK) ON C.LOGICALREF = F.CLIENTREF
        WHERE F.CANCELLED = 0
          AND F.TRCODE IN (1, 7)
          AND ISNULL(F.NETTOTAL, 0) > 0
          AND C.ACTIVE = 0
          AND (
               REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                 C.DEFINITION_, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')
                 COLLATE Turkish_CI_AS LIKE :p1
            OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                 C.CODE, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')
                 COLLATE Turkish_CI_AS LIKE :p2
            OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                 C.CITY, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')
                 COLLATE Turkish_CI_AS LIKE :p3
          )
        GROUP BY C.LOGICALREF, C.CODE, C.DEFINITION_, C.CITY
        ORDER BY MAX(F.DATE_) DESC, SUM(ISNULL(F.NETTOTAL, 0)) DESC
    ";

    try {
        $stmt = $dbh->prepare($sqlSiparisliCariler);
        $stmt->execute([
            ':p1' => $like,
            ':p2' => $like,
            ':p3' => $like,
        ]);
        $siparisliCariler = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Tek sonuç çıkarsa otomatik seç
        if ($seciliSiparisCariId <= 0 && count($siparisliCariler) === 1) {
            $seciliSiparisCariId = (int)$siparisliCariler[0]['CARIID'];
        }
    } catch (PDOException $e) {
        $aramaHatasi = 'Siparişi olan cariler aranırken bir hata oluştu.';
    }
}

if ($seciliSiparisCariId > 0) {
    try {
        $stmtCari = $dbh->prepare("
            SELECT CODE, DEFINITION_
            FROM {$firma}CLCARD
            WHERE LOGICALREF = :cariid
        ");
        $stmtCari->execute([':cariid' => $seciliSiparisCariId]);
        $cari = $stmtCari->fetch(PDO::FETCH_ASSOC);
        $seciliCariUnvan = (string)($cari['DEFINITION_'] ?? '');

        $stmtSiparis = $dbh->prepare("
            SELECT TOP 100
              F.LOGICALREF AS STOKHAREKET,
              F.FICHENO,
              F.DATE_,
              ISNULL(F.NETTOTAL, 0) AS NETTOTAL,
              ISNULL(F.GROSSTOTAL, 0) AS GROSSTOTAL
            FROM {$firmadonem}ORFICHE F WITH(NOLOCK)
            WHERE F.CLIENTREF = :cariid
              AND F.CANCELLED = 0
              AND F.TRCODE IN (1, 7)
              AND ISNULL(F.NETTOTAL, 0) > 0
            ORDER BY F.DATE_ DESC, F.LOGICALREF DESC
        ");
        $stmtSiparis->execute([':cariid' => $seciliSiparisCariId]);
        $seciliCariSiparisler = $stmtSiparis->fetchAll(PDO::FETCH_ASSOC);

        $stmtBakiye = $dbh->prepare("
            SELECT (ISNULL(G.DEBIT, 0) - ISNULL(G.CREDIT, 0)) AS BAKIYE
            FROM {$firmadonemx}GNTOTCL G WITH(NOLOCK)
            WHERE G.CARDREF = :cariid AND G.TOTTYP = 1
        ");
        $stmtBakiye->execute([':cariid' => $seciliSiparisCariId]);
        $seciliCariBakiye = (float)$stmtBakiye->fetchColumn();

        // Cari kartında risk limiti alanını dinamik tespit et.
        $tableName = $firma . 'CLCARD';
        $stmtRiskCols = $dbh->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_NAME = :table
              AND (
                COLUMN_NAME LIKE '%RISK%'
                OR COLUMN_NAME LIKE '%LIMIT%'
                OR COLUMN_NAME LIKE '%CREDIT%'
              )
        ");
        $stmtRiskCols->execute([':table' => $tableName]);
        $riskCols = $stmtRiskCols->fetchAll(PDO::FETCH_COLUMN);

        $riskColsUpperMap = [];
        foreach ($riskCols as $col) {
            $riskColsUpperMap[strtoupper((string)$col)] = (string)$col;
        }

        $priorityCols = ['RISKLIMIT', 'CREDITLIMIT', 'CRLIMIT', 'RISK_LIM', 'RISKLIM', 'CREDIT_LIMIT'];
        foreach ($priorityCols as $col) {
            if (isset($riskColsUpperMap[$col])) {
                $riskLimitKolon = $riskColsUpperMap[$col];
                break;
            }
        }
        if ($riskLimitKolon === '' && !empty($riskCols)) {
            $riskLimitKolon = (string)$riskCols[0];
        }

        if ($riskLimitKolon !== '') {
            $riskColSafe = preg_replace('/[^A-Za-z0-9_]/', '', $riskLimitKolon);
            if ($riskColSafe !== '') {
                $stmtRiskLimit = $dbh->prepare("
                    SELECT TRY_CONVERT(DECIMAL(18,2), [{$riskColSafe}]) AS RISK_LIMIT
                    FROM {$firma}CLCARD
                    WHERE LOGICALREF = :cariid
                ");
                $stmtRiskLimit->execute([':cariid' => $seciliSiparisCariId]);
                $riskLimit = $stmtRiskLimit->fetchColumn();
                $seciliCariRiskLimit = ($riskLimit !== false && $riskLimit !== null) ? (float)$riskLimit : null;
            }
        }

        if ($seciliCariBakiye !== null && $seciliCariBakiye > 0) {
            if ($seciliCariRiskLimit !== null && $seciliCariRiskLimit > 0) {
                $riskOran = $seciliCariBakiye / $seciliCariRiskLimit;
                if ($riskOran >= 1) {
                    $riskDurum = 'kritik';
                    $riskMesaj = 'Cari bakiyesi risk limitini aştı.';
                } elseif ($riskOran >= 0.8) {
                    $riskDurum = 'uyari';
                    $riskMesaj = 'Cari bakiyesi risk limitine çok yaklaştı.';
                }
            } elseif ($seciliCariBakiye >= 250000) {
                $riskDurum = 'uyari';
                $riskMesaj = 'Cari bakiyesi yüksek. (Risk limit alanı bulunamadı, bakiye bazlı uyarı verildi.)';
            }
        }
    } catch (PDOException $e) {
        $siparisHatasi = 'Seçili carinin siparişleri listelenirken bir hata oluştu.';
    }
}

function paraYaz(float|int|string|null $tutar): string
{
    if (function_exists('paraformat')) {
        return paraformat((float)$tutar);
    }
    return number_format((float)$tutar, 2, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hizli Erisim</title>
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (file_exists(__DIR__ . '/pwa-header.php')) { include_once(__DIR__ . '/pwa-header.php'); } ?>
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
            --rose: #e11d48;
            --rose-soft: #fff1f2;
            --pink: #db2777;
            --pink-soft: #fdf2f8;
        }
        * { box-sizing: border-box; margin: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
        }

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
            max-width: 1100px; margin: 0 auto;
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

        /* ═══════ MAIN LAYOUT ═══════ */
        .page-main {
            max-width: 1100px;
            margin: 0 auto;
            padding: 22px 24px 60px;
        }

        /* ═══════ HERO CARD ═══════ */
        .hero-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            padding: 20px 22px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .hero-card .hero-ico {
            width: 56px; height: 56px;
            border-radius: 14px;
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            color: var(--red);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }
        .hero-card .hero-text h1 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-1);
            line-height: 1.2;
        }
        .hero-card .hero-text p {
            margin-top: 4px;
            font-size: 12px;
            color: var(--text-2);
        }

        /* ═══════ GLASS CARD ═══════ */
        .glass-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            padding: 20px 22px;
            margin-bottom: 20px;
            animation: cardIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .glass-card + .glass-card { margin-top: 0; }

        .card-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }
        .card-head .card-ico {
            width: 38px; height: 38px;
            border-radius: 11px;
            background: var(--red-soft);
            color: var(--red);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
        }
        .card-head.tone-sky .card-ico { background: var(--sky-soft); color: var(--sky); }
        .card-head .card-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-1);
            line-height: 1.2;
        }
        .card-head .card-sub {
            margin-top: 2px;
            font-size: 11px;
            color: var(--text-2);
        }

        /* ═══════ SEARCH FORM ═══════ */
        .search-form {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
            align-items: end;
        }
        .search-field label {
            display: block;
            font-size: 11.5px;
            color: var(--text-2);
            margin-bottom: 6px;
            font-weight: 500;
        }
        .search-field input {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 11px 14px;
            font-family: inherit;
            font-size: 13px;
            color: var(--text-1);
            background: #fff;
            outline: none;
            transition: all 0.2s ease;
        }
        .search-field input:focus {
            border-color: var(--red);
            box-shadow: 0 0 0 3px rgba(111, 16, 34, 0.12);
        }
        .btn-primary {
            height: 44px;
            padding: 0 22px;
            border-radius: 12px;
            background: var(--red);
            color: #fff;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .btn-primary:hover { background: #b91c1c; transform: translateY(-1px); }

        /* ═══════ ALERT ═══════ */
        .alert {
            margin-top: 14px;
            border-radius: 12px;
            padding: 10px 14px;
            font-size: 12.5px;
            border: 1px solid var(--red-soft);
            background: var(--red-soft);
            color: #991b1b;
        }
        .alert-warn {
            border-color: #fde68a;
            background: var(--amber-soft);
            color: #92400e;
        }
        .alert-strong {
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }
        .empty-msg {
            margin-top: 14px;
            font-size: 12.5px;
            color: var(--text-2);
        }

        /* ═══════ CARI LIST ═══════ */
        .cari-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 16px;
        }
        .cari-item {
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px 16px;
            background: #fff;
            transition: all 0.2s ease;
        }
        .cari-item:hover { border-color: rgba(239, 68, 68, 0.3); box-shadow: 0 4px 14px rgba(0,0,0,0.04); }
        .cari-item.is-active {
            border-color: var(--red);
            background: linear-gradient(180deg, #fff 0%, var(--red-soft) 100%);
        }
        .cari-row {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }
        .cari-info .cari-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-1);
        }
        .cari-info .cari-meta {
            margin-top: 4px;
            font-size: 11px;
            color: var(--text-3);
        }
        .cari-amount { text-align: right; }
        .cari-amount .amount-label {
            font-size: 10.5px;
            color: var(--text-3);
        }
        .cari-amount .amount-value {
            font-size: 16px;
            font-weight: 700;
            color: var(--emerald);
            margin-top: 2px;
        }
        .cari-amount .amount-meta {
            font-size: 10.5px;
            color: var(--text-3);
            margin-top: 2px;
        }
        .cari-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 12px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s ease;
            border: 1px solid transparent;
        }
        .pill-red { background: var(--red); color: #fff; }
        .pill-red:hover { background: #b91c1c; }
        .pill-slate { background: #334155; color: #fff; }
        .pill-slate:hover { background: #1e293b; }
        .pill-emerald { background: var(--emerald); color: #fff; }
        .pill-emerald:hover { background: #047857; }

        /* ═══════ STATS GRID ═══════ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }
        .stat-card {
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px 16px;
            background: #fff;
        }
        .stat-card.tone-slate  { background: #f8fafc; }
        .stat-card.tone-emerald { background: var(--emerald-soft); border-color: #a7f3d0; }
        .stat-card.tone-sky    { background: var(--sky-soft); border-color: #bae6fd; }
        .stat-card.tone-purple { background: var(--purple-soft); border-color: #ddd6fe; }
        .stat-card .stat-label {
            font-size: 10.5px;
            color: var(--text-3);
            font-weight: 500;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }
        .stat-card.tone-emerald .stat-label { color: var(--emerald); }
        .stat-card.tone-sky .stat-label    { color: var(--sky); }
        .stat-card.tone-purple .stat-label { color: var(--purple); }
        .stat-card .stat-value {
            font-size: 17px;
            font-weight: 700;
            color: var(--text-1);
            margin-top: 4px;
        }
        .stat-card .stat-sub {
            font-size: 10.5px;
            color: var(--text-3);
            margin-top: 2px;
        }
        .stat-pos { color: var(--emerald) !important; }
        .stat-neg { color: var(--red) !important; }

        /* ═══════ ORDERS TABLE ═══════ */
        .orders-wrap {
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            background: #fff;
        }
        .orders-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }
        .orders-table thead th {
            background: #f8fafc;
            color: var(--text-2);
            font-weight: 600;
            text-align: left;
            padding: 11px 14px;
            border-bottom: 1px solid var(--border);
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .orders-table thead th.th-right { text-align: right; }
        .orders-table tbody td {
            padding: 11px 14px;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text-1);
            white-space: nowrap;
        }
        .orders-table tbody tr:last-child td { border-bottom: none; }
        .orders-table tbody tr:hover { background: #fafafa; }
        .td-right { text-align: right; }
        .td-strong { font-weight: 600; }
        .td-net { color: var(--emerald); font-weight: 600; }
        .row-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .mini-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 9px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .mini-pill.tone-emerald { background: var(--emerald-soft); color: #065f46; }
        .mini-pill.tone-emerald:hover { background: #d1fae5; }
        .mini-pill.tone-sky { background: var(--sky-soft); color: #075985; }
        .mini-pill.tone-sky:hover { background: #dbeafe; }
        .mini-pill.tone-purple { background: var(--purple-soft); color: #5b21b6; }
        .mini-pill.tone-purple:hover { background: #ede9fe; }

        .orders-scroll { overflow-x: auto; }

        /* ═══════ ANIMATIONS ═══════ */
        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        /* ═══════ MOBILE ═══════ */
        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 767px) {
            .top-header { height: 50px; }
            .header-inner { padding: 0 12px; gap: 10px; }
            .header-title { font-size: 15px; }
            .header-back { width: 30px; height: 30px; border-radius: 8px; }

            .page-main { padding: 14px 12px 40px; }

            .hero-card { padding: 16px; gap: 12px; margin-bottom: 16px; }
            .hero-card .hero-ico { width: 46px; height: 46px; font-size: 18px; }
            .hero-card .hero-text h1 { font-size: 15px; }
            .hero-card .hero-text p { font-size: 11px; }

            .glass-card { padding: 16px; border-radius: 14px; }
            .search-form { grid-template-columns: 1fr; gap: 10px; }
            .btn-primary { width: 100%; height: 42px; }

            .stats-grid { gap: 10px; }
            .stat-card { padding: 12px 14px; border-radius: 12px; }
            .stat-card .stat-value { font-size: 15px; }

            .orders-table { font-size: 11.5px; }
            .orders-table thead th,
            .orders-table tbody td { padding: 9px 10px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .cari-amount { text-align: left; }
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
                <i class="fa-solid fa-bolt"></i>Hizli Erisim
            </span>
        </div>
    </header>

    <main class="page-main">

        <!-- Hero card -->
        <div class="hero-card">
            <span class="hero-ico"><i class="fa-solid fa-bolt"></i></span>
            <div class="hero-text">
                <h1>Hizli Erisim</h1>
                <p>Cari adiyla siparisi bul, cariyi sec, tutari gor ve tek tikla Excel al.</p>
            </div>
        </div>

        <!-- Siparisi Olan Cari Ara -->
        <section class="glass-card">
            <div class="card-head">
                <span class="card-ico"><i class="fa-solid fa-magnifying-glass"></i></span>
                <div>
                    <div class="card-title">Siparisi Olan Cari Ara</div>
                    <div class="card-sub">Cari adi, kodu veya sehir ile arama yapin</div>
                </div>
            </div>

            <form method="get" class="search-form">
                <div class="search-field">
                    <label for="siparis_cari">Cari adi / kodu / sehir</label>
                    <input
                        type="text"
                        name="siparis_cari"
                        id="siparis_cari"
                        value="<?php echo e($siparisCariAramaRaw); ?>"
                        placeholder="Orn: X Insaat"
                        autocomplete="off"
                    >
                </div>
                <button type="submit" class="btn-primary">
                    <i class="fa-solid fa-magnifying-glass"></i> Ara
                </button>
            </form>

            <?php if ($aramaHatasi !== ''): ?>
                <div class="alert">
                    <?php echo e($aramaHatasi); ?>
                </div>
            <?php endif; ?>

            <?php if ($siparisCariAramaRaw !== '' && empty($siparisliCariler) && $aramaHatasi === ''): ?>
                <div class="empty-msg">Bu aramada siparisi olan cari bulunamadi.</div>
            <?php endif; ?>

            <?php if (!empty($siparisliCariler)): ?>
                <div class="cari-list">
                    <?php foreach ($siparisliCariler as $row): ?>
                        <?php $toplamTutar = (float)($row['TOPLAM_TUTAR'] ?? 0); ?>
                        <?php if ($toplamTutar <= 0): ?>
                            <?php continue; ?>
                        <?php endif; ?>
                        <?php $aktif = ((int)$row['CARIID'] === $seciliSiparisCariId); ?>
                        <div class="cari-item<?php echo $aktif ? ' is-active' : ''; ?>">
                            <div class="cari-row">
                                <div class="cari-info">
                                    <div class="cari-name">
                                        <?php echo e($row['UNVANI'] ?? ''); ?>
                                    </div>
                                    <div class="cari-meta">
                                        Kod: <?php echo e($row['KODU'] ?? ''); ?> &nbsp;|&nbsp;
                                        Sehir: <?php echo e($row['SEHIR'] ?? ''); ?> &nbsp;|&nbsp;
                                        Cari ID: <?php echo (int)($row['CARIID'] ?? 0); ?>
                                    </div>
                                </div>
                                <div class="cari-amount">
                                    <div class="amount-label">Toplam Siparis Tutari</div>
                                    <div class="amount-value"><?php echo paraYaz($toplamTutar); ?> TL</div>
                                    <div class="amount-meta">
                                        <?php echo (int)($row['SIPARIS_SAYISI'] ?? 0); ?> siparis &nbsp;|&nbsp;
                                        Son: <?php echo e(tarihcevir((string)($row['SON_SIPARIS_TARIHI'] ?? ''))); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="cari-actions">
                                <a href="hizli_erisim.php?siparis_cari=<?php echo urlencode($siparisCariAramaRaw); ?>&siparis_cariid=<?php echo (int)$row['CARIID']; ?>"
                                    class="pill pill-red">
                                    <i class="fa-solid fa-check"></i> Bu Cariyi Sec
                                </a>
                                <a href="cari/lg_hareket.php?cariid=<?php echo (int)$row['CARIID']; ?>" target="_blank" rel="noopener noreferrer"
                                    class="pill pill-slate">
                                    <i class="fa-solid fa-file-invoice"></i> Ekstre
                                </a>
                                <a href="siparis/fisekle.php?cariid=<?php echo (int)$row['CARIID']; ?>&stokhareket=0" target="_blank" rel="noopener noreferrer"
                                    class="pill pill-emerald">
                                    <i class="fa-solid fa-basket-shopping"></i> Siparis Ac
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($seciliSiparisCariId > 0): ?>
            <section class="glass-card">
                <div class="card-head tone-sky">
                    <span class="card-ico"><i class="fa-solid fa-list-check"></i></span>
                    <div>
                        <div class="card-title">Secili Carinin Siparisleri</div>
                        <div class="card-sub">
                            Cari ID: <?php echo (int)$seciliSiparisCariId; ?>
                            <?php if ($seciliCariUnvan !== ''): ?>
                                &nbsp;|&nbsp; <?php echo e($seciliCariUnvan); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($siparisHatasi !== ''): ?>
                    <div class="alert">
                        <?php echo e($siparisHatasi); ?>
                    </div>
                <?php endif; ?>

                <?php if (empty($seciliCariSiparisler) && $siparisHatasi === ''): ?>
                    <div class="empty-msg">Secili cari icin siparis bulunamadi.</div>
                <?php else: ?>
                    <?php
                    $seciliCariToplam = 0.0;
                    foreach ($seciliCariSiparisler as $s) {
                        $seciliCariToplam += (float)($s['NETTOTAL'] ?? 0);
                    }
                    $sonSiparis = $seciliCariSiparisler[0] ?? null;
                    ?>

                    <?php if ($riskDurum !== '' && $riskMesaj !== ''): ?>
                        <div class="alert <?php echo $riskDurum === 'kritik' ? '' : 'alert-warn'; ?>">
                            <div class="alert-strong">
                                <i class="fa-solid <?php echo $riskDurum === 'kritik' ? 'fa-triangle-exclamation' : 'fa-circle-exclamation'; ?>"></i>
                                Risk Uyarisi
                            </div>
                            <div>
                                <?php echo e($riskMesaj); ?>
                                <?php if ($seciliCariRiskLimit !== null && $seciliCariRiskLimit > 0 && $riskOran !== null): ?>
                                    (Bakiye: <?php echo paraYaz($seciliCariBakiye); ?> TL &nbsp;|&nbsp; Limit: <?php echo paraYaz($seciliCariRiskLimit); ?> TL &nbsp;|&nbsp; Oran: %<?php echo number_format($riskOran * 100, 1, ',', '.'); ?>)
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="stats-grid">
                        <?php if ($seciliCariBakiye !== null && abs((float)$seciliCariBakiye) > 0.0001): ?>
                            <div class="stat-card tone-slate">
                                <div class="stat-label">Guncel Bakiye</div>
                                <div class="stat-value <?php echo $seciliCariBakiye >= 0 ? 'stat-pos' : 'stat-neg'; ?>">
                                    <?php echo paraYaz(abs((float)$seciliCariBakiye)); ?> TL
                                </div>
                                <div class="stat-sub">
                                    <?php echo $seciliCariBakiye >= 0 ? 'Borclu' : 'Alacakli'; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($seciliCariToplam > 0): ?>
                            <div class="stat-card tone-emerald">
                                <div class="stat-label">Toplam Siparis Tutari</div>
                                <div class="stat-value"><?php echo paraYaz($seciliCariToplam); ?> TL</div>
                            </div>
                        <?php endif; ?>

                        <div class="stat-card tone-sky">
                            <div class="stat-label">Siparis Sayisi</div>
                            <div class="stat-value"><?php echo (int)count($seciliCariSiparisler); ?></div>
                        </div>

                        <?php if (is_array($sonSiparis) && (float)($sonSiparis['NETTOTAL'] ?? 0) > 0): ?>
                            <div class="stat-card tone-purple">
                                <div class="stat-label">Son Siparis</div>
                                <div class="stat-value"><?php echo e(tarihcevir((string)($sonSiparis['DATE_'] ?? ''))); ?></div>
                                <div class="stat-sub"><?php echo paraYaz($sonSiparis['NETTOTAL'] ?? 0); ?> TL</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="orders-wrap">
                        <div class="orders-scroll">
                            <table class="orders-table">
                                <thead>
                                    <tr>
                                        <th>Tarih</th>
                                        <th>Fis No</th>
                                        <th class="th-right">Net Tutar</th>
                                        <th class="th-right">Brut Tutar</th>
                                        <th>Hizli Islem</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($seciliCariSiparisler as $sip): ?>
                                        <?php $netTutar = (float)($sip['NETTOTAL'] ?? 0); ?>
                                        <?php $brutTutar = (float)($sip['GROSSTOTAL'] ?? 0); ?>
                                        <?php if ($netTutar <= 0 && $brutTutar <= 0): ?>
                                            <?php continue; ?>
                                        <?php endif; ?>
                                        <tr>
                                            <td><?php echo e(tarihcevir((string)$sip['DATE_'])); ?></td>
                                            <td class="td-strong"><?php echo e((string)($sip['FICHENO'] ?? '')); ?></td>
                                            <td class="td-right td-net"><?php echo $netTutar > 0 ? paraYaz($netTutar) . ' TL' : ''; ?></td>
                                            <td class="td-right"><?php echo $brutTutar > 0 ? paraYaz($brutTutar) . ' TL' : ''; ?></td>
                                            <td>
                                                <div class="row-actions">
                                                    <a href="stok/stok_hareket_excel_xlsx.php?stokhareket=<?php echo (int)$sip['STOKHAREKET']; ?>" target="_blank" rel="noopener noreferrer"
                                                        class="mini-pill tone-emerald">
                                                        <i class="fa-solid fa-file-excel"></i> XLSX
                                                    </a>
                                                    <a href="yazdir/fisyazexcel.php?stokhareket=<?php echo (int)$sip['STOKHAREKET']; ?>" target="_blank" rel="noopener noreferrer"
                                                        class="mini-pill tone-sky">
                                                        <i class="fa-solid fa-file-export"></i> XLS
                                                    </a>
                                                    <a href="siparis/lg_siparis.php?stokhareket=<?php echo (int)$sip['STOKHAREKET']; ?>" target="_blank" rel="noopener noreferrer"
                                                        class="mini-pill tone-purple">
                                                        <i class="fa-solid fa-pen-to-square"></i> Siparis
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>

    <script>
    (function() {
        const input = document.getElementById('siparis_cari');
        const key = 'hizli_erisim_siparis_cari';

        try {
            const saved = localStorage.getItem(key);
            if (input && !input.value && saved) {
                input.value = saved;
            }
        } catch (e) {
            // localStorage kapaliysa sessizce devam et
        }

        if (input) {
            input.addEventListener('input', function() {
                try {
                    localStorage.setItem(key, input.value || '');
                } catch (e) {
                    // localStorage kapaliysa sessizce devam et
                }
            }, { passive: true });
        }
    })();
    </script>
</body>
</html>
