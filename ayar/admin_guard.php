<?php
declare(strict_types=1);

if (!function_exists('ayar_forbidden')) {
    /**
     * Yetkisiz istekleri tek noktadan yönet.
     */
    function ayar_forbidden(bool $asJson = false, string $message = 'Erişim reddedildi'): never
    {
        if ($asJson) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: ' . APP_ROOT_URL . '/403.html', true, 303);
        exit;
    }
}

if (!function_exists('ayar_require_m16')) {
    /**
     * Ayarlar modülünü sadece M16 yetkisine aç.
     */
    function ayar_require_m16(int|string|null $terminalkullanici, bool $asJson = false): void
    {
        if ($terminalkullanici === null || m_p_yetki($terminalkullanici, 'M16') != 1) {
            ayar_forbidden($asJson, 'Bu işlem için M16 yetkisi gerekiyor');
        }
    }
}

if (!function_exists('ayar_require_csrf')) {
    /**
     * Durum değiştiren isteklerde CSRF token zorunluluğu.
     */
    function ayar_require_csrf(bool $asJson = false): void
    {
        if (!csrf_verify()) {
            ayar_forbidden($asJson, 'Geçersiz güvenlik doğrulaması');
        }
    }
}

