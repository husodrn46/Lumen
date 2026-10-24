<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/logo_zaman.php';

test('LOGO saat kodu: saat, dakika ve saniye çözülür', function (): void {
    $kod = 14 * 16777216 + 37 * 65536 + 52 * 256;
    esit(['saat' => 14, 'dakika' => 37, 'saniye' => 52], lumen_logo_saat_coz($kod));
    esit('24.10.2026 14:37', lumen_logo_tarih_saat_goster('2026-10-24 00:00:00', $kod));
});

test('LOGO saat kodu: gerçek gece yarısı korunur', function (): void {
    esit(['saat' => 0, 'dakika' => 0, 'saniye' => 0], lumen_logo_saat_coz(0));
    esit('24.10.2026 00:00', lumen_logo_tarih_saat_goster('2026-10-24', 0));
});

test('LOGO saat kodu: kaynak yoksa sahte 00:00 gösterilmez', function (): void {
    esit('24.10.2026 --:--', lumen_logo_tarih_saat_goster('2026-10-24', null));
    esit(null, lumen_logo_saat_coz('bozuk'));
    esit('-', lumen_logo_tarih_saat_goster('geçersiz', null));
});
