<?php
declare(strict_types=1);

/**
 * POST /api/siparis_iptal.php
 * Gövde: { "order_id":123 }  ·  veya  { "fisno":"AKLSP008745" }
 * Yanıt: { ok, mesaj }
 *
 * Token + M10 (iptal) yetkisi zorunlu. Sevkiyatı başlamış sipariş iptal edilemez.
 * İptal = ORFICHE.CANCELLED = 1 (mevcut ai_beta_tool_siparis_iptal_onayla sözleşmesi).
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firmadonem;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(['ok' => false, 'mesaj' => 'Yalnızca POST desteklenir.'], 405);
}
$oturum   = api_oturum_gerekli($dbh);
$personel = (int) $oturum['personel'];
if (!api_yetki_var($personel, 'M10')) {
    api_json(['ok' => false, 'mesaj' => 'Sipariş iptal yetkiniz yok.'], 403);
}

$body    = api_body();
$orderId = (int) ($body['order_id'] ?? 0);
$fisno   = trim((string) ($body['fisno'] ?? ''));
if ($orderId <= 0 && $fisno !== '') {
    $st = $dbh->prepare("SELECT TOP 1 LOGICALREF FROM {$firmadonem}ORFICHE WITH(NOLOCK)
                         WHERE UPPER(FICHENO) = :f AND TRCODE = 1 AND ISNULL(CANCELLED, 0) = 0");
    $st->execute([':f' => mb_strtoupper($fisno, 'UTF-8')]);
    $orderId = (int) ($st->fetchColumn() ?: 0);
}
if ($orderId <= 0) {
    api_json(['ok' => false, 'mesaj' => 'Sipariş bulunamadı veya zaten iptal edilmiş.'], 404);
}

try {
    // Sevkiyat başlamışsa iptal edilemez.
    $sevk = $dbh->prepare("SELECT SUM(CASE WHEN ISNULL(SHIPPEDAMOUNT, 0) > 0 THEN 1 ELSE 0 END)
                           FROM {$firmadonem}ORFLINE
                           WHERE ORDFICHEREF = :id AND LINETYPE = 0");
    $sevk->execute([':id' => $orderId]);
    if ((int) ($sevk->fetchColumn() ?: 0) > 0) {
        api_json(['ok' => false, 'mesaj' => 'Bu siparişte sevkiyat başlamış; uygulamadan iptal edilemez (LOGO üzerinden kontrol edin).'], 409);
    }

    // İptal atomik: yarış durumunda (kontrol ile UPDATE arası sevkiyat başlarsa)
    // NOT EXISTS koşulu sevkiyatlı fişi yine de iptal etmez.
    $upd = $dbh->prepare("UPDATE {$firmadonem}ORFICHE SET CANCELLED = 1
                          WHERE LOGICALREF = :id AND TRCODE = 1 AND ISNULL(CANCELLED, 0) = 0
                            AND NOT EXISTS (
                                SELECT 1 FROM {$firmadonem}ORFLINE L
                                WHERE L.ORDFICHEREF = :id2 AND L.LINETYPE = 0 AND ISNULL(L.SHIPPEDAMOUNT, 0) > 0
                            )");
    $upd->execute([':id' => $orderId, ':id2' => $orderId]);
    if ($upd->rowCount() <= 0) {
        api_json(['ok' => false, 'mesaj' => 'Sipariş iptal edilemedi; daha önce iptal edilmiş olabilir.'], 409);
    }
} catch (Throwable $e) {
    error_log('api/siparis_iptal: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Sipariş iptal edilirken hata oluştu.'], 500);
}

api_json(['ok' => true, 'mesaj' => 'Sipariş iptal edildi.']);
