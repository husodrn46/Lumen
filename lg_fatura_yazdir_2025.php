<?php
declare(strict_types=1);

include_once(__DIR__ . "/ayr.php");
require_once __DIR__ . '/kontrol.php';
include_once(__DIR__ . "/log_ip.php");

// 2025 dönemi için eski tabloları kullan
$firmadonem = $eskifirmadonem;

// Parametreleri al
$CARIID = isset($_GET['cari_id']) ? (int)$_GET['cari_id'] : 0;
$REF = isset($_GET['REF']) ? (int)$_GET['REF'] : 0;

if ($CARIID <= 0 || $REF <= 0) {
    exit;
}
function paraformat(float|int|string|null $kusurat): string
{
    global $parakusurat;
    if ($kusurat === "" || is_null($kusurat)) {
        $kusurat = 0.0;
    }
    return number_format((float)$kusurat, $parakusurat, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Satış Siparişleri (2025)</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f5f5f5;
            color: #1a1a1a;
            padding: 30px 15px;
            line-height: 1.6;
        }

        .button-group {
            max-width: 1100px;
            margin: 0 auto 20px;
            display: flex;
            gap: 10px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 500;
            color: #1a1a1a;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn:hover {
            border-color: #1a1a1a;
            background-color: #fafafa;
        }

        .btn.back {
            color: #666;
        }

        .page-container {
            max-width: 1100px;
            margin: 0 auto;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
        }

        .header {
            padding: 40px 50px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .header img {
            max-height: 50px;
        }

        .header .title {
            font-size: 24px;
            font-weight: 600;
            color: #1a1a1a;
            letter-spacing: -0.3px;
        }

        .donem-badge {
            background-color: #fef3c7;
            color: #92400e;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 600;
        }

        .content-section {
            padding: 50px;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px 50px;
            margin-bottom: 50px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .info-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #666;
        }

        .info-value {
            font-size: 16px;
            font-weight: 500;
            color: #1a1a1a;
        }

        /* Tablo */
        .table-container {
            margin: 40px 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .details-table th {
            background-color: #fafafa;
            padding: 14px 16px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #666;
            border-bottom: 1px solid #e0e0e0;
            border-top: 1px solid #e0e0e0;
        }

        .details-table td {
            padding: 16px;
            font-size: 14px;
            border-bottom: 1px solid #f0f0f0;
            color: #1a1a1a;
        }

        .details-table tbody tr:last-child td {
            border-bottom: 1px solid #e0e0e0;
        }

        .details-table tbody tr:hover {
            background-color: #fafafa;
        }

        .details-table .numeric {
            text-align: right;
            font-weight: 500;
        }

        .details-table .numeric-center {
            text-align: center;
            font-weight: 500;
        }

        /* Summary */
        .summary-section {
            margin-top: 50px;
            padding-top: 30px;
            border-top: 1px solid #e0e0e0;
        }

        .summary-table {
            width: 100%;
            max-width: 500px;
            margin-left: auto;
        }

        .summary-table td {
            padding: 12px 0;
            font-size: 14px;
        }

        .summary-table tr td:first-child {
            font-weight: 500;
            color: #666;
            text-align: right;
            padding-right: 30px;
        }

        .summary-table tr td:last-child {
            text-align: right;
            font-weight: 600;
            color: #1a1a1a;
        }

        .summary-table .total-row {
            border-top: 2px solid #1a1a1a;
        }

        .summary-table .total-row td {
            padding-top: 20px;
            padding-bottom: 0;
            font-size: 18px;
            font-weight: 700;
        }

        .summary-table .balance-row {
            padding-top: 20px;
        }

        .summary-table .balance-row td {
            padding-top: 20px;
            color: #666;
            font-size: 14px;
        }

        .discount-percentage {
            font-size: 12px;
            color: #999;
            font-weight: 400;
            margin-left: 4px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding: 15px 10px;
            }

            .button-group {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .header {
                padding: 30px 20px;
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .content-section {
                padding: 30px 20px;
            }

            .info-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .table-container {
                overflow-x: auto;
                margin: 30px -20px;
                padding: 0 20px;
            }

            .details-table {
                min-width: 700px;
            }

            .summary-table {
                max-width: 100%;
            }

            .summary-table tr td:first-child {
                padding-right: 15px;
            }
        }

        /* Print */
        @media print {
            body {
                background: white;
                padding: 0;
            }

            .button-group {
                display: none;
            }

            .page-container {
                border: none;
                border-radius: 0;
            }

            .header {
                padding: 30px;
            }

            .content-section {
                padding: 30px;
            }
        }
    </style>
</head>

<body>
    <!-- Butonlar -->
    <div class="button-group">
        <a href="lg_hareket.php?cariid=<?php echo (int)$CARIID; ?>" class="btn back">
            ← Geri Dön
        </a>
        <button class="btn" onclick="window.print()">
            PDF Olarak Kaydet
        </button>
    </div>

    <div class="page-container">
        <div class="header">
            <div class="header-left">
                <img src="logo.png" alt="Logo" onerror="this.style.display='none'">
                <div class="title">Satış Siparişleri</div>
            </div>
            <span class="donem-badge">2025 Dönemi</span>
        </div>

        <div class="content-section">

        <?php
        $mdoviz = "₺";

        // Fatura bilgilerini al (2025 dönemi tablosundan)
        $stmtFatura = $dbh->prepare("
            SELECT C.DEFINITION_, C.CITY, C.TELNRS1,
                   F.FICHENO, F.DATE_, F.NETTOTAL,
                   F.GROSSTOTAL, F.TOTALDISCOUNTS, F.TOTALVAT
            FROM {$firmadonem}INVOICE AS F
            LEFT JOIN {$firma}CLCARD AS C
              ON C.LOGICALREF = F.CLIENTREF
            WHERE F.LOGICALREF = :ref
        ");
        $stmtFatura->execute([':ref' => $REF]);
        $sqf = $stmtFatura->fetch(PDO::FETCH_ASSOC);

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
            echo "<p>Fatura bilgileri bulunamadı.</p>"; exit;
        }
        ?>

        <!-- Firma / Belge Bilgileri -->
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Firma Adı</div>
                <div class="info-value"><?php echo htmlspecialchars(tr($sqf['DEFINITION_'])); ?></div>
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

        <!-- Ürün Detayları -->
        <div class="table-container">
            <table class="details-table">
                <thead>
                    <tr>
                        <th>Kodu</th>
                        <th>Açıklama</th>
                        <th>Koli İçi</th>
                        <th>Koli</th>
                        <th class="numeric-center">Miktar</th>
                        <th class="numeric">Fiyat</th>
                        <th class="numeric">N. Fiyat</th>
                        <th class="numeric">Toplam</th>
                    </tr>
                </thead>
                    <tbody>
                    <?php
                    $stmtLines = $dbh->prepare("
                        SELECT
                          SH.AMOUNT, SH.PRICE, SH.TOTAL, SH.DISTDISC,
                          S.CODE, S.NAME,
                          COALESCE(ui.koli_ici,1) AS koli_ici,
                          CASE WHEN COALESCE(ui.koli_ici,1)=0 THEN 0
                               ELSE SH.AMOUNT/ui.koli_ici END AS koli_adedi
                        FROM {$firmadonem}STLINE AS SH
                        LEFT JOIN {$firma}ITEMS AS S
                          ON S.LOGICALREF = SH.STOCKREF
                        LEFT JOIN (
                          SELECT IA.ITEMREF, MAX(IA.CONVFACT2) AS koli_ici
                          FROM {$firma}ITMUNITA IA
                          INNER JOIN {$firma}ITEMS IT ON IA.ITEMREF = IT.LOGICALREF
                          INNER JOIN {$firma}UNITSETL UL ON IA.UNITLINEREF = UL.LOGICALREF
                          WHERE UL.UNITSETREF = IT.UNITSETREF
                          GROUP BY IA.ITEMREF
                        ) AS ui
                          ON ui.ITEMREF = S.LOGICALREF
                        WHERE SH.INVOICEREF = :ref
                          AND SH.LINETYPE=0
                          AND S.ACTIVE=0
                    ");
                    $stmtLines->execute([':ref' => $REF]);
                    $toplam_koli = 0;
                    while ($rowx = $stmtLines->fetch(PDO::FETCH_ASSOC)) {
                        $net_fiyat = $rowx['AMOUNT']
                          ? ($rowx['TOTAL'] - $rowx['DISTDISC']) / $rowx['AMOUNT']
                          : 0;
                        $toplam_koli += $rowx['koli_adedi'];
                        echo '<tr>
                                <td>'.htmlspecialchars((string) $rowx['CODE']).'</td>
                                <td>'.htmlspecialchars(tr($rowx['NAME'])).'</td>
                                <td class="numeric-center">'.kusuratsifir($rowx['koli_ici']).'</td>
                                <td class="numeric-center">'.kusuratsifir($rowx['koli_adedi']).'</td>
                                <td class="numeric-center">'.kusuratsifir($rowx['AMOUNT']).'</td>
                                <td class="numeric">'.paraformat($rowx['PRICE']).' '.$mdoviz.'</td>
                                <td class="numeric">'.paraformat($net_fiyat).' '.$mdoviz.'</td>
                                <td class="numeric">'.paraformat($rowx['TOTAL']).' '.$mdoviz.'</td>
                            </tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- Özet -->
        <div class="summary-section">
            <table class="summary-table">
                <tr>
                    <td>Toplam Koli Adedi:</td>
                    <td><?php echo kusuratsifir($toplam_koli); ?></td>
                </tr>
                <tr>
                    <td>Brüt Toplam:</td>
                    <td><?php echo paraformat($sqf['GROSSTOTAL']).' '.$mdoviz; ?></td>
                </tr>
                <tr>
                    <td>İskonto Tutarı
                        <span class="discount-percentage">
                            (<?php
                                $isk = $sqf['GROSSTOTAL']
                                  ? ($sqf['TOTALDISCOUNTS']/$sqf['GROSSTOTAL'])*100
                                  : 0;
                                echo number_format($isk,2).'%';
                            ?>)
                        </span>
                    </td>
                    <td><?php echo paraformat($sqf['TOTALDISCOUNTS']).' '.$mdoviz; ?></td>
                </tr>
                <tr>
                    <td>Net Toplam:</td>
                    <td><?php echo paraformat($sqf['GROSSTOTAL'] - $sqf['TOTALDISCOUNTS']).' '.$mdoviz; ?></td>
                </tr>
                <tr>
                    <td>KDV Tutarı:</td>
                    <td><?php echo paraformat($sqf['TOTALVAT']).' '.$mdoviz; ?></td>
                </tr>
                <tr class="total-row">
                    <td>Genel Toplam:</td>
                    <td>
                        <?php echo paraformat($sqf['NETTOTAL']).' '.$mdoviz; ?>
                    </td>
                </tr>
                <?php if ($sqlbakiye): ?>
                <tr class="balance-row">
                    <td>Son Bakiye (2025):</td>
                    <td>
                        <?php echo paraformat($sqlbakiye['BAKIYE']).' '.$mdoviz; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        </div><!-- .content-section -->
    </div><!-- .page-container -->
</body>

</html>
