<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../log_ip.php");
require_once __DIR__ . '/../kontrol.php';

// Yetki kontrolu
if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

$canSeeBalance = (m_p_yetki($terminalkullanici, 'CR1') == 1);

function money_tr(float|int|string|null $amount): string
{
    $decimals = (int) ($GLOBALS['parakusurat'] ?? 2);
    return number_format((float) ($amount ?? 0), $decimals, ',', '.') . ' ₺';
}

/**
 * Tahsilat önceliği için basit risk skoru ve aksiyon önerisi üretir.
 */
function aging_priority_meta(float $d31_60, float $d61_90, float $d90p, float $total): array
{
    $overdue = max(0.0, $d31_60 + $d61_90 + $d90p);
    $overdueRatio = $total > 0 ? ($overdue / $total) * 100 : 0.0;
    $priorityScore = ($d90p * 1.00) + ($d61_90 * 0.65) + ($d31_60 * 0.35);
    $severeDebt = $d90p + $d61_90;

    if ($d90p >= 50000 || $overdueRatio >= 80 || $severeDebt >= 100000) {
        return [
            'level' => 'kritik',
            'label' => 'Kritik',
            'action' => 'Ayni gun arama + odeme plani',
            'score' => $priorityScore,
            'overdue' => $overdue,
            'overdue_ratio' => $overdueRatio,
        ];
    }
    if ($d90p > 0 || $overdueRatio >= 55 || $severeDebt >= 30000) {
        return [
            'level' => 'yuksek',
            'label' => 'Yuksek',
            'action' => '24 saat icinde takip aramasi',
            'score' => $priorityScore,
            'overdue' => $overdue,
            'overdue_ratio' => $overdueRatio,
        ];
    }
    if ($overdue > 0 || $overdueRatio >= 25) {
        return [
            'level' => 'orta',
            'label' => 'Orta',
            'action' => 'Bu hafta icerisinde tahsilat takibi',
            'score' => $priorityScore,
            'overdue' => $overdue,
            'overdue_ratio' => $overdueRatio,
        ];
    }

    return [
        'level' => 'dusuk',
        'label' => 'Dusuk',
        'action' => 'Rutin izleme',
        'score' => $priorityScore,
        'overdue' => $overdue,
        'overdue_ratio' => $overdueRatio,
    ];
}

function aging_priority_badge_class(string $level): string
{
    // Kendi CSS sinif adlarini donduruyoruz (Tailwind bagimligini kaldirir).
    return match ($level) {
        'kritik' => 'pill pill-kritik',
        'yuksek' => 'pill pill-yuksek',
        'orta' => 'pill pill-orta',
        default => 'pill pill-dusuk',
    };
}

// As-of date
$today = date('Y-m-d');
$asOf = isset($_GET['tarih']) ? trim((string) $_GET['tarih']) : $today;
if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $asOf)) {
    $asOf = $today;
}

// Filters
$rawQ = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$q = $rawQ !== '' ? turkce($rawQ) : '';

$rawMin = isset($_GET['min']) ? trim((string) $_GET['min']) : '';
$minTotal = 0.0;
if ($rawMin !== '') {
    $minNorm = str_replace(' ', '', $rawMin);
    if (str_contains($minNorm, ',') && str_contains($minNorm, '.')) {
        $minNorm = str_replace('.', '', $minNorm);
        $minNorm = str_replace(',', '.', $minNorm);
    } elseif (str_contains($minNorm, ',')) {
        $minNorm = str_replace(',', '.', $minNorm);
    }
    if (is_numeric($minNorm)) {
        $minTotal = max(0.0, (float) $minNorm);
    }
}

$priorityFilter = isset($_GET['oncelik']) ? strtolower(trim((string) $_GET['oncelik'])) : 'all';
$allowedPriorityFilters = ['all', 'kritik', 'yuksek', 'orta', 'dusuk'];
if (!in_array($priorityFilter, $allowedPriorityFilters, true)) {
    $priorityFilter = 'all';
}

$defaultExclude = ['genel gider'];
$rawExclude = isset($_GET['exclude']) ? trim((string) $_GET['exclude']) : '';
$excludeList = [];
if (!isset($_GET['exclude'])) {
    $excludeList = $defaultExclude;
    $rawExclude = implode(', ', $defaultExclude);
} else {
    $parts = preg_split('/[\\r\\n,;]+/', $rawExclude) ?: [];
    foreach ($parts as $p) {
        $p = trim((string) $p);
        if ($p !== '') {
            $excludeList[] = $p;
        }
    }
    $excludeList = array_values(array_unique($excludeList));
    $excludeList = array_slice($excludeList, 0, 20);
}

$defaultLimit = 1000;
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : $defaultLimit;
$limit = max(100, min($limit, 10000));

$rows = [];
$totals = [
    'D0_30' => 0.0,
    'D31_60' => 0.0,
    'D61_90' => 0.0,
    'D90P' => 0.0,
    'OVERDUE' => 0.0,
    'TOTAL' => 0.0,
    'CUSTOMERS' => 0,
    'CRITICAL' => 0,
    'HIGH' => 0,
];
$pageError = '';

