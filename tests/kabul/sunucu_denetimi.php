<?php

declare(strict_types=1);

/**
 * Kurulu bir Lumen'i DIŞARIDAN denetler (IIS, Apache, nginx fark etmez): gizli dosyalar
 * tarayıcıdan indirilemiyor mu, güvenlik başlıkları ve çerez bayrakları yerinde mi,
 * kurulum sihirbazı kapalı mı, PHP sürümü sızıyor mu / yamalı mı.
 * Uygulama koduna bağımlılığı yoktur; yalnız PHP + curl gerekir.
 *
 *   php tests/kabul/sunucu_denetimi.php https://lumen.firma.local [--kullanici YONETICI --parola ...] [--rapor rapor.md]
 *
 * Parola komut satırında görünmesin isterseniz LUMEN_DENETIM_PAROLA ortam değişkenini kullanın.
 * Yönetici bilgisi verilirse giriş yapılıp ana sayfadaki PHP güvenlik uyarısına bakılır.
 * Çıkış kodu: 0 sorun yok, 1 en az bir HATA, 2 kullanım hatası. Hiçbir veri değiştirmez.
 */

if (PHP_SAPI !== 'cli') {
    exit(2);
}
// Seçenekler adresten önce ya da sonra yazılabilir.
$o = [];
$url = '';
for ($i = 1; $i < $argc; $i++) {
    if (preg_match('/^--(kullanici|parola|rapor)$/', $argv[$i], $m) && isset($argv[$i + 1])) {
        $o[$m[1]] = $argv[++$i];
    } elseif ($argv[$i] === '--yardim') {
        $o['yardim'] = true;
    } else {
        $url = rtrim($argv[$i], '/');
    }
}
if (isset($o['yardim']) || preg_match('#^https?://#', $url) !== 1) {
    fwrite(STDERR, "Kullanım: php tests/kabul/sunucu_denetimi.php https://adres [--kullanici K --parola P] [--rapor dosya.md]\n");
    exit(2);
}
$parola = (string) ($o['parola'] ?? getenv('LUMEN_DENETIM_PAROLA') ?: '');
$cerez = tempnam(sys_get_temp_dir(), 'ldenetim');

/** @return array{kod:int, govde:string, basliklar:array<string, list<string>>, yer:string} */
function iste(string $url, ?array $post = null, string $cerez = ''): array
{
    $ch = curl_init($url);
    $basliklar = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => getenv('LUMEN_DENETIM_SERTIFIKA_DENETLEME') !== '0',
        CURLOPT_COOKIEFILE => $cerez, CURLOPT_COOKIEJAR => $cerez, CURLOPT_USERAGENT => 'Lumen-sunucu-denetimi',
        CURLOPT_HEADERFUNCTION => static function ($ch, string $satir) use (&$basliklar): int {
            if (str_contains($satir, ':')) {
                [$k, $v] = explode(':', $satir, 2);
                $basliklar[strtolower(trim($k))][] = trim($v);
            }
            return strlen($satir);
        },
    ]);
    if ($post !== null) {
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    }
    $govde = curl_exec($ch);
    $r = ['kod' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'govde' => is_string($govde) ? $govde : '',
          'basliklar' => $basliklar, 'yer' => (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL), 'hata' => curl_error($ch)];
    curl_close($ch);
    return $r;
}

$sonuclar = [];
$kaydet = static function (string $durum, string $konu, string $ayrinti = '') use (&$sonuclar): void {
    $sonuclar[] = [$durum, $konu, $ayrinti];
    printf("  %s %s%s\n", ['TAMAM' => '✓', 'UYARI' => '!', 'HATA' => '✗'][$durum], $konu, $ayrinti !== '' ? " — {$ayrinti}" : '');
};

