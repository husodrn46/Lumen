<?php
declare(strict_types=1);

/**
 * POST /api/cari_hareket_detay.php
 * Gövde: { "cari_id":123, "belge_ref":456, "donem"?:"aktif"|"onceki" }
 * Yanıt: { ok, baslik:{ fisno, tarih, net, net_metin }, satirlar:[{ kod, ad, miktar,
 *          miktar_metin, fiyat, fiyat_metin, toplam, toplam_metin }], sayi }
 *
 * Token + CR1 yetkisi. Bir cari hareketinin bağlı olduğu faturanın (INVOICE.LOGICALREF =
 * belge_ref) ürün satırlarını (STLINE LINETYPE=0) döner — "fiş ayrıntıları" drill-down.
 * INVOICE.CLIENTREF doğrulanır (başka cariye ait fatura getirilemez). ../cari/lg_hareketdetay.php kalıbı.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma, $firmadonem, $eskifirmadonem;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(['ok' => false, 'mesaj' => 'Yalnızca POST desteklenir.'], 405);
}
$oturum   = api_oturum_gerekli($dbh);
$personel = (int) $oturum['personel'];
if (!api_yetki_var($personel, 'CR1')) {
    api_json(['ok' => false, 'mesaj' => 'Belge detayı görme yetkiniz yok.'], 403);
}

$body     = api_body();
$cariId   = (int) ($body['cari_id'] ?? 0);
$belgeRef = (int) ($body['belge_ref'] ?? 0);
$donem    = (string) ($body['donem'] ?? 'aktif');
if ($cariId <= 0 || $belgeRef <= 0) {
    api_json(['ok' => false, 'mesaj' => 'Cari ve belge seçimi gerekiyor.'], 400);
}

$prefix = ($donem === 'onceki' && isset($eskifirmadonem) && $eskifirmadonem !== '')
    ? $eskifirmadonem
    : $firmadonem;

try {
    $h = $dbh->prepare("SELECT TOP 1 FICHENO, DATE_, ISNULL(NETTOTAL, 0) AS NET
                        FROM {$prefix}INVOICE WITH(NOLOCK)
                        WHERE LOGICALREF = :ref AND CLIENTREF = :c");
    $h->execute([':ref' => $belgeRef, ':c' => $cariId]);
    $fatura = $h->fetch(PDO::FETCH_ASSOC);
    if (!$fatura) {
        api_json(['ok' => false, 'mesaj' => 'Belge bulunamadı veya bu cariye ait değil.'], 404);
    }

    $l = $dbh->prepare("SELECT SH.AMOUNT, SH.PRICE, SH.TOTAL, SH.DISTDISC, S.CODE, S.NAME
                        FROM {$prefix}STLINE SH WITH(NOLOCK)
                        LEFT JOIN {$firma}ITEMS S WITH(NOLOCK) ON S.LOGICALREF = SH.STOCKREF
                        WHERE SH.INVOICEREF = :ref AND SH.LINETYPE = 0
                        ORDER BY SH.LOGICALREF ASC");
    $l->execute([':ref' => $belgeRef]);
    $rows = $l->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('api/cari_hareket_detay: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Belge detayı alınırken hata oluştu.'], 500);
}

$miktarBicim = static function (float $m): string {
    $s = number_format($m, 2, ',', '.');
    // Tam sayıysa ,00 ekini at.
    return preg_replace('/,00$/', '', $s) ?? $s;
};

$satirlar = array_map(static function (array $r) use ($miktarBicim): array {
    $miktar = (float) $r['AMOUNT'];
    $fiyat  = (float) $r['PRICE'];
    $toplam = (float) $r['TOTAL'];
    return [
        'kod'          => (string) ($r['CODE'] ?? ''),
        'ad'           => api_metin($r['NAME'] ?? ''),
        'miktar'       => $miktar,
        'miktar_metin' => $miktarBicim($miktar),
        'fiyat'        => $fiyat,
        'fiyat_metin'  => api_money($fiyat),
        'toplam'       => $toplam,
        'toplam_metin' => api_money($toplam),
    ];
}, $rows);

$net = (float) $fatura['NET'];
api_json([
    'ok'      => true,
    'baslik'  => [
        'fisno'     => (string) ($fatura['FICHENO'] ?? ''),
        'tarih'     => api_tarih($fatura['DATE_'] ?? null),
        'net'       => $net,
        'net_metin' => api_money($net),
    ],
    'satirlar' => $satirlar,
    'sayi'     => count($satirlar),
]);
