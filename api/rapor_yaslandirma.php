<?php
declare(strict_types=1);

/**
 * GET/POST /api/rapor_yaslandirma.php
 * Gövde: { "limit"?: 15 }
 * Yanıt: { ok, cariler:[{ kod, unvan, sehir, d0_30, d31_60, d61_90, d90p, toplam, toplam_metin }] }
 * Token zorunlu. Cari yaşlandırma (FIFO açık tutar, yaş kovaları). rapor_cari_yaslandirma.php kalıbı.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma, $firmadonem;
api_oturum_gerekli($dbh);

$body  = api_body();
$limit = max(1, min(50, (int) ($body['limit'] ?? ($_GET['limit'] ?? 15))));

try {
    $sql = "
WITH PARAMS AS (
    SELECT CAST(:asof AS DATE) AS ASOF_DATE
),
CARILER AS (
    SELECT C.LOGICALREF AS CARIID, C.CODE AS KODU, C.DEFINITION_ AS UNVANI, C.CITY AS SEHIR
    FROM {$firma}CLCARD C WITH(NOLOCK)
    WHERE C.ACTIVE = 0 AND C.CARDTYPE IN (3, 10)
),
NET AS (
    SELECT L.CLIENTREF AS CARIID,
        SUM(CASE WHEN L.SIGN = 1 THEN CAST(L.AMOUNT AS DECIMAL(18,2)) ELSE CAST(0 AS DECIMAL(18,2)) END) AS CREDIT_TOTAL,
        SUM(CASE WHEN L.SIGN = 0 THEN CAST(L.AMOUNT AS DECIMAL(18,2)) ELSE -CAST(L.AMOUNT AS DECIMAL(18,2)) END) AS NET_BAKIYE
    FROM {$firmadonem}CLFLINE L WITH(NOLOCK)
    INNER JOIN CARILER C ON C.CARIID = L.CLIENTREF
    CROSS JOIN PARAMS P
    WHERE L.CANCELLED = 0 AND L.DATE_ < DATEADD(DAY, 1, P.ASOF_DATE)
    GROUP BY L.CLIENTREF
),
BORCLU AS (
    SELECT N.CARIID, N.CREDIT_TOTAL, N.NET_BAKIYE FROM NET N WHERE N.NET_BAKIYE > 0
),
DEBIT_LINES AS (
    SELECT L.CLIENTREF AS CARIID, CAST(L.DATE_ AS DATE) AS ISLEM_TARIHI,
        CAST(L.AMOUNT AS DECIMAL(18,2)) AS TUTAR,
        SUM(CAST(L.AMOUNT AS DECIMAL(18,2))) OVER (PARTITION BY L.CLIENTREF ORDER BY L.DATE_ ASC, L.LOGICALREF ASC) AS CUM_DEBIT
    FROM {$firmadonem}CLFLINE L WITH(NOLOCK)
    INNER JOIN BORCLU B ON B.CARIID = L.CLIENTREF
    CROSS JOIN PARAMS P
    WHERE L.CANCELLED = 0 AND L.SIGN = 0 AND L.AMOUNT > 0 AND L.DATE_ < DATEADD(DAY, 1, P.ASOF_DATE)
),
OPEN_LINES AS (
    SELECT D.CARIID, D.ISLEM_TARIHI,
        (D.TUTAR - (
            CASE
                WHEN (B.CREDIT_TOTAL - (D.CUM_DEBIT - D.TUTAR)) <= 0 THEN CAST(0 AS DECIMAL(18,2))
                WHEN (B.CREDIT_TOTAL - (D.CUM_DEBIT - D.TUTAR)) >= D.TUTAR THEN D.TUTAR
                ELSE (B.CREDIT_TOTAL - (D.CUM_DEBIT - D.TUTAR))
            END
        )) AS ACIK_TUTAR
    FROM DEBIT_LINES D
    INNER JOIN BORCLU B ON B.CARIID = D.CARIID
),
AGE AS (
    SELECT O.CARIID, O.ACIK_TUTAR, DATEDIFF(DAY, O.ISLEM_TARIHI, P.ASOF_DATE) AS AGE_DAYS
    FROM OPEN_LINES O CROSS JOIN PARAMS P WHERE O.ACIK_TUTAR > 0
)
SELECT TOP {$limit}
    C.KODU, C.UNVANI, C.SEHIR,
    SUM(CASE WHEN A.AGE_DAYS BETWEEN 0 AND 30 THEN A.ACIK_TUTAR ELSE 0 END) AS D0_30,
    SUM(CASE WHEN A.AGE_DAYS BETWEEN 31 AND 60 THEN A.ACIK_TUTAR ELSE 0 END) AS D31_60,
    SUM(CASE WHEN A.AGE_DAYS BETWEEN 61 AND 90 THEN A.ACIK_TUTAR ELSE 0 END) AS D61_90,
    SUM(CASE WHEN A.AGE_DAYS >= 91 THEN A.ACIK_TUTAR ELSE 0 END) AS D90P,
    (
        SUM(CASE WHEN A.AGE_DAYS >= 91 THEN A.ACIK_TUTAR ELSE 0 END) * 1.00
        + SUM(CASE WHEN A.AGE_DAYS BETWEEN 61 AND 90 THEN A.ACIK_TUTAR ELSE 0 END) * 0.65
        + SUM(CASE WHEN A.AGE_DAYS BETWEEN 31 AND 60 THEN A.ACIK_TUTAR ELSE 0 END) * 0.35
    ) AS PRIORITY_SCORE,
    SUM(A.ACIK_TUTAR) AS TOTAL
FROM AGE A
INNER JOIN CARILER C ON C.CARIID = A.CARIID
GROUP BY C.KODU, C.UNVANI, C.SEHIR
ORDER BY PRIORITY_SCORE DESC, TOTAL DESC
";
    $stmt = $dbh->prepare($sql);
    $stmt->execute([':asof' => date('Y-m-d')]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('api/rapor_yaslandirma: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Rapor alınamadı.'], 500);
}

$cariler = array_map(static function (array $r): array {
    $toplam = (float) ($r['TOTAL'] ?? 0);
    return [
        'kod'         => (string) ($r['KODU'] ?? ''),
        'unvan'       => api_metin($r['UNVANI'] ?? ''),
        'sehir'       => api_metin($r['SEHIR'] ?? ''),
        'd0_30'       => round((float) ($r['D0_30'] ?? 0), 2),
        'd31_60'      => round((float) ($r['D31_60'] ?? 0), 2),
        'd61_90'      => round((float) ($r['D61_90'] ?? 0), 2),
        'd90p'        => round((float) ($r['D90P'] ?? 0), 2),
        'toplam'      => round($toplam, 2),
        'toplam_metin' => api_money($toplam),
    ];
}, $rows);

api_json(['ok' => true, 'cariler' => $cariler, 'sayi' => count($cariler)]);
