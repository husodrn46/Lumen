<?php
declare(strict_types=1);

/**
 * GET/POST /api/stok_ara.php
 * Gövde (JSON): { "query": "tabak", "limit"?: 20 }  ·  veya  ?q=tabak
 * Yanıt: { ok:true, adaylar:[{ id, kod, ad, kdv, birim, stok, fiyat, fiyat_metin, skor }],
 *          sayi, yetki:{ stok, fiyat } }
 *
 * Token zorunlu. Eldeki stok yalnızca ST1, fiyat yalnızca ST2 yetkisinde döner
 * (yoksa ilgili alan null). SQL kalıbı mevcut ai_beta_stok_ara ile aynıdır.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma, $firmadonemx;
$oturum   = api_oturum_gerekli($dbh);
$personel = (int) $oturum['personel'];

$body  = api_body();
$query = trim((string) ($body['query'] ?? ($_GET['q'] ?? '')));
$limit = max(1, min(50, (int) ($body['limit'] ?? 20)));

if (mb_strlen($query) < 2) {
    api_json(['ok' => true, 'adaylar' => [], 'sayi' => 0, 'yetki' => ['stok' => false, 'fiyat' => false]]);
}

$canQty   = api_yetki_var($personel, 'ST1');
$canPrice = api_yetki_var($personel, 'ST2');

$norm     = api_arama_norm($query);
$codeNorm = api_sql_norm('S.CODE');
$nameNorm = api_sql_norm('S.NAME');

try {
    // STINVTOT stok başına birden çok satır tutar (INVENNO=-1 dönemsel kayıtlar);
    // doğru eldeki miktar bunların toplamıdır (bkz. stok_miktar_bul). Birim ve
    // fiyat da OUTER APPLY ile tekilleştirilir, böylece her ürün TEK satır gelir.
    $sql = "
        SELECT TOP {$limit}
            S.LOGICALREF AS id,
            S.CODE        AS code,
            S.NAME        AS name,
            S.VAT         AS vat,
            ISNULL(U.CODE, '')   AS unit_code,
            ISNULL(ST.ONHAND, 0) AS onhand,
            ISNULL(PL.PRICE, 0)  AS price,
            CASE
                WHEN {$codeNorm} = :exact_code     THEN 100
                WHEN {$nameNorm} = :exact_name     THEN 95
                WHEN {$codeNorm} LIKE :prefix_code THEN 90
                WHEN {$nameNorm} LIKE :prefix_name THEN 78
                ELSE 55
            END AS score
        FROM {$firma}ITEMS S WITH(NOLOCK)
        OUTER APPLY (
            SELECT TOP 1 B.CODE
            FROM {$firma}UNITSETL B WITH(NOLOCK)
            WHERE B.UNITSETREF = S.UNITSETREF AND B.MAINUNIT = 1
        ) U
        OUTER APPLY (
            SELECT SUM(X.ONHAND) AS ONHAND
            FROM {$firmadonemx}STINVTOT X WITH(NOLOCK)
            WHERE X.STOCKREF = S.LOGICALREF AND X.INVENNO = -1
        ) ST
        OUTER APPLY (
            SELECT TOP 1 P.PRICE
            FROM {$firma}PRCLIST P WITH(NOLOCK)
            WHERE P.CARDREF = S.LOGICALREF AND P.PTYPE = 2 AND P.ACTIVE = 0
            ORDER BY P.LOGICALREF DESC
        ) PL
        WHERE S.ACTIVE = 0 AND S.CARDTYPE <> 22
          AND ({$codeNorm} LIKE :w_code OR {$nameNorm} LIKE :w_name)
        ORDER BY score DESC, S.CODE ASC
    ";
    $stmt = $dbh->prepare($sql);
    $stmt->execute([
        ':exact_code'  => $norm,
        ':exact_name'  => $norm,
        ':prefix_code' => $norm . '%',
        ':prefix_name' => $norm . '%',
        ':w_code'      => '%' . $norm . '%',
        ':w_name'      => '%' . $norm . '%',
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('api/stok_ara: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Urun aramasi sirasinda hata olustu.'], 500);
}

$adaylar = array_map(static function (array $r) use ($canQty, $canPrice): array {
    $fiyat = (float) $r['price'];
    return [
        'id'          => (int) $r['id'],
        'kod'         => (string) $r['code'],
        'ad'          => api_metin($r['name']),
        'kdv'         => (float) $r['vat'],
        'birim'       => (string) ($r['unit_code'] ?? ''),
        'stok'        => $canQty ? (float) $r['onhand'] : null,
        'fiyat'       => $canPrice ? $fiyat : null,
        'fiyat_metin' => $canPrice ? api_money($fiyat) : null,
        'skor'        => (int) $r['score'],
    ];
}, $rows);

api_json([
    'ok'      => true,
    'adaylar' => $adaylar,
    'sayi'    => count($adaylar),
    'yetki'   => ['stok' => $canQty, 'fiyat' => $canPrice],
]);
