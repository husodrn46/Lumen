<?php
declare(strict_types=1);

include_once(__DIR__ . "/ayr.php");
include(__DIR__ . "/kontrol.php");

// Sayfalama
$sayfa = isset($_GET['sayfa']) ? max(1, (int)$_GET['sayfa']) : 1;
$limit = 50;
$offset = ($sayfa - 1) * $limit;

// Arama
$arama = isset($_GET['arama']) ? trim($_GET['arama']) : '';

// Toplam kayıt sayısı
$countSql = "SELECT COUNT(*) as toplam FROM {$firma}ITEMS WHERE CODE LIKE 'AKL%' AND ACTIVE = 0";
$countParams = [];

if ($arama !== '') {
    $countSql .= " AND (CODE LIKE :arama OR NAME LIKE :arama2)";
    $countParams[':arama'] = "%$arama%";
    $countParams[':arama2'] = "%$arama%";
}

$countStmt = $dbh->prepare($countSql);
$countStmt->execute($countParams);
$toplam = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['toplam'];
$toplamSayfa = ceil($toplam / $limit);

// Ürünleri çek
$sql = "
    SELECT
        I.LOGICALREF,
        I.CODE,
        I.NAME,
        I.UNITSETREF,
        COALESCE(U.WIDTH, 0) AS WIDTH,
        COALESCE(U.LENGTH, 0) AS LENGTH,
        COALESCE(U.HEIGHT, 0) AS HEIGHT,
        COALESCE(U.WEIGHT, 0) AS WEIGHT,
        U.LOGICALREF AS ITMUNITA_REF
    FROM {$firma}ITEMS I
    LEFT JOIN {$firma}ITMUNITA U ON I.LOGICALREF = U.ITEMREF AND U.LINENR = 1
    WHERE I.CODE LIKE 'AKL%' AND I.ACTIVE = 0
";

$params = [];
if ($arama !== '') {
    $sql .= " AND (I.CODE LIKE :arama OR I.NAME LIKE :arama2)";
    $params[':arama'] = "%$arama%";
    $params[':arama2'] = "%$arama%";
}

$sql .= " ORDER BY
    CASE WHEN SUBSTRING(I.CODE, 4, 1) LIKE '[0-9]' THEN 0 ELSE 1 END,
    CASE WHEN SUBSTRING(I.CODE, 4, 1) LIKE '[0-9]'
         THEN CAST(SUBSTRING(I.CODE, 4, 3) AS INT)
         ELSE 9999 END,
    I.CODE
    OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY";

