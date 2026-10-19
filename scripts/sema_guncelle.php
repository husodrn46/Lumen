<?php

declare(strict_types=1);

/**
 * Veritabanı şemasını günceller: sql/ klasöründeki Lumen betiklerini (M_* tabloları,
 * yeni kolonlar) yapılandırılmış veritabanına uygular. Kurulum sihirbazıyla aynı
 * fonksiyonu kullanır (includes/kurulum_sema.php). Betikler idempotenttir; yeni bir
 * Lumen sürümüne geçtikten sonra bir kez çalıştırın, tekrar çalıştırmak güvenlidir.
 *
 *   php scripts/sema_guncelle.php
 *
 * LOGO tablolarına dokunmaz; yalnız Lumen'e özel tabloları oluşturur/genişletir.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? 'sema_guncelle.php';
require_once dirname(__DIR__) . '/ayr.php';
require_once dirname(__DIR__) . '/includes/kurulum_sema.php';

$sonuc = kurulum_sema_uygula($dbh, dirname(__DIR__) . '/sql');
printf("%d/%d betik uygulandı (%s).\n", $sonuc['basarili'], $sonuc['toplam'], (string) $veritabani);
foreach ($sonuc['hatalar'] as $ad => $mesaj) {
    fwrite(STDERR, "HATA {$ad}: {$mesaj}\n");
}
exit($sonuc['hatalar'] === [] ? 0 : 1);
