<?php
declare(strict_types=1);
require __DIR__.'/_ortak.php';
if (!in_array($DB,['LUMEN_DEMO','LUMEN_BAGIMSIZ'],true)) { fwrite(STDERR,"REDDEDİLDİ: yalnız sentetik demo veritabanı.\n");exit(3); }
require_once dirname(__DIR__,2).'/ayr.php';
require_once dirname(__DIR__,2).'/includes/eticaret/cekim.php';
$dbh=$pdo;$firmano=1;$kanal=0;
$d=sys_get_temp_dir().'/lumen-hb-akis-'.bin2hex(random_bytes(8));mkdir($d,0700);$eski=getenv('LUMEN_ETICARET_ANAHTAR_DOSYASI');putenv('LUMEN_ETICARET_ANAHTAR_DOSYASI='.$d.'/master');
$bag=['ortam'=>'stage','satici'=>'00000000-0000-4000-8000-000000000123','anahtar'=>'sentetik-api-kullanici','gizli'=>str_repeat('s',32),'istemci'=>'Lumen_Demo'];
$belgeTablo=lumen_bagimsiz_mi() ? 'LM_BELGE' : 'LG_001_02_ORFICHE';$belgeSayisi=(int) tek($pdo,"SELECT COUNT(*) FROM {$belgeTablo}");
function hb_akis_paketler(int $bas, int $adet): array
{
    $ornek=json_decode((string) file_get_contents(dirname(__DIR__,2).'/docs/eticaret/hepsiburada_paket.json'),true)[0];$p=[];
    for ($i=$bas;$i<$bas+$adet;$i++) {
        $v=$ornek;$v['packageNumber']='SENTETIK-HB-'.$i;$v['items'][0]['orderNumber']='HB-API-'.$i;$v['items'][0]['lineItemId']='SENTETIK-KALEM-'.$i;$p[]=$v;
    }
    return $p;
}
function hb_akis_sayfa(array $p, int $toplam, int $offset = 0): array
{
    return ['kod'=>200,'govde'=>et_json($p),'basliklar'=>['limit'=>'10','offset'=>(string) $offset,'totalcount'=>(string) $toplam,'pagecount'=>(string) (int) ceil($toplam/10)]];
}
function hb_akis_hazir(PDO $db, int $k, int $i): void
{
    $db->prepare('UPDATE M_ET_BAGLANTI SET SON_ISTEK=DATEADD(SECOND,-10,SYSUTCDATETIME()) WHERE KANAL_ID=:k')->execute([':k'=>$k]);
    $db->prepare('UPDATE M_ET_CEKIM SET SONRAKI=DATEADD(SECOND,-10,SYSUTCDATETIME()),KILIT_BITIS=NULL WHERE ID=:i')->execute([':i'=>$i]);
}
function hb_akis_baslat(PDO $db, int $k): int
{
    $r=et_cekimi_oku($db,$k);if ($r && $r['DURUM']==='suruyor') { et_cekimi_yonet($db,$k,(int) $r['ID'],1,'durdur'); }
    $n=new DateTimeImmutable('now',new DateTimeZone('Europe/Istanbul'));
    $i=et_cekimi_baslat($db,$k,1,$n->modify('-1 day')->format('Y-m-d\TH:i'),$n->format('Y-m-d\TH:i'));hb_akis_hazir($db,$k,$i);return $i;
}
try {
    $kanal=lumen_insert_kimlik($pdo,"INSERT INTO M_ET_KANAL (FIRMA,AD,TUR,OLUSTURAN,GUNCELLEYEN) VALUES (1,N'Sentetik Hepsiburada alım testi','hepsiburada',1,1)",[],'ID');
    akis('Hepsiburada: UUID, şifreli sırlar, firma ve yetki sınırı',function () use ($pdo,$kanal,$bag): void {
        et_baglanti_kaydet($pdo,$kanal,1,$bag);$b=et_baglanti($pdo,$kanal);
        bekle($b['SATICI_ID']===$bag['satici'] && $b['TUR']==='hepsiburada' && $b['ISTEMCI']===$bag['istemci'],'bağlantı alanları kayboldu');
        bekle(!str_contains($b['SIFRELI'],$bag['gizli']) && et_sir_coz($b['SIFRELI'],1,$kanal)['gizli']===$bag['gizli'],'sırlar şifrelenmedi');
        $GLOBALS['firmano']=2;$red=false;try { et_baglanti($pdo,$kanal); } catch (DomainException) { $red=true; } finally { $GLOBALS['firmano']=1; }bekle($red,'firma sınırı aşıldı');
        $depo=(int) tek($pdo,"SELECT LOGICALREF FROM LG_SLSMAN WHERE CODE='DEPO1'");$red=false;
        try { et_baglanti_kaydet($pdo,$kanal,$depo,$bag); } catch (DomainException) { $red=true; }bekle($red,'yetkisiz kişi bağlantı yazdı');
    });
    akis('Hepsiburada: sabit filtreyle iki sayfa, kalıcı offset ve erken istek engeli',function () use ($pdo,$kanal): void {
        $i=hb_akis_baslat($pdo,$kanal);$n=0;$ilkFiltre=[];
        $r=et_cekimi_adim($pdo,$kanal,$i,1,static function ($istek) use (&$n,&$ilkFiltre): array { $n++;parse_str(parse_url($istek['url'],PHP_URL_QUERY),$ilkFiltre);bekle($ilkFiltre['offset']==='0','ilk offset sıfır değil');return hb_akis_sayfa(hb_akis_paketler(1,10),11); });
        bekle((int) $r['YENI']===10 && json_decode($r['IMLEC'],true)['offset']===10,'ilk sayfa veya offset kayboldu');
        et_cekimi_adim($pdo,$kanal,$i,1,static function () use (&$n): array { $n++;return hb_akis_sayfa([],0); });bekle($n===1,'5 saniye bekleme atlandı');hb_akis_hazir($pdo,$kanal,$i);
        $r=et_cekimi_adim($pdo,$kanal,$i,1,static function ($istek) use (&$n,$ilkFiltre): array { $n++;parse_str(parse_url($istek['url'],PHP_URL_QUERY),$q);bekle($q['offset']==='10' && $q['begindate']===$ilkFiltre['begindate'] && $q['enddate']===$ilkFiltre['enddate'],'filtre/cursor değişti');return hb_akis_sayfa(hb_akis_paketler(11,1),11,10); });
        bekle($r['DURUM']==='tamam' && (int) $r['YENI']===11 && (int) $r['SAYFA']===2,'iki sayfa tamamlanmadı');
        bekle((int) tek($pdo,'SELECT COUNT(*) FROM M_ET_CEKIM_SAYFA WHERE CEKIM_ID=:i AND VERI_OZET IS NOT NULL',[':i'=>$i])===2,'sayfa denetim izi yok');
    });
    akis('Hepsiburada: aynı aralığın yeniden alımı yeni sipariş oluşturmaz',function () use ($pdo,$kanal): void {
        $i=hb_akis_baslat($pdo,$kanal);et_cekimi_adim($pdo,$kanal,$i,1,static fn()=>hb_akis_sayfa(hb_akis_paketler(1,10),11));hb_akis_hazir($pdo,$kanal,$i);
        $r=et_cekimi_adim($pdo,$kanal,$i,1,static fn()=>hb_akis_sayfa(hb_akis_paketler(11,1),11,10));bekle((int) $r['YENI']===0 && (int) $r['MUKERRER']===11,'yeniden alım siparişi çoğalttı');
    });
    akis('Hepsiburada: 429 kalıcı bekleme, 401 durma, aynı sayfayı güvenle yeniden deneme',function () use ($pdo,$kanal): void {
        $i=hb_akis_baslat($pdo,$kanal);$n=0;
        $r=et_cekimi_adim($pdo,$kanal,$i,1,static function () use (&$n): array { $n++;return ['kod'=>429,'govde'=>'gizli-yanit','bekleme'=>120]; });
        bekle((int) $r['DENEME']===1 && (int) $r['SAYFA']===0 && $r['IMLEC']===null && et_api_zaman($r['SONRAKI'])>=time()+119,'hatalı sayfa ilerledi veya bekleme kayboldu');
        et_cekimi_adim($pdo,$kanal,$i,1,static function () use (&$n): array { $n++;return hb_akis_sayfa([],0); });bekle($n===1,'hızlı tekrar çıktı');hb_akis_hazir($pdo,$kanal,$i);
        $r=et_cekimi_adim($pdo,$kanal,$i,1,static fn()=>['kod'=>401,'govde'=>'gizli-yanit']);bekle((int) $r['DENEME']===5 && !str_contains($r['HATA'],'gizli-yanit'),'401 durmadı veya yanıt sızdı');
        et_cekimi_yonet($pdo,$kanal,$i,1,'tekrar');hb_akis_hazir($pdo,$kanal,$i);$r=et_cekimi_adim($pdo,$kanal,$i,1,static fn()=>hb_akis_sayfa([],0));bekle($r['DURUM']==='tamam','boş son sayfa tamamlanamadı');
    });
    akis('Hepsiburada: değişen toplam ve yinelenen paket sayfası sessizce atlanmaz',function () use ($pdo,$kanal): void {
        $i=hb_akis_baslat($pdo,$kanal);et_cekimi_adim($pdo,$kanal,$i,1,static fn()=>hb_akis_sayfa(hb_akis_paketler(1,10),11));hb_akis_hazir($pdo,$kanal,$i);
        $r=et_cekimi_adim($pdo,$kanal,$i,1,static fn()=>hb_akis_sayfa(hb_akis_paketler(11,2),12,10));bekle((int) $r['SAYFA']===1 && (int) $r['DENEME']===5,'değişen listede offset ilerledi');
        $i=hb_akis_baslat($pdo,$kanal);et_cekimi_adim($pdo,$kanal,$i,1,static fn()=>hb_akis_sayfa(hb_akis_paketler(1,10),20));hb_akis_hazir($pdo,$kanal,$i);
        $r=et_cekimi_adim($pdo,$kanal,$i,1,static fn()=>hb_akis_sayfa(hb_akis_paketler(1,10),20,10));bekle((int) $r['SAYFA']===1 && (int) $r['DENEME']===5,'önceki sayfa tekrar alınarak tamamlandı');
    });
    akis('Hepsiburada: kanal türü ve bağlantı değişirse eski sır/yanıt kullanılamaz',function () use ($pdo,$kanal,$bag): void {
        $i=hb_akis_baslat($pdo,$kanal);$n=0;$red=false;
        try {
            $pdo->prepare("UPDATE M_ET_KANAL SET TUR='trendyol' WHERE ID=:k")->execute([':k'=>$kanal]);
            try { et_cekimi_adim($pdo,$kanal,$i,1,static function () use (&$n): array { $n++;return hb_akis_sayfa([],0); }); } catch (DomainException) { $red=true; }
            bekle($red && $n===0,'HB sırları başka adaptöre gönderilebilir');
        } finally { $pdo->prepare("UPDATE M_ET_KANAL SET TUR='hepsiburada' WHERE ID=:k")->execute([':k'=>$kanal]); }
        hb_akis_hazir($pdo,$kanal,$i);$r=et_cekimi_adim($pdo,$kanal,$i,1,static function () use ($pdo,$kanal,$bag): array { et_baglanti_kaydet($pdo,$kanal,1,$bag);return hb_akis_sayfa([],0); });
        bekle($r['DURUM']==='durduruldu' && (int) $r['SAYFA']===0,'bağlantısı değişen geç yanıt ilerledi');
    });
    akis('Hepsiburada: SQL hatası bütün sayfayı geri alır; yeniden deneme tek kayıt üretir',function () use ($pdo,$kanal): void {
        $i=hb_akis_baslat($pdo,$kanal);$ad='TR_HB_TEST_'.strtoupper(bin2hex(random_bytes(6)));$olustu=false;
        $once=(int) tek($pdo,'SELECT COUNT(*) FROM M_ET_ICE_AKTARIM WHERE KANAL_ID=:k',[':k'=>$kanal]);
        try {
            $pdo->exec("CREATE TRIGGER [{$ad}] ON dbo.M_ET_CEKIM_SAYFA AFTER INSERT AS BEGIN IF EXISTS (SELECT 1 FROM inserted WHERE CEKIM_ID={$i}) THROW 51000,N'Sentetik HB kayıt hatası',1; END");$olustu=true;
            $r=et_cekimi_adim($pdo,$kanal,$i,1,static fn()=>hb_akis_sayfa(hb_akis_paketler(999,1),1));
            bekle((int) $r['SAYFA']===0 && $r['IMLEC']===null,'başarısız sayfa ilerledi');
            bekle((int) tek($pdo,'SELECT COUNT(*) FROM M_ET_ICE_AKTARIM WHERE KANAL_ID=:k',[':k'=>$kanal])===$once,'ham sayfa yarım kaldı');
            bekle((int) tek($pdo,"SELECT COUNT(*) FROM M_ET_SIPARIS WHERE KANAL_ID=:k AND HARICI_NO='HB-API-999'",[':k'=>$kanal])===0,'yarım sipariş kaldı');
        } finally { if ($olustu) { $pdo->exec("DROP TRIGGER [{$ad}]"); } }
        et_cekimi_yonet($pdo,$kanal,$i,1,'tekrar');hb_akis_hazir($pdo,$kanal,$i);$r=et_cekimi_adim($pdo,$kanal,$i,1,static fn()=>hb_akis_sayfa(hb_akis_paketler(999,1),1));bekle($r['DURUM']==='tamam' && (int) $r['YENI']===1,'başarısız sayfa tekrarlanamadı');
    });
    akis('Hepsiburada: uygun olmayan kaynak kutuda bekler; muhasebe yazılmaz, sırlar denetime girmez',function () use ($pdo,$kanal,$bag,$belgeTablo,$belgeSayisi): void {
        $i=hb_akis_baslat($pdo,$kanal);$p=hb_akis_paketler(1000,1);$p[0]['status']='CancelledByCustomer';
        et_cekimi_adim($pdo,$kanal,$i,1,static fn()=>hb_akis_sayfa($p,1));
        bekle((bool) tek($pdo,"SELECT KAYNAK_ENGELI FROM M_ET_SIPARIS WHERE KANAL_ID=:k AND HARICI_NO='HB-API-1000'",[':k'=>$kanal]),'uygun olmayan kaynak hazır sayıldı');
        bekle((int) tek($pdo,"SELECT COUNT(*) FROM {$belgeTablo}")===$belgeSayisi && (int) tek($pdo,'SELECT COUNT(*) FROM M_ET_SIPARIS WHERE KANAL_ID=:k AND FIS_ID IS NOT NULL',[':k'=>$kanal])===0,'onaysız muhasebe yazıldı');
        $s=$pdo->prepare('SELECT MESAJ,VERI FROM M_ET_OLAY WHERE KANAL_ID=:k');$s->execute([':k'=>$kanal]);$iz=et_json($s->fetchAll(PDO::FETCH_ASSOC));
        bekle(!str_contains($iz,$bag['gizli']) && !str_contains($iz,base64_encode($bag['anahtar'].':'.$bag['gizli'])),'sırlar denetim izine yazıldı');
    });
} finally {
    if ($kanal) {
        $pdo->prepare('DELETE FROM M_ET_CEKIM_SAYFA WHERE CEKIM_ID IN (SELECT ID FROM M_ET_CEKIM WHERE KANAL_ID=:k)')->execute([':k'=>$kanal]);
        foreach (['M_ET_CEKIM','M_ET_BAGLANTI','M_ET_OLAY','M_ET_URUN_ESLEME','M_ET_SIPARIS_SATIR','M_ET_SIPARIS','M_ET_ICE_AKTARIM','M_ET_KANAL'] as $t) {
            $kosul=$t==='M_ET_KANAL' ? 'ID=:k' : ($t==='M_ET_SIPARIS_SATIR' ? 'SIPARIS_ID IN (SELECT ID FROM M_ET_SIPARIS WHERE KANAL_ID=:k)' : 'KANAL_ID=:k');$pdo->prepare("DELETE FROM {$t} WHERE {$kosul}")->execute([':k'=>$kanal]);
        }
    }
    putenv($eski===false ? 'LUMEN_ETICARET_ANAHTAR_DOSYASI' : 'LUMEN_ETICARET_ANAHTAR_DOSYASI='.$eski);foreach (glob($d.'/*') ?: [] as $p) { unlink($p); }rmdir($d);
}
$hata=count(array_filter($sonuclar,static fn(array $r): bool=>!$r[0]));printf("%d Hepsiburada akışı, %d başarısız\n",count($sonuclar),$hata);exit($hata ? 1 : 0);
