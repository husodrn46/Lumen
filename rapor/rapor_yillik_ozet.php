<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

// Yetki kontrolü — rapor sayfası: M17 (diğer tüm raporlarla aynı;
// eskiden yanlışlıkla M1 "Yeni Sipariş" kontrol ediliyordu)
if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// Yıl seçimi
$selectedYear = (isset($_GET['year']) && is_numeric($_GET['year'])) ? (int) $_GET['year'] : (int) date('Y');
$previousYear = $selectedYear - 1;

// Tarih aralıkları
$currentYearStart = "$selectedYear-01-01";
$currentYearEnd = "$selectedYear-12-31";
$previousYearStart = "$previousYear-01-01";
$previousYearEnd = "$previousYear-12-31";

// =============================================================================
// 1. ANA KPI'LAR
// =============================================================================

$stmtYearSales = $dbh->prepare("
	    SELECT
	        ISNULL(SUM(CASE WHEN L.TRCODE IN (7,8)
	                        THEN ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)
	                        ELSE -(ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)) END), 0) AS TOTAL_SALES,
	        COUNT(DISTINCT CASE WHEN I.TRCODE IN (7,8) THEN I.LOGICALREF END) AS ORDER_COUNT,
	        COUNT(DISTINCT CASE WHEN I.TRCODE IN (7,8) THEN I.CLIENTREF END) AS CUSTOMER_COUNT,
	        AVG(CASE WHEN I.TRCODE IN (7,8) THEN I.NETTOTAL END) AS AVG_ORDER_VALUE
	    FROM {$firmadonem}INVOICE I
	    JOIN {$firmadonem}STLINE L ON L.INVOICEREF = I.LOGICALREF AND L.TRCODE IN (2,3,7,8)
	    JOIN {$firma}ITEMS IT ON IT.LOGICALREF = L.STOCKREF
	    WHERE I.TRCODE IN (2,3,7,8)
	    AND I.DATE_ >= :startDate
	    AND I.DATE_ <= :endDate
	    AND I.CANCELLED = 0
	    AND IT.CODE LIKE 'AKL%'
	");
// PDO_SQLSRV: ayni statement'i yeniden calistirmadan once result set'in TAMAMI
// tuketilmeli (fetchAll) — fetch+closeCursor "Invalid cursor state" (24000) veriyor.
$stmtYearSales->execute([':startDate' => $currentYearStart, ':endDate' => $currentYearEnd]);
$currentYearSales = $stmtYearSales->fetchAll(PDO::FETCH_ASSOC)[0] ?? [];

$stmtYearSales->execute([':startDate' => $previousYearStart, ':endDate' => $previousYearEnd]);
$previousYearSales = $stmtYearSales->fetchAll(PDO::FETCH_ASSOC)[0] ?? [];

$currentYearSales = [
    'TOTAL_SALES' => (float) ($currentYearSales['TOTAL_SALES'] ?? 0),
    'ORDER_COUNT' => (int) ($currentYearSales['ORDER_COUNT'] ?? 0),
    'CUSTOMER_COUNT' => (int) ($currentYearSales['CUSTOMER_COUNT'] ?? 0),
    'AVG_ORDER_VALUE' => (float) ($currentYearSales['AVG_ORDER_VALUE'] ?? 0),
];

$previousYearSales = [
    'TOTAL_SALES' => (float) ($previousYearSales['TOTAL_SALES'] ?? 0),
    'ORDER_COUNT' => (int) ($previousYearSales['ORDER_COUNT'] ?? 0),
    'CUSTOMER_COUNT' => (int) ($previousYearSales['CUSTOMER_COUNT'] ?? 0),
    'AVG_ORDER_VALUE' => (float) ($previousYearSales['AVG_ORDER_VALUE'] ?? 0),
];

$salesChange = $previousYearSales['TOTAL_SALES'] > 0
    ? (($currentYearSales['TOTAL_SALES'] - $previousYearSales['TOTAL_SALES']) / $previousYearSales['TOTAL_SALES']) * 100
    : 0;

$orderCountChange = $previousYearSales['ORDER_COUNT'] > 0
    ? (($currentYearSales['ORDER_COUNT'] - $previousYearSales['ORDER_COUNT']) / $previousYearSales['ORDER_COUNT']) * 100
    : 0;

