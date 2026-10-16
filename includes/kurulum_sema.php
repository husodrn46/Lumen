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
    function kurulum_sema_uygula(PDO $pdo, string $dir): array
    {
        $dosyalar = glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($dosyalar, SORT_NATURAL | SORT_FLAG_CASE);

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
