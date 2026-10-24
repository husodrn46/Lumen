<?php

declare(strict_types=1);

/**
 * Log hijyeni (zamanlanmış görev): büyüyen log dosyalarını döndürür, eski
 * arşivleri siler.
 *
 *   php scripts/log_hijyeni.php                 # varsayılan: 10 MB üstünü döndür, 90 günden eski arşivi sil
 *   php scripts/log_hijyeni.php --mb=50 --gun=180
 *   php scripts/log_hijyeni.php --kuru          # yalnız ne yapılacağını göster
 *
 * Log kökü .env LOG_ROOT (yoksa <proje>/logs). Döndürülen dosya
 * <ad>.YYYYmmdd-HHMMSS.log.gz olarak sıkıştırılır ve asıl dosya sıfırlanır
 * (yazan süreçler aynı dosyaya eklemeye devam eder).
 *
 * Eskiden erişim logu günde bir kez bir kullanıcı isteği sırasında tümüyle
 * belleğe okunup süzülüyordu (log_ip.php): büyük logda yavaş istek ve arada
 * yazılan satırların kaybı. Bu betik onun yerini alır.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/app_observability.php';

$s = getopt('', ['mb:', 'gun:', 'kuru']);
$esik = max(1, (int) ($s['mb'] ?? 10)) * 1024 * 1024;
$gun = max(1, (int) ($s['gun'] ?? 90));
$kuru = isset($s['kuru']);
$kok = function_exists('app_log_root_dir') ? app_log_root_dir() : dirname(__DIR__) . '/logs';

if (!is_dir($kok)) {
    echo "Log kökü yok: {$kok}\n";
    exit(0);
}

$dondurulen = 0;
$silinen = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($kok, FilesystemIterator::SKIP_DOTS));
foreach ($it as $dosya) {
    $yol = (string) $dosya;
    if (str_ends_with($yol, '.log') && $dosya->getSize() > $esik) {
        $arsiv = substr($yol, 0, -4) . '.' . date('Ymd-His') . '.log.gz';
        printf("döndür  %s (%.1f MB) → %s\n", $yol, $dosya->getSize() / 1048576, basename($arsiv));
        if ($kuru) {
            continue;
        }
        $kaynak = fopen($yol, 'r+');
        if ($kaynak === false || !flock($kaynak, LOCK_EX)) {
            fwrite(STDERR, "kilitlenemedi: {$yol}\n");
            continue;
        }
        $hedef = gzopen($arsiv, 'wb6');
        if ($hedef === false) {
            flock($kaynak, LOCK_UN);
            fclose($kaynak);
            fwrite(STDERR, "arşiv yazılamadı: {$arsiv}\n");
            continue;
        }
        while (!feof($kaynak)) {
            gzwrite($hedef, (string) fread($kaynak, 1 << 20));
        }
        gzclose($hedef);
        ftruncate($kaynak, 0);
        flock($kaynak, LOCK_UN);
        fclose($kaynak);
        $dondurulen++;
    } elseif (preg_match('/\.\d{8}-\d{6}\.log\.gz$/', $yol) && $dosya->getMTime() < time() - $gun * 86400) {
        printf("sil     %s\n", $yol);
        if (!$kuru && unlink($yol)) {
            $silinen++;
        }
    }
}
printf("%s%d dosya döndürüldü, %d eski arşiv silindi.\n", $kuru ? '[kuru çalışma] ' : '', $dondurulen, $silinen);
