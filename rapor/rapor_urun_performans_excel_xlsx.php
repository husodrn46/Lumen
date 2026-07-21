<?php
declare(strict_types=1);

// Output buffering: stray output breaks xlsx download headers.
ob_start();

include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../log_ip.php");
require_once __DIR__ . '/../kontrol.php';

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Yetki kontrolü
if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

$currentYear = (int) date('Y');
$year = (isset($_GET['year']) && is_numeric($_GET['year'])) ? (int) $_GET['year'] : $currentYear;

// Yıl <= 2025 verisi eski dönem tablosunda (rapor sayfasıyla aynı eşleme)
$donemTablo = ($year <= 2025) ? 'LG_001_01_' : $firmadonem;

// SQL: tüm ürünler, NET satışa göre desc (satış 7,8 − iade 2,3; rapor sayfasıyla birebir aynı tanım)
$urunKosulu = urun_kodu_kosulu('I');

$sql = "
SELECT
    I.CODE AS URUN_KODU,
    I.NAME AS URUN_ADI,
    SUM(CASE WHEN S.TRCODE IN (7,8) THEN S.AMOUNT ELSE -S.AMOUNT END) AS SATILAN_ADET,
    SUM(CASE WHEN S.TRCODE IN (7,8) THEN S.PRICE * S.AMOUNT - S.DISTDISC
             ELSE -(S.PRICE * S.AMOUNT - S.DISTDISC) END)             AS SATILAN_TUTAR
FROM {$donemTablo}STLINE S  WITH (NOLOCK)
JOIN {$firma}ITEMS  I ON S.STOCKREF = I.LOGICALREF
WHERE
      {$urunKosulu}
  AND S.TRCODE IN (2,3,7,8)
  AND YEAR(S.DATE_)   = :y
  AND S.CANCELLED     = 0
GROUP BY
    I.CODE,
    I.NAME
HAVING
    SUM(CASE WHEN S.TRCODE IN (7,8) THEN S.PRICE * S.AMOUNT - S.DISTDISC
             ELSE -(S.PRICE * S.AMOUNT - S.DISTDISC) END) > 0
ORDER BY
    SATILAN_TUTAR DESC;
";

try {
    $stmt = $dbh->prepare($sql);
    $stmt->execute(['y' => $year]);
    $rows = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $adet = (float) ($r['SATILAN_ADET'] ?? 0);
        $tutar = (float) ($r['SATILAN_TUTAR'] ?? 0);
        $r['SATILAN_ADET'] = $adet;
        $r['SATILAN_TUTAR'] = $tutar;
        $r['ORT_FIYAT'] = $adet > 0 ? round($tutar / $adet, 2) : 0.0;
        $rows[] = $r;
    }
} catch (Throwable $e) {
    if (function_exists('app_log_exception')) {
        $ref = app_log_exception($e, 'rapor/rapor_urun_performans_excel_xlsx.php', [
            'year' => $year,
        ]);
        http_response_code(500);
        die("Beklenmeyen bir hata olustu. Ref: " . htmlspecialchars((string) $ref, ENT_QUOTES, 'UTF-8'));
    }
    throw $e;
}

$sumSales = 0.0;
$sumQty = 0.0;
foreach ($rows as $r) {
    $sumSales += (float) ($r['SATILAN_TUTAR'] ?? 0);
    $sumQty += (float) ($r['SATILAN_ADET'] ?? 0);
}
$avgPriceAll = $sumQty > 0 ? round($sumSales / $sumQty, 2) : 0.0;

$decimals = (int) ($GLOBALS['parakusurat'] ?? 2);
$fmtMoney = '#,##0' . ($decimals > 0 ? '.' . str_repeat('0', $decimals) : '') . '" ₺"';
$fmtQty = '#,##0';

// Excel
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Lumen')
    ->setTitle('Urun Performans')
    ->setSubject('Urun Performans')
    ->setDescription('Urun performans raporu (AKL% urunleri, NET: satis 7,8 - iade 2,3).');

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Ürün Performansı');

$row = 1;
$sheet->setCellValue("A{$row}", ($GLOBALS['firmabaslik'] ?? 'Lumen') . " - Ürün Performansı ({$year})");
$sheet->mergeCells("A{$row}:E{$row}");
$sheet->getStyle("A{$row}")->applyFromArray([
    'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DC2626']],
]);
$sheet->getRowDimension($row)->setRowHeight(26);
$row++;

$sheet->setCellValue("A{$row}", 'Yıl:');
$sheet->setCellValue("B{$row}", $year);
$sheet->setCellValue("C{$row}", 'Net Satış:');
$sheet->setCellValue("D{$row}", $sumSales);
$sheet->setCellValue("E{$row}", 'Olusturma: ' . date('Y-m-d H:i'));
$sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
$sheet->getStyle("D{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
$row++;

$sheet->setCellValue("A{$row}", 'Toplam Adet:');
$sheet->setCellValue("B{$row}", $sumQty);
$sheet->setCellValue("C{$row}", 'Ort. Fiyat:');
$sheet->setCellValue("D{$row}", $avgPriceAll);
$sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
$sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode($fmtQty);
$sheet->getStyle("D{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
$row += 2;

$headerRow = $row;
$headers = ['Kod', 'Ürün Adı', 'Net Satış (TL)', 'Net Adet', 'Ort. Fiyat'];
$col = 'A';
foreach ($headers as $h) {
    $sheet->setCellValue($col . $headerRow, $h);
    $col++;
}
$sheet->getStyle("A{$headerRow}:E{$headerRow}")->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '991B1B']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
]);
$sheet->getRowDimension($headerRow)->setRowHeight(20);
$row++;

$dataStartRow = $row;
foreach ($rows as $idx => $r) {
    $sheet->setCellValueExplicit("A{$row}", (string) ($r['URUN_KODU'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue("B{$row}", (string) ($r['URUN_ADI'] ?? ''));
    $sheet->setCellValue("C{$row}", (float) ($r['SATILAN_TUTAR'] ?? 0));
    $sheet->setCellValue("D{$row}", (float) ($r['SATILAN_ADET'] ?? 0));
    $sheet->setCellValue("E{$row}", (float) ($r['ORT_FIYAT'] ?? 0));

    $sheet->getStyle("C{$row}:C{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
    $sheet->getStyle("D{$row}:D{$row}")->getNumberFormat()->setFormatCode($fmtQty);
    $sheet->getStyle("E{$row}:E{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
    $sheet->getStyle("C{$row}:E{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    if ($idx % 2 === 1) {
        $sheet->getStyle("A{$row}:E{$row}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F9FAFB']],
        ]);
    }
    $sheet->getStyle("A{$row}:E{$row}")->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);

    $row++;
}
$dataEndRow = $row - 1;

$sheet->getColumnDimension('A')->setWidth(14);
$sheet->getColumnDimension('B')->setWidth(48);
$sheet->getColumnDimension('C')->setWidth(18);
$sheet->getColumnDimension('D')->setWidth(12);
$sheet->getColumnDimension('E')->setWidth(16);
$sheet->getStyle("B{$dataStartRow}:B{$dataEndRow}")->getAlignment()->setWrapText(true);

$sheet->freezePane("A" . ($headerRow + 1));
if ($dataEndRow >= $dataStartRow) {
    $sheet->setAutoFilter("A{$headerRow}:E{$dataEndRow}");
}

ob_end_clean();
$filename = "urun_performans_{$year}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
