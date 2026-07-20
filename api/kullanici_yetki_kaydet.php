<?php
declare(strict_types=1);

/**
 * POST /api/kullanici_yetki_kaydet.php
 * Gövde: { "personel_id": 123, "kolon": "M5", "deger": 0|1 }
 * Yanıt: { ok, mesaj }
 *
 * Token + M16 yetki zorunlu. Bir kullanıcının tek bir izin kolonunu günceller.
 * Web karşılığı: ayar/yetki_guncelle_ajax.php (UPDATE/INSERT mantığı birebir uyarlandı).
 * ÖNEMLİ: M_P_YETKI tablosu PREFİKSSİZDİR (sabit ad); $firma/$firmadonem KULLANILMAZ.
 * Bu uçtan PERSONEL/SIFRE/YETKI DEĞİŞTİRİLEMEZ — yalnızca izin kolonları (M/ST/CR/SP).
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
$kolon      = trim((string) ($body['kolon'] ?? ''));
// Değer 0 veya 1'e indirgenir (web tarafıyla aynı: tek bit izin).
$deger      = ((int) ($body['deger'] ?? 0) === 1) ? 1 : 0;

if ($personelId <= 0) {
    api_json(['ok' => false, 'mesaj' => 'Geçersiz personel ID.'], 400);
}

/**
 * Whitelist = m_p_yetki_tum_sutunlar() (ayr.php) içindeki yalnızca izin kolonları.
 * PERSONEL/SIFRE/YETKI elenir (regex M\d+|ST\d+|CR\d+|SP\d+). Kolon adı bu listeden
 * geldiği için aşağıda dinamik string olarak güvenle gömülebilir; değer parametrelidir.
 */
$izinKolonlari = array_values(array_filter(
    function_exists('m_p_yetki_tum_sutunlar') ? m_p_yetki_tum_sutunlar() : [],
    static fn (string $k): bool => (bool) preg_match('/^(M\d+|ST\d+|CR\d+|SP\d+)$/', $k)
));

if (!in_array($kolon, $izinKolonlari, true)) {
    api_json(['ok' => false, 'mesaj' => 'Geçersiz kolon.'], 400);
}

try {
    // Kullanıcının M_P_YETKI satırı var mı? (named param tek kullanım — sqlsrv kuralı)
    $check = $dbh->prepare("SELECT COUNT(*) FROM M_P_YETKI WHERE PERSONEL = :id");
    $check->bindValue(':id', $personelId, PDO::PARAM_INT);
    $check->execute();
    $varMi = (int) $check->fetchColumn() > 0;

    if ($varMi) {
        // Kolon adı whitelist'ten geldiği için güvenle gömülür; değer parametreli.
        $upd = $dbh->prepare("UPDATE M_P_YETKI SET [{$kolon}] = :deger WHERE PERSONEL = :id");
        $upd->bindValue(':deger', $deger, PDO::PARAM_INT);
        $upd->bindValue(':id', $personelId, PDO::PARAM_INT);
        $upd->execute();
    } else {
        // Satır yoksa varsayılan YETKI=2 (Müşteri) ile oluştur ve izni ata.
        $ins = $dbh->prepare("INSERT INTO M_P_YETKI (PERSONEL, YETKI, [{$kolon}]) VALUES (:id, 2, :deger)");
        $ins->bindValue(':id', $personelId, PDO::PARAM_INT);
        $ins->bindValue(':deger', $deger, PDO::PARAM_INT);
        $ins->execute();
    }
} catch (Throwable $e) {
    error_log('api/kullanici_yetki_kaydet: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Yetki güncellenemedi.'], 500);
}

// Hedef kullanıcının yetki cache'ini temizle (varsa).
if (function_exists('m_p_yetki_cache_temizle')) {
    m_p_yetki_cache_temizle($personelId);
}

api_json(['ok' => true, 'mesaj' => 'Yetki güncellendi.']);
