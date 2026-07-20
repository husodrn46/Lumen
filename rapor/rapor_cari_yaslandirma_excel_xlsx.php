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
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

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

/**
 * Tahsilat önceliği için basit risk skoru ve aksiyon önerisi üretir.
 */
function aging_priority_meta_excel(float $d31_60, float $d61_90, float $d90p, float $total): array
{
    $overdue = max(0.0, $d31_60 + $d61_90 + $d90p);
    $overdueRatio = $total > 0 ? ($overdue / $total) * 100 : 0.0;
    $priorityScore = ($d90p * 1.00) + ($d61_90 * 0.65) + ($d31_60 * 0.35);
    $severeDebt = $d90p + $d61_90;

    if ($d90p >= 50000 || $overdueRatio >= 80 || $severeDebt >= 100000) {
        return ['level' => 'kritik', 'label' => 'Kritik', 'action' => 'Ayni gun arama + odeme plani', 'score' => $priorityScore, 'overdue' => $overdue, 'overdue_ratio' => $overdueRatio];
    }
    if ($d90p > 0 || $overdueRatio >= 55 || $severeDebt >= 30000) {
        return ['level' => 'yuksek', 'label' => 'Yuksek', 'action' => '24 saat icinde takip aramasi', 'score' => $priorityScore, 'overdue' => $overdue, 'overdue_ratio' => $overdueRatio];
    }
    if ($overdue > 0 || $overdueRatio >= 25) {
        return ['level' => 'orta', 'label' => 'Orta', 'action' => 'Bu hafta icerisinde tahsilat takibi', 'score' => $priorityScore, 'overdue' => $overdue, 'overdue_ratio' => $overdueRatio];
    }
    return ['level' => 'dusuk', 'label' => 'Dusuk', 'action' => 'Rutin izleme', 'score' => $priorityScore, 'overdue' => $overdue, 'overdue_ratio' => $overdueRatio];
}

// As-of date
$today = date('Y-m-d');
$asOf = isset($_GET['tarih']) ? trim((string) $_GET['tarih']) : $today;
if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $asOf)) {
    $asOf = $today;
}

// Filters
$rawQ = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$q = $rawQ !== '' ? turkce($rawQ) : '';

$rawMin = isset($_GET['min']) ? trim((string) $_GET['min']) : '';
$minTotal = 0.0;
if ($rawMin !== '') {
    $minNorm = str_replace(' ', '', $rawMin);
    if (str_contains($minNorm, ',') && str_contains($minNorm, '.')) {
        $minNorm = str_replace('.', '', $minNorm);
        $minNorm = str_replace(',', '.', $minNorm);
    } elseif (str_contains($minNorm, ',')) {
        $minNorm = str_replace(',', '.', $minNorm);
    }
    if (is_numeric($minNorm)) {
        $minTotal = max(0.0, (float) $minNorm);
    }
}

$priorityFilter = isset($_GET['oncelik']) ? strtolower(trim((string) $_GET['oncelik'])) : 'all';
$allowedPriorityFilters = ['all', 'kritik', 'yuksek', 'orta', 'dusuk'];
if (!in_array($priorityFilter, $allowedPriorityFilters, true)) {
    $priorityFilter = 'all';
}

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

$defaultLimit = 1000;
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : $defaultLimit;
$limit = max(100, min($limit, 10000));

$params = [
    ':asof' => $asOf,
];

$whereC = [];
$whereC[] = "C.ACTIVE = 0";
// Müşteri kartları (döviz modülü ile uyumlu)
$whereC[] = "C.CARDTYPE IN (3, 10)";

if ($q !== '') {
    $like = "%{$q}%";
    $whereC[] = "
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
        $whereC[] = "({$normDefExpr} NOT LIKE {$paramDef} AND {$normCodeExpr} NOT LIKE {$paramCode})";
        $params[$paramDef] = $like;
        $params[$paramCode] = $like;
    }
}

$whereCSql = implode("\n        AND ", $whereC);

$extraNetWhere = "";
if ($minTotal > 0) {
    $extraNetWhere .= "\n        AND N.NET_BAKIYE >= :min_total";
    $params[':min_total'] = $minTotal;
}

