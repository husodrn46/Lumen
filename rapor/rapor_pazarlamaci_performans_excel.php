<?php
declare(strict_types=1);

/**
 * Excel export: Pazarlamaci Performans – 2026
 * 3 sayfa:
 *   1) Musteri Detay (bakiye devir haric, ciro, islem sayisi, 2026 tahsilat)
 *   2) Aylik Satis Ozeti
 *   3) Aylik Tahsilat (Nakit/Havale/POS + Cek + Senet ayrimi)
 *
 * Tahsilat tek kaynak: CLFLINE (CF.DATE_ bazli) — ana sayfayla ayni mantik.
 */

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
use PhpOffice\PhpSpreadsheet\Cell\DataType;

if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}
if (m_p_yetki($terminalkullanici, 'CR1') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

$year = 2026;
$cariPrefix = isset($_GET['prefix']) && trim((string) $_GET['prefix']) !== ''
    ? trim((string) $_GET['prefix'])
    : 'TMÇN';

$monthNames = [
    1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan',
    5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos',
    9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık',
];

/* =====================================================================
 *  VERİ SORGULARI
 * ===================================================================== */

// 1) Musteri bakiyeleri (devir haric) — devir CLFLINE TRCODE=14'ten;
//    LV_ GNTOTCL view'inda TOTTYP=0 satiri yok (eski hesap devri dusmuyordu)
$balanceExpr = "((ISNULL(G.DEBIT,0)-ISNULL(G.CREDIT,0))-ISNULL(DV.DEVIR,0))";
$customers = [];
try {
    $stmt = $dbh->prepare("
        SELECT C.LOGICALREF AS CARIID, C.CODE AS KODU, C.DEFINITION_ AS UNVANI, C.CITY AS SEHIR, {$balanceExpr} AS BAKIYE
        FROM {$firma}CLCARD C WITH(NOLOCK)
        LEFT JOIN {$firmadonemx}GNTOTCL G WITH(NOLOCK) ON G.CARDREF=C.LOGICALREF AND G.TOTTYP=1
        LEFT JOIN (
            SELECT CLIENTREF, SUM(CASE WHEN SIGN = 0 THEN AMOUNT ELSE -AMOUNT END) AS DEVIR
            FROM {$firmadonem}CLFLINE WITH(NOLOCK)
            WHERE TRCODE = 14 AND CANCELLED = 0
            GROUP BY CLIENTREF
        ) DV ON DV.CLIENTREF=C.LOGICALREF
        WHERE C.ACTIVE=0 AND C.CODE LIKE :prefix
        ORDER BY {$balanceExpr} DESC
    ");
    $stmt->execute([':prefix' => $cariPrefix . '%']);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { throw $e; }

// 2) Aylik satis
$monthly = [];
for ($i = 1; $i <= 12; $i++) { $monthly[$i] = ['C' => 0, 'NET' => 0.0]; }
try {
    $stmt = $dbh->prepare("
        SELECT MONTH(SL.DATE_) AS M, COUNT(DISTINCT SL.INVOICEREF) AS C,
               SUM(ISNULL(SL.LINENET,(SL.PRICE*SL.AMOUNT-SL.DISTDISC)) + ISNULL(SL.VATAMNT,0)) AS NET
        FROM {$firmadonem}STLINE SL WITH(NOLOCK)
        JOIN {$firma}CLCARD CL WITH(NOLOCK) ON SL.CLIENTREF=CL.LOGICALREF
        WHERE CL.CODE LIKE :prefix AND SL.TRCODE IN (7,8)
          AND SL.DATE_>='2026-01-01' AND SL.DATE_<'2027-01-01'
          AND SL.CANCELLED=0 AND SL.LINETYPE=0
        GROUP BY MONTH(SL.DATE_)
    ");
    $stmt->execute([':prefix' => $cariPrefix . '%']);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $m = (int) $r['M'];
        $monthly[$m]['C'] = (int) $r['C'];
        $monthly[$m]['NET'] = (float) $r['NET'];
    }
} catch (Throwable $e) {}

// 3) Musteri bazli satis
$customerSales = [];
try {
    $stmt = $dbh->prepare("
        SELECT CL.LOGICALREF AS CARIID, COUNT(DISTINCT SL.INVOICEREF) AS ISLEM_SAYISI,
               SUM(ISNULL(SL.LINENET,(SL.PRICE*SL.AMOUNT-SL.DISTDISC)) + ISNULL(SL.VATAMNT,0)) AS TOPLAM_CIRO
        FROM {$firmadonem}STLINE SL WITH(NOLOCK)
        JOIN {$firma}CLCARD CL WITH(NOLOCK) ON SL.CLIENTREF=CL.LOGICALREF
        WHERE CL.CODE LIKE :prefix AND SL.TRCODE IN (7,8)
          AND SL.DATE_>='2026-01-01' AND SL.DATE_<'2027-01-01'
          AND SL.CANCELLED=0 AND SL.LINETYPE=0
        GROUP BY CL.LOGICALREF
    ");
    $stmt->execute([':prefix' => $cariPrefix . '%']);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $customerSales[(int) $r['CARIID']] = $r;
    }
} catch (Throwable $e) {}

// 4) Aylik tahsilat — Nakit/Havale/POS + Cek + Senet ayri ayri
$monthlyTahsilatNakit = array_fill(1, 12, 0.0);
$monthlyTahsilatCek   = array_fill(1, 12, 0.0);
$monthlyTahsilatSenet = array_fill(1, 12, 0.0);

try {
    $stmt = $dbh->prepare("
        SELECT MONTH(CF.DATE_) AS M,
               SUM(CASE WHEN CF.TRCODE IN (1,4,20,70) THEN CF.AMOUNT ELSE 0 END) AS NAKIT,
               SUM(CASE WHEN CF.TRCODE = 61            THEN CF.AMOUNT ELSE 0 END) AS CEK,
               SUM(CASE WHEN CF.TRCODE = 62            THEN CF.AMOUNT ELSE 0 END) AS SENET
        FROM {$firmadonem}CLFLINE CF WITH(NOLOCK)
        JOIN {$firma}CLCARD CL WITH(NOLOCK) ON CF.CLIENTREF=CL.LOGICALREF
        WHERE CL.CODE LIKE :prefix
          AND CF.TRCODE IN (1,4,20,61,62,70)
          AND CF.SIGN = 1
          AND CF.DATE_ >= '2026-01-01' AND CF.DATE_ < '2027-01-01'
          AND CF.CANCELLED = 0
        GROUP BY MONTH(CF.DATE_)
    ");
    $stmt->execute([':prefix' => $cariPrefix . '%']);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $m = (int) $r['M'];
        if ($m >= 1 && $m <= 12) {
            $monthlyTahsilatNakit[$m] = (float) $r['NAKIT'];
            $monthlyTahsilatCek[$m]   = (float) $r['CEK'];
            $monthlyTahsilatSenet[$m] = (float) $r['SENET'];
        }
    }
} catch (Throwable $e) {}

// 5) Musteri bazli tahsilat (CLFLINE TRCODE 1/4/20/61/62/70 toplami)
$customerTahsilat = [];
try {
    $stmt = $dbh->prepare("
        SELECT CL.LOGICALREF AS CARIID, SUM(CF.AMOUNT) AS TUTAR
        FROM {$firmadonem}CLFLINE CF WITH(NOLOCK)
        JOIN {$firma}CLCARD CL WITH(NOLOCK) ON CF.CLIENTREF=CL.LOGICALREF
        WHERE CL.CODE LIKE :prefix
          AND CF.TRCODE IN (1,4,20,61,62,70)
          AND CF.SIGN = 1
          AND CF.DATE_ >= '2026-01-01' AND CF.DATE_ < '2027-01-01'
          AND CF.CANCELLED = 0
        GROUP BY CL.LOGICALREF
    ");
    $stmt->execute([':prefix' => $cariPrefix . '%']);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $customerTahsilat[(int) $r['CARIID']] = (float) $r['TUTAR'];
    }
} catch (Throwable $e) {}

/* =====================================================================
 *  EXCEL OLUSTURMA
 * ===================================================================== */
$decimals = (int) ($GLOBALS['parakusurat'] ?? 2);
$fmtMoney = '#,##0' . ($decimals > 0 ? '.' . str_repeat('0', $decimals) : '') . '" ₺"';
$fmtMoneySigned = $fmtMoney . ';[Red]-' . $fmtMoney;

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator($GLOBALS['firmabaslik'] ?? 'Lumen')
    ->setTitle('Pazarlamaci Performans 2026')
    ->setDescription('2026 pazarlamaci satis ve bakiye raporu (devir haric).');

$hdrStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '115E59']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
];
$borderStyle = [
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
];
$totStyle = [
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'CCFBF1']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
];

