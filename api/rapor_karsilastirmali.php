<?php
declare(strict_types=1);

/**
 * GET/POST /api/rapor_karsilastirmali.php
 * Gövde: { "yil"?:2026 }
 * Yanıt: { ok, yil, onceki_yil,
 *          bu_yil:[{ay,ay_adi,net,net_metin}],
 *          onceki:[{ay,ay_adi,net,net_metin}],
 *          ozet:{ bu_yil_toplam, bu_yil_toplam_metin, onceki_toplam, onceki_toplam_metin,
 *                 degisim, degisim_yuzde } }
 *
 * Token zorunlu. Seçili yıl ile bir önceki yılın aylık satış cirosunu (ürün kodu ön ekine göre,
 * STLINE TRCODE 7/8, KDV dahil net) karşılaştırır. İki dönem tablosu (aktif + önceki)
 * UNION ALL ile birleştirilir; yıl tarih aralığı int-türevli literalle gömülür (named
 * parametre tekrarı tuzağına düşmeden). rapor_satis_aylik.php ile aynı net formülü.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma, $firmadonem, $eskifirmadonem;
$oturum = api_oturum_gerekli($dbh);
api_yetki_gerekli((int) $oturum['personel'], 'M17');

$body = api_body();
$yil  = (int) ($body['yil'] ?? ($_GET['yil'] ?? (int) date('Y')));
if ($yil < 2000 || $yil > 2100) {
    $yil = (int) date('Y');
}
$oncekiYil = $yil - 1;

$onceki    = (string) ($eskifirmadonem ?? '');
$periyotlar = ($onceki !== '' && $onceki !== $firmadonem) ? [$firmadonem, $onceki] : [$firmadonem];

$aylarTr = [1=>'Oca',2=>'Şub',3=>'Mar',4=>'Nis',5=>'May',6=>'Haz',7=>'Tem',8=>'Ağu',9=>'Eyl',10=>'Eki',11=>'Kas',12=>'Ara'];
$netSql  = 'ISNULL(SL.LINENET, (SL.PRICE * SL.AMOUNT - SL.DISTDISC)) + ISNULL(SL.VATAMNT, 0)';

/** Bir yılın 12 aylık net cirosunu (dizi: ay=>tutar) döndürür. */
$urunKosulu = urun_kodu_kosulu('ITM');

$yilAylari = function (int $y) use ($dbh, $firma, $periyotlar, $netSql, $urunKosulu): array {
    $bas = sprintf('%04d-01-01', $y);
    $bit = sprintf('%04d-01-01', $y + 1); // yarı açık üst sınır
    $parcalar = [];
    foreach ($periyotlar as $tbl) {
        $parcalar[] = "SELECT MONTH(SL.DATE_) AS AY, {$netSql} AS NET
            FROM {$tbl}STLINE SL WITH(NOLOCK)
            JOIN {$firma}ITEMS ITM WITH(NOLOCK) ON ITM.LOGICALREF = SL.STOCKREF
            WHERE {$urunKosulu} AND SL.TRCODE IN (7, 8)
              AND SL.CANCELLED = 0 AND SL.LINETYPE = 0
              AND SL.DATE_ >= '{$bas}' AND SL.DATE_ < '{$bit}'";
    }
    $union = implode("\n            UNION ALL\n", $parcalar);
    $sql = "SELECT T.AY, SUM(T.NET) AS NET FROM (\n{$union}\n        ) T GROUP BY T.AY ORDER BY T.AY";
    $aylar = array_fill(1, 12, 0.0);
    foreach ($dbh->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ay = (int) $r['AY'];
        if ($ay >= 1 && $ay <= 12) {
            $aylar[$ay] = (float) $r['NET'];
        }
    }
    return $aylar;
};

try {
    $buAylar = $yilAylari($yil);
    $onAylar = $yilAylari($oncekiYil);
} catch (Throwable $e) {
    error_log('api/rapor_karsilastirmali: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Karşılaştırmalı satış alınamadı.'], 500);
}

$bicimle = static function (array $aylar) use ($aylarTr): array {
    $out = [];
    foreach ($aylarTr as $no => $ad) {
        $net = (float) $aylar[$no];
        $out[] = ['ay' => $no, 'ay_adi' => $ad, 'net' => $net, 'net_metin' => api_money($net)];
    }
    return $out;
};

$buToplam = array_sum($buAylar);
$onToplam = array_sum($onAylar);
$degisim  = $buToplam - $onToplam;
$yuzde    = $onToplam > 0 ? round(($degisim / $onToplam) * 100, 1) : null;

api_json([
    'ok'         => true,
    'yil'        => $yil,
    'onceki_yil' => $oncekiYil,
    'bu_yil'     => $bicimle($buAylar),
    'onceki'     => $bicimle($onAylar),
    'ozet'       => [
        'bu_yil_toplam'        => $buToplam,
        'bu_yil_toplam_metin'  => api_money($buToplam),
        'onceki_toplam'        => $onToplam,
        'onceki_toplam_metin'  => api_money($onToplam),
        'degisim'              => $degisim,
        'degisim_metin'        => api_money($degisim),
        'degisim_yuzde'        => $yuzde,
    ],
]);
