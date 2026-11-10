<?php
declare(strict_types=1);
require_once __DIR__.'/cekim.php';

/*
 * Zamanlanmış sipariş alımı (scripts/eticaret_al.php).
 *
 * Otomatik alımı açık kanalda önce yarım kalan alım sürdürülür; yoksa son tamamlanan otomatik
 * aralığın sonundan (OTOMATIK_SON) bugüne yeni bir aralık başlatılır. Her ağ çağrısı
 * et_cekimi_adim() ile tek sayfa alır: kilit, bekleme, yeniden deneme ve kutuya yazım ekrandaki
 * alımla aynıdır. Siparişler yalnız kontrol kutusuna gelir; aktarım kullanıcı onayında kalır.
 * Görev, bağlantıyı kaydeden kişi adına çalışır; kişi etkin ve M33+M16 yetkili olmalıdır.
 */

const ET_OTOMATIK_ORTUSME=600;   // aralıklar 10 dk örtüşür; geç yansıyan değişiklik kaçmaz, tekrarlar kutuda birleşir
const ET_OTOMATIK_ARA=300;       // son aralığın üzerinden 5 dk geçmeden yeni aralık açılmaz
const ET_OTOMATIK_ILK=86400;     // ilk otomatik alım son 24 saati kapsar
const ET_OTOMATIK_GECIKME=3600;  // görev bu süreden uzun süredir çalışmadıysa ekranda uyarı verilir

function et_dakika(DateTimeImmutable $t): DateTimeImmutable
{
    return $t->setTime((int) $t->format('H'),(int) $t->format('i'));
}

