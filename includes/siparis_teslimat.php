<?php

declare(strict_types=1);

/**
 * Sipariş satırındaki toplam, teslim edilen ve kalan miktarı güvenli biçimde hesaplar.
 *
 * @return array{toplam: float, teslim_edilen: float, kalan: float, durum: 'bekliyor'|'kismi'|'tamamlandi'}
 */
function lumen_siparis_teslimat_miktarlari(
    float|int|string|null $toplam,
    float|int|string|null $teslimEdilen
): array {
    $toplamMiktar = max(0.0, (float) ($toplam ?? 0));
    $teslimMiktari = max(0.0, (float) ($teslimEdilen ?? 0));
    $kalanMiktar = max(0.0, $toplamMiktar - $teslimMiktari);
    $epsilon = 0.000001;

    if ($teslimMiktari <= $epsilon) {
        $durum = 'bekliyor';
    } elseif ($kalanMiktar <= $epsilon) {
        $durum = 'tamamlandi';
    } else {
        $durum = 'kismi';
    }

    return [
        'toplam' => $toplamMiktar,
        'teslim_edilen' => $teslimMiktari,
        'kalan' => $kalanMiktar,
        'durum' => $durum,
    ];
}
