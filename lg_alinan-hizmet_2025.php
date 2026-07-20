<?php
declare(strict_types=1);

include_once(__DIR__ . "/ayr.php");
require_once __DIR__ . '/kontrol.php';
include_once(__DIR__ . "/log_ip.php");

// 2025 dönemi için eski tabloları kullan
$firmadonem = $eskifirmadonem;
$donem2025 = $eskifirmadonem;

// Parametreleri al
if (isset($_GET['cari_id'], $_GET['REF'])) {
    $CARIID = (int)$_GET['cari_id'];
    $REF    = (int)$_GET['REF'];
} else {
    exit;
}

// Faturanın türünü CLFLINE'dan çek
$stmtClSatir = $dbh->prepare("
    SELECT TOP 1 TRCODE, SOURCEFREF
    FROM {$firmadonem}CLFLINE
    WHERE CLIENTREF  = :cariid
      AND SOURCEFREF = :ref
      AND CANCELLED  = 0
");
$stmtClSatir->execute([':cariid' => $CARIID, ':ref' => $REF]);
$clSatir = $stmtClSatir->fetch(PDO::FETCH_ASSOC);

// Başlığı belirle (TRCODE: 28,34=Alınan, 29,39=Verilen Hizmet Faturası)
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
    <title><?php echo htmlspecialchars($fatura_baslik); ?> (2025)</title>
    <style>
        :root {
            --bg: #f3f4f6;
            --card: #ffffff;
            --primary: #0f766e;
            --primary-hover: #115e57;
            --text: #0f172a;
            --muted: #6b7280;
            --border: #e5e7eb;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 50;
            background: #fff;
            border-bottom: 1px solid var(--border);
            padding: .75rem 1.25rem;
            display: flex;
            gap: .75rem;
            align-items: center;
            justify-content: space-between;
        }

        .topbar-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-title {
            font-weight: 600;
        }

        .donem-badge {
            background-color: #fef3c7;
            color: #92400e;
            padding: 3px 10px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
        }

        .button-group {
            display: flex;
            gap: .5rem;
        }

        .btn {
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: .5rem;
            padding: .45rem .9rem;
            font-size: .85rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: .25rem;
        }

        .btn.back {
            background: #e2e8f0;
            color: #0f172a;
        }

        .btn:hover {
            background: var(--primary-hover);
            color: #fff;
        }

        .btn.back:hover {
            background: #d1d9e6;
        }

        .page-container {
            max-width: 1100px;
            margin: 1.25rem auto 2.5rem;
            padding: 0 1rem;
        }

        .content-section {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .info-grid {
            display: grid;
            gap: .75rem;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: .75rem;
            padding: 1rem 1.25rem;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: .25rem;
        }

        .info-label {
            font-size: .7rem;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--muted);
        }

        .info-value {
            font-weight: 500;
        }

        .table-container {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: .75rem;
            overflow: hidden;
        }

        .details-table {
            width: 100%;
            border-collapse: collapse;
        }

        .details-table thead {
            background: #f8fafc;
        }

        .details-table th,
        .details-table td {
            padding: .55rem .65rem;
            font-size: .78rem;
            border-bottom: 1px solid #edf2f7;
        }

        .details-table th {
            text-align: left;
            font-weight: 600;
            color: #0f172a;
            white-space: nowrap;
        }

        .details-table tbody tr:nth-child(even) {
            background: #fafafa;
        }

        .details-table tbody tr:hover {
            background: #eef2ff;
        }

        .numeric {
            text-align: right;
        }

        .numeric-center {
            text-align: center;
        }

        .summary-section {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: .75rem;
            padding: 1rem 1.25rem;
            max-width: 380px;
            margin-left: auto;
        }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
        }

        .summary-table td {
            padding: .35rem 0;
            font-size: .78rem;
        }

        .summary-table td:last-child {
            text-align: right;
            font-weight: 500;
        }

        .summary-table .total-row td {
            border-top: 1px solid var(--border);
            padding-top: .6rem;
            font-weight: 600;
        }

        .summary-table .balance-row td {
            border-top: 1px solid var(--border);
            padding-top: .6rem;
            color: #0f766e;
            font-weight: 600;
        }

        h3 {
            margin: .85rem .85rem .25rem;
            font-size: .85rem;
            font-weight: 600;
        }

        @media print {

            .topbar,
            .btn,
            .summary-section {
                display: none !important;
            }

            body {
                background: #fff;
            }

            .page-container {
                max-width: 100%;
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>

<body>

    <div class="topbar">
        <div class="topbar-title">
            <h1 class="page-title"><?php echo htmlspecialchars($fatura_baslik); ?></h1>
            <span class="donem-badge">2025 Dönemi</span>
        </div>
        <div class="button-group">
            <a href="lg_hareket.php?cariid=<?php echo (int)$CARIID; ?>" class="btn back">← Geri Dön</a>
            <button class="btn" onclick="window.print()">PDF Olarak Kaydet</button>
        </div>
    </div>

    <div class="page-container">
        <div class="content-section">
            <?php
            $mdoviz = "₺";

            // Fatura bilgilerini al
            $sqf = null;

            // Yöntem 1: SOURCEFREF = INVOICE.LOGICALREF olarak dene
            $stmtFatura1 = $dbh->prepare("
                SELECT C.DEFINITION_, C.CITY, C.TELNRS1,
                       F.FICHENO, F.DATE_, F.NETTOTAL,
                       F.GROSSTOTAL, F.TOTALDISCOUNTS, F.TOTALVAT,
                       F.LOGICALREF AS INVOICE_REF
                FROM {$firmadonem}INVOICE AS F
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
                    FROM {$firmadonem}CLFLINE AS CL
                    INNER JOIN {$firmadonem}STFICHE AS ST ON ST.LOGICALREF = CL.SOURCEFREF
                    INNER JOIN {$firmadonem}INVOICE AS F ON F.LOGICALREF = ST.INVOICEREF
                    LEFT JOIN {$firma}CLCARD AS C ON C.LOGICALREF = F.CLIENTREF
                    WHERE CL.CLIENTREF = :cariid
                      AND CL.SOURCEFREF = :ref
                      AND CL.CANCELLED = 0
                ");
                $stmtFatura2->execute([':cariid' => $CARIID, ':ref' => $REF]);
                $sqf = $stmtFatura2->fetch(PDO::FETCH_ASSOC);
            }

            // Cari bakiye (2025 dönemi)
            $stmtBakiye = $dbh->prepare("
                SELECT
                  SUM((1 - CLFLINE.SIGN) * CLFLINE.AMOUNT)
                - SUM(CLFLINE.SIGN * CLFLINE.AMOUNT) AS BAKIYE
                FROM {$firma}CLCARD AS C
                LEFT JOIN {$firmadonem}CLFLINE AS CLFLINE
                  ON C.LOGICALREF = CLFLINE.CLIENTREF
                 AND CLFLINE.CANCELLED = 0
                WHERE C.LOGICALREF = :cariid
            ");
            $stmtBakiye->execute([':cariid' => $CARIID]);
            $sqlbakiye = $stmtBakiye->fetch(PDO::FETCH_ASSOC);

            if (!$sqf) {
                echo "<div style='padding:20px; background:#fee; border:1px solid #c00; border-radius:8px; margin:20px;'>";
                echo "<p style='color:red; font-weight:bold;'>Fatura bilgileri bulunamadı.</p>";
                echo "<p style='color:#666; font-size:14px;'>Cari ID: " . htmlspecialchars((string)$CARIID) . "</p>";
                echo "<p style='color:#666; font-size:14px;'>REF (SOURCEFREF): " . htmlspecialchars((string)$REF) . "</p>";
                echo "<a href='javascript:history.back()' style='display:inline-block; margin-top:10px; padding:8px 16px; background:#c00; color:white; text-decoration:none; border-radius:4px;'>← Geri Dön</a>";
                echo "</div>";
                exit;
            }
            ?>

            <!-- Firma / Belge Bilgileri -->
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Firma Adı</div>
                    <div class="info-value"><?php echo htmlspecialchars((string) $sqf['DEFINITION_']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Tarih</div>
                    <div class="info-value"><?php echo tarihcevir($sqf['DATE_']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Şehir</div>
                    <div class="info-value"><?php echo htmlspecialchars((string) $sqf['CITY']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Belge No</div>
                    <div class="info-value"><?php echo htmlspecialchars((string) $sqf['FICHENO']); ?></div>
                </div>
                <?php if (!empty($sqf['TELNRS1'])): ?>
                    <div class="info-item">
                        <div class="info-label">Telefon</div>
                        <div class="info-value"><?php echo htmlspecialchars((string) $sqf['TELNRS1']); ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <?php
            // Hizmet satırları
            $invoiceRef = isset($sqf['INVOICE_REF']) ? (int)$sqf['INVOICE_REF'] : $REF;
            $stmtServ = $dbh->prepare("
                SELECT
                    S.CODE        AS SRV_CODE,
                    S.DEFINITION_ AS SRV_NAME,
                    L.AMOUNT      AS AMOUNT,
                    L.PRICE       AS PRICE,
                    L.TOTAL       AS TOTAL
                FROM {$firmadonem}STLINE AS L
                INNER JOIN {$firma}SRVCARD AS S
                    ON S.LOGICALREF = L.STOCKREF
                WHERE L.INVOICEREF = :ref
                  AND L.LINETYPE = 4
            ");
            $stmtServ->execute([':ref' => $invoiceRef]);

            $hizmetler = $stmtServ ? $stmtServ->fetchAll(PDO::FETCH_ASSOC) : [];
            ?>

            <div class="table-container">
                <table class="details-table">
                    <thead>
                        <tr>
                            <th>Kodu</th>
                            <th>Açıklama</th>
                            <th class="numeric-center">Miktar</th>
                            <th class="numeric">Fiyat</th>
                            <th class="numeric">Toplam</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($hizmetler) > 0): ?>
                            <?php foreach ($hizmetler as $srv): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string) $srv['SRV_CODE']); ?></td>
                                    <td><?php echo htmlspecialchars((string) $srv['SRV_NAME']); ?></td>
                                    <td class="numeric-center"><?php echo kusuratsifir($srv['AMOUNT']); ?></td>
                                    <td class="numeric"><?php echo paraformat($srv['PRICE']) . ' ' . $mdoviz; ?></td>
                                    <td class="numeric"><?php echo paraformat($srv['TOTAL']) . ' ' . $mdoviz; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center; color:#6b7280; padding:.7rem 0;">
                                    Bu faturaya bağlı hizmet satırı bulunamadı.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>


            <!-- Özet -->
            <div class="summary-section">
                <table class="summary-table">
                    <tr>
                        <td>Brüt Toplam:</td>
                        <td><?php echo paraformat($sqf['GROSSTOTAL']) . ' ' . $mdoviz; ?></td>
                    </tr>
                    <tr>
                        <td>İskonto Tutarı</td>
                        <td><?php echo paraformat($sqf['TOTALDISCOUNTS']) . ' ' . $mdoviz; ?></td>
                    </tr>
                    <tr>
                        <td>Net Toplam:</td>
                        <td><?php echo paraformat($sqf['GROSSTOTAL'] - $sqf['TOTALDISCOUNTS']) . ' ' . $mdoviz; ?></td>
                    </tr>
                    <tr>
                        <td>KDV Tutarı:</td>
                        <td><?php echo paraformat($sqf['TOTALVAT']) . ' ' . $mdoviz; ?></td>
                    </tr>
                    <tr class="total-row">
                        <td>Genel Toplam:</td>
                        <td><?php echo paraformat($sqf['NETTOTAL']) . ' ' . $mdoviz; ?></td>
                    </tr>
                    <?php if ($sqlbakiye): ?>
                        <tr class="balance-row">
                            <td>Son Bakiye (2025):</td>
                            <td><?php echo paraformat($sqlbakiye['BAKIYE']) . ' ' . $mdoviz; ?></td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>

        </div><!-- .content-section -->
    </div><!-- .page-container -->
</body>

</html>
