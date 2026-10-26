<?php
declare(strict_types=1);
require_once __DIR__.'/../ayr.php';
require_once __DIR__.'/../kontrol.php';
require_once __DIR__.'/_ortak.php';
$hata='';
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        ek_post();$islem=ek_alan('islem');
        if ($islem==='kaydet') {
            $anahtar=ek_alan('evds_api_key');unset($_POST['evds_api_key']);
            evds_baglanti_kaydet($dbh,(int) $firmano,(int) $terminalkullanici,$anahtar);unset($anahtar);
        } elseif ($islem==='kaldir') { evds_baglanti_kaydet($dbh,(int) $firmano,(int) $terminalkullanici,null); }
        else { throw new DomainException('Bilinmeyen bağlantı işlemi.'); }
        unset($_SESSION['evds_sonuc']);
        header('Location: baglanti.php?'.http_build_query(['ok'=>$islem==='kaydet' ? 'API anahtarı kaydedildi. Veri alarak erişiminizi doğrulayabilirsiniz.' : 'Kişisel API anahtarı ve önbellek kaldırıldı.']),true,303);exit;
    }
} catch (Throwable $e) { unset($anahtar,$_POST['evds_api_key']);$hata=ek_hata($e); }
$bag=evds_baglanti($dbh,(int) $firmano,(int) $terminalkullanici);
ek_basla('Kişisel EVDS bağlantısı');
?>
<div class="ek-giris"><div><h1>Kişisel EVDS bağlantısı</h1><p>TCMB verilerini kendi EVDS hesabının API anahtarıyla kullan.</p></div><span class="ek-durum"><i class="fa-solid <?= $bag ? 'fa-key' : 'fa-plug' ?>" aria-hidden="true"></i><?= $bag ? 'Anahtar tanımlı' : 'Anahtar tanımlanmadı' ?></span></div>
<?php if ($hata): ?><div class="bildirim hata" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= bh($hata) ?></span></div><?php endif; ?>
<div class="ek-baglanti-duzen"><section class="kart ek-bolum"><div class="ek-bolum-baslik"><span class="ek-ikon"><i class="fa-solid fa-key" aria-hidden="true"></i></span><div><h2><?= $bag ? 'API anahtarını değiştir' : 'API anahtarını tanımla' ?></h2><p class="ek-kucuk">Bu bağlantı yalnız senin Lumen hesabın için kullanılır.</p></div></div>
<form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="islem" value="kaydet"><label class="alan">EVDS API anahtarı<input type="password" name="evds_api_key" minlength="16" maxlength="128" required autocomplete="off" spellcheck="false" placeholder="EVDS profilindeki anahtar" aria-describedby="ek-anahtar-not"></label><p class="ek-kucuk" id="ek-anahtar-not">Anahtar şifrelenerek saklanır ve tekrar gösterilmez. Başka kullanıcıların bağlantısı değişmez.</p><div class="ek-form-son"><a class="btn btn-ikincil" href="index.php">Göstergelere dön</a><button class="btn btn-birincil"><i class="fa-solid fa-check" aria-hidden="true"></i>Anahtarı kaydet</button></div></form>
<?php if ($bag): ?><form method="post" class="ek-kaldir"><?= csrf_field() ?><input type="hidden" name="islem" value="kaldir"><div><strong>Kayıtlı bağlantı</strong><p class="ek-kucuk">Son değişiklik: <?= bh((new DateTimeImmutable($bag['GUNCELLEME']))->format('d.m.Y H:i')) ?></p></div><button class="btn btn-tehlike">Anahtarı kaldır</button></form><?php endif; ?></section>
<aside class="ek-rehber"><h2>Anahtarını nereden alırsın?</h2><ol><li>EVDS hesabına giriş yap.</li><li><strong>Benim Sayfam → Profilim</strong> bölümünü aç.</li><li><strong>API KEY KOPYALA</strong> ile anahtarını alıp bu alana gir.</li></ol><div class="ek-eylemler"><a class="btn btn-ikincil" href="https://evds3.tcmb.gov.tr/" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>EVDS'yi aç</a><a class="btn btn-ikincil" href="https://evds3.tcmb.gov.tr/igmevdsms-dis/documents/showDocument?docId=18" target="_blank" rel="noopener noreferrer">Kullanım şartları</a></div><p class="ek-kucuk">Bu ekran ekonomik veriyi okur. Anahtar kaydetmek, veriye erişimin başarılı olduğunu tek başına göstermez; gösterge ekranından bir sorgu çalıştır.</p></aside></div>
<?php ek_bitir();
