<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
include(__DIR__ . "/../kontrol.php");

// Yetki kontrolü - M17
if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// Yıl seçimi
$selected_year = isset($_GET['yil']) ? intval($_GET['yil']) : 2024;
$start_date = $selected_year . '-01-01';
$end_date = $selected_year . '-12-31';

// ==================== SQL SORGULARI ====================

// 1. Aylık Nakit Giriş (Tahsilat)
$sql_giris = "
SELECT
    MONTH(DATE_) AS AY,
    SUM(AMOUNT) AS TAHSILAT
FROM {$firmadonem}CLFLINE
WHERE TRCODE IN (1, 4, 20, 61, 62, 70)
AND SIGN = 1
AND DATE_ >= :start_date
AND DATE_ <= :end_date
AND CANCELLED = 0
GROUP BY MONTH(DATE_)
ORDER BY MONTH(DATE_)
";

$stmt_giris = $dbh->prepare($sql_giris);
$stmt_giris->bindParam(':start_date', $start_date);
$stmt_giris->bindParam(':end_date', $end_date);
$stmt_giris->execute();
$giris_data = $stmt_giris->fetchAll(PDO::FETCH_ASSOC);

// 2. Aylık Nakit Çıkış (Ödeme)
$sql_cikis = "
SELECT
    MONTH(DATE_) AS AY,
    SUM(AMOUNT) AS ODEME
FROM {$firmadonem}CLFLINE
WHERE TRCODE IN (2, 3, 21, 63, 64, 72)
AND SIGN = 0
AND DATE_ >= :start_date
AND DATE_ <= :end_date
AND CANCELLED = 0
GROUP BY MONTH(DATE_)
ORDER BY MONTH(DATE_)
";

$stmt_cikis = $dbh->prepare($sql_cikis);
$stmt_cikis->bindParam(':start_date', $start_date);
$stmt_cikis->bindParam(':end_date', $end_date);
$stmt_cikis->execute();
$cikis_data = $stmt_cikis->fetchAll(PDO::FETCH_ASSOC);

// Aylık veri dizileri oluştur
$aylar = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
$aylar_giris = array_fill(1, 12, 0);
$aylar_cikis = array_fill(1, 12, 0);

foreach ($giris_data as $row) {
    $aylar_giris[$row['AY']] = floatval($row['TAHSILAT']);
}

foreach ($cikis_data as $row) {
    $aylar_cikis[$row['AY']] = floatval($row['ODEME']);
}

// Net akış ve kümülatif hesapla
$aylar_net = [];
$aylar_kumulatif = [];
$kumulatif_toplam = 0;

for ($i = 1; $i <= 12; $i++) {
    $net = $aylar_giris[$i] - $aylar_cikis[$i];
    $aylar_net[$i] = $net;
    $kumulatif_toplam += $net;
    $aylar_kumulatif[$i] = $kumulatif_toplam;
}

$toplam_giris = array_sum($aylar_giris);
$toplam_cikis = array_sum($aylar_cikis);
$net_akis = $toplam_giris - $toplam_cikis;

