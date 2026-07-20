<?php
declare(strict_types=1);

/**
 * POST /api/tercih_getir.php
 * Gövde (JSON): { "anahtar"?: "tema" }   (anahtar verilmezse TÜM tercihler)
 * Yanıt: { ok:true, tercihler:{ "<key>":"<value>", ... } }
 *
 * Token zorunlu. Masaüstü uygulamasının kişisel tercihlerini (tema/görünüm vb.)
 * web ile AYNI M_USER_SETTINGS key-value tablosundan okur. M16 GEREKMEZ —
 * herkes yalnızca kendi tercihlerini görür.
 *
 * USER_CODE = LG_SLSMAN.CODE. Oturumdaki personel (LOGICALREF) id'sinden kod
 * çekilir; kod bulunamazsa personel id'si string olarak fallback kullanılır.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');

global $dbh;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(['ok' => false, 'mesaj' => 'Yalnizca POST destekleniyor.'], 405);
}

$oturum   = api_oturum_gerekli($dbh);
$personel = (int) $oturum['personel'];

/**
 * M_USER_SETTINGS yoksa oluştur (repoda CREATE script'i yok; DB'de elle açılmış
 * olabilir). Web ile aynı kolon yapısı: USER_CODE / SETTING_KEY / SETTING_VALUE.
 * Hata olursa sessiz geç — uç çalışmaya devam etsin. Process-level guard.
 */
function api_ensure_user_settings_table(PDO $dbh): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    try {
        $stmt = $dbh->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = :t");
        $stmt->execute([':t' => 'M_USER_SETTINGS']);
        if ($stmt->fetchColumn()) {
            return;
        }
        $dbh->exec("CREATE TABLE M_USER_SETTINGS (
            ID INT IDENTITY(1,1) PRIMARY KEY,
            USER_CODE VARCHAR(50) NOT NULL,
            SETTING_KEY VARCHAR(80) NOT NULL,
            SETTING_VALUE NVARCHAR(MAX) NULL
        )");
    } catch (Throwable $e) {
        error_log('M_USER_SETTINGS olusturma: ' . $e->getMessage());
    }
}

/**
 * Personel (LG_SLSMAN.LOGICALREF) id'sinden CODE çek. Bulunamazsa id'yi string
 * olarak döndür (fallback). Tercih tablosunun USER_CODE'u bu değerle eşleşir.
 */
function api_kullanici_kodu(PDO $dbh, int $personel): string
{
    try {
        $stmt = $dbh->prepare("SELECT CODE FROM LG_SLSMAN WHERE LOGICALREF = :id");
        $stmt->execute([':id' => $personel]);
        $kod = $stmt->fetchColumn();
        if ($kod !== false && $kod !== null && (string) $kod !== '') {
            return (string) $kod;
        }
    } catch (Throwable $e) {
        error_log('api_kullanici_kodu: ' . $e->getMessage());
    }
    return (string) $personel;
}

$body    = api_body();
$anahtar = trim((string) ($body['anahtar'] ?? ''));

try {
    api_ensure_user_settings_table($dbh);
    $kod = api_kullanici_kodu($dbh, $personel);

    if ($anahtar !== '') {
        $stmt = $dbh->prepare(
            "SELECT SETTING_KEY, SETTING_VALUE FROM M_USER_SETTINGS
             WHERE USER_CODE = :code AND SETTING_KEY = :key"
        );
        $stmt->execute([':code' => $kod, ':key' => $anahtar]);
    } else {
        $stmt = $dbh->prepare(
            "SELECT SETTING_KEY, SETTING_VALUE FROM M_USER_SETTINGS
             WHERE USER_CODE = :code"
        );
        $stmt->execute([':code' => $kod]);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('api/tercih_getir: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Tercihler alınamadı.'], 500);
}

$tercihler = [];
foreach ($rows as $r) {
    $tercihler[(string) $r['SETTING_KEY']] = (string) ($r['SETTING_VALUE'] ?? '');
}

api_json(['ok' => true, 'tercihler' => $tercihler]);
