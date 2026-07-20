<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

	include_once(__DIR__ . "/ayr.php");
	include(__DIR__ . "/kontrol.php");

	// YETKI KONTROLÜ: M7 (Fiyat Listesi) yetkisi kontrolü
	// Not: index.php menüsünde de M7 ile gösteriliyor, ancak direkt URL erişimini de engellemek için burada zorunlu kılınır.
	if (m_p_yetki($terminalkullanici, 'M7') != 1 && (int)($yetkidurum ?? 0) !== 0) {
	    header('Location: ' . APP_ROOT_URL . '/403.html');
	    exit;
	}

	// Arama (liste + export aynı filtreyi kullanmalı)
	$arama = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

	// Excel export
	if (isset($_GET['export']) && $_GET['export'] === 'excel') {
	    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
	    header('Content-Disposition: attachment; filename="fiyat_listesi_' . date('Y-m-d_H-i') . '.xls"');
	    header('Cache-Control: max-age=0');

    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta charset="utf-8"></head>';
    echo '<body>';
    echo '<table border="1">';
    echo '<tr style="background:#333; color:#fff; font-weight:bold;">';
    echo '<th>Stok Kodu</th>';
    echo '<th>Stok Adı</th>';
    echo '<th>Koli İçi</th>';
    echo '<th>Fiyat</th>';
    echo '<th>KDV %</th>';
		    echo '</tr>';

	    $sql = "SELECT
	        I.CODE, I.NAME, I.VAT,
	        ISNULL(P.PRICE, 0) as PRICE,
	        ISNULL(KB.KOLI_ICI, 1) as KOLI_ICI
	        FROM {$firma}ITEMS I
	        OUTER APPLY (SELECT TOP 1 PRICE FROM {$firma}PRCLIST WHERE CARDREF = I.LOGICALREF AND PTYPE = 2 AND ACTIVE = 0 ORDER BY LOGICALREF DESC) P
	        LEFT JOIN (SELECT ITEMREF, MAX(CONVFACT2) AS KOLI_ICI FROM {$firma}ITMUNITA GROUP BY ITEMREF) KB ON KB.ITEMREF = I.LOGICALREF
	        WHERE I.ACTIVE = 0";

	    if ($arama !== '') {
	        $sql .= " AND (I.CODE LIKE :arama OR I.NAME LIKE :arama2)";
	    }

	    $sql .= "
	        ORDER BY I.CODE";

	    $stmt = $dbh->prepare($sql);
	    if ($arama !== '') {
	        $aramaParam = '%' . $arama . '%';
	        $stmt->bindValue(':arama', $aramaParam, PDO::PARAM_STR);
	        $stmt->bindValue(':arama2', $aramaParam, PDO::PARAM_STR);
	    }
	    $stmt->execute();
	    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
	        $fiyat = (float)$row['PRICE'];
	        $kdv = (float)$row['VAT'];
	        $kdvDahil = $fiyat * (1 + $kdv / 100);

        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['CODE']) . '</td>';
        echo '<td>' . htmlspecialchars(tr($row['NAME'])) . '</td>';
        echo '<td style="text-align:center;">' . number_format((float) $row['KOLI_ICI'], 0) . '</td>';
        echo '<td style="text-align:right;">' . number_format($fiyat, 2, ',', '.') . '</td>';
        echo '<td style="text-align:center;">' . number_format($kdv, 0) . '</td>';
        echo '</tr>';
    }

    echo '</table>';
    echo '</body></html>';
	    exit;
	}

	// Ürünleri çek
	$sql = "SELECT
	    I.LOGICALREF, I.CODE, I.NAME, I.VAT,
	    ISNULL(P.PRICE, 0) as PRICE,
	    ISNULL(KB.KOLI_ICI, 1) as KOLI_ICI
    FROM {$firma}ITEMS I
    OUTER APPLY (SELECT TOP 1 PRICE FROM {$firma}PRCLIST WHERE CARDREF = I.LOGICALREF AND PTYPE = 2 AND ACTIVE = 0 ORDER BY LOGICALREF DESC) P
    LEFT JOIN (SELECT ITEMREF, MAX(CONVFACT2) AS KOLI_ICI FROM {$firma}ITMUNITA GROUP BY ITEMREF) KB ON KB.ITEMREF = I.LOGICALREF
    WHERE I.ACTIVE = 0";

if (!empty($arama)) {
    $sql .= " AND (I.CODE LIKE :arama OR I.NAME LIKE :arama2)";
}
$sql .= " ORDER BY I.CODE";

