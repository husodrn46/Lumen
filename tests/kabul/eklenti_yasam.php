<?php
declare(strict_types=1);

/** Yalnız sentetik demo: eski şemadan yükseltme ve eklenti yedeğinin geri dönüşü. */
require dirname(__DIR__).'/akis/_ortak.php';
require_once dirname(__DIR__,2).'/includes/kurulum_sema.php';
require_once dirname(__DIR__,2).'/includes/eticaret/ice_aktar.php';
require_once dirname(__DIR__,2).'/includes/eticaret/anahtar.php';
require_once dirname(__DIR__,2).'/includes/evds/depo.php';
require_once dirname(__DIR__,2).'/includes/yedek/yedek_lib.php';
if (!in_array($DB,['LUMEN_DEMO','LUMEN_BAGIMSIZ'],true)) {
    fwrite(STDERR,"REDDEDİLDİ: yaşam döngüsü testi yalnız iki demo veritabanında çalışır.\n");exit(3);
}
$kok=dirname(__DIR__,2);$firmano=1;
$gecici=sys_get_temp_dir().'/lumen-yasam-'.bin2hex(random_bytes(8));mkdir($gecici,0700);
$oncekiAnahtar=getenv('LUMEN_EVDS_ANAHTAR_DOSYASI');putenv('LUMEN_EVDS_ANAHTAR_DOSYASI='.$gecici.'/master');
$oncekiEtAnahtar=getenv('LUMEN_ETICARET_ANAHTAR_DOSYASI');putenv('LUMEN_ETICARET_ANAHTAR_DOSYASI='.$gecici.'/et-master');

function yasam_sema(PDO $db, array $dosyalar): void
{
    foreach ($dosyalar as $dosya) {
        foreach (kurulum_sql_batchleri((string) file_get_contents($dosya)) as $batch) { $db->exec($batch); }
    }
}

/** Rowversion geri yüklenirken yeniden üretilir; kalıcı iş verisi birebir karşılaştırılır. */
function yasam_kutu_ozeti(PDO $db): array
{
    $ozet=[];
    foreach (['M_ET_KANAL','M_ET_ICE_AKTARIM','M_ET_SIPARIS','M_ET_SIPARIS_SATIR','M_ET_URUN_ESLEME','M_ET_OLAY','M_ET_BAGLANTI','M_ET_CEKIM','M_ET_CEKIM_SAYFA'] as $tablo) {
        $sira=$tablo==='M_ET_BAGLANTI' ? 'KANAL_ID' : 'ID';
        $satirlar=$db->query("SELECT * FROM dbo.{$tablo} ORDER BY {$sira}")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($satirlar as &$r) { if (in_array($tablo,['M_ET_KANAL','M_ET_SIPARIS'],true)) { unset($r['SURUM']); } }unset($r);
        $ozet[$tablo]=hash('sha256',json_encode($satirlar,JSON_THROW_ON_ERROR));
    }
    return $ozet;
}

