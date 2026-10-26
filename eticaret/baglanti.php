<?php
declare(strict_types=1);
require_once __DIR__.'/../ayr.php';
require_once __DIR__.'/../kontrol.php';
require_once __DIR__.'/_ortak.php';
require_once __DIR__.'/../includes/eticaret/cekim.php';
if ((int) m_p_yetki($terminalkullanici,'M16')!==1) { lumen_hata_sayfasi('Yetkiniz yok','Kanal bağlantısı için M16 gerekiyor.',403); }
$kanal=(int) ($_GET['kanal'] ?? 0);$hata='';
try { $k=et_kanal($dbh,$kanal);if ($k['TUR']!=='trendyol') { throw new DomainException('Bu kanal için API bağlantısı henüz desteklenmiyor.'); } }
catch (Throwable $e) { lumen_hata_sayfasi('Kanal bulunamadı',et_hata($e),404); }
if ($_SERVER['REQUEST_METHOD']==='POST') {
    et_post();
    try {
        $islem=(string) ($_POST['islem'] ?? '');
        if (!in_array($islem,['kaydet','kaldir'],true)) { throw new DomainException('İşlem tanınmadı.'); }
        et_baglanti_kaydet($dbh,$kanal,(int) $terminalkullanici,$islem==='kaldir' ? null : $_POST);
        header('Location: baglanti.php?'.http_build_query(['kanal'=>$kanal,'ok'=>1]),true,303);exit;
    } catch (Throwable $e) { $hata=et_hata($e); }
}
$b=et_baglanti($dbh,$kanal);et_basla('Trendyol bağlantısı');
et_giris('Trendyol bağlantısı',$k['AD'],'<a class="btn btn-ikincil" href="kanallar.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Kanallara dön</a>');
if ($hata) { echo '<p class="bildirim hata">'.bh($hata).'</p>'; }
if (isset($_GET['ok'])) { echo '<p class="bildirim basari" role="status">Bağlantı ayarı kaydedildi.</p>'; }
?>
<div class="et-baglanti-duzen"><section class="kart et-bolum"><div class="et-bolum-baslik"><div><h2><?= $b ? 'Bağlantı ayarları' : 'Trendyol hesabını bağla' ?></h2><p class="et-kucuk"><?= $b ? 'Anahtarlar kayıtlı. Değiştirmeyeceksen iki anahtar alanını da boş bırak.' : 'Bilgileri Trendyol Satıcı Paneli → Hesap bilgilerim → Entegrasyon bilgileri bölümünden alabilirsin.' ?></p></div><span class="et-ikon"><i class="fa-solid fa-plug" aria-hidden="true"></i></span></div>
<form method="post" autocomplete="off">
<?= csrf_field() ?><input type="hidden" name="islem" value="kaydet">
<div class="et-form-grid">
<label class="alan">Ortam <select name="ortam"><option value="stage" <?= ($b['ORTAM'] ?? 'stage')==='stage' ? 'selected' : '' ?>>Test ortamı</option><option value="prod" <?= ($b['ORTAM'] ?? '')==='prod' ? 'selected' : '' ?>>Üretim ortamı</option></select></label>
<label class="alan">Satıcı numarası <input name="satici" inputmode="numeric" pattern="[1-9][0-9]{0,18}" maxlength="19" required value="<?= bh($b['SATICI_ID'] ?? '') ?>"></label>
<label class="alan">API anahtarı <input name="anahtar" type="password" autocomplete="new-password" maxlength="256" <?= $b ? '' : 'required' ?>></label>
<label class="alan">API gizli anahtarı <input name="gizli" type="password" autocomplete="new-password" maxlength="256" <?= $b ? '' : 'required' ?>></label>
</div>
<p class="et-kucuk">Test ve üretim anahtarları farklıdır. Ortamı veya satıcıyı değiştirirken iki anahtarı da yeniden gir.</p>
<div class="et-form-son"><p class="et-kucuk">Kaydetmek Trendyol'a istek göndermez. Yarım kalan alım varsa durdurulur.</p><button class="btn btn-birincil"><i class="fa-solid fa-check" aria-hidden="true"></i>Bağlantıyı kaydet</button></div>
</form></section>
<aside class="et-baglanti-not"><h2>Siparişler önce kutuya gelir</h2><ol><li>Bağlantı bilgilerini kaydet.</li><li>Tarih aralığı seçerek siparişleri al.</li><li>Kutuda eşlemeleri ve tutarları inceleyip aktar.</li></ol><p class="et-kucuk">Trendyol'daki siparişler bu bağlantıyla değiştirilmez. Satış siparişini Lumen'de sen onaylarsın.</p>
<?php if ($b): ?><a class="btn btn-birincil" href="al.php?kanal=<?= $kanal ?>"><i class="fa-solid fa-cloud-arrow-down" aria-hidden="true"></i>Siparişleri al</a><form method="post" class="et-baglanti-kaldir"><?= csrf_field() ?><button class="btn btn-ikincil" name="islem" value="kaldir"><i class="fa-solid fa-link-slash" aria-hidden="true"></i>Bağlantıyı kaldır</button></form><?php endif; ?>
</aside></div><?php et_bitir();
