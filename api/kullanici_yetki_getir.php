<?php
declare(strict_types=1);

/**
 * POST /api/kullanici_yetki_getir.php
 * Gövde: { "personel_id": 123 }
 * Yanıt: { ok, yetki:int(YETKI), yonetici:bool, izinler:{ "M1":0|1, ..., "ST1":0|1, ... } }
 *
 * Token + M16 yetki zorunlu. Bir kullanıcının menü/işlem izinlerini döndürür.
 * Web karşılığı: ayar/mobilyetki.php izin matrisi (yetki_guncelle_ajax.php okuma yönü).
 * ÖNEMLİ: M_P_YETKI tablosu PREFİKSSİZDİR (sabit ad); $firma/$firmadonem KULLANILMAZ.
 * PERSONEL ve SIFRE sütunları ASLA yanıtta dönmez; YETKI ayrı alan olarak döner.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(['ok' => false, 'mesaj' => 'Yalnızca POST desteklenir.'], 405);
}

$oturum   = api_oturum_gerekli($dbh);
$personel = (int) $oturum['personel'];

if (!api_yetki_var($personel, 'M16')) {
    api_json(['ok' => false, 'mesaj' => 'Yetki yönetimi yetkiniz yok.'], 403);
}

$body       = api_body();
$personelId = (int) ($body['personel_id'] ?? 0);

if ($personelId <= 0) {
    api_json(['ok' => false, 'mesaj' => 'Geçersiz personel ID.'], 400);
}

/**
 * İzin kolonları whitelist'i = m_p_yetki_tum_sutunlar() (ayr.php) içinden yalnızca
 * gerçek izin kolonları. PERSONEL/SIFRE/YETKI elenir (regex M\d+|ST\d+|CR\d+|SP\d+).
 */
$izinKolonlari = array_values(array_filter(
    function_exists('m_p_yetki_tum_sutunlar') ? m_p_yetki_tum_sutunlar() : [],
    static fn (string $k): bool => (bool) preg_match('/^(M\d+|ST\d+|CR\d+|SP\d+)$/', $k)
));

try {
    // Tek satır; named param tek kullanım (sqlsrv kuralı).
    $stmt = $dbh->prepare("SELECT * FROM M_P_YETKI WHERE PERSONEL = :id");
    $stmt->bindValue(':id', $personelId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('api/kullanici_yetki_getir: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Yetki bilgisi alınamadı.'], 500);
}

// Satır yoksa boş varsay: tüm izinler 0, YETKI varsayılan 2 (Müşteri).
$row   = is_array($row) ? $row : [];
$yetki = isset($row['YETKI']) ? (int) $row['YETKI'] : 2;

// YETKI=0 (Yönetici) ise tüm izinler etkin sayılır; yine de gerçek kolon değerlerini döndürürüz.
$yonetici = ($yetki === 0);

$izinler = [];
foreach ($izinKolonlari as $kolon) {
    $izinler[$kolon] = (isset($row[$kolon]) && (int) $row[$kolon] === 1) ? 1 : 0;
}

api_json([
    'ok'       => true,
    'yetki'    => $yetki,
    'yonetici' => $yonetici,
    'izinler'  => $izinler,
]);
