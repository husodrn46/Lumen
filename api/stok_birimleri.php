<?php
declare(strict_types=1);

/**
 * GET/POST /api/stok_birimleri.php
 * Gövde: { "stok_id": 123 }  ·  veya  ?stok_id=123
 * Yanıt: { ok, birimler:[{ kod, carpan }] }  (ana birim çarpan 1; koli/paket > 1)
 * Token zorunlu. Masaüstü sipariş ekranında birim (adet/koli) seçimi için.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');
include_once(__DIR__ . '/_siparis.inc');

global $dbh, $firma;
api_oturum_gerekli($dbh);

$body   = api_body();
$stokId = (int) ($body['stok_id'] ?? ($_GET['stok_id'] ?? 0));
if ($stokId <= 0) {
    api_json(['ok' => false, 'mesaj' => 'Ürün seçimi gerekiyor.'], 400);
}

try {
    $birimler = siparis_stok_birimleri($dbh, $firma, $stokId);
} catch (Throwable $e) {
    error_log('api/stok_birimleri: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Birimler alınamadı.'], 500);
}

// En azından ana birim (ADET) garantisi.
if (!$birimler) {
    $birimler = [['kod' => 'ADET', 'carpan' => 1.0]];
}

api_json(['ok' => true, 'birimler' => $birimler]);
