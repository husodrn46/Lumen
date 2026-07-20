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

// CSCARD.CURRSTAT -> cek durumu etiketi (rapor_cek_hesap.php ile ayni)
function cekDurumBilgi(int $cs): array
{
    return match ($cs) {
        1 => ['Portfoyde', 'portfoy'],
        2 => ['Ciro Edildi', 'ciro'],
        3 => ['Teminatta', 'diger'],
        4 => ['Tahsilde', 'tahsil'],
        5 => ['Teminat Tahsilde', 'tahsil'],
        6 => ['Tahsil Edildi', 'tahsil'],
        8 => ['Tahsil Edildi', 'tahsil'],
        default => ['Diger', 'diger'],
    };
}

// Durum filtresi (rapor_cek_hesap.php ile ayni)
$durum = (string) ($_GET['durum'] ?? 'portfoy');
if (!in_array($durum, ['portfoy', 'ciro', 'tahsil', 'tumu'], true)) {
    $durum = 'portfoy';
}
$currstatFiltre = match ($durum) {
    'ciro'   => 'IN (2)',
    'tahsil' => 'IN (4,5,6,8)',
    'tumu'   => 'IN (1,2,3,4,5,6,8)',
    default  => 'IN (1)',
};

// Detaylı liste (rapor/rapor_cek_hesap.php ile aynı filtreler)
$sql = "
    SELECT
        CAST(LGMAIN.DUEDATE AS DATE) AS DUEDATE,
        LGMAIN.TRNET AS TUTAR,
        LGMAIN.OWING AS KIMDEN,
        LGMAIN.PORTFOYNO,
        LGMAIN.NEWSERINO,
        CAST(LGMAIN.SETDATE AS DATE) AS SETDATE,
        LGMAIN.CURRSTAT AS DURUM_KOD,
        ISNULL(CL.CODE, '') AS CARIHESAP
    FROM {$firmadonem}CSCARD LGMAIN WITH(NOLOCK)
    LEFT JOIN (
        SELECT
            T.CSREF,
            T.CARDREF,
            ROW_NUMBER() OVER (PARTITION BY T.CSREF ORDER BY T.LOGICALREF DESC) AS RN
        FROM {$firmadonem}CSTRANS T WITH(NOLOCK)
    ) TX ON TX.CSREF = LGMAIN.LOGICALREF AND TX.RN = 1
    LEFT JOIN {$firma}CLCARD CL WITH(NOLOCK) ON CL.LOGICALREF = TX.CARDREF
    WHERE LGMAIN.CURRSTAT {$currstatFiltre} AND LGMAIN.STATUS IN(0,1) AND LGMAIN.DOC=1
    ORDER BY CAST(LGMAIN.DUEDATE AS DATE) ASC, LGMAIN.OWING, LGMAIN.TRNET
";

try {
    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $rows = [];

    $todayTs = strtotime(date('Y-m-d')) ?: time();
    $next7DaysTs = strtotime('+7 days', $todayTs) ?: $todayTs;

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $amount = isset($r['TUTAR']) ? (float) $r['TUTAR'] : 0.0;
        $dueTs = raporDateToTimestamp($r['DUEDATE'] ?? null);
        $setTs = raporDateToTimestamp($r['SETDATE'] ?? null);

        $rows[] = [
            'DUE_FMT' => $dueTs !== null ? date('d.m.Y', $dueTs) : '-',
            'STATUS' => cekDurumBilgi((int) ($r['DURUM_KOD'] ?? 0))[0],
            'AMOUNT' => $amount,
            'KIMDEN' => (string) ($r['KIMDEN'] ?? ''),
            'PORTFOYNO' => (string) ($r['PORTFOYNO'] ?? ''),
            'NEWSERINO' => (string) ($r['NEWSERINO'] ?? ''),
            'SET_FMT' => $setTs !== null ? date('d.m.Y', $setTs) : '-',
            'CARIHESAP' => (string) ($r['CARIHESAP'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    if (function_exists('app_log_exception')) {
        $ref = app_log_exception($e, 'rapor/rapor_cek_hesap_excel_xlsx.php');
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
    ->setTitle('Cek Hesap')
    ->setSubject('Cek Hesap')
    ->setDescription('Cek hesap dokumu.');

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Çek Hesap');

$row = 1;
$sheet->setCellValue("A{$row}", ($GLOBALS['firmabaslik'] ?? 'Lumen') . ' - Çek Hesap Dökümü');
$sheet->mergeCells("A{$row}:H{$row}");
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
$sheet->setCellValue("F{$row}", 'Toplam:');
$sheet->setCellValue("G{$row}", $totalAmount);
$sheet->getStyle("A{$row}:G{$row}")->getFont()->setBold(true);
$sheet->getStyle("G{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
$row += 2;

$headerRow = $row;
$headers = ['Vade Tarihi', 'Durum', 'Tutar', 'Kimden', 'Portföy No', 'Seri No', 'Alım Tarihi', 'Cari Kodu'];
$col = 'A';
foreach ($headers as $h) {
    $sheet->setCellValue($col . $headerRow, $h);
    $col++;
}
$sheet->getStyle("A{$headerRow}:H{$headerRow}")->applyFromArray([
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
    $sheet->setCellValue("B{$row}", (string) ($r['STATUS'] ?? 'Normal'));
    $sheet->setCellValue("C{$row}", (float) ($r['AMOUNT'] ?? 0));
    $sheet->setCellValue("D{$row}", (string) ($r['KIMDEN'] ?? ''));
    $sheet->setCellValue("E{$row}", (string) ($r['PORTFOYNO'] ?? ''));
    $sheet->setCellValueExplicit("F{$row}", (string) ($r['NEWSERINO'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue("G{$row}", (string) ($r['SET_FMT'] ?? '-'));
    $sheet->setCellValue("H{$row}", (string) ($r['CARIHESAP'] ?? ''));

    $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
    $sheet->getStyle("C{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    if ($idx % 2 === 1) {
        $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F9FAFB']],
        ]);
    }
    $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);

    $row++;
}
$dataEndRow = $row - 1;

// Layout
$sheet->getColumnDimension('A')->setWidth(14);
$sheet->getColumnDimension('B')->setWidth(12);
$sheet->getColumnDimension('C')->setWidth(18);
$sheet->getColumnDimension('D')->setWidth(22);
$sheet->getColumnDimension('E')->setWidth(14);
$sheet->getColumnDimension('F')->setWidth(14);
$sheet->getColumnDimension('G')->setWidth(14);
$sheet->getColumnDimension('H')->setWidth(14);

$sheet->freezePane("A" . ($headerRow + 1));
if ($dataEndRow >= $dataStartRow) {
    $sheet->setAutoFilter("A{$headerRow}:H{$dataEndRow}");
}

ob_end_clean();
$filename = 'cek_hesap_dokumu_' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
