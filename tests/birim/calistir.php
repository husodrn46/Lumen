<?php

declare(strict_types=1);

/**
 * Lumen birim testleri — veritabanı gerektirmeyen saf fonksiyonlar için.
 * Ek bağımlılık yoktur:  php tests/birim/calistir.php
 *
 * Her *_test.php dosyası test('ad', fn() => ...) çağrılarıyla test tanımlar;
 * esit() / dogru() başarısızlıkta istisna fırlatır.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$GLOBALS['__testler'] = [];

function test(string $ad, callable $fn): void
{
    $GLOBALS['__testler'][] = [$ad, $fn];
}

function esit(mixed $beklenen, mixed $gercek, string $mesaj = ''): void
{
    if ($beklenen !== $gercek) {
        throw new RuntimeException(trim($mesaj . ' beklenen ' . var_export($beklenen, true) . ', gelen ' . var_export($gercek, true)));
    }
}

function dogru(bool $kosul, string $mesaj = 'koşul sağlanmadı'): void
{
    if (!$kosul) {
        throw new RuntimeException($mesaj);
    }
}

foreach (glob(__DIR__ . '/*_test.php') ?: [] as $dosya) {
    require $dosya;
}

$basarisiz = 0;
foreach ($GLOBALS['__testler'] as [$ad, $fn]) {
    try {
        $fn();
        echo "  ✓ {$ad}\n";
    } catch (Throwable $e) {
        $basarisiz++;
        echo "  ✗ {$ad}\n      " . $e->getMessage() . "\n";
    }
}
printf("%d test, %d başarısız\n", count($GLOBALS['__testler']), $basarisiz);
exit($basarisiz === 0 ? 0 : 1);
