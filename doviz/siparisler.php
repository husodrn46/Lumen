<?php
declare(strict_types=1);

/**
 * Döviz Modülü - Sipariş Listesi
 * Sadece dövizli siparişleri listeler (TRCURR > 0)
 */

include_once(__DIR__ . "/../ayr.php");
include(__DIR__ . "/../kontrol.php");
require_once __DIR__ . '/doviz_guard.php';

doviz_require_m21($terminalkullanici);

// LOGO döviz kodları: 1=USD, 20=EUR
$dovizBilgileri = [
    1 => ['kod' => 'USD', 'sembol' => '$'],
    20 => ['kod' => 'EUR', 'sembol' => '€'],
];

// Filtreleme
$filtreDoviz = isset($_GET['doviz']) ? (int)$_GET['doviz'] : 0;
$filtreTarih = isset($_GET['tarih']) ? $_GET['tarih'] : date('Y-m');
$dovizSiparislerReturnUrl = 'siparisler.php';
if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '') {
    $dovizSiparislerReturnUrl .= '?' . $_SERVER['QUERY_STRING'];
}

// Tarih aralığı
$baslangic = $filtreTarih . '-01';
$bitis = date('Y-m-t', strtotime($baslangic));

// Dövizli siparişleri çek (TRCURR > 0 olanlar)
$sql = "
    SELECT
        F.LOGICALREF, F.FICHENO, F.DATE_, F.GENEXP1,
        F.TRCURR, F.TRRATE, F.TRNET,
        F.NETTOTAL, F.GROSSTOTAL, F.TOTALVAT,
        F.STATUS,
        C.CODE AS CARI_KOD, C.DEFINITION_ AS CARI_ISIM,
        (SELECT COUNT(*) FROM {$firmadonem}ORFLINE WHERE ORDFICHEREF = F.LOGICALREF AND LINETYPE = 0) AS SATIR_SAYISI
    FROM {$firmadonem}ORFICHE F
    LEFT JOIN {$firma}CLCARD C ON C.LOGICALREF = F.CLIENTREF
    WHERE F.TRCODE = 1
      AND F.TRCURR > 0
      AND F.TRRATE > 0
      AND F.NETTOTAL > 0
      AND F.DATE_ >= :baslangic
      AND F.DATE_ <= :bitis
      AND EXISTS (SELECT 1 FROM {$firmadonem}ORFLINE L WHERE L.ORDFICHEREF = F.LOGICALREF AND L.LINETYPE = 0)
";

$params = [':baslangic' => $baslangic, ':bitis' => $bitis];

if ($filtreDoviz > 0) {
    $sql .= " AND F.TRCURR = :doviz";
    $params[':doviz'] = $filtreDoviz;
}

$sql .= " ORDER BY F.DATE_ DESC, F.LOGICALREF DESC";

