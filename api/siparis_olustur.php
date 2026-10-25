<?php
declare(strict_types=1);

/**
 * POST /api/siparis_olustur.php
 * Başlık: Idempotency-Key: UUIDv4 veya 64 hex (isteğe bağlı, önerilir)
 * Gövde: { "cari_id": 123, "kalemler": [ { "stok_id":1, "miktar":5, "fiyat"?:.. }, ... ] }
 * Yanıt: { ok, fis:{id,fisno}, cari, satir_sayisi, ara_toplam, genel_toplam, atlanan, mesaj }
 *
 * LOGO'ya YAZAR (tek transaction): ORFICHE başlık + ORFLINE satırlar + ORFICHE toplam.
 * Yazma sözleşmesi mevcut akıştan birebir alınmıştır (ai_beta_order_header,
 * ../siparis/hareketeklecoklu.php, ../siparis/lg_fis.php toplam güncelleme). Mevcut akışla aynı şekilde
 * satır KDV ve genel iskontoları ortak hesaplarla yazılır. Herhangi bir adım hata verirse tüm işlem geri alınır.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');
include_once(__DIR__ . '/_siparis.inc');
include_once(__DIR__ . '/_idempotency.inc');
require_once __DIR__ . '/../includes/siparis/olustur.php';

global $dbh, $firmano, $firma, $firmadonem, $firmadonemx, $sipno, $depo, $kayitsaat, $saat, $dakika, $saniye, $reserve, $terminalkullanici;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(['ok' => false, 'mesaj' => 'Yalnızca POST desteklenir.'], 405);
}
$oturum   = api_oturum_gerekli($dbh);
$personel = (int) $oturum['personel'];
$terminalkullanici = $personel; // SALESMANREF / CAPIBLOCK alanları için

if (!api_yetki_var($personel, 'M1')) {
    api_json(['ok' => false, 'mesaj' => 'Sipariş oluşturma yetkiniz yok.'], 403);
}

$body = api_body();
try {
    $idempotencyKey = siparis_idempotency_anahtari();
    $bodyHash = $idempotencyKey === null ? null : siparis_idempotency_parmakizi($body);
} catch (InvalidArgumentException | JsonException $e) {
    api_json(['ok' => false, 'mesaj' => 'Geçerli bir Idempotency-Key ve JSON gövdesi gerekli.'], 400);
}
$cariId   = (int) ($body['cari_id'] ?? 0);
$kalemler = is_array($body['kalemler'] ?? null) ? $body['kalemler'] : [];
$iskonto1 = max(0.0, min(100.0, siparis_sayi($body['iskonto1'] ?? 0)));
$iskonto2 = max(0.0, min(100.0, siparis_sayi($body['iskonto2'] ?? 0)));
if ($cariId <= 0) {
    api_json(['ok' => false, 'mesaj' => 'Cari seçimi gerekiyor.'], 400);
}

// Özel Cari kısıtı: kısıtlı cariye sipariş açılamaz
// (web ../siparis/fisekle.php ile aynı kural).
if (!m_p_cariid_goruntulebilir_mi($dbh, $firma, $personel, $cariId)) {
    api_json(['ok' => false, 'mesaj' => 'Cari bulunamadi.'], 404);
}

if (!$kalemler) {
    api_json(['ok' => false, 'mesaj' => 'En az bir kalem gerekiyor.'], 400);
}
if (count($kalemler) > 200) {
    api_json(['ok' => false, 'mesaj' => 'Tek seferde en fazla 200 kalem gönderilebilir.'], 400);
}

$kayitsaatI = (int) $kayitsaat;
$saatI      = (int) $saat;
$dakikaI    = (int) $dakika;
$saniyeI    = (int) $saniye;
$depoI      = (int) $depo;
$reserveOn  = ((string) $reserve === '1');

$brut = 0.0;
$netKdv = 0.0;
$fisId = 0;
$fisno = '';
$yanit = [];
$idempotencyDonem = lumen_bagimsiz_mi() ? 'LUMEN' : $firmadonem;

try {
    $dbh->beginTransaction();

    if ($idempotencyKey !== null) {
        $onceki = siparis_idempotency_baslat(
            $dbh,
            $idempotencyKey,
            (int) $firmano,
            $idempotencyDonem,
            $personel,
            (string) $bodyHash
        );
        if ($onceki !== null) {
            if (!$dbh->commit()) {
                throw new RuntimeException('Commit sonucu doğrulanamadı.');
            }
            api_json($onceki);
        }
    }

    $cari = siparis_cari_getir($dbh, $firma, $cariId);
    if ($cari === null) {
        $dbh->rollBack();
        api_json(['ok' => false, 'mesaj' => 'Cari bulunamadı.'], 404);
    }

    // Fiş AÇMADAN önce tüm kalemleri doğrula (F3 kuralı) — fiyatsız kalem orphan fiş bırakmaz.
    $h = siparis_kalemleri_hazirla(
        $dbh,
        $firma,
        $firmadonemx,
        $kalemler,
        api_yetki_var($personel, 'SP1')
    );
    $ready = $h['ready'];
    if (!$ready) {
        $dbh->rollBack();
        api_json([
            'ok' => false,
            'mesaj' => 'Geçerli kalem bulunamadı, sipariş açılmadı.',
            'atlanan' => $h['skipped'],
        ], 422);
    }

    $yanit = siparis_servis_yaz($dbh, $ready, $cari, [
        'firmadonem' => $firmadonem, 'sipno' => $sipno, 'personel' => $personel,
        'kayitsaat' => $kayitsaatI, 'saat' => $saatI, 'dakika' => $dakikaI,
        'saniye' => $saniyeI, 'depo' => $depoI, 'reserve' => $reserveOn,
    ], $iskonto1, $iskonto2, $h['skipped']);
    $fisId = $yanit['fis']['id'];
    $fisno = $yanit['fis']['fisno'];
    if ($idempotencyKey !== null) {
        siparis_idempotency_tamamla(
            $dbh,
            $idempotencyKey,
            (int) $firmano,
            $idempotencyDonem,
            $personel,
            (string) $bodyHash,
            $yanit
        );
    }
    if (!$dbh->commit()) {
        throw new RuntimeException('Commit sonucu doğrulanamadı.');
    }
} catch (Throwable $e) {
    try {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
    } catch (Throwable $rollbackError) {
        error_log('API sipariş rollback sonucu doğrulanamadı.');
    }
    if ($e instanceof SiparisIdempotencyCakisma) {
        api_json(['ok' => false, 'mesaj' => 'İşlem anahtarı bu istek için kullanılamaz.'], 409);
    }
    if ($e instanceof SiparisIdempotencyHazirDegil) {
        error_log('API idempotency şeması hazır değil.');
        api_json([
            'ok' => false,
            'mesaj' => 'Tekrar gönderim koruması hazır değil; isteği anahtarsız göndermeyin.',
        ], 503);
    }
    if ($e instanceof SiparisIdempotencyBekle) {
        if (!headers_sent()) {
            header('Retry-After: 1');
        }
        api_json([
            'ok' => false,
            'mesaj' => 'İşlem sonucu henüz alınamadı; aynı anahtarla tekrar deneyin.',
        ], 503);
    }
    error_log('api/siparis_olustur: ' . $e->getMessage());
    api_json([
        'ok' => false,
        'mesaj' => $idempotencyKey === null
            ? 'Sipariş sonucu doğrulanamadı; yeniden göndermeden önce kontrol edin.'
            : 'Sipariş sonucu doğrulanamadı; aynı anahtar ve içerikle tekrar deneyin.',
    ], 500);
}

// Denetim izi (mevcut web akışıyla aynı) — log hatası siparişi etkilemez.
if (function_exists('logFisOlusturma')) {
    try {
        logFisOlusturma($fisId, $fisno, $cariId, $personel, [
            'doviz'    => 'TL',
            'depo'     => $depoI,
            'durum'    => 4,
            'aciklama' => 'Masaüstü API ile fiş oluşturuldu',
        ]);
    } catch (Throwable $e) {
        error_log('siparis_olustur log: ' . $e->getMessage());
    }
}

api_json($yanit);
