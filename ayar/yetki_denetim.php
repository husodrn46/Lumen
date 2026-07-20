<?php
declare(strict_types=1);

require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/yetki_denetim_helper.php';

ayar_require_m16($terminalkullanici);

$firmaFilter = isset($_GET['firma']) ? (int) $_GET['firma'] : (int) $firmano;
$durumFilter = trim((string) ($_GET['durum'] ?? 'aktif'));
$search = trim((string) ($_GET['ara'] ?? ''));

if (!in_array($durumFilter, ['aktif', 'pasif', 'tum'], true)) {
    $durumFilter = 'aktif';
}

$pageAuditItems = denetimSayfaListesi();

$permissionLabels = function_exists('m_p_yetki_etiketleri')
    ? m_p_yetki_etiketleri()
    : [];

$firmaStmt = $dbh->prepare("SELECT NR, NAME FROM L_CAPIFIRM ORDER BY NAME ASC");
$firmaStmt->execute();
$firmalar = $firmaStmt->fetchAll(PDO::FETCH_ASSOC);

$whereParts = ['S.FIRMNR = :firma'];
$params = [':firma' => $firmaFilter];

if ($durumFilter === 'aktif') {
    $whereParts[] = 'S.ACTIVE = 0';
} elseif ($durumFilter === 'pasif') {
    $whereParts[] = 'S.ACTIVE = 1';
}

if ($search !== '') {
    $whereParts[] = '(S.CODE LIKE :p1 OR S.DEFINITION_ LIKE :p2)';
    $params[':p1'] = '%' . $search . '%';
    $params[':p2'] = '%' . $search . '%';
}

$sql = "SELECT
            S.LOGICALREF,
            S.CODE,
            S.DEFINITION_,
            S.ACTIVE,
            S.FIRMNR,
            CASE WHEN Y.PERSONEL IS NULL THEN 0 ELSE 1 END AS HAS_YETKI_ROW,
            ISNULL(Y.YETKI, 2) AS YETKI_TURU
        FROM LG_SLSMAN S
        LEFT JOIN M_P_YETKI Y ON Y.PERSONEL = S.LOGICALREF
        WHERE " . implode(' AND ', $whereParts) . "
        ORDER BY S.ACTIVE ASC, S.CODE ASC";

$stmt = $dbh->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'toplam' => count($users),
    'aktif' => 0,
    'pasif' => 0,
    'yetki_kaydi_yok' => 0,
    'yonetici' => 0,
];

$userRows = [];
foreach ($users as $user) {
    $userId = (int) $user['LOGICALREF'];
    $role = (int) $user['YETKI_TURU'];
    $isActive = (int) $user['ACTIVE'] === 0;
    $hasYetkiRow = (int) $user['HAS_YETKI_ROW'] === 1;

    if ($isActive) {
        $summary['aktif']++;
    } else {
        $summary['pasif']++;
    }
    if (!$hasYetkiRow) {
        $summary['yetki_kaydi_yok']++;
    }
    if ($role === 0) {
        $summary['yonetici']++;
    }

    $enabledCodes = [];
    foreach ($permissionLabels as $code => $label) {
        if (!function_exists('m_p_yetki_izin_sutunu_mu') || !m_p_yetki_izin_sutunu_mu($code)) {
            continue;
        }
        if ((int) m_p_yetki($userId, $code) === 1) {
            $enabledCodes[] = $code;
        }
    }

    $pageAccess = [];
    $accessibleCount = 0;
    foreach ($pageAuditItems as $item) {
        $allowed = denetimBeklenenErisimVarMi($userId, $item);
        $pageAccess[] = $allowed;
        if ($allowed) {
            $accessibleCount++;
        }
    }

    $enabledPreview = array_slice($enabledCodes, 0, 8);
    $remainingEnabledCount = max(0, count($enabledCodes) - count($enabledPreview));

    $userRows[] = [
        'user' => $user,
        'role' => $role,
        'is_active' => $isActive,
        'has_yetki_row' => $hasYetkiRow,
        'enabled_codes' => $enabledCodes,
        'enabled_preview' => $enabledPreview,
        'enabled_remaining' => $remainingEnabledCount,
        'page_access' => $pageAccess,
        'accessible_count' => $accessibleCount,
    ];
}

