<?php

declare(strict_types=1);

/**
 * Ürün kartı ekleme / düzenleme (yalnız bağımsız mod): ad, grup, KDV, birimler ve çarpanlar,
 * barkodlar, satış ve alış fiyatı. Erişim: M11 (Yeni Stok) ya da yönetici; satış fiyatı ST2
 * (fiyat görme), alış fiyatı M31 (Faturalama) ister.
 */

include_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/../includes/belge/sayfa.php';
require_once __DIR__ . '/../includes/tanim/stok_kart_lib.php';

// İkinci savunma hattı: bu modül yalnız bağımsız modda çalışır (modül korumasından bağımsız).
if (!lumen_bagimsiz_mi()) {
    lumen_hata_sayfasi('Modül kullanılamıyor', 'Bu ekran yalnız bağımsız modda (Lumen veritabanı) kullanılır.', 501, 'fa-person-digging');
}
$yonetici = (int) $yetkidurum === 0;
if (!$yonetici && (int) m_p_yetki($terminalkullanici, 'M11') !== 1) {
    lumen_hata_sayfasi('Yetkiniz yok', 'Ürün kartı için M11 (Yeni Stok) yetkisi gerekir.', 403, 'fa-lock');
}
$izin = ['fiyat' => $yonetici || (int) m_p_yetki($terminalkullanici, 'ST2') === 1,
         'alis' => $yonetici || (int) m_p_yetki($terminalkullanici, 'M31') === 1];
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        lumen_hata_sayfasi('Geçersiz istek', 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.', 403);
    }
    $r = lumen_stok_kart_kaydet($dbh, $id, $_POST, $izin);
    if (function_exists('app_log_write')) {
        app_log_write('stok-kart', ['id' => $r['id'] ?? $id, 'yeni' => $id === 0, 'personel' => (int) $terminalkullanici, 'sonuc' => $r['ok']]);
    }
    if ($r['ok']) {
        header('Location: stok.php?' . http_build_query(['id' => $r['id'], 'ok' => $r['mesaj']]), true, 303);
        exit;
    }
    $hataMesaj = $r['mesaj'];
}

$d = $id > 0 ? lumen_stok_kart_oku($dbh, $id) : null;
if ($id > 0 && $d === null) {
    lumen_hata_sayfasi('Ürün bulunamadı', 'Bu stok kartı yok.', 404);
}
$birimler = $dbh->query('SELECT ID, KOD, AD FROM dbo.LM_BIRIM ORDER BY KOD')->fetchAll(PDO::FETCH_ASSOC);
$k = $d['kart'] ?? [];
// Hatalı gönderimde girilenler korunur
$deger = static fn(string $alan, mixed $varsayilan = '') => isset($hataMesaj) ? ($_POST[$alan] ?? $varsayilan) : $varsayilan;
$adet = 0;
foreach ($birimler as $b) {
    if ($b['KOD'] === 'ADET') {
        $adet = (int) $b['ID'];
    }
}
$ekBirimler = isset($hataMesaj)
    ? array_map(null, (array) ($_POST['birim_id'] ?? []), (array) ($_POST['carpan'] ?? []))
    : array_map(static fn($b) => [(int) $b['BIRIM_ID'], rtrim(rtrim(number_format((float) $b['CARPAN'], 6, ',', ''), '0'), ',')], $d['birimler'] ?? []);
$barkodlar = isset($hataMesaj)
    ? array_map(null, (array) ($_POST['barkod'] ?? []), (array) ($_POST['barkod_birim'] ?? []))
    : array_map(static fn($b) => [$b['BARKOD'], (int) $b['BIRIM_ID']], $d['barkodlar'] ?? []);
$ekBirimler[] = [0, ''];
$ekBirimler[] = [0, ''];
$barkodlar[] = ['', 0];
$barkodlar[] = ['', 0];
$fiyatYaz = static fn(?float $f): string => $f === null ? '' : number_format($f, 2, ',', '');
$anaBirim = (int) ($k['ANA_BIRIM_ID'] ?? $deger('ana_birim_id', $adet));
$kdvSecenek = [0, 1, 10, 20];
$kdv = (float) $deger('kdv', $k['KDV_ORANI'] ?? 20);
if (!in_array((int) $kdv, $kdvSecenek, true) || (float) (int) $kdv !== $kdv) {
    $kdvSecenek[] = $kdv;
}
$birimSecim = static function (string $ad, int $secili, bool $bos = true) use ($birimler): string {
    $h = '<select name="' . $ad . '">' . ($bos ? '<option value="0">—</option>' : '');
    foreach ($birimler as $b) {
        $h .= '<option value="' . (int) $b['ID'] . '"' . ((int) $b['ID'] === $secili ? ' selected' : '') . '>' . bh($b['KOD']) . '</option>';
    }
    return $h . '</select>';
};

