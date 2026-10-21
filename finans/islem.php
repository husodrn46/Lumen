<?php

declare(strict_types=1);

/**
 * Kasa/banka fişi işlemleri (POST): kaydet, iptal.
 * Sonuç sayfaya yönlendirmeyle bildirilir (ok / hata parametresi).
 */

include_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    lumen_hata_sayfasi('Geçersiz istek', 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.', 403);
}

$islem = (string) ($_POST['islem'] ?? '');
$git = static function (string $yol, array $q): never {
    header('Location: ' . $yol . '?' . http_build_query($q), true, 303);
    exit;
};

if ($islem === 'iptal') {
    $id = (int) ($_POST['id'] ?? 0);
    // Görme kuralı fiş ekranıyla aynı: gizli carinin fişi iptal edilemez.
    $fc = $dbh->prepare('SELECT CARI_ID FROM dbo.LM_FINANS_FIS WHERE ID = :id');
    $fc->execute([':id' => $id]);
    $fcari = $fc->fetchColumn();
    if ($fcari === false || ($fcari !== null && !m_p_cariid_goruntulebilir_mi($dbh, $firma, (int) $terminalkullanici, (int) $fcari))) {
        lumen_hata_sayfasi('Fiş bulunamadı', 'Kasa/banka fişi bulunamadı.', 404);
    }
    $r = lumen_finans_fis_iptal($dbh, $id, (int) $terminalkullanici, (int) $yetkidurum === 0);
    $git('goster.php', ['id' => $id, $r['ok'] ? 'ok' : 'hata' => $r['mesaj']]);
}

if ($islem === 'kaydet') {
    $tur = (int) ($_POST['tur'] ?? 0);
    $cari = (int) ($_POST['cari_id'] ?? 0);
    // Açılış fişi yalnız kurulumda / içe aktarmada kesilir.
    if ($tur === 5) {
        $git('yeni.php', ['hata' => 'Geçersiz fiş türü.']);
    }
    // Özel cari kısıtı: gizlenen cariye fiş kesilemez.
    if ($cari > 0 && !m_p_cariid_goruntulebilir_mi($dbh, $firma, (int) $terminalkullanici, $cari)) {
        $git('yeni.php', ['tur' => $tur, 'hata' => 'Cari bulunamadı.']);
    }
    $r = lumen_finans_fis_kaydet($dbh, [
        'tur' => $tur, 'hesap_id' => (int) ($_POST['hesap_id'] ?? 0), 'karsi_hesap_id' => (int) ($_POST['karsi_hesap_id'] ?? 0),
        'cari_id' => $cari, 'tutar' => (string) ($_POST['tutar'] ?? '0'), 'tarih' => (string) ($_POST['tarih'] ?? ''),
        'aciklama' => (string) ($_POST['aciklama'] ?? ''), 'istek_id' => (string) ($_POST['istek_id'] ?? ''),
    ], (int) $terminalkullanici);
    if ($r['ok'] || isset($r['id'])) {
        $git('goster.php', ['id' => $r['id'], $r['ok'] ? 'ok' : 'hata' => $r['mesaj']]);
    }
    $git('yeni.php', ['tur' => $tur, 'hata' => $r['mesaj']]);
}

lumen_hata_sayfasi('Geçersiz istek', 'Bilinmeyen işlem.', 400);
