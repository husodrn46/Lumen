<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/evds/depo.php';

function evds_test_reddet(callable $f): void
{
    try { $f(); } catch (DomainException|InvalidArgumentException|RuntimeException) { return; }
    throw new RuntimeException('Geçersiz EVDS girdisi kabul edildi.');
}
test('EVDS: yalnız seri kodu ve en fazla 1000 günlük geçerli tarih aralığı',function (): void {
    $q=evds_sorgu(' tp.dk.usd.s.ytl ','2024-01-01','2024-12-31');
    esit('TP.DK.USD.S.YTL',$q['seri']);
    foreach ([['https://ornek.test','2024-01-01','2024-01-02'],['TP.DK.USD.S.YTL','2024-02-30','2024-03-01'],
        ['TP.DK.USD.S.YTL','2024-02-01','2024-01-01'],['TP.DK.USD.S.YTL','2020-01-01','2024-01-01']] as $v) { evds_test_reddet(static fn()=>evds_sorgu(...$v)); }
});
test('EVDS: key URL parametresi değildir ve eski evds2 ucu kullanılmaz',function (): void {
    $q=evds_sorgu('TP.DK.USD.S.YTL','2024-01-01','2024-02-01');
    esit(EVDS_SERVIS.'series=TP.DK.USD.S.YTL&startDate=01-01-2024&endDate=01-02-2024&type=json',evds_adres('veri',$q));
    dogru(!str_contains(evds_adres('meta',$q),'key='));
    evds_test_reddet(static fn()=>evds_anahtar_dogrula("abcdefgh12345678\r\nHost: baska"));
    evds_test_reddet(static fn()=>evds_anahtar_dogrula('kisa'));
});
test('EVDS: anahtar GCM ile rastgele şifrelenir, kişi ve firma bağlamından ayrılamaz',function (): void {
    $master=random_bytes(32);$v='sentetik-api-anahtari-123456789';
    $a=evds_sifrele($v,$master,'1:2');$b=evds_sifrele($v,$master,'1:2');
    dogru($a!==$b);dogru(!str_contains($a,$v));esit($v,evds_sifre_coz($a,$master,'1:2'));
    evds_test_reddet(static fn()=>evds_sifre_coz($a,$master,'1:3'));
    evds_test_reddet(static fn()=>evds_sifre_coz($a,random_bytes(32),'1:2'));
    evds_test_reddet(static fn()=>evds_sifre_coz('v1:AAAA',$master,'1:2'));
});
test('EVDS: orijinal aylık sıklık, eksik gözlem ve sayısal sıfır ayrılır',function (): void {
    $o=evds_ornek();esit(true,$o['ornek']);esit(5,$o['veri']['ozet']['adet']);esit(1,$o['veri']['ozet']['eksik']);
    esit(null,$o['veri']['satirlar'][2]['deger']);esit(12.5,$o['veri']['ozet']['degisim']);
    $q=$o['veri']['sorgu'];$r=evds_gozlemler('{"items":[{"Tarih":"2024-01","TP_LUMEN_DEMO":"0"},{"Tarih":"2024-02","TP_LUMEN_DEMO":"2,5"}]}',$q);
    esit(0.0,$r[0]['deger']);esit(2.5,$r[1]['deger']);esit(null,evds_ozet($r)['degisim']);
});
test('EVDS: seri bulunmadan veri istenmez; metadata adı ve orijinal sıklık korunur',function (): void {
    $calls=[];$ornek=json_decode(file_get_contents(dirname(__DIR__,2).'/docs/evds/sentetik.json'),true);
    $q=['seri'=>'TP.LUMEN.DEMO','bas'=>'2024-01-01','bit'=>'2024-06-30'];
    $v=evds_veri_oku($q,'sentetik-anahtar-123456789',static function ($url,$key) use (&$calls,$ornek): string {
        dogru(!str_contains($url,$key));$calls[]=$url;
        return json_encode(str_contains($url,'serieList/') ? $ornek['meta'] : $ornek['veri']);
    });
    esit(2,count($calls));esit('Aylık',$v['meta']['siklik']);
    $calls=[];evds_test_reddet(static function () use ($q,&$calls): void {
        evds_veri_oku($q,'sentetik-anahtar-123456789',static function ($url) use (&$calls): string { $calls[]=$url;return '[]'; });
    });esit(1,count($calls));
});
test('EVDS: çelişkili tarih, yanlış seri ve sayısal olmayan yanıt gösterilmez',function (): void {
    $q=['seri'=>'TP.LUMEN.DEMO','bas'=>'2024-01-01','bit'=>'2024-06-30'];
    foreach (['{"items":[{"Tarih":"2024-01","TP_LUMEN_DEMO":"10"},{"Tarih":"2024-01","TP_LUMEN_DEMO":"11"}]}',
        '{"items":[{"Tarih":"2024-01","BASKA":"10"}]}','{"items":[{"Tarih":"2024-01","TP_LUMEN_DEMO":"NaN"}]}',
        '<html>giriş gerekli</html>'] as $g) { evds_test_reddet(static fn()=>evds_gozlemler($g,$q)); }
    esit([],evds_gozlemler('{"items":[]}',$q));
    esit('2024-04-01',evds_gozlem_tarihi('2024-Q2'));
    evds_test_reddet(static fn()=>evds_gozlem_tarihi('2024-13'));
});
