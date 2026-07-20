<?php
declare(strict_types=1);

// Output buffering başlat - header sorunlarını önlemek için
ob_start();

include_once __DIR__ . "/../ayr.php";
include __DIR__ . "/../kontrol.php";
include_once __DIR__ . "/../log_ip.php";
require_once __DIR__ . '/doviz_guard.php';

doviz_require_m21($terminalkullanici);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('fis_excel_xlsx fatal: ' . json_encode($err, JSON_UNESCAPED_UNICODE));
    }
});

$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
];
if (isset($_SERVER['DOCUMENT_ROOT'])) {
    $docRoot = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/\\');
    $autoloadCandidates[] = $docRoot . '/vendor/autoload.php';
    $autoloadCandidates[] = $docRoot . '/akl/vendor/autoload.php';
}

$autoloadPath = null;
foreach ($autoloadCandidates as $candidate) {
    if ($candidate !== '' && is_file($candidate)) {
        $autoloadPath = $candidate;
        break;
    }
}

if ($autoloadPath === null) {
    error_log('Autoload not found. Tried: ' . implode(' | ', $autoloadCandidates));
    die('Autoload bulunamadı. Lütfen vendor/ klasörünü kontrol edin.');
}

require $autoloadPath;

// Geçici dosya dizini (PhpSpreadsheet için)
$sysTemp = sys_get_temp_dir();
if (!is_writable($sysTemp)) {
    $altTemp = dirname(__DIR__) . '/logs';
    if (is_dir($altTemp) && is_writable($altTemp)) {
        ini_set('upload_tmp_dir', $altTemp);
        \PhpOffice\PhpSpreadsheet\Shared\File::setUseUploadTempDirectory(true);
    } else {
        error_log('Temp dir not writable. sys: ' . $sysTemp . ' alt: ' . $altTemp);
    }
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

// Sipariş ID kontrolü
$stokhareket = 0;
if (isset($_GET['id'])) {
    $stokhareket = (int) $_GET['id'];
} elseif (isset($_GET['stokhareket'])) {
    $stokhareket = (int) $_GET['stokhareket'];
}
if ($stokhareket <= 0) {
    die("Sipariş ID eksik!");
}

// Veritabanı sorguları
try {
    // Firma bilgisi
    $firmaSorgu = $dbh->prepare("SELECT TOP 1 DEFINITION_ FROM {$firma}CLCARD WHERE ACTIVE = 0 ORDER BY LOGICALREF");
    $firmaSorgu->execute();
    $firmaBilgisi = $firmaSorgu->fetch(PDO::FETCH_ASSOC);
    $firmaAdi = $firmaBilgisi['DEFINITION_'] ?? 'Firma Adı';

    // Fiş başlık bilgisi (döviz)
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
            FICHE.GENEXP3,
            FICHE.TRCURR,
            FICHE.TRRATE
        FROM {$firmadonem}ORFICHE AS FICHE
        LEFT JOIN {$firma}CLCARD AS CARI ON FICHE.CLIENTREF = CARI.LOGICALREF
        WHERE FICHE.LOGICALREF = :stokhareket AND FICHE.TRCODE = 1
    ");
    $fisSorgu->bindParam(':stokhareket', $stokhareket, PDO::PARAM_INT);
    $fisSorgu->execute();
    $fisBilgisi = $fisSorgu->fetch(PDO::FETCH_ASSOC);

    if (!$fisBilgisi) {
        die("Fiş bulunamadı!");
    }

    $trcurr = (int) ($fisBilgisi['TRCURR'] ?? 0);
    $trrate = (float) ($fisBilgisi['TRRATE'] ?? 0);
    if ($trcurr === 0 || $trrate <= 0) {
        die("Bu sipariş TL cinsindedir.");
    }

    // Döviz bilgileri
    $dovizBilgileri = [
        1 => ['kod' => 'USD', 'sembol' => '$', 'ad' => 'Amerikan Doları'],
        20 => ['kod' => 'EUR', 'sembol' => '€', 'ad' => 'Euro'],
    ];
    $doviz = $dovizBilgileri[$trcurr] ?? ['kod' => 'DV', 'sembol' => '?', 'ad' => 'Döviz'];

    // Dosya adını oluştur
    $cariUnvan = $fisBilgisi['CARI_ADI'] ?? 'Bilinmeyen_Cari';
    $fisNo     = $fisBilgisi['FICHENO'] ?? $stokhareket;
    $temizUnvan = preg_replace('/[^a-zA-Z0-9ÇŞĞÜÖİçşğığüöı\s]/', '', (string) $cariUnvan);
    $temizUnvan = str_replace(' ', '_', $temizUnvan);
    $dosyaAdi   = $temizUnvan . "_" . $fisNo . "_" . $doviz['kod'] . ".xlsx";

    // Fiş satırları
    $satirlarSorgu = $dbh->prepare("
        SELECT
            LIN.LINENO_ AS SATIR_NO,
            STK.CODE AS STOK_KODU,
            STK.NAME AS STOK_ADI,
            BR.CODE AS BIRIM,
            LIN.AMOUNT AS MIKTAR,
            LIN.PRICE AS BIRIM_FIYAT_TL,
            LIN.PRPRICE AS BIRIM_FIYAT_DOVIZ,
            LIN.LINENET AS SATIR_NET_TL,
            LIN.VAT AS KDV_ORANI,
            LIN.VATAMNT AS KDV_TUTARI_TL,
            LIN.DISTDISC AS ISKONTO_TUTARI_TL
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
    ->setTitle("Dövizli Sipariş Detayı - " . $fisNo)
    ->setSubject("Dövizli Sipariş")
    ->setDescription("Dövizli sipariş detay raporu");

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Dövizli Sipariş');

// BAŞLIK BÖLÜMÜ
$row = 1;

// Firma başlığı
$sheet->setCellValue('A' . $row, $firmaAdi);
$sheet->mergeCells('A' . $row . ':K' . $row);
$sheet->getStyle('A' . $row)->applyFromArray([
    'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '1F4E78']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E7E6E6']]
]);
$sheet->getRowDimension($row)->setRowHeight(30);
$row++;

// Başlık
$sheet->setCellValue('A' . $row, 'DÖVİZLİ SİPARİŞ DETAYI');
$sheet->mergeCells('A' . $row . ':K' . $row);
$sheet->getStyle('A' . $row)->applyFromArray([
    'font' => ['bold' => true, 'size' => 14],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E1F2']]
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
$row++;

$sheet->setCellValue('A' . $row, 'Cari Ünvan:');
$sheet->setCellValue('B' . $row, $fisBilgisi['CARI_ADI'] ?? '');
$sheet->mergeCells('B' . $row . ':C' . $row);
$sheet->setCellValue('D' . $row, 'Tarih:');
$sheet->setCellValue('E' . $row, date('d.m.Y', strtotime($fisBilgisi['DATE_'] ?? 'now')));
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('D' . $row)->getFont()->setBold(true);
$row++;

// Döviz ve Kur bilgisi
$sheet->setCellValue('A' . $row, 'Döviz:');
$sheet->setCellValue('B' . $row, $doviz['kod'] . ' ' . $doviz['sembol']);
$sheet->setCellValue('D' . $row, 'Kur:');
$sheet->setCellValue('E' . $row, '1 ' . $doviz['kod'] . ' = ' . number_format($trrate, 4, ',', '.') . ' TL');
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('D' . $row)->getFont()->setBold(true);
$row++;

// Açıklama (varsa)
if (!empty($fisBilgisi['GENEXP1'])) {
    $sheet->setCellValue('A' . $row, 'Açıklama:');
    $sheet->setCellValue('B' . $row, $fisBilgisi['GENEXP1']);
    $sheet->mergeCells('B' . $row . ':K' . $row);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $row++;
}
$row++;

// TABLO BAŞLIKLARI (Döviz)
$baslikRow = $row;
$headers = [
    'No',
    'Stok Kodu',
    'Stok Adı',
    'Birim',
    'Miktar',
    'Birim Fiyat (' . $doviz['kod'] . ')',
    'Net Birim Fiyat (' . $doviz['kod'] . ')',
    'İskonto (' . $doviz['kod'] . ')',
    'KDV %',
    'KDV Tutarı (' . $doviz['kod'] . ')',
    'Toplam (' . $doviz['kod'] . ')'
];
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . $row, $header);
    $col++;
}

$sheet->getStyle('A' . $row . ':K' . $row)->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
]);
$row++;

