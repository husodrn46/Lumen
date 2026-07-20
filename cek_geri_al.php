<?php

declare(strict_types=1);

/**
 * POST /cek_geri_al.php — Bir çek girişini M_CEK_LOG kaydından tam geri alır.
 * Gövde: log_id, csrf_token
 * Yanıt: JSON { ok, mesaj }
 *
 * BETA: $cek_beta açık + yönetici + allow-list + CSRF + POST (fail-closed).
 * GUARD: çek portföyden çıkmışsa (ciro/tahsil) geri-alma reddedilir.
 */

include_once(__DIR__ . '/ayr.php');
include_once(__DIR__ . '/kontrol.php');
include_once(__DIR__ . '/donem_helper.php');
include_once(__DIR__ . '/log_ip.php');
include_once(__DIR__ . '/cek_lib.php');

global $dbh, $firmadonem, $terminalkullanici, $yetkidurum;
global $cek_beta, $cek_beta_kullanicilar;

header('Content-Type: application/json; charset=utf-8');

function cek_geri_json(array $a, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Guard zinciri (fail-closed) ---
if (empty($cek_beta)) {
    cek_geri_json(['ok' => false, 'mesaj' => 'Çek girişi özelliği şu an kapalı.'], 403);
}
if ((int) ($yetkidurum ?? 1) !== 0) {
    cek_geri_json(['ok' => false, 'mesaj' => 'Bu işlem için yöneticilik gerekir.'], 403);
}
$izinli = is_array($cek_beta_kullanicilar ?? null) ? $cek_beta_kullanicilar : [];
if (empty($izinli) || !in_array((int) $terminalkullanici, array_map('intval', $izinli), true)) {
    cek_geri_json(['ok' => false, 'mesaj' => 'Çek girişi beta döneminde size tanımlı değil.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cek_geri_json(['ok' => false, 'mesaj' => 'Yalnızca POST desteklenir.'], 405);
}
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    cek_geri_json(['ok' => false, 'mesaj' => 'Oturum doğrulaması başarısız, sayfayı yenileyin.'], 403);
}

$logId = (int) ($_POST['log_id'] ?? 0);
$not   = trim((string) ($_POST['not'] ?? ''));
if ($logId <= 0) { cek_geri_json(['ok' => false, 'mesaj' => 'Kayıt seçilmedi.'], 400); }

$res = cek_geri_al_core($dbh, $firmadonem, $logId, (int) $terminalkullanici, $not);

csrf_regenerate();
unset($res['hata']);
cek_geri_json($res, ($res['ok'] ?? false) ? 200 : (int) ($res['kod'] ?? 400));