// NOTE: Aging hesaplamasi, tahsilatlari en eski borclara (en eski islem tarihine) dogru FIFO mantigiyla dagitir.
$sql = "
WITH PARAMS AS (
    SELECT CAST(:asof AS DATE) AS ASOF_DATE
),
CARILER AS (
    SELECT
        C.LOGICALREF AS CARIID,
        C.CODE AS KODU,
        C.DEFINITION_ AS UNVANI,
        C.CITY AS SEHIR
    FROM {$firma}CLCARD C WITH(NOLOCK)
    WHERE {$whereCSql}
),
NET AS (
    SELECT
        L.CLIENTREF AS CARIID,
        SUM(CASE WHEN L.SIGN = 0 THEN CAST(L.AMOUNT AS DECIMAL(18,2)) ELSE CAST(0 AS DECIMAL(18,2)) END) AS DEBIT_TOTAL,
        SUM(CASE WHEN L.SIGN = 1 THEN CAST(L.AMOUNT AS DECIMAL(18,2)) ELSE CAST(0 AS DECIMAL(18,2)) END) AS CREDIT_TOTAL,
        SUM(CASE WHEN L.SIGN = 0 THEN CAST(L.AMOUNT AS DECIMAL(18,2)) ELSE -CAST(L.AMOUNT AS DECIMAL(18,2)) END) AS NET_BAKIYE
    FROM {$firmadonem}CLFLINE L WITH(NOLOCK)
    INNER JOIN CARILER C ON C.CARIID = L.CLIENTREF
    CROSS JOIN PARAMS P
    WHERE L.CANCELLED = 0
      AND L.DATE_ < DATEADD(DAY, 1, P.ASOF_DATE)
    GROUP BY L.CLIENTREF
),
BORCLU AS (
    SELECT
        N.CARIID,
        N.DEBIT_TOTAL,
        N.CREDIT_TOTAL,
        N.NET_BAKIYE
    FROM NET N
    WHERE N.NET_BAKIYE > 0
    {$extraNetWhere}
),
DEBIT_LINES AS (
    SELECT
        L.CLIENTREF AS CARIID,
        CAST(L.DATE_ AS DATE) AS ISLEM_TARIHI,
        CAST(L.AMOUNT AS DECIMAL(18,2)) AS TUTAR,
        SUM(CAST(L.AMOUNT AS DECIMAL(18,2)))
            OVER (PARTITION BY L.CLIENTREF ORDER BY L.DATE_ ASC, L.LOGICALREF ASC)
            AS CUM_DEBIT
    FROM {$firmadonem}CLFLINE L WITH(NOLOCK)
    INNER JOIN BORCLU B ON B.CARIID = L.CLIENTREF
    CROSS JOIN PARAMS P
    WHERE L.CANCELLED = 0
      AND L.SIGN = 0
      AND L.AMOUNT > 0
      AND L.DATE_ < DATEADD(DAY, 1, P.ASOF_DATE)
),
OPEN_LINES AS (
    SELECT
        D.CARIID,
        D.ISLEM_TARIHI,
        (D.TUTAR - (
            CASE
                WHEN (B.CREDIT_TOTAL - (D.CUM_DEBIT - D.TUTAR)) <= 0 THEN CAST(0 AS DECIMAL(18,2))
                WHEN (B.CREDIT_TOTAL - (D.CUM_DEBIT - D.TUTAR)) >= D.TUTAR THEN D.TUTAR
                ELSE (B.CREDIT_TOTAL - (D.CUM_DEBIT - D.TUTAR))
            END
        )) AS ACIK_TUTAR
    FROM DEBIT_LINES D
    INNER JOIN BORCLU B ON B.CARIID = D.CARIID
),
AGE AS (
    SELECT
        O.CARIID,
        O.ISLEM_TARIHI,
        O.ACIK_TUTAR,
        DATEDIFF(DAY, O.ISLEM_TARIHI, P.ASOF_DATE) AS AGE_DAYS
    FROM OPEN_LINES O
    CROSS JOIN PARAMS P
    WHERE O.ACIK_TUTAR > 0
)
SELECT TOP {$limit}
    C.KODU,
    C.UNVANI,
    C.SEHIR,
    SUM(CASE WHEN A.AGE_DAYS BETWEEN 0 AND 30 THEN A.ACIK_TUTAR ELSE 0 END) AS D0_30,
    SUM(CASE WHEN A.AGE_DAYS BETWEEN 31 AND 60 THEN A.ACIK_TUTAR ELSE 0 END) AS D31_60,
    SUM(CASE WHEN A.AGE_DAYS BETWEEN 61 AND 90 THEN A.ACIK_TUTAR ELSE 0 END) AS D61_90,
    SUM(CASE WHEN A.AGE_DAYS >= 91 THEN A.ACIK_TUTAR ELSE 0 END) AS D90P,
    SUM(CASE WHEN A.AGE_DAYS >= 31 THEN A.ACIK_TUTAR ELSE 0 END) AS OVERDUE,
    (
        SUM(CASE WHEN A.AGE_DAYS >= 91 THEN A.ACIK_TUTAR ELSE 0 END) * 1.00
        + SUM(CASE WHEN A.AGE_DAYS BETWEEN 61 AND 90 THEN A.ACIK_TUTAR ELSE 0 END) * 0.65
        + SUM(CASE WHEN A.AGE_DAYS BETWEEN 31 AND 60 THEN A.ACIK_TUTAR ELSE 0 END) * 0.35
    ) AS PRIORITY_SCORE,
    SUM(A.ACIK_TUTAR) AS TOTAL
