<?php
declare(strict_types=1);
require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';
if ((int) m_p_yetki($terminalkullanici, 'M16') !== 1) { lumen_hata_sayfasi('Yetkiniz yok', 'Kanal yönetimi için M16 gerekiyor.', 403); }
$hata = ''; $id = (int) ($_GET['id'] ?? 0);
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        et_post();
        $id = et_kanal_kaydet($dbh, $_POST, (int) $terminalkullanici);
        header('Location: kanallar.php?' . http_build_query(['id' => $id, 'ok' => 'Kanal kaydedildi.']), true, 303); exit;
    }
    $e = $id ? et_kanal($dbh, $id) : [];
} catch (Throwable $ex) { $hata = et_hata($ex); $e = []; }
et_basla('Satış kanalları');
et_giris('Satış kanalları', 'Siparişlerin geldiği kanalları ve aktarımda kullanılacak varsayılanları yönet.');
if ($hata) { echo '<p class="bildirim hata">' . bh($hata) . '</p>'; }
?>
<div class="et-yonetim"><section class="kart et-bolum"><div class="et-bolum-baslik"><div><h2><?= $e ? 'Kanalı düzenle' : 'Yeni satış kanalı' ?></h2><p class="et-kucuk">Dosya içe aktarmak için erişim anahtarı gerekmez. Trendyol API bağlantısını kanalı kaydettikten sonra tanımlayabilirsin.</p></div><span class="et-ikon"><i class="fa-solid fa-store" aria-hidden="true"></i></span></div>
<?php et_cari_ara_formu($id); ?>
<form method="post">
<?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($e['ID'] ?? 0) ?>"><input type="hidden" name="surum" value="<?= bh($e['SURUM_KOD'] ?? '') ?>">
<div class="et-form-grid">
<label class="alan">Kanal adı <input name="ad" required maxlength="100" value="<?= bh($e['AD'] ?? '') ?>"></label>
<label class="alan">Kanal türü <select name="tur"><?php foreach (['trendyol','hepsiburada','woocommerce','shopify','site','diger'] as $tur): ?><option value="<?= bh($tur) ?>" <?= ($e['TUR'] ?? '') === $tur ? 'selected' : '' ?>><?= bh(et_kanal_turu($tur)) ?></option><?php endforeach; ?></select></label>
<?php et_hedef_alanlari($dbh,$e); ?>
<label class="alan kutu"><input name="aktif" type="checkbox" <?= ($e['AKTIF'] ?? 1) ? 'checked' : '' ?>>Kanal aktif</label>
</div><div class="et-not"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Cari, depo ve personeli birlikte seç veya tümünü boş bırak. Bu seçimler yeni siparişlere varsayılan olarak gelir.</span></div>
<div class="et-form-son"><a class="btn btn-ikincil" href="kanallar.php"><i class="fa-solid fa-plus" aria-hidden="true"></i>Yeni kanal</a><button class="btn btn-birincil"><i class="fa-solid fa-check" aria-hidden="true"></i>Kaydet</button></div>
</form></section>
<?php $kanallar=et_kanallar($dbh); ?><section class="kart"><div class="et-tablo-baslik"><h2>Tanımlı kanallar</h2><span class="et-sayi"><?= count($kanallar) ?> kanal</span></div>
<?php if (!$kanallar): ?><div class="et-bos"><i class="fa-solid fa-store" aria-hidden="true"></i><h2>Henüz kanal tanımlanmadı</h2><p class="et-kucuk">İlk kanalını formdan ekleyebilirsin.</p></div><?php else: ?><div class="tablo-sar"><table class="liste kartla"><thead><tr><th>Kanal</th><th>Durum</th><th>İşlem</th></tr></thead><tbody>
<?php foreach ($kanallar as $k): ?><tr><td><div><strong><?= bh($k['AD']) ?></strong><span class="et-satir-alt"><?= bh(et_kanal_turu($k['TUR'])) ?></span></div></td><td><span class="et-durum <?= $k['AKTIF'] ? 'et-durum-hazir' : '' ?>"><?= $k['AKTIF'] ? 'Aktif' : 'Pasif' ?></span></td><td><div class="et-eylemler">
<a class="btn btn-ikincil" href="?id=<?= (int) $k['ID'] ?>" aria-label="<?= bh($k['AD']) ?> kanalını düzenle"><i class="fa-solid fa-pen" aria-hidden="true"></i>Düzenle</a><a class="btn btn-ikincil" href="eslemeler.php?kanal=<?= (int) $k['ID'] ?>" aria-label="<?= bh($k['AD']) ?> ürün eşlemeleri"><i class="fa-solid fa-link" aria-hidden="true"></i>Eşlemeler</a>
<?php if ($k['TUR']==='trendyol'): ?><a class="btn btn-ikincil" href="baglanti.php?kanal=<?= (int) $k['ID'] ?>"><i class="fa-solid fa-plug" aria-hidden="true"></i>Bağlantı</a><a class="btn btn-birincil" href="al.php?kanal=<?= (int) $k['ID'] ?>"><i class="fa-solid fa-cloud-arrow-down" aria-hidden="true"></i>Siparişleri al</a><?php endif; ?>
</div></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section></div><?php et_bitir();
