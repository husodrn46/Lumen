<?php
declare(strict_types=1);

/**
 * GET/POST /api/rapor_cek_vade.php
 * Gövde: { "gun"?: 30 }
 * Yanıt: { ok, cekler:[{ vade, tutar, tutar_metin, cari_kod, cari_ad, sahip, portfoy }] }
 * Token zorunlu. Vadesi N gün içinde gelen müşteri çekleri (CSCARD DOC=1).
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma, $firmadonem;
api_oturum_gerekli($dbh);

$body = api_body();
$gun  = max(1, min(180, (int) ($body['gun'] ?? ($_GET['gun'] ?? 30))));

try {
    // CAST(:days AS INT): DATEADD nvarchar tuzağını önler.
    $stmt = $dbh->prepare("
        SELECT
            CAST(LGMAIN.DUEDATE AS DATE) AS due_date,
            LGMAIN.TRNET     AS amount,
            LGMAIN.OWING     AS owner_name,
            LGMAIN.PORTFOYNO AS portfolio_no,
            ISNULL(CL.CODE, '')        AS cari_code,
            ISNULL(CL.DEFINITION_, '') AS cari_name
        FROM {$firmadonem}CSCARD LGMAIN WITH(NOLOCK)
        LEFT JOIN (
            SELECT T.CSREF, T.CARDREF,
                   ROW_NUMBER() OVER (PARTITION BY T.CSREF ORDER BY T.LOGICALREF DESC) AS RN
            FROM {$firmadonem}CSTRANS T WITH(NOLOCK)
        ) TX ON TX.CSREF = LGMAIN.LOGICALREF AND TX.RN = 1
        LEFT JOIN {$firma}CLCARD CL WITH(NOLOCK) ON CL.LOGICALREF = TX.CARDREF
        WHERE LGMAIN.CURRSTAT IN (1) AND LGMAIN.STATUS IN (0, 1) AND LGMAIN.DOC = 1
          AND CAST(LGMAIN.DUEDATE AS DATE) <= DATEADD(DAY, CAST(:days AS INT), CAST(GETDATE() AS DATE))
        ORDER BY CAST(LGMAIN.DUEDATE AS DATE) ASC, LGMAIN.TRNET DESC
    ");
    $stmt->execute([':days' => $gun]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('api/rapor_cek_vade: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Rapor alınamadı.'], 500);
}

$toplam = 0.0;
$cekler = array_map(static function (array $r) use (&$toplam): array {
    $tutar = (float) ($r['amount'] ?? 0);
    $toplam += $tutar;
    return [
        'vade'        => api_tarih($r['due_date'] ?? null),
        'tutar'       => $tutar,
        'tutar_metin' => api_money($tutar),
        'cari_kod'    => (string) ($r['cari_code'] ?? ''),
        'cari_ad'     => api_metin($r['cari_name'] ?? ''),
        'sahip'       => api_metin($r['owner_name'] ?? ''),
        'portfoy'     => (string) ($r['portfolio_no'] ?? ''),
    ];
}, $rows);

api_json([
    'ok'          => true,
    'cekler'      => $cekler,
    'sayi'        => count($cekler),
    'toplam'      => round($toplam, 2),
    'toplam_metin' => api_money($toplam),
]);
