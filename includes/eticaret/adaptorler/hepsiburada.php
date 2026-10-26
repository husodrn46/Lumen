<?php
declare(strict_types=1);
require_once __DIR__.'/ortak.php';

function et_hb_para(mixed $v): float
{
    if (!is_array($v) || ($v['currency'] ?? '')!=='TRY') { throw new InvalidArgumentException('Hepsiburada tutarı TRY para nesnesi olmalı.'); }
    return et_api_para($v['amount'] ?? '');
}
/** Satıcıya Ait Paket Bilgilerini Listeleme. Ödemesi beklenen sipariş ucu farklıdır. */
function et_hepsiburada_paket(mixed $p): array
{
    $aday=[];$meta=['sistem'=>'hepsiburada','durum'=>'Belirsiz','engel'=>'Hepsiburada kaydı doğrulanamadı.'];
    try {
        if (!is_array($p)) { throw new InvalidArgumentException('Hepsiburada paketi nesne olmalı.'); }
        $paket=et_api_metin($p,'packageNumber',100);
        $items=$p['items'] ?? [];
        $ilk=is_array($items) ? ($items[0] ?? []) : [];
        $no=et_metin($ilk['orderNumber'] ?? $p['orderNumber'] ?? '');
        $aday=['kimlik'=>'HB:'.$paket,'numara'=>$no,'paket_kimligi'=>$paket];
        $durum=et_api_metin($p,'status',50); $meta['durum']=$durum;
        $meta['engel']=et_api_durum_engeli('Hepsiburada',$durum,['Open','Packaged']);
        if (!empty($p['lastStatusUpdateDate'])) { $meta['guncelleme']=et_api_iso_tarih($p['lastStatusUpdateDate']); }
        if (!is_array($items) || !array_is_list($items) || !$items || count($items)>200) { throw new InvalidArgumentException('Hepsiburada paket kalemleri eksik veya sınır dışında.'); }
        if (isset($p['orderNumber']) && et_metin($p['orderNumber'])!==$no) { throw new InvalidArgumentException('Hepsiburada paket/sipariş numaraları çelişiyor.'); }
        $out=[];$toplam=0.0;$ids=[];$kdvToplam=0.0;
        foreach ($items as $k) {
            if (!is_array($k)) { throw new InvalidArgumentException('Hepsiburada kalemi geçersiz.'); }
            if (et_api_metin($k,'orderNumber')!==$no) { throw new InvalidArgumentException('Birden fazla sipariş içeren Hepsiburada paketi bu sürümde desteklenmiyor.'); }
            $id=et_api_metin($k,'lineItemId');
            if (isset($ids[$id])) { throw new InvalidArgumentException('Hepsiburada paketinde tekrarlı kalem kimliği.'); }$ids[$id]=true;
            $q=et_sayi($k['quantity'] ?? '',1,1000000);
            if (floor($q)!==$q) { throw new InvalidArgumentException('Hepsiburada miktarı tam adet olmalı.'); }
            $f=et_hb_para($k['price'] ?? null);$t=et_hb_para($k['totalPrice'] ?? null);
            et_api_esit($f*$q,$t,'Hepsiburada kalem toplamı');
            $oran=et_sayi($k['vatRate'] ?? '',0,100);$vat=et_api_para($k['vat'] ?? '');
            et_api_esit($t*$oran/(100+$oran),$vat,'Hepsiburada KDV dahil tutar/KDV');
            // Alıcı ve HB için farklı fatura tutarı üreten indirimler ayrı bir iş kuralıdır.
            foreach (['discountToBeBilledToHB','deptorDifferenceAmount','unitHBDiscount','totalHBDiscount','unitmerchantDiscount','totalmerchantDiscount'] as $alan) {
                if (!isset($k[$alan])) { continue; }
                $deger=is_array($k[$alan]) ? et_hb_para($k[$alan]) : et_api_para($k[$alan]);
                if ($deger>0) { throw new InvalidArgumentException('Hepsiburada indirim/fatura farkı bu sürümde ayrı inceleme gerektiriyor: '.$alan); }
            }
            if (isset($k['merchantTotalPrice'])) {
                $merchant=is_array($k['merchantTotalPrice']) ? et_hb_para($k['merchantTotalPrice']) : et_api_para($k['merchantTotalPrice']);
                et_api_esit($merchant,$t,'Hepsiburada satıcı/müşteri toplamı');
            }
            if (isset($k['status']) && !in_array($k['status'],['Open','Packaged'],true)) { $meta['engel']='Hepsiburada kalemi aktarım için uygun değil: '.et_metin($k['status'],50); }
            $out[]=['harici_kimlik'=>$id,'sku'=>et_metin($k['merchantSku'] ?? ''),'barkod'=>et_metin($k['productBarcode'] ?? ''),
                'ad'=>et_metin($k['productName'] ?? '',200),'birim'=>'ADET','miktar'=>$q,'fiyat'=>$f,'kdv'=>$oran,'kdv_dahil'=>true];
            $toplam+=$t;$kdvToplam+=$vat;
        }
        et_api_esit($toplam,et_hb_para($p['totalPrice'] ?? null),'Hepsiburada paket toplamı');
        $meta['finans']=['KDV dahil net'=>$toplam,'KDV tutarı'=>$kdvToplam];
        $fatura=['name'=>$p['companyName'] ?? $p['customerName'] ?? $p['recipientName'] ?? '',
            'address'=>$p['billingAddress'] ?? '', 'city'=>$p['billingCity'] ?? '', 'town'=>$p['billingTown'] ?? '',
            'postalCode'=>$p['billingPostalCode'] ?? '', 'taxNumber'=>$p['taxNumber'] ?? '', 'taxOffice'=>$p['taxOffice'] ?? '',
            'turkishIdentityNumber'=>$p['identityNo'] ?? ''];
        $teslimat=['name'=>$p['recipientName'] ?? '', 'address'=>$p['shippingAddressDetail'] ?? '', 'city'=>$p['shippingCity'] ?? '',
            'town'=>$p['shippingTown'] ?? '', 'countryCode'=>$p['shippingCountryCode'] ?? '', 'phoneNumber'=>$p['phoneNumber'] ?? '', 'email'=>$p['email'] ?? ''];
        if (isset($p['shippingCountryCode']) && $p['shippingCountryCode']!=='TR') { $meta['engel']='Hepsiburada ihracat siparişleri bu sürümde desteklenmiyor.'; }
        $aday+=['tarih'=>et_api_gun(et_api_iso_tarih($p['orderDate'] ?? '')),'para_birimi'=>'TRY','genel_toplam'=>$toplam,
            'musteri'=>['ad'=>et_metin($p['customerName'] ?? $p['recipientName'] ?? '',200)],'fatura'=>et_api_adres($fatura),
            'teslimat'=>et_api_adres($teslimat),'kalemler'=>$out];
        return et_kayit_hazirla($p,$aday,$meta);
    } catch (InvalidArgumentException $e) {
        $meta['engel']=$meta['engel'] ?: $e->getMessage();
        return et_kayit_hazirla($p,$aday,$meta,$e->getMessage());
    }
}
