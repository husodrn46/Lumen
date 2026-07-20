<?php
declare(strict_types=1);

require_once __DIR__ . '/kontrol.php';
include_once(__DIR__ . "/ayr.php");
include_once(__DIR__ . "/log_ip.php");
$mdoviz = "TL";
if (isset($_GET['cariid'])) {
	$CARIID = intcevir($_GET['cariid']);
} else {
	exit;
}

// Firma ismini almak için sorgu (CODE geri linkinde kullanılır)
$firmaIsim = '';
$cariKod = '';
$stmtFirma = $dbh->prepare("SELECT CODE, DEFINITION_ FROM {$firma}CLCARD WHERE LOGICALREF = :cariid");
$stmtFirma->execute([':cariid' => $CARIID]);
if ($rowFirma = $stmtFirma->fetch(PDO::FETCH_ASSOC)) {
    $firmaIsim = $rowFirma['DEFINITION_'];  // Firma adını alıyoruz
    $cariKod = trim((string) ($rowFirma['CODE'] ?? ''));
}

// SQL sorgusu ile verileri aylık bazda gruplandırıyoruz
$months = [];
$alacak = [];
$borc = [];
$nakitTahsilat = 0;
$nakitOdeme = 0;
$cekVerisi = 0;
$senetVerisi = 0;

