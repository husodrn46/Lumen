<?php
declare(strict_types=1);

/**
 * GET/POST /api/fatura_listesi.php
 * Gövde: { "cari_id"?:123, "query"?:"AKL", "donem"?:"aktif"|"onceki"|"tumu", "limit"?:100 }
 * Yanıt: { ok, faturalar:[{ id, fisno, tarih, tur, net, net_metin, brut, brut_metin,
 *          kdv, kdv_metin, cari_kod, cari_ad }], sayi, toplam, toplam_metin, donem }
 *
 * Token + CR1 (cari finans) yetkisi zorunlu. Kesilen SATIŞ faturalarını listeler:
 * INVOICE.TRCODE IN (6,7,8) (perakende/toptan/hizmet) ve CANCELLED=0. Opsiyonel cari
 * ve fiş/cari araması. donem=tumu iki dönemi (aktif + önceki) birleştirir.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma, $firmadonem, $eskifirmadonem;
$oturum   = api_oturum_gerekli($dbh);
$personel = (int) $oturum['personel'];
if (!api_yetki_var($personel, 'CR1')) {
    api_json(['ok' => false, 'mesaj' => 'Fatura görüntüleme yetkiniz yok.'], 403);
}

$body   = api_body();
$cariId = (int) ($body['cari_id'] ?? ($_GET['cari_id'] ?? 0));
$query  = trim((string) ($body['query'] ?? ($_GET['q'] ?? '')));
$limit  = max(1, min(500, (int) ($body['limit'] ?? ($_GET['limit'] ?? 100))));
$donem  = strtolower(trim((string) ($body['donem'] ?? ($_GET['donem'] ?? 'aktif'))));

// Hangi dönem tablo(ları)?
$onceki = (string) ($eskifirmadonem ?? '');
$donemler = match ($donem) {
    'onceki' => $onceki !== '' ? [$onceki] : [$firmadonem],
    'tumu'   => $onceki !== '' && $onceki !== $firmadonem ? [$firmadonem, $onceki] : [$firmadonem],
    default  => [$firmadonem],
};

$cidSafe = $cariId > 0 ? (int) $cariId : 0; // int — UNION'da güvenle gömülür

// Her dönem için SELECT + (araması varsa) o döneme özel parametreler üret.
$parcalar = [];
$params   = [];
foreach ($donemler as $i => $tbl) {
    $w = ['ISNULL(I.CANCELLED, 0) = 0', 'I.TRCODE IN (6, 7, 8)'];
    if ($cidSafe > 0) {
        $w[] = "I.CLIENTREF = {$cidSafe}";
    }
    if ($query !== '') {
        $norm = api_arama_norm($query);
        $fp = ":qf{$i}";
        $cp = ":qc{$i}";
        $kp = ":qk{$i}";
        $w[] = '(' . api_sql_norm('I.FICHENO') . " LIKE {$fp} OR "
            . api_sql_norm('C.DEFINITION_') . " LIKE {$cp} OR "
            . api_sql_norm('C.CODE') . " LIKE {$kp})";
        $params[$fp] = '%' . $norm . '%';
        $params[$cp] = '%' . $norm . '%';
        $params[$kp] = '%' . $norm . '%';
    }
    $whereSql = implode(' AND ', $w);
    $parcalar[] = "
        SELECT I.LOGICALREF AS id, I.FICHENO AS fisno, I.DATE_ AS tarih, I.TRCODE AS trcode,
               ISNULL(I.NETTOTAL, 0) AS net, ISNULL(I.GROSSTOTAL, 0) AS brut, ISNULL(I.TOTALVAT, 0) AS kdv,
               C.CODE AS cari_kod, C.DEFINITION_ AS cari_ad
        FROM {$tbl}INVOICE I WITH(NOLOCK)
        LEFT JOIN {$firma}CLCARD C WITH(NOLOCK) ON C.LOGICALREF = I.CLIENTREF
        WHERE {$whereSql}";
}
$birlesik = implode("\n        UNION ALL\n", $parcalar);

try {
    $sql = "SELECT TOP {$limit} * FROM (\n{$birlesik}\n    ) AS T ORDER BY T.tarih DESC, T.id DESC";
    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('api/fatura_listesi: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Fatura listesi alınamadı.'], 500);
}

$turler = [
    1 => 'Alış Faturası', 3 => 'Toptan İade', 6 => 'Perakende Satış',
    7 => 'Toptan Satış', 8 => 'Hizmet Faturası', 9 => 'Satış İade',
];

$toplam = 0.0;
$faturalar = array_map(static function (array $r) use (&$toplam, $turler): array {
    $net = (float) $r['net'];
    $toplam += $net;
    $trc = (int) $r['trcode'];
    return [
        'id'         => (int) $r['id'],
        'fisno'      => (string) $r['fisno'],
        'tarih'      => api_tarih($r['tarih']),
        'tur'        => $turler[$trc] ?? ('Fatura (' . $trc . ')'),
        'net'        => $net,
        'net_metin'  => api_money($net),
        'brut'       => (float) $r['brut'],
        'brut_metin' => api_money((float) $r['brut']),
        'kdv'        => (float) $r['kdv'],
        'kdv_metin'  => api_money((float) $r['kdv']),
        'cari_kod'   => (string) ($r['cari_kod'] ?? ''),
        'cari_ad'    => api_metin($r['cari_ad'] ?? ''),
    ];
}, $rows);

api_json([
    'ok'           => true,
    'faturalar'    => $faturalar,
    'sayi'         => count($faturalar),
    'toplam'       => $toplam,
    'toplam_metin' => api_money($toplam),
    'donem'        => $donem,
]);
