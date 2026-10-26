<?php
declare(strict_types=1);
require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/../includes/eticaret/kutu.php';
$durum=(string) ($_GET['durum'] ?? ''); $kanal=(int) ($_GET['kanal'] ?? 0); $ara=mb_substr(trim((string) ($_GET['ara'] ?? '')),0,150);
$sayfa=max(1,(int) ($_GET['sayfa'] ?? 1));
$kosul='K.FIRMA=:f'; $p=[':f'=>(int) $firmano];
if (isset(et_durumlar()[$durum])) { $kosul.=' AND S.DURUM=:d'; $p[':d']=$durum; }
if ($kanal>0) { $kosul.=' AND S.KANAL_ID=:k'; $p[':k']=$kanal; }
if ($ara!=='') { $kosul.=' AND S.HARICI_NO=:ara'; $p[':ara']=$ara; }
foreach (['bas'=>'>=','bit'=>'<='] as $alan=>$op) {
    $t=(string) ($_GET[$alan] ?? '');
    if (($dt=DateTimeImmutable::createFromFormat('!Y-m-d',$t)) && $dt->format('Y-m-d')===$t) { $kosul.=" AND CAST(S.ALINMA AS DATE) {$op} :{$alan}"; $p[':'.$alan]=$t; }
}
$ozel=m_p_ozel_cari_sql_filtresi($terminalkullanici,'ISNULL(S.CARI_ID,0)','et_liste');
if ($ozel['sql']!=='') { $kosul.=' AND '.$ozel['sql']; $p+=$ozel['params']; }
$from="FROM dbo.M_ET_SIPARIS S JOIN dbo.M_ET_KANAL K ON K.ID=S.KANAL_ID WHERE {$kosul}";
$toplam=(int) (et_tek($dbh,"SELECT COUNT(*) AS N {$from}",$p)['N'] ?? 0);
$sayfa=min($sayfa,max(1,(int) ceil($toplam/50))); $ofset=($sayfa-1)*50;
$s=$dbh->prepare("SELECT S.ID,S.HARICI_NO,S.PAKET_KIMLIGI,S.KAYNAK_DURUMU,S.DURUM,S.ALINMA,S.SON_HATA,S.NORMAL,K.AD {$from} ORDER BY S.ID DESC OFFSET {$ofset} ROWS FETCH NEXT 50 ROWS ONLY");
$s->execute($p); $satirlar=$s->fetchAll(PDO::FETCH_ASSOC);
et_basla('E-ticaret Sipariş Kutusu');
et_giris('Sipariş kutusu', 'Gelen siparişleri incele, eşlemeleri tamamla ve aktarılacak kayıtları onayla.', '<a class="btn btn-birincil" href="ice_aktar.php"><i class="fa-solid fa-plus" aria-hidden="true"></i>Dosya içe aktar</a>');
?>
<form class="kart et-bolum et-filtre" method="get" aria-label="Sipariş filtreleri">
<label class="alan">Durum <select name="durum"><option value="">Tümü</option><?php foreach (et_durumlar() as $k=>$ad): ?><option value="<?= bh($k) ?>" <?= $durum===$k ? 'selected' : '' ?>><?= bh($ad) ?></option><?php endforeach; ?></select></label>
<label class="alan">Kanal <select name="kanal"><option value="0">Tümü</option><?php foreach (et_kanallar($dbh) as $k): ?><option value="<?= (int) $k['ID'] ?>" <?= $kanal===(int) $k['ID'] ? 'selected' : '' ?>><?= bh($k['AD']) ?></option><?php endforeach; ?></select></label>
<label class="alan">Harici sipariş numarası <input name="ara" value="<?= bh($ara) ?>"></label>
<label class="alan">Alınma başlangıcı <input type="date" name="bas" value="<?= bh($_GET['bas'] ?? '') ?>"></label>
<label class="alan">Alınma bitişi <input type="date" name="bit" value="<?= bh($_GET['bit'] ?? '') ?>"></label><div class="et-eylemler"><a class="btn btn-ikincil" href="index.php">Temizle</a><button class="btn btn-ikincil"><i class="fa-solid fa-filter" aria-hidden="true"></i>Süz</button></div>
</form>
<section class="kart"><div class="et-tablo-baslik"><h2><?= $durum && isset(et_durumlar()[$durum]) ? bh(et_durumlar()[$durum]) : 'Tüm siparişler' ?></h2><span class="et-sayi"><?= $toplam ?> kayıt</span></div>
<?php if (!$satirlar): ?><div class="et-bos"><i class="fa-solid fa-inbox" aria-hidden="true"></i><h2>Gösterilecek sipariş yok</h2><p class="et-metin"><?= $ara || $kanal || $durum || !empty($_GET['bas']) || !empty($_GET['bit']) ? 'Filtrelerini değiştir veya yeni bir sipariş dosyası ekle.' : 'İlk sipariş dosyanı ekleyerek eşleme ve kontrol adımlarına başlayabilirsin.' ?></p><a class="btn btn-ikincil" href="ice_aktar.php"><i class="fa-solid fa-file-import" aria-hidden="true"></i>Dosya içe aktar</a></div><?php else: ?>
<div class="tablo-sar"><table class="liste kartla"><thead><tr><th>Sipariş / paket</th><th>Kanal</th><th>Müşteri</th><th>Durum</th><th class="sag">Toplam</th><th>Alınma</th></tr></thead><tbody>
<?php foreach ($satirlar as $r): $n=json_decode($r['NORMAL'],true); ?><tr><td><div><a class="et-kayit-link" href="detay.php?id=<?= (int) $r['ID'] ?>"><span><?= bh($r['HARICI_NO']) ?></span><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a><?php if ($r['PAKET_KIMLIGI'] !== ''): ?><span class="et-satir-alt">Paket <?= bh($r['PAKET_KIMLIGI']) ?></span><?php endif; ?></div></td><td><?= bh($r['AD']) ?></td><td><?= bh(is_scalar($n['musteri']['ad'] ?? '') ? ($n['musteri']['ad'] ?? '') : '') ?></td><td><div><?php et_rozet($r['DURUM']); ?><?php if ($r['KAYNAK_DURUMU']): ?><span class="et-satir-alt">Kaynak: <?= bh($r['KAYNAK_DURUMU']) ?></span><?php endif; ?><?php if ($r['SON_HATA']): ?><span class="et-satir-alt et-satir-notu"><?= bh($r['SON_HATA']) ?></span><?php endif; ?></div></td><td class="et-tablo-tutar"><?= isset($n['genel_toplam']) ? btl($n['genel_toplam']) : '—' ?></td><td><span class="et-kucuk"><?= bh(et_tarih($r['ALINMA'])) ?></span></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section><nav class="et-sayfalama" aria-label="Kutu sayfaları"><span><?= $toplam ?> kayıt · Sayfa <?= $sayfa ?></span><div class="et-eylemler">
<?php foreach ([$sayfa-1=>'Önceki',$sayfa+1=>'Sonraki'] as $n=>$ad): if ($n>=1 && $n<=max(1,ceil($toplam/50))): ?><a class="btn btn-ikincil" href="?<?= bh(http_build_query(array_replace($_GET,['sayfa'=>$n]))) ?>"><?= bh($ad) ?></a><?php endif; endforeach; ?></div></nav>
<?php et_bitir();
