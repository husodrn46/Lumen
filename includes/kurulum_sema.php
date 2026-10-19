<?php

declare(strict_types=1);

/**
 * kurulum_sema.php — sql/ klasöründeki Lumen şema betiklerini uygular.
 *
 * Kurulum sihirbazı (kurulum.php) ve demo/test araçları aynı fonksiyonu kullanır;
 * böylece sihirbazın temiz bir veritabanında gerçekten çalıştığı test edilebilir.
 * Bağımlılık içermez (ayr.php'ye ihtiyaç duymaz).
 */

if (!function_exists('kurulum_sql_batchleri')) {
    /**
     * SQL Server "GO" toplu iş ayırıcısını PDO anlamadığından betiği batch'lere böler.
     *
     * @return string[]
     */
    function kurulum_sql_batchleri(string $icerik): array
    {
        $batchler = preg_split('/^[ \t]*GO[ \t]*;?[ \t]*$/mi', $icerik) ?: [];
        return array_values(array_filter(array_map('trim', $batchler), static fn(string $b): bool => $b !== ''));
    }
}

require_once __DIR__ . '/veri/kaynak.php';

if (!function_exists('kurulum_sema_dosyalari')) {
    /**
     * Moda göre uygulanacak şema betikleri.
     * Bağlı mod (LOGO): sql/*.sql.
     * Bağımsız mod: önce sql/lumen_cekirdek/*.sql (Lumen'in iş tabloları), sonra
     * sql/*.sql — ilk satırında "lumen:yalniz-logo" işareti olanlar hariç (LOGO
     * tablolarına başvuran view'lar vb.).
     *
     * @return string[]
     */
    function kurulum_sema_dosyalari(string $dir, bool $bagimsiz): array
    {
        $dir = rtrim($dir, '/\\');
        $ortak = glob($dir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($ortak, SORT_NATURAL | SORT_FLAG_CASE);
        if (!$bagimsiz) {
            return $ortak;
        }
        $cekirdek = glob($dir . DIRECTORY_SEPARATOR . 'lumen_cekirdek' . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($cekirdek, SORT_NATURAL | SORT_FLAG_CASE);
        $ortak = array_values(array_filter($ortak, static function (string $yol): bool {
            $ilk = strtok((string) file_get_contents($yol, false, null, 0, 300), "\n");
            return !str_contains((string) $ilk, 'lumen:yalniz-logo');
        }));
        return array_merge($cekirdek, $ortak);
    }
}

if (!function_exists('kurulum_sema_uygula')) {
    /**
     * sql/ klasöründeki tüm şema betiklerini uygular (Lumen'e özel M_* tabloları).
     *
     * Betikler idempotenttir (IF NOT EXISTS); tekrar çalıştırmak güvenlidir. Bazı
     * betikler diğerlerinin tablolarına bağlıdır (ör. m_gorev.sql → M_P_YETKI) ve
     * dosya adı sırası bu bağımlılığı garanti etmez; bu yüzden başarısız betikler,
     * ilerleme kalmayana dek sonraki turlarda yeniden denenir.
     *
     * @return array{basarili:int, toplam:int, hatalar:array<string,string>}
     */
    function kurulum_sema_uygula(PDO $pdo, string $dir, ?bool $bagimsiz = null): array
    {
        $dosyalar = kurulum_sema_dosyalari($dir, $bagimsiz ?? lumen_bagimsiz_mi());

        $bekleyen = $dosyalar;
        $hatalar  = [];
        do {
            $kalan = [];
            $hatalar = [];
            foreach ($bekleyen as $yol) {
                try {
                    foreach (kurulum_sql_batchleri((string) file_get_contents($yol)) as $batch) {
                        $pdo->exec($batch);
                    }
                } catch (Throwable $e) {
                    $kalan[] = $yol;
                    $hatalar[basename($yol)] = $e->getMessage();
                }
            }
            $ilerledi = count($kalan) < count($bekleyen);
            $bekleyen = $kalan;
        } while ($bekleyen !== [] && $ilerledi);

        return ['basarili' => count($dosyalar) - count($bekleyen), 'toplam' => count($dosyalar), 'hatalar' => $hatalar];
    }
}

if (!function_exists('lumen_sema_eksikleri')) {
    /**
     * Bu Lumen sürümünün beklediği ama veritabanında olmayan tablo/kolonlar.
     * Boş değilse yönetici scripts/sema_guncelle.php çalıştırmalıdır.
     *
     * @return string[]
     */
    function lumen_sema_eksikleri(PDO $dbh): array
    {
        $beklenen = [
            'M_P_YETKI' => null, 'M_GIRIS_LOG' => null, 'M_REMEMBER_TOKEN' => 'PREV_HASH', 'M_API_TOKEN' => null,
            'M_BILDIRIM' => null, 'M_USER_SETTINGS' => null, 'M_FIS_LOG' => null, 'M_CEK_EK' => 'FIRMADONEM',
            'M_OTURUM_KAPAT' => null, 'M_SATIR_DEGISIKLIK_LOG' => null,
        ];
        $parcalar = [];
        foreach ($beklenen as $tablo => $kolon) {
            $parcalar[] = $kolon === null
                ? "SELECT '{$tablo}' AS AD WHERE OBJECT_ID('dbo.{$tablo}', 'U') IS NULL"
                : "SELECT '{$tablo}.{$kolon}' AS AD WHERE COL_LENGTH('dbo.{$tablo}', '{$kolon}') IS NULL";
        }
        try {
            return $dbh->query(implode(' UNION ALL ', $parcalar))->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return [];
        }
    }
}