FROM AGE A
INNER JOIN CARILER C ON C.CARIID = A.CARIID
GROUP BY C.KODU, C.UNVANI, C.SEHIR
ORDER BY PRIORITY_SCORE DESC, TOTAL DESC
";

try {
    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);
    $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach ($rawRows as $r) {
        $d0 = (float) ($r['D0_30'] ?? 0);
        $d31 = (float) ($r['D31_60'] ?? 0);
        $d61 = (float) ($r['D61_90'] ?? 0);
        $d90 = (float) ($r['D90P'] ?? 0);
        $total = (float) ($r['TOTAL'] ?? 0);
        $meta = aging_priority_meta_excel($d31, $d61, $d90, $total);

        if ($priorityFilter !== 'all' && $meta['level'] !== $priorityFilter) {
            continue;
        }

        $r['D0_30'] = $d0;
        $r['D31_60'] = $d31;
        $r['D61_90'] = $d61;
        $r['D90P'] = $d90;
        $r['TOTAL'] = $total;
        $r['OVERDUE'] = (float) ($r['OVERDUE'] ?? $meta['overdue']);
        $r['PRIORITY_SCORE'] = (float) ($r['PRIORITY_SCORE'] ?? $meta['score']);
        $r['OVERDUE_RATIO'] = $meta['overdue_ratio'];
        $r['PRIORITY_LEVEL'] = $meta['level'];
        $r['PRIORITY_LABEL'] = $meta['label'];
        $r['PRIORITY_ACTION'] = $meta['action'];

        $rows[] = $r;
    }
} catch (Throwable $e) {
    if (function_exists('app_log_exception')) {
        $ref = app_log_exception($e, 'rapor/rapor_cari_yaslandirma_excel_xlsx.php', [
            'q' => mb_substr($rawQ, 0, 120),
            'asof' => $asOf,
            'limit' => $limit,
            'min_total' => $minTotal,
            'priority_filter' => $priorityFilter,
        ]);
        http_response_code(500);
        die("Beklenmeyen bir hata olustu. Ref: " . htmlspecialchars((string) $ref, ENT_QUOTES, 'UTF-8'));
    }
    throw $e;
}

