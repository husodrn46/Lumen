<?php
declare(strict_types=1);

/**
 * Kar-Zarar Analizi Excel Export
 */

require_once __DIR__ . '/kontrol.php';
include_once(__DIR__ . "/../../ayr.php");
include_once(__DIR__ . "/../../_baglanti_.inc");
include_once(__DIR__ . "/../../_bilgi_.inc");

// Yetki kontrolü
if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    die('Yetkiniz yok!');
}

try {
    $dbh = new PDO("sqlsrv:server=$anamakina;database=$veritabani;", $kullanici, $sifre);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log('kar_zarar_excel hata: ' . $e->getMessage());
    die("Veritabani baglanti hatasi");
}

// Filtre parametreleri
$baslangic_tarih = $_GET['baslangic'] ?? date('Y-m-01');
$bitis_tarih = $_GET['bitis'] ?? date('Y-m-d');
$musteri_id = isset($_GET['musteri']) ? (int)$_GET['musteri'] : 0;
$urun_id = isset($_GET['urun']) ? (int)$_GET['urun'] : 0;
$ortalama_vade = isset($_GET['ortalama_vade']) ? (int)$_GET['ortalama_vade'] : 90;

// SQL sorgusu (FATURALI SATIŞLAR)
$where_conditions = [];
$params = [];
$where_conditions[] = "INV.DATE_ BETWEEN :baslangic_tarih AND :bitis_tarih";
$params[':baslangic_tarih'] = $baslangic_tarih;
$params[':bitis_tarih'] = $bitis_tarih;
$where_conditions[] = "INV.TRCODE IN (7,8)";

if ($musteri_id > 0) {
    $where_conditions[] = "INV.CLIENTREF = :musteri_id";
    $params[':musteri_id'] = $musteri_id;
}

if ($urun_id > 0) {
    $where_conditions[] = "L.STOCKREF = :urun_id";
    $params[':urun_id'] = $urun_id;
}

$where_sql = implode(' AND ', $where_conditions);

/* FATURALI SATIŞLAR: Perakende (7) + Toptan (8) */
$query = "
    SELECT
        INV.FICHENO AS SIPARIS_NO,
        CAST(INV.DATE_ AS DATE) AS TARIH,
        C.CODE AS MUSTERI_KODU,
        C.DEFINITION_ AS MUSTERI_ADI,
        I.CODE AS URUN_KODU,
        I.NAME AS URUN_ADI,
        L.AMOUNT AS MIKTAR,
        L.PRICE AS LISTE_FIYAT,
	        L.DISCPER AS ISKONTO_YUZDE,
	        (L.TOTAL - L.DISTDISC) AS NET_SATIS,
	        L.STOCKREF AS URUN_REF,
	        :ortalama_vade AS VADE_GUN
	    FROM {$firmadonem}INVOICE INV
	    INNER JOIN {$firmadonem}STLINE L ON L.INVOICEREF = INV.LOGICALREF AND L.TRCODE IN (7,8)
	    INNER JOIN {$firma}CLCARD C ON C.LOGICALREF = INV.CLIENTREF
	    INNER JOIN {$firma}ITEMS I ON I.LOGICALREF = L.STOCKREF
    WHERE {$where_sql}
      AND L.LINETYPE = 0
	      AND INV.CANCELLED = 0
	    ORDER BY INV.DATE_ DESC
	";

	$params[':ortalama_vade'] = $ortalama_vade;
	$stmt = $dbh->prepare($query);
	$stmt->execute($params);
	$satirlar = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Excel oluştur
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="kar_zarar_analizi_' . date('Y-m-d') . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF"; // UTF-8 BOM

