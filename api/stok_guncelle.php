<?php
declare(strict_types=1);

/**
 * POST /api/stok_guncelle.php
 * Gövde: { "stok_id":123, "kod":"AKL001", "ad":"...", "ozel_kod2"?:"GRP", "kdv"?:20 }
 * Yanıt: { ok, mesaj }
 *
 * Token + ST2 yetkisi zorunlu. Mevcut ürünün kodu/adı/özel kod2 (+ opsiyonel KDV) günceller.
 * Kod başka bir karta atanmışsa reddeder. (Web lg_stok_duzenle.php UPDATE sözleşmesi.)
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(['ok' => false, 'mesaj' => 'Yalnızca POST desteklenir.'], 405);
}
$oturum   = api_oturum_gerekli($dbh);
$personel = (int) $oturum['personel'];
if (!api_yetki_var($personel, 'ST2')) {
    api_json(['ok' => false, 'mesaj' => 'Stok düzenleme yetkiniz yok.'], 403);
}

$body = api_body();
$stok = (int) ($body['stok_id'] ?? 0);
$kod  = trim((string) ($body['kod'] ?? ''));
$ad   = trim((string) ($body['ad'] ?? ''));
// SPECODE2 ve KDV opsiyonel: yalnızca gönderildiyse güncellenir (aksi halde mevcut korunur).
$ozelVar = array_key_exists('ozel_kod2', $body) && $body['ozel_kod2'] !== null;
$ozel    = $ozelVar ? trim((string) $body['ozel_kod2']) : null;
$kdvVar  = array_key_exists('kdv', $body) && $body['kdv'] !== '' && $body['kdv'] !== null;
$kdv     = $kdvVar ? (float) $body['kdv'] : null;

if ($stok <= 0) {
    api_json(['ok' => false, 'mesaj' => 'Geçerli bir ürün seçilmedi.'], 400);
}
if ($kod === '' || mb_strlen($kod) > 40) {
    api_json(['ok' => false, 'mesaj' => 'Stok kodu boş veya 40 karakterden uzun olamaz.'], 422);
}
if ($ad === '' || mb_strlen($ad) > 60) {
    api_json(['ok' => false, 'mesaj' => 'Stok adı boş veya 60 karakterden uzun olamaz.'], 422);
}
if ($kdvVar && ($kdv < 0 || $kdv > 100)) {
    api_json(['ok' => false, 'mesaj' => 'KDV oranı 0–100 aralığında olmalı.'], 422);
}

try {
    // Ürün var mı?
    $v = $dbh->prepare("SELECT TOP 1 LOGICALREF FROM {$firma}ITEMS WITH(NOLOCK) WHERE LOGICALREF = :s");
    $v->execute([':s' => $stok]);
    if (!$v->fetchColumn()) {
        api_json(['ok' => false, 'mesaj' => 'Ürün bulunamadı.'], 404);
    }

    // Kod başka karta atanmış mı?
    $c = $dbh->prepare("SELECT TOP 1 LOGICALREF FROM {$firma}ITEMS WITH(NOLOCK)
                        WHERE CODE = :kod AND LOGICALREF <> :s");
    $c->execute([':kod' => $kod, ':s' => $stok]);
    if ($c->fetchColumn()) {
        api_json(['ok' => false, 'mesaj' => "'{$kod}' kodu başka bir ürüne atanmış."], 409);
    }

    $sets   = ['CODE = :kod', 'NAME = :ad'];
    $params = [':kod' => $kod, ':ad' => $ad, ':s' => $stok];
    if ($ozelVar) {
        $sets[] = 'SPECODE2 = :ozel';
        $params[':ozel'] = $ozel;
    }
    if ($kdvVar) {
        $sets[] = 'VAT = :kdv';
        $params[':kdv'] = $kdv;
    }
    $sql = "UPDATE {$firma}ITEMS SET " . implode(', ', $sets) . " WHERE LOGICALREF = :s";
    $upd = $dbh->prepare($sql);
    $upd->execute($params);
} catch (Throwable $e) {
    error_log('api/stok_guncelle: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Stok güncellenirken hata oluştu.'], 500);
}

api_json(['ok' => true, 'mesaj' => 'Stok bilgileri güncellendi.']);
