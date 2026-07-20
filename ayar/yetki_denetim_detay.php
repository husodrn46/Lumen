<?php
declare(strict_types=1);

require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/yetki_denetim_helper.php';

ayar_require_m16($terminalkullanici);

$userId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($userId <= 0) {
    header('Location: yetki_denetim.php?hata=gecersiz_kullanici', true, 303);
    exit;
}

$permissionLabels = function_exists('m_p_yetki_etiketleri')
    ? m_p_yetki_etiketleri()
    : [];
$pageAuditItems = denetimSayfaListesi();
$fieldGroups = denetimAlanYetkiGruplari();

$stmt = $dbh->prepare("SELECT
        S.LOGICALREF,
        S.CODE,
        S.DEFINITION_,
        S.ACTIVE,
        S.FIRMNR,
        CASE WHEN Y.PERSONEL IS NULL THEN 0 ELSE 1 END AS HAS_YETKI_ROW,
        ISNULL(Y.YETKI, 2) AS YETKI_TURU
    FROM LG_SLSMAN S
    LEFT JOIN M_P_YETKI Y ON Y.PERSONEL = S.LOGICALREF
    WHERE S.LOGICALREF = :id");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: yetki_denetim.php?hata=kullanici_bulunamadi', true, 303);
    exit;
}

$role = (int) $user['YETKI_TURU'];
$baseUrl = denetimBaseUrl();
$probeSession = denetimProbeSessionOlustur($userId);
$probeResults = [];
$matchCount = 0;
$mismatchCount = 0;

foreach ($pageAuditItems as $item) {
    $expectedAllowed = denetimBeklenenErisimVarMi($userId, $item);
    $probe = denetimHttpProbe($baseUrl, $probeSession['session_name'], $probeSession['session_id'], $item['path']);
    $actualAllowed = in_array($probe['status'], ['allowed', 'redirect'], true);
    $isMatch = ($expectedAllowed === $actualAllowed);

    if ($isMatch) {
        $matchCount++;
    } else {
        $mismatchCount++;
    }

    $probeResults[] = [
        'item' => $item,
        'expected_allowed' => $expectedAllowed,
        'actual_allowed' => $actualAllowed,
        'is_match' => $isMatch,
        'probe' => $probe,
    ];
}

denetimProbeSessionTemizle($probeSession['session_id']);

$fieldAudit = [];
foreach ($fieldGroups as $groupName => $codes) {
    $items = [];
    foreach ($codes as $code) {
        $items[] = [
            'code' => $code,
            'label' => $permissionLabels[$code] ?? $code,
            'allowed' => ((int) m_p_yetki($userId, $code) === 1),
        ];
    }
    $fieldAudit[$groupName] = $items;
}

$csvEscape = static function (mixed $value): string {
    $text = (string) $value;
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    return '"' . str_replace('"', '""', $text) . '"';
};

$csvLines = [];
$csvLines[] = implode(',', [
    $csvEscape('BOLUM'),
    $csvEscape('TIP'),
    $csvEscape('ALAN_1'),
    $csvEscape('ALAN_2'),
    $csvEscape('ALAN_3'),
    $csvEscape('ALAN_4'),
    $csvEscape('ALAN_5'),
    $csvEscape('ALAN_6'),
    $csvEscape('ALAN_7'),
    $csvEscape('ALAN_8'),
]);

$csvLines[] = implode(',', [
    $csvEscape('OZET'),
    $csvEscape('KULLANICI'),
    $csvEscape($user['DEFINITION_'] ?? ''),
    $csvEscape($user['CODE'] ?? ''),
    $csvEscape($user['LOGICALREF'] ?? ''),
    $csvEscape($user['FIRMNR'] ?? ''),
    $csvEscape(denetimRolAdi($role)),
    $csvEscape(((int) $user['ACTIVE'] === 0) ? 'Aktif' : 'Pasif'),
    $csvEscape(((int) $user['HAS_YETKI_ROW'] === 1) ? 'Var' : 'Eksik'),
    $csvEscape($baseUrl),
]);

$csvLines[] = implode(',', [
    $csvEscape('OZET'),
    $csvEscape('TEST_SONUCU'),
    $csvEscape('Toplam Sayfa'),
    $csvEscape(count($probeResults)),
    $csvEscape('Uyusan'),
    $csvEscape($matchCount),
    $csvEscape('Uyusmayan'),
    $csvEscape($mismatchCount),
    $csvEscape(''),
    $csvEscape(''),
]);

