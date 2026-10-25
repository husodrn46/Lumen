<?php
declare(strict_types=1);
require_once __DIR__.'/ortak.php';

/** Get Shipment Packages v2 / stream: güncel alan isimleri kullanılır. */
function et_trendyol_paket(mixed $p): array
{
    $aday=[]; $meta=['sistem'=>'trendyol','durum'=>'Belirsiz','engel'=>'Trendyol kaydı doğrulanamadı.'];
    try {
        if (!is_array($p)) { throw new InvalidArgumentException('Trendyol paketi nesne olmalı.'); }
        $paket=et_api_metin($p,'shipmentPackageId',100);
        $aday=['kimlik'=>'TY:'.$paket,'numara'=>et_api_metin($p,'orderNumber'),'paket_kimligi'=>$paket];
        $durum=et_api_metin($p,'shipmentPackageStatus',50);
        $meta['durum']=$durum;
        $meta['engel']=et_api_durum_engeli('Trendyol',$durum,['Created','Picking']);
        $meta['guncelleme']=et_api_milisaniye($p['lastModifiedDate'] ?? '');
        if (isset($p['status']) && $p['status']!==$durum) { throw new InvalidArgumentException('Trendyol paket durumları çelişiyor.'); }
        if (($p['currencyCode'] ?? '')!=='TRY') { throw new InvalidArgumentException('Trendyol adaptörü yalnız TRY destekler.'); }
        if (($p['micro'] ?? false) || ($p['3pByTrendyol'] ?? false) || ($p['orderCountryCode'] ?? 'TR')!=='TR') {
            $meta['engel']='Trendyol ihracat/aracılık modeli bu sürümde desteklenmiyor.';
        }
        $origins=$p['originPackageIds'] ?? [];
        if (!is_array($origins) || !array_is_list($origins)) { throw new InvalidArgumentException('Önceki paket kimlikleri liste olmalı.'); }
        $meta['eski_paketler']=array_map(static fn($id)=>et_metin($id,100),$origins);
        $kalemler=$p['lines'] ?? null;
        if (!is_array($kalemler) || !array_is_list($kalemler) || !$kalemler || count($kalemler)>200) { throw new InvalidArgumentException('Trendyol paket kalemleri eksik veya sınır dışında.'); }
        $out=[]; $brut=0.0; $satici=0.0; $platform=0.0; $net=0.0; $ids=[];
        foreach ($kalemler as $k) {
            if (!is_array($k)) { throw new InvalidArgumentException('Trendyol kalemi geçersiz.'); }
            $id=et_api_metin($k,'lineId');
            if (isset($ids[$id])) { throw new InvalidArgumentException('Trendyol paketinde tekrarlı kalem kimliği.'); } $ids[$id]=true;
            $q=et_sayi($k['quantity'] ?? '',1,1000000);
            if (floor($q)!==$q) { throw new InvalidArgumentException('Trendyol miktarı tam adet olmalı.'); }
            if (($k['currencyCode'] ?? '')!=='TRY') { throw new InvalidArgumentException('Trendyol kalem para birimi farklı.'); }
            $birimBrut=et_api_para($k['lineGrossAmount'] ?? '');
            $ds=et_api_para($k['lineSellerDiscount'] ?? ''); $dp=et_api_para($k['lineTyDiscount'] ?? '');
            $f=et_sayi($k['lineUnitPrice'] ?? '',0.000001,100000000);
            et_api_esit($birimBrut-$ds-$dp,$f,'Trendyol indirimli birim fiyatı');
            et_api_esit(et_api_para($k['lineTotalDiscount'] ?? ''),$ds+$dp,'Trendyol kalem indirimi');
            $lineStatus=et_api_metin($k,'orderLineItemStatusName',50);
            if (!in_array($lineStatus,['Created','Picking'],true)) { $meta['engel']='Trendyol kalemi aktarım için uygun değil: '.$lineStatus; }
            $out[]=['harici_kimlik'=>$id,'sku'=>et_metin($k['stockCode'] ?? ''),'barkod'=>et_metin($k['barcode'] ?? ''),
                'ad'=>et_metin($k['productName'] ?? '',200),'birim'=>'ADET','miktar'=>$q,'fiyat'=>$f,'kdv'=>$k['vatRate'] ?? '', 'kdv_dahil'=>true];
            $brut+=round($q*$birimBrut,2);$satici+=round($q*$ds,2);$platform+=round($q*$dp,2);$net+=round($q*$f,2);
        }
        $toplam=et_api_para($p['packageTotalPrice'] ?? '');
        et_api_esit($brut,et_api_para($p['packageGrossAmount'] ?? ''),'Trendyol paket brütü');
        et_api_esit($satici,et_api_para($p['packageSellerDiscount'] ?? ''),'Trendyol satıcı indirimi');
        et_api_esit($platform,et_api_para($p['packageTyDiscount'] ?? ''),'Trendyol platform indirimi');
        et_api_esit($satici+$platform,et_api_para($p['packageTotalDiscount'] ?? ''),'Trendyol toplam indirimi');
        et_api_esit($net,$toplam,'Trendyol paket toplamı');
        $meta['finans']=['KDV dahil brüt'=>$brut,'Satıcı indirimi'=>$satici,'Platform indirimi'=>$platform,'KDV dahil net'=>$toplam];
        $aday+=['tarih'=>et_api_gun(et_api_milisaniye($p['orderDate'] ?? '')),'para_birimi'=>'TRY','genel_toplam'=>$toplam,
            'musteri'=>['ad'=>trim(et_metin($p['customerFirstName'] ?? '').' '.et_metin($p['customerLastName'] ?? '')),
                'eposta'=>et_metin($p['customerEmail'] ?? '',250)],'fatura'=>et_api_adres($p['invoiceAddress'] ?? null),
            'teslimat'=>et_api_adres($p['shipmentAddress'] ?? null),'kalemler'=>$out];
        return et_kayit_hazirla($p,$aday,$meta);
    } catch (InvalidArgumentException $e) {
        $meta['engel']=$meta['engel'] ?: $e->getMessage();
        return et_kayit_hazirla($p,$aday,$meta,$e->getMessage());
    }
}
