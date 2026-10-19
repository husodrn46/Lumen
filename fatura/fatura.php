<?php

declare(strict_types=1);

/**
 * POST /fatura/fatura.php — Bir satış siparişini (ORFICHE) LOGO satış faturasına çevirir.
 * Gövde: siparis_ref, csrf_token
 * Yanıt: JSON { ok, mesaj, invoice_ref?, fiche_no?, net?, log_id? }
 *
 * Erişim: $faturalama_aktif açık + M31 yetkisi + CSRF + POST (fail-closed).
 *
 * BETA: Bu özellik henüz beta aşamasındadır ve yalnızca beta sürümünde
 * bulunur. LOGO'ya kalıcı kayıt yazar; geri alma (fatura_geri_al.php)
 * mevcuttur ama önce test veritabanında denemeniz önerilir.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/../kontrol.php');
include_once(__DIR__ . '/../donem_helper.php');
include_once(__DIR__ . '/../log_ip.php');
include_once(__DIR__ . '/fatura_lib.php');

global $dbh, $firma, $firmadonem, $terminalkullanici;
global $faturalama_aktif, $fatura_prefix, $fatura_irsaliye_prefix;

header('Content-Type: application/json; charset=utf-8');

function fatura_json(array $a, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Guard zinciri (fail-closed) ---
if (empty($faturalama_aktif)) {
    fatura_json(['ok' => false, 'mesaj' => 'Faturalama özelliği şu an kapalı.'], 403);
}
if ((int) m_p_yetki($terminalkullanici, 'M31') !== 1) {
    fatura_json(['ok' => false, 'mesaj' => 'Faturalama yetkiniz yok.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fatura_json(['ok' => false, 'mesaj' => 'Yalnızca POST desteklenir.'], 405);
}
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    fatura_json(['ok' => false, 'mesaj' => 'Oturum doğrulaması başarısız, sayfayı yenileyin.'], 403);
}

$orficheRef = (int) ($_POST['siparis_ref'] ?? 0);
if ($orficheRef <= 0) {
    fatura_json(['ok' => false, 'mesaj' => 'Sipariş seçilmedi.'], 400);
}

// Özel Cari kısıtı: kısıtlı carinin siparişi faturalanamaz.
if (!m_p_siparis_goruntulebilir_mi($dbh, $firmadonem, $firma, (int) $terminalkullanici, $orficheRef)) {
    fatura_json(['ok' => false, 'mesaj' => 'Sipariş bulunamadı.'], 404);
}

$requestId = (string) guid();
$res = fatura_olustur_core($dbh, $firma, $firmadonem, $orficheRef, (int) $terminalkullanici, $requestId, [
    'invoice_prefix'  => (string) ($fatura_prefix ?? ''),
    'irsaliye_prefix' => (string) ($fatura_irsaliye_prefix ?? ''),
    'ip'              => function_exists('getUserIP') ? (string) getUserIP() : (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
]);

csrf_regenerate();

// Hata detayını (varsa) istemciye sızdırma — sadece logda kalsın
unset($res['hata']);
fatura_json($res, ($res['ok'] ?? false) ? 200 : (int) ($res['kod'] ?? 400));
