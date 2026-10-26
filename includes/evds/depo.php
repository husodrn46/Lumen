<?php
declare(strict_types=1);
require_once __DIR__.'/istemci.php';

/** Ana anahtar SQL yedeğinden ayrıdır; mevcut korumalı database/ dizininde tutulur. */
function evds_ana_anahtar(): string
{
    $yol=getenv('LUMEN_EVDS_ANAHTAR_DOSYASI') ?: dirname(__DIR__,2).'/database/evds-master.key';
    $dizin=dirname($yol);
    if (!is_dir($dizin) && !mkdir($dizin,0750,true) && !is_dir($dizin)) { throw new RuntimeException('EVDS anahtar dizini oluşturulamadı.'); }
    $kilit=@fopen($yol.'.lock','c');
    if (!$kilit || !flock($kilit,LOCK_EX)) { throw new RuntimeException('EVDS anahtar dosyası kilitlenemedi.'); }
    try {
        if (!is_file($yol)) {
            $gecici=$yol.'.yeni-'.bin2hex(random_bytes(8));
            $dosya=@fopen($gecici,'x+b');
            if (!$dosya) { throw new RuntimeException('EVDS ana anahtarı kaydedilemedi.'); }
            try {
                // Anahtar baytları yazılmadan önce dosyanın erişimini kısıtla.
                if (!chmod($gecici,0600) || fwrite($dosya,random_bytes(32))!==32 || !fflush($dosya)) {
                    throw new RuntimeException('EVDS ana anahtarı kaydedilemedi.');
                }
            } catch (Throwable $e) {
                fclose($dosya);@unlink($gecici);throw $e;
            }
            fclose($dosya);
            if (!rename($gecici,$yol)) {
                @unlink($gecici);throw new RuntimeException('EVDS ana anahtarı kaydedilemedi.');
            }
        }
        $anahtar=file_get_contents($yol);
        if ($anahtar===false || strlen($anahtar)!==32) { throw new RuntimeException('EVDS ana anahtarı okunamadı; yedek anahtarı geri yükleyin.'); }
        return $anahtar;
    } finally { flock($kilit,LOCK_UN);fclose($kilit); }
}

function evds_sifrele(string $deger, string $anahtar, string $sahip): string
{
    if (strlen($anahtar)!==32) { throw new InvalidArgumentException('Şifreleme anahtarı 32 bayt olmalı.'); }
    $iv=random_bytes(12);$tag='';$v=openssl_encrypt($deger,'aes-256-gcm',$anahtar,OPENSSL_RAW_DATA,$iv,$tag,$sahip,16);
    if ($v===false) { throw new RuntimeException('EVDS anahtarı şifrelenemedi.'); }
    return 'v1:'.base64_encode($iv.$tag.$v);
}

function evds_sifre_coz(string $v, string $anahtar, string $sahip): string
{
    $ham=str_starts_with($v,'v1:') ? base64_decode(substr($v,3),true) : false;
    if ($ham===false || strlen($ham)<29 || strlen($anahtar)!==32) { throw new RuntimeException('EVDS bağlantı anahtarı okunamadı; anahtarı yeniden tanımlayın.'); }
    $cozum=openssl_decrypt(substr($ham,28),'aes-256-gcm',$anahtar,OPENSSL_RAW_DATA,substr($ham,0,12),substr($ham,12,16),$sahip);
    if ($cozum===false) { throw new RuntimeException('EVDS bağlantı anahtarı açılamadı; ana anahtarı veya bağlantı ayarını kontrol edin.'); }
    return $cozum;
}

function evds_baglanti(PDO $db, int $firma, int $kullanici): ?array
{
    $s=$db->prepare('SELECT SIFRELI_ANAHTAR,GUNCELLEME FROM dbo.M_EVDS_BAGLANTI WHERE FIRMA=:f AND KULLANICI=:u');
    $s->execute([':f'=>$firma,':u'=>$kullanici]);return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function evds_baglanti_kaydet(PDO $db, int $firma, int $kullanici, ?string $anahtar): void
{
    if ($firma<1 || $kullanici<1) { throw new DomainException('Kullanıcı bulunamadı.'); }
    $sifre=$anahtar===null ? null : evds_sifrele(evds_anahtar_dogrula($anahtar),evds_ana_anahtar(),$firma.':'.$kullanici);
    $db->beginTransaction();
    try {
        evds_kilit($db,$firma,$kullanici);
        $p=[':f'=>$firma,':u'=>$kullanici];
        $db->prepare('DELETE FROM dbo.M_EVDS_BAGLANTI WHERE FIRMA=:f AND KULLANICI=:u')->execute($p);
        if ($sifre!==null) { $db->prepare('INSERT INTO dbo.M_EVDS_BAGLANTI (FIRMA,KULLANICI,SIFRELI_ANAHTAR,GUNCELLEME) VALUES (:f,:u,:a,:t)')->execute($p+[':a'=>$sifre,':t'=>date('Y-m-d H:i:s')]); }
        $db->prepare('DELETE FROM dbo.M_EVDS_ONBELLEK WHERE FIRMA=:f AND KULLANICI=:u')->execute($p);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); }throw $e; }
}

