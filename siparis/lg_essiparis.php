<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
include(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

// YETKI KONTROLÜ: M2 (Siparişler) yetkisi kontrolü
if (m_p_yetki($terminalkullanici, 'M2') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

function paraformat(float|int|string|null $kusurat): string
{
    global $parakusurat;

    if ($kusurat === "" || is_null($kusurat)) {
        $kusurat = 0;
    }
    // String'i float'a çevir (number_format float/int bekliyor)
    $kusurat = (float) $kusurat;
    $ondalik = isset($parakusurat) ? (int) $parakusurat : 2;
    return number_format($kusurat, $ondalik, ',', '.') . '₺';
}

function parseFilterDecimal(?string $value): ?float
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $value = str_replace(' ', '', $value);
    if (str_contains($value, ',') && str_contains($value, '.')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } else {
        $value = str_replace(',', '.', $value);
    }

    if (!is_numeric($value)) {
        return null;
    }

    return (float)$value;
}

function isIsoDate(string $value): bool
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
}

$lgEssiparisReturnUrl = 'lg_essiparis.php';
if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '') {
    $lgEssiparisReturnUrl .= '?' . $_SERVER['QUERY_STRING'];
}

// -- 1) Sıralama ve sayfalama parametreleri
$sortField = $_GET['sort'] ?? '';      // price, date, ficheno
$sortOrder = $_GET['order'] ?? 'desc'; // asc, desc
if (!in_array($sortOrder, ['asc', 'desc'], true)) {
    $sortOrder = 'desc';
}

$pageSize = 50; // Sayfa başına kayıt
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($currentPage - 1) * $pageSize;

// -- 1b) Salesman filtresi (LOGICALREF)
$salesmanRef = isset($_GET['salesman']) ? (int)$_GET['salesman'] : 0;

// -- 1c) Gelişmiş filtreler
$searchQuery = trim((string)($_GET['q'] ?? ''));
$searchQuery = function_exists('mb_substr') ? mb_substr($searchQuery, 0, 120) : substr($searchQuery, 0, 120);
// Turkce karakterleri normalize et (magaza = MAĞAZA esitligi icin).
// DB charset latin5, PHP UTF-8. Kullanici "mağa" yazarsa "maga"ya cevrilir,
// SQL tarafinda COLLATE Turkish_CI_AI ile accent-insensitive eslesir.
$searchQueryNorm = function_exists('turkce') ? turkce($searchQuery) : $searchQuery;
$dateFromInput = trim((string)($_GET['date_from'] ?? ''));
$dateToInput = trim((string)($_GET['date_to'] ?? ''));
$minTotalInput = trim((string)($_GET['min_total'] ?? ''));
$maxTotalInput = trim((string)($_GET['max_total'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$printState = trim((string)($_GET['print_state'] ?? 'all'));

$allowedStatusFilters = ['all', '1', '2', '4'];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = 'all';
}

$allowedPrintStates = ['all', 'printed', 'unprinted'];
if (!in_array($printState, $allowedPrintStates, true)) {
    $printState = 'all';
}

$minTotal = parseFilterDecimal($minTotalInput);
$maxTotal = parseFilterDecimal($maxTotalInput);
if ($minTotal !== null && $maxTotal !== null && $minTotal > $maxTotal) {
    [$minTotal, $maxTotal] = [$maxTotal, $minTotal];
    [$minTotalInput, $maxTotalInput] = [$maxTotalInput, $minTotalInput];
}

$hasDateFrom = isIsoDate($dateFromInput);
$hasDateTo = isIsoDate($dateToInput);
if ($hasDateFrom && $hasDateTo && $dateFromInput > $dateToInput) {
    [$dateFromInput, $dateToInput] = [$dateToInput, $dateFromInput];
}

$ozelCariSiparisFiltresi = m_p_ozel_cari_sql_filtresi($terminalkullanici, 'FS.CLIENTREF', 'siparis_gizli');

// -- 2b) Filtre dropdown'ı için salesman listesini çek (sadece güncel dönem)
$smSql = "
    SELECT DISTINCT SL.LOGICALREF AS ID, SL.CODE, SL.DEFINITION_
    FROM {$firmadonem}ORFICHE FS
    LEFT JOIN {$firma}CLCARD CR ON CR.LOGICALREF = FS.CLIENTREF
    LEFT JOIN LG_SLSMAN SL ON SL.LOGICALREF = FS.SALESMANREF
    WHERE FS.TRCODE IN (1, 20, 21, 22) AND FS.NETTOTAL > 0 AND FS.SALESMANREF IS NOT NULL AND SL.LOGICALREF IS NOT NULL" .
    ($ozelCariSiparisFiltresi['sql'] !== '' ? " AND {$ozelCariSiparisFiltresi['sql']}" : '') . "
    ORDER BY SL.CODE
";
$smStmt = app_db_prepare_execute($dbh, $smSql, $ozelCariSiparisFiltresi['params'], [
    'page' => 'lg_essiparis.php',
    'action' => 'salesman_filter_list'
]);
$salesmen = [];
while ($sm = $smStmt->fetch(PDO::FETCH_ASSOC)) {
    $salesmen[] = $sm;
}

// Yazdırma sayısı için log tablosu kontrolü (varsa ana sorguya dahil et)
$yazdirSelect = '';
$yazdirJoin = '';
$tableExists = false;
try {
    $tableCheck = app_db_query_execute(
        $dbh,
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'M_MOBIL_DIZAYN_LOG'",
        [
            'page' => 'lg_essiparis.php',
            'action' => 'print_log_table_check'
        ]
    );
    $tableExists = $tableCheck && (int)$tableCheck->fetchColumn() > 0;
    if ($tableExists) {
        $yazdirSelect = ", ISNULL(YS.YAZDIRMA_SAYISI, 0) AS YAZDIRMA_SAYISI";
        $yazdirJoin = "
    LEFT JOIN (
        SELECT FIS, COUNT(*) AS YAZDIRMA_SAYISI
        FROM M_MOBIL_DIZAYN_LOG
        GROUP BY FIS
    ) YS ON YS.FIS = FS.LOGICALREF";
    }
} catch (Throwable $e) {
    error_log("Yazdirma log tablo kontrolu hatasi: " . $e->getMessage());
}

if (!$tableExists) {
    $printState = 'all';
}

// -- 3) Filtre + sıralama sorgu parçaları
$conditions = [
    "FS.LOGICALREF NOT IN (
            SELECT ST.ORDFICHEREF
            FROM {$firmadonem}STLINE ST
            WHERE ST.ORDFICHEREF IS NOT NULL AND ST.ORDFICHEREF <> 0
        )",
    "FS.TRCODE IN (1, 20, 21, 22)",
    "FS.NETTOTAL > 0",
];
$queryParams = $ozelCariSiparisFiltresi['params'];
if ($ozelCariSiparisFiltresi['sql'] !== '') {
    $conditions[] = $ozelCariSiparisFiltresi['sql'];
}

if ($salesmanRef > 0) {
    $conditions[] = "FS.SALESMANREF = :salesmanRef";
    $queryParams[':salesmanRef'] = $salesmanRef;
}

$relevanceSelect = "0 AS RELEVANCE_SCORE";
if ($searchQuery !== '') {
    // COLLATE Latin1_General_CI_AI: Case Insensitive + Accent Insensitive,
    // "I = i" esitligi (Ingilizce kurali). Turkish_CI_AI kullanmiyoruz cunku
    // Turkce'de I -> ı cevriliyor ve "teklif" aramasi "TEKLIF" ile eslesmiyor.
    // Latin1_General ile hem ş/s, ğ/g hem de I/i esit sayilir.
    $conditions[] = "(FS.FICHENO COLLATE Latin1_General_CI_AI LIKE :q_where_ficheno
        OR CR.DEFINITION_ COLLATE Latin1_General_CI_AI LIKE :q_where_musteri
        OR CR.CITY COLLATE Latin1_General_CI_AI LIKE :q_where_sehir
        OR SL.CODE COLLATE Latin1_General_CI_AI LIKE :q_where_satiskodu
        OR SL.DEFINITION_ COLLATE Latin1_General_CI_AI LIKE :q_where_satisadi
        OR FS.GENEXP1 COLLATE Latin1_General_CI_AI LIKE :q_where_aciklama)";

    $relevanceSelect = "CASE
            WHEN FS.FICHENO COLLATE Latin1_General_CI_AI = :q_exact THEN 100
            WHEN FS.FICHENO COLLATE Latin1_General_CI_AI LIKE :q_prefix_fis THEN 70
            WHEN FS.FICHENO COLLATE Latin1_General_CI_AI LIKE :q_like_fis THEN 50
            WHEN CR.DEFINITION_ COLLATE Latin1_General_CI_AI LIKE :q_prefix_musteri THEN 35
            WHEN CR.DEFINITION_ COLLATE Latin1_General_CI_AI LIKE :q_like_musteri THEN 25
            WHEN CR.CITY COLLATE Latin1_General_CI_AI LIKE :q_like_sehir THEN 15
            WHEN SL.CODE COLLATE Latin1_General_CI_AI LIKE :q_prefix_satiskodu THEN 12
            WHEN SL.DEFINITION_ COLLATE Latin1_General_CI_AI LIKE :q_like_satisadi THEN 10
            ELSE 1
        END AS RELEVANCE_SCORE";

    // Normalize edilmis (Turkce karakteri cikarilmis) versiyonla arama yap
    $searchLike = '%' . $searchQueryNorm . '%';
    $searchPrefix = $searchQueryNorm . '%';
    $queryParams[':q_where_ficheno'] = $searchLike;
    $queryParams[':q_where_musteri'] = $searchLike;
    $queryParams[':q_where_sehir'] = $searchLike;
    $queryParams[':q_where_satiskodu'] = $searchLike;
    $queryParams[':q_where_satisadi'] = $searchLike;
    $queryParams[':q_where_aciklama'] = $searchLike;
    $queryParams[':q_exact'] = $searchQueryNorm;
    $queryParams[':q_prefix_fis'] = $searchPrefix;
    $queryParams[':q_like_fis'] = $searchLike;
    $queryParams[':q_prefix_musteri'] = $searchPrefix;
    $queryParams[':q_like_musteri'] = $searchLike;
    $queryParams[':q_like_sehir'] = $searchLike;
    $queryParams[':q_prefix_satiskodu'] = $searchPrefix;
    $queryParams[':q_like_satisadi'] = $searchLike;
}

if ($hasDateFrom) {
    $conditions[] = "FS.DATE_ >= :dateFrom";
    $queryParams[':dateFrom'] = $dateFromInput . ' 00:00:00';
}
if ($hasDateTo) {
    $dateToNext = date('Y-m-d', strtotime($dateToInput . ' +1 day'));
    $conditions[] = "FS.DATE_ < :dateToNext";
    $queryParams[':dateToNext'] = $dateToNext . ' 00:00:00';
}