echo "<html xmlns:x=\"urn:schemas-microsoft-com:office:excel\">";
echo "<head>";
echo "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\">";
echo "<xml>";
echo "<x:ExcelWorkbook>";
echo "<x:ExcelWorksheets>";
echo "<x:ExcelWorksheet>";
echo "<x:Name>Kar-Zarar Analizi</x:Name>";
echo "<x:WorksheetOptions>";
echo "<x:Print><x:ValidPrinterInfo/></x:Print>";
echo "</x:WorksheetOptions>";
echo "</x:ExcelWorksheet>";
echo "</x:ExcelWorksheets>";
echo "</x:ExcelWorkbook>";
echo "</xml>";
echo "</head>";
echo "<body>";

echo "<table border='1'>";
echo "<tr style='background-color: #4472C4; color: white; font-weight: bold;'>";
echo "<th>Tarih</th>";
echo "<th>Sipariş No</th>";
echo "<th>Müşteri Kodu</th>";
echo "<th>Müşteri Adı</th>";
echo "<th>Ürün Kodu</th>";
echo "<th>Ürün Adı</th>";
echo "<th>Miktar</th>";
echo "<th>Liste Fiyat</th>";
echo "<th>İskonto %</th>";
echo "<th>Net Satış</th>";
echo "<th>Maliyet</th>";
echo "<th>Brüt Kar</th>";
echo "<th>Brüt Kar %</th>";
echo "<th>Vade (Gün)</th>";
echo "<th>Enflasyon %</th>";
echo "<th>Enflasyon Maliyet</th>";
echo "<th>Net Kar</th>";
echo "<th>Net Kar %</th>";
echo "<th>Durum</th>";
echo "</tr>";

foreach ($satirlar as $satir) {
    $maliyet = son_alis_fiyati($satir['URUN_REF']);
    $net_satis = $satir['NET_SATIS'];
    $vade_gun = max(0, $satir['VADE_GUN']);

    $kar_analiz = net_kar_hesapla($net_satis, $maliyet * $satir['MIKTAR'], $vade_gun, $satir['TARIH']);

    echo "<tr>";
    echo "<td>" . date('d.m.Y', strtotime((string) $satir['TARIH'])) . "</td>";
    echo "<td>" . htmlspecialchars((string) $satir['SIPARIS_NO']) . "</td>";
    echo "<td>" . htmlspecialchars((string) $satir['MUSTERI_KODU']) . "</td>";
    echo "<td>" . htmlspecialchars((string) $satir['MUSTERI_ADI']) . "</td>";
    echo "<td>" . htmlspecialchars((string) $satir['URUN_KODU']) . "</td>";
    echo "<td>" . htmlspecialchars((string) $satir['URUN_ADI']) . "</td>";
    echo "<td>" . number_format($satir['MIKTAR'], 2, ',', '.') . "</td>";
    echo "<td>" . number_format($satir['LISTE_FIYAT'], 2, ',', '.') . "</td>";
    echo "<td>" . number_format($satir['ISKONTO_YUZDE'], 2, ',', '.') . "</td>";
    echo "<td>" . number_format($net_satis, 2, ',', '.') . "</td>";
    echo "<td>" . number_format($maliyet * $satir['MIKTAR'], 2, ',', '.') . "</td>";
    echo "<td>" . number_format($kar_analiz['brut_kar'], 2, ',', '.') . "</td>";
    echo "<td>" . number_format($kar_analiz['brut_kar_yuzde'], 2, ',', '.') . "</td>";
    echo "<td>" . $vade_gun . "</td>";
    echo "<td>" . number_format($kar_analiz['enflasyon_oran'], 2, ',', '.') . "</td>";
    echo "<td>" . number_format($kar_analiz['enflasyon_maliyet'], 2, ',', '.') . "</td>";
    echo "<td>" . number_format($kar_analiz['net_kar'], 2, ',', '.') . "</td>";
    echo "<td>" . number_format($kar_analiz['net_kar_yuzde'], 2, ',', '.') . "</td>";
    echo "<td>" . ucfirst((string) $kar_analiz['durum']) . "</td>";
    echo "</tr>";
}

echo "</table>";
echo "</body>";
echo "</html>";
