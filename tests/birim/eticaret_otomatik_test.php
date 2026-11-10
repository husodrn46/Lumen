<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/eticaret/otomatik.php';

function et_oto_an(string $t): DateTimeImmutable
{
    return new DateTimeImmutable($t,new DateTimeZone('Europe/Istanbul'));
}

/** Görevin ürettiği aralık, ekrandaki elle alımla aynı adaptör doğrulamasından geçmelidir. */
function et_oto_gecerli(string $tur, array $p, DateTimeImmutable $simdi): void
{
    ($a=et_ag_adaptor($tur))['aralik']($p['bas'],$p['bit'],$simdi);
    $sure=et_oto_an($p['bit'])->getTimestamp()-et_oto_an($p['bas'])->getTimestamp();
    dogru($sure>0 && $sure<=$a['en_uzun'],$tur.' aralığı sınır dışında: '.$p['bas'].' – '.$p['bit']);
}

test('Otomatik alım: ilk aralık son 24 saat, dakikaya yuvarlanır ve doğrulamadan geçer',function (): void {
    $simdi=et_oto_an('2026-11-10 20:47:31');
    foreach (['trendyol','hepsiburada'] as $tur) {
        $p=et_otomatik_pencere(et_ag_adaptor($tur),null,$simdi);
        esit(['bas'=>'2026-11-09T20:47','bit'=>'2026-11-10T20:47','bosluk'=>false],$p);et_oto_gecerli($tur,$p,$simdi);
    }
});

test('Otomatik alım: sonraki aralık 10 dk örtüşür, 5 dk dolmadan açılmaz',function (): void {
    $a=et_ag_adaptor('trendyol');$son='2026-11-10 17:30:00.0000000';   // UTC = 20:30 İstanbul
    esit(null,et_otomatik_pencere($a,$son,et_oto_an('2026-11-10 20:34:59')),'5 dk dolmadan aralık açıldı');
    $simdi=et_oto_an('2026-11-10 20:35:00');$p=et_otomatik_pencere($a,$son,$simdi);
    esit(['bas'=>'2026-11-10T20:20','bit'=>'2026-11-10T20:35','bosluk'=>false],$p);et_oto_gecerli('trendyol',$p,$simdi);
    esit(null,et_otomatik_pencere($a,'2026-11-10 18:00:00',et_oto_an('2026-11-10 20:47:00')),'ileri tarihli son nokta yeni aralık açtı');
});

test('Otomatik alım: Hepsiburada boşluğu 24 saatlik dilimlerle kapanır',function (): void {
    $a=et_ag_adaptor('hepsiburada');$simdi=et_oto_an('2026-11-10 20:47:31');$son='2026-11-07 10:00:00';$dilim=0;
    while (($p=et_otomatik_pencere($a,$son,$simdi))!==null) {
        et_oto_gecerli('hepsiburada',$p,$simdi);$dilim++;
        dogru($dilim<10,'aralıklar ilerlemiyor');
        $son=et_oto_an($p['bit'])->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    esit(4,$dilim,'üç günü aşan boşluk dört dilimde kapanmalı');
});

test('Otomatik alım: Trendyol 14 gün ve üç ay sınırına uyar, eski boşluğu bildirir',function (): void {
    $a=et_ag_adaptor('trendyol');$simdi=et_oto_an('2026-11-10 20:47:31');
    $p=et_otomatik_pencere($a,'2026-10-01 09:00:00',$simdi);
    esit(['bas'=>'2026-10-01T11:50','bit'=>'2026-10-15T11:50','bosluk'=>false],$p);et_oto_gecerli('trendyol',$p,$simdi);
    $p=et_otomatik_pencere($a,'2026-06-01 09:00:00',$simdi);
    dogru($p['bosluk'],'üç aydan eski boşluk bildirilmedi');esit('2026-08-10T21:47',$p['bas']);et_oto_gecerli('trendyol',$p,$simdi);
});

test('Otomatik alım durumu: görev hiç çalışmadıysa veya bir saati aştıysa gecikmiş sayılır',function (): void {
    dogru(!et_otomatik_durum(null)['acik'],'bağlantısız kanal açık görünüyor');
    $bag=['OTOMATIK'=>'1','OTOMATIK_SON'=>null,'OTOMATIK_KONTROL'=>null,'OTOMATIK_HATA'=>null];
    $d=et_otomatik_durum($bag);dogru($d['acik'] && $d['gecikti'],'hiç çalışmayan görev gecikmiş sayılmadı');
    $d=et_otomatik_durum(['OTOMATIK_KONTROL'=>gmdate('Y-m-d H:i:s',time()-60)]+$bag);dogru(!$d['gecikti'],'yeni kontrol gecikmiş sayıldı');
    $d=et_otomatik_durum(['OTOMATIK_KONTROL'=>gmdate('Y-m-d H:i:s',time()-ET_OTOMATIK_GECIKME-60)]+$bag);dogru($d['gecikti'],'eski kontrol gecikmiş sayılmadı');
    dogru(!et_otomatik_durum(['OTOMATIK'=>'0']+$bag)['acik'],'kapalı bağlantı açık görünüyor');
});
