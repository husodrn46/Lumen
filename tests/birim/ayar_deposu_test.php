<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/ayar_deposu.php';

$GLOBALS['__ayar_test_dizin'] = sys_get_temp_dir() . '/lumen-ayar-' . bin2hex(random_bytes(4));
putenv('LUMEN_AYAR_DOSYASI=' . $GLOBALS['__ayar_test_dizin'] . '/ayarlar.json');

test('ayar: türler doğrulanır, kod enjeksiyonu metin olarak kalır', function (): void {
    esit('1', lumen_ayar_dogrula('bool', '1'));
    esit('0', lumen_ayar_dogrula('bool', 'evet'));
    esit('hayir', lumen_ayar_dogrula('boolyn', '0'));
    esit('999999', lumen_ayar_dogrula('int', '12345678'));
    esit('system(_GET0)', lumen_ayar_dogrula('text', '{${system($_GET[0])}}'));
    esit('\\\\SUNUCU\\HP', lumen_ayar_dogrula('yazici', '\\\\SUNUCU\\HP'));
    esit(null, lumen_ayar_dogrula('text', ['dizi']));
});

test('ayar: iskonto listesi süzülür', function (): void {
    $l = lumen_ayar_dogrula('iskonto_listesi', [['ad' => "Bayi'; x", 'oran' => '10'], ['ad' => 'Sıfır', 'oran' => 0], 'bozuk']);
    esit([['ad' => 'Bayi x', 'oran' => 10.0]], $l);
});

test('ayar: kaydet → oku; şema dışı anahtar yazılmaz', function (): void {
    dogru(lumen_ayar_kaydet(['sipno' => 'SIP-', 'adetkusurat' => '3', 'zararli' => 'x'], 1));
    $a = lumen_ayar_oku();
    esit('SIP-', $a['sipno']);
    esit('3', $a['adetkusurat']);
    dogru(!array_key_exists('zararli', $a));
});

test('ayar: dosya elle bozulsa da şema dışı değer uygulanmaz', function (): void {
    $yol = lumen_ayar_dosyasi();
    file_put_contents($yol, json_encode(['ayarlar' => ['dbh' => 'x', 'sipno' => ['dizi'], 'depo' => '7']]));
    esit(['depo' => '7'], lumen_ayar_oku());
    file_put_contents($yol, '{bozuk');
    esit([], lumen_ayar_oku());
    foreach (array_merge(glob($GLOBALS['__ayar_test_dizin'] . '/*') ?: [], glob($GLOBALS['__ayar_test_dizin'] . '/.ayarlar.lock') ?: []) as $f) {
        unlink($f);
    }
    @rmdir($GLOBALS['__ayar_test_dizin']);
});