$customerChange = $previousYearSales['CUSTOMER_COUNT'] > 0
    ? (($currentYearSales['CUSTOMER_COUNT'] - $previousYearSales['CUSTOMER_COUNT']) / $previousYearSales['CUSTOMER_COUNT']) * 100
    : 0;

$avgOrderChange = $previousYearSales['AVG_ORDER_VALUE'] > 0
    ? (($currentYearSales['AVG_ORDER_VALUE'] - $previousYearSales['AVG_ORDER_VALUE']) / $previousYearSales['AVG_ORDER_VALUE']) * 100
    : 0;

// =============================================================================
// 2. AYLIK CİRO
// =============================================================================

$stmtMonthlySales = $dbh->prepare("
	      SELECT ISNULL(SUM(CASE WHEN L.TRCODE IN (7,8)
	                             THEN ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)
	                             ELSE -(ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)) END), 0) AS TOTAL
	      FROM {$firmadonem}INVOICE I
	      JOIN {$firmadonem}STLINE L ON L.INVOICEREF = I.LOGICALREF AND L.TRCODE IN (2,3,7,8)
	      JOIN {$firma}ITEMS IT ON IT.LOGICALREF = L.STOCKREF
	      WHERE I.CANCELLED = 0
	        AND YEAR(I.DATE_) = :year
	        AND MONTH(I.DATE_) = :month
	        AND IT.CODE LIKE 'AKL%'
	    ");

$monthlySales = [];
for ($month = 1; $month <= 12; $month++) {
    $monthStr = str_pad((string) $month, 2, '0', STR_PAD_LEFT);

    $stmtMonthlySales->execute([':year' => $selectedYear, ':month' => $month]);
    $currentMonthSales = $stmtMonthlySales->fetchAll(PDO::FETCH_ASSOC)[0] ?? [];

    $stmtMonthlySales->execute([':year' => $previousYear, ':month' => $month]);
    $previousMonthSales = $stmtMonthlySales->fetchAll(PDO::FETCH_ASSOC)[0] ?? [];

    $monthlySales[] = [
        'month' => $monthStr,
        'current' => (float) ($currentMonthSales['TOTAL'] ?? 0),
        'previous' => (float) ($previousMonthSales['TOTAL'] ?? 0),
    ];
}

// =============================================================================
// 3. ÇEYREKSEL PERFORMANS
// =============================================================================

$quarters = [
    'Q1' => ['start' => '01-01', 'end' => '03-31', 'label' => 'Ç1 (Oca-Mar)'],
    'Q2' => ['start' => '04-01', 'end' => '06-30', 'label' => 'Ç2 (Nis-Haz)'],
    'Q3' => ['start' => '07-01', 'end' => '09-30', 'label' => 'Ç3 (Tem-Eyl)'],
    'Q4' => ['start' => '10-01', 'end' => '12-31', 'label' => 'Ç4 (Eki-Ara)']
];

