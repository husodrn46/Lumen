<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../kontrol.php';
include_once __DIR__ . '/../ayr.php';
include_once __DIR__ . '/../log_ip.php';

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

$CARIID = (int) ($_GET['cariid'] ?? 0);
if ($CARIID <= 0) {
    ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8');
    die('Hata: Gecersiz cari id.');
}

$donemSecim = isset($_GET['donem']) ? (string) $_GET['donem'] : 'aktif';
if (!in_array($donemSecim, ['aktif', 'onceki', 'tumu'], true)) {
    $donemSecim = 'aktif';
}

if (!m_p_cariid_goruntulebilir_mi($dbh, $firma, $terminalkullanici, $CARIID, 'M4')) {
    ob_end_clean();
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// Cari bilgileri
$stmtCari = $dbh->prepare("SELECT CODE, DEFINITION_ FROM {$firma}CLCARD WHERE LOGICALREF = :cariid");
$stmtCari->execute([':cariid' => $CARIID]);
$cari = $stmtCari->fetch(PDO::FETCH_ASSOC) ?: ['CODE' => '', 'DEFINITION_' => 'Bilinmeyen Cari'];

// Firma adı
$stmtFirma = $dbh->prepare("SELECT TOP 1 DEFINITION_ FROM {$firma}CLCARD WHERE ACTIVE = 0 ORDER BY LOGICALREF");
$stmtFirma->execute();
$firmaAdi = (string) ($stmtFirma->fetchColumn() ?: 'Firma');

// Resmi bakiye
$stmtResmi = $dbh->prepare("SELECT (ISNULL(G.DEBIT, 0) - ISNULL(G.CREDIT, 0)) AS BAKIYE
    FROM {$firmadonemx}GNTOTCL G WITH(NOLOCK)
    WHERE G.CARDREF = :cariid AND G.TOTTYP = 1");
$stmtResmi->execute([':cariid' => $CARIID]);
$resmi_bakiye = (float) $stmtResmi->fetchColumn();

// Dönem prefixleri
$donemPrefixler = match ($donemSecim) {
    'onceki' => [$eskifirmadonem],
    'tumu' => [$firmadonem, $eskifirmadonem],
    default => [$firmadonem],
};

$trcodeCaseExpr = "CASE HAREKET.TRCODE
    WHEN 1 THEN 'Nakit Tahsilat' WHEN 2 THEN 'Nakit Odeme' WHEN 3 THEN 'Borc Dekontu' WHEN 4 THEN 'Alacak Dekontu' WHEN 5 THEN 'Virman Fisi'
    WHEN 6 THEN 'Kur Farki Fisi' WHEN 12 THEN 'Ozel Fis' WHEN 14 THEN 'Acilis Fisi' WHEN 20 THEN 'Gelen Havale' WHEN 21 THEN 'Gonderilen Havale'
    WHEN 24 THEN 'Doviz Alis Belgesi' WHEN 25 THEN 'Doviz Satis belgesi' WHEN 28 THEN 'Alinan Hizmet Faturasi' WHEN 29 THEN 'Verilen Hizmet Faturasi'
    WHEN 31 THEN 'Satin Alma Faturasi' WHEN 32 THEN 'Perakende Satis Iade Faturasi' WHEN 33 THEN 'Toptan Satis Iade Faturasi'
    WHEN 34 THEN 'Alinan Hizmet Faturasi' WHEN 35 THEN 'Alinan Proforma Fatura' WHEN 36 THEN 'Satin Alma Iade Faturasi'
    WHEN 37 THEN 'Perakende Satis Faturasi' WHEN 38 THEN 'Toptan Satis Faturasi' WHEN 39 THEN 'Verilen Hizmet Faturasi'
    WHEN 40 THEN 'Verilen proforma fatura' WHEN 41 THEN 'Verilen Vade Farki Faturasi' WHEN 42 THEN 'Alinan Vade Farki Faturasi'
    WHEN 43 THEN 'Satin Alma Fiyat Farki Faturasi' WHEN 44 THEN 'Satis Fiyat Farki Faturasi' WHEN 45 THEN 'Verilen Serbest Meslek Makbuzu'
    WHEN 46 THEN 'Alinan Serbest Meslek Makbuzu' WHEN 56 THEN 'Mustahsil Makbuzu'
    WHEN 61 THEN 'Cek Girisi' WHEN 62 THEN 'Senet Girisi'
    WHEN 63 THEN 'Cek Cikisi (Cari Hesaba)' WHEN 64 THEN 'Senet Cikisi (Cari Hesaba)'
    WHEN 70 THEN 'Kredi Karti Fisi' WHEN 71 THEN 'Kredi Karti Iade Fisi' WHEN 72 THEN 'Firma Kredi Karti Fisi' WHEN 73 THEN 'Firma Kredi Karti Iade Fisi'
    WHEN 81 THEN 'Satinalma Siparisi' WHEN 82 THEN 'Satis Siparisi'
  END";

$cariIdSafe = (int) $CARIID;
$queryParts = [];
foreach ($donemPrefixler as $prefix) {
    $acilisFiltresi = ($donemSecim === 'tumu') ? ' AND HAREKET.TRCODE <> 14' : '';
    $queryParts[] = "
    SELECT
      HAREKET.LOGICALREF,
      HAREKET.DATE_,
      HAREKET.TRANNO AS DOCODE,
      HAREKET.TRCODE AS TRCODE_NO,
      {$trcodeCaseExpr} AS TRCODE,
      HAREKET.LINEEXP AS ACIKLAMA,
      ISNULL((1 - HAREKET.SIGN) * HAREKET.AMOUNT, 0) AS BORC,
      ISNULL(HAREKET.SIGN * HAREKET.AMOUNT, 0) AS ALACAK,
      '{$prefix}' AS DONEM_PREFIX
    FROM {$prefix}CLFLINE AS HAREKET
    WHERE HAREKET.CANCELLED = 0 AND HAREKET.CLIENTREF = {$cariIdSafe}{$acilisFiltresi}";
}

$sql = "SELECT * FROM (" . implode(" UNION ALL ", $queryParts) . ") AS BIRLESIK ORDER BY DATE_ ASC, LOGICALREF ASC";
$stmt = $dbh->prepare($sql);
$stmt->execute();
$hareketler = [];
$toplam_borc = 0.0;
$toplam_alacak = 0.0;
while ($h = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $hareketler[] = $h;
    $toplam_borc += (float) $h['BORC'];
    $toplam_alacak += (float) $h['ALACAK'];
}
$islem_net_etki = $toplam_borc - $toplam_alacak;
$baslangic_bakiye = $resmi_bakiye - $islem_net_etki;

$donemEtiket = match ($donemSecim) {
    'onceki' => '2025 (Onceki Donem)',
    'tumu' => 'Tum Donemler',
    default => '2026 (Aktif Donem)',
};

// Excel oluştur
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator($firmaAdi)
    ->setTitle('Cari Hareket Ekstresi - ' . ($cari['DEFINITION_'] ?? ''))
    ->setSubject('Cari Hareket Ekstresi');
$spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Cari Hareket Ekstresi');

$colorPrimary = 'B71C1C';
$colorPrimaryDark = '7F1212';
$colorPrimarySoft = 'FDECEC';
$colorHeader = '263238';
$colorHeaderSoft = 'ECEFF1';
$colorBorder = 'CFD8DC';
$colorZebra = 'FAFAFA';
$colorWhite = 'FFFFFF';
$colorGreen = '059669';
$colorRed = 'DC2626';
$moneyFormat = '#,##0.00 "TL"';

$row = 1;

// Firma başlığı
$sheet->setCellValue('A' . $row, $firmaAdi);
$sheet->mergeCells('A' . $row . ':F' . $row);
$sheet->getStyle('A' . $row)->applyFromArray([
    'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => $colorWhite]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colorPrimaryDark]],
]);
$sheet->getRowDimension($row)->setRowHeight(30);
$row++;

// Ekstre başlığı
$sheet->setCellValue('A' . $row, 'CARI HAREKET EKSTRESI');
$sheet->mergeCells('A' . $row . ':F' . $row);
$sheet->getStyle('A' . $row)->applyFromArray([
    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => $colorHeader]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colorHeaderSoft]],
]);
$sheet->getRowDimension($row)->setRowHeight(24);
$row++;
$row++;

