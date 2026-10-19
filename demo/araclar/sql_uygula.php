<?php
declare(strict_types=1);

/**
 * Demo/test yardımcı aracı. Konteyner içinde çalışır:
 *
 *   php demo/araclar/sql_uygula.php <veritabanı> --sema       # sql/ → kurulum sihirbazının fonksiyonuyla
 *   php demo/araclar/sql_uygula.php <veritabanı> <dosya.sql>... # tek tek dosyalar ("GO"dan bölünür)
 *
 * Bağlantı bilgileri AKL_DB_* ortam değişkenlerinden okunur.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

[$betik, $db] = $argv + [null, null];
$dosyalar = array_slice($argv, 2);
if ($db === null || $dosyalar === []) {
    fwrite(STDERR, "kullanım: php sql_uygula.php <veritabanı> <dosya.sql>...\n");
    exit(2);
}

// Canlı veritabanı koruması: bu araç veritabanına yazar (demo verisini siler).
// Yalnız LUMEN_ ile başlayan (demo/test) veritabanlarında çalışır.
if (!preg_match('/^LUMEN_[A-Z0-9_]+$/', (string) $db)) {
    fwrite(STDERR, "REDDEDİLDİ: '{$db}' bir demo/test veritabanı değil (LUMEN_ ile başlamalı).\n");
    exit(3);
}

$dsn = sprintf('sqlsrv:server=%s;database=%s;TrustServerCertificate=1', getenv('AKL_DB_SERVER') ?: 'db', $db);
$pdo = new PDO($dsn, getenv('AKL_DB_USER') ?: 'sa', getenv('AKL_DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

require_once dirname(__DIR__, 2) . '/includes/kurulum_sema.php';

if ($dosyalar === ['--sema']) {
    $sonuc = kurulum_sema_uygula($pdo, dirname(__DIR__, 2) . '/sql');
    printf("    %d/%d betik uygulandı\n", $sonuc['basarili'], $sonuc['toplam']);
    foreach ($sonuc['hatalar'] as $ad => $mesaj) {
        fwrite(STDERR, "HATA {$ad}: {$mesaj}\n");
    }
    exit($sonuc['hatalar'] === [] ? 0 : 1);
}

$hata = 0;
foreach ($dosyalar as $yol) {
    foreach (kurulum_sql_batchleri((string) file_get_contents($yol)) as $no => $batch) {
        try {
            $pdo->exec($batch);
        } catch (Throwable $e) {
            fwrite(STDERR, sprintf("HATA %s (batch %d): %s\n", $yol, $no + 1, $e->getMessage()));
            $hata = 1;
            break;
        }
    }
}
exit($hata);
