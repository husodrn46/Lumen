<?php
declare(strict_types=1);

/**
 * POST /api/bekleyen_siparis.php
 * Gövde: { "limit"?: 1000 }
 * Yanıt: { ok, ozet:{...}, urunler:[{kod, ad, birim, koli_ici, stok_miktar, stok_koli,
 *                                      bekleyen_miktar, bekleyen_koli, uret_koli}] }
 *
 * Üretim planı: ürün kodu ön ekine göre eldeki stok ile bekleyen (sevkedilmemiş) sipariş
 * dengesi; üretilmesi gereken koli. ../siparis/bekleyen_siparis.php (web) mantığıyla birebir.
 * Token zorunlu, salt-okuma.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma, $firmadonem, $firmadonemx;
$oturum = api_oturum_gerekli($dbh);
api_yetki_gerekli((int) $oturum['personel'], 'M8');

$body  = api_body();
$limit = max(1, min(2000, (int) ($body['limit'] ?? 1000)));

try {
    $urunKosulu = urun_kodu_kosulu('URUN');

    $sql = "
    SELECT
        b.[URUN KODU] AS UrunKodu, b.[URUN ADI] AS UrunAdi, b.[URUN ID] AS UrunID,
        b.[BIRIM] AS Birim, b.[KOLI_ICI] AS KoliIci, b.MIKTAR AS StokMiktar,
        COALESCE(f.BeklenenMiktar, 0) AS BekleyenMiktar,
        FLOOR(b.MIKTAR / NULLIF(b.[KOLI_ICI], 0)) AS StokKoli,
        CEILING(COALESCE(f.BeklenenMiktar, 0) / NULLIF(b.[KOLI_ICI], 0)) AS BekleyenKoli,
        CASE
            WHEN CEILING(COALESCE(f.BeklenenMiktar, 0) / NULLIF(b.[KOLI_ICI], 0)) > FLOOR(b.MIKTAR / NULLIF(b.[KOLI_ICI], 0))
            THEN CEILING(COALESCE(f.BeklenenMiktar, 0) / NULLIF(b.[KOLI_ICI], 0)) - FLOOR(b.MIKTAR / NULLIF(b.[KOLI_ICI], 0))
            ELSE 0
        END AS UretKoli
    FROM (
        SELECT TOP {$limit}
            URUN.CODE AS [URUN KODU], URUN.NAME AS [URUN ADI], URUN.LOGICALREF AS [URUN ID],
            BIRIM.CODE AS [BIRIM],
            ISNULL(AMBARM.MIKTAR, 0) AS MIKTAR,
            ISNULL(MAX(ICBIRIM.CONVFACT2), 1) AS [KOLI_ICI]
        FROM {oj {$firma}ITEMS URUN
            LEFT JOIN (
                SELECT SUM(ONHAND) AS MIKTAR, STOCKREF FROM {$firmadonemx}STINVTOT WHERE INVENNO = -1 GROUP BY STOCKREF
            ) AMBARM ON URUN.LOGICALREF = AMBARM.STOCKREF
            LEFT JOIN {$firma}UNITSETL BIRIM ON URUN.UNITSETREF = BIRIM.UNITSETREF
            LEFT JOIN {$firma}ITMUNITA ICBIRIM ON URUN.LOGICALREF = ICBIRIM.ITEMREF
        }
        WHERE URUN.CARDTYPE <> '22' AND BIRIM.LINENR = 1 AND URUN.ACTIVE = 0
            AND {$urunKosulu}
        GROUP BY URUN.CODE, URUN.NAME, URUN.LOGICALREF, BIRIM.CODE, AMBARM.MIKTAR
    ) AS b
    LEFT JOIN (
        SELECT STOCKREF, SUM(AMOUNT - SHIPPEDAMOUNT) AS BeklenenMiktar
        FROM {$firmadonem}ORFLINE
        WHERE TRCODE = 1 AND STATUS <> 2 AND CLOSED = 0 AND AMOUNT > SHIPPEDAMOUNT
        GROUP BY STOCKREF
    ) AS f ON f.STOCKREF = b.[URUN ID]
    ORDER BY UretKoli DESC, UrunKodu
    ";
    $rows = $dbh->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('api/bekleyen_siparis: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Bekleyen sipariş raporu alınamadı.'], 500);
}

$toplamSatir = count($rows);
$uretimGereken = 0;
$toplamBekleyenKoli = 0.0;
$toplamUretimKoli = 0.0;

$urunler = array_map(static function (array $r) use (&$uretimGereken, &$toplamBekleyenKoli, &$toplamUretimKoli): array {
    $uret = (float) ($r['UretKoli'] ?? 0);
    $bekKoli = (float) ($r['BekleyenKoli'] ?? 0);
    $toplamBekleyenKoli += $bekKoli;
    $toplamUretimKoli   += $uret;
    if ($uret > 0) {
        $uretimGereken++;
    }
    return [
        'kod'             => (string) $r['UrunKodu'],
        'ad'              => api_metin($r['UrunAdi'] ?? ''),
        'birim'           => (string) ($r['Birim'] ?? ''),
        'koli_ici'        => (float) ($r['KoliIci'] ?? 0),
        'stok_miktar'     => (float) ($r['StokMiktar'] ?? 0),
        'stok_koli'       => (float) ($r['StokKoli'] ?? 0),
        'bekleyen_miktar' => (float) ($r['BekleyenMiktar'] ?? 0),
        'bekleyen_koli'   => $bekKoli,
        'uret_koli'       => $uret,
    ];
}, $rows);

$oran = $toplamSatir > 0 ? round(($uretimGereken / $toplamSatir) * 100, 1) : 0.0;

api_json([
    'ok' => true,
    'ozet' => [
        'toplam_urun'          => $toplamSatir,
        'uretim_gereken'       => $uretimGereken,
        'uretim_orani'         => $oran,
        'toplam_bekleyen_koli' => round($toplamBekleyenKoli, 0),
        'toplam_uretim_koli'   => round($toplamUretimKoli, 0),
    ],
    'urunler' => $urunler,
    'sayi'    => $toplamSatir,
]);
