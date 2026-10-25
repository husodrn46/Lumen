<?php
declare(strict_types=1);
require __DIR__ . '/_ortak.php';
$yonetici = (new Tarayici($URL))->giris('YONETICI');
$personel = (new Tarayici($URL))->giris('DEPO1');
akis('e-ticaret: kanal kaydı, pasiflik ve CSRF/yetki', function () use ($pdo,$yonetici,$personel): void {
    $ad = 'Sentetik kanal ' . bin2hex(random_bytes(4));
    $p = [':ad' => $ad];
    $r = $yonetici->istek('eticaret/kanallar.php', ['csrf_token' => $yonetici->belirtec('eticaret/kanallar.php'),
        'ad' => $ad, 'tur' => 'site', 'aktif' => '1', 'cari' => 4, 'depo' => 0, 'personel' => 1]);
    bekle($r['kod'] === 303, 'kanal kaydedilemedi: ' . strip_tags($r['govde']));
    $id = (int) tek($pdo, 'SELECT ID FROM M_ET_KANAL WHERE AD=:ad', $p);
    bekle($id > 0, 'kanal yok');
    bekle($personel->istek('eticaret/kanallar.php')['kod'] === 403, 'yetkisiz kanal erişimi');
    bekle($yonetici->istek('eticaret/kanallar.php', ['ad' => $ad])['kod'] === 403, 'CSRF eksik istek kabul edildi');
    $g = $yonetici->istek('eticaret/kanallar.php?id=' . $id)['govde'];
    preg_match('/name="surum" value="([^"]+)"/', $g, $m);
    $r = $yonetici->istek('eticaret/kanallar.php', ['csrf_token' => $yonetici->belirtec('eticaret/kanallar.php'),
        'id'=>$id,'surum'=>$m[1] ?? '', 'ad'=>$ad,'tur'=>'site','cari'=>4,'depo'=>0,'personel'=>1]);
    bekle($r['kod'] === 303 && (int) tek($pdo, 'SELECT AKTIF FROM M_ET_KANAL WHERE ID=:i', [':i'=>$id]) === 0, 'pasiflik kaydedilemedi');
});
$etKanal=0; $etSiparis=0;
akis('e-ticaret: önizleme, kutuya alma, tekrar ve değişmiş içerik', function () use ($pdo,$yonetici,&$etKanal,&$etSiparis): void {
    $ad='İçe aktarma '.bin2hex(random_bytes(4));
    $yonetici->istek('eticaret/kanallar.php',['csrf_token'=>$yonetici->belirtec('eticaret/kanallar.php'),
        'ad'=>$ad,'tur'=>'site','aktif'=>1,'cari'=>4,'depo'=>0,'personel'=>1]);
    $etKanal=(int) tek($pdo,'SELECT ID FROM M_ET_KANAL WHERE AD=:a',[':a'=>$ad]);
    $ornek=json_decode(file_get_contents(dirname(__DIR__,2).'/docs/eticaret/ornek.json'),true);
    $al=static function(array $v) use ($yonetici,$etKanal): string {
        $yol=tempnam(sys_get_temp_dir(),'et_');
        try {
            file_put_contents($yol,json_encode($v));
            $r=$yonetici->istekDosya('eticaret/ice_aktar.php',['csrf_token'=>$yonetici->belirtec('eticaret/ice_aktar.php'),
                'islem'=>'yukle','kanal'=>$etKanal],'dosya',$yol,'sentetik.json');
            preg_match('/name="anahtar" value="([a-f0-9]+)"/',$r['govde'],$m);
            bekle(isset($m[1]),'önizleme yok: '.strip_tags($r['govde']));
            return $yonetici->istek('eticaret/ice_aktar.php',['csrf_token'=>$yonetici->belirtec('eticaret/ice_aktar.php'),
                'islem'=>'kaydet','anahtar'=>$m[1]])['govde'];
        } finally { unlink($yol); }
    };
    $once=(int) tek($pdo,'SELECT COUNT(*) FROM LG_001_02_ORFICHE');
    bekle(str_contains($al($ornek),'Yeni: 1'),'ilk alım başarısız');
    $etSiparis=(int) tek($pdo,'SELECT ID FROM M_ET_SIPARIS WHERE KANAL_ID=:k',[':k'=>$etKanal]);
    bekle(str_contains($al($ornek),'Mükerrer: 1'),'tekrar algılanmadı');
    $ornek['siparisler'][0]['genel_toplam']=123;
    bekle(str_contains($al($ornek),'Değişmiş içerik: 1'),'değişmiş veri ezildi');
    bekle((int) tek($pdo,'SELECT COUNT(*) FROM M_ET_SIPARIS WHERE KANAL_ID=:k',[':k'=>$etKanal])===1,'kutu çoğaldı');
    bekle((int) tek($pdo,'SELECT COUNT(*) FROM LG_001_02_ORFICHE')===$once,'onaysız satış siparişi oluştu');
});
$basarisiz = count(array_filter($sonuclar, static fn($r) => !$r[0]));
printf("%d e-ticaret akışı, %d başarısız\n", count($sonuclar), $basarisiz);
exit($basarisiz ? 1 : 0);