foreach ($fieldAudit as $groupName => $items) {
    foreach ($items as $item) {
        $csvLines[] = implode(',', [
            $csvEscape('ALAN_YETKI'),
            $csvEscape($groupName),
            $csvEscape($item['code']),
            $csvEscape($item['label']),
            $csvEscape($item['allowed'] ? 'Acik' : 'Kapali'),
            $csvEscape(''),
            $csvEscape(''),
            $csvEscape(''),
            $csvEscape(''),
            $csvEscape(''),
        ]);
    }
}

foreach ($probeResults as $result) {
    $detailText = $result['probe']['transport_error'] !== ''
        ? $result['probe']['transport_error']
        : $result['probe']['snippet'];

    $csvLines[] = implode(',', [
        $csvEscape('SAYFA_TEST'),
        $csvEscape($result['item']['label']),
        $csvEscape(denetimKodMetni($result['item'])),
        $csvEscape($result['item']['path']),
        $csvEscape($result['expected_allowed'] ? 'Girmeli' : 'Girmemeli'),
        $csvEscape(denetimSonucRozeti($result['probe']['status'])['label']),
        $csvEscape($result['probe']['http_code']),
        $csvEscape($result['probe']['location']),
        $csvEscape($result['probe']['duration_ms']),
        $csvEscape($result['is_match'] ? 'Uyustu' : 'Sorun: beklenen ile gercek farkli'),
    ]);

    if ($detailText !== '') {
        $csvLines[] = implode(',', [
            $csvEscape('SAYFA_DETAY'),
            $csvEscape($result['item']['label']),
            $csvEscape('Detay'),
            $csvEscape($detailText),
            $csvEscape(''),
            $csvEscape(''),
            $csvEscape(''),
            $csvEscape(''),
            $csvEscape(''),
            $csvEscape(''),
        ]);
    }
}

$csvExportContent = implode("\n", $csvLines);

$userKodu  = (string) ($user['CODE'] ?? '');
$userAdi   = (string) ($user['DEFINITION_'] ?? '');
$userFirma = (int) ($user['FIRMNR'] ?? 0);
$userRef   = (int) ($user['LOGICALREF'] ?? 0);
$isActive  = ((int) $user['ACTIVE'] === 0);
$hasYetki  = ((int) $user['HAS_YETKI_ROW'] === 1);
$roleName  = denetimRolAdi($role);