belge_sayfa_basla($d ? 'Düzenle: ' . $k['KOD'] : 'Yeni ürün', 'fa-box', $d ? 'stok.php?id=' . $id : '../index.php');
if (isset($hataMesaj)) {
    echo '<div class="bildirim hata" role="alert"><i class="fa-solid fa-circle-exclamation"></i>' . bh($hataMesaj) . '</div>';
}
?>
<style>
    .satirlar { display: grid; gap: 8px; }
    .satirlar .s { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .not { font-size: 12px; color: var(--text-2); margin-top: 6px; }
    label.alan.kutu { flex-direction: row; align-items: center; gap: 8px; font-size: 13px; }
    label.alan.kutu input { width: auto; flex: 0 0 auto; }
</style>
<form method="post" action="stok_duzenle.php">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="kart kart-ic izgara">
        <label class="alan">Ürün kodu
            <?php if ($d): ?><input value="<?= bh($k['KOD']) ?>" disabled>
            <?php else: ?><input name="kod" required maxlength="40" value="<?= bh($deger('kod')) ?>" autofocus style="text-transform:uppercase"><?php endif; ?></label>
        <label class="alan" style="grid-column:span 2">Ürün adı <input name="ad" required maxlength="200" value="<?= bh($deger('ad', $k['AD'] ?? '')) ?>"></label>
        <label class="alan">Ana birim
            <?php if ($d): ?><input value="<?= bh($k['ANA_BIRIM']) ?>" disabled>
            <?php else: ?><?= $birimSecim('ana_birim_id', $anaBirim, false) ?><?php endif; ?></label>
        <label class="alan">KDV %
            <select name="kdv"><?php foreach ($kdvSecenek as $o): ?><option value="<?= bh((string) $o) ?>" <?= (float) $o === $kdv ? 'selected' : '' ?>><?= bh(bmiktar($o)) ?></option><?php endforeach; ?></select></label>
        <label class="alan">Grup <input name="grup" maxlength="40" value="<?= bh($deger('grup', $k['GRUP'] ?? '')) ?>"></label>
        <label class="alan">Özel kod <input name="ozel_kod" maxlength="40" value="<?= bh($deger('ozel_kod', $k['OZEL_KOD'] ?? '')) ?>"></label>
        <label class="alan kutu">
            <input type="checkbox" name="hizmet" value="1" <?= $deger('hizmet', ($k['STOK_TAKIPLI'] ?? 1) == 0 ? '1' : '') ? 'checked' : '' ?> <?= !empty($d['hareketli']) ? 'disabled' : '' ?>>
            Hizmet (stok tutulmaz)</label>
        <?php if (!empty($d['hareketli']) && (int) $k['STOK_TAKIPLI'] === 0): ?><input type="hidden" name="hizmet" value="1"><?php endif; ?>
        <?php if ($d): ?>
        <label class="alan kutu">
            <input type="checkbox" name="aktif" value="1" <?= $deger('aktif', (int) $k['AKTIF'] === 1 ? '1' : '') ? 'checked' : '' ?>> Aktif (pasif ürün belgelerde aranmaz)</label>
        <?php endif; ?>
    </div>

    <?php if ($izin['fiyat'] || $izin['alis']): ?>
    <div class="kart kart-ic">
        <div class="kart-baslik" style="padding:0 0 10px"><i class="fa-solid fa-tags"></i>Fiyat (ana birim, KDV hariç, TL)</div>
        <div class="izgara">
            <?php if ($izin['fiyat']): ?><label class="alan">Satış fiyatı <input name="satis" inputmode="decimal" placeholder="0,00" value="<?= bh($deger('satis', $fiyatYaz($d['satis'] ?? null))) ?>"></label><?php endif; ?>
            <?php if ($izin['alis']): ?><label class="alan">Alış fiyatı <input name="alis" inputmode="decimal" placeholder="0,00" value="<?= bh($deger('alis', $fiyatYaz($d['alis'] ?? null))) ?>"></label><?php endif; ?>
        </div>
        <div class="not">Fiyatı değiştirirseniz bugünden geçerli olur; eski fiyat geçmişte kalır. Boş bırakılan fiyat değişmez.</div>
    </div>
    <?php endif; ?>

    <div class="ikili" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px">
        <div class="kart kart-ic">
            <div class="kart-baslik" style="padding:0 0 10px"><i class="fa-solid fa-ruler"></i>Diğer birimler</div>
            <div class="satirlar">
                <?php foreach ($ekBirimler as [$b, $c]): ?>
                <div class="s"><?= $birimSecim('birim_id[]', (int) $b) ?><input name="carpan[]" inputmode="decimal" placeholder="çarpan" value="<?= bh((string) $c) ?>"></div>
                <?php endforeach; ?>
            </div>
            <div class="not">Çarpan: bir birimde kaç ana birim var (1 KOLİ = 24 ADET ise 24). Satırı boşaltmak birimi kaldırır; belgelerde kullanılmış birim kaldırılamaz. Listede olmayan birimi Tanımlar → Birimler'den ekleyin.</div>
        </div>
        <div class="kart kart-ic">
            <div class="kart-baslik" style="padding:0 0 10px"><i class="fa-solid fa-barcode"></i>Barkodlar</div>
            <div class="satirlar">
                <?php foreach ($barkodlar as [$bk, $bb]): ?>
                <div class="s"><input name="barkod[]" maxlength="60" placeholder="barkod" value="<?= bh((string) $bk) ?>"><?= $birimSecim('barkod_birim[]', (int) $bb) ?></div>
                <?php endforeach; ?>
            </div>
            <div class="not">Birim boşsa barkod ana birime aittir; koli barkodunun yanında KOLİ'yi seçin. Daha fazla satır için kaydedip yeniden açın.</div>
        </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px">
        <a href="<?= $d ? 'stok.php?id=' . $id : '../index.php' ?>" class="btn btn-ikincil">Vazgeç</a>
        <button type="submit" class="btn btn-birincil"><i class="fa-solid fa-check"></i> Kaydet</button>
    </div>
</form>
<?php belge_sayfa_bitir();