/* =================================================================
 *  SAYFA 1: Musteri Detay
 * ================================================================= */
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Musteri Detay');

$row = 1;
$lastCol = 'H';
$sheet->setCellValue("A{$row}", ($GLOBALS['firmabaslik'] ?? 'Lumen') . ' - Pazarlamaci Performans 2026');
$sheet->mergeCells("A{$row}:{$lastCol}{$row}");
$sheet->getStyle("A{$row}")->applyFromArray([
    'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0D9488']],
]);
$sheet->getRowDimension($row)->setRowHeight(28);
$row++;

$sheet->setCellValue("A{$row}", 'Rapor Donemi:');
$sheet->setCellValue("B{$row}", '2026 Yili (devir haric)');
$sheet->setCellValue("D{$row}", 'Cari Prefix:');
$sheet->setCellValue("E{$row}", $cariPrefix);
$sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
$row++;
$sheet->setCellValue("A{$row}", 'Olusturma:');
$sheet->setCellValue("B{$row}", date('Y-m-d H:i'));
$sheet->setCellValue("D{$row}", 'Musteri Sayisi:');
$sheet->setCellValue("E{$row}", count($customers));
$sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
$row += 2;

$headerRow = $row;
$headers = ['Kod', 'Musteri', 'Sehir', 'Bakiye (devir haric)', 'Durum', '2026 Ciro', 'Islem', '2026 Tahsilat'];
$col = 'A';
foreach ($headers as $h) { $sheet->setCellValue($col . $headerRow, $h); $col++; }
$sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->applyFromArray($hdrStyle);
$sheet->getRowDimension($headerRow)->setRowHeight(22);
$row++;

