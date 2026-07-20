<?php
declare(strict_types=1);

/**
 * GET/POST /api/siparis_detay_getir.php
 * Gövde: { "order_id":123 }  ·  veya  { "fisno":"AKLSP008745" }
 * Yanıt: { ok, fis:{ id, fisno, tarih, cari_kod, cari_ad, brut, iskonto, kdv, net, iptal }, satirlar:[...], satir_sayisi }
 *
 * Token zorunlu. Bir siparişin başlık + ürün satırlarını (LINETYPE=0) döndürür.
 * Salt-okuma. Satır durumu sevk/açık miktara göre belirlenir.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma, $firmadonem;
api_oturum_gerekli($dbh);

$body    = api_body();
$orderId = (int) ($body['order_id'] ?? ($_GET['order_id'] ?? 0));
$fisno   = trim((string) ($body['fisno'] ?? ($_GET['fisno'] ?? '')));

try {
    if ($orderId <= 0 && $fisno !== '') {
        $st = $dbh->prepare("SELECT TOP 1 LOGICALREF FROM {$firmadonem}ORFICHE WITH(NOLOCK)
                             WHERE UPPER(FICHENO) = :f AND TRCODE = 1");
        $st->execute([':f' => mb_strtoupper($fisno, 'UTF-8')]);
        $orderId = (int) ($st->fetchColumn() ?: 0);
    }
    if ($orderId <= 0) {
        api_json(['ok' => false, 'mesaj' => 'Sipariş bulunamadı.'], 404);
    }

    $hStmt = $dbh->prepare("
        SELECT TOP 1
            F.LOGICALREF AS id, F.FICHENO AS fisno, F.DATE_ AS tarih,
            ISNULL(F.GROSSTOTAL, 0)     AS brut,
            ISNULL(F.TOTALDISCOUNTS, 0) AS iskonto,
            ISNULL(F.TOTALVAT, 0)       AS kdv,
            ISNULL(F.NETTOTAL, 0)       AS net,
            ISNULL(F.CANCELLED, 0)      AS iptal,
            C.CODE AS cari_kod, C.DEFINITION_ AS cari_ad
        FROM {$firmadonem}ORFICHE F WITH(NOLOCK)
        LEFT JOIN {$firma}CLCARD C WITH(NOLOCK) ON C.LOGICALREF = F.CLIENTREF
        WHERE F.LOGICALREF = :id AND F.TRCODE = 1
    ");
    $hStmt->execute([':id' => $orderId]);
    $f = $hStmt->fetch(PDO::FETCH_ASSOC);
    if (!$f) {
        api_json(['ok' => false, 'mesaj' => 'Sipariş bulunamadı.'], 404);
    }

    $lStmt = $dbh->prepare("
        SELECT
            L.LOGICALREF AS satir_id,
            L.STOCKREF AS stok_id, I.CODE AS kod, I.NAME AS ad,
            ISNULL(L.AMOUNT, 0)        AS miktar,
            ISNULL(L.SHIPPEDAMOUNT, 0) AS sevk,
            ISNULL(L.PRICE, 0)         AS fiyat,
            ISNULL(L.TOTAL, 0)         AS brut,
            ISNULL(L.LINENET, 0)       AS net,
            ISNULL(L.VAT, 0)           AS kdv_oran,
            ISNULL(L.CLOSED, 0)        AS kapali,
            (SELECT TOP 1 IU.CONVFACT2 FROM {$firma}ITMUNITA IU WITH(NOLOCK)
               LEFT JOIN {$firma}UNITSETL BU WITH(NOLOCK) ON BU.LOGICALREF = IU.UNITLINEREF
               WHERE IU.ITEMREF = L.STOCKREF AND IU.LINENR = 2
                 AND BU.UNITSETREF = I.UNITSETREF) AS koli_carpan
        FROM {$firmadonem}ORFLINE L WITH(NOLOCK)
        LEFT JOIN {$firma}ITEMS I WITH(NOLOCK) ON I.LOGICALREF = L.STOCKREF
        WHERE L.ORDFICHEREF = :id AND L.LINETYPE = 0
        ORDER BY L.LINENO_ ASC
    ");
    $lStmt->execute([':id' => $orderId]);
    $satirlar = $lStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('api/siparis_detay_getir: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Sipariş detayı alınamadı.'], 500);
}

$satirOut = array_map(static function (array $r): array {
    $miktar = (float) $r['miktar'];
    $sevk   = (float) $r['sevk'];
    $acik   = ((int) $r['kapali'] === 1) ? 0.0 : max(0.0, $miktar - $sevk);
    $durum  = (int) $r['kapali'] === 1 ? 'Kapalı'
        : ($sevk <= 0 ? 'Bekliyor' : ($acik <= 0 ? 'Sevk edildi' : 'Kısmi sevk'));
    return [
        'satir_id'    => (int) $r['satir_id'],
        'stok_id'     => (int) $r['stok_id'],
        'kod'         => (string) ($r['kod'] ?? ''),
        'ad'          => api_metin($r['ad'] ?? ''),
        'miktar'      => $miktar,
        'sevk'        => $sevk,
        'acik'        => $acik,
        'fiyat'       => (float) $r['fiyat'],
        'fiyat_metin' => api_money((float) $r['fiyat']),
        'net'         => (float) $r['net'],
        'net_metin'   => api_money((float) $r['net']),
        'kdv_oran'    => (float) $r['kdv_oran'],
        'koli_carpan' => (float) ($r['koli_carpan'] ?? 0),
        'durum'       => $durum,
    ];
}, $satirlar);

api_json([
    'ok'  => true,
    'fis' => [
        'id'            => (int) $f['id'],
        'fisno'         => (string) $f['fisno'],
        'tarih'         => api_tarih($f['tarih']),
        'cari_kod'      => (string) ($f['cari_kod'] ?? ''),
        'cari_ad'       => api_metin($f['cari_ad'] ?? ''),
        'brut'          => (float) $f['brut'],
        'brut_metin'    => api_money((float) $f['brut']),
        'iskonto'       => (float) $f['iskonto'],
        'iskonto_metin' => api_money((float) $f['iskonto']),
        'kdv'           => (float) $f['kdv'],
        'kdv_metin'     => api_money((float) $f['kdv']),
        'net'           => (float) $f['net'],
        'net_metin'     => api_money((float) $f['net']),
        'iptal'         => (int) $f['iptal'] === 1,
    ],
    'satirlar'     => $satirOut,
    'satir_sayisi' => count($satirOut),
]);
