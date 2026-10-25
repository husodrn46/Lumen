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
$basarisiz = count(array_filter($sonuclar, static fn($r) => !$r[0]));
printf("%d e-ticaret akışı, %d başarısız\n", count($sonuclar), $basarisiz);
exit($basarisiz ? 1 : 0);
