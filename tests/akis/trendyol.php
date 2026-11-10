<?php
declare(strict_types=1);
require __DIR__.'/_ortak.php';
if (!in_array($DB,['LUMEN_DEMO','LUMEN_BAGIMSIZ'],true)) { fwrite(STDERR,"REDDEDİLDİ: yalnız sentetik demo veritabanı.\n");exit(3); }
require_once dirname(__DIR__,2).'/ayr.php';
require_once dirname(__DIR__,2).'/includes/eticaret/otomatik.php';
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
/** Görevin beklemesi yerine zamanı ileri alır: sıradaki sayfa hemen alınabilir. */
function ty_oto_uyu(PDO $db, int $kanal): callable
{
    return static function () use ($db,$kanal): void {
        $db->prepare('UPDATE M_ET_BAGLANTI SET SON_ISTEK=DATEADD(SECOND,-10,SYSUTCDATETIME()) WHERE KANAL_ID=:k')->execute([':k'=>$kanal]);
        $db->prepare("UPDATE M_ET_CEKIM SET SONRAKI=DATEADD(SECOND,-10,SYSUTCDATETIME()),KILIT_BITIS=NULL WHERE KANAL_ID=:k AND DURUM='suruyor'")->execute([':k'=>$kanal]);
    };
}
function ty_oto_paket(int $n): array
{
    $p=$GLOBALS['paket'];$p['shipmentPackageId']='95000000'.$n;$p['orderNumber']='TY-OTO-'.$n;
    foreach ($p['lines'] as $i=>$l) { $p['lines'][$i]['lineId']='TY-OTO-SATIR-'.$n.'-'.$i; }
    return $p;
}
function ty_oto_son(PDO $db, int $kanal, string $fark): void
{
    $db->prepare("UPDATE M_ET_BAGLANTI SET OTOMATIK_SON=DATEADD(MINUTE,CAST(:f AS INT),CAST(CONVERT(VARCHAR(16),SYSUTCDATETIME(),120) AS DATETIME2)) WHERE KANAL_ID=:k")->execute([':f'=>(int) $fark,':k'=>$kanal]);
}
try {
    $kanal=lumen_insert_kimlik($pdo,"INSERT INTO M_ET_KANAL (FIRMA,AD,TUR,OLUSTURAN,GUNCELLEYEN) VALUES (1,N'Sentetik Trendyol alım testi','trendyol',1,1)",[],'ID');
    akis('Trendyol: bağlantı şifreli, firma sınırı ve iki yönetim yetkisi zorunlu',function () use ($pdo,$kanal,$bag): void {
        et_baglanti_kaydet($pdo,$kanal,1,$bag);$b=et_baglanti($pdo,$kanal);bekle(!str_contains($b['SIFRELI'],$bag['gizli']),'gizli anahtar düz metin');
        $eskiFirma=$GLOBALS['firmano'];$GLOBALS['firmano']=2;$red=false;
        try { et_baglanti($pdo,$kanal); } catch (DomainException) { $red=true; } finally { $GLOBALS['firmano']=$eskiFirma; }bekle($red,'başka firma bağlantıyı okudu');
        $depo=(int) tek($pdo,"SELECT LOGICALREF FROM LG_SLSMAN WHERE CODE='DEPO1'");$red=false;
        try { et_baglanti_kaydet($pdo,$kanal,$depo,$bag); } catch (DomainException) { $red=true; }bekle($red,'yetkisiz kişi bağlantıyı değiştirdi');
        $testKisi=random_int(1000000,2000000000);
        try {
            $pdo->prepare('INSERT INTO M_P_YETKI (PERSONEL,YETKI,M33,M16) VALUES (:u,1,0,1)')->execute([':u'=>$testKisi]);
            foreach ([[0,1],[1,0]] as [$m33,$m16]) {
                $pdo->prepare('UPDATE M_P_YETKI SET M33=:a,M16=:b WHERE PERSONEL=:u')->execute([':a'=>$m33,':b'=>$m16,':u'=>$testKisi]);m_p_yetki_cache_temizle($testKisi);$red=false;
                try { et_baglanti_kaydet($pdo,$kanal,$testKisi,$bag); } catch (DomainException) { $red=true; }bekle($red,'tek yetki bağlantıya yetti');
            }
        } finally { $pdo->prepare('DELETE FROM M_P_YETKI WHERE PERSONEL=:u')->execute([':u'=>$testKisi]);m_p_yetki_cache_temizle($testKisi); }
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
    akis('Trendyol otomatik: kapalı başlar; açılınca görev aralığı sayfa sayfa alır ve kaldığı yeri ilerletir',function () use ($pdo,$kanal,$bag): void {
        et_baglanti_kaydet($pdo,$kanal,1,$bag);bekle((int) et_baglanti($pdo,$kanal)['OTOMATIK']===0,'otomatik alım varsayılan açık');
        $istekler=[];
        bekle(et_otomatik_calistir($pdo,30,$kanal,static function () use (&$istekler): array { $istekler[]='x';return ty_sayfa([]); },ty_oto_uyu($pdo,$kanal))===[] && !$istekler,'kapalı kanalda görev çalıştı');
        et_baglanti_kaydet($pdo,$kanal,1,$bag+['otomatik'=>'1']);$p=ty_oto_paket(1);
        $o=et_otomatik_calistir($pdo,60,$kanal,static function (array $i) use (&$istekler,$p): array { $istekler[]=$i['url'];return count($istekler)===1 ? ty_sayfa([$p],'oto-imlec') : ty_sayfa([]); },ty_oto_uyu($pdo,$kanal))[$kanal];
        bekle($o['durum']==='guncel' && $o['hata']===null && $o['sayfa']===2 && $o['yeni']===1,'otomatik alım özeti yanlış: '.json_encode($o));
        bekle(count($istekler)===2 && !str_contains($istekler[0],'nextCursor') && str_contains($istekler[1],'nextCursor=oto-imlec'),'imleç sürdürülmedi');
        $b=et_baglanti($pdo,$kanal);$c=et_cekimi_oku($pdo,$kanal);$f=json_decode($c['FILTRE'],true);
        bekle($c['DURUM']==='tamam' && $c['OTOMATIK_BITIS']!==null && $b['OTOMATIK_SON']===$c['OTOMATIK_BITIS'],'kaldığı yer ilerlemedi');
        bekle($f['lastModifiedEndDate']-$f['lastModifiedStartDate']===86400000,'ilk otomatik aralık son 24 saat değil');
        bekle($b['OTOMATIK_KONTROL']!==null && $b['OTOMATIK_HATA']===null,'görevin kontrol anı yazılmadı');
        $o=et_otomatik_calistir($pdo,30,$kanal,static function () use (&$istekler): array { $istekler[]='x';return ty_sayfa([]); },ty_oto_uyu($pdo,$kanal))[$kanal];
        bekle(count($istekler)===2 && $o['durum']==='guncel' && $o['sayfa']===0,'5 dk dolmadan yeni aralık alındı');
    });
    akis('Trendyol otomatik: sonraki aralık 10 dk örtüşür; elle alım kaldığı yeri değiştirmez',function () use ($pdo,$kanal): void {
        ty_oto_son($pdo,$kanal,'-120');$son=et_baglanti($pdo,$kanal)['OTOMATIK_SON'];
        $id=ty_baslat($pdo,$kanal);et_cekimi_adim($pdo,$kanal,$id,1,static fn()=>ty_sayfa([]));
        bekle(et_cekimi_oku($pdo,$kanal,$id)['DURUM']==='tamam' && et_baglanti($pdo,$kanal)['OTOMATIK_SON']===$son,'elle alım otomatik alımın kaldığı yeri değiştirdi');
        $url='';et_otomatik_calistir($pdo,60,$kanal,static function (array $i) use (&$url): array { $url=$i['url'];return ty_sayfa([]); },ty_oto_uyu($pdo,$kanal));
        parse_str((string) parse_url($url,PHP_URL_QUERY),$q);
        $beklenen=((new DateTimeImmutable(substr($son,0,19),new DateTimeZone('UTC')))->getTimestamp()-600)*1000;
        bekle((int) ($q['lastModifiedStartDate'] ?? 0)===$beklenen,'aralık kaldığı yerden 10 dk örtüşerek başlamadı');
        bekle(et_baglanti($pdo,$kanal)['OTOMATIK_SON']>$son,'kaldığı yer ilerlemedi');
    });
    akis('Trendyol otomatik: yetkisiz veya etkin olmayan kişi adına çalışmaz, hata ekranda kalır',function () use ($pdo,$kanal): void {
        $n=0;$tasiyici=static function () use (&$n): array { $n++;return ty_sayfa([]); };ty_oto_son($pdo,$kanal,'-60');
        $depo=(int) tek($pdo,"SELECT LOGICALREF FROM LG_SLSMAN WHERE CODE='DEPO1'");
        foreach ([[$depo,'yetki'],[2000000000,'etkin değil']] as [$kisi,$neden]) {
            $pdo->prepare('UPDATE M_ET_BAGLANTI SET GUNCELLEYEN=:u WHERE KANAL_ID=:k')->execute([':u'=>$kisi,':k'=>$kanal]);
            $o=et_otomatik_calistir($pdo,30,$kanal,$tasiyici,ty_oto_uyu($pdo,$kanal))[$kanal];
            bekle($o['durum']==='hata' && str_contains((string) $o['hata'],$neden) && $n===0,'yetkisiz kişi adına alım yapıldı: '.json_encode($o));
            bekle(str_contains((string) et_baglanti($pdo,$kanal)['OTOMATIK_HATA'],$neden),'görev hatası bağlantıya yazılmadı');
        }
        $pdo->prepare('UPDATE M_ET_BAGLANTI SET GUNCELLEYEN=1 WHERE KANAL_ID=:k')->execute([':k'=>$kanal]);
        $o=et_otomatik_calistir($pdo,60,$kanal,$tasiyici,ty_oto_uyu($pdo,$kanal))[$kanal];
        bekle($o['hata']===null && $n===1 && et_baglanti($pdo,$kanal)['OTOMATIK_HATA']===null,'yetki düzelince görev hatası temizlenmedi');
    });
    akis('Trendyol otomatik: 401 alımı durdurur ve görev tekrarlamaz; anahtar yenilemesi kaldığı yeri korur',function () use ($pdo,$kanal,$bag): void {
        ty_oto_son($pdo,$kanal,'-60');$n=0;
        $o=et_otomatik_calistir($pdo,60,$kanal,static function () use (&$n): array { $n++;return ['kod'=>401,'govde'=>'gizli-olabilecek-yanit']; },ty_oto_uyu($pdo,$kanal))[$kanal];
        bekle($o['durum']==='hata' && $n===1 && !str_contains((string) $o['hata'],'gizli-olabilecek'),'401 sonrası görev durumu yanlış: '.json_encode($o));
        $o=et_otomatik_calistir($pdo,60,$kanal,static function () use (&$n): array { $n++;return ty_sayfa([]); },ty_oto_uyu($pdo,$kanal))[$kanal];
        bekle($o['durum']==='hata' && $n===1,'kimlik hatası görevde yeniden denendi');
        $son=et_baglanti($pdo,$kanal)['OTOMATIK_SON'];
        et_baglanti_kaydet($pdo,$kanal,1,['anahtar'=>str_repeat('c',16),'gizli'=>str_repeat('d',16),'otomatik'=>'1']+$bag);$b=et_baglanti($pdo,$kanal);
        bekle($son!==null && $b['OTOMATIK_SON']===$son && $b['OTOMATIK_HATA']===null && (int) $b['OTOMATIK']===1,'anahtar yenilemesi kaldığı yeri sildi');
        bekle(et_cekimi_oku($pdo,$kanal)['DURUM']==='durduruldu','yarım otomatik alım durdurulmadı');
        et_baglanti_kaydet($pdo,$kanal,1,['satici'=>'124','otomatik'=>'1']+$bag);
        bekle(et_baglanti($pdo,$kanal)['OTOMATIK_SON']===null,'başka satıcı hesabı eski hesabın kaldığı yerden sürdü');
        et_baglanti_kaydet($pdo,$kanal,1,$bag+['otomatik'=>'1']);
    });
    akis('Trendyol otomatik: süre dolunca alım yarım kalır, sonraki çalıştırma aynı aralıktan sürer',function () use ($pdo,$kanal): void {
        ty_oto_son($pdo,$kanal,'-60');$p=ty_oto_paket(2);
        $tasiyici=static fn(array $i): array=>str_contains($i['url'],'nextCursor=yarim-imlec') ? ty_sayfa([]) : ty_sayfa([$p],'yarim-imlec');
        $o=et_otomatik_calistir($pdo,3,$kanal,$tasiyici,static function (): void {})[$kanal];$c=et_cekimi_oku($pdo,$kanal);
        bekle($o['durum']==='suruyor' && $c['DURUM']==='suruyor' && $c['OTOMATIK_BITIS']!==null,'süre dolunca alım yarım kalmadı: '.json_encode($o));
        $o=et_otomatik_calistir($pdo,60,$kanal,$tasiyici,ty_oto_uyu($pdo,$kanal))[$kanal];$c2=et_cekimi_oku($pdo,$kanal);
        bekle($o['durum']==='guncel' && (int) $c2['ID']===(int) $c['ID'] && $c2['DURUM']==='tamam' && (int) $c2['YENI']===1,'yarım alım aynı aralıktan sürmedi');
    });
    akis('Otomatik alım betiği: açık kanal yokken ağa çıkmaz, kapalı kanal seçimini bildirir, tek kopya çalışır',function () use ($pdo,$kanal): void {
        $pdo->prepare('UPDATE M_ET_BAGLANTI SET OTOMATIK=0 WHERE KANAL_ID=:k')->execute([':k'=>$kanal]);
        bekle((int) tek($pdo,'SELECT COUNT(*) FROM M_ET_BAGLANTI WHERE OTOMATIK=1')===0,'demo veritabanında otomatik alımı açık başka bağlantı var; betik dış servise çıkabilirdi');
        $kok=dirname(__DIR__,2);
        $calistir=static function (string ...$arg) use ($kok): array {
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($kok.'/scripts/eticaret_al.php').($arg ? ' '.implode(' ',array_map('escapeshellarg',$arg)) : '').' 2>&1',$cikti,$kod);
            return [$kod,implode("\n",$cikti)];
        };
        [$kod,$cikti]=$calistir('--sure=30');bekle($kod===0 && str_contains($cikti,'Otomatik alımı açık kanal yok'),'boş çalıştırma: '.$cikti);
        [$kod,$cikti]=$calistir('--kanal='.$kanal);bekle($kod===1 && str_contains($cikti,'açık değil'),'kapalı kanal seçimi: '.$cikti);
        $kilit=fopen($kok.'/database/eticaret_al.kilit','c');bekle($kilit!==false && flock($kilit,LOCK_EX),'test kilidi alınamadı');
        try { [$kod,$cikti]=$calistir(); } finally { flock($kilit,LOCK_UN);fclose($kilit); }
        bekle($kod===0 && str_contains($cikti,'Başka bir otomatik alım sürüyor'),'eşzamanlı ikinci kopya çalıştı: '.$cikti);
    });
    akis('Trendyol: alım muhasebe belgesi üretmez ve sırları denetim izine yazmaz',function () use ($pdo,$kanal,$bag,$bakiyeTablo,$belgeSayisi): void {
        bekle((int) tek($pdo,"SELECT COUNT(*) FROM {$bakiyeTablo}")===$belgeSayisi,'alım gerçek sipariş oluşturdu');
        bekle((int) tek($pdo,'SELECT COUNT(*) FROM M_ET_SIPARIS WHERE KANAL_ID=:k AND FIS_ID IS NOT NULL',[':k'=>$kanal])===0,'onaysız aktarım kimliği');
        $s=$pdo->prepare('SELECT MESAJ,VERI FROM M_ET_OLAY WHERE KANAL_ID=:k');$s->execute([':k'=>$kanal]);$iz=json_encode($s->fetchAll(PDO::FETCH_ASSOC));
        bekle(!str_contains($iz,$bag['gizli']) && !str_contains($iz,base64_encode($bag['anahtar'].':'.$bag['gizli'])),'sır denetim izine yazıldı');
    });
    $web=(new Tarayici($URL))->giris('YONETICI');
    akis('Trendyol ekranı: giriş, yetki ve CSRF korunur',function () use ($web,$URL,$kanal): void {
        $yol='eticaret/baglanti.php?kanal='.$kanal;
        $anonim=(new Tarayici($URL))->istek($yol);bekle($anonim['kod']===303,'oturumsuz bağlantı açıldı');
        $depo=(new Tarayici($URL))->giris('DEPO1');bekle($depo->istek($yol)['kod']===403,'yetkisiz bağlantı açıldı');
        bekle($web->istek($yol,['islem'=>'kaldir'])['kod']===403,'bağlantıda CSRF atlandı');
        bekle($web->istek('eticaret/al.php?kanal='.$kanal,['islem'=>'adim'])['kod']===403,'alımda CSRF atlandı');
    });
    akis('Trendyol ekranı: sırlar boş forma döner; kaydetmek/GET dış istek yapmaz',function () use ($pdo,$web,$kanal,$bag): void {
        $yol='eticaret/baglanti.php?kanal='.$kanal;
        $r=$web->istek($yol,['csrf_token'=>$web->belirtec($yol),'islem'=>'kaydet']+$bag);bekle($r['kod']===303,'bağlantı formu kaydedilemedi');
        $once=et_baglanti($pdo,$kanal)['SON_ISTEK'];$g=$web->istek($yol)['govde'];
        bekle(!str_contains($g,$bag['anahtar']) && !str_contains($g,$bag['gizli']),'anahtar forma döndü');
        bekle(!preg_match('/name="(?:anahtar|gizli)"[^>]*value="[^"]+"/',$g),'dolu sır alanı');
        $g=$web->istek('eticaret/al.php?kanal='.$kanal)['govde'];bekle(!str_contains($g,'<script src="../assets/js/eticaret-al.js'),'GET otomatik alım başlattı');
        bekle(et_baglanti($pdo,$kanal)['SON_ISTEK']===$once,'kaydet/GET istek zamanını değiştirdi');
    });
    akis('Trendyol ekranı: otomatik alım anahtarı kaydedilir; durum bağlantı ve alım ekranında görünür',function () use ($pdo,$web,$kanal,$bag): void {
        $yol='eticaret/baglanti.php?kanal='.$kanal;
        $r=$web->istek($yol,['csrf_token'=>$web->belirtec($yol),'islem'=>'kaydet','otomatik'=>'1']+$bag);
        bekle($r['kod']===303 && (int) et_baglanti($pdo,$kanal)['OTOMATIK']===1,'otomatik alım formdan açılamadı');
        $g=$web->istek($yol)['govde'];
        bekle(str_contains($g,'name="otomatik" type="checkbox" value="1" checked') && str_contains($g,'<strong>Otomatik alım açık.</strong>'),'otomatik alım durumu bağlantı ekranında yok');
        $pdo->prepare("UPDATE M_ET_BAGLANTI SET OTOMATIK_HATA=N'Sentetik görev hatası <b>' WHERE KANAL_ID=:k")->execute([':k'=>$kanal]);
        $g=$web->istek($yol)['govde'];bekle(str_contains($g,'Otomatik alım durdu.') && str_contains($g,'Sentetik görev hatası &lt;b&gt;'),'görev hatası kaçışsız veya görünmüyor');
        bekle(str_contains($web->istek('eticaret/al.php?kanal='.$kanal)['govde'],'<strong>Otomatik alım açık.</strong>'),'alım ekranı otomatik alımı göstermiyor');
        $r=$web->istek($yol,['csrf_token'=>$web->belirtec($yol),'islem'=>'kaydet']+$bag);
        bekle($r['kod']===303 && (int) et_baglanti($pdo,$kanal)['OTOMATIK']===0 && et_baglanti($pdo,$kanal)['OTOMATIK_HATA']===null,'otomatik alım formdan kapatılamadı');
    });
    akis('Trendyol ekranı: açık POST başlatır, yönlendirmede tek kullanımlık otomatik alım ve durdurma',function () use ($pdo,$web,$kanal): void {
        $yol='eticaret/al.php?kanal='.$kanal;$simdi=new DateTimeImmutable('now',new DateTimeZone('Europe/Istanbul'));
        $once=et_baglanti($pdo,$kanal)['SON_ISTEK'];
        $r=$web->istek($yol,['csrf_token'=>$web->belirtec($yol),'islem'=>'baslat','bas'=>$simdi->modify('-1 day')->format('Y-m-d\TH:i'),'bit'=>$simdi->format('Y-m-d\TH:i')]);bekle($r['kod']===303,'alım başlatılamadı');
        $id=(int) et_cekimi_oku($pdo,$kanal)['ID'];
        // Tarayici JavaScript çalıştırmaz. Bu GET/POST akışı hiçbir pazaryeri isteği göndermez.
        $g=$web->istek($yol)['govde'];bekle(str_contains($g,'<script src="../assets/js/eticaret-al.js'),'açık POST otomatik alım hakkı vermedi');
        $g=$web->istek($yol)['govde'];bekle(!str_contains($g,'<script src="../assets/js/eticaret-al.js'),'yeniden GET dış istek başlatabilir');
        $r=$web->istek($yol,['csrf_token'=>$web->belirtec($yol),'islem'=>'durdur','cekim'=>$id]);bekle($r['kod']===303 && et_cekimi_oku($pdo,$kanal,$id)['DURUM']==='durduruldu','alım durdurulamadı');
        bekle(et_baglanti($pdo,$kanal)['SON_ISTEK']===$once,'başlatma sayfa almadan dış istek yaptı');
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
