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

test('tutar yazıyla: Türkçe fatura biçimi (bin, kuruş, döviz)', function (): void {
    require_once dirname(__DIR__, 2) . '/includes/belge/fatura_sablon.php';
    esit('İkibindörtyüzaltmışaltı Türk Lirası', lumen_tutar_yaziyla(2466));
    esit('Bin Türk Lirası Elli Kuruş', lumen_tutar_yaziyla(1000.5));
    esit('Yüzbir Türk Lirası', lumen_tutar_yaziyla(101));
    esit('Birmilyonbinbir Türk Lirası', lumen_tutar_yaziyla(1001001));
    esit('Sıfır Türk Lirası Bir Kuruş', lumen_tutar_yaziyla(0.01));
    esit('Altmış ABD Doları', lumen_tutar_yaziyla(60, 'USD'));
    esit('Onbin Türk Lirası', lumen_tutar_yaziyla(9999.999));
});

test('IBAN denetimi ve TCMB kur XML çözümleme', function (): void {
    require_once dirname(__DIR__, 2) . '/includes/tanim/tanim_lib.php';
    dogru(lumen_iban_gecerli('TR69 0006 1000 0000 1234 5678 90'), 'geçerli IBAN');
    dogru(!lumen_iban_gecerli('TR70 0006 1000 0000 1234 5678 90'), 'kontrol hanesi yanlış');
    dogru(!lumen_iban_gecerli('DE89370400440532013000'), 'TR dışı');
    $xml = '<?xml version="1.0" encoding="UTF-8"?><Tarih_Date Tarih="17.10.2026" Date="10/17/2026">'
        . '<Currency CurrencyCode="USD"><Unit>1</Unit><ForexBuying>41.1023</ForexBuying><ForexSelling>41.1764</ForexSelling></Currency>'
        . '<Currency CurrencyCode="JPY"><Unit>100</Unit><ForexBuying>27.50</ForexBuying><ForexSelling>27.70</ForexSelling></Currency>'
        . '<Currency CurrencyCode="XDR"><Unit>1</Unit><ForexBuying></ForexBuying><ForexSelling></ForexSelling></Currency></Tarih_Date>';
    $c = lumen_tcmb_kurlari_cozumle($xml);
    esit('2026-10-17', $c['tarih']);
    esit([41.1023, 41.1764], $c['kurlar']['USD']);
    esit(0.275, $c['kurlar']['JPY'][0]);
    dogru(!isset($c['kurlar']['XDR']), 'boş kur atlanır');
    esit(null, lumen_tcmb_kurlari_cozumle('bozuk'));
});
