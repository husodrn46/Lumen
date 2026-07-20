<?php
declare(strict_types=1);

/**
 * POST /api/siparis_satir_sil.php
 * Gövde: { "satir_id":456 }   (ORFLINE.LOGICALREF)
 * Yanıt: { ok, toplam:{...}, mesaj }
 *
 * Bir sipariş satırını siler (yalnızca LINETYPE=0 ürün satırı). hareketsil.php
 * sözleşmesi: DELETE + LINENO_ yeniden sıralama; ardından ORFICHE toplamı
 * tüm satırlardan yeniden hesaplanır (web'deki manuel düzeltmeden daha tutarlı).
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');
include_once(__DIR__ . '/_siparis.inc');

global $dbh, $firma, $firmadonem, $terminalkullanici;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(['ok' => false, 'mesaj' => 'Yalnızca POST desteklenir.'], 405);
}
$oturum   = api_oturum_gerekli($dbh);
$personel = (int) $oturum['personel'];
$terminalkullanici = $personel;

if (!api_yetki_var($personel, 'M1')) {
    api_json(['ok' => false, 'mesaj' => 'Sipariş düzenleme yetkiniz yok.'], 403);
}

$body    = api_body();
$satirId = (int) ($body['satir_id'] ?? 0);

$satir = siparis_satir_getir($dbh, $firma, $firmadonem, $satirId);
if ($satir === null) {
    api_json(['ok' => false, 'mesaj' => 'Satır bulunamadı.'], 404);
}
if ($satir['linetype'] !== 0) {
    api_json(['ok' => false, 'mesaj' => 'Yalnızca ürün satırı silinebilir.'], 422);
}

$fisId = $satir['fis_id'];
$fis   = siparis_fis_duzenlenebilir($dbh, $firmadonem, $fisId);
if ($fis === null) {
    api_json(['ok' => false, 'mesaj' => 'Sipariş bulunamadı veya iptal edilmiş.'], 404);
}

try {
    $dbh->beginTransaction();

    // Eşzamanlı silmeye karşı: satır hâlâ duruyorsa sil.
    $del = $dbh->prepare("DELETE FROM {$firmadonem}ORFLINE WHERE LOGICALREF = :id AND LINETYPE = 0");
    $del->execute([':id' => $satirId]);
    if ($del->rowCount() === 0) {
        $dbh->rollBack();
        api_json(['ok' => false, 'mesaj' => 'Satır zaten silinmiş.'], 409);
    }

    siparis_lineno_yenile($dbh, $firmadonem, $fisId);
    $top = siparis_fis_toplam_yenile($dbh, $firmadonem, $fisId);

    $dbh->commit();
} catch (Throwable $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    error_log('api/siparis_satir_sil: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Satır silinirken hata oluştu, kayıt yapılmadı.'], 500);
}

if (function_exists('logSatirSilme')) {
    try {
        logSatirSilme($fisId, $satirId, $fis['fisno'], (string) $satir['kod'], (string) $satir['ad'],
            $satir['miktar'], $satir['fiyat'], $satir['toplam'], $personel, 'Masaüstü API ile satır silindi');
    } catch (Throwable $e) {
        error_log('siparis_satir_sil log: ' . $e->getMessage());
    }
}

api_json([
    'ok'  => true,
    'fis' => ['id' => $fisId, 'fisno' => $fis['fisno']],
    'toplam' => [
        'brut'   => $top['brut'],   'brut_metin'   => api_money($top['brut']),
        'iskonto'=> $top['iskonto'],'iskonto_metin'=> api_money($top['iskonto']),
        'kdv'    => $top['kdv'],    'kdv_metin'    => api_money($top['kdv']),
        'net'    => $top['net'],    'net_metin'    => api_money($top['net']),
    ],
    'mesaj' => ($satir['kod'] !== '' ? $satir['kod'] . ' ' : '') . 'satırı silindi.',
]);
