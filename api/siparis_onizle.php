<?php
declare(strict_types=1);

/**
 * POST /api/siparis_onizle.php
 * Gövde: { "cari_id":123, "iskonto1"?:5, "iskonto2"?:0,
 *          "kalemler":[ { "stok_id":1, "miktar":5, "fiyat"?:.., "kdv"?:20, "birim_carpan"?:12, "label"?:.. }, ... ] }
 * Yanıt: { ok, cari, satirlar:[...], atlanan:[...], ara_toplam, iskonto_toplam, kdv_toplam, genel_toplam, satir_sayisi }
 *
 * LOGO'ya HİÇBİR ŞEY YAZMAZ. Doğrular + iskonto/KDV dahil toplamı hesaplar.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');
include_once(__DIR__ . '/_siparis.inc');

global $dbh, $firma, $firmadonemx;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(['ok' => false, 'mesaj' => 'Yalnızca POST desteklenir.'], 405);
}
$oturum   = api_oturum_gerekli($dbh);
$personel = (int) $oturum['personel'];
if (!api_yetki_var($personel, 'M1')) {
    api_json(['ok' => false, 'mesaj' => 'Sipariş oluşturma yetkiniz yok.'], 403);
}

$body     = api_body();
$cariId   = siparis_kimlik($body['cari_id'] ?? 0);
$kalemler = is_array($body['kalemler'] ?? null) ? $body['kalemler'] : [];
if (!is_finite(siparis_sayi($body['iskonto1'] ?? 0)) || !is_finite(siparis_sayi($body['iskonto2'] ?? 0))) {
    api_json(['ok' => false, 'mesaj' => 'Geçerli bir iskonto oranı giriniz.'], 422);
}
$iskonto1 = max(0.0, min(100.0, siparis_sayi($body['iskonto1'] ?? 0)));
$iskonto2 = max(0.0, min(100.0, siparis_sayi($body['iskonto2'] ?? 0)));

if ($cariId <= 0) {
    api_json(['ok' => false, 'mesaj' => 'Cari seçimi gerekiyor.'], 400);
}
if (!$kalemler) {
    api_json(['ok' => false, 'mesaj' => 'En az bir kalem gerekiyor.'], 400);
}
if (count($kalemler) > 200) {
    api_json(['ok' => false, 'mesaj' => 'Tek seferde en fazla 200 kalem gönderilebilir.'], 400);
}

try {
    if (!m_p_cariid_goruntulebilir_mi($dbh, $firma, $personel, $cariId)) {
        api_json(['ok' => false, 'mesaj' => 'Cari bulunamadı.'], 404);
    }

    $cari = siparis_cari_getir($dbh, $firma, $cariId);
    if ($cari === null) {
        api_json(['ok' => false, 'mesaj' => 'Cari bulunamadı.'], 404);
    }
} catch (Throwable $e) {
    error_log('API sipariş önkontrol: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Sipariş bilgileri okunamadı.'], 500);
}


try {
    $h = siparis_kalemleri_hazirla($dbh, $firma, $firmadonemx, $kalemler);
    $hesap = siparis_toplam_hesapla($h['ready'], $iskonto1, $iskonto2);
} catch (Throwable $e) {
    error_log('api/siparis_onizle: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Önizleme sırasında hata oluştu.'], 500);
}

api_json([
    'ok'   => true,
    'cari' => $cari,
    'satirlar' => array_map(static function (array $r): array {
        return [
            'stok_id'      => $r['stok_id'],
            'kod'          => $r['kod'],
            'ad'           => $r['ad'],
            'miktar'       => $r['miktar'],
            'birim_carpan' => $r['birim_carpan'],
            'fiyat'        => $r['fiyat'],
            'fiyat_metin'  => api_money($r['fiyat']),
            'kdv'          => $r['kdv'],
            'brut'         => $r['brut'],
            'iskonto'      => $r['iskonto'],
            'net'          => $r['net'],
            'toplam'       => $r['net'],
            'toplam_metin' => api_money($r['net']),
        ];
    }, $hesap['satirlar']),
    'atlanan'              => $h['skipped'],
    'satir_sayisi'         => count($hesap['satirlar']),
    'iskonto1'             => $iskonto1,
    'iskonto2'             => $iskonto2,
    'ara_toplam'           => $hesap['ara_toplam'],
    'ara_toplam_metin'     => api_money($hesap['ara_toplam']),
    'iskonto_toplam'       => $hesap['iskonto_toplam'],
    'iskonto_toplam_metin' => api_money($hesap['iskonto_toplam']),
    'kdv_toplam'           => $hesap['kdv_toplam'],
    'kdv_toplam_metin'     => api_money($hesap['kdv_toplam']),
    'genel_toplam'         => $hesap['genel_toplam'],
    'genel_toplam_metin'   => api_money($hesap['genel_toplam']),
]);
