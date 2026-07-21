<?php
declare(strict_types=1);

// Output buffering başlat - header sorunlarını önlemek için
ob_start();

include_once __DIR__ . "/../ayr.php";
include    __DIR__ . "/../kontrol.php";
include_once __DIR__ . "/../log_ip.php";

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

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
    $dosyaAdi   = $temizUnvan . "_" . $fisNo . ".xlsx";

    // Fiş satırları (KDV oranları ile)
    $satirlarSorgu = $dbh->prepare("
        SELECT
            LIN.LINENO_ AS SATIR_NO,
            STK.CODE AS STOK_KODU,
            (
                SELECT TOP 1 UB.BARCODE
                FROM {$firma}UNITBARCODE AS UB
                WHERE UB.ITEMREF = STK.LOGICALREF
                ORDER BY UB.LOGICALREF
            ) AS BARKOD,
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

// Excel oluştur (PhpSpreadsheet)
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator($firmaAdi)
    ->setTitle("Sipariş Detayı - " . $fisNo)
    ->setSubject("Stok Hareket")
    ->setDescription("Sipariş detay raporu");
$spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Sipariş Detayı');

// Lumen tema renkleri
$colorPrimary = 'B71C1C';
$colorPrimaryDark = '7F1212';
$colorPrimarySoft = 'FDECEC';
$colorHeader = '263238';
$colorHeaderSoft = 'ECEFF1';
$colorBorder = 'CFD8DC';
$colorZebra = 'FAFAFA';
$colorWhite = 'FFFFFF';
$moneyFormat = '#,##0.00 "₺"';
$qtyFormat = '#,##0.00';

// BAŞLIK BÖLÜMÜ
$row = 1;

// Firma başlığı
$sheet->setCellValue('A' . $row, $firmaAdi);
$sheet->mergeCells('A' . $row . ':M' . $row);
$sheet->getStyle('A' . $row)->applyFromArray([
    'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => $colorWhite]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colorPrimaryDark]]
]);
$sheet->getRowDimension($row)->setRowHeight(30);
$row++;

// Başlık
$sheet->setCellValue('A' . $row, 'STOK HAREKET DETAYI');
$sheet->mergeCells('A' . $row . ':M' . $row);
$sheet->getStyle('A' . $row)->applyFromArray([
    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => $colorHeader]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colorHeaderSoft]]
]);
$row++;
$row++;

// Cari bilgileri
$sheet->setCellValue('A' . $row, 'Cari Kodu:');
$sheet->setCellValue('B' . $row, $fisBilgisi['CARI_KODU'] ?? '');
$sheet->setCellValue('D' . $row, 'Fiş No:');
$sheet->setCellValue('E' . $row, $fisBilgisi['FICHENO'] ?? '');
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('D' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row . ':E' . $row)->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $colorBorder]]]
]);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colorPrimarySoft);
$sheet->getStyle('D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colorPrimarySoft);
$row++;

$sheet->setCellValue('A' . $row, 'Cari Unvan:');
$sheet->setCellValue('B' . $row, $fisBilgisi['CARI_ADI'] ?? '');
$sheet->mergeCells('B' . $row . ':C' . $row);
$sheet->setCellValue('D' . $row, 'Tarih:');
$sheet->setCellValue('E' . $row, date('d.m.Y', strtotime($fisBilgisi['DATE_'] ?? 'now')));
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('D' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row . ':E' . $row)->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $colorBorder]]]
]);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colorPrimarySoft);
$sheet->getStyle('D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colorPrimarySoft);
$row++;

// Açıklama (varsa)
if (!empty($fisBilgisi['GENEXP1'])) {
    $sheet->setCellValue('A' . $row, 'Açıklama:');
    $sheet->setCellValue('B' . $row, $fisBilgisi['GENEXP1']);
    $sheet->mergeCells('B' . $row . ':M' . $row);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $sheet->getStyle('A' . $row . ':M' . $row)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $colorBorder]]]
    ]);
    $sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colorPrimarySoft);
    $row++;
}
$row++;

// TABLO BAŞLIKLARI
$baslikRow = $row;
$headers = ['No', 'Stok Kodu', 'Barkod', 'Stok Adı', 'Miktar', 'Birim', 'Birim Fiyat', 'Net Birim Fiyat', 'KDV Dahil Birim Fiyat', 'İskonto', 'KDV %', 'KDV Tutarı', 'Toplam'];
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . $row, $header);
    $col++;
}

$sheet->getStyle('A' . $row . ':M' . $row)->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => $colorWhite]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colorHeader]],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $colorBorder]]]
]);
$sheet->getRowDimension($row)->setRowHeight(22);
$row++;

$kdvGruplari = [];

