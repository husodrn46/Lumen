<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
require_once __DIR__ . '/../kontrol.php';
include_once(__DIR__ . "/../log_ip.php");

// DEBUG çıktısı
echo "<!-- DEBUG START -->";
echo "<!-- ayr.php loaded, dbh exists: " . (isset($dbh) ? 'YES' : 'NO') . " -->";

// en başta cari_id ve REF'i alıyoruz
if (isset($_GET['cari_id'], $_GET['REF'])) {
    $CARIID = (int)$_GET['cari_id'];
    $REF    = (int)$_GET['REF'];
    echo "<!-- CARIID: $CARIID, REF: $REF -->";
} else {
    echo "<!-- HATA: cari_id veya REF eksik! -->";
    exit;
}

// Dönem parametresini al
$donemParam = isset($_GET['donem']) ? $_GET['donem'] : '';

// Dönem prefix'leri - doğrudan tanımla
$donem2026 = 'LG_001_02_';
$donem2025 = 'LG_001_01_';

// Önce faturanın türünü CLFLINE'dan çek - her iki dönemde de ara
$clSatir = null;
$donemBulundu = $donem2026; // varsayılan aktif dönem

// Önce 2026 döneminde ara
$stmtClSatir = $dbh->prepare("
    SELECT TOP 1 TRCODE, SOURCEFREF
    FROM {$donem2026}CLFLINE
    WHERE CLIENTREF  = :cariid
      AND SOURCEFREF = :ref
      AND CANCELLED  = 0
");
$stmtClSatir->execute([':cariid' => $CARIID, ':ref' => $REF]);
$clSatir = $stmtClSatir->fetch(PDO::FETCH_ASSOC);

// 2026'da bulunamazsa 2025'te ara
if (!$clSatir) {
    $stmtClSatir2 = $dbh->prepare("
        SELECT TOP 1 TRCODE, SOURCEFREF
        FROM {$donem2025}CLFLINE
        WHERE CLIENTREF  = :cariid
          AND SOURCEFREF = :ref
          AND CANCELLED  = 0
    ");
    $stmtClSatir2->execute([':cariid' => $CARIID, ':ref' => $REF]);
    $clSatir = $stmtClSatir2->fetch(PDO::FETCH_ASSOC);
    if ($clSatir) {
        $donemBulundu = $donem2025;
    }
}

// Dönem parametresi açıkça verilmişse ve kayıt o dönemde varsa kullan
if ($donemParam === '2025') {
    // 2025'te var mı kontrol et
    $stmtCheck = $dbh->prepare("
        SELECT TOP 1 1 FROM {$donem2025}CLFLINE
        WHERE CLIENTREF = :cariid AND SOURCEFREF = :ref AND CANCELLED = 0
    ");
    $stmtCheck->execute([':cariid' => $CARIID, ':ref' => $REF]);
    if ($stmtCheck->fetch()) {
        $donemBulundu = $donem2025;
    }
} elseif ($donemParam === '2026') {
    // 2026'da var mı kontrol et
    $stmtCheck = $dbh->prepare("
        SELECT TOP 1 1 FROM {$donem2026}CLFLINE
        WHERE CLIENTREF = :cariid AND SOURCEFREF = :ref AND CANCELLED = 0
    ");
    $stmtCheck->execute([':cariid' => $CARIID, ':ref' => $REF]);
    if ($stmtCheck->fetch()) {
        $donemBulundu = $donem2026;
    }
}

echo "<!-- donemBulundu: $donemBulundu -->";
echo "<!-- clSatir: " . ($clSatir ? 'BULUNDU' : 'YOK') . " -->";

// başlığı belirle (TRCODE: 28,34=Alınan, 29,39=Verilen Hizmet Faturası)
$trcode = $clSatir ? (int)$clSatir['TRCODE'] : 0;
if (in_array($trcode, [29, 39])) {
    $fatura_baslik = 'Verilen Hizmet Faturası';
} else {
    $fatura_baslik = 'Alınan Hizmet Faturası';
}

function paraformat(float|int|string|null $kusurat): string
{
    global $parakusurat;
    if ($kusurat === "" || is_null($kusurat)) {
        $kusurat = 0;
    }
    return number_format((float)$kusurat, (int)$parakusurat, ',', '.');
}
?>

<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($fatura_baslik); ?></title>
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="/tm/css/tailwind.js"
            onerror="var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);"></script>
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
            --purple: #7c3aed;
            --purple-soft: #f5f3ff;
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

        .top-header {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(124, 58, 237, 0.18);
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.04);
        }
        .header-inner {
            max-width: 980px; margin: 0 auto;
            padding: 12px 22px;
            display: flex; align-items: center; gap: 12px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 34px; height: 34px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
        }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--purple); }
        .header-divider { width: 1px; height: 22px; background: var(--border); }
        .header-title {
            font-size: 15px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 7px;
            flex: 1 1 auto;
        }
        .header-title i { color: var(--purple); font-size: 13px; }
        .btn-print {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 14px; background: var(--red,#ef4444); color: #fff;
            border: none; border-radius: 9px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12px; font-weight: 700;
            cursor: pointer; transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
        }
        .btn-print:hover { background: var(--red); transform: translateY(-1px); }

        main { max-width: 980px; margin: 0 auto; padding: 20px 22px 50px; }

        .glass-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(124, 58, 237, 0.18);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            margin-bottom: 16px;
            overflow: hidden;
        }
        .glass-card:nth-of-type(2) { animation-delay: 60ms; }
        .glass-card:nth-of-type(3) { animation-delay: 120ms; }

        .hero {
            padding: 20px 22px;
            background: linear-gradient(135deg, #f5f3ff, #ede9fe);
            border-bottom: 1px solid rgba(124, 58, 237, 0.22);
            display: flex; align-items: center; gap: 14px;
            flex-wrap: wrap;
        }
        .hero .hero-ico {
            width: 54px; height: 54px;
            border-radius: 14px;
            background: #fff;
            color: var(--purple);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.15);
        }
        .hero .hero-text { flex: 1 1 auto; min-width: 0; }
        .hero .hero-text h2 {
            font-size: 18px; font-weight: 700; color: var(--text-1);
            margin: 0;
        }
        .hero .hero-text p {
            margin: 3px 0 0;
            font-size: 12px;
            color: var(--purple);
            font-weight: 600;
            word-break: break-word;
        }
        .hero .hero-amount {
            margin-left: auto;
            text-align: right;
        }
        .hero .hero-amount .lbl {
            font-size: 10px;
            color: var(--purple);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-weight: 600;
        }
        .hero .hero-amount .val {
            display: block;
            margin-top: 2px;
            font-size: 22px;
            font-weight: 800;
            color: var(--purple);
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
        .info-item .val.strong {
            font-size: 15px;
            font-weight: 800;
            color: var(--purple);
        }
        .info-grid > *:nth-last-child(-n+2):nth-child(2n+1) { border-bottom: none; }
        .info-grid > *:nth-last-child(-n+2):nth-child(2n+1) ~ .info-item:last-child { border-bottom: none; }
        .info-grid > *:last-child { border-bottom: none; }

        .section-title {
            padding: 14px 22px 10px;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            display: flex;
            align-items: center;
            gap: 7px;
            border-bottom: 1px solid var(--border);
        }
        .section-title i { color: var(--purple); font-size: 12px; }

        .hizmet-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }
        .hizmet-table thead th {
            background: #f9fafb;
            color: var(--text-2);
            font-weight: 700;
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 11px 14px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .hizmet-table tbody td {
            padding: 12px 14px;
            border-bottom: 1px solid #f3f4f6;
            color: var(--text-1);
            vertical-align: top;
        }
        .hizmet-table tbody tr:last-child td { border-bottom: none; }
        .hizmet-table tbody tr:hover { background: var(--purple-soft); }
        .hizmet-table .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .hizmet-table .num.center { text-align: center; }
        .hizmet-table .code { font-size: 11.5px; color: var(--text-2); font-weight: 600; }
        .hizmet-table .name { font-weight: 600; color: var(--text-1); }
        .hizmet-table .empty {
            text-align: center;
            padding: 24px 14px;
            color: var(--text-3);
            font-style: italic;
        }

        .error-box {
            padding: 20px 22px;
            background: var(--red-soft);
            border: 1px solid rgba(111, 16, 34, 0.22);
            border-radius: 14px;
            color: var(--red);
            margin-bottom: 16px;
        }
        .error-box p { margin: 4px 0; font-size: 13px; }
        .error-box p.title { font-weight: 700; font-size: 14px; margin-bottom: 8px; }
        .error-box a {
            display: inline-block;
            margin-top: 10px;
            padding: 8px 16px;
            background: var(--red);
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 12px;
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        @media (max-width: 767px) {
            .header-inner { padding: 10px 14px; gap: 10px; }
            .header-title { font-size: 13px; }
            .btn-print { padding: 7px 12px; font-size: 11.5px; }

            main { padding: 14px 12px 40px; }

            .hero { padding: 16px 18px; gap: 12px; }
            .hero .hero-ico { width: 44px; height: 44px; font-size: 18px; }
            .hero .hero-text h2 { font-size: 15px; }
            .hero .hero-amount { margin-left: 0; width: 100%; text-align: left; }
            .hero .hero-amount .val { font-size: 20px; }

            .info-grid { grid-template-columns: 1fr; }
            .info-item { border-right: none; }

            .hizmet-table thead { display: none; }
            .hizmet-table, .hizmet-table tbody, .hizmet-table tr, .hizmet-table td { display: block; width: 100%; }
            .hizmet-table tbody tr {
                padding: 12px 14px;
                border-bottom: 1px solid var(--border);
            }
            .hizmet-table tbody tr:last-child { border-bottom: none; }
            .hizmet-table tbody td {
                padding: 3px 0;
                border: none;
            }
            .hizmet-table .num, .hizmet-table .num.center { text-align: left; }
            .hizmet-table td::before {
                content: attr(data-label);
                display: inline-block;
                min-width: 90px;
                font-size: 10px;
                color: var(--text-2);
                text-transform: uppercase;
                letter-spacing: 0.4px;
                font-weight: 700;
                margin-right: 8px;
            }
            .hizmet-table td.no-label::before { content: none; }
        }

        @media print {
            body { background: #fff; }
            .top-header { position: static; box-shadow: none; border-bottom: 2px solid #000; }
            .btn-print, .header-back { display: none !important; }
            .glass-card { box-shadow: none; border: 1px solid #ccc; animation: none; }
            .hizmet-table tbody tr:hover { background: transparent; }
        }
    </style>
</head>

<body>

    <header class="top-header">
        <div class="header-inner">
            <a href="lg_hareket.php?cariid=<?php echo (int)$CARIID; ?>" class="header-back" title="Cari Hareket Sayfasina Geri Don">
                <i class="fa fa-arrow-left"></i>
            </a>
            <div class="header-divider"></div>
            <span class="header-title">
                <i class="fa-solid fa-file-invoice"></i><?php echo htmlspecialchars($fatura_baslik); ?>
            </span>
            <button onclick="window.print()" class="btn-print" type="button">
                <i class="fa-solid fa-print"></i> PDF Kaydet
            </button>
        </div>
    </header>

    <main>
        <?php
            $mdoviz = "₺";

            // Fatura bilgilerini al - önce CLFLINE üzerinden INVOICE'a ulaş
            // Hizmet faturaları için SOURCEFREF direkt INVOICE.LOGICALREF olmayabilir
            $sqf = null;

            // Yöntem 1: SOURCEFREF = INVOICE.LOGICALREF olarak dene
            $stmtFatura1 = $dbh->prepare("
                SELECT C.DEFINITION_, C.CITY, C.TELNRS1,
                       F.FICHENO, F.DATE_, F.NETTOTAL,
                       F.GROSSTOTAL, F.TOTALDISCOUNTS, F.TOTALVAT,
                       F.LOGICALREF AS INVOICE_REF
                FROM {$donemBulundu}INVOICE AS F
                LEFT JOIN {$firma}CLCARD AS C
                  ON C.LOGICALREF = F.CLIENTREF
                WHERE F.LOGICALREF = :ref
            ");
            $stmtFatura1->execute([':ref' => $REF]);
            $sqf = $stmtFatura1->fetch(PDO::FETCH_ASSOC);

            // Yöntem 2: Eğer bulunamazsa, CLFLINE -> STFICHE -> INVOICE yoluyla dene
            if (!$sqf) {
                $stmtFatura2 = $dbh->prepare("
                    SELECT C.DEFINITION_, C.CITY, C.TELNRS1,
                           F.FICHENO, F.DATE_, F.NETTOTAL,
                           F.GROSSTOTAL, F.TOTALDISCOUNTS, F.TOTALVAT,
                           F.LOGICALREF AS INVOICE_REF
                    FROM {$donemBulundu}CLFLINE AS CL
                    INNER JOIN {$donemBulundu}STFICHE AS ST ON ST.LOGICALREF = CL.SOURCEFREF
                    INNER JOIN {$donemBulundu}INVOICE AS F ON F.LOGICALREF = ST.INVOICEREF
                    LEFT JOIN {$firma}CLCARD AS C ON C.LOGICALREF = F.CLIENTREF
                    WHERE CL.CLIENTREF = :cariid
                      AND CL.SOURCEFREF = :ref
                      AND CL.CANCELLED = 0
                ");
                $stmtFatura2->execute([':cariid' => $CARIID, ':ref' => $REF]);
                $sqf = $stmtFatura2->fetch(PDO::FETCH_ASSOC);
            }

            // Yöntem 3: Direkt INVOICE tablosundan CLIENTREF ile ara
            if (!$sqf) {
                $stmtFatura3 = $dbh->prepare("
                    SELECT C.DEFINITION_, C.CITY, C.TELNRS1,
                           F.FICHENO, F.DATE_, F.NETTOTAL,
                           F.GROSSTOTAL, F.TOTALDISCOUNTS, F.TOTALVAT,
                           F.LOGICALREF AS INVOICE_REF
                    FROM {$donemBulundu}INVOICE AS F
                    LEFT JOIN {$firma}CLCARD AS C ON C.LOGICALREF = F.CLIENTREF
                    WHERE F.CLIENTREF = :cariid
                      AND F.TRCODE IN (28, 29, 34, 39)
                    ORDER BY F.DATE_ DESC
                ");
                $stmtFatura3->execute([':cariid' => $CARIID]);
                // REF sırasına göre al (en son = en büyük REF)
                $tumFaturalar = $stmtFatura3->fetchAll(PDO::FETCH_ASSOC);
                if (count($tumFaturalar) > 0) {
                    // REF değerine göre seç (index olarak kullan)
                    $index = $REF - 1;
                    if (isset($tumFaturalar[$index])) {
                        $sqf = $tumFaturalar[$index];
                    } else {
                        $sqf = $tumFaturalar[0]; // İlk faturayı al
                    }
                }
            }

            // Cari bakiye (GNTOTCL'den - lg_bakiye.php ile aynı kaynak)
            $stmtBakiye = $dbh->prepare("
                SELECT (ISNULL(G.DEBIT, 0) - ISNULL(G.CREDIT, 0)) AS BAKIYE
                FROM {$firmadonemx}GNTOTCL G WITH(NOLOCK)
                WHERE G.CARDREF = :cariid AND G.TOTTYP = 1
            ");
            $stmtBakiye->execute([':cariid' => $CARIID]);
            $sqlbakiye = $stmtBakiye->fetch(PDO::FETCH_ASSOC);

            if (!$sqf) {
                echo "<div class='error-box'>";
                echo "<p class='title'><i class='fa-solid fa-triangle-exclamation'></i> Fatura bilgileri bulunamadi.</p>";
                echo "<p>Cari ID: " . htmlspecialchars((string)$CARIID) . "</p>";
                echo "<p>REF (SOURCEFREF): " . htmlspecialchars((string)$REF) . "</p>";
                echo "<p>Donem: " . htmlspecialchars($donemBulundu) . "</p>";
                echo "<p>CLFLINE kaydi: " . ($clSatir ? 'Bulundu (TRCODE=' . ($clSatir['TRCODE'] ?? '?') . ')' : 'Bulunamadi') . "</p>";
                echo "<p>3 farkli yontem denendi: INVOICE.LOGICALREF, STFICHE->INVOICE, CLIENTREF+TRCODE</p>";
                echo "<a href='javascript:history.back()'><i class='fa fa-arrow-left'></i> Geri Don</a>";
                echo "</div>";
                echo "</main></body></html>";
                exit;
            }

            // === SADECE HİZMET SATIRLARI (bulunan INVOICE_REF ile) ===
            $invoiceRef = isset($sqf['INVOICE_REF']) ? (int)$sqf['INVOICE_REF'] : $REF;
            $stmtServ = $dbh->prepare("
                SELECT
                    S.CODE        AS SRV_CODE,
                    S.DEFINITION_ AS SRV_NAME,
                    L.AMOUNT      AS AMOUNT,
                    L.PRICE       AS PRICE,
                    L.TOTAL       AS TOTAL
                FROM {$donemBulundu}STLINE AS L
                INNER JOIN {$firma}SRVCARD AS S
                    ON S.LOGICALREF = L.STOCKREF
                WHERE L.INVOICEREF = :ref
                  AND L.LINETYPE = 4
            ");
            $stmtServ->execute([':ref' => $invoiceRef]);

            $hizmetler = $stmtServ ? $stmtServ->fetchAll(PDO::FETCH_ASSOC) : [];

            $genelToplam = (float)($sqf['NETTOTAL'] ?? 0) + (float)($sqf['TOTALVAT'] ?? 0);
            ?>

            <section class="glass-card">
                <div class="hero">
                    <span class="hero-ico">
                        <i class="fa-solid fa-file-invoice"></i>
                    </span>
                    <div class="hero-text">
                        <h2><?php echo htmlspecialchars($fatura_baslik); ?></h2>
                        <p><?php echo htmlspecialchars((string) ($sqf['DEFINITION_'] ?? '')); ?></p>
                    </div>
                    <div class="hero-amount">
                        <span class="lbl">Genel Toplam</span>
                        <span class="val"><?php echo paraformat($genelToplam) . ' ' . $mdoviz; ?></span>
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-item">
                        <div class="label"><i class="fa-regular fa-calendar"></i>Tarih</div>
                        <div class="val"><?php echo tarihcevir($sqf['DATE_']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label"><i class="fa-solid fa-hashtag"></i>Belge No</div>
                        <div class="val"><?php echo htmlspecialchars((string) $sqf['FICHENO']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label"><i class="fa-solid fa-location-dot"></i>Sehir</div>
                        <div class="val"><?php echo htmlspecialchars((string) ($sqf['CITY'] ?? '')) ?: '-'; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label"><i class="fa-solid fa-phone"></i>Telefon</div>
                        <div class="val"><?php echo !empty($sqf['TELNRS1']) ? htmlspecialchars((string) $sqf['TELNRS1']) : '-'; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label"><i class="fa-solid fa-coins"></i>Brut Toplam</div>
                        <div class="val"><?php echo paraformat($sqf['GROSSTOTAL']) . ' ' . $mdoviz; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label"><i class="fa-solid fa-tag"></i>Iskonto</div>
                        <div class="val"><?php echo paraformat($sqf['TOTALDISCOUNTS']) . ' ' . $mdoviz; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label"><i class="fa-solid fa-calculator"></i>Net Toplam</div>
                        <div class="val"><?php echo paraformat($sqf['NETTOTAL']) . ' ' . $mdoviz; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label"><i class="fa-solid fa-percent"></i>KDV Tutari</div>
                        <div class="val"><?php echo paraformat($sqf['TOTALVAT']) . ' ' . $mdoviz; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label"><i class="fa-solid fa-money-bill-trend-up"></i>Genel Toplam</div>
                        <div class="val strong"><?php echo paraformat($genelToplam) . ' ' . $mdoviz; ?></div>
                    </div>
                    <?php if ($sqlbakiye): ?>
                        <div class="info-item">
                            <div class="label"><i class="fa-solid fa-scale-balanced"></i>Son Bakiye</div>
                            <div class="val"><?php echo paraformat($sqlbakiye['BAKIYE']) . ' ' . $mdoviz; ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="glass-card">
                <div class="section-title">
                    <i class="fa-solid fa-list-check"></i> Hizmet Satirlari
                </div>
                <table class="hizmet-table">
                    <thead>
                        <tr>
                            <th>Kodu</th>
                            <th>Aciklama</th>
                            <th class="num center">Miktar</th>
                            <th class="num">Fiyat</th>
                            <th class="num">Toplam</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($hizmetler) > 0): ?>
                            <?php foreach ($hizmetler as $srv): ?>
                                <tr>
                                    <td data-label="Kodu" class="code"><?php echo htmlspecialchars((string) $srv['SRV_CODE']); ?></td>
                                    <td data-label="Aciklama" class="name"><?php echo htmlspecialchars((string) $srv['SRV_NAME']); ?></td>
                                    <td data-label="Miktar" class="num center"><?php echo kusuratsifir($srv['AMOUNT']); ?></td>
                                    <td data-label="Fiyat" class="num"><?php echo paraformat($srv['PRICE']) . ' ' . $mdoviz; ?></td>
                                    <td data-label="Toplam" class="num"><?php echo paraformat($srv['TOTAL']) . ' ' . $mdoviz; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="empty no-label">
                                    Bu faturaya bagli hizmet satiri bulunamadi.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>

    </main>
</body>

</html>
