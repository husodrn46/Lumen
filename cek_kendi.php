<?php

declare(strict_types=1);

/**
 * POST /cek_kendi.php — Kendi çekimizi (DOC=3) düzenleyip bir hedef cariye verir (çek çıkışı).
 * Gövde: hedef_cari, banka_ref, kalemler (JSON: [{tutar,vade,cekno},...]), aciklama, csrf_token
 * Erişim: M30 (Çek İşlemleri) yetkisi + CSRF + POST (fail-closed).
 */

include_once(__DIR__ . '/ayr.php');
include_once(__DIR__ . '/kontrol.php');
include_once(__DIR__ . '/donem_helper.php');
include_once(__DIR__ . '/log_ip.php');
include_once(__DIR__ . '/cek_lib.php');

global $dbh, $firma, $firmadonem, $terminalkullanici, $yetkidurum;

header('Content-Type: application/json; charset=utf-8');

function cek_kendi_json(array $a, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

if ((int) m_p_yetki($terminalkullanici, 'M30') !== 1) {
    cek_kendi_json(['ok' => false, 'mesaj' => 'Çek işlemi için yetkiniz yok.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cek_kendi_json(['ok' => false, 'mesaj' => 'Yalnızca POST desteklenir.'], 405);
}
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    cek_kendi_json(['ok' => false, 'mesaj' => 'Oturum doğrulaması başarısız, sayfayı yenileyin.'], 403);
}

function cek_kendi_tutar_parse(mixed $s): float
{
    $s = str_replace(['.', ' '], '', trim((string) $s));
    $s = str_replace(',', '.', $s);
    return (float) $s;
}

$hedefCari = (int) ($_POST['hedef_cari'] ?? 0);
$bankaRef  = (int) ($_POST['banka_ref'] ?? 0);
$aciklama  = trim((string) ($_POST['aciklama'] ?? ''));

$kalemler = [];
$ham = (string) ($_POST['kalemler'] ?? '');
if ($ham !== '') {
    $j = json_decode($ham, true);
    if (is_array($j)) {
        foreach ($j as $x) {
            if (!is_array($x)) { continue; }
            $kalemler[] = [
                'tutar' => cek_kendi_tutar_parse($x['tutar'] ?? '0'),
                'vade'  => trim((string) ($x['vade'] ?? '')),
                'cekno' => trim((string) ($x['cekno'] ?? '')),
            ];
        }
    }
}

if ($hedefCari <= 0) { cek_kendi_json(['ok' => false, 'mesaj' => 'Hedef cari seçilmedi.'], 400); }
if ($bankaRef <= 0)  { cek_kendi_json(['ok' => false, 'mesaj' => 'Banka hesabı seçilmedi.'], 400); }
if (!$kalemler)      { cek_kendi_json(['ok' => false, 'mesaj' => 'En az bir çek girin.'], 422); }

$requestId = (string) guid();
$res = cek_kendi_core($dbh, $firma, $firmadonem, $hedefCari, $bankaRef, $kalemler, $aciklama, (int) $terminalkullanici, $requestId, [
    'ip' => function_exists('getUserIP') ? (string) getUserIP() : (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
]);

csrf_regenerate();
unset($res['hata']);
cek_kendi_json($res, ($res['ok'] ?? false) ? 200 : (int) ($res['kod'] ?? 400));
