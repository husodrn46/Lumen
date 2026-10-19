<?php

declare(strict_types=1);

/**
 * ayar_deposu.php — ekrandan değiştirilen sistem ayarlarının saklandığı yer.
 *
 * Eskiden Ayarlar ekranı değerleri _bilgi_.inc (PHP kaynak dosyası) içine PHP
 * literali olarak yazıyordu. Süzgeçteki her eksik kod çalıştırmaya, her hatalı
 * yazım sitenin tamamen çökmesine yol açabiliyordu. Artık:
 *
 *   - _bilgi_.inc yalnız VARSAYILANLARI taşır ve uygulama tarafından yazılmaz.
 *   - Ekrandan kaydedilen değerler database/ayarlar.json'a VERİ olarak yazılır
 *     (web sunucusu kurallarıyla dışarıya kapalı, git'e girmez).
 *   - Yalnız şemadaki anahtarlar kabul edilir; değerler türüne göre doğrulanır
 *     (okurken de: dosya elle bozulsa bile şema dışı değer uygulanmaz).
 *   - Yazım kilitli, geçici dosya + yerine taşıma ile; her kayıt denetim loguna.
 *
 * _bilgi_.inc sonunda lumen_ayar_uygula() çağrılır ve kayıtlı değerler
 * varsayılanların üzerine yazılır.
 */

if (!function_exists('lumen_ayar_semasi')) {
    /**
     * Ekrandan değiştirilebilen ayarlar ve türleri.
     *   bool   → '0' / '1'          boolyn → 'evet' / 'hayir'
     *   int    → 0..999999          text   → harf/rakam/boşluk ve . _ - , / : @ # ( )
     *   yazici → text + ters bölü (\\sunucu\yazici)
     *   iskonto_listesi → [['ad' => string, 'oran' => float], ...]
     *
     * @return array<string,string>
     */
    function lumen_ayar_semasi(): array
    {
        return [
            'fisyazici' => 'yazici', 'fisyazici2' => 'yazici', 'fisyazici3' => 'yazici', 'fisyazici4' => 'yazici',
            'barkodyazici' => 'yazici', 'barkodyazici2' => 'yazici',
            'adetkusurat' => 'int', 'parakusurat' => 'int',
            'dovizlicalis' => 'bool', 'dovizfirma' => 'int', 'doviztipleri' => 'text', 'tlkodu' => 'int',
            'kullanicigirisli' => 'bool', 'benihatirla' => 'bool',
            'bakiyegorunsun' => 'bool', 'extregorunsun' => 'bool', 'carilistesayisi' => 'int',
            'yenicariac' => 'bool', 'carikoduontaki' => 'text', 'hizlicari' => 'int', 'iletisim' => 'bool',
            'stoklistesayisi' => 'int', 'stoksonalisfiyati' => 'bool', 'stoksonsatisfiyati' => 'bool',
            'stokekle' => 'boolyn', 'yenistokac' => 'bool', 'stokkoduontaki' => 'text',
            'resimlistoklistesi' => 'bool', 'coklustokgiris' => 'boolyn',
            'sonsiparis_aktif' => 'bool', 'favoriler_aktif' => 'bool',
            'iskontolu' => 'int', 'iskontolu2' => 'int', 'iskontolu3' => 'int', 'topluiskonto' => 'bool', 'iskontoartir' => 'bool',
            'sipno' => 'text', 'siparistefiyatduzenle' => 'bool', 'yenialissiparisi' => 'bool', 'reserve' => 'bool',
            'exceleaktar' => 'bool', 'koliyazdir' => 'bool', 'resimliyazdir' => 'bool', 'barkodyazdir' => 'bool',
            'depo' => 'int', 'cokludepo' => 'bool',
            'fiyatgruplu' => 'bool', 'odemekodu' => 'text', 'tanimlialantoplam' => 'bool', 'tanimlialan' => 'bool',
            'yenifiyatduzenle' => 'bool', 'toplukdv' => 'bool', 'eksik' => 'bool', 'maliyetkontrol' => 'bool',
            'cokluekledeayniurun' => 'bool', 'dil' => 'int',
            'hizli_iskontolar' => 'iskonto_listesi',
        ];
    }
}

if (!function_exists('lumen_ayar_dogrula')) {
    /**
     * Değeri şemadaki türe göre doğrular/normalleştirir. Geçersizse null.
     */
    function lumen_ayar_dogrula(string $tur, mixed $deger): mixed
    {
        switch ($tur) {
            case 'bool':
                return ($deger === true || $deger === 1 || $deger === '1') ? '1' : '0';
            case 'boolyn':
                return ($deger === true || $deger === 'evet' || $deger === '1' || $deger === 1) ? 'evet' : 'hayir';
            case 'int':
                if (!is_scalar($deger)) {
                    return null;
                }
                $n = (int) preg_replace('/[^0-9-]/', '', (string) $deger);
                return (string) max(0, min(999999, $n));
            case 'text':
            case 'yazici':
                if (!is_scalar($deger)) {
                    return null;
                }
                $desen = $tur === 'yazici' ? '/[^\p{L}\p{N} ._\-,\/:@#()\\\\]/u' : '/[^\p{L}\p{N} ._\-,\/:@#()]/u';
                $s = preg_replace($desen, '', (string) $deger) ?? '';
                return mb_substr(trim($s), 0, $tur === 'yazici' ? 80 : 60);
            case 'iskonto_listesi':
                if (!is_array($deger)) {
                    return null;
                }
                $liste = [];
                foreach ($deger as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $ad = mb_substr(trim(preg_replace('/[^\p{L}\p{N} .\-\/]/u', '', (string) ($r['ad'] ?? '')) ?? ''), 0, 24);
                    $oran = is_numeric($r['oran'] ?? null) ? (float) $r['oran'] : 0.0;
                    if ($ad !== '' && $oran > 0 && $oran <= 100) {
                        $liste[] = ['ad' => $ad, 'oran' => round($oran, 2)];
                    }
                }
                return array_slice($liste, 0, 20);
        }
        return null;
    }
}

