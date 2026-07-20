<?php
declare(strict_types=1);

// Gerekli yapılandırma dosyalarını ve güvenlik kontrolünü yükle.
require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/ayr.php';
require_once __DIR__ . '/../kontrol.php';

// Yetki kontrolü - M16 (Ayarlar) yetkisi gerekli
if (m_p_yetki($terminalkullanici, 'M16') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// Yetki tanımları — TEK KAYNAK: ayar/yetki_tanimlari.php (M + CR + ST + SP, gruplu)
require_once __DIR__ . '/yetki_tanimlari.php';
$yetkiGruplari = yetki_gruplari();
$yetkiler = yetki_tum_tanimlar();

// Hazır rol şablonları — TEK KAYNAK: ayar/rol_helper.php
// Built-in (Yönetici, Tümünü Sıfırla) + ayar/roller.php'den yönetilen özel roller.
// 'yetki_turu' => M_P_YETKI.YETKI sutunu (0=Yonetici, 1=Personel, 2=Musteri, null=degistirme)
require_once __DIR__ . '/rol_helper.php';
$rol_sablonlari = rol_tum_sablonlar();

// Kullanıcıları ve yetkilerini çek
$firma_filter = isset($_POST['firma']) ? (int)$_POST['firma'] : (int)$firmano;

// Sutun listesi tek kaynaktan uretilir (kodlar sabit tanimli, SQL enjeksiyonu soz konusu degil)
$yetkiSutunlari = 'y.' . implode(', y.', yetki_tum_kodlar());
$sql = "SELECT
            s.LOGICALREF,
            s.CODE,
            s.DEFINITION_,
            s.ACTIVE,
            {$yetkiSutunlari},
            y.YETKI as YETKI_TURU
        FROM LG_SLSMAN s
        LEFT JOIN M_P_YETKI y ON y.PERSONEL = s.LOGICALREF
        WHERE s.FIRMNR = :firma
        ORDER BY s.CODE ASC";

$stmt = $dbh->prepare($sql);
$stmt->execute([':firma' => $firma_filter]);
$kullanicilar = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yetki Matrisi</title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php if (is_file(__DIR__ . '/../pwa-header.php')) { include_once(__DIR__ . '/../pwa-header.php'); } ?>
    <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
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
            --indigo-hover: #4338ca;
            --amber: #d97706;
            --amber-soft: #fffbeb;
            --sky: #0284c7;
            --sky-soft: #eff6ff;
            --purple: #7c3aed;
            --purple-soft: #f5f3ff;
            --blue: #2563eb;
            --blue-soft: #eff6ff;
            --green: #16a34a;
            --green-soft: #f0fdf4;
            --slate-bg: #f8fafc;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
            margin: 0;
            padding-bottom: 120px;
        }
        .mono { font-family: 'JetBrains Mono', 'Courier New', monospace; }

        /* ============ STICKY INDIGO TOP HEADER ============ */
        .top-header {
            position: sticky; top: 0; z-index: 40;
            height: 64px;
            background: rgba(79, 70, 229, 0.96);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            box-shadow: 0 2px 10px rgba(79, 70, 229, 0.2);
            color: #fff;
        }
        .header-inner {
            max-width: 1400px; margin: 0 auto;
            height: 100%; display: flex; align-items: center; gap: 14px;
            padding: 0 24px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 40px; height: 40px; border-radius: 12px;
            color: rgba(255,255,255,0.92); text-decoration: none;
            transition: all 0.2s ease;
            background: rgba(255,255,255,0.1);
        }
        .header-back:hover { background: rgba(255,255,255,0.22); color: #fff; }
        .header-icon {
            width: 40px; height: 40px;
            border-radius: 12px;
            background: rgba(255,255,255,0.15);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 16px; color: #fff;
        }
        .header-title-wrap { min-width: 0; flex: 1; }
        .header-title { font-size: 17px; font-weight: 700; color: #fff; line-height: 1.2; }
        .header-sub { font-size: 11.5px; color: rgba(255,255,255,0.78); margin-top: 1px; }
        .header-badge {
            margin-left: auto;
            padding: 5px 12px;
            font-size: 11px;
            font-weight: 600;
            color: #fff;
            background: rgba(255,255,255,0.18);
            border: 1px solid rgba(255,255,255,0.28);
            border-radius: 100px;
            display: inline-flex; align-items: center; gap: 6px;
            white-space: nowrap;
        }
        .header-badge .dot {
            width: 7px; height: 7px; border-radius: 50%;
            background: #fff;
            animation: hpulse 1.6s ease infinite;
        }
        @keyframes hpulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.85); }
        }

        /* ============ LAYOUT ============ */
        .wrap {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 24px 40px;
        }

        /* ============ GLASS CARDS ============ */
        .glass-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(79, 70, 229, 0.12);
            border-radius: 16px;
            box-shadow: 0 4px 18px rgba(15, 23, 42, 0.05);
            will-change: transform, opacity;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            margin-bottom: 18px;
        }
        .glass-card.stagger-1 { animation-delay: 0.04s; }
        .glass-card.stagger-2 { animation-delay: 0.10s; }
        .glass-card.stagger-3 { animation-delay: 0.18s; }
        .glass-card.stagger-4 { animation-delay: 0.26s; }

        .card-head {
            padding: 16px 20px 14px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 12px;
        }
        .card-head .icon-box {
            width: 38px; height: 38px;
            flex-shrink: 0;
            border-radius: 11px;
            background: var(--indigo-soft);
            color: var(--indigo);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 15px;
        }
        .card-head .icon-box.tone-amber { background: var(--amber-soft); color: var(--amber); }
        .card-head .icon-box.tone-emerald { background: var(--emerald-soft); color: var(--emerald); }
        .card-head h2 { font-size: 14.5px; font-weight: 700; margin: 0; color: var(--text-1); }
        .card-head p { font-size: 11.5px; color: var(--text-2); margin: 2px 0 0; }
        .card-body { padding: 18px 20px; }

        /* ============ FILTER ROW ============ */
        .filter-row {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .filter-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .form-select {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14px;
            padding: 10px 14px;
            min-height: 44px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fff;
            color: var(--text-1);
            outline: none;
            transition: all 0.2s ease;
            min-width: 220px;
        }
        .form-select:focus {
            border-color: var(--indigo);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        /* ============ HINT BANNER ============ */
        .hint-banner {
            display: flex; gap: 12px;
            padding: 14px 16px;
            background: var(--indigo-soft);
            border: 1px solid rgba(79, 70, 229, 0.18);
            border-left: 4px solid var(--indigo);
            border-radius: 12px;
            margin-bottom: 18px;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .hint-banner i { color: var(--indigo); font-size: 18px; margin-top: 1px; flex-shrink: 0; }
        .hint-banner .hint-title { font-size: 13px; font-weight: 700; color: var(--indigo); margin-bottom: 4px; }
        .hint-banner .hint-text { font-size: 12.5px; color: var(--text-1); line-height: 1.5; }
        .hint-banner .hint-text strong { color: var(--indigo); font-weight: 600; }

        /* ============ LEGEND GRID ============ */
        .legend-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 10px;
        }
        .legend-cell {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 10px;
            background: var(--slate-bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            transition: all 0.15s ease;
        }
        .legend-cell:hover { background: #fff; border-color: rgba(79, 70, 229, 0.28); }
        .legend-cell .ic {
            width: 30px; height: 30px;
            border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            font-size: 12px;
        }
        .legend-cell .txt { min-width: 0; }
        .legend-cell .code { font-size: 11.5px; font-weight: 700; color: var(--text-1); }
        .legend-cell .nm { font-size: 11px; color: var(--text-2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .legend-group-title {
            display: flex; align-items: center; gap: 8px;
            font-size: 11px; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: 0.5px;
            margin: 14px 0 8px;
        }
        .legend-group-title:first-child { margin-top: 0; }
        .legend-group-title i { color: var(--indigo); font-size: 12px; }
        .legend-cell.pasif { opacity: 0.55; }
        .pasif-rozet {
            display: inline-block;
            font-size: 8.5px; font-weight: 700; letter-spacing: 0.3px;
            padding: 1px 6px; border-radius: 100px;
            background: #f3f4f6; color: var(--text-3);
            border: 1px solid var(--border);
            vertical-align: middle; text-transform: uppercase;
        }

        /* ============ PERMISSION MATRIX TABLE ============ */
        .matrix-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 14px;
            border: 1px solid var(--border);
            background: #fff;
        }
        .perm-matrix {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 12.5px;
        }
        .perm-matrix thead th {
            position: sticky; top: 0;
            background: var(--slate-bg);
            z-index: 3;
            font-size: 10.5px;
            font-weight: 700;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 12px 8px;
            border-bottom: 2px solid var(--border);
            text-align: center;
            white-space: nowrap;
        }
        .perm-matrix thead th.th-user {
            text-align: left;
            min-width: 240px;
            padding-left: 16px;
        }
        .perm-matrix thead th.th-role {
            min-width: 150px;
        }
        .perm-matrix thead th.th-perm {
            min-width: 62px;
            max-width: 62px;
        }
        .perm-matrix thead th.th-perm .th-ic {
            display: inline-flex; flex-direction: column;
            align-items: center; gap: 3px;
        }
        .perm-matrix thead th.th-perm .th-ic i {
            font-size: 13px;
            color: var(--indigo);
        }
        .perm-matrix thead th.th-perm .th-ic span {
            font-size: 10px;
            font-weight: 700;
            color: var(--text-2);
        }
        /* Grup satiri sticky degil — kod satiri scroll'da tepede kalir */
        .perm-matrix thead tr.group-row th { position: static; }
        .perm-matrix thead th.th-group {
            background: #eef2ff;
            color: var(--indigo);
            font-size: 10px;
            border-left: 2px solid var(--border);
            border-bottom: 1px solid var(--border);
        }
        .perm-matrix thead th.th-group i { margin-right: 5px; }
        .perm-matrix thead th.th-pasif { opacity: 0.45; }

        /* Sticky first columns */
        .perm-matrix th:first-child,
        .perm-matrix td.user-cell {
            position: sticky;
            left: 0;
            background: #fff;
            z-index: 2;
        }
        .perm-matrix thead th:first-child { z-index: 4; background: var(--slate-bg); }

        .perm-matrix tbody tr {
            transition: background 0.15s ease;
        }
        .perm-matrix tbody tr:hover td { background: #fafbff; }
        .perm-matrix tbody tr:hover td.user-cell { background: #fafbff; }
        .perm-matrix tbody tr.inactive td { opacity: 0.55; }

        .perm-matrix tbody td {
            padding: 10px 8px;
            border-bottom: 1px solid #f3f4f6;
            text-align: center;
            vertical-align: middle;
        }

        .user-cell {
            text-align: left !important;
            padding: 12px 16px !important;
        }
        .user-box {
            display: flex; align-items: center; gap: 10px;
        }
        .user-avatar {
            width: 36px; height: 36px;
            border-radius: 50%;
            background: var(--indigo-soft);
            color: var(--indigo);
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            font-size: 14px;
            font-weight: 600;
        }
        .user-info { min-width: 0; }
        .user-name { font-size: 13px; font-weight: 600; color: var(--text-1); line-height: 1.2; }
        .user-code { font-size: 11px; color: var(--text-2); margin-top: 2px; font-family: 'JetBrains Mono', monospace; }
        .chip {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 2px 8px;
            border-radius: 100px;
            font-size: 9.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }
        .chip.chip-inactive { background: var(--red-soft); color: var(--red); border: 1px solid rgba(111, 16, 34, 0.2); }
        .chip.chip-admin { background: var(--red-soft); color: var(--red); border: 1px solid rgba(111, 16, 34, 0.22); }
        .chip.chip-staff { background: var(--blue-soft); color: var(--blue); border: 1px solid rgba(37, 99, 235, 0.22); }
        .chip.chip-customer { background: var(--green-soft); color: var(--green); border: 1px solid rgba(22, 163, 74, 0.22); }
        .chip.chip-none { background: #f3f4f6; color: var(--text-3); border: 1px solid var(--border); }

        /* Role cell with preset select */
        .role-cell .role-stack {
            display: flex; flex-direction: column; align-items: center; gap: 6px;
        }
        .preset-select {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 11.5px;
            padding: 5px 8px;
            min-height: 30px;
            border: 1px solid var(--border);
            border-radius: 7px;
            background: #fff;
            color: var(--text-1);
            outline: none;
            transition: all 0.2s ease;
            max-width: 140px;
            cursor: pointer;
        }
        .preset-select:focus {
            border-color: var(--indigo);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        /* Permission badge */
        .permission-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px; height: 34px;
            min-width: 44px; min-height: 44px;
            width: 44px; height: 44px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.22, 1, 0.36, 1);
            border: 1px solid transparent;
            background: #f3f4f6;
            color: var(--text-3);
            user-select: none;
        }
        .permission-badge:hover {
            transform: scale(1.08);
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
        }
        .permission-badge:active { transform: scale(0.95); }
        .permission-badge.on {
            background: var(--emerald-soft);
            color: var(--emerald);
            border-color: rgba(5, 150, 105, 0.25);
        }
        .permission-badge.off {
            background: #f3f4f6;
            color: var(--text-3);
            border-color: var(--border);
        }
        .permission-badge i { font-size: 13px; }
        .permission-badge.changed {
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.4);
            background: var(--indigo-soft);
            border-color: var(--indigo);
            animation: badgePulse 1.6s infinite;
        }
        .permission-badge.changed.on { color: var(--indigo); }
        .permission-badge.changed.off { color: var(--indigo); }
        @keyframes badgePulse {
            0%, 100% { box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.4); }
            50% { box-shadow: 0 0 0 6px rgba(79, 70, 229, 0.2); }
        }

        /* Empty state */
        .empty-state {
            padding: 48px 20px;
            text-align: center;
            color: var(--text-2);
        }
        .empty-state i { font-size: 42px; color: var(--text-3); margin-bottom: 12px; }
        .empty-state p { font-size: 13px; }

        /* Info box */
        .info-box {
            display: flex; gap: 12px;
            padding: 14px 16px;
            background: var(--sky-soft);
            border: 1px solid rgba(2, 132, 199, 0.18);
            border-left: 4px solid var(--sky);
            border-radius: 12px;
            margin-top: 18px;
        }
        .info-box i { color: var(--sky); font-size: 17px; margin-top: 1px; flex-shrink: 0; }
        .info-box .info-title { font-size: 13px; font-weight: 700; color: var(--sky); margin-bottom: 6px; }
        .info-box ul { font-size: 12.5px; color: var(--text-1); line-height: 1.7; padding-left: 18px; margin: 0; }
        .info-box ul li strong { color: var(--indigo); }

        /* ============ STICKY SAVE PANEL ============ */
        .save-panel {
            position: fixed;
            bottom: -120px;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, var(--indigo) 0%, var(--indigo-hover) 100%);
            box-shadow: 0 -8px 28px rgba(79, 70, 229, 0.28);
            padding: 16px 0;
            z-index: 60;
            transition: bottom 0.4s cubic-bezier(0.22, 1, 0.36, 1);
            border-top: 1px solid rgba(255,255,255,0.15);
        }
        .save-panel.show { bottom: 0; }
        .save-inner {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }
        .save-info { color: #fff; min-width: 0; }
        .save-info .title {
            font-size: 15px; font-weight: 700;
            display: flex; align-items: center; gap: 8px;
        }
        .save-info .title .count-pill {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 26px; height: 22px;
            padding: 0 8px;
            background: #fff;
            color: var(--indigo);
            border-radius: 100px;
            font-size: 12px;
            font-weight: 800;
        }
        .save-info .sub { font-size: 11.5px; color: rgba(255,255,255,0.82); margin-top: 2px; }
        .save-actions { display: flex; gap: 10px; }
        .btn {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13.5px;
            font-weight: 600;
            padding: 11px 20px;
            min-height: 44px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex; align-items: center; gap: 7px;
            white-space: nowrap;
        }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-ghost {
            background: rgba(255,255,255,0.15);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.25);
        }
        .btn-ghost:hover:not(:disabled) { background: rgba(255,255,255,0.25); }
        .btn-primary-white {
            background: #fff;
            color: var(--indigo);
            box-shadow: 0 4px 12px rgba(0,0,0,0.14);
        }
        .btn-primary-white:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(0,0,0,0.2);
        }

        /* ============ TOAST ============ */
        .toast {
            position: fixed;
            bottom: 120px;
            right: 20px;
            padding: 14px 20px;
            border-radius: 12px;
            box-shadow: 0 10px 28px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 9999;
            animation: slideIn 0.3s ease-out;
            max-width: 400px;
            font-size: 13px;
            font-weight: 500;
        }
        .toast.success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #fff; }
        .toast.error   { background: linear-gradient(135deg, var(--red,#ef4444) 0%, var(--red,#6F1022) 100%); color: #fff; }
        .toast i { font-size: 18px; }
        @keyframes slideIn {
            from { transform: translateX(420px); opacity: 0; }
            to   { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to   { transform: translateX(420px); opacity: 0; }
        }
        .toast.hiding { animation: slideOut 0.3s ease-in forwards; }

        /* ============ ANIMATION ============ */
        @keyframes cardIn {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ============ RESPONSIVE ============ */
        @media (max-width: 768px) {
            .wrap { padding: 14px; }
            .header-inner { padding: 0 14px; gap: 10px; }
            .header-title { font-size: 15px; }
            .header-sub { font-size: 10.5px; }
            .header-badge { display: none; }
            .card-head { padding: 14px 16px 12px; }
            .card-body { padding: 14px 16px; }
            .form-select { font-size: 16px; min-width: 180px; width: 100%; }
            .filter-row { flex-direction: column; align-items: stretch; }
            .perm-matrix thead th.th-user { min-width: 180px; }
            .perm-matrix thead th.th-role { min-width: 120px; }
            .save-inner { flex-direction: column; align-items: stretch; padding: 0 14px; }
            .save-actions { width: 100%; }
            .save-actions .btn { flex: 1; justify-content: center; }
            .toast { bottom: 160px; left: 12px; right: 12px; max-width: none; }
        }
        @media (max-width: 480px) {
            .legend-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
            .permission-badge { width: 40px; height: 40px; min-width: 40px; min-height: 40px; }
        }
    </style>
</head>
<body>
    <?php $aktifSekme = 'matris'; include __DIR__ . '/yetki_sekmeler.php'; ?>

    <div class="wrap">
        <!-- FIRMA FILTER -->
        <section class="glass-card stagger-1">
            <div class="card-head">
                <div class="icon-box"><i class="fa-solid fa-building"></i></div>
                <div>
                    <h2>Firma Seçimi</h2>
                    <p>Matrisi yüklemek için firma seçin</p>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" class="filter-row" onsubmit="return confirmPageChange()">
                    <?php echo csrf_field(); ?>
                    <label for="firma" class="filter-label">Firma</label>
                    <select id="firma" name="firma" class="form-select" onchange="if(confirmPageChange()) this.form.submit()">
                        <?php
                        $firm_stmt = $dbh->prepare("SELECT NR, NAME FROM L_CAPIFIRM ORDER BY NAME ASC");
                        $firm_stmt->execute();
                        $allFirms = $firm_stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($allFirms as $f) {
                            $selected = ($f['NR'] == $firma_filter) ? 'selected' : '';
                            echo "<option value='" . htmlspecialchars((string) $f['NR']) . "' $selected>" . htmlspecialchars((string) $f['NAME']) . "</option>";
                        }
                        ?>
                    </select>
                </form>
            </div>
        </section>

        <!-- HINT -->
        <div class="hint-banner">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <div class="hint-title">Nasıl kullanılır?</div>
                <div class="hint-text">
                    Her hücredeki rozete tıklayarak yetkileri açın/kapatın. Değiştirilen hücreler <strong>mor çerçeve</strong> ile işaretlenir.
                    Hazır rol uygulamak için kullanıcı satırındaki <strong>preset</strong> seçiciyi kullanın.
                    Tüm değişiklikler tamamlandığında aşağıdaki <strong>Kaydet</strong> butonuna basın.
                </div>
            </div>
        </div>

        <!-- LEGEND -->
        <section class="glass-card stagger-2">
            <div class="card-head">
                <div class="icon-box tone-emerald"><i class="fa-solid fa-list-ul"></i></div>
                <div>
                    <h2>Yetki Tanımları</h2>
                    <p><?php echo count($yetkiler); ?> yetki kodu · <?php echo count($yetkiGruplari); ?> grup (tek kaynak: yetki_tanimlari.php)</p>
                </div>
            </div>
            <div class="card-body">
                <?php foreach ($yetkiGruplari as $grup): ?>
                <div class="legend-group-title">
                    <i class="fa-solid <?php echo htmlspecialchars((string) $grup['ikon']); ?>"></i>
                    <?php echo htmlspecialchars((string) $grup['ad']); ?>
                </div>
                <div class="legend-grid">
                    <?php foreach ($grup['kodlar'] as $kod => $yetki): $pasif = empty($yetki['aktif']); ?>
                    <div class="legend-cell<?php echo $pasif ? ' pasif' : ''; ?>" title="<?php echo htmlspecialchars((string) ($yetki['desc'] ?? '')); ?>">
                        <div class="ic" style="background: var(--indigo-soft); color: var(--indigo);">
                            <i class="fa-solid <?php echo htmlspecialchars((string) $yetki['icon']); ?>"></i>
                        </div>
                        <div class="txt">
                            <div class="code"><?php echo htmlspecialchars($kod); ?><?php if ($pasif): ?> <span class="pasif-rozet">kullanılmıyor</span><?php endif; ?></div>
                            <div class="nm"><?php echo htmlspecialchars((string) $yetki['name']); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- MATRIX -->
        <section class="glass-card stagger-3">
            <div class="card-head">
                <div class="icon-box"><i class="fa-solid fa-table-cells-large"></i></div>
                <div>
                    <h2>Kullanıcı Yetki Matrisi</h2>
                    <p><?php echo count($kullanicilar); ?> kullanıcı &middot; <?php echo count($yetkiler); ?> yetki modülü</p>
                </div>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="matrix-wrap">
                    <table class="perm-matrix">
                        <thead>
                            <tr class="group-row">
                                <th class="th-user" rowspan="2">Kullanıcı</th>
                                <th class="th-role" rowspan="2">Yetki Türü</th>
                                <?php foreach ($yetkiGruplari as $grup): ?>
                                <th class="th-group" colspan="<?php echo count($grup['kodlar']); ?>">
                                    <i class="fa-solid <?php echo htmlspecialchars((string) $grup['ikon']); ?>"></i>
                                    <?php echo htmlspecialchars((string) $grup['ad']); ?>
                                </th>
                                <?php endforeach; ?>
                            </tr>
                            <tr>
                                <?php foreach ($yetkiler as $kod => $yetki): $pasif = empty($yetki['aktif']); ?>
                                <th class="th-perm<?php echo $pasif ? ' th-pasif' : ''; ?>" title="<?php echo htmlspecialchars((string) $yetki['name'] . ($pasif ? ' (kullanılmıyor)' : '') . ' — ' . (string) ($yetki['desc'] ?? '')); ?>">
                                    <span class="th-ic">
                                        <i class="fa-solid <?php echo htmlspecialchars((string) $yetki['icon']); ?>"></i>
                                        <span><?php echo htmlspecialchars($kod); ?></span>
                                    </span>
                                </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($kullanicilar)): ?>
                            <tr>
                                <td colspan="<?php echo count($yetkiler) + 2; ?>" class="empty-state">
                                    <i class="fa-solid fa-user-slash"></i>
                                    <p>Bu firmada kayıtlı kullanıcı bulunamadı.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($kullanicilar as $kullanici):
                                    $isActive = ($kullanici['ACTIVE'] == 0);
                                    $yetkiTuru = $kullanici['YETKI_TURU'];
                                    $kisaAd = mb_substr(trim((string) $kullanici['DEFINITION_']), 0, 1, 'UTF-8');
                                ?>
                                <tr class="<?php echo $isActive ? '' : 'inactive'; ?>">
                                    <td class="user-cell">
                                        <div class="user-box">
                                            <div class="user-avatar">
                                                <?php echo htmlspecialchars(mb_strtoupper($kisaAd !== '' ? $kisaAd : '?', 'UTF-8')); ?>
                                            </div>
                                            <div class="user-info">
                                                <div class="user-name"><?php echo htmlspecialchars((string) $kullanici['DEFINITION_']); ?></div>
                                                <div class="user-code">
                                                    <?php echo htmlspecialchars((string) $kullanici['CODE']); ?>
                                                    <?php if (!$isActive): ?>
                                                        &middot; <span class="chip chip-inactive">Pasif</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="role-cell">
                                        <div class="role-stack">
                                            <?php
                                            if ($yetkiTuru === '0' || $yetkiTuru === 0) {
                                                echo '<span class="chip chip-admin"><i class="fa-solid fa-crown"></i> Yönetici</span>';
                                            } elseif ($yetkiTuru === '1' || $yetkiTuru === 1) {
                                                echo '<span class="chip chip-staff"><i class="fa-solid fa-user"></i> Personel</span>';
                                            } elseif ($yetkiTuru === '2' || $yetkiTuru === 2) {
                                                echo '<span class="chip chip-customer"><i class="fa-solid fa-handshake"></i> Müşteri</span>';
                                            } else {
                                                echo '<span class="chip chip-none">Tanımsız</span>';
                                            }
                                            ?>
                                            <select
                                                class="preset-select"
                                                data-personel-id="<?php echo (int) $kullanici['LOGICALREF']; ?>"
                                                onchange="applyPreset(this)"
                                                title="Hazır rol uygula"
                                            >
                                                <option value="">— Hazır Rol —</option>
                                                <?php foreach ($rol_sablonlari as $key => $rol): ?>
                                                    <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars((string) $rol['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </td>

                                    <?php foreach (array_keys($yetkiler) as $kod):
                                        $has_permission = isset($kullanici[$kod]) && $kullanici[$kod] == 1;
                                        $personel_id = (int) $kullanici['LOGICALREF'];
                                    ?>
                                    <td>
                                        <span
                                            class="permission-badge <?php echo $has_permission ? 'on' : 'off'; ?>"
                                            data-personel-id="<?php echo $personel_id; ?>"
                                            data-yetki-kodu="<?php echo htmlspecialchars($kod); ?>"
                                            data-durum="<?php echo $has_permission ? '1' : '0'; ?>"
                                            data-original="<?php echo $has_permission ? '1' : '0'; ?>"
                                            onclick="togglePermission(this)"
                                            title="Tıklayarak değiştir"
                                            role="button"
                                            tabindex="0"
                                        >
                                            <i class="fa-solid <?php echo $has_permission ? 'fa-check' : 'fa-xmark'; ?>"></i>
                                        </span>
                                    </td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- INFO -->
        <div class="info-box">
            <i class="fa-solid fa-lightbulb"></i>
            <div>
                <div class="info-title">Yetkilendirme bilgisi</div>
                <ul>
                    <li><strong>Yönetici (0):</strong> Tüm yetkilere sahiptir</li>
                    <li><strong>Personel (1):</strong> Atanan yetkilere göre sınırlı erişim</li>
                    <li><strong>Müşteri (2):</strong> Sadece okuma yetkisi</li>
                    <li>Hazır roller matris satırındaki preset seçiciden uygulanır</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- STICKY SAVE PANEL -->
    <div id="savePanel" class="save-panel" role="region" aria-label="Değişiklik kaydet paneli">
        <div class="save-inner">
            <div class="save-info">
                <div class="title">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span class="count-pill"><span id="changeCount">0</span></span>
                    <span>Kaydedilmemiş değişiklik</span>
                </div>
                <div class="sub">Tüm düzenlemelerinizi onaylamak için Kaydet butonuna basın</div>
            </div>
            <div class="save-actions">
                <button type="button" onclick="resetChanges()" class="btn btn-ghost">
                    <i class="fa-solid fa-rotate-left"></i>
                    Sıfırla
                </button>
                <button type="button" onclick="saveAllChanges()" id="saveBtn" class="btn btn-primary-white">
                    <i class="fa-solid fa-floppy-disk"></i>
                    Değişiklikleri Kaydet
                </button>
            </div>
        </div>
    </div>

    <!-- jQuery -->
    <script src="/tm/js/jquery-3.7.1.min.js"></script>

    <script>
        // RAF reflow helper
        requestAnimationFrame(function() {
            document.querySelectorAll('.glass-card').forEach(function(el) {
                el.style.willChange = 'auto';
            });
        });

        // Değişiklik izleme
        var changes = [];
        var csrfToken = <?php echo json_encode(csrf_token(), JSON_UNESCAPED_UNICODE); ?>;

        // Toast bildirimi göster
        function showToast(message, type = 'success') {
            $('.toast').remove();

            var icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
            var toast = $('<div class="toast ' + type + '">' +
                '<i class="fa-solid ' + icon + '"></i>' +
                '<span>' + message + '</span>' +
                '</div>');

            $('body').append(toast);

            setTimeout(function() {
                toast.addClass('hiding');
                setTimeout(function() {
                    toast.remove();
                }, 300);
            }, 3000);
        }

        // Değişiklik sayısını güncelle
        function updateChangeCount() {
            var count = changes.length;
            $('#changeCount').text(count);

            if (count > 0) {
                $('#savePanel').addClass('show');
            } else {
                $('#savePanel').removeClass('show');
            }
        }

        // Hazir rol sablonlari (PHP'den gelen)
        var rolSablonlari = <?php echo json_encode($rol_sablonlari, JSON_UNESCAPED_UNICODE); ?>;
        var tumYetkiKodlari = <?php echo json_encode(array_keys($yetkiler), JSON_UNESCAPED_UNICODE); ?>;

        // Hazir rol uygula (secili kullaniciya)
        function applyPreset(selectEl) {
            var preset = selectEl.value;
            if (!preset) return;

            var personelId = selectEl.getAttribute('data-personel-id');
            var rol = rolSablonlari[preset];
            if (!rol) return;

            var rolYetkileri = rol.yetkiler || [];
            var rolYetkiTuru = (typeof rol.yetki_turu !== 'undefined') ? rol.yetki_turu : null;
            var rolAdi = selectEl.options[selectEl.selectedIndex].text;

            if (!confirm('Bu kullaniciya "' + rolAdi + '" rolunu uygulamak istediginize emin misiniz?\n\nMevcut tum yetkiler rolun tanimina gore degistirilecek (kaydetmek icin alttaki butona basmaniz gerekir).')) {
                selectEl.value = '';
                return;
            }

            // YETKI turunu degistirmek icin "sanal rozet" kullanacagiz
            if (rolYetkiTuru !== null) {
                var changeKey = personelId + '_YETKI';
                var existingIndex = changes.findIndex(function(c) { return c.key === changeKey; });
                var changeObj = {
                    key: changeKey,
                    personel_id: personelId,
                    yetki_kodu: 'YETKI',
                    yeni_deger: rolYetkiTuru
                };
                if (existingIndex >= 0) {
                    changes[existingIndex] = changeObj;
                } else {
                    changes.push(changeObj);
                }

                // Gorsel olarak chip'i guncelle
                var $cell = $('select.preset-select[data-personel-id="' + personelId + '"]').closest('td');
                var $chip = $cell.find('.chip').first();
                var chipHtml;
                if (rolYetkiTuru === 0) {
                    chipHtml = '<span class="chip chip-admin"><i class="fa-solid fa-crown"></i> Yönetici</span>';
                } else if (rolYetkiTuru === 1) {
                    chipHtml = '<span class="chip chip-staff"><i class="fa-solid fa-user"></i> Personel</span>';
                } else if (rolYetkiTuru === 2) {
                    chipHtml = '<span class="chip chip-customer"><i class="fa-solid fa-handshake"></i> Müşteri</span>';
                }
                if (chipHtml) {
                    $chip.replaceWith(chipHtml);
                }
            }

            // Bu kullanicinin tum yetki rozetlerini bul ve gerekli durumlarini uygula
            tumYetkiKodlari.forEach(function(kod) {
                var $badge = $('.permission-badge[data-personel-id="' + personelId + '"][data-yetki-kodu="' + kod + '"]');
                if ($badge.length === 0) return;

                var hedef = rolYetkileri.indexOf(kod) !== -1 ? 1 : 0;
                var mevcut = parseInt($badge.data('durum'));

                // Sadece hedef != mevcut ise togglePermission tetikle
                if (hedef !== mevcut) {
                    togglePermission($badge[0]);
                }
            });

            updateChangeCount();

            // Dropdown'u sifirla
            selectEl.value = '';
            showToast('"' + rolAdi + '" rolu uygulandi. Kaydetmek icin alttaki butona basin.', 'success');
        }

        // Yetki durumunu değiştir (sadece görsel)
        function togglePermission(element) {
            var $badge = $(element);
            var personelId = $badge.data('personel-id');
            var yetkiKodu = $badge.data('yetki-kodu');
            var mevcutDurum = parseInt($badge.data('durum'));
            var originalDurum = parseInt($badge.data('original'));
            var yeniDurum = mevcutDurum === 1 ? 0 : 1;

            // Görünümü güncelle
            $badge.data('durum', yeniDurum);

            if (yeniDurum === 1) {
                $badge.removeClass('off').addClass('on');
                $badge.find('i').removeClass('fa-xmark').addClass('fa-check');
            } else {
                $badge.removeClass('on').addClass('off');
                $badge.find('i').removeClass('fa-check').addClass('fa-xmark');
            }

            // Değişiklik kaydı
            var changeKey = personelId + '_' + yetkiKodu;
            var existingIndex = changes.findIndex(c => c.key === changeKey);

            if (yeniDurum !== originalDurum) {
                // Değişti - kaydet veya güncelle
                $badge.addClass('changed');

                if (existingIndex >= 0) {
                    changes[existingIndex].yeni_deger = yeniDurum;
                } else {
                    changes.push({
                        key: changeKey,
                        personel_id: personelId,
                        yetki_kodu: yetkiKodu,
                        yeni_deger: yeniDurum
                    });
                }
            } else {
                // Orijinal haline döndü - değişiklik listesinden çıkar
                $badge.removeClass('changed');

                if (existingIndex >= 0) {
                    changes.splice(existingIndex, 1);
                }
            }

            updateChangeCount();
        }

        // Tüm değişiklikleri kaydet
        function saveAllChanges() {
            if (changes.length === 0) {
                showToast('Kaydedilecek değişiklik yok', 'error');
                return;
            }

            var $saveBtn = $('#saveBtn');
            $saveBtn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i>Kaydediliyor...');

            var successCount = 0;
            var errorCount = 0;
            var totalChanges = changes.length;

            // Her değişikliği sırayla kaydet
            var saveNext = function(index) {
                if (index >= totalChanges) {
                    // Tüm işlemler bitti
                    $saveBtn.prop('disabled', false).html('<i class="fa-solid fa-floppy-disk"></i>Değişiklikleri Kaydet');

                    if (errorCount === 0) {
                        showToast(successCount + ' değişiklik başarıyla kaydedildi!', 'success');

                        // Değişiklikleri temizle ve orijinal değerleri güncelle
                        $('.permission-badge.changed').each(function() {
                            var newVal = $(this).data('durum');
                            $(this).data('original', newVal);
                            $(this).removeClass('changed');
                        });

                        changes = [];
                        updateChangeCount();
                    } else {
                        showToast(successCount + ' başarılı, ' + errorCount + ' hata', 'error');
                    }
                    return;
                }

                var change = changes[index];

                $.ajax({
                    url: 'yetki_guncelle_ajax.php',
                    type: 'POST',
                    data: {
                        personel_id: change.personel_id,
                        yetki_kodu: change.yetki_kodu,
                        yeni_deger: change.yeni_deger,
                        csrf_token: csrfToken
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            successCount++;
                        } else {
                            errorCount++;
                        }
                    },
                    error: function() {
                        errorCount++;
                    },
                    complete: function() {
                        saveNext(index + 1);
                    }
                });
            };

            saveNext(0);
        }

        // Değişiklikleri sıfırla
        function resetChanges() {
            if (changes.length === 0) return;

            if (!confirm('Tüm değişiklikler geri alınacak. Emin misiniz?')) {
                return;
            }

            $('.permission-badge.changed').each(function() {
                var $badge = $(this);
                var originalDurum = parseInt($badge.data('original'));

                $badge.data('durum', originalDurum);

                if (originalDurum === 1) {
                    $badge.removeClass('off').addClass('on');
                    $badge.find('i').removeClass('fa-xmark').addClass('fa-check');
                } else {
                    $badge.removeClass('on').addClass('off');
                    $badge.find('i').removeClass('fa-check').addClass('fa-xmark');
                }

                $badge.removeClass('changed');
            });

            changes = [];
            updateChangeCount();
            showToast('Tüm değişiklikler geri alındı', 'success');
        }

        // Sayfa değişikliği uyarısı
        function confirmPageChange() {
            if (changes.length > 0) {
                return confirm('Kaydedilmemiş ' + changes.length + ' değişiklik var. Sayfadan ayrılmak istediğinize emin misiniz?');
            }
            return true;
        }

        // Sayfa kapatılırken uyarı
        window.addEventListener('beforeunload', function (e) {
            if (changes.length > 0) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        // Keyboard accessibility for badges
        $(document).on('keydown', '.permission-badge', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                togglePermission(this);
            }
        });
    </script>
</body>
</html>