echo "Lumen sunucu denetimi: {$url}\n";
$giris = iste("{$url}/giris.php", null, $cerez);
if ($giris['kod'] === 0) {
    fwrite(STDERR, "Sunucuya ulaşılamadı: {$giris['hata']}\n");
    exit(2);
}
$kaydet($giris['kod'] === 200 && stripos($giris['govde'], 'kullaniciadi') !== false ? 'TAMAM' : 'HATA', 'Giriş sayfası açılıyor', "HTTP {$giris['kod']}");

// 1) Tarayıcıdan indirilmemesi gereken dosya ve klasörler
$gizli = [
    '/.env' => 'veritabanı parolası', '/_baglanti_.inc' => 'bağlantı dosyası', '/_bilgi_.inc' => 'ayar dosyası',
    '/.git/HEAD' => 'kaynak geçmişi', '/composer.json' => 'bağımlılık listesi', '/composer.lock' => 'bağımlılık sürümleri',
    '/database/ayarlar.json' => 'uygulama ayarları', '/database/yedek_ayar.json' => 'yedek ayarı',
    '/database/yedekler/' => 'yedek klasörü (parola özetleri içerir)', '/database/enflasyon.db' => 'yerel veri',
    '/logs/app/app-error.log' => 'hata günlüğü', '/logs/php/php_errors.log' => 'PHP günlüğü',
    '/sql/m_p_yetki.sql' => 'şema betiği', '/vendor/autoload.php' => 'kütüphaneler', '/tests/akis/_ortak.php' => 'testler',
    '/scripts/logo_aktar.php' => 'komut satırı betiği', '/demo/kur.sh' => 'demo betiği', '/.htaccess' => 'sunucu kuralları',
    '/web.config' => 'IIS kuralları', '/kurulum.php.bak' => 'yedek dosya',
];
foreach ($gizli as $yol => $ne) {
    $r = iste($url . $yol, null, $cerez);
    $acik = $r['kod'] === 200 && $r['govde'] !== '' && stripos($r['govde'], 'kullaniciadi') === false;
    if ($acik && in_array($yol, ['/vendor/autoload.php', '/tests/akis/_ortak.php', '/scripts/logo_aktar.php'], true) && trim($r['govde']) === '') {
        $kaydet('UYARI', "{$yol} engellenmemiş ({$ne})", 'boş yanıt veriyor; yine de sunucu kuralıyla kapatın');
        continue;
    }
    $kaydet($acik ? 'HATA' : 'TAMAM', "{$yol} dışarıya kapalı ({$ne})", "HTTP {$r['kod']}");
}

// 2) Kurulum sihirbazı kurulu sistemde kapalı olmalı
$k = iste("{$url}/kurulum.php", null, $cerez);
$kaydet($k['kod'] === 200 && str_contains($k['govde'], 'db_server') ? 'HATA' : 'TAMAM', 'Kurulum sihirbazı kapalı', $k['kod'] === 200 ? 'sihirbaz formu açık!' : "HTTP {$k['kod']}");

// 3) Güvenlik başlıkları, PHP sürüm sızıntısı, çerezler
$b = $giris['basliklar'];
foreach (['x-content-type-options' => 'nosniff', 'x-frame-options' => '', 'content-security-policy' => '', 'referrer-policy' => ''] as $baslik => $beklenen) {
    $v = implode(' ', $b[$baslik] ?? []);
    $kaydet($v !== '' && ($beklenen === '' || stripos($v, $beklenen) !== false) ? 'TAMAM' : 'UYARI', "Güvenlik başlığı: {$baslik}", $v !== '' ? $v : 'yok');
}
$powered = implode(' ', $b['x-powered-by'] ?? []);
$sunucu = implode(' ', $b['server'] ?? []);
if (preg_match('/PHP\/([0-9.]+)/i', $powered . ' ' . $sunucu, $m)) {
    $kaydet('UYARI', 'PHP sürümü yanıt başlığında görünüyor', "{$m[1]} — php.ini'de expose_php=Off yapın");
    $yamali = ['8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'];
    $dal = implode('.', array_slice(explode('.', $m[1]), 0, 2));
    $durum = isset($yamali[$dal]) ? version_compare($m[1], $yamali[$dal], '>=') : version_compare($dal, '8.5', '>');
    $kaydet($durum ? 'TAMAM' : 'HATA', 'PHP kritik açıklara karşı yamalı (CVE-2026-109618/109619)', $m[1]);
} else {
    $kaydet('TAMAM', 'PHP sürümü yanıt başlığında gizli');
}
$https = str_starts_with($url, 'https://');
$kaydet($https ? 'TAMAM' : 'UYARI', 'HTTPS kullanılıyor', $https ? '' : 'parolalar ağda açık gider; yerel ağ dışında mutlaka HTTPS');
foreach ($b['set-cookie'] ?? [] as $c) {
    if (stripos($c, 'PHPSESSID') === false && stripos($c, 'LUMEN') === false) {
        continue;
    }
    $ad = strtok($c, '=');
    $eksik = array_filter(['HttpOnly', 'SameSite'] + ($https ? [2 => 'Secure'] : []), static fn($f) => stripos($c, $f) === false);
    $kaydet($eksik === [] ? 'TAMAM' : 'UYARI', "Oturum çerezi bayrakları ({$ad})", $eksik === [] ? '' : 'eksik: ' . implode(', ', $eksik));
}