$stmt = $dbh->prepare($sql);
if (!empty($arama)) {
    $aramaParam = '%' . $arama . '%';
    $stmt->bindValue(':arama', $aramaParam, PDO::PARAM_STR);
    $stmt->bindValue(':arama2', $aramaParam, PDO::PARAM_STR);
}
$stmt->execute();
$urunler = $stmt->fetchAll(PDO::FETCH_ASSOC);
$toplam = count($urunler);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fiyat Listesi</title>
    <link rel="icon" type="image/png" href="icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <?php if (file_exists(__DIR__ . '/tailwind.local.css')): ?>
        <link rel="stylesheet" href="tailwind.local.css">
    <?php else: ?>
        <script src="https://cdn.tailwindcss.com"></script>
    <?php endif; ?>
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
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Avenir Next', 'Montserrat', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg);
            color: var(--text-1);
            margin: 0;
            padding: 0;
            -webkit-font-smoothing: antialiased;
        }

        .page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 16px 32px;
        }

        .top-header {
            position: sticky;
            top: 0;
            z-index: 50;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: saturate(180%) blur(12px);
            -webkit-backdrop-filter: saturate(180%) blur(12px);
            border-bottom: 1px solid var(--border);
            margin: 0 -16px 20px;
            padding: 14px 16px;
        }

        .top-header-inner {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: #fff;
            border: 1px solid var(--border);
            color: var(--text-1);
            text-decoration: none;
            transition: all 0.15s ease;
            flex-shrink: 0;
        }
        .back-btn:hover {
            background: var(--red-soft);
            border-color: var(--red);
            color: var(--red);
        }

        .header-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: var(--red-soft);
            color: var(--red);
            font-size: 18px;
            flex-shrink: 0;
        }

        .header-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-1);
            margin: 0;
            line-height: 1.2;
            flex: 1;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .header-count {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 999px;
            background: var(--red-soft);
            color: var(--red);
            font-size: 12px;
            font-weight: 600;
            border: 1px solid #fecaca;
            flex-shrink: 0;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: saturate(180%) blur(12px);
            -webkit-backdrop-filter: saturate(180%) blur(12px);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04), 0 1px 2px rgba(0, 0, 0, 0.03);
            margin-bottom: 16px;
            overflow: hidden;
        }

        .filter-panel { padding: 16px; }

        .filter-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .search-wrap {
            position: relative;
            flex: 1 1 280px;
            min-width: 220px;
        }

        .search-wrap i.fa-search,
        .search-wrap i.fa-magnifying-glass {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-3);
            font-size: 14px;
            pointer-events: none;
        }

        .search-input {
            width: 100%;
            padding: 11px 14px 11px 38px;
            font-size: 14px;
            font-family: inherit;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text-1);
            transition: all 0.15s ease;
            outline: none;
        }
        .search-input:focus {
            border-color: var(--red);
            box-shadow: 0 0 0 3px rgba(111, 16, 34, 0.1);
        }
        .search-input::placeholder { color: var(--text-3); }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 18px;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            border-radius: 12px;
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .btn-primary { background: var(--text-1); color: #fff; }
        .btn-primary:hover { background: #111827; }

        .btn-ghost {
            background: #fff;
            border-color: var(--border);
            color: var(--text-1);
        }
        .btn-ghost:hover {
            background: var(--red-soft);
            border-color: #fecaca;
            color: var(--red);
        }

        .btn-emerald { background: var(--emerald); color: #fff; }
        .btn-emerald:hover { background: #047857; }

        .table-card { padding: 0; }

        .table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .gd-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .gd-table thead th {
            position: sticky;
            top: 0;
            background: #f9fafb;
            color: var(--text-2);
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            text-align: left;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .gd-table thead th.t-right { text-align: right; }
        .gd-table thead th.t-center { text-align: center; }

        .gd-table tbody td {
            padding: 12px 16px;
            border-bottom: 1px solid #f3f4f6;
            color: var(--text-1);
            vertical-align: middle;
        }
        .gd-table tbody tr:hover td { background: var(--red-soft); }
        .gd-table tbody tr:last-child td { border-bottom: none; }

        .cell-code {
            font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
            font-weight: 600;
            color: var(--text-1);
            font-size: 13px;
        }
        .cell-name { color: var(--text-1); }
        .cell-num {
            text-align: right;
            font-variant-numeric: tabular-nums;
            font-weight: 600;
            color: var(--text-1);
            white-space: nowrap;
        }
        .cell-num-bold {
            text-align: right;
            font-variant-numeric: tabular-nums;
            font-weight: 700;
            color: var(--emerald);
            white-space: nowrap;
        }

        .vat-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 42px;
            padding: 3px 10px;
            border-radius: 999px;
            background: var(--indigo-soft);
            color: var(--indigo);
            font-size: 12px;
            font-weight: 600;
        }
        .cell-vat { text-align: center; }

        .empty {
            padding: 60px 20px;
            text-align: center;
            color: var(--text-3);
        }
        .empty i {
            font-size: 32px;
            margin-bottom: 12px;
            color: var(--text-3);
        }
        .empty p {
            margin: 0;
            font-size: 14px;
            color: var(--text-2);
        }

        @media (max-width: 768px) {
            .page { padding: 0 12px 24px; }
            .top-header { margin: 0 -12px 16px; padding: 12px; }
            .header-title { font-size: 17px; }
            .filter-panel { padding: 12px; }
            .search-wrap { flex: 1 1 100%; }
            .btn { flex: 1 1 auto; }

            .gd-table thead { display: none; }
            .gd-table, .gd-table tbody, .gd-table tr, .gd-table td { display: block; width: 100%; }
            .gd-table tbody tr {
                padding: 12px 14px;
                border-bottom: 1px solid var(--border);
                background: #fff;
            }
            .gd-table tbody tr:hover td { background: transparent; }
            .gd-table tbody td {
                padding: 4px 0;
                border: none;
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 12px;
            }
            .gd-table tbody td::before {
                content: attr(data-label);
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                color: var(--text-2);
                flex-shrink: 0;
            }
            .cell-num, .cell-num-bold { text-align: right; }

            input.search-input,
            select.search-input,
            textarea.search-input {
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
    <?php
    $pwaHeader = __DIR__ . '/pwa-header.php';
    if (file_exists($pwaHeader)) {
        include $pwaHeader;
    }
    ?>
    <div class="page">
        <div class="top-header">
            <div class="top-header-inner">
                <a href="index.php" class="back-btn" aria-label="Geri">
                    <i class="fa-solid fa-arrow-left"></i>
                </a>
                <span class="header-icon"><i class="fa-solid fa-tags"></i></span>
                <h1 class="header-title">Fiyat Listesi</h1>
                <span class="header-count">
                    <i class="fa-solid fa-box"></i>
                    <?php echo number_format($toplam); ?> ürün
                </span>
            </div>
        </div>

        <div class="glass-card filter-panel">
            <form method="GET" class="filter-row">
                <div class="search-wrap">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" name="q" class="search-input"
                        value="<?php echo htmlspecialchars($arama); ?>"
                        placeholder="Stok kodu veya adı ara...">
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-magnifying-glass"></i> Ara
                </button>
                <?php if (!empty($arama)): ?>
                    <a href="fiyat_listesi.php" class="btn btn-ghost">
                        <i class="fa-solid fa-xmark"></i> Temizle
                    </a>
                <?php endif; ?>
                <a href="fiyat_listesi.php?export=excel<?php echo !empty($arama) ? '&q=' . urlencode($arama) : ''; ?>"
                    class="btn btn-emerald">
                    <i class="fa-solid fa-file-excel"></i> Excel
                </a>
            </form>
        </div>

        <div class="glass-card table-card">
            <div class="table-wrap">
                <table class="gd-table">
                    <thead>
                        <tr>
                            <th>Stok Kodu</th>
                            <th>Stok Adı</th>
                            <th class="t-center">Koli İçi</th>
                            <th class="t-right">Fiyat</th>
                            <th class="t-center">KDV %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($urunler)): ?>
                            <tr>
                                <td colspan="5">
                                    <div class="empty">
                                        <i class="fa-solid fa-magnifying-glass"></i>
                                        <p>Ürün bulunamadı</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($urunler as $urun):
                                $fiyat = (float)$urun['PRICE'];
                                $kdv = (float)$urun['VAT'];
                                $kdvDahil = $fiyat * (1 + $kdv / 100);
                            ?>
                                <tr>
                                    <td class="cell-code" data-label="Kod">
                                        <?php echo htmlspecialchars($urun['CODE']); ?>
                                    </td>
                                    <td class="cell-name" data-label="Ad">
                                        <?php echo htmlspecialchars(tr($urun['NAME'])); ?>
                                    </td>
                                    <td class="cell-vat" data-label="Koli İçi">
                                        <span class="vat-pill"><?php echo number_format((float) $urun['KOLI_ICI'], 0); ?></span>
                                    </td>
                                    <td class="cell-num" data-label="Fiyat">
                                        <?php echo number_format($fiyat, 2, ',', '.'); ?> &#8378;
                                    </td>
                                    <td class="cell-vat" data-label="KDV">
                                        <span class="vat-pill">%<?php echo number_format($kdv, 0); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
