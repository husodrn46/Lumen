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

// Yetki kontrolu
if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

$canSeeBalance = (m_p_yetki($terminalkullanici, 'CR1') == 1);
if (!$canSeeBalance) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

function money_parse_tr(string $raw): float
{
    $raw = trim($raw);
    if ($raw === '') {
        return 0.0;
    }

    $norm = str_replace(' ', '', $raw);
    // 1.000,50 -> 1000.50 (TR format)
    if (str_contains($norm, ',') && str_contains($norm, '.')) {
        $norm = str_replace('.', '', $norm);
        $norm = str_replace(',', '.', $norm);
    } elseif (str_contains($norm, ',')) {
        $norm = str_replace(',', '.', $norm);
    }
    return is_numeric($norm) ? (float) $norm : 0.0;
}

function report_excel_date(mixed $value): ?DateTime
{
    if ($value === null || $value === '') {
        return null;
    }

    try {
        return new DateTime((string) $value);
    } catch (Throwable) {
        return null;
    }
}

$rawQ = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$q = $rawQ !== '' ? turkce($rawQ) : '';

$durum = 'borclu';
$hideZero = true;

$rawMin = isset($_GET['min']) ? trim((string) $_GET['min']) : '';
$minAbs = max(0.0, money_parse_tr($rawMin));

$defaultExclude = ['genel gider'];
$rawExclude = isset($_GET['exclude']) ? trim((string) $_GET['exclude']) : '';
$excludeList = [];
if (!isset($_GET['exclude'])) {
    $excludeList = $defaultExclude;
    $rawExclude = implode(', ', $defaultExclude);
} else {
    $parts = preg_split('/[\\r\\n,;]+/', $rawExclude) ?: [];
    foreach ($parts as $p) {
        $p = trim((string) $p);
        if ($p !== '') {
            $excludeList[] = $p;
        }
    }
    $excludeList = array_values(array_unique($excludeList));
    $excludeList = array_slice($excludeList, 0, 20);
}

$defaultLimit = isset($carilistesayisi) ? (int) $carilistesayisi : 1000;
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : $defaultLimit;
$limit = max(50, min($limit, 10000));

