<?php

declare(strict_types=1);

/**
 * Toplu fiyat güncelleme (yalnız bağımsız mod, M16): ürün grubu / kod başlangıcıyla seçilen
 * ürünlerin satış ya da alış fiyatı yüzde ya da tutar kadar değişir. Önce önizleme, sonra onay.
 */

include_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/../includes/belge/belge_lib.php';   // lumen_tarih_hatasi (dönem kilidi)
require_once __DIR__ . '/../includes/tanim/toplu_fiyat_lib.php';

$v = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
$islem = (string) ($v['islem'] ?? '');
$sonuc = null;
$liste = null;
if ($islem !== '') {
    if (!csrf_verify()) {
        lumen_hata_sayfasi('Geçersiz istek', 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.', 403);
    }
    $c = lumen_toplu_fiyat_coz($dbh, $v);
    if (!$c['ok']) {
        $sonuc = $c;
    } elseif ($islem === 'uygula') {
        $sonuc = lumen_toplu_fiyat_uygula($dbh, $c['f']);
        if (function_exists('app_log_write')) {
            app_log_write('ayar-degisiklik', ['ekran' => 'toplu_fiyat', 'filtre' => $c['f'], 'personel' => (int) $terminalkullanici,
                'sonuc' => $sonuc['ok'], 'sayi' => $sonuc['sayi'] ?? 0]);
        }
        if ($sonuc['ok']) {
            header('Location: toplu_fiyat.php?' . http_build_query(['ok' => $sonuc['mesaj']]), true, 303);
            exit;
        }
    } else {
        $liste = lumen_toplu_fiyat_hesapla($dbh, $c['f']);
    }
}
$gruplar = $dbh->query("SELECT DISTINCT GRUP FROM dbo.LM_STOK WHERE AKTIF = 1 AND GRUP IS NOT NULL AND GRUP <> '' ORDER BY GRUP")->fetchAll(PDO::FETCH_COLUMN);
$d = static fn(string $k, string $vars = ''): string => (string) ($v[$k] ?? $vars);
$sec = static fn(string $k, string $deger, string $vars): string => $d($k, $vars) === $deger ? 'selected' : '';

belge_sayfa_basla('Toplu fiyat güncelleme', 'fa-percent', 'index.php');
if ($sonuc !== null && !$sonuc['ok']) {
    echo '<div class="bildirim hata" role="alert"><i class="fa-solid fa-circle-exclamation"></i>' . bh($sonuc['mesaj']) . '</div>';
}
?>
<form method="post" action="toplu_fiyat.php" class="kart kart-ic">
    <?= csrf_field() ?>
    <div class="izgara">
        <label class="alan">Fiyat
            <select name="tur"><option value="1" <?= $sec('tur', '1', '1') ?>>Satış fiyatı</option><option value="2" <?= $sec('tur', '2', '1') ?>>Alış fiyatı</option></select></label>
        <label class="alan">Ürün grubu
            <select name="grup"><option value="">Tüm gruplar</option>
                <?php foreach ($gruplar as $g): ?><option value="<?= bh($g) ?>" <?= $sec('grup', (string) $g, '') ?>><?= bh($g) ?></option><?php endforeach; ?></select></label>
        <label class="alan">Kodu şununla başlayan <input name="kod" maxlength="40" value="<?= bh($d('kod')) ?>" placeholder="boş: hepsi"></label>
        <label class="alan">Değişiklik
            <select name="yontem"><option value="yuzde" <?= $sec('yontem', 'yuzde', 'yuzde') ?>>Yüzde (%)</option><option value="tutar" <?= $sec('yontem', 'tutar', 'yuzde') ?>>Tutar (₺)</option></select></label>
        <label class="alan">Miktar <input name="deger" inputmode="decimal" required value="<?= bh($d('deger')) ?>" placeholder="ör. 10 ya da -5"></label>
        <label class="alan">Yuvarla
            <select name="yuvarla">
                <?php foreach (['0' => 'Yuvarlama yok', '0.05' => '0,05 ₺', '0.1' => '0,10 ₺', '0.5' => '0,50 ₺', '1' => '1 ₺', '5' => '5 ₺', '10' => '10 ₺'] as $k => $a): ?>
                    <option value="<?= $k ?>" <?= $sec('yuvarla', (string) $k, '0') ?>><?= bh($a) ?></option><?php endforeach; ?></select></label>
        <label class="alan">Geçerlilik tarihi <input type="date" name="tarih" value="<?= bh($d('tarih', date('Y-m-d'))) ?>" required></label>
    </div>
    <p class="ipucu" style="font-size:12.5px;color:var(--text-2);margin-top:10px">Yalnız ana birimde TL fiyatı olan aktif ürünler değişir (KDV hariç fiyat). Eski fiyat silinmez; seçilen tarihten geçerli yeni fiyat yazılır, geçmiş belgeler etkilenmez.</p>
    <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-ikincil" type="submit" name="islem" value="onizle"><i class="fa-solid fa-eye"></i> Önizle</button>
        <?php if ($liste): ?>
            <button class="btn btn-birincil" type="submit" name="islem" value="uygula"><i class="fa-solid fa-check"></i> <?= count($liste) ?> ürünün fiyatını güncelle</button>
        <?php endif; ?>
    </div>
</form>
<?php if ($liste !== null): ?>
<div class="kart tablo-sar">
    <div class="kart-baslik"><i class="fa-solid fa-list"></i><?= $liste === [] ? 'Değişecek fiyat yok' : count($liste) . ' ürün değişecek' . (count($liste) > 200 ? ' (ilk 200 gösteriliyor)' : '') ?></div>
    <?php if ($liste): ?>
    <table class="liste">
        <thead><tr><th>Kod</th><th>Ürün</th><th class="sag">Şimdiki</th><th class="sag">Yeni</th><th class="sag">Fark</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($liste, 0, 200) as $r): ?>
            <tr><td><?= bh($r['kod']) ?></td><td><?= bh($r['ad']) ?></td><td class="sag"><?= btl($r['eski']) ?></td>
                <td class="sag koyu"><?= btl($r['yeni']) ?></td><td class="sag soluk"><?= btl($r['yeni'] - $r['eski']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php belge_sayfa_bitir();