// 4) İsteğe bağlı: yönetici girişi ve ana sayfadaki PHP güvenlik uyarısı
$kullanici = (string) ($o['kullanici'] ?? '');
if ($kullanici !== '' && $parola !== '') {
    $g = iste("{$url}/giris.php", ['kullaniciadi' => $kullanici, 'kullanicisifre' => $parola], $cerez);
    if (!in_array($g['kod'], [302, 303], true)) {
        $kaydet('UYARI', 'Yönetici girişi yapılamadı', "HTTP {$g['kod']} — denetimin bu kısmı atlandı");
    } else {
        $ana = iste("{$url}/index.php", null, $cerez);
        $uyari = str_contains($ana['govde'], "Sunucu PHP'si güncellenmeli");
        $kaydet($uyari ? 'HATA' : 'TAMAM', 'Lumen PHP güvenlik uyarısı vermiyor (PHP yamalı)', $uyari ? 'ana sayfada güncelleme uyarısı var' : '');
        $sema = str_contains($ana['govde'], 'Veritabanı şeması güncel değil');
        $kaydet($sema ? 'HATA' : 'TAMAM', 'Veritabanı şeması güncel', $sema ? 'sunucuda php scripts/sema_guncelle.php çalıştırın' : '');
        $y = iste("{$url}/ayar/sistem_yedek.php", null, $cerez);
        if ($y['kod'] === 200 && str_contains($y['govde'], 'Yedekleme')) {
            $yok = str_contains($y['govde'], 'Henüz yedek yok');
            $kaydet($yok ? 'UYARI' : 'TAMAM', 'En az bir yedek var', $yok ? 'Sistem ve Yedek ekranından yedek alın' : '');
        }
    }
}
@unlink($cerez);

$hata = count(array_filter($sonuclar, static fn($s) => $s[0] === 'HATA'));
$uyari = count(array_filter($sonuclar, static fn($s) => $s[0] === 'UYARI'));
printf("\n%d kontrol: %d hata, %d uyarı\n", count($sonuclar), $hata, $uyari);
if (isset($o['rapor'])) {
    $md = "## Sunucu denetimi\n\nAdres: `{$url}` · " . date('Y-m-d H:i') . "\n\n| Sonuç | Kontrol | Ayrıntı |\n|---|---|---|\n";
    foreach ($sonuclar as [$d, $k, $a]) {
        $md .= '| ' . ['TAMAM' => '✅', 'UYARI' => '⚠️', 'HATA' => '❌'][$d] . " | {$k} | " . str_replace('|', '\|', $a) . " |\n";
    }
    $md .= "\n**{$hata} hata, {$uyari} uyarı**\n";
    file_put_contents((string) $o['rapor'], $md, FILE_APPEND);
}
exit($hata === 0 ? 0 : 1);
