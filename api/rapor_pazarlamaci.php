<?php
declare(strict_types=1);

/**
 * GET/POST /api/rapor_pazarlamaci.php
 * Gövde: { "prefix"?:"TMÇN", "yil"?:2026 }
 * Yanıt: { ok, prefix, yil, ozet:{ yillik_ciro, yillik_ciro_metin, yillik_tahsilat,
 *          yillik_tahsilat_metin, tahsilat_orani, bakiye, bakiye_metin, cari_sayisi },
 *          aylik:[{ ay, ay_adi, ciro, ciro_metin, tahsilat, tahsilat_metin }] }
 *
 * Token zorunlu. Cari kodu ön ekine (pazarlamacı/grup) göre yıllık satış + tahsilat + bakiye.
 * prefix boşsa tüm aktif cariler (şirket geneli özeti). Satış: STLINE TRCODE 7/8 KDV-dahil net;
 * tahsilat: CLFLINE TRCODE(1,4,20,61,62,70) SIGN=1; bakiye: GNTOTCL TOTTYP=1 DEBIT-CREDIT.
 * (rapor/rapor_pazarlamaci_performans.php kalıbıyla uyumlu; prefix genelleştirildi.)
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma, $firmadonem, $firmadonemx;
api_oturum_gerekli($dbh);

$body   = api_body();
$prefix = trim((string) ($body['prefix'] ?? ($_GET['prefix'] ?? '')));
$yil    = (int) ($body['yil'] ?? ($_GET['yil'] ?? (int) date('Y')));
if ($yil < 2000 || $yil > 2100) {
    $yil = (int) date('Y');
}
$bas = sprintf('%04d-01-01', $yil);
$bit = sprintf('%04d-01-01', $yil + 1);

$aylarTr = [1=>'Oca',2=>'Şub',3=>'Mar',4=>'Nis',5=>'May',6=>'Haz',7=>'Tem',8=>'Ağu',9=>'Eyl',10=>'Eki',11=>'Kas',12=>'Ara'];

// prefix filtresi (varsa) — her sorgu kendi prepare/execute'unda :prefix bir kez kullanır.
$prefixSql = $prefix !== '' ? ' AND CL.CODE LIKE :prefix' : '';
$prefixVal = $prefix !== '' ? $prefix . '%' : null;
$pp = static function (array $base) use ($prefixVal): array {
    if ($prefixVal !== null) {
        $base[':prefix'] = $prefixVal;
    }
    return $base;
};

$netSql = 'ISNULL(SL.LINENET, (SL.PRICE * SL.AMOUNT - SL.DISTDISC)) + ISNULL(SL.VATAMNT, 0)';

try {
    // 1) Aylık ciro (STLINE)
    $satisSql = "SELECT MONTH(SL.DATE_) AS AY, SUM({$netSql}) AS NET
        FROM {$firmadonem}STLINE SL WITH(NOLOCK)
        JOIN {$firma}CLCARD CL WITH(NOLOCK) ON CL.LOGICALREF = SL.CLIENTREF
        WHERE SL.TRCODE IN (7, 8) AND SL.CANCELLED = 0 AND SL.LINETYPE = 0
          AND SL.DATE_ >= '{$bas}' AND SL.DATE_ < '{$bit}'{$prefixSql}
        GROUP BY MONTH(SL.DATE_)";
    $st = $dbh->prepare($satisSql);
    $st->execute($pp([]));
    $ciroAy = array_fill(1, 12, 0.0);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ciroAy[(int) $r['AY']] = (float) $r['NET'];
    }

    // 2) Aylık tahsilat (CLFLINE)
    $tahSql = "SELECT MONTH(CF.DATE_) AS AY, SUM(ISNULL(CF.AMOUNT, 0)) AS TUTAR
        FROM {$firmadonem}CLFLINE CF WITH(NOLOCK)
        JOIN {$firma}CLCARD CL WITH(NOLOCK) ON CL.LOGICALREF = CF.CLIENTREF
        WHERE CF.TRCODE IN (1, 4, 20, 61, 62, 70) AND CF.SIGN = 1 AND CF.CANCELLED = 0
          AND CF.DATE_ >= '{$bas}' AND CF.DATE_ < '{$bit}'{$prefixSql}
        GROUP BY MONTH(CF.DATE_)";
    $st2 = $dbh->prepare($tahSql);
    $st2->execute($pp([]));
    $tahAy = array_fill(1, 12, 0.0);
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tahAy[(int) $r['AY']] = (float) $r['TUTAR'];
    }

    // 3) Bakiye + cari sayısı (GNTOTCL TOTTYP=1)
    $bakSql = "SELECT SUM(ISNULL(G.DEBIT,0) - ISNULL(G.CREDIT,0)) AS BAKIYE, COUNT(*) AS SAYI
        FROM {$firma}CLCARD CL WITH(NOLOCK)
        LEFT JOIN {$firmadonemx}GNTOTCL G WITH(NOLOCK) ON G.CARDREF = CL.LOGICALREF AND G.TOTTYP = 1
        WHERE CL.ACTIVE = 0{$prefixSql}";
    $st3 = $dbh->prepare($bakSql);
    $st3->execute($pp([]));
    $bak = $st3->fetch(PDO::FETCH_ASSOC) ?: ['BAKIYE' => 0, 'SAYI' => 0];
} catch (Throwable $e) {
    error_log('api/rapor_pazarlamaci: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Pazarlamacı performansı alınamadı.'], 500);
}

$aylik = [];
$yillikCiro = 0.0;
$yillikTah  = 0.0;
foreach ($aylarTr as $no => $ad) {
    $c = (float) $ciroAy[$no];
    $t = (float) $tahAy[$no];
    $yillikCiro += $c;
    $yillikTah  += $t;
    $aylik[] = [
        'ay'             => $no,
        'ay_adi'         => $ad,
        'ciro'           => $c,
        'ciro_metin'     => api_money($c),
        'tahsilat'       => $t,
        'tahsilat_metin' => api_money($t),
    ];
}
$oran = $yillikCiro > 0 ? round(($yillikTah / $yillikCiro) * 100, 1) : null;

api_json([
    'ok'     => true,
    'prefix' => $prefix,
    'yil'    => $yil,
    'ozet'   => [
        'yillik_ciro'           => $yillikCiro,
        'yillik_ciro_metin'     => api_money($yillikCiro),
        'yillik_tahsilat'       => $yillikTah,
        'yillik_tahsilat_metin' => api_money($yillikTah),
        'tahsilat_orani'        => $oran,
        'bakiye'                => (float) ($bak['BAKIYE'] ?? 0),
        'bakiye_metin'          => api_money((float) ($bak['BAKIYE'] ?? 0)),
        'cari_sayisi'           => (int) ($bak['SAYI'] ?? 0),
    ],
    'aylik'  => $aylik,
]);