// Excel çıktısı
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="Nakit_Akis_Raporu_' . $selected_year . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF"; // UTF-8 BOM
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th, td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #4CAF50;
            color: white;
            font-weight: bold;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .bg-success {
            background-color: #d4edda;
        }
        .bg-danger {
            background-color: #f8d7da;
        }
        .bg-primary {
            background-color: #cce5ff;
        }
        .bg-warning {
            background-color: #fff3cd;
        }
        .total-row {
            background-color: #e9ecef;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <h1>NAKİT AKIŞ RAPORU - <?php echo $selected_year; ?></h1>
    <p>Rapor Tarihi: <?php echo date('d.m.Y H:i'); ?></p>

    <h2>ÖZET BİLGİLER</h2>
    <table>
        <tr>
            <th>Metrik</th>
            <th class="text-right">Tutar (TL)</th>
        </tr>
        <tr class="bg-success">
            <td>Toplam Nakit Giriş</td>
            <td class="text-right"><?php echo number_format($toplam_giris, 2, ',', '.'); ?></td>
        </tr>
        <tr class="bg-danger">
            <td>Toplam Nakit Çıkış</td>
            <td class="text-right"><?php echo number_format($toplam_cikis, 2, ',', '.'); ?></td>
        </tr>
        <tr class="<?php echo $net_akis >= 0 ? 'bg-primary' : 'bg-warning'; ?>">
            <td>Net Nakit Akışı</td>
            <td class="text-right"><?php echo number_format($net_akis, 2, ',', '.'); ?></td>
        </tr>
    </table>

    <br><br>

    <h2>AYLIK DETAY</h2>
    <table>
        <thead>
            <tr>
                <th>Ay</th>
                <th class="text-right">Nakit Giriş (TL)</th>
                <th class="text-right">Nakit Çıkış (TL)</th>
                <th class="text-right">Net Akış (TL)</th>
            </tr>
        </thead>
        <tbody>
            <?php for ($i = 1; $i <= 12; $i++): ?>
                <tr>
                    <td><?php echo $aylar[$i-1]; ?></td>
                    <td class="text-right"><?php echo number_format($aylar_giris[$i], 2, ',', '.'); ?></td>
                    <td class="text-right"><?php echo number_format($aylar_cikis[$i], 2, ',', '.'); ?></td>
                    <td class="text-right"><?php echo number_format($aylar_net[$i], 2, ',', '.'); ?></td>
                </tr>
            <?php endfor; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td>TOPLAM</td>
                <td class="text-right"><?php echo number_format($toplam_giris, 2, ',', '.'); ?></td>
                <td class="text-right"><?php echo number_format($toplam_cikis, 2, ',', '.'); ?></td>
                <td class="text-right"><?php echo number_format($net_akis, 2, ',', '.'); ?></td>
            </tr>
        </tfoot>
    </table>


<?php
// Vade Dağılımı
$sql_vade = "
SELECT
    VADE_ARALIGI,
    SUM(TUTAR) AS TUTAR,
    SUM(ISLEM_SAYISI) AS ISLEM_SAYISI,
    SIRA
FROM (
    SELECT
        CASE
            WHEN DATEDIFF(DAY, DATE_, GETDATE()) BETWEEN 0 AND 30 THEN '0-30 gün'
            WHEN DATEDIFF(DAY, DATE_, GETDATE()) BETWEEN 31 AND 60 THEN '31-60 gün'
            WHEN DATEDIFF(DAY, DATE_, GETDATE()) BETWEEN 61 AND 90 THEN '61-90 gün'
            ELSE '90+ gün'
        END AS VADE_ARALIGI,
        (1 - SIGN) * AMOUNT AS TUTAR,
        1 AS ISLEM_SAYISI,
        CASE
            WHEN DATEDIFF(DAY, DATE_, GETDATE()) BETWEEN 0 AND 30 THEN 1
            WHEN DATEDIFF(DAY, DATE_, GETDATE()) BETWEEN 31 AND 60 THEN 2
            WHEN DATEDIFF(DAY, DATE_, GETDATE()) BETWEEN 61 AND 90 THEN 3
            ELSE 4
        END AS SIRA
    FROM {$firmadonem}CLFLINE
    WHERE ((1 - SIGN) * AMOUNT) > 0
    AND CANCELLED = 0
) AS SubQuery
GROUP BY VADE_ARALIGI, SIRA
ORDER BY SIRA
";

$stmt_vade = $dbh->prepare($sql_vade);
$stmt_vade->execute();
$vade_data = $stmt_vade->fetchAll(PDO::FETCH_ASSOC);

if (count($vade_data) > 0):
?>
    <br><br>

    <h2>VADE BAZLI ALACAK DAĞILIMI</h2>
    <table>
        <thead>
            <tr>
                <th>Vade Aralığı</th>
                <th class="text-right">Tutar (TL)</th>
                <th class="text-right">İşlem Sayısı</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $toplam_vade = 0;
            foreach ($vade_data as $row):
                $toplam_vade += $row['TUTAR'];
            ?>
                <tr>
                    <td><?php echo htmlspecialchars((string) $row['VADE_ARALIGI']); ?></td>
                    <td class="text-right"><?php echo number_format($row['TUTAR'], 2, ',', '.'); ?></td>
                    <td class="text-right"><?php echo number_format($row['ISLEM_SAYISI'], 0); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td>TOPLAM</td>
                <td class="text-right"><?php echo number_format($toplam_vade, 2, ',', '.'); ?></td>
                <td class="text-right">-</td>
            </tr>
        </tfoot>
    </table>
<?php endif; ?>

<?php
// Çek Vade Analizi
$sql_cek_vade = "
SELECT
    VADE_DURUMU,
    SUM(TUTAR) AS TUTAR,
    COUNT(*) AS CEK_SAYISI,
    SIRA
FROM (
    SELECT
        CASE
            WHEN DATEDIFF(DAY, C.SETDATE, C.DUEDATE) <= 30 THEN '0-30 gün vade'
            WHEN DATEDIFF(DAY, C.SETDATE, C.DUEDATE) BETWEEN 31 AND 60 THEN '31-60 gün vade'
            WHEN DATEDIFF(DAY, C.SETDATE, C.DUEDATE) BETWEEN 61 AND 90 THEN '61-90 gün vade'
            WHEN DATEDIFF(DAY, C.SETDATE, C.DUEDATE) BETWEEN 91 AND 180 THEN '91-180 gün vade'
            ELSE '180+ gün vade'
        END AS VADE_DURUMU,
        C.AMOUNT AS TUTAR,
        CASE
            WHEN DATEDIFF(DAY, C.SETDATE, C.DUEDATE) <= 30 THEN 1
            WHEN DATEDIFF(DAY, C.SETDATE, C.DUEDATE) BETWEEN 31 AND 60 THEN 2
            WHEN DATEDIFF(DAY, C.SETDATE, C.DUEDATE) BETWEEN 61 AND 90 THEN 3
            WHEN DATEDIFF(DAY, C.SETDATE, C.DUEDATE) BETWEEN 91 AND 180 THEN 4
            ELSE 5
        END AS SIRA
    FROM {$firmadonem}CSCARD C
    INNER JOIN {$firmadonem}CSTRANS T ON T.CSREF = C.LOGICALREF
    WHERE C.CURRSTAT IN (1, 8, 9)
    AND C.STATUS IN (0, 1)
    AND C.DOC = 1
    AND T.TRCODE IN (1, 2, 3)
    AND YEAR(C.SETDATE) = :selected_year
) AS SubQuery
GROUP BY VADE_DURUMU, SIRA
ORDER BY SIRA
";

$stmt_cek = $dbh->prepare($sql_cek_vade);
$stmt_cek->bindParam(':selected_year', $selected_year, PDO::PARAM_INT);
$stmt_cek->execute();
$cek_vade_data = $stmt_cek->fetchAll(PDO::FETCH_ASSOC);

if (count($cek_vade_data) > 0):
?>
    <br><br>

    <h2>ÇEK VADE ANALİZİ</h2>
    <table>
        <thead>
            <tr>
                <th>Vade Süresi</th>
                <th class="text-right">Toplam Tutar (TL)</th>
                <th class="text-right">Çek Sayısı</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $toplam_cek = 0;
            $toplam_adet = 0;
            foreach ($cek_vade_data as $row):
                $toplam_cek += $row['TUTAR'];
                $toplam_adet += $row['CEK_SAYISI'];
            ?>
                <tr>
                    <td><?php echo htmlspecialchars((string) $row['VADE_DURUMU']); ?></td>
                    <td class="text-right"><?php echo number_format($row['TUTAR'], 2, ',', '.'); ?></td>
                    <td class="text-right"><?php echo number_format($row['CEK_SAYISI'], 0); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td>TOPLAM</td>
                <td class="text-right"><?php echo number_format($toplam_cek, 2, ',', '.'); ?></td>
                <td class="text-right"><?php echo number_format($toplam_adet, 0); ?></td>
            </tr>
        </tfoot>
    </table>
<?php endif; ?>

</body>
</html>
