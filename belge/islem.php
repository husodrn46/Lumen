<?php

declare(strict_types=1);

/**
 * Belge işlemleri (POST): kaydet (yeni belge / siparişten), iptal.
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
    $tb = $dbh->prepare('SELECT TUR, YON FROM dbo.LM_BELGE WHERE ID = :id');
    $tb->execute([':id' => $id]);
    $tr = $tb->fetch(PDO::FETCH_ASSOC);
    if (!$tr || !isset($belgeTurleri[(string) lumen_belge_tur_kodu((int) $tr['TUR'], (int) $tr['YON'])])) {
        lumen_hata_sayfasi('Belge bulunamadı', 'Bu numarada bir belge yok.', 404);
    }
    $r = lumen_belge_iptal($dbh, $id, (int) $terminalkullanici);
    $git('goster.php', ['id' => $id, $r['ok'] ? 'ok' : 'hata' => $r['mesaj']]);
}

if ($islem === 'kaydet') {
    $tur = (string) ($_POST['tur'] ?? '');
    if (!isset($belgeTurleri[$tur]) || (!$belgeTam && (int) ($_POST['kaynak_belge_id'] ?? 0) > 0)) {
        lumen_hata_sayfasi('Yetkiniz yok', 'Bu belge türü için M31 (Faturalama) yetkisi gerekir.', 403, 'fa-lock');
    }
    $cari = (int) ($_POST['cari_id'] ?? 0);
    // Özel cari kısıtı: gizlenen cariye belge kesilemez.
    if ($cari > 0 && !m_p_cariid_goruntulebilir_mi($dbh, $firma, (int) $terminalkullanici, $cari)) {
        $git('yeni.php', ['tur' => $tur, 'hata' => 'Cari bulunamadı.']);
    }
    $satirlar = [];
    $s = $_POST['satir'] ?? [];
    $sayi = is_array($s['stok_id'] ?? null) ? count($s['stok_id']) : 0;
    for ($i = 0; $i < $sayi; $i++) {
        $sayi_ = static fn(string $alan): float => lumen_tutar_coz($s[$alan][$i] ?? '0');
        $satirlar[] = [
            'stok_id' => (int) ($s['stok_id'][$i] ?? 0), 'birim_id' => (int) ($s['birim_id'][$i] ?? 0),
            'miktar' => $sayi_('miktar'), 'fiyat' => $sayi_('fiyat'), 'iskonto' => $sayi_('iskonto'), 'kdv' => $sayi_('kdv'),
            'aciklama' => (string) ($s['aciklama'][$i] ?? ''), 'kaynak_satir_id' => (int) ($s['kaynak_satir_id'][$i] ?? 0),
        ];
    }
    $r = lumen_belge_kaydet($dbh, $tur, [
        'cari_id' => $cari, 'tarih' => (string) ($_POST['tarih'] ?? date('Y-m-d')), 'vade' => (string) ($_POST['vade'] ?? ''),
        'depo_id' => (int) ($_POST['depo_id'] ?? 0), 'aciklama' => (string) ($_POST['aciklama'] ?? ''),
        'dokuman_no' => (string) ($_POST['dokuman_no'] ?? ''), 'kaynak_belge_id' => (int) ($_POST['kaynak_belge_id'] ?? 0),
    ], $satirlar, (int) $terminalkullanici);
    if ($r['ok']) {
        $git('goster.php', ['id' => $r['id'], 'ok' => $r['mesaj']]);
    }
    $kaynak = (int) ($_POST['kaynak_belge_id'] ?? 0);
    $git($kaynak > 0 ? 'siparisten.php' : 'yeni.php', $kaynak > 0 ? ['siparis' => $kaynak, 'hata' => $r['mesaj']] : ['tur' => $tur, 'hata' => $r['mesaj']]);
}

lumen_hata_sayfasi('Geçersiz istek', 'Bilinmeyen işlem.', 400);