// Cari bilgileri bloğu
$infoRows = [
    ['Cari Kodu:', (string) $cari['CODE']],
    ['Cari Unvan:', (string) $cari['DEFINITION_']],
    ['Donem:', $donemEtiket],
    ['Rapor Tarihi:', date('d.m.Y H:i')],
];
foreach ($infoRows as $info) {
    $sheet->setCellValue('A' . $row, $info[0]);
    $sheet->setCellValue('B' . $row, $info[1]);
    $sheet->mergeCells('B' . $row . ':F' . $row);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colorPrimarySoft);
    $sheet->getStyle('A' . $row . ':F' . $row)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $colorBorder]]],
    ]);
    $row++;
}
$row++;

// Özet kutuları
$summaryStart = $row;
$sheet->setCellValue('A' . $row, 'Toplam Giris (Alacak)');
$sheet->setCellValue('B' . $row, $toplam_alacak);
$sheet->setCellValue('C' . $row, 'Toplam Cikis (Borc)');
$sheet->setCellValue('D' . $row, $toplam_borc);
$sheet->setCellValue('E' . $row, 'Genel Bakiye');
$bakiyeMutlak = abs($resmi_bakiye);
$bakiyeEtiket = abs($resmi_bakiye) < 0.01 ? '' : ($resmi_bakiye > 0 ? ' (Borclu)' : ' (Alacakli)');
$sheet->setCellValue('F' . $row, $bakiyeMutlak);
$sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
$sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
$sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
$sheet->getStyle('A' . $row)->getFont()->setBold(true)->getColor()->setRGB($colorGreen);
$sheet->getStyle('C' . $row)->getFont()->setBold(true)->getColor()->setRGB($colorRed);
$sheet->getStyle('E' . $row)->getFont()->setBold(true);
$sheet->getStyle('B' . $row)->getFont()->setBold(true)->getColor()->setRGB($colorGreen);
$sheet->getStyle('D' . $row)->getFont()->setBold(true)->getColor()->setRGB($colorRed);
$sheet->getStyle('F' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row . ':F' . $row)->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $colorBorder]]],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colorHeaderSoft]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension($row)->setRowHeight(22);
if ($bakiyeEtiket !== '') {
    $row++;
    $sheet->setCellValue('E' . $row, 'Durum');
    $sheet->setCellValue('F' . $row, trim($bakiyeEtiket, ' ()'));
    $sheet->getStyle('E' . $row)->getFont()->setBold(true);
    $sheet->getStyle('E' . $row . ':F' . $row)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $colorBorder]]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
}
$row++;
$row++;

