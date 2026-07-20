<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app_observability.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Yazilabilir bir cache dosya yolu bulur.
 * Once tmp/ dizinini dener, yoksa sys_get_temp_dir() fallback.
 * Okuma icin de ayni siralamayla bakilir.
 */
function hava_cache_path(): string
{
    static $cached = null;
    if ($cached !== null) return $cached;

    $primary = __DIR__ . DIRECTORY_SEPARATOR . 'tmp';
    // Dizini olusturmayi sessizce dene
    if (!is_dir($primary)) {
        @mkdir($primary, 0755, true);
    }
    if (is_dir($primary) && is_writable($primary)) {
        $cached = $primary . DIRECTORY_SEPARATOR . 'hava_cache.json';
        return $cached;
    }

    // Fallback: sys temp
    $fallback = sys_get_temp_dir();
    if (is_dir($fallback) && is_writable($fallback)) {
        $cached = $fallback . DIRECTORY_SEPARATOR . 'akl_hava_cache.json';
        return $cached;
    }

    // Hicbiri yazilamiyor, bos string ile sinyal ver
    $cached = '';
    return $cached;
}

$cache_dosya = hava_cache_path();
$cache_sure = 7200; // 2 saat (hava durumu sik degismez; gereksiz wttr.in cagrilarini azaltir)

// Cache hala gecerliyse API'ye gitme (okuma basarisizsa da sorun degil, API'ye gider)
if ($cache_dosya !== '' && is_file($cache_dosya) && (time() - filemtime($cache_dosya)) < $cache_sure) {
    $raw = @file_get_contents($cache_dosya);
    if (is_string($raw)) {
        $veri = json_decode($raw, true);
        if (is_array($veri) && isset($veri['kod'])) {
            echo json_encode(['ok' => true, 'kod' => $veri['kod']]);
            exit;
        }
    }
}

// Cache yok veya suresi dolmus, API'ye git
$sehir = 'Istanbul';
$ctx = stream_context_create(['http' => ['timeout' => 3, 'header' => "User-Agent: akl-dashboard/1.0\r\n"]]);
$json = @file_get_contents('https://wttr.in/' . urlencode($sehir) . '?format=j1', false, $ctx);

if ($json === false) {
    echo json_encode(['ok' => false]);
    exit;
}

$parsed = json_decode($json, true);
if (!isset($parsed['current_condition'][0]['weatherCode'])) {
    echo json_encode(['ok' => false]);
    exit;
}

$kod = (string) $parsed['current_condition'][0]['weatherCode'];
$veri = ['kod' => $kod, 'zaman' => time()];

// Cache yazmayi sessizce dene — yazilamazsa bile cevabi dondurmeye devam et
if ($cache_dosya !== '') {
    @file_put_contents($cache_dosya, json_encode($veri), LOCK_EX);
}

echo json_encode(['ok' => true, 'kod' => $kod]);
