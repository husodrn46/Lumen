<?php
declare(strict_types=1);

/** Kurulum yardımcıları ayr.php'yi veya bir üretim bağlantısını açmaz. */
function kurulum_onekler(int $firma, int $donem): array
{
    if ($firma < 1 || $firma > 999 || $donem < 1 || $donem > 99) {
        throw new InvalidArgumentException('Firma 1–999, dönem 1–99 arasında olmalı.');
    }
    $f = str_pad((string) $firma, 3, '0', STR_PAD_LEFT);
    $d = str_pad((string) $donem, 2, '0', STR_PAD_LEFT);
    $de = str_pad((string) max(1, $donem - 1), 2, '0', STR_PAD_LEFT);
    return [
        'FIRMA_PREFIX' => "LG_{$f}_", 'FIRMA_DONEM' => "LG_{$f}_{$d}_",
        'FIRMA_DONEM_VIEW' => "LV_{$f}_{$d}_",
        'FIRMA_ESKI_DONEM' => "LG_{$f}_{$de}_", 'FIRMA_ESKI_DONEM_VIEW' => "LV_{$f}_{$de}_",
    ];
}

/** Kontrol listesi yalnız ihtiyaç duyulan nesne/sütunları SELECT ile doğrular. */
function kurulum_sema_kontrol(PDO $pdo, array $onek, bool $lumenDahil = true): void
{
    // Önekleri yeniden üret: metadata'dan gelen serbest SQL tanımlayıcısı yok.
    if (!preg_match('/\ALG_([0-9]{3})_([0-9]{2})_\z/', (string) ($onek['FIRMA_DONEM'] ?? ''), $m)) {
        throw new InvalidArgumentException('Kurulum firma/dönem kapsamı geçersiz.');
    }
    if ($onek !== kurulum_onekler((int) $m[1], (int) $m[2])) {
        throw new InvalidArgumentException('Kurulum önekleri uyuşmuyor.');
    }
    $firma = $onek['FIRMA_PREFIX'];
    $donem = $onek['FIRMA_DONEM'];
    $view = $onek['FIRMA_DONEM_VIEW'];
    $nesneler = [
        'LG_SLSMAN' => ['LOGICALREF', 'CODE', 'FIRMNR', 'ACTIVE'],
        $firma . 'CLCARD' => ['LOGICALREF', 'CODE', 'ACTIVE'],
        $firma . 'ITEMS' => ['LOGICALREF', 'UNITSETREF', 'VAT', 'ACTIVE'],
        $firma . 'ITMUNITA' => ['ITEMREF', 'UNITLINEREF', 'CONVFACT1', 'CONVFACT2'],
        $firma . 'UNITSETL' => ['LOGICALREF', 'MAINUNIT', 'CODE'],
        $donem . 'ORFICHE' => ['LOGICALREF', 'FICHENO', 'CLIENTREF', 'TRCODE', 'CANCELLED'],
        $donem . 'ORFLINE' => ['LOGICALREF', 'ORDFICHEREF', 'STOCKREF', 'LINETYPE', 'AMOUNT'],
        $view . 'STINVTOT' => ['STOCKREF', 'INVENNO', 'ONHAND'],
    ];
    if ($lumenDahil) {
        $nesneler += [
            'M_P_YETKI' => ['PERSONEL', 'SIFRE', 'YETKI', 'M1', 'M3', 'M10'],
            'M_GIRIS_LOG' => ['KULLANICI_ID', 'KULLANICI_ADI', 'ISLEM_TIPI', 'BASARILI', 'IP_ADRESI', 'TARIH'],
            'M_OTURUM_KAPAT' => ['KULLANICI_ID', 'KAPATMA_TS'],
            'M_API_IDEMPOTENCY' => ['KEYHASH', 'FIRMA', 'DONEM', 'PERSONEL', 'KAPSAM', 'BODYHASH', 'YANIT', 'OLUSTURMA'],
        ];
    }
    foreach ($nesneler as $tablo => $sutunlar) {
        $pdo->query('SELECT TOP 0 [' . implode('], [', $sutunlar) . '] FROM [dbo].[' . $tablo . ']')->closeCursor();
    }
}

/**
 * Önce izin tablosu, sonra ona bağlı betikler. Herhangi bir hatada devam edilmez.
 * Manuel/onarıcı betikler de kontrol edilir; başarısızsa kurulum tamamlanamaz.
 */
function kurulum_sema_uygula(PDO $pdo, string $dir, array $onek): array
{
    kurulum_sema_kontrol($pdo, $onek, false);
    $dosyalar = glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    sort($dosyalar, SORT_NATURAL | SORT_FLAG_CASE);
    $yetki = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'm_p_yetki.sql';
    if (!in_array($yetki, $dosyalar, true)) {
        throw new RuntimeException('Zorunlu yetki şeması bulunamadı.');
    }
    $dosyalar = array_merge([$yetki], array_values(array_diff($dosyalar, [$yetki])));
    $basarili = 0;
    $hatalar = [];
    foreach ($dosyalar as $yol) {
        try {
            $icerik = file_get_contents($yol);
            if ($icerik === false) {
                throw new RuntimeException('Şema betiği okunamadı.');
            }
            if (basename($yol) === 'm_fis_hareketleri_nettutar.sql') {
                // Bu legacy view seçilen firma/dönemden üretilir, LG_001'e sabitlenmez.
                $icerik = str_replace(['LV_001_02_', 'LG_001_02_', 'LG_001_'],
                    [$onek['FIRMA_DONEM_VIEW'], $onek['FIRMA_DONEM'], $onek['FIRMA_PREFIX']], $icerik);
                $q = $pdo->query("SELECT OBJECT_ID('dbo.M_FIS_HAREKETLERI', 'V')");
                $var = $q->fetchColumn();
                $q->closeCursor();
                if (!$var) {
                    $icerik = str_replace('ALTER VIEW [dbo].[M_FIS_HAREKETLERI]', 'CREATE VIEW [dbo].[M_FIS_HAREKETLERI]', $icerik);
                }
            }
            foreach (preg_split('/^\s*GO\s*;?\s*$/mi', $icerik) ?: [] as $batch) {
                if (trim($batch) !== '') {
                    $pdo->exec(trim($batch));
                }
            }
            $basarili++;
        } catch (Throwable $e) {
            error_log('Kurulum şema hatası (' . basename($yol) . '): ' . $e->getMessage());
            $hatalar[basename($yol)] = 'Şema uygulanamadı; sunucu kaydını inceleyin.';
            break;
        }
    }
    if ($hatalar === []) {
        kurulum_sema_kontrol($pdo, $onek);
    }
    return ['basarili' => $basarili, 'toplam' => count($dosyalar), 'hatalar' => $hatalar];
}