/** SQL'deki UTC DATETIME2 metni → İstanbul zamanı. */
function et_utc_yerel(?string $t): ?DateTimeImmutable
{
    return $t ? (new DateTimeImmutable(substr($t,0,19),new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Istanbul')) : null;
}

/**
 * Sıradaki otomatik aralık (İstanbul saati, 'Y-m-d\TH:i'); zamanı gelmediyse null.
 * bosluk: adaptörün en eski tarih sınırı yüzünden alınamayan dönem kaldı.
 *
 * @return array{bas:string,bit:string,bosluk:bool}|null
 */
function et_otomatik_pencere(array $a, ?string $son, DateTimeImmutable $simdi): ?array
{
    $simdi=$simdi->setTimezone(new DateTimeZone('Europe/Istanbul'));$bitis=et_dakika($simdi);
    if ($son!==null) {
        $sonT=et_utc_yerel($son);
        if ($simdi->getTimestamp()-$sonT->getTimestamp()<ET_OTOMATIK_ARA) { return null; }
        $bas=et_dakika($sonT->modify('-'.ET_OTOMATIK_ORTUSME.' seconds'));
    } else {
        $bas=$bitis->modify('-'.ET_OTOMATIK_ILK.' seconds');
    }
    $bosluk=false;
    if ($a['en_eski']!==null) {
        // Doğrulama kendi "şimdi"sini kullanır; bir saatlik pay aralığı sınırın dışına taşırmaz.
        $sinir=et_dakika($simdi->modify($a['en_eski'])->modify('+1 hour'));
        if ($bas<$sinir) { $bas=$sinir;$bosluk=true; }
    }
    $bit=min($bitis,$bas->modify('+'.$a['en_uzun'].' seconds'));
    return $bas<$bit ? ['bas'=>$bas->format('Y-m-d\TH:i'),'bit'=>$bit->format('Y-m-d\TH:i'),'bosluk'=>$bosluk] : null;
}

/** et_cekimi_adim()'ın ağ çağrısı yapmadan döneceği kalan süre (sn). */
function et_otomatik_bekleme(array $r, array $bag): int
{
    $simdi=time();
    return max(0,et_api_zaman($r['SONRAKI'])-$simdi,et_api_zaman($r['KILIT_BITIS'])-$simdi,et_api_zaman($bag['SON_ISTEK'])+5-$simdi);
}

/** Bu firmada otomatik alımı açık, aktif ve türü bağlantısıyla uyuşan kanallar. */
function et_otomatik_kanallar(PDO $db, ?int $yalniz = null): array
{
    $s=$db->prepare('SELECT K.ID,K.AD,K.TUR,B.SURUM FROM dbo.M_ET_KANAL K JOIN dbo.M_ET_BAGLANTI B ON B.KANAL_ID=K.ID
        WHERE K.FIRMA=:f AND K.AKTIF=1 AND B.OTOMATIK=1 AND B.TUR=K.TUR'.($yalniz!==null ? ' AND K.ID=:k' : '').' ORDER BY K.ID');
    $s->execute([':f'=>(int) $GLOBALS['firmano']]+($yalniz!==null ? [':k'=>$yalniz] : []));
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Kanalda en fazla bir ağ çağrısı yapar. Sürdürülemeyen durum DomainException olarak döner.
 * durum: ilerledi (hemen yeniden çağrılabilir), bekle (bekle sn sonra), bitti (bu çalıştırmada iş yok).
 *
 * @return array{durum:string,bekle:int,fark:array{sayfa:int,yeni:int,mukerrer:int,incele:int}}
 */
function et_otomatik_adim(PDO $db, int $kanal, ?callable $tasiyici = null, ?DateTimeImmutable $simdi = null): array
{
    $sonuc=['durum'=>'bitti','bekle'=>0,'fark'=>['sayfa'=>0,'yeni'=>0,'mukerrer'=>0,'incele'=>0]];
    $k=et_kanal($db,$kanal);$bag=et_baglanti($db,$kanal);
    if (!$bag || !(int) $bag['OTOMATIK'] || !$k['AKTIF'] || $bag['TUR']!==$k['TUR']) { return $sonuc; }
    $kullanici=(int) $bag['GUNCELLEYEN'];
    if (!lumen_personel_getir($db,$kullanici)) { throw new DomainException('Otomatik alımı açan kullanıcı artık etkin değil. Bağlantıyı yetkili bir kullanıcıyla yeniden kaydedin.'); }
    et_baglanti_yetki($kullanici);
    $r=et_cekimi_oku($db,$kanal);
    if ($r && $r['DURUM']==='suruyor') {
        $durdu=static fn(array $c): DomainException=>new DomainException(trim(($c['HATA'] ?? 'Alım durdu.').' Alım ekranından yeniden deneyin.'));
        if ((int) $r['DENEME']>=5) { throw $durdu($r); }
        $bekle=et_otomatik_bekleme($r,$bag);
        if ($bekle>0) { return ['durum'=>'bekle','bekle'=>$bekle]+$sonuc; }
        $y=et_cekimi_adim($db,$kanal,(int) $r['ID'],$kullanici,$tasiyici);
        $sonuc['fark']=['sayfa'=>(int) $y['SAYFA']-(int) $r['SAYFA'],'yeni'=>(int) $y['YENI']-(int) $r['YENI'],'mukerrer'=>(int) $y['MUKERRER']-(int) $r['MUKERRER'],
            'incele'=>(int) $y['CAKISMA']+(int) $y['HATALI']-(int) $r['CAKISMA']-(int) $r['HATALI']];
        if ($y['DURUM']!=='suruyor') { return ['durum'=>'ilerledi']+$sonuc; }
        if ((int) $y['DENEME']>=5) { throw $durdu($y); }
        $bekle=et_otomatik_bekleme($y,et_baglanti($db,$kanal) ?? $bag);
        return ['durum'=>$bekle>0 ? 'bekle' : 'ilerledi','bekle'=>$bekle]+$sonuc;
    }
    $a=et_ag_adaptor($k['TUR']);
    $p=et_otomatik_pencere($a,$bag['OTOMATIK_SON'],$simdi ?? new DateTimeImmutable('now'));
    if (!$p) { return $sonuc; }
    $id=et_cekimi_baslat($db,$kanal,$kullanici,$p['bas'],$p['bit'],true);
    if ($p['bosluk']) {
        et_olay($db,$kanal,null,$kullanici,'api_otomatik_bosluk',$a['ad'].' otomatik alımı uzun süre çalışmadı; '.str_replace('T',' ',$p['bas']).' öncesi bu servisten alınamaz. Eksik dönemi pazaryeri panelinden kontrol edin.',['cekim'=>$id]);
    }
    return ['durum'=>'ilerledi']+$sonuc;
}

/**
 * Otomatik alımı açık kanalları en fazla $sure saniye ilerletir; kanallar sırayla birer adım atar,
 * biri beklerken diğeri ilerler. $uyu yalnız testler içindir (varsayılan sleep).
 *
 * @return array<int,array{ad:string,tur:string,durum:string,hata:?string,sayfa:int,yeni:int,mukerrer:int,incele:int}>
 */
function et_otomatik_calistir(PDO $db, int $sure, ?int $yalniz = null, ?callable $tasiyici = null, ?callable $uyu = null): array
{
    $son=time()+$sure;$ozet=[];$surum=[];
    foreach (et_otomatik_kanallar($db,$yalniz) as $k) {
        $id=(int) $k['ID'];$surum[$id]=$k['SURUM'];
        $ozet[$id]=['ad'=>$k['AD'],'tur'=>$k['TUR'],'durum'=>'guncel','hata'=>null,'sayfa'=>0,'yeni'=>0,'mukerrer'=>0,'incele'=>0];
        $db->prepare('UPDATE dbo.M_ET_BAGLANTI SET OTOMATIK_KONTROL=SYSUTCDATETIME() WHERE KANAL_ID=:k AND SURUM=:v')->execute([':k'=>$id,':v'=>$surum[$id]]);
    }
    $acik=array_keys($ozet);
    while ($acik) {
        $bekle=null;
        foreach ($acik as $i=>$id) {
            try { $s=et_otomatik_adim($db,$id,$tasiyici); }
            catch (DomainException $e) { $ozet[$id]['hata']=$e->getMessage();unset($acik[$i]);continue; }
            catch (Throwable $e) {
                // Ayrıntı yalnız sunucu günlüğüne; ekrana genel mesaj yazılır.
                error_log('eticaret_al: kanal '.$id.': '.get_class($e).': '.$e->getMessage());
                $ozet[$id]['hata']='Beklenmeyen bir hata oluştu; ayrıntı sunucu günlüğünde.';unset($acik[$i]);continue;
            }
            foreach ($s['fark'] as $ad=>$n) { $ozet[$id][$ad]+=$n; }
            if ($s['durum']==='bitti') { unset($acik[$i]); }
            else { $bekle=min($bekle ?? PHP_INT_MAX,$s['durum']==='bekle' ? $s['bekle'] : 0); }
        }
        if (!$acik) { break; }
        if (time()+(int) $bekle>=$son) { foreach ($acik as $id) { $ozet[$id]['durum']='suruyor'; }break; }
        if ($bekle>0) { ($uyu ?? 'sleep')($bekle); }
    }
    foreach ($ozet as $id=>$o) {
        if ($o['hata']!==null) { $ozet[$id]['durum']='hata'; }
        $db->prepare('UPDATE dbo.M_ET_BAGLANTI SET OTOMATIK_HATA=:h WHERE KANAL_ID=:k AND SURUM=:v')
            ->execute([':h'=>$o['hata']===null ? null : mb_substr($o['hata'],0,500),':k'=>$id,':v'=>$surum[$id]]);
    }
    return $ozet;
}

/**
 * Bağlantı ve alım ekranları için otomatik alım durumu.
 * @return array{acik:bool,son:?DateTimeImmutable,kontrol:?DateTimeImmutable,gecikti:bool,hata:?string}
 */
function et_otomatik_durum(?array $bag): array
{
    $kontrol=et_utc_yerel($bag['OTOMATIK_KONTROL'] ?? null);
    return ['acik'=>(bool) ($bag['OTOMATIK'] ?? false),'son'=>et_utc_yerel($bag['OTOMATIK_SON'] ?? null),'kontrol'=>$kontrol,
        'gecikti'=>$kontrol===null || time()-$kontrol->getTimestamp()>ET_OTOMATIK_GECIKME,'hata'=>$bag['OTOMATIK_HATA'] ?? null];
}