function evds_kilit(PDO $db, int $firma, int $kullanici): void
{
    $s=$db->prepare("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=:r,@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=5000; SELECT @r;");
    $s->execute([':r'=>'lumen_evds:'.$firma.':'.$kullanici]);$kod=(int) $s->fetchColumn();$s->closeCursor();
    if ($kod<0) { throw new DomainException('Başka bir EVDS isteğiniz sürüyor. Biraz sonra tekrar deneyin.'); }
}

function evds_sorgu_ozeti(array $q): string
{
    return hash('sha256',json_encode(evds_sorgu($q['seri'],$q['bas'],$q['bit']),JSON_THROW_ON_ERROR));
}

function evds_onbellek(PDO $db, int $firma, int $kullanici, string $ozet): ?array
{
    $s=$db->prepare('SELECT VERI,ALINMA,SON_DENEME,HATA FROM dbo.M_EVDS_ONBELLEK WHERE FIRMA=:f AND KULLANICI=:u AND OZET=:h');
    $s->execute([':f'=>$firma,':u'=>$kullanici,':h'=>$ozet]);$r=$s->fetch(PDO::FETCH_ASSOC);
    if (!$r || $r['VERI']===null) { return null; }
    $v=json_decode($r['VERI'],true,32,JSON_THROW_ON_ERROR);
    return ['veri'=>$v,'alinma'=>$r['ALINMA'],'eski'=>substr($r['ALINMA'],0,10)!==date('Y-m-d'),'uyari'=>$r['HATA']];
}

/** Günlük önbellek ve kullanıcı kilidi aynı sorgunun eşzamanlı tekrarını önler. */
function evds_getir(PDO $db, int $firma, int $kullanici, array $q, ?callable $tasiyici = null): array
{
    $q=evds_sorgu($q['seri'],$q['bas'],$q['bit']);$ozet=evds_sorgu_ozeti($q);$p=[':f'=>$firma,':u'=>$kullanici,':h'=>$ozet];
    $hata=null;$sonuc=null;
    $db->beginTransaction();
    try {
        evds_kilit($db,$firma,$kullanici);
        $bag=evds_baglanti($db,$firma,$kullanici);
        if (!$bag) { throw new DomainException('Önce kendi EVDS API anahtarınızı tanımlayın.'); }
        $once=evds_onbellek($db,$firma,$kullanici,$ozet);
        if ($once && !$once['eski']) { $db->commit();return $once; }
        $s=$db->prepare('SELECT SON_DENEME FROM dbo.M_EVDS_ONBELLEK WHERE FIRMA=:f AND KULLANICI=:u AND OZET=:h');$s->execute($p);$deneme=$s->fetchColumn();$s->closeCursor();
        if ($deneme && strtotime($deneme)>time()-60) { throw new DomainException('Bu sorguyu az önce denediniz. Yeniden denemeden önce bir dakika bekleyin.'); }
        $s=$db->prepare('SELECT COUNT(*) FROM dbo.M_EVDS_ONBELLEK WHERE FIRMA=:f AND KULLANICI=:u AND SON_DENEME>=:t');
        $s->execute([':f'=>$firma,':u'=>$kullanici,':t'=>date('Y-m-d')]);$sayi=(int) $s->fetchColumn();$s->closeCursor();
        if ($sayi>=20) { throw new DomainException('Lumen günlük 20 farklı EVDS sorgusu sınırına ulaşıldı. Kayıtlı sonuçlarınızı kullanabilir veya yarın tekrar deneyebilirsiniz.'); }
        try {
            $anahtar=evds_sifre_coz($bag['SIFRELI_ANAHTAR'],evds_ana_anahtar(),$firma.':'.$kullanici);
            $v=evds_veri_oku($q,$anahtar,$tasiyici);
            $sonuc=['veri'=>$v,'alinma'=>date('Y-m-d H:i:s'),'eski'=>false,'uyari'=>null];
        } catch (DomainException $e) { $hata=$e->getMessage(); }
        $veri=$sonuc ? json_encode($sonuc['veri'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE) : ($once ? json_encode($once['veri'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE) : null);
        $db->prepare('DELETE FROM dbo.M_EVDS_ONBELLEK WHERE FIRMA=:f AND KULLANICI=:u AND OZET=:h')->execute($p);
        $db->prepare('INSERT INTO dbo.M_EVDS_ONBELLEK (FIRMA,KULLANICI,OZET,VERI,ALINMA,SON_DENEME,HATA) VALUES (:f,:u,:h,:v,:a,:d,:e)')->execute($p+[':v'=>$veri,':a'=>$sonuc['alinma'] ?? $once['alinma'] ?? null,':d'=>date('Y-m-d H:i:s'),':e'=>$hata]);
        // Eski sorguların birikmesini önle; yalnız kişinin önbelleği temizlenir.
        $db->prepare('DELETE FROM dbo.M_EVDS_ONBELLEK WHERE FIRMA=:f AND KULLANICI=:u AND SON_DENEME<:t')->execute([':f'=>$firma,':u'=>$kullanici,':t'=>date('Y-m-d',strtotime('-30 days'))]);
        $db->commit();
        if (!$sonuc && $once) { return array_replace($once,['eski'=>true,'uyari'=>$hata]); }
    } catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); }throw $e; }
    if (!$sonuc) { throw new DomainException($hata ?? 'EVDS verisi alınamadı.'); }
    return $sonuc;
}
