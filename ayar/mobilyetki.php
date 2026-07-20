<?php
declare(strict_types=1);

require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/admin_guard.php';

ayar_require_m16($terminalkullanici);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ayar_require_csrf();

    $action = trim((string) $_POST['action']);
    $perid = isset($_POST['perid']) ? (int) $_POST['perid'] : 0;

    if ($perid <= 0) {
        header('Location: mobilyetki.php?hata=gecersiz_kullanici', true, 303);
        exit;
    }

    if ($action === 'toggle_status') {
        $allowed = ['aktif', 'pasif'];
        $durum = trim((string) ($_POST['durum'] ?? ''));
        if (!in_array($durum, $allowed, true)) {
            header('Location: mobilyetki.php?hata=gecersiz_durum', true, 303);
            exit;
        }

        $dr = ($durum === 'aktif') ? 0 : 1;
        $upd = $dbh->prepare("UPDATE LG_SLSMAN SET ACTIVE = :dr WHERE LOGICALREF = :id");
        $upd->execute([':dr' => $dr, ':id' => $perid]);

        header('Location: mobilyetki.php', true, 303);
        exit;
    }

    if ($action === 'delete_user') {
        $check = $dbh->prepare("SELECT ACTIVE FROM LG_SLSMAN WHERE LOGICALREF = :id");
        $check->execute([':id' => $perid]);
        $user = $check->fetch(PDO::FETCH_ASSOC);

        if ($user && (int) $user['ACTIVE'] === 1) {
            $delYetki = $dbh->prepare("DELETE FROM M_P_YETKI WHERE PERSONEL = :id");
            $delYetki->execute([':id' => $perid]);

            $delUser = $dbh->prepare("DELETE FROM LG_SLSMAN WHERE LOGICALREF = :id AND ACTIVE = 1");
            $delUser->execute([':id' => $perid]);

            if (function_exists('m_p_yetki_cache_temizle')) {
                m_p_yetki_cache_temizle($perid);
            }

            header('Location: mobilyetki.php?silindi=1', true, 303);
            exit;
        }

        header('Location: mobilyetki.php?hata=aktif_silinemez', true, 303);
        exit;
    }

    header('Location: mobilyetki.php?hata=gecersiz_islem', true, 303);
    exit;
}

$search  = isset($_POST['ara'])   ? trim((string) $_POST['ara']) : '';
$firmaId = isset($_POST['firma']) ? (int)$_POST['firma'] : (int)$firmano;
$like    = '%' . $search . '%';

$sql = "SELECT LOGICALREF, CODE, DEFINITION_, ACTIVE
        FROM   LG_SLSMAN
        WHERE  FIRMNR = :firm
          AND (CODE LIKE :p1 OR DEFINITION_ LIKE :p2)
        ORDER BY CODE ASC";