if ($minTotal !== null) {
    $conditions[] = "FS.NETTOTAL >= :minTotal";
    $queryParams[':minTotal'] = $minTotal;
}
if ($maxTotal !== null) {
    $conditions[] = "FS.NETTOTAL <= :maxTotal";
    $queryParams[':maxTotal'] = $maxTotal;
}

if ($statusFilter !== 'all') {
    $conditions[] = "FS.STATUS = :statusFilter";
    $queryParams[':statusFilter'] = (int)$statusFilter;
}

if ($tableExists && $printState === 'printed') {
    $conditions[] = "ISNULL(YS.YAZDIRMA_SAYISI, 0) > 0";
} elseif ($tableExists && $printState === 'unprinted') {
    $conditions[] = "ISNULL(YS.YAZDIRMA_SAYISI, 0) = 0";
}

$sortExpression = match ($sortField) {
    'price' => 'FS.NETTOTAL ' . $sortOrder,
    'date' => 'FS.DATE_ ' . $sortOrder . ', FS.LOGICALREF ' . $sortOrder,
    'ficheno' => 'FS.FICHENO ' . $sortOrder,
    default => 'FS.DATE_ DESC, FS.LOGICALREF DESC',
};

// -- 4) Veritabanı sorgusu (gelişmiş filtre + relevance destekli + sayfalama)
// N+1 SORUN ÇÖZÜMÜ: Satır sayısı ve yazdırma sayısı ana sorguya dahil edildi

$whereClause = implode("\n        AND ", $conditions);

$fromClause = "
    FROM {$firmadonem}ORFICHE FS
    LEFT JOIN {$firma}CLCARD CR ON FS.CLIENTREF = CR.LOGICALREF
    LEFT JOIN LG_SLSMAN SL ON SL.LOGICALREF = FS.SALESMANREF
    LEFT JOIN (
        SELECT ORDFICHEREF, COUNT(*) AS SATIR_SAYISI
        FROM {$firmadonem}ORFLINE
        WHERE LINETYPE = 0
        GROUP BY ORDFICHEREF
    ) SS ON SS.ORDFICHEREF = FS.LOGICALREF
    LEFT JOIN M_FIS_BASLIK MFB ON MFB.FIS_NO = FS.LOGICALREF
    {$yazdirJoin}
    WHERE {$whereClause}";

// COUNT sorgusu icin sadece WHERE'deki parametreleri kullan (relevance parametreleri haric)
$countParams = array_filter($queryParams, function($key) {
    return str_starts_with($key, ':q_where_') || !str_starts_with($key, ':q_');
}, ARRAY_FILTER_USE_KEY);

// COUNT/SUM icin SS (ORFLINE satir sayisi subquery) ve MFB (fis basligi) JOIN'leri gereksiz:
// yalnizca liste gosteriminde (SELECT) kullaniliyorlar, WHERE'de yer almiyorlar ve LEFT JOIN
// olduklari icin COUNT'u degistirmezler. Cikarmak pahali ORFLINE GROUP BY taramasini engeller.
$countFromClause = "
    FROM {$firmadonem}ORFICHE FS
    LEFT JOIN {$firma}CLCARD CR ON FS.CLIENTREF = CR.LOGICALREF
    LEFT JOIN LG_SLSMAN SL ON SL.LOGICALREF = FS.SALESMANREF
    {$yazdirJoin}
    WHERE {$whereClause}";
$countSql = "SELECT COUNT(*) AS TOPLAM, ISNULL(SUM(FS.NETTOTAL), 0) AS TOPLAM_TUTAR {$countFromClause}";
$stmtCount = app_db_prepare_execute($dbh, $countSql, $countParams, [
    'page' => 'lg_essiparis.php',
    'action' => 'order_count'
]);
$countRow = $stmtCount->fetch(PDO::FETCH_ASSOC);
$grandTotalOrders = (int)($countRow['TOPLAM'] ?? 0);
$grandSumNetTotal = (float)($countRow['TOPLAM_TUTAR'] ?? 0);
$totalPages = $grandTotalOrders > 0 ? (int)ceil($grandTotalOrders / $pageSize) : 1;
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
    $offset = ($currentPage - 1) * $pageSize;
}

$orderClause = $searchQuery !== ''
    ? "RELEVANCE_SCORE DESC, " . $sortExpression
    : $sortExpression;

$sql = "
    SELECT
        FS.FICHENO,
        FS.DATE_,
        FS.NETTOTAL,
        FS.LOGICALREF,
        CR.DEFINITION_,
        CR.DEFINITION2,
        CR.CITY,
        FS.CLIENTREF,
        FS.STATUS,
        FS.GENEXP1,
        FS.GENEXP2,
        FS.FCSTATUSREF,
        FS.TRCURR,
        FS.TRRATE,
        FS.SALESMANREF,
        FS.DOCODE,
        SL.CODE        AS SALESMAN_KODU,
        SL.DEFINITION_ AS SALESMAN_ADI,
        ISNULL(SS.SATIR_SAYISI, 0) AS SATIR_SAYISI,
        ISNULL(MFB.HACIM, 0) AS HACIM,
        ISNULL(MFB.AGIRLIK, 0) AS AGIRLIK,
        '{$firmadonem}' AS DONEM_PREFIX,
        {$relevanceSelect}
        {$yazdirSelect}
    {$fromClause}
    ORDER BY {$orderClause}
    OFFSET :paging_offset ROWS FETCH NEXT :paging_limit ROWS ONLY
";

$pagedParams = array_merge($queryParams, [
    ':paging_offset' => $offset,
    ':paging_limit' => $pageSize
]);

$stmtList = app_db_prepare_execute($dbh, $sql, $pagedParams, [
    'page' => 'lg_essiparis.php',
    'action' => 'order_list',
    'has_search' => $searchQuery !== '',
    'params_count' => count($pagedParams)
]);

// Sonuçları diziye al
$resultRows = [];
while ($row = $stmtList->fetch(PDO::FETCH_ASSOC)) {
    $resultRows[] = $row;
}

// Toggle mantığı
$currentOrder = $sortOrder;
$toggleOrderPrice   = ($sortField === 'price'   && $currentOrder === 'desc') ? 'asc' : 'desc';
$toggleOrderDate    = ($sortField === 'date'    && $currentOrder === 'desc') ? 'asc' : 'desc';
$toggleOrderFicheno = ($sortField === 'ficheno' && $currentOrder === 'desc') ? 'asc' : 'desc';

// Sıralama linklerinde filtrenin korunması
$baseQueryParams = [];
if ($salesmanRef > 0) {
    $baseQueryParams['salesman'] = $salesmanRef;
}
if ($searchQuery !== '') {
    $baseQueryParams['q'] = $searchQuery;
}
if ($hasDateFrom) {
    $baseQueryParams['date_from'] = $dateFromInput;
}
if ($hasDateTo) {
    $baseQueryParams['date_to'] = $dateToInput;
}
if ($minTotalInput !== '') {
    $baseQueryParams['min_total'] = $minTotalInput;
}
if ($maxTotalInput !== '') {
    $baseQueryParams['max_total'] = $maxTotalInput;
}
if ($statusFilter !== 'all') {
    $baseQueryParams['status'] = $statusFilter;
}
if ($tableExists && $printState !== 'all') {
    $baseQueryParams['print_state'] = $printState;
}

$sortDateUrl = '?' . http_build_query(array_merge($baseQueryParams, ['sort' => 'date', 'order' => $toggleOrderDate, 'page' => 1]));
$sortPriceUrl = '?' . http_build_query(array_merge($baseQueryParams, ['sort' => 'price', 'order' => $toggleOrderPrice, 'page' => 1]));
$sortFichenoUrl = '?' . http_build_query(array_merge($baseQueryParams, ['sort' => 'ficheno', 'order' => $toggleOrderFicheno, 'page' => 1]));

$activeFilterBadges = [];
if ($searchQuery !== '') {
    $activeFilterBadges[] = 'Arama: "' . $searchQuery . '"';
}
if ($salesmanRef > 0) {
    $salesmanText = 'ID: ' . $salesmanRef;
    foreach ($salesmen as $sm) {
        if ((int)$sm['ID'] === $salesmanRef) {
            $salesmanText = (string)$sm['CODE'];
            break;
        }
    }
    $activeFilterBadges[] = 'Temsilci: ' . $salesmanText;
}
if ($hasDateFrom || $hasDateTo) {
    $activeFilterBadges[] = 'Tarih: ' . ($hasDateFrom ? $dateFromInput : '...') . ' - ' . ($hasDateTo ? $dateToInput : '...');
}
if ($minTotal !== null || $maxTotal !== null) {
    $activeFilterBadges[] = 'Tutar: ' . ($minTotal !== null ? paraformat($minTotal) : '...') . ' - ' . ($maxTotal !== null ? paraformat($maxTotal) : '...');
}
if ($statusFilter !== 'all') {
    $statusText = match ($statusFilter) {
        '1' => 'Onay Bekliyor',
        '2' => 'İptal Edildi',
        '4' => 'Onaylandı',
        default => 'Diğer'
    };
    $activeFilterBadges[] = 'Durum: ' . $statusText;
}
if ($tableExists && $printState !== 'all') {
    $activeFilterBadges[] = 'Yazdırma: ' . ($printState === 'printed' ? 'Yazdırılmış' : 'Yazdırılmamış');
}
$hasActiveFilters = !empty($activeFilterBadges);

// Özet rakamlar (toplam ve tutar COUNT sorgusundan, sayfa verileri resultRows'tan)
$totalOrders = $grandTotalOrders;
$pageOrderCount = count($resultRows);
$sumNetTotal = $grandSumNetTotal;
$avgNetTotal = $totalOrders > 0 ? $sumNetTotal / $totalOrders : 0;

// Sayfa verileri için hacim/ağırlık
$sumHacim = 0;
$sumAgirlik = 0;
foreach ($resultRows as $r) {
    $sumHacim += (float)($r['HACIM'] ?? 0);
    $sumAgirlik += (float)($r['AGIRLIK'] ?? 0);
}