$stmt = $dbh->prepare($sql);
$stmt->execute($params);
$siparisler = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Toplam hesapla (LOGO: 1=USD, 20=EUR)
$toplamUSD = 0;
$toplamEUR = 0;
$toplamTL  = 0;
foreach ($siparisler as $s) {
    $dovizToplam = (float)$s['TRRATE'] > 0 ? (float)$s['NETTOTAL'] / (float)$s['TRRATE'] : 0;
    if ((int)$s['TRCURR'] === 1) {
        $toplamUSD += $dovizToplam;
    } elseif ((int)$s['TRCURR'] === 20) {
        $toplamEUR += $dovizToplam;
    }
    $toplamTL += (float)$s['NETTOTAL'];
}
$toplamSiparis = count($siparisler);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dövizli Siparişler</title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f9fafb;
            --text-1: #1f2937;
            --text-2: #6b7280;
            --text-3: #9ca3af;
            --border: #e5e7eb;
            --sky: var(--red,#6F1022);
            --sky-soft: #fef2f2;
            --indigo: #4f46e5;
            --indigo-soft: #eef2ff;
            --emerald: #059669;
            --emerald-soft: #ecfdf5;
            --amber: #d97706;
            --amber-soft: #fffbeb;
            --red: #6F1022;
            --red-soft: #fef2f2;
            --purple: #7c3aed;
            --purple-soft: #f5f3ff;
        }
        * { box-sizing: border-box; margin: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
            will-change: transform;
        }

        /* Sticky red header */
        .top-header {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(111, 16, 34, 0.18);
            box-shadow: 0 2px 8px rgba(111, 16, 34, 0.05);
        }
        .header-inner {
            max-width: 1200px; margin: 0 auto;
            padding: 14px 20px;
            display: flex; align-items: center; gap: 14px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 40px; height: 40px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
            flex-shrink: 0;
        }
        .header-back:hover { background: var(--sky-soft); color: var(--sky); }
        .header-icon {
            width: 40px; height: 40px; flex-shrink: 0;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 12px;
            background: linear-gradient(180deg, #fee2e2, #fecaca);
            color: var(--sky);
            font-size: 16px;
            border: 1px solid rgba(111, 16, 34, 0.22);
        }
        .header-text { display: flex; flex-direction: column; min-width: 0; flex: 1 1 auto; }
        .header-title {
            font-size: 16px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 8px;
            line-height: 1.15;
        }
        .header-subtitle {
            font-size: 11.5px;
            color: var(--text-2);
            margin-top: 2px;
            font-weight: 500;
        }
        .header-subtitle strong { color: var(--sky); font-weight: 700; }

        main {
            max-width: 1200px; margin: 0 auto;
            padding: 20px 20px 48px;
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .glass-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(111, 16, 34, 0.14);
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
        }

        /* Filter panel */
        .filter-panel { padding: 16px; margin-bottom: 16px; }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            align-items: end;
        }
        .field { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
        .field label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .field input[type="month"],
        .field input[type="text"],
        .field select {
            width: 100%;
            padding: 11px 12px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14px;
            color: var(--text-1);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            outline: none;
            transition: all 0.18s ease;
            min-height: 44px;
        }
        .field input:focus, .field select:focus {
            border-color: rgba(111, 16, 34, 0.5);
            box-shadow: 0 0 0 3px rgba(111, 16, 34, 0.12);
        }

        .btn-primary {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 11px 22px;
            background: linear-gradient(135deg, var(--red,#ef4444), var(--red,#6F1022));
            color: #fff;
            border: 1px solid var(--red,#6F1022);
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.18s ease;
            box-shadow: 0 4px 12px rgba(111, 16, 34, 0.22);
            text-decoration: none;
            min-height: 44px;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(111, 16, 34, 0.3);
            filter: brightness(1.04);
        }

        /* Stat cards */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }
        .stat-card {
            padding: 14px 16px;
            border-radius: 14px;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid var(--border);
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            display: flex; align-items: center; gap: 12px;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
        }
        .stat-card .ic {
            width: 42px; height: 42px; flex-shrink: 0;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 12px; font-size: 16px;
        }
        .stat-card .tx { flex: 1 1 auto; min-width: 0; }
        .stat-card .lbl { font-size: 11px; color: var(--text-2); font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; }
        .stat-card .val { font-size: 18px; font-weight: 700; color: var(--text-1); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .stat-card.sky  .ic { background: var(--sky-soft);     color: var(--sky); }
        .stat-card.emer .ic { background: var(--emerald-soft); color: var(--emerald); }
        .stat-card.indg .ic { background: var(--indigo-soft);  color: var(--indigo); }

        /* Stagger */
        .stat-card:nth-child(1) { animation-delay: 0.02s; }
        .stat-card:nth-child(2) { animation-delay: 0.10s; }
        .stat-card:nth-child(3) { animation-delay: 0.18s; }

        /* Table card */
        .table-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(111, 16, 34, 0.14);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            overflow: hidden;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) 0.26s both;
            will-change: transform, opacity;
        }
        .table-wrap { overflow-x: auto; }
        .gd-table { width: 100%; border-collapse: collapse; }
        .gd-table thead { background: linear-gradient(180deg, #fff, #fef2f2); }
        .gd-table thead th {
            padding: 12px 14px;
            font-size: 10px; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: 0.5px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .gd-table thead th.right { text-align: right; }
        .gd-table thead th.center { text-align: center; }
        .gd-table tbody td {
            padding: 12px 14px;
            font-size: 12.5px; color: var(--text-1);
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .gd-table tbody tr { transition: background 0.15s ease; }
        .gd-table tbody tr:hover { background: var(--sky-soft); }
        .gd-table tbody tr:last-child td { border-bottom: none; }

        .gd-table td.ficheno {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px; color: var(--sky); font-weight: 700;
            white-space: nowrap;
        }
        .gd-table td.ficheno a { color: var(--sky); text-decoration: none; }
        .gd-table td.ficheno a:hover { text-decoration: underline; }
        .gd-table td.date { white-space: nowrap; color: var(--text-2); font-size: 12px; }
        .gd-table td.firma {
            max-width: 280px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .gd-table td.firma .name { font-weight: 600; color: var(--text-1); }
        .gd-table td.firma .code { color: var(--text-3); font-size: 11px; font-weight: 500; }
        .gd-table td.tutar {
            text-align: right; font-weight: 700; color: var(--text-1); white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }
        .gd-table td.actions { white-space: nowrap; text-align: right; }

        /* Row chips */
        .chip {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px;
            border-radius: 100px;
            font-size: 11px; font-weight: 700;
            white-space: nowrap;
            border: 1px solid transparent;
        }
        .chip.count { background: #f3f4f6; color: var(--text-2); border-color: var(--border); }
        .chip.usd   { background: var(--sky-soft);    color: var(--sky);    border-color: rgba(111,16,34,0.22); }
        .chip.eur   { background: var(--indigo-soft); color: var(--indigo); border-color: rgba(79,70,229,0.22); }
        .chip.rate  { background: var(--amber-soft);  color: var(--amber);  border-color: rgba(217,119,6,0.22); font-variant-numeric: tabular-nums; }

        .status-pill {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px;
            border-radius: 100px;
            font-size: 10.5px; font-weight: 700;
            white-space: nowrap;
            border: 1px solid transparent;
        }
        .status-pill.open   { background: var(--emerald-soft); color: var(--emerald); border-color: rgba(5,150,105,0.22); }
        .status-pill.closed { background: var(--red-soft);     color: var(--red);     border-color: rgba(111,16,34,0.22); }

        .row-btn {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 7px 11px;
            border-radius: 8px;
            font-size: 11px; font-weight: 600;
            text-decoration: none;
            transition: all 0.15s ease;
            border: 1px solid;
            min-height: 32px;
        }
        .row-btn.edit    { background: var(--sky-soft);     color: var(--sky);     border-color: rgba(111,16,34,0.22); }
        .row-btn.edit:hover    { background: var(--sky);     color: #fff; border-color: var(--sky); }
        .row-btn.print   { background: var(--emerald-soft); color: var(--emerald); border-color: rgba(5,150,105,0.22); }
        .row-btn.print:hover   { background: var(--emerald); color: #fff; border-color: var(--emerald); }
        .row-btn.design  { background: var(--purple-soft);  color: var(--purple);  border-color: rgba(124,58,237,0.22); }
        .row-btn.design:hover  { background: var(--purple);  color: #fff; border-color: var(--purple); }
        .row-btn i { font-size: 10px; }
        .action-group { display: inline-flex; gap: 5px; justify-content: flex-end; flex-wrap: nowrap; }

        /* Empty state */
        .empty-state {
            padding: 56px 20px;
            text-align: center;
            color: var(--text-3);
        }
        .empty-state i { font-size: 38px; margin-bottom: 12px; color: var(--text-3); }
        .empty-state .big { font-size: 15px; font-weight: 600; color: var(--text-2); margin-bottom: 4px; }
        .empty-state .sm  { font-size: 12.5px; color: var(--text-3); }

        /* Mobile */
        @media (max-width: 900px) {
            .filter-grid { grid-template-columns: 1fr 1fr; }
            .stat-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .header-inner { padding: 12px 16px; gap: 10px; }
            .header-icon { width: 36px; height: 36px; font-size: 15px; }
            .header-title { font-size: 15px; }
            main { padding: 16px 16px 40px; }
            .filter-panel { padding: 14px; }
            .filter-grid { grid-template-columns: 1fr; gap: 10px; }
            .btn-primary { width: 100%; font-size: 14px; padding: 12px 20px; }

            /* Stacked table on mobile */
            .gd-table thead { display: none; }
            .gd-table, .gd-table tbody, .gd-table tr, .gd-table td { display: block; width: 100%; }
            .gd-table tbody tr {
                padding: 12px 14px;
                border-bottom: 1px solid #f3f4f6;
            }
            .gd-table tbody tr:last-child { border-bottom: none; }
            .gd-table tbody td {
                padding: 4px 0;
                border: none;
                display: flex; justify-content: space-between; align-items: center;
                gap: 10px;
                font-size: 13px;
            }
            .gd-table tbody td::before {
                content: attr(data-label);
                font-size: 10.5px; font-weight: 700; color: var(--text-3);
                text-transform: uppercase; letter-spacing: 0.4px;
                flex-shrink: 0;
            }
            .gd-table td.firma { max-width: none; }
            .gd-table td.firma .name { text-align: right; }
            .gd-table td.actions { justify-content: flex-end; padding-top: 8px; }
            .action-group { flex-wrap: wrap; }
        }
    </style>
</head>
<body>

<header class="top-header">
    <div class="header-inner">
        <a href="index.php" class="header-back" title="Geri">
            <i class="fa fa-arrow-left"></i>
        </a>
        <div class="header-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
        <div class="header-text">
            <div class="header-title">Dövizli Siparişler</div>
            <div class="header-subtitle">
                <strong><?php echo $toplamSiparis; ?></strong> sipariş
                &middot; TL toplam <strong><?php echo number_format($toplamTL, 2, ',', '.'); ?> ₺</strong>
            </div>
        </div>
    </div>
</header>

<main>

    <!-- Filtreler -->
    <div class="glass-card filter-panel">
        <form method="GET" class="filter-grid">
            <div class="field">
                <label for="tarih">Dönem</label>
                <input id="tarih" type="month" name="tarih" value="<?php echo htmlspecialchars($filtreTarih); ?>">
            </div>
            <div class="field">
                <label for="doviz">Döviz Tipi</label>
                <select id="doviz" name="doviz">
                    <option value="0"  <?php echo $filtreDoviz === 0  ? 'selected' : ''; ?>>Tümü</option>
                    <option value="1"  <?php echo $filtreDoviz === 1  ? 'selected' : ''; ?>>$ USD</option>
                    <option value="20" <?php echo $filtreDoviz === 20 ? 'selected' : ''; ?>>€ EUR</option>
                </select>
            </div>
            <div class="field">
                <label>&nbsp;</label>
                <button type="submit" class="btn-primary">
                    <i class="fa fa-filter"></i> Filtrele
                </button>
            </div>
        </form>
    </div>

    <!-- Stat Cards -->
    <div class="stat-grid">
        <div class="stat-card sky">
            <div class="ic"><i class="fa-solid fa-list-check"></i></div>
            <div class="tx">
                <div class="lbl">Toplam Sipariş</div>
                <div class="val"><?php echo $toplamSiparis; ?></div>
            </div>
        </div>
        <div class="stat-card emer">
            <div class="ic"><i class="fa-solid fa-dollar-sign"></i></div>
            <div class="tx">
                <div class="lbl">Toplam USD</div>
                <div class="val"><?php echo number_format($toplamUSD, 2, ',', '.'); ?> $</div>
            </div>
        </div>
        <div class="stat-card indg">
            <div class="ic"><i class="fa-solid fa-euro-sign"></i></div>
            <div class="tx">
                <div class="lbl">Toplam EUR</div>
                <div class="val"><?php echo number_format($toplamEUR, 2, ',', '.'); ?> €</div>
            </div>
        </div>
    </div>

    <!-- Sipariş Listesi -->
    <div class="table-card">
        <?php if (empty($siparisler)): ?>
            <div class="empty-state">
                <i class="fa fa-inbox"></i>
                <div class="big">Kayıt bulunamadı</div>
                <div class="sm">Seçilen dönem ve döviz tipine ait dövizli sipariş yok.</div>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="gd-table">
                <thead>
                    <tr>
                        <th>Fiş No</th>
                        <th>Tarih</th>
                        <th>Cari</th>
                        <th class="center">Satır</th>
                        <th class="center">Döviz</th>
                        <th class="center">Kur</th>
                        <th class="right">Döviz Toplam</th>
                        <th class="right">TL Toplam</th>
                        <th class="center">Durum</th>
                        <th class="right">İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($siparisler as $sip):
                        $dovizInfo = $dovizBilgileri[(int)$sip['TRCURR']] ?? ['kod' => '?', 'sembol' => '?'];
                        $dovizToplam = (float)$sip['TRRATE'] > 0 ? (float)$sip['NETTOTAL'] / (float)$sip['TRRATE'] : 0;
                        $tarih = date('d.m.Y', strtotime($sip['DATE_']));
                        $isUSD = ((int)$sip['TRCURR']) === 1;
                        $isOpen = ((int)$sip['STATUS']) === 0;
                        $chipClass = $isUSD ? 'usd' : 'eur';
                        $lref = (int)$sip['LOGICALREF'];
                    ?>
                    <tr>
                        <td class="ficheno" data-label="Fiş No">
                            <a href="fis.php?id=<?php echo $lref; ?>&return_to=<?php echo rawurlencode($dovizSiparislerReturnUrl); ?>"><?php echo htmlspecialchars((string)$sip['FICHENO']); ?></a>
                        </td>
                        <td class="date" data-label="Tarih"><?php echo $tarih; ?></td>
                        <td class="firma" data-label="Cari">
                            <div class="name"><?php echo htmlspecialchars((string)$sip['CARI_ISIM']); ?></div>
                            <div class="code"><?php echo htmlspecialchars((string)$sip['CARI_KOD']); ?></div>
                        </td>
                        <td class="center" data-label="Satır">
                            <span class="chip count"><?php echo (int)$sip['SATIR_SAYISI']; ?></span>
                        </td>
                        <td class="center" data-label="Döviz">
                            <span class="chip <?php echo $chipClass; ?>">
                                <?php echo $dovizInfo['sembol']; ?> <?php echo $dovizInfo['kod']; ?>
                            </span>
                        </td>
                        <td class="center" data-label="Kur">
                            <span class="chip rate"><?php echo number_format((float)$sip['TRRATE'], 4, ',', '.'); ?></span>
                        </td>
                        <td class="tutar" data-label="Döviz Toplam">
                            <?php echo $dovizInfo['sembol']; ?> <?php echo number_format($dovizToplam, 2, ',', '.'); ?>
                        </td>
                        <td class="tutar" data-label="TL Toplam">
                            <?php echo number_format((float)$sip['NETTOTAL'], 2, ',', '.'); ?> ₺
                        </td>
                        <td class="center" data-label="Durum">
                            <?php if ($isOpen): ?>
                                <span class="status-pill open"><i class="fa fa-circle-check"></i> Açık</span>
                            <?php else: ?>
                                <span class="status-pill closed"><i class="fa fa-lock"></i> Kapalı</span>
                            <?php endif; ?>
                        </td>
                        <td class="actions" data-label="İşlem">
                            <div class="action-group">
                                <a class="row-btn edit"   href="fis.php?id=<?php echo $lref; ?>&return_to=<?php echo rawurlencode($dovizSiparislerReturnUrl); ?>" title="Düzenle">
                                    <i class="fa fa-pen-to-square"></i> Düzenle
                                </a>
                                <a class="row-btn print"  href="yazdir.php?id=<?php echo $lref; ?>" title="Yazdır" target="_blank">
                                    <i class="fa fa-print"></i>
                                </a>
                                <a class="row-btn print"  href="yazdirx.php?id=<?php echo $lref; ?>" title="Yazdır X" target="_blank">
                                    <i class="fa fa-file-lines"></i>
                                </a>
                                <a class="row-btn design" href="dizayn.php?id=<?php echo $lref; ?>" title="Dizayn" target="_blank">
                                    <i class="fa fa-palette"></i>
                                </a>
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

<script>
    // RAF reflow to ensure card animations land cleanly after layout
    requestAnimationFrame(function(){
        requestAnimationFrame(function(){
            document.body.classList.add('ready');
        });
    });
</script>

    <?php include_once(__DIR__ . '/../ux_katman.php'); ?>
</body>
</html>