$quarterlyData = [];
$stmtQuarterSales = $dbh->prepare("
	        SELECT ISNULL(SUM(CASE WHEN I.TRCODE IN (7,8) THEN I.NETTOTAL ELSE -I.NETTOTAL END), 0) AS TOTAL
	        FROM {$firmadonem}INVOICE I
	        WHERE I.TRCODE IN (2,3,7,8)
	        AND I.DATE_ >= :startDate
	        AND I.DATE_ <= :endDate
	        AND I.CANCELLED = 0
	    ");
foreach ($quarters as $qKey => $qInfo) {
    $qStart = $selectedYear . '-' . $qInfo['start'];
    $qEnd = $selectedYear . '-' . $qInfo['end'];
    $stmtQuarterSales->execute([':startDate' => $qStart, ':endDate' => $qEnd]);
    $currentQ = $stmtQuarterSales->fetchAll(PDO::FETCH_ASSOC)[0] ?? [];

    $pQStart = $previousYear . '-' . $qInfo['start'];
    $pQEnd = $previousYear . '-' . $qInfo['end'];
    $stmtQuarterSales->execute([':startDate' => $pQStart, ':endDate' => $pQEnd]);
    $previousQ = $stmtQuarterSales->fetchAll(PDO::FETCH_ASSOC)[0] ?? [];

    $currentQuarterTotal = (float) ($currentQ['TOTAL'] ?? 0);
    $previousQuarterTotal = (float) ($previousQ['TOTAL'] ?? 0);

    $quarterlyData[$qKey] = [
        'label' => $qInfo['label'],
        'current' => $currentQuarterTotal,
        'previous' => $previousQuarterTotal,
        'change' => $previousQuarterTotal > 0
            ? (($currentQuarterTotal - $previousQuarterTotal) / $previousQuarterTotal) * 100
            : 0,
    ];
}

// =============================================================================
// 4. EN ÇOK SATAN 10 ÜRÜN
// =============================================================================

$stmtTopProducts = $dbh->prepare("
	    SELECT TOP 10
	        IT.CODE AS PRODUCT_CODE,
	        IT.NAME AS PRODUCT_NAME,
	        SUM(CASE WHEN L.TRCODE IN (7,8) THEN L.AMOUNT ELSE -L.AMOUNT END) AS TOTAL_QTY,
	        SUM(CASE WHEN L.TRCODE IN (7,8)
	                 THEN ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)
	                 ELSE -(ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)) END) AS TOTAL_SALES,
	        AVG(CASE WHEN L.TRCODE IN (7,8) THEN L.PRICE END) AS AVG_PRICE
	    FROM {$firmadonem}STLINE L
	    JOIN {$firma}ITEMS IT ON IT.LOGICALREF = L.STOCKREF
	    JOIN {$firmadonem}INVOICE I ON I.LOGICALREF = L.INVOICEREF
	    WHERE L.TRCODE IN (2,3,7,8)
	    AND I.DATE_ >= :startDate
	    AND I.DATE_ <= :endDate
	    AND I.CANCELLED = 0
	    AND L.LINETYPE = 0
	    AND IT.CODE LIKE 'AKL%'
	    GROUP BY IT.CODE, IT.NAME
	    ORDER BY SUM(CASE WHEN L.TRCODE IN (7,8)
	                      THEN ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)
	                      ELSE -(ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)) END) DESC
	");
$stmtTopProducts->execute([':startDate' => $currentYearStart, ':endDate' => $currentYearEnd]);
$topProducts = $stmtTopProducts->fetchAll(PDO::FETCH_ASSOC);
foreach ($topProducts as $idx => $product) {
    $topProducts[$idx]['PRODUCT_CODE'] = (string) ($product['PRODUCT_CODE'] ?? '');
    $topProducts[$idx]['PRODUCT_NAME'] = (string) ($product['PRODUCT_NAME'] ?? '');
    $topProducts[$idx]['TOTAL_QTY'] = (float) ($product['TOTAL_QTY'] ?? 0);
    $topProducts[$idx]['TOTAL_SALES'] = (float) ($product['TOTAL_SALES'] ?? 0);
    $topProducts[$idx]['AVG_PRICE'] = (float) ($product['AVG_PRICE'] ?? 0);
}

// =============================================================================
// 5. EN DEĞERLİ 10 MÜŞTERİ
// =============================================================================

$stmtTopCustomers = $dbh->prepare("
	    SELECT TOP 10
	        C.CODE AS CUSTOMER_CODE,
	        C.DEFINITION_ AS CUSTOMER_NAME,
	        COUNT(DISTINCT CASE WHEN I.TRCODE IN (7,8) THEN I.LOGICALREF END) AS ORDER_COUNT,
	        SUM(CASE WHEN L.TRCODE IN (7,8)
	                 THEN ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)
	                 ELSE -(ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)) END) AS TOTAL_SALES
	    FROM {$firmadonem}INVOICE I
	    JOIN {$firmadonem}STLINE L ON L.INVOICEREF = I.LOGICALREF AND L.TRCODE IN (2,3,7,8)
	    JOIN {$firma}ITEMS IT ON IT.LOGICALREF = L.STOCKREF
	    LEFT JOIN {$firma}CLCARD C ON C.LOGICALREF = I.CLIENTREF
	    WHERE I.TRCODE IN (2,3,7,8)
	    AND I.DATE_ >= :startDate
	    AND I.DATE_ <= :endDate
	    AND I.CANCELLED = 0
	    AND IT.CODE LIKE 'AKL%'
	    GROUP BY C.CODE, C.DEFINITION_
	    ORDER BY SUM(CASE WHEN L.TRCODE IN (7,8)
	                      THEN ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)
	                      ELSE -(ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)) END) DESC
	");