$totalPageAudits = count($pageAuditItems);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yetki Denetim</title>
    <link rel="icon" type="image/png" href="../icon.png">
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
            --slate: #475569;
            --slate-soft: #f1f5f9;
        }
        * { box-sizing: border-box; margin: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
        }

        /* Sticky INDIGO header */
        .top-header {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(99, 102, 241, 0.18);
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.05);
        }
        .header-inner {
            max-width: 1280px; margin: 0 auto;
            padding: 14px 24px;
            display: flex; align-items: center; gap: 14px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
            flex-shrink: 0;
        }
        .header-back:hover { background: var(--indigo-soft); color: var(--indigo); }
        .header-divider { width: 1px; height: 24px; background: var(--border); flex-shrink: 0; }
        .header-icon {
            width: 36px; height: 36px; border-radius: 10px;
            background: var(--indigo-soft); color: var(--indigo);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 15px; flex-shrink: 0;
        }
        .header-titles { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1 1 auto; }
        .header-title { font-size: 16px; font-weight: 700; color: var(--text-1); line-height: 1.2; }
        .header-subtitle {
            font-size: 11.5px; color: var(--text-2);
            display: inline-flex; align-items: center; gap: 8px;
        }
        .header-subtitle .dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
        .header-subtitle .dot.em { background: var(--emerald); }
        .header-subtitle .dot.am { background: var(--amber); }

        main {
            max-width: 1280px; margin: 0 auto;
            padding: 20px 24px 60px;
        }

        /* Glass card base */
        .glass-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(99, 102, 241, 0.16);
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            will-change: opacity, transform;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        /* Info note */
        .info-note {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 12px 14px;
            border-radius: 12px;
            background: var(--indigo-soft);
            border: 1px solid rgba(99,102,241,0.22);
            color: var(--indigo);
            font-size: 12.5px; line-height: 1.5; font-weight: 500;
            margin-bottom: 14px;
            animation: cardIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .info-note i { font-size: 14px; flex-shrink: 0; margin-top: 2px; }
        .info-note strong { font-weight: 700; }

        /* Filter panel */
        .filter-panel {
            padding: 16px;
            margin-bottom: 14px;
            animation-delay: 0.05s;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1.4fr auto auto;
            gap: 12px; align-items: end;
        }
        .field { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
        .field label {
            font-size: 10.5px; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .field select,
        .field input[type="text"] {
            width: 100%;
            padding: 11px 14px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13px;
            color: var(--text-1);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            outline: none;
            transition: all 0.18s ease;
        }
        .field select:focus,
        .field input[type="text"]:focus {
            border-color: rgba(99,102,241,0.5);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.12);
        }
        .search-wrap { position: relative; }
        .search-wrap i.fa-search {
            position: absolute; top: 50%; left: 14px;
            transform: translateY(-50%);
            color: var(--text-3); font-size: 13px;
            pointer-events: none;
        }
        .search-wrap input { padding-left: 38px !important; }

        .btn-filter {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 11px 22px;
            background: linear-gradient(180deg, #6366f1 0%, #4f46e5 100%);
            color: #fff;
            border: 1px solid var(--indigo);
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13px; font-weight: 700;
            cursor: pointer; transition: all 0.18s ease;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2);
            height: 42px;
        }
        .btn-filter:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(79,70,229,0.3); filter: brightness(1.03); }
        .btn-reset {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 11px 14px;
            background: #fff;
            color: var(--text-2);
            border: 1px solid var(--border);
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13px; font-weight: 600;
            cursor: pointer; transition: all 0.18s ease;
            text-decoration: none; height: 42px;
        }
        .btn-reset:hover { background: #fafafa; border-color: var(--indigo); color: var(--indigo); }

        /* Stat grid */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 14px;
        }
        .stat-card {
            padding: 14px 16px;
            background: #fff;
            border-radius: 12px;
            border: 1px solid var(--border);
            display: flex; align-items: center; gap: 12px;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .stat-card.in { border-color: rgba(99,102,241,0.22); background: linear-gradient(135deg, var(--indigo-soft) 0%, #fff 60%); animation-delay: 0.06s; }
        .stat-card.em { border-color: rgba(5,150,105,0.22); background: linear-gradient(135deg, var(--emerald-soft) 0%, #fff 60%); animation-delay: 0.12s; }
        .stat-card.am { border-color: rgba(217,119,6,0.22); background: linear-gradient(135deg, var(--amber-soft) 0%, #fff 60%); animation-delay: 0.18s; }
        .stat-card.rd { border-color: rgba(111,16,34,0.22); background: linear-gradient(135deg, var(--red-soft) 0%, #fff 60%); animation-delay: 0.24s; }
        .stat-icon {
            width: 38px; height: 38px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 15px; flex-shrink: 0;
        }
        .stat-card.in .stat-icon { background: rgba(99,102,241,0.12); color: var(--indigo); }
        .stat-card.em .stat-icon { background: rgba(5,150,105,0.12); color: var(--emerald); }
        .stat-card.am .stat-icon { background: rgba(217,119,6,0.12); color: var(--amber); }
        .stat-card.rd .stat-icon { background: rgba(111,16,34,0.12); color: var(--red); }
        .stat-body { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .stat-val { font-size: 20px; font-weight: 700; color: var(--text-1); line-height: 1; }
        .stat-label { font-size: 11px; color: var(--text-2); font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; }

        /* Table card */
        .table-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(99, 102, 241, 0.16);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            overflow: hidden;
            will-change: opacity, transform;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) 0.28s both;
        }
        .table-head {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            gap: 10px;
            background: linear-gradient(180deg, #fff, #f8faff);
        }
        .table-head h3 {
            font-size: 13px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 8px;
        }
        .table-head h3 i { color: var(--indigo); }
        .table-head .count {
            font-size: 11px; color: var(--text-2);
            background: var(--slate-soft); border: 1px solid var(--border);
            padding: 4px 10px; border-radius: 100px; font-weight: 600;
        }

        .table-scroll { overflow-x: auto; }
        .gd-table { width: 100%; border-collapse: collapse; }
        .gd-table thead { background: #fff; }
        .gd-table thead th {
            padding: 11px 14px;
            font-size: 10px; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: 0.5px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .gd-table thead th.center { text-align: center; }
        .gd-table thead th.right { text-align: right; }
        .gd-table tbody td {
            padding: 12px 14px;
            font-size: 13px; color: var(--text-1);
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .gd-table tbody td.center { text-align: center; }
        .gd-table tbody tr { transition: background 0.15s ease; }
        .gd-table tbody tr:hover { background: var(--indigo-soft); }
        .gd-table tbody tr:last-child td { border-bottom: none; }

        .user-cell { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .user-avatar {
            width: 34px; height: 34px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px; flex-shrink: 0;
        }
        .user-avatar.em { background: var(--emerald-soft); color: var(--emerald); }
        .user-avatar.am { background: #f3f4f6; color: var(--text-3); }
        .user-cell .info { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .user-cell .name {
            font-weight: 600; color: var(--text-1);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            max-width: 260px;
        }
        .user-cell .name a { color: inherit; text-decoration: none; }
        .user-cell .name a:hover { color: var(--indigo); }
        .user-cell .code { font-size: 11px; color: var(--text-2); font-family: 'JetBrains Mono', monospace; }

        /* Chips */
        .chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: 100px;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.3px;
            border: 1px solid transparent;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            white-space: nowrap;
        }
        .chip .dot { width: 6px; height: 6px; border-radius: 50%; }
        .chip.em { background: var(--emerald-soft); color: var(--emerald); border-color: rgba(5,150,105,0.22); }
        .chip.em .dot { background: var(--emerald); }
        .chip.am { background: var(--amber-soft); color: var(--amber); border-color: rgba(217,119,6,0.22); }
        .chip.am .dot { background: var(--amber); }
        .chip.rd { background: var(--red-soft); color: var(--red); border-color: rgba(111,16,34,0.22); }
        .chip.rd .dot { background: var(--red); }
        .chip.sl { background: var(--slate-soft); color: var(--slate); border-color: var(--border); }
        .chip.sl .dot { background: var(--slate); }
        .chip.in { background: var(--indigo-soft); color: var(--indigo); border-color: rgba(99,102,241,0.22); }
        .chip.in .dot { background: var(--indigo); }

        /* Summary bar (page access progress) */
        .sum-wrap { display: flex; flex-direction: column; gap: 6px; min-width: 160px; }
        .sum-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 12px; }
        .sum-top .count { font-weight: 700; color: var(--text-1); }
        .sum-top .total { color: var(--text-2); font-size: 11px; }
        .sum-bar {
            width: 100%; height: 6px; background: #f1f5f9;
            border-radius: 100px; overflow: hidden;
        }
        .sum-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #6366f1 0%, #4f46e5 100%);
            border-radius: 100px;
            transition: width 0.35s ease;
        }
        .sum-bar-fill.full { background: linear-gradient(90deg, #10b981 0%, #059669 100%); }
        .sum-bar-fill.empty { background: linear-gradient(90deg, var(--red,#ef4444) 0%, var(--red,#6F1022) 100%); }

        /* Tag preview */
        .tag-list { display: flex; flex-wrap: wrap; gap: 4px; max-width: 280px; }
        .tag-mini {
            display: inline-flex; align-items: center;
            padding: 3px 8px; border-radius: 6px;
            font-size: 10.5px; font-weight: 600;
            background: var(--slate-soft); color: var(--slate);
            font-family: 'JetBrains Mono', monospace;
            border: 1px solid var(--border);
        }
        .tag-mini.more {
            background: var(--indigo-soft); color: var(--indigo);
            border-color: rgba(99,102,241,0.22);
        }
        .tag-empty { font-size: 11.5px; color: var(--text-3); font-style: italic; }

        /* Detail button */
        .btn-detail {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 8px 14px;
            background: #fff;
            color: var(--sky);
            border: 1px solid rgba(2,132,199,0.25);
            border-radius: 9px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 12px; font-weight: 700;
            text-decoration: none; cursor: pointer;
            transition: all 0.18s ease;
            white-space: nowrap;
        }
        .btn-detail:hover {
            background: var(--sky); color: #fff;
            border-color: var(--sky);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(2,132,199,0.28);
        }
        .btn-detail i { font-size: 11px; }

        /* Empty state */
        .empty-state { padding: 60px 20px; text-align: center; }
        .empty-state i { font-size: 44px; color: var(--text-3); margin-bottom: 12px; }
        .empty-state p { color: var(--text-1); font-weight: 600; margin-bottom: 4px; }
        .empty-state span { color: var(--text-2); font-size: 12.5px; }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        @media (max-width: 1100px) {
            .filter-grid { grid-template-columns: 1fr 1fr; }
            .filter-grid .btn-filter, .filter-grid .btn-reset { width: 100%; }
        }
        @media (max-width: 900px) {
            .stat-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 767px) {
            .header-inner { padding: 12px 14px; gap: 10px; }
            .header-title { font-size: 14px; }
            .header-subtitle { font-size: 10.5px; }
            .header-icon { width: 32px; height: 32px; font-size: 13px; }
            .header-back { width: 32px; height: 32px; }

            main { padding: 14px 12px 40px; }

            .filter-panel { padding: 12px; }
            .filter-grid { grid-template-columns: 1fr; gap: 10px; }
            .field select,
            .field input[type="text"] { font-size: 16px; padding: 12px 14px; min-height: 44px; }
            .btn-filter, .btn-reset { min-height: 44px; font-size: 14px; width: 100%; }

            .stat-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card { padding: 12px; }
            .stat-icon { width: 34px; height: 34px; }
            .stat-val { font-size: 18px; }

            /* Mobile: table stacks into cards */
            .table-head { flex-direction: column; align-items: flex-start; gap: 6px; }
            .gd-table thead { display: none; }
            .gd-table, .gd-table tbody, .gd-table tr, .gd-table td { display: block; width: 100%; }
            .gd-table tbody tr {
                padding: 14px;
                border-bottom: 1px solid #f3f4f6;
                display: grid;
                grid-template-columns: 1fr auto;
                gap: 10px 10px;
                align-items: center;
            }
            .gd-table tbody td { padding: 0; border-bottom: none; text-align: left !important; }
            .gd-table tbody td.m-user { grid-column: 1; }
            .gd-table tbody td.m-status { grid-column: 2; justify-self: end; }
            .gd-table tbody td.m-role,
            .gd-table tbody td.m-firma,
            .gd-table tbody td.m-summary,
            .gd-table tbody td.m-tags { grid-column: 1 / -1; }
            .gd-table tbody td.m-action { grid-column: 1 / -1; display: flex; justify-content: flex-end; padding-top: 6px; border-top: 1px dashed #f3f4f6; }
            .user-cell .name { max-width: 100%; white-space: normal; }
            .sum-wrap { min-width: 0; }
            .tag-list { max-width: 100%; }
            .btn-detail { min-height: 44px; padding: 10px 16px; }
            .m-label {
                display: inline-block;
                font-size: 10px; font-weight: 700; color: var(--text-2);
                text-transform: uppercase; letter-spacing: 0.5px;
                margin-bottom: 4px;
            }
        }
        @media (min-width: 768px) {
            .m-label { display: none; }
        }
    </style>
</head>
<body>

<?php $aktifSekme = 'denetim'; include __DIR__ . '/yetki_sekmeler.php'; ?>

<main>

    <div class="info-note">
        <i class="fa-solid fa-circle-info"></i>
        <span>
            <strong>Okuma notu:</strong> Kullanicilarin sayfa erisim yetkilerini toplu gorebilir ve detayli analiz icin
            satirdaki "Detay" butonunu kullanabilirsiniz. Sari rozetli kullanicilar yoneticidir; kirmizi rozetli kullanicilarin M_P_YETKI kaydi eksiktir.
        </span>
    </div>

    <!-- Filter panel -->
    <div class="filter-panel glass-card">
        <form method="GET" class="filter-grid">
            <div class="field">
                <label for="firma">Firma</label>
                <select id="firma" name="firma">
                    <?php foreach ($firmalar as $firmaItem): ?>
                        <option value="<?= (int) $firmaItem['NR'] ?>" <?= ((int) $firmaItem['NR'] === $firmaFilter) ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $firmaItem['NAME'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="durum">Durum</label>
                <select id="durum" name="durum">
                    <option value="aktif" <?= $durumFilter === 'aktif' ? 'selected' : '' ?>>Sadece aktif</option>
                    <option value="pasif" <?= $durumFilter === 'pasif' ? 'selected' : '' ?>>Sadece pasif</option>
                    <option value="tum" <?= $durumFilter === 'tum' ? 'selected' : '' ?>>Tumu</option>
                </select>
            </div>
            <div class="field">
                <label for="ara">Kullanici Ara</label>
                <div class="search-wrap">
                    <i class="fa-solid fa-search"></i>
                    <input type="text" id="ara" name="ara"
                           value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Kod veya isim..." autocomplete="off">
                </div>
            </div>
            <button type="submit" class="btn-filter">
                <i class="fa-solid fa-filter"></i> Filtrele
            </button>
            <a href="yetki_denetim.php" class="btn-reset" title="Filtreleri sifirla">
                <i class="fa-solid fa-rotate-left"></i>
            </a>
        </form>
    </div>

    <!-- Stat cards -->
    <div class="stat-grid">
        <div class="stat-card in">
            <span class="stat-icon"><i class="fa-solid fa-users"></i></span>
            <div class="stat-body">
                <span class="stat-val"><?= (int) $summary['toplam'] ?></span>
                <span class="stat-label">Toplam Kullanici</span>
            </div>
        </div>
        <div class="stat-card em">
            <span class="stat-icon"><i class="fa-solid fa-user-check"></i></span>
            <div class="stat-body">
                <span class="stat-val"><?= (int) $summary['aktif'] ?></span>
                <span class="stat-label">Aktif</span>
            </div>
        </div>
        <div class="stat-card am">
            <span class="stat-icon"><i class="fa-solid fa-user-slash"></i></span>
            <div class="stat-body">
                <span class="stat-val"><?= (int) $summary['pasif'] ?></span>
                <span class="stat-label">Pasif</span>
            </div>
        </div>
        <div class="stat-card rd">
            <span class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
            <div class="stat-body">
                <span class="stat-val"><?= (int) $summary['yetki_kaydi_yok'] ?></span>
                <span class="stat-label">Yetki Kaydi Eksik</span>
            </div>
        </div>
    </div>

    <!-- Users table -->
    <div class="table-card">
        <div class="table-head">
            <h3><i class="fa-solid fa-list-check"></i> Kullanici Listesi</h3>
            <span class="count"><?= (int) $summary['toplam'] ?> kayit</span>
        </div>

        <?php if (empty($userRows)): ?>
            <div class="empty-state">
                <i class="fa-solid fa-user-slash"></i>
                <p>Kullanici Bulunamadi</p>
                <span>Arama kriterlerinizi degistirerek tekrar deneyin.</span>
            </div>
        <?php else: ?>
            <div class="table-scroll">
                <table class="gd-table">
                    <thead>
                        <tr>
                            <th>Kullanici</th>
                            <th>Rol</th>
                            <th>Durum</th>
                            <th>Kayit</th>
                            <th>Sayfa Erisim Ozeti</th>
                            <th>Etkin Yetkiler</th>
                            <th class="right">Detay</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($userRows as $row):
                            $user = $row['user'];
                            $role = (int) $row['role'];
                            $isActive = (bool) $row['is_active'];
                            $hasYetki = (bool) $row['has_yetki_row'];
                            $code = (string) $user['CODE'];
                            $name = (string) $user['DEFINITION_'];
                            $uid = (int) $user['LOGICALREF'];
                            $accessible = (int) $row['accessible_count'];
                            $totalItems = max(1, $totalPageAudits);
                            $accessPct = (int) round(($accessible / $totalItems) * 100);

                            $barClass = '';
                            if ($totalPageAudits > 0) {
                                if ($accessible === $totalPageAudits) { $barClass = 'full'; }
                                elseif ($accessible === 0) { $barClass = 'empty'; }
                            }

                            $rolSinifi = denetimRolSinifi($role);
                            $rolAdi = denetimRolAdi($role);
                            $chipRole = 'sl';
                            if ($role === 0) { $chipRole = 'am'; }
                            elseif ($role === 1) { $chipRole = 'in'; }
                            elseif ($role === 2) { $chipRole = 'sl'; }

                            $enabledTooltip = implode(', ', $row['enabled_codes']);
                        ?>
                            <tr>
                                <td class="m-user">
                                    <div class="user-cell">
                                        <span class="user-avatar <?= $isActive ? 'em' : 'am' ?>">
                                            <i class="fa-solid fa-user"></i>
                                        </span>
                                        <div class="info">
                                            <span class="name" title="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">
                                                <a href="yetki_denetim_detay.php?id=<?= $uid ?>"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></a>
                                            </span>
                                            <span class="code"><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?> &middot; ID: <?= $uid ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="m-role">
                                    <span class="m-label">Rol</span>
                                    <span class="chip <?= $chipRole ?>">
                                        <span class="dot"></span>
                                        <?= htmlspecialchars($rolAdi, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td class="m-status">
                                    <?php if ($isActive): ?>
                                        <span class="chip em"><span class="dot"></span>Aktif</span>
                                    <?php else: ?>
                                        <span class="chip sl"><span class="dot"></span>Pasif</span>
                                    <?php endif; ?>
                                </td>
                                <td class="m-firma">
                                    <span class="m-label">Yetki Kaydi</span>
                                    <?php if ($hasYetki): ?>
                                        <span class="chip em"><span class="dot"></span>Var</span>
                                    <?php else: ?>
                                        <span class="chip rd"><span class="dot"></span>Eksik</span>
                                    <?php endif; ?>
                                </td>
                                <td class="m-summary">
                                    <span class="m-label">Sayfa Erisim</span>
                                    <div class="sum-wrap">
                                        <div class="sum-top">
                                            <span class="count"><?= $accessible ?> / <?= (int) $totalPageAudits ?></span>
                                            <span class="total"><?= $accessPct ?>%</span>
                                        </div>
                                        <div class="sum-bar">
                                            <div class="sum-bar-fill <?= $barClass ?>" style="width: <?= $accessPct ?>%;"></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="m-tags">
                                    <span class="m-label">Etkin Yetkiler</span>
                                    <div class="tag-list" title="<?= htmlspecialchars($enabledTooltip, ENT_QUOTES, 'UTF-8') ?>">
                                        <?php if (empty($row['enabled_preview'])): ?>
                                            <span class="tag-empty">Yetki yok</span>
                                        <?php else: ?>
                                            <?php foreach ($row['enabled_preview'] as $code2): ?>
                                                <span class="tag-mini"><?= htmlspecialchars($code2, ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endforeach; ?>
                                            <?php if ($row['enabled_remaining'] > 0): ?>
                                                <span class="tag-mini more">+<?= (int) $row['enabled_remaining'] ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="m-action" style="text-align:right;">
                                    <a href="yetki_denetim_detay.php?id=<?= $uid ?>" class="btn-detail" title="Detayli incele">
                                        <i class="fa-solid fa-arrow-right-long"></i> Detay
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</main>

<script>
    // Card animation reflow fix
    window.addEventListener('load', function() {
        document.querySelectorAll('.glass-card, .stat-card, .table-card').forEach(function(el) {
            requestAnimationFrame(function() {
                el.style.willChange = 'auto';
            });
        });
    });
</script>
</body>
</html>
