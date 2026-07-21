<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");

// YETKI KONTROLÜ: M4 (Dashboard yetkisi)
if (m_p_yetki($terminalkullanici, 'M4') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

$fisRef = isset($_GET['fis']) ? intval($_GET['fis']) : 0;
$tip = $_GET['tip'] ?? 'siparis'; // siparis veya fatura

if ($fisRef <= 0) {
    die("Geçersiz fiş referansı");
}

// Fiş bilgilerini çek (Sipariş veya Fatura)
if ($tip == 'siparis') {
    $stmtFisInfo = $dbh->prepare("
        SELECT
            F.LOGICALREF,
            F.FICHENO,
            F.DATE_ AS TARIH,
            F.GENEXP1 AS ACIKLAMA,
            F.NETTOTAL,
            F.TOTALDISCOUNTED,
            F.GROSSTOTAL,
            F.TRCODE,
            F.TOTALDISCOUNTS AS TOPLAM_ISKONTO,
            F.GENEXP2 AS ACIKLAMA2,
            F.GENEXP3 AS ACIKLAMA3,
            F.SPECODE AS OZELKOD,
            C.DEFINITION_ AS MUSTERI_ADI,
            C.CODE AS MUSTERI_KODU,
            C.ADDR1 AS ADRES1,
            C.ADDR2 AS ADRES2,
            C.CITY AS SEHIR,
            C.DISTRICT AS ILCE,
            C.TELNRS1 AS TELEFON,
            C.EMAILADDR AS EMAIL,
            S.DEFINITION_ AS SATICI_ADI,
            S.CODE AS SATICI_KODU
        FROM " . $firmadonem . "ORFICHE F
        LEFT JOIN " . $firma . "CLCARD C ON C.LOGICALREF = F.CLIENTREF
        LEFT JOIN LG_SLSMAN S ON S.LOGICALREF = F.SALESMANREF
        WHERE F.LOGICALREF = :fisRef
    ");
    $stmtFisInfo->execute([':fisRef' => $fisRef]);
    $fisInfo = $stmtFisInfo->fetch(PDO::FETCH_ASSOC);

    if (!$fisInfo) {
        die("Fiş bulunamadı");
    }

    // Fiş satırları
    $stmtSatirlar = $dbh->prepare("
        SELECT
            L.LINENO_ AS SIRA,
            I.CODE AS STOK_KODU,
            I.NAME AS STOK_ADI,
            L.AMOUNT AS MIKTAR,
            L.PRICE AS FIYAT,
            L.TOTAL AS TUTAR,
            L.VAT AS KDV,
            L.VATAMNT AS KDV_TUTAR,
            L.LINEEXP AS ACIKLAMA,
            U.CODE AS BIRIM
        FROM " . $firmadonem . "ORFLINE L
        LEFT JOIN " . $firma . "ITEMS I ON I.LOGICALREF = L.STOCKREF
        LEFT JOIN " . $firma . "UNITSETL U ON U.UNITSETREF = I.UNITSETREF AND U.LINENR = 1
        WHERE L.ORDFICHEREF = :fisRef AND L.LINETYPE = 0
        ORDER BY L.LINENO_ ASC
    ");
    $stmtSatirlar->execute([':fisRef' => $fisRef]);
    $satirlar = $stmtSatirlar->fetchAll(PDO::FETCH_ASSOC);

    $tipAdi = $fisInfo['TRCODE'] == 1 ? 'Satış Siparişi' : 'Alış Siparişi';
    $tipIcon = 'fa-file-alt';
    $tipColor = 'orange';
} else {
    $stmtFisInfo = $dbh->prepare("
        SELECT
            F.LOGICALREF,
            F.FICHENO,
            F.DATE_ AS TARIH,
            F.GENEXP1 AS ACIKLAMA,
            F.NETTOTAL,
            F.TOTALDISCOUNTED,
            F.GROSSTOTAL,
            F.TRCODE,
            F.TOTALDISCOUNTS AS TOPLAM_ISKONTO,
            F.GENEXP2 AS ACIKLAMA2,
            F.GENEXP3 AS ACIKLAMA3,
            F.SPECODE AS OZELKOD,
            C.DEFINITION_ AS MUSTERI_ADI,
            C.CODE AS MUSTERI_KODU,
            C.ADDR1 AS ADRES1,
            C.ADDR2 AS ADRES2,
            C.CITY AS SEHIR,
            C.DISTRICT AS ILCE,
            C.TELNRS1 AS TELEFON,
            C.EMAILADDR AS EMAIL,
            S.DEFINITION_ AS SATICI_ADI,
            S.CODE AS SATICI_KODU
        FROM " . $firmadonem . "INVOICE F
        LEFT JOIN " . $firma . "CLCARD C ON C.LOGICALREF = F.CLIENTREF
        LEFT JOIN LG_SLSMAN S ON S.LOGICALREF = F.SALESMANREF
        WHERE F.LOGICALREF = :fisRef
    ");
    $stmtFisInfo->execute([':fisRef' => $fisRef]);
    $fisInfo = $stmtFisInfo->fetch(PDO::FETCH_ASSOC);

    if (!$fisInfo) {
        die("Fatura bulunamadı");
    }

    // Fatura satırları
    $stmtSatirlar = $dbh->prepare("
        SELECT
            ROW_NUMBER() OVER (ORDER BY L.LOGICALREF) AS SIRA,
            I.CODE AS STOK_KODU,
            I.NAME AS STOK_ADI,
            L.AMOUNT AS MIKTAR,
            L.PRICE AS FIYAT,
            L.TOTAL AS TUTAR,
            L.VAT AS KDV,
            L.VATAMNT AS KDV_TUTAR,
            L.LINEEXP AS ACIKLAMA,
            U.CODE AS BIRIM
        FROM " . $firmadonem . "STLINE L
        LEFT JOIN " . $firma . "ITEMS I ON I.LOGICALREF = L.STOCKREF
        LEFT JOIN " . $firma . "UNITSETL U ON U.UNITSETREF = I.UNITSETREF AND U.LINENR = 1
        WHERE L.INVOICEREF = :fisRef AND L.LINETYPE = 0
        ORDER BY L.LOGICALREF ASC
    ");
    $stmtSatirlar->execute([':fisRef' => $fisRef]);
    $satirlar = $stmtSatirlar->fetchAll(PDO::FETCH_ASSOC);

    $tipAdi = $fisInfo['TRCODE'] == 7 ? 'Perakende Satış Faturası' : 'Toptan Satış Faturası';
    $tipIcon = 'fa-file-invoice';
    $tipColor = 'green';
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fis Goruntule - <?php echo htmlspecialchars((string) $fisInfo['FICHENO']); ?></title>
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="/tm/css/tailwind.js" onerror="var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);"></script>
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
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        .top-header {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(111, 16, 34, 0.18);
            box-shadow: 0 2px 8px rgba(111, 16, 34, 0.04);
        }
        .header-inner {
            max-width: 900px; margin: 0 auto;
            padding: 12px 22px;
            display: flex; align-items: center; gap: 12px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 34px; height: 34px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
            background: transparent; border: none; cursor: pointer;
        }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--red); }
        .header-divider { width: 1px; height: 22px; background: var(--border); }
        .header-title {
            font-size: 15px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 7px;
            flex: 1 1 auto;
        }
        .header-title i { color: var(--red); font-size: 14px; }
        .header-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 10px;
            background: var(--red-soft);
            color: var(--red);
            border: 1px solid rgba(111, 16, 34, 0.2);
            border-radius: 999px;
            font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .header-badge i { font-size: 10px; }

        main { max-width: 900px; margin: 0 auto; padding: 20px 22px 50px; }

        .glass-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(2, 132, 199, 0.18);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            margin-bottom: 16px;
            overflow: hidden;
        }
        .glass-card:nth-of-type(2) { animation-delay: 60ms; }
        .glass-card:nth-of-type(3) { animation-delay: 120ms; }
        .glass-card:nth-of-type(4) { animation-delay: 180ms; }

        .hero {
            padding: 22px 24px;
            background: linear-gradient(135deg, var(--red-soft), #fee2e2);
            border-bottom: 1px solid rgba(111, 16, 34, 0.22);
            display: flex; align-items: center; gap: 16px;
            flex-wrap: wrap;
        }
        .hero .hero-ico {
            width: 56px; height: 56px;
            border-radius: 14px;
            background: #fff;
            color: var(--red);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(111, 16, 34, 0.18);
        }
        .hero .hero-text { flex: 1 1 auto; min-width: 0; }
        .hero .hero-text h2 {
            font-size: 19px; font-weight: 700; color: var(--text-1);
            margin: 0;
        }
        .hero .hero-text p {
            margin: 3px 0 0;
            font-size: 12.5px;
            color: var(--red);
            font-weight: 600;
            overflow: hidden; text-overflow: ellipsis;
        }
        .hero .hero-amount {
            margin-left: auto;
            text-align: right;
        }
        .hero .hero-amount .lbl {
            font-size: 10px;
            color: var(--red);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-weight: 600;
        }
        .hero .hero-amount .val {
            display: block;
            margin-top: 2px;
            font-size: 24px;
            font-weight: 800;
            color: var(--red);
            line-height: 1.15;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0;
        }
        .info-item {
            padding: 14px 18px;
            border-right: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }
        .info-item:nth-child(2n) { border-right: none; }
        .info-item.full { grid-column: 1 / -1; border-right: none; }
        .info-item .label {
            font-size: 10px;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 4px;
        }
        .info-item .label i { color: var(--text-3); font-size: 10px; }
        .info-item .val {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-1);
            word-break: break-word;
        }

        .card-head {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 9px;
            background: #fafbfc;
        }
        .card-head i { color: var(--sky); font-size: 13px; }
        .card-head span {
            font-size: 13px; font-weight: 700; color: var(--text-1);
            text-transform: uppercase; letter-spacing: 0.3px;
        }

        .table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .lines-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }
        .lines-table thead th {
            padding: 11px 12px;
            font-size: 10px;
            font-weight: 700;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            background: #f9fafb;
            white-space: nowrap;
        }
        .lines-table tbody td {
            padding: 11px 12px;
            font-size: 12.5px;
            color: var(--text-1);
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        .lines-table tbody tr:last-child td { border-bottom: none; }
        .lines-table tbody tr:hover { background: #f9fafb; }
        .cell-sira { color: var(--text-3); font-weight: 600; font-variant-numeric: tabular-nums; width: 38px; }
        .cell-code { font-weight: 600; color: var(--sky); font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 12px; white-space: nowrap; }
        .cell-name { color: var(--text-1); min-width: 200px; }
        .cell-name .line-desc { display: block; font-size: 11px; color: var(--text-3); font-weight: 500; margin-top: 2px; }
        .cell-qty { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; font-weight: 600; color: var(--indigo); }
        .cell-unit { text-align: center; color: var(--text-2); font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; }
        .cell-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; color: var(--text-2); }
        .cell-total { text-align: right; font-weight: 700; color: var(--emerald); font-variant-numeric: tabular-nums; white-space: nowrap; }
        .cell-vat { text-align: center; color: var(--text-2); font-size: 11px; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .cell-vat-amt { text-align: right; color: #d97706; font-variant-numeric: tabular-nums; white-space: nowrap; }

        .summary-wrap {
            padding: 18px 20px;
            display: flex;
            justify-content: flex-end;
        }
        .summary-box {
            width: 100%;
            max-width: 420px;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            padding: 8px 0;
            font-size: 13px;
        }
        .summary-row .s-lbl { color: var(--text-2); font-weight: 500; }
        .summary-row .s-val { color: var(--text-1); font-weight: 600; font-variant-numeric: tabular-nums; }
        .summary-row.total {
            margin-top: 6px;
            padding-top: 14px;
            border-top: 2px solid var(--red);
        }
        .summary-row.total .s-lbl { color: var(--red); font-weight: 700; font-size: 14px; text-transform: uppercase; letter-spacing: 0.3px; }
        .summary-row.total .s-val { color: var(--red); font-weight: 800; font-size: 20px; }

        .notes-wrap { padding: 16px 20px; display: flex; flex-direction: column; gap: 10px; }
        .note-item {
            padding: 12px 14px;
            border-radius: 10px;
            border-left: 3px solid var(--sky);
            background: var(--sky-soft);
        }
        .note-item.n2 { border-left-color: var(--emerald); background: var(--emerald-soft); }
        .note-item.n3 { border-left-color: var(--indigo); background: var(--indigo-soft); }
        .note-item .note-lbl {
            font-size: 9.5px;
            font-weight: 700;
            color: var(--sky);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            display: block;
            margin-bottom: 4px;
        }
        .note-item.n2 .note-lbl { color: var(--emerald); }
        .note-item.n3 .note-lbl { color: var(--indigo); }
        .note-item .note-txt { font-size: 12.5px; color: var(--text-1); line-height: 1.55; }

        .readonly-banner {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 14px 18px;
            margin-top: 8px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-left: 3px solid #f59e0b;
            border-radius: 12px;
        }
        .readonly-banner i { color: #d97706; font-size: 16px; margin-top: 1px; }
        .readonly-banner .rb-title { font-size: 12.5px; font-weight: 700; color: #92400e; }
        .readonly-banner .rb-text { font-size: 11.5px; color: #a16207; margin-top: 2px; }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        @media (max-width: 767px) {
            .header-inner { padding: 10px 14px; gap: 10px; }
            .header-title { font-size: 13px; }
            .header-badge { padding: 4px 8px; font-size: 9.5px; }

            main { padding: 14px 12px 40px; }

            .hero { padding: 16px 18px; gap: 12px; }
            .hero .hero-ico { width: 46px; height: 46px; font-size: 19px; }
            .hero .hero-text h2 { font-size: 16px; }
            .hero .hero-text p { font-size: 11.5px; }
            .hero .hero-amount { margin-left: 0; width: 100%; text-align: left; padding-top: 8px; border-top: 1px dashed rgba(111, 16, 34, 0.3); }
            .hero .hero-amount .val { font-size: 20px; }

            .info-grid { grid-template-columns: 1fr; }
            .info-item { border-right: none; }
            .info-item:nth-child(2n) { border-right: none; }

            .summary-wrap { padding: 14px 16px; }
            .summary-box { max-width: 100%; }
        }

        @media print {
            body { background: #fff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .top-header, .readonly-banner { display: none !important; }
            main { max-width: none; padding: 0; margin: 0; }
            .glass-card { box-shadow: none; border: 1px solid #999; animation: none; backdrop-filter: none; background: #fff; }
            .hero { background: #fef2f2 !important; }
            .lines-table { min-width: 0; }
            .lines-table tbody tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="header-inner">
            <button onclick="window.history.length > 1 ? window.history.back() : window.close()" class="header-back" title="Geri">
                <i class="fa fa-arrow-left"></i>
            </button>
            <div class="header-divider"></div>
            <span class="header-title">
                <i class="fa-solid fa-receipt"></i>Fis Goruntule
            </span>
            <span class="header-badge">
                <i class="fa-solid fa-lock"></i>Salt Okunur
            </span>
        </div>
    </header>

    <main>
        <section class="glass-card">
            <div class="hero">
                <span class="hero-ico"><i class="fa-solid <?php echo htmlspecialchars((string) $tipIcon); ?>"></i></span>
                <div class="hero-text">
                    <h2><?php echo htmlspecialchars((string) $tipAdi); ?></h2>
                    <p><?php echo htmlspecialchars((string) $fisInfo['MUSTERI_ADI']); ?></p>
                </div>
                <div class="hero-amount">
                    <span class="lbl">Net Toplam</span>
                    <span class="val"><?php echo number_format((float)$fisInfo['NETTOTAL'], 2, ',', '.'); ?> &#8378;</span>
                </div>
            </div>

            <div class="info-grid">
                <div class="info-item">
                    <div class="label"><i class="fa-solid fa-hashtag"></i>Fis No</div>
                    <div class="val"><?php echo htmlspecialchars((string) $fisInfo['FICHENO']); ?></div>
                </div>
                <div class="info-item">
                    <div class="label"><i class="fa-regular fa-calendar"></i>Tarih</div>
                    <div class="val"><?php echo date('d.m.Y', strtotime((string) $fisInfo['TARIH'])); ?></div>
                </div>
                <div class="info-item">
                    <div class="label"><i class="fa-solid fa-id-card"></i>Musteri Kodu</div>
                    <div class="val"><?php echo htmlspecialchars((string) $fisInfo['MUSTERI_KODU']); ?></div>
                </div>
                <div class="info-item">
                    <div class="label"><i class="fa-solid fa-user-tie"></i>Satici</div>
                    <div class="val"><?php echo htmlspecialchars((string) ($fisInfo['SATICI_ADI'] ?? '-')); ?></div>
                </div>
                <div class="info-item full">
                    <div class="label"><i class="fa-solid fa-user"></i>Musteri</div>
                    <div class="val"><?php echo htmlspecialchars((string) $fisInfo['MUSTERI_ADI']); ?></div>
                </div>
                <?php if (!empty($fisInfo['SEHIR'])): ?>
                <div class="info-item">
                    <div class="label"><i class="fa-solid fa-location-dot"></i>Sehir / Ilce</div>
                    <div class="val"><?php echo htmlspecialchars((string) $fisInfo['SEHIR']); ?><?php echo !empty($fisInfo['ILCE']) ? ' / ' . htmlspecialchars((string) $fisInfo['ILCE']) : ''; ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($fisInfo['TELEFON'])): ?>
                <div class="info-item">
                    <div class="label"><i class="fa-solid fa-phone"></i>Telefon</div>
                    <div class="val"><?php echo htmlspecialchars((string) $fisInfo['TELEFON']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($fisInfo['EMAIL'])): ?>
                <div class="info-item full">
                    <div class="label"><i class="fa-solid fa-envelope"></i>E-Posta</div>
                    <div class="val"><?php echo htmlspecialchars((string) $fisInfo['EMAIL']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($fisInfo['ADRES1'])): ?>
                <div class="info-item full">
                    <div class="label"><i class="fa-solid fa-house"></i>Adres</div>
                    <div class="val"><?php echo htmlspecialchars((string) $fisInfo['ADRES1']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($fisInfo['OZELKOD'])): ?>
                <div class="info-item full">
                    <div class="label"><i class="fa-solid fa-tag"></i>Ozel Kod</div>
                    <div class="val"><?php echo htmlspecialchars((string) $fisInfo['OZELKOD']); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <?php if (!empty($satirlar)): ?>
        <section class="glass-card">
            <div class="card-head">
                <i class="fa-solid fa-list"></i>
                <span>Urunler</span>
            </div>
            <div class="table-wrap">
                <table class="lines-table">
                    <thead>
                        <tr>
                            <th class="cell-sira">#</th>
                            <th>Kodu</th>
                            <th>Aciklama</th>
                            <th class="cell-qty">Miktar</th>
                            <th class="cell-unit">Birim</th>
                            <th class="cell-num">Fiyat</th>
                            <th class="cell-num">Tutar</th>
                            <th class="cell-vat">KDV %</th>
                            <th class="cell-vat-amt">KDV Tutar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($satirlar as $satir): ?>
                            <tr>
                                <td class="cell-sira"><?php echo $satir['SIRA']; ?></td>
                                <td class="cell-code"><?php echo htmlspecialchars((string) $satir['STOK_KODU']); ?></td>
                                <td class="cell-name">
                                    <?php echo htmlspecialchars((string) $satir['STOK_ADI']); ?>
                                    <?php if (!empty($satir['ACIKLAMA'])): ?>
                                        <span class="line-desc"><?php echo htmlspecialchars((string) $satir['ACIKLAMA']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell-qty"><?php echo kusuratadet($satir['MIKTAR']); ?></td>
                                <td class="cell-unit"><?php echo htmlspecialchars((string) $satir['BIRIM']); ?></td>
                                <td class="cell-num"><?php echo number_format((float)$satir['FIYAT'], 2, ',', '.'); ?> &#8378;</td>
                                <td class="cell-total"><?php echo number_format((float)$satir['TUTAR'], 2, ',', '.'); ?> &#8378;</td>
                                <td class="cell-vat">%<?php echo $satir['KDV']; ?></td>
                                <td class="cell-vat-amt"><?php echo number_format((float)$satir['KDV_TUTAR'], 2, ',', '.'); ?> &#8378;</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <section class="glass-card">
            <div class="card-head">
                <i class="fa-solid fa-calculator"></i>
                <span>Ozet</span>
            </div>
            <div class="summary-wrap">
                <div class="summary-box">
                    <div class="summary-row">
                        <span class="s-lbl">Brut Toplam</span>
                        <span class="s-val"><?php echo number_format((float)$fisInfo['GROSSTOTAL'], 2, ',', '.'); ?> &#8378;</span>
                    </div>
                    <div class="summary-row">
                        <span class="s-lbl">Toplam Iskonto</span>
                        <span class="s-val">- <?php echo number_format((float)$fisInfo['TOPLAM_ISKONTO'], 2, ',', '.'); ?> &#8378;</span>
                    </div>
                    <div class="summary-row">
                        <span class="s-lbl">Iskonto Sonrasi</span>
                        <span class="s-val"><?php echo number_format((float)$fisInfo['TOTALDISCOUNTED'], 2, ',', '.'); ?> &#8378;</span>
                    </div>
                    <div class="summary-row total">
                        <span class="s-lbl">Genel Toplam</span>
                        <span class="s-val"><?php echo number_format((float)$fisInfo['NETTOTAL'], 2, ',', '.'); ?> &#8378;</span>
                    </div>
                </div>
            </div>
        </section>

        <?php if (!empty($fisInfo['ACIKLAMA']) || !empty($fisInfo['ACIKLAMA2']) || !empty($fisInfo['ACIKLAMA3'])): ?>
        <section class="glass-card">
            <div class="card-head">
                <i class="fa-solid fa-comment-dots"></i>
                <span>Aciklamalar</span>
            </div>
            <div class="notes-wrap">
                <?php if (!empty($fisInfo['ACIKLAMA'])): ?>
                    <div class="note-item">
                        <span class="note-lbl">Aciklama 1</span>
                        <div class="note-txt"><?php echo nl2br(htmlspecialchars((string) $fisInfo['ACIKLAMA'])); ?></div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($fisInfo['ACIKLAMA2'])): ?>
                    <div class="note-item n2">
                        <span class="note-lbl">Aciklama 2</span>
                        <div class="note-txt"><?php echo nl2br(htmlspecialchars((string) $fisInfo['ACIKLAMA2'])); ?></div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($fisInfo['ACIKLAMA3'])): ?>
                    <div class="note-item n3">
                        <span class="note-lbl">Aciklama 3</span>
                        <div class="note-txt"><?php echo nl2br(htmlspecialchars((string) $fisInfo['ACIKLAMA3'])); ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <div class="readonly-banner">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <div class="rb-title">Salt Okunur Gorunum</div>
                <div class="rb-text">Bu sayfa sadece goruntuleme amaclidir. Degisiklik yapmak icin ana siparis sayfasini kullanin.</div>
            </div>
        </div>
    </main>

</body>
</html>
