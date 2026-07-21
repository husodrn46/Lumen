<?php

declare(strict_types=1);

/**
 * gorev_ek_indir.php — Gorev ekini yetki kontrolu ile servis eder.
 * GET: id (M_GOREV_EK.ID)
 * Yalnizca ekin ait oldugu goreve erisebilen kullanici indirebilir.
 */

include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/gorev_lib.php");

$benimId = (int) $terminalkullanici;

if (!gorev_erisim_var_mi($benimId)) {
    http_response_code(403);
    exit('Yetkisiz erişim.');
}

$ekId = (int) ($_GET['id'] ?? 0);
if ($ekId <= 0) {
    http_response_code(400);
    exit('Geçersiz istek.');
}

try {
    $stmt = $dbh->prepare("SELECT GOREV_ID, DOSYA_ADI, DOSYA_YOLU, DOSYA_TIP FROM M_GOREV_EK WITH(NOLOCK) WHERE ID = :id");
    $stmt->bindValue(':id', $ekId, PDO::PARAM_INT);
    $stmt->execute();
    $ek = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('gorev ek indir sorgu: ' . $e->getMessage());
    http_response_code(500);
    exit('Sunucu hatası.');
}

if (!$ek) {
    http_response_code(404);
    exit('Ek bulunamadı.');
}

// Ekin ait oldugu goreve erisim yetkisi var mi?
$g = gorev_getir_yetkili($dbh, (int) $ek['GOREV_ID'], $benimId);
if (!$g) {
    http_response_code(403);
    exit('Bu eke erişim yetkiniz yok.');
}

// Path traversal'e karsi yalnizca dosya adi
$guvenliAd = basename((string) $ek['DOSYA_YOLU']);
$dosyaYolu = __DIR__ . '/../gorev_ekleri/' . $guvenliAd;

if ($guvenliAd === '' || !is_file($dosyaYolu)) {
    http_response_code(404);
    exit('Dosya bulunamadı.');
}

$mime = (string) ($ek['DOSYA_TIP'] ?? '');
if ($mime === '') {
    $mime = 'application/octet-stream';
}
$indirmeAdi = (string) ($ek['DOSYA_ADI'] ?? $guvenliAd);

// Resim ve PDF tarayicida acilsin (inline), digerleri indirilsin (attachment)
$inlineTipler = ['image/jpeg', 'image/pjpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
$disposition = in_array($mime, $inlineTipler, true) ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($dosyaYolu));
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($indirmeAdi) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($dosyaYolu);
exit;