// Totals
$totals = [
    'D0_30' => 0.0,
    'D31_60' => 0.0,
    'D61_90' => 0.0,
    'D90P' => 0.0,
    'OVERDUE' => 0.0,
    'TOTAL' => 0.0,
    'CUSTOMERS' => 0,
    'CRITICAL' => 0,
    'HIGH' => 0,
];
foreach ($rows as $r) {
    $totals['CUSTOMERS']++;
    $totals['D0_30'] += (float) ($r['D0_30'] ?? 0);
    $totals['D31_60'] += (float) ($r['D31_60'] ?? 0);
    $totals['D61_90'] += (float) ($r['D61_90'] ?? 0);
    $totals['D90P'] += (float) ($r['D90P'] ?? 0);
    $totals['OVERDUE'] += (float) ($r['OVERDUE'] ?? 0);
    $totals['TOTAL'] += (float) ($r['TOTAL'] ?? 0);
    if (($r['PRIORITY_LEVEL'] ?? '') === 'kritik') {
        $totals['CRITICAL']++;
    } elseif (($r['PRIORITY_LEVEL'] ?? '') === 'yuksek') {
        $totals['HIGH']++;
    }
}

$decimals = (int) ($GLOBALS['parakusurat'] ?? 2);
$fmt = '#,##0' . ($decimals > 0 ? '.' . str_repeat('0', $decimals) : '') . '" ₺"';

// Build Excel
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Lumen')
    ->setTitle('Cari Yaşlandırma')
    ->setSubject('Cari Yaşlandırma')
    ->setDescription('Cari yaşlandırma raporu (işlem tarihine göre).');

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Cari Yaşlandırma');

$row = 1;

// Title
$sheet->setCellValue('A' . $row, ($GLOBALS['firmabaslik'] ?? 'Lumen') . ' - Cari Yaşlandırma');
$sheet->mergeCells("A{$row}:L{$row}");
$sheet->getStyle("A{$row}")->applyFromArray([
    'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DC2626']],
]);
$sheet->getRowDimension($row)->setRowHeight(26);
$row++;

// Meta rows
$sheet->setCellValue("A{$row}", 'Rapor Tarihi:');
$sheet->setCellValue("B{$row}", $asOf);
$sheet->setCellValue("D{$row}", 'Olusturma:');
$sheet->setCellValue("E{$row}", date('Y-m-d H:i'));
$sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
$row++;

$sheet->setCellValue("A{$row}", 'Ara:');
$sheet->setCellValue("B{$row}", $rawQ !== '' ? $rawQ : '-');
$sheet->setCellValue("D{$row}", 'Min Toplam:');
$sheet->setCellValue("E{$row}", $minTotal);
$sheet->setCellValue("G{$row}", 'Oncelik:');
$sheet->setCellValue("H{$row}", $priorityFilter === 'all' ? 'Tum Kayitlar' : ucfirst($priorityFilter));
$sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
$sheet->getStyle("G{$row}:H{$row}")->getFont()->setBold(true);
$sheet->getStyle("E{$row}")->getNumberFormat()->setFormatCode($fmt);
$row++;

$sheet->setCellValue("A{$row}", 'Haric Tut:');
$sheet->setCellValue("B{$row}", $rawExclude !== '' ? $rawExclude : '-');
$sheet->mergeCells("B{$row}:L{$row}");
$sheet->getStyle("A{$row}")->getFont()->setBold(true);
$sheet->getStyle("B{$row}")->getAlignment()->setWrapText(true);
$row += 2;

// Table header
$headerRow = $row;
$headers = ['Kod', 'Müşteri', 'Şehir', '0-30', '31-60', '61-90', '90+', 'Toplam', 'Vadesi Gecen', 'Vade %', 'Oncelik', 'Aksiyon'];
$col = 'A';
foreach ($headers as $h) {
    $sheet->setCellValue($col . $headerRow, $h);
    $col++;
}

$sheet->getStyle("A{$headerRow}:L{$headerRow}")->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '991B1B']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
]);
$sheet->getRowDimension($headerRow)->setRowHeight(20);

$row++;
$dataStartRow = $row;

