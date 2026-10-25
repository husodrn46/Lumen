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
$s=$dbh->prepare("SELECT S.ID,S.HARICI_NO,S.DURUM,S.ALINMA,S.SON_HATA,S.NORMAL,K.AD {$from} ORDER BY S.ID DESC OFFSET {$ofset} ROWS FETCH NEXT 50 ROWS ONLY");
$s->execute($p); $satirlar=$s->fetchAll(PDO::FETCH_ASSOC);
et_basla('E-ticaret Sipariş Kutusu');
?>
<form class="kart kart-ic izgara" method="get">
<label class="alan">Durum <select name="durum"><option value="">Tümü</option><?php foreach (et_durumlar() as $k=>$ad): ?><option value="<?= bh($k) ?>" <?= $durum===$k ? 'selected' : '' ?>><?= bh($ad) ?></option><?php endforeach; ?></select></label>
<label class="alan">Kanal <select name="kanal"><option value="0">Tümü</option><?php foreach (et_kanallar($dbh) as $k): ?><option value="<?= (int) $k['ID'] ?>" <?= $kanal===(int) $k['ID'] ? 'selected' : '' ?>><?= bh($k['AD']) ?></option><?php endforeach; ?></select></label>
<label class="alan">Harici sipariş numarası <input name="ara" value="<?= bh($ara) ?>"></label>
<label class="alan">Alınma başlangıcı <input type="date" name="bas" value="<?= bh($_GET['bas'] ?? '') ?>"></label>
<label class="alan">Alınma bitişi <input type="date" name="bit" value="<?= bh($_GET['bit'] ?? '') ?>"></label><button class="btn btn-birincil">Süz</button>
</form>
<div class="kart tablo-sar"><table class="liste"><thead><tr><th>Sipariş</th><th>Kanal</th><th>Müşteri</th><th>Durum</th><th>Toplam</th><th>Alınma</th></tr></thead><tbody>
<?php foreach ($satirlar as $r): $n=json_decode($r['NORMAL'],true); ?><tr><td><a href="detay.php?id=<?= (int) $r['ID'] ?>"><?= bh($r['HARICI_NO']) ?></a></td><td><?= bh($r['AD']) ?></td><td><?= bh(is_scalar($n['musteri']['ad'] ?? '') ? ($n['musteri']['ad'] ?? '') : '') ?></td><td><span class="rozet"><?= bh(et_durumlar()[$r['DURUM']]) ?></span><?php if ($r['SON_HATA']): ?><div class="soluk"><?= bh($r['SON_HATA']) ?></div><?php endif; ?></td><td><?= isset($n['genel_toplam']) ? btl($n['genel_toplam']) : '—' ?></td><td><?= bh(et_tarih($r['ALINMA'])) ?></td></tr><?php endforeach; ?>
<?php if (!$satirlar): ?><tr><td colspan="6">Sipariş yok. CSV/JSON içe aktararak başlayın.</td></tr><?php endif; ?>
</tbody></table></div><nav class="kart kart-ic" aria-label="Kutu sayfaları"><?= $toplam ?> kayıt · Sayfa <?= $sayfa ?>
<?php foreach ([$sayfa-1=>'Önceki',$sayfa+1=>'Sonraki'] as $n=>$ad): if ($n>=1 && $n<=max(1,ceil($toplam/50))): ?><a class="btn btn-ikincil" href="?<?= bh(http_build_query(array_replace($_GET,['sayfa'=>$n]))) ?>"><?= bh($ad) ?></a><?php endif; endforeach; ?></nav>
<?php belge_sayfa_bitir();
