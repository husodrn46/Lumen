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
if (!isset($k)) { belge_sayfa_bitir(); exit; }
$q=mb_substr(trim((string) ($_GET['q'] ?? '')),0,100);
$s=$dbh->prepare("SELECT TOP 30 CODE,NAME,LOGICALREF FROM {$firma}ITEMS WHERE ACTIVE=0 AND (CODE LIKE :q OR NAME LIKE :ad) ORDER BY CODE");
$terim='%'.str_replace(['[','%','_'],['[[]','[%]','[_]'],$q).'%'; $s->execute([':q'=>$terim,':ad'=>$terim]); $stoklar=$s->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="kart kart-ic"><strong><?= bh($k['AD']) ?></strong><p>Eşlemeler yalnız bu kanala uygulanır. Birim, kaynak siparişteki birimle aynı olmalıdır.</p>
<form method="get"><input type="hidden" name="kanal" value="<?= $kanal ?>"><label class="alan">Stok kodu veya adı ara <input name="q" value="<?= bh($q) ?>"></label><button class="btn btn-ikincil">Ara</button></form></div>
<div class="kart tablo-sar"><table class="liste"><thead><tr><th>Stok kodu</th><th>Ad</th><th>Birimler</th></tr></thead><tbody><?php foreach ($stoklar as $st): ?><tr><td><?= bh($st['CODE']) ?></td><td><?= bh($st['NAME']) ?></td><td><?= bh(implode(', ',array_map(static fn($b)=>$b['kod'].' ×'.$b['carpan'],et_birimler($dbh,$firma,(int) $st['LOGICALREF'])))) ?></td></tr><?php endforeach; ?></tbody></table></div>
<form method="post" class="kart kart-ic izgara"><?= csrf_field() ?><input type="hidden" name="kanal" value="<?= $kanal ?>">
<label class="alan">Tür <select name="tur"><option value="sku">SKU</option><option value="barkod" <?= ($_GET['tur'] ?? '')==='barkod' ? 'selected' : '' ?>>Barkod</option></select></label>
<label class="alan">Harici değer <input name="deger" required maxlength="150" value="<?= bh($_GET['deger'] ?? '') ?>"></label>
<label class="alan">Lumen stok kodu <input name="stok_kod" required maxlength="150" list="stoklar"><datalist id="stoklar"><?php foreach ($stoklar as $st): ?><option value="<?= bh($st['CODE']) ?>"><?= bh($st['NAME']) ?></option><?php endforeach; ?></datalist></label>
<label class="alan">Birim kodu <input name="birim" required maxlength="30" value="<?= bh($_GET['birim'] ?? '') ?>"></label><button class="btn btn-birincil">Eşlemeyi kaydet</button></form>
<?php
$sayfa=max(1,(int) ($_GET['sayfa'] ?? 1)); $ofset=($sayfa-1)*50;
$s=$dbh->prepare("SELECT E.*,S.CODE FROM dbo.M_ET_URUN_ESLEME E LEFT JOIN {$firma}ITEMS S ON S.LOGICALREF=E.STOK_ID WHERE E.KANAL_ID=:k ORDER BY E.ID DESC OFFSET {$ofset} ROWS FETCH NEXT 51 ROWS ONLY");
$s->execute([':k'=>$kanal]);$eslemeler=$s->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="kart tablo-sar"><table class="liste"><thead><tr><th>Tür</th><th>Harici değer</th><th>Stok</th><th>Birim</th></tr></thead><tbody><?php foreach (array_slice($eslemeler,0,50) as $e): ?><tr><td><?= bh($e['TUR']) ?></td><td><?= bh($e['DEGER']) ?></td><td><?= bh($e['CODE']) ?></td><td><?= bh($e['BIRIM']) ?> ×<?= bmiktar($e['CARPAN']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php foreach ([$sayfa-1=>'Önceki',$sayfa+1=>'Sonraki'] as $n=>$ad): if ($n>=1 && ($n<$sayfa || count($eslemeler)>50)): ?><a class="btn btn-ikincil" href="?<?= bh(http_build_query(['kanal'=>$kanal,'sayfa'=>$n,'q'=>$q])) ?>"><?= bh($ad) ?></a><?php endif; endforeach; belge_sayfa_bitir();
