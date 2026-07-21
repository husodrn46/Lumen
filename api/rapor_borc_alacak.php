<?php
declare(strict_types=1);

/**
 * GET/POST /api/rapor_borc_alacak.php
 * Gövde: { "query"?:"akal", "min"?:0, "limit"?:200 }
 * Yanıt: { ok, cariler:[{ id, kod, unvan, sehir, borc, borc_metin, alacak, alacak_metin,
 *          bakiye, bakiye_metin, son_alim }], sayi, ozet:{ toplam_borc, toplam_borc_metin,
 *          borclu_sayi, ortalama_metin } }
 *
 * Token zorunlu. Borçlu carileri (GNTOTCL DEBIT-CREDIT, TOTTYP=1 > 0) bakiye sıralı listeler.
 * Son ürün alımı = en son satış faturası tarihi (INVOICE TRCODE 7/8). Opsiyonel arama + min tutar.
 * (rapor/rapor_cari_borc_alacak.php bakiye + son alım mantığıyla uyumlu.)
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma, $firmadonem, $firmadonemx;
$oturum   = api_oturum_gerekli($dbh);
$personel = (int) $oturum['personel'];

// Web ikizi (../rapor/rapor_cari_borc_alacak.php) M17 + CR1 istiyor.
if (!api_yetki_var($personel, 'M17')) {
    api_json(['ok' => false, 'mesaj' => 'Bu rapora erişim yetkiniz yok.'], 403);
}
if (!api_yetki_var($personel, 'CR1')) {
    api_json(['ok' => false, 'mesaj' => 'Cari bakiye görme yetkiniz yok.'], 403);
}

$body  = api_body();
$query = trim((string) ($body['query'] ?? ($_GET['q'] ?? '')));
$min   = (isset($body['min']) && $body['min'] !== '') ? (float) $body['min'] : 0.0;
$limit = max(1, min(2000, (int) ($body['limit'] ?? ($_GET['limit'] ?? 200))));

$bakiyeSql = '(ISNULL(G.DEBIT, 0) - ISNULL(G.CREDIT, 0))';

$wheres = ['C.ACTIVE = 0', "{$bakiyeSql} > 0"];
$params = [];
if ($min > 0) {
    $wheres[] = "{$bakiyeSql} >= :min_t";
    $params[':min_t'] = $min;
}
if ($query !== '') {
    $norm = api_arama_norm($query);
    $wheres[] = '(' . api_sql_norm('C.DEFINITION_') . ' LIKE :q_d OR '
        . api_sql_norm('C.CODE') . ' LIKE :q_k OR '
        . api_sql_norm('C.CITY') . ' LIKE :q_s)';
    $params[':q_d'] = '%' . $norm . '%';
    $params[':q_k'] = '%' . $norm . '%';
    $params[':q_s'] = '%' . $norm . '%';
}
$whereSql = implode(' AND ', $wheres);

try {
    $sql = "
        SELECT TOP {$limit}
            C.LOGICALREF AS id, C.CODE AS kod, C.DEFINITION_ AS unvan, C.CITY AS sehir,
            ISNULL(G.DEBIT, 0)  AS borc,
            ISNULL(G.CREDIT, 0) AS alacak,
            {$bakiyeSql}        AS bakiye,
            LS.SON_ALIM AS son_alim
        FROM {$firma}CLCARD C WITH(NOLOCK)
        LEFT JOIN {$firmadonemx}GNTOTCL G WITH(NOLOCK)
            ON G.CARDREF = C.LOGICALREF AND G.TOTTYP = 1
        OUTER APPLY (
            SELECT TOP 1 I.DATE_ AS SON_ALIM
            FROM {$firmadonem}INVOICE I WITH(NOLOCK)
            WHERE I.CLIENTREF = C.LOGICALREF AND I.CANCELLED = 0 AND I.TRCODE IN (7, 8)
            ORDER BY I.DATE_ DESC, I.LOGICALREF DESC
        ) LS
        WHERE {$whereSql}
        ORDER BY {$bakiyeSql} DESC";
    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Özet (tüm borçlular — limitten bağımsız)
    $ozetSql = "
        SELECT SUM(X.B) AS toplam_borc, COUNT(*) AS borclu_sayi FROM (
            SELECT {$bakiyeSql} AS B
            FROM {$firma}CLCARD C WITH(NOLOCK)
            LEFT JOIN {$firmadonemx}GNTOTCL G WITH(NOLOCK)
                ON G.CARDREF = C.LOGICALREF AND G.TOTTYP = 1
            WHERE {$whereSql}
        ) X";
    $ozetStmt = $dbh->prepare($ozetSql);
    $ozetStmt->execute($params);
    $ozet = $ozetStmt->fetch(PDO::FETCH_ASSOC) ?: ['toplam_borc' => 0, 'borclu_sayi' => 0];
} catch (Throwable $e) {
    error_log('api/rapor_borc_alacak: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Borç/alacak raporu alınamadı.'], 500);
}

$cariler = array_map(static function (array $r): array {
    return [
        'id'           => (int) $r['id'],
        'kod'          => (string) ($r['kod'] ?? ''),
        'unvan'        => api_metin($r['unvan'] ?? ''),
        'sehir'        => api_metin($r['sehir'] ?? ''),
        'borc'         => (float) $r['borc'],
        'borc_metin'   => api_money((float) $r['borc']),
        'alacak'       => (float) $r['alacak'],
        'alacak_metin' => api_money((float) $r['alacak']),
        'bakiye'       => (float) $r['bakiye'],
        'bakiye_metin' => api_money((float) $r['bakiye']),
        'son_alim'     => api_tarih($r['son_alim'] ?? null),
    ];
}, $rows);

$toplamBorc = (float) ($ozet['toplam_borc'] ?? 0);
$borcluSayi = (int) ($ozet['borclu_sayi'] ?? 0);
$ortalama   = $borcluSayi > 0 ? $toplamBorc / $borcluSayi : 0.0;

api_json([
    'ok'      => true,
    'cariler' => $cariler,
    'sayi'    => count($cariler),
    'ozet'    => [
        'toplam_borc'       => $toplamBorc,
        'toplam_borc_metin' => api_money($toplamBorc),
        'borclu_sayi'       => $borcluSayi,
        'ortalama_metin'    => api_money($ortalama),
    ],
]);