// SATIRLAR
$dataStartRow = $row;
foreach ($satirlar as $satir) {
    $miktar = isset($satir['MIKTAR']) ? floatval($satir['MIKTAR']) : 0;
    $birimFiyat = isset($satir['BIRIM_FIYAT']) ? floatval($satir['BIRIM_FIYAT']) : 0;
    $kdvOrani = isset($satir['KDV_ORANI']) ? floatval($satir['KDV_ORANI']) : 0;
    $kdvTutari = isset($satir['KDV_TUTARI']) ? floatval($satir['KDV_TUTARI']) : 0;
    $satirNet = isset($satir['SATIR_NET']) ? floatval($satir['SATIR_NET']) : 0;
    $iskontoTutari = isset($satir['ISKONTO_TUTARI']) ? floatval($satir['ISKONTO_TUTARI']) : 0;

    // Net birim fiyat hesaplama (iskonto düşülmüş, KDV hariç)
    $netBirimFiyat = $miktar > 0 ? ($satirNet / $miktar) : $birimFiyat;
    $kdvDahilBirimFiyat = null;
    if ($miktar > 0 && ($kdvOrani > 0 || $kdvTutari > 0)) {
        // KDV oranı gelmiyorsa KDV tutarından birim başına eklenen KDV hesaplanır.
        if ($kdvOrani > 0) {
            $kdvDahilBirimFiyat = $netBirimFiyat * (1 + ($kdvOrani / 100));
        } else {
            $kdvDahilBirimFiyat = $netBirimFiyat + ($kdvTutari / $miktar);
        }
    }

    if (!isset($kdvGruplari[$kdvOrani])) {
        $kdvGruplari[$kdvOrani] = ['matrah' => 0, 'kdv' => 0];
    }
    $kdvGruplari[$kdvOrani]['matrah'] += $satirNet;
    $kdvGruplari[$kdvOrani]['kdv'] += $kdvTutari;

    // Satır toplamı KDV dahil
    $satirKdvDahilToplam = $satirNet + $kdvTutari;

    $sheet->setCellValue('A' . $row, $satir['SATIR_NO'] ?? '');
    $sheet->setCellValue('B' . $row, $satir['STOK_KODU'] ?? '');
    $sheet->setCellValueExplicit('C' . $row, (string) ($satir['BARKOD'] ?? ''), DataType::TYPE_STRING);
    $sheet->setCellValue('D' . $row, $satir['STOK_ADI'] ?? '');
    $sheet->setCellValue('E' . $row, $miktar);
    $sheet->setCellValue('F' . $row, $satir['BIRIM'] ?? '');
    $sheet->setCellValue('G' . $row, $birimFiyat);
    $sheet->setCellValue('H' . $row, $netBirimFiyat);
    $sheet->setCellValue('I' . $row, $kdvDahilBirimFiyat);
    $sheet->setCellValue('J' . $row, $iskontoTutari);
    $sheet->setCellValue('K' . $row, $kdvOrani);
    $sheet->setCellValue('L' . $row, $kdvTutari);
    $sheet->setCellValue('M' . $row, $satirKdvDahilToplam);

    // Sayı formatları
    $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode($qtyFormat);
    $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode('@');
    $sheet->getStyle('G' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
    $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
    $sheet->getStyle('I' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
    $sheet->getStyle('J' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
    $sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('0');
    $sheet->getStyle('L' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
    $sheet->getStyle('M' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
    $sheet->getStyle('E' . $row . ':M' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('A' . $row . ':M' . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('F' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('K' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('D' . $row)->getAlignment()->setWrapText(true);

    // Zebra stripes
    if (($row - $dataStartRow) % 2 == 0) {
        $sheet->getStyle('A' . $row . ':M' . $row)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colorZebra]]
        ]);
    }

    $sheet->getStyle('A' . $row . ':M' . $row)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $colorBorder]]]
    ]);
    $sheet->getRowDimension($row)->setRowHeight(20);

    $row++;
}
$dataEndRow = $row - 1;
$row++;

// TOPLAM BÖLÜMÜ
$araToplam = isset($fisBilgisi['GROSSTOTAL']) ? (float) $fisBilgisi['GROSSTOTAL'] : 0.0;
$toplamIskonto = isset($fisBilgisi['TOTALDISCOUNTS']) ? (float) $fisBilgisi['TOTALDISCOUNTS'] : 0.0;
$toplamKdv = isset($fisBilgisi['TOTALVAT']) ? (float) $fisBilgisi['TOTALVAT'] : 0.0;
$netToplamDb = isset($fisBilgisi['NETTOTAL']) ? (float) $fisBilgisi['NETTOTAL'] : 0.0;

// NETTOTAL bazı kurulumlarda KDV dahil gelebilir; bu yüzden net (KDV hariç) değeri güvenli hesaplanır.
$netToplamKdvHaric = $araToplam - $toplamIskonto;
if ($netToplamKdvHaric < 0) {
    $netToplamKdvHaric = max(0, $netToplamDb - $toplamKdv);
}
$genelToplam = $netToplamKdvHaric + $toplamKdv;

