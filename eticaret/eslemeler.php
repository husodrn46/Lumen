<?php
declare(strict_types=1);
require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/../includes/eticaret/kutu.php';
$kanal=(int) ($_POST['kanal'] ?? $_GET['kanal'] ?? 0); $hata='';
try {
    $k=et_kanal($dbh,$kanal);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        et_post(); et_esleme_kaydet($dbh,$_POST,(int) $terminalkullanici);
        header('Location: eslemeler.php?'.http_build_query(['kanal'=>$kanal,'ok'=>'Eşleme kaydedildi.']),true,303); exit;
    }
} catch (Throwable $e) { $hata=et_hata($e); }
et_basla('Ürün eşlemeleri');
if ($hata) { echo '<p class="bildirim hata">'.bh($hata).'</p>'; }
if (!isset($k)) { et_bitir(); exit; }
et_giris('Ürün eşlemeleri', $k['AD'].' kanalındaki SKU veya barkodu Lumen stok kartıyla eşleştir.');
$q=mb_substr(trim((string) ($_GET['q'] ?? '')),0,100);
$s=$dbh->prepare("SELECT TOP 30 CODE,NAME,LOGICALREF FROM {$firma}ITEMS WHERE ACTIVE=0 AND (CODE LIKE :q OR NAME LIKE :ad) ORDER BY CODE");
$terim='%'.str_replace(['[','%','_'],['[[]','[%]','[_]'],$q).'%'; $s->execute([':q'=>$terim,':ad'=>$terim]); $stoklar=$s->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="et-yonetim"><section class="kart et-bolum"><div class="et-bolum-baslik"><div><h2>Eşleme ekle veya güncelle</h2><p class="et-kucuk">Yalnız bu kanaldaki siparişlere uygulanır.</p></div><span class="et-ikon"><i class="fa-solid fa-link" aria-hidden="true"></i></span></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="kanal" value="<?= $kanal ?>"><div class="et-form-grid">
<label class="alan">Tür <select name="tur"><option value="sku">SKU</option><option value="barkod" <?= ($_GET['tur'] ?? '')==='barkod' ? 'selected' : '' ?>>Barkod</option></select></label>
<label class="alan">Harici değer <input name="deger" required maxlength="150" value="<?= bh($_GET['deger'] ?? '') ?>"></label>
<label class="alan">Lumen stok kodu <input name="stok_kod" required maxlength="150" list="stoklar"><datalist id="stoklar"><?php foreach ($stoklar as $st): ?><option value="<?= bh($st['CODE']) ?>"><?= bh($st['NAME']) ?></option><?php endforeach; ?></datalist></label>
<label class="alan">Birim kodu <input name="birim" required maxlength="30" value="<?= bh($_GET['birim'] ?? '') ?>" placeholder="Örn. ADET"></label></div><div class="et-form-son"><span class="et-kucuk">Birim, kaynak siparişteki birimle aynı olmalı.</span><button class="btn btn-birincil"><i class="fa-solid fa-check" aria-hidden="true"></i>Eşlemeyi kaydet</button></div></form></section>
<section class="kart"><div class="et-bolum"><h2>Lumen stoklarında ara</h2><p class="et-kucuk">Stok kodunu ve kullanılabilir birimleri bul.</p></div>
<form method="get" class="et-arama et-bolum"><input type="hidden" name="kanal" value="<?= $kanal ?>"><?php foreach (['tur','deger','birim'] as $alan): if (isset($_GET[$alan])): ?><input type="hidden" name="<?= $alan ?>" value="<?= bh($_GET[$alan]) ?>"><?php endif; endforeach; ?><label class="alan">Stok kodu veya adı ara <input name="q" value="<?= bh($q) ?>" placeholder="Kod veya ürün adı"></label><button class="btn btn-ikincil"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>Ara</button></form>
<div class="tablo-sar"><table class="liste kartla"><thead><tr><th>Stok kodu</th><th>Ad</th><th>Birimler</th></tr></thead><tbody><?php foreach ($stoklar as $st): ?><tr><td><strong><?= bh($st['CODE']) ?></strong></td><td><?= bh($st['NAME']) ?></td><td><?= bh(implode(', ',array_map(static fn($b)=>$b['kod'].' ×'.$b['carpan'],et_birimler($dbh,$firma,(int) $st['LOGICALREF'])))) ?></td></tr><?php endforeach; ?><?php if (!$stoklar): ?><tr><td colspan="3">Bu aramada stok bulunamadı.</td></tr><?php endif; ?></tbody></table></div></section></div>
<?php
$sayfa=max(1,(int) ($_GET['sayfa'] ?? 1)); $ofset=($sayfa-1)*50;
$s=$dbh->prepare("SELECT E.*,S.CODE FROM dbo.M_ET_URUN_ESLEME E LEFT JOIN {$firma}ITEMS S ON S.LOGICALREF=E.STOK_ID WHERE E.KANAL_ID=:k ORDER BY E.ID DESC OFFSET {$ofset} ROWS FETCH NEXT 51 ROWS ONLY");
$s->execute([':k'=>$kanal]);$eslemeler=$s->fetchAll(PDO::FETCH_ASSOC);
?>
<section class="kart"><div class="et-tablo-baslik"><h2>Kayıtlı eşlemeler</h2></div><?php if (!$eslemeler): ?><div class="et-bos"><i class="fa-solid fa-link" aria-hidden="true"></i><h2>Henüz ürün eşlemesi yok</h2><p class="et-kucuk">İlk eşlemeyi yukarıdaki formdan kaydet.</p></div><?php else: ?><div class="tablo-sar"><table class="liste kartla"><thead><tr><th>Harici değer</th><th>Tür</th><th>Lumen stok</th><th>Birim</th></tr></thead><tbody><?php foreach (array_slice($eslemeler,0,50) as $e): ?><tr><td><strong><?= bh($e['DEGER']) ?></strong></td><td><?= $e['TUR']==='sku' ? 'SKU' : 'Barkod' ?></td><td><?= bh($e['CODE']) ?></td><td><?= bh($e['BIRIM']) ?> ×<?= bmiktar($e['CARPAN']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<nav class="et-sayfalama" aria-label="Eşleme sayfaları"><span>Sayfa <?= $sayfa ?></span><div class="et-eylemler"><?php foreach ([$sayfa-1=>'Önceki',$sayfa+1=>'Sonraki'] as $n=>$ad): if ($n>=1 && ($n<$sayfa || count($eslemeler)>50)): ?><a class="btn btn-ikincil" href="?<?= bh(http_build_query(['kanal'=>$kanal,'sayfa'=>$n,'q'=>$q])) ?>"><?= bh($ad) ?></a><?php endif; endforeach; ?></div></nav><?php et_bitir();
