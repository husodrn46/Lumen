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

function raporDateToTimestamp(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    $ts = strtotime((string) $value);
    return ($ts === false) ? null : $ts;
}

// Detaylı liste
$sql = "
SELECT
    C.DUEDATE,
    C.AMOUNT,
    C.NEWSERINO,
    C.SETDATE,
    ISNULL(CL.DEFINITION_, '---') AS CARI,
    ISNULL(R.GENEXP1, '') AS ACIKLAMA
FROM {$firmadonem}CSCARD C WITH(NOLOCK)
INNER JOIN (
    SELECT
        T.CSREF,
        T.CARDREF,
        T.ROLLREF,
        ROW_NUMBER() OVER (PARTITION BY T.CSREF ORDER BY T.LOGICALREF DESC) AS RN
    FROM {$firmadonem}CSTRANS T WITH(NOLOCK)
    WHERE T.TRCODE IN (2, 3, 4, 5, 6, 7, 8)
) TX ON TX.CSREF = C.LOGICALREF AND TX.RN = 1
LEFT JOIN {$firmadonem}CSROLL R WITH(NOLOCK) ON R.LOGICALREF = TX.ROLLREF
LEFT JOIN {$firma}CLCARD CL WITH(NOLOCK) ON CL.LOGICALREF = TX.CARDREF
WHERE C.CURRSTAT = '9'
ORDER BY C.DUEDATE
";

try {
    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $rows = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $amount = isset($r['AMOUNT']) ? (float) $r['AMOUNT'] : 0.0;
        $dueTs = raporDateToTimestamp($r['DUEDATE'] ?? null);
        $setTs = raporDateToTimestamp($r['SETDATE'] ?? null);

        $rows[] = [
            'DUE_ISO' => $dueTs !== null ? date('Y-m-d', $dueTs) : '',
            'DUE_FMT' => $dueTs !== null ? date('d.m.Y', $dueTs) : '-',
            'AMOUNT' => $amount,
            'NEWSERINO' => (string) ($r['NEWSERINO'] ?? ''),
            'SET_ISO' => $setTs !== null ? date('Y-m-d', $setTs) : '',
            'SET_FMT' => $setTs !== null ? date('d.m.Y', $setTs) : '-',
            'CARI' => (string) ($r['CARI'] ?? ''),
            'ACIKLAMA' => (string) ($r['ACIKLAMA'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    if (function_exists('app_log_exception')) {
        $ref = app_log_exception($e, 'rapor/rapor_kendi_cekler_excel_xlsx.php');
        http_response_code(500);
        die("Beklenmeyen bir hata olustu. Ref: " . htmlspecialchars((string) $ref, ENT_QUOTES, 'UTF-8'));
    }
    throw $e;
}

$totalCount = count($rows);
$totalAmount = 0.0;
foreach ($rows as $r) {
    $totalAmount += (float) ($r['AMOUNT'] ?? 0);
}

$decimals = (int) ($GLOBALS['parakusurat'] ?? 2);
$fmtMoney = '#,##0' . ($decimals > 0 ? '.' . str_repeat('0', $decimals) : '') . '" ₺"';

// Excel
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Lumen')
    ->setTitle('Kendi Ceklerimiz')
    ->setSubject('Kendi Ceklerimiz')
    ->setDescription('Kendi ceklerimiz raporu.');

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Çek Listesi');

$row = 1;
$sheet->setCellValue("A{$row}", ($GLOBALS['firmabaslik'] ?? 'Lumen') . ' - Kendi Çeklerimiz');
$sheet->mergeCells("A{$row}:F{$row}");
$sheet->getStyle("A{$row}")->applyFromArray([
    'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DC2626']],
]);
$sheet->getRowDimension($row)->setRowHeight(26);
$row++;

$sheet->setCellValue("A{$row}", 'Olusturma:');
$sheet->setCellValue("B{$row}", date('Y-m-d H:i'));
$sheet->setCellValue("D{$row}", 'Adet:');
$sheet->setCellValue("E{$row}", $totalCount);
$sheet->setCellValue("F{$row}", '');
$sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
$row++;

$sheet->setCellValue("A{$row}", 'Toplam Tutar:');
$sheet->setCellValue("B{$row}", $totalAmount);
$sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);
$sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
$row += 2;

$headerRow = $row;
$headers = ['Vade Tarihi', 'Tutar', 'Seri No', 'Alım Tarihi', 'Verilen Cari', 'Bordro Açıklaması'];
$col = 'A';
foreach ($headers as $h) {
    $sheet->setCellValue($col . $headerRow, $h);
    $col++;
}
$sheet->getStyle("A{$headerRow}:F{$headerRow}")->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '991B1B']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
]);
$sheet->getRowDimension($headerRow)->setRowHeight(20);
$row++;

$dataStartRow = $row;
foreach ($rows as $idx => $r) {
    $sheet->setCellValue("A{$row}", (string) ($r['DUE_FMT'] ?? '-'));
    $sheet->setCellValue("B{$row}", (float) ($r['AMOUNT'] ?? 0));
    $sheet->setCellValueExplicit("C{$row}", (string) ($r['NEWSERINO'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue("D{$row}", (string) ($r['SET_FMT'] ?? '-'));
    $sheet->setCellValue("E{$row}", (string) ($r['CARI'] ?? ''));
    $sheet->setCellValue("F{$row}", (string) ($r['ACIKLAMA'] ?? ''));

    $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
    $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    if ($idx % 2 === 1) {
        $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F9FAFB']],
        ]);
    }
    $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);

    $row++;
}
$dataEndRow = $row - 1;

$sheet->getColumnDimension('A')->setWidth(14);
$sheet->getColumnDimension('B')->setWidth(18);
$sheet->getColumnDimension('C')->setWidth(14);
$sheet->getColumnDimension('D')->setWidth(14);
$sheet->getColumnDimension('E')->setWidth(30);
$sheet->getColumnDimension('F')->setWidth(40);
$sheet->getStyle("E{$dataStartRow}:F{$dataEndRow}")->getAlignment()->setWrapText(true);

$sheet->freezePane("A" . ($headerRow + 1));
if ($dataEndRow >= $dataStartRow) {
    $sheet->setAutoFilter("A{$headerRow}:F{$dataEndRow}");
}

ob_end_clean();
$filename = 'kendi_cekler_raporu_' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