$sheet->setCellValue('L' . $row, 'Ara Toplam (TL):');
$sheet->setCellValue('M' . $row, $araToplam);
$sheet->getStyle('L' . $row)->getFont()->setBold(true);
$sheet->getStyle('L' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('M' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
$sheet->getStyle('L' . $row . ':M' . $row)->applyFromArray([
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colorHeaderSoft]],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $colorBorder]]]
]);
$row++;

// İskonto satırı (eksi değer)
$iskontoEtiket = 'İskonto:';
if ($iskontolar !== []) {
    $oranlar = array_map(
        static fn($oran): string => '%' . number_format((float) $oran, 2, ',', '.'),
        $iskontolar
    );
    $iskontoEtiket = (count($oranlar) === 1)
        ? 'İskonto (' . $oranlar[0] . '):'
        : 'İskonto Toplam (' . implode(' + ', $oranlar) . '):';
}
$sheet->setCellValue('L' . $row, $iskontoEtiket);
$sheet->setCellValue('M' . $row, -$toplamIskonto);
$sheet->getStyle('L' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('M' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
$sheet->getStyle('L' . $row . ':M' . $row)->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $colorBorder]]]
]);
$row++;

// Net toplam (KDV hariç)
$sheet->setCellValue('L' . $row, 'Net Toplam (KDV Hariç):');
$sheet->setCellValue('M' . $row, $netToplamKdvHaric);
$sheet->getStyle('L' . $row)->getFont()->setBold(true);
$sheet->getStyle('L' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('M' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
$sheet->getStyle('L' . $row . ':M' . $row)->applyFromArray([
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colorHeaderSoft]],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $colorBorder]]]
]);
$row++;

// KDV Detayları
if (count($kdvGruplari) > 1) {
    $sheet->setCellValue('L' . $row, 'KDV DETAYI');
    $sheet->mergeCells('L' . $row . ':M' . $row);
    $sheet->getStyle('L' . $row)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => $colorWhite]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colorHeader]]
    ]);
    $row++;

    foreach ($kdvGruplari as $oran => $toplam) {
        $sheet->setCellValue('L' . $row, 'KDV %' . number_format((float) $oran, 0) . ':');
        $sheet->setCellValue('M' . $row, $toplam['kdv']);
        $sheet->getStyle('L' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('M' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
        $sheet->getStyle('L' . $row . ':M' . $row)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $colorBorder]]]
        ]);
        $row++;
    }
}

// Toplam KDV
$sheet->setCellValue('L' . $row, 'Toplam KDV:');
$sheet->setCellValue('M' . $row, $toplamKdv);
$sheet->getStyle('L' . $row)->getFont()->setBold(true);
$sheet->getStyle('L' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('M' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
$sheet->getStyle('L' . $row . ':M' . $row)->applyFromArray([
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colorHeaderSoft]],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $colorBorder]]]
]);
$row++;

// Genel toplam (KDV dahil)
$sheet->setCellValue('L' . $row, 'Genel Toplam (TL):');
$sheet->setCellValue('M' . $row, $genelToplam);
$sheet->getStyle('L' . $row . ':M' . $row)->applyFromArray([
    'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => $colorWhite]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colorPrimary]],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THICK, 'color' => ['rgb' => $colorPrimaryDark]]]
]);
$sheet->getStyle('M' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
$row++;

// SÜTUN GENİŞLİKLERİ
$sheet->getColumnDimension('A')->setWidth(8);
$sheet->getColumnDimension('B')->setWidth(15);
$sheet->getColumnDimension('C')->setWidth(18);
$sheet->getColumnDimension('D')->setWidth(35);
$sheet->getColumnDimension('E')->setWidth(12);
$sheet->getColumnDimension('F')->setWidth(10);
$sheet->getColumnDimension('G')->setWidth(14);
$sheet->getColumnDimension('H')->setWidth(16);
$sheet->getColumnDimension('I')->setWidth(20);
$sheet->getColumnDimension('J')->setWidth(12);
$sheet->getColumnDimension('K')->setWidth(10);
$sheet->getColumnDimension('L')->setWidth(14);
$sheet->getColumnDimension('M')->setWidth(16);

// Başlık satırını dondur
$sheet->freezePane('A' . ($baslikRow + 1));

// Otomatik filtre
$sheet->setAutoFilter('A' . $baslikRow . ':M' . $dataEndRow);

// Yazdırma ayarları
$sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
$sheet->getPageSetup()->setFitToWidth(1);
$sheet->getPageSetup()->setFitToHeight(0);

// Header/Footer
$sheet->getHeaderFooter()->setOddHeader('&C&B' . $firmaAdi);
$sheet->getHeaderFooter()->setOddFooter('&LSayfa &P / &N&R' . date('d.m.Y H:i'));

// Output buffer'ı temizle
ob_end_clean();

// Excel dosyasını oluştur ve indir
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $dosyaAdi . '"');
header('Cache-Control: max-age=0');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

// Memory cleanup
$spreadsheet->disconnectWorksheets();
unset($spreadsheet);

exit;
