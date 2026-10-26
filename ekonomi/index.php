<?php
declare(strict_types=1);
require_once __DIR__.'/../ayr.php';
require_once __DIR__.'/../kontrol.php';
require_once __DIR__.'/_ortak.php';
$hata='';$sonuc=null;$hazir=ek_hazir_seriler();
$form=$_SESSION['evds_form'] ?? ['secim'=>'TP.DK.USD.S.YTL','seri'=>'','bas'=>date('Y-m-01',strtotime('-1 year')),'bit'=>date('Y-m-d')];
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        ek_post();$islem=ek_alan('islem');
        if ($islem==='csv') {
            $sonuc=ek_sonuc($dbh,(int) $firmano,(int) $terminalkullanici);
            if (!$sonuc) { throw new DomainException('Önce bir veri sorgusu veya sentetik örnek açın.'); }
            header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="lumen-ekonomik-gostergeler.csv"');
            $cikti=fopen('php://output','wb');fwrite($cikti,"\xEF\xBB\xBF");evds_csv_yaz($cikti,$sonuc);fclose($cikti);exit;
        }
        if ($islem==='ornek') { $_SESSION['evds_sonuc']=['ornek'=>true]; }
        elseif ($islem==='getir') {
            $form=['secim'=>ek_alan('secim'),'seri'=>ek_alan('seri'),'bas'=>ek_alan('bas'),'bit'=>ek_alan('bit')];
            if (!isset($hazir[$form['secim']]) && $form['secim']!=='ozel') { throw new DomainException('Bir gösterge seçin.'); }
            $q=evds_sorgu($form['secim']==='ozel' ? $form['seri'] : $form['secim'],$form['bas'],$form['bit']);
            $sonuc=evds_getir($dbh,(int) $firmano,(int) $terminalkullanici,$q);
            $_SESSION['evds_sonuc']=['ozet'=>evds_sorgu_ozeti($q)];$_SESSION['evds_form']=$form;
        } else { throw new DomainException('Bilinmeyen gösterge işlemi.'); }
        header('Location: index.php',true,303);exit;
    }
    $sonuc=ek_sonuc($dbh,(int) $firmano,(int) $terminalkullanici);
} catch (Throwable $e) { $hata=ek_hata($e); }
$bag=evds_baglanti($dbh,(int) $firmano,(int) $terminalkullanici);
ek_basla('Ekonomik Göstergeler');
?>
<div class="ek-giris"><div><h1>Ekonomik göstergeler</h1><p>TCMB EVDS zaman serilerini kaynak ve dönem bilgisiyle incele.</p></div><a class="btn btn-ikincil" href="baglanti.php"><i class="fa-solid fa-key" aria-hidden="true"></i><?= $bag ? 'Kişisel bağlantım' : 'API anahtarını tanımla' ?></a></div>
<?php if ($hata): ?><div class="bildirim hata" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= bh($hata) ?></span></div><?php endif; ?>
<div class="ek-calisma"><aside><details class="kart ek-filtre" id="ek-filtre" open><summary><span>Gösterge ve dönem</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary><div class="ek-bolum"><form method="post" class="ek-sorgu"><?= csrf_field() ?><input type="hidden" name="islem" value="getir">
<label class="alan">Gösterge<select name="secim" id="ek-secim"><?php foreach ($hazir+['ozel'=>'Diğer EVDS serisi'] as $kod=>$ad): ?><option value="<?= bh($kod) ?>" <?= $form['secim']===$kod ? 'selected' : '' ?>><?= bh($ad) ?></option><?php endforeach; ?></select></label>
<label class="alan" id="ek-ozel">EVDS seri kodu<input name="seri" value="<?= bh($form['seri']) ?>" maxlength="100" placeholder="TP.…"><span class="alan-ipucu">Enflasyon, faiz veya başka bir serinin kodunu EVDS'den kopyala.</span></label>
<label class="alan">Başlangıç<input type="date" name="bas" value="<?= bh($form['bas']) ?>" min="1950-01-01" max="<?= date('Y-m-d') ?>" required></label>
<label class="alan">Bitiş<input type="date" name="bit" value="<?= bh($form['bit']) ?>" min="1950-01-01" max="<?= date('Y-m-d') ?>" required></label><button class="btn btn-birincil" <?= !$bag ? 'disabled' : '' ?>><i class="fa-solid fa-chart-line" aria-hidden="true"></i>Veriyi getir</button>
<p class="ek-kucuk"><?= $bag ? 'Aynı sorgu gün boyunca önbellekten okunur. Orijinal seri sıklığı korunur.' : 'Gerçek veri almak için önce kişisel API anahtarını tanımla.' ?></p></form></div>
<section class="ek-yardim"><h3>Önce bir örneğe bak</h3><p class="ek-kucuk">API anahtarı olmadan ekranı sentetik verilerle inceleyebilirsin.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="islem" value="ornek"><button class="btn btn-ikincil"><i class="fa-solid fa-flask" aria-hidden="true"></i>Sentetik örneği göster</button></form><a class="ek-baglanti" href="https://evds3.tcmb.gov.tr/tumSeriler" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>EVDS'de seri bul</a></section></details></aside>
<div class="ek-sonuc">
<?php if (!$sonuc): ?><section class="kart ek-ilk-durum"><span class="ek-ikon"><i class="fa-solid fa-chart-area" aria-hidden="true"></i></span><h2>Bir göstergeyle başla</h2><p>Gösterge ve tarih aralığını seçtiğinde seri grafiği, dönem değişimi ve gözlem tablosu burada görünür.</p><div class="ek-ilk-alt"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Bu modül muhasebe kayıtlarını veya tanımlı döviz kurlarını değiştirmez.</span></div></section>
<?php else: $v=$sonuc['veri'];$o=$v['ozet'];$ornek=!empty($sonuc['ornek']); ?>
<?php if ($ornek): ?><div class="bildirim uyari" role="status"><i class="fa-solid fa-flask" aria-hidden="true"></i><span><strong>Sentetik örnek</strong> · Bu değerler TCMB verisi değildir.</span></div><?php elseif ($sonuc['eski'] || $sonuc['uyari']): ?><div class="bildirim uyari" role="status"><i class="fa-solid fa-clock" aria-hidden="true"></i><span><strong>Eski önbellek gösteriliyor.</strong> <?= bh($sonuc['uyari'] ?: 'Bugün henüz yeni veri alınmadı; yeniden sorgulayabilirsiniz.') ?></span></div><?php endif; ?>
<section class="kart"><div class="ek-sonuc-baslik"><div><h2><?= bh($v['meta']['ad']) ?></h2><p class="ek-kucuk"><?= bh($v['meta']['kod']) ?> · <?= bh($v['meta']['siklik'] ?: 'Orijinal sıklık') ?></p></div><form method="post"><?= csrf_field() ?><input type="hidden" name="islem" value="csv"><button class="btn btn-ikincil"><i class="fa-solid fa-download" aria-hidden="true"></i>CSV indir</button></form></div>
<dl class="ek-olcumler"><div><dt>İlk dolu gözlem</dt><dd><?= evds_sayi($o['ilk']['deger'] ?? null) ?></dd><small><?= bh($o['ilk']['donem'] ?? 'Gözlem yok') ?></small></div><div><dt>Son dolu gözlem</dt><dd><?= evds_sayi($o['son']['deger'] ?? null) ?></dd><small><?= bh($o['son']['donem'] ?? 'Gözlem yok') ?></small></div><div><dt>Dönem değişimi</dt><dd><?= $o['degisim']!==null ? '%'.evds_sayi($o['degisim']) : '—' ?></dd><small><?= (int) $o['adet'] ?> dolu · <?= (int) $o['eksik'] ?> eksik gözlem</small></div></dl>
<div class="ek-grafik-sar"><?= evds_grafik($v['satirlar']) ?></div><div class="ek-kaynak"><span>Kaynak: <?= bh($ornek ? 'Lumen sentetik örneği' : 'TCMB EVDS / '.($v['meta']['kaynak'] ?: 'EVDS serisi')) ?></span><span><?= $ornek ? 'Ocak–Haziran 2024 örnek dönemi' : 'Alınma: '.bh((new DateTimeImmutable($sonuc['alinma']))->format('d.m.Y H:i')) ?></span></div></section>
<p class="ek-aciklama">Değişim, ilk ve son dolu gözlem arasındadır; serinin resmî yıllık değişimiyle aynı olmayabilir. Eksik değerler doldurulmaz. Grafik ve tablo serinin kendi birimini kullanır.</p>
<?php $sayfa=max(1,(int) ($_GET['sayfa'] ?? 1));$sayfa=min($sayfa,max(1,(int) ceil(count($v['satirlar'])/25))); ?>
<section class="kart"><div class="ek-tablo-baslik"><h2>Gözlem tablosu</h2><span class="ek-kucuk"><?= count($v['satirlar']) ?> dönem</span></div><div class="tablo-sar"><table class="liste"><thead><tr><th>Dönem</th><th class="sag">Değer</th><th>Durum</th></tr></thead><tbody><?php foreach (array_slice($v['satirlar'],($sayfa-1)*25,25) as $r): ?><tr><td><?= bh($r['donem']) ?></td><td class="sag sayi"><?= evds_sayi($r['deger']) ?></td><td><span class="ek-kucuk"><?= $r['deger']===null ? 'Eksik gözlem' : 'Dolu gözlem' ?></span></td></tr><?php endforeach; ?><?php if (!$v['satirlar']): ?><tr><td colspan="3">Bu aralıkta gözlem bulunamadı.</td></tr><?php endif; ?></tbody></table></div></section>
<nav class="ek-sayfalama" aria-label="Gözlem sayfaları"><span>Sayfa <?= $sayfa ?></span><div class="ek-eylemler"><?php foreach ([$sayfa-1=>'Önceki',$sayfa+1=>'Sonraki'] as $s=>$ad): if ($s>=1 && $s<=ceil(count($v['satirlar'])/25)): ?><a class="btn btn-ikincil" href="?sayfa=<?= $s ?>"><?= bh($ad) ?></a><?php endif; endforeach; ?></div></nav>
<?php endif; ?></div></div>
<script>
(function(){var secim=document.getElementById('ek-secim'),alan=document.getElementById('ek-ozel');function guncelle(){alan.hidden=secim.value!=='ozel';alan.querySelector('input').required=secim.value==='ozel';}secim.addEventListener('change',guncelle);guncelle();var filtre=document.getElementById('ek-filtre'),mobil=window.matchMedia('(max-width:767px)'),sonucVar=<?= $sonuc ? 'true' : 'false' ?>;function duzenle(){filtre.open=!sonucVar||!mobil.matches;}mobil.addEventListener('change',duzenle);duzenle();})();
</script>
<?php ek_bitir();
