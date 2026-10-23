<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/ayar/yetki_denetim_helper.php';

test('yetki denetimi: yalnızca literal loopback hedefleri kabul edilir', function (): void {
    dogru(denetimBaseUrlGuvenliMi('http://127.0.0.1'));
    dogru(denetimBaseUrlGuvenliMi('http://127.0.0.1:8088'));
    dogru(denetimBaseUrlGuvenliMi('https://[::1]:8443/lumen'));

    foreach ([
        'https://example.com',
        'http://localhost',
        'http://127.0.0.1@example.com',
        'http://127.0.0.1?hedef=dis',
        'http://127.0.0.1#parca',
        'ftp://127.0.0.1',
        'http://127.0.0.1:99999',
    ] as $url) {
        dogru(!denetimBaseUrlGuvenliMi($url), 'reddedilmesi gereken hedef: ' . $url);
    }
});

test('yetki denetimi: istek Host başlığı probe hedefini belirleyemez', function (): void {
    $oncekiEnv = getenv('LUMEN_DENETIM_BASE_URL');
    $oncekiHost = $_SERVER['HTTP_HOST'] ?? null;

    try {
        putenv('LUMEN_DENETIM_BASE_URL');
        $_SERVER['HTTP_HOST'] = 'saldirgan.example';
        esit('http://127.0.0.1', denetimBaseUrl());

        putenv('LUMEN_DENETIM_BASE_URL=http://127.0.0.1:8088');
        esit('http://127.0.0.1:8088', denetimBaseUrl());
    } finally {
        $oncekiEnv === false
            ? putenv('LUMEN_DENETIM_BASE_URL')
            : putenv('LUMEN_DENETIM_BASE_URL=' . $oncekiEnv);
        if ($oncekiHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $oncekiHost;
        }
    }
});

test('yetki denetimi: güvenli olmayan ortam hedefinde kapalı kalır', function (): void {
    $oncekiEnv = getenv('LUMEN_DENETIM_BASE_URL');
    try {
        putenv('LUMEN_DENETIM_BASE_URL=https://saldirgan.example');
        $reddedildi = false;
        try {
            denetimBaseUrl();
        } catch (RuntimeException) {
            $reddedildi = true;
        }
        dogru($reddedildi);
    } finally {
        $oncekiEnv === false
            ? putenv('LUMEN_DENETIM_BASE_URL')
            : putenv('LUMEN_DENETIM_BASE_URL=' . $oncekiEnv);
    }
});