if ($canSeeBalance) {
    $params = [
        ':asof' => $asOf,
    ];

    $whereC = [];
    $whereC[] = "C.ACTIVE = 0";
    // Müşteri kartları (döviz modülü ile uyumlu)
    $whereC[] = "C.CARDTYPE IN (3, 10)";

    if ($q !== '') {
        $like = "%{$q}%";
        $whereC[] = "
            (
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
        ";
        $params[':p1'] = $like;
        $params[':p2'] = $like;
        $params[':p3'] = $like;
    }

    if ($excludeList !== []) {
        $normDefExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(C.DEFINITION_, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c') COLLATE Turkish_CI_AS";
        $normCodeExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(C.CODE, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c') COLLATE Turkish_CI_AS";

        $i = 0;
        foreach ($excludeList as $ex) {
            $i++;
            $exNorm = turkce($ex);
            $like = "%{$exNorm}%";
            $paramDef = ":ex_def{$i}";
            $paramCode = ":ex_code{$i}";
            $whereC[] = "({$normDefExpr} NOT LIKE {$paramDef} AND {$normCodeExpr} NOT LIKE {$paramCode})";
            $params[$paramDef] = $like;
            $params[$paramCode] = $like;
        }
    }

    $whereCSql = implode("\n        AND ", $whereC);

    $extraNetWhere = "";
    if ($minTotal > 0) {
        $extraNetWhere .= "\n        AND N.NET_BAKIYE >= :min_total";
        $params[':min_total'] = $minTotal;
    }

    // NOTE: Aging hesaplamasi, tahsilatlari en eski borclara (en eski islem tarihine) dogru FIFO mantigiyla dagitir.
    $sql = "
WITH PARAMS AS (
    SELECT CAST(:asof AS DATE) AS ASOF_DATE
),
CARILER AS (
    SELECT
        C.LOGICALREF AS CARIID,
        C.CODE AS KODU,
        C.DEFINITION_ AS UNVANI,
        C.CITY AS SEHIR
    FROM {$firma}CLCARD C WITH(NOLOCK)
    WHERE {$whereCSql}
),
NET AS (
    SELECT
        L.CLIENTREF AS CARIID,
        SUM(CASE WHEN L.SIGN = 0 THEN CAST(L.AMOUNT AS DECIMAL(18,2)) ELSE CAST(0 AS DECIMAL(18,2)) END) AS DEBIT_TOTAL,
        SUM(CASE WHEN L.SIGN = 1 THEN CAST(L.AMOUNT AS DECIMAL(18,2)) ELSE CAST(0 AS DECIMAL(18,2)) END) AS CREDIT_TOTAL,
        SUM(CASE WHEN L.SIGN = 0 THEN CAST(L.AMOUNT AS DECIMAL(18,2)) ELSE -CAST(L.AMOUNT AS DECIMAL(18,2)) END) AS NET_BAKIYE
    FROM {$firmadonem}CLFLINE L WITH(NOLOCK)
    INNER JOIN CARILER C ON C.CARIID = L.CLIENTREF
    CROSS JOIN PARAMS P
    WHERE L.CANCELLED = 0
      AND L.DATE_ < DATEADD(DAY, 1, P.ASOF_DATE)
    GROUP BY L.CLIENTREF
),
BORCLU AS (
    SELECT
        N.CARIID,
        N.DEBIT_TOTAL,
        N.CREDIT_TOTAL,
        N.NET_BAKIYE
    FROM NET N
    WHERE N.NET_BAKIYE > 0
    {$extraNetWhere}
),
DEBIT_LINES AS (
    SELECT
        L.CLIENTREF AS CARIID,
        CAST(L.DATE_ AS DATE) AS ISLEM_TARIHI,
        CAST(L.AMOUNT AS DECIMAL(18,2)) AS TUTAR,
        SUM(CAST(L.AMOUNT AS DECIMAL(18,2)))
            OVER (PARTITION BY L.CLIENTREF ORDER BY L.DATE_ ASC, L.LOGICALREF ASC)
            AS CUM_DEBIT
    FROM {$firmadonem}CLFLINE L WITH(NOLOCK)
    INNER JOIN BORCLU B ON B.CARIID = L.CLIENTREF
    CROSS JOIN PARAMS P
    WHERE L.CANCELLED = 0
      AND L.SIGN = 0
      AND L.AMOUNT > 0
      AND L.DATE_ < DATEADD(DAY, 1, P.ASOF_DATE)
),
OPEN_LINES AS (
    SELECT
        D.CARIID,
        D.ISLEM_TARIHI,
        (D.TUTAR - (
            CASE
                WHEN (B.CREDIT_TOTAL - (D.CUM_DEBIT - D.TUTAR)) <= 0 THEN CAST(0 AS DECIMAL(18,2))
                WHEN (B.CREDIT_TOTAL - (D.CUM_DEBIT - D.TUTAR)) >= D.TUTAR THEN D.TUTAR
                ELSE (B.CREDIT_TOTAL - (D.CUM_DEBIT - D.TUTAR))
            END
        )) AS ACIK_TUTAR
    FROM DEBIT_LINES D
    INNER JOIN BORCLU B ON B.CARIID = D.CARIID
),
AGE AS (
    SELECT
        O.CARIID,
        O.ISLEM_TARIHI,
        O.ACIK_TUTAR,
        DATEDIFF(DAY, O.ISLEM_TARIHI, P.ASOF_DATE) AS AGE_DAYS
    FROM OPEN_LINES O
    CROSS JOIN PARAMS P
    WHERE O.ACIK_TUTAR > 0
)
SELECT TOP {$limit}
    C.KODU,
    C.UNVANI,
    C.SEHIR,
    SUM(CASE WHEN A.AGE_DAYS BETWEEN 0 AND 30 THEN A.ACIK_TUTAR ELSE 0 END) AS D0_30,
    SUM(CASE WHEN A.AGE_DAYS BETWEEN 31 AND 60 THEN A.ACIK_TUTAR ELSE 0 END) AS D31_60,
    SUM(CASE WHEN A.AGE_DAYS BETWEEN 61 AND 90 THEN A.ACIK_TUTAR ELSE 0 END) AS D61_90,
    SUM(CASE WHEN A.AGE_DAYS >= 91 THEN A.ACIK_TUTAR ELSE 0 END) AS D90P,
    SUM(CASE WHEN A.AGE_DAYS >= 31 THEN A.ACIK_TUTAR ELSE 0 END) AS OVERDUE,
    (
        SUM(CASE WHEN A.AGE_DAYS >= 91 THEN A.ACIK_TUTAR ELSE 0 END) * 1.00
        + SUM(CASE WHEN A.AGE_DAYS BETWEEN 61 AND 90 THEN A.ACIK_TUTAR ELSE 0 END) * 0.65
        + SUM(CASE WHEN A.AGE_DAYS BETWEEN 31 AND 60 THEN A.ACIK_TUTAR ELSE 0 END) * 0.35
    ) AS PRIORITY_SCORE,
    SUM(A.ACIK_TUTAR) AS TOTAL
FROM AGE A
INNER JOIN CARILER C ON C.CARIID = A.CARIID
GROUP BY C.KODU, C.UNVANI, C.SEHIR
ORDER BY PRIORITY_SCORE DESC, TOTAL DESC
";

    try {
        if (function_exists('app_db_prepare_execute')) {
            $stmt = app_db_prepare_execute($dbh, $sql, $params, [
                'page' => 'rapor/rapor_cari_yaslandirma.php',
                'action' => 'list',
                'has_search' => ($q !== ''),
                'asof' => $asOf,
                'limit' => $limit,
                'min_total' => $minTotal,
                'priority_filter' => $priorityFilter,
            ]);
        } else {
            $stmt = $dbh->prepare($sql);
            $stmt->execute($params);
        }

        $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rows = [];
        foreach ($rawRows as $r) {
            $d0 = (float) ($r['D0_30'] ?? 0);
            $d31 = (float) ($r['D31_60'] ?? 0);
            $d61 = (float) ($r['D61_90'] ?? 0);
            $d90 = (float) ($r['D90P'] ?? 0);
            $total = (float) ($r['TOTAL'] ?? 0);
            $meta = aging_priority_meta($d31, $d61, $d90, $total);

            if ($priorityFilter !== 'all' && $meta['level'] !== $priorityFilter) {
                continue;
            }

            $r['D0_30'] = $d0;
            $r['D31_60'] = $d31;
            $r['D61_90'] = $d61;
            $r['D90P'] = $d90;
            $r['TOTAL'] = $total;
            $r['OVERDUE'] = (float) ($r['OVERDUE'] ?? $meta['overdue']);
            $r['PRIORITY_SCORE'] = (float) ($r['PRIORITY_SCORE'] ?? $meta['score']);
            $r['OVERDUE_RATIO'] = $meta['overdue_ratio'];
            $r['PRIORITY_LEVEL'] = $meta['level'];
            $r['PRIORITY_LABEL'] = $meta['label'];
            $r['PRIORITY_ACTION'] = $meta['action'];

            $rows[] = $r;
        }
    } catch (Throwable $e) {
        if (function_exists('app_log_exception')) {
            $ref = app_log_exception($e, 'rapor/rapor_cari_yaslandirma.php', [
                'q' => mb_substr($rawQ, 0, 120),
                'asof' => $asOf,
                'limit' => $limit,
                'min_total' => $minTotal,
                'priority_filter' => $priorityFilter,
            ]);
            $pageError = "Beklenmeyen bir hata olustu. Ref: " . htmlspecialchars((string) $ref, ENT_QUOTES, 'UTF-8');
        } else {
            $pageError = "Beklenmeyen bir hata olustu: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    }
}

foreach ($rows as $r) {
    $totals['CUSTOMERS']++;
    $totals['D0_30'] += (float) ($r['D0_30'] ?? 0);
    $totals['D31_60'] += (float) ($r['D31_60'] ?? 0);
    $totals['D61_90'] += (float) ($r['D61_90'] ?? 0);
    $totals['D90P'] += (float) ($r['D90P'] ?? 0);
    $totals['OVERDUE'] += (float) ($r['OVERDUE'] ?? 0);
    $totals['TOTAL'] += (float) ($r['TOTAL'] ?? 0);
    if (($r['PRIORITY_LEVEL'] ?? '') === 'kritik') {
        $totals['CRITICAL']++;
    } elseif (($r['PRIORITY_LEVEL'] ?? '') === 'yuksek') {
        $totals['HIGH']++;
    }
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cari Yaşlandırma</title>
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once(__DIR__ . '/../pwa-header.php'); } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f9fafb;
            --surface: #ffffff;
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
            --amber-border: rgba(217, 119, 6, 0.22);
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

        /* Sticky header (amber) */
        .top-header {
            position: sticky; top: 0; z-index: 40;
            height: 64px;
            background: rgba(255,255,255,0.88);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid var(--amber-border);
            box-shadow: 0 2px 8px rgba(217, 119, 6, 0.05);
        }
        .header-inner {
            max-width: 1280px; margin: 0 auto;
            height: 100%;
            display: flex; align-items: center; gap: 14px;
            padding: 0 24px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 10px;
            color: var(--text-2); text-decoration: none;
            transition: all 0.2s ease;
        }
        .header-back:hover { background: rgba(217,119,6,0.08); color: var(--amber); }
        .header-divider { width: 1px; height: 24px; background: var(--border); }
        .header-title {
            font-size: 18px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 8px;
        }
        .header-title .material-icons { color: var(--amber); font-size: 20px; }
        .header-actions {
            margin-left: auto;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .icon-btn {
            display: inline-flex; align-items: center; justify-content: center;
            width: 40px; height: 40px; border-radius: 10px;
            color: var(--text-2);
            background: transparent;
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .icon-btn:hover { background: var(--amber-soft); color: var(--amber); }
        .icon-btn.icon-excel { color: var(--emerald); }
        .icon-btn.icon-excel:hover { background: var(--emerald-soft); }

        main {
            max-width: 1280px; margin: 0 auto;
            padding: 20px 24px 60px;
        }

        /* Glass card */
        .glass-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            will-change: transform, opacity;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        /* Filter panel */
        .filter-panel {
            padding: 18px 20px;
            margin-bottom: 18px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 2fr 1fr 1fr 1fr;
            gap: 12px;
        }
        .form-row-bottom {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
            margin-top: 12px;
            align-items: end;
        }
        .form-field { display: flex; flex-direction: column; }
        .form-label {
            display: block;
            font-size: 11.5px; font-weight: 600;
            color: var(--text-2);
            margin-bottom: 6px;
        }
        .form-input,
        .form-select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13.5px;
            color: var(--text-1);
            background: #fff;
            transition: all 0.2s ease;
            outline: none;
        }
        .form-input:focus,
        .form-select:focus {
            border-color: rgba(217, 119, 6, 0.5);
            box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.1);
        }
        .form-input-wrap { position: relative; }
        .form-input-wrap .fa-solid {
            position: absolute; left: 12px; top: 50%;
            transform: translateY(-50%);
            color: var(--text-3);
            font-size: 14px;
            pointer-events: none;
        }
        .form-input.has-icon { padding-left: 36px; }

        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 6px;
            padding: 10px 18px;
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13px; font-weight: 600;
            border: none; cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            min-height: 44px;
        }
        .btn-amber { background: var(--amber); color: #fff; }
        .btn-amber:hover { background: #b45309; }

        /* Alert card */
        .alert-card {
            margin-bottom: 16px;
            padding: 14px 18px;
            border-radius: 14px;
            display: flex; align-items: flex-start; gap: 12px;
            font-size: 13px;
            background: rgba(255,255,255,0.92);
            border: 1px solid rgba(111, 16, 34, 0.25);
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .alert-card .material-icons { color: var(--red); font-size: 22px; flex-shrink: 0; }
        .alert-card .alert-title { font-weight: 700; color: var(--text-1); }
        .alert-card .alert-desc { color: var(--text-2); margin-top: 2px; font-size: 12.5px; }

        /* Stat grid (6 cards) */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px;
            margin-bottom: 18px;
        }
        .stat-card {
            padding: 12px 14px;
            display: flex; align-items: center; gap: 10px;
        }
        .stat-card:nth-child(1) { animation-delay: 0ms; }
        .stat-card:nth-child(2) { animation-delay: 40ms; }
        .stat-card:nth-child(3) { animation-delay: 80ms; }
        .stat-card:nth-child(4) { animation-delay: 120ms; }
        .stat-card:nth-child(5) { animation-delay: 160ms; }
        .stat-card:nth-child(6) { animation-delay: 200ms; }

        .stat-ico {
            width: 38px; height: 38px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .stat-ico .material-icons { font-size: 18px; }
        .stat-ico.gray    { background: #f3f4f6; color: var(--text-2); }
        .stat-ico.emerald { background: var(--emerald-soft); color: var(--emerald); }
        .stat-ico.amber   { background: var(--amber-soft);   color: var(--amber); }
        .stat-ico.orange  { background: #ffedd5;             color: #c2410c; }
        .stat-ico.red     { background: var(--red-soft);     color: var(--red); }
        .stat-ico.purple  { background: var(--purple-soft);  color: var(--purple); }

        .stat-body { min-width: 0; flex: 1; }
        .stat-label {
            font-size: 10px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.4px;
            color: var(--text-2);
        }
        .stat-value {
            font-size: 15px; font-weight: 700;
            color: var(--text-1);
            line-height: 1.2; margin-top: 3px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            font-variant-numeric: tabular-nums;
        }
        .stat-sub {
            font-size: 10.5px; color: var(--text-3); margin-top: 2px;
        }
        .stat-card.tone-gray    { border-color: rgba(107,114,128,0.22); background: linear-gradient(180deg, #f9fafb, #fff); }
        .stat-card.tone-emerald { border-color: rgba(5,150,105,0.22); background: linear-gradient(180deg, var(--emerald-soft), #fff); }
        .stat-card.tone-amber   { border-color: var(--amber-border); background: linear-gradient(180deg, var(--amber-soft), #fff); }
        .stat-card.tone-orange  { border-color: rgba(234,88,12,0.22); background: linear-gradient(180deg, #ffedd5, #fff); }
        .stat-card.tone-red     { border-color: rgba(111,16,34,0.22); background: linear-gradient(180deg, var(--red-soft), #fff); }
        .stat-card.tone-purple  { border-color: rgba(124,58,237,0.22); background: linear-gradient(180deg, var(--purple-soft), #fff); }

        /* Hero summary */
        .hero-card {
            padding: 18px 22px;
            margin-bottom: 18px;
            display: flex; align-items: center;
            justify-content: space-between; gap: 16px;
            flex-wrap: wrap;
            background: linear-gradient(135deg, var(--purple-soft), #fff);
            border: 1px solid rgba(124, 58, 237, 0.22);
            animation-delay: 220ms;
        }
        .hero-card h2 {
            font-size: 13px; color: var(--text-2); font-weight: 600;
        }
        .hero-card .hero-amount {
            font-size: 26px; font-weight: 800; color: var(--text-1);
            margin-top: 4px; font-variant-numeric: tabular-nums;
        }
        .hero-card .hero-note {
            font-size: 11.5px; color: var(--text-3); margin-top: 4px;
        }
        .hero-meta { font-size: 12px; color: var(--text-2); text-align: right; }
        .hero-meta .asof {
            font-weight: 700; color: var(--text-1);
        }
        .hero-meta .critical { font-weight: 700; color: var(--red); }
        .hero-meta .high { font-weight: 700; color: #c2410c; }

        /* Table card */
        .table-card {
            border-radius: 16px;
            overflow: hidden;
            animation-delay: 260ms;
        }
        .table-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(180deg, #fff, #fffbf3);
        }
        .table-head-title {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 14px; font-weight: 700; color: var(--text-1);
        }
        .table-head-title .material-icons { color: var(--amber); font-size: 20px; }
        .table-head-meta { font-size: 12px; color: var(--text-2); }

        .table-scroll { overflow-x: auto; }
        .gd-table { width: 100%; border-collapse: collapse; min-width: 980px; }
        .gd-table thead { background: #f8fafc; }
        .gd-table thead th {
            padding: 11px 12px;
            font-size: 10.5px; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: 0.5px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .gd-table thead th.right { text-align: right; }
        .gd-table thead th.center { text-align: center; }
        .gd-table tbody td {
            padding: 10px 12px;
            font-size: 12.5px; color: var(--text-1);
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .gd-table tbody tr { transition: background 0.15s ease; }
        .gd-table tbody tr:hover { background: rgba(217, 119, 6, 0.03); }
        .gd-table tbody tr:last-child td { border-bottom: none; }

        .cell-right { text-align: right; }
        .cell-center { text-align: center; }
        .cell-code {
            font-family: 'JetBrains Mono', 'Courier New', monospace;
            font-size: 11.5px; font-weight: 700;
        }
        .cell-musteri { font-weight: 600; max-width: 220px; overflow: hidden; text-overflow: ellipsis; }
        .cell-sehir { color: var(--text-2); font-size: 12px; }
        .num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .num.aging-0 { color: var(--emerald); font-weight: 600; }
        .num.aging-31 { color: #ca8a04; font-weight: 600; }
        .num.aging-61 { color: #c2410c; font-weight: 600; }
        .num.aging-90 { color: var(--red); font-weight: 700; }
        .num.total    { color: var(--text-1); font-weight: 800; }
        .num.overdue  { color: var(--purple); font-weight: 700; }
        .overdue-ratio { font-size: 10.5px; color: var(--text-3); font-weight: 500; }

        /* Priority pills */
        .pill {
            display: inline-flex; align-items: center;
            padding: 4px 11px;
            border-radius: 100px;
            font-size: 11px; font-weight: 700;
            border: 1px solid transparent;
        }
        .pill-kritik { background: var(--red-soft); color: #991b1b; border-color: rgba(111,16,34,0.22); }
        .pill-yuksek { background: #ffedd5; color: #9a3412; border-color: rgba(234,88,12,0.22); }
        .pill-orta { background: #fef9c3; color: #854d0e; border-color: rgba(202,138,4,0.22); }
        .pill-dusuk { background: var(--emerald-soft); color: #065f46; border-color: rgba(5,150,105,0.22); }

        .action-cell {
            color: var(--text-2); font-size: 12px;
            max-width: 220px;
        }

        .empty-state {
            padding: 48px 20px;
            text-align: center;
            color: var(--text-2);
        }
        .empty-state .material-icons { font-size: 42px; color: var(--text-3); margin-bottom: 10px; }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to   { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        /* Mobile */
        @media (max-width: 1200px) {
            .stat-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 1024px) {
            .form-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 767px) {
            .top-header { height: 50px; }
            .header-inner { padding: 0 12px; gap: 10px; }
            .header-title { font-size: 14.5px; }
            .header-back { width: 30px; height: 30px; border-radius: 8px; }
            .icon-btn { width: 36px; height: 36px; }

            main { padding: 14px 12px 40px; }

            .filter-panel { padding: 14px; }
            .form-grid { grid-template-columns: 1fr 1fr; }
            .form-row-bottom { grid-template-columns: 1fr; }

            .stat-grid { grid-template-columns: repeat(2, 1fr); gap: 8px; }
            .stat-card { padding: 10px; }
            .stat-ico { width: 32px; height: 32px; }
            .stat-ico .material-icons { font-size: 16px; }
            .stat-value { font-size: 13px; }

            .hero-card { padding: 14px; gap: 10px; }
            .hero-card .hero-amount { font-size: 20px; }
            .hero-meta { text-align: left; }

            .gd-table { min-width: auto; }
            .gd-table thead { display: none; }
            .gd-table, .gd-table tbody, .gd-table tr, .gd-table td { display: block; width: 100%; }
            .gd-table tbody tr {
                padding: 14px;
                display: grid;
                grid-template-columns: 1fr auto;
                gap: 6px 10px;
                border-bottom: 1px solid #f3f4f6;
            }
            .gd-table tbody td { padding: 0; border: none; }
            .gd-table tbody td.m-code { grid-column: 1; grid-row: 1; font-weight: 700; }
            .gd-table tbody td.m-priority { grid-column: 2; grid-row: 1; text-align: right; }
            .gd-table tbody td.m-musteri { grid-column: 1 / -1; grid-row: 2; max-width: none; white-space: normal; }
            .gd-table tbody td.m-sehir { grid-column: 1 / -1; grid-row: 3; font-size: 11px; color: var(--text-2); }
            .m-aging {
                grid-column: 1 / -1;
                grid-row: 4;
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 6px;
                margin-top: 4px;
                padding-top: 8px;
                border-top: 1px dashed #e5e7eb;
                font-size: 11.5px;
            }
            .m-aging .cell-m {
                display: flex; flex-direction: column;
                align-items: flex-start; gap: 2px;
            }
            .m-aging .cell-m .lbl { font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.4px; color: var(--text-3); font-weight: 700; }
            .m-total-row { grid-column: 1 / -1; grid-row: 5; display: flex; gap: 14px; flex-wrap: wrap; font-size: 11.5px; }
            .m-total-row .cell-m { display: flex; gap: 4px; align-items: baseline; }
            .m-total-row .cell-m .lbl { font-size: 10px; color: var(--text-3); font-weight: 600; text-transform: uppercase; }
            .m-action { grid-column: 1 / -1; grid-row: 6; font-size: 11.5px; color: var(--text-2); }
        }

        /* Touch */
        a, button { min-height: 44px; }
        .header-back, .icon-btn, .pill { min-height: 0; }

        /* iOS zoom fix */
        input, select, textarea { font-size: 16px; }

        /* Print */
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            main { padding: 0 !important; max-width: 100% !important; }
            .glass-card { box-shadow: none !important; border-radius: 6px !important; }
            .gd-table tbody tr { page-break-inside: avoid; }
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
        <span class="header-title">
            <span class="material-icons">schedule</span>Cari Yaslandirma
        </span>
        <div class="header-actions">
            <button type="button" onclick="window.print()" class="icon-btn" title="Yazdir">
                <span class="material-icons">print</span>
            </button>
            <?php
                $excelQuery = http_build_query([
                    'tarih' => $asOf,
                    'q' => $rawQ,
                    'min' => $rawMin,
                    'oncelik' => $priorityFilter,
                    'exclude' => $rawExclude,
                    'limit' => (int) $limit,
                ]);
            ?>
            <a href="rapor_cari_yaslandirma_excel_xlsx.php?<?php echo htmlspecialchars($excelQuery, ENT_QUOTES, 'UTF-8'); ?>"
               class="icon-btn icon-excel" title="Excel">
                <span class="material-icons">download</span>
            </a>
        </div>
    </div>
</header>

<main>
    <!-- Filtre Formu -->
    <div class="glass-card filter-panel no-print">
        <form method="get">
            <div class="form-grid">
                <div class="form-field">
                    <label class="form-label">Tarih (Rapor Gunu)</label>
                    <input
                        type="date"
                        name="tarih"
                        value="<?php echo htmlspecialchars($asOf, ENT_QUOTES, 'UTF-8'); ?>"
                        class="form-input"
                    >
                </div>

                <div class="form-field">
                    <label class="form-label">Ara</label>
                    <div class="form-input-wrap">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input
                            type="text"
                            name="q"
                            value="<?php echo htmlspecialchars($rawQ, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="Firma adı, kodu veya şehir..."
                            class="form-input has-icon"
                        >
                    </div>
                </div>

                <div class="form-field">
                    <label class="form-label">Min Toplam (&#8378;)</label>
                    <input
                        type="number"
                        name="min"
                        min="0"
                        step="0.01"
                        value="<?php echo htmlspecialchars($rawMin, ENT_QUOTES, 'UTF-8'); ?>"
                        placeholder="orn: 1000"
                        class="form-input"
                    >
                </div>

                <div class="form-field">
                    <label class="form-label">Limit</label>
                    <input type="number" name="limit" min="100" max="10000"
                        value="<?php echo (int) $limit; ?>"
                        class="form-input">
                </div>

                <div class="form-field">
                    <label class="form-label">Tahsilat Onceligi</label>
                    <select name="oncelik" class="form-select">
                        <option value="all" <?php echo $priorityFilter === 'all' ? 'selected' : ''; ?>>Tümü</option>
                        <option value="kritik" <?php echo $priorityFilter === 'kritik' ? 'selected' : ''; ?>>Kritik</option>
                        <option value="yuksek" <?php echo $priorityFilter === 'yuksek' ? 'selected' : ''; ?>>Yüksek</option>
                        <option value="orta" <?php echo $priorityFilter === 'orta' ? 'selected' : ''; ?>>Orta</option>
                        <option value="dusuk" <?php echo $priorityFilter === 'dusuk' ? 'selected' : ''; ?>>Düşük</option>
                    </select>
                </div>
            </div>

            <div class="form-row-bottom">
                <div class="form-field">
                    <label class="form-label">Hariç Tut (opsiyonel)</label>
                    <input
                        type="text"
                        name="exclude"
                        value="<?php echo htmlspecialchars($rawExclude, ENT_QUOTES, 'UTF-8'); ?>"
                        placeholder="orn: genel giderler"
                        class="form-input"
                    >
                    <div style="margin-top:6px;font-size:11.5px;color:var(--text-3);">
                        Virgülle ayırın.
                    </div>
                </div>
                <div class="form-field" style="justify-content:flex-end;">
                    <button type="submit" class="btn btn-amber">
                        <span class="material-icons" style="font-size:18px;">search</span>
                        Uygula
                    </button>
                </div>
            </div>
        </form>
    </div>

    <?php if (!$canSeeBalance): ?>
        <div class="alert-card">
            <span class="material-icons">warning</span>
            <div>
                <div class="alert-title">Bakiye görme yetkiniz yok (CR1).</div>
                <div class="alert-desc">Bu rapor, cari bakiyeleri gösterdiği için yetki gerektirir.</div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($pageError !== ''): ?>
        <div class="alert-card">
            <span class="material-icons">error</span>
            <div>
                <div class="alert-title">Hata olustu</div>
                <div class="alert-desc"><?php echo $pageError; ?></div>
            </div>
        </div>
    <?php endif; ?>

    <!-- KPI: 6 kart -->
    <div class="stat-grid">
        <div class="glass-card stat-card tone-gray">
            <span class="stat-ico gray"><span class="material-icons">groups</span></span>
            <div class="stat-body">
                <div class="stat-label">Cari</div>
                <div class="stat-value"><?php echo number_format((int) $totals['CUSTOMERS'], 0, ',', '.'); ?></div>
                <div class="stat-sub">listelenen</div>
            </div>
        </div>

        <div class="glass-card stat-card tone-emerald">
            <span class="stat-ico emerald"><span class="material-icons">trending_up</span></span>
            <div class="stat-body">
                <div class="stat-label">0-30 Gün</div>
                <div class="stat-value"><?php echo money_tr($totals['D0_30']); ?></div>
            </div>
        </div>

        <div class="glass-card stat-card tone-amber">
            <span class="stat-ico amber"><span class="material-icons">warning_amber</span></span>
            <div class="stat-body">
                <div class="stat-label">31-60 Gün</div>
                <div class="stat-value"><?php echo money_tr($totals['D31_60']); ?></div>
            </div>
        </div>

        <div class="glass-card stat-card tone-orange">
            <span class="stat-ico orange"><span class="material-icons">priority_high</span></span>
            <div class="stat-body">
                <div class="stat-label">61-90 Gün</div>
                <div class="stat-value"><?php echo money_tr($totals['D61_90']); ?></div>
            </div>
        </div>

        <div class="glass-card stat-card tone-red">
            <span class="stat-ico red"><span class="material-icons">report</span></span>
            <div class="stat-body">
                <div class="stat-label">90+ Gün</div>
                <div class="stat-value"><?php echo money_tr($totals['D90P']); ?></div>
            </div>
        </div>

        <div class="glass-card stat-card tone-purple">
            <span class="stat-ico purple"><span class="material-icons">pending_actions</span></span>
            <div class="stat-body">
                <div class="stat-label">Vadesi Geçen</div>
                <div class="stat-value"><?php echo money_tr($totals['OVERDUE']); ?></div>
                <div class="stat-sub">31+ gün toplam</div>
            </div>
        </div>
    </div>

    <!-- Hero ozet -->
    <div class="glass-card hero-card">
        <div>
            <h2>Toplam Açık Borç</h2>
            <div class="hero-amount"><?php echo money_tr($totals['TOTAL']); ?></div>
            <div class="hero-note">FIFO tahsilat dağılımı ile hesaplanır (en eski işlem tarihinden düşer).</div>
        </div>
        <div class="hero-meta">
            Rapor Tarihi: <span class="asof"><?php echo htmlspecialchars($asOf, ENT_QUOTES, 'UTF-8'); ?></span>
            <div style="margin-top:4px;">
                Kritik Cari: <span class="critical"><?php echo number_format((int) $totals['CRITICAL'], 0, ',', '.'); ?></span>
                &middot; Yüksek Cari: <span class="high"><?php echo number_format((int) $totals['HIGH'], 0, ',', '.'); ?></span>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="glass-card table-card">
        <div class="table-head">
            <div class="table-head-title">
                <span class="material-icons">list_alt</span>Cari Listesi
            </div>
            <div class="table-head-meta">
                Gösterilen: <?php echo number_format(count($rows), 0, ',', '.'); ?>
            </div>
        </div>

        <div class="table-scroll">
            <table id="agingTable" class="gd-table">
                <thead>
                    <tr>
                        <th>Kod</th>
                        <th>Müşteri</th>
                        <th>Şehir</th>
                        <th class="right">0-30</th>
                        <th class="right">31-60</th>
                        <th class="right">61-90</th>
                        <th class="right">90+</th>
                        <th class="right">Toplam</th>
                        <th class="right">Vadesi Geçen</th>
                        <th class="center">Öncelik</th>
                        <th>Aksiyon</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$canSeeBalance): ?>
                        <tr>
                            <td colspan="11">
                                <div class="empty-state">
                                    <span class="material-icons">lock</span>
                                    <div>Yetki olmadığı için liste gösterilmiyor.</div>
                                </div>
                            </td>
                        </tr>
                    <?php elseif ($rows === []): ?>
                        <tr>
                            <td colspan="11">
                                <div class="empty-state">
                                    <span class="material-icons">inbox</span>
                                    <div>Kayıt bulunamadı.</div>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td class="m-code cell-code">
                                    <?php echo htmlspecialchars((string) ($r['KODU'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td class="m-musteri cell-musteri">
                                    <?php echo htmlspecialchars((string) ($r['UNVANI'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td class="m-sehir cell-sehir">
                                    <?php
                                        $sehir = (string) ($r['SEHIR'] ?? '');
                                        echo htmlspecialchars(function_exists('trcevir') ? (string) trcevir($sehir) : $sehir, ENT_QUOTES, 'UTF-8');
                                    ?>
                                </td>
                                <td class="cell-right num aging-0 m-aging-0" data-label="0-30">
                                    <?php echo money_tr((float) ($r['D0_30'] ?? 0)); ?>
                                </td>
                                <td class="cell-right num aging-31 m-aging-31" data-label="31-60">
                                    <?php echo money_tr((float) ($r['D31_60'] ?? 0)); ?>
                                </td>
                                <td class="cell-right num aging-61 m-aging-61" data-label="61-90">
                                    <?php echo money_tr((float) ($r['D61_90'] ?? 0)); ?>
                                </td>
                                <td class="cell-right num aging-90 m-aging-90" data-label="90+">
                                    <?php echo money_tr((float) ($r['D90P'] ?? 0)); ?>
                                </td>
                                <td class="cell-right num total m-total">
                                    <?php echo money_tr((float) ($r['TOTAL'] ?? 0)); ?>
                                </td>
                                <td class="cell-right num overdue m-overdue">
                                    <?php echo money_tr((float) ($r['OVERDUE'] ?? 0)); ?>
                                    <div class="overdue-ratio">
                                        <?php echo number_format((float) ($r['OVERDUE_RATIO'] ?? 0), 1, ',', '.'); ?>%
                                    </div>
                                </td>
                                <td class="cell-center m-priority">
                                    <span class="<?php echo aging_priority_badge_class((string) ($r['PRIORITY_LEVEL'] ?? 'dusuk')); ?>">
                                        <?php echo htmlspecialchars((string) ($r['PRIORITY_LABEL'] ?? 'Dusuk'), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td class="action-cell m-action">
                                    <?php echo htmlspecialchars((string) ($r['PRIORITY_ACTION'] ?? 'Rutin izleme'), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script>
    window.addEventListener('load', function(){
        requestAnimationFrame(function(){
            document.querySelectorAll('.glass-card').forEach(function(el){ void el.offsetHeight; });
        });
    });
</script>

</body>
</html>
