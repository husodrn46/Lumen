<?php

declare(strict_types=1);

/**
 * POST /cek_kendi_geri_al.php — Bir kendi-çek verme işlemini geri alır (DOC=3 kartlar dahil silinir).
 * Gövde: log_id, csrf_token
 * Erişim: M30 (Çek İşlemleri) yetkisi + CSRF + POST (fail-closed).
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/../kontrol.php');
include_once(__DIR__ . '/../donem_helper.php');
include_once(__DIR__ . '/../log_ip.php');
include_once(__DIR__ . '/cek_lib.php');

global $dbh, $firmadonem, $terminalkullanici, $yetkidurum;

header('Content-Type: application/json; charset=utf-8');

function cek_kendi_geri_json(array $a, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

if ((int) m_p_yetki($terminalkullanici, 'M30') !== 1) {
    cek_kendi_geri_json(['ok' => false, 'mesaj' => 'Çek işlemi için yetkiniz yok.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cek_kendi_geri_json(['ok' => false, 'mesaj' => 'Yalnızca POST desteklenir.'], 405);
}
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    cek_kendi_geri_json(['ok' => false, 'mesaj' => 'Oturum doğrulaması başarısız, sayfayı yenileyin.'], 403);
}

$logId = (int) ($_POST['log_id'] ?? 0);
$not   = trim((string) ($_POST['not'] ?? ''));
if ($logId <= 0) { cek_kendi_geri_json(['ok' => false, 'mesaj' => 'Kayıt seçilmedi.'], 400); }

$res = cek_kendi_geri_al($dbh, $firmadonem, $logId, (int) $terminalkullanici, $not);

csrf_regenerate();
unset($res['hata']);
cek_kendi_geri_json($res, ($res['ok'] ?? false) ? 200 : (int) ($res['kod'] ?? 400));