// Rol chip rengi (emerald=admin, amber=personel, sky=musteri)
$roleChipClass = 'chip-sky';
if ($role === 0) { $roleChipClass = 'chip-emerald'; }
elseif ($role === 1) { $roleChipClass = 'chip-amber'; }
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yetki Denetim Detay - <?php echo htmlspecialchars($userKodu, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="/tm/css/tailwind.js" onerror="var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);"></script>
    <style>
        :root {
            --bg: #f9fafb;
            --surface: #ffffff;
            --text-1: #1f2937;
            --text-2: #6b7280;
            --text-3: #9ca3af;
            --border: #e5e7eb;
            --border-soft: #f1f5f9;
            --indigo: #4f46e5;
            --indigo-soft: #eef2ff;
            --emerald: #059669;
            --emerald-soft: #ecfdf5;
            --amber: #d97706;
            --amber-soft: #fffbeb;
            --sky: #0284c7;
            --sky-soft: #eff6ff;
            --red: #6F1022;
            --red-soft: #fef2f2;
            --slate: #475569;
            --slate-soft: #f1f5f9;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { -webkit-text-size-adjust: 100%; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* Sticky indigo top header */
        .top-header {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(79, 70, 229, 0.18);
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.05);
        }
        .header-inner {
            max-width: 1200px; margin: 0 auto;
            padding: 12px 22px;
            display: flex; align-items: center; gap: 12px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 38px; height: 38px; border-radius: 10px;
            color: var(--text-2); text-decoration: none;
            background: transparent; border: none; cursor: pointer;
            transition: all .2s ease;
        }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--indigo); }
        .header-divider { width: 1px; height: 22px; background: var(--border); }
        .header-title-wrap { flex: 1 1 auto; min-width: 0; }
        .header-title {
            font-size: 15px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 8px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .header-title i { color: var(--indigo); font-size: 14px; }
        .header-sub {
            display: block; font-size: 11.5px; font-weight: 500;
            color: var(--text-2); margin-top: 2px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .header-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 10px;
            background: var(--indigo-soft);
            color: var(--indigo);
            border: 1px solid rgba(79, 70, 229, 0.22);
            border-radius: 999px;
            font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.4px;
            white-space: nowrap;
        }

        /* Page wrap */
        .page-wrap {
            max-width: 1200px; margin: 0 auto;
            padding: 20px 22px 48px;
        }

        /* Glass card */
        .glass-card {
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 16px;
            box-shadow: 0 1px 2px rgba(15,23,42,0.04), 0 8px 20px -12px rgba(15,23,42,0.08);
            overflow: hidden;
            opacity: 0;
            transform: translateY(8px);
            will-change: opacity, transform;
        }
        .glass-card.cardIn {
            animation: cardIn .42s cubic-bezier(.16,.84,.44,1) forwards;
        }
        @keyframes cardIn {
            to { opacity: 1; transform: translateY(0); }
        }

        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-soft);
            display: flex; align-items: center; gap: 10px;
        }
        .card-header i { color: var(--indigo); }
        .card-title {
            font-size: 14px; font-weight: 700; color: var(--text-1);
            letter-spacing: -0.01em;
        }
        .card-sub { font-size: 12px; color: var(--text-2); margin-top: 2px; }
        .card-body { padding: 20px; }

        /* Hero */
        .hero {
            display: flex; align-items: center; gap: 18px;
            padding: 22px;
            background: linear-gradient(135deg, rgba(79,70,229,0.06), rgba(14,165,233,0.04));
        }
        .hero-avatar {
            flex: 0 0 auto;
            width: 64px; height: 64px; border-radius: 18px;
            background: linear-gradient(135deg, var(--indigo), #6366f1);
            color: #fff;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 28px;
            box-shadow: 0 10px 24px -8px rgba(79,70,229,0.55);
        }
        .hero-info { flex: 1 1 auto; min-width: 0; }
        .hero-name {
            font-size: 20px; font-weight: 700; color: var(--text-1);
            letter-spacing: -0.01em; line-height: 1.25;
            word-break: break-word;
        }
        .hero-meta {
            display: flex; flex-wrap: wrap; gap: 6px 14px;
            margin-top: 6px; font-size: 12.5px; color: var(--text-2);
        }
        .hero-meta strong { color: var(--text-1); font-weight: 600; }
        .hero-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }

        /* Chips */
        .chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 11px;
            border-radius: 999px;
            font-size: 11.5px; font-weight: 700;
            letter-spacing: 0.2px;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .chip i { font-size: 10px; }
        .chip-emerald { background: var(--emerald-soft); color: var(--emerald); border-color: rgba(5,150,105,0.22); }
        .chip-amber   { background: var(--amber-soft);   color: var(--amber);   border-color: rgba(217,119,6,0.22); }
        .chip-sky     { background: var(--sky-soft);     color: var(--sky);     border-color: rgba(2,132,199,0.22); }
        .chip-indigo  { background: var(--indigo-soft);  color: var(--indigo);  border-color: rgba(79,70,229,0.22); }
        .chip-red     { background: var(--red-soft);     color: var(--red);     border-color: rgba(111,16,34,0.22); }
        .chip-slate   { background: var(--slate-soft);   color: var(--slate);   border-color: rgba(71,85,105,0.22); }

        /* Info grid */
        .info-grid {
            display: grid; grid-template-columns: repeat(2, minmax(0,1fr));
            gap: 12px;
        }
        .info-cell {
            background: #fff;
            border: 1px solid var(--border-soft);
            border-radius: 12px;
            padding: 12px 14px;
        }
        .info-label {
            font-size: 11px; font-weight: 600;
            color: var(--text-3); text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .info-value {
            font-size: 14px; font-weight: 600;
            color: var(--text-1); margin-top: 4px;
            word-break: break-word;
        }

        /* Stat tiles */
        .stats-grid {
            display: grid; grid-template-columns: repeat(4, minmax(0,1fr));
            gap: 12px;
        }
        .stat-tile {
            background: #fff;
            border: 1px solid var(--border-soft);
            border-radius: 12px;
            padding: 14px 16px;
        }
        .stat-label { font-size: 11.5px; color: var(--text-2); font-weight: 500; }
        .stat-value {
            font-size: 26px; font-weight: 800; color: var(--text-1);
            margin-top: 6px; letter-spacing: -0.02em;
        }
        .stat-value.em { color: var(--emerald); }
        .stat-value.rd { color: var(--red); }
        .stat-url {
            font-size: 12px; font-weight: 600; color: var(--text-1);
            margin-top: 6px; word-break: break-all;
        }

        /* Permission matrix */
        .perm-groups {
            display: grid; grid-template-columns: repeat(3, minmax(0,1fr));
            gap: 12px;
        }
        .perm-group {
            background: #fff;
            border: 1px solid var(--border-soft);
            border-radius: 12px;
            padding: 14px 16px;
        }
        .perm-group-title {
            font-size: 13px; font-weight: 700; color: var(--text-1);
            margin-bottom: 10px;
            display: flex; align-items: center; gap: 8px;
        }
        .perm-group-title i { color: var(--indigo); font-size: 12px; }
        .perm-row {
            display: flex; align-items: center; justify-content: space-between;
            gap: 10px;
            padding: 8px 10px;
            border: 1px solid var(--border-soft);
            border-radius: 10px;
            margin-bottom: 6px;
        }
        .perm-row:last-child { margin-bottom: 0; }
        .perm-row-label { font-size: 13px; font-weight: 600; color: var(--text-1); }
        .perm-row-code {
            font-size: 10.5px; font-weight: 600; color: var(--text-3);
            margin-top: 2px; text-transform: uppercase; letter-spacing: 0.3px;
        }

        /* Results table */
        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .data-table { min-width: 100%; border-collapse: collapse; font-size: 13px; }
        .data-table thead th {
            background: var(--slate-soft);
            color: var(--text-2);
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.4px;
            padding: 10px 12px; text-align: left;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .data-table thead th.center { text-align: center; }
        .data-table tbody td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border-soft);
            color: var(--text-1);
            vertical-align: top;
        }
        .data-table tbody td.center { text-align: center; }
        .data-table tbody tr:hover td { background: rgba(79,70,229,0.03); }
        .cell-strong { font-weight: 600; color: var(--text-1); }
        .cell-muted { font-size: 11px; color: var(--text-3); margin-top: 2px; word-break: break-all; }

        /* Actions row */
        .actions-row {
            display: flex; flex-wrap: wrap; gap: 10px;
            padding: 14px 20px;
            border-top: 1px solid var(--border-soft);
            background: rgba(248,250,252,0.6);
        }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            min-height: 44px;
            padding: 10px 16px;
            border-radius: 12px;
            font-size: 13.5px; font-weight: 600;
            text-decoration: none;
            cursor: pointer; border: 1px solid transparent;
            transition: all .2s ease;
        }
        .btn-indigo { background: var(--indigo); color: #fff; border-color: var(--indigo); }
        .btn-indigo:hover { background: #4338ca; border-color: #4338ca; }
        .btn-sky-ghost { background: var(--sky-soft); color: var(--sky); border-color: rgba(2,132,199,0.22); }
        .btn-sky-ghost:hover { background: #dbeafe; }
        .btn-ghost { background: #fff; color: var(--text-2); border-color: var(--border); }
        .btn-ghost:hover { background: var(--slate-soft); color: var(--text-1); }
        .btn-red-ghost { background: var(--red-soft); color: var(--red); border-color: rgba(111,16,34,0.22); }
        .btn-red-ghost:hover { background: #fee2e2; }

        .copy-status { font-size: 12.5px; color: var(--text-2); align-self: center; }
        .copy-status.ok { color: var(--emerald); }
        .copy-status.err { color: var(--red); }

        .sr-only {
            position: absolute !important; width: 1px; height: 1px;
            padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0);
            white-space: nowrap; border: 0;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .perm-groups { grid-template-columns: repeat(2, minmax(0,1fr)); }
            .stats-grid { grid-template-columns: repeat(2, minmax(0,1fr)); }
        }
        @media (max-width: 680px) {
            .header-inner { padding: 10px 14px; }
            .header-badge { display: none; }
            .page-wrap { padding: 14px 14px 40px; }
            .hero { flex-direction: row; padding: 18px; }
            .hero-avatar { width: 52px; height: 52px; font-size: 22px; border-radius: 14px; }
            .hero-name { font-size: 17px; }
            .info-grid { grid-template-columns: 1fr; }
            .perm-groups { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, minmax(0,1fr)); }
            .card-body { padding: 16px; }
            .card-header { padding: 14px 16px; }
            .actions-row { padding: 14px 16px; }
            .btn { width: 100%; }
            .copy-status { width: 100%; text-align: center; }
            /* 16px inputs - iOS */
            input, select, textarea, button { font-size: 16px; }
        }

        /* Print */
        @media print {
            body { background: #fff; }
            .top-header, .actions-row, .btn { display: none !important; }
            .glass-card {
                box-shadow: none !important;
                border: 1px solid #e5e7eb !important;
                background: #fff !important;
                backdrop-filter: none !important;
                animation: none !important;
                opacity: 1 !important;
                transform: none !important;
                break-inside: avoid;
                margin-bottom: 12px;
            }
            .page-wrap { max-width: none; padding: 0; }
            .data-table tbody tr:hover td { background: transparent; }
        }
    </style>
</head>
<body>

<header class="top-header">
    <div class="header-inner">
        <a href="yetki_denetim.php?firma=<?php echo $userFirma; ?>" class="header-back" title="Geri">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="header-divider"></div>
        <div class="header-title-wrap">
            <div class="header-title">
                <i class="fa-solid fa-user-shield"></i>
                Yetki Denetim Detay
            </div>
            <span class="header-sub">
                <?php echo htmlspecialchars($userKodu, ENT_QUOTES, 'UTF-8'); ?>
                &middot;
                <?php echo htmlspecialchars($userAdi, ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>
        <span class="header-badge">
            <i class="fa-solid fa-shield-halved"></i>
            Canli Test
        </span>
    </div>
</header>

<main class="page-wrap">

    <!-- Hero -->
    <section class="glass-card" style="margin-bottom: 14px;" data-stagger="0">
        <div class="hero">
            <div class="hero-avatar">
                <i class="fa-solid fa-user-shield"></i>
            </div>
            <div class="hero-info">
                <div class="hero-name"><?php echo htmlspecialchars($userAdi, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="hero-meta">
                    <span>Kod: <strong><?php echo htmlspecialchars($userKodu, ENT_QUOTES, 'UTF-8'); ?></strong></span>
                    <span>ID: <strong><?php echo $userRef; ?></strong></span>
                    <span>Firma: <strong><?php echo $userFirma; ?></strong></span>
                </div>
                <div class="hero-chips">
                    <span class="chip <?php echo $roleChipClass; ?>">
                        <i class="fa-solid fa-id-badge"></i>
                        <?php echo htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <span class="chip <?php echo $isActive ? 'chip-emerald' : 'chip-slate'; ?>">
                        <i class="fa-solid <?php echo $isActive ? 'fa-circle-check' : 'fa-circle-minus'; ?>"></i>
                        <?php echo $isActive ? 'Aktif' : 'Pasif'; ?>
                    </span>
                    <span class="chip <?php echo $hasYetki ? 'chip-emerald' : 'chip-red'; ?>">
                        <i class="fa-solid <?php echo $hasYetki ? 'fa-check' : 'fa-triangle-exclamation'; ?>"></i>
                        <?php echo $hasYetki ? 'Yetki Kaydi Var' : 'Yetki Kaydi Eksik'; ?>
                    </span>
                </div>
            </div>
        </div>
        <div class="actions-row">
            <button type="button" id="copyCsvButton" class="btn btn-sky-ghost">
                <i class="fa-solid fa-copy"></i>
                CSV Kopyala
            </button>
            <a href="mobilkullanicix.php?perid=<?php echo $userRef; ?>" class="btn btn-indigo">
                <i class="fa-solid fa-pen-to-square"></i>
                Duzenle
            </a>
            <a href="yetki_denetim_detay.php?id=<?php echo $userRef; ?>" class="btn btn-red-ghost">
                <i class="fa-solid fa-rotate"></i>
                Tekrar Test
            </a>
            <a href="yetki_denetim.php?firma=<?php echo $userFirma; ?>" class="btn btn-ghost">
                <i class="fa-solid fa-arrow-left"></i>
                Geri
            </a>
            <span id="copyCsvStatus" class="copy-status">
                Butona basarak ozet, alan yetkileri ve canli test sonuclarini panoya kopyalayabilirsin.
            </span>
        </div>
    </section>

    <!-- Info grid -->
    <section class="glass-card" style="margin-bottom: 14px;" data-stagger="1">
        <div class="card-header">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <div class="card-title">Kullanici Bilgileri</div>
                <div class="card-sub">LG_SLSMAN kaydi ve M_P_YETKI durumu</div>
            </div>
        </div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-cell">
                    <div class="info-label">Firma</div>
                    <div class="info-value"><?php echo $userFirma; ?></div>
                </div>
                <div class="info-cell">
                    <div class="info-label">Personel ID</div>
                    <div class="info-value"><?php echo $userRef; ?></div>
                </div>
                <div class="info-cell">
                    <div class="info-label">Kod</div>
                    <div class="info-value"><?php echo htmlspecialchars($userKodu, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="info-cell">
                    <div class="info-label">Yetki Tipi</div>
                    <div class="info-value"><?php echo htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="info-cell">
                    <div class="info-label">Durum</div>
                    <div class="info-value"><?php echo $isActive ? 'Aktif' : 'Pasif'; ?></div>
                </div>
                <div class="info-cell">
                    <div class="info-label">Yetki Kaydi</div>
                    <div class="info-value"><?php echo $hasYetki ? 'Var' : 'Eksik'; ?></div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stat tiles -->
    <section class="glass-card" style="margin-bottom: 14px;" data-stagger="2">
        <div class="card-header">
            <i class="fa-solid fa-chart-simple"></i>
            <div>
                <div class="card-title">Canli Test Ozeti</div>
                <div class="card-sub">Beklenen ile gercek HTTP sonucu karsilastirmasi</div>
            </div>
        </div>
        <div class="card-body">
            <div class="stats-grid">
                <div class="stat-tile">
                    <div class="stat-label">Test Edilen Sayfa</div>
                    <div class="stat-value"><?php echo count($probeResults); ?></div>
                </div>
                <div class="stat-tile">
                    <div class="stat-label">Uyusan Test</div>
                    <div class="stat-value em"><?php echo $matchCount; ?></div>
                </div>
                <div class="stat-tile">
                    <div class="stat-label">Uyusmayan Test</div>
                    <div class="stat-value rd"><?php echo $mismatchCount; ?></div>
                </div>
                <div class="stat-tile">
                    <div class="stat-label">Baz URL</div>
                    <div class="stat-url"><?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>
        </div>
    </section>

    <!-- Permission matrix -->
    <section class="glass-card" style="margin-bottom: 14px;" data-stagger="3">
        <div class="card-header">
            <i class="fa-solid fa-list-check"></i>
            <div>
                <div class="card-title">Alan Yetki Matrisi</div>
                <div class="card-sub">M kodlariyla aktif / pasif durum</div>
            </div>
        </div>
        <div class="card-body">
            <div class="perm-groups">
                <?php foreach ($fieldAudit as $groupName => $items): ?>
                    <div class="perm-group">
                        <div class="perm-group-title">
                            <i class="fa-solid fa-folder-open"></i>
                            <?php echo htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <?php foreach ($items as $item): ?>
                            <div class="perm-row">
                                <div style="min-width:0;">
                                    <div class="perm-row-label"><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="perm-row-code"><?php echo htmlspecialchars($item['code'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <span class="chip <?php echo $item['allowed'] ? 'chip-emerald' : 'chip-slate'; ?>">
                                    <i class="fa-solid <?php echo $item['allowed'] ? 'fa-check' : 'fa-xmark'; ?>"></i>
                                    <?php echo $item['allowed'] ? 'Aktif' : 'Pasif'; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Probe results -->
    <section class="glass-card" data-stagger="4">
        <div class="card-header">
            <i class="fa-solid fa-globe"></i>
            <div>
                <div class="card-title">Gercek Sayfa Sonuclari</div>
                <div class="card-sub">HTTP probe ile dogrulanmis sayfa erisimleri</div>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Sayfa</th>
                        <th class="center">Kod</th>
                        <th class="center">Beklenen</th>
                        <th class="center">Gercek</th>
                        <th class="center">HTTP</th>
                        <th class="center">Sure</th>
                        <th class="center">Kontrol</th>
                        <th>Detay</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($probeResults as $result): ?>
                        <?php
                        $badge = denetimSonucRozeti($result['probe']['status']);
                        // map backend badge class to local chip class if possible
                        $statusClass = 'chip-slate';
                        if (stripos($badge['class'], 'emerald') !== false) { $statusClass = 'chip-emerald'; }
                        elseif (stripos($badge['class'], 'red') !== false) { $statusClass = 'chip-red'; }
                        elseif (stripos($badge['class'], 'amber') !== false || stripos($badge['class'], 'yellow') !== false) { $statusClass = 'chip-amber'; }
                        elseif (stripos($badge['class'], 'sky') !== false || stripos($badge['class'], 'blue') !== false) { $statusClass = 'chip-sky'; }
                        elseif (stripos($badge['class'], 'indigo') !== false) { $statusClass = 'chip-indigo'; }
                        ?>
                        <tr>
                            <td>
                                <a href="<?php echo htmlspecialchars(denetimLinki($result['item']['path']), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="cell-strong" style="color: var(--indigo); text-decoration: none;">
                                    <?php echo htmlspecialchars($result['item']['label'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                                <div class="cell-muted"><?php echo htmlspecialchars($result['item']['path'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </td>
                            <td class="center cell-strong"><?php echo htmlspecialchars((string) ($result['item']['kod'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="center">
                                <span class="chip <?php echo $result['expected_allowed'] ? 'chip-emerald' : 'chip-slate'; ?>">
                                    <?php echo $result['expected_allowed'] ? 'Girmeli' : 'Girmemeli'; ?>
                                </span>
                            </td>
                            <td class="center">
                                <span class="chip <?php echo $statusClass; ?>">
                                    <?php echo htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td class="center cell-strong">
                                <?php echo (int) $result['probe']['http_code']; ?>
                                <?php if ($result['probe']['location'] !== ''): ?>
                                    <div class="cell-muted"><?php echo htmlspecialchars($result['probe']['location'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="center"><?php echo htmlspecialchars((string) $result['probe']['duration_ms'], ENT_QUOTES, 'UTF-8'); ?> ms</td>
                            <td class="center">
                                <span class="chip <?php echo $result['is_match'] ? 'chip-emerald' : 'chip-red'; ?>">
                                    <i class="fa-solid <?php echo $result['is_match'] ? 'fa-check' : 'fa-triangle-exclamation'; ?>"></i>
                                    <?php echo $result['is_match'] ? 'Uyustu' : 'Sorun'; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($result['probe']['transport_error'] !== ''): ?>
                                    <div style="color: var(--red); font-size: 12px; font-weight: 600;"><?php echo htmlspecialchars($result['probe']['transport_error'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php elseif ($result['probe']['snippet'] !== ''): ?>
                                    <div style="color: var(--text-2); font-size: 12px; line-height: 1.5;"><?php echo htmlspecialchars($result['probe']['snippet'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php else: ?>
                                    <span class="cell-muted">Ek detay yok</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

<textarea id="yetkiCsvContent" class="sr-only"><?php echo htmlspecialchars($csvExportContent, ENT_QUOTES, 'UTF-8'); ?></textarea>

<script>
    // Stagger-in animation with RAF reflow fix (max 280ms)
    (function () {
        var cards = document.querySelectorAll('[data-stagger]');
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                cards.forEach(function (el) {
                    var idx = parseInt(el.getAttribute('data-stagger') || '0', 10);
                    var delay = Math.min(idx * 60, 280);
                    el.style.animationDelay = delay + 'ms';
                    el.classList.add('cardIn');
                });
            });
        });
    }());

    (function () {
        var copyButton = document.getElementById('copyCsvButton');
        var copyStatus = document.getElementById('copyCsvStatus');
        var csvContent = document.getElementById('yetkiCsvContent');

        function durumYaz(metin, basarili) {
            copyStatus.textContent = metin;
            copyStatus.className = basarili ? 'copy-status ok' : 'copy-status err';
        }

        async function kopyala() {
            var text = csvContent.value || '';
            if (!text) {
                durumYaz('Kopyalanacak CSV icerigi bulunamadi.', false);
                return;
            }

            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);
                } else {
                    csvContent.classList.remove('sr-only');
                    csvContent.select();
                    csvContent.setSelectionRange(0, text.length);
                    var copied = document.execCommand('copy');
                    csvContent.classList.add('sr-only');
                    if (!copied) {
                        throw new Error('Tarayici kopyalama istegini reddetti');
                    }
                }

                durumYaz('CSV panoya kopyalandi. Bana dogrudan yapistirabilirsin.', true);
            } catch (error) {
                durumYaz('CSV kopyalanamadi. Tarayici izni veya guvenli baglanti gerekebilir.', false);
            }
        }

        copyButton.addEventListener('click', function () {
            kopyala();
        });
    }());
</script>

</body>
</html>
