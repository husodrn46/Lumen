<?php
declare(strict_types=1);

/**
 * GET/POST /api/rapor_nakit_akis.php
 * Gövde: { "yil"?:2026 }
 * Yanıt: { ok, yil, aylar:[{ ay, ay_adi, tahsilat, odeme, net, kumulatif, *_metin }],
 *          ozet:{ toplam_tahsilat, toplam_odeme, net_akis, *_metin } }
 *
 * Token zorunlu. CLFLINE üzerinden aylık tahsilat/ödeme + kümülatif net akış.
 * Tahsilat: TRCODE IN (1,4,20,61,62,70) & SIGN=1; Ödeme: TRCODE IN (2,3,21,63,64,72) & SIGN=0.
 * İki dönem tablosu UNION ALL; yıl tarih aralığı int-türevli literalle gömülür (named param tekrarı yok).
 * Kümülatif PHP'de hesaplanır (rapor/rapor_nakit_akis.php ile aynı mantık).
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firmadonem, $eskifirmadonem;
$oturum = api_oturum_gerekli($dbh);
api_yetki_gerekli((int) $oturum['personel'], 'M17');

$body = api_body();
$yil  = (int) ($body['yil'] ?? ($_GET['yil'] ?? (int) date('Y')));
if ($yil < 2000 || $yil > 2100) {
    $yil = (int) date('Y');
}

$onceki     = (string) ($eskifirmadonem ?? '');
$periyotlar = ($onceki !== '' && $onceki !== $firmadonem) ? [$firmadonem, $onceki] : [$firmadonem];

$aylarTr = [1=>'Oca',2=>'Şub',3=>'Mar',4=>'Nis',5=>'May',6=>'Haz',7=>'Tem',8=>'Ağu',9=>'Eyl',10=>'Eki',11=>'Kas',12=>'Ara'];

$bas = sprintf('%04d-01-01', $yil);
$bit = sprintf('%04d-01-01', $yil + 1);

/** Verilen yön (tahsilat/ödeme) için aylık toplamları döndürür. */
$aylikYon = function (string $trcodeIn, int $sign) use ($dbh, $periyotlar, $bas, $bit): array {
    $parcalar = [];
    foreach ($periyotlar as $tbl) {
        $parcalar[] = "SELECT MONTH(DATE_) AS AY, ISNULL(AMOUNT,0) AS TUTAR
            FROM {$tbl}CLFLINE WITH(NOLOCK)
            WHERE TRCODE IN ({$trcodeIn}) AND SIGN = {$sign} AND CANCELLED = 0
              AND DATE_ >= '{$bas}' AND DATE_ < '{$bit}'";
    }
    $union = implode("\n            UNION ALL\n", $parcalar);
    $sql = "SELECT T.AY, SUM(T.TUTAR) AS TUTAR FROM (\n{$union}\n        ) T GROUP BY T.AY";
    $aylar = array_fill(1, 12, 0.0);
    foreach ($dbh->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ay = (int) $r['AY'];
        if ($ay >= 1 && $ay <= 12) {
            $aylar[$ay] = (float) $r['TUTAR'];
        }
    }
    return $aylar;
};

try {
    $tahsilat = $aylikYon('1, 4, 20, 61, 62, 70', 1);
    $odeme    = $aylikYon('2, 3, 21, 63, 64, 72', 0);
} catch (Throwable $e) {
    error_log('api/rapor_nakit_akis: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Nakit akış alınamadı.'], 500);
}

$aylar = [];
$kumulatif = 0.0;
$topTah = 0.0;
$topOde = 0.0;
foreach ($aylarTr as $no => $ad) {
    $t = (float) $tahsilat[$no];
    $o = (float) $odeme[$no];
    $net = $t - $o;
    $kumulatif += $net;
    $topTah += $t;
    $topOde += $o;
    $aylar[] = [
        'ay'             => $no,
        'ay_adi'         => $ad,
        'tahsilat'       => $t,
        'tahsilat_metin' => api_money($t),
        'odeme'          => $o,
        'odeme_metin'    => api_money($o),
        'net'            => $net,
        'net_metin'      => api_money($net),
        'kumulatif'      => $kumulatif,
        'kumulatif_metin'=> api_money($kumulatif),
    ];
}

api_json([
    'ok'    => true,
    'yil'   => $yil,
    'aylar' => $aylar,
    'ozet'  => [
        'toplam_tahsilat'       => $topTah,
        'toplam_tahsilat_metin' => api_money($topTah),
        'toplam_odeme'          => $topOde,
        'toplam_odeme_metin'    => api_money($topOde),
        'net_akis'              => $topTah - $topOde,
        'net_akis_metin'        => api_money($topTah - $topOde),
    ],
]);
