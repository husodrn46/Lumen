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
} catch (Throwable $e) {
    error_log('api/login sorgu hatasi: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Sunucu hatasi. Lutfen tekrar deneyin.'], 500);
}

// Hatalı kullanıcı/şifre: tek tip mesaj (kullanıcı var mı bilgisini sızdırma).
if (!$row || !isset($row['SIFRE']) || !sifre_dogrula($parola, (string) $row['SIFRE'])) {
    api_json(['ok' => false, 'mesaj' => 'Kullanici adi veya sifre hatali.'], 401);
}

$personel = (int) $row['LOGICALREF'];
$yetkiKodu = m_p_yetki($personel, 'YETKI');
$yetkidurum = is_numeric($yetkiKodu) ? (int) $yetkiKodu : -1;

if (!in_array($yetkidurum, [0, 1], true)) {
    api_json(['ok' => false, 'mesaj' => 'Bu hesabin masaustu uygulamasina giris yetkisi yok.'], 403);
}

$token = api_token_olustur($dbh, $personel, $kullanici);

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