// Tablo başlıkları
$baslikRow = $row;
$headers = ['Tarih', 'Islem Turu', 'Aciklama', 'Giris (Alacak)', 'Cikis (Borc)', 'Bakiye'];
$col = 'A';
foreach ($headers as $h) {
    $sheet->setCellValue($col . $row, $h);
    $col++;
}
$sheet->getStyle('A' . $row . ':F' . $row)->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => $colorWhite]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colorHeader]],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $colorBorder]]],
]);
$sheet->getRowDimension($row)->setRowHeight(22);
$row++;

$dataStartRow = $row;

// Devir satırı (varsa)
if (abs($baslangic_bakiye) > 0.01) {
    $sheet->setCellValue('A' . $row, '-');
    $sheet->setCellValue('B' . $row, 'Onceki Donem Bakiyesi');
    $sheet->setCellValue('C' . $row, 'Devir');
    $sheet->setCellValue('D' . $row, '');
    $sheet->setCellValue('E' . $row, '');
    $sheet->setCellValue('F' . $row, $baslangic_bakiye);
    $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
    $sheet->getStyle('A' . $row . ':F' . $row)->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colorZebra]],
        'font' => ['italic' => true],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $colorBorder]]],
    ]);
    $sheet->getStyle('F' . $row)->getFont()->setBold(true)->getColor()->setRGB($baslangic_bakiye >= 0 ? $colorGreen : $colorRed);
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('D' . $row . ':F' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $row++;
}

$bakiye = $baslangic_bakiye;
$satirIndex = 0;

