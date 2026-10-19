<?php

declare(strict_types=1);

/**
 * Akış testlerinin ortak parçası: demo veritabanı bağlantısı, oturumlu HTTP
 * istemcisi (Tarayici) ve akis()/bekle()/yakin()/tek() yardımcıları.
 * tests/akis/akislar.php (LOGO modu) ve tests/akis/bagimsiz.php (bağımsız mod) kullanır.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$URL = rtrim(getenv('LUMEN_TEST_URL') ?: 'http://web', '/');
$DB  = getenv('AKL_DB_NAME') ?: 'LUMEN_DEMO';
if (!preg_match('/^LUMEN_[A-Z0-9_]+$/', $DB)) {
    fwrite(STDERR, "REDDEDİLDİ: akış testleri yalnız demo/test veritabanında çalışır ({$DB}).\n");
    exit(3);
}
$pdo = new PDO(
    sprintf('sqlsrv:server=%s;database=%s;TrustServerCertificate=1', getenv('AKL_DB_SERVER') ?: 'db', $DB),
    getenv('AKL_DB_USER') ?: 'sa', getenv('AKL_DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ---------------------------------------------------------------- yardımcılar
final class Tarayici
{
    private string $cerez;

    public function __construct(private string $url)
    {
        $this->cerez = (string) tempnam(sys_get_temp_dir(), 'akis_');
    }

    /** @return array{kod:int, govde:string, yer:string} */
    public function istek(string $yol, ?array $post = null, array $basliklar = []): array
    {
        $ch = curl_init(str_starts_with($yol, 'http') ? $yol : $this->url . '/' . ltrim($yol, '/'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_COOKIEFILE => $this->cerez, CURLOPT_COOKIEJAR => $this->cerez,
            CURLOPT_HTTPHEADER => $basliklar,
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $yanit = (string) curl_exec($ch);
        $bas = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $sonuc = ['kod' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'govde' => substr($yanit, $bas),
                  'yer' => (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '')];
        curl_close($ch);
        return $sonuc;
    }

    public function giris(string $kullanici, string $parola = 'demo1234'): self
    {
        $this->istek('giris.php');
        $r = $this->istek('giris.php', ['kullaniciadi' => $kullanici, 'kullanicisifre' => $parola]);
        if ($r['kod'] !== 302) {
            throw new RuntimeException("giriş başarısız: {$kullanici}");
        }
        return $this;
    }

    /** Çerez kavanozunda yalnız adı desene uyan çerezleri bırakır (ör. oturum düşmüş tarayıcı). */
    public function cerezleriSuz(string $desen): void
    {
        $satirlar = file($this->cerez) ?: [];
        $kalan = array_filter($satirlar, static fn(string $s): bool => str_starts_with($s, '# ') || trim($s) === ''
            || preg_match($desen, (string) (explode("\t", rtrim($s, "\n"))[5] ?? '')) === 1);
        file_put_contents($this->cerez, implode('', $kalan));
    }

    /** Sayfadaki CSRF belirteci (form alanı veya JS değişkeni). */
    public function belirtec(string $yol): string
    {
        $g = $this->istek($yol)['govde'];
        if (preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $g, $m) || preg_match('/csrf\w*\s*=\s*"([a-f0-9]{40,})"/i', $g, $m)) {
            return $m[1];
        }
        throw new RuntimeException("CSRF belirteci bulunamadı: {$yol}");
    }
}

function tek(PDO $pdo, string $sql, array $p = []): mixed
{
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $v = $st->fetchColumn();
    $st->closeCursor();
    return $v;
}

$sonuclar = [];
function akis(string $ad, callable $fn): void
{
    global $sonuclar;
    try {
        $fn();
        $sonuclar[] = [true, $ad, ''];
        echo "  ✓ {$ad}\n";
    } catch (Throwable $e) {
        $sonuclar[] = [false, $ad, $e->getMessage()];
        echo "  ✗ {$ad}\n      " . $e->getMessage() . "\n";
    }
}

function bekle(bool $kosul, string $mesaj): void
{
    if (!$kosul) {
        throw new RuntimeException($mesaj);
    }
}

function yakin(float $beklenen, float $gercek, string $ne): void
{
    bekle(abs($beklenen - $gercek) < 0.011, "{$ne}: beklenen {$beklenen}, gelen {$gercek}");
}

