<?php
declare(strict_types=1);

include_once __DIR__ . "/../ayr.php";
include    __DIR__ . "/../kontrol.php";
include_once __DIR__ . "/../log_ip.php";

// Stok hareket ID kontrolü
if (!isset($_GET['stokhareket']) || empty($_GET['stokhareket'])) {
    die("Stok hareket ID eksik!");
}
$stokhareket = $_GET['stokhareket'];

// Veritabanı sorguları
try {
    // Firma bilgisi
    $firmaSorgu = $dbh->prepare("SELECT TOP 1 DEFINITION_ FROM {$firma}CLCARD WHERE ACTIVE = 0 ORDER BY LOGICALREF");
    $firmaSorgu->execute();
    $firmaBilgisi = $firmaSorgu->fetch(PDO::FETCH_ASSOC);
    $firmaAdi = $firmaBilgisi['DEFINITION_'] ?? 'Firma Adı';

    // Fiş başlık bilgisi
    $fisSorgu = $dbh->prepare("
        SELECT
            FICHE.FICHENO,
            FICHE.DATE_,
            CARI.DEFINITION_ AS CARI_ADI,
            CARI.CODE AS CARI_KODU,
            FICHE.GROSSTOTAL,
            FICHE.TOTALDISCOUNTS,
            FICHE.NETTOTAL,
            FICHE.TOTALVAT,
            FICHE.GENEXP1,
            FICHE.GENEXP2,
            FICHE.GENEXP3
        FROM {$firmadonem}ORFICHE AS FICHE
        LEFT JOIN {$firma}CLCARD AS CARI ON FICHE.CLIENTREF = CARI.LOGICALREF
        WHERE FICHE.LOGICALREF = :stokhareket
    ");
    $fisSorgu->bindParam(':stokhareket', $stokhareket, PDO::PARAM_INT);
    $fisSorgu->execute();
    $fisBilgisi = $fisSorgu->fetch(PDO::FETCH_ASSOC);

    if (!$fisBilgisi) {
        die("Fiş bulunamadı!");
    }

    // Dosya adını oluştur
    $cariUnvan = $fisBilgisi['CARI_ADI'] ?? 'Bilinmeyen_Cari';
    $fisNo     = $fisBilgisi['FICHENO'] ?? $stokhareket;
    $temizUnvan = preg_replace('/[^a-zA-Z0-9ÇŞĞÜÖİçşığüöı\s]/', '', (string) $cariUnvan);
    $temizUnvan = str_replace(' ', '_', $temizUnvan);
    $dosyaAdi   = $temizUnvan . "_" . $fisNo . ".xls";

    // Fiş satırları (KDV oranları ile)
    $satirlarSorgu = $dbh->prepare("
        SELECT
            LIN.LINENO_ AS SATIR_NO,
            STK.CODE AS STOK_KODU,
            STK.NAME AS STOK_ADI,
            BR.CODE AS BIRIM,
            LIN.AMOUNT AS MIKTAR,
            LIN.PRICE AS BIRIM_FIYAT,
            LIN.TOTAL AS SATIR_TOPLAM,
            LIN.LINENET AS SATIR_NET,
            LIN.VAT AS KDV_ORANI,
            LIN.VATAMNT AS KDV_TUTARI,
            LIN.DISTDISC AS ISKONTO_TUTARI
        FROM {$firmadonem}ORFLINE AS LIN
        LEFT JOIN {$firma}ITEMS AS STK ON LIN.STOCKREF = STK.LOGICALREF
        LEFT JOIN {$firma}UNITSETL AS BR ON LIN.UOMREF = BR.LOGICALREF
        WHERE LIN.ORDFICHEREF = :stokhareket AND LIN.LINETYPE = 0
        ORDER BY LIN.LINENO_
    ");
    $satirlarSorgu->bindParam(':stokhareket', $stokhareket, PDO::PARAM_INT);
    $satirlarSorgu->execute();
    $satirlar = $satirlarSorgu->fetchAll(PDO::FETCH_ASSOC);

    // İskonto bilgileri
    $iskontolarSorgu = $dbh->prepare("
        SELECT DISCPER AS ISKONTO_ORANI
        FROM {$firmadonem}ORFLINE
        WHERE ORDFICHEREF = :stokhareket AND LINETYPE = 2
        ORDER BY LINENO_
    ");
    $iskontolarSorgu->bindParam(':stokhareket', $stokhareket, PDO::PARAM_INT);
    $iskontolarSorgu->execute();
    $iskontolar = $iskontolarSorgu->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    die("Veritabanı hatası: " . $e->getMessage());
}

// İstatistikler için değişkenler
$toplamUrunCesidi = count($satirlar);
$toplamMiktar = 0;
$kdvGruplari = [];

foreach ($satirlar as $satir) {
    $miktar = floatval($satir['MIKTAR'] ?? 0);
    $kdvOrani = floatval($satir['KDV_ORANI'] ?? 0);
    $kdvTutari = floatval($satir['KDV_TUTARI'] ?? 0);
    $satirNet = floatval($satir['SATIR_NET'] ?? 0);

    $toplamMiktar += $miktar;

    if (!isset($kdvGruplari[$kdvOrani])) {
        $kdvGruplari[$kdvOrani] = ['matrah' => 0, 'kdv' => 0];
    }
    $kdvGruplari[$kdvOrani]['matrah'] += $satirNet;
    $kdvGruplari[$kdvOrani]['kdv'] += $kdvTutari;
}

// Excel başlıkları
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"{$dosyaAdi}\"");
header("Pragma: no-cache");
header("Expires: 0");

// Excel HTML çıktısı
echo '<!DOCTYPE html>';
echo '<html><head><meta charset="utf-8"></head><body>';
echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;">';

// Firma başlığı
echo '<tr><td colspan="11" style="background-color:#E7E6E6;text-align:center;font-size:16pt;font-weight:bold;color:#1F4E78;">' . htmlspecialchars($firmaAdi) . '</td></tr>';

// Sipariş Detayı başlığı
echo '<tr><td colspan="11" style="background-color:#D9E1F2;text-align:center;font-size:14pt;font-weight:bold;">SİPARİŞ DETAYI</td></tr>';

// Boş satır
echo '<tr><td colspan="11"></td></tr>';

// Cari bilgileri
echo '<tr>';
echo '<td style="font-weight:bold;">Cari Kodu:</td><td>' . htmlspecialchars($fisBilgisi['CARI_KODU'] ?? '') . '</td>';
echo '<td></td>';
echo '<td style="font-weight:bold;">Fiş No:</td><td>' . htmlspecialchars($fisBilgisi['FICHENO'] ?? '') . '</td>';
echo '<td colspan="6"></td>';
echo '</tr>';

echo '<tr>';
echo '<td style="font-weight:bold;">Cari Unvan:</td><td colspan="2">' . htmlspecialchars($fisBilgisi['CARI_ADI'] ?? '') . '</td>';
echo '<td style="font-weight:bold;">Tarih:</td><td>' . date('d.m.Y', strtotime($fisBilgisi['DATE_'] ?? 'now')) . '</td>';
echo '<td colspan="6"></td>';
echo '</tr>';

// Açıklama varsa
if (!empty($fisBilgisi['GENEXP1'])) {
    echo '<tr>';
    echo '<td style="font-weight:bold;">Açıklama:</td><td colspan="10">' . htmlspecialchars($fisBilgisi['GENEXP1']) . '</td>';
    echo '</tr>';
}

// Boş satır
echo '<tr><td colspan="11"></td></tr>';

// Tablo başlıkları
echo '<tr style="background-color:#4472C4;color:#FFFFFF;font-weight:bold;text-align:center;">';
echo '<td>No</td>';
echo '<td>Stok Kodu</td>';
echo '<td>Stok Adı</td>';
echo '<td>Birim</td>';
echo '<td>Miktar</td>';
echo '<td>Birim Fiyat</td>';
echo '<td>Net Birim Fiyat</td>';
echo '<td>İskonto</td>';
echo '<td>KDV %</td>';
echo '<td>KDV Tutarı</td>';
echo '<td>Toplam</td>';
echo '</tr>';

// Satır verileri
$rowIndex = 0;
foreach ($satirlar as $satir) {
    $miktar = floatval($satir['MIKTAR'] ?? 0);
    $birimFiyat = floatval($satir['BIRIM_FIYAT'] ?? 0);
    $kdvOrani = floatval($satir['KDV_ORANI'] ?? 0);
    $kdvTutari = floatval($satir['KDV_TUTARI'] ?? 0);
    $satirNet = floatval($satir['SATIR_NET'] ?? 0);
    $iskontoTutari = floatval($satir['ISKONTO_TUTARI'] ?? 0);

    // Net birim fiyat hesaplama
    $netBirimFiyat = $miktar > 0 ? ($satirNet / $miktar) : $birimFiyat;

    // Satır toplamı KDV dahil
    $satirKdvDahilToplam = $satirNet + $kdvTutari;

    $bgColor = ($rowIndex % 2 == 0) ? '#F2F2F2' : '#FFFFFF';

    echo '<tr style="background-color:' . $bgColor . ';">';
    echo '<td style="text-align:center;">' . htmlspecialchars($satir['SATIR_NO'] ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($satir['STOK_KODU'] ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($satir['STOK_ADI'] ?? '') . '</td>';
    echo '<td style="text-align:center;">' . htmlspecialchars($satir['BIRIM'] ?? '') . '</td>';
    echo '<td style="text-align:right;">' . number_format($miktar, 2, ',', '.') . '</td>';
    echo '<td style="text-align:right;">' . number_format($birimFiyat, 2, ',', '.') . '</td>';
    echo '<td style="text-align:right;">' . number_format($netBirimFiyat, 2, ',', '.') . '</td>';
    echo '<td style="text-align:right;">' . number_format($iskontoTutari, 2, ',', '.') . '</td>';
    echo '<td style="text-align:center;">' . number_format($kdvOrani, 0) . '</td>';
    echo '<td style="text-align:right;">' . number_format($kdvTutari, 2, ',', '.') . '</td>';
    echo '<td style="text-align:right;">' . number_format($satirKdvDahilToplam, 2, ',', '.') . '</td>';
    echo '</tr>';

    $rowIndex++;
}

// Boş satır
echo '<tr><td colspan="11"></td></tr>';

// Toplam bölümü
$brutToplam = floatval($fisBilgisi['GROSSTOTAL'] ?? 0);
$netToplam = floatval($fisBilgisi['NETTOTAL'] ?? 0);
$toplamKdv = floatval($fisBilgisi['TOTALVAT'] ?? 0);
$toplamIskonto = floatval($fisBilgisi['TOTALDISCOUNTS'] ?? 0);
$genelToplam = $netToplam + $toplamKdv;

// Brüt Toplam
echo '<tr style="background-color:#E7E6E6;">';
echo '<td colspan="10" style="text-align:right;font-weight:bold;">Brüt Toplam:</td>';
echo '<td style="text-align:right;">' . number_format($brutToplam, 2, ',', '.') . '</td>';
echo '</tr>';

// İskonto satırları
foreach ($iskontolar as $i => $iskonto) {
    $iskontoAdi = (count($iskontolar) > 1) ? "İskonto " . ($i+1) : "İskonto";
    $iskontoTutar = $brutToplam * floatval($iskonto) / 100;

    echo '<tr>';
    echo '<td colspan="10" style="text-align:right;">' . $iskontoAdi . ' (%' . number_format(floatval($iskonto), 2, ',', '.') . '):</td>';
    echo '<td style="text-align:right;">' . number_format($iskontoTutar, 2, ',', '.') . '</td>';
    echo '</tr>';
}

// Net Toplam
echo '<tr style="background-color:#E7E6E6;">';
echo '<td colspan="10" style="text-align:right;font-weight:bold;">Net Toplam (KDV Hariç):</td>';
echo '<td style="text-align:right;">' . number_format($netToplam, 2, ',', '.') . '</td>';
echo '</tr>';

// KDV Detayları
if (!empty($kdvGruplari)) {
    echo '<tr style="background-color:#D9E1F2;">';
    echo '<td colspan="10" style="text-align:center;font-weight:bold;">KDV DETAYI</td>';
    echo '<td></td>';
    echo '</tr>';

    foreach ($kdvGruplari as $oran => $toplam) {
        echo '<tr>';
        echo '<td colspan="10" style="text-align:right;">KDV %' . number_format($oran, 0) . ':</td>';
        echo '<td style="text-align:right;">' . number_format($toplam['kdv'], 2, ',', '.') . '</td>';
        echo '</tr>';
    }
}

// Toplam KDV
echo '<tr style="background-color:#E7E6E6;">';
echo '<td colspan="10" style="text-align:right;font-weight:bold;">Toplam KDV:</td>';
echo '<td style="text-align:right;">' . number_format($toplamKdv, 2, ',', '.') . '</td>';
echo '</tr>';

// Genel Toplam
echo '<tr style="background-color:#4472C4;color:#FFFFFF;">';
echo '<td colspan="10" style="text-align:right;font-weight:bold;font-size:12pt;">GENEL TOPLAM (KDV Dahil):</td>';
echo '<td style="text-align:right;font-weight:bold;font-size:12pt;">' . number_format($genelToplam, 2, ',', '.') . '</td>';
echo '</tr>';

// Boş satırlar
echo '<tr><td colspan="11"></td></tr>';
echo '<tr><td colspan="11"></td></tr>';

// İstatistikler
echo '<tr style="background-color:#70AD47;color:#FFFFFF;">';
echo '<td colspan="4" style="text-align:center;font-weight:bold;font-size:12pt;">SİPARİŞ İSTATİSTİKLERİ</td>';
echo '<td colspan="7"></td>';
echo '</tr>';

$ortalamaFiyat = ($toplamUrunCesidi > 0 && $toplamMiktar > 0) ? ($brutToplam / $toplamMiktar) : 0;
$iskontoOrani = $brutToplam > 0 ? (($toplamIskonto / $brutToplam) * 100) : 0;

$istatistikler = [
    'Toplam Ürün Çeşidi' => $toplamUrunCesidi,
    'Toplam Miktar' => number_format($toplamMiktar, 2, ',', '.'),
    'Ortalama Birim Fiyat' => number_format($ortalamaFiyat, 2, ',', '.') . ' TL',
    'Toplam İskonto' => number_format($toplamIskonto, 2, ',', '.') . ' TL',
    'İskonto Oranı' => number_format($iskontoOrani, 2, ',', '.') . ' %'
];

foreach ($istatistikler as $baslik => $deger) {
    echo '<tr style="background-color:#E2EFDA;">';
    echo '<td colspan="2" style="font-weight:bold;">' . $baslik . '</td>';
    echo '<td colspan="2" style="text-align:right;">' . $deger . '</td>';
    echo '<td colspan="7"></td>';
    echo '</tr>';
}

echo '</table>';
echo '</body></html>';
