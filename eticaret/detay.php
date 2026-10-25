<?php
declare(strict_types=1);
require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/../includes/eticaret/aktar.php';
$id=(int) ($_GET['id'] ?? $_POST['id'] ?? 0); $hata='';
try {
    $r=et_siparis($dbh,$id,(int) $terminalkullanici);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        et_post();
        $surum=(string) ($_POST['surum'] ?? '');
        switch ($_POST['islem'] ?? '') {
            case 'kontrol': et_hazirla($dbh,$id,(int) $terminalkullanici,$surum,$_POST); break;
            case 'aktar': et_aktar($dbh,$id,(int) $terminalkullanici,$surum); break;
            case 'iptal': et_iptal($dbh,$id,(int) $terminalkullanici,$surum); break;
            default: throw new DomainException('Bilinmeyen işlem.');
        }
        header('Location: detay.php?id='.$id,true,303); exit;
    }
} catch (Throwable $e) { $hata=et_hata($e); if (isset($r)) { $r=et_siparis($dbh,$id,(int) $terminalkullanici); } }
if (!isset($r)) { lumen_hata_sayfasi('Sipariş bulunamadı',$hata,404); }
$arsiv=json_decode($r['ONAY_GOVDE'] ?? 'null',true);
if ($r['DURUM']==='aktarildi' && isset($arsiv['gorunum'])) {
    $v=['hatalar'=>[],'satirlar'=>$arsiv['gorunum'],'toplam'=>['genel_toplam'=>$arsiv['toplam']]];
} else { $v=et_kontrol($dbh,$r,(int) $terminalkullanici); }
$n=json_decode($r['NORMAL'],true);
et_basla('Sipariş '.$r['HARICI_NO']);
if ($hata) { echo '<p class="bildirim hata">'.bh($hata).'</p>'; }
?>
<div class="kart kart-ic"><span class="rozet"><?= bh(et_durumlar()[$r['DURUM']]) ?></span> <?= bh($r['KANAL_AD']) ?> · <?= bh($n['tarih'] ?? '') ?> · Kaynak toplamı: <?= btl($n['genel_toplam'] ?? 0) ?>
<p><a href="eslemeler.php?kanal=<?= (int) $r['KANAL_ID'] ?>">Kanalın ürün eşlemeleri</a></p></div>
<div class="et-adresler"><?php foreach (['musteri'=>'Müşteri','fatura'=>'Fatura bilgileri','teslimat'=>'Teslimat bilgileri'] as $k=>$ad): ?><section class="kart kart-ic"><h2><?= bh($ad) ?></h2><?php et_adres_goster($n[$k] ?? []); ?></section><?php endforeach; ?></div>
<?php if ($v['hatalar'] && !in_array($r['DURUM'],['aktarildi','iptal'],true)): ?><div class="bildirim hata"><ul><?php foreach ($v['hatalar'] as $e): ?><li><?= bh($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="kart tablo-sar"><table class="liste"><thead><tr><th>Harici ürün</th><th>Lumen stok</th><th>Miktar / birim</th><th>Kanal fiyatı</th><th>Lumen fiyatı / KDV</th><th>Depo stoku</th><th>Kontrol</th></tr></thead><tbody>
<?php foreach ($v['satirlar'] as $g): ?><tr><td><?= bh($g['sku'] ?: $g['barkod']) ?><div><?= bh($g['ad']) ?></div><a href="eslemeler.php?<?= bh(http_build_query(['kanal'=>$r['KANAL_ID'],'tur'=>$g['sku'] ? 'sku' : 'barkod','deger'=>$g['sku'] ?: $g['barkod'],'birim'=>$g['birim'],'q'=>$g['sku']])) ?>">Eşle</a></td><td><?= bh($g['stok']['code'] ?? 'Bulunamadı') ?></td><td><?= bmiktar($g['miktar']) ?> <?= bh($g['birim']) ?><?php if ($g['carpan']): ?><div>Ana birim çarpanı: <?= bmiktar($g['carpan']) ?></div><?php endif; ?></td><td><?= btl($g['fiyat']) ?> / %<?= bmiktar($g['kdv']) ?><div><?= $g['kdv_dahil'] ? 'KDV dahil' : 'KDV hariç' ?></div></td><td><?= $g['liste_fiyat'] !== null ? btl($g['liste_fiyat']) : '—' ?> / %<?= bmiktar($g['stok_kdv'] ?? 0) ?><div>Aktarılacak ana birim fiyatı: <?= $g['hedef_fiyat'] !== null ? bh(number_format($g['hedef_fiyat'],6,',','.')) : '—' ?></div></td><td><?= $g['eldeki'] !== null ? bmiktar($g['eldeki']) : '—' ?></td><td><?= bh($g['engel'] ?: 'Eşlendi') ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php if (!in_array($r['DURUM'],['aktarildi','iptal'],true)): ?>
<?php et_cari_ara_formu($id); ?>
<form method="post" class="kart kart-ic"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="surum" value="<?= bh($r['SURUM_KOD']) ?>"><input type="hidden" name="islem" value="kontrol">
<div class="izgara"><?php et_hedef_alanlari($dbh,$r); ?></div>
<?php if ($r['CAKISMA'] ?? false): ?><p><label><input type="checkbox" name="cakisma_onay" value="1" required> Değişmiş kaynak verisini denetim izinde inceledim; kutudaki orijinal içerikle devam etmeyi onaylıyorum.</label></p><?php endif; ?>
<p>Fatura/teslimat bilgileri ortak cari kartını değiştirmez. Kontrol satış siparişi oluşturmaz.</p><button class="btn btn-birincil">Seçimleri kaydet ve kontrol et</button></form>
<?php endif; ?>
<?php if ($r['DURUM']==='hazir' && !$v['hatalar'] && (int) m_p_yetki($terminalkullanici,'M1')===1): ?>
<form method="post" class="kart kart-ic"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="surum" value="<?= bh($r['SURUM_KOD']) ?>"><input type="hidden" name="islem" value="aktar">
<p>Kontrol edilen bütün kalemler ile <?= btl($v['toplam']['genel_toplam']) ?> tutarında satış siparişi oluşturulacak. Mevcut rezervasyon ayarı uygulanır.</p>
<label><input type="checkbox" required> Ürün, cari, depo, personel, fiyat ve KDV bilgilerini inceledim.</label><p><button class="btn btn-birincil">Satış siparişine aktar</button></p></form>
<?php endif; if ($r['DURUM']==='aktarildi'): ?><div class="bildirim ok">Aktarıldı: <?php if ($r['HEDEF_MOD']===(lumen_bagimsiz_mi() ? 'lumen' : 'logo') && $r['HEDEF_DONEM']===(lumen_bagimsiz_mi() ? 'LUMEN' : $firmadonem)): ?><a href="../siparis/lg_siparis.php?stokhareket=<?= (int) $r['FIS_ID'] ?>"><?= bh($r['FIS_NO']) ?></a><?php else: ?><?= bh($r['FIS_NO'].' · '.$r['HEDEF_DONEM']) ?><?php endif; ?> · Kimlik <?= (int) $r['FIS_ID'] ?> · İşlemi yapan kullanıcı <?= (int) $r['AKTARAN'] ?></div><?php endif; ?>
<?php if (!in_array($r['DURUM'],['aktarildi','iptal'],true)): ?><form method="post" class="kart kart-ic"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="surum" value="<?= bh($r['SURUM_KOD']) ?>"><input type="hidden" name="islem" value="iptal"><button class="btn btn-ikincil">Kutu kaydını iptal et</button></form><?php endif; ?>
<details class="kart kart-ic"><summary>Ham sipariş verisi</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= bh($r['HAM']) ?></pre></details>
<section class="kart kart-ic"><h2>Denetim izi</h2><p>Alınma: <?= bh(et_tarih($r['ALINMA'])) ?> · Aktarım: <?= bh(et_tarih($r['AKTARILMA'])) ?> · Deneme: <?= (int) $r['DENEME'] ?></p>
<?php $s=$dbh->prepare('SELECT TOP 100 * FROM dbo.M_ET_OLAY WHERE SIPARIS_ID=:i ORDER BY ID DESC');$s->execute([':i'=>$id]);foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $o): ?><p><?= bh(et_tarih($o['ZAMAN'])) ?> · Kullanıcı <?= (int) $o['KULLANICI'] ?> · <?= bh($o['MESAJ']) ?></p><?php if ($o['VERI']): ?><details><summary>Olay verisi</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= bh($o['VERI']) ?></pre></details><?php endif; endforeach; ?></section>
<?php belge_sayfa_bitir();
