<?php

declare(strict_types=1);

/**
 * cek_ek_goster.php — Cek gorselini yetki kontrolu ile servis eder.
 * GET: id (M_CEK_EK.ID). Yalnizca yonetici. Dosyalar cek_ekleri/ disina cikamaz.
 */

include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../kontrol.php");

if ((int) ($yetkidurum ?? 1) !== 0) {
    http_response_code(403);
    exit('Yetkisiz.');
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Gecersiz istek.');
}

$st = $dbh->prepare("SELECT DOSYA_YOLU, DOSYA_TIP, DOSYA_ADI FROM M_CEK_EK WHERE ID = :id");
$st->execute([':id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Bulunamadi.');
}

$dosyaAd = basename((string) $row['DOSYA_YOLU']);
$path = __DIR__ . '/../cek_ekleri/' . $dosyaAd;
if (!is_file($path)) {
    http_response_code(404);
    exit('Dosya yok.');
}

$tip = (string) ($row['DOSYA_TIP'] ?? 'application/octet-stream');
header('Content-Type: ' . $tip);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . rawurlencode((string) ($row['DOSYA_ADI'] ?? $dosyaAd)) . '"');
header('Cache-Control: private, max-age=300');
readfile($path);
exit;
