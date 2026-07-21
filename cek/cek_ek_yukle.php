<?php

declare(strict_types=1);

/**
 * cek_ek_yukle.php — Cek/senete gorsel eki yukler (AJAX, JSON).
 * POST: cek_ref (CSCARD.LOGICALREF), etiket (on|arka|ek), csrf_token, dosya (multipart)
 * Erisim: yalnizca yonetici (yetki=0).
 */

ob_start();
include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../kontrol.php");
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

function cek_ek_cik(bool $ok, string $mesaj = ''): void
{
    echo json_encode(['ok' => $ok, 'mesaj' => $mesaj], JSON_UNESCAPED_UNICODE);
    exit;
}

if ((int) ($yetkidurum ?? 1) !== 0) {
    cek_ek_cik(false, 'Bu islem icin yonetici yetkisi gerekli.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cek_ek_cik(false, 'Gecersiz istek.');
}
if (!csrf_verify()) {
    cek_ek_cik(false, 'Oturum dogrulamasi basarisiz.');
}

$cekRef = (int) ($_POST['cek_ref'] ?? 0);
if ($cekRef <= 0) {
    cek_ek_cik(false, 'Cek referansi gecersiz.');
}
$etiket = (string) ($_POST['etiket'] ?? 'ek');
if (!in_array($etiket, ['on', 'arka', 'ek'], true)) {
    $etiket = 'ek';
}

// Cek gercekten var mi?
$v = $dbh->prepare("SELECT COUNT(*) FROM {$firmadonem}CSCARD WHERE LOGICALREF = :r");
$v->execute([':r' => $cekRef]);
if (!$v->fetchColumn()) {
    cek_ek_cik(false, 'Cek bulunamadi.');
}

if (!isset($_FILES['dosya']) || $_FILES['dosya']['error'] !== UPLOAD_ERR_OK) {
    cek_ek_cik(false, 'Dosya yuklenemedi.');
}
$dosya = $_FILES['dosya'];
if ($dosya['size'] <= 0 || $dosya['size'] > 20 * 1024 * 1024) {
    cek_ek_cik(false, 'Dosya boyutu 20 MB sinirini asiyor.');
}

// Sadece resim ve PDF
$izinli = [
    'image/jpeg' => 'jpg', 'image/pjpeg' => 'jpg', 'image/png' => 'png',
    'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heif',
    'image/bmp' => 'bmp', 'image/gif' => 'gif', 'application/pdf' => 'pdf',
];
$mime = '';
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    $mime = (string) finfo_file($fi, $dosya['tmp_name']);
    finfo_close($fi);
}
if ($mime === '' || !isset($izinli[$mime])) {
    cek_ek_cik(false, 'Izin verilmeyen dosya turu (yalnizca resim veya PDF).');
}
$uz = $izinli[$mime];

$orijinalAd = trim((string) $dosya['name']);
if ($orijinalAd === '') {
    $orijinalAd = 'cek.' . $uz;
}
$orijinalAd = mb_substr($orijinalAd, 0, 200);

try {
    $guvenliAd = 'c' . $cekRef . '_' . bin2hex(random_bytes(10)) . '.' . $uz;
} catch (Exception $e) {
    cek_ek_cik(false, 'Sunucu hatasi.');
}

$klasor = __DIR__ . '/../cek_ekleri';
if (!is_dir($klasor) && !mkdir($klasor, 0775, true) && !is_dir($klasor)) {
    error_log('cek ek klasor olusturulamadi: ' . $klasor);
    cek_ek_cik(false, 'Yukleme klasoru hazirlanamadi.');
}
$hedef = $klasor . '/' . $guvenliAd;
if (!is_uploaded_file($dosya['tmp_name'])) {
    cek_ek_cik(false, 'Gecersiz yukleme istegi.');
}
if (!move_uploaded_file($dosya['tmp_name'], $hedef)) {
    error_log('cek ek tasima hatasi: ' . $hedef . ' | ' . (error_get_last()['message'] ?? ''));
    cek_ek_cik(false, 'Dosya kaydedilemedi (klasor yazma izni gerekebilir).');
}

try {
    $ins = $dbh->prepare(
        "INSERT INTO M_CEK_EK (CEK_REF, ETIKET, DOSYA_ADI, DOSYA_YOLU, DOSYA_TIP, BOYUT, PERSONEL_ID, TARIH)
         VALUES (:c, :e, :ad, :yol, :tip, :b, :p, GETDATE())"
    );
    $ins->execute([
        ':c' => $cekRef, ':e' => $etiket, ':ad' => $orijinalAd, ':yol' => $guvenliAd,
        ':tip' => $mime, ':b' => (int) $dosya['size'], ':p' => (int) $terminalkullanici,
    ]);
    cek_ek_cik(true, 'Gorsel eklendi.');
} catch (PDOException $e) {
    @unlink($hedef);
    error_log('cek_ek_yukle db: ' . $e->getMessage());
    cek_ek_cik(false, 'Kayit sirasinda hata olustu.');
}
