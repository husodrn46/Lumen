<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/eticaret/okuyucu.php';
function et_ornek_api(string $bicim): array
{
    return json_decode(file_get_contents(dirname(__DIR__,2).'/docs/eticaret/'.$bicim.'.json'),true);
}
test('Trendyol v2: paket/sipariş ayrımı ve indirimli fiyat korunur', function (): void {
    $v=et_dosya_oku(json_encode(et_ornek_api('trendyol_v2')),'json','trendyol_v2')[0];
    esit(null,$v['hata']);esit(null,$v['kaynak_engeli']);esit('TY:9000000001',$v['kimlik']);
    esit('TY-DEMO-0001',$v['numara']);esit('9000000001',$v['paket_kimligi']);
    esit(60.0,$v['normal']['kalemler'][0]['fiyat']);esit(120.0,$v['normal']['genel_toplam']);
    esit(24.0,$v['normal']['kaynak_bilgileri']['finans']['Satıcı indirimi']);
});
test('Trendyol: iptal/ödeme/belirsiz durum aktarımı engeller; eski şema tahmin edilmez', function (): void {
    foreach (['Awaiting','Verified','Cancelled','UnPacked','Returned','Delivered','Bilinmeyen'] as $status) {
        $j=et_ornek_api('trendyol_v2');$j['content'][0]['shipmentPackageStatus']=$status;$j['content'][0]['status']=$status;
        $r=et_dosya_oku(json_encode($j),'json','trendyol_v2')[0];dogru($r['kaynak_engeli']!==null,$status);
    }
    $j=et_ornek_api('trendyol_v2');unset($j['content'][0]['shipmentPackageId']);$j['content'][0]['id']=1;
    dogru(et_dosya_oku(json_encode($j),'json','trendyol_v2')[0]['hata']!==null);
});
test('Trendyol: brüt/indirim/net tutarsızlığı, eksik KDV ve tekrarlı satır reddedilir', function (): void {
    foreach (['packageTotalPrice'=>119,'packageSellerDiscount'=>25] as $alan=>$deger) {
        $j=et_ornek_api('trendyol_v2');$j['content'][0][$alan]=$deger;
        dogru(et_dosya_oku(json_encode($j),'json','trendyol_v2')[0]['hata']!==null,$alan);
    }
    $j=et_ornek_api('trendyol_v2');unset($j['content'][0]['lines'][0]['vatRate']);
    dogru(et_dosya_oku(json_encode($j),'json','trendyol_v2')[0]['hata']!==null);
    $j=et_ornek_api('trendyol_v2');$j['content'][0]['lines'][]=$j['content'][0]['lines'][0];
    dogru(et_dosya_oku(json_encode($j),'json','trendyol_v2')[0]['hata']!==null);
});
test('Hepsiburada: vat tutardır, vatRate oran olarak alınır; adres ve kimlikler korunur', function (): void {
    $j=et_ornek_api('hepsiburada_paket');$j[0]['totalPrice']['amount']=240;
    $j[0]['items'][0]['price']['amount']=120;$j[0]['items'][0]['totalPrice']['amount']=240;$j[0]['items'][0]['vat']=40;
    $r=et_dosya_oku(json_encode($j),'json','hepsiburada_paket')[0];
    esit(null,$r['hata']);esit(null,$r['kaynak_engeli']);esit(20.0,$r['normal']['kalemler'][0]['kdv']);
    esit(240.0,$r['normal']['genel_toplam']);esit('Örnek Fatura Mahallesi',$r['normal']['fatura']['adres']);
});
test('Hepsiburada: belirsiz durum, KDV farkı ve farklı fatura indirimi bekletilir', function (): void {
    $j=et_ornek_api('hepsiburada_paket');$j[0]['status']='CancelledByCustomer';
    dogru(et_dosya_oku(json_encode($j),'json','hepsiburada_paket')[0]['kaynak_engeli']!==null);
    $j=et_ornek_api('hepsiburada_paket');$j[0]['items'][0]['vat']=21;
    dogru(et_dosya_oku(json_encode($j),'json','hepsiburada_paket')[0]['hata']!==null);
    $j=et_ornek_api('hepsiburada_paket');$j[0]['items'][0]['discountToBeBilledToHB']=1;
    dogru(et_dosya_oku(json_encode($j),'json','hepsiburada_paket')[0]['hata']!==null);
});
test('Pazaryeri okuyucu: boş sayfa ve büyük kimlik korunur; yanlış biçim reddedilir', function (): void {
    esit([],et_dosya_oku('{"content":[]}','json','trendyol_v2'));
    esit([],et_dosya_oku('[]','json','hepsiburada_paket'));
    $j=et_ornek_api('trendyol_v2');$raw=str_replace('"shipmentPackageId":"9000000001"','"shipmentPackageId":99999999999999999999',json_encode($j));
    esit('TY:99999999999999999999',et_dosya_oku($raw,'json','trendyol_v2')[0]['kimlik']);
    try { et_dosya_oku('[]','csv','trendyol_v2');dogru(false,'CSV kabul edildi'); } catch (InvalidArgumentException) {}
});