$dataStartRow = $row;
$totBorc = 0.0; $totAlacak = 0.0; $totCiro = 0.0; $totTahsilat = 0.0;

foreach ($customers as $idx => $c) {
    $bakiye = (float) ($c['BAKIYE'] ?? 0);
    $cariId = (int) ($c['CARIID'] ?? 0);
    $sales = $customerSales[$cariId] ?? null;
    $ciro = $sales ? (float) $sales['TOPLAM_CIRO'] : 0.0;
    $islem = $sales ? (int) $sales['ISLEM_SAYISI'] : 0;
    $tahsilat = (float) ($customerTahsilat[$cariId] ?? 0.0);

    $durumText = 'Sifir';
    if ($bakiye > 0) { $durumText = 'Borclu'; $totBorc += $bakiye; }
    elseif ($bakiye < 0) { $durumText = 'Alacakli'; $totAlacak += abs($bakiye); }
    $totCiro += $ciro;
    $totTahsilat += $tahsilat;

    $sheet->setCellValueExplicit("A{$row}", (string) ($c['KODU'] ?? ''), DataType::TYPE_STRING);
    $sheet->setCellValue("B{$row}", (string) ($c['UNVANI'] ?? ''));
    $sheet->setCellValue("C{$row}", (string) ($c['SEHIR'] ?? ''));
    $sheet->setCellValue("D{$row}", $bakiye);
    $sheet->setCellValue("E{$row}", $durumText);
    $sheet->setCellValue("F{$row}", $ciro);
    $sheet->setCellValue("G{$row}", $islem);
    $sheet->setCellValue("H{$row}", $tahsilat);

    $sheet->getStyle("D{$row}")->getNumberFormat()->setFormatCode($fmtMoneySigned);
    $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
    $sheet->getStyle("H{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
    $sheet->getStyle("D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle("F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle("H{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle("G{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    if ($idx % 2 === 1) {
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0FDFA']],
        ]);
    }
    $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray($borderStyle);
    $row++;
}
$dataEndRow = $row - 1;

$sheet->setCellValue("A{$row}", 'TOPLAM');
$sheet->mergeCells("A{$row}:C{$row}");
$sheet->setCellValue("D{$row}", $totBorc - $totAlacak);
$sheet->setCellValue("E{$row}", 'Net');
$sheet->setCellValue("F{$row}", $totCiro);
$sheet->setCellValue("H{$row}", $totTahsilat);
$sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray($totStyle);
$sheet->getStyle("D{$row}")->getNumberFormat()->setFormatCode($fmtMoneySigned);
$sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
$sheet->getStyle("H{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
$sheet->getStyle("D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle("F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle("H{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

$sheet->getColumnDimension('A')->setWidth(16);
$sheet->getColumnDimension('B')->setWidth(40);
$sheet->getColumnDimension('C')->setWidth(16);
$sheet->getColumnDimension('D')->setWidth(22);
$sheet->getColumnDimension('E')->setWidth(12);
$sheet->getColumnDimension('F')->setWidth(20);
$sheet->getColumnDimension('G')->setWidth(10);
$sheet->getColumnDimension('H')->setWidth(20);

$sheet->freezePane("A" . ($headerRow + 1));
if ($dataEndRow >= $dataStartRow) {
    $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$dataEndRow}");
}

/* =================================================================
 *  SAYFA 2: Aylik Satis Ozeti
 * ================================================================= */
$sheet2 = $spreadsheet->createSheet();
$sheet2->setTitle('Aylik Satis 2026');

$row = 1;
$sheet2->setCellValue("A{$row}", ($GLOBALS['firmabaslik'] ?? 'Lumen') . ' - 2026 Aylik Satis Ozeti');
$sheet2->mergeCells("A{$row}:C{$row}");
$sheet2->getStyle("A{$row}")->applyFromArray([
    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0D9488']],
]);
$sheet2->getRowDimension($row)->setRowHeight(26);
$row++;

$sheet2->setCellValue("A{$row}", 'Cari Prefix:');
$sheet2->setCellValue("B{$row}", $cariPrefix);
$sheet2->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);
$row += 2;

$hdr2 = $row;
foreach (['Ay', 'Islem', 'Tutar'] as $i => $h) {
    $sheet2->setCellValue(chr(65 + $i) . $hdr2, $h);
}
$sheet2->getStyle("A{$hdr2}:C{$hdr2}")->applyFromArray($hdrStyle);
$row++;

$ySatis = 0.0; $yCount = 0;
for ($m = 1; $m <= 12; $m++) {
    $d = $monthly[$m];
    $sheet2->setCellValue("A{$row}", $monthNames[$m]);
    $sheet2->setCellValue("B{$row}", $d['C']);
    $sheet2->setCellValue("C{$row}", $d['NET']);

    $sheet2->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet2->getStyle("C{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
    $sheet2->getStyle("C{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    if ($m % 2 === 0) {
        $sheet2->getStyle("A{$row}:C{$row}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0FDFA']],
        ]);
    }
    $sheet2->getStyle("A{$row}:C{$row}")->applyFromArray($borderStyle);

    $ySatis += $d['NET']; $yCount += $d['C'];
    $row++;
}

$sheet2->setCellValue("A{$row}", 'TOPLAM');
$sheet2->setCellValue("B{$row}", $yCount);
$sheet2->setCellValue("C{$row}", $ySatis);
$sheet2->getStyle("A{$row}:C{$row}")->applyFromArray($totStyle);
$sheet2->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet2->getStyle("C{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
$sheet2->getStyle("C{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

$sheet2->getColumnDimension('A')->setWidth(16);
$sheet2->getColumnDimension('B')->setWidth(12);
$sheet2->getColumnDimension('C')->setWidth(22);

/* =================================================================
 *  SAYFA 3: Aylik Tahsilat (Nakit/Havale/POS + Cek + Senet)
 * ================================================================= */
$sheet3 = $spreadsheet->createSheet();
$sheet3->setTitle('Aylik Tahsilat 2026');

$row = 1;
$sheet3->setCellValue("A{$row}", ($GLOBALS['firmabaslik'] ?? 'Lumen') . ' - 2026 Aylik Tahsilat');
$sheet3->mergeCells("A{$row}:E{$row}");
$sheet3->getStyle("A{$row}")->applyFromArray([
    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0D9488']],
]);
$sheet3->getRowDimension($row)->setRowHeight(26);
$row++;

$sheet3->setCellValue("A{$row}", 'Cari Prefix:');
$sheet3->setCellValue("B{$row}", $cariPrefix);
$sheet3->setCellValue("D{$row}", 'Kaynak:');
$sheet3->setCellValue("E{$row}", 'CLFLINE (alma tarihi bazli)');
$sheet3->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
$row += 2;

$hdr3 = $row;
foreach (['Ay', 'Nakit / Havale / POS', 'Cek (alindi)', 'Senet (alindi)', 'Toplam'] as $i => $h) {
    $sheet3->setCellValue(chr(65 + $i) . $hdr3, $h);
}
$sheet3->getStyle("A{$hdr3}:E{$hdr3}")->applyFromArray($hdrStyle);
$sheet3->getRowDimension($hdr3)->setRowHeight(22);
$row++;

$yNakit = 0.0; $yCek = 0.0; $ySenet = 0.0;
for ($m = 1; $m <= 12; $m++) {
    $nakit = $monthlyTahsilatNakit[$m];
    $cek   = $monthlyTahsilatCek[$m];
    $senet = $monthlyTahsilatSenet[$m];
    $toplam = $nakit + $cek + $senet;

    $sheet3->setCellValue("A{$row}", $monthNames[$m]);
    $sheet3->setCellValue("B{$row}", $nakit);
    $sheet3->setCellValue("C{$row}", $cek);
    $sheet3->setCellValue("D{$row}", $senet);
    $sheet3->setCellValue("E{$row}", $toplam);

    foreach (['B', 'C', 'D', 'E'] as $c) {
        $sheet3->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
        $sheet3->getStyle("{$c}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    if ($m % 2 === 0) {
        $sheet3->getStyle("A{$row}:E{$row}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0FDFA']],
        ]);
    }
    $sheet3->getStyle("A{$row}:E{$row}")->applyFromArray($borderStyle);

    $yNakit += $nakit; $yCek += $cek; $ySenet += $senet;
    $row++;
}

$sheet3->setCellValue("A{$row}", 'TOPLAM');
$sheet3->setCellValue("B{$row}", $yNakit);
$sheet3->setCellValue("C{$row}", $yCek);
$sheet3->setCellValue("D{$row}", $ySenet);
$sheet3->setCellValue("E{$row}", $yNakit + $yCek + $ySenet);
$sheet3->getStyle("A{$row}:E{$row}")->applyFromArray($totStyle);
foreach (['B', 'C', 'D', 'E'] as $c) {
    $sheet3->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode($fmtMoney);
    $sheet3->getStyle("{$c}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
}

$sheet3->getColumnDimension('A')->setWidth(16);
$sheet3->getColumnDimension('B')->setWidth(22);
$sheet3->getColumnDimension('C')->setWidth(20);
$sheet3->getColumnDimension('D')->setWidth(20);
$sheet3->getColumnDimension('E')->setWidth(22);

$spreadsheet->setActiveSheetIndex(0);

/* Dosya ciktisi */
ob_end_clean();
$safePrefix = preg_replace('/[^a-zA-Z0-9_-]/', '', turkce($cariPrefix));
$filename = 'pazarlamaci_performans_2026_' . $safePrefix . '_' . date('Y-m-d') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
