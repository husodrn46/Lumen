<?php
declare(strict_types=1);

/**
 * GET/POST /api/rapor_kasa.php
 * Yanıt: { ok, kasalar:[{ kod, ad, bakiye, bakiye_metin, doviz }], ozet:{ tl_toplam,
 *          tl_toplam_metin, kasa_sayisi, doviz_sayisi } }
 *
 * Token zorunlu. Aktif kasaların (KSCARD ACTIVE=0) anlık bakiyesini döndürür:
 * KSLINES SIGN=0 (giren) − SIGN=1 (çıkan), ileri tarihli hareketler hariç.
 * Kodu 'DÖVİZ-%' ile başlayan kasalarda TRNET (orijinal döviz), diğerlerinde AMOUNT (TL).
 * (rapor/rapor_kasa.php çekirdek bakiye sorgusuyla birebir. TL toplam yalnızca TL kasaları kapsar;
 * döviz kasaları kendi para biriminde gösterilir — canlı kur çevirimi yapılmaz.)
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma, $firmadonem;
api_oturum_gerekli($dbh);

try {
    $sql = "
        ;WITH S AS (
            SELECT k.CODE AS kod, k.NAME AS ad,
                SUM(CASE WHEN l.SIGN = 0 THEN ISNULL(l.TRNET, 0)  ELSE -ISNULL(l.TRNET, 0)  END) AS net_trnet,
                SUM(CASE WHEN l.SIGN = 0 THEN ISNULL(l.AMOUNT, 0) ELSE -ISNULL(l.AMOUNT, 0) END) AS net_amount
            FROM {$firma}KSCARD k WITH(NOLOCK)
            LEFT JOIN {$firmadonem}KSLINES l WITH(NOLOCK)
                ON l.CARDREF = k.LOGICALREF AND l.DATE_ <= GETDATE()
            WHERE k.ACTIVE = 0
            GROUP BY k.CODE, k.NAME
        )
        SELECT kod, ad,
            CASE WHEN kod LIKE N'DÖVİZ-%' THEN net_trnet ELSE net_amount END AS bakiye,
            CASE WHEN kod LIKE N'DÖVİZ-%' THEN 1 ELSE 0 END AS doviz
        FROM S
        ORDER BY doviz ASC, kod ASC";
    $rows = $dbh->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('api/rapor_kasa: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Kasa durumu alınamadı.'], 500);
}

$tlToplam = 0.0;
$dovizSayisi = 0;
$kasalar = array_map(static function (array $r) use (&$tlToplam, &$dovizSayisi): array {
    $bakiye = (float) $r['bakiye'];
    $doviz  = (int) $r['doviz'] === 1;
    if ($doviz) {
        $dovizSayisi++;
    } else {
        $tlToplam += $bakiye;
    }
    return [
        'kod'          => (string) $r['kod'],
        'ad'           => api_metin($r['ad'] ?? ''),
        'bakiye'       => $bakiye,
        'bakiye_metin' => api_money($bakiye),
        'doviz'        => $doviz,
    ];
}, $rows);

api_json([
    'ok'      => true,
    'kasalar' => $kasalar,
    'ozet'    => [
        'tl_toplam'       => $tlToplam,
        'tl_toplam_metin' => api_money($tlToplam),
        'kasa_sayisi'     => count($kasalar),
        'doviz_sayisi'    => $dovizSayisi,
    ],
]);