// İSTATİSTİKLER İÇİN DEĞİŞKENLER
$toplamUrunCesidi = count($satirlar);
$toplamMiktar = 0;
$kdvGruplari = [];

// SATIRLAR
$dataStartRow = $row;
foreach ($satirlar as $satir) {
    $miktar = isset($satir['MIKTAR']) ? floatval($satir['MIKTAR']) : 0;
    $birimFiyatTl = isset($satir['BIRIM_FIYAT_TL']) ? floatval($satir['BIRIM_FIYAT_TL']) : 0;
    $birimFiyatDoviz = isset($satir['BIRIM_FIYAT_DOVIZ']) ? floatval($satir['BIRIM_FIYAT_DOVIZ']) : 0;
    $kdvOrani = isset($satir['KDV_ORANI']) ? floatval($satir['KDV_ORANI']) : 0;
    $kdvTutariTl = isset($satir['KDV_TUTARI_TL']) ? floatval($satir['KDV_TUTARI_TL']) : 0;
    $satirNetTl = isset($satir['SATIR_NET_TL']) ? floatval($satir['SATIR_NET_TL']) : 0;
    $iskontoTl = isset($satir['ISKONTO_TUTARI_TL']) ? floatval($satir['ISKONTO_TUTARI_TL']) : 0;

    // Net birim fiyat (TL)
    $netBirimTl = $miktar > 0 ? ($satirNetTl / $miktar) : $birimFiyatTl;

    // Döviz birim fiyatı (PRPRICE varsa kullan)
    if ($birimFiyatDoviz <= 0 && $trrate > 0) {
        $birimFiyatDoviz = $birimFiyatTl / $trrate;
    }

    // Döviz net fiyat / tutarlar
    $netBirimDoviz = $trrate > 0 ? ($netBirimTl / $trrate) : 0;
    $iskontoDoviz = $trrate > 0 ? ($iskontoTl / $trrate) : 0;
    $kdvTutariDoviz = $trrate > 0 ? ($kdvTutariTl / $trrate) : 0;

    // Satır toplamı (KDV dahil) döviz
    $satirKdvDahilTl = $satirNetTl + $kdvTutariTl;
    $satirKdvDahilDoviz = $trrate > 0 ? ($satirKdvDahilTl / $trrate) : 0;

    $toplamMiktar += $miktar;

    if (!isset($kdvGruplari[$kdvOrani])) {
        $kdvGruplari[$kdvOrani] = ['matrah_tl' => 0, 'kdv_tl' => 0];
    }
    $kdvGruplari[$kdvOrani]['matrah_tl'] += $satirNetTl;
    $kdvGruplari[$kdvOrani]['kdv_tl'] += $kdvTutariTl;

    $sheet->setCellValue('A' . $row, $satir['SATIR_NO'] ?? '');
    $sheet->setCellValue('B' . $row, $satir['STOK_KODU'] ?? '');
    $sheet->setCellValue('C' . $row, $satir['STOK_ADI'] ?? '');
    $sheet->setCellValue('D' . $row, $satir['BIRIM'] ?? '');
    $sheet->setCellValue('E' . $row, $miktar);
    $sheet->setCellValue('F' . $row, $birimFiyatDoviz);
    $sheet->setCellValue('G' . $row, $netBirimDoviz);
    $sheet->setCellValue('H' . $row, $iskontoDoviz);
    $sheet->setCellValue('I' . $row, $kdvOrani);
    $sheet->setCellValue('J' . $row, $kdvTutariDoviz);
    $sheet->setCellValue('K' . $row, $satirKdvDahilDoviz);

    // Sayı formatları
    $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('G' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('I' . $row)->getNumberFormat()->setFormatCode('0');
    $sheet->getStyle('J' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('#,##0.00');

    // Zebra stripes
    if (($row - $dataStartRow) % 2 == 0) {
        $sheet->getStyle('A' . $row . ':K' . $row)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']]
        ]);
    }

    $sheet->getStyle('A' . $row . ':K' . $row)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]]
    ]);

    $row++;
}
$dataEndRow = $row - 1;
$row++;