// N+1 ÇÖZÜMÜ: Eski kart başına sorgular kaldırıldı
// Satır sayısı ve yazdırma sayısı artık ana sorguya dahil edildi (LEFT JOIN subquery)
?>
<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Siparis Listesi</title>
    <?php include_once(__DIR__ . '/../pwa-header.php'); ?>
    <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" onerror="(function(){var s=document.createElement('script');s.defer=true;s.src='https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js';document.head.appendChild(s);}())"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f9fafb;
            --surface: #ffffff;
            --text-1: #1f2937;
            --text-2: #4b5563;
            --text-3: #6b7280;
            --border: #e5e7eb;
            --red: #6F1022;
            --red-soft: #fef2f2;
            --red-border: rgba(248, 113, 113, 0.25);
            --amber: #d97706;
            --amber-soft: #fffbeb;
            --emerald: #059669;
            --emerald-soft: #ecfdf5;
            --indigo: #4f46e5;
            --indigo-soft: #eef2ff;
        }

        * { box-sizing: border-box; margin: 0; }

        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
        }

        [x-cloak]:not(.print-dropdown) { display: none !important; }

        /* ═══════════ HEADER ═══════════ */
        .top-header {
            position: sticky;
            top: 0;
            z-index: 40;
            height: 64px;
            background: rgba(255,255,255,0.88);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(248, 113, 113, 0.18);
            box-shadow: 0 2px 8px rgba(111, 16, 34, 0.04);
            animation: headerSlide 0.46s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .header-inner {
            max-width: 1280px;
            margin: 0 auto;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .header-back {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            color: var(--text-2);
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .header-back:hover {
            background: rgba(0,0,0,0.04);
            color: var(--red);
        }

        .header-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-1);
        }

        .header-divider {
            width: 1px;
            height: 24px;
            background: var(--border);
        }

        /* ═══════════ SUMMARY CARDS ═══════════ */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 20px;
        }

        .summary-card {
            background: #ffffff;
            backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.26);
            border-radius: 16px;
            padding: 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            will-change: transform, opacity;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .summary-card:nth-child(1) { animation-delay: 0ms; }
        .summary-card:nth-child(2) { animation-delay: 40ms; }
        .summary-card:nth-child(3) { animation-delay: 80ms; }

        .summary-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .summary-icon-red {
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            color: var(--red,#ef4444);
        }
        .summary-icon-emerald {
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            color: #10b981;
        }
        .summary-icon-indigo {
            background: linear-gradient(135deg, #eef2ff, #e0e7ff);
            color: #6366f1;
        }

        .summary-label {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: var(--text-3);
        }
        .summary-value {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-1);
            line-height: 1.2;
        }

        @media (max-width: 767px) {
            .top-header { height: 50px; }
            .header-inner { padding: 0 12px; }
            .header-title { font-size: 15px; }
            .header-back { width: 30px; height: 30px; border-radius: 8px; }
            .header-divider { height: 18px; }

            .summary-grid { grid-template-columns: repeat(3, 1fr); gap: 6px; }
            .summary-card {
                padding: 10px 6px;
                gap: 4px;
                flex-direction: column;
                text-align: center;
                align-items: center;
                border-radius: 12px;
            }
            .summary-icon { width: 28px; height: 28px; font-size: 11px; border-radius: 8px; }
            .summary-value { font-size: 12px; word-break: normal; overflow-wrap: anywhere; }
            .summary-label { font-size: 8px; letter-spacing: 0.3px; }
        }

        /* ═══════════ FILTER PANEL ═══════════ */
        .filter-panel {
            background: #ffffff;
            backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.24);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            will-change: transform, opacity;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .filter-input {
            width: 100%;
            padding: 10px 12px 10px 40px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14px;
            color: var(--text-1);
            background: #fff;
            transition: all 0.2s ease;
            outline: none;
        }
        .filter-input:focus {
            border-color: rgba(239, 68, 68, 0.5);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }

        .filter-select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14px;
            color: var(--text-1);
            background: #fff;
            transition: all 0.2s ease;
            outline: none;
            cursor: pointer;
        }
        .filter-select:focus {
            border-color: rgba(239, 68, 68, 0.5);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }

        .filter-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-2);
            margin-bottom: 6px;
        }

        .btn-flat {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 18px;
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .btn-flat:hover { box-shadow: 0 2px 6px rgba(0,0,0,0.15); }
        .btn-flat:active { transform: scale(0.98); }

        .btn-red { background: var(--red,#ef4444); color: #fff; }
        .btn-red:hover { background: var(--red,#6F1022); }
        .btn-light { background: #fff; color: var(--text-2); border: 1px solid var(--border); }
        .btn-light:hover { background: #f9fafb; color: var(--text-1); }
        .btn-indigo { background: #6366f1; color: #fff; }
        .btn-indigo:hover { background: #4f46e5; }
        .btn-amber { background: #f59e0b; color: #fff; }
        .btn-amber:hover { background: #d97706; }

        .sort-btn {
            flex: 1;
            text-align: center;
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text-2);
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .sort-btn:hover { background: #f9fafb; }
        .sort-btn-active {
            background: var(--red,#ef4444);
            color: #fff;
            border-color: var(--red,#ef4444);
            box-shadow: 0 1px 3px rgba(111, 16, 34, 0.3);
        }

        .filter-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 100px;
            font-size: 12px;
            font-weight: 600;
            background: var(--red-soft);
            color: var(--red);
            border: 1px solid var(--red-border);
        }

        /* ═══════════ ORDER GRID ═══════════ */
        .order-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 14px;
        }
        @media (max-width: 767px) {
            .order-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
        }

        /* ═══════════ ORDER CARDS ═══════════ */
        .order-card {
            position: relative;
            overflow: hidden;
            background: #ffffff;
            backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.24);
            border-radius: 16px;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.06);
            display: flex;
            flex-direction: column;
            /* opacity: 0 kaldirildi - render blok sorunu. Keyframe ilk %0'da opacity:0 ile baslar */
            will-change: transform, opacity;
            animation: cardIn 0.5s cubic-bezier(0.22, 1, 0.36, 1) both;
            transition: transform 0.35s cubic-bezier(0.22, 1, 0.36, 1),
                        box-shadow 0.35s ease,
                        border-color 0.35s ease;
            touch-action: pan-y;
        }

        /* Shimmer */
        .order-card::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(120deg, transparent 15%, rgba(255,255,255,0.5) 45%, transparent 75%);
            transform: translateX(-130%);
            transition: transform 0.75s ease;
            pointer-events: none;
            z-index: 1;
        }
        .order-card:hover::before {
            transform: translateX(130%);
        }
        .order-card:hover {
            transform: translate3d(0, -4px, 0);
            border-color: rgba(239, 68, 68, 0.35);
            box-shadow: 0 18px 35px -18px rgba(111, 16, 34, 0.2), 0 8px 18px rgba(15, 23, 42, 0.08);
        }

        @media (max-width: 767px) {
            .order-card:hover { transform: none; }
        }

        /* Card Header */
        .oc-header {
            padding: 16px 18px;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            flex-wrap: wrap;
        }

        .oc-ficheno {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-1);
        }

        .oc-header-right {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .oc-date {
            font-size: 12px;
            color: var(--text-3);
            white-space: nowrap;
        }

        /* Card Body */
        .oc-body {
            padding: 16px 18px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .oc-customer {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-1);
            line-height: 1.3;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .oc-price-box {
            padding: 12px 14px;
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border: 1px solid rgba(16,185,129,0.2);
            border-radius: 12px;
            text-align: center;
        }

        .oc-price {
            font-size: 22px;
            font-weight: 700;
            color: #065f46;
            line-height: 1.2;
        }

        .oc-price-doviz {
            font-size: 14px;
            font-weight: 600;
            color: #1e40af;
            margin-top: 4px;
        }

        .oc-meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--text-3);
        }
        .oc-meta-dot {
            width: 3px; height: 3px;
            border-radius: 50%;
            background: var(--text-3);
            flex-shrink: 0;
        }

        .oc-note {
            font-size: 12px;
            font-style: italic;
            color: var(--text-3);
            line-height: 1.4;
            padding: 8px 12px;
            background: #eef2ff;
            border-radius: 8px;
            border: 1px solid rgba(99,102,241,0.15);
        }

        /* Card Footer */
        .oc-footer {
            padding: 12px 18px;
            border-top: 1px solid rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ═══ LİSTE GÖRÜNÜMÜ (kişisel ayar gor_liste=liste → html.akl-liste) — kompakt tek-satır.
              Sadece ≥768px; mobilde kart düzeni korunur. Ödeme paneli KORUNUR (chip); yalnız
              ikincil/bilgi öğeleri (meta/açıklama/döviz) gizlenir. ═══ */
        @media (min-width: 768px) {
            html.akl-liste .order-grid { grid-template-columns: 1fr; gap: 7px; }
            html.akl-liste .order-card {
                flex-direction: row; flex-wrap: wrap; align-items: center;
                border-radius: 10px; box-shadow: 0 1px 3px rgba(15,23,42,0.07);
            }
            html.akl-liste .order-card:hover { transform: none; }
            html.akl-liste .oc-header {
                flex: 0 0 auto; padding: 8px 14px; border-bottom: none;
                gap: 8px; flex-wrap: nowrap; justify-content: flex-start;
            }
            html.akl-liste .oc-body {
                flex: 1 1 260px; flex-direction: row; flex-wrap: wrap;
                align-items: center; gap: 6px 14px; padding: 8px 6px;
            }
            html.akl-liste .oc-customer { flex: 1 1 auto; min-width: 90px; }
            html.akl-liste .oc-price-box {
                flex: 0 0 auto; padding: 0; background: transparent;
                border: none; border-radius: 0; text-align: right;
            }
            html.akl-liste .oc-price { font-size: 16px; }
            html.akl-liste .oc-meta,
            html.akl-liste .oc-note,
            html.akl-liste .oc-price-doviz { display: none; }
            html.akl-liste .odeme-secim { flex: 0 0 auto; margin: 0; }
            html.akl-liste .oc-footer {
                flex: 0 0 auto; padding: 8px 14px; border-top: none; gap: 6px;
            }
        }

        @media (max-width: 767px) {
            .order-card {
                border-radius: 12px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.04);
                overflow: visible;
            }
            .order-card::before { display: none; }
            .order-card:hover { transform: none; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
            /* Dropdown acikken kart en uste ciksin */
            .order-card:has([x-show]:not([style*="display: none"])) {
                z-index: 30;
            }

            /* Header: iki satir duzeni - ust: fis no + tarih, alt: durum badge'leri */
            .oc-header {
                padding: 10px 12px;
                gap: 6px;
                flex-direction: column;
                align-items: stretch;
            }
            .oc-header > div:first-child {
                display: flex;
                align-items: center;
                justify-content: space-between;
                width: 100%;
            }
            .oc-header-right {
                gap: 4px;
                flex-wrap: wrap;
                justify-content: flex-start;
            }
            /* Hizli yazdir butonu header'da gizle - footer'da zaten var */
            .oc-header-right > a.btn-flat { display: none; }
            .oc-ficheno { font-size: 12px; }
            .oc-date { font-size: 11px; }

            .oc-body { padding: 10px 12px; gap: 8px; }
            .oc-customer { font-size: 13px; }
            .oc-price { font-size: 17px; }
            .oc-price-box { padding: 8px 10px; border-radius: 10px; }
            .oc-price-doviz { font-size: 12px; }
            .oc-meta { font-size: 11px; gap: 4px; }
            .oc-note { font-size: 11px; padding: 6px 10px; border-radius: 6px; }
            .status-badge { font-size: 10px; padding: 2px 7px; }
            .print-count-badge { font-size: 9px; padding: 1px 5px; }

            /* Footer: butonlar yan yana, esit genislik */
            .oc-footer {
                padding: 8px 12px;
                gap: 6px;
                flex-wrap: nowrap;
            }
            .oc-footer .btn-flat { font-size: 11px; padding: 8px 8px; border-radius: 8px; }
            .oc-footer > a[href*="lg_fis"] { flex: 1; min-width: 0; }
            .oc-footer > a[href*="fis_gecmis"] { padding: 8px 10px !important; flex: 0 0 auto; }
            .oc-footer > div[x-data] { flex: 1; min-width: 0; }
            .oc-footer > div[x-data] .btn-flat { width: 100%; }

            /* Dropdown mobilde asagi acilsin */
            .print-dropdown {
                bottom: auto !important;
                top: 100% !important;
                margin-bottom: 0 !important;
                margin-top: 6px !important;
                z-index: 9999 !important;
                position: fixed !important;
                right: 12px !important;
                left: 12px !important;
                width: auto !important;
                max-height: 60vh;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                border-radius: 12px;
            }
            .print-dropdown a { padding: 12px 16px; font-size: 13px; }
            .print-dropdown a:active { background: #fef3c7; }

            /* Pagination kompakt */
            .paging-btn { min-width: 34px; height: 34px; padding: 0 8px; font-size: 12px; border-radius: 8px; }
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 10px;
            border-radius: 100px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-onay { background: #fef3c7; color: #92400e; }
        .status-ok { background: #d1fae5; color: #065f46; }
        .status-iptal { background: #fee2e2; color: #991b1b; }
        .status-diger { background: #f1f5f9; color: #475569; }
        .status-doviz { background: #dbeafe; color: #1e40af; }

        /* ═══════════ ODEME SECIMI (Magaza Satis) ═══════════ */
        .odeme-secim {
            padding: 10px 12px;
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border: 1px solid rgba(217, 119, 6, 0.25);
            border-radius: 10px;
        }

        /* Collapsed durumu: sadece chip gorunur */
        .odeme-secim.odeme-collapsed {
            padding: 0;
            background: transparent;
            border: none;
        }
        .odeme-secim.odeme-collapsed .odeme-label,
        .odeme-secim.odeme-collapsed .odeme-btns { display: none; }

        /* Expanded durumunda chip gizli */
        .odeme-secim:not(.odeme-collapsed) .odeme-chip { display: none; }

        .odeme-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 100px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
            color: #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.12);
        }
        .odeme-chip:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.18);
        }
        .odeme-chip-nakit { background: linear-gradient(135deg, #059669, #047857); }
        .odeme-chip-kart { background: linear-gradient(135deg, #2563eb, #1d4ed8); }
        .odeme-chip-havale { background: linear-gradient(135deg, #7c3aed, #6d28d9); }
        .odeme-chip-karma { background: linear-gradient(135deg, #ea580c, #c2410c); }

        .odeme-label {
            font-size: 11px;
            font-weight: 600;
            color: #92400e;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .odeme-label i { margin-right: 3px; }
        .odeme-btns {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
        }
        .odeme-btn {
            padding: 8px 6px;
            border-radius: 8px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid #fde68a;
            background: rgba(255,255,255,0.7);
            color: #78350f;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }
        .odeme-btn:hover {
            background: #fff;
            border-color: #d97706;
        }
        .odeme-btn.active {
            color: #fff;
            border-color: transparent;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        .odeme-btn.odeme-nakit.active { background: #059669; }
        .odeme-btn.odeme-kart.active { background: #2563eb; }
        .odeme-btn.odeme-havale.active { background: #7c3aed; }
        .odeme-btn.odeme-karma.active { background: #ea580c; }
        .odeme-btn:disabled { opacity: 0.5; cursor: wait; }

        @media (max-width: 767px) {
            .odeme-secim { padding: 8px 10px; border-radius: 8px; }
            .odeme-label { font-size: 10px; margin-bottom: 5px; }
            .odeme-btns { grid-template-columns: repeat(2, 1fr); }
            .odeme-btn { font-size: 12px; padding: 10px 8px; min-height: 44px; }
            .odeme-chip { font-size: 11px; padding: 6px 12px; }
        }

        .print-count-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 7px;
            border-radius: 100px;
            font-size: 10px;
            font-weight: 700;
            background: #dbeafe;
            color: #1e40af;
        }
        .print-count-badge-many {
            background: #d1fae5;
            color: #065f46;
        }

        /* Dropdown */
        .print-dropdown {
            position: absolute;
            bottom: 100%;
            right: 0;
            margin-bottom: 6px;
            width: 220px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 12px 28px rgba(0,0,0,0.12);
            z-index: 30;
            overflow: hidden;
        }
        .print-dropdown a {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-1);
            text-decoration: none;
            transition: background 0.15s;
        }
        .print-dropdown a:hover {
            background: #fffbeb;
        }
        .print-dropdown a i {
            width: 16px;
            text-align: center;
            color: var(--text-3);
            font-size: 13px;
        }
        .print-dropdown-sep {
            height: 1px;
            background: var(--border);
            margin: 2px 0;
        }

        /* ═══════════ PAGINATION ═══════════ */
        .paging-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 38px;
            height: 38px;
            padding: 0 12px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            background: #fff;
            color: var(--text-2);
            border: 1px solid var(--border);
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .paging-btn:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }
        .paging-btn-active {
            background: var(--red,#ef4444);
            color: #fff;
            border-color: var(--red,#ef4444);
            box-shadow: 0 1px 3px rgba(111, 16, 34, 0.3);
        }

        /* ═══════════ MODAL ═══════════ */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.4);
            backdrop-filter: blur(4px);
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .modal-box {
            background: #fff;
            border-radius: 16px;
            max-width: 640px;
            width: 100%;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 25px 50px rgba(0,0,0,0.15);
        }
        .modal-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-header h3 {
            font-size: 17px;
            font-weight: 700;
            color: var(--text-1);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .modal-header h3 i { color: #6366f1; }
        .modal-close {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: none;
            border: none;
            color: var(--text-3);
            cursor: pointer;
            transition: all 0.2s;
        }
        .modal-close:hover {
            background: #f1f5f9;
            color: var(--text-1);
        }
        .modal-body {
            padding: 24px;
            overflow-y: auto;
            max-height: calc(90vh - 70px);
        }

        /* ═══════════ EMPTY STATE ═══════════ */
        .empty-state {
            background: rgba(255,255,255,0.88);
            backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 16px;
            padding: 60px 24px;
            text-align: center;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        /* ═══════════ PULL TO REFRESH ═══════════ */
        .pull-indicator {
            position: fixed;
            top: 0;
            left: 50%;
            transform: translateX(-50%) translateY(-100%);
            z-index: 9999;
            transition: transform 0.2s ease;
            pointer-events: none;
        }
        .pull-indicator.visible {
            transform: translateX(-50%) translateY(10px);
        }
        .pull-indicator.refreshing {
            transform: translateX(-50%) translateY(10px);
        }
        .pull-indicator .spinner {
            animation: spin 1s linear infinite;
        }

        /* ═══════════ ANIMATIONS ═══════════ */
        @keyframes headerSlide {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 16px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* ═══════════ HIGHLIGHT (vurgula parametresi) ═══════════ */
        /*
         * Vurgulama stratejisi:
         * - Sticky header altina dusmesin diye scroll-margin-top
         * - overflow:hidden engeli icin ::after pseudo-element (ring disariya tasar)
         * - 3 asamali: (1) ilk pop, (2) sustained pulse, (3) fade out
         */
        .order-card {
            scroll-margin-top: 84px;
        }
        @media (max-width: 767px) {
            .order-card { scroll-margin-top: 66px; }
        }
        .order-card.highlight-target {
            position: relative;
            z-index: 12;
            isolation: isolate;
            overflow: visible !important;
            /* Base card'in opacity/transform stillerini sifirla ki gorunsun */
            opacity: 1 !important;
            animation: highlightEnter 0.7s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .order-card.highlight-target::after {
            content: "";
            position: absolute;
            inset: -4px;
            border-radius: 18px;
            pointer-events: none;
            z-index: 11;
            background: radial-gradient(120% 120% at 50% 50%, rgba(245, 158, 11, 0.22), rgba(245, 158, 11, 0.08) 55%, transparent 75%);
            box-shadow:
                0 0 0 2px rgba(245, 158, 11, 0.85),
                0 0 30px rgba(245, 158, 11, 0.5),
                0 0 60px rgba(245, 158, 11, 0.25);
            opacity: 0;
            animation: highlightRingPulse 2.1s cubic-bezier(0.4, 0, 0.6, 1) 0.2s infinite;
        }
        .order-card.highlight-target::before {
            /* shimmer'i devre disi birak - glow ile cakismasin */
            display: none !important;
        }

        @keyframes highlightEnter {
            0%   { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
            35%  { opacity: 1; transform: translate3d(0, 0, 0) scale(1.025); }
            70%  { opacity: 1; transform: translate3d(0, 0, 0) scale(0.995); }
            100% { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }
        @keyframes highlightRingPulse {
            0% {
                opacity: 0.55;
                transform: scale(0.985);
                box-shadow:
                    0 0 0 2px rgba(245, 158, 11, 0.9),
                    0 0 20px rgba(245, 158, 11, 0.6),
                    0 0 40px rgba(245, 158, 11, 0.35);
            }
            50% {
                opacity: 1;
                transform: scale(1.015);
                box-shadow:
                    0 0 0 4px rgba(245, 158, 11, 0.65),
                    0 0 45px rgba(245, 158, 11, 0.75),
                    0 0 90px rgba(245, 158, 11, 0.4);
            }
            100% {
                opacity: 0.55;
                transform: scale(0.985);
                box-shadow:
                    0 0 0 2px rgba(245, 158, 11, 0.9),
                    0 0 20px rgba(245, 158, 11, 0.6),
                    0 0 40px rgba(245, 158, 11, 0.35);
            }
        }

        /* Fade out faza */
        .order-card.highlight-fading::after {
            animation: highlightFade 0.9s ease-out forwards;
        }
        @keyframes highlightFade {
            from { opacity: 1; }
            to { opacity: 0; box-shadow: 0 0 0 0 transparent; }
        }

        /* Label "Iste burada" rozeti */
        .highlight-badge {
            position: absolute;
            top: -12px;
            right: 14px;
            z-index: 13;
            padding: 5px 12px;
            border-radius: 100px;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.3px;
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.45), 0 2px 4px rgba(0,0,0,0.1);
            white-space: nowrap;
            pointer-events: none;
            animation: highlightBadgeIn 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.3s both;
        }
        .highlight-badge i { margin-right: 4px; font-size: 10px; }
        @keyframes highlightBadgeIn {
            from { opacity: 0; transform: translateY(6px) scale(0.8); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* Reduced motion */
        @media (prefers-reduced-motion: reduce) {
            .order-card.highlight-target,
            .order-card.highlight-target::after,
            .highlight-badge {
                animation: none !important;
            }
            .order-card.highlight-target::after {
                opacity: 1;
                box-shadow:
                    0 0 0 3px rgba(245, 158, 11, 0.9),
                    0 0 30px rgba(245, 158, 11, 0.5);
            }
        }

        /* ═══════════ ALERT ═══════════ */
        .alert-box {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 16px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 14px;
            animation: cardIn 0.5s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .alert-error {
            background: #fef2f2;
            border: 1px solid rgba(248, 113, 113, 0.3);
            color: #991b1b;
        }
        .alert-success {
            background: #ecfdf5;
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #065f46;
        }
        .alert-icon { font-size: 16px; margin-top: 2px; flex-shrink: 0; }

        /* Mobile filter toggle */
        .mobile-toggle {
            display: none;
            width: 100%;
            padding: 10px;
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-2);
            background: #fff;
            border: 1px solid var(--border);
            cursor: pointer;
            transition: all 0.2s;
        }
        .mobile-toggle:hover { background: #f9fafb; }

        @media (max-width: 767px) {
            .mobile-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                padding: 9px;
                border-radius: 8px;
                font-size: 12px;
            }
            .filter-panel {
                padding: 12px;
                margin-bottom: 12px;
                border-radius: 12px;
            }
            .filter-input {
                padding: 10px 10px 10px 36px;
                font-size: 16px; /* iOS zoom engeli: min 16px */
                border-radius: 8px;
            }
            .filter-select {
                padding: 10px 10px;
                font-size: 16px; /* iOS zoom engeli: min 16px */
                border-radius: 8px;
            }
            .filter-label { font-size: 11px; margin-bottom: 3px; }
            .filter-grid-2col { grid-template-columns: 1fr !important; gap: 8px !important; }
            .filter-badge { font-size: 11px; padding: 3px 10px; }
            .btn-flat { padding: 8px 12px; font-size: 12px; border-radius: 8px; }
            .sort-btn { padding: 7px 10px; font-size: 12px; border-radius: 8px; }

            main { padding: 12px 8px !important; }

            /* Empty state kompakt */
            .empty-state { padding: 40px 16px; border-radius: 12px; }

            /* Alert kompakt */
            .alert-box { padding: 10px 14px; font-size: 13px; border-radius: 10px; gap: 10px; }
        }
    </style>
</head>

<body>
    <!-- Pull to Refresh Indicator -->
    <div id="pullIndicator" class="pull-indicator">
        <div style="background:#fff;border-radius:50%;padding:12px;box-shadow:0 4px 12px rgba(0,0,0,0.1);">
            <i id="pullIcon" class="fa fa-arrow-down" style="color:var(--red,#ef4444);font-size:18px;"></i>
        </div>
    </div>

    <!-- Header -->
    <header class="top-header">
        <div class="header-inner">
            <div class="header-left">
                <a href="../index.php" class="header-back" title="Ana Sayfa">
                    <i class="fa fa-arrow-left"></i>
                </a>
                <div class="header-divider"></div>
                <span class="header-title">Siparişler</span>
            </div>
        </div>
    </header>

    <?php $sipAktifSekme = 'acik'; include __DIR__ . '/siparis_sekmeler.php'; ?>

    <!-- Main Content -->
    <main style="max-width:1280px;margin:0 auto;padding:24px 16px;">

        <!-- Alerts -->
        <?php if (isset($_SESSION['yazdirma_hata'])): ?>
            <div class="alert-box alert-error">
                <i class="fa-solid fa-exclamation-circle alert-icon"></i>
                <div>
                    <strong>Yazdırma Hatası:</strong>
                    <p><?php echo htmlspecialchars($_SESSION['yazdirma_hata'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </div>
            <?php unset($_SESSION['yazdirma_hata']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['yazdirma_basarili'])): ?>
            <div class="alert-box alert-success">
                <i class="fa-solid fa-check-circle alert-icon"></i>
                <div>
                    <strong>Başarılı:</strong>
                    <p><?php echo htmlspecialchars($_SESSION['yazdirma_basarili'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </div>
            <?php unset($_SESSION['yazdirma_basarili']); ?>
        <?php endif; ?>

        <!-- Summary Cards (both desktop and mobile) -->
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-icon summary-icon-red">
                    <i class="fa-solid fa-clipboard-list"></i>
                </div>
                <div>
                    <div class="summary-label">Toplam Sipariş</div>
                    <div class="summary-value"><?php echo number_format($totalOrders, 0, ',', '.'); ?></div>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon summary-icon-emerald">
                    <i class="fa-solid fa-coins"></i>
                </div>
                <div>
                    <div class="summary-label">Toplam Tutar</div>
                    <div class="summary-value"><?php echo paraformat($sumNetTotal); ?></div>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon summary-icon-indigo">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
                <div>
                    <div class="summary-label">Ortalama</div>
                    <div class="summary-value"><?php echo paraformat($avgNetTotal); ?></div>
                </div>
            </div>
        </div>

        <!-- Filter Panel -->
        <div class="filter-panel">
            <form method="get" id="filterForm" style="display:flex;flex-direction:column;gap:14px;">
                <input type="hidden" name="sort" value="<?php echo htmlspecialchars((string)$sortField, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="order" value="<?php echo htmlspecialchars((string)$sortOrder, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="page" value="1">

                <!-- Search -->
                <div>
                    <label for="barkod" class="filter-label">Listede Ara</label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-3);"><i class="fa-solid fa-search"></i></span>
                        <input type="text" name="q" id="barkod" class="filter-input"
                            value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="Sipariş no, müşteri, şehir, temsilci...">
                    </div>
                </div>

                <!-- Mobile filter toggle -->
                <button type="button" id="mobileFilterToggle" class="mobile-toggle">
                    <i class="fa-solid fa-sliders"></i> Filtreler
                </button>

                <!-- Collapsible filters -->
                <div id="mobileFilterPanel" style="display:none;flex-direction:column;gap:14px;">
                    <style>
                        @media (min-width: 768px) {
                            #mobileFilterPanel { display: flex !important; }
                        }
                    </style>

                    <div class="filter-grid-2col" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div>
                            <label for="salesman" class="filter-label">Satış Temsilcisi</label>
                            <select name="salesman" id="salesman" class="filter-select">
                                <option value="0">Hepsi</option>
                                <?php foreach ($salesmen as $sm): ?>
                                <option value="<?php echo (int)$sm['ID']; ?>" <?php echo ($salesmanRef === (int)$sm['ID']) ? 'selected' : ''; ?>>
                                    <?php
                                    echo htmlspecialchars((string)$sm['CODE'], ENT_QUOTES, 'UTF-8') . ' - ' .
                                        htmlspecialchars(tr($sm['DEFINITION_']), ENT_QUOTES, 'UTF-8');
                                    ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="status" class="filter-label">Durum</label>
                            <select id="status" name="status" class="filter-select">
                                <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>Tümü</option>
                                <option value="1" <?php echo $statusFilter === '1' ? 'selected' : ''; ?>>Onay Bekliyor</option>
                                <option value="4" <?php echo $statusFilter === '4' ? 'selected' : ''; ?>>Onaylandı</option>
                                <option value="2" <?php echo $statusFilter === '2' ? 'selected' : ''; ?>>İptal Edildi</option>
                            </select>
                        </div>
                    </div>

                    <div class="filter-grid-2col" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div>
                            <?php if ($tableExists): ?>
                                <label for="print_state" class="filter-label">Yazdırma Durumu</label>
                                <select id="print_state" name="print_state" class="filter-select">
                                    <option value="all" <?php echo $printState === 'all' ? 'selected' : ''; ?>>Tümü</option>
                                    <option value="printed" <?php echo $printState === 'printed' ? 'selected' : ''; ?>>Yazdırılmış</option>
                                    <option value="unprinted" <?php echo $printState === 'unprinted' ? 'selected' : ''; ?>>Yazdırılmamış</option>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="filter-label">Sırala</label>
                            <div style="display:flex;gap:8px;">
                                <a href="<?php echo htmlspecialchars($sortDateUrl, ENT_QUOTES, 'UTF-8'); ?>" class="sort-btn <?php echo $sortField === 'date' ? 'sort-btn-active' : ''; ?>">Tarih</a>
                                <a href="<?php echo htmlspecialchars($sortPriceUrl, ENT_QUOTES, 'UTF-8'); ?>" class="sort-btn <?php echo $sortField === 'price' ? 'sort-btn-active' : ''; ?>">Tutar</a>
                                <a href="<?php echo htmlspecialchars($sortFichenoUrl, ENT_QUOTES, 'UTF-8'); ?>" class="sort-btn <?php echo $sortField === 'ficheno' ? 'sort-btn-active' : ''; ?>">Sip. No</a>
                            </div>
                        </div>
                    </div>

                    <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;">
                        <button type="submit" class="btn-flat btn-red">
                            <i class="fa-solid fa-filter"></i> Filtre Uygula
                        </button>
                        <a href="lg_essiparis-beta.php" class="btn-flat btn-light">
                            <i class="fa-solid fa-rotate-left"></i> Temizle
                        </a>
                    </div>
                </div>
            </form>

            <?php if ($hasActiveFilters): ?>
                <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                    <span style="font-size:11px;font-weight:600;letter-spacing:0.5px;text-transform:uppercase;color:var(--text-3);">Aktif filtreler:</span>
                    <?php foreach ($activeFilterBadges as $badge): ?>
                        <span class="filter-badge"><?php echo htmlspecialchars($badge, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Order Cards -->
        <?php if (empty($resultRows)): ?>
            <div class="empty-state">
                <div style="width:48px;height:48px;border-radius:14px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;color:var(--text-3);font-size:20px;">
                    <i class="fa-solid fa-inbox"></i>
                </div>
                <p style="font-size:16px;font-weight:600;color:var(--text-1);">Sipariş bulunamadı</p>
                <p style="font-size:13px;color:var(--text-3);margin-top:6px;">Filtreleri temizleyip tekrar deneyin.</p>
            </div>
        <?php else: ?>
            <div class="order-grid">
                <?php
                $cardIndex = 0;
                foreach ($resultRows as $ila):
                $stokhareket = $ila['LOGICALREF'];
                // N+1 ÇÖZÜMÜ: Artık ana sorgudan geliyor (ayrı sorgu yok)
                $eynes = ['T' => (int)($ila['SATIR_SAYISI'] ?? 0)];
                $cariid = intcevir($ila['CLIENTREF']);

                // N+1 ÇÖZÜMÜ: Yazdırma sayısı artık ana sorgudan geliyor
                $yazdirmaSayisi = (int)($ila['YAZDIRMA_SAYISI'] ?? 0);

                // Döviz bilgilerini önce hesapla
                $trCurr = isset($ila['TRCURR']) ? (int)$ila['TRCURR'] : 0;
                $hasDoviz = $trCurr !== 0 && $trCurr !== 1 && isset($ila['TRRATE']) && (float)$ila['TRRATE'] > 0;
                $dovizSembol = $hasDoviz ? dovizsembol_bul($trCurr) : '';
                $dovizIcon = 'fa-dollar-sign';
                $dovizLabel = 'Dolar Yazdir';
                if ($dovizSembol == '€') {
                    $dovizIcon = 'fa-euro-sign';
                    $dovizLabel = 'Euro Yazdir';
                } elseif ($dovizSembol == '£') {
                    $dovizIcon = 'fa-sterling-sign';
                    $dovizLabel = 'Sterlin Yazdir';
                }

                // Status badge
                $statusClass = match ($ila['STATUS']) {
                    1 => 'status-onay',
                    4 => 'status-ok',
                    2 => 'status-iptal',
                    default => 'status-diger',
                };
                $statusLabel = match ($ila['STATUS']) {
                    1 => 'Onay Bekliyor',
                    4 => 'Onaylandı',
                    2 => 'İptal Edildi',
                    default => 'Diğer',
                };

                $salesmanKodu = $ila['SALESMAN_KODU'] ?? '-';
                $salesmanAdi  = $ila['SALESMAN_ADI'] ?? 'Tanımsız';
                ?>
                <div class="order-card swipe-card"
                    style="animation-delay:<?php echo min($cardIndex * 40, 280); ?>ms;"
                    data-logicalref="<?php echo (int)$ila['LOGICALREF']; ?>"
                    data-nettotal="<?php echo htmlspecialchars((string) ((float) ($ila['NETTOTAL'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?>"
                    data-hacim="<?php echo htmlspecialchars((string) ((float) ($ila['HACIM'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?>"
                    data-agirlik="<?php echo htmlspecialchars((string) ((float) ($ila['AGIRLIK'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?>">

                    <!-- HEADER: Fiş No + Tarih + Durum + Hızlı Yazdır -->
                    <div class="oc-header">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;min-width:0;">
                            <span class="oc-ficheno">
                                <i class="fa-solid fa-hashtag" style="color:var(--red,#ef4444);margin-right:2px;font-size:12px;"></i>
                                <span class="js-highlight-ficheno"><?php echo htmlspecialchars((string) $ila['FICHENO'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </span>
                            <?php if ($yazdirmaSayisi > 0): ?>
                                <span class="print-count-badge <?php echo $yazdirmaSayisi > 3 ? 'print-count-badge-many' : ''; ?>"
                                    title="<?php echo $yazdirmaSayisi; ?> kere yazdırıldı"
                                    <?php if (m_p_yetki($terminalkullanici, 'M22') == 1): ?>
                                    style="cursor:pointer;" onclick="yazdirmaDetayGoster(<?php echo $stokhareket; ?>)"
                                    <?php endif; ?>>
                                    <i class="fa-solid fa-print" style="font-size:9px;"></i>
                                    <?php echo $yazdirmaSayisi; ?>
                                </span>
                            <?php endif; ?>
                            <span class="oc-date">
                                <i class="fa-regular fa-calendar-alt" style="margin-right:3px;"></i><?php echo tarihcevir($ila['DATE_']); ?>
                            </span>
                        </div>
                        <div class="oc-header-right">
                            <span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                            <?php if ($hasDoviz): ?>
                                <span class="status-badge status-doviz"><i class="fa-solid <?php echo $dovizIcon; ?>" style="margin-right:3px;font-size:10px;"></i><?php echo htmlspecialchars($dovizSembol, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <a href="../yazdir/hizli_yazdir.php?fisno=<?php echo urlencode((string) $ila['FICHENO']); ?>"
                               class="btn-flat btn-red" style="padding:6px 10px;font-size:12px;" title="Hızlı Yazdır">
                                <i class="fa-solid fa-print"></i>
                            </a>
                        </div>
                    </div>

                    <!-- BODY: Müşteri + Fiyat + Meta -->
                    <div class="oc-body">
                        <!-- Müşteri Adı -->
                        <div class="oc-customer js-highlight-musteri" title="<?php echo e_tr($ila['DEFINITION_']); ?>">
                            <i class="fa-regular fa-building" style="color:#6366f1;margin-right:4px;font-size:13px;"></i>
                            <?php echo e_tr($ila['DEFINITION_']); ?>
                        </div>

                        <!-- Fiyat Kutusu -->
                        <div class="oc-price-box">
                            <div class="oc-price">
                                <?php echo paraformat($ila['NETTOTAL']); // lokal paraformat zaten '₺' ekler — ikonla çift ₺ oluyordu ?>
                            </div>
                            <?php if (isset($ila['TRCURR']) && $ila['TRCURR'] != '0' && isset($ila['TRRATE']) && $ila['TRRATE'] > 0):
                                $dovizSembolInner = dovizsembol_bul($ila['TRCURR']);
                                $netTotalDoviz = $ila['NETTOTAL'] / $ila['TRRATE'];
                                $dovizIconInner = 'fa-dollar-sign';
                                if ($dovizSembolInner == '€') { $dovizIconInner = 'fa-euro-sign'; }
                                elseif ($dovizSembolInner == '£') { $dovizIconInner = 'fa-sterling-sign'; }
                            ?>
                                <div class="oc-price-doviz">
                                    <i class="fa-solid <?php echo $dovizIconInner; ?>" style="margin-right:2px;"></i><?php echo paraformat($netTotalDoviz); ?>
                                    <span style="font-size:11px;color:var(--text-3);margin-left:3px;">(Kur: <?php echo number_format((float)$ila['TRRATE'], 4, ',', '.'); ?>)</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Durum + Ürün Sayısı -->
                        <div class="oc-meta">
                            <span title="<?php echo htmlspecialchars((string) $salesmanAdi, ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-id-badge" style="margin-right:2px;"></i>
                                <?php echo htmlspecialchars((string) $salesmanKodu, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span class="oc-meta-dot"></span>
                            <span><i class="fa-solid fa-box-open" style="margin-right:2px;color:#f59e0b;"></i><?php echo $eynes['T']; ?> ürün</span>
                            <?php if ((float)$ila['HACIM'] > 0): ?>
                                <span class="oc-meta-dot"></span>
                                <span><i class="fa-solid fa-cube" style="margin-right:2px;"></i><?php echo number_format((float)$ila['HACIM'], 2, ',', '.'); ?> m³</span>
                            <?php endif; ?>
                        </div>

                        <!-- Açıklama -->
                        <?php if (!empty($ila['GENEXP1'])): ?>
                            <div class="oc-note">
                                <i class="fa-solid fa-circle-info" style="margin-right:4px;color:#6366f1;"></i>
                                <?php echo e_tr($ila['GENEXP1']); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ((int)$ila['CLIENTREF'] === 2): // Sadece magaza satis ?>
                            <?php
                                $mevcutOdeme = strtoupper(trim((string)($ila['DOCODE'] ?? '')));
                                $odemeMetinleri = ['NAKIT' => 'Nakit', 'KART' => 'Kredi Karti', 'HAVALE' => 'Havale/EFT', 'KARMA' => 'Karma'];
                                $odemeIkonlari = ['NAKIT' => 'fa-wallet', 'KART' => 'fa-credit-card', 'HAVALE' => 'fa-building-columns', 'KARMA' => 'fa-shuffle'];
                                $secili = in_array($mevcutOdeme, ['NAKIT', 'KART', 'HAVALE', 'KARMA'], true);
                            ?>
                            <div class="odeme-secim <?php echo $secili ? 'odeme-collapsed' : ''; ?>" data-ref="<?php echo intcevir($ila['LOGICALREF']); ?>" data-secili="<?php echo $secili ? '1' : '0'; ?>">
                                <!-- Collapsed: sadece secili buton gorunur -->
                                <button type="button" class="odeme-chip odeme-chip-<?php echo strtolower($mevcutOdeme ?: 'nakit'); ?>" data-odeme-chip>
                                    <i class="fa-solid <?php echo $odemeIkonlari[$mevcutOdeme] ?? 'fa-wallet'; ?>"></i>
                                    <span><?php echo $odemeMetinleri[$mevcutOdeme] ?? 'Nakit'; ?></span>
                                    <i class="fa-solid fa-pen" style="font-size:10px;opacity:0.7;margin-left:4px;"></i>
                                </button>

                                <!-- Expanded: 4 buton + label -->
                                <div class="odeme-label"><i class="fa-solid fa-money-bill-wave"></i> Odeme Sekli</div>
                                <div class="odeme-btns">
                                    <button type="button" class="odeme-btn odeme-nakit <?php echo $mevcutOdeme === 'NAKIT' ? 'active' : ''; ?>" data-odeme="NAKIT">
                                        <i class="fa-solid fa-wallet"></i> Nakit
                                    </button>
                                    <button type="button" class="odeme-btn odeme-kart <?php echo $mevcutOdeme === 'KART' ? 'active' : ''; ?>" data-odeme="KART">
                                        <i class="fa-solid fa-credit-card"></i> Kart
                                    </button>
                                    <button type="button" class="odeme-btn odeme-havale <?php echo $mevcutOdeme === 'HAVALE' ? 'active' : ''; ?>" data-odeme="HAVALE">
                                        <i class="fa-solid fa-building-columns"></i> Havale
                                    </button>
                                    <button type="button" class="odeme-btn odeme-karma <?php echo $mevcutOdeme === 'KARMA' ? 'active' : ''; ?>" data-odeme="KARMA">
                                        <i class="fa-solid fa-shuffle"></i> Karma
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- FOOTER: Detay + Yazdır Dropdown -->
                    <div class="oc-footer">
                        <a href="lg_fis.php?stokhareket=<?php echo intcevir($ila['LOGICALREF']); ?>&return_to=<?php echo rawurlencode($lgEssiparisReturnUrl); ?>" class="btn-flat btn-indigo" style="flex:1;">
                            <i class="fa fa-list-alt"></i> Detay
                        </a>

                        <?php if (m_p_yetki($terminalkullanici, 'M18') == 1): ?>
                            <a href="../yazdir/fis_gecmis.php?fis=<?php echo intcevir($ila['LOGICALREF']); ?>&return_to=<?php echo rawurlencode($lgEssiparisReturnUrl); ?>" class="btn-flat btn-light" style="padding:10px 12px;" title="Geçmiş">
                                <i class="fa fa-history"></i>
                            </a>
                        <?php endif; ?>

                        <!-- Yazdır Dropdown -->
                        <div style="position:relative;flex:1;">
                            <button class="btn-flat btn-amber btn-yazdir-toggle" style="width:100%;">
                                <i class="fa fa-print"></i> Yazdır
                                <i class="fa fa-chevron-down" style="font-size:10px;margin-left:2px;"></i>
                            </button>
                            <div class="print-dropdown" style="display:none;">
                                <a href="../yazdir/hizli_yazdir.php?fisno=<?php echo urlencode((string) $ila['FICHENO']); ?>">
                                    <i class="fa-solid fa-bolt"></i> Hızlı Yazdır
                                </a>
                                <a href="../yazdir/hizli_yazdir.php?tercih=depo.frx&fisno=<?php echo urlencode((string) $ila['FICHENO']); ?>">
                                    <i class="fa-solid fa-warehouse"></i> Depo Yazdır
                                </a>
                                <a href="../yazdir/fisyazhtml.php?stokhareket=<?php echo intcevir($ila['LOGICALREF']); ?>&yazdir=yazdir&return_to=<?php echo rawurlencode($lgEssiparisReturnUrl); ?>">
                                    <i class="fa-solid fa-file-pdf"></i> PDF (Fiyatlı)
                                </a>
                                <a href="../yazdir/fisyazhtmlfiyatsiz.php?stokhareket=<?php echo intcevir($ila['LOGICALREF']); ?>&yazdir=yazdir&return_to=<?php echo rawurlencode($lgEssiparisReturnUrl); ?>">
                                    <i class="fa-solid fa-file"></i> PDF (Fiyatsız)
                                </a>
                                <?php if ($hasDoviz): ?>
                                <a href="../yazdir/hizli_yazdir.php?tercih=dovizli.frx&fisno=<?php echo urlencode((string) $ila['FICHENO']); ?>">
                                    <i class="fa-solid <?php echo $dovizIcon; ?>"></i> <?php echo htmlspecialchars($dovizLabel, ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                                <?php endif; ?>
                                <div class="print-dropdown-sep"></div>
                                <a href="../yazdir/yeni_dizayn.php?stokhareket=<?php echo intcevir($ila['LOGICALREF']); ?>&tipdurum=1">
                                    <i class="fa-solid fa-print"></i> Normal Yazdır
                                </a>
                                <?php if ($hasDoviz): ?>
                                <a href="../yazdir/fisyazdoviz.php?stokhareket=<?php echo intcevir($ila['LOGICALREF']); ?>&yazdir=yazdir">
                                    <i class="fa-solid fa-money-bill-wave"></i> Dövizli Fiyat Listesi
                                </a>
                                <?php endif; ?>
                                <div class="print-dropdown-sep"></div>
                                <a href="lg_siparis.php?stokhareket=<?php echo intcevir($ila['LOGICALREF']); ?>&sipariskaydet">
                                    <i class="fa-solid fa-save"></i> Kaydet
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php
                $cardIndex++;
                endforeach;
                ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div style="margin-top:32px;display:flex;flex-direction:column;align-items:center;gap:14px;">
                <p style="font-size:13px;color:var(--text-3);">
                    <strong style="color:var(--text-1);"><?php echo number_format($totalOrders, 0, ',', '.'); ?></strong> siparisten
                    <strong style="color:var(--text-1);"><?php echo number_format($offset + 1, 0, ',', '.'); ?>-<?php echo number_format(min($offset + $pageSize, $totalOrders), 0, ',', '.'); ?></strong> arasi gosteriliyor
                </p>
                <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:6px;">
                    <?php
                    $pagingBase = $baseQueryParams;
                    if ($sortField !== '') {
                        $pagingBase['sort'] = $sortField;
                        $pagingBase['order'] = $sortOrder;
                    }

                    // Onceki sayfa
                    if ($currentPage > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($pagingBase, ['page' => $currentPage - 1])); ?>"
                           class="paging-btn">
                            <i class="fa-solid fa-chevron-left" style="margin-right:4px;font-size:11px;"></i> Onceki
                        </a>
                    <?php endif;

                    // Sayfa numaralari
                    $startPage = max(1, $currentPage - 2);
                    $endPage = min($totalPages, $currentPage + 2);

                    if ($startPage > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($pagingBase, ['page' => 1])); ?>"
                           class="paging-btn">1</a>
                        <?php if ($startPage > 2): ?>
                            <span style="color:var(--text-3);padding:0 4px;">...</span>
                        <?php endif;
                    endif;

                    for ($p = $startPage; $p <= $endPage; $p++): ?>
                        <a href="?<?php echo http_build_query(array_merge($pagingBase, ['page' => $p])); ?>"
                           class="paging-btn <?php echo $p === $currentPage ? 'paging-btn-active' : ''; ?>">
                            <?php echo $p; ?>
                        </a>
                    <?php endfor;

                    if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                            <span style="color:var(--text-3);padding:0 4px;">...</span>
                        <?php endif; ?>
                        <a href="?<?php echo http_build_query(array_merge($pagingBase, ['page' => $totalPages])); ?>"
                           class="paging-btn"><?php echo $totalPages; ?></a>
                    <?php endif;

                    // Sonraki sayfa
                    if ($currentPage < $totalPages): ?>
                        <a href="?<?php echo http_build_query(array_merge($pagingBase, ['page' => $currentPage + 1])); ?>"
                           class="paging-btn">
                            Sonraki <i class="fa-solid fa-chevron-right" style="margin-left:4px;font-size:11px;"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>

    </main>

    <!-- Print Detail Modal -->
    <div id="yazdirmaModal" class="hidden">
        <div class="modal-overlay" onclick="if(event.target===this)modalKapat();">
            <div class="modal-box">
                <div class="modal-header">
                    <h3><i class="fa-solid fa-print"></i> Yazdırma Geçmişi</h3>
                    <button onclick="modalKapat()" class="modal-close">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                <div id="modalIcerik" class="modal-body">
                    <div style="display:flex;align-items:center;justify-content:center;padding:32px 0;">
                        <i class="fa-solid fa-spinner fa-spin fa-2x" style="color:#6366f1;"></i>
                        <span style="margin-left:12px;color:var(--text-2);">Yukleniyor...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Render bug fix: Bazen backdrop-filter + animation kombinasyonu
        // initial render'da tetiklenmiyor, elementler gorunmez kaliyordu.
        // requestAnimationFrame ile bir reflow force ediyoruz.
        (function() {
            function forceReflow() {
                // Animasyonu olan elementleri bul, reflow tetikle
                var selectors = '.filter-panel, .summary-card, .order-card, .empty-state';
                document.querySelectorAll(selectors).forEach(function(el) {
                    void el.offsetHeight; // force reflow
                });
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    requestAnimationFrame(forceReflow);
                });
            } else {
                requestAnimationFrame(forceReflow);
            }
        })();

        // Odeme sekli isaretleme (magaza satis)
        (function() {
            var csrfToken = '<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>';

            var odemeMetin = { 'NAKIT': 'Nakit', 'KART': 'Kredi Karti', 'HAVALE': 'Havale/EFT', 'KARMA': 'Karma' };
            var odemeIkon = { 'NAKIT': 'fa-wallet', 'KART': 'fa-credit-card', 'HAVALE': 'fa-building-columns', 'KARMA': 'fa-shuffle' };

            function renderChip(wrap, odeme) {
                var chip = wrap.querySelector('[data-odeme-chip]');
                if (!chip) return;
                chip.className = 'odeme-chip odeme-chip-' + odeme.toLowerCase();
                chip.innerHTML = '<i class="fa-solid ' + odemeIkon[odeme] + '"></i>' +
                    '<span>' + odemeMetin[odeme] + '</span>' +
                    '<i class="fa-solid fa-pen" style="font-size:10px;opacity:0.7;margin-left:4px;"></i>';
            }

            document.addEventListener('click', function(e) {
                // Chip'e tiklandiginda aç (expand)
                var chip = e.target.closest('[data-odeme-chip]');
                if (chip) {
                    var wrap = chip.closest('.odeme-secim');
                    if (wrap) wrap.classList.remove('odeme-collapsed');
                    return;
                }

                // Buton secimi
                var btn = e.target.closest('.odeme-btn');
                if (!btn) return;

                var wrap = btn.closest('.odeme-secim');
                if (!wrap) return;

                var fisRef = wrap.dataset.ref;
                var odeme = btn.dataset.odeme;
                var allBtns = wrap.querySelectorAll('.odeme-btn');

                // Ayni butona tekrar tiklanirsa temizle
                var isActive = btn.classList.contains('active');
                var yeniOdeme = isActive ? '' : odeme;

                allBtns.forEach(function(b) { b.disabled = true; });

                var fd = new FormData();
                fd.append('fis_ref', fisRef);
                fd.append('odeme', yeniOdeme);
                fd.append('csrf_token', csrfToken);

                fetch('ajax_odeme_sekli.php', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.ok) {
                            allBtns.forEach(function(b) { b.classList.remove('active'); });
                            if (yeniOdeme !== '') {
                                btn.classList.add('active');
                                // Seçim yapildi, chip'i guncelle ve collapse
                                renderChip(wrap, yeniOdeme);
                                wrap.classList.add('odeme-collapsed');
                                if (window.toast) { toast('Ödeme şekli güncellendi'); }
                            } else {
                                // Secim temizlendi, collapsed degil expanded kalsin
                                wrap.classList.remove('odeme-collapsed');
                                if (window.toast) { toast('Ödeme şekli kaldırıldı', 'info'); }
                            }
                        } else {
                            if (window.toast) { toast(data.msg || 'İşlem başarısız', 'error'); } else { alert('Hata: ' + (data.msg || 'Bilinmeyen hata')); }
                        }
                    })
                    .catch(function() {
                        if (window.toast) { toast('Bağlantı hatası', 'error'); } else { alert('Baglanti hatasi'); }
                    })
                    .finally(function() {
                        allBtns.forEach(function(b) { b.disabled = false; });
                    });
            });
        })();

        // URL'deki "vurgula" parametresiyle kart highlight + scroll
        // Not: Sadece TEK sipariş varsa calisir. Birden fazlaysa vurgulama yok,
        //      sadece siparisler sayfasi acilir.
        (function() {
            var params = new URLSearchParams(window.location.search);
            var vurgulaRaw = params.get('vurgula');
            if (!vurgulaRaw) return;

            var ids = vurgulaRaw.split(',').map(function(s) { return s.trim(); }).filter(Boolean);
            if (ids.length !== 1) return; // Sadece tek siparis icin vurgulama

            var tekId = ids[0];

            window.addEventListener('load', function() {
                setTimeout(function() {
                    var card = document.querySelector('.order-card[data-logicalref="' + tekId + '"]');
                    if (!card) {
                        console.warn('Vurgulanacak siparis bu sayfada bulunamadi: ' + tekId);
                        return;
                    }

                    // Scroll
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });

                    // Scroll'un bitmesini bekle, sonra vurgula
                    setTimeout(function() {
                        var badge = document.createElement('div');
                        badge.className = 'highlight-badge';
                        badge.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i>Burada!';
                        card.appendChild(badge);
                        card.classList.add('highlight-target');
                    }, 500);

                    // 6 saniye sonra kibarca fade-out
                    setTimeout(function() {
                        card.classList.add('highlight-fading');
                        setTimeout(function() {
                            card.classList.remove('highlight-target', 'highlight-fading');
                            var badge = card.querySelector('.highlight-badge');
                            if (badge) badge.remove();
                        }, 900);
                    }, 6500);
                }, 300);
            });
        })();

        // Yazdir dropdown toggle (pure JS, no Alpine dependency)
        (function() {
            var openDropdown = null;

            document.addEventListener('click', function(e) {
                var toggleBtn = e.target.closest('.btn-yazdir-toggle');

                // Toggle butonuna basildi
                if (toggleBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    var dd = toggleBtn.parentElement.querySelector('.print-dropdown');
                    if (!dd) return;

                    // Acik olan baska dropdown varsa kapat
                    if (openDropdown && openDropdown !== dd) {
                        openDropdown.style.display = 'none';
                        openDropdown.closest('.order-card').style.zIndex = '';
                    }

                    if (dd.style.display === 'none' || dd.style.display === '') {
                        // Mobilde fixed positioning icin top hesapla
                        if (window.innerWidth < 768) {
                            var btnRect = toggleBtn.getBoundingClientRect();
                            dd.style.top = btnRect.bottom + 6 + 'px';
                        }
                        dd.style.display = 'block';
                        dd.closest('.order-card').style.zIndex = '99';
                        openDropdown = dd;
                    } else {
                        dd.style.display = 'none';
                        dd.closest('.order-card').style.zIndex = '';
                        openDropdown = null;
                    }
                    return;
                }

                // Dropdown disina tiklaninca kapat
                if (openDropdown && !e.target.closest('.print-dropdown')) {
                    openDropdown.style.display = 'none';
                    openDropdown.closest('.order-card').style.zIndex = '';
                    openDropdown = null;
                }
            });
        })();

        // Mobile filter toggle
        (function() {
            var mobileFilterToggle = document.getElementById('mobileFilterToggle');
            var mobileFilterPanel = document.getElementById('mobileFilterPanel');

            if (mobileFilterToggle && mobileFilterPanel) {
                mobileFilterToggle.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        var isHidden = mobileFilterPanel.style.display === 'none' || mobileFilterPanel.style.display === '';
                        mobileFilterPanel.style.display = isHidden ? 'flex' : 'none';
                    }
                });
            }

            // Enter to submit search
            var searchInput = document.getElementById('barkod');
            if (searchInput) {
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        document.getElementById('filterForm').submit();
                    }
                });
            }
        })();

        // Print detail modal
        function yazdirmaDetayGoster(fisRef) {
            document.getElementById('yazdirmaModal').classList.remove('hidden');
            document.getElementById('modalIcerik').innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:32px 0;"><i class="fa-solid fa-spinner fa-spin fa-2x" style="color:#6366f1;"></i><span style="margin-left:12px;color:#6b7280;">Yukleniyor...</span></div>';

            fetch('../yazdir/yazdirma_detay.php?fis=' + fisRef)
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success && data.data.length > 0) {
                        var html = '<div style="display:flex;flex-direction:column;gap:10px;">';
                        data.data.forEach(function(item, index) {
                            html += '<div style="border:1px solid #e5e7eb;border-radius:12px;padding:14px;transition:box-shadow 0.2s;">';
                            html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">';
                            html += '<div style="display:flex;align-items:center;gap:10px;">';
                            html += '<span style="display:flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:8px;background:#eef2ff;color:#4f46e5;font-weight:700;font-size:13px;">' + (index + 1) + '</span>';
                            html += '<div>';
                            html += '<p style="font-weight:600;color:#1f2937;font-size:14px;">' + item.kullanici + '</p>';
                            html += '<p style="font-size:11px;color:#9ca3af;">Kod: ' + item.kullanici_kodu + '</p>';
                            html += '</div>';
                            html += '</div>';
                            html += '<div style="text-align:right;">';
                            html += '<p style="font-size:13px;font-weight:500;color:#6b7280;"><i class="fa-regular fa-calendar" style="margin-right:4px;"></i>' + item.tarih + '</p>';
                            html += '</div>';
                            html += '</div>';
                            html += '<div style="margin-top:8px;padding-top:8px;border-top:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;font-size:13px;">';
                            html += '<span style="color:#6b7280;"><i class="fa-solid fa-file" style="margin-right:4px;"></i>' + item.dizayn + '</span>';
                            html += '<span style="padding:3px 10px;background:#f1f5f9;border-radius:8px;color:#475569;font-weight:500;"><i class="fa-solid fa-copy" style="margin-right:4px;"></i>' + item.miktar + ' kopya</span>';
                            html += '</div>';
                            html += '</div>';
                        });
                        html += '</div>';
                        html += '<div style="margin-top:20px;padding:14px;background:#eef2ff;border-radius:12px;text-align:center;">';
                        html += '<p style="color:#4f46e5;font-weight:600;font-size:14px;"><i class="fa-solid fa-info-circle" style="margin-right:6px;"></i>Toplam ' + data.toplam + ' yazdirma kaydi bulundu</p>';
                        html += '</div>';
                        document.getElementById('modalIcerik').innerHTML = html;
                    } else {
                        document.getElementById('modalIcerik').innerHTML = '<div style="text-align:center;padding:32px 0;"><i class="fa-solid fa-inbox fa-3x" style="color:#d1d5db;display:block;margin-bottom:14px;"></i><p style="color:#6b7280;">Henuz yazdirma kaydi yok</p></div>';
                    }
                })
                .catch(function(error) {
                    document.getElementById('modalIcerik').innerHTML = '<div style="text-align:center;padding:32px 0;color:var(--red,#6F1022);"><i class="fa-solid fa-exclamation-triangle fa-2x" style="display:block;margin-bottom:14px;"></i><p>Bir hata olustu: ' + error.message + '</p></div>';
                });
        }

        function modalKapat() {
            document.getElementById('yazdirmaModal').classList.add('hidden');
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                modalKapat();
            }
        });

        // =============================================
        // Pull to Refresh (Mobile)
        // =============================================
        (function() {
            var pullIndicator = document.getElementById('pullIndicator');
            var pullIcon = document.getElementById('pullIcon');
            var startY = 0;
            var pulling = false;
            var threshold = 80;

            document.addEventListener('touchstart', function(e) {
                if (window.scrollY === 0) {
                    startY = e.touches[0].clientY;
                    pulling = true;
                }
            }, { passive: true });

            document.addEventListener('touchmove', function(e) {
                if (!pulling) return;
                var currentY = e.touches[0].clientY;
                var diff = currentY - startY;

                if (diff > 0 && window.scrollY === 0) {
                    pullIndicator.classList.add('visible');
                    if (diff > threshold) {
                        pullIcon.classList.remove('fa-arrow-down');
                        pullIcon.classList.add('fa-check');
                    } else {
                        pullIcon.classList.remove('fa-check');
                        pullIcon.classList.add('fa-arrow-down');
                    }
                }
            }, { passive: true });

            document.addEventListener('touchend', function(e) {
                if (!pulling) return;
                var endY = e.changedTouches[0].clientY;
                var diff = endY - startY;

                if (diff > threshold && window.scrollY === 0) {
                    pullIndicator.classList.add('refreshing');
                    pullIcon.classList.remove('fa-arrow-down', 'fa-check');
                    pullIcon.classList.add('fa-spinner', 'spinner');
                    setTimeout(function() { location.reload(); }, 500);
                } else {
                    pullIndicator.classList.remove('visible');
                }
                pulling = false;
            }, { passive: true });
        })();

        // =============================================
        // Swipe Actions (Mobile)
        // =============================================
        (function() {
            if (window.innerWidth > 768) return;

            document.querySelectorAll('.swipe-card').forEach(function(card) {
                var startX = 0;
                var startY = 0;
                var currentX = 0;
                var isHorizontalSwipe = null;
                var isSwiping = false;

                var content = card;
                var detayLinkEl = card.querySelector('a[href*="lg_fis.php"]');
                var yazdirLinkEl = card.querySelector('a[href*="yazdir/yeni_dizayn.php"]');
                var detayLink = detayLinkEl ? detayLinkEl.href : null;
                var yazdirLink = yazdirLinkEl ? yazdirLinkEl.href : null;

                card.addEventListener('touchstart', function(e) {
                    startX = e.touches[0].clientX;
                    startY = e.touches[0].clientY;
                    currentX = startX;
                    isSwiping = true;
                    isHorizontalSwipe = null;
                    content.style.transition = 'none';
                }, { passive: true });

                card.addEventListener('touchmove', function(e) {
                    if (!isSwiping) return;

                    var touchX = e.touches[0].clientX;
                    var touchY = e.touches[0].clientY;
                    var diffX = Math.abs(touchX - startX);
                    var diffY = Math.abs(touchY - startY);

                    if (isHorizontalSwipe === null && (diffX > 10 || diffY > 10)) {
                        isHorizontalSwipe = diffX > diffY * 1.5;
                    }

                    if (isHorizontalSwipe === true) {
                        currentX = touchX;
                        var diff = currentX - startX;
                        if (Math.abs(diff) < 120) {
                            content.style.transform = 'translateX(' + diff + 'px)';
                        }
                    }
                }, { passive: true });

                card.addEventListener('touchend', function(e) {
                    if (!isSwiping) return;
                    isSwiping = false;
                    content.style.transition = 'transform 0.2s ease';

                    if (isHorizontalSwipe !== true) {
                        content.style.transform = 'translateX(0)';
                        return;
                    }

                    var diff = currentX - startX;

                    if (diff > 80 && detayLink) {
                        content.style.transform = 'translateX(100%)';
                        setTimeout(function() { window.location.href = detayLink; }, 200);
                    } else if (diff < -80 && yazdirLink) {
                        content.style.transform = 'translateX(-100%)';
                        setTimeout(function() { window.location.href = yazdirLink; }, 200);
                    } else {
                        content.style.transform = 'translateX(0)';
                    }
                }, { passive: true });
            });
        })();
    </script>
    <?php include_once(__DIR__ . '/../ux_katman.php'); ?>
</body>

</html>
