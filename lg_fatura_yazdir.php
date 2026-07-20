<?php
declare(strict_types=1);

include_once(__DIR__ . "/ayr.php");
require_once __DIR__ . '/kontrol.php';
include_once(__DIR__ . "/log_ip.php");

// Bu sayfa sadece aktif dönem (2026) için kullanılır
// Eski dönem (2025) fişleri için lg_fatura_yazdir_2025.php kullanılır

// URL parametrelerini session'a aktar ve temiz URL'ye yönlendir
migrateUrlToSession(['cari_id', 'REF', 'trcode', 'islem']);

// Session bazlı parametre sistemi
$CARIID = getPageParamInt('cari_id');
$REF = getPageParamInt('REF');

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
<?php
// Fatura bilgilerini al
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

// Cari bakiye
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

$mdoviz = "₺";
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Fatura Yazdir</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (file_exists(__DIR__ . '/pwa-header.php')) { include_once __DIR__ . '/pwa-header.php'; } ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
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
            --sky: #0284c7;
            --sky-soft: #eff6ff;
            --indigo: #4f46e5;
            --indigo-soft: #eef2ff;
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
            border-bottom: 1px solid rgba(2, 132, 199, 0.18);
            box-shadow: 0 2px 8px rgba(2, 132, 199, 0.04);
        }
        .header-inner {
            max-width: 900px; margin: 0 auto;
            padding: 12px 22px;
            display: flex; align-items: center; gap: 12px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 34px; height: 34px; border-radius: 10px;
            color: var(--text-2); text-decoration: none;
            transition: all 0.2s ease;
        }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--sky); }
        .header-divider { width: 1px; height: 22px; background: var(--border); }
        .header-title {
            font-size: 15px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 7px;
            flex: 1 1 auto;
        }
        .header-title i { color: var(--sky); font-size: 14px; }
        .btn-print {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 14px; background: #ef4444; color: #fff;
            border: none; border-radius: 9px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12px; font-weight: 700;
            cursor: pointer; transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
        }
        .btn-print:hover { background: var(--red); transform: translateY(-1px); }

        main {
            max-width: 900px;
            margin: 24px auto;
            padding: 0 22px 50px;
        }

        /* Glass-card doküman */
        .document {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(2, 132, 199, 0.18);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            padding: 36px 40px;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .doc-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--sky);
            margin-bottom: 26px;
            gap: 20px;
        }
        .doc-title {
            font-size: 26px;
            font-weight: 800;
            color: var(--sky);
            letter-spacing: 0.6px;
            line-height: 1.1;
            margin: 0;
        }
        .doc-subtitle {
            font-size: 13px;
            color: var(--text-2);
            margin-top: 4px;
            font-weight: 500;
        }
        .doc-meta { text-align: right; }
        .doc-meta-row {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-bottom: 4px;
            align-items: baseline;
        }
        .doc-meta-row:last-child { margin-bottom: 0; }
        .doc-meta-row .lbl {
            color: var(--text-2);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-size: 10px;
        }
        .doc-meta-row .val {
            color: var(--text-1);
            font-weight: 700;
            font-size: 13px;
            font-variant-numeric: tabular-nums;
        }

        .doc-section { margin-bottom: 24px; }
        .doc-section-title {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--sky);
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid rgba(2, 132, 199, 0.2);
        }

        .customer-block .firma {
            font-size: 17px;
            font-weight: 700;
            color: var(--text-1);
            margin-bottom: 8px;
        }
        .customer-block .fields {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 6px 24px;
            font-size: 12.5px;
        }
        .customer-block .field .lbl {
            color: var(--text-2);
            font-weight: 500;
            margin-right: 6px;
        }
        .customer-block .field .val {
            color: var(--text-1);
            font-weight: 600;
        }

        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .lines-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
            min-width: 680px;
        }
        .lines-table thead th {
            padding: 10px 8px;
            font-size: 10px;
            font-weight: 700;
            color: var(--text-1);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            text-align: left;
            border-bottom: 2px solid var(--sky);
            border-top: 1px solid var(--border);
            background: var(--sky-soft);
            white-space: nowrap;
        }
        .lines-table tbody td {
            padding: 8px 8px;
            color: var(--text-1);
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        .lines-table tbody tr:last-child td { border-bottom: 1px solid var(--sky); }
        .lines-table tbody tr:hover { background: var(--sky-soft); }
        .cell-code { font-weight: 600; color: var(--sky); font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 11.5px; white-space: nowrap; }
        .cell-name { min-width: 200px; }
        .cell-qty { text-align: center; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .cell-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .cell-total { text-align: right; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; color: var(--text-1); }

        .doc-summary {
            margin-top: 22px;
            display: flex;
            justify-content: flex-end;
        }
        .doc-summary-box {
            width: 100%;
            max-width: 400px;
        }
        .doc-summary-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            padding: 7px 0;
            font-size: 13px;
        }
        .doc-summary-row .s-lbl { color: var(--text-2); font-weight: 500; }
        .doc-summary-row .s-val { color: var(--text-1); font-weight: 600; font-variant-numeric: tabular-nums; }
        .doc-summary-row .s-pct {
            font-size: 11px;
            color: var(--text-3);
            margin-left: 5px;
            font-weight: 400;
        }
        .doc-summary-row.total {
            margin-top: 8px;
            padding: 12px 0;
            border-top: 2px solid var(--sky);
            border-bottom: 4px double var(--sky);
            font-size: 14px;
        }
        .doc-summary-row.total .s-lbl {
            color: var(--sky);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .doc-summary-row.total .s-val {
            color: var(--sky);
            font-weight: 800;
            font-size: 18px;
        }
        .doc-summary-row.balance {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dashed var(--border);
            font-size: 12px;
        }
        .doc-summary-row.balance .s-lbl,
        .doc-summary-row.balance .s-val {
            color: var(--text-2);
            font-weight: 500;
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        @media (max-width: 767px) {
            .header-inner { padding: 10px 14px; gap: 10px; }
            .header-title { font-size: 13px; }
            .btn-print { padding: 7px 12px; font-size: 11.5px; }

            main { margin: 14px auto; padding: 0 12px 40px; }
            .document { padding: 22px 20px; border-radius: 14px; }

            .doc-title { font-size: 22px; }
            .customer-block .fields { grid-template-columns: 1fr; }
            .doc-summary-box { max-width: 100%; }
        }

        @media (max-width: 600px) {
            .doc-head {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            .doc-meta { text-align: left; width: 100%; }
            .doc-meta-row { justify-content: flex-start; gap: 8px; }
        }

        @page { size: A4; margin: 12mm 10mm; }
        @media print {
            body {
                background: #fff;
                font-size: 11px;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .top-header, .btn-print, .header-back { display: none !important; }
            main { margin: 0; padding: 0; max-width: none; }
            .document {
                border: 1px solid #999;
                background: #fff;
                box-shadow: none;
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
                padding: 0;
                animation: none;
                border-radius: 0;
            }
            .doc-head { margin-bottom: 14px; padding-bottom: 10px; }
            .doc-title { font-size: 20px; }
            .doc-subtitle { font-size: 11px; }
            .doc-section { margin-bottom: 14px; }
            .doc-section-title { margin-bottom: 6px; font-size: 9px; }
            .customer-block .firma { font-size: 14px; margin-bottom: 5px; }
            .customer-block .fields { font-size: 10.5px; gap: 3px 16px; grid-template-columns: repeat(2, 1fr); }
            .lines-table { font-size: 10.5px; min-width: 0; }
            .lines-table thead { display: table-header-group; }
            .lines-table thead th {
                font-size: 8.5px;
                padding: 5px 6px;
                background: var(--sky-soft) !important;
            }
            .lines-table tbody td {
                padding: 4px 6px;
                font-size: 10px;
            }
            .lines-table tbody tr { page-break-inside: avoid; page-break-after: auto; }
            .doc-summary { margin-top: 12px; page-break-inside: avoid; }
            .doc-summary-box { max-width: 320px; }
            .doc-summary-row { font-size: 10.5px; padding: 4px 0; }
            .doc-summary-row.total { padding: 8px 0; }
            .doc-summary-row.total .s-val { font-size: 14px; }
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
                <i class="fa-solid fa-file-invoice"></i>Fatura Yazdir
            </span>
            <button onclick="window.print()" class="btn-print" type="button">
                <i class="fa-solid fa-print"></i> PDF Kaydet
            </button>
        </div>
    </header>

    <main>
        <div class="document">
            <div class="doc-head">
                <div>
                    <h1 class="doc-title">FATURA</h1>
                    <div class="doc-subtitle">Satis Faturasi</div>
                </div>
                <div class="doc-meta">
                    <div class="doc-meta-row">
                        <span class="lbl">Belge No</span>
                        <span class="val"><?php echo htmlspecialchars((string) $sqf['FICHENO']); ?></span>
                    </div>
                    <div class="doc-meta-row">
                        <span class="lbl">Tarih</span>
                        <span class="val"><?php echo tarihcevir($sqf['DATE_']); ?></span>
                    </div>
                </div>
            </div>

            <div class="doc-section customer-block">
                <div class="doc-section-title">Musteri</div>
                <div class="firma"><?php echo htmlspecialchars(tr($sqf['DEFINITION_'] ?? '')); ?></div>
                <div class="fields">
                    <div class="field">
                        <span class="lbl">Sehir:</span>
                        <span class="val"><?php echo !empty($sqf['CITY'] ?? '') ? htmlspecialchars((string) $sqf['CITY']) : '-'; ?></span>
                    </div>
                    <div class="field">
                        <span class="lbl">Telefon:</span>
                        <span class="val"><?php echo !empty($sqf['TELNRS1']) ? htmlspecialchars((string) $sqf['TELNRS1']) : '-'; ?></span>
                    </div>
                </div>
            </div>

            <div class="doc-section">
                <div class="doc-section-title">Fatura Satirlari</div>
                <div class="table-wrap">
                    <table class="lines-table">
                        <thead>
                            <tr>
                                <th>Kodu</th>
                                <th>Aciklama</th>
                                <th class="cell-qty">Koli Ici</th>
                                <th class="cell-qty">Koli</th>
                                <th class="cell-qty">Miktar</th>
                                <th class="cell-num">Fiyat</th>
                                <th class="cell-num">N. Fiyat</th>
                                <th class="cell-num">Toplam</th>
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
                            $amount = (float) $rowx['AMOUNT'];
                            $net_fiyat = $amount > 0
                              ? ((float)$rowx['TOTAL'] - (float)$rowx['DISTDISC']) / $amount
                              : 0;
                            $toplam_koli += $rowx['koli_adedi'];
                            echo '<tr>
                                    <td class="cell-code">'.htmlspecialchars((string) $rowx['CODE']).'</td>
                                    <td class="cell-name">'.htmlspecialchars(tr($rowx['NAME'])).'</td>
                                    <td class="cell-qty">'.kusuratsifir($rowx['koli_ici']).'</td>
                                    <td class="cell-qty">'.kusuratsifir($rowx['koli_adedi']).'</td>
                                    <td class="cell-qty">'.kusuratsifir($rowx['AMOUNT']).'</td>
                                    <td class="cell-num">'.paraformat($rowx['PRICE']).' '.$mdoviz.'</td>
                                    <td class="cell-num">'.paraformat($net_fiyat).' '.$mdoviz.'</td>
                                    <td class="cell-total">'.paraformat($rowx['TOTAL']).' '.$mdoviz.'</td>
                                </tr>';
                        }
                        ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="doc-summary">
                <div class="doc-summary-box">
                    <div class="doc-summary-row">
                        <span class="s-lbl">Toplam Koli Adedi</span>
                        <span class="s-val"><?php echo kusuratsifir($toplam_koli); ?></span>
                    </div>
                    <div class="doc-summary-row">
                        <span class="s-lbl">Brut Toplam</span>
                        <span class="s-val"><?php echo paraformat($sqf['GROSSTOTAL']).' '.$mdoviz; ?></span>
                    </div>
                    <div class="doc-summary-row">
                        <span class="s-lbl">Iskonto Tutari<span class="s-pct">(<?php
                            $gross = (float) $sqf['GROSSTOTAL'];
                            $isk = $gross > 0
                              ? ((float)$sqf['TOTALDISCOUNTS'] / $gross) * 100
                              : 0;
                            echo number_format($isk,2).'%';
                        ?>)</span></span>
                        <span class="s-val"><?php echo paraformat($sqf['TOTALDISCOUNTS']).' '.$mdoviz; ?></span>
                    </div>
                    <div class="doc-summary-row">
                        <span class="s-lbl">Net Toplam</span>
                        <span class="s-val"><?php echo paraformat($sqf['GROSSTOTAL'] - $sqf['TOTALDISCOUNTS']).' '.$mdoviz; ?></span>
                    </div>
                    <div class="doc-summary-row">
                        <span class="s-lbl">KDV Tutari</span>
                        <span class="s-val"><?php echo paraformat($sqf['TOTALVAT']).' '.$mdoviz; ?></span>
                    </div>
                    <div class="doc-summary-row total">
                        <span class="s-lbl">Genel Toplam</span>
                        <span class="s-val"><?php echo paraformat($sqf['NETTOTAL']).' '.$mdoviz; ?></span>
                    </div>
                    <?php if ($sqlbakiye): ?>
                    <div class="doc-summary-row balance">
                        <span class="s-lbl">Son Bakiye</span>
                        <span class="s-val"><?php echo paraformat($sqlbakiye['BAKIYE']).' '.$mdoviz; ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

</body>
</html>
