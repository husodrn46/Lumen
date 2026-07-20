<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../kontrol.php");

// YETKI KONTROLÜ: M15 (Stoklar) yetkisi kontrolü
if (m_p_yetki($terminalkullanici, 'M15') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}
try {
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbh->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
} catch (Exception) {
}
// Hayalet fis temizligi: "Yeni Uretim" ile olusturulup hic satir eklenmeden
// terk edilen bos fisler listede gorunmez ve UI'dan silinemez — 1 gunden
// eski, URT onekli ve satirsiz olanlari sessizce kaldir.
try {
    $dbh->prepare("
        DELETE F FROM {$firmadonem}STFICHE F
        WHERE F.TRCODE = 13
          AND F.FICHENO LIKE 'URT%'
          AND F.DATE_ < DATEADD(day, -1, GETDATE())
          AND NOT EXISTS (SELECT 1 FROM {$firmadonem}STLINE L WHERE L.STFICHEREF = F.LOGICALREF)
    ")->execute();
} catch (Throwable $e) {
    error_log('[stok/index] bos fis temizligi: ' . $e->getMessage());
}

$today = date('Y-m-d');
$startDate = isset($_GET['baslangic']) ? trim((string) $_GET['baslangic']) : date('Y-m-01');
$endDate = isset($_GET['bitis']) ? trim((string) $_GET['bitis']) : $today;
$fichenoFilter = isset($_GET['ficheno']) ? trim((string) $_GET['ficheno']) : '';
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;  // önce 100'dü, 50 yaptık
if ($limit < 1) {
    $limit = 1;
}
if ($limit > 500) {
    $limit = 500;
}
$conditions = ["f.TRCODE = 13"];
$params = [];
if ($startDate !== '') {
    $conditions[] = "f.DATE_ >= ?";
    $params[] = $startDate . ' 00:00:00';
}
if ($endDate !== '') {
    $conditions[] = "f.DATE_ < DATEADD(day, 1, ?)";
    $params[] = $endDate . ' 00:00:00';
}
if ($fichenoFilter !== '') {
    $conditions[] = "f.FICHENO LIKE ?";
    $params[] = '%' . $fichenoFilter . '%';
}
$whereSql = implode(' AND ', $conditions);
$query = "
    SELECT TOP {$limit}
        f.LOGICALREF AS FIS_ID,
        f.FICHENO,
        f.DATE_,
        f.DESTINDEX,
        f.GENEXP1,
        SUM(CASE WHEN l.IOCODE = 1 AND l.LINETYPE = 0 AND l.CANCELLED = 0 THEN l.AMOUNT ELSE 0 END) AS TOPLAM_ADET,
        SUM(CASE
                WHEN l.IOCODE = 1 AND l.LINETYPE = 0 AND l.CANCELLED = 0 AND NULLIF(PK.CONVFACT2, 0) IS NOT NULL
                    THEN CAST(l.AMOUNT AS FLOAT) / NULLIF(PK.CONVFACT2, 0)
                ELSE 0
            END) AS TOPLAM_KOLI,
        COUNT(CASE WHEN l.IOCODE = 1 AND l.LINETYPE = 0 AND l.CANCELLED = 0 THEN 1 END) AS SATIR_SAYISI
    FROM {$firmadonem}STFICHE f
    LEFT JOIN {$firmadonem}STLINE l ON l.STFICHEREF = f.LOGICALREF AND l.TRCODE = 13
    LEFT JOIN {$firma}ITEMS it ON it.LOGICALREF = l.STOCKREF
    OUTER APPLY (
        SELECT TOP 1 IA.CONVFACT2
        FROM {$firma}ITMUNITA IA
        WHERE IA.ITEMREF = it.LOGICALREF AND IA.CONVFACT2 IS NOT NULL AND IA.CONVFACT2 > 0
        ORDER BY IA.LINENR DESC
    ) AS PK
    WHERE {$whereSql}
    GROUP BY f.LOGICALREF, f.FICHENO, f.DATE_, f.DESTINDEX, f.GENEXP1
    HAVING SUM(CASE WHEN l.IOCODE = 1 AND l.LINETYPE = 0 AND l.CANCELLED = 0 THEN l.AMOUNT ELSE 0 END) > 0
        OR COUNT(CASE WHEN l.IOCODE = 1 AND l.LINETYPE = 0 AND l.CANCELLED = 0 THEN 1 END) > 0
    ORDER BY f.DATE_ DESC, f.LOGICALREF DESC
";
$stmt = $dbh->prepare($query);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$rowCount = count($rows);
$subtitleText = $rowCount . ' fiş · ' . date('d.m.Y', strtotime($startDate)) . ' — ' . date('d.m.Y', strtotime($endDate));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Üretim Fişleri</title>
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
            --emerald-600: #047857;
            --emerald-soft: #ecfdf5;
            --emerald-border: rgba(5, 150, 105, 0.22);
        }
        * { box-sizing: border-box; margin: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* Sticky Top Header */
        .top-header {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid var(--emerald-border);
            box-shadow: 0 2px 8px rgba(5, 150, 105, 0.05);
        }
        .header-inner {
            max-width: 1200px; margin: 0 auto;
            padding: 14px 20px;
            display: flex; align-items: center; gap: 14px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 40px; height: 40px; min-width: 40px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
            flex-shrink: 0;
        }
        .header-back:hover { background: var(--emerald-soft); color: var(--emerald); }
        .header-icon-badge {
            width: 40px; height: 40px; border-radius: 12px;
            background: linear-gradient(135deg, #10b981, var(--emerald));
            color: #fff;
            display: inline-flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.28);
            flex-shrink: 0;
        }
        .header-icon-badge i { font-size: 16px; }
        .header-titles { flex: 1 1 auto; min-width: 0; }
        .header-title {
            font-size: 16px; font-weight: 700; color: var(--text-1);
            line-height: 1.2;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .header-subtitle {
            font-size: 11.5px; color: var(--text-2); font-weight: 500;
            margin-top: 2px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .btn-primary {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 16px;
            background: var(--emerald); color: #fff;
            border: none; border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 13px; font-weight: 600;
            text-decoration: none; cursor: pointer;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.24);
            transition: all 0.2s ease;
            min-height: 40px; white-space: nowrap;
        }
        .btn-primary:hover { background: var(--emerald-600); transform: translateY(-1px); box-shadow: 0 6px 16px rgba(5, 150, 105, 0.32); }
        .btn-primary i { font-size: 12px; }

        /* Main container */
        .page {
            max-width: 1200px; margin: 0 auto;
            padding: 20px;
        }

        /* Glass card with cardIn animation */
        @keyframes cardIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .glass-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid var(--emerald-border);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            will-change: transform, opacity;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .glass-card.c1 { animation-delay: 0.06s; }
        .glass-card.c2 { animation-delay: 0.14s; }
        .glass-card.c3 { animation-delay: 0.22s; }

        /* Filter panel */
        .filter-card { padding: 16px; margin-bottom: 16px; }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr) auto;
            gap: 12px;
            align-items: end;
        }
        .field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .field label {
            font-size: 10.5px; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .field input, .field select {
            width: 100%;
            padding: 10px 12px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 16px; /* 16px to prevent iOS auto-zoom */
            color: var(--text-1);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            outline: none;
            transition: all 0.2s ease;
            min-height: 44px;
        }
        @media (min-width: 768px) {
            .field input, .field select { font-size: 14px; min-height: 40px; }
        }
        .field input:focus, .field select:focus {
            border-color: rgba(5, 150, 105, 0.5);
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.12);
        }
        .btn-filter {
            display: inline-flex; align-items: center; justify-content: center; gap: 7px;
            padding: 10px 18px;
            background: var(--emerald); color: #fff;
            border: none; border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 13px; font-weight: 600;
            cursor: pointer; transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.22);
            min-height: 44px; white-space: nowrap;
        }
        .btn-filter:hover { background: var(--emerald-600); transform: translateY(-1px); }
        .btn-reset {
            display: inline-flex; align-items: center; justify-content: center; gap: 7px;
            padding: 10px 16px;
            background: #fff; color: var(--text-2);
            border: 1px solid var(--border); border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 13px; font-weight: 600;
            text-decoration: none; cursor: pointer; transition: all 0.2s ease;
            min-height: 44px; white-space: nowrap;
        }
        .btn-reset:hover { background: #fafafa; border-color: var(--text-3); color: var(--text-1); }

        /* Action bar */
        .action-bar {
            display: flex; justify-content: flex-end; align-items: center; gap: 10px;
            margin-bottom: 14px;
        }

        /* Table card */
        .table-card { overflow: hidden; }
        .table-wrap { overflow-x: auto; }
        .gd-table { width: 100%; border-collapse: collapse; }
        .gd-table thead { background: linear-gradient(180deg, #fff, #f5fdf9); }
        .gd-table thead th {
            padding: 11px 14px;
            font-size: 10.5px; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: 0.5px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .gd-table thead th.right { text-align: right; }
        .gd-table tbody td {
            padding: 12px 14px;
            font-size: 13px; color: var(--text-1);
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .gd-table tbody tr:last-child td { border-bottom: none; }
        .gd-table tbody tr { transition: background 0.15s ease; }
        .gd-table tbody tr:hover { background: var(--emerald-soft); }
        .cell-row-num { color: var(--text-3); font-weight: 600; font-size: 12px; width: 48px; }
        .cell-ficheno {
            font-family: 'JetBrains Mono', 'Menlo', 'Consolas', monospace;
            font-weight: 600; color: var(--text-1);
        }
        .cell-date { color: var(--text-2); font-size: 12.5px; white-space: nowrap; }
        .cell-desc { color: var(--text-2); font-size: 12.5px; max-width: 360px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .cell-num { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; color: var(--text-1); white-space: nowrap; }
        .cell-muted { text-align: right; color: var(--text-2); font-variant-numeric: tabular-nums; }
        .cell-actions { text-align: right; white-space: nowrap; }
        .btn-edit {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 12px;
            background: var(--emerald-soft); color: var(--emerald-600);
            border: 1px solid var(--emerald-border); border-radius: 8px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12px; font-weight: 600;
            text-decoration: none; transition: all 0.2s ease;
            min-height: 34px;
        }
        .btn-edit:hover { background: var(--emerald); color: #fff; border-color: var(--emerald); }
        .btn-edit i { font-size: 11px; }
        .pill-tip {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 100px;
            background: var(--emerald-soft);
            color: var(--emerald-600);
            border: 1px solid var(--emerald-border);
            font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.3px;
        }

        /* Empty state */
        .empty-state {
            padding: 64px 24px;
            text-align: center;
            color: var(--text-2);
        }
        .empty-state .icon {
            font-size: 40px; color: var(--text-3); margin-bottom: 12px;
        }
        .empty-state h3 { font-size: 15px; font-weight: 700; color: var(--text-1); margin-bottom: 6px; }
        .empty-state p { font-size: 13px; color: var(--text-2); }

        /* Toast */
        @keyframes slideIn { from { opacity: 0; transform: translateX(100%); } to { opacity: 1; transform: translateX(0); } }
        @keyframes slideOut { from { opacity: 1; transform: translateX(0); } to { opacity: 0; transform: translateX(100%); } }
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 60; display: flex; flex-direction: column; gap: 10px; max-width: 360px; }
        .toast {
            display: flex; align-items: flex-start; gap: 12px;
            background: #ffffff;
            border-left: 4px solid #3b82f6;
            color: #111827;
            border-radius: 10px;
            padding: 12px 14px;
            box-shadow: 0 12px 28px rgba(17, 24, 39, 0.18);
            animation: slideIn 0.3s ease;
        }
        .toast.success { border-left-color: var(--emerald); }
        .toast.error { border-left-color: var(--red,#6F1022); }
        .toast.warning { border-left-color: #d97706; }
        .toast.info { border-left-color: #2563eb; }
        .toast .toast-icon { margin-top: 2px; font-size: 1rem; }
        .toast.success .toast-icon { color: var(--emerald); }
        .toast.error .toast-icon { color: var(--red,#6F1022); }
        .toast.warning .toast-icon { color: #d97706; }
        .toast.info .toast-icon { color: #2563eb; }
        .toast .toast-content { min-width: 0; flex: 1; }
        .toast .toast-title { font-weight: 600; line-height: 1.2; margin-bottom: 2px; font-size: 13px; }
        .toast .toast-message { font-size: 12.5px; line-height: 1.35; color: #4b5563; word-break: break-word; }
        .toast .toast-close { border: 0; background: transparent; color: #6b7280; padding: 0; margin-left: 6px; cursor: pointer; }
        .toast .toast-close:hover { color: #111827; }
        .toast.hiding { animation: slideOut 0.3s ease forwards; }

        /* Responsive */
        @media (max-width: 1024px) {
            .filter-grid { grid-template-columns: 1fr 1fr 1fr; }
            .filter-grid .btn-filter, .filter-grid .btn-reset { grid-column: auto; }
        }
        @media (max-width: 640px) {
            .page { padding: 14px; }
            .header-inner { padding: 12px 14px; gap: 10px; }
            .header-title { font-size: 15px; }
            .header-subtitle { font-size: 11px; }
            .filter-grid { grid-template-columns: 1fr 1fr; }
            .filter-grid .field-actions { grid-column: 1 / -1; display: flex; gap: 10px; }
            .filter-grid .btn-filter, .filter-grid .btn-reset { flex: 1; }

            /* Table -> stacked cards */
            .table-wrap { overflow-x: visible; }
            .gd-table thead { display: none; }
            .gd-table, .gd-table tbody, .gd-table tr, .gd-table td { display: block; width: 100%; }
            .gd-table tbody tr {
                padding: 12px 14px;
                border-bottom: 1px solid var(--border);
                background: #fff;
            }
            .gd-table tbody tr:hover { background: #fff; }
            .gd-table tbody td {
                padding: 4px 0; border-bottom: none;
                display: flex; justify-content: space-between; align-items: center; gap: 12px;
            }
            .gd-table tbody td::before {
                content: attr(data-label);
                font-size: 10.5px; font-weight: 700; color: var(--text-2);
                text-transform: uppercase; letter-spacing: 0.4px;
                flex-shrink: 0;
            }
            .cell-row-num { display: none !important; }
            .cell-desc { white-space: normal; max-width: none; text-align: right; }
            .cell-actions { margin-top: 6px; }
        }
    </style>
</head>
<body>
    <div class="toast-container" id="toastContainer"></div>

    <!-- Sticky Emerald Top Header -->
    <header class="top-header">
        <div class="header-inner">
            <a href="../index.php" class="header-back" title="Ana Sayfa" aria-label="Ana Sayfa">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div class="header-icon-badge"><i class="fa-solid fa-industry"></i></div>
            <div class="header-titles">
                <div class="header-title">Üretim Fişleri</div>
                <div class="header-subtitle"><?= htmlspecialchars($subtitleText); ?></div>
            </div>
            <!-- WhatsApp Liste ozelligi KALDIRILDI (2026-07-07, kullanici karari: kullanilmiyor).
                 Dosyalar git gecmisinde: stok/whatsapp_listeler.php, whatsapp_liste_detay.php,
                 whatsapp_sync_worker.php, whatsapp_uretim_lib.php. M_WA_URETIM_* tablolari duruyor. -->
            <a href="uretim_miktar_onar.php" class="btn-primary" style="background:#f97316;">
                <i class="fa-solid fa-wrench"></i>
                <span>Miktar Onar</span>
            </a>
            <a href="uretim_giris.php?yeni=1" class="btn-primary">
                <i class="fas fa-plus-circle"></i>
                <span>Yeni Üretim</span>
            </a>
        </div>
    </header>

    <main class="page">
        <!-- Filter Panel -->
        <section class="glass-card c1 filter-card">
            <form method="get" class="filter-grid">
                <div class="field">
                    <label for="baslangic">Başlangıç</label>
                    <input type="date" id="baslangic" name="baslangic" value="<?= htmlspecialchars($startDate); ?>">
                </div>
                <div class="field">
                    <label for="bitis">Bitiş</label>
                    <input type="date" id="bitis" name="bitis" value="<?= htmlspecialchars($endDate); ?>">
                </div>
                <div class="field">
                    <label for="ficheno">Fiş No</label>
                    <input type="text" id="ficheno" name="ficheno" value="<?= htmlspecialchars($fichenoFilter); ?>" placeholder="Ara...">
                </div>
                <div class="field">
                    <label for="limit">Limit</label>
                    <select id="limit" name="limit">
                        <?php foreach ([25, 50, 100, 200, 500] as $opt): ?>
                            <option value="<?= $opt; ?>" <?= $limit === $opt ? 'selected' : ''; ?>><?= $opt; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-actions" style="display:flex; gap:8px;">
                    <button type="submit" class="btn-filter">
                        <i class="fas fa-filter"></i> Filtrele
                    </button>
                    <a href="index.php" class="btn-reset">
                        <i class="fas fa-rotate-left"></i> Sıfırla
                    </a>
                </div>
            </form>
        </section>

        <!-- Fiches Table -->
        <section class="glass-card c2 table-card">
            <div class="table-wrap">
                <table class="gd-table">
                    <thead>
                        <tr>
                            <th class="cell-row-num">#</th>
                            <th>Fiş No</th>
                            <th>Tarih</th>
                            <th>Tip</th>
                            <th>Açıklama</th>
                            <th class="right">Toplam Adet</th>
                            <th class="right">Koli</th>
                            <th class="right">Satır</th>
                            <th class="right">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="9">
                                    <div class="empty-state">
                                        <div class="icon"><i class="fa-solid fa-box-open"></i></div>
                                        <h3>Üretim fişi bulunamadı</h3>
                                        <p>Seçili tarih aralığında veya filtrede kayıt yok. Tarihleri genişletmeyi deneyin.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else:
                            $idx = 0;
                            foreach ($rows as $r):
                                $idx++;
                                $fisId = (int) $r['FIS_ID'];
                                $depogiris = isset($r['DESTINDEX']) ? (int) $r['DESTINDEX'] : 0;
                                $koli = isset($r['TOPLAM_KOLI']) ? (float) $r['TOPLAM_KOLI'] : 0.0;
                                $adet = isset($r['TOPLAM_ADET']) ? (float) $r['TOPLAM_ADET'] : 0.0;
                                $fichenoDisp = function_exists('trcevir') ? (string) trcevir($r['FICHENO']) : (string) $r['FICHENO'];
                                $aciklamaDisp = function_exists('trcevir') ? (string) trcevir($r['GENEXP1']) : (string) ($r['GENEXP1'] ?? '');
                        ?>
                            <tr>
                                <td class="cell-row-num" data-label="#"><?= $idx; ?></td>
                                <td class="cell-ficheno" data-label="Fiş No"><?= htmlspecialchars($fichenoDisp, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="cell-date" data-label="Tarih"><?= htmlspecialchars(date('d.m.Y H:i', strtotime((string) $r['DATE_']))); ?></td>
                                <td data-label="Tip"><span class="pill-tip">Üretim</span></td>
                                <td class="cell-desc" data-label="Açıklama" title="<?= htmlspecialchars($aciklamaDisp, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($aciklamaDisp, ENT_QUOTES, 'UTF-8') ?: '—'; ?></td>
                                <td class="cell-num" data-label="Toplam Adet"><?= htmlspecialchars(kusuratadet($adet)); ?></td>
                                <td class="cell-num" data-label="Koli"><?= htmlspecialchars(kusuratadet($koli)); ?></td>
                                <td class="cell-muted" data-label="Satır"><?= (int) $r['SATIR_SAYISI']; ?></td>
                                <td class="cell-actions" data-label="İşlem">
                                    <a class="btn-edit" href="uretim_giris.php?fis=<?= $fisId; ?>&depogiris=<?= $depogiris; ?>">
                                        <i class="fas fa-pen-to-square"></i> Düzenle
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <script>
        // RAF reflow fix for card entry animation
        requestAnimationFrame(function() {
            document.querySelectorAll('.glass-card').forEach(function(el) {
                void el.offsetWidth;
            });
        });

        // Toast Notification System
        const Toast = {
            container: null,
            init() {
                this.container = document.getElementById('toastContainer');
                if (!this.container) {
                    this.container = document.createElement('div');
                    this.container.id = 'toastContainer';
                    this.container.className = 'toast-container';
                    document.body.appendChild(this.container);
                }
            },
            show(message, type = 'info', title = '', duration = 5000) {
                if (!this.container) this.init();
                const toast = document.createElement('div');
                toast.className = `toast ${type}`;
                const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
                const titles = { success: 'Başarılı', error: 'Hata', warning: 'Uyarı', info: 'Bilgi' };
                const icon = icons[type] || icons.info;
                const toastTitle = title || titles[type] || titles.info;
                toast.innerHTML = `
                    <i class="fas ${icon} toast-icon"></i>
                    <div class="toast-content">
                        <div class="toast-title">${toastTitle}</div>
                        <div class="toast-message">${message}</div>
                    </div>
                    <button class="toast-close" onclick="Toast.remove(this.parentElement)"><i class="fas fa-times"></i></button>
                `;
                this.container.appendChild(toast);
                if (duration > 0) setTimeout(() => this.remove(toast), duration);
                return toast;
            },
            remove(toast) {
                if (!toast) return;
                toast.classList.add('hiding');
                setTimeout(() => { if (toast.parentElement) toast.parentElement.removeChild(toast); }, 300);
            },
            success(m, t = '', d = 5000) { return this.show(m, 'success', t, d); },
            error(m, t = '', d = 7000) { return this.show(m, 'error', t, d); },
            warning(m, t = '', d = 6000) { return this.show(m, 'warning', t, d); },
            info(m, t = '', d = 5000) { return this.show(m, 'info', t, d); }
        };

        document.addEventListener('DOMContentLoaded', function() {
            Toast.init();
            const urlParams = new URLSearchParams(window.location.search);
            const successMsg = urlParams.get('success');
            const errorMsg = urlParams.get('error');
            if (successMsg) Toast.success(decodeURIComponent(successMsg));
            if (errorMsg) Toast.error(decodeURIComponent(errorMsg));
        });
    </script>
</body>
</html>
