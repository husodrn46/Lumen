<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");
// Genel "rapor" yetkisi M17 sütununda tutuluyor
if (m_p_yetki($terminalkullanici, 'M17') != 1) {
  header('Location: ' . APP_ROOT_URL . '/403.html');
  exit;
}
// 1) stockref parametresi
if (!isset($_GET['stockref']) || !is_numeric($_GET['stockref'])) {
    die('Geçerli bir stok referansı giriniz.');
}
$stockref = (int)$_GET['stockref'];

// 2) Stok bilgisi
$stkStmt = $dbh->prepare("
    SELECT CODE, NAME
    FROM {$firma}ITEMS
    WHERE LOGICALREF = :ref
");
$stkStmt->execute(['ref' => $stockref]);
$stok = $stkStmt->fetch(PDO::FETCH_ASSOC);
if (!$stok) {
    die('Stok bulunamadı.');
}

// 3) Hareket verilerini çek
$movStmt = $dbh->prepare("
    SELECT
      STL.DATE_                           AS TARIH,
      CASE WHEN STL.IOCODE IN (1,2) THEN STL.AMOUNT ELSE 0 END AS GIRIS,
      CASE WHEN STL.IOCODE NOT IN (1,2) THEN STL.AMOUNT ELSE 0 END AS CIKIS,
      (CASE WHEN STL.IOCODE IN (1,2) THEN STL.AMOUNT ELSE 0 END
       - CASE WHEN STL.IOCODE NOT IN (1,2) THEN STL.AMOUNT ELSE 0 END) AS NET,
      FICH.FICHENO                        AS FIS_NO,
      STL.SOURCEINDEX                     AS AMBAR
    FROM {$firmadonem}STLINE STL
    JOIN {$firmadonem}STFICHE FICH
      ON STL.STFICHEREF = FICH.LOGICALREF
    WHERE STL.CANCELLED = 0
      AND STL.LINETYPE  = 0
      AND STL.STOCKREF = :ref
    ORDER BY STL.DATE_
");
$movStmt->execute(['ref' => $stockref]);

// 4) Grafik için kümülatif hesap
$chartData = [['Tarih', 'Kümülatif Miktar']];
$cum = 0;
$rows = [];
$totalGiris = 0.0;
$totalCikis = 0.0;
while ($r = $movStmt->fetch(PDO::FETCH_ASSOC)) {
    $date = (new DateTime($r['TARIH']))->format('Y-m-d');
    $giris = floatval($r['GIRIS']);
    $cikis = floatval($r['CIKIS']);
    $net   = floatval($r['NET']);
    $cum  += $net;
    $totalGiris += $giris;
    $totalCikis += $cikis;
    $chartData[] = [$date, $cum];
    $rows[] = [
        'date'  => $date,
        'giris' => $giris,
        'cikis' => $cikis,
        'net'   => $net,
        'fis'   => $r['FIS_NO'],
        'ambar' => $r['AMBAR']
    ];
}
$netStok = $totalGiris - $totalCikis;
$toplamIslem = count($rows);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Stok Hareket Detayi &ndash; <?=htmlspecialchars($stok['CODE'].' - '.$stok['NAME'])?></title>

  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://www.gstatic.com/charts/loader.js"></script>

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
        --sky-deep: #075985;
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
        border-bottom: 1px solid rgba(2, 132, 199, 0.2);
        box-shadow: 0 2px 8px rgba(2, 132, 199, 0.05);
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
    .header-back:hover { background: var(--sky-soft); color: var(--sky); }
    .header-divider { width: 1px; height: 24px; background: var(--border); flex-shrink: 0; }
    .header-title { min-width: 0; flex: 1 1 auto; }
    .header-title h1 {
        font-size: 16px; font-weight: 700; color: var(--text-1);
        display: flex; align-items: center; gap: 8px; margin: 0;
    }
    .header-title h1 .material-icons { color: var(--sky); font-size: 20px; }
    .header-title p {
        margin: 2px 0 0; font-size: 11.5px; color: var(--text-2); font-weight: 500;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .header-title p .kod {
        display: inline-block; padding: 1px 8px;
        background: var(--sky-soft); color: var(--sky-deep);
        border-radius: 100px; font-size: 10.5px; font-weight: 700; margin-left: 4px;
    }
    .header-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .btn-action {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 9px 14px; background: #fff;
        border: 1px solid var(--border); border-radius: 10px;
        font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12.5px; font-weight: 600;
        color: var(--text-1); cursor: pointer; transition: all 0.2s ease;
        min-height: 44px; text-decoration: none;
    }
    .btn-action:hover { background: var(--sky-soft); color: var(--sky); border-color: rgba(2, 132, 199, 0.3); }
    .btn-action .material-icons { font-size: 18px; }
    .btn-print { background: var(--sky); color: #fff; border-color: var(--sky); box-shadow: 0 4px 12px rgba(2, 132, 199, 0.22); }
    .btn-print:hover { background: #0369a1; color: #fff; border-color: #0369a1; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(2, 132, 199, 0.32); }
    .btn-excel { background: var(--emerald); color: #fff; border-color: var(--emerald); box-shadow: 0 4px 12px rgba(5, 150, 105, 0.2); }
    .btn-excel:hover { background: #047857; color: #fff; border-color: #047857; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(5, 150, 105, 0.3); }

    main { max-width: 1280px; margin: 0 auto; padding: 22px 24px 60px; }

    .stat-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 14px;
        margin-bottom: 18px;
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
    .stat-card.red::before     { background: var(--red); }
    .stat-card.sky::before     { background: var(--sky); }
    .stat-card .icon-box {
        width: 48px; height: 48px;
        flex-shrink: 0;
        border-radius: 12px;
        display: inline-flex; align-items: center; justify-content: center;
    }
    .stat-card .icon-box .material-icons { font-size: 24px; }
    .stat-card.emerald .icon-box { background: var(--emerald-soft); color: var(--emerald); }
    .stat-card.red     .icon-box { background: var(--red-soft);     color: var(--red); }
    .stat-card.sky     .icon-box { background: var(--sky-soft);     color: var(--sky); }
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
    }
    .stat-card.emerald .stat-value { color: var(--emerald); }
    .stat-card.red     .stat-value { color: var(--red); }
    .stat-card.sky     .stat-value { color: var(--sky-deep); }
    .stat-card .stat-sub {
        display: block; margin-top: 2px;
        font-size: 10.5px; color: var(--text-3); font-weight: 500;
    }

    .glass-card {
        background: rgba(255,255,255,0.94);
        backdrop-filter: blur(6px);
        -webkit-backdrop-filter: blur(6px);
        border: 1px solid rgba(2, 132, 199, 0.16);
        border-radius: 16px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.04);
        padding: 20px 22px;
        margin-bottom: 18px;
        animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) 0.18s both;
    }
    .card-title {
        font-size: 13.5px; font-weight: 700; color: var(--text-1);
        display: flex; align-items: center; gap: 8px;
        margin-bottom: 14px;
    }
    .card-title .material-icons { color: var(--sky); font-size: 19px; }

    #chart_div { height: 320px; width: 100%; }

    .hareket-table { width: 100%; border-collapse: collapse; }
    .hareket-table thead { background: linear-gradient(180deg, #fff, #f8fbff); }
    .hareket-table thead th {
        padding: 12px 14px;
        font-size: 10px; font-weight: 700; color: var(--text-2);
        text-transform: uppercase; letter-spacing: 0.5px;
        text-align: left; border-bottom: 1px solid var(--border);
        white-space: nowrap;
    }
    .hareket-table thead th.right { text-align: right; }
    .hareket-table tbody td {
        padding: 11px 14px;
        font-size: 12.5px; color: var(--text-1);
        border-bottom: 1px solid #f3f4f6;
        vertical-align: middle;
    }
    .hareket-table tbody tr { transition: background 0.15s ease; }
    .hareket-table tbody tr:hover { background: var(--sky-soft); }
    .hareket-table tbody tr:last-child td { border-bottom: none; }
    .hareket-table td.date { color: var(--text-2); font-weight: 500; white-space: nowrap; font-size: 12px; }
    .hareket-table td.right { text-align: right; font-weight: 600; white-space: nowrap; }
    .hareket-table td.right.giris  { color: var(--emerald); }
    .hareket-table td.right.cikis  { color: var(--red); }
    .hareket-table td.right.net.pozitif { color: var(--emerald); font-weight: 700; }
    .hareket-table td.right.net.negatif { color: var(--red); font-weight: 700; }
    .hareket-table td.right .muted { color: var(--text-3); font-weight: 400; }
    .hareket-table td.fis { color: var(--sky-deep); font-weight: 600; font-family: 'Avenir Next', 'Montserrat', monospace; font-size: 12px; }
    .hareket-table td.ambar { color: var(--text-2); font-size: 12px; }
    .hareket-table .empty-row td {
        text-align: center; padding: 48px 20px;
        color: var(--text-3); font-size: 13px;
    }
    .hareket-table .empty-row .material-icons {
        display: block; font-size: 32px; margin-bottom: 10px; color: var(--text-3);
    }

    @keyframes cardIn {
        from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
        to   { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
    }

    @media (max-width: 767px) {
        .header-inner { padding: 12px 14px; gap: 10px; }
        .header-title h1 { font-size: 14px; }
        .header-title p { font-size: 10.5px; }
        .header-actions { width: 100%; justify-content: flex-start; }
        main { padding: 14px 12px 40px; }

        .stat-grid { grid-template-columns: 1fr; gap: 10px; margin-bottom: 14px; }
        .stat-card { padding: 14px 16px; }
        .stat-card .icon-box { width: 42px; height: 42px; }
        .stat-card .icon-box .material-icons { font-size: 20px; }
        .stat-card .stat-value { font-size: 16px; }

        .glass-card { padding: 16px; }
        #chart_div { height: 260px; }

        .hareket-table thead { display: none; }
        .hareket-table, .hareket-table tbody, .hareket-table tr, .hareket-table td {
            display: block; width: 100%;
        }
        .hareket-table tbody tr {
            padding: 14px 16px;
            border-bottom: 1px solid #f3f4f6;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 4px 10px;
        }
        .hareket-table tbody tr:last-child { border-bottom: none; }
        .hareket-table tbody td { padding: 0; border-bottom: none; }
        .hareket-table td.date   { grid-column: 1; font-size: 11px; }
        .hareket-table td.fis    { grid-column: 2; text-align: right; font-size: 11px; }
        .hareket-table td.ambar  { grid-column: 1 / -1; font-size: 10.5px; color: var(--text-3); }
        .hareket-table td.giris, .hareket-table td.cikis, .hareket-table td.net {
            grid-column: span 1; text-align: right;
        }
        .hareket-table td.giris::before { content: 'Giris '; color: var(--text-3); font-weight: 400; font-size: 10px; }
        .hareket-table td.cikis::before { content: 'Cikis '; color: var(--text-3); font-weight: 400; font-size: 10px; }
        .hareket-table td.net::before   { content: 'Net '; color: var(--text-3); font-weight: 400; font-size: 10px; }
    }

    @media print {
        body { background: #fff; }
        .top-header { position: static; box-shadow: none; border-bottom: 2px solid #000; }
        .no-print, .header-actions { display: none !important; }
        .stat-card, .glass-card { box-shadow: none; border: 1px solid #ccc; animation: none; }
        .hareket-table tbody tr:hover { background: transparent; }
    }
  </style>
  <script>
    google.charts.load('current', { packages: ['corechart'] });
    google.charts.setOnLoadCallback(function() {
      var data = google.visualization.arrayToDataTable(
        <?php echo json_encode($chartData);?>
      );
      var opts = {
        legend: { position: 'none' },
        chartArea: { width: '85%', height: '78%' },
        colors: ['#0284c7'],
        backgroundColor: 'transparent',
        fontName: 'Montserrat',
        fontSize: 12,
        hAxis: { textStyle: { color: '#6b7280', fontSize: 11 }, gridlines: { color: '#f3f4f6' } },
        vAxis: { textStyle: { color: '#6b7280', fontSize: 11 }, gridlines: { color: '#f3f4f6' } },
        lineWidth: 3,
        pointSize: 4
      };
      new google.visualization.LineChart(
        document.getElementById('chart_div')
      ).draw(data, opts);
    });
    function exportXLS() {
      var html = document.getElementById('mov_tbl').outerHTML;
      var a = document.createElement('a'); document.body.appendChild(a);
      a.href = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
      a.download = 'stok_hareket_<?=$stockref?>.xls';
      a.click();
    }
  </script>
</head>
<body>

<header class="top-header no-print">
  <div class="header-inner">
    <a href="rapor_hareketsiz_stok.php" class="header-back" title="Geri">
      <span class="material-icons">arrow_back</span>
    </a>
    <div class="header-divider"></div>
    <div class="header-title">
      <h1><span class="material-icons">inventory</span>Stok Hareket Detayi</h1>
      <p>
        <?=htmlspecialchars((string) $stok['NAME'])?>
        <span class="kod"><?=htmlspecialchars((string) $stok['CODE'])?></span>
      </p>
    </div>
    <div class="header-actions">
      <button onclick="window.print()" class="btn-action btn-print" type="button" title="Yazdir">
        <span class="material-icons">print</span>
        <span>Yazdir</span>
      </button>
      <button onclick="exportXLS()" class="btn-action btn-excel" type="button" title="Excel">
        <span class="material-icons">download</span>
        <span>Excel</span>
      </button>
    </div>
  </div>
</header>

<main>

  <!-- KPI -->
  <div class="stat-grid">
    <div class="stat-card emerald">
      <span class="icon-box"><span class="material-icons">call_received</span></span>
      <div class="stat-body">
        <span class="stat-label">Toplam Giris</span>
        <span class="stat-value"><?php echo number_format($totalGiris, 2, ',', '.'); ?></span>
        <span class="stat-sub">adet / miktar</span>
      </div>
    </div>
    <div class="stat-card red">
      <span class="icon-box"><span class="material-icons">call_made</span></span>
      <div class="stat-body">
        <span class="stat-label">Toplam Cikis</span>
        <span class="stat-value"><?php echo number_format($totalCikis, 2, ',', '.'); ?></span>
        <span class="stat-sub">adet / miktar</span>
      </div>
    </div>
    <div class="stat-card sky">
      <span class="icon-box"><span class="material-icons">equalizer</span></span>
      <div class="stat-body">
        <span class="stat-label">Net Stok (<?php echo $toplamIslem; ?> islem)</span>
        <span class="stat-value"><?php echo number_format($netStok, 2, ',', '.'); ?></span>
        <span class="stat-sub">giris - cikis</span>
      </div>
    </div>
  </div>

  <!-- Grafik -->
  <div class="glass-card">
    <h3 class="card-title">
      <span class="material-icons">show_chart</span>
      Kumulatif Stok Seviyesi
    </h3>
    <div id="chart_div"></div>
  </div>

  <!-- Hareket Listesi -->
  <div class="glass-card">
    <h3 class="card-title">
      <span class="material-icons">list_alt</span>
      Hareket Listesi
    </h3>
    <div style="overflow-x:auto;">
      <table id="mov_tbl" class="hareket-table">
        <thead>
          <tr>
            <th>Tarih</th>
            <th class="right">Giris</th>
            <th class="right">Cikis</th>
            <th class="right">Net</th>
            <th>Fis No</th>
            <th>Ambar</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($rows === []): ?>
            <tr class="empty-row"><td colspan="6">
              <span class="material-icons">inbox</span>
              Bu stok icin hareket kaydi bulunamadi
            </td></tr>
          <?php else: foreach ($rows as $r):
            $netClass = $r['net'] >= 0 ? 'pozitif' : 'negatif';
          ?>
          <tr>
            <td class="date"><?= htmlspecialchars((string) $r['date']) ?></td>
            <td class="right giris"><?= $r['giris'] > 0 ? number_format($r['giris'], 2, ',', '.') : '<span class="muted">-</span>' ?></td>
            <td class="right cikis"><?= $r['cikis'] > 0 ? number_format($r['cikis'], 2, ',', '.') : '<span class="muted">-</span>' ?></td>
            <td class="right net <?= $netClass ?>"><?= number_format($r['net'], 2, ',', '.') ?></td>
            <td class="fis"><?= htmlspecialchars((string) $r['fis']) ?></td>
            <td class="ambar"><?= htmlspecialchars((string) $r['ambar']) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</main>

</body>
</html>
