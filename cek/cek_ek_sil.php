<?php

declare(strict_types=1);

/**
 * cek_ek_sil.php — Cek gorsel ekini siler (AJAX, JSON). Yalnizca yonetici.
 * POST: ek_id (M_CEK_EK.ID), csrf_token
 */

ob_start();
include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../kontrol.php");
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
function cek_sil_cik(bool $ok, string $mesaj = ''): void
{
    echo json_encode(['ok' => $ok, 'mesaj' => $mesaj], JSON_UNESCAPED_UNICODE);
    exit;
}

if ((int) ($yetkidurum ?? 1) !== 0) {
    cek_sil_cik(false, 'Bu islem icin yonetici yetkisi gerekli.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    cek_sil_cik(false, 'Gecersiz istek / oturum.');
}

$ekId = (int) ($_POST['ek_id'] ?? 0);
if ($ekId <= 0) {
    cek_sil_cik(false, 'Gecersiz kayit.');
}

$st = $dbh->prepare("SELECT DOSYA_YOLU FROM M_CEK_EK WHERE ID = :id");
$st->execute([':id' => $ekId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    cek_sil_cik(false, 'Kayit bulunamadi.');
}

// Path traversal korumasi: yalnizca dosya adi, sabit klasor
$dosyaAd = basename((string) $row['DOSYA_YOLU']);
$path = __DIR__ . '/../cek_ekleri/' . $dosyaAd;
if (is_file($path)) {
    @unlink($path);
}

try {
    $dbh->prepare("DELETE FROM M_CEK_EK WHERE ID = :id")->execute([':id' => $ekId]);
    cek_sil_cik(true, 'Gorsel silindi.');
} catch (PDOException $e) {
    error_log('cek_ek_sil db: ' . $e->getMessage());
    cek_sil_cik(false, 'Silme sirasinda hata olustu.');
}
