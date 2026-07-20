<?php
declare(strict_types=1);

/**
 * GET/POST /api/fiyat_listesi.php
 * Gövde: { "query"?:"akl", "limit"?:1000 }
 * Yanıt: { ok, urunler:[{ kod, ad, fiyat, fiyat_metin, kdv, kdv_dahil, kdv_dahil_metin }], sayi }
 *
 * Token zorunlu. Aktif ürünlerin (ITEMS ACTIVE=0) satış fiyat listesi: PRCLIST (CARDREF,
 * ACTIVE=0) fiyatı + ITEMS.VAT KDV oranı + KDV dahil. PRCLIST satış satırı (PTYPE 1) öncelikli.
 * (Web fiyat_listesi.php ile aynı kaynak; çoklu fiyat satırına karşı TOP 1 alt sorgu.)
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma;
api_oturum_gerekli($dbh);

$body  = api_body();
$query = trim((string) ($body['query'] ?? ($_GET['q'] ?? '')));
$limit = max(1, min(5000, (int) ($body['limit'] ?? ($_GET['limit'] ?? 1000))));

$wheres = ['I.ACTIVE = 0'];
$params = [];
if ($query !== '') {
    $norm = api_arama_norm($query);
    $wheres[] = '(' . api_sql_norm('I.CODE') . ' LIKE :q_k OR ' . api_sql_norm('I.NAME') . ' LIKE :q_n)';
    $params[':q_k'] = '%' . $norm . '%';
    $params[':q_n'] = '%' . $norm . '%';
}
$whereSql = implode(' AND ', $wheres);

try {
    $sql = "
        SELECT TOP {$limit}
            I.CODE AS kod, I.NAME AS ad, ISNULL(I.VAT, 0) AS kdv,
            ISNULL((SELECT TOP 1 P.PRICE FROM {$firma}PRCLIST P WITH(NOLOCK)
                    WHERE P.CARDREF = I.LOGICALREF AND P.ACTIVE = 0
                    ORDER BY P.PTYPE ASC, P.LOGICALREF ASC), 0) AS fiyat
        FROM {$firma}ITEMS I WITH(NOLOCK)
        WHERE {$whereSql}
        ORDER BY I.CODE";
    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('api/fiyat_listesi: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Fiyat listesi alınamadı.'], 500);
}

$urunler = array_map(static function (array $r): array {
    $fiyat = (float) $r['fiyat'];
    $kdv   = (float) $r['kdv'];
    $dahil = $fiyat * (1 + $kdv / 100);
    return [
        'kod'             => (string) ($r['kod'] ?? ''),
        'ad'              => api_metin($r['ad'] ?? ''),
        'fiyat'           => $fiyat,
        'fiyat_metin'     => api_money($fiyat),
        'kdv'             => $kdv,
        'kdv_dahil'       => $dahil,
        'kdv_dahil_metin' => api_money($dahil),
    ];
}, $rows);

api_json(['ok' => true, 'urunler' => $urunler, 'sayi' => count($urunler)]);
