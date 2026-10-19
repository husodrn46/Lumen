<?php

declare(strict_types=1);

/** Tanım işlemleri (POST) — sonuç sekmeye yönlendirmeyle bildirilir. */

include_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    lumen_hata_sayfasi('Geçersiz istek', 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.', 403);
}
$islem = (string) ($_POST['islem'] ?? '');
$id = (int) ($_POST['id'] ?? 0);
$sekme = match (true) {
    str_starts_with($islem, 'firma') => 'firma', str_starts_with($islem, 'hesap') => 'hesap', str_starts_with($islem, 'depo') => 'depo',
    str_starts_with($islem, 'birim') => 'birim', str_starts_with($islem, 'kur') => 'kur', default => 'firma',
};
try {
    $r = match ($islem) {
        'firma_kaydet' => lumen_tanim_firma_kaydet($dbh, $_POST),
        'hesap_kaydet' => lumen_tanim_hesap_kaydet($dbh, $id, $_POST),
        'hesap_aktif', 'hesap_pasif' => lumen_tanim_hesap_aktiflik($dbh, $id, $islem === 'hesap_aktif'),
        'depo_kaydet' => lumen_tanim_depo_kaydet($dbh, $id, $_POST),
        'depo_aktif', 'depo_pasif' => lumen_tanim_depo_aktiflik($dbh, $id, $islem === 'depo_aktif'),
        'birim_kaydet' => lumen_tanim_birim_kaydet($dbh, $id, $_POST),
        'birim_sil' => lumen_tanim_birim_sil($dbh, $id),
        'kur_kaydet' => lumen_tanim_kur_kaydet($dbh, (string) ($_POST['tarih'] ?? ''), strtoupper((string) ($_POST['pb'] ?? '')),
            lumen_tutar_coz($_POST['alis'] ?? '0'), lumen_tutar_coz($_POST['satis'] ?? '0')),
        'kur_tcmb' => lumen_tcmb_kurlari_al($dbh),
        default => ['ok' => false, 'mesaj' => 'Bilinmeyen işlem.'],
    };
} catch (Throwable $e) {
    error_log('tanimlar/islem: ' . $e->getMessage());
    $r = ['ok' => false, 'mesaj' => 'İşlem yapılamadı.'];
}
if (function_exists('app_log_write')) {
    app_log_write('ayar-degisiklik', ['ekran' => 'tanimlar', 'islem' => $islem, 'id' => $id, 'personel' => (int) $terminalkullanici, 'sonuc' => $r['ok']]);
}
header('Location: index.php?' . http_build_query(['sekme' => $sekme, $r['ok'] ? 'ok' : 'hata' => $r['mesaj']]), true, 303);
exit;
