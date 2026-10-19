<?php

declare(strict_types=1);

/**
 * Lumen duman testi — demo veritabanı üzerinde tüm sayfaları gezer.
 *
 * Demo ortamında (demo/kur.sh) uygulama konteyneri içinde çalışır:
 *   docker compose -f demo/docker-compose.yml exec app php tests/duman/tara.php
 *
 * Denetimler:
 *   1. Oturumsuz erişim: her sayfa girişe yönlendirmeli veya 401/403/404 dönmeli.
 *   2. Her demo kullanıcısıyla (YONETICI, SATIS1, DEPO1) her sayfa GET ile açılır:
 *      HTTP 500, gövdede PHP hata metni ve istek sırasında PHP hata loguna yazılan
 *      satırlar hata sayılır.
 *   3. Yetki: SATIS1/DEPO1, yönetici sayfalarını (ayar/, loglar) açamamalı.
 *
 * Ortam: LUMEN_TEST_URL (varsayılan http://web), LUMEN_TEST_SADECE (yol öneki filtresi).
 * Çıkış kodu: hata yoksa 0.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$KOK = dirname(__DIR__, 2);
$URL = rtrim(getenv('LUMEN_TEST_URL') ?: 'http://web', '/');
$SADECE = (string) getenv('LUMEN_TEST_SADECE');
$PAROLA = 'demo1234';

// Doğrudan açılan sayfa olmayan dosyalar (kütüphane / include / CLI).
$HARIC = [
    '#^(vendor|demo|tests|scripts|includes|tmp|logs|api)/#',
    '#^(ayr|kontrol|rector|donem_helper|log_ip|pwa-header|ux_katman|kurulum)\.php$#',
    '#(^|/)ayr\.php$#',
    '#_(lib|helper|guard|core)\.php$#',
    '#^ayar/(admin_guard|rol_helper|yetki_denetim_helper)\.php$#',
    // Yalnız include edilen parçalar (sekme şeritleri, tanım listeleri, JS/HTML parçası)
    '#^ayar/(\w+_sekmeler|yetki_tanimlari)\.php$#',
    '#^(cek/cek_kirpma|stok/ean)\.php$#',
];
// Oturumsuz açılabilen sayfalar.
$HERKESE_ACIK = ['giris.php', 'js_hata_log.php', 'ajax_hava.php'];
// Yalnız yöneticinin (M16) açabilmesi gereken sayfalar. Kişisel ayarlar
// (görünüm, kart favorileri) her kullanıcıya açıktır.
$YONETICI_SAYFASI = '#^(ayar/(?!gorunum\.php$|kart_favori_kaydet\.php$)|loglar\.php$)#';
// Sorgu parametresi olmadan açıldığında 400/404/422 dönmesi beklenen uç noktalar
// bu testte hata sayılmaz; yalnız 500 ve PHP hataları sayılır.

$sayfalar = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($KOK, FilesystemIterator::SKIP_DOTS));
foreach ($it as $dosya) {
    $yol = ltrim(str_replace('\\', '/', substr((string) $dosya, strlen($KOK))), '/');
    if (!str_ends_with($yol, '.php') || str_starts_with($yol, '.')) {
        continue;
    }
    foreach ($HARIC as $desen) {
        if (preg_match($desen, $yol)) {
            continue 2;
        }
    }
    if ($SADECE !== '' && !str_starts_with($yol, $SADECE)) {
        continue;
    }
    $sayfalar[] = $yol;
}
sort($sayfalar);

$logDosyalari = [$KOK . '/logs/php/php_errors.log', $KOK . '/logs/app/app-error.log'];

/** @return array<string,int> */
function log_boyutlari(array $dosyalar): array
{
    clearstatcache();
    $b = [];
    foreach ($dosyalar as $d) {
        $b[$d] = is_file($d) ? (int) filesize($d) : 0;
    }
    return $b;
}

/** @return string[] istek sırasında eklenen log satırları */
function yeni_log_satirlari(array $once): array
{
    clearstatcache();
    $satirlar = [];
    foreach ($once as $d => $boyut) {
        if (!is_file($d) || filesize($d) <= $boyut) {
            continue;
        }
        $fh = fopen($d, 'rb');
        fseek($fh, $boyut);
        $ek = (string) stream_get_contents($fh);
        fclose($fh);
        foreach (preg_split('/\R/', trim($ek)) ?: [] as $s) {
            if ($s !== '') {
                $satirlar[] = $s;
            }
        }
    }
    return $satirlar;
}

