<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$geciciDosya = tempnam(sys_get_temp_dir(), 'lumen-xlsx-');
if ($geciciDosya === false) {
    throw new RuntimeException('XLSX duman testi icin gecici dosya olusturulamadi.');
}

try {
    $calismaKitabi = new Spreadsheet();
    $calismaKitabi->getActiveSheet()->setCellValue('A1', 'Lumen');
    (new Xlsx($calismaKitabi))->save($geciciDosya);

    if (!is_file($geciciDosya) || filesize($geciciDosya) === 0) {
        throw new RuntimeException('PhpSpreadsheet bos XLSX ciktisi uretti.');
    }
} finally {
    if (is_file($geciciDosya) && !unlink($geciciDosya)) {
        throw new RuntimeException('XLSX duman testi gecici dosyayi temizleyemedi.');
    }
}

$pdf = new Dompdf();
$pdf->loadHtml('<h1>Lumen</h1>');
$pdf->render();

if (strlen($pdf->output()) < 100) {
    throw new RuntimeException('Dompdf gecerli PDF ciktisi uretmedi.');
}

echo "PhpSpreadsheet ve Dompdf duman testi gecti.\n";
