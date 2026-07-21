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

// Ay isimleri
$monthNames = [1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos', 9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'];

// SQL: FATURALI SATIŞLAR (TRCODE 7,8) + ürün kodu ön eki filtresi (_bilgi_.inc)
$monthly = [];
for ($i = 1; $i <= 12; $i++) {
    $monthly[$i] = ['C' => 0, 'NET' => 0.0];
}

$urunKosulu = urun_kodu_kosulu('ITM');

$sql = "
    SELECT
        MONTH(SL.DATE_)                                        AS M,
        COUNT(DISTINCT CASE WHEN SL.TRCODE IN (7,8) THEN SL.INVOICEREF END) AS C,
        SUM(CASE WHEN SL.TRCODE IN (7,8)
                 THEN ISNULL(SL.LINENET, (SL.PRICE * SL.AMOUNT - SL.DISTDISC)) + ISNULL(SL.VATAMNT, 0)
                 ELSE -(ISNULL(SL.LINENET, (SL.PRICE * SL.AMOUNT - SL.DISTDISC)) + ISNULL(SL.VATAMNT, 0)) END) AS NET
    FROM {$firmadonem}STLINE SL WITH(NOLOCK)
    JOIN {$firma}ITEMS ITM ON SL.STOCKREF = ITM.LOGICALREF
    WHERE {$urunKosulu}
      AND SL.TRCODE IN (2,3,7,8)
      AND SL.DATE_ >= :startDate
      AND SL.DATE_ < :endDate
      AND SL.CANCELLED  = 0
      AND SL.LINETYPE = 0
    GROUP BY MONTH(SL.DATE_);
";

try {
    $stmt = $dbh->prepare($sql);
    $stmt->execute([
        'startDate' => sprintf('%04d-01-01', $year),
        'endDate' => sprintf('%04d-01-01', $year + 1),
    ]);

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $m = (int) ($r['M'] ?? 0);
        if ($m >= 1 && $m <= 12) {
            $monthly[$m]['C'] = (int) ($r['C'] ?? 0);
            $monthly[$m]['NET'] = (float) ($r['NET'] ?? 0);
        }
    }
} catch (Throwable $e) {
    if (function_exists('app_log_exception')) {
        $ref = app_log_exception($e, 'rapor/rapor_satis_aylik_excel_xlsx.php', [
            'year' => $year,
        ]);
        http_response_code(500);
        die("Beklenmeyen bir hata olustu. Ref: " . htmlspecialchars((string) $ref, ENT_QUOTES, 'UTF-8'));
    }
    throw $e;
}

// Totals
$yearlyTotal = 0.0;
$totalCount = 0;
for ($m = 1; $m <= 12; $m++) {
    $yearlyTotal += (float) $monthly[$m]['NET'];
    $totalCount += (int) $monthly[$m]['C'];
}

$decimals = (int) ($GLOBALS['parakusurat'] ?? 2);
$fmtMoney = '#,##0' . ($decimals > 0 ? '.' . str_repeat('0', $decimals) : '') . '" ₺"';

// Excel
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Lumen')
    ->setTitle('Aylik Satis')
    ->setSubject('Aylik Satis')
    ->setDescription('Aylik satis raporu (urun kodu on ekine gore, TRCODE 7,8).');

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Aylık Satış');

$row = 1;

// Title
$sheet->setCellValue("A{$row}", ($GLOBALS['firmabaslik'] ?? 'Lumen') . " - Aylık Satış ({$year})");
$sheet->mergeCells("A{$row}:C{$row}");
$sheet->getStyle("A{$row}")->applyFromArray([
    'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DC2626']],
]);
$sheet->getRowDimension($row)->setRowHeight(26);
$row++;

// Meta
$sheet->setCellValue("A{$row}", 'Yıl:');
$sheet->setCellValue("B{$row}", $year);
$sheet->setCellValue("C{$row}", 'Olusturma: ' . date('Y-m-d H:i'));
$sheet->getStyle("A{$row}:C{$row}")->getFont()->setBold(true);
$row += 2;

// Header
$headerRow = $row;
$headers = ['Ay', 'İşlem', 'Tutar'];
$col = 'A';
foreach ($headers as $h) {
    $sheet->setCellValue($col . $headerRow, $h);
    $col++;
}
$sheet->getStyle("A{$headerRow}:C{$headerRow}")->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '991B1B']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
]);
$sheet->getRowDimension($headerRow)->setRowHeight(20);
$row++;

$dataStartRow = $row;
for ($m = 1; $m <= 12; $m++) {
    $sheet->setCellValue("A{$row}", $monthNames[$m]);
    $sheet->setCellValue("B{$row}", (int) $monthly[$m]['C']);
    $sheet->setCellValue("C{$row}", (float) $monthly[$m]['NET']);

    $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle("C{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode($fmtMoney);

    if (($m % 2) === 0) {
        $sheet->getStyle("A{$row}:C{$row}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F9FAFB']],
        ]);
    }
    $sheet->getStyle("A{$row}:C{$row}")->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);

    $row++;
}
$dataEndRow = $row - 1;

// Totals
$sheet->setCellValue("A{$row}", 'TOPLAM');
$sheet->setCellValue("B{$row}", $totalCount);
$sheet->setCellValue("C{$row}", $yearlyTotal);
$sheet->getStyle("A{$row}:C{$row}")->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E7EB']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
]);
$sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle("C{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode($fmtMoney);

// Layout
$sheet->getColumnDimension('A')->setWidth(18);
$sheet->getColumnDimension('B')->setWidth(10);
$sheet->getColumnDimension('C')->setWidth(20);

$sheet->freezePane("A" . ($headerRow + 1));
$sheet->setAutoFilter("A{$headerRow}:C{$dataEndRow}");

// Output
ob_end_clean();
$filename = "aylik_satis_{$year}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