/** @return array{kod:int, govde:string, yer:string} */
function istek(string $url, string $cerez, array $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_COOKIEFILE => $cerez,
        CURLOPT_COOKIEJAR => $cerez,
        CURLOPT_HEADER => true,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $yanit = (string) curl_exec($ch);
    $kod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $bas = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $yer = (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
    curl_close($ch);
    return ['kod' => $kod, 'govde' => substr($yanit, $bas), 'yer' => $yer];
}

function giris_yap(string $url, string $kullanici, string $parola): string
{
    $cerez = tempnam(sys_get_temp_dir(), 'lumen_cerez_');
    istek("$url/giris.php", $cerez);
    $r = istek("$url/giris.php", $cerez, ['kullaniciadi' => $kullanici, 'kullanicisifre' => $parola]);
    if ($r['kod'] !== 302 || !str_contains($r['yer'], 'index.php')) {
        fwrite(STDERR, "GİRİŞ BAŞARISIZ: $kullanici (HTTP {$r['kod']})\n");
        exit(1);
    }
    return $cerez;
}

const PHP_HATA_DESENI = '/(Fatal error|Uncaught |Stack trace:|<b>(Warning|Notice|Deprecated)<\/b>|PHP (Warning|Notice|Deprecated))/';

$hatalar = [];
$hata = static function (string $tur, string $yol, string $ayrinti) use (&$hatalar): void {
    $hatalar[] = sprintf('%-10s %-45s %s', $tur, $yol, mb_substr(preg_replace('/\s+/', ' ', $ayrinti) ?? '', 0, 220));
};

// 1) Oturumsuz erişim
$anonim = tempnam(sys_get_temp_dir(), 'lumen_cerez_');
foreach ($sayfalar as $yol) {
    if (in_array($yol, $HERKESE_ACIK, true)) {
        continue;
    }
    $r = istek("$URL/$yol", $anonim);
    $girise = $r['kod'] >= 300 && $r['kod'] < 400 && str_contains($r['yer'], 'giris.php');
    if (!$girise && !in_array($r['kod'], [401, 403, 404], true)) {
        $hata('OTURUMSUZ', $yol, "HTTP {$r['kod']} — girişe yönlendirmedi");
    }
}

// 2-3) Kullanıcılarla gezinti
foreach (['YONETICI', 'SATIS1', 'DEPO1'] as $kullanici) {
    $cerez = giris_yap($URL, $kullanici, $PAROLA);
    foreach ($sayfalar as $yol) {
        $once = log_boyutlari($logDosyalari);
        $r = istek("$URL/$yol", $cerez);
        $etiket = "$kullanici";
        // 501: sunucuda bulunmayan özellik için bilinçli yanıt (ör. php_printer yok)
        if ($r['kod'] >= 500 && $r['kod'] !== 501) {
            $hata("HTTP{$r['kod']}", $yol, "$etiket");
        }
        if (preg_match(PHP_HATA_DESENI, $r['govde'], $m)) {
            $konum = strpos($r['govde'], $m[0]);
            $hata('PHP-ÇIKTI', $yol, "$etiket: " . strip_tags(substr($r['govde'], (int) $konum, 300)));
        }
        foreach (yeni_log_satirlari($once) as $satir) {
            $hata('PHP-LOG', $yol, "$etiket: $satir");
        }
        if ($kullanici !== 'YONETICI' && preg_match($YONETICI_SAYFASI, $yol) && $r['kod'] === 200
            && !preg_match('/(yetkiniz yok|erişim yetkiniz|403)/iu', $r['govde'])) {
            $hata('YETKİ', $yol, "$etiket yönetici sayfasını açabildi");
        }
        // Oturum düşmüş mü? (bir sayfa oturumu kapatırsa sonraki sonuçlar anlamsızlaşır)
        if ($r['kod'] >= 300 && $r['kod'] < 400 && str_contains($r['yer'], 'oturum_gerekli')) {
            $hata('OTURUM', $yol, "$etiket: oturum düştü, yeniden giriş yapılıyor");
            $cerez = giris_yap($URL, $kullanici, $PAROLA);
        }
    }
}

printf("Taranan sayfa: %d · Kullanıcı: 3 · Hata: %d\n", count($sayfalar), count($hatalar));
foreach ($hatalar as $h) {
    echo $h, "\n";
}
exit($hatalar === [] ? 0 : 1);
