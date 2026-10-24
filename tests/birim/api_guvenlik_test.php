<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/_api.inc';

test('API JSON gövdesi: yalnız nesne kabul edilir', function (): void {
    esit([], api_json_govde_coz(''));
    esit(['cari_id' => 4], api_json_govde_coz('{"cari_id":4}'));

    foreach (['[1,2]', 'true', '"metin"', '{bozuk'] as $govde) {
        try {
            api_json_govde_coz($govde);
            dogru(false, 'geçersiz JSON gövdesi kabul edildi: ' . $govde);
        } catch (InvalidArgumentException) {
            // Beklenen.
        }
    }
});

test('API tokenı: saklanan özet işaretlidir ve Bearer başlığı tam eşleşir', function (): void {
    $token = str_repeat('a', 64);
    esit('sha256:' . hash('sha256', $token), api_token_saklama_degeri($token));

    $onceki = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    try {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        esit($token, api_bearer_token());
        $_SERVER['HTTP_AUTHORIZATION'] = 'önek Bearer ' . $token;
        esit('', api_bearer_token());
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token . ' son-ek';
        esit('', api_bearer_token());
    } finally {
        if ($onceki === null) {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        } else {
            $_SERVER['HTTP_AUTHORIZATION'] = $onceki;
        }
    }
});
