<?php
declare(strict_types=1);

/**
 * GET/POST /api/cari_ara.php
 * Gövde (JSON): { "query": "ahmet", "limit"?: 20 }  ·  veya  ?q=ahmet
 * Yanıt: { ok:true, adaylar:[{ id, kod, ad, sehir, skor }], sayi }
 *
 * Token zorunlu (Authorization: Bearer). Türkçe-duyarlı (aksan/harf duyarsız)
 * skor tabanlı arama; SQL kalıbı mevcut ai_beta_cari_ara ile aynıdır.
 */

include_once(__DIR__ . '/../ayr.php');   // $dbh, $firma, trcevir, m_p_yetki
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma;
$oturum   = api_oturum_gerekli($dbh);
$personel = (int) $oturum['personel'];

$body  = api_body();
$query = trim((string) ($body['query'] ?? ($_GET['q'] ?? '')));
$limit = max(1, min(50, (int) ($body['limit'] ?? 20)));

if (mb_strlen($query) < 2) {
    api_json(['ok' => true, 'adaylar' => [], 'sayi' => 0]);
}

$norm     = api_arama_norm($query);
$codeNorm = api_sql_norm('C.CODE');
$defNorm  = api_sql_norm('C.DEFINITION_');
$cityNorm = api_sql_norm('C.CITY');

// Özel Cari kısıtı: kısıtlı cariler arama sonuçlarında hiç görünmez
// (web ../cari/cari.php ile aynı kural).
$ozelCariFiltresi = m_p_ozel_cari_sql_filtresi($personel, 'C.LOGICALREF', 'api_cari_ara');
$ozelCariSql = ($ozelCariFiltresi['sql'] ?? '') !== '' ? "\n          AND " . $ozelCariFiltresi['sql'] : '';

try {
    $sql = "
        SELECT TOP {$limit}
            C.LOGICALREF AS id,
            C.CODE        AS code,
            C.DEFINITION_ AS name,
            C.CITY        AS city,
            CASE
                WHEN {$codeNorm} = :exact_code      THEN 100
                WHEN {$defNorm}  = :exact_def       THEN 95
                WHEN {$codeNorm} LIKE :prefix_code  THEN 88
                WHEN {$defNorm}  LIKE :prefix_def   THEN 82
                WHEN {$cityNorm} LIKE :like_city    THEN 45
                ELSE 55
            END AS score
        FROM {$firma}CLCARD C WITH(NOLOCK)
        WHERE C.ACTIVE = 0
          AND (
                {$codeNorm} LIKE :w_code
             OR {$defNorm}  LIKE :w_def
             OR {$cityNorm} LIKE :w_city
          ){$ozelCariSql}
        ORDER BY score DESC, C.DEFINITION_ ASC
    ";
    $stmt = $dbh->prepare($sql);
    $stmt->execute(array_merge([
        ':exact_code'  => $norm,
        ':exact_def'   => $norm,
        ':prefix_code' => $norm . '%',
        ':prefix_def'  => $norm . '%',
        ':like_city'   => '%' . $norm . '%',
        ':w_code'      => '%' . $norm . '%',
        ':w_def'       => '%' . $norm . '%',
        ':w_city'      => '%' . $norm . '%',
    ], $ozelCariFiltresi['params'] ?? []));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('api/cari_ara: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Cari aramasi sirasinda hata olustu.'], 500);
}

$adaylar = array_map(static function (array $r): array {
    return [
        'id'    => (int) $r['id'],
        'kod'   => (string) $r['code'],
        'ad'    => api_metin($r['name']),
        'sehir' => api_metin($r['city'] ?? ''),
        'skor'  => (int) $r['score'],
    ];
}, $rows);

api_json(['ok' => true, 'adaylar' => $adaylar, 'sayi' => count($adaylar)]);
