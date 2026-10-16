<?php

declare(strict_types=1);

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
    require_once dirname(__DIR__, 2) . '/includes/xlsx_guvenli.php';

    test('xlsx: "=" ile başlayan kullanıcı metni formül olarak yazılmaz', function (): void {
        lumen_xlsx_guvenli_baglayici();
        $sheet = (new \PhpOffice\PhpSpreadsheet\Spreadsheet())->getActiveSheet();
        $sheet->setCellValue('A1', '=HYPERLINK("http://ornek.test","tikla")');
        $sheet->setCellValue('A2', '@SUM(1,2)');
        $sheet->setCellValue('A3', 1234.5);
        $sheet->setCellValue('A4', '-42');
        $sheet->setCellValue('A5', 'Normal açıklama');
        esit('s', $sheet->getCell('A1')->getDataType());
        esit('s', $sheet->getCell('A2')->getDataType());
        esit('n', $sheet->getCell('A3')->getDataType());
        esit('n', $sheet->getCell('A4')->getDataType());
        esit('s', $sheet->getCell('A5')->getDataType());
    });
}
