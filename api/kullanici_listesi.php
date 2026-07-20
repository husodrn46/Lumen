<?php
declare(strict_types=1);

/**
 * POST /api/kullanici_listesi.php
 * Gövde: { "query"?: "ali" }
 * Yanıt: { ok, kullanicilar:[{ id, kod, ad, firma, yetki, pasif }], sayi }
 *
 * Token + M16 yetki zorunlu. Aktif ve pasif tüm sistem kullanıcılarını listeler.
 * ÖNEMLİ: LG_SLSMAN ve M_P_YETKI tabloları PREFİKSSİZDİR (sabit ad); $firma/$firmadonem KULLANILMAZ.
 * Web karşılığı: ayar/mobilyetki.php kullanıcı listesi.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(['ok' => false, 'mesaj' => 'Yalnızca POST desteklenir.'], 405);
}

$oturum   = api_oturum_gerekli($dbh);
$personel = (int) $oturum['personel'];

if (!api_yetki_var($personel, 'M16')) {
    api_json(['ok' => false, 'mesaj' => 'Kullanıcı yönetimi yetkiniz yok.'], 403);
}

$body  = api_body();
$query = trim((string) ($body['query'] ?? ''));

$params = [];
$aramaSql = '';
// sqlsrv kuralı: aynı named parametre tek sorguda iki kez kullanılamaz.
// İki LIKE için :q ve :q2 olarak iki ayrı parametre bind edilir (aynı değer).
if (mb_strlen($query) >= 2) {
    $like = '%' . mb_strtoupper($query, 'UTF-8') . '%';
    $aramaSql = ' AND (UPPER(L.CODE) LIKE :q OR UPPER(L.DEFINITION_) LIKE :q2)';
    $params[':q']  = $like;
    $params[':q2'] = $like;
}

try {
    $sql = "SELECT L.LOGICALREF AS id, L.CODE AS kod, L.DEFINITION_ AS ad, L.FIRMNR AS firma,
                   ISNULL(L.ACTIVE, 0) AS pasif, ISNULL(M.YETKI, 2) AS yetki
            FROM LG_SLSMAN L WITH(NOLOCK)
            LEFT JOIN M_P_YETKI M WITH(NOLOCK) ON L.LOGICALREF = M.PERSONEL
            WHERE L.CARDTYPE = 0{$aramaSql}
            ORDER BY L.ACTIVE ASC, L.CODE ASC";
    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('api/kullanici_listesi: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Kullanıcı listesi alınamadı.'], 500);
}

$kullanicilar = array_map(static function (array $r): array {
    return [
        'id'    => (int) $r['id'],
        'kod'   => (string) ($r['kod'] ?? ''),
        'ad'    => api_metin($r['ad'] ?? ''),
        'firma' => (int) $r['firma'],
        'yetki' => (int) $r['yetki'],
        'pasif' => ((int) $r['pasif']) === 1,
    ];
}, $rows);

api_json(['ok' => true, 'kullanicilar' => $kullanicilar, 'sayi' => count($kullanicilar)]);
