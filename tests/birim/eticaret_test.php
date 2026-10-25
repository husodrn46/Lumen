<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/eticaret/okuyucu.php';
test('e-ticaret adaptörleri: JSON/CSV aynı kalem tutarlarını ve adresleri korur', function (): void {
    $kok=dirname(__DIR__,2).'/docs/eticaret/ornek.';
    $j=et_dosya_oku(file_get_contents($kok.'json'),'json');
    $c=et_dosya_oku(file_get_contents($kok.'csv'),'csv');
    esit(null,$j[0]['hata']); esit(null,$c[0]['hata']);
    esit($j[0]['normal']['kalemler'],$c[0]['normal']['kalemler']);
    esit($j[0]['normal']['fatura'],$c[0]['normal']['fatura']);
});
test('e-ticaret: geçersiz sayı, tarih, para birimi ve eksik kalem atlanmaz', function (): void {
    $ham=json_decode(file_get_contents(dirname(__DIR__,2).'/docs/eticaret/ornek.json'),true);
    foreach (['miktar'=>'12abc','fiyat'=>'1.234,56','kdv'=>101,'kdv_dahil'=>'false'] as $alan=>$deger) {
        $x=$ham; $x['siparisler'][0]['kalemler'][0][$alan]=$deger;
        dogru(et_dosya_oku(json_encode($x),'json')[0]['hata'] !== null,$alan);
    }
    foreach (['tarih'=>'2026-02-30','para_birimi'=>'EUR','kargo'=>5,'kalemler'=>[]] as $alan=>$deger) {
        $x=$ham; $x['siparisler'][0][$alan]=$deger;
        dogru(et_dosya_oku(json_encode($x),'json')[0]['hata'] !== null,$alan);
    }
});
