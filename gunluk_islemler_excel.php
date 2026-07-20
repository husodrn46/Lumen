<?php
declare(strict_types=1);

include_once(__DIR__ . "/ayr.php");
include_once(__DIR__ . "/_baglanti_.inc");
require_once __DIR__ . '/kontrol.php';

// Yetki kontrolü - M13 günlük işlemler yetkisi
if (m_p_yetki($terminalkullanici, 'M13') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// Veritabanı bağlantısı
try {
    $dbh = new PDO("sqlsrv:server=" . $anamakina . ";database=" . $veritabani . ";", $kullanici, $sifre);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Veritabanı bağlantı hatası: " . $e->getMessage());
}

// Tarih filtresi
$selected_date = $_GET['tarih'] ?? date('Y-m-d');
$display_date = date('d.m.Y', strtotime((string) $selected_date));

// Excel başlıkları
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=gunluk_islemler_" . str_replace('.', '_', $display_date) . ".xls");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF"; // UTF-8 BOM

// 1. GÜNLÜK SATIŞ ÖZETİ
$sql_sales = "
    SELECT
        COUNT(DISTINCT CLIENTREF) AS MUSTERI_SAYISI,
        COUNT(*) AS ISLEM_SAYISI,
        SUM((1-SIGN)*AMOUNT) AS TOPLAM_SATIS
    FROM " . $firmadonem . "CLFLINE WITH(NOLOCK)
    WHERE CANCELLED=0
        AND TRCODE=38
        AND CAST(DATE_ AS DATE) = :tarih
";

$stmt_sales = $dbh->prepare($sql_sales);
$stmt_sales->bindParam(':tarih', $selected_date, PDO::PARAM_STR);
$stmt_sales->execute();
$sales_summary = $stmt_sales->fetch(PDO::FETCH_ASSOC);

// 2. SATIŞ DETAYLARI
$sql_sales_detail = "
    SELECT
        C.DATE_ AS TARIH,
        CL.CODE AS CARI_KODU,
        CL.DEFINITION_ AS CARI_ADI,
        (1-C.SIGN)*C.AMOUNT AS TUTAR,
        C.REPORTRATE AS KUR
    FROM " . $firmadonem . "CLFLINE C WITH(NOLOCK)
    LEFT JOIN " . $firma . "CLCARD CL ON CL.LOGICALREF = C.CLIENTREF
    WHERE C.CANCELLED=0
        AND C.TRCODE=38
        AND CAST(C.DATE_ AS DATE) = :tarih
    ORDER BY C.DATE_ DESC
";

$stmt_sales_detail = $dbh->prepare($sql_sales_detail);
$stmt_sales_detail->bindParam(':tarih', $selected_date, PDO::PARAM_STR);
$stmt_sales_detail->execute();
$sales_details = $stmt_sales_detail->fetchAll(PDO::FETCH_ASSOC);

// 3. KASA HAREKETLERİ ÖZETİ
$sql_cash_summary = "
    SELECT
        SUM(CASE WHEN l.SIGN = 0 THEN l.AMOUNT ELSE 0 END) AS GIRIS,
        SUM(CASE WHEN l.SIGN = 1 THEN l.AMOUNT ELSE 0 END) AS CIKIS,
        SUM(CASE WHEN l.SIGN = 0 THEN l.AMOUNT ELSE -l.AMOUNT END) AS NET
    FROM " . $firma . "KSCARD k WITH(NOLOCK)
    LEFT JOIN " . $firmadonem . "KSLINES l ON l.CARDREF = k.LOGICALREF
    WHERE k.ACTIVE = 0
        AND CAST(l.DATE_ AS DATE) = :tarih
";

$stmt_cash_summary = $dbh->prepare($sql_cash_summary);
$stmt_cash_summary->bindParam(':tarih', $selected_date, PDO::PARAM_STR);
$stmt_cash_summary->execute();
$cash_summary = $stmt_cash_summary->fetch(PDO::FETCH_ASSOC);

// 4. KASA HAREKETLERİ DETAY
$sql_cash_detail = "
    SELECT
        k.CODE AS KASA_KODU,
        k.NAME AS KASA_ADI,
        l.DATE_ AS TARIH,
        CASE WHEN l.SIGN = 0 THEN l.AMOUNT ELSE 0 END AS GIRIS,
        CASE WHEN l.SIGN = 1 THEN l.AMOUNT ELSE 0 END AS CIKIS,
        l.LINEEXP AS ACIKLAMA
    FROM " . $firma . "KSCARD k WITH(NOLOCK)
    LEFT JOIN " . $firmadonem . "KSLINES l ON l.CARDREF = k.LOGICALREF
    WHERE k.ACTIVE = 0
        AND CAST(l.DATE_ AS DATE) = :tarih
    ORDER BY l.DATE_ DESC
";

$stmt_cash_detail = $dbh->prepare($sql_cash_detail);
$stmt_cash_detail->bindParam(':tarih', $selected_date, PDO::PARAM_STR);
$stmt_cash_detail->execute();
$cash_details = $stmt_cash_detail->fetchAll(PDO::FETCH_ASSOC);

// 5. ALINAN ÇEKLER DETAY
$sql_checks_received_detail = "
    SELECT
        C.DUEDATE AS VADE_TARIHI,
        C.SETDATE AS TANZIM_TARIHI,
        C.AMOUNT AS TUTAR,
        C.NEWSERINO AS SERI_NO,
        C.OWING AS BORCLU,
        ISNULL(CL.DEFINITION_, '---') AS CARI,
        T.DATE_ AS KAYIT_TARIHI
    FROM " . $firmadonem . "CSCARD C WITH(NOLOCK)
    INNER JOIN " . $firmadonem . "CSTRANS T ON T.CSREF = C.LOGICALREF
    LEFT JOIN " . $firma . "CLCARD CL ON CL.LOGICALREF = T.CARDREF
    WHERE C.CURRSTAT IN(1)
        AND C.STATUS IN(0,1)
        AND C.DOC = 1
        AND CAST(T.DATE_ AS DATE) = :tarih
        AND T.TRCODE IN (1, 2, 3)
    ORDER BY C.DUEDATE
";

$stmt_checks_received_detail = $dbh->prepare($sql_checks_received_detail);
$stmt_checks_received_detail->bindParam(':tarih', $selected_date, PDO::PARAM_STR);
$stmt_checks_received_detail->execute();
$checks_received_detail = $stmt_checks_received_detail->fetchAll(PDO::FETCH_ASSOC);

// 6. VERİLEN ÇEKLER DETAY
$sql_checks_given_detail = "
    SELECT
        C.DUEDATE AS VADE_TARIHI,
        C.SETDATE AS TANZIM_TARIHI,
        C.AMOUNT AS TUTAR,
        C.NEWSERINO AS SERI_NO,
        C.OWING AS ALACAKLI,
        ISNULL(CL.DEFINITION_, '---') AS CARI,
        T.DATE_ AS KAYIT_TARIHI
    FROM " . $firmadonem . "CSCARD C WITH(NOLOCK)
    INNER JOIN " . $firmadonem . "CSTRANS T ON T.CSREF = C.LOGICALREF
    LEFT JOIN " . $firma . "CLCARD CL ON CL.LOGICALREF = T.CARDREF
    WHERE C.CURRSTAT = 9
        AND C.STATUS IN(0,1)
        AND C.DOC = 1
        AND CAST(T.DATE_ AS DATE) = :tarih
        AND T.TRCODE IN (1, 2, 3)
    ORDER BY C.DUEDATE
";

$stmt_checks_given_detail = $dbh->prepare($sql_checks_given_detail);
$stmt_checks_given_detail->bindParam(':tarih', $selected_date, PDO::PARAM_STR);
$stmt_checks_given_detail->execute();
$checks_given_detail = $stmt_checks_given_detail->fetchAll(PDO::FETCH_ASSOC);

// Null değerleri sıfırla
$sales_summary['MUSTERI_SAYISI'] = $sales_summary['MUSTERI_SAYISI'] ?: 0;
$sales_summary['ISLEM_SAYISI'] = $sales_summary['ISLEM_SAYISI'] ?: 0;
$sales_summary['TOPLAM_SATIS'] = $sales_summary['TOPLAM_SATIS'] ?: 0;

$cash_summary['GIRIS'] = $cash_summary['GIRIS'] ?: 0;
$cash_summary['CIKIS'] = $cash_summary['CIKIS'] ?: 0;
$cash_summary['NET'] = $cash_summary['NET'] ?: 0;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Günlük İşlem Özeti - <?php echo $display_date; ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
        }
        h1 {
            font-size: 16px;
            margin-bottom: 10px;
            color: #333;
        }
        h2 {
            font-size: 14px;
            margin-top: 20px;
            margin-bottom: 10px;
            color: #666;
            background-color: #f0f0f0;
            padding: 5px;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            margin-bottom: 20px;
        }
        th {
            background-color: #4a5568;
            color: white;
            font-weight: bold;
            padding: 8px;
            text-align: left;
            border: 1px solid #ddd;
        }
        td {
            padding: 6px;
            border: 1px solid #ddd;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .summary-box {
            background-color: #e6f7ff;
            padding: 10px;
            margin-bottom: 20px;
            border: 1px solid #91d5ff;
        }
        .summary-box strong {
            color: #0050b3;
        }
        .text-right {
            text-align: right;
        }
        .total-row {
            background-color: #fff3cd !important;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <h1>GÜNLÜK İŞLEM ÖZETİ - <?php echo $display_date; ?></h1>

    <!-- Özet Bilgiler -->
    <div class="summary-box">
        <strong>TOPLAM SATIŞ:</strong> <?php echo number_format($sales_summary['TOPLAM_SATIS'], 2, ',', '.'); ?> TL<br>
        <strong>İŞLEM SAYISI:</strong> <?php echo $sales_summary['ISLEM_SAYISI']; ?> adet<br>
        <strong>MÜŞTERİ SAYISI:</strong> <?php echo $sales_summary['MUSTERI_SAYISI']; ?> adet<br>
        <strong>KASA GİRİŞ:</strong> <?php echo number_format($cash_summary['GIRIS'], 2, ',', '.'); ?> TL<br>
        <strong>KASA ÇIKIŞ:</strong> <?php echo number_format($cash_summary['CIKIS'], 2, ',', '.'); ?> TL<br>
        <strong>NET KASA:</strong> <?php echo number_format($cash_summary['NET'], 2, ',', '.'); ?> TL<br>
    </div>

    <!-- Satış İşlemleri -->
    <h2>SATIŞ İŞLEMLERİ</h2>
    <?php if (count($sales_details) > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Tarih</th>
                <th>Müşteri Kodu</th>
                <th>Müşteri Adı</th>
                <th class="text-right">Tutar (TL)</th>
                <th class="text-right">Kur</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $total_sales = 0;
            foreach ($sales_details as $sale):
                $total_sales += $sale['TUTAR'];
            ?>
            <tr>
                <td><?php echo date('d.m.Y', strtotime((string) $sale['TARIH'])); ?></td>
                <td><?php echo $sale['CARI_KODU']; ?></td>
                <td><?php echo $sale['CARI_ADI']; ?></td>
                <td class="text-right"><?php echo number_format($sale['TUTAR'], 2, ',', '.'); ?></td>
                <td class="text-right"><?php echo number_format($sale['KUR'], 4, ',', '.'); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="3" class="text-right">TOPLAM:</td>
                <td class="text-right"><?php echo number_format($total_sales, 2, ',', '.'); ?> TL</td>
                <td></td>
            </tr>
        </tbody>
    </table>
    <?php else: ?>
    <p>Bu tarihte satış işlemi bulunmamaktadır.</p>
    <?php endif; ?>

    <!-- Kasa Hareketleri -->
    <h2>KASA HAREKETLERİ</h2>
    <?php if (count($cash_details) > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Tarih</th>
                <th>Kasa Kodu</th>
                <th>Kasa Adı</th>
                <th>Açıklama</th>
                <th class="text-right">Giriş (TL)</th>
                <th class="text-right">Çıkış (TL)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $total_giris = 0;
            $total_cikis = 0;
            foreach ($cash_details as $cash):
                $total_giris += $cash['GIRIS'];
                $total_cikis += $cash['CIKIS'];
            ?>
            <tr>
                <td><?php echo date('d.m.Y', strtotime((string) $cash['TARIH'])); ?></td>
                <td><?php echo $cash['KASA_KODU']; ?></td>
                <td><?php echo $cash['KASA_ADI']; ?></td>
                <td><?php echo $cash['ACIKLAMA']; ?></td>
                <td class="text-right"><?php echo $cash['GIRIS'] > 0 ? number_format($cash['GIRIS'], 2, ',', '.') : '-'; ?></td>
                <td class="text-right"><?php echo $cash['CIKIS'] > 0 ? number_format($cash['CIKIS'], 2, ',', '.') : '-'; ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="4" class="text-right">TOPLAM:</td>
                <td class="text-right"><?php echo number_format($total_giris, 2, ',', '.'); ?> TL</td>
                <td class="text-right"><?php echo number_format($total_cikis, 2, ',', '.'); ?> TL</td>
            </tr>
        </tbody>
    </table>
    <?php else: ?>
    <p>Bu tarihte kasa hareketi bulunmamaktadır.</p>
    <?php endif; ?>

    <!-- Alınan Çekler -->
    <h2>ALINAN ÇEKLER</h2>
    <?php if (count($checks_received_detail) > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Seri No</th>
                <th>Müşteri</th>
                <th>Borçlu</th>
                <th>Tanzim Tarihi</th>
                <th>Vade Tarihi</th>
                <th class="text-right">Tutar (TL)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $total_checks_received = 0;
            foreach ($checks_received_detail as $check):
                $total_checks_received += $check['TUTAR'];
            ?>
            <tr>
                <td><?php echo $check['SERI_NO']; ?></td>
                <td><?php echo $check['CARI']; ?></td>
                <td><?php echo $check['BORCLU']; ?></td>
                <td><?php echo date('d.m.Y', strtotime((string) $check['TANZIM_TARIHI'])); ?></td>
                <td><?php echo date('d.m.Y', strtotime((string) $check['VADE_TARIHI'])); ?></td>
                <td class="text-right"><?php echo number_format($check['TUTAR'], 2, ',', '.'); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="5" class="text-right">TOPLAM:</td>
                <td class="text-right"><?php echo number_format($total_checks_received, 2, ',', '.'); ?> TL</td>
            </tr>
        </tbody>
    </table>
    <?php else: ?>
    <p>Bu tarihte alınan çek bulunmamaktadır.</p>
    <?php endif; ?>

    <!-- Verilen Çekler -->
    <h2>VERİLEN ÇEKLER</h2>
    <?php if (count($checks_given_detail) > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Seri No</th>
                <th>Cari</th>
                <th>Alacaklı</th>
                <th>Tanzim Tarihi</th>
                <th>Vade Tarihi</th>
                <th class="text-right">Tutar (TL)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $total_checks_given = 0;
            foreach ($checks_given_detail as $check):
                $total_checks_given += $check['TUTAR'];
            ?>
            <tr>
                <td><?php echo $check['SERI_NO']; ?></td>
                <td><?php echo $check['CARI']; ?></td>
                <td><?php echo $check['ALACAKLI']; ?></td>
                <td><?php echo date('d.m.Y', strtotime((string) $check['TANZIM_TARIHI'])); ?></td>
                <td><?php echo date('d.m.Y', strtotime((string) $check['VADE_TARIHI'])); ?></td>
                <td class="text-right"><?php echo number_format($check['TUTAR'], 2, ',', '.'); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="5" class="text-right">TOPLAM:</td>
                <td class="text-right"><?php echo number_format($total_checks_given, 2, ',', '.'); ?> TL</td>
            </tr>
        </tbody>
    </table>
    <?php else: ?>
    <p>Bu tarihte verilen çek bulunmamaktadır.</p>
    <?php endif; ?>

    <br><br>
    <p style="text-align: center; color: #999; font-size: 10px;">
        Rapor Tarihi: <?php echo date('d.m.Y H:i:s'); ?> | Kullanıcı: <?php echo $terminalkullanici; ?>
    </p>
</body>
</html>
