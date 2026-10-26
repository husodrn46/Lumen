<?php
declare(strict_types=1);
require_once __DIR__.'/ice_aktar.php';
require_once __DIR__.'/anahtar.php';
require_once __DIR__.'/ag_adaptorleri.php';

function et_baglanti_yetki(int $kullanici): void
{
    if ((int) m_p_yetki($kullanici,'M33')!==1 || (int) m_p_yetki($kullanici,'M16')!==1) { throw new DomainException('Sipariş almak ve bağlantıyı yönetmek için M33 ve M16 yetkileri gerekiyor.'); }
}

/** SQL DATETIME2'nin kesirli saniyesi aşağı yuvarlanarak bekleme kısaltılmaz. */
function et_api_zaman(?string $t): int
{
    return $t ? (int) strtotime(substr($t,0,19).' UTC')+1 : 0;
}

/** Ağ çağrısı sırasında SQL kilidi tutulmaz. Yalnız kısa durum geçişleri kilitlenir. */
function et_cekim_kilit(PDO $db, int $kanal): void
{
    $s=$db->prepare("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=:r,@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=0; SELECT @r;");
    $s->execute([':r'=>'et_cekim:'.$GLOBALS['firmano'].':'.$kanal]);$kod=(int) $s->fetchColumn();$s->closeCursor();
    if ($kod<0) { throw new DomainException('Bu kanalda başka bir işlem sürüyor. Biraz sonra tekrar deneyin.'); }
}

function et_baglanti(PDO $db, int $kanal): ?array
{
    et_kanal($db,$kanal);
    return et_tek($db,'SELECT * FROM dbo.M_ET_BAGLANTI WHERE KANAL_ID=:k',[':k'=>$kanal]);
}

function et_baglanti_kaydet(PDO $db, int $kanal, int $kullanici, ?array $v): void
{
    et_baglanti_yetki($kullanici);$db->beginTransaction();
    try {
        et_cekim_kilit($db,$kanal);$k=et_kanal($db,$kanal);
        $a=et_ag_adaptor($k['TUR']);
        $eski=et_baglanti($db,$kanal);$sifre=null;
        if ($v!==null) {
            $ortam=(string) ($v['ortam'] ?? '');$satici=trim((string) ($v['satici'] ?? ''));
            if (!in_array($ortam,['stage','prod'],true)) { throw new DomainException('Test veya üretim ortamını seçin.'); }
            $istemci=$k['TUR']==='hepsiburada' ? et_hb_istemci(trim((string) ($v['istemci'] ?? ''))) : null;
            if ($k['TUR']==='hepsiburada') { $satici=et_hb_merchant($satici); }
            $anahtar=(string) ($v['anahtar'] ?? '');$gizli=(string) ($v['gizli'] ?? '');
            if ($anahtar==='' && $gizli==='' && $eski && $eski['TUR']===$k['TUR'] && $eski['ORTAM']===$ortam && $eski['SATICI_ID']===$satici) { $sifre=$eski['SIFRELI']; }
            else { $sir=($a['kimlik'])($satici,$anahtar,$gizli);$sifre=et_sir_sifrele($sir,(int) $k['FIRMA'],$kanal); }
        }
        $db->prepare("UPDATE dbo.M_ET_CEKIM SET DURUM='durduruldu',BITIS=SYSUTCDATETIME(),KILIT=NULL,KILIT_BITIS=NULL WHERE KANAL_ID=:k AND DURUM='suruyor'")->execute([':k'=>$kanal]);
        $db->prepare('DELETE FROM dbo.M_ET_BAGLANTI WHERE KANAL_ID=:k')->execute([':k'=>$kanal]);
        if ($v!==null) {
            $db->prepare('INSERT INTO dbo.M_ET_BAGLANTI (KANAL_ID,ORTAM,SATICI_ID,TUR,ISTEMCI,SIFRELI,SURUM,SON_ISTEK,GUNCELLEYEN) VALUES (:k,:o,:s,:tur,:c,:g,:v,:t,:u)')
                ->execute([':k'=>$kanal,':o'=>$ortam,':s'=>$satici,':tur'=>$k['TUR'],':c'=>$istemci,':g'=>$sifre,':v'=>bin2hex(random_bytes(16)),':t'=>$eski['SON_ISTEK'] ?? null,':u'=>$kullanici]);
        }
        et_olay($db,$kanal,null,$kullanici,'api_baglanti',$v===null ? 'API bağlantısı kaldırıldı.' : $a['ad'].' API bağlantısı kaydedildi.',$v===null ? null : ['ortam'=>$ortam,'satici'=>$satici]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); }throw $e; }
}

