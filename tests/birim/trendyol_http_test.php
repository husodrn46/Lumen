<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/eticaret/adaptorler/trendyol_http.php';
require_once dirname(__DIR__,2).'/includes/eticaret/anahtar.php';

function ty_reddet(callable $f): void
{
    try { $f(); } catch (DomainException) { return; }throw new RuntimeException('Geçersiz Trendyol girdisi kabul edildi.');
}
test('Trendyol stream: İstanbul tarihleri tek kez epoch ms olur; 14 gün ve üç ay sınırı',function (): void {
    $simdi=new DateTimeImmutable('2026-10-26T12:00:00+03:00');
    $f=et_ty_aralik('2026-10-25T12:00','2026-10-26T12:00',$simdi);
    esit((new DateTimeImmutable('2026-10-25T12:00:00+03:00'))->getTimestamp()*1000,$f['lastModifiedStartDate']);esit(10,$f['size']);
    foreach ([['2026-10-01T12:00','2026-10-26T12:00'],['2026-10-25T12:00','2026-10-26T12:01'],['2026-07-01T12:00','2026-07-02T12:00'],['2026-02-30T12:00','2026-03-01T12:00']] as $d) { ty_reddet(static fn()=>et_ty_aralik($d[0],$d[1],$simdi)); }
});
test('Trendyol stream: sabit host, zorunlu başlıklar ve değiştirilmeden taşınan opaque cursor',function (): void {
    $b=['ORTAM'=>'stage','SATICI_ID'=>'123'];$s=['anahtar'=>str_repeat('a',16),'gizli'=>str_repeat('b',16)];
    $f=['lastModifiedStartDate'=>1000,'lastModifiedEndDate'=>2000,'size'=>10];$c='a+/= %?&İ';
    $i=et_ty_istek($b,$s,$f,$c);parse_str(parse_url($i['url'],PHP_URL_QUERY),$q);esit($c,$q['nextCursor']);
    dogru(str_starts_with($i['url'],'https://stageapigw.trendyol.com/integration/order/sellers/123/orders/stream?'));
    esit('User-Agent: 123 - SelfIntegration',$i['basliklar'][1]);esit('Authorization: Basic '.base64_encode($s['anahtar'].':'.$s['gizli']),$i['basliklar'][0]);
    dogru(!str_contains($i['url'],$s['anahtar']));ty_reddet(static fn()=>et_ty_istek(['ORTAM'=>'https://ornek.test','SATICI_ID'=>'123'],$s,$f,null));
    ty_reddet(static fn()=>et_ty_kimlik('123',"aaaa\r\nbbbb",str_repeat('c',16)));
});
test('Trendyol stream: boş son sayfa geçerli; bozuk devam, yabancı satıcı ve kimlik kaybı reddedilir',function (): void {
    $z=['content'=>[],'hasMore'=>false];esit(null,et_ty_yanit(['kod'=>200,'govde'=>json_encode($z)],'123')['imlec']);
    foreach ([['content'=>[],'hasMore'=>'false'],['content'=>[],'hasMore'=>true],['content'=>[['supplierId'=>999]],'hasMore'=>false],['content'=>[['shipmentPackageId'=>[],'orderNumber'=>'x']],'hasMore'=>false]] as $j) {
        ty_reddet(static fn()=>et_ty_yanit(['kod'=>200,'govde'=>json_encode($j)],'123'));
    }
    ty_reddet(static fn()=>et_ty_yanit(['kod'=>200,'govde'=>'<html>hata</html>'],'123'));
    foreach ([401,403,429,500] as $kod) {
        try { et_ty_yanit(['kod'=>$kod,'govde'=>'gizli-yanit','bekleme'=>99999],'123');throw new RuntimeException('hata bekleniyordu'); }
        catch (EtTrendyolHatasi $e) { dogru(!str_contains($e->getMessage(),'gizli-yanit'));esit(in_array($kod,[429,500],true),$e->tekrar);dogru($e->bekleme<=3600); }
    }
});
test('E-ticaret sırları: dosya 0600, rastgele GCM ve firma/kanal bağlamı',function (): void {
    $d=sys_get_temp_dir().'/lumen-et-test-'.bin2hex(random_bytes(8));mkdir($d,0700);$eski=getenv('LUMEN_ETICARET_ANAHTAR_DOSYASI');putenv('LUMEN_ETICARET_ANAHTAR_DOSYASI='.$d.'/master');
    try {
        $v=['anahtar'=>str_repeat('a',16),'gizli'=>str_repeat('b',16)];$a=et_sir_sifrele($v,1,2);$b=et_sir_sifrele($v,1,2);
        dogru($a!==$b);esit($v,et_sir_coz($a,1,2));ty_reddet(static fn()=>et_sir_coz($a,1,3));ty_reddet(static fn()=>et_sir_coz($a,2,2));
        if (PHP_OS_FAMILY!=='Windows') { esit(0600,fileperms($d.'/master')&0777); }
    } finally { putenv($eski===false ? 'LUMEN_ETICARET_ANAHTAR_DOSYASI' : 'LUMEN_ETICARET_ANAHTAR_DOSYASI='.$eski);foreach (glob($d.'/*') ?: [] as $p) { unlink($p); }rmdir($d); }
});
