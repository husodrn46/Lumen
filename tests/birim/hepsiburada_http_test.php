<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/eticaret/adaptorler/hepsiburada_http.php';

function hb_reddet(callable $f): void
{
    try { $f(); } catch (DomainException) { return; }throw new RuntimeException('Geçersiz Hepsiburada girdisi kabul edildi.');
}
function hb_test_paket(int $id = 1): array
{
    $p=json_decode((string) file_get_contents(dirname(__DIR__,2).'/docs/eticaret/hepsiburada_paket.json'),true)[0];
    $p['packageNumber']='SENTETIK-HB-'.$id;$p['items'][0]['merchantId']='00000000-0000-4000-8000-000000000123';return $p;
}
function hb_test_yanit(array $paketler, int $total, int $offset = 0): array
{
    return ['kod'=>200,'govde'=>json_encode($paketler,JSON_THROW_ON_ERROR),'basliklar'=>['limit'=>'10','offset'=>(string) $offset,'totalcount'=>(string) $total,'pagecount'=>(string) (int) ceil($total/10)]];
}
test('Hepsiburada: 24 saat sınırı, geçersiz tarih ve gelecek aralığı reddedilir',function (): void {
    $simdi=new DateTimeImmutable('2026-10-26T12:00:00+03:00');$f=et_hb_aralik('2026-10-25T12:00','2026-10-26T12:00',$simdi);
    esit(['begindate'=>'2026-10-25 12:00','enddate'=>'2026-10-26 12:00','limit'=>10],$f);
    foreach ([['2026-10-25T11:59','2026-10-26T12:00'],['2026-10-26T12:00','2026-10-26T12:01'],['2026-10-26T12:00','2026-10-26T12:00'],['2026-02-30T12:00','2026-03-01T12:00']] as $d) { hb_reddet(static fn()=>et_hb_aralik($d[0],$d[1],$simdi)); }
});
test('Hepsiburada: Merchant UUID, Basic ve kullanıcının tanımlı User-Agent başlığı',function (): void {
    $m='00000000-0000-4000-8000-000000000123';$sir=et_hb_kimlik($m,'sentetik-kullanici',str_repeat('s',16));$b=['ORTAM'=>'stage','SATICI_ID'=>$m,'ISTEMCI'=>'Lumen_Demo'];
    $f=['begindate'=>'2026-10-25 12:00','enddate'=>'2026-10-26 12:00','limit'=>10];$i=et_hb_istek($b,$sir,$f,null);
    dogru(str_starts_with($i['url'],'https://oms-external-sit.hepsiburada.com/packages/merchantid/'.$m.'?'));
    parse_str(parse_url($i['url'],PHP_URL_QUERY),$q);esit('0',$q['offset']);esit('10',$q['limit']);esit($f['begindate'],$q['begindate']);
    esit('User-Agent: Lumen_Demo',$i['basliklar'][1]);esit('Authorization: Basic '.base64_encode($sir['anahtar'].':'.$sir['gizli']),$i['basliklar'][0]);dogru(!str_contains($i['url'],$sir['gizli']));
    $b['ORTAM']='prod';dogru(str_starts_with(et_hb_istek($b,$sir,$f,null)['url'],'https://oms-external.hepsiburada.com/'));
    foreach (['123','00000000-0000-0000-0000-000000000000','../baska'] as $v) { hb_reddet(static fn()=>et_hb_merchant($v)); }
    hb_reddet(static fn()=>et_hb_kimlik($m,"abc\r\ndef",'sentetik-servis'));hb_reddet(static fn()=>et_hb_istemci("Lumen\r\nHost: baska"));
    hb_reddet(static fn()=>et_hb_http(['url'=>'https://ornek.test/packages/merchantid/'.$m,'basliklar'=>[]]));
});
test('Hepsiburada: header tabanlı offset kalıcıdır, kısa son sayfa ve boş liste tamamlanır',function (): void {
    $m='00000000-0000-4000-8000-000000000123';$p=[];for ($i=1;$i<=10;$i++) { $p[]=hb_test_paket($i); }
    $ilk=et_hb_yanit(hb_test_yanit($p,11),$m,[],null);esit(['offset'=>10,'total'=>11],json_decode($ilk['imlec'],true));
    $son=et_hb_yanit(hb_test_yanit([hb_test_paket(11)],11,10),$m,[],$ilk['imlec']);esit(null,$son['imlec']);dogru($ilk['kayit_ozeti']!==$son['kayit_ozeti']);
    esit(null,et_hb_yanit(hb_test_yanit([],0),$m,[],null)['imlec']);
    hb_reddet(static fn()=>et_hb_yanit(['kod'=>200,'govde'=>'{}','basliklar'=>hb_test_yanit([],0)['basliklar']],$m,[],null));
});
test('Hepsiburada: eksik/çelişkili başlık, değişen liste ve tekrarlı/kimliksiz paket ilerlemez',function (): void {
    $m='00000000-0000-4000-8000-000000000123';$iyi=hb_test_yanit([hb_test_paket()],1);
    foreach (['limit','offset','totalcount','pagecount'] as $h) { $r=$iyi;unset($r['basliklar'][$h]);hb_reddet(static fn()=>et_hb_yanit($r,$m,[],null)); }
    foreach (['limit'=>'9','offset'=>'10','totalcount'=>'2','pagecount'=>'2'] as $h=>$v) { $r=$iyi;$r['basliklar'][$h]=$v;hb_reddet(static fn()=>et_hb_yanit($r,$m,[],null)); }
    $r=hb_test_yanit([hb_test_paket()],11,10);hb_reddet(static fn()=>et_hb_yanit($r,$m,[],'{"offset":10,"total":12}'));
    hb_reddet(static fn()=>et_hb_yanit(hb_test_yanit([hb_test_paket(),hb_test_paket()],2),$m,[],null));
    $p=hb_test_paket();unset($p['packageNumber']);hb_reddet(static fn()=>et_hb_yanit(hb_test_yanit([$p],1),$m,[],null));
    $p=hb_test_paket();$p['items'][0]['merchantId']='00000000-0000-4000-8000-000000000999';hb_reddet(static fn()=>et_hb_yanit(hb_test_yanit([$p],1),$m,[],null));
    $p=hb_test_paket();unset($p['items'][0]['lineItemId']);hb_reddet(static fn()=>et_hb_yanit(hb_test_yanit([$p],1),$m,[],null));
    hb_reddet(static fn()=>et_hb_devam('{"offset":-10,"total":12}'));hb_reddet(static fn()=>et_hb_devam('https://ornek.test'));
});
test('Hepsiburada: servis hata gövdesi saklanmaz; 401 durur, 429 ve kesinti yeniden denenir',function (): void {
    foreach ([401,403,429,500,0] as $kod) {
        try { et_hb_yanit(['kod'=>$kod,'govde'=>'olasi-gizli-yanit','bekleme'=>99999],'00000000-0000-4000-8000-000000000123',[],null);throw new RuntimeException('hata bekleniyordu'); }
        catch (EtApiHatasi $e) { dogru(!str_contains($e->getMessage(),'olasi-gizli-yanit'));esit(in_array($kod,[429,500,0],true),$e->tekrar);dogru($e->bekleme<=3600); }
    }
});
