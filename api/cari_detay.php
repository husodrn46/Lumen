<?php
declare(strict_types=1);

/**
 * GET/POST /api/cari_detay.php
 * Gövde (JSON): { "cari_id": 123 }  ·  veya  ?cari_id=123
 * Yanıt: { ok, cari:{...}, bakiye:{...}, son_satislar:[...], acik_siparis:{...} }
 *
 * Token zorunlu. Bakiye tutarı yalnızca CR1 yetkisi olan personele döner
 * (yetki yoksa bakiye.deger=null, metin='Yetki yok'). Özel Cari kısıtı olan
 * cariler kimlik alanları dahil hiç dönmez (web arayüzüyle aynı kural).
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma, $firmadonem, $firmadonemx;
$oturum   = api_oturum_gerekli($dbh);
$personel = (int) $oturum['personel'];

$body   = api_body();
$cariId = (int) ($body['cari_id'] ?? ($_GET['cari_id'] ?? 0));
if ($cariId <= 0) {
    api_json(['ok' => false, 'mesaj' => 'Cari secimi gerekiyor.'], 400);
}

// Özel Cari kısıtı: kısıtlı cari için kimlik alanları da dönmemeli.
// (Aksi halde cari_id sayarak tüm müşteri listesi çıkarılabilir.)
// Kapsam 'M4': bakiye/ekstre ekranlarıyla (../cari/lg_hareket.php) aynı sıkılık.
if (!m_p_cariid_goruntulebilir_mi($dbh, $firma, $personel, $cariId, 'M4')) {
    api_json(['ok' => false, 'mesaj' => 'Cari bulunamadi.'], 404);
}

$bakiyeYetki = api_yetki_var($personel, 'CR1');

try {
    // Cari kimliği + bakiye + son ürün alım tarihi
    $stmt = $dbh->prepare("
        SELECT TOP 1
            C.LOGICALREF AS id,
            C.CODE        AS code,
            C.DEFINITION_ AS name,
            C.CITY        AS city,
            C.DISTRICT    AS district,
            C.TELNRS1     AS phone,
            C.EMAILADDR   AS email,
            (ISNULL(G.DEBIT, 0) - ISNULL(G.CREDIT, 0)) AS bakiye,
            LS.SON AS son_alim
        FROM {$firma}CLCARD C WITH(NOLOCK)
        LEFT JOIN {$firmadonemx}GNTOTCL G WITH(NOLOCK)
            ON G.CARDREF = C.LOGICALREF AND G.TOTTYP = 1
        OUTER APPLY (
            SELECT TOP 1 I.DATE_ AS SON
            FROM {$firmadonem}INVOICE I WITH(NOLOCK)
            WHERE I.CLIENTREF = C.LOGICALREF AND I.CANCELLED = 0 AND I.TRCODE IN (7, 8)
            ORDER BY I.DATE_ DESC, I.LOGICALREF DESC
        ) LS
        WHERE C.ACTIVE = 0 AND C.LOGICALREF = :cid
    ");
    $stmt->execute([':cid' => $cariId]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) {
        api_json(['ok' => false, 'mesaj' => 'Cari bulunamadi.'], 404);
    }

    // Son 5 satış fişi
    $ss = $dbh->prepare("
        SELECT TOP 5
            I.LOGICALREF AS id,
            I.FICHENO    AS fisno,
            I.DATE_      AS tarih,
            I.NETTOTAL   AS toplam
        FROM {$firmadonem}INVOICE I WITH(NOLOCK)
        WHERE I.CLIENTREF = :cid AND I.CANCELLED = 0 AND I.TRCODE IN (7, 8)
        ORDER BY I.DATE_ DESC, I.LOGICALREF DESC
    ");
    $ss->execute([':cid' => $cariId]);
    $satislar = $ss->fetchAll(PDO::FETCH_ASSOC);

    // Açık (sevkedilmemiş) sipariş özeti
    $os = $dbh->prepare("
        SELECT COUNT(*) AS adet, SUM(ISNULL(T.NETTOTAL, 0)) AS toplam
        FROM (
            SELECT DISTINCT F.LOGICALREF, F.NETTOTAL
            FROM {$firmadonem}ORFICHE F WITH(NOLOCK)
            INNER JOIN {$firmadonem}ORFLINE L WITH(NOLOCK)
                ON L.ORDFICHEREF = F.LOGICALREF AND L.LINETYPE = 0
            WHERE F.CLIENTREF = :cid AND F.TRCODE = 1
              AND ISNULL(F.CANCELLED, 0) = 0 AND ISNULL(F.STATUS, 0) <> 2
              AND ISNULL(L.CLOSED, 0) = 0
              AND (ISNULL(L.AMOUNT, 0) - ISNULL(L.SHIPPEDAMOUNT, 0)) > 0
        ) T
    ");
    $os->execute([':cid' => $cariId]);
    $acik = $os->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('api/cari_detay: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Cari detayi alinirken hata olustu.'], 500);
}

$bakiye = round((float) ($c['bakiye'] ?? 0), 2);
$durum  = $bakiye > 0 ? 'Borçlu' : ($bakiye < 0 ? 'Alacaklı' : 'Sıfır');

api_json([
    'ok'   => true,
    'cari' => [
        'id'      => (int) $c['id'],
        'kod'     => (string) $c['code'],
        'ad'      => api_metin($c['name']),
        'sehir'   => api_metin($c['city'] ?? ''),
        'ilce'    => api_metin($c['district'] ?? ''),
        'telefon' => (string) ($c['phone'] ?? ''),
        'eposta'  => (string) ($c['email'] ?? ''),
    ],
    'bakiye' => [
        'yetki'    => $bakiyeYetki,
        'deger'    => $bakiyeYetki ? $bakiye : null,
        'mutlak'   => $bakiyeYetki ? abs($bakiye) : null,
        'durum'    => $bakiyeYetki ? $durum : null,
        'metin'    => $bakiyeYetki ? (api_money(abs($bakiye)) . ' (' . $durum . ')') : 'Yetki yok',
        'son_alim' => api_tarih($c['son_alim'] ?? null),
    ],
    'son_satislar' => array_map(static function (array $r): array {
        $t = (float) ($r['toplam'] ?? 0);
        return [
            'fisno'        => (string) $r['fisno'],
            'tarih'        => api_tarih($r['tarih']),
            'toplam'       => $t,
            'toplam_metin' => api_money($t),
        ];
    }, $satislar),
    'acik_siparis' => [
        'adet'         => (int) ($acik['adet'] ?? 0),
        'toplam'       => (float) ($acik['toplam'] ?? 0),
        'toplam_metin' => api_money((float) ($acik['toplam'] ?? 0)),
    ],
]);