$rows = [];
try {
    // Bakiye ifadesi (Pozitif: Borclu, Negatif: Alacakli)
    $balanceExpr = "(ISNULL(G.DEBIT, 0) - ISNULL(G.CREDIT, 0))";

    $where = [];
    $params = [];

    $where[] = "C.ACTIVE = 0";

    if ($q !== '') {
        $like = "%{$q}%";
        $where[] = "
            (
                 REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                   C.DEFINITION_, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')
                   COLLATE Turkish_CI_AS LIKE :p1
              OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                   C.CODE, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')
                   COLLATE Turkish_CI_AS LIKE :p2
              OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                   C.CITY, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')
                   COLLATE Turkish_CI_AS LIKE :p3
            )
        ";
        $params[':p1'] = $like;
        $params[':p2'] = $like;
        $params[':p3'] = $like;
    }

    $where[] = "{$balanceExpr} > 0";

    if ($minAbs > 0) {
        $where[] = "{$balanceExpr} >= :min_abs";
        $params[':min_abs'] = $minAbs;
    }

    if ($excludeList !== []) {
        $normDefExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(C.DEFINITION_, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c') COLLATE Turkish_CI_AS";
        $normCodeExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(C.CODE, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c') COLLATE Turkish_CI_AS";

        $i = 0;
        foreach ($excludeList as $ex) {
            $i++;
            $exNorm = turkce($ex);
            $like = "%{$exNorm}%";
            // NOTE: SQLSRV/PDO named params cannot be re-used: each placeholder must be unique.
            $paramDef = ":ex_def{$i}";
            $paramCode = ":ex_code{$i}";
            $where[] = "({$normDefExpr} NOT LIKE {$paramDef} AND {$normCodeExpr} NOT LIKE {$paramCode})";
            $params[$paramDef] = $like;
            $params[$paramCode] = $like;
        }
    }

    $whereSql = implode("\n    AND ", $where);

    $orderSql = "{$balanceExpr} DESC";

    $sqlList = "
        SELECT TOP {$limit}
            C.CODE AS KODU,
            C.DEFINITION_ AS UNVANI,
            C.CITY AS SEHIR,
            ISNULL(G.DEBIT, 0) AS BORC,
            ISNULL(G.CREDIT, 0) AS ALACAK,
            LS.SON_URUN_ALIMI,
            CAST('' AS NVARCHAR(50)) AS NOT1,
            CAST('' AS NVARCHAR(50)) AS NOT2,
            {$balanceExpr} AS BAKIYE
        FROM {$firma}CLCARD C WITH(NOLOCK)
        LEFT JOIN {$firmadonemx}GNTOTCL G WITH(NOLOCK)
            ON G.CARDREF = C.LOGICALREF AND G.TOTTYP = 1
        OUTER APPLY (
            SELECT TOP 1 I.DATE_ AS SON_URUN_ALIMI
            FROM {$firmadonem}INVOICE I WITH(NOLOCK)
            WHERE I.CLIENTREF = C.LOGICALREF
              AND I.CANCELLED = 0
              AND I.TRCODE IN (7, 8)
              AND EXISTS (
                  SELECT 1
                  FROM {$firmadonem}STLINE L WITH(NOLOCK)
                  WHERE L.INVOICEREF = I.LOGICALREF
                    AND L.CANCELLED = 0
                    AND L.LINETYPE = 0
              )
            ORDER BY I.DATE_ DESC, I.LOGICALREF DESC
        ) LS
        WHERE {$whereSql}
        ORDER BY {$orderSql}
    ";

    $stmt = $dbh->prepare($sqlList);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if (function_exists('app_log_exception')) {
        $ref = app_log_exception($e, 'rapor/rapor_cari_borc_alacak_excel_xlsx.php', [
            'q' => mb_substr($rawQ, 0, 120),
            'durum' => $durum,
            'hide_zero' => $hideZero,
            'limit' => $limit,
            'min_abs' => $minAbs,
        ]);
        http_response_code(500);
        die("Beklenmeyen bir hata olustu. Ref: " . htmlspecialchars((string) $ref, ENT_QUOTES, 'UTF-8'));
    }
    throw $e;
}

// Totals
$totals = [
    'BORC' => 0.0,
    'ALACAK' => 0.0,
    'NET' => 0.0,
    'COUNT' => 0,
];
foreach ($rows as $r) {
    $b = (float) ($r['BAKIYE'] ?? 0);
    $totals['COUNT']++;
    $totals['NET'] += $b;
    $totals['BORC'] += (float) ($r['BORC'] ?? 0);
    $totals['ALACAK'] += (float) ($r['ALACAK'] ?? 0);
}

// Excel
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Lumen')
    ->setTitle('Cari Borc')
    ->setSubject('Cari Borc')
    ->setDescription('Cari borclu bakiye ozeti.');

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Cari Bakiye');

$decimals = (int) ($GLOBALS['parakusurat'] ?? 2);
$fmtMoney = '#,##0' . ($decimals > 0 ? '.' . str_repeat('0', $decimals) : '') . '" ₺"';
// Negatifleri kirmizi goster
$fmtMoneySigned = $fmtMoney . ';[Red]-' . $fmtMoney;

$row = 1;

// Title
$sheet->setCellValue("A{$row}", ($GLOBALS['firmabaslik'] ?? 'Lumen') . ' - Cari Borc');
$sheet->mergeCells("A{$row}:I{$row}");
$sheet->getStyle("A{$row}")->applyFromArray([
    'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DC2626']],
]);
$sheet->getRowDimension($row)->setRowHeight(26);
$row++;

// Meta
$sheet->setCellValue("A{$row}", 'Olusturma:');
$sheet->setCellValue("B{$row}", date('Y-m-d H:i'));
$sheet->setCellValue("D{$row}", 'Durum:');
$sheet->setCellValue("E{$row}", $durum);
$sheet->getStyle("A{$row}:I{$row}")->getFont()->setBold(true);
$row++;