$stmt = $dbh->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$urunler = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <link rel="icon" type="image/png" href="icon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Toplu Koli Olcusu Duzenleme</title>
    <?php if (file_exists(__DIR__ . '/pwa-header.php')) { include_once __DIR__ . '/pwa-header.php'; } ?>
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
        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; }

        /* ═══════ HEADER ═══════ */
        .top-header {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(248, 113, 113, 0.18);
            box-shadow: 0 2px 8px rgba(111, 16, 34, 0.04);
        }
        .header-inner {
            max-width: 1280px; margin: 0 auto;
            padding: 14px 24px;
            display: flex; align-items: center; gap: 14px;
            flex-wrap: wrap;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
            flex-shrink: 0;
        }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--red); }
        .header-divider { width: 1px; height: 24px; background: var(--border); flex-shrink: 0; }
        .header-title { min-width: 0; flex: 1 1 auto; }
        .header-title h1 {
            font-size: 16px; font-weight: 700; color: var(--text-1);
            display: flex; align-items: center; gap: 8px; margin: 0;
        }
        .header-title h1 i { color: var(--sky); font-size: 14px; }
        .header-title p {
            margin: 2px 0 0; font-size: 11.5px; color: var(--text-2); font-weight: 500;
        }
        .header-title p strong { color: var(--text-1); font-weight: 700; }

        /* ═══════ SEARCH FORM ═══════ */
        .header-search {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .search-wrap { position: relative; }
        .search-wrap i.fa-search {
            position: absolute; top: 50%; left: 12px;
            transform: translateY(-50%);
            color: var(--text-3); font-size: 13px;
            pointer-events: none;
        }
        .search-input {
            width: 220px;
            padding: 9px 14px 9px 36px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13px;
            color: var(--text-1);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            outline: none;
            transition: all 0.2s ease;
        }
        .search-input:focus {
            border-color: rgba(239, 68, 68, 0.5);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }
        .btn-search {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 9px 14px;
            background: var(--red,#ef4444); color: #fff;
            border: none; border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 13px; font-weight: 600;
            cursor: pointer; transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.18);
        }
        .btn-search:hover { background: var(--red); transform: translateY(-1px); }
        .btn-clear {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 9px 12px;
            background: #fff; color: var(--text-2);
            border: 1px solid var(--border); border-radius: 10px;
            font-size: 12px; cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .btn-clear:hover { background: #fafafa; color: var(--text-1); }

        /* ═══════ TABLE CARD ═══════ */
        .table-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            overflow: hidden;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
        }
        .koli-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .koli-table thead { background: linear-gradient(180deg, #fff, #fffafa); }
        .koli-table thead th {
            padding: 11px 14px;
            font-size: 10px; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: 0.5px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .koli-table thead th.center { text-align: center; }
        .koli-table tbody td {
            padding: 8px 14px;
            color: var(--text-1);
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .koli-table tbody tr {
            transition: background 0.2s ease;
        }
        .koli-table tbody tr:hover { background: #fafafa; }
        .koli-table tbody tr:last-child td { border-bottom: none; }
        .koli-table tbody tr.row-modified { background: #fffbeb !important; }
        .koli-table tbody tr.row-modified:hover { background: #fef3c7 !important; }
        .koli-table tbody tr.row-saved { animation: flash-green 0.6s; }
        @keyframes flash-green {
            0% { background: #d1fae5; }
            100% { background: transparent; }
        }

        .koli-table .col-code {
            font-family: 'JetBrains Mono', 'Courier New', monospace;
            font-size: 11px;
            color: var(--red);
            font-weight: 700;
            white-space: nowrap;
        }

        .input-name {
            width: 100%;
            padding: 8px 36px 8px 12px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 12.5px;
            color: var(--text-1);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 8px;
            outline: none;
            transition: all 0.15s ease;
        }
        .input-name:focus {
            border-color: rgba(239, 68, 68, 0.5);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }
        .input-name.limit-warning {
            border-color: var(--amber);
            background: var(--amber-soft);
        }
        .input-name.limit-danger {
            border-color: var(--red);
            background: var(--red-soft);
        }

        .name-wrap { position: relative; }
        .char-count {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 10px;
            color: var(--text-3);
            font-weight: 600;
            pointer-events: none;
            background: rgba(255,255,255,0.9);
            padding: 1px 4px;
            border-radius: 4px;
        }
        .char-count.warning { color: var(--amber); }
        .char-count.danger { color: var(--red); }

        .input-num {
            width: 86px;
            padding: 8px 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text-1);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 8px;
            outline: none;
            text-align: right;
            transition: all 0.15s ease;
        }
        .input-num:focus {
            border-color: rgba(239, 68, 68, 0.5);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }

        .row-actions {
            display: flex;
            gap: 6px;
            justify-content: center;
        }
        .btn-copy, .btn-save {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px; height: 32px;
            border: none;
            border-radius: 8px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-copy {
            background: var(--sky-soft);
            color: var(--sky);
            border: 1px solid rgba(14, 165, 233, 0.22);
        }
        .btn-copy:hover { background: var(--sky); color: #fff; border-color: var(--sky); }
        .btn-save {
            background: var(--emerald);
            color: #fff;
            box-shadow: 0 2px 6px rgba(5, 150, 105, 0.25);
        }
        .btn-save:hover:not(:disabled) {
            background: #047857;
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(5, 150, 105, 0.35);
        }
        .btn-save:disabled {
            background: #d1d5db;
            cursor: not-allowed;
            box-shadow: none;
            opacity: 0.6;
        }

        /* ═══════ PAGINATION ═══════ */
        .pager {
            padding: 14px 18px;
            background: #fafafa;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .pager-buttons { display: flex; gap: 6px; }
        .pager-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px; height: 34px;
            background: #fff;
            color: var(--text-2);
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 11px;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .pager-btn:hover { background: var(--red-soft); color: var(--red); border-color: rgba(239, 68, 68, 0.3); }
        .pager-info {
            font-size: 12px;
            color: var(--text-2);
            font-weight: 500;
        }
        .pager-info strong { color: var(--text-1); font-weight: 700; }

        /* ═══════ INFO BAR ═══════ */
        .info-bar {
            margin-top: 16px;
            padding: 14px 18px;
            background: var(--amber-soft);
            border: 1px solid rgba(217, 119, 6, 0.22);
            border-radius: 12px;
            font-size: 12px;
            color: #92400e;
            line-height: 1.6;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        .info-bar i { color: var(--amber); font-size: 14px; flex-shrink: 0; margin-top: 2px; }
        .info-bar .info-text strong { color: #78350f; }

        /* ═══════ TOAST ═══════ */
        .toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .toast {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 18px;
            color: #fff;
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13px;
            font-weight: 600;
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
            animation: toastIn 0.3s ease both;
            min-width: 240px;
        }
        .toast.success { background: var(--emerald); }
        .toast.error { background: var(--red); }
        @keyframes toastIn {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        /* ═══════ MOBILE ═══════ */
        @media (max-width: 900px) {
            .header-search { width: 100%; }
            .search-input { width: 100%; flex: 1 1 auto; }
        }
        @media (max-width: 767px) {
            .header-inner { padding: 12px 14px; gap: 10px; }
            .header-title h1 { font-size: 14px; }
            .header-title p { font-size: 10.5px; }
            .header-back { width: 30px; height: 30px; border-radius: 8px; }

            main { padding: 14px 12px 40px !important; }

            .search-input { font-size: 16px; }
            .btn-search { padding: 9px 12px; font-size: 12px; }

            .koli-table thead { display: none; }
            .koli-table, .koli-table tbody, .koli-table tr, .koli-table td {
                display: block;
                width: 100%;
            }
            .koli-table tbody tr {
                padding: 14px 14px 12px;
                border-bottom: 1px solid #f3f4f6;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px 10px;
            }
            .koli-table tbody td {
                padding: 0;
                border-bottom: none;
            }
            .koli-table tbody td.col-code {
                grid-column: 1 / -1;
                padding-bottom: 4px;
                font-size: 11px;
            }
            .koli-table tbody td.col-name { grid-column: 1 / -1; }
            .input-num { width: 100%; font-size: 16px; }
            .input-name { font-size: 16px; }

            .koli-table tbody td::before {
                display: block;
                font-size: 9.5px;
                color: var(--text-3);
                text-transform: uppercase;
                letter-spacing: 0.4px;
                font-weight: 600;
                margin-bottom: 3px;
            }
            .koli-table tbody td.col-w::before { content: 'Agirlik (kg)'; }
            .koli-table tbody td.col-l::before { content: 'Uzunluk (mm)'; }
            .koli-table tbody td.col-wd::before { content: 'Genislik (mm)'; }
            .koli-table tbody td.col-h::before { content: 'Yukseklik (mm)'; }
            .koli-table tbody td.col-actions { grid-column: 1 / -1; padding-top: 6px; }
            .row-actions { justify-content: flex-end; }
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
            <div class="header-title">
                <h1><i class="fa-solid fa-box-open"></i>Toplu Koli Olcusu Duzenleme</h1>
                <p>Toplam <strong><?= $toplam ?></strong> urun &middot; Sayfa <strong><?= $sayfa ?> / <?= $toplamSayfa ?></strong></p>
            </div>
            <form method="GET" class="header-search">
                <div class="search-wrap">
                    <i class="fa-solid fa-search"></i>
                    <input type="text" name="arama" value="<?= htmlspecialchars($arama) ?>"
                           class="search-input" placeholder="Kod veya isim ara..." autocomplete="off">
                </div>
                <button type="submit" class="btn-search" title="Ara">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </button>
                <?php if ($arama): ?>
                <a href="koli_olcu_toplu.php" class="btn-clear" title="Aramayi temizle">
                    <i class="fa-solid fa-xmark"></i>
                </a>
                <?php endif; ?>
            </form>
        </div>
    </header>

    <main style="max-width:1280px;margin:0 auto;padding:20px 24px 40px;">

        <div class="table-card">
            <div style="overflow-x:auto;">
                <table class="koli-table">
                    <thead>
                        <tr>
                            <th style="width: 120px;">Kod</th>
                            <th style="min-width: 250px;">Urun Adi</th>
                            <th class="center" style="width: 110px;">Agirlik (kg)</th>
                            <th class="center" style="width: 110px;">Uzunluk (mm)</th>
                            <th class="center" style="width: 110px;">Genislik (mm)</th>
                            <th class="center" style="width: 110px;">Yukseklik (mm)</th>
                            <th class="center" style="width: 90px;">Islem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($urunler as $index => $urun): ?>
                        <tr id="row-<?= $urun['LOGICALREF'] ?>"
                            data-original='<?= json_encode([
                                'name' => $urun['NAME'],
                                'width' => $urun['WIDTH'],
                                'length' => $urun['LENGTH'],
                                'height' => $urun['HEIGHT'],
                                'weight' => $urun['WEIGHT']
                            ]) ?>'>
                            <td class="col-code">
                                <?= htmlspecialchars($urun['CODE']) ?>
                            </td>
                            <td class="col-name">
                                <div class="name-wrap">
                                    <input type="text"
                                           class="input-name"
                                           name="name"
                                           value="<?= htmlspecialchars($urun['NAME']) ?>"
                                           data-id="<?= $urun['LOGICALREF'] ?>"
                                           onchange="markModified(this)"
                                           oninput="checkNameLength(this)"
                                           maxlength="51"
                                           tabindex="-1">
                                    <span class="char-count" id="char-<?= $urun['LOGICALREF'] ?>"><?= 51 - mb_strlen($urun['NAME'] ?? '') ?></span>
                                </div>
                            </td>
                            <td class="col-w">
                                <input type="number" class="input-num" name="weight"
                                       value="<?= number_format((float)$urun['WEIGHT'], 1, '.', '') ?>"
                                       data-id="<?= $urun['LOGICALREF'] ?>"
                                       onchange="markModified(this)" min="0" step="0.1" inputmode="decimal">
                            </td>
                            <td class="col-l">
                                <input type="number" class="input-num" name="length"
                                       value="<?= (int)$urun['LENGTH'] ?>"
                                       data-id="<?= $urun['LOGICALREF'] ?>"
                                       onchange="markModified(this)" min="0" step="1" inputmode="numeric">
                            </td>
                            <td class="col-wd">
                                <input type="number" class="input-num" name="width"
                                       value="<?= (int)$urun['WIDTH'] ?>"
                                       data-id="<?= $urun['LOGICALREF'] ?>"
                                       onchange="markModified(this)" min="0" step="1" inputmode="numeric">
                            </td>
                            <td class="col-h">
                                <input type="number" class="input-num" name="height"
                                       value="<?= (int)$urun['HEIGHT'] ?>"
                                       data-id="<?= $urun['LOGICALREF'] ?>"
                                       onchange="markModified(this)" min="0" step="1" inputmode="numeric">
                            </td>
                            <td class="col-actions">
                                <div class="row-actions">
                                    <button type="button" class="btn-copy"
                                            title="Ustteki satiri kopyala"
                                            onclick="copyFromAbove(<?= $urun['LOGICALREF'] ?>)">
                                        <i class="fa-solid fa-arrow-down"></i>
                                    </button>
                                    <button type="button" class="btn-save"
                                            id="btn-<?= $urun['LOGICALREF'] ?>"
                                            onclick="saveRow(<?= $urun['LOGICALREF'] ?>)" disabled
                                            title="Kaydet">
                                        <i class="fa-solid fa-floppy-disk"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($toplamSayfa > 1): ?>
            <div class="pager">
                <div class="pager-buttons">
                    <?php if ($sayfa > 1): ?>
                        <a href="?sayfa=1&arama=<?= urlencode($arama) ?>" class="pager-btn" title="Ilk"><i class="fa-solid fa-angles-left"></i></a>
                        <a href="?sayfa=<?= $sayfa - 1 ?>&arama=<?= urlencode($arama) ?>" class="pager-btn" title="Onceki"><i class="fa-solid fa-angle-left"></i></a>
                    <?php endif; ?>
                </div>
                <span class="pager-info">Sayfa <strong><?= $sayfa ?></strong> / <?= $toplamSayfa ?></span>
                <div class="pager-buttons">
                    <?php if ($sayfa < $toplamSayfa): ?>
                        <a href="?sayfa=<?= $sayfa + 1 ?>&arama=<?= urlencode($arama) ?>" class="pager-btn" title="Sonraki"><i class="fa-solid fa-angle-right"></i></a>
                        <a href="?sayfa=<?= $toplamSayfa ?>&arama=<?= urlencode($arama) ?>" class="pager-btn" title="Son"><i class="fa-solid fa-angles-right"></i></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="info-bar">
            <i class="fa-solid fa-circle-info"></i>
            <div class="info-text">
                Degisiklik yaptiginizda satir <strong>sari renk</strong> alir. Kaydetmek icin <i class="fa-solid fa-floppy-disk"></i> butonuna basin.
                <br><strong>Not:</strong> Urun adi en fazla <strong>51 karakter</strong> olabilir.
            </div>
        </div>
    </main>

    <div class="toast-container" id="toastContainer"></div>

    <script>
    function markModified(input) {
        const row = input.closest('tr');
        const id = input.dataset.id;
        row.classList.add('row-modified');
        document.getElementById('btn-' + id).disabled = false;
    }

    function checkNameLength(input) {
        const maxLen = 51;
        const remaining = maxLen - input.value.length;
        const id = input.dataset.id;
        const counter = document.getElementById('char-' + id);

        counter.textContent = remaining;
        counter.classList.remove('warning', 'danger');
        input.classList.remove('limit-warning', 'limit-danger');

        if (remaining <= 0) {
            counter.classList.add('danger');
            input.classList.add('limit-danger');
        } else if (remaining <= 10) {
            counter.classList.add('warning');
            input.classList.add('limit-warning');
        }

        markModified(input);
    }

    function copyFromAbove(id) {
        const currentRow = document.getElementById('row-' + id);
        const prevRow = currentRow.previousElementSibling;

        if (!prevRow || !prevRow.id.startsWith('row-')) {
            showToast('Ustte satir yok!', 'error');
            return;
        }

        // Ustteki satirdan degerleri al
        const weight = prevRow.querySelector('input[name="weight"]').value;
        const length = prevRow.querySelector('input[name="length"]').value;
        const width = prevRow.querySelector('input[name="width"]').value;
        const height = prevRow.querySelector('input[name="height"]').value;

        // Mevcut satira kopyala
        currentRow.querySelector('input[name="weight"]').value = weight;
        currentRow.querySelector('input[name="length"]').value = length;
        currentRow.querySelector('input[name="width"]').value = width;
        currentRow.querySelector('input[name="height"]').value = height;

        // Satiri degismis olarak isaretle
        currentRow.classList.add('row-modified');
        document.getElementById('btn-' + id).disabled = false;

        showToast('Veriler kopyalandi');
    }

    function showToast(message, type = 'success') {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = `toast ${type === 'success' ? 'success' : 'error'}`;
        toast.innerHTML = `<i class="fa-solid fa-${type === 'success' ? 'circle-check' : 'circle-exclamation'}"></i>${message}`;
        container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    async function saveRow(id) {
        const row = document.getElementById('row-' + id);
        const btn = document.getElementById('btn-' + id);
        const inputs = row.querySelectorAll('input');

        const data = {
            id: id,
            name: row.querySelector('input[name="name"]').value,
            width: parseFloat(row.querySelector('input[name="width"]').value) || 0,
            length: parseFloat(row.querySelector('input[name="length"]').value) || 0,
            height: parseFloat(row.querySelector('input[name="height"]').value) || 0,
            weight: parseFloat(row.querySelector('input[name="weight"]').value) || 0
        };

        // İsim uzunluğu kontrolü
        if (data.name.length > 51) {
            showToast('Urun adi en fazla 51 karakter olabilir!', 'error');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        try {
            const response = await fetch('koli_olcu_kaydet.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                row.classList.remove('row-modified');
                row.classList.add('row-saved');
                setTimeout(() => row.classList.remove('row-saved'), 500);
                showToast(result.message || 'Kaydedildi');

                // Original data'yi guncelle
                row.dataset.original = JSON.stringify({
                    name: data.name,
                    width: data.width,
                    length: data.length,
                    height: data.height,
                    weight: data.weight
                });
            } else {
                showToast(result.message || 'Hata olustu', 'error');
                btn.disabled = false;
            }
        } catch (error) {
            showToast('Baglanti hatasi', 'error');
            btn.disabled = false;
        }

        btn.innerHTML = '<i class="fas fa-save"></i>';
    }
    </script>
</body>
</html>