$stmtTopCustomers->execute([':startDate' => $currentYearStart, ':endDate' => $currentYearEnd]);
$topCustomers = $stmtTopCustomers->fetchAll(PDO::FETCH_ASSOC);
foreach ($topCustomers as $idx => $customer) {
    $topCustomers[$idx]['CUSTOMER_CODE'] = (string) ($customer['CUSTOMER_CODE'] ?? '');
    $topCustomers[$idx]['CUSTOMER_NAME'] = (string) ($customer['CUSTOMER_NAME'] ?? '');
    $topCustomers[$idx]['ORDER_COUNT'] = (int) ($customer['ORDER_COUNT'] ?? 0);
    $topCustomers[$idx]['TOTAL_SALES'] = (float) ($customer['TOTAL_SALES'] ?? 0);
}

// =============================================================================
// 6. YENİ MÜŞTERİ KAZANIMI
// =============================================================================

$stmtNewCustomers = $dbh->prepare("
	    SELECT COUNT(DISTINCT C.LOGICALREF) AS NEW_CUSTOMER_COUNT
	    FROM {$firma}CLCARD C
	    WHERE C.LOGICALREF IN (
	        SELECT DISTINCT I.CLIENTREF
	        FROM {$firmadonem}INVOICE I
	        JOIN {$firmadonem}STLINE L ON L.INVOICEREF = I.LOGICALREF AND L.TRCODE IN (7,8)
	        JOIN {$firma}ITEMS IT ON IT.LOGICALREF = L.STOCKREF
	        WHERE I.TRCODE IN (7,8)
	        AND I.DATE_ >= :startDate
	        AND I.DATE_ <= :endDate
	        AND I.CANCELLED = 0
	        AND IT.CODE LIKE 'AKL%'
	        AND I.CLIENTREF NOT IN (
	            SELECT DISTINCT I2.CLIENTREF
	            FROM {$firmadonem}INVOICE I2
	            JOIN {$firmadonem}STLINE L2 ON L2.INVOICEREF = I2.LOGICALREF AND L2.TRCODE IN (7,8)
	            JOIN {$firma}ITEMS IT2 ON IT2.LOGICALREF = L2.STOCKREF
	            WHERE I2.TRCODE IN (7,8)
	            AND I2.DATE_ < :cutoffDate
	            AND I2.CANCELLED = 0
	            AND IT2.CODE LIKE 'AKL%'
	        )
	    )
	");
$stmtNewCustomers->execute([':startDate' => $currentYearStart, ':endDate' => $currentYearEnd, ':cutoffDate' => $currentYearStart]);
$newCustomers = $stmtNewCustomers->fetch(PDO::FETCH_ASSOC) ?: [];
$newCustomers['NEW_CUSTOMER_COUNT'] = (int) ($newCustomers['NEW_CUSTOMER_COUNT'] ?? 0);

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $selectedYear; ?> Yıllık Özet Raporu</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
            border-bottom: 1px solid rgba(124, 58, 237, 0.18);
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.04);
        }
        .header-inner {
            max-width: 1200px; margin: 0 auto;
            padding: 14px 24px;
            display: flex; align-items: center; gap: 14px;
            flex-wrap: wrap;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; min-width: 36px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
        }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--purple); }
        .header-divider { width: 1px; height: 24px; background: var(--border); }
        .header-title { min-width: 0; flex: 1 1 auto; }
        .header-title h1 {
            font-size: 16px; font-weight: 700; color: var(--text-1);
            display: flex; align-items: center; gap: 8px; margin: 0;
        }
        .header-title h1 .material-icons { color: var(--purple); font-size: 18px; }
        .header-title p {
            margin: 2px 0 0; font-size: 11.5px; color: var(--text-2); font-weight: 500;
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
            border-color: rgba(124, 58, 237, 0.5);
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }
        .btn-ghost-print {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 10px 14px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 12.5px; font-weight: 600;
            border-radius: 10px; min-height: 44px;
            background: var(--purple); color: #fff;
            border: none; cursor: pointer;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.22);
            transition: all 0.2s ease;
        }
        .btn-ghost-print:hover { background: #6d28d9; transform: translateY(-1px); }
        .btn-ghost-print .material-icons { font-size: 16px; }

        main {
            max-width: 1200px; margin: 0 auto;
            padding: 20px 24px 40px;
        }

        .year-badge {
            display: inline-flex; align-items: center; gap: 8px;
            margin-bottom: 16px;
            font-size: 12px; color: var(--text-2); font-weight: 500;
        }
        .year-badge strong {
            display: inline-block;
            padding: 4px 12px;
            background: var(--purple-soft); color: var(--purple);
            border-radius: 999px;
            font-weight: 700; font-size: 12.5px;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
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
            padding: 18px 18px;
            position: relative; overflow: hidden;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .stat-card:nth-child(2) { animation-delay: 60ms; }
        .stat-card:nth-child(3) { animation-delay: 120ms; }
        .stat-card:nth-child(4) { animation-delay: 180ms; }
        .stat-card::before {
            content: ''; position: absolute;
            top: 0; left: 0; bottom: 0;
            width: 4px;
        }
        .stat-card.sky::before     { background: var(--sky); }
        .stat-card.emerald::before { background: var(--emerald); }
        .stat-card.purple::before  { background: var(--purple); }
        .stat-card.amber::before   { background: var(--amber); }
        .stat-card .stat-top {
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
        }
        .stat-card .icon-box {
            width: 40px; height: 40px;
            border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .stat-card .icon-box .material-icons { font-size: 20px; }
        .stat-card.sky .icon-box     { background: var(--sky-soft); color: var(--sky); }
        .stat-card.emerald .icon-box { background: var(--emerald-soft); color: var(--emerald); }
        .stat-card.purple .icon-box  { background: var(--purple-soft); color: var(--purple); }
        .stat-card.amber .icon-box   { background: var(--amber-soft); color: var(--amber); }
        .stat-label {
            font-size: 10.5px; color: var(--text-2); font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .stat-value {
            display: block; margin-top: 10px;
            font-size: 20px; font-weight: 700; color: var(--text-1);
            line-height: 1.2;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .stat-change {
            display: inline-flex; align-items: center; gap: 4px;
            margin-top: 8px;
            font-size: 11.5px; font-weight: 600;
        }
        .stat-change.up { color: var(--emerald); }
        .stat-change.down { color: var(--red); }
        .stat-change .material-icons { font-size: 15px; }
        .stat-sub {
            margin-top: 2px;
            font-size: 10.5px; color: var(--text-3); font-weight: 500;
        }

        .glass-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            padding: 18px 20px;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) 0.12s both;
        }
        .glass-card.purple-border { border-color: rgba(124, 58, 237, 0.18); }
        .glass-card h3 {
            font-size: 13px; font-weight: 700; color: var(--text-1);
            margin: 0 0 14px; display: flex; align-items: center; gap: 8px;
        }
        .glass-card h3 .material-icons { color: var(--purple); font-size: 18px; }
        .glass-card h3 .material-icons.emerald-ic { color: var(--emerald); }
        .glass-card h3 .material-icons.sky-ic { color: var(--sky); }

        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }
        .chart-wrap { position: relative; height: 320px; }

        .top-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .gd-table-wrap { overflow-x: auto; }
        .gd-table { width: 100%; border-collapse: collapse; }
        .gd-table thead th {
            padding: 10px 12px;
            font-size: 10px; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: 0.5px;
            text-align: left;
            background: linear-gradient(180deg, #fff, #f5f3ff);
            border-bottom: 2px solid var(--border);
            white-space: nowrap;
        }
        .gd-table thead th.right { text-align: right; }
        .gd-table tbody td {
            padding: 10px 12px;
            font-size: 12.5px; color: var(--text-1);
            border-bottom: 1px solid #f3f4f6;
            vertical-align: top;
        }
        .gd-table tbody td.right { text-align: right; font-weight: 600; }
        .gd-table tbody tr:hover { background: var(--purple-soft); }
        .rank-badge {
            display: inline-flex; align-items: center; justify-content: center;
            width: 24px; height: 24px;
            background: var(--purple-soft); color: var(--purple);
            border-radius: 8px;
            font-size: 11.5px; font-weight: 700;
        }
        .rank-badge.top3 { background: var(--amber-soft); color: var(--amber); }
        .prod-code { font-weight: 600; color: var(--text-1); font-size: 12.5px; }
        .prod-name { font-size: 11px; color: var(--text-3); margin-top: 2px; }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        @media (max-width: 900px) {
            .stat-grid { grid-template-columns: repeat(2, 1fr); }
            .charts-grid, .top-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 767px) {
            .header-inner { padding: 12px 14px; gap: 10px; }
            .header-title h1 { font-size: 14px; }
            .header-title p { font-size: 10.5px; }
            main { padding: 14px 12px 40px; }
            .stat-card { padding: 14px 14px; }
            .stat-value { font-size: 17px; }
            .year-select { font-size: 16px; }
            .glass-card { padding: 14px 14px; }
            .chart-wrap { height: 260px; }
        }
        @media print {
            body { background: #fff !important; }
            .no-print, .top-header { display: none !important; }
            main { padding: 10px 0; max-width: 100%; }
            .glass-card, .stat-card { box-shadow: none !important; border-radius: 0 !important; border: 1px solid #ddd !important; }
            .chart-wrap { height: 240px !important; }
        }
    </style>
</head>
<body>

<header class="top-header no-print">
    <div class="header-inner">
        <a href="dashboard.php" class="header-back" title="Geri">
            <span class="material-icons">arrow_back</span>
        </a>
        <div class="header-divider"></div>
        <div class="header-title">
            <h1><span class="material-icons">summarize</span> Yillik Ozet Raporu</h1>
            <p><?php echo $selectedYear; ?> yılı genel performans özeti</p>
        </div>
        <div class="header-actions">
            <select onchange="window.location.href='?year='+this.value" class="year-select">
                <?php for ($y = (int) date('Y'); $y >= 2020; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y == $selectedYear ? 'selected' : ''; ?>>
                        <?php echo $y; ?>
                    </option>
                <?php endfor; ?>
            </select>
            <button onclick="window.print()" class="btn-ghost-print" title="Yazdır">
                <span class="material-icons">print</span>
                <span>Yazdır</span>
            </button>
        </div>
    </div>
</header>

<main>

    <div class="year-badge">
        <strong><?php echo $selectedYear; ?></strong>
        <span>yılı, geçen yıl (<?php echo $previousYear; ?>) ile karşılaştırma</span>
    </div>

    <!-- KPI Kartları -->
    <div class="stat-grid">

        <div class="stat-card sky">
            <div class="stat-top">
                <span class="stat-label">Toplam Satış</span>
                <div class="icon-box"><span class="material-icons">payments</span></div>
            </div>
            <div class="stat-value"><?php echo number_format($currentYearSales['TOTAL_SALES'], 2, ',', '.'); ?> ₺</div>
            <div class="stat-change <?php echo $salesChange >= 0 ? 'up' : 'down'; ?>">
                <span class="material-icons"><?php echo $salesChange >= 0 ? 'trending_up' : 'trending_down'; ?></span>
                <?php echo number_format(abs($salesChange), 1); ?>%
            </div>
            <div class="stat-sub"><?php echo $previousYear; ?>: <?php echo number_format($previousYearSales['TOTAL_SALES'], 2, ',', '.'); ?> ₺</div>
        </div>

        <div class="stat-card emerald">
            <div class="stat-top">
                <span class="stat-label">Sipariş Sayısı</span>
                <div class="icon-box"><span class="material-icons">receipt_long</span></div>
            </div>
            <div class="stat-value"><?php echo number_format($currentYearSales['ORDER_COUNT'], 0, ',', '.'); ?></div>
            <div class="stat-change <?php echo $orderCountChange >= 0 ? 'up' : 'down'; ?>">
                <span class="material-icons"><?php echo $orderCountChange >= 0 ? 'trending_up' : 'trending_down'; ?></span>
                <?php echo number_format(abs($orderCountChange), 1); ?>%
            </div>
            <div class="stat-sub"><?php echo $previousYear; ?>: <?php echo number_format($previousYearSales['ORDER_COUNT'], 0, ',', '.'); ?></div>
        </div>

        <div class="stat-card purple">
            <div class="stat-top">
                <span class="stat-label">Aktif Müşteri</span>
                <div class="icon-box"><span class="material-icons">group</span></div>
            </div>
            <div class="stat-value"><?php echo number_format($currentYearSales['CUSTOMER_COUNT'], 0, ',', '.'); ?></div>
            <div class="stat-change <?php echo $customerChange >= 0 ? 'up' : 'down'; ?>">
                <span class="material-icons"><?php echo $customerChange >= 0 ? 'trending_up' : 'trending_down'; ?></span>
                <?php echo number_format(abs($customerChange), 1); ?>%
            </div>
            <div class="stat-sub">Yeni: <?php echo number_format($newCustomers['NEW_CUSTOMER_COUNT'], 0); ?></div>
        </div>

        <div class="stat-card amber">
            <div class="stat-top">
                <span class="stat-label">Ort. Sipariş Değeri</span>
                <div class="icon-box"><span class="material-icons">shopping_cart</span></div>
            </div>
            <div class="stat-value"><?php echo number_format($currentYearSales['AVG_ORDER_VALUE'], 2, ',', '.'); ?> ₺</div>
            <div class="stat-change <?php echo $avgOrderChange >= 0 ? 'up' : 'down'; ?>">
                <span class="material-icons"><?php echo $avgOrderChange >= 0 ? 'trending_up' : 'trending_down'; ?></span>
                <?php echo number_format(abs($avgOrderChange), 1); ?>%
            </div>
            <div class="stat-sub"><?php echo $previousYear; ?>: <?php echo number_format($previousYearSales['AVG_ORDER_VALUE'], 2, ',', '.'); ?> ₺</div>
        </div>

    </div>

    <!-- Grafikler -->
    <div class="charts-grid">
        <div class="glass-card purple-border">
            <h3><span class="material-icons">show_chart</span> Aylık Ciro Trendi</h3>
            <div class="chart-wrap">
                <canvas id="monthlySalesChart"></canvas>
            </div>
        </div>

        <div class="glass-card purple-border">
            <h3><span class="material-icons">bar_chart</span> Çeyreksel Performans</h3>
            <div class="chart-wrap">
                <canvas id="quarterlyChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Top 10 Listeler -->
    <div class="top-grid">

        <div class="glass-card purple-border">
            <h3><span class="material-icons sky-ic">star</span> En Çok Satan 10 Ürün</h3>
            <div class="gd-table-wrap">
                <table class="gd-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Ürün</th>
                            <th class="right">Adet</th>
                            <th class="right">Tutar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank = 1; foreach ($topProducts as $product): ?>
                        <tr>
                            <td>
                                <span class="rank-badge <?php echo $rank <= 3 ? 'top3' : ''; ?>"><?php echo $rank++; ?></span>
                            </td>
                            <td>
                                <div class="prod-code"><?php echo htmlspecialchars((string) $product['PRODUCT_CODE']); ?></div>
                                <div class="prod-name"><?php echo htmlspecialchars(substr((string) $product['PRODUCT_NAME'], 0, 32)); ?></div>
                            </td>
                            <td class="right"><?php echo number_format($product['TOTAL_QTY'], 0, ',', '.'); ?></td>
                            <td class="right"><?php echo number_format($product['TOTAL_SALES'], 0, ',', '.'); ?> ₺</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="glass-card purple-border">
            <h3><span class="material-icons">emoji_events</span> En Değerli 10 Müşteri</h3>
            <div class="gd-table-wrap">
                <table class="gd-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Müşteri</th>
                            <th class="right">Sipariş</th>
                            <th class="right">Tutar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank = 1; foreach ($topCustomers as $customer): ?>
                        <tr>
                            <td>
                                <span class="rank-badge <?php echo $rank <= 3 ? 'top3' : ''; ?>"><?php echo $rank++; ?></span>
                            </td>
                            <td>
                                <div class="prod-code"><?php echo htmlspecialchars((string) $customer['CUSTOMER_NAME']); ?></div>
                                <div class="prod-name"><?php echo htmlspecialchars((string) $customer['CUSTOMER_CODE']); ?></div>
                            </td>
                            <td class="right"><?php echo number_format($customer['ORDER_COUNT'], 0); ?></td>
                            <td class="right"><?php echo number_format($customer['TOTAL_SALES'], 0, ',', '.'); ?> ₺</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</main>

<script>
// Aylık Satış Grafiği
const monthlySalesCtx = document.getElementById('monthlySalesChart').getContext('2d');
new Chart(monthlySalesCtx, {
    type: 'line',
    data: {
        labels: ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'],
        datasets: [
            {
                label: '<?php echo $selectedYear; ?>',
                data: <?php echo json_encode(array_column($monthlySales, 'current')); ?>,
                borderColor: 'rgb(124, 58, 237)',
                backgroundColor: 'rgba(124, 58, 237, 0.12)',
                tension: 0.4,
                fill: true,
                borderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 5
            },
            {
                label: '<?php echo $previousYear; ?>',
                data: <?php echo json_encode(array_column($monthlySales, 'previous')); ?>,
                borderColor: 'rgb(156, 163, 175)',
                backgroundColor: 'rgba(156, 163, 175, 0.1)',
                tension: 0.4,
                borderDash: [5, 5],
                borderWidth: 2,
                pointRadius: 2
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
                labels: { font: { family: 'Montserrat', size: 11 } }
            }
        },
        scales: {
            x: {
                ticks: { font: { family: 'Montserrat', size: 11 } },
                grid: { display: false }
            },
            y: {
                beginAtZero: true,
                ticks: {
                    font: { family: 'Montserrat', size: 11 },
                    callback: function(value) {
                        return new Intl.NumberFormat('tr-TR').format(value) + ' ₺';
                    }
                },
                grid: { color: 'rgba(0,0,0,0.05)' }
            }
        }
    }
});

// Çeyreksel Performans Grafiği
const quarterlyCtx = document.getElementById('quarterlyChart').getContext('2d');
new Chart(quarterlyCtx, {
    type: 'bar',
    data: {
        labels: ['Ç1 (Oca-Mar)', 'Ç2 (Nis-Haz)', 'Ç3 (Tem-Eyl)', 'Ç4 (Eki-Ara)'],
        datasets: [
            {
                label: '<?php echo $selectedYear; ?>',
                data: <?php echo json_encode([
                    $quarterlyData['Q1']['current'],
                    $quarterlyData['Q2']['current'],
                    $quarterlyData['Q3']['current'],
                    $quarterlyData['Q4']['current']
                ]); ?>,
                backgroundColor: 'rgba(124, 58, 237, 0.8)',
                borderColor: 'rgba(124, 58, 237, 1)',
                borderWidth: 1,
                borderRadius: 6
            },
            {
                label: '<?php echo $previousYear; ?>',
                data: <?php echo json_encode([
                    $quarterlyData['Q1']['previous'],
                    $quarterlyData['Q2']['previous'],
                    $quarterlyData['Q3']['previous'],
                    $quarterlyData['Q4']['previous']
                ]); ?>,
                backgroundColor: 'rgba(156, 163, 175, 0.6)',
                borderColor: 'rgba(156, 163, 175, 1)',
                borderWidth: 1,
                borderRadius: 6
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
                labels: { font: { family: 'Montserrat', size: 11 } }
            }
        },
        scales: {
            x: {
                ticks: { font: { family: 'Montserrat', size: 11 } },
                grid: { display: false }
            },
            y: {
                beginAtZero: true,
                ticks: {
                    font: { family: 'Montserrat', size: 11 },
                    callback: function(value) {
                        return new Intl.NumberFormat('tr-TR').format(value) + ' ₺';
                    }
                },
                grid: { color: 'rgba(0,0,0,0.05)' }
            }
        }
    }
});
</script>

</body>
</html>
