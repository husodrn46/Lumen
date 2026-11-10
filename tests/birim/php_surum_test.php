<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/guvenlik/php_surum.php';

test('PHP güvenlik denetimi: yamasız sürüm uyarılır, yamalı ve yeni sürüm temiz', function (): void {
    dogru(count(lumen_php_guvenlik_sorunlari('8.2.34', true)) === 2, '8.2.34 + upload_progress açık');
    dogru(count(lumen_php_guvenlik_sorunlari('8.2.34', false)) === 1, '8.2.34, upload_progress kapalı');
    dogru(str_contains(lumen_php_guvenlik_sorunlari('8.2.34', false)[0], 'CVE-2026-109618'), '8.2.34 açığı da bildirilir');
    dogru(count(lumen_php_guvenlik_sorunlari('8.3.35', true)) === 2, '8.3.35 + upload_progress açık');
    esit([], lumen_php_guvenlik_sorunlari('8.3.36', true));
    dogru(lumen_php_guvenlik_sorunlari('8.4.26', false) !== [], '8.4.26');
    esit([], lumen_php_guvenlik_sorunlari('8.5.12', false));
    dogru(str_contains(lumen_php_guvenlik_sorunlari('8.1.30', false)[0], 'desteklenmiyor'), '8.1');
    esit([], lumen_php_guvenlik_sorunlari('8.6.0', true));
});

test('PHP taban sürümü: 8.2 yamalı olsa da desteklenmez, geçici önlem önerilmez', function (): void {
    $sorunlar = lumen_php_guvenlik_sorunlari('8.2.35', true);
    dogru(count($sorunlar) === 1, '8.2.35 tek uyarı almalı');
    dogru(str_contains($sorunlar[0], 'desteklenmiyor') && str_contains($sorunlar[0], '8.3'), '8.2.35 destek dışı');
    dogru(!str_contains($sorunlar[0], 'CVE'), 'yamalı 8.2 açık olarak bildirilmez');
});
