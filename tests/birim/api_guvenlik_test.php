<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/_api.inc';
require_once dirname(__DIR__, 2) . '/api/_idempotency.inc';

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

test('API idempotency anahtarı: yalnız UUIDv4 veya 64 hex kabul edilir', function (): void {
    $onceki = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    try {
        unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
        esit(null, siparis_idempotency_anahtari());

        $uuid = '2e15b293-a425-49ca-9057-e8ed09d780f0';
        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = strtoupper($uuid);
        esit($uuid, siparis_idempotency_anahtari());
        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = str_repeat('b', 64);
        esit(str_repeat('b', 64), siparis_idempotency_anahtari());

        foreach (['', 'kisa', ' ' . $uuid, $uuid . ',ikinci', '2e15b293-a425-19ca-9057-e8ed09d780f0'] as $anahtar) {
            $_SERVER['HTTP_IDEMPOTENCY_KEY'] = $anahtar;
            try {
                siparis_idempotency_anahtari();
                dogru(false, 'geçersiz idempotency anahtarı kabul edildi: ' . $anahtar);
            } catch (InvalidArgumentException) {
                // Beklenen.
            }
        }
    } finally {
        if ($onceki === null) {
            unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
        } else {
            $_SERVER['HTTP_IDEMPOTENCY_KEY'] = $onceki;
        }
    }
});

test('API idempotency parmak izi: nesne sırası önemsiz, değer ve liste sırası önemlidir', function (): void {
    $govde = ['cari_id' => 4, 'kalemler' => [['stok_id' => 2, 'miktar' => 12]]];
    esit(
        siparis_idempotency_parmakizi($govde),
        siparis_idempotency_parmakizi(['kalemler' => $govde['kalemler'], 'cari_id' => 4])
    );
    dogru(
        siparis_idempotency_parmakizi($govde)
            !== siparis_idempotency_parmakizi(['cari_id' => 4, 'kalemler' => [['stok_id' => 2, 'miktar' => 13]]])
    );
    dogru(siparis_idempotency_parmakizi(['n' => 1]) !== siparis_idempotency_parmakizi(['n' => '1']));
    dogru(siparis_idempotency_parmakizi(['n' => 1]) !== siparis_idempotency_parmakizi(['n' => 1.0]));
    dogru(siparis_idempotency_parmakizi(['liste' => [1, 2]]) !== siparis_idempotency_parmakizi(['liste' => [2, 1]]));
});
