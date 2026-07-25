<?php

declare(strict_types=1);

/**
 * POST /fatura/fatura_geri_al.php — Uygulamanın oluşturduğu bir faturayı tam
 * geri alır (INVOICE/STFICHE/STLINE/CLFLINE siler, sipariş SHIPPEDAMOUNT'unu
 * eski değerine döndürür). Tek transaction.
 * Gövde: log_id, csrf_token   (alternatif: siparis_ref → aktif log bulunur)
 * Yanıt: JSON { ok, mesaj }
 *
 * Erişim: $faturalama_aktif açık + M31 yetkisi + CSRF + POST (fail-closed).
 * Yalnızca M_FATURA_LOG'da kaydı olan, yani bu uygulamanın kestiği faturalar
 * geri alınabilir; LOGO'da elle kesilmiş faturalara dokunmaz.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/../kontrol.php');
include_once(__DIR__ . '/../donem_helper.php');
include_once(__DIR__ . '/../log_ip.php');
include_once(__DIR__ . '/fatura_lib.php');

global $dbh, $firma, $firmadonem, $terminalkullanici;
global $faturalama_aktif;

header('Content-Type: application/json; charset=utf-8');

function fatura_geri_json(array $a, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($faturalama_aktif)) {
    fatura_geri_json(['ok' => false, 'mesaj' => 'Faturalama özelliği şu an kapalı.'], 403);
}
if ((int) m_p_yetki($terminalkullanici, 'M31') !== 1) {
    fatura_geri_json(['ok' => false, 'mesaj' => 'Faturalama yetkiniz yok.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fatura_geri_json(['ok' => false, 'mesaj' => 'Yalnızca POST desteklenir.'], 405);
}
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    fatura_geri_json(['ok' => false, 'mesaj' => 'Oturum doğrulaması başarısız, sayfayı yenileyin.'], 403);
}

fatura_log_tablo_olustur($dbh);

$logId = (int) ($_POST['log_id'] ?? 0);
if ($logId <= 0) {
    // siparis_ref ile aktif log bul
    $orf = (int) ($_POST['siparis_ref'] ?? 0);
    if ($orf > 0) {
        $st = $dbh->prepare("SELECT TOP 1 ID FROM M_FATURA_LOG WHERE ORFICHE_REF = :r AND DURUM = 'AKTIF' ORDER BY ID DESC");
        $st->execute([':r' => $orf]);
        $logId = (int) $st->fetchColumn();
    }
}
if ($logId <= 0) {
    fatura_geri_json(['ok' => false, 'mesaj' => 'Geri alınacak aktif fatura kaydı bulunamadı.'], 404);
}

$not = (string) ($_POST['not'] ?? '');
$res = fatura_geri_al_core($dbh, $firmadonem, $logId, (int) $terminalkullanici, $not);

csrf_regenerate();
unset($res['hata']);
fatura_geri_json($res, ($res['ok'] ?? false) ? 200 : (int) ($res['kod'] ?? 400));
