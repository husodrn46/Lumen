<?php
declare(strict_types=1);

/** Kullanıcının seçtiği biçim sabit kayıt defterinden gelir; dosya adı/kod yüklenmez. */
function et_bicimler(): array
{
    return ['lumen'=>['ad'=>'Lumen genel CSV / JSON','kanal'=>null],
        'trendyol_v2'=>['ad'=>'Trendyol sipariş paketleri v2 JSON','kanal'=>'trendyol'],
        'hepsiburada_paket'=>['ad'=>'Hepsiburada paket listesi JSON','kanal'=>'hepsiburada']];
}
function et_adaptor_oku(string $ham, string $bicim): array
{
    require_once __DIR__.'/adaptorler/trendyol.php';
    require_once __DIR__.'/adaptorler/hepsiburada.php';
    $j=json_decode($ham,true,64,JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    if ($bicim==='trendyol_v2') {
        if (!is_array($j) || !is_array($j['content'] ?? null) || !array_is_list($j['content'])) {
            throw new InvalidArgumentException('Trendyol yanıtı content paket listesi içermeli.');
        }
        $liste=$j['content'];$fn='et_trendyol_paket';
    } elseif ($bicim==='hepsiburada_paket') {
        if (!is_array($j) || !array_is_list($j)) { throw new InvalidArgumentException('Hepsiburada paket yanıtı JSON listesi olmalı.'); }
        $liste=$j;$fn='et_hepsiburada_paket';
    } else { throw new InvalidArgumentException('İçe aktarma biçimi tanınmadı.'); }
    if (count($liste)>100) { throw new InvalidArgumentException('Bir dosyada en fazla 100 paket alınabilir.'); }
    // Boş bir API sayfası hata değildir; önizlemede açıkça gösterilir.
    return array_map($fn,$liste);
}
