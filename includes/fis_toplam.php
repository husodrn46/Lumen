<?php

declare(strict_types=1);

/**
 * Sipariş fişi (ORFICHE) toplamlarının ekran/PDF/Excel çıktılarında tek ve
 * tutarlı hesabı. Bağımlılık içermez (birim testlidir: tests/birim).
 */

if (!function_exists('lumen_fis_toplamlari')) {
    /**
     * LOGO'da ORFICHE.NETTOTAL KDV DAHİL genel toplamdır. Bazı ekranlar onu
     * "KDV hariç net" sayıp üstüne TOTALVAT ekliyor, KDV'yi iki kez sayıyordu.
     *
     * @param array<string,mixed> $fis GROSSTOTAL, TOTALDISCOUNTS, TOTALVAT, NETTOTAL
     * @return array{brut:float, iskonto:float, net_kdv_haric:float, kdv:float, genel:float}
     */
    function lumen_fis_toplamlari(array $fis): array
    {
        $brut    = (float) ($fis['GROSSTOTAL'] ?? 0);
        $iskonto = (float) ($fis['TOTALDISCOUNTS'] ?? 0);
        $kdv     = (float) ($fis['TOTALVAT'] ?? 0);
        $netDb   = (float) ($fis['NETTOTAL'] ?? 0);

        $netKdvHaric = $brut - $iskonto;
        if ($netKdvHaric < 0) {
            $netKdvHaric = max(0.0, $netDb - $kdv);
        }

        return [
            'brut'          => round($brut, 2),
            'iskonto'       => round($iskonto, 2),
            'net_kdv_haric' => round($netKdvHaric, 2),
            'kdv'           => round($kdv, 2),
            'genel'         => round($netKdvHaric + $kdv, 2),
        ];
    }
}
