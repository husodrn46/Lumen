<?php
declare(strict_types=1);

/**
 * POST /api/rapor_satis_aylik.php
 * Gövde: { "year"?: 2026 }   (verilmezse içinde bulunulan yıl)
 * Yanıt: { ok, year, aylar:[{ay, ay_adi, islem, adet, net, net_metin}], ozet:{...} }
 *
 * Aylık satış cirosu (STLINE TRCODE 7,8; ürün kodu ön ekine göre; net = LINENET + KDV).
 * rapor_satis_aylik.php (web) mantığıyla birebir. Token zorunlu, salt-okuma.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma, $firmadonem;
$oturum = api_oturum_gerekli($dbh);
api_yetki_gerekli((int) $oturum['personel'], 'M17');

$body = api_body();
$year = (int) ($body['year'] ?? ($_GET['year'] ?? 0));
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

$aylar = [];
for ($i = 1; $i <= 12; $i++) {
    $aylar[$i] = ['islem' => 0, 'adet' => 0.0, 'net' => 0.0];
}

$urunKosulu = urun_kodu_kosulu('ITM');

try {
    $sql = "
        SELECT
            MONTH(SL.DATE_) AS M,
            COUNT(DISTINCT SL.INVOICEREF) AS C,
            SUM(SL.AMOUNT) AS QTY,
            SUM(ISNULL(SL.LINENET, (SL.PRICE * SL.AMOUNT - SL.DISTDISC)) + ISNULL(SL.VATAMNT, 0)) AS NET
        FROM {$firmadonem}STLINE SL WITH(NOLOCK)
        JOIN {$firma}ITEMS ITM WITH(NOLOCK) ON SL.STOCKREF = ITM.LOGICALREF
        WHERE {$urunKosulu}
          AND SL.TRCODE IN (7, 8)
          AND SL.DATE_ >= :baslangic
          AND SL.DATE_ <  :bitis
          AND SL.CANCELLED = 0
          AND SL.LINETYPE = 0
        GROUP BY MONTH(SL.DATE_)
    ";
    $stmt = $dbh->prepare($sql);
    $stmt->execute([
        ':baslangic' => sprintf('%04d-01-01', $year),
        ':bitis'     => sprintf('%04d-01-01', $year + 1),
    ]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m = (int) $r['M'];
        if ($m >= 1 && $m <= 12) {
            $aylar[$m] = [
                'islem' => (int) $r['C'],
                'adet'  => (float) $r['QTY'],
                'net'   => (float) $r['NET'],
            ];
        }
    }
} catch (Throwable $e) {
    error_log('api/rapor_satis_aylik: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Rapor alınamadı.'], 500);
}

$adlar = [1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayıs', 6 => 'Haziran',
          7 => 'Temmuz', 8 => 'Ağustos', 9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'];

$cikti = [];
$yillikToplam = 0.0;
$satisOlanAy = 0;
$enYuksek = ['ay' => 0, 'net' => 0.0];
$enHareketli = ['ay' => 0, 'islem' => 0];
$toplamIslem = 0;

for ($m = 1; $m <= 12; $m++) {
    $d = $aylar[$m];
    $net = round((float) $d['net'], 2);
    $yillikToplam += $net;
    $toplamIslem += (int) $d['islem'];
    if ($net > 0) {
        $satisOlanAy++;
        if ($net > $enYuksek['net']) {
            $enYuksek = ['ay' => $m, 'net' => $net];
        }
    }
    if ((int) $d['islem'] > $enHareketli['islem']) {
        $enHareketli = ['ay' => $m, 'islem' => (int) $d['islem']];
    }
    $cikti[] = [
        'ay'        => $m,
        'ay_adi'    => $adlar[$m],
        'islem'     => (int) $d['islem'],
        'adet'      => round((float) $d['adet'], 2),
        'net'       => $net,
        'net_metin' => api_money($net),
    ];
}

$aylikOrtalama = $satisOlanAy > 0 ? round($yillikToplam / $satisOlanAy, 2) : 0.0;

api_json([
    'ok'   => true,
    'year' => $year,
    'aylar' => $cikti,
    'ozet' => [
        'yillik_toplam'        => round($yillikToplam, 2),
        'yillik_toplam_metin'  => api_money($yillikToplam),
        'aylik_ortalama'       => $aylikOrtalama,
        'aylik_ortalama_metin' => api_money($aylikOrtalama),
        'toplam_islem'         => $toplamIslem,
        'satis_olan_ay'        => $satisOlanAy,
        'en_yuksek_ay'         => $enYuksek['ay'] > 0 ? $adlar[$enYuksek['ay']] : '-',
        'en_yuksek_metin'      => api_money($enYuksek['net']),
        'en_hareketli_ay'      => $enHareketli['ay'] > 0 ? $adlar[$enHareketli['ay']] : '-',
        'en_hareketli_islem'   => $enHareketli['islem'],
    ],
]);