// TOPLAM BÖLÜMÜ (Döviz)
$brutToplamDoviz = isset($fisBilgisi['GROSSTOTAL']) && $trrate > 0 ? (floatval($fisBilgisi['GROSSTOTAL']) / $trrate) : 0;
$sheet->setCellValue('J' . $row, 'Brüt Toplam:');
$sheet->setCellValue('K' . $row, $brutToplamDoviz);
$sheet->getStyle('J' . $row)->getFont()->setBold(true);
$sheet->getStyle('J' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('J' . $row . ':K' . $row)->applyFromArray([
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E7E6E6']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
]);
$row++;

// İskonto satırları
foreach ($iskontolar as $i => $iskonto) {
    $iskontoAdi = (count($iskontolar) > 1) ? "İskonto " . ($i + 1) : "İskonto";
    $brutToplamTl = isset($fisBilgisi['GROSSTOTAL']) ? floatval($fisBilgisi['GROSSTOTAL']) : 0;
    $iskontoTutarTl = ($brutToplamTl * floatval($iskonto) / 100);
    $iskontoTutarDoviz = $trrate > 0 ? ($iskontoTutarTl / $trrate) : 0;

    $sheet->setCellValue('J' . $row, $iskontoAdi . ' (%' . number_format((float)$iskonto, 2, ',', '.') . '):');
    $sheet->setCellValue('K' . $row, $iskontoTutarDoviz);
    $sheet->getStyle('J' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $row++;
}

// Net toplam (KDV hariç)
$netToplamDoviz = isset($fisBilgisi['NETTOTAL']) && $trrate > 0 ? (floatval($fisBilgisi['NETTOTAL']) / $trrate) : 0;
$sheet->setCellValue('J' . $row, 'Net Toplam (KDV Hariç):');
$sheet->setCellValue('K' . $row, $netToplamDoviz);
$sheet->getStyle('J' . $row)->getFont()->setBold(true);
$sheet->getStyle('J' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('J' . $row . ':K' . $row)->applyFromArray([
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E7E6E6']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
]);
$row++;

// KDV Detayları
if ($kdvGruplari !== []) {
    $sheet->setCellValue('J' . $row, 'KDV DETAYI');
    $sheet->mergeCells('J' . $row . ':K' . $row);
    $sheet->getStyle('J' . $row)->applyFromArray([
        'font' => ['bold' => true],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E1F2']]
    ]);
    $row++;

    foreach ($kdvGruplari as $oran => $toplam) {
        $kdvDoviz = $trrate > 0 ? ($toplam['kdv_tl'] / $trrate) : 0;
        $sheet->setCellValue('J' . $row, 'KDV %' . number_format((float)$oran, 0) . ':');
        $sheet->setCellValue('K' . $row, $kdvDoviz);
        $sheet->getStyle('J' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $row++;
    }
}

// Toplam KDV
$toplamKdvDoviz = isset($fisBilgisi['TOTALVAT']) && $trrate > 0 ? (floatval($fisBilgisi['TOTALVAT']) / $trrate) : 0;
$sheet->setCellValue('J' . $row, 'Toplam KDV:');
$sheet->setCellValue('K' . $row, $toplamKdvDoviz);
$sheet->getStyle('J' . $row)->getFont()->setBold(true);
$sheet->getStyle('J' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('J' . $row . ':K' . $row)->applyFromArray([
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E7E6E6']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
]);
$row++;

// Genel toplam (KDV dahil)
$netToplamTl = isset($fisBilgisi['NETTOTAL']) ? floatval($fisBilgisi['NETTOTAL']) : 0;
$toplamKdvTl = isset($fisBilgisi['TOTALVAT']) ? floatval($fisBilgisi['TOTALVAT']) : 0;
$genelToplamDoviz = $trrate > 0 ? (($netToplamTl + $toplamKdvTl) / $trrate) : 0;
$sheet->setCellValue('J' . $row, 'GENEL TOPLAM (KDV Dahil):');
$sheet->setCellValue('K' . $row, $genelToplamDoviz);
$sheet->getStyle('J' . $row . ':K' . $row)->applyFromArray([
    'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THICK]]
]);
$sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$row++;
$row += 2;

// TL KARŞILIĞI
$sheet->setCellValue('A' . $row, 'TL KARŞILIĞI');
$sheet->mergeCells('A' . $row . ':D' . $row);
$sheet->getStyle('A' . $row)->applyFromArray([
    'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '70AD47']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
]);
$row++;

$tlOzet = [
    'Brüt Toplam (TL)' => number_format((float)($fisBilgisi['GROSSTOTAL'] ?? 0), 2, ',', '.'),
    'Toplam İskonto (TL)' => number_format((float)($fisBilgisi['TOTALDISCOUNTS'] ?? 0), 2, ',', '.'),
    'Net Toplam (TL)' => number_format((float)($fisBilgisi['NETTOTAL'] ?? 0), 2, ',', '.'),
    'Toplam KDV (TL)' => number_format((float)($fisBilgisi['TOTALVAT'] ?? 0), 2, ',', '.'),
    'Genel Toplam (TL)' => number_format((float)($netToplamTl + $toplamKdvTl), 2, ',', '.')
];

foreach ($tlOzet as $baslik => $deger) {
    $sheet->setCellValue('A' . $row, $baslik);
    $sheet->setCellValue('C' . $row, $deger);
    $sheet->mergeCells('C' . $row . ':D' . $row);
    $sheet->getStyle('A' . $row)->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2EFDA']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
    ]);
    $sheet->getStyle('C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $row++;
}

// SÜTUN GENİŞLİKLERİ
$sheet->getColumnDimension('A')->setWidth(8);
$sheet->getColumnDimension('B')->setWidth(15);
$sheet->getColumnDimension('C')->setWidth(35);
$sheet->getColumnDimension('D')->setWidth(10);
$sheet->getColumnDimension('E')->setWidth(12);
$sheet->getColumnDimension('F')->setWidth(16);
$sheet->getColumnDimension('G')->setWidth(18);
$sheet->getColumnDimension('H')->setWidth(16);
$sheet->getColumnDimension('I')->setWidth(10);
$sheet->getColumnDimension('J')->setWidth(16);
$sheet->getColumnDimension('K')->setWidth(16);

// Başlık satırını dondur
$sheet->freezePane('A' . ($baslikRow + 1));

// Otomatik filtre
$sheet->setAutoFilter('A' . $baslikRow . ':K' . $dataEndRow);

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
try {
    $writer->save('php://output');
} catch (Throwable $e) {
    error_log('fis_excel_xlsx write error: ' . $e->getMessage());
    die('Excel oluşturulamadı. Lütfen yöneticinize bildiriniz.');
}

// Memory cleanup
$spreadsheet->disconnectWorksheets();
unset($spreadsheet);

exit;
