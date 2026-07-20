<?php
declare(strict_types=1);

/**
 * POST /api/fiyat_guncelle.php
 * Gövde: { "stok_id":123, "fiyat":45.50 }
 * Yanıt: { ok, eski_fiyat, eski_fiyat_metin, yeni_fiyat, yeni_fiyat_metin, mesaj }
 *
 * Token + ST2 (fiyat değiştirme) yetkisi zorunlu. Ürünün VARSAYILAN satış fiyatını
 * (PRCLIST PTYPE=2, PAYPLANREF=0, ACTIVE=0) günceller — yalnızca mevcut satırın PRICE'ı.
 * Yeni fiyat satırı oluşturma (ödeme planına özel) web tarafında kalır (riskli INSERT).
 * (Web fiyat_guncelle.php UPDATE sözleşmesi: PTYPE=2.)
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
    api_json(['ok' => false, 'mesaj' => 'Fiyat değiştirme yetkiniz yok.'], 403);
}

$body    = api_body();
$stok    = (int) ($body['stok_id'] ?? 0);
$fiyatRaw = $body['fiyat'] ?? null;
$fiyat    = ($fiyatRaw === null || $fiyatRaw === '')
    ? null
    : (is_numeric($fiyatRaw) ? (float) $fiyatRaw : (float) str_replace(',', '.', (string) $fiyatRaw));
if ($stok <= 0) {
    api_json(['ok' => false, 'mesaj' => 'Geçerli bir ürün seçilmedi.'], 400);
}
if ($fiyat === null || $fiyat < 0) {
    api_json(['ok' => false, 'mesaj' => 'Geçerli bir fiyat girin.'], 422);
}

try {
    // Varsayılan satış fiyatı satırı (PTYPE=2, genel = PAYPLANREF 0).
    $st = $dbh->prepare("SELECT TOP 1 LOGICALREF, ISNULL(PRICE,0) AS PRICE
                         FROM {$firma}PRCLIST WITH(NOLOCK)
                         WHERE CARDREF = :s AND PTYPE = 2 AND ISNULL(PAYPLANREF,0) = 0 AND ACTIVE = 0
                         ORDER BY LOGICALREF ASC");
    $st->execute([':s' => $stok]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        api_json(['ok' => false, 'mesaj' => 'Ürünün varsayılan satış fiyatı bulunamadı; yeni fiyatı web üzerinden ekleyin.'], 404);
    }
    $rowId = (int) $row['LOGICALREF'];
    $eski  = (float) $row['PRICE'];

    $upd = $dbh->prepare("UPDATE {$firma}PRCLIST SET PRICE = :f WHERE LOGICALREF = :id AND PTYPE = 2");
    $upd->execute([':f' => $fiyat, ':id' => $rowId]);
    if ($upd->rowCount() <= 0) {
        api_json(['ok' => false, 'mesaj' => 'Fiyat güncellenemedi.'], 409);
    }
} catch (Throwable $e) {
    error_log('api/fiyat_guncelle: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Fiyat güncellenirken hata oluştu.'], 500);
}

api_json([
    'ok'               => true,
    'eski_fiyat'       => $eski,
    'eski_fiyat_metin' => api_money($eski),
    'yeni_fiyat'       => $fiyat,
    'yeni_fiyat_metin' => api_money($fiyat),
    'mesaj'            => 'Fiyat güncellendi.',
]);