$stmt = $dbh->prepare($sql);
$stmt->execute([':firm' => $firmaId, ':p1' => $like, ':p2' => $like]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
$aktifSayisi = count(array_filter($users, fn($u) => $u['ACTIVE'] == 0));
$pasifSayisi = count($users) - $aktifSayisi;

$firm_stmt = $dbh->prepare("SELECT NR, NAME FROM L_CAPIFIRM ORDER BY NAME ASC");
$firm_stmt->execute();
$allFirms = $firm_stmt->fetchAll(PDO::FETCH_ASSOC);

$hasMatris  = file_exists(__DIR__ . '/yetki_matrisi.php');
$hasDenetim = file_exists(__DIR__ . '/yetki_denetim.php');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kullanici Yetki Yonetimi</title>
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
        }
        * { box-sizing: border-box; margin: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
        }

        /* Sticky header — INDIGO tone */
        .top-header {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(99, 102, 241, 0.18);
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.05);
        }
        .header-inner {
            max-width: 1100px; margin: 0 auto;
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
        .header-title {
            font-size: 16px; font-weight: 700; color: var(--text-1);
            line-height: 1.2;
        }
        .header-subtitle {
            font-size: 11.5px; color: var(--text-2);
            display: inline-flex; align-items: center; gap: 8px;
        }
        .header-subtitle .dot-em { width: 6px; height: 6px; border-radius: 50%; background: var(--emerald); display: inline-block; }
        .header-subtitle .dot-am { width: 6px; height: 6px; border-radius: 50%; background: var(--amber); display: inline-block; }

        main {
            max-width: 1100px; margin: 0 auto;
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

        /* Alert */
        .alert-bar {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 13px; font-weight: 500;
            margin-bottom: 14px;
            animation: cardIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .alert-bar.ok { background: var(--emerald-soft); color: var(--emerald); border: 1px solid rgba(5,150,105,0.25); }
        .alert-bar.err { background: var(--red-soft); color: var(--red); border: 1px solid rgba(239,68,68,0.25); }
        .alert-bar i { font-size: 14px; flex-shrink: 0; }

        /* Quick actions bar */
        .quick-actions {
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
            margin-bottom: 14px;
        }
        .btn-primary {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 11px 18px;
            background: linear-gradient(180deg, #6366f1 0%, #4f46e5 100%);
            color: #fff;
            border: 1px solid var(--indigo);
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13px; font-weight: 700;
            cursor: pointer; transition: all 0.18s ease;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.22);
            text-decoration: none;
        }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(79,70,229,0.32); filter: brightness(1.03); }
        .btn-ghost {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 10px 14px;
            background: #fff; color: var(--text-2);
            border: 1px solid var(--border); border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12.5px; font-weight: 600;
            cursor: pointer; transition: all 0.2s ease;
            text-decoration: none; white-space: nowrap;
        }
        .btn-ghost:hover { background: #fafafa; border-color: var(--indigo); color: var(--indigo); }

        /* Filter panel */
        .filter-panel {
            padding: 16px;
            margin-bottom: 14px;
            animation-delay: 0.05s;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: 1fr 1.4fr auto;
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

        /* Stat mini-cards */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
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
        .stat-card.em { border-color: rgba(5,150,105,0.22); background: linear-gradient(135deg, var(--emerald-soft) 0%, #fff 60%); animation-delay: 0.08s; }
        .stat-card.am { border-color: rgba(217,119,6,0.22); background: linear-gradient(135deg, var(--amber-soft) 0%, #fff 60%); animation-delay: 0.14s; }
        .stat-icon {
            width: 38px; height: 38px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 15px; flex-shrink: 0;
        }
        .stat-card.em .stat-icon { background: rgba(5,150,105,0.12); color: var(--emerald); }
        .stat-card.am .stat-icon { background: rgba(217,119,6,0.12); color: var(--amber); }
        .stat-body { display: flex; flex-direction: column; gap: 2px; }
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
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) 0.18s both;
        }

        .gd-table { width: 100%; border-collapse: collapse; }
        .gd-table thead { background: linear-gradient(180deg, #fff, #f8faff); }
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
            padding: 11px 14px;
            font-size: 13px; color: var(--text-1);
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .gd-table tbody tr { transition: background 0.15s ease; }
        .gd-table tbody tr:hover { background: var(--indigo-soft); }
        .gd-table tbody tr:last-child td { border-bottom: none; }

        .user-avatar {
            width: 34px; height: 34px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px; flex-shrink: 0;
        }
        .user-avatar.em { background: var(--emerald-soft); color: var(--emerald); }
        .user-avatar.am { background: #f3f4f6; color: var(--text-3); }
        .user-cell { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .user-cell .info { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .user-cell .name { font-weight: 600; color: var(--text-1); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 320px; }
        .user-cell .code { font-size: 11px; color: var(--text-2); font-family: 'JetBrains Mono', monospace; }

        .chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: 100px;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.3px;
            cursor: pointer; border: 1px solid transparent;
            transition: all 0.15s ease;
            background: none; font-family: 'Avenir Next', 'Montserrat', sans-serif;
        }
        .chip.em { background: var(--emerald-soft); color: var(--emerald); border-color: rgba(5,150,105,0.22); }
        .chip.em:hover { background: rgba(5,150,105,0.18); }
        .chip.am { background: var(--amber-soft); color: var(--amber); border-color: rgba(217,119,6,0.22); }
        .chip.am:hover { background: rgba(217,119,6,0.2); }
        .chip .dot { width: 6px; height: 6px; border-radius: 50%; }
        .chip.em .dot { background: var(--emerald); }
        .chip.am .dot { background: var(--amber); }

        .row-actions {
            display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end;
        }
        .btn-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px;
            border-radius: 9px;
            background: #fff;
            border: 1px solid var(--border);
            color: var(--text-2);
            text-decoration: none; cursor: pointer;
            transition: all 0.15s ease;
            font-size: 13px;
        }
        .btn-icon:hover { border-color: var(--indigo); color: var(--indigo); background: var(--indigo-soft); }
        .btn-icon.danger:hover { border-color: var(--red); color: var(--red); background: var(--red-soft); }
        .btn-icon.detail:hover { border-color: var(--sky); color: var(--sky); background: var(--sky-soft); }

        /* Empty state */
        .empty-state {
            padding: 60px 20px;
            text-align: center;
        }
        .empty-state i { font-size: 44px; color: var(--text-3); margin-bottom: 12px; }
        .empty-state p { color: var(--text-1); font-weight: 600; margin-bottom: 4px; }
        .empty-state span { color: var(--text-2); font-size: 12.5px; }

        /* Modal */
        .m-overlay {
            position: fixed; inset: 0;
            background: rgba(17, 24, 39, 0.55);
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
            z-index: 60;
            display: flex; align-items: center; justify-content: center;
            padding: 16px;
        }
        .m-overlay[hidden] { display: none; }
        .m-card {
            background: #fff;
            border-radius: 16px;
            width: 100%; max-width: 440px;
            box-shadow: 0 24px 48px rgba(0,0,0,0.22);
            border: 1px solid var(--border);
            animation: cardIn 0.3s cubic-bezier(0.22, 1, 0.36, 1) both;
            overflow: hidden;
        }
        .m-head {
            padding: 16px 18px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            background: linear-gradient(180deg, #fff, #fef2f2);
        }
        .m-head h3 { font-size: 14px; font-weight: 700; color: var(--text-1); display: inline-flex; align-items: center; gap: 8px; }
        .m-head h3 i { color: var(--red); }
        .m-close {
            background: none; border: none; color: var(--text-3); cursor: pointer;
            font-size: 18px; padding: 4px 8px; border-radius: 6px;
            transition: all 0.15s ease;
        }
        .m-close:hover { color: var(--red); background: var(--red-soft); }
        .m-body { padding: 20px 18px; text-align: center; }
        .m-body .icon-box {
            width: 56px; height: 56px; border-radius: 14px;
            background: var(--red-soft); color: var(--red);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 22px; margin: 0 auto 14px;
        }
        .m-body p { color: var(--text-1); font-size: 13.5px; line-height: 1.55; }
        .m-body p strong { color: var(--red); font-weight: 700; }
        .m-body .warn { color: var(--red); font-size: 11.5px; margin-top: 10px; font-weight: 600; }
        .m-foot {
            padding: 14px 18px;
            border-top: 1px solid var(--border);
            display: flex; gap: 10px;
            background: #fafafa;
        }
        .m-foot button {
            flex: 1; padding: 10px 14px;
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 13px; font-weight: 700;
            cursor: pointer; transition: all 0.18s ease;
            border: 1px solid transparent;
        }
        .m-foot .btn-cancel {
            background: #fff; color: var(--text-2); border-color: var(--border);
        }
        .m-foot .btn-cancel:hover { background: #f3f4f6; color: var(--text-1); }
        .m-foot .btn-confirm {
            background: linear-gradient(180deg, var(--red,#ef4444) 0%, var(--red,#6F1022) 100%);
            color: #fff; border-color: var(--red);
            box-shadow: 0 4px 12px rgba(239,68,68,0.22);
            display: inline-flex; align-items: center; justify-content: center; gap: 7px;
        }
        .m-foot .btn-confirm:hover { transform: translateY(-1px); filter: brightness(1.03); }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        @media (max-width: 900px) {
            .filter-grid { grid-template-columns: 1fr; gap: 12px; }
            .btn-filter { width: 100%; }
        }
        @media (max-width: 767px) {
            .header-inner { padding: 12px 14px; gap: 10px; }
            .header-title { font-size: 14px; }
            .header-subtitle { font-size: 10.5px; }
            .header-icon { width: 32px; height: 32px; font-size: 13px; }
            .header-back { width: 32px; height: 32px; }

            main { padding: 14px 12px 40px; }

            .quick-actions .btn-primary,
            .quick-actions .btn-ghost { flex: 1; justify-content: center; min-height: 44px; }

            .filter-panel { padding: 12px; }
            .field select,
            .field input[type="text"] { font-size: 16px; padding: 12px 14px; min-height: 44px; }
            .search-wrap input { padding-left: 38px !important; }
            .btn-filter { min-height: 44px; font-size: 14px; }

            .stat-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card { padding: 12px; }
            .stat-icon { width: 34px; height: 34px; }
            .stat-val { font-size: 18px; }

            /* Mobile: table stacks into cards */
            .gd-table thead { display: none; }
            .gd-table, .gd-table tbody, .gd-table tr, .gd-table td { display: block; width: 100%; }
            .gd-table tbody tr {
                padding: 12px 14px;
                border-bottom: 1px solid #f3f4f6;
                display: grid;
                grid-template-columns: 1fr auto;
                gap: 10px 10px;
                align-items: center;
            }
            .gd-table tbody td { padding: 0; border-bottom: none; }
            .gd-table tbody td.user-col { grid-column: 1; }
            .gd-table tbody td.status-col { grid-column: 2; justify-self: end; }
            .gd-table tbody td.actions-col { grid-column: 1 / -1; }
            .user-cell .name { max-width: 100%; white-space: normal; }

            .row-actions { width: 100%; justify-content: flex-end; gap: 8px; padding-top: 4px; border-top: 1px dashed #f3f4f6; }
            .btn-icon { width: 44px; height: 44px; }

            .m-card { max-width: 92vw; }
        }
    </style>
</head>
<body>

<header class="top-header">
    <div class="header-inner">
        <a href="index.php" class="header-back" title="Yonetim Paneli">
            <i class="fa fa-arrow-left"></i>
        </a>
        <div class="header-divider"></div>
        <span class="header-icon"><i class="fa-solid fa-users-gear"></i></span>
        <div class="header-titles">
            <span class="header-title">Kullanici Yetki Yonetimi</span>
            <span class="header-subtitle">
                <span class="dot-em"></span><?= (int)$aktifSayisi ?> aktif
                <span class="dot-am" style="margin-left:4px;"></span><?= (int)$pasifSayisi ?> pasif
            </span>
        </div>
    </div>
</header>

<main>

    <?php if (isset($_GET['silindi'])): ?>
        <div class="alert-bar ok">
            <i class="fa-solid fa-circle-check"></i>
            <span>Kullanici basariyla silindi.</span>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['hata'])):
        $h = (string)$_GET['hata'];
        $msg = match ($h) {
            'aktif_silinemez'    => 'Aktif kullanicilar silinemez. Once pasif yapin.',
            'gecersiz_kullanici' => 'Gecersiz kullanici secimi.',
            'gecersiz_durum'     => 'Gecersiz durum degeri.',
            'gecersiz_islem'     => 'Gecersiz islem.',
            default              => 'Bir hata olustu.',
        };
    ?>
        <div class="alert-bar err">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endif; ?>

    <!-- Quick action bar -->
    <div class="quick-actions">
        <a href="mobilkullanici.php" class="btn-primary">
            <i class="fa-solid fa-user-plus"></i> Yeni Kullanici Ekle
        </a>
        <?php if ($hasMatris): ?>
            <a href="yetki_matrisi.php" class="btn-ghost">
                <i class="fa-solid fa-shield-halved"></i> Yetki Matrisi
            </a>
        <?php endif; ?>
        <?php if ($hasDenetim): ?>
            <a href="yetki_denetim.php" class="btn-ghost">
                <i class="fa-solid fa-clipboard-check"></i> Yetki Denetimi
            </a>
        <?php endif; ?>
        <a href="../loglar.php" class="btn-ghost">
            <i class="fa-solid fa-list"></i> Loglar
        </a>
    </div>

    <!-- Filter panel -->
    <div class="filter-panel glass-card">
        <form id="searchForm" method="POST" class="filter-grid">
            <?php echo csrf_field(); ?>

            <div class="field">
                <label for="firma">Firma</label>
                <select id="firma" name="firma">
                    <?php foreach ($allFirms as $f):
                        $selected = ((int)$f['NR'] === (int)$firmaId) ? 'selected' : '';
                    ?>
                        <option value="<?= (int)$f['NR'] ?>" <?= $selected ?>><?= htmlspecialchars((string)$f['NAME'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
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
        </form>
    </div>

    <!-- Stat mini cards -->
    <div class="stat-grid">
        <div class="stat-card em">
            <span class="stat-icon"><i class="fa-solid fa-user-check"></i></span>
            <div class="stat-body">
                <span class="stat-val"><?= (int)$aktifSayisi ?></span>
                <span class="stat-label">Aktif Kullanici</span>
            </div>
        </div>
        <div class="stat-card am">
            <span class="stat-icon"><i class="fa-solid fa-user-slash"></i></span>
            <div class="stat-body">
                <span class="stat-val"><?= (int)$pasifSayisi ?></span>
                <span class="stat-label">Pasif Kullanici</span>
            </div>
        </div>
    </div>

    <!-- Users table -->
    <div class="table-card">
        <?php if (empty($users)): ?>
            <div class="empty-state">
                <i class="fa-solid fa-user-slash"></i>
                <p>Kayit Bulunamadi</p>
                <span>Arama kriterlerinizi degistirerek tekrar deneyin.</span>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="gd-table">
                    <thead>
                        <tr>
                            <th>Kullanici</th>
                            <th>Durum</th>
                            <th class="right">Islemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u):
                            $isActive  = ((int)$u['ACTIVE'] === 0);
                            $toggleVal = $isActive ? 'pasif' : 'aktif';
                            $name      = (string)$u['DEFINITION_'];
                            $code      = (string)$u['CODE'];
                            $pid       = (int)$u['LOGICALREF'];
                        ?>
                            <tr>
                                <td class="user-col">
                                    <div class="user-cell">
                                        <span class="user-avatar <?= $isActive ? 'em' : 'am' ?>">
                                            <i class="fa-solid fa-user"></i>
                                        </span>
                                        <div class="info">
                                            <span class="name" title="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="code"><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="status-col">
                                    <form method="POST" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="perid" value="<?= $pid ?>">
                                        <input type="hidden" name="durum" value="<?= htmlspecialchars($toggleVal, ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="chip <?= $isActive ? 'em' : 'am' ?>"
                                                title="<?= $isActive ? 'Pasif yap' : 'Aktif yap' ?>">
                                            <span class="dot"></span>
                                            <?= $isActive ? 'Aktif' : 'Pasif' ?>
                                        </button>
                                    </form>
                                </td>
                                <td class="actions-col">
                                    <div class="row-actions">
                                        <a href="mobilkullanicix.php?perid=<?= $pid ?>"
                                           class="btn-icon detail" title="Detay / Duzenle">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </a>
                                        <?php if (!$isActive): ?>
                                            <button type="button" class="btn-icon danger"
                                                    onclick="confirmDelete(<?= $pid ?>, <?= htmlspecialchars(json_encode($name, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>)"
                                                    title="Sil">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</main>

<!-- Delete confirm modal -->
<div id="deleteModal" class="m-overlay" hidden>
    <div class="m-card">
        <div class="m-head">
            <h3><i class="fa-solid fa-triangle-exclamation"></i> Kullaniciyi Sil</h3>
            <button type="button" class="m-close" onclick="closeDeleteModal()" aria-label="Kapat">&times;</button>
        </div>
        <div class="m-body">
            <div class="icon-box"><i class="fa-solid fa-trash"></i></div>
            <p>
                <strong id="deleteUserName"></strong><br>
                kullanicisini kalici olarak silmek istediginize emin misiniz?
            </p>
            <div class="warn"><i class="fa-solid fa-exclamation-triangle"></i> Bu islem geri alinamaz.</div>
        </div>
        <div class="m-foot">
            <form id="deleteForm" method="POST" style="display:none;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="perid" id="deletePerid" value="">
            </form>
            <button type="button" class="btn-cancel" onclick="closeDeleteModal()">Iptal</button>
            <button type="button" class="btn-confirm" id="confirmDeleteBtn">
                <i class="fa-solid fa-trash"></i> Evet, Sil
            </button>
        </div>
    </div>
</div>

<script>
    // Card animation reflow fix
    window.addEventListener('load', function() {
        document.querySelectorAll('.glass-card, .stat-card, .table-card').forEach(function(el) {
            el.style.willChange = 'auto';
        });
    });

    let deleteUserId = null;

    function confirmDelete(userId, userName) {
        deleteUserId = userId;
        document.getElementById('deletePerid').value = String(userId);
        document.getElementById('deleteUserName').textContent = userName;
        const m = document.getElementById('deleteModal');
        m.hidden = false;
        requestAnimationFrame(function(){ m.querySelector('.m-card').style.animation = 'none'; m.querySelector('.m-card').offsetHeight; m.querySelector('.m-card').style.animation = ''; });
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').hidden = true;
        deleteUserId = null;
    }

    document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
        if (deleteUserId) {
            document.getElementById('deleteForm').submit();
        }
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') closeDeleteModal();
    });

    // Click outside modal to close
    document.getElementById('deleteModal').addEventListener('click', function(e){
        if (e.target === this) closeDeleteModal();
    });
</script>
</body>
</html>
