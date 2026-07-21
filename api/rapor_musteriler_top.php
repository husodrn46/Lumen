<?php
declare(strict_types=1);

/**
 * POST /api/rapor_musteriler_top.php
 * Gövde: { "year"?: 2026, "limit"?: 50 }
 * Yanıt: { ok, year, ozet:{...}, musteriler:[{kod, ad, sehir, ciro, ciro_metin}] }
 *
 * En değerli müşteriler — yıl bazlı satış cirosu (INVOICE+STLINE TRCODE 7,8, ürün kodu ön ekine göre).
 * rapor_musteriler_top.php (web) mantığıyla birebir. Token zorunlu, salt-okuma.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma, $firmadonem;
api_oturum_gerekli($dbh);

$body  = api_body();
$year  = (int) ($body['year'] ?? ($_GET['year'] ?? 0));
$limit = max(1, min(100, (int) ($body['limit'] ?? 50)));
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

$urunKosulu = urun_kodu_kosulu('IT');

try {
    // Özet (tüm müşteriler): müşteri sayısı + toplam ciro.
    $kpi = $dbh->prepare("
        SELECT COUNT(DISTINCT I.CLIENTREF) AS MUSTERI,
               SUM(ISNULL(L.LINENET,0) + ISNULL(L.VATAMNT,0)) AS CIRO
        FROM {$firmadonem}INVOICE I WITH(NOLOCK)
        JOIN {$firmadonem}STLINE L WITH(NOLOCK) ON L.INVOICEREF = I.LOGICALREF AND L.TRCODE IN (7,8)
        JOIN {$firma}ITEMS IT WITH(NOLOCK) ON IT.LOGICALREF = L.STOCKREF
        JOIN {$firma}CLCARD C WITH(NOLOCK) ON I.CLIENTREF = C.LOGICALREF
        WHERE C.ACTIVE = 0 AND I.CANCELLED = 0 AND I.TRCODE IN (7,8)
          AND YEAR(I.DATE_) = :y AND {$urunKosulu}
    ");
    $kpi->execute([':y' => $year]);
    $k = $kpi->fetch(PDO::FETCH_ASSOC) ?: [];
    $toplamMusteri = (int) ($k['MUSTERI'] ?? 0);
    $toplamCiro    = round((float) ($k['CIRO'] ?? 0), 2);

    // TOP {limit} müşteri.
    $st = $dbh->prepare("
        SELECT TOP {$limit}
            C.CODE        AS kod,
            C.DEFINITION_ AS ad,
            C.CITY        AS sehir,
            SUM(ISNULL(L.LINENET,0) + ISNULL(L.VATAMNT,0)) AS ciro
        FROM {$firmadonem}INVOICE I WITH(NOLOCK)
        JOIN {$firmadonem}STLINE L WITH(NOLOCK) ON L.INVOICEREF = I.LOGICALREF AND L.TRCODE IN (7,8)
        JOIN {$firma}ITEMS IT WITH(NOLOCK) ON IT.LOGICALREF = L.STOCKREF
        JOIN {$firma}CLCARD C WITH(NOLOCK) ON I.CLIENTREF = C.LOGICALREF
        WHERE C.ACTIVE = 0 AND I.CANCELLED = 0 AND I.TRCODE IN (7,8)
          AND YEAR(I.DATE_) = :y AND {$urunKosulu}
        GROUP BY C.CODE, C.DEFINITION_, C.CITY
        ORDER BY ciro DESC
    ");
    $st->execute([':y' => $year]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('api/rapor_musteriler_top: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Rapor alınamadı.'], 500);
}

$musteriler = array_map(static function (array $r): array {
    $ciro = round((float) $r['ciro'], 2);
    return [
        'kod'        => (string) ($r['kod'] ?? ''),
        'ad'         => api_metin($r['ad'] ?? ''),
        'sehir'      => api_metin($r['sehir'] ?? ''),
        'ciro'       => $ciro,
        'ciro_metin' => api_money($ciro),
    ];
}, $rows);

$ortalama = $toplamMusteri > 0 ? round($toplamCiro / $toplamMusteri, 2) : 0.0;
$enDegerli = $musteriler[0] ?? null;

api_json([
    'ok'   => true,
    'year' => $year,
    'ozet' => [
        'toplam_musteri'     => $toplamMusteri,
        'toplam_ciro'        => $toplamCiro,
        'toplam_ciro_metin'  => api_money($toplamCiro),
        'ortalama_metin'     => api_money($ortalama),
        'en_degerli_ad'      => $enDegerli['ad'] ?? '-',
        'en_degerli_metin'   => $enDegerli['ciro_metin'] ?? api_money(0),
    ],
    'musteriler' => $musteriler,
    'sayi'       => count($musteriler),
]);
