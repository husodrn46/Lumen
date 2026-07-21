<?php

declare(strict_types=1);

/**
 * cari_olustur_ve_aktar.php — Yeni cari olusturur ve mevcut fisi (ORFICHE) o
 * cariye aktarir. siparis/lg_fis.php'den AJAX (JSON) ile cagrilir.
 * POST: stokhareket, unvan, telefon, sehir, ilce, csrf_token
 * Erisim: M12 (Yeni Cari) yetkisi.
 */

ob_start();
include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../kontrol.php");
require_once(__DIR__ . "/cari_olustur_lib.php");
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

function coa_cik(bool $ok, string $mesaj = '', array $ek = []): void
{
    echo json_encode(array_merge(['ok' => $ok, 'mesaj' => $mesaj], $ek), JSON_UNESCAPED_UNICODE);
    exit;
}

if (m_p_yetki($terminalkullanici, 'M12') != 1) {
    coa_cik(false, 'Yeni cari acma yetkiniz yok.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    coa_cik(false, 'Gecersiz istek / oturum dogrulamasi.');
}

$stokhareket = (int) ($_POST['stokhareket'] ?? 0);
$unvan = trim((string) ($_POST['unvan'] ?? ''));
$telefon = trim((string) ($_POST['telefon'] ?? ''));
$sehir = trim((string) ($_POST['sehir'] ?? ''));
$ilce = trim((string) ($_POST['ilce'] ?? ''));

if ($unvan === '') {
    coa_cik(false, 'Cari unvani zorunludur.');
}
if ($stokhareket <= 0) {
    coa_cik(false, 'Gecerli bir fis bulunamadi.');
}

// Fis gercekten var mi?
try {
    $f = $dbh->prepare("SELECT LOGICALREF FROM {$firmadonem}ORFICHE WHERE LOGICALREF = :s");
    $f->execute([':s' => $stokhareket]);
    if (!$f->fetchColumn()) {
        coa_cik(false, 'Fis bulunamadi.');
    }
} catch (Throwable $e) {
    error_log('cari_olustur_ve_aktar fis kontrol: ' . $e->getMessage());
    coa_cik(false, 'Fis dogrulanamadi.');
}

// 1) Yeni cari olustur
$sonuc = cari_olustur_yeni($dbh, $firma, (int) $terminalkullanici, $unvan, $telefon, $sehir, $ilce);
if (!$sonuc['ok']) {
    coa_cik(false, $sonuc['mesaj']);
}

// 2) Fisi yeni cariye aktar (ORFICHE.CLIENTREF)
try {
    $u = $dbh->prepare("UPDATE {$firmadonem}ORFICHE SET CLIENTREF = :c WHERE LOGICALREF = :s");
    $u->execute([':c' => $sonuc['cariid'], ':s' => $stokhareket]);
    if (function_exists('logFisGuncelleme')) {
        @logFisGuncelleme($stokhareket, "Yeni cari olusturuldu ve fis aktarildi: [{$sonuc['kod']}] {$sonuc['ad']}");
    }
} catch (Throwable $e) {
    error_log('cari_olustur_ve_aktar UPDATE: ' . $e->getMessage());
    // Cari olustu ama aktarim basarisiz -> kullaniciya cariyi bildir
    coa_cik(false, 'Cari olusturuldu (' . $sonuc['kod'] . ') ancak fise aktarilamadi. Fisten "Baska Cariye Aktar" ile elle aktarabilirsiniz.', [
        'cariid' => $sonuc['cariid'],
        'kod' => $sonuc['kod'],
    ]);
}

coa_cik(true, 'Cari olusturuldu ve fis bu cariye aktarildi.', [
    'cariid' => $sonuc['cariid'],
    'kod' => $sonuc['kod'],
    'ad' => $sonuc['ad'],
]);
