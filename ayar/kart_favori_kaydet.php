<?php
declare(strict_types=1);

/**
 * Ana sayfa favori kartları (yıldız pin) — kişisel tercih kaydı.
 * Değer dinamik (kart anahtarları listesi) olduğu için gorunum.php'nin whitelist'li
 * generic ucundan ayrı; format-doğrulamalı kendi ucu. Güvenlik: render tarafı zaten
 * yalnız yetkili tile'ları gösterir (favori yalnız SIRAYI etkiler), bu uç format + adet
 * sınırı uygular. USER_CODE = tema_kullanici_kodu() (LG_SLSMAN.CODE) — diğer kişisel
 * ayarlarla aynı anahtar üretimi.
 */

include_once(__DIR__ . '/../ayr.php');
include(__DIR__ . '/../kontrol.php');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'mesaj' => 'Geçersiz yöntem.']);
    exit;
}
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    echo json_encode(['ok' => false, 'mesaj' => 'Güvenlik doğrulaması başarısız.']);
    exit;
}

// Gelen favori anahtar listesini normalize + doğrula (yalnız güvenli path formatı, azami 40).
$raw = (string) ($_POST['favoriler'] ?? '');
$keys = [];
foreach (explode(',', $raw) as $k) {
    $k = strtolower(trim($k));
    if ($k === '' || !preg_match('~^[a-z0-9_./-]{1,80}$~', $k)) { continue; }
    if (!in_array($k, $keys, true)) { $keys[] = $k; }
    if (count($keys) >= 40) { break; }
}
$deger = implode(',', $keys);

$kod = tema_kullanici_kodu();
if ($kod === '') {
    echo json_encode(['ok' => false, 'mesaj' => 'Kullanıcı bulunamadı.']);
    exit;
}

try {
    $var = $dbh->prepare("SELECT COUNT(*) FROM M_USER_SETTINGS WHERE USER_CODE = :c AND SETTING_KEY = 'gor_kart_favori'");
    $var->execute([':c' => $kod]);
    if ((int) $var->fetchColumn() > 0) {
        $dbh->prepare("UPDATE M_USER_SETTINGS SET SETTING_VALUE = :v WHERE USER_CODE = :c AND SETTING_KEY = 'gor_kart_favori'")
            ->execute([':v' => $deger, ':c' => $kod]);
    } else {
        $dbh->prepare("INSERT INTO M_USER_SETTINGS (USER_CODE, SETTING_KEY, SETTING_VALUE) VALUES (:c, 'gor_kart_favori', :v)")
            ->execute([':c' => $kod, ':v' => $deger]);
    }
    if (isset($_SESSION['_kis_ayar']) && is_array($_SESSION['_kis_ayar'])) {
        $_SESSION['_kis_ayar']['gor_kart_favori'] = $deger;   // cache güncelle → sonraki yükleme anında görür
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('kart_favori_kaydet: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'mesaj' => 'Kaydedilemedi.']);
}
