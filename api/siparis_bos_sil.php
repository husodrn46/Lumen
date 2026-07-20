<?php
declare(strict_types=1);

/**
 * POST /api/siparis_bos_sil.php
 * Gövde: { "order_id":123 }  veya  { "order_ids":[1,2,3] }
 * Yanıt: { ok, silinen:int, mesaj }
 *
 * Token + M10 (Sil / Geri Dönüşüm) yetkisi zorunlu.
 * YALNIZCA oturum sahibinin (SALESMANREF) SATIRSIZ ve NETTOTAL=0 olan satış siparişi
 * (TRCODE=1) taslaklarını siler. Dolu / sevkli / başkasına ait fiş ASLA silinmez.
 * (Web lg_geridonusum.php silme kriterleriyle birebir — boş kabuk kaydı temizliği.)
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
    api_json(['ok' => false, 'mesaj' => 'Taslak silme yetkiniz yok.'], 403);
}

$body = api_body();
$ids  = [];
if (isset($body['order_ids']) && is_array($body['order_ids'])) {
    foreach ($body['order_ids'] as $v) {
        $iv = (int) $v;
        if ($iv > 0) {
            $ids[] = $iv;
        }
    }
}
$single = (int) ($body['order_id'] ?? 0);
if ($single > 0) {
    $ids[] = $single;
}
$ids = array_values(array_unique($ids));

if ($ids === []) {
    api_json(['ok' => false, 'mesaj' => 'Silinecek sipariş belirtilmedi.'], 400);
}
if (count($ids) > 500) {
    $ids = array_slice($ids, 0, 500);
}

try {
    // DELETE + alias (T-SQL): boş kabuk kriterleri korunarak — yanlış kayıt asla gitmez.
    $place = implode(',', array_fill(0, count($ids), '?'));
    $sql = "DELETE F FROM {$firmadonem}ORFICHE F
            WHERE F.LOGICALREF IN ({$place})
              AND F.SALESMANREF = ?
              AND F.TRCODE = 1
              AND F.NETTOTAL = 0
              AND NOT EXISTS (SELECT 1 FROM {$firmadonem}ORFLINE L WHERE L.ORDFICHEREF = F.LOGICALREF)";
    $stmt = $dbh->prepare($sql);
    $stmt->execute(array_merge($ids, [$personel]));
    $silinen = (int) $stmt->rowCount();
} catch (Throwable $e) {
    error_log('api/siparis_bos_sil: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Taslak silinirken hata oluştu.'], 500);
}

if ($silinen <= 0) {
    api_json(['ok' => false, 'mesaj' => 'Silinecek uygun taslak bulunamadı (dolu, sevkli ya da size ait değil olabilir).'], 409);
}

api_json(['ok' => true, 'silinen' => $silinen, 'mesaj' => "{$silinen} taslak sipariş silindi."]);
