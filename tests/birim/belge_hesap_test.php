<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/belge/belge_lib.php';

test('belge satırı: tutar, iskonto, net, KDV (2 hane, net üzerinden KDV)', function (): void {
    esit(['tutar' => 510.0, 'iskonto' => 51.0, 'net' => 459.0, 'kdv' => 91.8], lumen_belge_satir_hesapla(12, 42.5, 10, 20));
    esit(['tutar' => 33.33, 'iskonto' => 0.0, 'net' => 33.33, 'kdv' => 0.33], lumen_belge_satir_hesapla(3, 11.11, 0, 1));
    esit(['tutar' => 0.0, 'iskonto' => 0.0, 'net' => 0.0, 'kdv' => 0.0], lumen_belge_satir_hesapla(5, 0, 50, 20));
});

test('belge türleri: TUR/YON eşlemesi tek anlamlı, stok/cari yönleri tutarlı', function (): void {
    foreach (lumen_belge_turleri() as $kod => $t) {
        esit($kod, lumen_belge_tur_kodu($t['tur'], $t['yon']) === $kod || $t['tur'] === 6 ? $kod : 'çakışma', $kod);
        dogru(in_array($t['stok'], [-1, 0, 1], true) && in_array($t['cari'], [-1, 0, 1], true), $kod);
    }
    esit(-1, lumen_belge_turleri()['satis_fatura']['stok']);
    esit(1, lumen_belge_turleri()['satis_fatura']['cari']);
    esit(1, lumen_belge_turleri()['satis_iade']['stok']);
});

test('form tutarı: TR ve EN biçimleri, binlik gruplu tamsayı, geçersiz girdi', function (): void {
    esit(1234.56, lumen_tutar_coz('1.234,56'));
    esit(1234.56, lumen_tutar_coz('1234.56'));
    esit(1234.0, lumen_tutar_coz('1.234'));
    esit(12.5, lumen_tutar_coz('12,5'));
    esit(0.0, lumen_tutar_coz('abc'));
    esit(7.25, lumen_tutar_coz(7.25));
});
