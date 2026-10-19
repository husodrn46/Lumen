<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/cek/cek_lib.php';

test('çek tutarı: panelden gelen JSON sayısı aynen kalır (100 kat hatası)', function (): void {
    $kalem = json_decode('{"tutar":1234.56}', true);
    esit(1234.56, cek_tutar_coz($kalem['tutar']));
    esit(1500.0, cek_tutar_coz(1500));
    esit(0.5, cek_tutar_coz(0.5));
});

test('çek tutarı: TR biçimli metin', function (): void {
    esit(1234.56, cek_tutar_coz('1.234,56'));
    esit(1234567.8, cek_tutar_coz('1.234.567,80'));
    esit(12.5, cek_tutar_coz('12,5'));
    esit(1234.0, cek_tutar_coz('1.234'));
});

test('çek tutarı: EN biçimli metin ve boşluklar', function (): void {
    esit(1234.56, cek_tutar_coz('1234.56'));
    esit(1234.56, cek_tutar_coz(' 1 234,56 '));
});

test('çek tutarı: geçersiz girdi sıfır olur', function (): void {
    esit(0.0, cek_tutar_coz('abc'));
    esit(0.0, cek_tutar_coz(null));
    esit(0.0, cek_tutar_coz(['1']));
    esit(0.0, cek_tutar_coz(true));
});