if (empty($hareketler)) {
    $sheet->setCellValue('A' . $row, 'Bu cari icin goruntulenecek hareket bulunamadi.');
    $sheet->mergeCells('A' . $row . ':F' . $row);
    $sheet->getStyle('A' . $row)->applyFromArray([
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'font' => ['italic' => true, 'color' => ['rgb' => '9CA3AF']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $colorBorder]]],
    ]);
    $sheet->getRowDimension($row)->setRowHeight(40);
    $row++;
} else {
    foreach ($hareketler as $h) {
        $borc = (float) $h['BORC'];
        $alacak = (float) $h['ALACAK'];
        if ($borc > 0)   { $bakiye += $borc; }
        if ($alacak > 0) { $bakiye -= $alacak; }

        $tarihGoster = '';
        if (!empty($h['DATE_'])) {
            $ts = strtotime((string) $h['DATE_']);
            $tarihGoster = $ts ? date('d.m.Y', $ts) : (string) $h['DATE_'];
        }

        $sheet->setCellValue('A' . $row, $tarihGoster);
        $sheet->setCellValue('B' . $row, (string) ($h['TRCODE'] ?? ''));
        $sheet->setCellValue('C' . $row, (string) ($h['ACIKLAMA'] ?? ''));
        if ($alacak > 0) {
            $sheet->setCellValue('D' . $row, $alacak);
            $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
            $sheet->getStyle('D' . $row)->getFont()->getColor()->setRGB($colorGreen);
        } else {
            $sheet->setCellValue('D' . $row, '-');
            $sheet->getStyle('D' . $row)->getFont()->getColor()->setRGB('9CA3AF');
        }
        if ($borc > 0) {
            $sheet->setCellValue('E' . $row, $borc);
            $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
            $sheet->getStyle('E' . $row)->getFont()->getColor()->setRGB($colorRed);
        } else {
            $sheet->setCellValue('E' . $row, '-');
            $sheet->getStyle('E' . $row)->getFont()->getColor()->setRGB('9CA3AF');
        }
        $sheet->setCellValue('F' . $row, $bakiye);
        $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
        $sheet->getStyle('F' . $row)->getFont()->setBold(true)->getColor()->setRGB($bakiye >= 0 ? $colorGreen : $colorRed);

        $sheet->getStyle('D' . $row . ':F' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A' . $row . ':F' . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('C' . $row)->getAlignment()->setWrapText(true);

        if ($satirIndex % 2 === 1) {
            $sheet->getStyle('A' . $row . ':F' . $row)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colorZebra]],
            ]);
        }
        $sheet->getStyle('A' . $row . ':F' . $row)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $colorBorder]]],
        ]);

        $row++;
        $satirIndex++;
    }
}
$dataEndRow = $row - 1;
$row++;

// Genel toplam satırı
$sheet->setCellValue('A' . $row, 'GENEL TOPLAM');
$sheet->mergeCells('A' . $row . ':C' . $row);
$sheet->setCellValue('D' . $row, $toplam_alacak);
$sheet->setCellValue('E' . $row, $toplam_borc);
$sheet->setCellValue('F' . $row, $resmi_bakiye);
$sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
$sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
$sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode($moneyFormat);
$sheet->getStyle('A' . $row . ':F' . $row)->applyFromArray([
    'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => $colorWhite]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colorPrimary]],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THICK, 'color' => ['rgb' => $colorPrimaryDark]]],
]);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$sheet->getRowDimension($row)->setRowHeight(24);

// Sütun genişlikleri
$sheet->getColumnDimension('A')->setWidth(13);
$sheet->getColumnDimension('B')->setWidth(28);
$sheet->getColumnDimension('C')->setWidth(38);
$sheet->getColumnDimension('D')->setWidth(18);
$sheet->getColumnDimension('E')->setWidth(18);
$sheet->getColumnDimension('F')->setWidth(20);

// Başlık satırını dondur
$sheet->freezePane('A' . ($baslikRow + 1));

// Otomatik filtre
if ($dataEndRow >= $dataStartRow) {
    $sheet->setAutoFilter('A' . $baslikRow . ':F' . $dataEndRow);
}

// Yazdırma ayarları
$sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
$sheet->getPageSetup()->setFitToWidth(1);
$sheet->getPageSetup()->setFitToHeight(0);
$sheet->getHeaderFooter()->setOddHeader('&C&B' . $firmaAdi . ' - Cari Hareket Ekstresi');
$sheet->getHeaderFooter()->setOddFooter('&L' . ($cari['DEFINITION_'] ?? '') . '&CSayfa &P / &N&R' . date('d.m.Y H:i'));

// Dosya adı
$temizUnvan = preg_replace('/[^a-zA-Z0-9_-]/u', '_', (string) ($cari['DEFINITION_'] ?? 'Cari'));
$temizUnvan = preg_replace('/_+/', '_', (string) $temizUnvan);
$temizUnvan = trim((string) $temizUnvan, '_');
if ($temizUnvan === '') {
    $temizUnvan = 'Cari';
}
$dosyaAdi = 'Cari_Hareket_Ekstresi_' . $temizUnvan . '_' . date('Ymd_His') . '.xlsx';

ob_end_clean();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $dosyaAdi . '"');
header('Cache-Control: max-age=0');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

$spreadsheet->disconnectWorksheets();
unset($spreadsheet);
exit;
