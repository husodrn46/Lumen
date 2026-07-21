<?php
declare(strict_types=1);

/**
 * POST /api/siparis_miktar_guncelle.php
 * Gövde: { "satir_id":456, "miktar":10, "fiyat"?:.., "kdv"?:.. }
 *   miktar = yeni AMOUNT (ana birim). fiyat/kdv verilmezse mevcut korunur.
 * Yanıt: { ok, toplam:{...}, mesaj }
 *
 * ../siparis/hareketduzenle.php sözleşmesi: ORFLINE UPDATE (AMOUNT/PRICE/VAT/VATAMNT/TOTAL/
 * VATMATRAH/LINENET). Ek olarak satır iskontosu (DISTDISC/DISTCOST) SIFIRLANIR —
 * miktar/fiyat değişince eski dağıtım geçersizdir; böylece toplam tutarlı kalır.
 * Ardından ORFICHE toplamı tüm satırlardan yeniden hesaplanır.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');
include_once(__DIR__ . '/_siparis.inc');

global $dbh, $firma, $firmadonem, $reserve, $terminalkullanici;

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
$miktar  = siparis_sayi($body['miktar'] ?? 0);

if ($miktar <= 0 || $miktar > 1000000) {
    api_json(['ok' => false, 'mesaj' => 'Miktar 0 veya negatif olamaz.'], 422);
}

$satir = siparis_satir_getir($dbh, $firma, $firmadonem, $satirId);
if ($satir === null) {
    api_json(['ok' => false, 'mesaj' => 'Satır bulunamadı.'], 404);
}
if ($satir['linetype'] !== 0) {
    api_json(['ok' => false, 'mesaj' => 'Yalnızca ürün satırı düzenlenebilir.'], 422);
}

$fisId = $satir['fis_id'];
$fis   = siparis_fis_duzenlenebilir($dbh, $firmadonem, $fisId);
if ($fis === null) {
    api_json(['ok' => false, 'mesaj' => 'Sipariş bulunamadı veya iptal edilmiş.'], 404);
}

// fiyat/kdv verilmezse mevcut değeri koru.
$fiyatRaw = $body['fiyat'] ?? null;
$fiyat = ($fiyatRaw !== null && $fiyatRaw !== '') ? max(0.0, siparis_sayi($fiyatRaw)) : (float) $satir['fiyat'];
if ($fiyat <= 0 || $fiyat > 100000000) {
    api_json(['ok' => false, 'mesaj' => 'Geçerli bir fiyat giriniz.'], 422);
}
$kdvRaw = $body['kdv'] ?? null;
$kdv = ($kdvRaw !== null && $kdvRaw !== '') ? max(0.0, min(100.0, siparis_sayi($kdvRaw))) : (float) $satir['kdv'];

$toplam   = round($miktar * $fiyat, 2);
$kdvTutar = round(($toplam / 100) * $kdv, 2);
$reserveOn = ((string) $reserve === '1');
$rezerve   = $reserveOn ? $miktar : 0.0;

try {
    $dbh->beginTransaction();

    $upd = $dbh->prepare("UPDATE {$firmadonem}ORFLINE SET
            AMOUNT = :miktar,
            PRICE = :fiyat,
            VAT = :kdv,
            VATAMNT = :kdvtut,
            TOTAL = :toplam,
            VATMATRAH = :toplam2,
            LINENET = :toplam3,
            DISTDISC = 0,
            DISTCOST = 0,
            ORGAMOUNT = :miktar2,
            ORGPRICE = :fiyat2,
            RESERVEAMOUNT = :rezerve,
            RESERVEDATE = GETDATE()
        WHERE LOGICALREF = :id AND LINETYPE = 0");
    $upd->execute([
        ':miktar' => $miktar, ':fiyat' => $fiyat, ':kdv' => $kdv, ':kdvtut' => $kdvTutar,
        ':toplam' => $toplam, ':toplam2' => $toplam, ':toplam3' => $toplam,
        ':miktar2' => $miktar, ':fiyat2' => $fiyat, ':rezerve' => $rezerve,
        ':id' => $satirId,
    ]);
    if ($upd->rowCount() === 0) {
        $dbh->rollBack();
        api_json(['ok' => false, 'mesaj' => 'Satır güncellenemedi (bulunamadı).'], 409);
    }

    $top = siparis_fis_toplam_yenile($dbh, $firmadonem, $fisId);

    $dbh->commit();
} catch (Throwable $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    error_log('api/siparis_miktar_guncelle: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Satır güncellenirken hata oluştu, kayıt yapılmadı.'], 500);
}

if (function_exists('logSatirDuzenleme')) {
    try {
        logSatirDuzenleme($fisId, $satirId, $fis['fisno'], (string) $satir['kod'], (string) $satir['ad'],
            $satir['miktar'], $miktar, $satir['fiyat'], $fiyat, $satir['toplam'], $toplam,
            $personel, 'Masaüstü API ile satır güncellendi');
    } catch (Throwable $e) {
        error_log('siparis_miktar_guncelle log: ' . $e->getMessage());
    }
}

api_json([
    'ok'  => true,
    'fis' => ['id' => $fisId, 'fisno' => $fis['fisno']],
    'satir' => ['id' => $satirId, 'miktar' => $miktar, 'fiyat' => $fiyat, 'kdv' => $kdv,
                'toplam' => $toplam, 'toplam_metin' => api_money($toplam)],
    'toplam' => [
        'brut'   => $top['brut'],   'brut_metin'   => api_money($top['brut']),
        'iskonto'=> $top['iskonto'],'iskonto_metin'=> api_money($top['iskonto']),
        'kdv'    => $top['kdv'],    'kdv_metin'    => api_money($top['kdv']),
        'net'    => $top['net'],    'net_metin'    => api_money($top['net']),
    ],
    'mesaj' => 'Satır güncellendi.',
]);
