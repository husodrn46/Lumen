<?php

declare(strict_types=1);

/**
 * LOGO Tiger veritabanından bağımsız Lumen veritabanına geçiş (includes/aktarim/logo_aktar.php).
 *
 *   php scripts/logo_aktar.php --kaynak LOGODB --hedef LUMEN --firma 1 --donem 2 [--sema] [--tarih 2027-01-01] [--uygula]
 *
 * Sunucu, kullanıcı ve parola ortamdan okunur (AKL_DB_SERVER, AKL_DB_USER, AKL_DB_PASS);
 * parola komut satırında verilmez (süreç listesinde görünmesin). İki veritabanı aynı
 * sunucuda olmalıdır.
 *
 * Varsayılan DENEME kipidir: her şey hedefe yazılır, toplamlar karşılaştırılır ve
 * işlem geri alınır. Yazmak için --uygula. --sema hedefe Lumen şemasını kurar
 * (yeni, boş veritabanı için). Hedef boş değilse çalışmaz.
 *
 * Sonra: .env'de AKL_DB_NAME=<hedef>, LUMEN_VERI_KAYNAGI=lumen. Kullanıcılar aynı
 * kod ve parolalarla girer (M_P_YETKI kimlikleriyle aktarılır).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Hedef bağımsız şemadır: veri katmanı işlevleri (lumen_insert_kimlik vb.) bu kipte çalışır.
putenv('LUMEN_VERI_KAYNAGI=lumen');
require_once dirname(__DIR__) . '/includes/kurulum_sema.php';
require_once dirname(__DIR__) . '/includes/aktarim/logo_aktar.php';

$o = getopt('', ['kaynak:', 'hedef:', 'firma:', 'donem:', 'tarih:', 'sema', 'uygula', 'yardim']);
if (isset($o['yardim']) || empty($o['kaynak']) || empty($o['hedef'])) {
    fwrite(STDERR, "Kullanım: php scripts/logo_aktar.php --kaynak LOGODB --hedef LUMEN --firma 1 --donem 2 [--sema] [--tarih YYYY-AA-GG] [--uygula]\n");
    exit(2);
}
foreach (['kaynak', 'hedef'] as $a) {
    if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,99}$/', (string) $o[$a]) !== 1) {
        fwrite(STDERR, "Geçersiz veritabanı adı: --{$a}\n");
        exit(2);
    }
}
if ($o['kaynak'] === $o['hedef']) {
    fwrite(STDERR, "Kaynak ve hedef aynı veritabanı olamaz.\n");
    exit(2);
}
$sunucu = (string) (getenv('AKL_DB_SERVER') ?: 'localhost');
$baglan = static fn(string $db): PDO => new PDO(
    "sqlsrv:server={$sunucu};database={$db};TrustServerCertificate=1",
    (string) getenv('AKL_DB_USER'),
    (string) getenv('AKL_DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

try {
    $kaynak = $baglan((string) $o['kaynak']);
    $hedef = $baglan((string) $o['hedef']);
    if (isset($o['sema'])) {
        $s = kurulum_sema_uygula($hedef, dirname(__DIR__) . '/sql', true);
        printf("şema: %d/%d betik\n", $s['basarili'], $s['toplam']);
        if ($s['hatalar'] !== []) {
            foreach ($s['hatalar'] as $ad => $m) {
                fwrite(STDERR, "HATA {$ad}: {$m}\n");
            }
            exit(1);
        }
    }
    $sonuc = logo_aktar($kaynak, $hedef, [
        'firma' => (int) ($o['firma'] ?? 1),
        'donem' => (int) ($o['donem'] ?? 1),
        'tarih' => (string) ($o['tarih'] ?? date('Y-m-d')),
        'uygula' => isset($o['uygula']),
    ], static function (string $satir): void {
        echo "  {$satir}\n";
    });
} catch (Throwable $e) {
    fwrite(STDERR, 'HATA: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\nKarşılaştırma (LOGO → Lumen):\n";
foreach ($sonuc['karsilastirma'] as $ad => [$a, $b]) {
    printf("  %-22s %18s %18s  %s\n", $ad, number_format($a, 2, ',', '.'), number_format($b, 2, ',', '.'), abs($a - $b) <= 0.011 ? 'tamam' : 'FARK');
}
foreach ($sonuc['uyarilar'] as $u) {
    echo "  uyarı: {$u}\n";
}
echo "\n{$sonuc['mesaj']}\n";
exit($sonuc['ok'] ? 0 : 1);
