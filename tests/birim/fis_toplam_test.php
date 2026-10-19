<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/fis_toplam.php';

test('fiş toplamı: NETTOTAL KDV dahil — KDV iki kez eklenmez', function (): void {
    // 1.000 TL brüt, 100 TL iskonto, %20 KDV → 900 + 180 = 1.080
    $t = lumen_fis_toplamlari(['GROSSTOTAL' => '1000', 'TOTALDISCOUNTS' => '100', 'TOTALVAT' => '180', 'NETTOTAL' => '1080']);
    esit(900.0, $t['net_kdv_haric']);
    esit(180.0, $t['kdv']);
    esit(1080.0, $t['genel']);
});

test('fiş toplamı: eksik alanlar sıfır sayılır', function (): void {
    esit(['brut' => 0.0, 'iskonto' => 0.0, 'net_kdv_haric' => 0.0, 'kdv' => 0.0, 'genel' => 0.0], lumen_fis_toplamlari([]));
});

test('fiş toplamı: iskonto brütü aşarsa NETTOTAL - KDV kullanılır', function (): void {
    $t = lumen_fis_toplamlari(['GROSSTOTAL' => 0, 'TOTALDISCOUNTS' => 50, 'TOTALVAT' => 20, 'NETTOTAL' => 120]);
    esit(100.0, $t['net_kdv_haric']);
    esit(120.0, $t['genel']);
});