$sheet->setCellValue("A{$row}", 'Ara:');
$sheet->setCellValue("B{$row}", $rawQ !== '' ? $rawQ : '-');
$sheet->setCellValue("D{$row}", 'Min:');
$sheet->setCellValue("E{$row}", $minAbs);
$sheet->getStyle("A{$row}:I{$row}")->getFont()->setBold(true);
$sheet->getStyle("E{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
$row++;

$sheet->setCellValue("A{$row}", 'Haric Tut:');
$sheet->setCellValue("B{$row}", $rawExclude !== '' ? $rawExclude : '-');
$sheet->mergeCells("B{$row}:I{$row}");
$sheet->getStyle("A{$row}")->getFont()->setBold(true);
$sheet->getStyle("B{$row}")->getAlignment()->setWrapText(true);
$row += 2;

// Header
$headerRow = $row;
$headers = ['Cari Kodu', 'Unvanı', 'Borç', 'Alacak', 'Bakiye Borç', 'Son Ürün Alımı', 'Not1', 'Not2', 'Durum'];
$col = 'A';
foreach ($headers as $h) {
    $sheet->setCellValue($col . $headerRow, $h);
    $col++;
}
$sheet->getStyle("A{$headerRow}:I{$headerRow}")->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '991B1B']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
]);
$sheet->getRowDimension($headerRow)->setRowHeight(20);
$row++;

$dataStartRow = $row;
foreach ($rows as $idx => $r) {
    $bakiye = (float) ($r['BAKIYE'] ?? 0);
    $sonUrunAlimi = report_excel_date($r['SON_URUN_ALIMI'] ?? null);
    $durumText = 'Sıfır';
    if ($bakiye > 0) {
        $durumText = 'Borçlu';
    } elseif ($bakiye < 0) {
        $durumText = 'Alacaklı';
    }

    $sheet->setCellValueExplicit("A{$row}", (string) ($r['KODU'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue("B{$row}", (string) ($r['UNVANI'] ?? ''));
    $sheet->setCellValue("C{$row}", (float) ($r['BORC'] ?? 0));
    $sheet->setCellValue("D{$row}", (float) ($r['ALACAK'] ?? 0));
    $sheet->setCellValue("E{$row}", abs($bakiye));
    if ($sonUrunAlimi !== null) {
        $sheet->setCellValue("F{$row}", \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($sonUrunAlimi));
    } else {
        $sheet->setCellValue("F{$row}", '-');
    }
    $sheet->setCellValue("G{$row}", (string) ($r['NOT1'] ?? ''));
    $sheet->setCellValue("H{$row}", (string) ($r['NOT2'] ?? ''));
    $sheet->setCellValue("I{$row}", $durumText);

    $sheet->getStyle("C{$row}:E{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
    $sheet->getStyle("C{$row}:E{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    if ($sonUrunAlimi !== null) {
        $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode('dd.mm.yyyy');
    }

    if ($idx % 2 === 1) {
        $sheet->getStyle("A{$row}:I{$row}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F9FAFB']],
        ]);
    }
    $sheet->getStyle("A{$row}:I{$row}")->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);

    $row++;
}
$dataEndRow = $row - 1;

// Totals row
$sheet->setCellValue("A{$row}", 'TOPLAM');
$sheet->mergeCells("A{$row}:B{$row}");
$sheet->setCellValue("C{$row}", $totals['BORC']);
$sheet->setCellValue("D{$row}", $totals['ALACAK']);
$sheet->setCellValue("E{$row}", abs($totals['NET']));
$sheet->setCellValue("I{$row}", 'Net');
$sheet->getStyle("A{$row}:I{$row}")->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E7EB']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
]);
$sheet->getStyle("C{$row}:E{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
$sheet->getStyle("C{$row}:E{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

// Column widths
$sheet->getColumnDimension('A')->setWidth(14);
$sheet->getColumnDimension('B')->setWidth(42);
$sheet->getColumnDimension('C')->setWidth(16);
$sheet->getColumnDimension('D')->setWidth(16);
$sheet->getColumnDimension('E')->setWidth(18);
$sheet->getColumnDimension('F')->setWidth(18);
$sheet->getColumnDimension('G')->setWidth(14);
$sheet->getColumnDimension('H')->setWidth(14);
$sheet->getColumnDimension('I')->setWidth(12);
$sheet->getStyle("B{$dataStartRow}:B{$dataEndRow}")->getAlignment()->setWrapText(true);

// Freeze + filter
$sheet->freezePane("A" . ($headerRow + 1));
if ($dataEndRow >= $dataStartRow) {
    $sheet->setAutoFilter("A{$headerRow}:I{$dataEndRow}");
}

// Output
ob_end_clean();
$filename = 'cari_borc_alacak_' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

