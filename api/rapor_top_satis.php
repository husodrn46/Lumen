<?php
declare(strict_types=1);

/**
 * GET/POST /api/rapor_top_satis.php
 * Gövde: { "limit"?: 15 }
 * Yanıt: { ok, urunler:[{ kod, ad, miktar, tutar, tutar_metin, fis_sayisi, son_tarih }] }
 * Token zorunlu. En çok satılan ürünler (satış faturaları TRCODE 7,8; net tutara göre).
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma, $firmadonem;
api_oturum_gerekli($dbh);

$body  = api_body();
$limit = max(1, min(50, (int) ($body['limit'] ?? ($_GET['limit'] ?? 15))));

try {
    $stmt = $dbh->prepare("
        SELECT TOP {$limit}
            IT.CODE AS code,
            IT.NAME AS name,
            SUM(ISNULL(L.AMOUNT, 0))  AS total_qty,
            SUM(ISNULL(L.LINENET, 0)) AS total_amount,
            COUNT(DISTINCT I.LOGICALREF) AS invoice_count,
            MAX(I.DATE_) AS last_date
        FROM {$firmadonem}INVOICE I WITH(NOLOCK)
        INNER JOIN {$firmadonem}STLINE L WITH(NOLOCK)
            ON L.INVOICEREF = I.LOGICALREF AND L.CANCELLED = 0 AND L.LINETYPE = 0
        INNER JOIN {$firma}ITEMS IT WITH(NOLOCK) ON IT.LOGICALREF = L.STOCKREF
        WHERE I.CANCELLED = 0 AND I.TRCODE IN (7, 8)
        GROUP BY IT.LOGICALREF, IT.CODE, IT.NAME
        ORDER BY SUM(ISNULL(L.LINENET, 0)) DESC, SUM(ISNULL(L.AMOUNT, 0)) DESC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('api/rapor_top_satis: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Rapor alınamadı.'], 500);
}

$urunler = array_map(static function (array $r): array {
    $tutar = (float) ($r['total_amount'] ?? 0);
    return [
        'kod'        => (string) $r['code'],
        'ad'         => api_metin($r['name']),
        'miktar'     => (float) ($r['total_qty'] ?? 0),
        'tutar'      => $tutar,
        'tutar_metin' => api_money($tutar),
        'fis_sayisi' => (int) ($r['invoice_count'] ?? 0),
        'son_tarih'  => api_tarih($r['last_date'] ?? null),
    ];
}, $rows);

api_json(['ok' => true, 'urunler' => $urunler, 'sayi' => count($urunler)]);