$stmtGrafik = $dbh->prepare("SELECT 
                        FORMAT(HAREKET.DATE_, 'yyyy-MM') AS AY,
                        SUM(ISNULL((1 - HAREKET.SIGN) * HAREKET.AMOUNT, 0)) AS TOPLAM_BORC,
                        SUM(ISNULL(HAREKET.SIGN * HAREKET.AMOUNT, 0)) AS TOPLAM_ALACAK,
                        SUM(CASE WHEN HAREKET.TRCODE = 1 THEN HAREKET.AMOUNT ELSE 0 END) AS NAKIT_TAHSILAT,
                        SUM(CASE WHEN HAREKET.TRCODE = 2 THEN HAREKET.AMOUNT ELSE 0 END) AS NAKIT_ODEME,
                        SUM(CASE WHEN HAREKET.TRCODE = 61 OR HAREKET.TRCODE = 63 THEN HAREKET.AMOUNT ELSE 0 END) AS CEK,
                        SUM(CASE WHEN HAREKET.TRCODE = 62 OR HAREKET.TRCODE = 64 THEN HAREKET.AMOUNT ELSE 0 END) AS SENET
                    FROM {$firmadonem}CLFLINE AS HAREKET 
                    INNER JOIN {$firma}CLCARD AS KART ON HAREKET.CLIENTREF = KART.LOGICALREF
                    WHERE (HAREKET.CANCELLED = 0) AND KART.LOGICALREF = :cariid
                    GROUP BY FORMAT(HAREKET.DATE_, 'yyyy-MM')
                    ORDER BY AY ASC");
$stmtGrafik->execute([':cariid' => $CARIID]);

while ($rowx = $stmtGrafik->fetch(PDO::FETCH_ASSOC)) {
    $months[] = $rowx['AY'];  // Ay bilgilerini kaydediyoruz
    $alacak[] = $rowx['TOPLAM_ALACAK'];  // Aylık alacakları kaydediyoruz
    $borc[] = $rowx['TOPLAM_BORC'];  // Aylık borçları kaydediyoruz
    $nakitTahsilat += $rowx['NAKIT_TAHSILAT'];  // Toplam nakit tahsilat
    $nakitOdeme += $rowx['NAKIT_ODEME'];  // Toplam nakit ödeme
    $cekVerisi += $rowx['CEK'];  // Toplam çek
    $senetVerisi += $rowx['SENET'];  // Toplam senet
}

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grafik ve Rapor</title>
    <link rel="icon" type="image/png" href="icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="/tm/css/tailwind.js" onerror="var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php if (file_exists(__DIR__ . '/pwa-header.php')) { include __DIR__ . '/pwa-header.php'; } ?>
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
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            background: var(--bg);
            color: var(--text-1);
            font-family: 'Avenir Next', 'Montserrat', system-ui, -apple-system, sans-serif;
            font-size: 14px;
            -webkit-font-smoothing: antialiased;
        }
        .top-header {
            position: sticky;
            top: 0;
            z-index: 50;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: saturate(180%) blur(12px);
            -webkit-backdrop-filter: saturate(180%) blur(12px);
            border-bottom: 1px solid var(--border);
        }
        .top-header-inner {
            max-width: 1200px;
            margin: 0 auto;
            padding: 14px 24px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .back-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text-2);
            cursor: pointer;
            text-decoration: none;
            transition: all .15s ease;
        }
        .back-btn:hover {
            color: var(--indigo);
            border-color: var(--indigo);
            background: var(--indigo-soft);
        }
        .header-divider {
            width: 1px;
            height: 28px;
            background: var(--border);
        }
        .header-title {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }
        .header-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: var(--indigo-soft);
            color: var(--indigo);
            font-size: 16px;
            flex-shrink: 0;
        }
        .header-text { min-width: 0; }
        .header-text h1 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
            color: var(--text-1);
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .header-text .subtitle {
            margin: 2px 0 0;
            font-size: 12px;
            color: var(--text-2);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        main {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.75);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 8px 24px rgba(15, 23, 42, .04);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .card-head {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
        }
        .card-head .ico {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 9px;
            background: var(--indigo-soft);
            color: var(--indigo);
            font-size: 14px;
        }
        .card-head h2 {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-1);
        }
        .card-head .sub {
            font-size: 12px;
            color: var(--text-2);
            margin-top: 2px;
        }
        .card-body { padding: 22px; }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: rgba(255, 255, 255, 0.75);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 6px 18px rgba(15, 23, 42, .04);
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }
        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 11px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .stat-icon.emerald { background: var(--emerald-soft); color: var(--emerald); }
        .stat-icon.red { background: var(--red-soft); color: var(--red); }
        .stat-icon.sky { background: var(--sky-soft); color: var(--sky); }
        .stat-icon.amber { background: var(--amber-soft); color: var(--amber); }
        .stat-icon.purple { background: var(--purple-soft); color: var(--purple); }
        .stat-icon.indigo { background: var(--indigo-soft); color: var(--indigo); }
        .stat-content { min-width: 0; flex: 1; }
        .stat-label {
            font-size: 12px;
            color: var(--text-2);
            font-weight: 500;
            margin-bottom: 4px;
        }
        .stat-value {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-1);
            line-height: 1.2;
            word-break: break-word;
        }
        .stat-value .unit {
            font-size: 12px;
            color: var(--text-3);
            font-weight: 500;
            margin-left: 3px;
        }

        .chart-wrap {
            position: relative;
            width: 100%;
            height: 380px;
        }

        .report-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .report-list p {
            margin: 0;
            line-height: 1.7;
            color: var(--text-1);
            font-size: 13.5px;
        }
        .highlight {
            font-weight: 600;
            color: var(--indigo);
            background: var(--indigo-soft);
            padding: 1px 7px;
            border-radius: 6px;
        }
        .highlight.up { color: var(--emerald); background: var(--emerald-soft); }
        .highlight.down { color: var(--red); background: var(--red-soft); }
        .highlight.amber { color: var(--amber); background: var(--amber-soft); }

        .peak-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 6px;
        }
        .peak-card {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px 16px;
            background: #fff;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .peak-card .ico {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }
        .peak-card.up .ico { background: var(--emerald-soft); color: var(--emerald); }
        .peak-card.down .ico { background: var(--red-soft); color: var(--red); }
        .peak-card .lbl { font-size: 12px; color: var(--text-2); }
        .peak-card .val { font-size: 14px; font-weight: 600; color: var(--text-1); margin-top: 2px; }

        @media (max-width: 1023px) {
            .stat-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 767px) {
            .top-header-inner { padding: 12px 16px; gap: 10px; }
            main { padding: 16px; }
            .stat-grid { grid-template-columns: 1fr; gap: 12px; }
            .card-head { padding: 14px 16px; }
            .card-body { padding: 16px; }
            .header-text h1 { font-size: 14px; }
            .header-text .subtitle { font-size: 11px; }
            .chart-wrap { height: 300px; }
            .peak-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<header class="top-header">
    <div class="top-header-inner">
        <a href="<?php echo $cariKod !== '' ? 'lg_bakiye.php?q=' . rawurlencode($cariKod) : 'lg_bakiye.php'; ?>" class="back-btn" title="Müşteri bakiyesine dön"><i class="fa-solid fa-arrow-left"></i></a>
        <div class="header-divider"></div>
        <div class="header-title">
            <span class="header-icon"><i class="fa-solid fa-chart-column"></i></span>
            <div class="header-text">
                <h1>Grafik ve Rapor</h1>
                <p class="subtitle"><?php echo htmlspecialchars($firmaIsim, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
    </div>
</header>

<main>
    <?php
    $totalAlacak = array_sum($alacak);
    $totalBorc = array_sum($borc);
    ?>
    <section class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon emerald"><i class="fa-solid fa-arrow-down-to-line"></i></div>
            <div class="stat-content">
                <div class="stat-label">Toplam Alacak (Giriş)</div>
                <div class="stat-value"><?php echo number_format((float)$totalAlacak, 2, ',', '.'); ?><span class="unit">TL</span></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon red"><i class="fa-solid fa-arrow-up-from-line"></i></div>
            <div class="stat-content">
                <div class="stat-label">Toplam Borç (Çıkış)</div>
                <div class="stat-value"><?php echo number_format((float)$totalBorc, 2, ',', '.'); ?><span class="unit">TL</span></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon sky"><i class="fa-solid fa-money-bill-wave"></i></div>
            <div class="stat-content">
                <div class="stat-label">Nakit Tahsilat</div>
                <div class="stat-value"><?php echo number_format((float)$nakitTahsilat, 2, ',', '.'); ?><span class="unit">TL</span></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon amber"><i class="fa-solid fa-hand-holding-dollar"></i></div>
            <div class="stat-content">
                <div class="stat-label">Nakit Ödeme</div>
                <div class="stat-value"><?php echo number_format((float)$nakitOdeme, 2, ',', '.'); ?><span class="unit">TL</span></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple"><i class="fa-solid fa-money-check"></i></div>
            <div class="stat-content">
                <div class="stat-label">Toplam Çek</div>
                <div class="stat-value"><?php echo number_format((float)$cekVerisi, 2, ',', '.'); ?><span class="unit">TL</span></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon indigo"><i class="fa-solid fa-file-signature"></i></div>
            <div class="stat-content">
                <div class="stat-label">Toplam Senet</div>
                <div class="stat-value"><?php echo number_format((float)$senetVerisi, 2, ',', '.'); ?><span class="unit">TL</span></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon indigo"><i class="fa-solid fa-calendar-days"></i></div>
            <div class="stat-content">
                <div class="stat-label">İşlem Yapılan Ay</div>
                <div class="stat-value"><?php echo count($months); ?><span class="unit">ay</span></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon emerald"><i class="fa-solid fa-scale-balanced"></i></div>
            <div class="stat-content">
                <div class="stat-label">Net Bakiye</div>
                <div class="stat-value"><?php echo number_format((float)($totalAlacak - $totalBorc), 2, ',', '.'); ?><span class="unit">TL</span></div>
            </div>
        </div>
    </section>

    <section class="glass-card">
        <div class="card-head">
            <span class="ico"><i class="fa-solid fa-chart-column"></i></span>
            <div>
                <h2>Aylık Alacak ve Borç Grafiği</h2>
                <div class="sub">İşlem aylarına göre giriş/çıkış karşılaştırması</div>
            </div>
        </div>
        <div class="card-body">
            <div class="chart-wrap">
                <canvas id="myChart" width="400" height="200"></canvas>
            </div>
        </div>
    </section>

    <section class="glass-card">
        <div class="card-head">
            <span class="ico"><i class="fa-solid fa-file-lines"></i></span>
            <div>
                <h2>Analiz Raporu</h2>
                <div class="sub">İşlem özetleri ve öne çıkan dönemler</div>
            </div>
        </div>
        <div class="card-body">
            <div class="report-list">
                <p>
                    <span class="highlight"><?php echo htmlspecialchars($firmaIsim, ENT_QUOTES, 'UTF-8'); ?></span> firması, analiz edilen tarihlerde toplamda
                    <span class="highlight"><?php echo count($months); ?> ay</span> boyunca işlem yapmıştır. Bu süre zarfında, firma toplamda
                    <span class="highlight up"><?php echo number_format((float)$totalAlacak, 2, ',', '.'); ?> TL</span> tutarında alacak (giriş) ve
                    <span class="highlight down"><?php echo number_format((float)$totalBorc, 2, ',', '.'); ?> TL</span> tutarında borç (çıkış) işlemi gerçekleştirmiştir.
                </p>
                <p>
                    Firmanın yaptığı işlemlere göre, en fazla <span class="highlight">nakit tahsilat</span>
                    <span class="highlight up"><?php echo number_format((float)$nakitTahsilat, 2, ',', '.'); ?> TL</span> olarak kaydedilmiştir. Aynı dönemde, firmanın yaptığı toplam
                    <span class="highlight">nakit ödeme</span> ise
                    <span class="highlight down"><?php echo number_format((float)$nakitOdeme, 2, ',', '.'); ?> TL</span> olarak gerçekleşmiştir.
                </p>
                <p>
                    Firmanın çek ve senet kullanımı incelendiğinde, toplamda
                    <span class="highlight amber"><?php echo number_format((float)$cekVerisi, 2, ',', '.'); ?> TL</span> tutarında çek işlemi gerçekleştirilmiş olup, toplam senet işlemleri ise
                    <span class="highlight amber"><?php echo number_format((float)$senetVerisi, 2, ',', '.'); ?> TL</span> olarak kaydedilmiştir.
                </p>
            </div>

            <?php if (count($months) > 0 && (max($alacak) > 0 || max($borc) > 0)):
                $maxAlacakAy = $months[array_search(max($alacak), $alacak)];
                $maxBorcAy = $months[array_search(max($borc), $borc)];
            ?>
            <div class="peak-grid">
                <div class="peak-card up">
                    <div class="ico"><i class="fa-solid fa-arrow-trend-up"></i></div>
                    <div>
                        <div class="lbl">En fazla alacak ayı</div>
                        <div class="val"><?php echo htmlspecialchars((string)$maxAlacakAy, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>
                <div class="peak-card down">
                    <div class="ico"><i class="fa-solid fa-arrow-trend-down"></i></div>
                    <div>
                        <div class="lbl">En fazla borç ayı</div>
                        <div class="val"><?php echo htmlspecialchars((string)$maxBorcAy, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<script>
    // PHP'den gelen verileri JavaScript'e aktarıyoruz
    var labels = <?php echo json_encode($months); ?>;  // Ay bilgileri
    var alacakData = <?php echo json_encode($alacak); ?>;  // Aylık alacak verileri
    var borcData = <?php echo json_encode($borc); ?>;  // Aylık borç verileri

    // Chart.js ile grafik oluşturma
    var ctx = document.getElementById('myChart').getContext('2d');
    var myChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Alacak (Giriş)',
                data: alacakData,
                backgroundColor: 'rgba(5, 150, 105, 0.18)',
                borderColor: 'rgba(5, 150, 105, 1)',
                borderWidth: 1.5,
                borderRadius: 6,
                maxBarThickness: 38
            }, {
                label: 'Borç (Çıkış)',
                data: borcData,
                backgroundColor: 'rgba(111, 16, 34, 0.18)',
                borderColor: 'rgba(111, 16, 34, 1)',
                borderWidth: 1.5,
                borderRadius: 6,
                maxBarThickness: 38
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        font: { family: "'Avenir Next', 'Montserrat', sans-serif", size: 12 },
                        color: '#374151',
                        usePointStyle: true,
                        boxWidth: 8,
                        padding: 16
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(31, 41, 55, 0.95)',
                    titleFont: { family: "'Avenir Next', 'Montserrat', sans-serif", size: 12, weight: '600' },
                    bodyFont: { family: "'Avenir Next', 'Montserrat', sans-serif", size: 12 },
                    padding: 10,
                    cornerRadius: 8,
                    callbacks: {
                        label: function(context) {
                            var v = context.parsed.y;
                            return context.dataset.label + ': ' + v.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' TL';
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { family: "'Avenir Next', 'Montserrat', sans-serif", size: 11 }, color: '#6b7280' }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(229, 231, 235, 0.7)', drawBorder: false },
                    ticks: {
                        font: { family: "'Avenir Next', 'Montserrat', sans-serif", size: 11 },
                        color: '#6b7280',
                        callback: function(value) { return value.toLocaleString('tr-TR'); }
                    }
                }
            }
        }
    });
</script>

</body>
</html>
