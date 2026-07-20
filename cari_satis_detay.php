<?php
declare(strict_types=1);

require_once __DIR__ . '/kontrol.php';
include_once(__DIR__ . "/ayr.php");
include_once(__DIR__ . "/_baglanti_.inc");
include_once(__DIR__ . "/log_ip.php");

$secilenTarih = $_POST['tarih'] ?? date("Y-m-d");

?>
<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cari Satis Detayi</title>
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (file_exists(__DIR__ . '/pwa-header.php')) { include_once __DIR__ . '/pwa-header.php'; } ?>
    <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="/tm/js/jquery-3.7.1.min.js"></script>
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
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

        .top-header {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(5, 150, 105, 0.18);
            box-shadow: 0 2px 8px rgba(5, 150, 105, 0.04);
        }
        .header-inner {
            max-width: 1100px; margin: 0 auto;
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
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--emerald); }
        .header-divider { width: 1px; height: 24px; background: var(--border); flex-shrink: 0; }
        .header-title { min-width: 0; flex: 1 1 auto; }
        .header-title h1 {
            font-size: 16px; font-weight: 700; color: var(--text-1);
            display: flex; align-items: center; gap: 8px; margin: 0;
        }
        .header-title h1 i { color: var(--emerald); font-size: 14px; }
        .header-title p {
            margin: 2px 0 0; font-size: 11.5px; color: var(--text-2); font-weight: 500;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }

        main {
            max-width: 1100px; margin: 0 auto;
            padding: 20px 24px 40px;
        }

        .alert-note {
            display: flex; align-items: flex-start; gap: 10px;
            background: var(--red-soft); color: var(--red);
            border: 1px solid rgba(239, 68, 68, 0.22);
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 12px; font-weight: 500;
            margin-bottom: 16px;
            animation: cardIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .alert-note i { font-size: 13px; flex-shrink: 0; margin-top: 2px; }

        .filter-panel {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(5, 150, 105, 0.18);
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            padding: 16px;
            margin-bottom: 16px;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1.6fr auto;
            gap: 12px;
            align-items: end;
        }
        .field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .field label {
            font-size: 10.5px; color: var(--text-2); font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .field input {
            width: 100%;
            padding: 11px 14px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14px;
            color: var(--text-1);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            outline: none;
            transition: all 0.2s ease;
        }
        .field input:focus {
            border-color: rgba(5, 150, 105, 0.5);
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
        }
        .btn-apply {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 11px 18px; background: var(--emerald); color: #fff;
            border: none; border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12.5px; font-weight: 700;
            cursor: pointer; transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.22);
            white-space: nowrap;
        }
        .btn-apply:hover {
            background: #047857;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(5, 150, 105, 0.3);
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 16px;
        }
        .stat-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            padding: 16px 18px;
            display: flex; align-items: center; gap: 14px;
            position: relative; overflow: hidden;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .stat-card:nth-child(2) { animation-delay: 60ms; }
        .stat-card:nth-child(3) { animation-delay: 120ms; }
        .stat-card::before {
            content: ''; position: absolute;
            top: 0; left: 0; bottom: 0;
            width: 4px;
        }
        .stat-card.emerald::before { background: var(--emerald); }
        .stat-card.sky::before { background: var(--sky); }
        .stat-card.indigo::before { background: var(--indigo); }
        .stat-card .icon-box {
            width: 48px; height: 48px;
            flex-shrink: 0;
            border-radius: 12px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 18px;
        }
        .stat-card.emerald .icon-box { background: var(--emerald-soft); color: var(--emerald); }
        .stat-card.sky .icon-box     { background: var(--sky-soft); color: var(--sky); }
        .stat-card.indigo .icon-box  { background: var(--indigo-soft); color: var(--indigo); }
        .stat-card .stat-body { flex: 1 1 auto; min-width: 0; }
        .stat-card .stat-label {
            font-size: 10.5px; color: var(--text-2); font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.4px;
            display: block;
        }
        .stat-card .stat-value {
            display: block; margin-top: 3px;
            font-size: 18px; font-weight: 700; color: var(--text-1);
            line-height: 1.2;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .stat-card.emerald .stat-value { color: var(--emerald); }
        .stat-card.sky .stat-value     { color: var(--sky); }
        .stat-card.indigo .stat-value  { color: var(--indigo); }

        .search-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 14px;
        }
        .search-wrap { position: relative; }
        .search-wrap i.fa-search {
            position: absolute; top: 50%; left: 14px;
            transform: translateY(-50%);
            color: var(--text-3); font-size: 13px;
            pointer-events: none;
        }
        .search-input {
            width: 100%;
            padding: 11px 14px 11px 38px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14px;
            color: var(--text-1);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            outline: none;
            transition: all 0.2s ease;
        }
        .search-input:focus {
            border-color: rgba(5, 150, 105, 0.5);
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
        }

        .table-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(5, 150, 105, 0.18);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            overflow: hidden;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) 0.12s both;
        }
        .gd-table { width: 100%; border-collapse: collapse; }
        .gd-table thead { background: linear-gradient(180deg, #fff, #f0fdf4); }
        .gd-table thead th {
            padding: 12px 16px;
            font-size: 10px; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: 0.5px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            cursor: pointer; user-select: none;
            white-space: nowrap;
        }
        .gd-table thead th.right { text-align: right; }
        .gd-table tbody td {
            padding: 12px 16px;
            font-size: 12.5px; color: var(--text-1);
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .gd-table tbody tr { transition: background 0.15s ease; }
        .gd-table tbody tr:hover { background: var(--emerald-soft); }
        .gd-table tbody tr:last-child td { border-bottom: none; }
        .gd-table td.date { color: var(--text-2); font-size: 12px; white-space: nowrap; }
        .gd-table td.kodu { color: var(--text-3); font-size: 11.5px; font-weight: 600; }
        .gd-table td.cari { color: var(--text-1); font-weight: 600; }
        .gd-table td.aciklama {
            color: var(--text-2); font-size: 12px;
            max-width: 320px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .gd-table td.tutar {
            text-align: right; font-weight: 700;
            color: var(--emerald);
            white-space: nowrap;
        }
        .gd-table tr.avg-row td {
            background: #f0fdf4;
            font-weight: 700;
            color: var(--emerald);
            border-top: 2px solid rgba(5, 150, 105, 0.25);
        }
        .gd-table tr.empty-row td {
            text-align: center; padding: 48px 20px;
            color: var(--text-3); font-size: 13px;
        }
        .gd-table tr.empty-row td i {
            display: block; font-size: 32px; margin-bottom: 10px; color: var(--text-3);
        }

        .chart-card {
            background: rgba(255,255,255,0.94);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            padding: 18px;
            margin-top: 16px;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) 0.18s both;
        }
        .chart-card h3 {
            font-size: 13px; font-weight: 700; color: var(--text-1);
            margin: 0 0 10px; display: flex; align-items: center; gap: 8px;
        }
        .chart-card h3 i { color: var(--emerald); font-size: 12px; }
        #chart_div { width: 100%; height: 420px; }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        @media (max-width: 900px) {
            .filter-grid { grid-template-columns: 1fr 1fr; }
            .filter-grid .btn-apply { grid-column: 1 / -1; justify-self: start; }
            .stat-grid { grid-template-columns: 1fr; }
            .search-row { grid-template-columns: 1fr; }
        }

        @media (max-width: 767px) {
            .header-inner { padding: 12px 14px; gap: 10px; }
            .header-title h1 { font-size: 14px; }
            .header-title p { font-size: 10.5px; }
            .header-back { width: 30px; height: 30px; border-radius: 8px; }

            main { padding: 14px 12px 40px; }

            .filter-panel { padding: 12px; }
            .field input { font-size: 16px; }
            .search-input { font-size: 16px; }

            .stat-card { padding: 14px 16px; }
            .stat-card .icon-box { width: 42px; height: 42px; font-size: 16px; }
            .stat-card .stat-value { font-size: 16px; }

            .gd-table thead { display: none; }
            .gd-table, .gd-table tbody, .gd-table tr, .gd-table td {
                display: block; width: 100%;
            }
            .gd-table tbody tr {
                padding: 12px 14px;
                border-bottom: 1px solid #f3f4f6;
                display: grid;
                grid-template-columns: 1fr auto;
                gap: 4px 10px;
            }
            .gd-table tbody td {
                padding: 0; border-bottom: none;
            }
            .gd-table td.kodu { grid-column: 1; grid-row: 1; font-size: 10.5px; }
            .gd-table td.date { grid-column: 2; grid-row: 1; text-align: right; font-size: 11px; }
            .gd-table td.cari { grid-column: 1 / -1; font-size: 13px; }
            .gd-table td.aciklama { grid-column: 1 / -1; max-width: none; }
            .gd-table td.tutar { grid-column: 1 / -1; text-align: right; font-size: 14px; }
            .gd-table tr.avg-row td { text-align: right !important; }

            #chart_div { height: 320px; }
        }
    </style>
</head>

<body>
    <header class="top-header">
        <div class="header-inner">
            <a href="index.php" class="header-back" title="Geri">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div class="header-divider"></div>
            <div class="header-title">
                <h1><i class="fa-solid fa-chart-line"></i> Cari Satis Detayi</h1>
                <p>Tarih araligina gore cari bazli satis hareketleri</p>
            </div>
        </div>
    </header>

    <main>
        <div class="alert-note">
            <i class="fa-solid fa-circle-info"></i>
            <span>Burdaki veriler sadece satis yapilan verilerdir. Yani tahsil edilmemis tutarlar da dahildir.</span>
        </div>

        <form method="post" class="filter-panel">
            <div class="filter-grid">
                <div class="field">
                    <label for="baslangic_tarihi">Baslangic Tarihi</label>
                    <input type="date" id="baslangic_tarihi" name="baslangic_tarihi"
                        value="<?php echo htmlspecialchars((string)($_POST['baslangic_tarihi'] ?? '')); ?>">
                </div>
                <div class="field">
                    <label for="bitis_tarihi">Bitis Tarihi</label>
                    <input type="date" id="bitis_tarihi" name="bitis_tarihi"
                        value="<?php echo htmlspecialchars((string)($_POST['bitis_tarihi'] ?? '')); ?>">
                </div>
                <div class="field">
                    <label for="cari">Cari Adi / Kodu</label>
                    <input type="text" id="cari" name="cari" placeholder="Cari adi veya kodu..."
                        value="<?php echo htmlspecialchars((string)($_POST['cari'] ?? '')); ?>">
                </div>
                <button type="submit" class="btn-apply">
                    <i class="fa-solid fa-magnifying-glass"></i> Ara
                </button>
            </div>
        </form>

        <?php
        $baslangicTarihi = $_POST['baslangic_tarihi'] ?? date("Y-m-d");
        $bitisTarihi = $_POST['bitis_tarihi'] ?? date("Y-m-d");
        $cari = $_POST['cari'] ?? '';

        $stmt = $dbh->prepare("SELECT HAREKET.LOGICALREF AS KODU, HAREKET.DATE_ AS TARIH, TRCODE, KART.LOGICALREF, KART.CODE, KART.DEFINITION_ AS CARI_ADI, HAREKET.LINEEXP AS ACIKLAMA, ISNULL((1 - HAREKET.SIGN) * HAREKET.AMOUNT, '0') AS TUTAR
        FROM {$firmadonem}CLFLINE AS HAREKET
        INNER JOIN {$firma}CLCARD AS KART ON HAREKET.CLIENTREF = KART.LOGICALREF
        WHERE (HAREKET.CANCELLED = 0)
          AND (KART.DEFINITION_ LIKE :cari1 OR KART.CODE LIKE :cari2)
          AND HAREKET.TRCODE = 38
          AND HAREKET.DATE_ BETWEEN :baslangic AND :bitis");
        $cariLike = '%' . $cari . '%';
        $stmt->execute([':cari1' => $cariLike, ':cari2' => $cariLike, ':baslangic' => $baslangicTarihi, ':bitis' => $bitisTarihi]);

        // Toplam tutar ve tutar sayisi icin degiskenler
        $totalAmount = 0.0;
        $count = 0;
        $rowsBuffer = '';

        while ($rowx = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $formattedDate = (new DateTime($rowx['TARIH']))->format('Y-m-d');
            $amount = floatval($rowx['TUTAR']);
            $totalAmount += $amount;
            $count++;

            $formattedAmount = number_format($amount, 2, ',', '.') . " TL";

            $rowsBuffer .= '<tr>'
                . '<td class="kodu">' . htmlspecialchars((string)$rowx['KODU']) . '</td>'
                . '<td class="date">' . htmlspecialchars($formattedDate) . '</td>'
                . '<td class="cari">' . htmlspecialchars((string)$rowx['CARI_ADI']) . '</td>'
                . '<td class="aciklama">' . htmlspecialchars((string)$rowx['ACIKLAMA']) . '</td>'
                . '<td class="tutar">' . $formattedAmount . '</td>'
                . '</tr>';
        }

        $averageAmount = $count > 0 ? $totalAmount / $count : 0.0;
        $formattedTotal = number_format($totalAmount, 2, ',', '.') . " TL";
        $formattedAverage = number_format($averageAmount, 2, ',', '.') . " TL";
        ?>

        <div class="stat-grid">
            <div class="stat-card emerald">
                <div class="icon-box"><i class="fa-solid fa-sack-dollar"></i></div>
                <div class="stat-body">
                    <span class="stat-label">Toplam Satis</span>
                    <span class="stat-value"><?php echo $formattedTotal; ?></span>
                </div>
            </div>
            <div class="stat-card sky">
                <div class="icon-box"><i class="fa-solid fa-receipt"></i></div>
                <div class="stat-body">
                    <span class="stat-label">Hareket Sayisi</span>
                    <span class="stat-value"><?php echo (int)$count; ?></span>
                </div>
            </div>
            <div class="stat-card indigo">
                <div class="icon-box"><i class="fa-solid fa-scale-balanced"></i></div>
                <div class="stat-body">
                    <span class="stat-label">Ortalama Tutar</span>
                    <span class="stat-value"><?php echo $formattedAverage; ?></span>
                </div>
            </div>
        </div>

        <div class="search-row">
            <div class="search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" name="kod" id="kod" class="search-input" placeholder="Urun kodu ara..." autofocus autocomplete="off">
            </div>
            <div class="search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" name="ad" id="ad" class="search-input" placeholder="Urun adi ara..." autocomplete="off">
            </div>
        </div>

        <div class="table-card">
            <table id="tableFull" class="gd-table">
                <thead>
                    <tr>
                        <th class="sortable">No</th>
                        <th class="sortable">Tarih</th>
                        <th class="sortable">Cari Adi</th>
                        <th class="sortable">Aciklama</th>
                        <th class="sortable right">Tutar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($count > 0) {
                        echo $rowsBuffer;
                        echo '<tr class="avg-row">'
                            . '<td colspan="4" style="text-align:right;">Ortalama Tutar</td>'
                            . '<td class="tutar">' . $formattedAverage . '</td>'
                            . '</tr>';
                    } else {
                        echo '<tr class="empty-row"><td colspan="5"><i class="fa-solid fa-inbox"></i>Kayit bulunamadi</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <div class="chart-card">
            <h3><i class="fa-solid fa-chart-column"></i> Kasalara Gore Net Giren Tutarlar</h3>
            <div id="chart_div"></div>
        </div>
    </main>

    <script>
        jQuery.expr[':'].contains = function (a, i, m) {
            return jQuery(a).text().toUpperCase()
                .indexOf(m[3].toUpperCase()) >= 0;
        };

        $(document).ready(function () {
            $('#kod').keyup(function () {
                var value = $("#kod").val();

                if (value.length == 0) {
                    $("#tableFull tbody tr").show();
                } else {
                    $("#tableFull tbody tr").hide();
                    $("#tableFull tbody tr:contains(" + value + ")").show();
                }
            });
        });

        $(document).ready(function () {
            $('#kod, #ad').keyup(function () {
                var kod = $("#kod").val().toLowerCase();
                var ad = $("#ad").val().toLowerCase();

                $("#tableFull tbody tr").filter(function () {
                    $(this).toggle($(this).text().toLowerCase().indexOf(kod) > -1 && $(this).text().toLowerCase().indexOf(ad) > -1)
                });
            });
        });

        function sortTable(n) {
            var table, rows, switching, i, x, y, shouldSwitch, dir, switchcount = 0;
            table = document.getElementById("tableFull");
            switching = true;
            dir = "asc";
            while (switching) {
                switching = false;
                rows = table.rows;
                for (i = 1; i < (rows.length - 1); i++) {
                    shouldSwitch = false;
                    x = rows[i].getElementsByTagName("TD")[n];
                    y = rows[i + 1].getElementsByTagName("TD")[n];
                    if (dir == "asc") {
                        if (x.innerHTML.toLowerCase() > y.innerHTML.toLowerCase()) {
                            shouldSwitch = true;
                            break;
                        }
                    } else if (dir == "desc") {
                        if (x.innerHTML.toLowerCase() < y.innerHTML.toLowerCase()) {
                            shouldSwitch = true;
                            break;
                        }
                    }
                }
                if (shouldSwitch) {
                    rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
                    switching = true;
                    switchcount++;
                } else {
                    if (switchcount == 0 && dir == "asc") {
                        dir = "desc";
                        switching = true;
                    }
                }
            }
        }

        document.querySelectorAll('.sortable').forEach(function (header, index) {
            header.addEventListener('click', function () {
                sortTable(index);
            });
        });

        google.charts.load('current', { 'packages': ['corechart'] });
        google.charts.setOnLoadCallback(drawChart);

        function drawChart() {
            var data = google.visualization.arrayToDataTable([
                ['Kasa Adi', 'Net Giren'],
                <?php
                $kasaTutarlari = $kasaTutarlari ?? [];
                foreach ($kasaTutarlari as $kasaAdi => $tutarlar) {
                    $netTutar = $tutarlar['giren'] - $tutarlar['cikan'];
                    echo "['" . $kasaAdi . "', " . $netTutar . "],";
                }
                ?>
            ]);

            var options = {
                title: 'Kasalara Gore Net Giren Tutarlar',
                chartArea: { width: '50%' },
                hAxis: {
                    title: 'Toplam Tutar',
                    minValue: 0
                },
                vAxis: {
                    title: 'Kasa Adi'
                }
            };

            var chart = new google.visualization.BarChart(document.getElementById('chart_div'));
            chart.draw(data, options);
        }
    </script>
</body>

</html>
