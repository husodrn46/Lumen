<?php
declare(strict_types=1);

require_once __DIR__ . '/../kontrol.php';
ob_start();

include_once(__DIR__ . "/../ayr.php");      // Veritabanı bağlantısı ve ayarlarınız
include_once(__DIR__ . "/../log_ip.php");    // IP loglama vb.

$stokhareket = filter_input(INPUT_GET, 'stokhareket', FILTER_SANITIZE_NUMBER_INT);
if (!$stokhareket || $stokhareket <= 0) { // Sıfır veya geçersizse durdur
    ob_end_clean();
    header("Content-Type: text/plain; charset=utf-8"); // Hata mesajı için düz metin
    die("Hata: Gecersiz veya eksik stok hareket numarasi.");
}

require __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Değişken tanımlamaları
$clientRef = 0;
$customerName = null;
$fisNo = null;
$fisDate = null;
$fisNotes = [];
$lineItems = [];
$brutToplam = 0;
$toplamIskonto = 0;
$toplamKDV = 0;
$genelToplam = 0;
$netToplam = 0;
$dbh_error = null;

try {
    // PDO hata modunu exception olarak ayarla
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Müşteri referansını al (Güvenli)
    $clientQuery = $dbh->prepare("SELECT CLIENTREF FROM {$firmadonem}ORFICHE WHERE LOGICALREF = :stokhareket");
    $clientQuery->bindParam(':stokhareket', $stokhareket, PDO::PARAM_INT);
    $clientQuery->execute();
    $clientResult = $clientQuery->fetch(PDO::FETCH_ASSOC);
    $clientRef = isset($clientResult['CLIENTREF']) ? (int)$clientResult['CLIENTREF'] : 0;

    // 2. Müşteri adını al (Basitleştirilmiş ve Güvenli)
    if ($clientRef > 0) {
        $sqlCustomerName = "SELECT DEFINITION_ FROM {$firma}CLCARD WHERE LOGICALREF = :clientRef";
        $stmtCustomerName = $dbh->prepare($sqlCustomerName);
        $stmtCustomerName->bindParam(':clientRef', $clientRef, PDO::PARAM_INT);
        $stmtCustomerName->execute();
        $customerResult = $stmtCustomerName->fetch(PDO::FETCH_ASSOC);
        $customerName = $customerResult['DEFINITION_'] ?? 'Musteri Bulunamadi';
    } else {
         $customerName = 'Musteri Iliskilendirilmemis';
    }

    // 2b. Fiş başlık bilgisi (Fiş No / Tarih / Toplamlar)
    $fisQuery = $dbh->prepare("
        SELECT
            FICHENO,
            DATE_,
            GROSSTOTAL,
            TOTALDISCOUNTS,
            NETTOTAL,
            TOTALVAT,
            GENEXP1,
            GENEXP2,
            GENEXP3
        FROM {$firmadonem}ORFICHE WITH(NOLOCK)
        WHERE LOGICALREF = :stokhareket
    ");
    $fisQuery->bindParam(':stokhareket', $stokhareket, PDO::PARAM_INT);
    $fisQuery->execute();
    $fisResult = $fisQuery->fetch(PDO::FETCH_ASSOC) ?: [];

    $fisNo = $fisResult['FICHENO'] ?? null;
    $fisDate = $fisResult['DATE_'] ?? null;
    $fisNotes = array_values(array_filter([
        (string) ($fisResult['GENEXP1'] ?? ''),
        (string) ($fisResult['GENEXP2'] ?? ''),
        (string) ($fisResult['GENEXP3'] ?? ''),
    ], static fn ($v) => trim($v) !== ''));

    // SQLSRV/PDO numeric alanları string döndürebildiği için float'a çeviriyoruz.
    $brutToplam = (float) ($fisResult['GROSSTOTAL'] ?? 0);
    $toplamIskonto = (float) ($fisResult['TOTALDISCOUNTS'] ?? 0);
    $netToplam = (float) ($fisResult['NETTOTAL'] ?? 0);
    $toplamKDV = (float) ($fisResult['TOTALVAT'] ?? 0);
    $genelToplam = $netToplam + $toplamKDV;

    // 3. Stok hareket detayları sorgusu (Güvenli)
    $sqlDetails = "
        SELECT
            HRKT.LINENO_,
            STK.CODE AS KODU,
            STK.NAME AS ADI,
            HRKT.AMOUNT,
            HRKT.PRICE,
            HRKT.TOTAL,
            HRKT.DISTDISC,
            HRKT.LINENET,
            HRKT.VAT,
            HRKT.VATAMNT,
            BR.CODE AS BIRIM
        FROM {$firmadonem}ORFLINE HRKT
        LEFT JOIN {$firma}ITEMS STK ON HRKT.STOCKREF = STK.LOGICALREF
        LEFT JOIN {$firma}UNITSETL BR ON HRKT.UOMREF = BR.LOGICALREF
        WHERE HRKT.ORDFICHEREF = :stokhareket AND HRKT.LINETYPE = 0
        ORDER BY HRKT.LINENO_ ASC /* Satır sırasına göre sırala */
    ";
    $stmtDetails = $dbh->prepare($sqlDetails);
    $stmtDetails->bindParam(':stokhareket', $stokhareket, PDO::PARAM_INT);
    $stmtDetails->execute();
    $lineItems = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $dbh_error = "Veritabani hatasi: " . $e->getMessage();
} catch (Exception $e) {
     $dbh_error = "Genel bir hata olustu: " . $e->getMessage();
}

// Hata varsa göster ve çık
if ($dbh_error !== null) {
    ob_end_clean();
    header("Content-Type: text/plain; charset=utf-8");
    // Gerçek ortamda hatayı loglamak daha iyi olabilir
    die($dbh_error);
}


// *********************************
// HTML İçeriğini Oluşturma (GÜNCELLENMİŞ)
// *********************************

// HTML içeriği için başlangıç
$html = '<html><head>
    <meta charset="utf-8">
    <style>
      /* DejaVu Sans fontunu @font-face ile tanımlamak daha güvenilir olabilir, ancak Dompdf genellikle bulur */
      body {
        font-family: \'DejaVu Sans\', sans-serif; /* Türkçe karakterler için */
        background-color: #ffffff; /* Beyaz arkaplan */
        color: #333;
        margin: 20px;
        font-size: 10pt; /* Genel font boyutu */
      }
      .f_yazi { /* Bu sınıf artık çok anlamlı değil, genel body stili yeterli olabilir */
        font-size: 11pt;
        color: #333;
        padding: 5px 0; /* Alt/üst padding */
        font-weight: bold;
        margin-bottom: 15px;
      }
      table.details { /* Detay tablosu için ayrı sınıf */
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
        
        border: 1px solid #ccc; /* Basit kenarlık */
      }
      table.details th, table.details td {
        border: 1px solid #ccc; /* İnce gri kenarlık */
        padding: 6px; /* Daha az padding */
        text-align: left;
        vertical-align: top; /* Üste hizala */
      }
      table.details th {
        background-color: #4F81BD; /* Daha yumuşak mavi */
        color: white;
        font-weight: bold;
      }
      table.details tr:nth-child(even) {
        background-color: #f2f7ff; /* Çok açık mavi tonu */
      }
      
      .header-info { /* Başlık bilgileri için */
         border-bottom: 2px solid #4F81BD;
         padding-bottom: 10px;
         margin-bottom: 20px;
      }
      .header-info .title {
        font-size: 16pt;
        font-weight: bold;
        color: #1F4E78; /* Koyu mavi */
        text-align: center;
        margin-bottom: 10px;
      }
       .header-info .sub-info {
        font-size: 11pt;
        font-weight: bold;
      }

      .summary-section { /* Özet bölümü için */
        margin-top: 25px;
        float: right; /* Sağa yasla */
        width: 40%; /* Genişlik ayarı */
      }

      table.summary { /* Özet tablosu */
         width: 100%;
         border-collapse: collapse;
         border: 1px solid #666;
      }
       table.summary td {
         border: 1px solid #ccc;
         padding: 6px 8px;
         font-size: 10pt;
       }
       table.summary td.label {
         text-align: right;
         font-weight: bold;
         width: 60%;
         background-color: #eaeaea;
       }
        table.summary td.value {
         text-align: right;
       }
       table.summary tr.grand-total td {
          font-weight: bold;
          font-size: 11pt;
          background-color: #d0e0f0; /* Genel toplam için farklı arkaplan */
       }

      /* Gereksiz CSS kaldırıldı (.print-btn vb.) */
    </style>
</head><body>';

// Başlık Bölümü
$html .= '<div class="header-info">';
$html .= '<div class="title">Stok Hareket Detayı</div>'; // Ana Başlık
$html .= '<div class="sub-info">Stok Hareket No: ' . htmlspecialchars((string) $stokhareket) . '</div>';
if ($fisNo) {
    $html .= '<div class="sub-info">Fiş No: ' . htmlspecialchars((string) $fisNo) . '</div>';
}
if ($fisDate) {
    $dt = strtotime((string) $fisDate);
    $html .= '<div class="sub-info">Tarih: ' . htmlspecialchars($dt ? date('d.m.Y', $dt) : (string) $fisDate) . '</div>';
}
if ($customerName && $customerName !== 'Musteri Iliskilendirilmemis' && $customerName !== 'Musteri Bulunamadi') {
    // Sadece müşteri adı gösteriliyor, "son iskonto" kaldırıldı
    $html .= '<div class="sub-info">Müşteri: ' . htmlspecialchars((string) $customerName) . '</div>';
}
if ($fisNotes !== []) {
    $html .= '<div class="sub-info">Açıklama: ' . htmlspecialchars(implode(' / ', $fisNotes)) . '</div>';
}
$html .= '</div>';

// Detay Tablosu
$html .= '<table class="details">
  <thead>
    <tr>
       <th>No</th>
       <th>Kodu</th>
       <th>Adı</th>
       <th>Birim</th>
       <th>Miktar</th>
       <th>Birim Fiyat</th>
       <th>İskonto</th>
       <th>Net Birim</th>
       <th>Net Tutar</th>
       <th>KDV %</th>
       <th>KDV</th>
       <th>Toplam</th>
    </tr>
  </thead>
  <tbody>';

// Satırları oluştur
if (!empty($lineItems)) {
    foreach ($lineItems as $row) {
        $amount = (float) ($row['AMOUNT'] ?? 0);
        $price = (float) ($row['PRICE'] ?? 0);
        $discount = (float) ($row['DISTDISC'] ?? 0);
        $lineNet = (float) ($row['LINENET'] ?? 0);
        if ($lineNet <= 0 && $amount > 0) {
            $lineNet = max(0.0, ($price * $amount) - $discount);
        }
        $vatRate = (float) ($row['VAT'] ?? 0);
        $vatAmount = (float) ($row['VATAMNT'] ?? 0);
        $totalWithVat = $lineNet + $vatAmount;
        $netUnit = $amount > 0 ? ($lineNet / $amount) : 0.0;

        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars((string) $row['LINENO_']) . '</td>';
        $html .= '<td>' . htmlspecialchars((string) $row['KODU']) . '</td>';
        $html .= '<td>' . htmlspecialchars((string) $row['ADI']) . '</td>';
        $html .= '<td>' . htmlspecialchars((string) ($row['BIRIM'] ?? '')) . '</td>';

        // Miktar
        $html .= '<td style="text-align:right;">' . number_format($amount, 2, ',', '.') . '</td>';
        // Birim fiyat
        $html .= '<td style="text-align:right;">' . number_format($price, 2, ',', '.') . ' ₺</td>';
        // İskonto (tutar)
        $html .= '<td style="text-align:right;">' . number_format($discount, 2, ',', '.') . ' ₺</td>';
        // Net birim
        $html .= '<td style="text-align:right;">' . number_format($netUnit, 2, ',', '.') . ' ₺</td>';
        // Net tutar (KDV hariç)
        $html .= '<td style="text-align:right;">' . number_format($lineNet, 2, ',', '.') . ' ₺</td>';
        // KDV %
        $html .= '<td style="text-align:right;">' . number_format($vatRate, 0, ',', '.') . '</td>';
        // KDV tutar
        $html .= '<td style="text-align:right;">' . number_format($vatAmount, 2, ',', '.') . ' ₺</td>';
        // Toplam (KDV dahil)
        $html .= '<td style="text-align:right;">' . number_format($totalWithVat, 2, ',', '.') . ' ₺</td>';

        $html .= '</tr>';
    }
} else {
    $html .= '<tr><td colspan="12" style="text-align:center;">Bu stok hareketine ait detay bulunamadı.</td></tr>';
}
$html .= '</tbody></table>';

// Özet Bölümü (Sağda, Tablo içinde)
$html .= '<div class="summary-section">';
$html .= '<table class="summary">';
// Brüt Toplam (Yeni Etiket)
$html .= '<tr><td class="label">Brüt Toplam:</td><td class="value">' . number_format($brutToplam, 2, ',', '.') . ' ₺</td></tr>';
// Toplam İskonto (Yeni Etiket)
$html .= '<tr><td class="label">Toplam İskonto:</td><td class="value">' . number_format($toplamIskonto, 2, ',', '.') . ' ₺</td></tr>';
// Net Toplam
$html .= '<tr><td class="label">Net Toplam (KDV Hariç):</td><td class="value">' . number_format($netToplam, 2, ',', '.') . ' ₺</td></tr>';
// Toplam KDV
$html .= '<tr><td class="label">Toplam KDV:</td><td class="value">' . number_format($toplamKDV, 2, ',', '.') . ' ₺</td></tr>';
// Genel Toplam (Yeni Hesaplama ile)
$html .= '<tr class="grand-total"><td class="label">Genel Toplam:</td><td class="value">' . number_format($genelToplam, 2, ',', '.') . ' ₺</td></tr>';
$html .= '</table>';
$html .= '</div>';

// HTML sonu
$html .= '</body></html>';

ob_end_clean(); // PDF göndermeden önce tamponu temizle

// *********************************
// Dompdf ile PDF Oluşturma
// *********************************

$options = new Options();
// isRemoteEnabled sadece dışarıdan resim/css çekecekseniz gereklidir. Güvenlik riski oluşturabilir.
// Yerel logo veya sadece inline CSS kullanıyorsanız false yapabilirsiniz.
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans'); // Fontu ayarla
$options->set('isHtml5ParserEnabled', true); // Daha iyi HTML5 desteği için
$options->set('isFontSubsettingEnabled', true); // PDF boyutunu küçültür

$dompdf = new Dompdf($options);
// HTML'i yüklemeden önce UTF-8 BOM (Byte Order Mark) sorunlarını gidermek için
$html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
$dompdf->loadHtml($html);

// Kağıt Boyutu ve Yönlendirme
$dompdf->setPaper('A4', 'landscape');

// PDF'i Oluştur
$dompdf->render();

// Tarayıcıya Gönder
$filename = "stok_hareket_" . $stokhareket . "_" . date('Ymd') . ".pdf"; // Daha kısa dosya adı
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"'); // inline: Tarayıcıda göster, attachment: İndir
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
echo $dompdf->output();
exit;
?>
