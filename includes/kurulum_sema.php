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

require_once __DIR__ . '/veri/veri.php';

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
                    error_log('kurulum_sema ' . basename($yol) . ': ' . $e->getMessage());
                }
            }
            $ilerledi = count($kalan) < count($bekleyen);
            $bekleyen = $kalan;
        } while ($bekleyen !== [] && $ilerledi);

        return ['basarili' => count($dosyalar) - count($bekleyen), 'toplam' => count($dosyalar), 'hatalar' => $hatalar];
    }
}

if (!function_exists('kurulum_yonetici_olustur')) {
    /**
     * Kurulumun yönetici adımı: kişiyi bulur (bağlı modda LOGO satış temsilcisi
     * olmalı; bağımsız modda LM_PERSONEL'de yoksa oluşturulur) ve M_P_YETKI'ye
     * yönetici (YETKI=0) olarak parolasıyla yazar.
     *
     * @return array{ok:bool, mesaj:string, personel?:int}
     */
    function kurulum_yonetici_olustur(PDO $pdo, bool $bagimsiz, string $kod, string $ad, string $parola): array
    {
        $kod = strtoupper(trim($kod));
        if ($kod === '' || preg_match('/^[A-Z0-9._-]{2,40}$/', $kod) !== 1) {
            return ['ok' => false, 'mesaj' => 'Kullanıcı kodu 2-40 karakter olmalı (harf, rakam, . _ -).'];
        }
        if (mb_strlen($parola) < 8) {
            return ['ok' => false, 'mesaj' => 'Parola en az 8 karakter olmalı.'];
        }
        if ($bagimsiz) {
            $q = $pdo->prepare('SELECT ID, AKTIF FROM dbo.LM_PERSONEL WHERE KOD = :k');
            $q->execute([':k' => $kod]);
            $kisi = $q->fetch(PDO::FETCH_ASSOC);
            if ($kisi && (int) $kisi['AKTIF'] !== 1) {
                return ['ok' => false, 'mesaj' => "'{$kod}' kodlu kullanıcı pasif."];
            }
            $ref = $kisi ? (int) $kisi['ID']
                : lumen_insert_kimlik($pdo, 'INSERT INTO dbo.LM_PERSONEL (KOD, AD, AKTIF) VALUES (:k, :a, 1)',
                    [':k' => $kod, ':a' => trim($ad) !== '' ? mb_substr(trim($ad), 0, 120) : $kod], 'ID');
        } else {
            // LOGO'da ACTIVE = 0 kullanımda demektir.
            $q = $pdo->prepare('SELECT LOGICALREF FROM LG_SLSMAN WHERE CODE = :k AND ACTIVE = 0');
            $q->execute([':k' => $kod]);
            $ref = $q->fetchColumn();
            if ($ref === false) {
                return ['ok' => false, 'mesaj' => "'{$kod}' kodlu aktif satış temsilcisi bulunamadı. Önce LOGO'da tanımlayın."];
            }
            $ref = (int) $ref;
        }
        $pdo->exec("IF OBJECT_ID('dbo.M_P_YETKI','U') IS NULL
            CREATE TABLE M_P_YETKI (PERSONEL INT NOT NULL PRIMARY KEY, SIFRE NVARCHAR(255) NULL, YETKI INT NULL)");
        $hash = password_hash($parola, PASSWORD_DEFAULT);
        $var = $pdo->prepare('SELECT COUNT(*) FROM M_P_YETKI WHERE PERSONEL = :p');
        $var->execute([':p' => $ref]);
        if ((int) $var->fetchColumn() > 0) {
            $pdo->prepare('UPDATE M_P_YETKI SET SIFRE = :s, YETKI = 0 WHERE PERSONEL = :p')->execute([':s' => $hash, ':p' => $ref]);
        } else {
            // Mevcut kurulumlarda M_P_YETKI ek NOT NULL izin kolonlarına (M1..Mn) sahip
            // olabilir; bunları 0 ile doldur (yönetici YETKI=0 zaten tüm izinleri açar).
            $extra = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_NAME = 'M_P_YETKI' AND IS_NULLABLE = 'NO'
                  AND COLUMN_NAME NOT IN ('PERSONEL','SIFRE','YETKI')
                  AND COLUMNPROPERTY(OBJECT_ID('dbo.M_P_YETKI'), COLUMN_NAME, 'IsIdentity') = 0")
                ->fetchAll(PDO::FETCH_COLUMN);
            $cols = ['[PERSONEL]', '[SIFRE]', '[YETKI]'];
            $vals = [':p', ':s', '0'];
            foreach ($extra as $c) {
                $cols[] = '[' . $c . ']';
                $vals[] = '0';
            }
            $pdo->prepare('INSERT INTO M_P_YETKI (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')')
                ->execute([':p' => $ref, ':s' => $hash]);
        }
        return ['ok' => true, 'mesaj' => 'Yönetici hazır.', 'personel' => $ref];
    }
}

if (!function_exists('kurulum_bagimsiz_veritabani')) {
    /**
     * Bağımsız kurulumda veritabanına bağlanır; yoksa (SQL Server 4060) master
     * üzerinden oluşturup yeniden bağlanır. LOGO veritabanı seçilmişse reddeder:
     * bağımsız mod ayrı bir veritabanında çalışır.
     *
     * @param callable(string $db): PDO $baglan
     * @return array{0: PDO, 1: bool} [bağlantı, yeni oluşturuldu mu]
     */
    function kurulum_bagimsiz_veritabani(callable $baglan, string $db): array
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,99}$/', $db) !== 1) {
            throw new InvalidArgumentException('Veritabanı adı harfle başlamalı; yalnız harf, rakam ve _ içermeli.');
        }
        $yeni = false;
        try {
            $pdo = $baglan($db);
        } catch (PDOException $e) {
            if (!str_contains($e->getMessage(), '4060') && stripos($e->getMessage(), 'Cannot open database') === false) {
                throw $e;
            }
            $baglan('master')->exec("CREATE DATABASE [{$db}]");
            $pdo = $baglan($db);
            $yeni = true;
        }
        $logo = (int) $pdo->query("SELECT COUNT(*) FROM sys.tables WHERE name LIKE 'LG[_]%[_]CLCARD' OR name = 'LG_SLSMAN'")->fetchColumn();
        if ($logo > 0) {
            throw new RuntimeException('Bu veritabanında LOGO tabloları var. Bağımsız mod için ayrı (boş) bir veritabanı adı girin.');
        }
        return [$pdo, $yeni];
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
            'M_API_IDEMPOTENCY' => null,
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