function et_cekimi_oku(PDO $db, int $kanal, int $id = 0): ?array
{
    et_kanal($db,$kanal);
    return et_tek($db,'SELECT TOP 1 * FROM dbo.M_ET_CEKIM WHERE KANAL_ID=:k'.($id ? ' AND ID=:i' : '').' ORDER BY ID DESC',[':k'=>$kanal]+($id ? [':i'=>$id] : []));
}

function et_cekimi_baslat(PDO $db, int $kanal, int $kullanici, string $bas, string $bit): int
{
    et_baglanti_yetki($kullanici);$db->beginTransaction();
    try {
        et_cekim_kilit($db,$kanal);$k=et_kanal($db,$kanal);$bag=et_baglanti($db,$kanal);
        $a=et_ag_adaptor($k['TUR']);$filtre=($a['aralik'])($bas,$bit);
        if (!$k['AKTIF'] || !$bag || $bag['TUR']!==$k['TUR']) { throw new DomainException('Aktif bir kanal ve türüne uygun API bağlantısı gerekiyor.'); }
        if (et_tek($db,"SELECT ID FROM dbo.M_ET_CEKIM WHERE KANAL_ID=:k AND DURUM='suruyor'",[':k'=>$kanal])) { throw new DomainException('Bu kanalda yarım kalan alımı sürdürün veya durdurun.'); }
        $id=lumen_insert_kimlik($db,'INSERT INTO dbo.M_ET_CEKIM (KANAL_ID,BAG_SURUM,FILTRE,KULLANICI,SON_KULLANICI) VALUES (:k,:v,:f,:u,:s)',
            [':k'=>$kanal,':v'=>$bag['SURUM'],':f'=>et_json($filtre),':u'=>$kullanici,':s'=>$kullanici],'ID');
        et_olay($db,$kanal,null,$kullanici,'api_baslat',$a['ad'].' sipariş alımı başlatıldı.',['cekim'=>$id,'filtre'=>$filtre]);$db->commit();return $id;
    } catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); }throw $e; }
}

function et_cekimi_yonet(PDO $db, int $kanal, int $id, int $kullanici, string $islem): void
{
    et_baglanti_yetki($kullanici);$db->beginTransaction();
    try {
        et_cekim_kilit($db,$kanal);$r=et_cekimi_oku($db,$kanal,$id);
        if (!$r || $r['DURUM']!=='suruyor' || !in_array($islem,['durdur','tekrar'],true)) { throw new DomainException('Sürdürülebilir alım bulunamadı.'); }
        if ($islem==='tekrar' && et_api_zaman($r['KILIT_BITIS'])>time()) { throw new DomainException('Bir sayfa alınıyor. Tamamlanmasını bekleyin.'); }
        $sql=$islem==='durdur' ? "DURUM='durduruldu',BITIS=SYSUTCDATETIME(),KILIT=NULL,KILIT_BITIS=NULL" : 'DENEME=0,HATA=NULL';
        $db->prepare("UPDATE dbo.M_ET_CEKIM SET {$sql},SON_KULLANICI=:u WHERE ID=:i")->execute([':u'=>$kullanici,':i'=>$id]);
        et_olay($db,$kanal,null,$kullanici,'api_'.$islem,$islem==='durdur' ? 'Sipariş alımı kullanıcı tarafından durduruldu.' : 'Sipariş alımı yeniden denemeye açıldı.',['cekim'=>$id]);$db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); }throw $e; }
}

