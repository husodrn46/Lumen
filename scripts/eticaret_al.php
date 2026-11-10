<?php

declare(strict_types=1);

/**
 * E-ticaret otomatik sipariş alımı (zamanlanmış görev).
 *
 * Bağlantı ekranında "Otomatik alım" açık olan Trendyol ve Hepsiburada kanallarında yarım
 * kalan alımı sürdürür, ardından son alınan noktadan bugüne yeni aralık alır. Siparişler
 * yalnız kontrol kutusuna gelir; satış siparişine aktarım kullanıcı onayıyla yapılır.
 * Aynı anda tek kopya çalışır; süre dolarsa kalan iş bir sonraki çalıştırmada sürer.
 *
 *   php scripts/eticaret_al.php [--sure=240] [--kanal=ID]
 *
 * Windows Görev Zamanlayıcı örneği (15 dakikada bir):
 *   schtasks /Create /SC MINUTE /MO 15 /TN "Lumen e-ticaret alim" /TR "C:\php\php.exe C:\inetpub\lumen\scripts\eticaret_al.php"
 * Linux cron (15 dakikada bir):  0,15,30,45 * * * *  php /var/www/lumen/scripts/eticaret_al.php
 *
 * Çıkış kodu: 0 sorun yok, 1 en az bir kanal müdahale bekliyor, 2 görev çalışamadı.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$s = getopt('', ['sure:', 'kanal:']);
$sure = max(30, min(3300, (int) ($s['sure'] ?? 240)));
$yalniz = isset($s['kanal']) ? (int) $s['kanal'] : null;

$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? 'eticaret_al.php';
require_once dirname(__DIR__) . '/ayr.php';
require_once dirname(__DIR__) . '/includes/eticaret/otomatik.php';

$kilit = @fopen(dirname(__DIR__) . '/database/eticaret_al.kilit', 'c');
if ($kilit === false) {
    fwrite(STDERR, "Kilit dosyası açılamadı (database/eticaret_al.kilit). Görev hesabının yazma iznini kontrol edin.\n");
    exit(2);
}
if (!flock($kilit, LOCK_EX | LOCK_NB)) {
    echo "Başka bir otomatik alım sürüyor; bu çalıştırma atlandı.\n";
    exit(0);
}

try {
    $ozet = et_otomatik_calistir($dbh, $sure, $yalniz);
} catch (Throwable $e) {
    error_log('eticaret_al: ' . get_class($e) . ': ' . $e->getMessage());
    fwrite(STDERR, "Otomatik alım çalıştırılamadı; ayrıntı sunucu günlüğünde.\n");
    exit(2);
} finally {
    flock($kilit, LOCK_UN);
    fclose($kilit);
}

if ($ozet === []) {
    echo $yalniz !== null ? "Kanal {$yalniz} için otomatik alım açık değil.\n" : "Otomatik alımı açık kanal yok.\n";
    exit($yalniz !== null ? 1 : 0);
}

$sorun = 0;
foreach ($ozet as $id => $o) {
    $satir = sprintf('%s (kanal %d): ', $o['ad'], $id);
    if ($o['hata'] !== null) {
        $sorun++;
        fwrite(STDERR, $satir . 'HATA — ' . $o['hata'] . "\n");
        continue;
    }
    printf("%s%s · %d sayfa, %d yeni, %d tekrar, %d inceleme gereken\n", $satir,
        $o['durum'] === 'suruyor' ? 'süre doldu, sonraki çalıştırmada sürecek' : 'güncel',
        $o['sayfa'], $o['yeni'], $o['mukerrer'], $o['incele']);
}
exit($sorun > 0 ? 1 : 0);