foreach ($rows as $idx => $r) {
    $sheet->setCellValueExplicit("A{$row}", (string) ($r['KODU'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue("B{$row}", (string) ($r['UNVANI'] ?? ''));
    $sheet->setCellValue("C{$row}", (string) ($r['SEHIR'] ?? ''));

    $sheet->setCellValue("D{$row}", (float) ($r['D0_30'] ?? 0));
    $sheet->setCellValue("E{$row}", (float) ($r['D31_60'] ?? 0));
    $sheet->setCellValue("F{$row}", (float) ($r['D61_90'] ?? 0));
    $sheet->setCellValue("G{$row}", (float) ($r['D90P'] ?? 0));
    $sheet->setCellValue("H{$row}", (float) ($r['TOTAL'] ?? 0));
    $sheet->setCellValue("I{$row}", (float) ($r['OVERDUE'] ?? 0));
    $sheet->setCellValue("J{$row}", (float) ($r['OVERDUE_RATIO'] ?? 0));
    $sheet->setCellValue("K{$row}", (string) ($r['PRIORITY_LABEL'] ?? 'Dusuk'));
    $sheet->setCellValue("L{$row}", (string) ($r['PRIORITY_ACTION'] ?? 'Rutin izleme'));

    $sheet->getStyle("D{$row}:I{$row}")->getNumberFormat()->setFormatCode($fmt);
    $sheet->getStyle("J{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
    $sheet->setCellValue("J{$row}", ((float) ($r['OVERDUE_RATIO'] ?? 0)) / 100);
    $sheet->getStyle("D{$row}:J{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle("K{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Zebra + borders
    if ($idx % 2 === 1) {
        $sheet->getStyle("A{$row}:L{$row}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F9FAFB']],
        ]);
    }
    $sheet->getStyle("A{$row}:L{$row}")->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);

    $row++;
}

$dataEndRow = $row - 1;

// Totals row
$sheet->setCellValue("A{$row}", 'TOPLAM');
$sheet->mergeCells("A{$row}:C{$row}");
$sheet->setCellValue("D{$row}", $totals['D0_30']);
$sheet->setCellValue("E{$row}", $totals['D31_60']);
$sheet->setCellValue("F{$row}", $totals['D61_90']);
$sheet->setCellValue("G{$row}", $totals['D90P']);
$sheet->setCellValue("H{$row}", $totals['TOTAL']);
$sheet->setCellValue("I{$row}", $totals['OVERDUE']);
$sheet->setCellValue("J{$row}", $totals['TOTAL'] > 0 ? ($totals['OVERDUE'] / $totals['TOTAL']) : 0);
$sheet->setCellValue("K{$row}", 'Kritik: ' . (string) $totals['CRITICAL']);
$sheet->setCellValue("L{$row}", 'Yuksek: ' . (string) $totals['HIGH']);
$sheet->getStyle("A{$row}:L{$row}")->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E7EB']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
]);
$sheet->getStyle("D{$row}:I{$row}")->getNumberFormat()->setFormatCode($fmt);
$sheet->getStyle("J{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
$sheet->getStyle("D{$row}:J{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle("K{$row}:L{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Column widths
$sheet->getColumnDimension('A')->setWidth(14);
$sheet->getColumnDimension('B')->setWidth(40);
$sheet->getColumnDimension('C')->setWidth(18);
foreach (['D', 'E', 'F', 'G', 'H', 'I'] as $c) {
    $sheet->getColumnDimension($c)->setWidth(18);
}
$sheet->getColumnDimension('J')->setWidth(12);
$sheet->getColumnDimension('K')->setWidth(12);
$sheet->getColumnDimension('L')->setWidth(34);

// Wrap long names
$sheet->getStyle("B{$dataStartRow}:B{$dataEndRow}")->getAlignment()->setWrapText(true);
$sheet->getStyle("L{$dataStartRow}:L{$dataEndRow}")->getAlignment()->setWrapText(true);

// Freeze header row, add autofilter
$sheet->freezePane("A" . ($headerRow + 1));
if ($dataEndRow >= $dataStartRow) {
    $sheet->setAutoFilter("A{$headerRow}:L{$dataEndRow}");
}

// Page setup
$sheet->getPageSetup()
    ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
    ->setFitToWidth(1)
    ->setFitToHeight(0);

// Output
ob_end_clean();

$fileSafeDate = preg_replace('/[^0-9\\-]/', '', $asOf);
$filename = "cari_yaslandirma_{$fileSafeDate}.xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
