<?php
declare(strict_types=1);
require __DIR__ . '/_ortak.php';
$yonetici = (new Tarayici($URL))->giris('YONETICI');
$personel = (new Tarayici($URL))->giris('DEPO1');
akis('e-ticaret: kanal kaydı, pasiflik ve CSRF/yetki', function () use ($pdo,$yonetici,$personel): void {
    $ad = 'Sentetik kanal ' . bin2hex(random_bytes(4));
    $p = [':ad' => $ad];
    $r = $yonetici->istek('eticaret/kanallar.php', ['csrf_token' => $yonetici->belirtec('eticaret/kanallar.php'),
        'ad' => $ad, 'tur' => 'site', 'aktif' => '1', 'cari' => 4, 'depo' => 0, 'personel' => 1]);
    bekle($r['kod'] === 303, 'kanal kaydedilemedi: ' . strip_tags($r['govde']));
    $id = (int) tek($pdo, 'SELECT ID FROM M_ET_KANAL WHERE AD=:ad', $p);
    bekle($id > 0, 'kanal yok');
    bekle($personel->istek('eticaret/kanallar.php')['kod'] === 403, 'yetkisiz kanal erişimi');
    bekle($yonetici->istek('eticaret/kanallar.php', ['ad' => $ad])['kod'] === 403, 'CSRF eksik istek kabul edildi');
    $g = $yonetici->istek('eticaret/kanallar.php?id=' . $id)['govde'];
    preg_match('/name="surum" value="([^"]+)"/', $g, $m);
    $r = $yonetici->istek('eticaret/kanallar.php', ['csrf_token' => $yonetici->belirtec('eticaret/kanallar.php'),
        'id'=>$id,'surum'=>$m[1] ?? '', 'ad'=>$ad,'tur'=>'site','cari'=>4,'depo'=>0,'personel'=>1]);
    bekle($r['kod'] === 303 && (int) tek($pdo, 'SELECT AKTIF FROM M_ET_KANAL WHERE ID=:i', [':i'=>$id]) === 0, 'pasiflik kaydedilemedi: HTTP '.$r['kod'].' '.strip_tags($r['govde']));
});
$etKanal=0; $etSiparis=0;
akis('e-ticaret: önizleme, kutuya alma, tekrar ve değişmiş içerik', function () use ($pdo,$yonetici,&$etKanal,&$etSiparis): void {
    $ad='İçe aktarma '.bin2hex(random_bytes(4));
    $yonetici->istek('eticaret/kanallar.php',['csrf_token'=>$yonetici->belirtec('eticaret/kanallar.php'),
        'ad'=>$ad,'tur'=>'site','aktif'=>1,'cari'=>4,'depo'=>0,'personel'=>1]);
    $etKanal=(int) tek($pdo,'SELECT ID FROM M_ET_KANAL WHERE AD=:a',[':a'=>$ad]);
    $ornek=json_decode(file_get_contents(dirname(__DIR__,2).'/docs/eticaret/ornek.json'),true);
    $al=static function(array $v) use ($yonetici,$etKanal): string {
        $yol=tempnam(sys_get_temp_dir(),'et_');
        try {
            file_put_contents($yol,json_encode($v));
            $r=$yonetici->istekDosya('eticaret/ice_aktar.php',['csrf_token'=>$yonetici->belirtec('eticaret/ice_aktar.php'),
                'islem'=>'yukle','kanal'=>$etKanal],'dosya',$yol,'sentetik.json');
            preg_match('/name="anahtar" value="([a-f0-9]+)"/',$r['govde'],$m);
            bekle(isset($m[1]),'önizleme yok: '.strip_tags($r['govde']));
            return $yonetici->istek('eticaret/ice_aktar.php',['csrf_token'=>$yonetici->belirtec('eticaret/ice_aktar.php'),
                'islem'=>'kaydet','anahtar'=>$m[1]])['govde'];
        } finally { unlink($yol); }
    };
    $once=(int) tek($pdo,'SELECT COUNT(*) FROM LG_001_02_ORFICHE');
    bekle(str_contains($al($ornek),'Yeni: 1'),'ilk alım başarısız');
    $etSiparis=(int) tek($pdo,'SELECT ID FROM M_ET_SIPARIS WHERE KANAL_ID=:k',[':k'=>$etKanal]);
    bekle(str_contains($al($ornek),'Mükerrer: 1'),'tekrar algılanmadı');
    $ornek['siparisler'][0]['genel_toplam']=123;
    bekle(str_contains($al($ornek),'Değişmiş içerik: 1'),'değişmiş veri ezildi');
    bekle((int) tek($pdo,'SELECT COUNT(*) FROM M_ET_SIPARIS WHERE KANAL_ID=:k',[':k'=>$etKanal])===1,'kutu çoğaldı');
    bekle((int) tek($pdo,'SELECT COUNT(*) FROM LG_001_02_ORFICHE')===$once,'onaysız satış siparişi oluştu');
});
akis('e-ticaret: eksik ürün engeli, kayıtlı eşleme ve hazır kontrolü', function () use ($pdo,$yonetici,&$etKanal,&$etSiparis): void {
    $g=$yonetici->istek('eticaret/detay.php?id='.$etSiparis)['govde'];
    bekle(str_contains($g,'Ürün eşlemesi eksik'),'eksik eşleme görünmüyor');
    $kod=(string) tek($pdo,'SELECT CODE FROM LG_001_ITEMS WHERE LOGICALREF=2');
    $r=$yonetici->istek('eticaret/eslemeler.php',['csrf_token'=>$yonetici->belirtec('eticaret/eslemeler.php?kanal='.$etKanal),
        'kanal'=>$etKanal,'tur'=>'sku','deger'=>'DEMO-SKU-1','stok_kod'=>$kod,'birim'=>'ADET']);
    bekle($r['kod']===303,'eşleme kaydı başarısız: '.strip_tags($r['govde']));
    $g=$yonetici->istek('eticaret/detay.php?id='.$etSiparis)['govde'];
    preg_match('/name="surum" value="([^"]+)"/',$g,$m);
    $r=$yonetici->istek('eticaret/detay.php',['csrf_token'=>$yonetici->belirtec('eticaret/detay.php?id='.$etSiparis),
        'id'=>$etSiparis,'surum'=>$m[1] ?? '', 'cakisma_onay'=>'1','islem'=>'kontrol','cari'=>4,'depo'=>0,'personel'=>1]);
    bekle($r['kod']===303,'kontrol başarısız: '.strip_tags($r['govde']));
    bekle(tek($pdo,'SELECT DURUM FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$etSiparis])==='hazir',
        'hazır olmadı: '.tek($pdo,'SELECT SON_HATA FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$etSiparis]));
});
akis('e-ticaret: aktarım atomiktir; hata izi kalır, yeniden deneme tek sipariş üretir', function () use ($pdo,$yonetici,&$etSiparis): void {
    $form=static function(string $islem) use ($yonetici,$etSiparis): array {
        $g=$yonetici->istek('eticaret/detay.php?id='.$etSiparis)['govde'];
        preg_match('/name="surum" value="([^"]+)"/',$g,$m);
        return ['csrf_token'=>$yonetici->belirtec('eticaret/detay.php?id='.$etSiparis),'id'=>$etSiparis,
            'surum'=>$m[1] ?? '', 'islem'=>$islem,'cari'=>4,'depo'=>0,'personel'=>1];
    };
    $once=(int) tek($pdo,'SELECT COUNT(*) FROM LG_001_02_ORFICHE');
    // Sipariş yazıldıktan, kutu tamamlanırken hata üret: her iki kaydın rollback olmasını kanıtlar.
    $pdo->exec("CREATE OR ALTER TRIGGER dbo.TR_ET_ATOM_TEST ON dbo.M_ET_SIPARIS AFTER UPDATE AS
        BEGIN IF EXISTS (SELECT 1 FROM inserted WHERE ID={$etSiparis} AND DURUM='aktarildi') THROW 51000, 'Sentetik aktarim hatasi', 1; END");
    try { $yonetici->istek('eticaret/detay.php',$form('aktar')); }
    finally { $pdo->exec('DROP TRIGGER dbo.TR_ET_ATOM_TEST'); }
    bekle((int) tek($pdo,'SELECT COUNT(*) FROM LG_001_02_ORFICHE')===$once,'hata orphan sipariş bıraktı');
    bekle((int) tek($pdo,'SELECT DENEME FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$etSiparis])===1,'başarısız deneme saklanmadı');
    bekle(tek($pdo,'SELECT DURUM FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$etSiparis])==='hatali','hata durumu kayıp');
    $yonetici->istek('eticaret/detay.php',$form('kontrol'));
    $aktar=$form('aktar'); $r=$yonetici->istek('eticaret/detay.php',$aktar);
    bekle($r['kod']===303,'yeniden deneme başarısız: '.strip_tags($r['govde']));
    $yonetici->istek('eticaret/detay.php',$aktar);
    $s=$pdo->query('SELECT * FROM M_ET_SIPARIS WHERE ID='.(int) $etSiparis)->fetch(PDO::FETCH_ASSOC);
    bekle($s['DURUM']==='aktarildi' && (int) $s['FIS_ID']>0 && $s['FIS_NO']!=='','sonuç saklanmadı: '.($s['SON_HATA'] ?? ''));
    bekle((int) tek($pdo,'SELECT COUNT(*) FROM LG_001_02_ORFICHE')===$once+1,'tekrar ikinci sipariş oluşturdu');
    yakin(120,(float) tek($pdo,'SELECT NETTOTAL FROM LG_001_02_ORFICHE WHERE LOGICALREF=:i',[':i'=>$s['FIS_ID']]),'aktarılan toplam');
    bekle((int) tek($pdo,"SELECT COUNT(*) FROM M_API_IDEMPOTENCY WHERE KAPSAM='eticaret_aktar:v1'")>0,'idempotency kullanılmadı');
    if (lumen_bagimsiz_mi()) {
        bekle((int) tek($pdo,'SELECT COUNT(*) FROM LM_STOK_HAREKET WHERE BELGE_ID=:i',[':i'=>$s['FIS_ID']])===0,'sipariş stok hareketi üretti');
        bekle((int) tek($pdo,'SELECT COUNT(*) FROM LM_CARI_HAREKET WHERE BELGE_ID=:i',[':i'=>$s['FIS_ID']])===0,'sipariş cari hareketi üretti');
    }
});
function et_test_yeni(Tarayici $t, int $kanal, array $degisiklik = []): int
{
    global $pdo;
    $v=json_decode(file_get_contents(dirname(__DIR__,2).'/docs/eticaret/ornek.json'),true);
    $no='TEST-'.bin2hex(random_bytes(6));
    $v['siparisler'][0]=array_replace($v['siparisler'][0],['kimlik'=>$no,'numara'=>$no],$degisiklik);
    $yol=tempnam(sys_get_temp_dir(),'et_');
    try {
        file_put_contents($yol,json_encode($v));
        $g=$t->istekDosya('eticaret/ice_aktar.php',['csrf_token'=>$t->belirtec('eticaret/ice_aktar.php'),
            'islem'=>'yukle','kanal'=>$kanal],'dosya',$yol,'sentetik.json')['govde'];
        preg_match('/name="anahtar" value="([a-f0-9]+)"/',$g,$m);
        bekle(isset($m[1]),'test önizlemesi yok');
        $t->istek('eticaret/ice_aktar.php',['csrf_token'=>$t->belirtec('eticaret/ice_aktar.php'),'islem'=>'kaydet','anahtar'=>$m[1]]);
    } finally { unlink($yol); }
    return (int) tek($pdo,'SELECT ID FROM M_ET_SIPARIS WHERE KANAL_ID=:k AND HARICI_NO=:n',[':k'=>$kanal,':n'=>$no]);
}
function et_test_form(Tarayici $t, int $id, string $islem): array
{
    $g=$t->istek('eticaret/detay.php?id='.$id)['govde']; preg_match('/name="surum" value="([^"]+)"/',$g,$m);
    return ['csrf_token'=>$t->belirtec('eticaret/ice_aktar.php'),'id'=>$id,'surum'=>$m[1] ?? '',
        'islem'=>$islem,'cari'=>4,'depo'=>0,'personel'=>1];
}
akis('e-ticaret: tutar farkı ve eşleme değişimi aktarımı durdurur; iptal korunur', function () use ($pdo,$yonetici,&$etKanal): void {
    $id=et_test_yeni($yonetici,$etKanal,['genel_toplam'=>119]);
    $yonetici->istek('eticaret/detay.php',et_test_form($yonetici,$id,'kontrol'));
    bekle(tek($pdo,'SELECT DURUM FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$id])==='hatali','tutar farkı kontrolü: '.(string) tek($pdo,'SELECT DURUM FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$id]));
    $id=et_test_yeni($yonetici,$etKanal);
    $yonetici->istek('eticaret/detay.php',et_test_form($yonetici,$id,'kontrol'));
    $eski=et_test_form($yonetici,$id,'aktar');
    $kod=(string) tek($pdo,'SELECT CODE FROM LG_001_ITEMS WHERE LOGICALREF=2');
    $yonetici->istek('eticaret/eslemeler.php',['csrf_token'=>$yonetici->belirtec('eticaret/eslemeler.php?kanal='.$etKanal),
        'kanal'=>$etKanal,'tur'=>'sku','deger'=>'DEMO-SKU-1','stok_kod'=>$kod,'birim'=>'ADET']);
    $yonetici->istek('eticaret/detay.php',$eski);
    bekle(tek($pdo,'SELECT FIS_ID FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$id])===null,'eski onayla aktarım yapıldı');
    $yonetici->istek('eticaret/detay.php',et_test_form($yonetici,$id,'iptal'));
    $yonetici->istek('eticaret/detay.php',et_test_form($yonetici,$id,'aktar'));
    bekle(tek($pdo,'SELECT DURUM FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$id])==='iptal','iptal geri açıldı');
});
akis('e-ticaret: paralel iki kullanıcı tek sipariş oluşturur; SP1 farkı engeller', function () use ($pdo,$yonetici,$URL,&$etKanal): void {
    $satisId=(int) tek($pdo,"SELECT LOGICALREF FROM LG_SLSMAN WHERE CODE='SATIS1'");
    $s=$pdo->prepare('SELECT M33,M1,SP1 FROM M_P_YETKI WHERE PERSONEL=:i');$s->execute([':i'=>$satisId]);$eski=$s->fetch(PDO::FETCH_ASSOC);$s->closeCursor();
    $s=$pdo->prepare('UPDATE M_P_YETKI SET M33=1,M1=1,SP1=0 WHERE PERSONEL=:i');$s->execute([':i'=>$satisId]);$s->closeCursor();
    try {
        $t=(new Tarayici($URL))->giris('SATIS1'); $id=et_test_yeni($yonetici,$etKanal);
        $t->istek('eticaret/detay.php',et_test_form($t,$id,'kontrol'));
        bekle(tek($pdo,'SELECT DURUM FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$id])==='hatali','SP1 olmadan kanal fiyatı kabul edildi');
        $s=$pdo->prepare('UPDATE M_P_YETKI SET SP1=1 WHERE PERSONEL=:i');$s->execute([':i'=>$satisId]);$s->closeCursor();
        $t=(new Tarayici($URL))->giris('SATIS1');
        $yonetici->istek('eticaret/detay.php',et_test_form($yonetici,$id,'kontrol'));
        $a=et_test_form($yonetici,$id,'aktar');$b=et_test_form($t,$id,'aktar');
        // Tarayici cookie dosyası özel alan olduğu için paralel form cookie başlığı hazırlamakla yetinilmez:
        // iki bağımsız oturumun HTTP istekleri curl_multi ile yürütülür.
        $once=(int) tek($pdo,'SELECT COUNT(*) FROM LG_001_02_ORFICHE');
        $multi=curl_multi_init();$chs=[];
        foreach ([[$yonetici,$a],[$t,$b]] as [$tarayici,$form]) {
            $ch=$tarayici->postHazirla('eticaret/detay.php',$form);$chs[]=$ch;curl_multi_add_handle($multi,$ch);
        }
        do { curl_multi_exec($multi,$aktif); if ($aktif) { curl_multi_select($multi,1); } } while ($aktif);
        foreach ($chs as $ch) { bekle(curl_getinfo($ch,CURLINFO_RESPONSE_CODE)===303,'paralel aktarım başarısız: '.strip_tags(curl_multi_getcontent($ch))); curl_multi_remove_handle($multi,$ch);curl_close($ch); }
        curl_multi_close($multi);
        bekle((int) tek($pdo,'SELECT COUNT(*) FROM LG_001_02_ORFICHE')===$once+1,'paralel aktarım çoğaldı');
        bekle(tek($pdo,'SELECT DURUM FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$id])==='aktarildi','paralel sonuç kayıp');
    } finally {
        $s=$pdo->prepare('UPDATE M_P_YETKI SET M33=:m,M1=:a,SP1=:p WHERE PERSONEL=:i');
        $s->execute([':m'=>$eski['M33'],':a'=>$eski['M1'],':p'=>$eski['SP1'],':i'=>$satisId]);
    }
});
akis('e-ticaret: aynı ürünün toplam stok talebi ve çelişkili barkod engellenir', function () use ($pdo,$yonetici,&$etKanal): void {
    $stok=(float) tek($pdo,'SELECT SUM(ONHAND) FROM LV_001_02_STINVTOT WHERE STOCKREF=2 AND INVENNO=0');
    $miktar=floor(max(0,$stok)/2)+1;
    $k=['sku'=>'DEMO-SKU-1','barkod'=>'','ad'=>'Sentetik','birim'=>'ADET','miktar'=>$miktar,'fiyat'=>50,'kdv'=>20,'kdv_dahil'=>false];
    $id=et_test_yeni($yonetici,$etKanal,['kalemler'=>[$k,$k],'genel_toplam'=>$miktar*120]);
    $yonetici->istek('eticaret/detay.php',et_test_form($yonetici,$id,'kontrol'));
    bekle(tek($pdo,'SELECT DURUM FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$id])==='hatali','toplam stok talebi geçildi');
    $kod=(string) tek($pdo,'SELECT CODE FROM LG_001_ITEMS WHERE LOGICALREF=3');
    $yonetici->istek('eticaret/eslemeler.php',['csrf_token'=>$yonetici->belirtec('eticaret/eslemeler.php?kanal='.$etKanal),
        'kanal'=>$etKanal,'tur'=>'barkod','deger'=>'SENTETIK-BARKOD','stok_kod'=>$kod,'birim'=>'ADET']);
    $k['miktar']=2;$k['barkod']='SENTETIK-BARKOD';
    $id=et_test_yeni($yonetici,$etKanal,['kalemler'=>[$k]]);
    $yonetici->istek('eticaret/detay.php',et_test_form($yonetici,$id,'kontrol'));
    bekle(tek($pdo,'SELECT DURUM FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$id])==='esleme','çelişkili barkod geçildi');
});
akis('e-ticaret: aynı siparişin ayrı paketleri aktarılır; aynı kalem ikinci pakette aktarılmaz', function () use ($pdo,$yonetici,&$etKanal): void {
    $no='PAKET-'.bin2hex(random_bytes(5));
    $temel=['sku'=>'DEMO-SKU-1','barkod'=>'','ad'=>'Sentetik paket kalemi','birim'=>'ADET','miktar'=>2,'fiyat'=>50,'kdv'=>20,'kdv_dahil'=>false];
    $ids=[];
    foreach (['A','B','C'] as $paket) {
        $k=$temel+['harici_kimlik'=>$paket==='C' ? $no.'-SATIR-A' : $no.'-SATIR-'.$paket];
        $id=et_test_yeni($yonetici,$etKanal,['kimlik'=>$no.'-'.$paket,'numara'=>$no,'paket_kimligi'=>$paket,'kalemler'=>[$k]]);
        // Aynı numaranın birden çok paketi var; kimlik üzerinden seçim gerekir.
        $id=(int) tek($pdo,'SELECT ID FROM M_ET_SIPARIS WHERE KANAL_ID=:k AND HARICI_ID=:i',[':k'=>$etKanal,':i'=>$no.'-'.$paket]);
        bekle($id>0,'paket kaydı yok');$ids[]=$id;
        $yonetici->istek('eticaret/detay.php',et_test_form($yonetici,$id,'kontrol'));
        if ($paket==='C') {
            bekle(tek($pdo,'SELECT DURUM FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$id])==='hatali','aynı kalem ikinci pakette hazır oldu');
        } else {
            $yonetici->istek('eticaret/detay.php',et_test_form($yonetici,$id,'aktar'));
            bekle(tek($pdo,'SELECT DURUM FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$id])==='aktarildi','ayrı paket aktarılamadı');
        }
    }
    bekle((int) tek($pdo,"SELECT COUNT(*) FROM M_ET_SIPARIS WHERE KANAL_ID=:k AND HARICI_NO=:n AND DURUM='aktarildi'",[':k'=>$etKanal,':n'=>$no])===2,'paket aktarım sayısı yanlış');
    et_test_yeni($yonetici,$etKanal,['kimlik'=>$no.'-TUMU','numara'=>$no]);
    bekle((int) tek($pdo,'SELECT COUNT(*) FROM M_ET_SIPARIS WHERE KANAL_ID=:k AND HARICI_NO=:n',[':k'=>$etKanal,':n'=>$no])===3,'paketli sipariş yeniden paketsiz alındı');
});
function et_test_api_al(Tarayici $t,int $kanal,string $bicim,array $v): string
{
    $yol=tempnam(sys_get_temp_dir(),'et_api_');
    try {
        file_put_contents($yol,json_encode($v));
        $g=$t->istekDosya('eticaret/ice_aktar.php',['csrf_token'=>$t->belirtec('eticaret/ice_aktar.php'),
            'islem'=>'yukle','kanal'=>$kanal,'bicim'=>$bicim],'dosya',$yol,'sentetik-api.json')['govde'];
        preg_match('/name="anahtar" value="([a-f0-9]+)"/',$g,$m);
        bekle(isset($m[1]),'API önizlemesi yok: '.mb_substr(strip_tags(preg_replace('#<style.*?</style>#s','',$g)),0,700));
        return $t->istek('eticaret/ice_aktar.php',['csrf_token'=>$t->belirtec('eticaret/ice_aktar.php'),'islem'=>'kaydet','anahtar'=>$m[1]])['govde'];
    } finally { unlink($yol); }
}
foreach (['trendyol_v2'=>'trendyol','hepsiburada_paket'=>'hepsiburada'] as $bicim=>$tur) {
    akis($tur.': API yanıtı, indirim/KDV, aktarım ve sonradan iptal izi', function () use ($pdo,$yonetici,$bicim,$tur): void {
        $ad='API '.$tur.' '.bin2hex(random_bytes(4));
        $yonetici->istek('eticaret/kanallar.php',['csrf_token'=>$yonetici->belirtec('eticaret/kanallar.php'),
            'ad'=>$ad,'tur'=>$tur,'aktif'=>1,'cari'=>4,'depo'=>0,'personel'=>1]);
        $kanal=(int) tek($pdo,'SELECT ID FROM M_ET_KANAL WHERE AD=:a',[':a'=>$ad]);
        $ornek=json_decode(file_get_contents(dirname(__DIR__,2).'/docs/eticaret/'.$bicim.'.json'),true);
        bekle(str_contains(et_test_api_al($yonetici,$kanal,$bicim,$ornek),'Yeni: 1'),'API alımı başarısız');
        bekle(str_contains(et_test_api_al($yonetici,$kanal,$bicim,$ornek),'Mükerrer: 1'),'API tekrarı çoğaldı');
        $id=(int) tek($pdo,'SELECT ID FROM M_ET_SIPARIS WHERE KANAL_ID=:k',[':k'=>$kanal]);
        $kod=(string) tek($pdo,'SELECT CODE FROM LG_001_ITEMS WHERE LOGICALREF=2');
        $yonetici->istek('eticaret/eslemeler.php',['csrf_token'=>$yonetici->belirtec('eticaret/eslemeler.php?kanal='.$kanal),
            'kanal'=>$kanal,'tur'=>'sku','deger'=>'DEMO-SKU-1','stok_kod'=>$kod,'birim'=>'ADET']);
        $kontrol=$yonetici->istek('eticaret/detay.php',et_test_form($yonetici,$id,'kontrol'));
        preg_match('#<p class="bildirim hata">(.*?)</p>#s',$kontrol['govde'],$kontrolHatasi);
        bekle(tek($pdo,'SELECT DURUM FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$id])==='hazir',
            'API hazırlığı başarısız: HTTP '.$kontrol['kod'].' '.strip_tags($kontrolHatasi[1] ?? '').' '.tek($pdo,'SELECT SON_HATA FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$id]));
        $yonetici->istek('eticaret/detay.php',et_test_form($yonetici,$id,'aktar'));
        $fis=(int) tek($pdo,'SELECT FIS_ID FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$id]);
        bekle($fis>0,'API kaydı aktarılamadı: '.tek($pdo,'SELECT SON_HATA FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$id]));
        yakin(120,(float) tek($pdo,'SELECT NETTOTAL FROM LG_001_02_ORFICHE WHERE LOGICALREF=:i',[':i'=>$fis]),'API toplamı / indirim ikinci kez uygulanmadı');
        $iptal=$ornek;
        if ($tur==='trendyol') {
            $iptal['content'][0]['shipmentPackageStatus']='Cancelled';$iptal['content'][0]['status']='Cancelled';
            $iptal['content'][0]['lastModifiedDate']+=1000;
        } else { $iptal[0]['status']='CancelledByCustomer';$iptal[0]['lastStatusUpdateDate']='2026-10-25T13:01:01'; }
        et_test_api_al($yonetici,$kanal,$bicim,$iptal);
        bekle(tek($pdo,'SELECT DURUM FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$id])==='aktarildi','kaynak iptali Lumen durumunu değiştirdi');
        bekle((string) tek($pdo,'SELECT KAYNAK_ENGELI FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$id])!=='','iptal izi saklanmadı');
        $g=$yonetici->istek('eticaret/detay.php?id='.$id)['govde'];
        bekle(str_contains($g,'Lumen siparişi otomatik değiştirilmedi'),'sonradan iptal uyarısı görünmüyor');
        bekle(str_contains(et_test_api_al($yonetici,$kanal,$bicim,$ornek),'Eski kaynak: 1'),'eski yanıt ayıklanmadı');
        bekle((string) tek($pdo,'SELECT KAYNAK_ENGELI FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$id])!=='','eski yanıt iptal engelini kaldırdı');
        // Yeni sipariş: iptal güncellemesi daha önceki Hazır onayla aşılamaz.
        $v=$ornek;
        if ($tur==='trendyol') { $v['content'][0]['shipmentPackageId']='9000000002';$v['content'][0]['orderNumber']='TY-DEMO-0002'; }
        else { $v[0]['packageNumber']='HB-DEMO-PAKET-0002';$v[0]['items'][0]['orderNumber']='HB-DEMO-0002'; }
        et_test_api_al($yonetici,$kanal,$bicim,$v);
        $id=(int) tek($pdo,'SELECT MAX(ID) FROM M_ET_SIPARIS WHERE KANAL_ID=:k',[':k'=>$kanal]);
        $yonetici->istek('eticaret/detay.php',et_test_form($yonetici,$id,'kontrol'));
        $eskiOnay=et_test_form($yonetici,$id,'aktar');
        if ($tur==='trendyol') { $v['content'][0]['shipmentPackageStatus']='Cancelled';$v['content'][0]['status']='Cancelled';$v['content'][0]['lastModifiedDate']+=1000; }
        else { $v[0]['status']='CancelledByCustomer';$v[0]['lastStatusUpdateDate']='2026-10-25T13:01:01'; }
        et_test_api_al($yonetici,$kanal,$bicim,$v);
        $f=et_test_form($yonetici,$id,'kontrol');$f['cakisma_onay']='1';$yonetici->istek('eticaret/detay.php',$f);
        $yonetici->istek('eticaret/detay.php',$eskiOnay);
        bekle(tek($pdo,'SELECT FIS_ID FROM M_ET_SIPARIS WHERE ID=:i',[':i'=>$id])===null,'iptal engeli orijinal onayla aşıldı');
    });
}
$basarisiz = count(array_filter($sonuclar, static fn($r) => !$r[0]));
printf("%d e-ticaret akışı, %d başarısız\n", count($sonuclar), $basarisiz);
exit($basarisiz ? 1 : 0);
