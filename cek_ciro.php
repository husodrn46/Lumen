<?php

declare(strict_types=1);

/**
 * POST /cek_ciro.php — Portföydeki N müşteri çekini bir hedef cariye (tedarikçi) ciro eder.
 * Gövde: hedef_cari, cek_refleri (JSON int dizisi), aciklama, csrf_token
 * Yanıt: JSON { ok, mesaj, csroll_ref?, clfline_ref?, rollno?, adet?, tutar?, log_id? }
 *
 * BETA: $cek_cikis_beta açık + yönetici (yetkidurum=0) + allow-list + CSRF + POST (fail-closed).
 */

include_once(__DIR__ . '/ayr.php');
include_once(__DIR__ . '/kontrol.php');
include_once(__DIR__ . '/donem_helper.php');
include_once(__DIR__ . '/log_ip.php');
include_once(__DIR__ . '/cek_lib.php');

global $dbh, $firma, $firmadonem, $terminalkullanici, $yetkidurum;
global $cek_cikis_beta, $cek_cikis_beta_kullanicilar;

header('Content-Type: application/json; charset=utf-8');

function cek_ciro_json(array $a, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Guard zinciri (fail-closed) ---
if (empty($cek_cikis_beta)) {
    cek_ciro_json(['ok' => false, 'mesaj' => 'Çek çıkışı özelliği şu an kapalı.'], 403);
}
if ((int) ($yetkidurum ?? 1) !== 0) {
    cek_ciro_json(['ok' => false, 'mesaj' => 'Bu işlem için yöneticilik gerekir.'], 403);
}
$izinli = is_array($cek_cikis_beta_kullanicilar ?? null) ? $cek_cikis_beta_kullanicilar : [];
if (empty($izinli) || !in_array((int) $terminalkullanici, array_map('intval', $izinli), true)) {
    cek_ciro_json(['ok' => false, 'mesaj' => 'Çek çıkışı beta döneminde size tanımlı değil.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cek_ciro_json(['ok' => false, 'mesaj' => 'Yalnızca POST desteklenir.'], 405);
}
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    cek_ciro_json(['ok' => false, 'mesaj' => 'Oturum doğrulaması başarısız, sayfayı yenileyin.'], 403);
}

$hedefCari = (int) ($_POST['hedef_cari'] ?? 0);
$aciklama  = trim((string) ($_POST['aciklama'] ?? ''));

$cekRefleri = [];
$ham = (string) ($_POST['cek_refleri'] ?? '');
if ($ham !== '') {
    $j = json_decode($ham, true);
    if (is_array($j)) { $cekRefleri = array_map('intval', $j); }
}

if ($hedefCari <= 0) { cek_ciro_json(['ok' => false, 'mesaj' => 'Hedef cari (tedarikçi) seçilmedi.'], 400); }
if (!$cekRefleri)    { cek_ciro_json(['ok' => false, 'mesaj' => 'Ciro edilecek çek seçilmedi.'], 422); }

$requestId = (string) guid();
$res = cek_ciro_core($dbh, $firma, $firmadonem, $hedefCari, $cekRefleri, $aciklama, (int) $terminalkullanici, $requestId, [
    'ip' => function_exists('getUserIP') ? (string) getUserIP() : (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
]);

csrf_regenerate();
unset($res['hata']);
cek_ciro_json($res, ($res['ok'] ?? false) ? 200 : (int) ($res['kod'] ?? 400));
