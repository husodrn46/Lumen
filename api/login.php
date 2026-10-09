<?php
declare(strict_types=1);

/**
 * POST /api/login.php
 * Gövde (JSON): { "kullanici": "...", "sifre": "..." }
 * Başarılı: { ok:true, token, kullanici:{ id, ad, depo, yetki_kodu, yetkidurum } }
 * Hatalı:   { ok:false, mesaj }
 *
 * Kimlik doğrulama mevcut web giriş mantığıyla aynıdır (LG_SLSMAN + M_P_YETKI,
 * sifre_dogrula). Müşteri (yetkidurum=2) masaüstüne giremez.
 */

include_once(__DIR__ . '/../ayr.php');   // DB ($dbh), $firmano, sifre_dogrula, m_p_yetki, normalize_login_username
include_once(__DIR__ . '/_api.inc');
include_once(__DIR__ . '/../includes/giris_koruma.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(['ok' => false, 'mesaj' => 'Yalnizca POST destekleniyor.'], 405);
}

$body = api_body();
$kullanici = function_exists('normalize_login_username')
    ? normalize_login_username((string) ($body['kullanici'] ?? ''))
    : trim((string) ($body['kullanici'] ?? ''));
$parola = (string) ($body['sifre'] ?? '');

if ($kullanici === '' || $parola === '') {
    api_json(['ok' => false, 'mesaj' => 'Kullanici adi ve sifre gerekli.'], 400);
}

global $dbh, $firmano;

// Brute-force sertlestirme: web girisiyle (giris.php) ayni esikler.
// Aksi halde saldirgan web tarafindaki sinirlamayi API uzerinden atlayabilirdi.
$istekIp = function_exists('getUserIP') ? getUserIP() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');

if (giris_ip_engelli_mi($dbh, $istekIp)) {
    if (function_exists('logGiris')) {
        logGiris(0, $kullanici, false, 'Brute-force engellendi (API)');
    }
    api_json(['ok' => false, 'mesaj' => 'Cok fazla hatali giris denemesi. Lutfen 15 dakika bekleyin.'], 429);
}

$kilit = giris_hesap_kilitli_mi($dbh, $kullanici);
if ($kilit['kilitli']) {
    api_json([
        'ok' => false,
        'mesaj' => "Cok fazla hatali giris nedeniyle hesap gecici olarak kilitlendi. {$kilit['kalan_dk']} dakika sonra tekrar deneyin.",
    ], 429);
}

try {
    $stmt = $dbh->prepare("
        SELECT L.LOGICALREF, L.CYPHCODE, L.SPECODE, M.SIFRE
        FROM LG_SLSMAN L
        LEFT JOIN M_P_YETKI M ON L.LOGICALREF = M.PERSONEL
        WHERE L.FIRMNR = :firmano
          AND L.CODE = :kullanici
          AND L.ACTIVE = 0
    ");
    $stmt->bindValue(':firmano', (int) $firmano, PDO::PARAM_INT);
    $stmt->bindValue(':kullanici', $kullanici);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
} catch (Throwable $e) {
    error_log('api/login sorgu hatasi: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Sunucu hatasi. Lutfen tekrar deneyin.'], 500);
}

// Hatalı kullanıcı/şifre: tek tip mesaj (kullanıcı var mı bilgisini sızdırma).
// Basarisiz deneme loglanir; yoksa IP/hesap esikleri hic dolmaz.
if (!$row || !isset($row['SIFRE']) || !sifre_dogrula($parola, (string) $row['SIFRE'])) {
    if (function_exists('logGiris')) {
        logGiris((int) ($row['LOGICALREF'] ?? 0), $kullanici, false, 'Hatali sifre (API)');
    }
    api_json(['ok' => false, 'mesaj' => 'Kullanici adi veya sifre hatali.'], 401);
}

$personel = (int) $row['LOGICALREF'];
if (isset($_SESSION) && is_array($_SESSION)) { m_p_yetki_cache_temizle($personel); }
$yetkiKodu = m_p_yetki($personel, 'YETKI');
$yetkidurum = is_numeric($yetkiKodu) ? (int) $yetkiKodu : -1;

if (!in_array($yetkidurum, [0, 1], true)) {
    api_json(['ok' => false, 'mesaj' => 'Bu hesabin masaustu uygulamasina giris yetkisi yok.'], 403);
}


try {
    // Web girişinin mevcut legacy-parola yükseltmesini API de uygular.
    if (sifre_yenilenmeli((string) $row['SIFRE'])) {
        $rehash = $dbh->prepare('UPDATE M_P_YETKI SET SIFRE = :s WHERE PERSONEL = :p AND SIFRE = :eski');
        $rehash->execute([':s' => sifre_hashle($parola), ':p' => $personel, ':eski' => (string) $row['SIFRE']]);
    }
    $token = api_token_olustur($dbh, $personel, $kullanici);
} catch (Throwable $e) {
    error_log('API giriş token/parola: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Oturum oluşturulamadı.'], 503);
}

// Basarili girisi logla: hesap kilidi sorgusu "son basarili giristen sonraki
// basarisizlar" uzerinden calisiyor, bu kayit olmazsa kilit penceresi sifirlanmaz.
if (function_exists('logGiris')) {
    logGiris($personel, $kullanici, true, 'Basarili giris (API)');
}


api_json([
    'ok' => true,
    'token' => $token,
    'kullanici' => [
        'id' => $personel,
        'ad' => $kullanici,
        'depo' => (string) ($row['SPECODE'] ?? ''),
        'yetki_kodu' => (string) ($row['CYPHCODE'] ?? ''),
        'yetkidurum' => $yetkidurum,
    ],
]);