try {
    akis('Beta.1 → güncel: ham veri, eşleme ve yetkiler korunur; yeni şema iki kez uygulanır',function () use ($pdo,$kok): void {
        $ad='LUMEN_KABUL_UP_'.strtoupper(bin2hex(random_bytes(6)));$olustu=false;$eski=null;
        try {
            $pdo->exec("CREATE DATABASE [{$ad}]");$olustu=true;
            $eski=new PDO('sqlsrv:server='.(getenv('AKL_DB_SERVER') ?: 'db').';database='.$ad.';TrustServerCertificate=1',
                getenv('AKL_DB_USER') ?: 'sa',getenv('AKL_DB_PASS') ?: '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            yasam_sema($eski,[$kok.'/tests/fixtures/guncelleme/beta1/m_p_yetki.sql',$kok.'/tests/fixtures/guncelleme/beta1/m_eticaret.sql']);
            $eski->exec('INSERT INTO M_P_YETKI (PERSONEL,YETKI,M1,M33) VALUES (1,1,1,1)');
            $eski->exec("INSERT INTO M_ET_KANAL (FIRMA,AD,TUR,OLUSTURAN,GUNCELLEYEN) VALUES (1,N'Sentetik eski kanal','site',1,1)");
            yasam_sema($eski,[$kok.'/tests/fixtures/guncelleme/trendyol/m_eticaret_baglanti.sql']);
            $eski->exec("INSERT INTO M_ET_KANAL (FIRMA,AD,TUR,OLUSTURAN,GUNCELLEYEN) VALUES (1,N'Sentetik eski Trendyol','trendyol',1,1)");
            $eskiSir=['anahtar'=>str_repeat('j',16),'gizli'=>str_repeat('k',16)];$eskiSifre=et_sir_sifrele($eskiSir,1,2);
            $eski->prepare("INSERT INTO M_ET_BAGLANTI (KANAL_ID,ORTAM,SATICI_ID,SIFRELI,SURUM,GUNCELLEYEN) VALUES (2,'stage','123',:s,:v,1)")->execute([':s'=>$eskiSifre,':v'=>str_repeat('a',32)]);
            $eskiCekim=lumen_insert_kimlik($eski,"INSERT INTO M_ET_CEKIM (KANAL_ID,BAG_SURUM,FILTRE,IMLEC,KULLANICI,SON_KULLANICI) VALUES (2,:v,N'{}',N'opaque-eski-cursor',1,1)",[':v'=>str_repeat('a',32)],'ID');
            $eski->prepare("INSERT INTO M_ET_ICE_AKTARIM (KANAL_ID,DOSYA_ADI,TUR,OZET,HAM,KULLANICI) VALUES (1,'eski.json','json',:o,:h,1)")->execute([':o'=>str_repeat('a',64),':h'=>'{"sentetik":true}']);
            $ham='{"numara":"ESKI-1","sentetik":true}';
            $eski->prepare("INSERT INTO M_ET_SIPARIS (KANAL_ID,AKTARIM_ID,HARICI_ID,HARICI_NO,HAM,NORMAL,ANAHTAR,OLUSTURAN) VALUES (1,1,'ESKI-1','ESKI-1',:h,:n,:a,1)")->execute([':h'=>$ham,':n'=>$ham,':a'=>str_repeat('b',64)]);
            $eski->exec("INSERT INTO M_ET_URUN_ESLEME (KANAL_ID,TUR,DEGER,STOK_ID,BIRIM,CARPAN,KULLANICI) VALUES (1,'sku','ESKI-SKU',123,'ADET',1,1)");
            $dosyalar=glob($kok.'/sql/m_eticaret*.sql') ?: [];sort($dosyalar);$dosyalar[]=$kok.'/sql/m_evds.sql';
            yasam_sema($eski,$dosyalar);evds_baglanti_kaydet($eski,1,1,str_repeat('c',32));yasam_sema($eski,$dosyalar);
            $r=$eski->query('SELECT * FROM M_ET_SIPARIS WHERE ID=1')->fetch(PDO::FETCH_ASSOC);
            bekle($r['HAM']===$ham && $r['NORMAL']===$ham && $r['DURUM']==='yeni','eski sipariş değişti');
            bekle($r['PAKET_KIMLIGI']==='' && $r['KAYNAK']==='lumen','eski siparişin varsayılanları yanlış');
            bekle((int) tek($eski,'SELECT M33 FROM M_P_YETKI WHERE PERSONEL=1')===1 && (int) tek($eski,'SELECT M34 FROM M_P_YETKI WHERE PERSONEL=1')===0,'yetkiler değişti');
            bekle((int) tek($eski,'SELECT STOK_ID FROM M_ET_URUN_ESLEME WHERE ID=1')===123,'eşleme kayboldu');
            $bag=$eski->query('SELECT * FROM M_ET_BAGLANTI WHERE KANAL_ID=2')->fetch(PDO::FETCH_ASSOC);
            bekle($bag['TUR']==='trendyol' && $bag['SATICI_ID']==='123' && $bag['SIFRELI']===$eskiSifre && et_sir_coz($bag['SIFRELI'],1,2)===$eskiSir,'eski Trendyol bağlantısı değişti');
            bekle(tek($eski,'SELECT IMLEC FROM M_ET_CEKIM WHERE ID=:i',[':i'=>$eskiCekim])==='opaque-eski-cursor','eski cursor kayboldu');
            bekle(evds_sifre_coz(evds_baglanti($eski,1,1)['SIFRELI_ANAHTAR'],evds_ana_anahtar(),'1:1')===str_repeat('c',32),'EVDS bağlantısı tekrar şemada değişti');
            $ekle=$eski->prepare("INSERT INTO M_ET_SIPARIS (KANAL_ID,AKTARIM_ID,HARICI_ID,HARICI_NO,PAKET_KIMLIGI,HAM,NORMAL,ANAHTAR,OLUSTURAN) VALUES (1,1,:i,'PAKETLI-1','A','{}','{}',:a,1)");
            $ekle->execute([':i'=>'P-A',':a'=>str_repeat('d',64)]);
            $reddedildi=false;try { $ekle->execute([':i'=>'P-B',':a'=>str_repeat('e',64)]); } catch (PDOException) { $reddedildi=true; }
            bekle($reddedildi,'aynı kanal/sipariş/paket için veritabanı benzersizliği yok');
        } finally {
            $eski=null;
            if ($olustu) { $pdo->exec("ALTER DATABASE [{$ad}] SET SINGLE_USER WITH ROLLBACK IMMEDIATE");$pdo->exec("DROP DATABASE [{$ad}]"); }
        }
    });

    akis('Dolu kurulum: tekrar şema ve yedekten dönüş kutuyu, aktarım kimliğini ve EVDS bağlantısını korur',function () use ($pdo,$kok): void {
        $kanal=0;$hbKanal=0;$u=random_int(1000000,2000000000);$yol=null;$onceki=null;$bozuldu=false;$dondu=false;
        try {
            $kanal=lumen_insert_kimlik($pdo,"INSERT INTO M_ET_KANAL (FIRMA,AD,TUR,OLUSTURAN,GUNCELLEYEN) VALUES (1,N'Sentetik yaşam döngüsü','trendyol',1,1)",[],'ID');
            $alim=et_ice_aktar($pdo,$kanal,(string) file_get_contents($kok.'/docs/eticaret/trendyol_v2.json'),'json','sentetik.json',1,'trendyol_v2');
            $sir=['anahtar'=>str_repeat('g',16),'gizli'=>str_repeat('h',16)];
            $pdo->prepare("INSERT INTO M_ET_BAGLANTI (KANAL_ID,ORTAM,SATICI_ID,SIFRELI,SURUM,GUNCELLEYEN) VALUES (:k,'stage','123',:s,:v,1)")->execute([':k'=>$kanal,':s'=>et_sir_sifrele($sir,1,$kanal),':v'=>str_repeat('a',32)]);
            $cekim=lumen_insert_kimlik($pdo,"INSERT INTO M_ET_CEKIM (KANAL_ID,BAG_SURUM,FILTRE,IMLEC,SAYFA,KULLANICI,SON_KULLANICI) VALUES (:k,:v,N'{}',N'opaque+/= sentetik',1,1,1)",[':k'=>$kanal,':v'=>str_repeat('a',32)],'ID');
            $pdo->prepare('INSERT INTO M_ET_CEKIM_SAYFA (CEKIM_ID,SIRA,ISTEK_OZET,AKTARIM_ID) VALUES (:i,1,:h,:a)')->execute([':i'=>$cekim,':h'=>hash('sha256',''),':a'=>$alim['aktarim_id']]);
            $hbKanal=lumen_insert_kimlik($pdo,"INSERT INTO M_ET_KANAL (FIRMA,AD,TUR,OLUSTURAN,GUNCELLEYEN) VALUES (1,N'Sentetik Hepsiburada yaşam döngüsü','hepsiburada',1,1)",[],'ID');
            $hbAlim=et_ice_aktar($pdo,$hbKanal,(string) file_get_contents($kok.'/docs/eticaret/hepsiburada_paket.json'),'json','sentetik-hb.json',1,'hepsiburada_paket');
            $hbSir=['anahtar'=>'sentetik-api-kullanici','gizli'=>str_repeat('p',32)];
            $pdo->prepare("INSERT INTO M_ET_BAGLANTI (KANAL_ID,ORTAM,SATICI_ID,TUR,ISTEMCI,SIFRELI,SURUM,GUNCELLEYEN) VALUES (:k,'stage','00000000-0000-4000-8000-000000000123','hepsiburada','Lumen_Demo',:s,:v,1)")->execute([':k'=>$hbKanal,':s'=>et_sir_sifrele($hbSir,1,$hbKanal),':v'=>str_repeat('b',32)]);
            $hbCekim=lumen_insert_kimlik($pdo,"INSERT INTO M_ET_CEKIM (KANAL_ID,BAG_SURUM,FILTRE,IMLEC,SAYFA,KULLANICI,SON_KULLANICI) VALUES (:k,:v,N'{}',:c,1,1,1)",[':k'=>$hbKanal,':v'=>str_repeat('b',32),':c'=>'{"offset":10,"total":11}'],'ID');
            $pdo->prepare('INSERT INTO M_ET_CEKIM_SAYFA (CEKIM_ID,SIRA,ISTEK_OZET,AKTARIM_ID,VERI_OZET) VALUES (:i,1,:h,:a,:v)')->execute([':i'=>$hbCekim,':h'=>hash('sha256',''),':a'=>$hbAlim['aktarim_id'],':v'=>hash('sha256','sentetik-hb-paket')]);
            // Gerçek sipariş üretimi eticaret.php akışında sınanır; burada aktarım bağlantısının kalıcılığı sınanır.
            $pdo->prepare("UPDATE M_ET_SIPARIS SET DURUM='aktarildi',FIS_ID=987654,FIS_NO='SENTETIK-AKTARIM',AKTARILMA=SYSDATETIME(),AKTARAN=1 WHERE KANAL_ID=:k")->execute([':k'=>$kanal]);
            $pdo->prepare("INSERT INTO M_ET_URUN_ESLEME (KANAL_ID,TUR,DEGER,STOK_ID,BIRIM,CARPAN,KULLANICI) VALUES (:k,'sku','SENTETIK-YASAM',123,'ADET',1,1)")->execute([':k'=>$kanal]);
            $api=str_repeat('f',32);evds_baglanti_kaydet($pdo,1,$u,$api);
            $ornek=json_decode((string) file_get_contents($kok.'/docs/evds/sentetik.json'),true,32,JSON_THROW_ON_ERROR);
            $q=['seri'=>'TP.LUMEN.DEMO','bas'=>'2024-01-01','bit'=>'2024-06-30'];
            evds_getir($pdo,1,$u,$q,static fn(string $url): string=>json_encode(str_contains($url,'serieList/') ? $ornek['meta'] : $ornek['veri'],JSON_THROW_ON_ERROR));
            $ozet=yasam_kutu_ozeti($pdo);$master=evds_ana_anahtar();$etMaster=et_ana_anahtar();
            for ($i=0;$i<2;$i++) { $r=kurulum_sema_uygula($pdo,$kok.'/sql');bekle($r['hatalar']===[],'dolu kurulumda şema hatası'); }
            bekle(yasam_kutu_ozeti($pdo)===$ozet,'tekrar şema kutu verisini değiştirdi');
            $y=lumen_yedek_al($pdo,'elle');bekle($y['ok'],$y['mesaj']);$yol=lumen_yedek_klasoru().'/'.$y['ad'];
            $icerik=gzdecode((string) file_get_contents($yol));bekle(is_string($icerik) && !str_contains($icerik,$api) && !str_contains($icerik,base64_encode($master)),'SQL yedeğine düz anahtar yazıldı');
            bekle(!str_contains($icerik,$sir['gizli']) && !str_contains($icerik,$hbSir['gizli']) && !str_contains($icerik,base64_encode($etMaster)),'SQL yedeğine e-ticaret sırrı yazıldı');
            $bozuldu=true;
            $pdo->prepare("UPDATE M_ET_SIPARIS SET HAM=N'{}',NORMAL=N'{}',FIS_NO='SENTETIK-BOZUK' WHERE KANAL_ID=:k")->execute([':k'=>$kanal]);
            $pdo->prepare("UPDATE M_EVDS_BAGLANTI SET SIFRELI_ANAHTAR='bozuk' WHERE FIRMA=1 AND KULLANICI=:u")->execute([':u'=>$u]);
            $pdo->prepare('DELETE FROM M_EVDS_ONBELLEK WHERE FIRMA=1 AND KULLANICI=:u')->execute([':u'=>$u]);
            $pdo->prepare("UPDATE M_ET_BAGLANTI SET SIFRELI='bozuk' WHERE KANAL_ID=:k")->execute([':k'=>$kanal]);
            $pdo->prepare('UPDATE M_ET_CEKIM SET IMLEC=NULL,SAYFA=0 WHERE ID=:i')->execute([':i'=>$cekim]);
            $pdo->prepare("UPDATE M_ET_BAGLANTI SET SIFRELI='bozuk' WHERE KANAL_ID=:k")->execute([':k'=>$hbKanal]);
            $pdo->prepare('UPDATE M_ET_CEKIM SET IMLEC=NULL,SAYFA=0 WHERE ID=:i')->execute([':i'=>$hbCekim]);
            $g=lumen_yedek_geri_yukle($pdo,$yol);$onceki=$g['onceki'] ?? null;bekle($g['ok'],$g['mesaj']);$dondu=true;
            bekle(yasam_kutu_ozeti($pdo)===$ozet,'kutu verisi veya aktarım kimliği geri dönmedi');
            bekle(evds_ana_anahtar()===$master,'SQL geri yükleme ana anahtarı değiştirdi');
            bekle(et_ana_anahtar()===$etMaster,'SQL geri yükleme e-ticaret ana anahtarını değiştirdi');
            bekle(et_sir_coz((string) tek($pdo,'SELECT SIFRELI FROM M_ET_BAGLANTI WHERE KANAL_ID=:k',[':k'=>$kanal]),1,$kanal)===$sir,'e-ticaret bağlantısı geri dönmedi');
            bekle(et_sir_coz((string) tek($pdo,'SELECT SIFRELI FROM M_ET_BAGLANTI WHERE KANAL_ID=:k',[':k'=>$hbKanal]),1,$hbKanal)===$hbSir,'Hepsiburada bağlantısı geri dönmedi');
            $sifre=evds_baglanti($pdo,1,$u)['SIFRELI_ANAHTAR'];
            bekle(evds_sifre_coz($sifre,$master,'1:'.$u)===$api,'şifreli bağlantı geri dönmedi');
            bekle(evds_onbellek($pdo,1,$u,evds_sorgu_ozeti($q))!==null,'EVDS önbelleği kayboldu');
            $reddedildi=false;try { evds_sifre_coz($sifre,random_bytes(32),'1:'.$u); } catch (RuntimeException) { $reddedildi=true; }
            bekle($reddedildi,'farklı ana anahtarla bağlantı açıldı');
        } finally {
            if ($bozuldu && !$dondu && $yol) { $g=lumen_yedek_geri_yukle($pdo,$yol);$onceki=$g['onceki'] ?? $onceki; }
            foreach (array_filter([$kanal,$hbKanal]) as $temizlenecek) {
                $pdo->prepare('DELETE FROM M_ET_CEKIM_SAYFA WHERE CEKIM_ID IN (SELECT ID FROM M_ET_CEKIM WHERE KANAL_ID=:k)')->execute([':k'=>$temizlenecek]);
                foreach (['M_ET_CEKIM','M_ET_BAGLANTI','M_ET_OLAY','M_ET_URUN_ESLEME','M_ET_SIPARIS_SATIR','M_ET_SIPARIS','M_ET_ICE_AKTARIM','M_ET_KANAL'] as $tablo) {
                    $kosul=$tablo==='M_ET_KANAL' ? 'ID=:k' : ($tablo==='M_ET_SIPARIS_SATIR' ? 'SIPARIS_ID IN (SELECT ID FROM M_ET_SIPARIS WHERE KANAL_ID=:k)' : 'KANAL_ID=:k');
                    $pdo->prepare("DELETE FROM {$tablo} WHERE {$kosul}")->execute([':k'=>$temizlenecek]);
                }
            }
            foreach (['M_EVDS_ONBELLEK','M_EVDS_BAGLANTI'] as $tablo) { $pdo->prepare("DELETE FROM {$tablo} WHERE FIRMA=1 AND KULLANICI=:u")->execute([':u'=>$u]); }
            foreach ([$yol,$onceki ? lumen_yedek_klasoru().'/'.$onceki : null] as $f) { if ($f && is_file($f)) { unlink($f); } }
        }
    });
} finally {
    putenv($oncekiAnahtar===false ? 'LUMEN_EVDS_ANAHTAR_DOSYASI' : 'LUMEN_EVDS_ANAHTAR_DOSYASI='.$oncekiAnahtar);
    putenv($oncekiEtAnahtar===false ? 'LUMEN_ETICARET_ANAHTAR_DOSYASI' : 'LUMEN_ETICARET_ANAHTAR_DOSYASI='.$oncekiEtAnahtar);
    foreach (glob($gecici.'/*') ?: [] as $f) { unlink($f); }rmdir($gecici);
}
$hata=count(array_filter($sonuclar,static fn(array $r): bool=>!$r[0]));
printf("%d eklenti yaşam döngüsü akışı, %d başarısız\n",count($sonuclar),$hata);exit($hata ? 1 : 0);
