<?php
declare(strict_types=1);
require __DIR__.'/_ortak.php';
if (!in_array($DB,['LUMEN_DEMO','LUMEN_BAGIMSIZ'],true)) { fwrite(STDERR,"REDDEDİLDİ: yalnız sentetik demo veritabanı.\n");exit(3); }
require_once dirname(__DIR__,2).'/ayr.php';
require_once dirname(__DIR__,2).'/includes/eticaret/cekim.php';
$dbh=$pdo;$firmano=1;$u=1;$kanal=0;
$d=sys_get_temp_dir().'/lumen-ty-akis-'.bin2hex(random_bytes(8));mkdir($d,0700);
$eski=getenv('LUMEN_ETICARET_ANAHTAR_DOSYASI');putenv('LUMEN_ETICARET_ANAHTAR_DOSYASI='.$d.'/master');
$bag=['ortam'=>'stage','satici'=>'123','anahtar'=>str_repeat('a',16),'gizli'=>str_repeat('b',16)];
$paket=json_decode((string) file_get_contents(dirname(__DIR__,2).'/docs/eticaret/trendyol_v2.json'),true)['content'][0];$paket['supplierId']=123;
$bakiyeTablo=lumen_bagimsiz_mi() ? 'LM_BELGE' : 'LG_001_02_ORFICHE';$belgeSayisi=(int) tek($pdo,"SELECT COUNT(*) FROM {$bakiyeTablo}");
function ty_hazir(PDO $db, int $kanal, int $id): void
{
    $db->prepare('UPDATE M_ET_BAGLANTI SET SON_ISTEK=DATEADD(SECOND,-10,SYSUTCDATETIME()) WHERE KANAL_ID=:k')->execute([':k'=>$kanal]);
    $db->prepare('UPDATE M_ET_CEKIM SET SONRAKI=DATEADD(SECOND,-10,SYSUTCDATETIME()),KILIT_BITIS=NULL WHERE ID=:i')->execute([':i'=>$id]);
}
function ty_baslat(PDO $db, int $kanal): int
{
    $once=et_cekimi_oku($db,$kanal);
    if ($once && $once['DURUM']==='suruyor') { et_cekimi_yonet($db,$kanal,(int) $once['ID'],1,'durdur'); }
    $tz=new DateTimeZone('Europe/Istanbul');$simdi=new DateTimeImmutable('now',$tz);
    $id=et_cekimi_baslat($db,$kanal,1,$simdi->modify('-1 day')->format('Y-m-d\TH:i'),$simdi->format('Y-m-d\TH:i'));ty_hazir($db,$kanal,$id);return $id;
}
function ty_sayfa(array $paketler, ?string $imlec = null): array
{
    return ['kod'=>200,'govde'=>json_encode(['content'=>$paketler,'hasMore'=>$imlec!==null,'nextCursor'=>$imlec],JSON_THROW_ON_ERROR)];
}
try {
    $kanal=lumen_insert_kimlik($pdo,"INSERT INTO M_ET_KANAL (FIRMA,AD,TUR,OLUSTURAN,GUNCELLEYEN) VALUES (1,N'Sentetik Trendyol alım testi','trendyol',1,1)",[],'ID');
    akis('Trendyol: bağlantı şifreli, firma sınırı ve iki yönetim yetkisi zorunlu',function () use ($pdo,$kanal,$bag): void {
        et_baglanti_kaydet($pdo,$kanal,1,$bag);$b=et_baglanti($pdo,$kanal);bekle(!str_contains($b['SIFRELI'],$bag['gizli']),'gizli anahtar düz metin');
        $eskiFirma=$GLOBALS['firmano'];$GLOBALS['firmano']=2;$red=false;
        try { et_baglanti($pdo,$kanal); } catch (DomainException) { $red=true; } finally { $GLOBALS['firmano']=$eskiFirma; }bekle($red,'başka firma bağlantıyı okudu');
        $depo=(int) tek($pdo,"SELECT LOGICALREF FROM LG_SLSMAN WHERE CODE='DEPO1'");$red=false;
        try { et_baglanti_kaydet($pdo,$kanal,$depo,$bag); } catch (DomainException) { $red=true; }bekle($red,'yetkisiz kişi bağlantıyı değiştirdi');
    });
    akis('Trendyol: sayfalar kalıcı cursor ile sürer, erken/eşzamanlı istek çıkmaz, iptal kutuyu engeller',function () use ($pdo,$kanal,$paket): void {
        $id=ty_baslat($pdo,$kanal);$n=0;$imlec='opaque+/= %&İ';
        $r=et_cekimi_adim($pdo,$kanal,$id,1,static function ($istek) use ($paket,$imlec,&$n): array { $n++;bekle(!str_contains($istek['url'],'nextCursor'),'ilk sayfada cursor gönderildi');return ty_sayfa([$paket],$imlec); });
        bekle((int) $r['YENI']===1 && $r['IMLEC']===$imlec,'ilk sayfa kayboldu');
        et_cekimi_adim($pdo,$kanal,$id,1,static function () use (&$n): array { $n++;return ty_sayfa([]); });bekle($n===1,'erken ikinci istek yapıldı');
        ty_hazir($pdo,$kanal,$id);$pdo->prepare('UPDATE M_ET_CEKIM SET KILIT_BITIS=DATEADD(SECOND,60,SYSUTCDATETIME()) WHERE ID=:i')->execute([':i'=>$id]);
        et_cekimi_adim($pdo,$kanal,$id,1,static function () use (&$n): array { $n++;return ty_sayfa([]); });bekle($n===1,'ikinci işlem lease kilidini aştı');ty_hazir($pdo,$kanal,$id);
        $iptal=$paket;$iptal['status']='Cancelled';$iptal['shipmentPackageStatus']='Cancelled';$iptal['lastModifiedDate']+=1000;$iptal['lines'][0]['orderLineItemStatusName']='Cancelled';
        $r=et_cekimi_adim($pdo,$kanal,$id,1,static function ($istek) use ($iptal,$imlec,&$n): array { $n++;parse_str(parse_url($istek['url'],PHP_URL_QUERY),$q);bekle($q['nextCursor']===$imlec,'cursor değiştirildi');return ty_sayfa([$iptal]); });
        bekle($r['DURUM']==='tamam' && (int) $r['SAYFA']===2,'ikinci sayfa tamamlanmadı');
        bekle((int) tek($pdo,'SELECT COUNT(*) FROM M_ET_SIPARIS WHERE KANAL_ID=:k',[':k'=>$kanal])===1,'ikinci sipariş oluşturuldu');
        bekle((bool) tek($pdo,'SELECT KAYNAK_ENGELI FROM M_ET_SIPARIS WHERE KANAL_ID=:k',[':k'=>$kanal]),'kaynak iptali tutulmadı');
        bekle((int) tek($pdo,'SELECT COUNT(*) FROM M_ET_CEKIM_SAYFA WHERE CEKIM_ID=:i',[':i'=>$id])===2,'ham sayfa denetim bağlantısı yok');
    });
    akis('Trendyol: 429 beklemesi kalıcı, aynı sayfa yeniden denenir; 401 otomatik döngüyü durdurur',function () use ($pdo,$kanal): void {
        $id=ty_baslat($pdo,$kanal);$n=0;$r=et_cekimi_adim($pdo,$kanal,$id,1,static function () use (&$n): array { $n++;return ['kod'=>429,'govde'=>'gizli-olabilecek-yanit','bekleme'=>120]; });
        bekle((int) $r['DENEME']===1 && (int) $r['SAYFA']===0 && $r['IMLEC']===null,'hatalı sayfa ilerledi');bekle(et_api_zaman($r['SONRAKI'])>=time()+119,'Retry-After korunmadı');
        et_cekimi_adim($pdo,$kanal,$id,1,static function () use (&$n): array { $n++;return ty_sayfa([]); });bekle($n===1,'bekleme atlandı');
        ty_hazir($pdo,$kanal,$id);$r=et_cekimi_adim($pdo,$kanal,$id,1,static fn()=>['kod'=>401,'govde'=>'gizli-olabilecek-yanit']);bekle((int) $r['DENEME']===5 && !str_contains($r['HATA'],'gizli-olabilecek'),'kimlik hatası veya hata sızıntısı');
        ty_hazir($pdo,$kanal,$id);et_cekimi_adim($pdo,$kanal,$id,1,static function () use (&$n): array { $n++;return ty_sayfa([]); });bekle($n===1,'kimlik hatası otomatik tekrarlandı');
        et_cekimi_yonet($pdo,$kanal,$id,1,'tekrar');$r=et_cekimi_adim($pdo,$kanal,$id,1,static fn()=>ty_sayfa([]));bekle($r['DURUM']==='tamam','boş son sayfa tamamlanamadı');
    });
    akis('Trendyol: döngü yapan cursor ve değişen bağlantının geç yanıtı ilerletilmez',function () use ($pdo,$kanal,$bag): void {
        $id=ty_baslat($pdo,$kanal);et_cekimi_adim($pdo,$kanal,$id,1,static fn()=>ty_sayfa([],'A'));ty_hazir($pdo,$kanal,$id);
        $r=et_cekimi_adim($pdo,$kanal,$id,1,static fn()=>ty_sayfa([],'A'));bekle((int) $r['SAYFA']===1 && (int) $r['DENEME']===5,'döngü yapan cursor kaydedildi');
        $id=ty_baslat($pdo,$kanal);$r=et_cekimi_adim($pdo,$kanal,$id,1,static function () use ($pdo,$kanal,$bag): array { et_baglanti_kaydet($pdo,$kanal,1,$bag);return ty_sayfa([]); });bekle($r['DURUM']==='durduruldu' && (int) $r['SAYFA']===0,'eski bağlantının yanıtı kaydedildi');
    });
    akis('Trendyol: kutu yazıldıktan sonraki SQL hatasında cursor ve tüm sayfa geri alınır',function () use ($pdo,$kanal,$paket): void {
        $id=ty_baslat($pdo,$kanal);$ad='TR_ET_TEST_'.strtoupper(bin2hex(random_bytes(6)));$olustu=false;
        $p=$paket;$p['shipmentPackageId']='9000000999';$p['orderNumber']='TY-ATOMIC-SENTETIK';
        $once=(int) tek($pdo,'SELECT COUNT(*) FROM M_ET_ICE_AKTARIM WHERE KANAL_ID=:k',[':k'=>$kanal]);
        try {
            $pdo->exec("CREATE TRIGGER [{$ad}] ON dbo.M_ET_CEKIM_SAYFA AFTER INSERT AS BEGIN IF EXISTS (SELECT 1 FROM inserted WHERE CEKIM_ID={$id}) THROW 51000,N'Sentetik kayıt hatası',1; END");$olustu=true;
            $r=et_cekimi_adim($pdo,$kanal,$id,1,static fn()=>ty_sayfa([$p],'B'));
            bekle((int) $r['SAYFA']===0 && $r['IMLEC']===null,'cursor geri alınmadı');
            bekle((int) tek($pdo,'SELECT COUNT(*) FROM M_ET_ICE_AKTARIM WHERE KANAL_ID=:k',[':k'=>$kanal])===$once,'ham sayfa yarım kaydedildi');
            bekle((int) tek($pdo,"SELECT COUNT(*) FROM M_ET_SIPARIS WHERE KANAL_ID=:k AND HARICI_NO='TY-ATOMIC-SENTETIK'",[':k'=>$kanal])===0,'yarım sipariş kaldı');
        } finally { if ($olustu) { $pdo->exec("DROP TRIGGER [{$ad}]"); } }
        et_cekimi_yonet($pdo,$kanal,$id,1,'tekrar');ty_hazir($pdo,$kanal,$id);$r=et_cekimi_adim($pdo,$kanal,$id,1,static fn()=>ty_sayfa([$p]));bekle($r['DURUM']==='tamam' && (int) $r['YENI']===1,'başarısız sayfa güvenle tekrarlanamadı');
    });
    akis('Trendyol: alım muhasebe belgesi üretmez ve sırları denetim izine yazmaz',function () use ($pdo,$kanal,$bag,$bakiyeTablo,$belgeSayisi): void {
        bekle((int) tek($pdo,"SELECT COUNT(*) FROM {$bakiyeTablo}")===$belgeSayisi,'alım gerçek sipariş oluşturdu');
        bekle((int) tek($pdo,'SELECT COUNT(*) FROM M_ET_SIPARIS WHERE KANAL_ID=:k AND FIS_ID IS NOT NULL',[':k'=>$kanal])===0,'onaysız aktarım kimliği');
        $s=$pdo->prepare('SELECT MESAJ,VERI FROM M_ET_OLAY WHERE KANAL_ID=:k');$s->execute([':k'=>$kanal]);$iz=json_encode($s->fetchAll(PDO::FETCH_ASSOC));
        bekle(!str_contains($iz,$bag['gizli']) && !str_contains($iz,base64_encode($bag['anahtar'].':'.$bag['gizli'])),'sır denetim izine yazıldı');
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
$hata=count(array_filter($sonuclar,static fn(array $r): bool=>!$r[0]));printf("%d Trendyol akışı, %d başarısız\n",count($sonuclar),$hata);exit($hata ? 1 : 0);
