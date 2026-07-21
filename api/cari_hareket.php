<?php
declare(strict_types=1);

/**
 * POST /api/cari_hareket.php
 * Gövde: { "cari_id":123, "donem"?: "aktif" | "onceki" | "tumu" }
 * Yanıt: { ok, bakiye:{...}, ozet:{toplam_giris, toplam_cikis}, baslangic:{...}, hareketler:[...] }
 *
 * Cari hesap ekstresi (CLFLINE borç/alacak hareketleri + yürüyen bakiye). ../cari/lg_hareket.php
 * mantığıyla birebir: resmi bakiye GNTOTCL'den; başlangıç = resmi − gösterilen hareket neti.
 * Token + CR1 (cari finansal) yetkisi zorunlu. Salt-okuma.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma, $firmadonem, $firmadonemx, $eskifirmadonem;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(['ok' => false, 'mesaj' => 'Yalnızca POST desteklenir.'], 405);
}
$oturum   = api_oturum_gerekli($dbh);
$personel = (int) $oturum['personel'];
if (!api_yetki_var($personel, 'CR1')) {
    api_json(['ok' => false, 'mesaj' => 'Cari hareket görme yetkiniz yok.'], 403);
}

$body   = api_body();
$cariId = (int) ($body['cari_id'] ?? 0);
$donem  = (string) ($body['donem'] ?? 'aktif');
if ($cariId <= 0) {
    api_json(['ok' => false, 'mesaj' => 'Cari seçimi gerekiyor.'], 400);
}

// Özel Cari kısıtı (web tarafındaki lg_hareket.php ile aynı kural).
if (!m_p_cariid_goruntulebilir_mi($dbh, $firma, $personel, $cariId, 'M4')) {
    api_json(['ok' => false, 'mesaj' => 'Cari bulunamadi.'], 404);
}

// Dönem prefixleri (eskifirmadonem yoksa yalnız aktif döneme düş).
$eski = (isset($eskifirmadonem) && $eskifirmadonem !== '') ? $eskifirmadonem : $firmadonem;
$prefixler = match ($donem) {
    'onceki' => [$eski],
    'tumu'   => array_values(array_unique([$firmadonem, $eski])),
    default  => [$firmadonem],
};

// İşlem türü (../cari/lg_hareket.php ile birebir TRCODE eşlemesi).
$trcase = "CASE H.TRCODE
    WHEN 1 THEN 'Nakit Tahsilat' WHEN 2 THEN 'Nakit Ödeme' WHEN 3 THEN 'Borç Dekontu' WHEN 4 THEN 'Alacak Dekontu' WHEN 5 THEN 'Virman Fişi'
    WHEN 6 THEN 'Kur Farkı Fişi' WHEN 12 THEN 'Özel Fiş' WHEN 14 THEN 'Açılış Fişi' WHEN 20 THEN 'Gelen Havale' WHEN 21 THEN 'Gönderilen Havale'
    WHEN 24 THEN 'Döviz Alış Belgesi' WHEN 25 THEN 'Döviz Satış Belgesi' WHEN 28 THEN 'Alınan Hizmet Faturası' WHEN 29 THEN 'Verilen Hizmet Faturası'
    WHEN 31 THEN 'Satın Alma Faturası' WHEN 32 THEN 'Perakende Satış İade Faturası' WHEN 33 THEN 'Toptan Satış İade Faturası'
    WHEN 34 THEN 'Alınan Hizmet Faturası' WHEN 35 THEN 'Alınan Proforma Fatura' WHEN 36 THEN 'Satın Alma İade Faturası'
    WHEN 37 THEN 'Perakende Satış Faturası' WHEN 38 THEN 'Toptan Satış Faturası' WHEN 39 THEN 'Verilen Hizmet Faturası'
    WHEN 40 THEN 'Verilen Proforma Fatura' WHEN 41 THEN 'Verilen Vade Farkı Faturası' WHEN 42 THEN 'Alınan Vade Farkı Faturası'
    WHEN 43 THEN 'Satın Alma Fiyat Farkı' WHEN 44 THEN 'Satış Fiyat Farkı' WHEN 45 THEN 'Verilen Serbest Meslek Makbuzu'
    WHEN 46 THEN 'Alınan Serbest Meslek Makbuzu' WHEN 56 THEN 'Müstahsil Makbuzu'
    WHEN 61 THEN 'Çek Girişi' WHEN 62 THEN 'Senet Girişi' WHEN 63 THEN 'Çek Çıkışı' WHEN 64 THEN 'Senet Çıkışı'
    WHEN 70 THEN 'Kredi Kartı Fişi' WHEN 71 THEN 'Kredi Kartı İade' WHEN 72 THEN 'Firma Kredi Kartı' WHEN 73 THEN 'Firma Kredi Kartı İade'
    WHEN 81 THEN 'Satınalma Siparişi' WHEN 82 THEN 'Satış Siparişi'
    ELSE 'Diğer' END";

try {
    // Resmi bakiye (GNTOTCL — ../cari/lg_bakiye.php ile aynı kaynak).
    $st = $dbh->prepare("SELECT (ISNULL(G.DEBIT,0) - ISNULL(G.CREDIT,0)) AS BAK
                         FROM {$firmadonemx}GNTOTCL G WITH(NOLOCK)
                         WHERE G.CARDREF = :c AND G.TOTTYP = 1");
    $st->execute([':c' => $cariId]);
    $resmi = round((float) $st->fetchColumn(), 2);

    // Hareketler — :cid UNION ALL'da tekrarlanacağı için int cast doğrudan gömülür (güvenli).
    $cidSafe = (int) $cariId;
    $parts = [];
    foreach ($prefixler as $p) {
        $acilis = ($donem === 'tumu') ? ' AND H.TRCODE <> 14' : '';
        $donemTag = ($p === $firmadonem) ? 'aktif' : 'onceki';
        $parts[] = "
            SELECT H.LOGICALREF, H.DATE_, H.TRCODE AS TRCODE_NO, {$trcase} AS TUR,
                   H.LINEEXP AS ACIKLAMA,
                   ISNULL((1 - H.SIGN) * H.AMOUNT, 0) AS BORC,
                   ISNULL(H.SIGN * H.AMOUNT, 0)       AS ALACAK,
                   ISNULL(COALESCE(INV.LOGICALREF, INV2.LOGICALREF), 0) AS BELGE_REF,
                   '{$donemTag}' AS DONEM
            FROM {$p}CLFLINE H WITH(NOLOCK)
            LEFT JOIN {$p}INVOICE INV WITH(NOLOCK)
                   ON INV.LOGICALREF = H.SOURCEFREF AND INV.CLIENTREF = H.CLIENTREF
            LEFT JOIN {$p}STFICHE STF WITH(NOLOCK) ON STF.LOGICALREF = H.SOURCEFREF
            LEFT JOIN {$p}INVOICE INV2 WITH(NOLOCK)
                   ON INV2.LOGICALREF = STF.INVOICEREF AND INV2.CLIENTREF = H.CLIENTREF
            WHERE H.CANCELLED = 0 AND H.CLIENTREF = {$cidSafe}{$acilis}";
    }
    $sql  = "SELECT * FROM (" . implode(" UNION ALL ", $parts) . ") T ORDER BY DATE_ ASC, LOGICALREF ASC";
    $rows = $dbh->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('api/cari_hareket: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Cari hareketleri alınırken hata oluştu.'], 500);
}

// Toplamlar + başlangıç (devir) bakiyesi.
$toplamBorc = 0.0;
$toplamAlacak = 0.0;
foreach ($rows as $r) {
    $toplamBorc   += (float) $r['BORC'];
    $toplamAlacak += (float) $r['ALACAK'];
}
$baslangic = round($resmi - ($toplamBorc - $toplamAlacak), 2);

// Yürüyen bakiye (../cari/lg_hareket.php: borç +, alacak −).
$bak = $baslangic;
$hareketler = [];
foreach ($rows as $r) {
    $borc   = round((float) $r['BORC'], 2);
    $alacak = round((float) $r['ALACAK'], 2);
    $bak    = round($bak + $borc - $alacak, 2);
    $belgeRef = (int) ($r['BELGE_REF'] ?? 0);
    $hareketler[] = [
        'tarih'        => api_tarih($r['DATE_']),
        'tur'          => (string) $r['TUR'],
        'aciklama'     => api_metin($r['ACIKLAMA'] ?? ''),
        'giris'        => $alacak,                       // Alacak = cari hesabına giriş
        'giris_metin'  => $alacak > 0 ? api_money($alacak) : '',
        'cikis'        => $borc,                          // Borç = cari hesabından çıkış
        'cikis_metin'  => $borc > 0 ? api_money($borc) : '',
        'bakiye'       => $bak,
        'bakiye_metin' => api_money(abs($bak)) . ($bak >= 0 ? ' B' : ' A'),
        'belge_ref'    => $belgeRef,                      // Faturaya bağlıysa STLINE detayı çekilebilir
        'belge_donem'  => (string) ($r['DONEM'] ?? 'aktif'),
        'detay_var'    => $belgeRef > 0,
    ];
}

$durum = $resmi > 0 ? 'Borçlu' : ($resmi < 0 ? 'Alacaklı' : 'Sıfır');

api_json([
    'ok' => true,
    'bakiye' => [
        'deger' => $resmi,
        'durum' => $durum,
        'metin' => api_money(abs($resmi)) . ' (' . $durum . ')',
    ],
    'ozet' => [
        'toplam_giris'       => round($toplamAlacak, 2),
        'toplam_giris_metin' => api_money($toplamAlacak),
        'toplam_cikis'       => round($toplamBorc, 2),
        'toplam_cikis_metin' => api_money($toplamBorc),
    ],
    'baslangic' => [
        'deger' => $baslangic,
        'metin' => api_money(abs($baslangic)) . ($baslangic >= 0 ? ' B' : ' A'),
        'var'   => abs($baslangic) > 0.01,
    ],
    'donem'      => $donem,
    'hareketler' => $hareketler,
    'sayi'       => count($hareketler),
]);
