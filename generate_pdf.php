<?php
declare(strict_types=1);

require_once __DIR__ . '/kontrol.php';
session_start();
require_once __DIR__ . '/vendor/autoload.php'; // Composer ile yüklediğiniz DOMPDF autoload dosyası

use Dompdf\Dompdf;

// HTML çıktısını yakalamak için output buffering başlatıyoruz
ob_start();
include(__DIR__ . "/english_quotation.php"); // PDF'e dönüştürmek istediğiniz sayfa
$html = ob_get_clean();

// DOMPDF örneğini oluşturuyoruz
$dompdf = new Dompdf();
$dompdf->loadHtml($html);

// A4 kağıt boyutunu ve dikey yönü ayarlıyoruz
$dompdf->setPaper('A4', 'portrait');

// HTML'i PDF'e render ediyoruz
$dompdf->render();

// PDF'i tarayıcıda gösteriyoruz. Attachment => false, PDF'in inline görüntülenmesini sağlar.
$dompdf->stream("quotation.pdf", ["Attachment" => false]);
?>
