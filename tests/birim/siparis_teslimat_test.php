<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/siparis_teslimat.php';

test('sipariş teslimatı: hiç teslim edilmemiş satırda miktarın tamamı kalır', function (): void {
    esit(
        ['toplam' => 10.0, 'teslim_edilen' => 0.0, 'kalan' => 10.0, 'durum' => 'bekliyor'],
        lumen_siparis_teslimat_miktarlari(10, 0)
    );
});

test('sipariş teslimatı: kısmi teslim edilen miktar toplamdan düşülür', function (): void {
    esit(
        ['toplam' => 10.0, 'teslim_edilen' => 4.25, 'kalan' => 5.75, 'durum' => 'kismi'],
        lumen_siparis_teslimat_miktarlari('10', '4.25')
    );
});

test('sipariş teslimatı: tam teslimde kalan sıfırdır', function (): void {
    esit(
        ['toplam' => 10.0, 'teslim_edilen' => 10.0, 'kalan' => 0.0, 'durum' => 'tamamlandi'],
        lumen_siparis_teslimat_miktarlari(10, 10)
    );
});

test('sipariş teslimatı: hatalı negatif değerler ekranda negatif miktar üretmez', function (): void {
    esit(
        ['toplam' => 0.0, 'teslim_edilen' => 2.0, 'kalan' => 0.0, 'durum' => 'tamamlandi'],
        lumen_siparis_teslimat_miktarlari(-3, 2)
    );
    esit(0.0, lumen_siparis_teslimat_miktarlari(3, -2)['teslim_edilen']);
});
