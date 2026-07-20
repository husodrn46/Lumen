<?php
declare(strict_types=1);

/**
 * POST /api/kullanici_kaydet.php
 * Gövde: { "id"?:0, "kod":"KOD-1", "ad":"Ad Soyad", "sifre"?:"1234", "firma":1, "yetki":1 }
 *   id yok / 0  -> EKLE   (web: ayar/mobilkullanici.php)
 *   id > 0      -> GÜNCELLE (web: ayar/mobilkullanicix.php)
 *   yetki: 0=Yönetici, 1=Personel, 2=Müşteri
 * Yanıt: { ok, id, mesaj }
 *
 * Token + M16 yetki zorunlu.
 * ÖNEMLİ: LG_SLSMAN ve M_P_YETKI tabloları PREFİKSSİZDİR (sabit ad); $firma/$firmadonem KULLANILMAZ.
 * LG_SLSMAN bir LOGO ERP tablosudur — INSERT/UPDATE alanları web ile birebir aynıdır. ACTIVE=0 aktif demektir.
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
    api_json(['ok' => false, 'mesaj' => 'Kullanıcı yönetimi yetkiniz yok.'], 403);
}

$body  = api_body();
$id    = (int) ($body['id'] ?? 0);
$kod   = trim((string) ($body['kod'] ?? ''));
$ad    = trim((string) ($body['ad'] ?? ''));
$sifre = trim((string) ($body['sifre'] ?? ''));
$firma = (int) ($body['firma'] ?? 0);
$yetki = (int) ($body['yetki'] ?? 1);

/* ---- Ortak doğrulama (ekle + güncelle) ---- */
if ($kod === '' || strlen($kod) > 30) {
    api_json(['ok' => false, 'mesaj' => 'Kod boş olamaz ve 30 karakterden uzun olamaz.'], 422);
}
if ($ad === '' || strlen($ad) > 50) {
    api_json(['ok' => false, 'mesaj' => 'Ad boş olamaz ve 50 karakterden uzun olamaz.'], 422);
}
if (!in_array($yetki, [0, 1, 2], true)) {
    api_json(['ok' => false, 'mesaj' => 'Geçersiz yetki tipi.'], 422);
}

$sifreGecerli = !($sifre === '' || $sifre === '0' || strlen($sifre) < 4);

if ($id <= 0) {
    /* ==========================  EKLE  ========================== */
    /* ayar/mobilkullanici.php birebir */

    // 1) Şifre zorunlu
    if (!$sifreGecerli) {
        api_json(['ok' => false, 'mesaj' => 'Şifre en az 4 karakter olmalıdır.'], 422);
    }

    try {
        // 2) Benzersizlik kontrolü
        $kontrol = $dbh->prepare("SELECT CODE FROM LG_SLSMAN WHERE CODE = :kod AND FIRMNR = :firma");
        $kontrol->execute([':kod' => $kod, ':firma' => $firma]);
        if ($kontrol->fetch()) {
            api_json(['ok' => false, 'mesaj' => 'Bu firmada bu kod zaten kullanılmış.'], 409);
        }

        // 3-5) LG_SLSMAN + M_P_YETKI tek transaction içinde
        $dbh->beginTransaction();

        $ekle = $dbh->prepare(
            "INSERT INTO LG_SLSMAN (CODE, DEFINITION_, CARDTYPE, CAPIBLOCK_CREADEDDATE, USERID, FIRMNR, TYP, ACTIVE)
             VALUES (:kod, :ad, 0, GETDATE(), 1, :firma, 0, 0)"
        );
        $ekle->execute([':kod' => $kod, ':ad' => $ad, ':firma' => $firma]);

        $sonid = (int) $dbh->lastInsertId();

        $yetkiEkle = $dbh->prepare(
            "INSERT INTO M_P_YETKI (PERSONEL, SIFRE, YETKI) VALUES (:sonid, :sifre, :yetki)"
        );
        $yetkiEkle->execute([
            ':sonid' => $sonid,
            ':sifre' => sifre_hashle($sifre),
            ':yetki' => $yetki,
        ]);

        $dbh->commit();
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        error_log('api/kullanici_kaydet (ekle): ' . $e->getMessage());
        api_json(['ok' => false, 'mesaj' => 'Kullanıcı kaydedilemedi.'], 500);
    }

    // 6)
    api_json(['ok' => true, 'id' => $sonid, 'mesaj' => $kod . ' eklendi.']);
}

/* ==========================  GÜNCELLE  ========================== */
/* ayar/mobilkullanicix.php birebir */
try {
    // 1) M_P_YETKI satırı var mı
    $check = $dbh->prepare("SELECT COUNT(*) FROM M_P_YETKI WHERE PERSONEL = :id");
    $check->execute([':id' => $id]);
    $exists = (int) $check->fetchColumn();

    // 2) Yeni yetki satırı oluşturulacaksa parola zorunlu
    if ($exists === 0 && !$sifreGecerli) {
        api_json(['ok' => false, 'mesaj' => 'Bu kullanıcı için şifre belirleyin (en az 4 karakter).'], 422);
    }

    // 3) Tüm yazma işlemleri tek transaction içinde
    $dbh->beginTransaction();

    $upd = $dbh->prepare(
        "UPDATE LG_SLSMAN SET CODE = :kod, DEFINITION_ = :ad, FIRMNR = :firma WHERE LOGICALREF = :id"
    );
    $upd->execute([':kod' => $kod, ':ad' => $ad, ':firma' => $firma, ':id' => $id]);

    if ($exists > 0) {
        $sqlYetki    = "UPDATE M_P_YETKI SET YETKI = :yetki";
        $paramsYetki = [':yetki' => $yetki, ':id' => $id];
        if ($sifreGecerli) {
            $sqlYetki .= ", SIFRE = :sifre";
            $paramsYetki[':sifre'] = sifre_hashle($sifre);
        }
        $sqlYetki .= " WHERE PERSONEL = :id";
        $stmtYetki = $dbh->prepare($sqlYetki);
        $stmtYetki->execute($paramsYetki);
    } else {
        $stmtYetki = $dbh->prepare(
            "INSERT INTO M_P_YETKI (PERSONEL, SIFRE, YETKI) VALUES (:id, :sifre, :yetki)"
        );
        $stmtYetki->execute([':id' => $id, ':sifre' => sifre_hashle($sifre), ':yetki' => $yetki]);
    }

    $dbh->commit();

    // 4) Yetki cache temizliği
    if (function_exists('m_p_yetki_cache_temizle')) {
        m_p_yetki_cache_temizle($id);
    }
} catch (Throwable $e) {
    // 5)
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    error_log('api/kullanici_kaydet (guncelle): ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Kayıt sırasında hata oluştu.'], 500);
}

// 6)
api_json(['ok' => true, 'id' => $id, 'mesaj' => $kod . ' güncellendi.']);
