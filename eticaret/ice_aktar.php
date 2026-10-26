<?php
declare(strict_types=1);
require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/../includes/eticaret/ice_aktar.php';
if (isset($_GET['ornek']) && in_array($_GET['ornek'],['csv','json','trendyol_v2','hepsiburada_paket'],true)) {
    $ornek = $_GET['ornek']; $tur=in_array($ornek,['csv','json'],true) ? $ornek : 'json';
    $dosya=in_array($ornek,['csv','json'],true) ? 'ornek.'.$ornek : $ornek.'.json'; header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="lumen-sentetik-' . $ornek . '.' . $tur . '"');
    readfile(__DIR__ . '/../docs/eticaret/' . $dosya); exit;
}
$hata=''; $sonuc=null; $onizleme=null;
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        et_post();
        if (($_POST['islem'] ?? '') === 'yukle') {
            unset($_SESSION['et_onizleme']);
            $f = $_FILES['dosya'] ?? [];
            if (($f['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || ($f['size'] ?? 0) > 1048576) {
                throw new DomainException('En fazla 1 MB büyüklüğünde dosya seçin.');
            }
            $tur = strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
            $ham = file_get_contents($f['tmp_name']);
            $bicim=(string) ($_POST['bicim'] ?? 'lumen');
            $adayOnizleme = et_dosya_oku($ham,$tur,$bicim);
            $k = et_kanal($dbh,(int) ($_POST['kanal'] ?? 0));
            if (et_bicimler()[$bicim]['kanal']!==null && et_bicimler()[$bicim]['kanal']!==$k['TUR']) { throw new DomainException('Dosya biçimi kanal türüyle uyuşmuyor.'); }
            if (!$k['AKTIF']) { throw new DomainException('Aktif kanal seçin.'); }
            $onizleme=$adayOnizleme;
            $_SESSION['et_onizleme'] = ['kanal'=>(int) $k['ID'],'ham'=>$ham,'tur'=>$tur,'ad'=>$f['name'],'bicim'=>$bicim,'anahtar'=>bin2hex(random_bytes(16))];
        } elseif (($_POST['islem'] ?? '') === 'kaydet') {
            $v = $_SESSION['et_onizleme'] ?? null;
            if (!$v || !hash_equals($v['anahtar'],(string) ($_POST['anahtar'] ?? ''))) { throw new DomainException('Önizleme süresi doldu; dosyayı yeniden seçin.'); }
            $sonuc = et_ice_aktar($dbh,$v['kanal'],$v['ham'],$v['tur'],$v['ad'],(int) $terminalkullanici,$v['bicim'] ?? 'lumen');
            unset($_SESSION['et_onizleme']);
        }
    }
} catch (Throwable $e) { $hata=et_hata($e); }
et_basla('Sipariş dosyası içe aktar');
et_giris('Siparişleri içe aktar', 'Dosyanı önizle, ardından sipariş kutusuna al. Eşleme ve onay tamamlandığında satış siparişine dönüştür.');
if ($hata) { echo '<div class="bildirim hata" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span>'.bh($hata).'</span></div>'; }
$aktifKanallar=array_filter(et_kanallar($dbh),static fn(array $k): bool => (bool) $k['AKTIF']);
$seciliKanal=(int) ($_POST['kanal'] ?? $_SESSION['et_onizleme']['kanal'] ?? 0);
$seciliBicim=(string) ($_POST['bicim'] ?? $_SESSION['et_onizleme']['bicim'] ?? 'lumen');
$adim=$sonuc ? 3 : (is_array($onizleme) ? 2 : 1);
?>
<ol class="et-adimlar" aria-label="İçe aktarma adımları"><?php foreach ([1=>'Dosya seç',2=>'Önizle',3=>'Kutuya al'] as $sira=>$ad): ?><li <?= $adim===$sira ? 'aria-current="step"' : '' ?>><span aria-hidden="true"><?= $sira ?></span><?= bh($ad) ?></li><?php endforeach; ?></ol>
<?php if ($sonuc): ?><section class="kart et-bolum" role="status"><div class="et-baslik-grup"><span class="et-ikon"><i class="fa-solid fa-check" aria-hidden="true"></i></span><h2>Dosya işlendi</h2></div><div class="et-sonuclar"><span>Yeni: <?= (int) $sonuc['yeni'] ?></span><span>Mükerrer: <?= (int) $sonuc['mukerrer'] ?></span><span>Değişmiş içerik: <?= (int) $sonuc['cakisma'] ?></span><span>Hatalı: <?= (int) $sonuc['hatali'] ?></span><span>Eski kaynak: <?= (int) $sonuc['eski'] ?></span></div><a class="btn btn-birincil" href="index.php"><i class="fa-solid fa-inbox" aria-hidden="true"></i>Kutuyu aç</a></section><?php endif; ?>
<div class="et-iki-sutun"><div>
<form method="post" enctype="multipart/form-data" class="kart et-bolum">
<div class="et-bolum-baslik"><div class="et-baslik-grup"><span class="et-ikon"><i class="fa-solid fa-file-import" aria-hidden="true"></i></span><div><h2>Sipariş dosyası</h2><p class="et-kucuk">Dosyanın geldiği kanalı ve biçimi seç.</p></div></div></div>
<?= csrf_field() ?><input type="hidden" name="islem" value="yukle">
<?php if (!$aktifKanallar): ?><div class="bildirim uyari"><span>Önce bir aktif satış kanalı tanımla.</span><a class="btn btn-ikincil" href="kanallar.php">Kanal tanımla</a></div><?php endif; ?>
<div class="et-form-grid"><label class="alan">Kanal <select name="kanal" required><?php foreach ($aktifKanallar as $k): ?><option value="<?= (int) $k['ID'] ?>" <?= $seciliKanal===(int) $k['ID'] ? 'selected' : '' ?>><?= bh($k['AD']) ?></option><?php endforeach; ?></select></label>
<label class="alan">Dosya biçimi <select name="bicim"><?php foreach (et_bicimler() as $kod=>$b): ?><option value="<?= bh($kod) ?>" <?= $seciliBicim===$kod ? 'selected' : '' ?>><?= bh(['lumen'=>'Genel CSV / JSON','trendyol_v2'=>'Trendyol v2 · JSON','hepsiburada_paket'=>'Hepsiburada · JSON'][$kod] ?? $b['ad']) ?></option><?php endforeach; ?></select></label></div>
<div class="et-dosya"><label class="alan">İçe aktarılacak dosya<input type="file" name="dosya" accept=".csv,.json" required aria-describedby="et-dosya-ipucu"></label><p class="et-kucuk" id="et-dosya-ipucu">CSV veya JSON · UTF-8 · En fazla 1 MB ve 100 sipariş paketi</p></div>
<div class="et-form-son"><span class="et-kucuk">Önizleme satış siparişi oluşturmaz.</span><button class="btn btn-birincil" <?= !$aktifKanallar ? 'disabled' : '' ?>><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>Önizle</button></div>
</form>
<?php if (is_array($onizleme)): ?>
<section class="kart"><div class="et-tablo-baslik"><h2>Dosya önizlemesi</h2><span class="et-sayi"><?= count($onizleme) ?> paket</span></div>
<?php if (!$onizleme): ?><div class="et-bolum et-metin">API yanıtında paket yok; boş dosya kaydı saklanabilir.</div><?php else: ?><div class="tablo-sar"><table class="liste kartla"><thead><tr><th>Sipariş / paket</th><th>İçe aktarma kontrolü</th></tr></thead><tbody><?php foreach ($onizleme as $r): $uyari=$r['hata'] ?? $r['kaynak_engeli'] ?? ''; ?><tr><td><div><strong><?= bh($r['numara'] ?: 'Numarasız/geçersiz kayıt') ?></strong><?php if ($r['paket_kimligi']): ?><span class="et-satir-alt">Paket <?= bh($r['paket_kimligi']) ?></span><?php endif; ?></div></td><td><span class="<?= $uyari ? 'eksi' : 'arti' ?>"><?= bh($uyari ?: 'Kutuya alınabilir') ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<form method="post" class="et-bolum"><?= csrf_field() ?><input type="hidden" name="islem" value="kaydet"><input type="hidden" name="anahtar" value="<?= bh($_SESSION['et_onizleme']['anahtar']) ?>"><p class="et-kucuk">Uyarılı kayıtlar da inceleme için kutuda saklanır. Aktarım ayrıca onaylanır.</p><div class="et-form-son"><span class="et-kucuk"><?= bh($_SESSION['et_onizleme']['ad']) ?></span><button class="btn btn-birincil"><i class="fa-solid fa-inbox" aria-hidden="true"></i>Sipariş kutusuna al</button></div></form></section>
<?php endif; ?></div>
<aside class="et-ornekler" aria-label="Örnek dosyalar"><h2>Örnek dosyalar</h2><p class="et-kucuk">Biçimi görmek için bir örnek indir.</p><div class="et-ornek-liste">
<?php foreach (['trendyol_v2'=>['Trendyol','JSON · Sipariş paketleri v2','fa-file-code'],'hepsiburada_paket'=>['Hepsiburada','JSON · Paket listesi','fa-file-code'],'csv'=>['Genel CSV','CSV · Kendi siten için','fa-file-csv'],'json'=>['Genel JSON','JSON · Ortak sipariş biçimi','fa-file-code']] as $kod=>[$ad,$aciklama,$ikon]): ?><a class="et-ornek" href="?ornek=<?= bh($kod) ?>" download aria-label="<?= bh($ad) ?> sentetik örneğini indir"><i class="fa-solid <?= bh($ikon) ?>" aria-hidden="true"></i><span><strong><?= bh($ad) ?></strong><small><?= bh($aciklama) ?></small></span><i class="fa-solid fa-download" aria-hidden="true"></i></a><?php endforeach; ?></div><div class="et-not"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Örneklerin tamamı sentetiktir. Gerçek müşteri veya pazaryeri verisi içermez.</span></div></aside></div>
<?php et_bitir();
