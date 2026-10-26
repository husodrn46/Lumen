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
if ($hata) { echo '<div class="bildirim hata" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span>'.bh($hata).'</span></div>'; }
?>
<div class="et-ozet"><div><div class="et-ozet-baslik"><h1><?= bh($r['HARICI_NO']) ?></h1><?php et_rozet($r['DURUM']); ?></div><div class="et-metin"><?= bh($r['KANAL_AD']) ?></div></div><div class="et-tutar"><span class="et-kucuk">Kaynak toplamı</span><strong><?= btl($n['genel_toplam'] ?? 0) ?></strong></div></div>
<section class="kart et-bolum"><div class="et-bolum-baslik"><h2>Sipariş bilgileri</h2><a class="btn btn-ikincil" href="eslemeler.php?kanal=<?= (int) $r['KANAL_ID'] ?>"><i class="fa-solid fa-link" aria-hidden="true"></i>Ürün eşlemeleri</a></div>
<dl class="et-bilgiler"><div><dt>Sipariş tarihi</dt><dd><?= bh($n['tarih'] ?? '—') ?></dd></div><div><dt>Paket kimliği</dt><dd><?= bh(($r['PAKET_KIMLIGI'] ?? '') ?: '—') ?></dd></div><div><dt>Pazaryeri durumu</dt><dd><?= bh(($r['KAYNAK_DURUMU'] ?? '') ?: '—') ?></dd></div><div><dt>Kaynak güncellemesi (UTC)</dt><dd><?= bh(et_tarih($r['KAYNAK_GUNCELLEME'] ?? null)) ?></dd></div></dl></section>
<?php if (($r['KAYNAK'] ?? '')==='hepsiburada' && !in_array($r['DURUM'],['aktarildi','iptal'],true)): ?><div class="et-not"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Bu kayıt alındığı andaki Hepsiburada paket bilgisidir. Ayrı iptal/iade servisleri izlenmez. Aktarmadan önce Hepsiburada panelinde paketin güncelliğini kontrol et.</span></div><?php endif; ?>
<?php if ($r['KAYNAK_ENGELI'] ?? ''): ?><div class="bildirim hata" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= bh($r['KAYNAK_ENGELI']) ?><?= $r['DURUM']==='aktarildi' ? ' Lumen siparişi otomatik değiştirilmedi; ayrıca incelenmeli.' : '' ?></span></div><?php endif; ?>
<?php if ($r['DURUM']==='aktarildi'): ?><div class="bildirim ok" role="status"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><div><strong>Satış siparişine aktarıldı</strong><div><?= bh($r['FIS_NO']) ?> · Kimlik <?= (int) $r['FIS_ID'] ?> · İşlemi yapan kullanıcı <?= (int) $r['AKTARAN'] ?></div></div><?php if ($r['HEDEF_MOD']===(lumen_bagimsiz_mi() ? 'lumen' : 'logo') && $r['HEDEF_DONEM']===(lumen_bagimsiz_mi() ? 'LUMEN' : $firmadonem)): ?><a class="btn btn-ikincil" href="../siparis/lg_siparis.php?stokhareket=<?= (int) $r['FIS_ID'] ?>"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>Siparişi aç</a><?php else: ?><span><?= bh($r['HEDEF_DONEM']) ?></span><?php endif; ?></div><?php endif; ?>
<?php if ($v['hatalar'] && !in_array($r['DURUM'],['aktarildi','iptal'],true)): ?><div class="bildirim hata" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><div><strong>Aktarımdan önce tamamlanmalı</strong><ul><?php foreach ($v['hatalar'] as $e): ?><li><?= bh($e) ?></li><?php endforeach; ?></ul></div></div><?php endif; ?>
<section class="kart"><div class="et-tablo-baslik"><h2>Ürün ve fiyat kontrolü</h2><span class="et-sayi"><?= count($v['satirlar']) ?> kalem</span></div><div class="tablo-sar"><table class="liste kartla"><thead><tr><th>Harici ürün</th><th>Lumen stok</th><th>Miktar / birim</th><th>Kanal fiyatı</th><th>Lumen fiyatı / KDV</th><th>Depo stoku</th><th>Kontrol</th></tr></thead><tbody>
<?php foreach ($v['satirlar'] as $g): ?><tr><td><div><strong><?= bh($g['sku'] ?: $g['barkod']) ?></strong><span class="et-satir-alt"><?= bh($g['ad']) ?></span><a class="btn btn-ikincil" href="eslemeler.php?<?= bh(http_build_query(['kanal'=>$r['KANAL_ID'],'tur'=>$g['sku'] ? 'sku' : 'barkod','deger'=>$g['sku'] ?: $g['barkod'],'birim'=>$g['birim'],'q'=>$g['sku']])) ?>"><i class="fa-solid fa-link" aria-hidden="true"></i>Eşle</a></div></td><td><strong><?= bh($g['stok']['code'] ?? 'Bulunamadı') ?></strong></td><td><div><?= bmiktar($g['miktar']) ?> <?= bh($g['birim']) ?><?php if ($g['carpan']): ?><span class="et-satir-alt">Ana birim çarpanı: <?= bmiktar($g['carpan']) ?></span><?php endif; ?></div></td><td><div><?= btl($g['fiyat']) ?><span class="et-satir-alt">%<?= bmiktar($g['kdv']) ?> · <?= $g['kdv_dahil'] ? 'KDV dahil' : 'KDV hariç' ?></span></div></td><td><div><?= $g['liste_fiyat'] !== null ? btl($g['liste_fiyat']) : '—' ?> / %<?= bmiktar($g['stok_kdv'] ?? 0) ?><span class="et-satir-alt">Aktarılacak ana birim fiyatı: <?= $g['hedef_fiyat'] !== null ? bh(number_format($g['hedef_fiyat'],6,',','.')) : '—' ?></span></div></td><td><?= $g['eldeki'] !== null ? bmiktar($g['eldeki']) : '—' ?></td><td><span class="<?= $g['engel'] ? 'eksi' : 'arti' ?>"><?= bh($g['engel'] ?: 'Eşlendi') ?></span></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php $aktarilabilir=$r['DURUM']==='hazir' && !$v['hatalar'] && (int) m_p_yetki($terminalkullanici,'M1')===1; ?>
<div class="<?= $aktarilabilir ? 'et-iki-sutun' : '' ?>">
<?php if (!in_array($r['DURUM'],['aktarildi','iptal'],true)): ?>
<section class="kart et-bolum"><div class="et-bolum-baslik"><div><h2>Aktarım bilgileri</h2><p class="et-kucuk">Cari, depo ve personel seçimlerini kontrol et.</p></div></div>
<?php et_cari_ara_formu($id); ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="surum" value="<?= bh($r['SURUM_KOD']) ?>"><input type="hidden" name="islem" value="kontrol">
<div class="et-form-grid"><?php et_hedef_alanlari($dbh,$r); ?></div>
<?php if ($r['CAKISMA'] ?? false): ?><p class="bildirim uyari"><label class="et-secim"><input type="checkbox" name="cakisma_onay" value="1" required> Değişmiş kaynak verisini denetim izinde inceledim; kutudaki orijinal içerikle devam etmeyi onaylıyorum.</label></p><?php endif; ?>
<div class="et-form-son"><span class="et-kucuk">Kontrol satış siparişi oluşturmaz. Fatura ve teslimat bilgileri ortak cari kartını değiştirmez.</span><button class="btn btn-<?= $aktarilabilir ? 'ikincil' : 'birincil' ?>"><i class="fa-solid fa-list-check" aria-hidden="true"></i>Seçimleri kaydet ve kontrol et</button></div></form></section>
<?php endif; ?>
<?php if ($aktarilabilir): ?>
<form method="post" class="kart et-bolum et-aktarim"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="surum" value="<?= bh($r['SURUM_KOD']) ?>"><input type="hidden" name="islem" value="aktar">
<h2>Aktarım için hazır</h2><p class="et-metin">Kontrol edilen bütün kalemler ile <?= btl($v['toplam']['genel_toplam']) ?> tutarında satış siparişi oluşturulacak.</p><p class="et-kucuk">Mevcut rezervasyon ayarı uygulanır.</p>
<label class="et-secim"><input type="checkbox" required> Ürün, cari, depo, personel, fiyat ve KDV bilgilerini inceledim.</label><button class="btn btn-birincil"><i class="fa-solid fa-arrow-right-arrow-left" aria-hidden="true"></i>Satış siparişine aktar</button></form>
<?php endif; ?></div>
<?php if (isset($n['kaynak_bilgileri']['finans'])): ?><section class="kart et-bolum"><h2>Pazaryeri tutar bileşenleri</h2><p class="et-kucuk">İndirimli fiyat aktarılır; indirim tekrar uygulanmaz. Komisyon ve kargo mutabakatı ayrıca yapılır.</p><dl class="et-finans"><?php foreach ($n['kaynak_bilgileri']['finans'] as $ad=>$tutar): ?><div><dt><?= bh($ad) ?></dt><dd><?= btl($tutar) ?></dd></div><?php endforeach; ?></dl></section><?php endif; ?>
<div class="et-adresler"><?php foreach (['musteri'=>'Müşteri','fatura'=>'Fatura bilgileri','teslimat'=>'Teslimat bilgileri'] as $k=>$ad): ?><section class="kart et-bolum"><h2><?= bh($ad) ?></h2><?php if (empty($n[$k])): ?><p class="et-kucuk">Kaynakta bilgi yok.</p><?php else: et_adres_goster($n[$k]); endif; ?></section><?php endforeach; ?></div>
<?php if (!in_array($r['DURUM'],['aktarildi','iptal'],true)): ?><form method="post" class="et-tehlike"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="surum" value="<?= bh($r['SURUM_KOD']) ?>"><input type="hidden" name="islem" value="iptal"><span class="et-kucuk">Bu kayıt satış siparişine dönüştürülmeyecekse iptal edebilirsin.</span><button class="btn btn-tehlike"><i class="fa-solid fa-ban" aria-hidden="true"></i>Kutu kaydını iptal et</button></form><?php endif; ?>
<details class="kart et-katla"><summary>Ham sipariş verisi</summary><div class="et-katla-ic"><pre><?= bh($r['HAM']) ?></pre></div></details>
<details class="kart et-katla"><summary>Denetim izi</summary><div class="et-katla-ic"><dl class="et-bilgiler"><div><dt>Alınma</dt><dd><?= bh(et_tarih($r['ALINMA'])) ?></dd></div><div><dt>Aktarım</dt><dd><?= bh(et_tarih($r['AKTARILMA'])) ?></dd></div><div><dt>Deneme sayısı</dt><dd><?= (int) $r['DENEME'] ?></dd></div></dl><ol class="et-olaylar">
<?php $s=$dbh->prepare('SELECT TOP 100 * FROM dbo.M_ET_OLAY WHERE SIPARIS_ID=:i ORDER BY ID DESC');$s->execute([':i'=>$id]);foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $o): ?><li><div class="et-kucuk"><?= bh(et_tarih($o['ZAMAN'])) ?> · Kullanıcı <?= (int) $o['KULLANICI'] ?></div><p><?= bh($o['MESAJ']) ?></p><?php if ($o['VERI']): ?><details><summary>Olay verisi</summary><pre><?= bh($o['VERI']) ?></pre></details><?php endif; ?></li><?php endforeach; ?></ol></div></details>
<?php et_bitir();
