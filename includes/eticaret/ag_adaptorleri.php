<?php
declare(strict_types=1);
require_once __DIR__.'/adaptorler/trendyol_http.php';
require_once __DIR__.'/adaptorler/hepsiburada_http.php';

/** Kapalı kayıt defteri; kullanıcı girdisi dosya veya çalıştırılacak fonksiyon seçemez. */
function et_ag_adaptor(string $tur): array
{
    return match ($tur) {
        'trendyol'=>['ad'=>'Trendyol','bicim'=>'trendyol_v2','kimlik'=>'et_ty_kimlik','aralik'=>'et_ty_aralik','istek'=>'et_ty_istek','yanit'=>'et_ty_yanit','http'=>'et_ty_http'],
        'hepsiburada'=>['ad'=>'Hepsiburada','bicim'=>'hepsiburada_paket','kimlik'=>'et_hb_kimlik','aralik'=>'et_hb_aralik','istek'=>'et_hb_istek','yanit'=>'et_hb_yanit','http'=>'et_hb_http'],
        default=>throw new DomainException('Bu kanal için API bağlantısı henüz desteklenmiyor.')
    };
}
