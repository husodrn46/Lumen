<?php
declare(strict_types=1);
require __DIR__.'/_ortak.php';
require_once dirname(__DIR__,2).'/includes/evds/depo.php';

$evdsDizin=sys_get_temp_dir().'/lumen-evds-test-'.bin2hex(random_bytes(8));mkdir($evdsDizin,0700);
$eskiDosya=getenv('LUMEN_EVDS_ANAHTAR_DOSYASI');putenv('LUMEN_EVDS_ANAHTAR_DOSYASI='.$evdsDizin.'/master');
$u=random_int(1000000,2000000000);$diger=$u+1;
$q=['seri'=>'TP.LUMEN.DEMO','bas'=>'2024-01-01','bit'=>'2024-06-30'];
$ornek=json_decode(file_get_contents(dirname(__DIR__,2).'/docs/evds/sentetik.json'),true);
$tasiyici=static function (string $url) use ($ornek): string { return json_encode(str_contains($url,'serieList/') ? $ornek['meta'] : $ornek['veri']); };
try {
    akis('EVDS: kişi/firma izolasyonu, şifreli saklama ve bağlantıyı kaldırma',function () use ($pdo,$u,$diger): void {
        $api=str_repeat('a',32);
        evds_baglanti_kaydet($pdo,1,$u,$api);evds_baglanti_kaydet($pdo,1,$diger,$api);
        $bag=evds_baglanti($pdo,1,$u);bekle($bag!==null,'bağlantı kaydedilmedi');
        bekle(!str_contains($bag['SIFRELI_ANAHTAR'],$api),'anahtar düz metin');
        bekle(evds_baglanti($pdo,2,$u)===null,'başka firma bağlantıyı gördü');
        bekle(evds_sifre_coz($bag['SIFRELI_ANAHTAR'],evds_ana_anahtar(),'1:'.$u)===$api,'şifre çözülmedi');
        evds_baglanti_kaydet($pdo,1,$diger,null);bekle(evds_baglanti($pdo,1,$u)!==null,'diğer kişinin bağlantısı silindi');
    });
    akis('EVDS: aynı sorgu günlük önbellekten okunur, anahtar değişimi cache temizler',function () use ($pdo,$u,$q,$tasiyici): void {
        $n=0;$oku=static function ($url) use (&$n,$tasiyici): string { $n++;return $tasiyici($url); };
        $v=evds_getir($pdo,1,$u,$q,$oku);bekle($n===2,'metadata ve veri çağrısı bekleniyordu');
        $ikinci=evds_getir($pdo,1,$u,$q,$oku);bekle($n===2,'günlük cache tekrar dış istek yaptı');
        yakin(12.5,(float) $ikinci['veri']['ozet']['degisim'],'dönem değişimi');
        bekle($v['eski']===false,'yeni veri eski işaretlendi');
        evds_baglanti_kaydet($pdo,1,$u,'degistirilmis-sentetik-anahtar-12345');
        bekle(evds_onbellek($pdo,1,$u,evds_sorgu_ozeti($q))===null,'anahtar değişiminde eski cache kaldı');
    });
    akis('EVDS: erişim kesilince tarihli eski sonuç korunur; hızlı yeniden deneme durur',function () use ($pdo,$u,$q,$tasiyici): void {
        evds_getir($pdo,1,$u,$q,$tasiyici);
        $pdo->prepare('UPDATE M_EVDS_ONBELLEK SET ALINMA=:a,SON_DENEME=:d WHERE FIRMA=1 AND KULLANICI=:u')->execute([':a'=>date('Y-m-d H:i:s',strtotime('-2 days')),':d'=>date('Y-m-d H:i:s',strtotime('-2 days')),':u'=>$u]);
        $n=0;$hata=static function () use (&$n): string { $n++;throw new DomainException('Sentetik bağlantı kesintisi.'); };
        $v=evds_getir($pdo,1,$u,$q,$hata);bekle($v['eski'] && $v['uyari']==='Sentetik bağlantı kesintisi.','eski veri uyarısı yok');
        $reddedildi=false;try { evds_getir($pdo,1,$u,$q,$hata); } catch (DomainException) { $reddedildi=true; }
        bekle($reddedildi && $n===1,'aynı başarısız sorgu hemen yeniden gönderildi');
        evds_baglanti_kaydet($pdo,1,$u,null);bekle(evds_onbellek($pdo,1,$u,evds_sorgu_ozeti($q))===null,'kaldırmada cache kaldı');
    });
} finally {
    foreach ([$u,$diger] as $id) {
        $pdo->prepare('DELETE FROM M_EVDS_ONBELLEK WHERE FIRMA=1 AND KULLANICI=:u')->execute([':u'=>$id]);
        $pdo->prepare('DELETE FROM M_EVDS_BAGLANTI WHERE FIRMA=1 AND KULLANICI=:u')->execute([':u'=>$id]);
    }
    putenv($eskiDosya===false ? 'LUMEN_EVDS_ANAHTAR_DOSYASI' : 'LUMEN_EVDS_ANAHTAR_DOSYASI='.$eskiDosya);
    foreach (glob($evdsDizin.'/*') ?: [] as $dosya) { unlink($dosya); }rmdir($evdsDizin);
}
$yonetici=(new Tarayici($URL))->giris('YONETICI');
$satisId=(int) tek($pdo,"SELECT LOGICALREF FROM LG_SLSMAN WHERE CODE='SATIS1'");
$yoneticiId=(int) tek($pdo,"SELECT LOGICALREF FROM LG_SLSMAN WHERE CODE='YONETICI'");
$eskiYetki=tek($pdo,'SELECT M34 FROM M_P_YETKI WHERE PERSONEL=:u',[':u'=>$satisId]);
$yedek=[];
foreach ([$yoneticiId,$satisId] as $id) {
    foreach (['M_EVDS_BAGLANTI','M_EVDS_ONBELLEK'] as $tablo) {
        $s=$pdo->prepare("SELECT * FROM {$tablo} WHERE FIRMA=1 AND KULLANICI=:u");$s->execute([':u'=>$id]);$yedek[$id][$tablo]=$s->fetchAll(PDO::FETCH_ASSOC);$s->closeCursor();
        $pdo->prepare("DELETE FROM {$tablo} WHERE FIRMA=1 AND KULLANICI=:u")->execute([':u'=>$id]);
    }
}
try {
    akis('EVDS ekranı: M34, CSRF ve girişsiz erişim korunur',function () use ($pdo,$yonetici,$satisId,$URL): void {
        $pdo->prepare('UPDATE M_P_YETKI SET M34=0 WHERE PERSONEL=:u')->execute([':u'=>$satisId]);
        $t=(new Tarayici($URL))->giris('SATIS1');
        bekle($t->istek('ekonomi/index.php')['kod']===403,'M34 olmadan panel açıldı');
        bekle($t->istek('ekonomi/baglanti.php',['islem'=>'kaydet','evds_api_key'=>str_repeat('b',32)])['kod']===403,'yetkisiz anahtar kaydı');
        bekle($yonetici->istek('ekonomi/baglanti.php',['islem'=>'kaydet','evds_api_key'=>str_repeat('b',32)])['kod']===403,'CSRF atlandı');
        $anonim=(new Tarayici($URL))->istek('ekonomi/index.php');bekle($anonim['kod']===303 && str_contains($anonim['yer'],'giris.php'),'oturumsuz panel açıldı');
        bekle(in_array($t->istek('database/evds-master.key')['kod'],[403,404],true),'ana anahtar web erişimine açık');
    });
    akis('EVDS ekranı: anahtar boş forma döner, diğer kullanıcıya yazılamaz',function () use ($pdo,$yonetici,$yoneticiId,$satisId,$URL): void {
        $fake='yalniz-sentetik-web-anahtari-123456';
        $r=$yonetici->istek('ekonomi/baglanti.php',['csrf_token'=>$yonetici->belirtec('ekonomi/baglanti.php'),'islem'=>'kaydet','evds_api_key'=>$fake,'kullanici'=>$satisId]);
        bekle($r['kod']===303,'anahtar kaydedilemedi');
        $g=$yonetici->istek('ekonomi/baglanti.php')['govde'];bekle(!str_contains($g,$fake),'anahtar forma basıldı');
        bekle(evds_baglanti($pdo,1,$yoneticiId)!==null && evds_baglanti($pdo,1,$satisId)===null,'POST kullanıcı kimliği dikkate alındı');
        $pdo->prepare('UPDATE M_P_YETKI SET M34=1 WHERE PERSONEL=:u')->execute([':u'=>$satisId]);$t=(new Tarayici($URL))->giris('SATIS1');
        $g=$t->istek('ekonomi/baglanti.php')['govde'];bekle(str_contains($g,'Anahtar tanımlanmadı'),'diğer kullanıcı aynı bağlantıyı gördü');
        $r=$yonetici->istek('ekonomi/baglanti.php',['csrf_token'=>$yonetici->belirtec('ekonomi/baglanti.php'),'islem'=>'kaldir']);bekle($r['kod']===303,'anahtar kaldırılamadı');
    });
    akis('EVDS ekranı: anahtarsız sentetik örnek, erişilebilir grafik ve CSV',function () use ($yonetici): void {
        $r=$yonetici->istek('ekonomi/index.php',['csrf_token'=>$yonetici->belirtec('ekonomi/index.php'),'islem'=>'ornek']);bekle($r['kod']===303,'örnek açılmadı');
        $g=$yonetici->istek('ekonomi/index.php')['govde'];bekle(str_contains($g,'Bu değerler TCMB verisi değildir.'),'demo verisi gerçek gibi göründü');
        bekle(str_contains($g,'role="img"') && str_contains($g,'Eksik gözlem'),'grafik veya eksik gözlem açıklaması yok');
        $r=$yonetici->istek('ekonomi/index.php',['csrf_token'=>$yonetici->belirtec('ekonomi/index.php'),'islem'=>'csv']);
        bekle($r['kod']===200 && str_contains($r['govde'],'2024-03;;') && str_contains($r['govde'],'TCMB verisi değildir'),'CSV eksik veya kaynak bilgisi yok');
    });
} finally {
    $pdo->prepare('UPDATE M_P_YETKI SET M34=:m WHERE PERSONEL=:u')->execute([':m'=>$eskiYetki,':u'=>$satisId]);
    foreach ($yedek as $id=>$tablolar) { foreach ($tablolar as $tablo=>$satirlar) {
        $pdo->prepare("DELETE FROM {$tablo} WHERE FIRMA=1 AND KULLANICI=:u")->execute([':u'=>$id]);
        foreach ($satirlar as $r) { $kolonlar=array_keys($r);$sql="INSERT INTO {$tablo} (".implode(',',$kolonlar).') VALUES ('.implode(',',array_fill(0,count($kolonlar),'?')).')';$pdo->prepare($sql)->execute(array_values($r)); }
    } }
}
$basarisiz=count(array_filter($sonuclar,static fn($r)=>!$r[0]));
printf("%d EVDS akışı, %d başarısız\n",count($sonuclar),$basarisiz);exit($basarisiz ? 1 : 0);