if (!function_exists('lumen_ayar_dosyasi')) {
    function lumen_ayar_dosyasi(): string
    {
        $test = (string) getenv('LUMEN_AYAR_DOSYASI'); // yalnız testler için
        return $test !== '' ? $test : dirname(__DIR__) . '/database/ayarlar.json';
    }
}

if (!function_exists('lumen_ayar_oku')) {
    /** Kayıtlı (doğrulanmış) ayarlar. Dosya yoksa/bozuksa boş dizi. */
    function lumen_ayar_oku(): array
    {
        $yol = lumen_ayar_dosyasi();
        if (!is_file($yol)) {
            return [];
        }
        $veri = json_decode((string) @file_get_contents($yol), true);
        if (!is_array($veri) || !is_array($veri['ayarlar'] ?? null)) {
            return [];
        }
        $sema = lumen_ayar_semasi();
        $sonuc = [];
        foreach ($veri['ayarlar'] as $anahtar => $deger) {
            if (!is_string($anahtar) || !isset($sema[$anahtar])) {
                continue;
            }
            $temiz = lumen_ayar_dogrula($sema[$anahtar], $deger);
            if ($temiz !== null) {
                $sonuc[$anahtar] = $temiz;
            }
        }
        return $sonuc;
    }
}

if (!function_exists('lumen_ayar_kaydet')) {
    /**
     * Değişiklikleri kayıtlı ayarlarla birleştirip yazar.
     *
     * @param array<string,mixed> $degisiklikler anahtar → ham değer (doğrulanır)
     * @return bool başarılı mı
     */
    function lumen_ayar_kaydet(array $degisiklikler, int $kullaniciId): bool
    {
        $yol = lumen_ayar_dosyasi();
        $dizin = dirname($yol);
        if (!is_dir($dizin) && !mkdir($dizin, 0750, true) && !is_dir($dizin)) {
            return false;
        }
        $kilit = fopen($dizin . '/.ayarlar.lock', 'c');
        if ($kilit === false || !flock($kilit, LOCK_EX)) {
            return false;
        }
        try {
            $sema = lumen_ayar_semasi();
            $mevcut = lumen_ayar_oku();
            $degisen = [];
            foreach ($degisiklikler as $anahtar => $ham) {
                if (!is_string($anahtar) || !isset($sema[$anahtar])) {
                    continue;
                }
                $temiz = lumen_ayar_dogrula($sema[$anahtar], $ham);
                if ($temiz === null) {
                    continue;
                }
                if (!array_key_exists($anahtar, $mevcut) || $mevcut[$anahtar] !== $temiz) {
                    $degisen[$anahtar] = $temiz;
                }
                $mevcut[$anahtar] = $temiz;
            }
            $icerik = json_encode([
                'surum' => 1,
                'guncelleme' => date('c'),
                'guncelleyen' => $kullaniciId,
                'ayarlar' => $mevcut,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $gecici = $yol . '.yeni-' . bin2hex(random_bytes(4));
            if ($icerik === false || file_put_contents($gecici, $icerik) === false || !rename($gecici, $yol)) {
                @unlink($gecici);
                return false;
            }
            if ($degisen !== [] && isset($GLOBALS['dbh']) && $GLOBALS['dbh'] instanceof PDO) {
                require_once __DIR__ . '/bildirim.php';
                lumen_bildirim_yoneticilere($GLOBALS['dbh'], 'ayar', 'Sistem ayarları değiştirildi',
                    'Değişen: ' . implode(', ', array_keys($degisen)) . ' (kullanıcı #' . $kullaniciId . ')',
                    'ayar/sistem_ayarlari.php', null, $kullaniciId);
            }
            if ($degisen !== [] && function_exists('app_log_write')) {
                app_log_write('ayar-degisiklik', [
                    'kullanici' => $kullaniciId,
                    'anahtarlar' => array_keys($degisen),
                    'degerler' => $degisen,
                ]);
            }
            return true;
        } finally {
            flock($kilit, LOCK_UN);
            fclose($kilit);
        }
    }
}

if (!function_exists('lumen_ayar_uygula')) {
    /** Kayıtlı ayarları global değişkenlere uygular (_bilgi_.inc sonunda çağrılır). */
    function lumen_ayar_uygula(): void
    {
        foreach (lumen_ayar_oku() as $anahtar => $deger) {
            $GLOBALS[$anahtar] = $deger;
        }
        if (!defined('LUMEN_AYAR_UYGULANDI')) {
            define('LUMEN_AYAR_UYGULANDI', true);
        }
    }
}
