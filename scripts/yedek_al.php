<?php

declare(strict_types=1);

/**
 * Komut satırından yedek alır (zamanlanmış görev için). Uygulama içi yedeklemeyle aynı
 * biçim ve klasör (Ayarlar → Sistem ve Yedek); saklama sayısı da aynı.
 *
 *   php scripts/yedek_al.php
 *
 * Windows Görev Zamanlayıcı örneği (her gece 02:00):
 *   schtasks /Create /SC DAILY /ST 02:00 /TN "Lumen yedek" /TR "C:\php\php.exe C:\inetpub\lumen\scripts\yedek_al.php"
 * Linux cron:  0 2 * * *  php /var/www/lumen/scripts/yedek_al.php
 * Bunu kullanıyorsanız uygulamadaki "Her gün otomatik yedek al" seçeneğini kapatın.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? 'yedek_al.php';
require_once dirname(__DIR__) . '/ayr.php';
require_once dirname(__DIR__) . '/includes/yedek/yedek_lib.php';

$r = lumen_yedek_al($dbh, 'otomatik');
echo $r['mesaj'], $r['ok'] ? ' (' . lumen_yedek_klasoru() . '/' . $r['ad'] . ')' : '', "\n";
exit($r['ok'] ? 0 : 1);
