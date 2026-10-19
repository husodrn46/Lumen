<?php

declare(strict_types=1);

/**
 * POST /cek_giris.php — Bir müşteri çekini portföye alır (CSCARD+CSROLL+CSTRANS+CLFLINE).
 * Gövde: cari_ref, tutar, cekno, banka, vade (YYYY-MM-DD), sahibi, aciklama, csrf_token
 * Yanıt: JSON { ok, mesaj, cscard_ref?, csroll_ref?, cstrans_ref?, clfline_ref?, portfoyno?, rollno?, tutar?, log_id? }
 *
 * Erişim: M30 (Çek İşlemleri) yetkisi + CSRF + POST (fail-closed).
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/../kontrol.php');
include_once(__DIR__ . '/../donem_helper.php');
include_once(__DIR__ . '/../log_ip.php');
include_once(__DIR__ . '/cek_lib.php');

global $dbh, $firma, $firmadonem, $terminalkullanici, $yetkidurum;

header('Content-Type: application/json; charset=utf-8');

function cek_json(array $a, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Guard zinciri (fail-closed) ---
if ((int) m_p_yetki($terminalkullanici, 'M30') !== 1) {
    cek_json(['ok' => false, 'mesaj' => 'Çek işlemi için yetkiniz yok.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cek_json(['ok' => false, 'mesaj' => 'Yalnızca POST desteklenir.'], 405);
}
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    cek_json(['ok' => false, 'mesaj' => 'Oturum doğrulaması başarısız, sayfayı yenileyin.'], 403);
}

$cariRef  = (int) ($_POST['cari_ref'] ?? 0);
$aciklama = trim((string) ($_POST['aciklama'] ?? ''));
$resmi    = (($_POST['resmi'] ?? '') === '1');

// Kalemler: JSON dizisi (çoklu çek). Yoksa tekli alanlardan (geri uyumluluk).
$kalemler = [];
$ham = (string) ($_POST['kalemler'] ?? '');
if ($ham !== '') {
    $j = json_decode($ham, true);
    if (is_array($j)) {
        foreach ($j as $x) {
            if (!is_array($x)) { continue; }
            $kalemler[] = [
                'tutar'  => cek_tutar_coz($x['tutar'] ?? '0'),
                'cekno'  => trim((string) ($x['cekno'] ?? '')),
                'banka'  => trim((string) ($x['banka'] ?? '')),
                'vade'   => trim((string) ($x['vade'] ?? '')),
                'sahibi' => trim((string) ($x['sahibi'] ?? '')),
            ];
        }
    }
}
if (!$kalemler) {
    $kalemler[] = [
        'tutar'  => cek_tutar_coz($_POST['tutar'] ?? '0'),
        'cekno'  => trim((string) ($_POST['cekno'] ?? '')),
        'banka'  => trim((string) ($_POST['banka'] ?? '')),
        'vade'   => trim((string) ($_POST['vade'] ?? '')),
        'sahibi' => trim((string) ($_POST['sahibi'] ?? '')),
    ];
}

if ($cariRef <= 0) { cek_json(['ok' => false, 'mesaj' => 'Cari seçilmedi.'], 400); }
if (count($kalemler) > 50) { cek_json(['ok' => false, 'mesaj' => 'En fazla 50 çek girilebilir.'], 422); }
foreach ($kalemler as $i => $k) {
    if ($k['tutar'] <= 0) { cek_json(['ok' => false, 'mesaj' => ($i + 1) . '. çekin tutarını girin.'], 422); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $k['vade'])) { cek_json(['ok' => false, 'mesaj' => ($i + 1) . '. çek için vade tarihi seçin.'], 422); }
}

$requestId = cek_istek_id($_POST['istek_id'] ?? null);
$res = cek_giris_core($dbh, $firma, $firmadonem, $cariRef, [
    'resmi' => $resmi, 'aciklama' => $aciklama, 'kalemler' => $kalemler,
], (int) $terminalkullanici, $requestId, [
    'ip' => function_exists('getUserIP') ? (string) getUserIP() : (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
]);

csrf_regenerate();
unset($res['hata']);   // hata detayını istemciye sızdırma
cek_json($res, ($res['ok'] ?? false) ? 200 : (int) ($res['kod'] ?? 400));
