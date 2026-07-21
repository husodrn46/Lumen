<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
include(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

// YETKI KONTROLÜ: M5 (Tüm Siparişler) yetkisi kontrolü
if (m_p_yetki($terminalkullanici, 'M5') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// Para formatlama fonksiyonu
function paraformat(float|int|string|null $kusurat): string
{
    global $parakusurat;
    if ($kusurat === "" || is_null($kusurat)) {
        $kusurat = 0;
    }
    return number_format(
        (float)$kusurat,
        $parakusurat ?? 2,
        ',',
        '.'
    );
}

// --- Filtre Parametreleri ---
// --- Filtre Parametreleri (GENİŞLETİLMİŞ) ---
$date_range    = $_GET['date_range'] ?? 'this_month';
$start_date_in = $_GET['start_date'] ?? '';
$end_date_in   = $_GET['end_date'] ?? '';
$unvan         = $_GET['unvan'] ?? '';
$code          = $_GET['code'] ?? '';
$city          = $_GET['city'] ?? '';
$ficheno       = $_GET['ficheno'] ?? '';
$min_total     = (isset($_GET['min_total']) && $_GET['min_total'] !== '' ? (float)$_GET['min_total'] : null);
$max_total     = (isset($_GET['max_total']) && $_GET['max_total'] !== '' ? (float)$_GET['max_total'] : null);
$status        = $_GET['status'] ?? 'all'; // all|pending|sent
$with_notes    = isset($_GET['with_notes'])    ? (int)$_GET['with_notes'] : 0;
$showClient14  = $_GET['show_client14'] ?? 0;
$sort_by       = $_GET['sort_by'] ?? 'date_desc'; // whitelist

// Tarih aralığını belirle
switch ($date_range) {
    case 'this_week':
        $startDate = date('Y-m-d', strtotime('monday this week'));
        $endDate   = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'this_year':
        $startDate = date('Y-01-01');
        $endDate   = date('Y-12-31');
        break;
    case 'last_month':
        $startDate = date('Y-m-01', strtotime('first day of last month'));
        $endDate   = date('Y-m-t', strtotime('last day of last month'));
        break;
    case 'custom':
        // Kullanıcı özel tarih girdiyse onu kullan
        $startDate = $start_date_in ?: date('Y-m-01');
        $endDate   = $end_date_in   ?: date('Y-m-t');
        break;
    case 'this_month':
    default:
        $startDate = date('Y-m-01');
        $endDate   = date('Y-m-t');
        break;
}

// --- GÜVENLİ SQL SORGUSU ---
$params = [':startDate' => $startDate, ':endDate' => $endDate];
$whereClauses = [];
$whereClauses[] = "FS.DATE_ BETWEEN :startDate AND :endDate";
$whereClauses[] = "FS.TRCODE = 1";
$whereClauses[] = "FS.NETTOTAL > 0";
$ozelCariTumSiparisFiltresi = m_p_ozel_cari_sql_filtresi($terminalkullanici, 'FS.CLIENTREF', 'tum_siparis_gizli');
if ($ozelCariTumSiparisFiltresi['sql'] !== '') {
    $whereClauses[] = $ozelCariTumSiparisFiltresi['sql'];
    $params = array_merge($params, $ozelCariTumSiparisFiltresi['params']);
}

// Türkçe karakter duyarsız arama için SQL REPLACE fonksiyonu
$sqlNormalize = function($column) {
    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        {$column}, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')";
};

// Unvan (Türkçe karakter duyarsız)
if ($unvan !== '') {
    $whereClauses[] = $sqlNormalize("CR.DEFINITION_") . " COLLATE Turkish_CI_AS LIKE :unvan";
    $params[':unvan'] = '%' . turkce($unvan) . '%';
} elseif (!$showClient14) {
    $whereClauses[] = "FS.CLIENTREF <> 14";
}

// Cari kodu (Türkçe karakter duyarsız)
if ($code !== '') {
    $whereClauses[] = $sqlNormalize("CR.CODE") . " COLLATE Turkish_CI_AS LIKE :code";
    $params[':code'] = '%' . turkce($code) . '%';
}

// Şehir (Türkçe karakter duyarsız)
if ($city !== '') {
    $whereClauses[] = $sqlNormalize("CR.CITY") . " COLLATE Turkish_CI_AS LIKE :city";
    $params[':city'] = '%' . turkce($city) . '%';
}

// Fiş No
if ($ficheno !== '') {
    $whereClauses[] = "FS.FICHENO LIKE :ficheno";
    $params[':ficheno'] = '%' . $ficheno . '%';
}

// Tutar aralığı
if ($min_total !== null) {
    $whereClauses[] = "FS.NETTOTAL >= :min_total";
    $params[':min_total'] = $min_total;
}
if ($max_total !== null) {
    $whereClauses[] = "FS.NETTOTAL <= :max_total";
    $params[':max_total'] = $max_total;
}

// Notu olanlar
if ($with_notes === 1) {
    $whereClauses[] = "(FS.GENEXP1 IS NOT NULL AND FS.GENEXP1 <> '')";
}

// Durum filtresi (Hazırlanıyor/Gönderildi)
if ($status === 'sent') {
    $whereClauses[] = "EXISTS (SELECT 1 FROM {$firmadonem}STLINE ST WHERE ST.ORDFICHEREF = FS.LOGICALREF AND ST.ORDFICHEREF > 0)";
} elseif ($status === 'pending') {
    $whereClauses[] = "NOT EXISTS (SELECT 1 FROM {$firmadonem}STLINE ST WHERE ST.ORDFICHEREF = FS.LOGICALREF AND ST.ORDFICHEREF > 0)";
}

$whereSql = implode(" AND ", $whereClauses);

// Sıralama (whitelist)
$sortMap = [
    'date_desc'    => "FS.DATE_ DESC, FS.FICHENO DESC",
    'date_asc'     => "FS.DATE_ ASC, FS.FICHENO ASC",
    'total_desc'   => "FS.NETTOTAL DESC",
    'total_asc'    => "FS.NETTOTAL ASC",
    'ficheno_desc' => "FS.FICHENO DESC",
    'ficheno_asc'  => "FS.FICHENO ASC",
    'name_asc'     => "CR.DEFINITION_ ASC",
    'name_desc'    => "CR.DEFINITION_ DESC",
];
$orderSql = $sortMap[$sort_by] ?? $sortMap['date_desc'];

// Sayfalama parametreleri
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50; // Sayfa başına kayıt
$offset = ($page - 1) * $perPage;

// Toplam kayıt sayısını al (sayfalama için)
$countQuery = "
SELECT COUNT(*) AS TOPLAM
FROM {$firmadonem}ORFICHE FS
LEFT JOIN {$firma}CLCARD CR ON FS.CLIENTREF = CR.LOGICALREF
WHERE {$whereSql}";
$countStmt = $dbh->prepare($countQuery);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

// Optimize edilmiş sorgu - yazdırma sayısı ve durum tek sorguda
$query = "
SELECT
    FS.FICHENO, FS.DATE_, FS.NETTOTAL, FS.LOGICALREF, FS.GENEXP1,
    CR.DEFINITION_, CR.DEFINITION2, CR.CITY, FS.CLIENTREF,
    CASE WHEN ST_CHECK.ORDFICHEREF IS NOT NULL THEN 1 ELSE 0 END AS SIPARIS_DURUMU,
    ISNULL(LOG_COUNT.YAZDIRMA_SAYISI, 0) AS YAZDIRMA_SAYISI
FROM {$firmadonem}ORFICHE FS
LEFT JOIN {$firma}CLCARD CR ON FS.CLIENTREF = CR.LOGICALREF
LEFT JOIN (
    SELECT DISTINCT ORDFICHEREF
    FROM {$firmadonem}STLINE
    WHERE ORDFICHEREF > 0
) ST_CHECK ON ST_CHECK.ORDFICHEREF = FS.LOGICALREF
LEFT JOIN (
    SELECT FIS, COUNT(*) AS YAZDIRMA_SAYISI
    FROM M_MOBIL_DIZAYN_LOG
    GROUP BY FIS
) LOG_COUNT ON LOG_COUNT.FIS = FS.LOGICALREF
WHERE {$whereSql}
ORDER BY {$orderSql}
OFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY";

$stmt = $dbh->prepare($query);
$stmt->execute($params);
$resultRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tüm Siparişler</title>
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
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

        /* Sticky header */
        .top-header {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(248, 113, 113, 0.18);
            box-shadow: 0 2px 8px rgba(111, 16, 34, 0.04);
        }
        .header-inner {
            max-width: 1200px; margin: 0 auto;
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
        .header-title i { color: var(--red); font-size: 14px; }
        .header-title .badge-count {
            margin-left: 6px;
            padding: 2px 8px;
            background: var(--red-soft);
            color: var(--red);
            border: 1px solid rgba(239, 68, 68, 0.22);
            border-radius: 100px;
            font-size: 10px;
            font-weight: 700;
        }

        main {
            max-width: 1200px; margin: 0 auto;
            padding: 20px 24px 48px;
        }

        /* Glass card base */
        .glass-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        /* Filter panel */
        .filter-panel {
            padding: 16px;
            margin-bottom: 16px;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px;
        }
        .field { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
        .field label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .field input[type="text"],
        .field input[type="number"],
        .field input[type="date"],
        .field select {
            width: 100%;
            padding: 10px 12px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13px;
            color: var(--text-1);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            outline: none;
            transition: all 0.18s ease;
        }
        .field input:focus,
        .field select:focus {
            border-color: rgba(239, 68, 68, 0.5);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }
        .field input:disabled {
            background: #f3f4f6;
            color: var(--text-3);
            cursor: not-allowed;
        }
        .field-check {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 12px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-2);
            cursor: pointer;
            user-select: none;
            height: 100%;
        }
        .field-check input[type="checkbox"] {
            width: 15px; height: 15px;
            accent-color: var(--red); cursor: pointer; margin: 0;
        }
        .field-check:hover { border-color: rgba(239, 68, 68, 0.35); color: var(--text-1); }

        .col-span-2 { grid-column: span 2; }
        .col-span-full { grid-column: 1 / -1; }

        .filter-actions {
            margin-top: 14px;
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        }
        .btn-primary {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 11px 22px;
            background: linear-gradient(180deg, var(--red,#ef4444) 0%, var(--red,#6F1022) 100%);
            color: #fff;
            border: 1px solid var(--red,#6F1022);
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.18s ease;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.18);
            text-decoration: none;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(239, 68, 68, 0.28);
            filter: brightness(1.03);
        }
        .btn-ghost {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 10px 16px;
            background: #fff; color: var(--text-2);
            border: 1px solid var(--border); border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12.5px; font-weight: 600;
            cursor: pointer; transition: all 0.2s ease;
            text-decoration: none;
            white-space: nowrap;
        }
        .btn-ghost:hover { background: #fafafa; border-color: var(--text-3); color: var(--text-1); }

        .live-search-wrap {
            margin-top: 12px;
            position: relative;
        }
        .live-search-wrap i.fa-search {
            position: absolute; top: 50%; left: 14px;
            transform: translateY(-50%);
            color: var(--text-3); font-size: 13px;
            pointer-events: none;
        }
        .live-search-input {
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
        .live-search-input:focus {
            border-color: rgba(239, 68, 68, 0.5);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }

        /* Table card */
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
        .gd-table { width: 100%; border-collapse: collapse; }
        .gd-table thead { background: linear-gradient(180deg, #fff, #fffafa); }
        .gd-table thead th {
            padding: 12px 14px;
            font-size: 10px; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: 0.5px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .gd-table thead th.right { text-align: right; }
        .gd-table thead th.center { text-align: center; }
        .gd-table tbody td {
            padding: 12px 14px;
            font-size: 12.5px; color: var(--text-1);
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .gd-table tbody tr { transition: background 0.15s ease; }
        .gd-table tbody tr:hover { background: var(--red-soft); }
        .gd-table tbody tr:last-child td { border-bottom: none; }

        .gd-table td.date { white-space: nowrap; color: var(--text-2); font-size: 12px; }
        .gd-table td.ficheno {
            font-family: 'JetBrains Mono', monospace;
            font-size: 11.5px; color: var(--red); font-weight: 600;
            white-space: nowrap;
        }
        .gd-table td.firma {
            max-width: 320px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
            font-weight: 500;
        }
        .gd-table td.firma .city { color: var(--text-3); font-size: 11px; font-weight: 400; margin-left: 6px; }
        .gd-table td.note {
            max-width: 200px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
            color: var(--sky); font-size: 11.5px;
        }
        .gd-table td.tutar {
            text-align: right; font-weight: 700; color: var(--text-1); white-space: nowrap;
        }
        .gd-table td.actions {
            white-space: nowrap; text-align: right;
        }

        .status-pill {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px;
            border-radius: 100px;
            font-size: 10.5px; font-weight: 700;
            white-space: nowrap;
        }
        .status-pill.sent {
            background: var(--emerald-soft); color: var(--emerald);
            border: 1px solid rgba(5,150,105,0.22);
        }
        .status-pill.pending {
            background: var(--amber-soft); color: var(--amber);
            border: 1px solid rgba(217,119,6,0.22);
        }

        .print-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 9px;
            border-radius: 8px;
            font-size: 10.5px; font-weight: 700;
            white-space: nowrap;
            transition: all 0.15s ease;
            border: 1px solid transparent;
        }
        .print-badge.zero {
            background: #f3f4f6; color: var(--text-3); border-color: var(--border);
        }
        .print-badge.has {
            background: var(--sky-soft); color: var(--sky); border-color: rgba(2,132,199,0.22);
        }
        .print-badge.has.clickable { cursor: pointer; }
        .print-badge.has.clickable:hover { background: #dbeafe; }

        .row-btn {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 7px 11px;
            border-radius: 8px;
            font-size: 11px; font-weight: 600;
            text-decoration: none;
            transition: all 0.15s ease;
            border: 1px solid;
        }
        .row-btn.detail {
            background: var(--indigo-soft); color: var(--indigo);
            border-color: rgba(79,70,229,0.22);
        }
        .row-btn.detail:hover { background: var(--indigo); color: #fff; border-color: var(--indigo); }
        .row-btn.history {
            background: var(--purple-soft); color: var(--purple);
            border-color: rgba(124,58,237,0.22);
        }
        .row-btn.history:hover { background: var(--purple); color: #fff; border-color: var(--purple); }
        .row-btn i { font-size: 10px; }

        .empty-row td {
            text-align: center;
            padding: 48px 20px !important;
            color: var(--text-3); font-size: 13px;
        }
        .empty-row i { display: block; font-size: 36px; margin-bottom: 12px; color: var(--text-3); }
        .empty-row .big { font-size: 15px; font-weight: 600; color: var(--text-2); margin-bottom: 4px; }

        /* Pagination */
        .pager {
            margin-top: 16px;
            padding: 14px 18px;
            display: flex; align-items: center; justify-content: space-between;
            gap: 14px; flex-wrap: wrap;
        }
        .pager .info { font-size: 12px; color: var(--text-2); }
        .pager .info strong { color: var(--text-1); font-weight: 700; }
        .pager .pages { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .page-link {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 36px; height: 36px; padding: 0 10px;
            background: #fff; color: var(--text-2);
            border: 1px solid var(--border); border-radius: 9px;
            font-size: 12px; font-weight: 600;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .page-link:hover { background: #fafafa; color: var(--text-1); border-color: var(--text-3); }
        .page-link.active {
            background: linear-gradient(180deg, var(--red,#ef4444) 0%, var(--red,#6F1022) 100%);
            color: #fff;
            border-color: var(--red);
            box-shadow: 0 3px 10px rgba(239,68,68,0.22);
        }

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(15,23,42,0.5);
            z-index: 60;
            align-items: center; justify-content: center;
            padding: 16px;
            animation: fadeIn 0.2s ease;
        }
        .modal-overlay.show { display: flex; }
        .modal-box {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 24px 48px rgba(0,0,0,0.25);
            max-width: 640px; width: 100%;
            max-height: 90vh;
            overflow: hidden;
            display: flex; flex-direction: column;
            animation: cardIn 0.3s cubic-bezier(0.22, 1, 0.36, 1);
        }
        .modal-head {
            padding: 16px 20px;
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: #fff;
            display: flex; align-items: center; justify-content: space-between;
        }
        .modal-head h3 {
            font-size: 15px; font-weight: 700;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .modal-close {
            background: transparent; border: none;
            color: rgba(255,255,255,0.85);
            font-size: 24px;
            cursor: pointer; line-height: 1;
            padding: 0 4px;
            transition: color 0.15s ease;
        }
        .modal-close:hover { color: #fff; }
        .modal-body { padding: 18px 20px; overflow-y: auto; flex: 1; }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Responsive */
        @media (max-width: 1100px) {
            .filter-grid { grid-template-columns: repeat(4, 1fr); }
        }
        @media (max-width: 820px) {
            .filter-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 767px) {
            .header-inner { padding: 12px 14px; gap: 10px; }
            .header-title { font-size: 13.5px; }
            .header-back { width: 30px; height: 30px; border-radius: 8px; }
            main { padding: 14px 12px 40px; }

            .filter-panel { padding: 12px; }
            .filter-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .col-span-2 { grid-column: span 2; }
            .field input, .field select, .live-search-input { font-size: 16px; }

            .gd-table thead { display: none; }
            .gd-table, .gd-table tbody, .gd-table tr, .gd-table td { display: block; width: 100%; }
            .gd-table tbody tr {
                padding: 14px 14px;
                border-bottom: 1px solid #f3f4f6;
                display: grid;
                grid-template-columns: 1fr auto;
                gap: 6px 10px;
                align-items: start;
            }
            .gd-table tbody td { padding: 0; border-bottom: none; }
            .gd-table td.firma { grid-column: 1; max-width: none; font-size: 13.5px; font-weight: 600; }
            .gd-table td.tutar { grid-column: 2; grid-row: 1; font-size: 14px; color: var(--red); }
            .gd-table td.date { grid-column: 1; font-size: 11px; }
            .gd-table td.ficheno { grid-column: 2; font-size: 10.5px; text-align: right; }
            .gd-table td.status { grid-column: 1; }
            .gd-table td.print { grid-column: 2; text-align: right; }
            .gd-table td.note { grid-column: 1 / -1; max-width: none; font-size: 11px; }
            .gd-table td.actions {
                grid-column: 1 / -1;
                display: flex; gap: 8px; padding-top: 4px;
                text-align: left;
            }
            .row-btn { flex: 1; justify-content: center; padding: 9px 10px; font-size: 12px; }

            .pager { padding: 12px; flex-direction: column; align-items: stretch; }
            .pager .info { text-align: center; }
            .pager .pages { justify-content: center; }
        }
    </style>
</head>

<body>

    <header class="top-header">
        <div class="header-inner">
            <a href="../index.php" class="header-back" title="Ana Sayfa"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="header-divider"></div>
            <div class="header-title">
                <i class="fa-solid fa-rectangle-list"></i>
                Tüm Siparişler
                <?php if ($totalRows > 0): ?>
                    <span class="badge-count"><?php echo number_format($totalRows, 0, ',', '.'); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <?php $sipAktifSekme = 'tum'; include __DIR__ . '/siparis_sekmeler.php'; ?>

    <main>

        <div class="glass-card filter-panel">
            <form method="GET" id="filterForm">
                <div class="filter-grid">
                    <div class="field">
                        <label for="date_range">Tarih Aralığı</label>
                        <select id="date_range" name="date_range">
                            <option value="this_month" <?= $date_range == 'this_month' ? 'selected' : ''; ?>>Bu Ay</option>
                            <option value="this_week" <?= $date_range == 'this_week' ? 'selected' : ''; ?>>Bu Hafta</option>
                            <option value="last_month" <?= $date_range == 'last_month' ? 'selected' : ''; ?>>Geçen Ay</option>
                            <option value="this_year" <?= $date_range == 'this_year' ? 'selected' : ''; ?>>Bu Yıl</option>
                            <option value="custom" <?= $date_range == 'custom' ? 'selected' : ''; ?>>Özel</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="start_date">Başlangıç</label>
                        <input type="date" id="start_date" name="start_date"
                            value="<?= htmlspecialchars((string) $startDate); ?>">
                    </div>

                    <div class="field">
                        <label for="end_date">Bitiş</label>
                        <input type="date" id="end_date" name="end_date"
                            value="<?= htmlspecialchars((string) $endDate); ?>">
                    </div>

                    <div class="field col-span-2">
                        <label for="unvan">Firma Unvanı</label>
                        <input type="text" id="unvan" name="unvan" value="<?= htmlspecialchars((string) $unvan); ?>"
                            placeholder="Unvan ara...">
                    </div>

                    <div class="field">
                        <label for="code">Cari Kodu</label>
                        <input type="text" id="code" name="code" value="<?= htmlspecialchars((string) $code); ?>"
                            placeholder="Örn: URN-001">
                    </div>

                    <div class="field">
                        <label for="city">Şehir</label>
                        <input type="text" id="city" name="city" value="<?= htmlspecialchars((string) $city); ?>"
                            placeholder="Örn: İstanbul">
                    </div>

                    <div class="field">
                        <label for="ficheno">Fiş No</label>
                        <input type="text" id="ficheno" name="ficheno" value="<?= htmlspecialchars((string) $ficheno); ?>"
                            placeholder="Örn: ORF-000123">
                    </div>

                    <div class="field">
                        <label for="min_total">Min Tutar</label>
                        <input type="number" step="0.01" id="min_total" name="min_total"
                            value="<?= $min_total !== null ? htmlspecialchars((string) $min_total) : ''; ?>"
                            placeholder="0.00">
                    </div>

                    <div class="field">
                        <label for="max_total">Max Tutar</label>
                        <input type="number" step="0.01" id="max_total" name="max_total"
                            value="<?= $max_total !== null ? htmlspecialchars((string) $max_total) : ''; ?>"
                            placeholder="999999.99">
                    </div>

                    <div class="field">
                        <label for="status">Durum</label>
                        <select id="status" name="status">
                            <option value="all" <?= $status == 'all' ? 'selected' : ''; ?>>Hepsi</option>
                            <option value="pending" <?= $status == 'pending' ? 'selected' : ''; ?>>Hazırlanıyor</option>
                            <option value="sent" <?= $status == 'sent' ? 'selected' : ''; ?>>Gönderildi</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="sort_by">Sıralama</label>
                        <select id="sort_by" name="sort_by">
                            <option value="date_desc" <?= $sort_by == 'date_desc' ? 'selected' : ''; ?>>Tarih &darr;</option>
                            <option value="date_asc" <?= $sort_by == 'date_asc' ? 'selected' : ''; ?>>Tarih &uarr;</option>
                            <option value="total_desc" <?= $sort_by == 'total_desc' ? 'selected' : ''; ?>>Tutar &darr;</option>
                            <option value="total_asc" <?= $sort_by == 'total_asc' ? 'selected' : ''; ?>>Tutar &uarr;</option>
                            <option value="ficheno_desc" <?= $sort_by == 'ficheno_desc' ? 'selected' : ''; ?>>Fiş No &darr;</option>
                            <option value="ficheno_asc" <?= $sort_by == 'ficheno_asc' ? 'selected' : ''; ?>>Fiş No &uarr;</option>
                            <option value="name_asc" <?= $sort_by == 'name_asc' ? 'selected' : ''; ?>>Unvan A&rarr;Z</option>
                            <option value="name_desc" <?= $sort_by == 'name_desc' ? 'selected' : ''; ?>>Unvan Z&rarr;A</option>
                        </select>
                    </div>

                    <label class="field-check">
                        <input type="checkbox" id="with_notes" name="with_notes" value="1" <?= $with_notes == 1 ? 'checked' : ''; ?>>
                        <span>Yalnızca notu olanlar</span>
                    </label>

                    <label class="field-check">
                        <input type="checkbox" id="show_client14" name="show_client14" value="1" <?= $showClient14 == 1 ? 'checked' : ''; ?>>
                        <span>Mağaza Satışları</span>
                    </label>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-primary">
                        <i class="fa-solid fa-filter"></i> Filtrele
                    </button>
                    <a href="lg_tumsiparisler.php" class="btn-ghost">
                        <i class="fa-solid fa-rotate-left"></i> Sıfırla
                    </a>
                </div>
            </form>

            <div class="live-search-wrap">
                <i class="fa-solid fa-search"></i>
                <input type="text" id="live_search" class="live-search-input"
                    placeholder="Görünen listede hızlı ara (unvan, fiş no, şehir...)">
            </div>
        </div>

        <div class="table-card">
            <table id="tbl" class="gd-table">
                <thead>
                    <tr>
                        <th>Tarih</th>
                        <th>Fiş No</th>
                        <th>Firma</th>
                        <th>Durum</th>
                        <th class="center">Yazdırma</th>
                        <th>Not</th>
                        <th class="right">Tutar</th>
                        <th class="right">İşlem</th>
                    </tr>
                </thead>
                <tbody id="siparis-listesi">
                    <?php if (empty($resultRows)): ?>
                        <tr class="empty-row">
                            <td colspan="8">
                                <i class="fa-solid fa-box-open"></i>
                                <div class="big">Sipariş Bulunamadı</div>
                                <div>Belirttiğiniz kriterlere uygun bir sipariş bulunamadı.</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($resultRows as $ila): ?>
                            <?php
                            $isSent = ($ila['SIPARIS_DURUMU'] == 1);
                            $statusText = $isSent ? 'Gönderildi' : 'Hazırlanıyor';
                            $statusCls  = $isSent ? 'sent' : 'pending';
                            $statusIcon = $isSent ? 'fa-check-circle' : 'fa-clock';

                            // Yazdırma sayısı artık ana sorgudan geliyor (N+1 query optimize edildi)
                            $stokhareket = $ila['LOGICALREF'];
                            $yazdirmaSayisi = (int)($ila['YAZDIRMA_SAYISI'] ?? 0);
                            $canSeePrintDetail = ($yazdirmaSayisi > 0 && m_p_yetki($terminalkullanici, 'M22') == 1);
                            ?>
                            <tr class="siparis-card">
                                <td class="date">
                                    <i class="fa-regular fa-calendar"></i>
                                    <?php echo htmlspecialchars(date("d.m.Y", strtotime((string) $ila['DATE_']))); ?>
                                </td>
                                <td class="ficheno"><?php echo htmlspecialchars((string) $ila['FICHENO']); ?></td>
                                <td class="firma" title="<?php echo htmlspecialchars(tr($ila['DEFINITION_'])); ?>">
                                    <?php echo htmlspecialchars(tr($ila['DEFINITION_'])); ?>
                                    <?php if (!empty($ila['CITY'])): ?>
                                        <span class="city"><?php echo htmlspecialchars(tr($ila['CITY'])); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="status">
                                    <span class="status-pill <?php echo $statusCls; ?>">
                                        <i class="fa-solid <?php echo $statusIcon; ?>"></i>
                                        <?php echo $statusText; ?>
                                    </span>
                                </td>
                                <td class="print">
                                    <?php if ($yazdirmaSayisi > 0): ?>
                                        <span class="print-badge has <?php echo $canSeePrintDetail ? 'clickable' : ''; ?>"
                                              <?php if ($canSeePrintDetail): ?>
                                              onclick="yazdirmaDetayGoster(<?php echo $stokhareket; ?>)"
                                              title="Yazdırma detayını gör"
                                              <?php endif; ?>>
                                            <i class="fa-solid fa-print"></i>
                                            <?php echo $yazdirmaSayisi; ?>x
                                        </span>
                                    <?php else: ?>
                                        <span class="print-badge zero">
                                            <i class="fa-solid fa-print"></i> 0
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="note">
                                    <?php if (!empty($ila['GENEXP1'])): ?>
                                        <i class="fa-solid fa-note-sticky"></i>
                                        <span title="<?php echo htmlspecialchars((string) $ila['GENEXP1']); ?>">
                                            <?php echo htmlspecialchars((string) $ila['GENEXP1']); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="tutar"><?php echo paraformat($ila['NETTOTAL']); ?> &#8378;</td>
                                <td class="actions">
                                    <a href="lg_fis.php?stokhareket=<?php echo intval($ila['LOGICALREF']); ?>"
                                        class="row-btn detail" title="Siparişi Görüntüle/Düzenle">
                                        <i class="fa fa-list-alt"></i> Detay
                                    </a>
                                    <?php if (m_p_yetki($terminalkullanici, 'M18') == 1): ?>
                                        <a href="../yazdir/fis_gecmis.php?fis=<?php echo intval($ila['LOGICALREF']); ?>"
                                            class="row-btn history" title="Değişiklik Geçmişi">
                                            <i class="fa fa-history"></i> Geçmiş
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Sayfalama -->
        <?php if ($totalPages > 1): ?>
        <div class="glass-card pager">
            <div class="info">
                Toplam <strong><?php echo number_format($totalRows, 0, ',', '.'); ?></strong> sipariş,
                Sayfa <strong><?php echo $page; ?></strong> / <strong><?php echo $totalPages; ?></strong>
            </div>
            <div class="pages">
                <?php
                // Mevcut GET parametrelerini koru
                $queryParams = $_GET;
                unset($queryParams['page']);
                $baseUrl = '?' . http_build_query($queryParams) . (empty($queryParams) ? '' : '&');
                ?>

                <?php if ($page > 1): ?>
                <a href="<?php echo $baseUrl; ?>page=1" class="page-link" title="İlk Sayfa">
                    <i class="fa-solid fa-angles-left"></i>
                </a>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>" class="page-link" title="Önceki">
                    <i class="fa-solid fa-angle-left"></i>
                </a>
                <?php endif; ?>

                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                for ($i = $startPage; $i <= $endPage; $i++):
                ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $i; ?>"
                   class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>" class="page-link" title="Sonraki">
                    <i class="fa-solid fa-angle-right"></i>
                </a>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $totalPages; ?>" class="page-link" title="Son Sayfa">
                    <i class="fa-solid fa-angles-right"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <!-- Yazdırma Detay Modalı -->
    <div id="yazdirmaModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-head">
                <h3><i class="fa-solid fa-print"></i> Yazdırma Geçmişi</h3>
                <button type="button" class="modal-close" onclick="modalKapat()">&times;</button>
            </div>
            <div id="modalIcerik" class="modal-body">
                <div style="display:flex;align-items:center;justify-content:center;padding:32px;color:#6b7280;">
                    <i class="fa-solid fa-spinner fa-spin fa-2x" style="color:#0284c7;"></i>
                    <span style="margin-left:12px;">Yükleniyor...</span>
                </div>
            </div>
        </div>
    </div>

    <script src="/tm/js/jquery-3.7.1.min.js"></script>
    <script>
        // Türkçe karakterleri ASCII'ye çevir (ğ→g, ü→u, ş→s, ı→i, ö→o, ç→c)
        function turkceNormalize(str) {
            if (!str) return '';
            return str
                .replace(/[ğĞ]/g, 'g')
                .replace(/[üÜ]/g, 'u')
                .replace(/[şŞ]/g, 's')
                .replace(/[ıİ]/g, 'i')
                .replace(/[öÖ]/g, 'o')
                .replace(/[çÇ]/g, 'c')
                .toLocaleLowerCase('tr-TR');
        }

        // Türkçe karakter duyarlı ":contains" seçicisi
        jQuery.expr[':'].contains = function(a, i, m) {
            return turkceNormalize(jQuery(a).text()).indexOf(turkceNormalize(m[3])) >= 0;
        };

        $(document).ready(function() {
            // Canlı arama input'u (Türkçe karakter duyarsız)
            $("#live_search").on("keyup", function() {
                var value = turkceNormalize($(this).val());
                // Satırları filtrele
                $("#siparis-listesi tr.siparis-card").filter(function() {
                    var rowText = turkceNormalize($(this).text());
                    $(this).toggle(rowText.indexOf(value) > -1);
                });
            });
        });
        (function() {
            const dr = document.getElementById('date_range');
            const s = document.getElementById('start_date');
            const e = document.getElementById('end_date');

            function toggleCustomDates() {
                const isCustom = dr.value === 'custom';
                s.disabled = e.disabled = !isCustom;
            }

            dr.addEventListener('change', toggleCustomDates);
            toggleCustomDates(); // ilk yüklemede
        })();

        // Yazdırma detayını göster
        function yazdirmaDetayGoster(fisRef) {
            const modal = document.getElementById('yazdirmaModal');
            const icerik = document.getElementById('modalIcerik');
            modal.classList.add('show');
            icerik.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:32px;color:#6b7280;"><i class="fa-solid fa-spinner fa-spin fa-2x" style="color:#0284c7;"></i><span style="margin-left:12px;">Yükleniyor...</span></div>';

            fetch('../yazdir/yazdirma_detay.php?fis=' + fisRef)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data.length > 0) {
                        let html = '<div style="display:flex;flex-direction:column;gap:10px;">';
                        data.data.forEach((item, index) => {
                            html += '<div style="border:1px solid #e5e7eb;border-radius:10px;padding:14px;background:#fff;">';
                            html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">';
                            html += '<div style="display:flex;align-items:center;gap:10px;">';
                            html += '<span style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;background:#eff6ff;color:#0284c7;font-weight:700;font-size:12px;">' + (index + 1) + '</span>';
                            html += '<div>';
                            html += '<div style="font-weight:600;color:#1f2937;font-size:13px;">' + item.kullanici + '</div>';
                            html += '<div style="font-size:11px;color:#9ca3af;">Kod: ' + item.kullanici_kodu + '</div>';
                            html += '</div></div>';
                            html += '<div style="font-size:12px;color:#6b7280;font-weight:500;"><i class="fa-regular fa-calendar" style="margin-right:4px;"></i>' + item.tarih + '</div>';
                            html += '</div>';
                            html += '<div style="margin-top:8px;padding-top:8px;border-top:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;font-size:12px;">';
                            html += '<span style="color:#6b7280;"><i class="fa-solid fa-file" style="margin-right:4px;"></i>' + item.dizayn + '</span>';
                            html += '<span style="padding:3px 8px;background:#f3f4f6;border-radius:6px;color:#374151;font-weight:600;"><i class="fa-solid fa-copy" style="margin-right:4px;"></i>' + item.miktar + ' kopya</span>';
                            html += '</div></div>';
                        });
                        html += '</div>';
                        html += '<div style="margin-top:16px;padding:12px;background:#eff6ff;border:1px solid rgba(2,132,199,0.2);border-radius:10px;text-align:center;color:#0284c7;font-weight:600;font-size:12.5px;">';
                        html += '<i class="fa-solid fa-info-circle" style="margin-right:6px;"></i>Toplam ' + data.toplam + ' yazdırma kaydı bulundu';
                        html += '</div>';
                        icerik.innerHTML = html;
                    } else {
                        icerik.innerHTML = '<div style="text-align:center;padding:40px 20px;color:#9ca3af;"><i class="fa-solid fa-inbox fa-3x" style="margin-bottom:12px;color:#d1d5db;"></i><div style="color:#6b7280;font-size:13px;">Henüz yazdırma kaydı yok</div></div>';
                    }
                })
                .catch(error => {
                    icerik.innerHTML = '<div style="text-align:center;padding:40px 20px;color:var(--red,#6F1022);"><i class="fa-solid fa-exclamation-triangle fa-2x" style="margin-bottom:12px;"></i><div>Bir hata oluştu: ' + error.message + '</div></div>';
                });
        }

        // Modal kapat
        function modalKapat() {
            document.getElementById('yazdirmaModal').classList.remove('show');
        }

        // ESC tuşu ile kapat
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                modalKapat();
            }
        });

        // Modal dışına tıklanınca kapat
        document.getElementById('yazdirmaModal').addEventListener('click', function(e) {
            if (e.target === this) {
                modalKapat();
            }
        });
    </script>
</body>

</html>
