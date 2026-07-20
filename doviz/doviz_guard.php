<?php
declare(strict_types=1);

if (!function_exists('doviz_require_m21')) {
    /**
     * Döviz modülünü sadece M21 yetkisine aç.
     */
    function doviz_require_m21(int|string|null $terminalkullanici): void
    {
        if ($terminalkullanici === null || m_p_yetki($terminalkullanici, 'M21') != 1) {
            header('Location: ' . APP_ROOT_URL . '/403.html', true, 303);
            exit;
        }
    }
}
