<?php

declare(strict_types=1);

/**
 * İstemci IP adresinin tespiti (saldırı modu yerel ağ kuralı, IP başına giriş
 * deneme sınırı ve loglar bunu kullanır). Bağımlılık içermez; birim testlidir.
 */

if (!function_exists('lumen_istemci_ip')) {
    /**
     * REMOTE_ADDR güvenilir bir proxy ise X-Forwarded-For zincirinde SAĞDAN sola
     * ilerleyip güvenilir olmayan ilk adresi döndürür. Zincirin en solundaki değeri
     * istemci kendisi yazabilir; eskiden o alındığından proxy arkasında
     * "X-Forwarded-For: 192.168.1.5" göndererek yerel ağdan geliyormuş gibi
     * görünmek ve IP başına deneme sınırını aşmak mümkündü.
     *
     * @param array<string,mixed> $server     $_SERVER
     * @param string[]            $guvenilir  Güvenilir proxy adresleri
     */
    function lumen_istemci_ip(array $server, array $guvenilir): string
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        if ($remote === '' || !in_array($remote, $guvenilir, true)) {
            return $remote !== '' ? $remote : 'UNKNOWN';
        }
        $zincir = array_map('trim', explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '')));
        for ($i = count($zincir) - 1; $i >= 0; $i--) {
            $ip = $zincir[$i];
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                break; // bozuk zincir: daha soldakilere güvenme
            }
            if (!in_array($ip, $guvenilir, true)) {
                return $ip;
            }
        }
        return $remote;
    }
}

if (!function_exists('lumen_guvenilir_proxyler')) {
    /** Varsayılan: yerel makine. .env TRUSTED_PROXIES=10.0.0.5,10.0.0.6 ile genişletilir. */
    function lumen_guvenilir_proxyler(): array
    {
        $ek = array_filter(array_map('trim', explode(',', (string) getenv('TRUSTED_PROXIES'))));
        return array_values(array_unique(array_merge(['127.0.0.1', '::1'], $ek)));
    }
}
