<?php

declare(strict_types=1);

/**
 * Çalışan PHP'nin bilinen kritik açıklara karşı durumu — yöneticiye uyarı için.
 *
 * Ekim 2026 "PHP2Shell": CVE-2026-109618 (bellek tükenmesinde yarım kalan istek verisi)
 * ve CVE-2026-109619 (istekler arası kalan durum; session.upload_progress yolu).
 * Kimlik doğrulamasız uzaktan kod çalıştırma; yalnız PHP güncellemesi tam çözümdür.
 * Yamalı sürümler: 8.2.35, 8.3.36, 8.4.27, 8.5.12. Lumen session.upload_progress
 * kullanmaz; kapatılması önerilir (php.ini, sunucu yeniden başlatılarak).
 */

if (!function_exists('lumen_php_guvenlik_sorunlari')) {
    /** @return list<string> boşsa sorun yok */
    function lumen_php_guvenlik_sorunlari(?string $surum = null, ?bool $yuklemeIlerlemesi = null): array
    {
        $surum ??= PHP_VERSION;
        $yuklemeIlerlemesi ??= filter_var(ini_get('session.upload_progress.enabled'), FILTER_VALIDATE_BOOLEAN);
        $yamali = ['8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'];
        $dal = implode('.', array_slice(explode('.', $surum), 0, 2));
        $sorunlar = [];
        if (version_compare($dal, '8.2', '<')) {
            $sorunlar[] = "PHP {$surum} desteklenmiyor ve kritik güvenlik açıkları (CVE-2026-109618/109619) için yaması yok; PHP 8.2.35 ya da üstüne geçin.";
        } elseif (isset($yamali[$dal]) && version_compare($surum, $yamali[$dal], '<')) {
            $sorunlar[] = "PHP {$surum} kritik uzaktan kod çalıştırma açıklarından etkileniyor (CVE-2026-109618, CVE-2026-109619); en az PHP {$yamali[$dal]} sürümüne güncelleyin.";
        }
        if ($yuklemeIlerlemesi && $sorunlar !== []) {
            $sorunlar[] = 'Güncelleyene kadar php.ini\'de session.upload_progress.enabled=0 yapıp web sunucusunu yeniden başlatın (Lumen bu özelliği kullanmaz).';
        }
        return $sorunlar;
    }
}
