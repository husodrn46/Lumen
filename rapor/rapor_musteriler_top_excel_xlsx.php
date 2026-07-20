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
$year = isset($_GET['year']) && is_numeric($_GET['year']) ? (int) $_GET['year'] : $currentYear;

$sql = "
  SELECT
    C.CODE           AS CUSTOMER_CODE,
    C.DEFINITION_    AS CUSTOMER_NAME,
    C.CITY           AS CITY,
    SUM(CASE WHEN L.TRCODE IN (7,8)
             THEN ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)
             ELSE -(ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)) END) AS TOTAL_SALES
  FROM {$firmadonem}INVOICE I WITH(NOLOCK)
  JOIN {$firmadonem}STLINE L ON L.INVOICEREF = I.LOGICALREF AND L.TRCODE IN (2,3,7,8)
  JOIN {$firma}CLCARD C ON I.CLIENTREF = C.LOGICALREF
  WHERE C.ACTIVE=0
    AND I.CANCELLED=0
    AND I.TRCODE IN (2,3,7,8)
    AND YEAR(I.DATE_) = :y
  GROUP BY C.CODE, C.DEFINITION_, C.CITY
  HAVING SUM(CASE WHEN L.TRCODE IN (7,8)
                  THEN ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)
                  ELSE -(ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)) END) > 0
  ORDER BY TOTAL_SALES DESC
";

try {
    $stmt = $dbh->prepare($sql);
    $stmt->execute(['y' => $year]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if (function_exists('app_log_exception')) {
        $ref = app_log_exception($e, 'rapor/rapor_musteriler_top_excel_xlsx.php', [
            'year' => $year,
        ]);
        http_response_code(500);
        die("Beklenmeyen bir hata olustu. Ref: " . htmlspecialchars((string) $ref, ENT_QUOTES, 'UTF-8'));
    }
    throw $e;
}

$sumSales = 0.0;
foreach ($rows as $r) {
    $sumSales += (float) ($r['TOTAL_SALES'] ?? 0);
}

$decimals = (int) ($GLOBALS['parakusurat'] ?? 2);
$fmtMoney = '#,##0' . ($decimals > 0 ? '.' . str_repeat('0', $decimals) : '') . '" ₺"';

// Excel
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Lumen')
    ->setTitle('Musteriler Top')
    ->setSubject('Musteriler Top')
    ->setDescription('En iyi musteriler (tum urunler, TRCODE 7,8).');

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Müşteriler');

$row = 1;
$sheet->setCellValue("A{$row}", ($GLOBALS['firmabaslik'] ?? 'Lumen') . " - En Değerli Müşteriler ({$year})");
$sheet->mergeCells("A{$row}:D{$row}");
$sheet->getStyle("A{$row}")->applyFromArray([
    'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DC2626']],
]);
$sheet->getRowDimension($row)->setRowHeight(26);
$row++;

$sheet->setCellValue("A{$row}", 'Yıl:');
$sheet->setCellValue("B{$row}", $year);
$sheet->setCellValue("C{$row}", 'Olusturma:');
$sheet->setCellValue("D{$row}", date('Y-m-d H:i'));
$sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
$row += 2;

$headerRow = $row;
$headers = ['Kod', 'Müşteri', 'Şehir', 'Satış'];
$col = 'A';
foreach ($headers as $h) {
    $sheet->setCellValue($col . $headerRow, $h);
    $col++;
}
$sheet->getStyle("A{$headerRow}:D{$headerRow}")->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '991B1B']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
]);
$sheet->getRowDimension($headerRow)->setRowHeight(20);
$row++;

$dataStartRow = $row;
foreach ($rows as $idx => $r) {
    $sheet->setCellValueExplicit("A{$row}", (string) ($r['CUSTOMER_CODE'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue("B{$row}", (string) ($r['CUSTOMER_NAME'] ?? ''));
    $sheet->setCellValue("C{$row}", (string) ($r['CITY'] ?? ''));
    $sheet->setCellValue("D{$row}", (float) ($r['TOTAL_SALES'] ?? 0));

    $sheet->getStyle("D{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
    $sheet->getStyle("D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    if ($idx % 2 === 1) {
        $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F9FAFB']],
        ]);
    }
    $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);

    $row++;
}
$dataEndRow = $row - 1;

$sheet->setCellValue("A{$row}", 'TOPLAM');
$sheet->mergeCells("A{$row}:C{$row}");
$sheet->setCellValue("D{$row}", $sumSales);
$sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E7EB']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
]);
$sheet->getStyle("D{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
$sheet->getStyle("D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

$sheet->getColumnDimension('A')->setWidth(14);
$sheet->getColumnDimension('B')->setWidth(40);
$sheet->getColumnDimension('C')->setWidth(18);
$sheet->getColumnDimension('D')->setWidth(18);
$sheet->getStyle("B{$dataStartRow}:B{$dataEndRow}")->getAlignment()->setWrapText(true);

$sheet->freezePane("A" . ($headerRow + 1));
if ($dataEndRow >= $dataStartRow) {
    $sheet->setAutoFilter("A{$headerRow}:D{$dataEndRow}");
}

ob_end_clean();
$filename = "en_degerli_musteriler_{$year}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

