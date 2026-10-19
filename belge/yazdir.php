<?php

declare(strict_types=1);

/** Belge çıktısı: yazdırılabilir sayfa ya da PDF (?pdf=1) — yalnız bağımsız mod. */

include_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/../includes/belge/fatura_sablon.php';

$id = (int) ($_GET['id'] ?? 0);
$st = $dbh->prepare('SELECT TUR, YON, NO, CARI_ID FROM dbo.LM_BELGE WHERE ID = :id AND TUR <> 1');
$st->execute([':id' => $id]);
$b = $st->fetch(PDO::FETCH_ASSOC);
if (!$b || !isset($belgeTurleri[(string) lumen_belge_tur_kodu((int) $b['TUR'], (int) $b['YON'])])
    || ((int) $b['CARI_ID'] > 0 && !m_p_cariid_goruntulebilir_mi($dbh, $firma, (int) $terminalkullanici, (int) $b['CARI_ID']))) {
    lumen_hata_sayfasi('Belge bulunamadı', 'Bu numarada bir belge yok.', 404);
}
$pdf = isset($_GET['pdf']);
$html = (string) lumen_belge_yazdir_html($dbh, $id, $pdf);
if (!$pdf) {
    echo $html;
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
$secenek = new Dompdf\Options();
$secenek->set('isRemoteEnabled', false);
$secenek->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf\Dompdf($secenek);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4');
$dompdf->render();
$ad = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $b['NO']) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $ad . '"');
header('X-Content-Type-Options: nosniff');
echo $dompdf->output();
