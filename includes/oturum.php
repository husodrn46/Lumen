<?php

declare(strict_types=1);

/**
 * Oturumu güvenli çerez ayarlarıyla başlatan tek nokta.
 *
 * Çerez parametreleri session_start()'tan ÖNCE verilmelidir. log_ip.php gibi
 * kontrol.php'den önce yüklenen dosyalar oturumu varsayılan ayarlarla açtığında
 * kontrol.php'deki HttpOnly / SameSite=Strict / Secure ayarları uygulanamıyordu.
 */
if (!function_exists('lumen_oturum_baslat')) {
    function lumen_oturum_baslat(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        if (!headers_sent()) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $https,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        session_start();
    }
}
