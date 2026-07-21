<?php
declare(strict_types=1);

/**
 * GET/POST /api/siparis_listesi.php
 * Gövde (tüm alanlar opsiyonel):
 *   { "cari_id"?:123, "query"?:"ar", "limit"?:50,
 *     "baslangic"?:"2026-06-01", "bitis"?:"2026-06-30",
 *     "sehir"?:"istanbul",
 *     "durum"?:"all"|"sevk"|"bekleyen"|"taslak",
 *     "min_tutar"?:100, "max_tutar"?:5000,
 *     "siralama"?:"tarih_desc"|... }
 * Yanıt: { ok, siparisler:[{ id, fisno, tarih, cari_kod, cari_ad, sehir,
 *          net, net_metin, satir_sayisi, durum:"sevk"|"bekleyen", not }], sayi }
 *
 * Token zorunlu. Aktif (iptal edilmemiş) satış siparişlerini (TRCODE=1) listeler.
 * - durum=sevk     : sevkiyatı başlamış (STLINE eşleşmesi olan) fişler
 * - durum=bekleyen : henüz sevkedilmemiş fişler
 * - durum=taslak   : YALNIZCA oturum sahibinin (SALESMANREF) satırsız + NETTOTAL=0 taslakları
 *   (web ../siparis/lg_tumsiparisler.php / ../siparis/lg_geridonusum.php filtreleriyle uyumlu).
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh, $firma, $firmadonem;
$oturum   = api_oturum_gerekli($dbh);
$personel = (int) $oturum['personel'];

$body   = api_body();
$cariId = (int) ($body['cari_id'] ?? ($_GET['cari_id'] ?? 0));
$query  = trim((string) ($body['query'] ?? ($_GET['q'] ?? '')));
$limit  = max(1, min(500, (int) ($body['limit'] ?? ($_GET['limit'] ?? 50))));
$bas    = trim((string) ($body['baslangic'] ?? ($_GET['baslangic'] ?? '')));
$bit    = trim((string) ($body['bitis'] ?? ($_GET['bitis'] ?? '')));
$sehir  = trim((string) ($body['sehir'] ?? ($_GET['sehir'] ?? '')));
$durum  = strtolower(trim((string) ($body['durum'] ?? ($_GET['durum'] ?? 'all'))));
$siralama = strtolower(trim((string) ($body['siralama'] ?? ($_GET['siralama'] ?? 'tarih_desc'))));
$minTutar = (isset($body['min_tutar']) && $body['min_tutar'] !== '') ? (float) $body['min_tutar'] : null;
$maxTutar = (isset($body['max_tutar']) && $body['max_tutar'] !== '') ? (float) $body['max_tutar'] : null;

// Sadece geçerli ISO tarih (YYYY-MM-DD) kabul et; aksi halde yok say.
$gecerliTarih = static fn(string $d): bool => $d !== '' && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);

$wheres = ['F.TRCODE = 1', 'ISNULL(F.CANCELLED, 0) = 0'];
$params = [];

// Özel Cari kısıtı: kısıtlı carilerin siparişleri listede hiç görünmez
// (web ../siparis/lg_tumsiparisler.php ile aynı kural).
$ozelCariFiltresi = m_p_ozel_cari_sql_filtresi($personel, 'F.CLIENTREF', 'api_siparis_listesi');
if (($ozelCariFiltresi['sql'] ?? '') !== '') {
    $wheres[] = $ozelCariFiltresi['sql'];
    $params = array_merge($params, $ozelCariFiltresi['params']);
}

if ($cariId > 0) {
    $wheres[] = 'F.CLIENTREF = :cari_id';
    $params[':cari_id'] = $cariId;
}
if ($query !== '') {
    $norm  = api_arama_norm($query);
    $fNorm = api_sql_norm('F.FICHENO');
    $cNorm = api_sql_norm('C.DEFINITION_');
    $kNorm = api_sql_norm('C.CODE');
    $wheres[] = "({$fNorm} LIKE :q_f OR {$cNorm} LIKE :q_c OR {$kNorm} LIKE :q_k)";
    $params[':q_f'] = '%' . $norm . '%';
    $params[':q_c'] = '%' . $norm . '%';
    $params[':q_k'] = '%' . $norm . '%';
}
if ($sehir !== '') {
    $wheres[] = api_sql_norm('C.CITY') . ' LIKE :sehir';
    $params[':sehir'] = '%' . api_arama_norm($sehir) . '%';
}
if ($gecerliTarih($bas)) {
    $wheres[] = 'F.DATE_ >= :bas';
    $params[':bas'] = $bas;
}
if ($gecerliTarih($bit)) {
    // Bitiş günü dahil: ertesi günden küçük (DATEADD'in 1. arg sabit INT — sqlsrv güvenli).
    $wheres[] = 'F.DATE_ < DATEADD(DAY, 1, :bit)';
    $params[':bit'] = $bit;
}
if ($minTutar !== null) {
    $wheres[] = 'F.NETTOTAL >= :min_t';
    $params[':min_t'] = $minTutar;
}
if ($maxTutar !== null) {
    $wheres[] = 'F.NETTOTAL <= :max_t';
    $params[':max_t'] = $maxTutar;
}

// Durum filtresi
if ($durum === 'sevk') {
    $wheres[] = "EXISTS (SELECT 1 FROM {$firmadonem}STLINE ST WITH(NOLOCK)
                         WHERE ST.ORDFICHEREF = F.LOGICALREF AND ST.ORDFICHEREF > 0)";
} elseif ($durum === 'bekleyen') {
    $wheres[] = "NOT EXISTS (SELECT 1 FROM {$firmadonem}STLINE ST WITH(NOLOCK)
                             WHERE ST.ORDFICHEREF = F.LOGICALREF AND ST.ORDFICHEREF > 0)";
} elseif ($durum === 'taslak') {
    // Satırsız + boş (NETTOTAL=0) + yalnızca oturum sahibinin taslakları.
    $wheres[] = 'F.NETTOTAL = 0';
    $wheres[] = 'F.SALESMANREF = :personel';
    $params[':personel'] = $personel;
    $wheres[] = "NOT EXISTS (SELECT 1 FROM {$firmadonem}ORFLINE L WITH(NOLOCK)
                             WHERE L.ORDFICHEREF = F.LOGICALREF)";
}

$whereSql = implode(' AND ', $wheres);

// Sıralama (whitelist — kullanıcı girdisi doğrudan SQL'e girmez)
$sortMap = [
    'tarih_desc' => 'F.DATE_ DESC, F.LOGICALREF DESC',
    'tarih_asc'  => 'F.DATE_ ASC, F.LOGICALREF ASC',
    'tutar_desc' => 'F.NETTOTAL DESC',
    'tutar_asc'  => 'F.NETTOTAL ASC',
    'fisno_desc' => 'F.FICHENO DESC',
    'fisno_asc'  => 'F.FICHENO ASC',
    'unvan_asc'  => 'C.DEFINITION_ ASC',
    'unvan_desc' => 'C.DEFINITION_ DESC',
];
$orderSql = $sortMap[$siralama] ?? $sortMap['tarih_desc'];

try {
    $sql = "
        SELECT TOP {$limit}
            F.LOGICALREF AS id,
            F.FICHENO    AS fisno,
            F.DATE_      AS tarih,
            ISNULL(F.NETTOTAL, 0) AS net,
            C.CODE        AS cari_kod,
            C.DEFINITION_ AS cari_ad,
            C.CITY        AS sehir,
            F.GENEXP1     AS not_,
            (SELECT COUNT(*) FROM {$firmadonem}ORFLINE L WITH(NOLOCK)
             WHERE L.ORDFICHEREF = F.LOGICALREF AND L.LINETYPE = 0) AS satir_sayisi,
            CASE WHEN EXISTS (SELECT 1 FROM {$firmadonem}STLINE S2 WITH(NOLOCK)
                              WHERE S2.ORDFICHEREF = F.LOGICALREF AND S2.ORDFICHEREF > 0)
                 THEN 1 ELSE 0 END AS sevk_durumu
        FROM {$firmadonem}ORFICHE F WITH(NOLOCK)
        LEFT JOIN {$firma}CLCARD C WITH(NOLOCK) ON C.LOGICALREF = F.CLIENTREF
        WHERE {$whereSql}
        ORDER BY {$orderSql}
    ";
    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('api/siparis_listesi: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Sipariş listesi alınamadı.'], 500);
}

$siparisler = array_map(static function (array $r): array {
    $net = (float) $r['net'];
    return [
        'id'           => (int) $r['id'],
        'fisno'        => (string) $r['fisno'],
        'tarih'        => api_tarih($r['tarih']),
        'cari_kod'     => (string) ($r['cari_kod'] ?? ''),
        'cari_ad'      => api_metin($r['cari_ad'] ?? ''),
        'sehir'        => api_metin($r['sehir'] ?? ''),
        'net'          => $net,
        'net_metin'    => api_money($net),
        'satir_sayisi' => (int) $r['satir_sayisi'],
        'durum'        => ((int) $r['sevk_durumu'] === 1) ? 'sevk' : 'bekleyen',
        'not'          => api_metin($r['not_'] ?? ''),
    ];
}, $rows);

api_json(['ok' => true, 'siparisler' => $siparisler, 'sayi' => count($siparisler)]);