/** Her çağrı en fazla bir GET sayfası alır. Kutu kaydı ve cursor aynı transaction içinde ilerler. */
function et_cekimi_adim(PDO $db, int $kanal, int $id, int $kullanici, ?callable $tasiyici = null): array
{
    et_baglanti_yetki($kullanici);$db->beginTransaction();
    try {
        et_cekim_kilit($db,$kanal);$k=et_kanal($db,$kanal);$r=et_cekimi_oku($db,$kanal,$id);$bag=et_baglanti($db,$kanal);
        if (!$r) { throw new DomainException('Sipariş alımı bulunamadı.'); }
        if ($r['DURUM']!=='suruyor' || (int) $r['DENEME']>=5) { $db->commit();return $r; }
        if (!$bag || $bag['SURUM']!==$r['BAG_SURUM'] || !$k['AKTIF'] || $k['TUR']!==$bag['TUR']) { throw new DomainException('Kanal veya bağlantı değişti. Mevcut alımı durdurup yeni alım başlatın.'); }
        $a=et_ag_adaptor($k['TUR']);
        $simdi=time();
        foreach ([$r['SONRAKI'],$r['KILIT_BITIS']] as $t) { if (et_api_zaman($t)>$simdi) { $db->commit();return $r; } }
        if (et_api_zaman($bag['SON_ISTEK'])+5>$simdi) { $db->commit();return $r; }
        $sir=et_sir_coz($bag['SIFRELI'],(int) $k['FIRMA'],$kanal);$token=bin2hex(random_bytes(16));
        $db->prepare('UPDATE dbo.M_ET_CEKIM SET KILIT=:t,KILIT_BITIS=DATEADD(SECOND,60,SYSUTCDATETIME()),SONRAKI=DATEADD(SECOND,5,SYSUTCDATETIME()),SON_KULLANICI=:u WHERE ID=:i')->execute([':t'=>$token,':u'=>$kullanici,':i'=>$id]);
        $db->prepare('UPDATE dbo.M_ET_BAGLANTI SET SON_ISTEK=SYSUTCDATETIME() WHERE KANAL_ID=:k')->execute([':k'=>$kanal]);$db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); }throw $e; }
    $hata=null;$sayfa=null;
    try {
        $filtre=json_decode($r['FILTRE'],true,8,JSON_THROW_ON_ERROR);
        $istek=($a['istek'])($bag,$sir,$filtre,$r['IMLEC']);
        $sayfa=($a['yanit'])(($tasiyici ?? $a['http'])($istek),$bag['SATICI_ID'],$filtre,$r['IMLEC']);
    } catch (EtApiHatasi $e) { $hata=$e; }
    catch (Throwable) { $hata=new EtApiHatasi('Sipariş sayfası alınamadı. Bağlantıyı kontrol edin.',true); }
    unset($sir,$istek);
    $db->beginTransaction();
    try {
        et_cekim_kilit($db,$kanal);$guncel=et_cekimi_oku($db,$kanal,$id);$guncelBag=et_baglanti($db,$kanal);$k=et_kanal($db,$kanal);
        // Durdurulan, ayarı değişen veya lease'i başka işlemce alınan yanıt ilerletilmez.
        if ($guncel['DURUM']!=='suruyor' || $guncel['KILIT']!==$token || !$guncelBag || $guncelBag['SURUM']!==$r['BAG_SURUM'] || !$k['AKTIF'] || $k['TUR']!==$guncelBag['TUR']) { $db->commit();return $guncel; }
        if (!$hata) {
            $imlecOzet=hash('sha256',$r['IMLEC'] ?? '');
            if ($sayfa['imlec']!==null && (hash('sha256',$sayfa['imlec'])===$imlecOzet || et_tek($db,'SELECT ID FROM dbo.M_ET_CEKIM_SAYFA WHERE CEKIM_ID=:i AND ISTEK_OZET=:h',[':i'=>$id,':h'=>hash('sha256',$sayfa['imlec'])]))) { $hata=new EtApiHatasi($a['ad'].' aynı devam bilgisini yineledi. Yeni alım başlatın.'); }
            if (isset($sayfa['kayit_ozeti']) && et_tek($db,'SELECT ID FROM dbo.M_ET_CEKIM_SAYFA WHERE CEKIM_ID=:i AND VERI_OZET=:h',[':i'=>$id,':h'=>$sayfa['kayit_ozeti']])) { $hata=new EtApiHatasi($a['ad'].' önceki sayfanın paketlerini yineledi. Alımı durdurup yeniden başlatın.'); }
        }
        if ($hata) {
            et_cekimi_hata_yaz($db,$id,$kanal,$kullanici,$guncel,$hata);$db->commit();return et_cekimi_oku($db,$kanal,$id);
        }
        $sonuc=et_ice_aktar($db,$kanal,$sayfa['ham'],'json',$k['TUR'].'-api-'.$id.'-'.((int) $r['SAYFA']+1).'.json',$kullanici,$a['bicim']);
        $db->prepare('INSERT INTO dbo.M_ET_CEKIM_SAYFA (CEKIM_ID,SIRA,ISTEK_OZET,AKTARIM_ID,VERI_OZET) VALUES (:i,:s,:h,:a,:v)')->execute([':i'=>$id,':s'=>(int) $r['SAYFA']+1,':h'=>$imlecOzet,':a'=>$sonuc['aktarim_id'],':v'=>$sayfa['kayit_ozeti'] ?? null]);
        $db->prepare("UPDATE dbo.M_ET_CEKIM SET IMLEC=:c,DURUM=:d,SAYFA=SAYFA+1,YENI=YENI+:y,MUKERRER=MUKERRER+:m,CAKISMA=CAKISMA+:ca,HATALI=HATALI+:h,ESKI=ESKI+:e,DENEME=0,HATA=NULL,KILIT=NULL,KILIT_BITIS=NULL,BITIS=:b WHERE ID=:i")
            ->execute([':c'=>$sayfa['imlec'],':d'=>$sayfa['imlec']===null ? 'tamam' : 'suruyor',':y'=>$sonuc['yeni'],':m'=>$sonuc['mukerrer'],':ca'=>$sonuc['cakisma'],':h'=>$sonuc['hatali'],':e'=>$sonuc['eski'],':b'=>$sayfa['imlec']===null ? gmdate('Y-m-d H:i:s') : null,':i'=>$id]);
        et_olay($db,$kanal,null,$kullanici,'api_sayfa',$a['ad'].' sayfası sipariş kutusuna alındı.',['cekim'=>$id,'sayfa'=>(int) $r['SAYFA']+1,'sonuc'=>$sonuc,'sayfalama'=>$sayfa['meta'] ?? null]);$db->commit();
    } catch (Throwable) {
        if ($db->inTransaction()) { $db->rollBack(); }
        // Bozuk sayfa veya SQL hatasında kutu ve cursor geri alınır; sır içerebilen istisna metni saklanmaz.
        $db->beginTransaction();
        try {
            et_cekim_kilit($db,$kanal);$guncel=et_cekimi_oku($db,$kanal,$id);
            if ($guncel['DURUM']==='suruyor' && $guncel['KILIT']===$token) { et_cekimi_hata_yaz($db,$id,$kanal,$kullanici,$guncel,new EtApiHatasi('Sayfa kutuya kaydedilemedi. Hiçbir kayıt ilerletilmedi; yeniden deneyin.')); }
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); }throw $e; }
    }
    return et_cekimi_oku($db,$kanal,$id);
}

function et_cekimi_hata_yaz(PDO $db, int $id, int $kanal, int $kullanici, array $r, EtApiHatasi $hata): void
{
    $deneme=$hata->tekrar ? min(5,(int) $r['DENEME']+1) : 5;
    $bekleme=max($hata->bekleme,min(3600,5*(2**$deneme)));
    $db->prepare('UPDATE dbo.M_ET_CEKIM SET DENEME=:d,HATA=:h,SONRAKI=:t,KILIT=NULL,KILIT_BITIS=NULL WHERE ID=:i')->execute([':d'=>$deneme,':h'=>$hata->getMessage(),':t'=>gmdate('Y-m-d H:i:s',time()+$bekleme),':i'=>$id]);
    et_olay($db,$kanal,null,$kullanici,'api_hata',$hata->getMessage(),['cekim'=>$id,'deneme'=>$deneme]);
}
