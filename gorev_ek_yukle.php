<?php

declare(strict_types=1);

/**
 * gorev_ek_yukle.php — Goreve dosya/fotograf eki yukler (AJAX, JSON)
 * POST: gorev_id, csrf_token, dosya (multipart)
 * Erisim: goreve erisimi olan (atayan ya da atanan) kullanici.
 */

ob_start();
include_once(__DIR__ . "/ayr.php");
include_once(__DIR__ . "/kontrol.php");
include_once(__DIR__ . "/gorev_lib.php");
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

function ek_jcik(bool $ok, string $mesaj = ''): void
{
    echo json_encode(['ok' => $ok, 'mesaj' => $mesaj], JSON_UNESCAPED_UNICODE);
    exit;
}

$benimId = (int) $terminalkullanici;

if (!gorev_erisim_var_mi($benimId)) {
    ek_jcik(false, 'Yetkisiz erişim.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ek_jcik(false, 'Geçersiz istek.');
}
if (!csrf_verify()) {
    ek_jcik(false, 'Oturum doğrulaması başarısız.');
}

$gid = (int) ($_POST['gorev_id'] ?? 0);
$g = gorev_getir_yetkili($dbh, $gid, $benimId);
if (!$g) {
    ek_jcik(false, 'Görev bulunamadı.');
}
if (gorev_durum_kapali_mi((int) $g['DURUM'])) {
    ek_jcik(false, 'Kapatılmış göreve ek eklenemez.');
}

if (!isset($_FILES['dosya']) || $_FILES['dosya']['error'] !== UPLOAD_ERR_OK) {
    ek_jcik(false, 'Dosya yüklenemedi.');
}

$dosya = $_FILES['dosya'];
$maxBoyut = 20 * 1024 * 1024; // 20 MB
if ($dosya['size'] <= 0 || $dosya['size'] > $maxBoyut) {
    ek_jcik(false, 'Dosya boyutu 20 MB sınırını aşıyor.');
}

// Gercek MIME tipini tespit et ve whitelist kontrolu yap
$izinli = [
    'image/jpeg' => 'jpg',
    'image/pjpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'image/heic' => 'heic',
    'image/heif' => 'heif',
    'image/bmp' => 'bmp',
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'text/plain' => 'txt',
];

$mime = '';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = (string) finfo_file($finfo, $dosya['tmp_name']);
    finfo_close($finfo);
}
if ($mime === '' || !isset($izinli[$mime])) {
    ek_jcik(false, 'İzin verilmeyen dosya türü: ' . ($mime !== '' ? $mime : 'bilinmiyor') . ' (Resim, PDF, Word, Excel veya metin yükleyin)');
}
$uzanti = $izinli[$mime];

// Orijinal ad (gosterim icin)
$orijinalAd = trim((string) $dosya['name']);
if ($orijinalAd === '') {
    $orijinalAd = 'ek.' . $uzanti;
}
if (mb_strlen($orijinalAd) > 200) {
    $orijinalAd = mb_substr($orijinalAd, 0, 200);
}

// Diskte saklanacak guvenli ad (tahmin edilemez)
try {
    $guvenliAd = 'g' . $gid . '_' . bin2hex(random_bytes(10)) . '.' . $uzanti;
} catch (Exception $e) {
    ek_jcik(false, 'Sunucu hatası.');
}

$klasor = __DIR__ . '/gorev_ekleri';
if (!is_dir($klasor) && !mkdir($klasor, 0775, true) && !is_dir($klasor)) {
    error_log('gorev ek klasor olusturulamadi: ' . $klasor);
    ek_jcik(false, 'Yükleme klasörü hazırlanamadı.');
}

$hedef = $klasor . '/' . $guvenliAd;
if (!is_uploaded_file($dosya['tmp_name'])) {
    error_log('gorev ek: gecersiz upload tmp=' . ($dosya['tmp_name'] ?? ''));
    ek_jcik(false, 'Geçersiz yükleme isteği.');
}
if (!move_uploaded_file($dosya['tmp_name'], $hedef)) {
    error_log('gorev ek tasima hatasi: hedef=' . $hedef . ' | ' . (error_get_last()['message'] ?? ''));
    ek_jcik(false, 'Dosya kaydedilemedi. Sunucudaki yükleme klasörü yazma izni gerektiriyor olabilir.');
}

try {
    $dbh->beginTransaction();
    $ins = $dbh->prepare(
        "INSERT INTO M_GOREV_EK (GOREV_ID, PERSONEL_ID, DOSYA_ADI, DOSYA_YOLU, DOSYA_TIP, BOYUT, TARIH)
         VALUES (:g, :p, :ad, :yol, :tip, :boyut, GETDATE())"
    );
    $ins->bindValue(':g', $gid, PDO::PARAM_INT);
    $ins->bindValue(':p', $benimId, PDO::PARAM_INT);
    $ins->bindValue(':ad', $orijinalAd, PDO::PARAM_STR);
    $ins->bindValue(':yol', $guvenliAd, PDO::PARAM_STR);
    $ins->bindValue(':tip', $mime, PDO::PARAM_STR);
    $ins->bindValue(':boyut', (int) $dosya['size'], PDO::PARAM_INT);
    $ins->execute();

    gorev_hareket_ekle($dbh, $gid, $benimId, GOREV_HAR_EK, null, null, $orijinalAd);
    $dbh->commit();
    ek_jcik(true, 'Dosya eklendi.');
} catch (PDOException $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    @unlink($hedef);
    error_log('gorev ek db: ' . $e->getMessage());
    ek_jcik(false, 'Kayıt sırasında hata oluştu.');
}
