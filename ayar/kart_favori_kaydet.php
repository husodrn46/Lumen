<?php
declare(strict_types=1);

/**
 * Ana sayfa favori kartları (yıldız pin) — kişisel tercih kaydı.
 * Değer dinamik (kart anahtarları listesi) olduğu için gorunum.php'nin whitelist'li
 * generic ucundan ayrı; format-doğrulamalı kendi ucu. Güvenlik: render tarafı zaten
 * yalnız yetkili tile'ları gösterir (favori yalnız SIRAYI etkiler), bu uç format + adet
 * sınırı uygular. USER_CODE = tema_kullanici_kodu() (LG_SLSMAN.CODE) — diğer kişisel
 * ayarlarla aynı anahtar üretimi (pilot kapalıyken). Pilot yalnız ayrı Lumen deposuna yazar.
 */

include_once(__DIR__ . '/../ayr.php');
include(__DIR__ . '/../kontrol.php');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'mesaj' => 'Geçersiz yöntem.']);
    exit;
}
if (!is_string($_POST['csrf_token'] ?? null) || !csrf_verify($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'mesaj' => 'Güvenlik doğrulaması başarısız.']);
    exit;
}

if (!is_string($_POST['favoriler'] ?? '')) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'mesaj'=>'Geçersiz favori listesi.']);
    exit;
}
$deger = lumen_favorites_normalize($_POST['favoriler'] ?? '');

try {
    if (lumen_favorites_enabled()) {
        $scope = lumen_favorites_current_scope();
        lumen_favorites_repository()->save($scope, $deger);
        // ERP cache is intentionally untouched; pilot reads use their own scoped store.
        echo json_encode(['ok'=>true]);
        exit;
    }
} catch (Throwable $e) {
    error_log('Lumen favorite save unavailable.');
    http_response_code(503);
    echo json_encode(['ok'=>false, 'mesaj'=>'Favori hizmeti şu anda kullanılamıyor.']);
    exit;
}

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
