<?php
declare(strict_types=1);
require_once __DIR__.'/../ayr.php';
require_once __DIR__.'/../kontrol.php';
require_once __DIR__.'/_ortak.php';
require_once __DIR__.'/../includes/eticaret/cekim.php';
if ((int) m_p_yetki($terminalkullanici,'M16')!==1) { lumen_hata_sayfasi('Yetkiniz yok','Sipariş almak için M16 gerekiyor.',403); }
$kanal=(int) ($_GET['kanal'] ?? 0);$hata='';
try { $k=et_kanal($dbh,$kanal);$a=et_ag_adaptor($k['TUR']); }
catch (Throwable $e) { lumen_hata_sayfasi('Kanal bulunamadı',et_hata($e),404); }
$oturumAnahtari='et_al_baslat_'.$kanal;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    et_post();$islem=(string) ($_POST['islem'] ?? '');$id=(int) ($_POST['cekim'] ?? 0);
    try {
        if ($islem==='baslat') { $id=et_cekimi_baslat($dbh,$kanal,(int) $terminalkullanici,(string) ($_POST['bas'] ?? ''),(string) ($_POST['bit'] ?? '')); }
        elseif ($islem==='tekrar' || $islem==='durdur') { et_cekimi_yonet($dbh,$kanal,$id,(int) $terminalkullanici,$islem); }
        elseif ($islem==='adim' || $islem==='sayfa') {
            // Aynı tarayıcı alımı durdurabilsin; ağ beklerken PHP oturum kilidini tutma.
            session_write_close();
            $r=et_cekimi_adim($dbh,$kanal,$id,(int) $terminalkullanici);
            if ($islem==='adim') {
                header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
                echo et_json(['durum'=>$r['DURUM'],'sayfa'=>(int) $r['SAYFA'],'yeni'=>(int) $r['YENI'],'mukerrer'=>(int) $r['MUKERRER'],'incele'=>(int) $r['CAKISMA']+(int) $r['HATALI'],
                    'hata'=>$r['HATA'],'manuel'=>(int) $r['DENEME']>=5,'bekle'=>max(5,et_api_zaman($r['SONRAKI'])-time(),et_api_zaman($r['KILIT_BITIS'])-time())]);exit;
            }
        } elseif ($islem==='devam') {
            $r=et_cekimi_oku($dbh,$kanal,$id);if (!$r || $r['DURUM']!=='suruyor') { throw new DomainException('Sürdürülebilir alım bulunamadı.'); }
        } else { throw new DomainException('İşlem tanınmadı.'); }
        if (in_array($islem,['baslat','devam','tekrar'],true)) { $_SESSION[$oturumAnahtari]=$id; }
        header('Location: al.php?'.http_build_query(['kanal'=>$kanal]),true,303);exit;
    } catch (Throwable $e) {
        $hata=et_hata($e);
        if ($islem==='adim') { http_response_code(422);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo et_json(['hata'=>$hata]);exit; }
    }
}
$b=et_baglanti($dbh,$kanal);if ($b && $b['TUR']!==$k['TUR']) { $b=null; }$r=et_cekimi_oku($dbh,$kanal);
$hb=$k['TUR']==='hepsiburada';$eylem=$hb ? 'Paketleri al' : 'Siparişleri al';$baslik=$hb ? 'Hepsiburada paketlerini al' : 'Trendyol siparişlerini al';
// Yalnız bu oturumdaki açık POST eyleminin yönlendirmesi otomatik ilerlemeyi başlatır.
$otomatik=$r && $r['DURUM']==='suruyor' && (int) ($_SESSION[$oturumAnahtari] ?? 0)===(int) $r['ID'];unset($_SESSION[$oturumAnahtari]);
$tz=new DateTimeZone('Europe/Istanbul');$simdi=new DateTimeImmutable('now',$tz);
et_basla($baslik);
et_giris($baslik,$k['AD'],'<a class="btn btn-ikincil" href="index.php?kanal='.$kanal.'"><i class="fa-solid fa-inbox" aria-hidden="true"></i>Sipariş kutusu</a>');
if ($hata) { echo '<p class="bildirim hata">'.bh($hata).'</p>'; }
?>
<div class="et-baglanti-duzen"><section class="kart et-bolum">
<?php if (!$b): ?><div class="et-bos"><i class="fa-solid fa-plug" aria-hidden="true"></i><h2>Önce bağlantıyı tanımla</h2><p class="et-kucuk">Bu kanal için geçerli <?= bh($a['ad']) ?> bağlantısı henüz tanımlanmadı.</p><a class="btn btn-birincil" href="baglanti.php?kanal=<?= $kanal ?>">Bağlantıyı tanımla</a></div>
<?php elseif ($r && $r['DURUM']==='suruyor'): ?>
<div class="et-bolum-baslik"><div><h2 id="al-durum"><?= $otomatik ? 'Siparişler alınıyor' : 'Alım bekliyor' ?></h2><p class="et-kucuk">Sayfayı kapatırsan kayıtlı ilerlemeden devam edebilirsin.</p></div><span class="et-ikon"><i class="fa-solid fa-cloud-arrow-down" aria-hidden="true"></i></span></div>
<?php else: ?><div class="et-bolum-baslik"><div><h2>Tarih aralığı seç</h2><p class="et-kucuk"><?= $hb ? 'Hazırlanmış açık paketler seçilen tarih aralığıyla alınır.' : 'Siparişin son değiştiği zamana göre alım yapılır.' ?></p></div><span class="et-durum"><?= $b['ORTAM']==='stage' ? 'Test ortamı' : 'Üretim ortamı' ?></span></div>
<?php endif; ?>
<?php if ($b && (!$r || $r['DURUM']!=='suruyor')): ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="islem" value="baslat"><div class="et-form-grid"><label class="alan">Başlangıç <input type="datetime-local" name="bas" required value="<?= bh($simdi->modify('-1 day')->format('Y-m-d\TH:i')) ?>"></label><label class="alan">Bitiş <input type="datetime-local" name="bit" required max="<?= bh($simdi->format('Y-m-d\TH:i')) ?>" value="<?= bh($simdi->format('Y-m-d\TH:i')) ?>"></label></div>
<div class="et-form-son"><p class="et-kucuk"><?= $hb ? 'İstanbul saati. En fazla 24 saat seçebilirsin.' : 'İstanbul saati. Son üç ay içinden en fazla 14 gün seçebilirsin.' ?></p><button class="btn btn-birincil" <?= !$k['AKTIF'] ? 'disabled' : '' ?>><i class="fa-solid fa-cloud-arrow-down" aria-hidden="true"></i><?= bh($eylem) ?></button></div></form>
<?php elseif ($b && $r): ?>
<?php $f=json_decode($r['FILTRE'],true);$bas=$hb ? new DateTimeImmutable($f['begindate'],$tz) : (new DateTimeImmutable('@'.(int) ($f['lastModifiedStartDate']/1000)))->setTimezone($tz);$bit=$hb ? new DateTimeImmutable($f['enddate'],$tz) : (new DateTimeImmutable('@'.(int) ($f['lastModifiedEndDate']/1000)))->setTimezone($tz); ?><p class="et-kucuk"><?= bh($bas->format('d.m.Y H:i')) ?> – <?= bh($bit->format('d.m.Y H:i')) ?> · <?= $b['ORTAM']==='stage' ? 'Test ortamı' : 'Üretim ortamı' ?></p>
<div id="al-hata" class="bildirim hata" role="status" <?= !$r['HATA'] ? 'hidden' : '' ?>><?= bh($r['HATA'] ?? '') ?></div><p id="al-bekleme" class="et-kucuk" role="status"></p>
<form method="post" id="al-form"><?= csrf_field() ?><input type="hidden" name="cekim" value="<?= (int) $r['ID'] ?>"><div class="et-eylemler"><button class="btn btn-birincil" name="islem" value="<?= (int) $r['DENEME']>=5 ? 'tekrar' : 'devam' ?>" id="al-devam" <?= $otomatik ? 'hidden' : '' ?>><i class="fa-solid fa-play" aria-hidden="true"></i><?= (int) $r['DENEME']>=5 ? 'Yeniden dene' : 'Alımı sürdür' ?></button><button class="btn btn-ikincil" name="islem" value="durdur" id="al-durdur"><i class="fa-solid fa-stop" aria-hidden="true"></i>Alımı durdur</button></div><noscript><p>Otomatik alım için JavaScript gerekir. Her tıklamayla bir sayfa alabilirsin.</p><button class="btn btn-ikincil" name="islem" value="sayfa">Bir sayfa al</button></noscript></form>
<?php endif; ?>
<?php if ($r): ?><div class="et-al-sonuc" aria-live="polite"><div><span>Yeni kayıt</span><strong id="al-yeni"><?= (int) $r['YENI'] ?></strong></div><div><span>Tekrar gelen</span><strong id="al-mukerrer"><?= (int) $r['MUKERRER'] ?></strong></div><div><span>İnceleme gereken</span><strong id="al-incele"><?= (int) $r['CAKISMA']+(int) $r['HATALI'] ?></strong></div><div><span>Alınan sayfa</span><strong id="al-sayfa"><?= (int) $r['SAYFA'] ?></strong></div></div>
<?php if ($r['DURUM']!=='suruyor'): ?><p class="et-kucuk" role="status">Son alım <?= $r['DURUM']==='tamam' ? 'tamamlandı' : 'durduruldu' ?>. Alınan kayıtları sipariş kutusunda inceleyebilirsin.</p><?php endif; ?><?php endif; ?>
</section><aside class="et-baglanti-not"><h2>Önce incele, sonra aktar</h2><p>Alınan siparişler kontrol kutusunda bekler. Ürün, cari, stok ve tutar kontrollerinden sonra satış siparişine dönüştürülür.</p><p class="et-kucuk"><?= $hb ? 'Paketlenmemiş siparişler ve ayrı iptal/iade servisleri bu alıma dahil değildir. Aktarmadan önce Hepsiburada panelinde paketin güncelliğini kontrol et.' : 'İptaller ve değişmiş siparişler de alınır. Daha önce gelen paketler ikinci kez oluşturulmaz.' ?></p><?php if ($b): ?><a class="btn btn-ikincil" href="baglanti.php?kanal=<?= $kanal ?>"><i class="fa-solid fa-sliders" aria-hidden="true"></i>Bağlantı ayarları</a><?php endif; ?><?php if (!$k['AKTIF']): ?><p class="bildirim hata">Kanal pasif. Alım için satış kanallarından etkinleştir.</p><?php endif; ?></aside></div>
<?php if ($otomatik): ?>
<script src="../assets/js/eticaret-al.js?v=<?= (int) filemtime(__DIR__.'/../assets/js/eticaret-al.js') ?>" defer></script>
<?php endif; ?>
<?php et_bitir();
