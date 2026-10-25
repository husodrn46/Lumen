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
if ($hata) { echo '<p class="bildirim hata">' . bh($hata) . '</p>'; }
?>
<?php et_cari_ara_formu($id); ?>
<form method="post" class="kart kart-ic">
<?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($e['ID'] ?? 0) ?>"><input type="hidden" name="surum" value="<?= bh($e['SURUM_KOD'] ?? '') ?>">
<div class="izgara">
<label class="alan">Kanal adı <input name="ad" required maxlength="100" value="<?= bh($e['AD'] ?? '') ?>"></label>
<label class="alan">Tür <select name="tur"><?php foreach (['trendyol'=>'Trendyol','hepsiburada'=>'Hepsiburada','woocommerce'=>'WooCommerce','shopify'=>'Shopify','site'=>'Kendi sitesi','diger'=>'Diğer'] as $k=>$ad): ?><option value="<?= bh($k) ?>" <?= ($e['TUR'] ?? '') === $k ? 'selected' : '' ?>><?= bh($ad) ?></option><?php endforeach; ?></select></label>
<?php et_hedef_alanlari($dbh,$e); ?>
<label class="alan"><span>Aktif</span><input name="aktif" type="checkbox" <?= ($e['AKTIF'] ?? 1) ? 'checked' : '' ?>></label>
</div><p class="soluk">Cari, depo ve personeli birlikte seçin veya tümünü boş bırakın. Bu sürümde kanallara CSV/JSON dosyasıyla veri alınır.</p>
<button class="btn btn-birincil">Kaydet</button> <a class="btn btn-ikincil" href="kanallar.php">Yeni kanal</a>
</form>
<div class="kart tablo-sar"><table class="liste"><thead><tr><th>Kanal</th><th>Tür</th><th>Durum</th><th></th></tr></thead><tbody>
<?php foreach (et_kanallar($dbh) as $k): ?><tr><td><?= bh($k['AD']) ?></td><td><?= bh($k['TUR']) ?></td><td><?= $k['AKTIF'] ? 'Aktif' : 'Pasif' ?></td><td><a href="?id=<?= (int) $k['ID'] ?>">Düzenle</a></td></tr><?php endforeach; ?>
</tbody></table></div><?php belge_sayfa_bitir();
