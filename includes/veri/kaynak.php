<?php

declare(strict_types=1);

/**
 * Veri kaynağı (çalışma modu) seçimi — bağımlılığı yoktur; kurulum ve CLI
 * betikleri de kullanır.
 *
 *   LUMEN_VERI_KAYNAGI=logo   (varsayılan) Bağlı mod: iş verisi LOGO Tiger
 *                             tablolarında; Lumen ön yüz olarak çalışır.
 *   LUMEN_VERI_KAYNAGI=lumen  Bağımsız mod: iş verisi Lumen'in kendi LM_
 *                             tablolarında (sql/lumen_cekirdek/); LOGO gerekmez.
 *
 * Mod kurulumda seçilir ve çalışırken değişmez. Ayrıntı: docs/mimari/bagimsiz-lumen.md
 */

if (!defined('LUMEN_KAYNAK_LOGO')) {
    define('LUMEN_KAYNAK_LOGO', 'logo');
    define('LUMEN_KAYNAK_LUMEN', 'lumen');
}

if (!function_exists('lumen_veri_kaynagi')) {
    function lumen_veri_kaynagi(): string
    {
        $k = strtolower(trim((string) getenv('LUMEN_VERI_KAYNAGI')));
        if ($k === '' || $k === LUMEN_KAYNAK_LOGO) {
            return LUMEN_KAYNAK_LOGO;
        }
        if ($k === LUMEN_KAYNAK_LUMEN) {
            return LUMEN_KAYNAK_LUMEN;
        }
        // Yanlış yazım sessizce LOGO'ya düşmesin: yanlış veritabanına yazmaktan iyidir.
        throw new RuntimeException("Geçersiz LUMEN_VERI_KAYNAGI: '{$k}' (beklenen: logo | lumen)");
    }
}

if (!function_exists('lumen_bagimsiz_mi')) {
    /** Bağımsız mod (Lumen'in kendi veritabanı) mı? */
    function lumen_bagimsiz_mi(): bool
    {
        return lumen_veri_kaynagi() === LUMEN_KAYNAK_LUMEN;
    }
}
