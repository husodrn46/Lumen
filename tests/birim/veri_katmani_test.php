<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/veri/veri.php';

/** Testi verilen modda çalıştırır, ortam değişkenini geri yükler. */
function veri_modunda(string $mod, callable $fn): void
{
    $eski = getenv('LUMEN_VERI_KAYNAGI');
    putenv('LUMEN_VERI_KAYNAGI=' . $mod);
    try {
        $fn();
    } finally {
        putenv($eski === false ? 'LUMEN_VERI_KAYNAGI' : 'LUMEN_VERI_KAYNAGI=' . $eski);
    }
}

test('veri kaynağı: boş = logo, lumen = bağımsız, yanlış yazım reddedilir', function (): void {
    veri_modunda('', fn() => esit(false, lumen_bagimsiz_mi()));
    veri_modunda('LUMEN', fn() => esit(true, lumen_bagimsiz_mi()));
    veri_modunda('lumne', function (): void {
        try {
            lumen_veri_kaynagi();
            dogru(false, 'yanlış mod adı sessizce kabul edildi');
        } catch (RuntimeException) {
        }
    });
});

test('modül eşleme: en uzun önek kazanır, kayıtsız sayfa bağımsız modda kapalı', function (): void {
    esit('cari', lumen_yolun_modulu('cari/cari.php'));
    esit('cekirdek', lumen_yolun_modulu('/index.php'));
    esit('cekirdek', lumen_yolun_modulu('ayar/gorunum.php'));      // 'ayar/' önekinden uzun tam eşleşme
    esit('yonetim', lumen_yolun_modulu('ayar/kullanici_yetki.php'));
    esit('cari', lumen_yolun_modulu('ayar/ozel_cari_kisitlari.php'));
    esit(null, lumen_yolun_modulu('bilinmeyen.php'));
    veri_modunda('logo', fn() => esit(true, lumen_yol_hazir_mi('bilinmeyen.php')));
    veri_modunda('lumen', function (): void {
        esit(false, lumen_yol_hazir_mi('bilinmeyen.php'));
        esit(true, lumen_yol_hazir_mi('index.php'));
    });
});

test('ortak tablolar: aynı kolon adları; LOGO öneki doğrulanır', function (): void {
    veri_modunda('lumen', fn() => dogru(str_contains(lumen_tablo('personel'), 'LM_PERSONEL')));
    veri_modunda('logo', function (): void {
        $GLOBALS['firma'] = 'LG_001_';
        $GLOBALS['firmadonemx'] = 'LV_001_02_';
        $sql = lumen_tablo('cari');
        dogru(str_contains($sql, 'LG_001_CLCARD') && str_contains($sql, 'AS UNVAN'), 'cari ifadesi beklenen değil');
        $GLOBALS['firma'] = "LG_001_; DROP TABLE x --";
        try {
            lumen_tablo('cari');
            dogru(false, 'geçersiz önek SQL\'e girdi');
        } catch (RuntimeException) {
        } finally {
            $GLOBALS['firma'] = 'LG_001_';
        }
    });
});
