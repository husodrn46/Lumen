<?php
declare(strict_types=1);

/**
 * GET/POST /api/cek_listesi.php
 * Gövde: { "tur"?:"hepsi"|"cek"|"senet" }
 * Yanıt: { ok, kiymetler:[{ tur, vade, tutar, tutar_metin, cari_kod, cari_ad, sahip, portfoy,
 *          gun_kala, durum }], sayi, ozet:{ toplam, toplam_metin, adet, vadesi_gecen,
 *          vadesi_gecen_metin, bu_ay, bu_ay_metin } }
 *
 * Token zorunlu. Portföydeki (CURRSTAT=1, STATUS 0/1) müşteri çek/senetleri (CSCARD DOC 1=çek,
 * 2=senet). Vade + gün kala + durum (vadesi geçmiş / bu ay / ileri). rapor_cek_vade.php ile
 * aynı CSCARD/CSTRANS kalıbı; pencere yerine tüm portföy + yaşlandırma özeti.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma, $firmadonem;
$oturum   = api_oturum_gerekli($dbh);
$personel = (int) $oturum['personel'];

// Çek portföyü: web'deki çek ekranlarıyla aynı kapı (M30).
if (!api_yetki_var($personel, 'M30')) {
    api_json(['ok' => false, 'mesaj' => 'Çek işlemleri yetkiniz yok.'], 403);
}

$body = api_body();
$tur  = strtolower(trim((string) ($body['tur'] ?? ($_GET['tur'] ?? 'hepsi'))));
$docFiltre = match ($tur) {
    'cek'   => 'LGMAIN.DOC = 1',
    'senet' => 'LGMAIN.DOC = 2',
    default => 'LGMAIN.DOC IN (1, 2)',
};

try {
    $stmt = $dbh->prepare("
        SELECT
            LGMAIN.DOC AS doc,
            CAST(LGMAIN.DUEDATE AS DATE) AS due_date,
            LGMAIN.TRNET     AS amount,
            LGMAIN.OWING     AS owner_name,
            LGMAIN.PORTFOYNO AS portfolio_no,
            DATEDIFF(DAY, CAST(GETDATE() AS DATE), CAST(LGMAIN.DUEDATE AS DATE)) AS gun_kala,
            ISNULL(CL.CODE, '')        AS cari_code,
            ISNULL(CL.DEFINITION_, '') AS cari_name
        FROM {$firmadonem}CSCARD LGMAIN WITH(NOLOCK)
        LEFT JOIN (
            SELECT T.CSREF, T.CARDREF,
                   ROW_NUMBER() OVER (PARTITION BY T.CSREF ORDER BY T.LOGICALREF DESC) AS RN
            FROM {$firmadonem}CSTRANS T WITH(NOLOCK)
        ) TX ON TX.CSREF = LGMAIN.LOGICALREF AND TX.RN = 1
        LEFT JOIN {$firma}CLCARD CL WITH(NOLOCK) ON CL.LOGICALREF = TX.CARDREF
        WHERE LGMAIN.CURRSTAT IN (1) AND LGMAIN.STATUS IN (0, 1) AND {$docFiltre}
        ORDER BY CAST(LGMAIN.DUEDATE AS DATE) ASC, LGMAIN.TRNET DESC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('api/cek_listesi: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Çek/senet listesi alınamadı.'], 500);
}

$toplam = 0.0;
$vadesiGecen = 0.0;
$buAy = 0.0;
$kiymetler = array_map(static function (array $r) use (&$toplam, &$vadesiGecen, &$buAy): array {
    $tutar = (float) ($r['amount'] ?? 0);
    $gun   = (int) ($r['gun_kala'] ?? 0);
    $toplam += $tutar;
    if ($gun < 0) {
        $vadesiGecen += $tutar;
        $durum = 'Vadesi geçmiş';
    } elseif ($gun <= 30) {
        $buAy += $tutar;
        $durum = 'Bu ay';
    } else {
        $durum = 'İleri vade';
    }
    return [
        'tur'         => ((int) $r['doc'] === 2) ? 'Senet' : 'Çek',
        'vade'        => api_tarih($r['due_date'] ?? null),
        'tutar'       => $tutar,
        'tutar_metin' => api_money($tutar),
        'cari_kod'    => (string) ($r['cari_code'] ?? ''),
        'cari_ad'     => api_metin($r['cari_name'] ?? ''),
        'sahip'       => api_metin($r['owner_name'] ?? ''),
        'portfoy'     => (string) ($r['portfolio_no'] ?? ''),
        'gun_kala'    => $gun,
        'durum'       => $durum,
    ];
}, $rows);

api_json([
    'ok'        => true,
    'kiymetler' => $kiymetler,
    'sayi'      => count($kiymetler),
    'ozet'      => [
        'toplam'             => round($toplam, 2),
        'toplam_metin'       => api_money($toplam),
        'adet'               => count($kiymetler),
        'vadesi_gecen'       => round($vadesiGecen, 2),
        'vadesi_gecen_metin' => api_money($vadesiGecen),
        'bu_ay'              => round($buAy, 2),
        'bu_ay_metin'        => api_money($buAy),
    ],
]);
