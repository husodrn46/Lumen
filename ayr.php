<?php
declare(strict_types=1);

//header("Content-Type: text/html; charset=utf-8");
# Set timezone
date_default_timezone_set('Europe/Istanbul');

// ================================================================
// GÜVENLIK: .env Dosyası Yükleyici
// ================================================================
/**
 * .env dosyasını yükler ve environment variables olarak set eder
 * Güvenlik: API anahtarları ve hassas bilgiler için
 */
function loadEnv(?string $path = null): bool
{
  if ($path === null) {
    $path = __DIR__ . '/.env';
  }

  if (!file_exists($path)) {
    // .env yoksa sessizce geç — ortam değişkenleri sunucu ortamından (IIS/Apache
    // app pool env) da gelebilir. Kurulum için: .env.example -> .env veya /kurulum.php
    return false;
  }

  $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    // Yorum satırlarını atla
    if (str_starts_with(trim($line), '#')) {
      continue;
    }

    // KEY=VALUE formatını parse et
    if (str_contains($line, '=')) {
      [$key, $value] = explode('=', $line, 2);
      $key = trim($key);
      $value = trim($value);

      // Tırnak işaretlerini temizle
      $value = trim($value, '"\'');

      // Environment variable olarak set et (eğer yoksa)
      if (!getenv($key)) {
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
      }
    }
  }

  return true;
}

// .env dosyasını yükle
loadEnv();
require_once __DIR__ . '/includes/app_observability.php';

if (function_exists('app_register_global_error_handlers')) {
  app_register_global_error_handlers();
}

if (function_exists('app_register_request_perf_logger')) {
  app_register_request_perf_logger();
}

// Tüm uygulama hatalarını merkezi dosyaya yönlendir.
if (function_exists('app_php_error_log_file')) {
  $phpErrorLogFile = app_php_error_log_file();
  if ($phpErrorLogFile !== '') {
    @ini_set('log_errors', '1');
    @ini_set('error_log', $phpErrorLogFile);
  }
}

// ================================================================
// GÜVENLIK: XSS Koruma Fonksiyonları
// ================================================================

/**
 * XSS koruması için HTML entities encode
 * Kullanım: echo e($kullanici_girisi);
 *
 * @param mixed $value Encode edilecek değer
 * @return string HTML-safe string
 */
function e(mixed $value): string
{
  if ($value === null) {
    return '';
  }
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * XSS koruması ile birlikte null-safe echo
 * Kullanım: echo safe($kullanici_girisi, 'Varsayılan');
 *
 * @param mixed $value Echo edilecek değer
 * @param string $default Varsayılan değer (değer boşsa)
 * @return string HTML-safe string
 */
function safe(mixed $value, mixed $default = ''): string
{
  if ($value === null || $value === '') {
    return e($default);
  }
  return e($value);
}

// ================================================================
// GÜVENLIK: CSRF Token Fonksiyonları
// ================================================================

/**
 * CSRF token oluştur veya mevcut token'ı döndür
 * @return string CSRF token
 */
function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * CSRF token için hidden input HTML'i döndür
 * @return string HTML hidden input
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

/**
 * CSRF token doğrula
 * @param string|null $token Doğrulanacak token (null ise POST'tan alır)
 * @return bool Token geçerli mi
 */
function csrf_verify(?string $token = null): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if ($token === null) {
        $token = $_POST['csrf_token'] ?? '';
    }

    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * CSRF token'ı yenile (form gönderildikten sonra kullanılabilir)
 */
function csrf_regenerate(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ================================================================
// KİŞİSEL GÖRÜNÜM: Kullanıcı vurgu rengi (M_USER_SETTINGS, key: tema_vurgu)
// ================================================================

/**
 * İzinli vurgu renkleri (preset palet). Anahtar = M_USER_SETTINGS'e kayıtlı değer;
 * dizi = [ana hex, soft hex]. SADECE bu anahtarlar kaydedilir/uygulanır
 * (okunmaz renk + XSS engeli — kullanıcı serbest hex giremez).
 * @return array<string,array{0:string,1:string}>
 */
function tema_vurgu_paleti(): array
{
    return [
        'kirmizi'  => ['#6F1022', '#fef2f2'],
        'mavi'     => ['#2563eb', '#eff6ff'],
        'mor'      => ['#7c3aed', '#f5f3ff'],
        'yesil'    => ['#059669', '#ecfdf5'],
        'turuncu'  => ['#ea580c', '#fff7ed'],
        'teal'     => ['#0d9488', '#f0fdfa'],
        'pembe'    => ['#db2777', '#fdf2f8'],
        'lacivert' => ['#1d4ed8', '#eff6ff'],
    ];
}

/**
 * Tema/kişisel ayar için USER_CODE. api/tercih_*.php ile AYNI derivasyon:
 * oturumdaki personel (LG_SLSMAN.LOGICALREF) -> CODE; bulunamazsa id string.
 * Böylece web ile masaüstü uç aynı satırı okur/yazar.
 */
function tema_kullanici_kodu(): string
{
    global $dbh;
    $personel = (int) ($_SESSION['plasiyer_id'] ?? 0);
    if ($personel <= 0) {
        return '';
    }
    try {
        $stmt = $dbh->prepare("SELECT CODE FROM LG_SLSMAN WHERE LOGICALREF = :id");
        $stmt->execute([':id' => $personel]);
        $kod = $stmt->fetchColumn();
        if ($kod !== false && $kod !== null && (string) $kod !== '') {
            return (string) $kod;
        }
    } catch (Throwable $e) {
        error_log('tema_kullanici_kodu: ' . $e->getMessage());
    }
    return (string) $personel;
}

/**
 * Kullanıcının TÜM kişisel ayarlarını [key=>value] döndürür. Session-cache'li
 * ($_SESSION['_kis_ayar']) — 118 sayfalık pwa-header'da tek DB sorgusu yeter.
 * Kayıt değişince gorunum.php bu cache'i günceller.
 * @return array<string,string>
 */
function kisisel_ayarlar(): array
{
    if (isset($_SESSION['_kis_ayar']) && is_array($_SESSION['_kis_ayar'])) {
        return $_SESSION['_kis_ayar'];
    }
    $kod = tema_kullanici_kodu();
    if ($kod === '') {
        // Giriş YAPILMAMIŞ (giris.php de pwa-header'ı include eder): boş sonucu
        // CACHE'LEME. Aksi halde login sayfasında oluşan boş cache, giris.php'deki
        // session_regenerate_id(true) ile giriş sonrası oturuma taşınıp DB'deki
        // ayarları maskeler → renk/ayar "çıkış-girişte kayboluyor" bug'ı.
        return [];
    }
    $out = [];
    global $dbh;
    try {
        $stmt = $dbh->prepare("SELECT SETTING_KEY, SETTING_VALUE FROM M_USER_SETTINGS WHERE USER_CODE = :c");
        $stmt->execute([':c' => $kod]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['SETTING_KEY']] = (string) ($r['SETTING_VALUE'] ?? '');
        }
    } catch (Throwable $e) {
        error_log('kisisel_ayarlar: ' . $e->getMessage());
    }
    $_SESSION['_kis_ayar'] = $out;
    return $out;
}

/** Tek bir kişisel ayarı döndürür (yoksa $def). */
function kisisel_ayar(string $key, string $def = ''): string
{
    $a = kisisel_ayarlar();
    return array_key_exists($key, $a) ? $a[$key] : $def;
}

/**
 * Kullanıcının seçili vurgu rengi anahtarını döndürür ('' = seçilmemiş/geçersiz).
 */
function tema_vurgu_key(): string
{
    $v = kisisel_ayar('tema_vurgu');
    return array_key_exists($v, tema_vurgu_paleti()) ? $v : '';
}

/**
 * Seçili vurgu renginin [ana, soft] hex çiftini döndürür; seçilmemişse null.
 * @return array{0:string,1:string}|null
 */
function tema_vurgu_renk(): ?array
{
    $key = tema_vurgu_key();
    if ($key === '') {
        return null;
    }
    return tema_vurgu_paleti()[$key] ?? null;
}

/**
 * Açılış ekranı anahtarını gerçek sayfa yoluna çevirir (whitelist — güvenli
 * yönlendirme). '' veya bilinmeyen → '' (yönlendirme yok, ana sayfada kal).
 */
function tema_acilis_hedef(string $key): string
{
    $harita = [
        'siparisler' => 'siparis/lg_essiparis.php',
        'stok'       => 'stok/stok_tara.php',
        'bekleyen'   => 'siparis/bekleyen_siparis.php',
    ];
    return $harita[$key] ?? '';
}

// ================================================================
// GÜVENLIK: Şifre Hash Fonksiyonları
// ================================================================

/**
 * Şifreyi güvenli şekilde hashle
 * @param string $password Düz metin şifre
 * @return string Hash'lenmiş şifre
 */
function sifre_hashle(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Şifreyi doğrula
 * @param string $password Düz metin şifre
 * @param string $hash Hash'lenmiş şifre
 * @return bool Şifre doğru mu
 */
function sifre_dogrula(string $password, string $hash): bool
{
    // Bos veya sentinel ('0') parola/hash kabul edilmez (auth bypass koruma)
    // Legacy duz-metin satirlarda SIFRE = '0' veya '' olabilir; bu degerlerle giris kapatilir
    $passwordNormalized = trim($password);
    $hashNormalized     = trim($hash);
    if ($passwordNormalized === '' || $passwordNormalized === '0'
        || $hashNormalized === ''  || $hashNormalized === '0') {
        return false;
    }

    // Eski düz metin şifrelerle geriye uyumluluk
    // Hash '$' ile başlamıyorsa düz metin olarak karşılaştır
    if (!str_starts_with($hash, '$')) {
        return hash_equals((string)$hash, (string)$password);
    }

    return password_verify($password, $hash);
}

/**
 * Şifrenin hash'lenmesi gerekip gerekmediğini kontrol et
 * @param string $hash Mevcut hash/şifre
 * @return bool Yeniden hash'lenmeli mi
 */
function sifre_yenilenmeli(string $hash): bool
{
    // Düz metin şifre (hash değil)
    if (!str_starts_with($hash, '$')) {
        return true;
    }

    return password_needs_rehash($hash, PASSWORD_DEFAULT);
}

// ================================================================
// REMEMBER ME TOKEN FONKSIYONLARI
// Cookie'de sifre yerine guvenli token kullanilir
// ================================================================

/**
 * Yeni remember token olustur
 * @param int $personelId Personel ID
 * @param int $gunSayisi Token gecerlilik suresi (varsayilan 30 gun)
 * @return array|false Basarili ise ['selector' => ..., 'token' => ...], hata durumunda false
 */
function remember_token_olustur(int $personelId, int $gunSayisi = 30)
{
    global $dbh;

    try {
        // Selector: Cookie'de saklanacak, token bulmak icin kullanilir (32 karakter hex)
        $selector = bin2hex(random_bytes(16));

        // Validator: Cookie'de saklanacak, dogrulama icin kullanilir (32 karakter hex)
        $validator = bin2hex(random_bytes(32));

        // Validator'un hash'i veritabaninda saklanir (guvenlik icin)
        $tokenHash = hash('sha256', $validator);

        // Token bitis tarihi
        $expiresAt = date('Y-m-d H:i:s', time() + (86400 * $gunSayisi));

        // IP ve User Agent bilgileri
        $ipAddress = function_exists('getUserIP') ? getUserIP() : ($_SERVER['REMOTE_ADDR'] ?? '');
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        // Veritabanina kaydet
        $stmt = $dbh->prepare("
            INSERT INTO M_REMEMBER_TOKEN
            (PERSONEL_ID, TOKEN_HASH, SELECTOR, EXPIRES_AT, IP_ADDRESS, USER_AGENT)
            VALUES (:personel_id, :token_hash, :selector, :expires_at, :ip_address, :user_agent)
        ");
        $stmt->execute([
            ':personel_id' => $personelId,
            ':token_hash' => $tokenHash,
            ':selector' => $selector,
            ':expires_at' => $expiresAt,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent
        ]);

        return [
            'selector' => $selector,
            'token' => $validator
        ];
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Remember token dogrula
 * @param string $selector Token selector
 * @param string $validator Token validator
 * @return int|false Basarili ise personel ID, gecersiz ise false
 */
function remember_token_dogrula(string $selector, string $validator)
{
    global $dbh;

    try {
        // Selector ile token bul
        $stmt = $dbh->prepare("
            SELECT ID, PERSONEL_ID, TOKEN_HASH, EXPIRES_AT
            FROM M_REMEMBER_TOKEN
            WHERE SELECTOR = :selector AND EXPIRES_AT > GETDATE()
        ");
        $stmt->execute([':selector' => $selector]);
        $token = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$token) {
            return false;
        }

        // Validator hash'ini kontrol et (timing-safe comparison)
        $expectedHash = $token['TOKEN_HASH'];
        $actualHash = hash('sha256', $validator);

        if (!hash_equals($expectedHash, $actualHash)) {
            return false;
        }

        return (int)$token['PERSONEL_ID'];
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Personelin tum tokenlarini sil (cikis yaptiginda)
 * @param int $personelId Personel ID
 * @return bool Basarili mi
 */
function remember_token_sil_personel(int $personelId): bool
{
    global $dbh;

    try {
        $stmt = $dbh->prepare("DELETE FROM M_REMEMBER_TOKEN WHERE PERSONEL_ID = :personel_id");
        $stmt->execute([':personel_id' => $personelId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Belirli bir token'i sil (selector ile)
 * @param string $selector Token selector
 * @return bool Basarili mi
 */
function remember_token_sil(string $selector): bool
{
    global $dbh;

    try {
        $stmt = $dbh->prepare("DELETE FROM M_REMEMBER_TOKEN WHERE SELECTOR = :selector");
        $stmt->execute([':selector' => $selector]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Suresi dolmus tokenlari temizle
 * @return int Silinen token sayisi
 */
function remember_token_temizle(): int
{
    global $dbh;

    try {
        $stmt = $dbh->prepare("DELETE FROM M_REMEMBER_TOKEN WHERE EXPIRES_AT < GETDATE()");
        $stmt->execute();
        return $stmt->rowCount();
    } catch (Exception $e) {
        return 0;
    }
}

// Uygulama kök URL'si
if (!defined('APP_ROOT_URL')) {
  define('APP_ROOT_URL', '');
}
// Global CSS/JS includes - artık doğrudan echo yapmıyoruz
// Her sayfa kendi head bölümünde bu dosyaları dahil etmeli
// Bu satırlar kaldırıldı çünkü header() redirect'i engelliyordu
include_once(__DIR__ . "/_baglanti_.inc");
include_once(__DIR__ . "/_bilgi_.inc");

// ================================================================
// GÜVENLIK: Veritabanı Bağlantısı (İyileştirilmiş Error Handling)
// ================================================================
try {
  $SET = 'UTF-8';
  $dbh = new PDO("sqlsrv:server=$anamakina;database=$veritabani;", $kullanici, $sifre);

  // Güvenlik: PDO ayarları
  $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  // NOT: ATTR_EMULATE_PREPARES ve ATTR_DEFAULT_FETCH_MODE
  // SQL Server driver'da desteklenmeyebilir
  // Sadece temel güvenlik ayarını yapıyoruz

} catch (PDOException $e) {
  // Güvenlik: Hassas bilgi sızmasını önle
  error_log("Veritabanı Bağlantı Hatası: " . $e->getMessage());

  // Kullanıcıya genel hata mesajı göster (detay verme!)
  // Geçici olarak hata detayını göster (geliştirme için)
  // Üretim ortamında bu satırı kaldırın veya DEBUG_MODE kontrolü yapın
  // Debug modu kapatıldı - güvenlik için
  // Sadece sunucu ortam değişkeni ile aktifleştirilebilir
  if (getenv('DEBUG_MODE') === 'true') {
    die("Veritabanı Bağlantı Hatası: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') .
        "<br><br>Sunucu: " . htmlspecialchars($anamakina ?? 'N/A', ENT_QUOTES, 'UTF-8') .
        "<br>Veritabanı: " . htmlspecialchars($veritabani ?? 'N/A', ENT_QUOTES, 'UTF-8') .
        "<br>Kullanıcı: " . htmlspecialchars($kullanici ?? 'N/A', ENT_QUOTES, 'UTF-8'));
  }
  die("Veritabanı bağlantısı kurulamadı. Lütfen sistem yöneticisine başvurun.");
}
/*try{

$dbh = new PDO("odbc:logodbc", 'logo', 'logo');

}catch (PDOException $e) {
echo "büyük hata veri güvenliği için aynı işlemi tekrarlamayın: " . $e->getMessage();
die();
}*/


// SQL Server için charset ayarları (MySQL'den farklı)
// SQL Server'da SET NAMES komutu yok, bu ayarlar connection string'de yapılmalı
// Mevcut ayarları koruyoruz
ini_set('mssql.charset', 'TURKISH_CI_AS');


$tarih = date("Y.m.d");
$saat = date("H");
$dakika = date("i");
$saniye = date("s");

$xsaat = date('H');
$xdakika = date('i');
$xsaniye = date('s');
$kayitsaat = ($xsaniye * 256) + ($xdakika * 65536) + ($xsaat * 16777216);

function tlgoster(float|int|string|null $kusurat): string
{
  if ($kusurat === "" || $kusurat === null) {
    $kusurat = 0;
  }
  // 5 ondalık basamak, ondalık ayıracı nokta, binlik ayıracı boş
  return number_format((float)$kusurat, 5, '.', '');
}

function kusuratpara(float|int|string|null $kusurat): string
{
  if ($kusurat === "" || $kusurat === null) {
    $kusurat = 0;
  }
  // 5 ondalık basamak, ondalık ayıracı nokta, binlik ayıracı boş
  return number_format((float)$kusurat, 5, '.', '');
}

/**
 * Para formatı - Türk formatında (virgül ondalık, nokta binlik ayracı)
 * Kullanım: echo paraformat($tutar);
 *
 * @param mixed $kusurat Formatlanacak tutar
 * @return string Formatlanmış tutar
 */
if (!function_exists('paraformat')) {
  function paraformat(float|int|string|null $kusurat): string
  {
    global $parakusurat;
    if ($kusurat === "" || is_null($kusurat)) {
      $kusurat = 0;
    }
    // $parakusurat _bilgi_.inc'den gelir, varsayılan 2
    $ondalik = $parakusurat ?? 2;
    return number_format((float)$kusurat, $ondalik, ',', '.');
  }
}


/**
 * Logo veritabanından gelen Türkçe karakterleri düzeltir
 * Latin-1 olarak yorumlanan Windows-1254 karakterlerini UTF-8'e çevirir
 *
 * @param string|null $text Dönüştürülecek metin
 * @return string UTF-8 formatında metin
 */
function tr($text): string
{
  if ($text === null || $text === '') {
    return '';
  }
  // Türkçe karakter eşleştirmesi (Latin-1 -> UTF-8)
  $replacements = [
    'Ð' => 'Ğ',  // 0xD0
    'ð' => 'ğ',  // 0xF0
    'Þ' => 'Ş',  // 0xDE
    'þ' => 'ş',  // 0xFE
    'Ý' => 'İ',  // 0xDD
    'ý' => 'ı',  // 0xFD
    'Ü' => 'Ü',  // 0xDC (aynı kalır)
    'ü' => 'ü',  // 0xFC (aynı kalır)
    'Ö' => 'Ö',  // 0xD6 (aynı kalır)
    'ö' => 'ö',  // 0xF6 (aynı kalır)
    'Ç' => 'Ç',  // 0xC7 (aynı kalır)
    'ç' => 'ç',  // 0xE7 (aynı kalır)
  ];
  return str_replace(array_keys($replacements), array_values($replacements), $text);
}

// Kullanılmayan fonksiyon temizlendi: tr_db

function kusuratadet(float|int|string|null $kusurata): string
{
  global $adetkusurat;
  if ($kusurata == "") {
    $kusurata = 0;
  }
  $precision = isset($adetkusurat) ? (int)$adetkusurat : 0;
  return str_replace(',', '', number_format((float)$kusurata, $precision));
}
function kusuratsifir(float|int|string|null $kusurata): string
{
  //return number_format($kusurata,0);
  return str_replace(',', '', number_format((float)$kusurata, 0));
}
// intcevir ve flcevir - 39+ dosyada kullanılıyor, geri eklendi
function intcevir(mixed $deger): int
{
  return (int)$deger;
}

function flcevir(mixed $deger): float
{
  return (float)$deger;
}

function virgul(string|int|float|null $virgul): string
{

  return str_replace(",", ".", (string)$virgul);
}

function trcevir(mixed $bilgi): string
{
  // Logo veritabanından gelen Türkçe karakterleri düzelt
  return tr((string)$bilgi);
}

/**
 * Türkçe karakter düzeltmesi + HTML encode (XSS koruması)
 * trcevir() yerine HTML çıktılarda bu fonksiyonu kullanın.
 */
function e_tr(mixed $bilgi): string
{
  return htmlspecialchars(trcevir((string)$bilgi), ENT_QUOTES, 'UTF-8');
}
function tarihcevir(string|int|float|null $tarih): string
{
  if ($tarih === null || $tarih === '' || $tarih === 0) {
    return '';
  }
  $ts = strtotime((string)$tarih);
  return $ts ? date('d.m.Y', $ts) : '';
}
// Kullanılmayan fonksiyon temizlendi: cari_mail_bul
function cari_bul(int|string $cariid): string
{
  global $dbh;
  global $firma;

  $cariid = (int)$cariid;
  if (isset($GLOBALS['_cache_cari_bul'][$cariid])) {
    return $GLOBALS['_cache_cari_bul'][$cariid];
  }

  try {
    $stmt = $dbh->prepare("SELECT CODE, DEFINITION_ FROM " . $firma . "CLCARD WHERE LOGICALREF = :cariid");
    $stmt->bindParam(':cariid', $cariid, PDO::PARAM_INT);
    $stmt->execute();

    $bul = $stmt->fetch(PDO::FETCH_ASSOC);
    $sonuc = $bul ? (string)$bul['DEFINITION_'] : '';
    $GLOBALS['_cache_cari_bul'][$cariid] = $sonuc;
    return $sonuc;

  } catch (PDOException $e) {
    error_log("cari_bul hatası: " . $e->getMessage());
    return '';
  }
}
// Kullanılmayan fonksiyon temizlendi: odemegrup_bul
function doviz_bul(string|int|float|null $doviz): float
{
  global $dbh;

  $doviz = trim((string)$doviz);
  if ($doviz === '' || $doviz === '0') {
    return 0.0;
  }

  if (isset($GLOBALS['_cache_doviz_bul'][$doviz])) {
    return $GLOBALS['_cache_doviz_bul'][$doviz];
  }

  $stmt = $dbh->prepare("SELECT RATES1 FROM L_DAILYEXCHANGES WHERE CRTYPE=:doviz ORDER BY LREF DESC");
  $stmt->bindParam(':doviz', $doviz, PDO::PARAM_STR);
  $stmt->execute();
  $bul = $stmt->fetch(PDO::FETCH_ASSOC);

  $sonuc = isset($bul['RATES1']) ? (float)$bul['RATES1'] : 0.0;
  $GLOBALS['_cache_doviz_bul'][$doviz] = $sonuc;
  return $sonuc;
}
function dovizkuru_bul(int|string $stokhareket): float
{
  global $dbh;
  global $firmadonem;

  // Güvenlik: Prepared statement ve validasyon
  $stokhareket = (int)$stokhareket;
  if ($stokhareket <= 0) {
    return 0.0;
  }

  $stmt = $dbh->prepare("SELECT TRRATE FROM " . $firmadonem . "ORFICHE WHERE LOGICALREF=:stokhareket");
  $stmt->bindParam(':stokhareket', $stokhareket, PDO::PARAM_INT);
  $stmt->execute();
  $bul = $stmt->fetch(PDO::FETCH_ASSOC);

  return isset($bul['TRRATE']) && is_numeric($bul['TRRATE']) ? (float)$bul['TRRATE'] : 0.0;
}
// dovizsembol_bul - birden fazla dosyada kullanılıyor
function dovizsembol_bul(int|string $doviz): string
{
  // LOGO ERP L_CURRENCYLIST tablosundaki CURTYPE değerleri
  $semboller = [
    0 => '₺',   // TL (yerel para - döviz yok)
    1 => '$',   // ABD Doları (USD)
    20 => '€',  // Euro (EUR)
    17 => '£',  // İngiliz Sterlini (GBP)
    53 => '₺',  // Türk Lirası
    160 => '₺', // Türk Lirası (yeni)
  ];
  return $semboller[(int)$doviz] ?? '₺';
}

// guid - birden fazla dosyada kullanılıyor
function guid(): string
{
  if (function_exists('com_create_guid')) {
    return com_create_guid();
  }
  $data = random_bytes(16);
  $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
  $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function turkce(string|int|float|null $text): string
{

  $search = ['Ç', 'ç', 'Ğ', 'ğ', 'ı', 'İ', 'Ö', 'ö', 'Ş', 'ş', 'Ü', 'ü'];
  $replace = ['c', 'c', 'g', 'g', 'i', 'i', 'o', 'o', 's', 's', 'u', 'u'];
  return str_replace($search, $replace, (string)$text);
}
function turkcearama(string|int|float|null $text): string
{

  $search = ['i', 'c', 'u', 's', 'g', 'o', 'İ', 'C', 'U', 'S', 'G', 'O'];
  $replace = ['[ıi]', '[cç]', '[uü]', '[sş]', '[gğ]', '[oö]', '[Iİ]', '[CÇ]', '[UÜ]', '[SŞ]', '[GĞ]', '[OÖ]'];
  return str_replace($search, $replace, (string)$text);
}

// ================================================================
// KAR-ZARAR ANALİZİ FONKSİYONLARI
// ================================================================

/**
 * SQLite Enflasyon Veritabanı Bağlantısı
 * @return PDO
 */
function enflasyon_db_baglan(): ?PDO
{
  $db_path = __DIR__ . '/database/enflasyon.db';
  try {
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
  } catch (PDOException $e) {
    error_log("Enflasyon DB Hata: " . $e->getMessage());
    return null;
  }
}

/**
 * İki tarih arasındaki kümülatif enflasyon oranını hesaplar
 *
 * @param string $baslangic_tarih Başlangıç tarihi (Y-m-d formatında)
 * @param int $vade_gun Vade günü sayısı
 * @return array ['oran' => float, 'aylik_detay' => array]
 */
function enflasyon_hesapla(string $baslangic_tarih, int|string $vade_gun): array
{
  $vade_gun = (int)$vade_gun;
  if ($vade_gun <= 0) {
    return ['oran' => 0, 'aylik_detay' => []];
  }

  $db = enflasyon_db_baglan();
  if (!$db instanceof \PDO) {
    return ['oran' => 0, 'aylik_detay' => [], 'hata' => 'Veritabanı bağlantısı kurulamadı'];
  }

  // Bitiş tarihini hesapla
  $baslangic = new DateTime($baslangic_tarih);
  $bitis = clone $baslangic;
  $bitis->modify("+{$vade_gun} days");

  // Ay bazında enflasyon oranlarını çek
  $stmt = $db->prepare("
        SELECT yil, ay, oran
        FROM enflasyon_aylik
        WHERE (yil * 100 + ay) BETWEEN :bas_yil_ay AND :bit_yil_ay
        ORDER BY yil, ay
    ");

  $bas_yil_ay = (int)($baslangic->format('Y') * 100 + $baslangic->format('m'));
  $bit_yil_ay = (int)($bitis->format('Y') * 100 + $bitis->format('m'));

  $stmt->execute([':bas_yil_ay' => $bas_yil_ay, ':bit_yil_ay' => $bit_yil_ay]);
  $aylik_veriler = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (empty($aylik_veriler)) {
    return ['oran' => 0, 'aylik_detay' => [], 'hata' => 'Enflasyon verisi bulunamadı'];
  }

  // Bileşik enflasyon hesaplama: (1+r1)*(1+r2)*(1+r3)... - 1
  $carpim = 1.0;
  $aylik_detay = [];

  foreach ($aylik_veriler as $veri) {
    $oran_desimal = $veri['oran'] / 100;
    $carpim *= (1 + $oran_desimal);
    $aylik_detay[] = ['ay' => sprintf('%04d-%02d', $veri['yil'], $veri['ay']), 'oran' => $veri['oran']];
  }

  $kumulatif_oran = ($carpim - 1) * 100;

  return ['oran' => round($kumulatif_oran, 4), 'aylik_detay' => $aylik_detay, 'ay_sayisi' => count($aylik_veriler)];
}

/**
 * Ürünün son alış fiyatını STLINE tablosundan getirir
 *
 * @param int $stok_id Stok LOGICALREF
 * @return float Son alış fiyatı
 */
function son_alis_fiyati(int|string $stok_id): float
{
  global $dbh, $firmadonem;

  try {
    $stok_id = (int)$stok_id;
    $stmt = $dbh->prepare("
            SELECT TOP 1 S.PRICE
            FROM {$firmadonem}STLINE S
            WHERE S.STOCKREF = :stok_id
              AND S.LINETYPE = 0
              AND S.CANCELLED = 0
              AND S.TRCODE IN (1, 14)
            ORDER BY S.DATE_ DESC
        ");
    $stmt->bindParam(':stok_id', $stok_id, PDO::PARAM_INT);
    $stmt->execute();

    $sonuc = $stmt->fetch(PDO::FETCH_ASSOC);
    return $sonuc ? (float)$sonuc['PRICE'] : 0.0;
  } catch (PDOException $e) {
    error_log("son_alis_fiyati hatası: " . $e->getMessage());
    return 0.0;
  }
}

/**
 * ORFICHE'den vade gün sayısını hesaplar
 * Not: Logo veritabanında vade bilgisi tablolarda farklı olabilir
 * Bu fonksiyon varsayılan olarak 90 gün vade kullanır
 *
 * @param int $siparis_ref ORFICHE LOGICALREF
 * @param int $varsayilan_vade Varsayılan vade gün sayısı (default: 90)
 * @return array ['vade_gun' => int, 'vade_tarihi' => string]
 */
function vade_gun_hesapla(int|string $siparis_ref, int $varsayilan_vade = 90): array
{
  global $dbh, $firmadonem;

  try {
    $siparis_ref = (int)$siparis_ref;
    $stmt = $dbh->prepare("
            SELECT DATE_
            FROM {$firmadonem}ORFICHE
            WHERE LOGICALREF = :ref
        ");
    $stmt->bindParam(':ref', $siparis_ref, PDO::PARAM_INT);
    $stmt->execute();

    $sonuc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sonuc) {
      return ['vade_gun' => $varsayilan_vade, 'vade_tarihi' => null];
    }

    $siparis_tarih = new DateTime($sonuc['DATE_']);
    $vade_tarih = clone $siparis_tarih;
    $vade_tarih->modify("+{$varsayilan_vade} days");

    return ['vade_gun' => $varsayilan_vade, 'vade_tarihi' => $vade_tarih->format('Y-m-d')];
  } catch (Exception $e) {
    error_log("vade_gun_hesapla hatası: " . $e->getMessage());
    return ['vade_gun' => $varsayilan_vade, 'vade_tarihi' => null];
  }
}

/**
 * Gerçek net karı hesaplar (enflasyon etkisi dahil)
 *
 * @param float $satis_fiyat Net satış fiyatı (iskonto sonrası)
 * @param float $maliyet Son alış maliyeti
 * @param int $vade_gun Vade gün sayısı
 * @param string $tarih Satış tarihi
 * @return array Detaylı kar-zarar analizi
 */
function net_kar_hesapla(float|int|string|null $satis_fiyat, float|int|string|null $maliyet, int|string $vade_gun, string $tarih): array
{
  $satis_fiyat = (float)$satis_fiyat;
  $maliyet = (float)$maliyet;
  $vade_gun = (int)$vade_gun;
  // Brüt kar
  $brut_kar = $satis_fiyat - $maliyet;
  $brut_kar_yuzde = $maliyet > 0 ? ($brut_kar / $maliyet) * 100 : 0;

  // Enflasyon etkisi
  $enflasyon = enflasyon_hesapla($tarih, $vade_gun);
  $enflasyon_oran = $enflasyon['oran'];
  $enflasyon_maliyet = ($satis_fiyat * $enflasyon_oran) / 100;

  // Net kar
  $net_kar = $brut_kar - $enflasyon_maliyet;
  $net_kar_yuzde = $maliyet > 0 ? ($net_kar / $maliyet) * 100 : 0;

  // Durum belirleme
  $durum = 'basabas';
  $durum_emoji = '🟡';
  if ($net_kar > 0) {
    $durum = 'karli';
    $durum_emoji = '🟢';
  } elseif ($net_kar < 0) {
    $durum = 'zararli';
    $durum_emoji = '🔴';
  }

  return ['brut_kar' => round($brut_kar, 2), 'brut_kar_yuzde' => round($brut_kar_yuzde, 2), 'enflasyon_oran' => round($enflasyon_oran, 2), 'enflasyon_maliyet' => round($enflasyon_maliyet, 2), 'net_kar' => round($net_kar, 2), 'net_kar_yuzde' => round($net_kar_yuzde, 2), 'durum' => $durum, 'durum_emoji' => $durum_emoji, 'aylik_detay' => $enflasyon['aylik_detay']];
}

function bekleyen_siparis(int|string $kodu): float
{
  global $dbh;
  global $firmadonem;

  try {
    $kodu = (int)$kodu;
    $stmt = $dbh->prepare("SELECT SUM(FS.AMOUNT) AS 'TOPLAM' FROM " . $firmadonem . "ORFLINE FS WHERE FS.STOCKREF = :kodu AND FS.TRCODE = '1' AND FS.STATUS <> 2 AND CASE FS.CLOSED WHEN 0 THEN (FS.AMOUNT-FS.SHIPPEDAMOUNT) WHEN 1 THEN (FS.AMOUNT-FS.AMOUNT) END > 0");
    $stmt->bindParam(':kodu', $kodu, PDO::PARAM_INT);
    $stmt->execute();

    $bul = $stmt->fetch(PDO::FETCH_ASSOC);
    return $bul && isset($bul['TOPLAM']) ? (float)$bul['TOPLAM'] : 0.0;

  } catch (PDOException $e) {
    error_log("bekleyen_siparis hatası: " . $e->getMessage());
    return 0.0;
  }
}

function birim_bul(int|string $stok_id): array
{
  global $dbh;
  global $firma;

  $stok_id = (int)$stok_id;
  if (isset($GLOBALS['_cache_birim_bul'][$stok_id])) {
    return $GLOBALS['_cache_birim_bul'][$stok_id];
  }

  try {
    $stmt = $dbh->prepare("SELECT B.LOGICALREF, B.UNITSETREF FROM " . $firma . "ITEMS S LEFT JOIN " . $firma . "UNITSETL B ON S.UNITSETREF = B.UNITSETREF WHERE B.LINENR = 1 AND S.LOGICALREF = :stok_id");
    $stmt->bindParam(':stok_id', $stok_id, PDO::PARAM_INT);
    $stmt->execute();

    $birim_bul = $stmt->fetch(PDO::FETCH_ASSOC);
    $sonuc = $birim_bul ? [$birim_bul['LOGICALREF'], $birim_bul['UNITSETREF']] : [0, 0];
    $GLOBALS['_cache_birim_bul'][$stok_id] = $sonuc;
    return $sonuc;

  } catch (PDOException $e) {
    error_log("birim_bul hatası: " . $e->getMessage());
    return [0, 0];
  }
}
function tanimlialantoplam(int|string $stok_id): array
{
  global $dbh;
  global $firma;

  try {
    $stok_id = (int)$stok_id;
    $stmt = $dbh->prepare("SELECT TEXTFLDS1, TEXTFLDS2, TEXTFLDS3 FROM " . $firma . "DEFNFLDSCARDV WHERE PARENTREF = :stok_id AND MODULENR = 6");
    $stmt->bindParam(':stok_id', $stok_id, PDO::PARAM_INT);
    $stmt->execute();

    $tanim = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tanim) {
      return [str_replace(",", ".", $tanim['TEXTFLDS1']), str_replace(",", ".", $tanim['TEXTFLDS2']), str_replace(",", ".", $tanim['TEXTFLDS3'])];
    }
    return ['', '', ''];

  } catch (PDOException $e) {
    error_log("tanimlialantoplam hatası: " . $e->getMessage());
    return ['', '', ''];
  }
}
function bosluksil(mixed $veri): string
{

  $veri = str_replace(" ", "", (string)$veri);
  return trim($veri);
};
function iskontooran(float|int|string|null $fiyati, float|int|string|null $iskontolu, string $durum): float
{
  $fiyati = (float)$fiyati;
  $iskontolu = (float)$iskontolu;
  if ($durum === "+") {
      return ($fiyati + (($fiyati * $iskontolu) / 100));
  }
  if ($durum === "-") {
    return ($fiyati - (($fiyati * $iskontolu) / 100));;
  }
  return $fiyati;
}

function stok_miktar_bul(int|string $id): float
{
  global $dbh;
  global $firmadonemx;

  $id = (int)$id;
  if (isset($GLOBALS['_cache_stok_miktar'][$id])) {
    return $GLOBALS['_cache_stok_miktar'][$id];
  }

  try {
    $stmt = $dbh->prepare("SELECT SUM(ONHAND) AS 'MIKTAR' FROM " . $firmadonemx . "STINVTOT WHERE STOCKREF = :id AND INVENNO = -1");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();

    $bul = $stmt->fetch(PDO::FETCH_ASSOC);
    $sonuc = $bul && isset($bul['MIKTAR']) ? (float)$bul['MIKTAR'] : 0.0;
    $GLOBALS['_cache_stok_miktar'][$id] = $sonuc;
    return $sonuc;

  } catch (PDOException $e) {
    error_log("stok_miktar_bul hatası: " . $e->getMessage());
    return 0.0;
  }
}


if (!function_exists('m_p_yetki_tum_sutunlar')) {
  /**
   * M_P_YETKI tablosunda kullanılan tüm güvenli sütunlar.
   */
  function m_p_yetki_tum_sutunlar(): array
  {
    return [
      'YETKI', 'SIFRE', 'PERSONEL',
      'M1', 'M2', 'M3', 'M4', 'M5', 'M6', 'M7', 'M8', 'M9', 'M10', 'M11', 'M12', 'M13', 'M14', 'M15', 'M16', 'M17', 'M18', 'M19', 'M20', 'M21', 'M22', 'M23', 'M24', 'M25', 'M26', 'M27', 'M28', 'M29', 'M30',
      'ST1', 'ST2', 'ST3',
      'CR1', 'CR2', 'CR3', 'CR4',
      'SP1', 'SP2', 'SP3', 'SP4',
    ];
  }
}

if (!function_exists('m_p_menu_yetki_kodlari')) {
  /**
   * Menü seviyesindeki M* yetkileri.
   */
  function m_p_menu_yetki_kodlari(): array
  {
    return [
      'M1', 'M2', 'M3', 'M4', 'M5', 'M6', 'M7', 'M8', 'M9', 'M10',
      'M11', 'M12', 'M13', 'M14', 'M15', 'M16', 'M17', 'M18', 'M19',
      'M20', 'M21', 'M22', 'M23', 'M24', 'M25', 'M26', 'M27', 'M28', 'M29', 'M30',
    ];
  }
}

if (!function_exists('m_p_yetki_kodlarindan_birine_sahip_mi')) {
  /**
   * Verilen yetki kodlarından en az birine sahip mi?
   */
  function m_p_yetki_kodlarindan_birine_sahip_mi(int|string $id, array $kodlar): bool
  {
    foreach ($kodlar as $kod) {
      if ((int) (m_p_yetki($id, (string) $kod) ?? 0) === 1) {
        return true;
      }
    }

    return false;
  }
}

if (!function_exists('m_p_bakiye_erisim_kodlari')) {
  /**
   * Tek bakiye ekranına erişim veren kodlar.
   */
  function m_p_bakiye_erisim_kodlari(): array
  {
    return ['M4', 'M20'];
  }
}

if (!function_exists('m_p_bakiye_erisim_var_mi')) {
  /**
   * Kullanıcı tek bakiye ekranına girebilir mi?
   */
  function m_p_bakiye_erisim_var_mi(int|string $id): bool
  {
    return m_p_yetki_kodlarindan_birine_sahip_mi($id, m_p_bakiye_erisim_kodlari());
  }
}

if (!function_exists('m_p_yetki_etiketleri')) {
  /**
   * Teşhis ve yönetim ekranlarında ortak kullanılacak yetki etiketleri.
   */
  function m_p_yetki_etiketleri(): array
  {
    return [
      'YETKI' => 'Yetki Türü (0=Yönetici, 1=Personel, 2=Müşteri)',
      'M1' => 'Yeni Sipariş',
      'M2' => 'Siparişler',
      'M3' => 'Mağaza Satış',
      'M4' => 'Müşteri Bakiye',
      'M5' => 'Tüm Siparişler',
      'M6' => 'Barkod Oluştur',
      'M7' => 'Stok Ara',
      'M8' => 'Bekleyen Ürünler',
      'M9' => 'Ambar',
      'M10' => 'Sil',
      'M11' => 'Yeni Stok',
      'M12' => 'Yeni Cari',
      'M13' => 'Günlük İşlemler',
      'M14' => 'Hızlı Erişim',
      'M15' => 'Stoklar',
      'M16' => 'Ayarlar',
      'M17' => 'Raporlar',
      'M18' => 'Değişiklik Geçmişi / Loglar',
      'M19' => 'Özel Cari Görme / Sipariş Kısıtı',
      'M20' => 'Müşteri Bakiye (Eski Yetki)',
      'M21' => 'Döviz İşlemleri',
      'M22' => 'Yazdırma Geçmişi',
      'M23' => 'Kullanıcı Aktivite',
      'M24' => 'Kasa Özeti (Mağaza Satış)',
      'M25' => 'Dosya Portalı',
      'M26' => 'Fiyat Listesi',
      'M27' => 'Ithalat Modulu',
      'M28' => 'Görevler',
      'M29' => 'Görev Atama',
      'M30' => 'Çek İşlemleri',
      'ST1' => 'Miktar Görme',
      'ST2' => 'Fiyat Görme',
      'ST3' => 'Stok Extre',
      'CR1' => 'Cari Bakiye',
      'CR2' => 'Cari Extre',
      'CR3' => 'İletişim Bilgileri',
      'CR4' => 'Özel Cari Bakiyesi',
      'SP1' => 'Sipariş Fiyat Değiştirme',
      'SP2' => 'Son Alış Fiyatı Görme',
      'SP3' => 'Son Satış Fiyatı Görme',
      'SP4' => 'Bekleyen Miktar Görme',
    ];
  }
}

if (!function_exists('m_p_ozel_cari_goruntuleme_yetki_kodu')) {
  /**
   * Özel cari gruplarını görüntüleme yetkisi.
   */
  function m_p_ozel_cari_goruntuleme_yetki_kodu(): string
  {
    return 'M19';
  }
}

if (!function_exists('m_p_ozel_cari_kisit_yetki_kodlari')) {
  /**
   * Özel cari kısıt ekranında yönetilen yetki kapsamları.
   *
   * M4  : Bakiye tarafında mutlak gizleme
   * M19 : Sipariş / özel cari tarafında görünürlük izni
   */
  function m_p_ozel_cari_kisit_yetki_kodlari(): array
  {
    return ['M4', 'M19'];
  }
}

if (!function_exists('m_p_ozel_cari_kisit_yetki_kodu_normalize')) {
  /**
   * Yetki kapsam kodunu normalize eder.
   */
  function m_p_ozel_cari_kisit_yetki_kodu_normalize(?string $yetkiKodu, bool $fallbackToDefault = true): string
  {
    $normalized = strtoupper(trim((string) $yetkiKodu));
    if ($normalized !== '' && in_array($normalized, m_p_ozel_cari_kisit_yetki_kodlari(), true)) {
      return $normalized;
    }

    return $fallbackToDefault ? m_p_ozel_cari_goruntuleme_yetki_kodu() : '';
  }
}

if (!function_exists('m_p_ozel_cari_kisit_yetki_bypass_var_mi')) {
  /**
   * Belirli bir özel cari kapsamı için kullanıcı bypass hakkına sahip mi?
   *
   * M4: Bakiye tarafında admin hariç bypass yok.
   * M19: Özel cari yetkisi olan kullanıcılar görebilir.
   */
  function m_p_ozel_cari_kisit_yetki_bypass_var_mi(int|string $personelId, ?string $yetkiKodu = null): bool
  {
    $normalizedCode = m_p_ozel_cari_kisit_yetki_kodu_normalize($yetkiKodu);

    if ((int) (m_p_yetki($personelId, 'YETKI') ?? 2) === 0) {
      return true;
    }

    if ($normalizedCode === 'M19') {
      return (int) (m_p_yetki($personelId, $normalizedCode) ?? 0) === 1;
    }

    return false;
  }
}

if (!function_exists('m_p_ozel_cari_tam_bypass_var_mi')) {
  /**
   * Yönetici kullanıcı özel cari kısıtlarının tamamını bypass eder.
   */
  function m_p_ozel_cari_tam_bypass_var_mi(int|string $personelId): bool
  {
    return (int) (m_p_yetki($personelId, 'YETKI') ?? 2) === 0;
  }
}

if (!function_exists('m_p_ozel_cari_kod_prefixleri')) {
  /**
   * Eski prefix tabanlı yapı için geriye dönük destek.
   * Yeni yapı ayarlardan seçilen cari listesiyle çalışır.
   */
  function m_p_ozel_cari_kod_prefixleri(): array
  {
    return [];
  }
}

if (!function_exists('m_p_ozel_cari_tablo_adi')) {
  /**
   * Ayarlardan yönetilen özel cari kısıt tablosu.
   */
  function m_p_ozel_cari_tablo_adi(): string
  {
    return 'M_OZEL_CARI_KISIT';
  }
}

if (!function_exists('m_p_ozel_cari_global_personel_id')) {
  /**
   * Tüm kullanıcılara uygulanan ortak kuralın personel anahtarı.
   */
  function m_p_ozel_cari_global_personel_id(): int
  {
    return 0;
  }
}

if (!function_exists('m_p_ozel_cari_kisit_personel_normalize')) {
  /**
   * Kısıt kuralı için personel ID değerini normalize eder.
   */
  function m_p_ozel_cari_kisit_personel_normalize(int|string|null $personelId): int
  {
    $normalized = (int) $personelId;
    return $normalized > 0 ? $normalized : m_p_ozel_cari_global_personel_id();
  }
}

if (!function_exists('m_p_ozel_cari_kisit_cache_temizle')) {
  /**
   * Özel cari kısıt listesinin request cache'ini temizler.
   */
  function m_p_ozel_cari_kisit_cache_temizle(): void
  {
    unset($GLOBALS['__m_p_ozel_cari_tablosu_var'], $GLOBALS['__m_p_ozel_cari_sema_hazir']);

    foreach (array_keys($GLOBALS) as $key) {
      if (str_starts_with((string) $key, '__m_p_ozel_cari_ref_listesi_')) {
        unset($GLOBALS[$key]);
      }
    }
  }
}

if (!function_exists('m_p_ozel_cari_tablosu_var_mi')) {
  /**
   * Yönetim tablosu var mı?
   */
  function m_p_ozel_cari_tablosu_var_mi(PDO $dbh): bool
  {
    if (array_key_exists('__m_p_ozel_cari_tablosu_var', $GLOBALS)) {
      return (bool) $GLOBALS['__m_p_ozel_cari_tablosu_var'];
    }

    $stmt = $dbh->prepare("
      SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_NAME = :table_name
    ");
    $stmt->execute([':table_name' => m_p_ozel_cari_tablo_adi()]);

    $exists = (int) $stmt->fetchColumn() > 0;
    $GLOBALS['__m_p_ozel_cari_tablosu_var'] = $exists;

    return $exists;
  }
}

if (!function_exists('m_p_ozel_cari_tablo_sutunu_var_mi')) {
  /**
   * Özel cari tablosunda belirtilen sütun var mı?
   */
  function m_p_ozel_cari_tablo_sutunu_var_mi(PDO $dbh, string $columnName): bool
  {
    $stmt = $dbh->prepare("
      SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_NAME = :table_name
        AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
      ':table_name' => m_p_ozel_cari_tablo_adi(),
      ':column_name' => $columnName,
    ]);

    return (int) $stmt->fetchColumn() > 0;
  }
}

if (!function_exists('m_p_ozel_cari_tablo_yapilandir')) {
  /**
   * Eski yapıyı kullanıcı bazlı M4/M19 kapsamlı yapıya yükseltir.
   */
  function m_p_ozel_cari_tablo_yapilandir(PDO $dbh): void
  {
    if (isset($GLOBALS['__m_p_ozel_cari_sema_hazir']) && $GLOBALS['__m_p_ozel_cari_sema_hazir'] === true) {
      return;
    }

    if (!m_p_ozel_cari_tablosu_var_mi($dbh)) {
      return;
    }

    $tableName = m_p_ozel_cari_tablo_adi();
    $globalPersonelId = m_p_ozel_cari_global_personel_id();

    if (!m_p_ozel_cari_tablo_sutunu_var_mi($dbh, 'YETKI_KODU')) {
      $dbh->exec("ALTER TABLE {$tableName} ADD YETKI_KODU NVARCHAR(10) NULL");
    }
    if (!m_p_ozel_cari_tablo_sutunu_var_mi($dbh, 'PERSONEL_ID')) {
      $dbh->exec("ALTER TABLE {$tableName} ADD PERSONEL_ID INT NULL");
    }

    $dbh->exec("
      UPDATE {$tableName}
      SET YETKI_KODU = 'M19'
      WHERE ISNULL(YETKI_KODU, '') = ''
    ");
    $dbh->exec("
      UPDATE {$tableName}
      SET PERSONEL_ID = {$globalPersonelId}
      WHERE PERSONEL_ID IS NULL
    ");

    foreach (["UX_{$tableName}_CARIREF", "UX_{$tableName}_CARIREF_YETKI"] as $dropIndex) {
      $dbh->exec("
        IF EXISTS (
          SELECT 1
          FROM sys.indexes
          WHERE name = '{$dropIndex}'
            AND object_id = OBJECT_ID('{$tableName}')
        )
        BEGIN
          DROP INDEX {$dropIndex} ON {$tableName}
        END
      ");
    }

    foreach (m_p_ozel_cari_kisit_yetki_kodlari() as $yetkiKodu) {
      $safeYetkiKodu = str_replace("'", "''", $yetkiKodu);
      $dbh->exec("
        INSERT INTO {$tableName} (PERSONEL_ID, CARIREF, YETKI_KODU, OLUSTURAN)
        SELECT S.PERSONEL_ID, S.CARIREF, '{$safeYetkiKodu}', MIN(S.OLUSTURAN)
        FROM {$tableName} S
        WHERE NOT EXISTS (
          SELECT 1
          FROM {$tableName} T
          WHERE T.PERSONEL_ID = S.PERSONEL_ID
            AND T.CARIREF = S.CARIREF
            AND T.YETKI_KODU = '{$safeYetkiKodu}'
        )
        GROUP BY S.PERSONEL_ID, S.CARIREF
      ");
    }

    $newUniqueIndex = "UX_{$tableName}_PERSONEL_YETKI_CARIREF";
    $stmtIndex = $dbh->prepare("
      SELECT COUNT(*)
      FROM sys.indexes
      WHERE name = :index_name
        AND object_id = OBJECT_ID(:table_name)
    ");

    $stmtIndex->execute([
      ':index_name' => $newUniqueIndex,
      ':table_name' => $tableName,
    ]);
    if ((int) $stmtIndex->fetchColumn() === 0) {
      $dbh->exec("CREATE UNIQUE INDEX {$newUniqueIndex} ON {$tableName}(PERSONEL_ID, YETKI_KODU, CARIREF)");
    }

    $creatorIndex = "IX_{$tableName}_OLUSTURAN";
    $stmtIndex->execute([
      ':index_name' => $creatorIndex,
      ':table_name' => $tableName,
    ]);
    if ((int) $stmtIndex->fetchColumn() === 0) {
      $dbh->exec("CREATE INDEX {$creatorIndex} ON {$tableName}(OLUSTURAN)");
    }

    $personelIndex = "IX_{$tableName}_PERSONEL_ID";
    $stmtIndex->execute([
      ':index_name' => $personelIndex,
      ':table_name' => $tableName,
    ]);
    if ((int) $stmtIndex->fetchColumn() === 0) {
      $dbh->exec("CREATE INDEX {$personelIndex} ON {$tableName}(PERSONEL_ID)");
    }

    $GLOBALS['__m_p_ozel_cari_sema_hazir'] = true;
    m_p_ozel_cari_kisit_cache_temizle();
    $GLOBALS['__m_p_ozel_cari_sema_hazir'] = true;
  }
}

if (!function_exists('m_p_ozel_cari_tablosu_olustur')) {
  /**
   * Yönetilebilir özel cari kısıt tablosunu oluşturur.
   */
  function m_p_ozel_cari_tablosu_olustur(PDO $dbh): void
  {
    if (m_p_ozel_cari_tablosu_var_mi($dbh)) {
      m_p_ozel_cari_tablo_yapilandir($dbh);
      return;
    }

    $tableName = m_p_ozel_cari_tablo_adi();

    $dbh->exec("
      CREATE TABLE {$tableName} (
        LOGICALREF INT IDENTITY(1,1) PRIMARY KEY,
        PERSONEL_ID INT NOT NULL DEFAULT(0),
        CARIREF INT NOT NULL,
        YETKI_KODU NVARCHAR(10) NOT NULL,
        OLUSTURAN INT NULL,
        OLUSTURMA_TARIHI DATETIME NOT NULL DEFAULT GETDATE()
      )
    ");
    $dbh->exec("CREATE UNIQUE INDEX UX_{$tableName}_PERSONEL_YETKI_CARIREF ON {$tableName}(PERSONEL_ID, YETKI_KODU, CARIREF)");
    $dbh->exec("CREATE INDEX IX_{$tableName}_OLUSTURAN ON {$tableName}(OLUSTURAN)");
    $dbh->exec("CREATE INDEX IX_{$tableName}_PERSONEL_ID ON {$tableName}(PERSONEL_ID)");

    m_p_ozel_cari_kisit_cache_temizle();
    m_p_ozel_cari_tablo_yapilandir($dbh);
  }
}

if (!function_exists('m_p_ozel_cari_kisitli_refler_getir')) {
  /**
   * Ayarlardan gizlenen cari referanslarını getirir.
   *
   * @return int[]
   */
  function m_p_ozel_cari_kisitli_refler_getir(PDO $dbh, ?string $yetkiKodu = null, int|string|null $personelId = null, bool $globalKurallarDahil = true): array
  {
    if (!m_p_ozel_cari_tablosu_var_mi($dbh)) {
      return [];
    }

    m_p_ozel_cari_tablo_yapilandir($dbh);

    $normalizedCode = m_p_ozel_cari_kisit_yetki_kodu_normalize($yetkiKodu, false);
    $normalizedPersonelId = m_p_ozel_cari_kisit_personel_normalize($personelId);
    $cacheKey = '__m_p_ozel_cari_ref_listesi_' . ($normalizedCode !== '' ? $normalizedCode : 'ALL') . '_' . $normalizedPersonelId . '_' . ($globalKurallarDahil ? 'G1' : 'G0');

    if (isset($GLOBALS[$cacheKey]) && is_array($GLOBALS[$cacheKey])) {
      return $GLOBALS[$cacheKey];
    }

    $sql = "SELECT CARIREF FROM " . m_p_ozel_cari_tablo_adi();
    $whereClauses = [];
    $params = [];
    if ($normalizedCode !== '') {
      $whereClauses[] = "YETKI_KODU = :yetki_kodu";
      $params[':yetki_kodu'] = $normalizedCode;
    }

    $globalPersonelId = m_p_ozel_cari_global_personel_id();
    if ($normalizedPersonelId > $globalPersonelId) {
      if ($globalKurallarDahil) {
        $whereClauses[] = "PERSONEL_ID IN (:personel_global, :personel_id)";
        $params[':personel_global'] = $globalPersonelId;
        $params[':personel_id'] = $normalizedPersonelId;
      } else {
        $whereClauses[] = "PERSONEL_ID = :personel_id";
        $params[':personel_id'] = $normalizedPersonelId;
      }
    } else {
      $whereClauses[] = "PERSONEL_ID = :personel_global";
      $params[':personel_global'] = $globalPersonelId;
    }

    if ($whereClauses !== []) {
      $sql .= " WHERE " . implode(' AND ', $whereClauses);
    }

    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);
    $refs = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $cariRef = isset($row['CARIREF']) ? (int) $row['CARIREF'] : 0;
      if ($cariRef > 0) {
        $refs[] = $cariRef;
      }
    }

    $refs = array_values(array_unique($refs));
    $GLOBALS[$cacheKey] = $refs;

    return $refs;
  }
}

if (!function_exists('m_p_ozel_cari_ref_kisitli_mi')) {
  /**
   * Cari ref yönetilen kısıt listesinde mi?
   */
  function m_p_ozel_cari_ref_kisitli_mi(PDO $dbh, int $cariRef, ?string $yetkiKodu = null, int|string|null $personelId = null, bool $globalKurallarDahil = true): bool
  {
    if ($cariRef <= 0) {
      return false;
    }

    return in_array($cariRef, m_p_ozel_cari_kisitli_refler_getir($dbh, $yetkiKodu, $personelId, $globalKurallarDahil), true);
  }
}

if (!function_exists('m_p_ozel_cari_kullaniciya_ozel_yasak_var_mi')) {
  /**
   * Kullanıcıya özel yasak kaydı var mı?
   */
  function m_p_ozel_cari_kullaniciya_ozel_yasak_var_mi(PDO $dbh, int|string $personelId, int $cariRef, ?string $yetkiKodu = null): bool
  {
    $normalizedPersonelId = m_p_ozel_cari_kisit_personel_normalize($personelId);
    if ($normalizedPersonelId <= m_p_ozel_cari_global_personel_id() || $cariRef <= 0) {
      return false;
    }

    return m_p_ozel_cari_ref_kisitli_mi($dbh, $cariRef, $yetkiKodu, $normalizedPersonelId, false);
  }
}

if (!function_exists('m_p_ozel_cari_kodu_normalize')) {
  /**
   * Cari kodlarını eşleştirme için normalize eder.
   */
  function m_p_ozel_cari_kodu_normalize(?string $cariKodu): string
  {
    return strtoupper(trim((string) $cariKodu));
  }
}

if (!function_exists('m_p_ozel_cari_kisitli_mi')) {
  /**
   * Cari kodu özel görünürlük kuralına takılıyor mu?
   */
  function m_p_ozel_cari_kisitli_mi(?string $cariKodu): bool
  {
    $normalizedCode = m_p_ozel_cari_kodu_normalize($cariKodu);
    if ($normalizedCode === '') {
      return false;
    }

    foreach (m_p_ozel_cari_kod_prefixleri() as $prefix) {
      $normalizedPrefix = m_p_ozel_cari_kodu_normalize($prefix);
      if ($normalizedPrefix !== '' && str_starts_with($normalizedCode, $normalizedPrefix)) {
        return true;
      }
    }

    return false;
  }
}

if (!function_exists('m_p_ozel_cari_goruntulebilir_mi')) {
  /**
   * Cari kodu kullanıcıya görünür mü?
   */
  function m_p_ozel_cari_goruntulebilir_mi(int|string $personelId, ?string $cariKodu): bool
  {
    if (!m_p_ozel_cari_kisitli_mi($cariKodu)) {
      return true;
    }

    return (int) (m_p_yetki($personelId, m_p_ozel_cari_goruntuleme_yetki_kodu()) ?? 0) === 1;
  }
}

if (!function_exists('m_p_ozel_cari_sql_filtresi')) {
  /**
   * SQL sorgularında ayarlardan gizlenen cari referanslarını filtreler.
   *
   * @return array{sql:string,params:array<string,int>}
   */
  function m_p_ozel_cari_sql_filtresi(int|string $personelId, string $columnName, string $paramPrefix = 'ozel_cari', ?string $yetkiKodu = null): array
  {
    global $dbh;

    $normalizedCode = m_p_ozel_cari_kisit_yetki_kodu_normalize($yetkiKodu);
    $normalizedPersonelId = m_p_ozel_cari_kisit_personel_normalize($personelId);

    if (m_p_ozel_cari_tam_bypass_var_mi($personelId)) {
      return ['sql' => '', 'params' => []];
    }

    $refs = m_p_ozel_cari_kisitli_refler_getir($dbh, $normalizedCode, $normalizedPersonelId, true);
    if (m_p_ozel_cari_kisit_yetki_bypass_var_mi($personelId, $normalizedCode)) {
      $refs = m_p_ozel_cari_kisitli_refler_getir($dbh, $normalizedCode, $normalizedPersonelId, false);
    }
    if ($refs === []) {
      return ['sql' => '', 'params' => []];
    }

    $placeholders = [];
    $params = [];
    $safePrefix = preg_replace('/[^A-Za-z0-9_]/', '_', $paramPrefix) ?: 'ozel_cari';

    foreach (array_values($refs) as $index => $cariRef) {
      $paramName = ':' . $safePrefix . '_' . $index;
      $placeholders[] = $paramName;
      $params[$paramName] = (int) $cariRef;
    }

    return [
      'sql' => "{$columnName} NOT IN (" . implode(', ', $placeholders) . ")",
      'params' => $params,
    ];
  }
}

if (!function_exists('m_p_cari_kodu_getir')) {
  /**
   * Cari ID üzerinden cari kodunu getirir.
   */
  function m_p_cari_kodu_getir(PDO $dbh, string $firma, int $cariId): ?string
  {
    if ($cariId <= 0) {
      return null;
    }

    $stmt = $dbh->prepare("SELECT TOP 1 CODE FROM {$firma}CLCARD WITH(NOLOCK) WHERE LOGICALREF = :cariid");
    $stmt->bindValue(':cariid', $cariId, PDO::PARAM_INT);
    $stmt->execute();
    $code = $stmt->fetchColumn();

    return $code === false ? null : (string) $code;
  }
}

if (!function_exists('m_p_cariid_goruntulebilir_mi')) {
  /**
   * Cari ID kullanıcıya görünür mü?
   */
  function m_p_cariid_goruntulebilir_mi(PDO $dbh, string $firma, int|string $personelId, int $cariId, ?string $yetkiKodu = null): bool
  {
    $normalizedCode = m_p_ozel_cari_kisit_yetki_kodu_normalize($yetkiKodu);
    $normalizedPersonelId = m_p_ozel_cari_kisit_personel_normalize($personelId);

    if (m_p_ozel_cari_tam_bypass_var_mi($personelId)) {
      return true;
    }

    if (!m_p_ozel_cari_ref_kisitli_mi($dbh, $cariId, $normalizedCode, $normalizedPersonelId, true)) {
      return true;
    }

    if (m_p_ozel_cari_kullaniciya_ozel_yasak_var_mi($dbh, $normalizedPersonelId, $cariId, $normalizedCode)) {
      return false;
    }

    return m_p_ozel_cari_kisit_yetki_bypass_var_mi($personelId, $normalizedCode);
  }
}

if (!function_exists('m_p_siparis_cari_ref_getir')) {
  /**
   * Sipariş ID üzerinden bağlı cari ref'ini getirir.
   */
  function m_p_siparis_cari_ref_getir(PDO $dbh, string $firmadonem, int $siparisRef): ?int
  {
    if ($siparisRef <= 0) {
      return null;
    }

    $stmt = $dbh->prepare("
      SELECT TOP 1 F.CLIENTREF
      FROM {$firmadonem}ORFICHE F WITH(NOLOCK)
      WHERE F.LOGICALREF = :siparisRef
    ");
    $stmt->bindValue(':siparisRef', $siparisRef, PDO::PARAM_INT);
    $stmt->execute();
    $clientRef = $stmt->fetchColumn();

    return $clientRef === false ? null : (int) $clientRef;
  }
}

if (!function_exists('m_p_siparis_goruntulebilir_mi')) {
  /**
   * Sipariş kullanıcıya görünür mü?
   */
  function m_p_siparis_goruntulebilir_mi(PDO $dbh, string $firmadonem, string $firma, int|string $personelId, int $siparisRef, ?string $yetkiKodu = null): bool
  {
    $cariRef = m_p_siparis_cari_ref_getir($dbh, $firmadonem, $siparisRef);
    if ($cariRef === null || $cariRef <= 0) {
      return true;
    }

    return m_p_cariid_goruntulebilir_mi($dbh, $firma, $personelId, $cariRef, $yetkiKodu);
  }
}

if (!function_exists('m_p_yetki_izin_sutunu_mu')) {
  /**
   * Meta alanları ayırmak için yalnızca izin sütunlarını işaretler.
   */
  function m_p_yetki_izin_sutunu_mu(string $sutun): bool
  {
    return preg_match('/^(M\d+|ST\d+|CR\d+|SP\d+)$/', $sutun) === 1;
  }
}

if (!function_exists('m_p_yetki_satiri_getir')) {
  /**
   * Kullanıcının yetki satırını kısa süreli session cache ile getirir.
   */
  function m_p_yetki_satiri_getir(int|string $id): ?array
  {
    global $dbh;

    $rowCacheKey = 'yetki_row_' . $id;
    $rowCacheTimeKey = 'yetki_row_time_' . $id;
    $cacheTtl = 300;

    if (isset($_SESSION[$rowCacheKey], $_SESSION[$rowCacheTimeKey])) {
      $cacheAge = time() - (int) $_SESSION[$rowCacheTimeKey];
      if ($cacheAge < $cacheTtl && is_array($_SESSION[$rowCacheKey])) {
        return $_SESSION[$rowCacheKey];
      }
    }

    $stmt = $dbh->prepare("SELECT * FROM M_P_YETKI WHERE PERSONEL = :personel_id");
    $stmt->bindValue(':personel_id', (int) $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $_SESSION[$rowCacheKey] = $row;
    $_SESSION[$rowCacheTimeKey] = time();

    return $row;
  }
}

/**
 * Kullanıcı yetki bilgisini güvenli ve tutarlı şekilde sorgular.
 *
 * Yönetici (YETKI=0) kullanıcılar izin sütunlarında otomatik olarak tam erişimli
 * kabul edilir. Bu, ayar ekranlarındaki "Yönetici = Tam Erişim" davranışını
 * uygulama geneline taşır.
 */
function m_p_yetki(int|string $id, string $sutun): int|string|null
{
  $allowedColumns = m_p_yetki_tum_sutunlar();

  if (!in_array($sutun, $allowedColumns, true)) {
    error_log("m_p_yetki: Geçersiz sütun adı: " . $sutun);
    return null;
  }

  $cacheKey = 'yetki_' . $id . '_' . $sutun;
  $cacheTimeKey = 'yetki_time_' . $id . '_' . $sutun;
  $cacheTtl = 300;

  if (isset($_SESSION[$cacheKey], $_SESSION[$cacheTimeKey])) {
    $cacheAge = time() - (int) $_SESSION[$cacheTimeKey];
    if ($cacheAge < $cacheTtl) {
      return $_SESSION[$cacheKey];
    }
  }

  try {
    $row = m_p_yetki_satiri_getir($id);

    if ($row !== null) {
      if (m_p_yetki_izin_sutunu_mu($sutun) && isset($row['YETKI']) && (int) $row['YETKI'] === 0) {
        $value = 1;
      } else {
        $value = $row[$sutun] ?? (($sutun === 'YETKI') ? 2 : 0);
      }

      $_SESSION[$cacheKey] = $value;
      $_SESSION[$cacheTimeKey] = time();
      return $value;
    }

    $defaultValue = ($sutun === 'YETKI') ? 2 : 0;
    $_SESSION[$cacheKey] = $defaultValue;
    $_SESSION[$cacheTimeKey] = time();
    return $defaultValue;
  } catch (PDOException $e) {
    error_log("m_p_yetki SQL hatası: " . $e->getMessage());
    return null;
  }
}

/**
 * Kullanıcının tüm yetki bilgilerini session'dan temizler
 * Şifre değişikliği veya yetki güncellemelerinde kullanılmalı
 *
 * @param int|string $id Personel ID (boş bırakılırsa mevcut kullanıcı)
 */
function m_p_yetki_cache_temizle(int|string|null $id = null): void
{
  if ($id === null && isset($_SESSION['plasiyer_id'])) {
    $id = $_SESSION['plasiyer_id'];
  }

  if ($id === null) {
    return;
  }

  unset($_SESSION['yetki_row_' . $id], $_SESSION['yetki_row_time_' . $id]);

  // Session'daki tüm yetki cache'lerini temizle
  foreach (array_keys($_SESSION) as $key) {
    if (
      str_starts_with($key, 'yetki_' . $id . '_')
      || str_starts_with($key, 'yetki_time_' . $id . '_')
    ) {
      unset($_SESSION[$key]);
    }
  }
}

// cokludepomiktar - birden fazla dosyada kullanılıyor
function cokludepomiktar(int|string $stokref): string
{
  global $dbh, $firmadonemx, $cokludepo;

  if (!isset($cokludepo) || $cokludepo != 1) {
    return '';
  }

  try {
    $stokref = (int)$stokref;
    $stmt = $dbh->prepare("
      SELECT W.NAME, ISNULL(S.ONHAND, 0) AS MIKTAR
      FROM L_CAPIWHOUSE W
      LEFT JOIN {$firmadonemx}STINVTOT S ON S.INVENNO = W.NR AND S.STOCKREF = :stokref
      WHERE W.FIRMNR = 1
      ORDER BY W.NR
    ");
    $stmt->execute([':stokref' => $stokref]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $output = '<small class="text-muted">';
    foreach ($rows as $row) {
      $output .= htmlspecialchars($row['NAME']) . ': ' . kusuratadet($row['MIKTAR']) . ' | ';
    }
    $output = rtrim($output, ' | ');
    $output .= '</small>';
    return $output;
  } catch (PDOException $e) {
    error_log("cokludepomiktar hatası: " . $e->getMessage());
    return '';
  }
}

// ================================================================
// DEĞİŞİKLİK LOGLAMA FONKSİYONLARI
// ================================================================

/**
 * Kullanıcının IP adresini al
 */
function getUserIP(): string
{
  // Güvenlik: Sadece güvenilir proxy'lerden gelen X-Forwarded-For'a güven
  $trustedProxies = ['127.0.0.1', '::1'];
  $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

  if (in_array($remoteAddr, $trustedProxies, true)) {
      if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
          // İlk IP adresi orijinal istemcidir
          $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
          return trim($ips[0]);
      }
      if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
          return $_SERVER['HTTP_CLIENT_IP'];
      }
  }
  return $remoteAddr;
}

/**
 * ============================================================================
 * SALDIRI MODU (Acil Durum Güvenlik Modu)
 * ============================================================================
 * Açıkken sistem yalnızca yerel ağdan (LAN) erişilebilir: public/internet
 * erişimi engellenir, hesap kilidi sertleşir, remember-me devre dışı kalır,
 * müşteri portalı kapanır. Durum tmp/saldiri_modu.json dosyasında tutulur
 * (dosya yok = mod kapalı). Fail-safe: dosya okunamaz/bozuksa mod KAPALI sayılır,
 * böylece bir dosya hatası tüm sistemi kilitlemez.
 * ============================================================================
 */

function saldiri_modu_dosya_yolu(): string
{
    return __DIR__ . '/tmp/saldiri_modu.json';
}

/**
 * Saldırı modu durumunu döndürür.
 * @return array{aktif:bool,acan_id?:int,acan_adi?:string,zaman?:string,ip?:string}
 */
function saldiri_modu_durum(): array
{
    $yol = saldiri_modu_dosya_yolu();
    if (!is_file($yol)) {
        return ['aktif' => false];
    }
    try {
        $veri = json_decode((string) file_get_contents($yol), true);
        if (is_array($veri) && !empty($veri['aktif'])) {
            $veri['aktif'] = true;
            return $veri;
        }
    } catch (Throwable $e) {
        error_log("saldiri_modu_durum hatasi: " . $e->getMessage());
    }
    // Fail-safe: dosya yok/bozuk/aktif değil => mod KAPALI
    return ['aktif' => false];
}

function saldiri_modu_aktif_mi(): bool
{
    return saldiri_modu_durum()['aktif'] === true;
}

/**
 * Verilen IP yerel/özel ağ mı? (RFC1918 private + loopback/reserved => true, public => false)
 * Geçersiz veya bilinmeyen IP güvenli tarafta tutulur (yerel değil sayılır).
 */
function ip_yerel_mi(string $ip): bool
{
    $ip = trim($ip);
    if ($ip === '' || strtoupper($ip) === 'UNKNOWN') {
        return false;
    }
    if ($ip === '::1') {
        return true;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    // Public ise (private/reserved DEĞİL) filter IP'yi döner => yerel değil.
    // Private/reserved (192.168.x, 10.x, 172.16-31.x, 127.x, 169.254.x ...) ise false döner => yerel.
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/**
 * Mevcut istek saldırı modu nedeniyle engellenmeli mi?
 * (mod aktif VE istemci yerel ağ dışında)
 */
function saldiri_modu_erisim_engelli_mi(): bool
{
    return saldiri_modu_aktif_mi() && !ip_yerel_mi(getUserIP());
}

/**
 * Saldırı modunu açar (dosya yazar) veya kapatır (dosya siler).
 */
function saldiri_modu_ayarla(bool $aktif, int $acanId = 0, string $acanAdi = ''): bool
{
    $yol = saldiri_modu_dosya_yolu();
    if (!$aktif) {
        if (is_file($yol)) {
            @unlink($yol);
        }
        return true;
    }
    $veri = [
        'aktif'    => true,
        'acan_id'  => $acanId,
        'acan_adi' => $acanAdi,
        'zaman'    => date('Y-m-d H:i:s'),
        'ip'       => getUserIP(),
    ];
    return file_put_contents($yol, json_encode($veri, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

/**
 * Saldırı modu engelli erişim için 403 yanıtı verip isteği sonlandırır.
 * JSON/AJAX isteklerinde JSON, normal isteklerde basit HTML döner.
 */
function saldiri_modu_engelle_ve_cik(): never
{
    if (!headers_sent()) {
        http_response_code(403);
    }
    $json = (strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false)
        || (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest')
        || (substr(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), -8) === '_api.php');

    if ($json) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'message' => 'Güvenlik nedeniyle erişim geçici olarak yalnızca yerel ağ ile sınırlıdır.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Erişim Kısıtlı</title>'
        . '<style>body{font-family:"Segoe UI",Roboto,Arial,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0;padding:20px}'
        . '.box{max-width:440px;text-align:center;background:rgba(30,41,59,.7);border:1px solid rgba(239,68,68,.3);border-radius:18px;padding:40px 28px}'
        . '.ico{width:74px;height:74px;border-radius:18px;background:rgba(239,68,68,.15);color:#f87171;display:inline-flex;align-items:center;justify-content:center;font-size:34px;margin-bottom:18px}'
        . 'h1{font-size:20px;margin:0 0 10px}p{font-size:14px;color:#94a3b8;line-height:1.6;margin:0}</style></head><body>'
        . '<div class="box"><div class="ico">&#128274;</div><h1>Erişim Geçici Olarak Kısıtlı</h1>'
        . '<p>Güvenlik nedeniyle sisteme şu anda yalnızca işletme içi yerel ağdan erişilebilmektedir. Lütfen ofis ağından tekrar deneyin veya yöneticinizle iletişime geçin.</p></div></body></html>';
    exit;
}

/**
 * Saldırı modunda müşteri portalı tamamen kapalıdır; 503 sayfası basıp çıkar.
 */
function musteri_portal_kapali_cik(): never
{
    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 3600');
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Portal Geçici Kapalı</title>'
        . '<style>body{font-family:"Segoe UI",Roboto,Arial,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0;padding:20px}'
        . '.box{max-width:420px;text-align:center;background:rgba(30,41,59,.7);border:1px solid rgba(239,68,68,.3);border-radius:18px;padding:40px 28px}'
        . '.ico{width:74px;height:74px;border-radius:18px;background:rgba(239,68,68,.15);color:#f87171;display:inline-flex;align-items:center;justify-content:center;font-size:34px;margin-bottom:18px}'
        . 'h1{font-size:20px;margin:0 0 10px}p{font-size:14px;color:#94a3b8;line-height:1.6;margin:0}</style></head><body>'
        . '<div class="box"><div class="ico">&#128274;</div><h1>Portal Geçici Olarak Kapalı</h1>'
        . '<p>Güvenlik bakımı nedeniyle müşteri portalı kısa süreliğine hizmet dışıdır. Lütfen daha sonra tekrar deneyin.</p></div></body></html>';
    exit;
}

/**
 * ============================================================================
 * CİHAZ / OTURUM ANALİZİ YARDIMCILARI
 * ============================================================================
 * M_GIRIS_LOG'daki ham user-agent değerini, IP ve sürüm gürültüsünden
 * arındırılmış okunabilir bir cihaz etiketine çevirir (ör. "iPhone/iPad · Safari").
 * Böylece aynı telefonun her IP/sürüm değişiminde "yeni cihaz" sanılması önlenir.
 */
function akl_cihaz_etiket(string|null $userAgent): string
{
    $ua = trim((string) $userAgent);
    if ($ua === '') {
        return 'Bilinmeyen';
    }
    if (akl_cihaz_anormal_mi($ua)) {
        if (stripos($ua, 'curl') !== false)     return 'curl (otomasyon)';
        if (stripos($ua, 'wget') !== false)     return 'wget (otomasyon)';
        if (stripos($ua, 'python') !== false)   return 'Python (script)';
        if (stripos($ua, 'postman') !== false)  return 'Postman';
        if (stripos($ua, 'insomnia') !== false) return 'Insomnia';
        return 'Otomasyon / Bot';
    }
    // İşletim sistemi / cihaz
    $os = 'Bilinmeyen';
    if (preg_match('/iPhone|iPad|iPod/i', $ua))         $os = 'iPhone/iPad';
    elseif (preg_match('/Android/i', $ua))              $os = 'Android';
    elseif (preg_match('/Windows/i', $ua))              $os = 'Windows';
    elseif (preg_match('/Macintosh|Mac OS X/i', $ua))   $os = 'Mac';
    elseif (preg_match('/Linux/i', $ua))                $os = 'Linux';
    // Tarayıcı (SIRA ÖNEMLİ: Edge/Chrome, Safari'den önce kontrol edilir; Chrome UA'sı "Safari" de içerir)
    $br = '';
    if (preg_match('/Edg/i', $ua))                $br = 'Edge';
    elseif (preg_match('/SamsungBrowser/i', $ua)) $br = 'Samsung Internet';
    elseif (preg_match('/OPR|Opera/i', $ua))      $br = 'Opera';
    elseif (preg_match('/CriOS|Chrome/i', $ua))   $br = 'Chrome';
    elseif (preg_match('/FxiOS|Firefox/i', $ua))  $br = 'Firefox';
    elseif (preg_match('/Safari/i', $ua))         $br = 'Safari';
    return $br !== '' ? ($os . ' · ' . $br) : $os;
}

/**
 * User-agent bir tarayıcı yerine otomasyon/script aracı mı (curl, bot, vb.)?
 * Boş user-agent da şüpheli sayılır.
 */
function akl_cihaz_anormal_mi(string|null $userAgent): bool
{
    $ua = trim((string) $userAgent);
    if ($ua === '') {
        return true;
    }
    return preg_match('/curl|wget|python|postman|insomnia|java\/|go-http|okhttp|libwww|scrapy|httpclient|bot\b|spider|crawl/i', $ua) === 1;
}

/**
 * Satır ekleme işlemini logla
 * @return bool İşlem başarılı mı
 */
function logSatirEkleme(int|string $fisRef, int|string $satirRef, string $ficheno, string $stokKodu, string $stokAdi, float|int|string|null $miktar, float|int|string|null $fiyat, float|int|string|null $total, int|string $kullaniciId, string $aciklama = ''): bool
{
  global $dbh;
  try {
    $stmt = $dbh->prepare("
            INSERT INTO M_SATIR_DEGISIKLIK_LOG
              (FIS_REF, SATIR_REF, FICHENO, STOK_KODU, STOK_ADI, ISLEM_TIPI,
               ESKI_MIKTAR, YENI_MIKTAR, ESKI_FIYAT, YENI_FIYAT, ESKI_TOTAL, YENI_TOTAL,
               KULLANICI, TARIH, IP_ADRESI, ACIKLAMA)
            VALUES
              (:fisRef, :satirRef, :ficheno, :stokKodu, :stokAdi, 'EKLE',
               NULL, :miktar, NULL, :fiyat, NULL, :total,
               :kullanici, GETDATE(), :ip, :aciklama)
        ");
    return $stmt->execute([':fisRef'    => $fisRef, ':satirRef'  => $satirRef, ':ficheno'   => $ficheno, ':stokKodu'  => $stokKodu, ':stokAdi'   => $stokAdi, ':miktar'    => (float)$miktar, ':fiyat'     => (float)$fiyat, ':total'     => (float)$total, ':kullanici' => $kullaniciId, ':ip'        => getUserIP(), ':aciklama'  => $aciklama]);
  } catch (PDOException $e) {
    error_log("logSatirEkleme hatası: " . $e->getMessage());
    logHataRapor('logSatirEkleme', $e);
    return false;
  }
}

/**
 * Satır düzenleme işlemini logla
 */
function logSatirDuzenleme(int|string $fisRef, int|string $satirRef, string $ficheno, string $stokKodu, string $stokAdi, float|int|string|null $eskiMiktar, float|int|string|null $yeniMiktar, float|int|string|null $eskiFiyat, float|int|string|null $yeniFiyat, float|int|string|null $eskiTotal, float|int|string|null $yeniTotal, int|string $kullaniciId, string $aciklama = ''): void
{
  global $dbh;
  try {
    $stmt = $dbh->prepare("
            INSERT INTO M_SATIR_DEGISIKLIK_LOG
              (FIS_REF, SATIR_REF, FICHENO, STOK_KODU, STOK_ADI, ISLEM_TIPI,
               ESKI_MIKTAR, YENI_MIKTAR, ESKI_FIYAT, YENI_FIYAT, ESKI_TOTAL, YENI_TOTAL,
               KULLANICI, TARIH, IP_ADRESI, ACIKLAMA)
            VALUES
              (:fisRef, :satirRef, :ficheno, :stokKodu, :stokAdi, 'DUZENLE',
               :eskiMiktar, :yeniMiktar, :eskiFiyat, :yeniFiyat, :eskiTotal, :yeniTotal,
               :kullanici, GETDATE(), :ip, :aciklama)
        ");
    $stmt->execute([':fisRef'      => $fisRef, ':satirRef'    => $satirRef, ':ficheno'     => $ficheno, ':stokKodu'    => $stokKodu, ':stokAdi'     => $stokAdi, ':eskiMiktar'  => (float)$eskiMiktar, ':yeniMiktar'  => (float)$yeniMiktar, ':eskiFiyat'   => (float)$eskiFiyat, ':yeniFiyat'   => (float)$yeniFiyat, ':eskiTotal'   => (float)$eskiTotal, ':yeniTotal'   => (float)$yeniTotal, ':kullanici'   => $kullaniciId, ':ip'          => getUserIP(), ':aciklama'    => $aciklama]);
  } catch (PDOException $e) {
    error_log("logSatirDuzenleme hatası: " . $e->getMessage());
  }
}

/**
 * Satır silme işlemini logla
 */
function logSatirSilme(int|string $fisRef, int|string $satirRef, string $ficheno, string $stokKodu, string $stokAdi, float|int|string|null $miktar, float|int|string|null $fiyat, float|int|string|null $total, int|string $kullaniciId, string $aciklama = ''): void
{
  global $dbh;
  try {
    $stmt = $dbh->prepare("
            INSERT INTO M_SATIR_DEGISIKLIK_LOG
              (FIS_REF, SATIR_REF, FICHENO, STOK_KODU, STOK_ADI, ISLEM_TIPI,
               ESKI_MIKTAR, YENI_MIKTAR, ESKI_FIYAT, YENI_FIYAT, ESKI_TOTAL, YENI_TOTAL,
               KULLANICI, TARIH, IP_ADRESI, ACIKLAMA)
            VALUES
              (:fisRef, :satirRef, :ficheno, :stokKodu, :stokAdi, 'SIL',
               :miktar, NULL, :fiyat, NULL, :total, NULL,
               :kullanici, GETDATE(), :ip, :aciklama)
        ");
    $stmt->execute([':fisRef'    => $fisRef, ':satirRef'  => $satirRef, ':ficheno'   => $ficheno, ':stokKodu'  => $stokKodu, ':stokAdi'   => $stokAdi, ':miktar'    => (float)$miktar, ':fiyat'     => (float)$fiyat, ':total'     => (float)$total, ':kullanici' => $kullaniciId, ':ip'        => getUserIP(), ':aciklama'  => $aciklama]);
  } catch (PDOException $e) {
    error_log("logSatirSilme hatası: " . $e->getMessage());
  }
}

/**
 * İskonto değişikliğini logla (basitleştirilmiş versiyon)
 */
function logIskontoDegisiklik(int|string $fisRef, string $ficheno, int|string $iskontoSeviye, float|int|string|null $eskiIskonto, float|int|string|null $yeniIskonto, float|int|string|null $eskiTutar, float|int|string|null $yeniTutar, int|string $kullaniciId, int|string $onaylayanId = 0, string $aciklama = ''): void
{
  global $dbh;
  try {
    // Mevcut tablo şemasına uygun mapping
    // ISKONTO_TIPI: 1=Genel İskonto, 2=İkinci İskonto vb.
    $stmt = $dbh->prepare("
            INSERT INTO M_ISKONTO_LOG
              (FIS_REF, FICHENO, ISKONTO_TIPI, YENI_DISCPER, ESKI_DISCPER,
               YENI_DISTDISC, ESKI_DISTDISC, KULLANICI, ONAYLAYAN, TARIH, IP_ADRESI, ACIKLAMA)
            VALUES
              (:fisRef, :ficheno, :iskontoTipi, :yeniDiscPer, :eskiDiscPer,
               :yeniDistDisc, :eskiDistDisc, :kullanici, :onaylayan, GETDATE(), :ip, :aciklama)
        ");
    $stmt->execute([
        ':fisRef'         => $fisRef,
        ':ficheno'        => $ficheno,
        ':iskontoTipi'    => $iskontoSeviye,
        // 1 veya 2 (iskonto seviyesi)
        ':eskiDiscPer'    => (float)$eskiIskonto,
        // Eski iskonto yüzdesi
        ':yeniDiscPer'    => (float)$yeniIskonto,
        // Yeni iskonto yüzdesi
        ':eskiDistDisc'   => (float)$eskiTutar,
        // Eski tutar
        ':yeniDistDisc'   => (float)$yeniTutar,
        // Yeni tutar
        ':kullanici'      => $kullaniciId,
        ':onaylayan'      => $onaylayanId,
        ':ip'             => getUserIP(),
        ':aciklama'       => $aciklama,
    ]);
  } catch (PDOException $e) {
    error_log("logIskontoDegisiklik hatası: " . $e->getMessage());
  }
}

/**
 * KDV değişikliğini logla (basitleştirilmiş versiyon - eski tablo şemasına uygun)
 */
function logKdvDegisiklik(int|string $fisRef, int|string $satirRef, string $ficheno, string $stokKodu, string $stokAdi, float|int|string|null $eskiKdv, float|int|string|null $yeniKdv, int|string $kullaniciId, string $aciklama = ''): void
{
  global $dbh;
  try {
    $stmt = $dbh->prepare("
            INSERT INTO M_KDV_DEGISIKLIK_LOG
              (FIS_REF, SATIR_REF, FICHENO, STOK_KODU, STOK_ADI,
               ESKI_KDV_ORAN, YENI_KDV_ORAN, KULLANICI, TARIH, IP_ADRESI, ACIKLAMA)
            VALUES
              (:fisRef, :satirRef, :ficheno, :stokKodu, :stokAdi,
               :eskiKdvOran, :yeniKdvOran, :kullanici, GETDATE(), :ip, :aciklama)
        ");
    $stmt->execute([':fisRef'       => $fisRef, ':satirRef'     => $satirRef, ':ficheno'      => $ficheno, ':stokKodu'     => $stokKodu, ':stokAdi'      => $stokAdi, ':eskiKdvOran'  => (float)$eskiKdv, ':yeniKdvOran'  => (float)$yeniKdv, ':kullanici'    => $kullaniciId, ':ip'           => getUserIP(), ':aciklama'     => $aciklama]);
  } catch (PDOException $e) {
    error_log("logKdvDegisiklik hatası: " . $e->getMessage());
  }
}

/**
 * ============================================================================
 * MERKEZİ LOGLAMA SİSTEMİ - Fiş İşlem Logları
 * ============================================================================
 * Fiş oluşturma, güncelleme, silme işlemlerinin detaylı takibi için
 * merkezi loglama sistemi
 * ============================================================================
 */

/**
 * Fiş oluşturma işlemini logla
 *
 * @param int $fisRef Fiş LOGICALREF
 * @param string $ficheno Fiş numarası
 * @param int $cariRef Cari LOGICALREF
 * @param int $kullaniciId Kullanıcı ID
 * @param array $options Opsiyonel parametreler (doviz, dovizKuru, depo, durum, aciklama)
 * @return bool İşlem başarılı mı
 */
function logFisOlusturma(int|string $fisRef, string $ficheno, int|string $cariRef, int|string $kullaniciId, array $options = []): bool
{
  global $dbh, $firma;

  try {
    // Cari bilgilerini al
    $stmtCariInfo = $dbh->prepare("
      SELECT CODE, DEFINITION_
      FROM {$firma}CLCARD
      WHERE LOGICALREF = :cariRef
    ");
    $stmtCariInfo->execute([':cariRef' => (int) $cariRef]);
    $cariInfo = $stmtCariInfo->fetch(PDO::FETCH_ASSOC);

    $cariKodu = $cariInfo ? $cariInfo['CODE'] : '';
    $cariAdi = $cariInfo ? $cariInfo['DEFINITION_'] : '';

    // Opsiyonel parametreleri al
    $doviz = $options['doviz'] ?? 'TL';
    $dovizKuru = $options['dovizKuru'] ?? 1;
    $depo = $options['depo'] ?? 0;
    $durum = $options['durum'] ?? 4;
    $aciklama = $options['aciklama'] ?? 'Yeni fiş oluşturuldu';

    $stmt = $dbh->prepare("
      INSERT INTO M_FIS_LOG
        (FIS_REF, FICHENO, ISLEM_TIPI, CARI_REF, CARI_KODU, CARI_ADI,
         TOPLAM_TUTAR, TOPLAM_KDV, GENEL_TOPLAM,
         DOVIZ_TIPI, DOVIZ_KURU, KULLANICI, TARIH, IP_ADRESI, ACIKLAMA, DEPO, DURUM)
      VALUES
        (:fisRef, :ficheno, 'OLUSTURMA', :cariRef, :cariKodu, :cariAdi,
         0, 0, 0,
         :doviz, :dovizKuru, :kullanici, GETDATE(), :ip, :aciklama, :depo, :durum)
    ");

    return $stmt->execute([':fisRef'    => $fisRef, ':ficheno'   => $ficheno, ':cariRef'   => $cariRef, ':cariKodu'  => $cariKodu, ':cariAdi'   => $cariAdi, ':doviz'     => $doviz, ':dovizKuru' => $dovizKuru, ':kullanici' => $kullaniciId, ':ip'        => getUserIP(), ':aciklama'  => $aciklama, ':depo'      => $depo, ':durum'     => $durum]);

  } catch (PDOException $e) {
    error_log("logFisOlusturma hatası: " . $e->getMessage());
    return false;
  }
}

/**
 * Fiş güncelleme işlemini logla
 *
 * @param int $fisRef Fiş LOGICALREF
 * @param string $ficheno Fiş numarası
 * @param int $kullaniciId Kullanıcı ID
 * @param string $aciklama İşlem açıklaması
 * @param array $tutarBilgileri Opsiyonel tutar bilgileri
 * @return bool İşlem başarılı mı
 */
function logFisGuncelleme(int|string $fisRef, string $ficheno, int|string $kullaniciId, string $aciklama = '', array $tutarBilgileri = []): bool
{
  global $dbh;

  try {
    $toplamTutar = $tutarBilgileri['toplam'] ?? 0;
    $toplamKdv = $tutarBilgileri['kdv'] ?? 0;
    $genelToplam = $tutarBilgileri['genelToplam'] ?? 0;

    $stmt = $dbh->prepare("
      INSERT INTO M_FIS_LOG
        (FIS_REF, FICHENO, ISLEM_TIPI, TOPLAM_TUTAR, TOPLAM_KDV, GENEL_TOPLAM,
         KULLANICI, TARIH, IP_ADRESI, ACIKLAMA)
      VALUES
        (:fisRef, :ficheno, 'GUNCELLEME', :toplamTutar, :toplamKdv, :genelToplam,
         :kullanici, GETDATE(), :ip, :aciklama)
    ");

    return $stmt->execute([':fisRef'      => $fisRef, ':ficheno'     => $ficheno, ':toplamTutar' => $toplamTutar, ':toplamKdv'   => $toplamKdv, ':genelToplam' => $genelToplam, ':kullanici'   => $kullaniciId, ':ip'          => getUserIP(), ':aciklama'    => $aciklama]);

  } catch (PDOException $e) {
    error_log("logFisGuncelleme hatası: " . $e->getMessage());
    return false;
  }
}

/**
 * Fiş silme işlemini logla
 *
 * @param int $fisRef Fiş LOGICALREF
 * @param string $ficheno Fiş numarası
 * @param int $kullaniciId Kullanıcı ID
 * @param string $aciklama İşlem açıklaması
 * @return bool İşlem başarılı mı
 */
function logFisSilme(int|string $fisRef, string $ficheno, int|string $kullaniciId, string $aciklama = 'Fiş silindi'): bool
{
  global $dbh;

  try {
    $stmt = $dbh->prepare("
      INSERT INTO M_FIS_LOG
        (FIS_REF, FICHENO, ISLEM_TIPI, KULLANICI, TARIH, IP_ADRESI, ACIKLAMA)
      VALUES
        (:fisRef, :ficheno, 'SILME', :kullanici, GETDATE(), :ip, :aciklama)
    ");

    return $stmt->execute([':fisRef'    => $fisRef, ':ficheno'   => $ficheno, ':kullanici' => $kullaniciId, ':ip'        => getUserIP(), ':aciklama'  => $aciklama]);

  } catch (PDOException $e) {
    error_log("logFisSilme hatası: " . $e->getMessage());
    return false;
  }
}

/**
 * ============================================================================
 * İYİLEŞTİRİLMİŞ HATA YAKALAMA SİSTEMİ
 * ============================================================================
 * Mevcut loglama fonksiyonlarına hata bildirimi eklenir
 * ============================================================================
 */
/**
 * Log hatalarını detaylı raporla
 *
 * @param string $fonksiyon Fonksiyon adı
 * @param PDOException $e Hata nesnesi
 * @param bool $sessizMod true ise kullanıcıya hata gösterme
 */
function logHataRapor(string $fonksiyon, Throwable $e, bool $sessizMod = true): void
{
  $hataMesaji = "[{$fonksiyon}] Loglama hatası: " . $e->getMessage();

  // Error log'a yaz
  error_log($hataMesaji);

  // Geliştirme ortamında ekrana da yaz (üretimde sessiz)
  if (!$sessizMod && defined('DEBUG_MODE') && DEBUG_MODE === true) {
    echo "<!-- LOG HATASI: {$hataMesaji} -->\n";
  }

  // Kritik hataları ayrı bir dosyaya da yazabiliriz
  $logDosya = function_exists('app_log_file')
    ? app_log_file('loglama_hatalari')
    : (__DIR__ . '/logs/loglama_hatalari.log');
  $logKlasoru = dirname($logDosya);
  if (is_dir($logKlasoru) || @mkdir($logKlasoru, 0775, true)) {
    $tarih = date('Y-m-d H:i:s');
    $ip = getUserIP();
    file_put_contents(
      $logDosya,
      "[{$tarih}] [{$ip}] {$hataMesaji}\n",
      FILE_APPEND
    );
  }
}

/**
 * ============================================================================
 * GİRİŞ/ÇIKIŞ LOGLAMA SİSTEMİ
 * ============================================================================
 * Kullanıcı giriş ve çıkış işlemlerini loglar
 * Tablo: M_GIRIS_LOG
 * ============================================================================
 */

/**
 * Kullanıcı girişini logla
 *
 * @param int $kullaniciId Kullanıcı LOGICALREF
 * @param string $kullaniciAdi Kullanıcı kodu/adı
 * @param bool $basarili Giriş başarılı mı
 * @param string $aciklama Ek açıklama (başarısız girişlerde sebep)
 * @return bool İşlem başarılı mı
 */
function logGiris(int|string|null $kullaniciId, string $kullaniciAdi, bool $basarili = true, string $aciklama = ''): bool
{
  global $dbh;
  try {
    $stmt = $dbh->prepare("
      INSERT INTO M_GIRIS_LOG
        (KULLANICI_ID, KULLANICI_ADI, ISLEM_TIPI, BASARILI, IP_ADRESI, TARAYICI, TARIH, ACIKLAMA)
      VALUES
        (:kullaniciId, :kullaniciAdi, 'GIRIS', :basarili, :ip, :tarayici, GETDATE(), :aciklama)
    ");
    return $stmt->execute([':kullaniciId'  => $kullaniciId ?: 0, ':kullaniciAdi' => $kullaniciAdi, ':basarili'     => $basarili ? 1 : 0, ':ip'           => getUserIP(), ':tarayici'     => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500) : '', ':aciklama'     => $aciklama]);
  } catch (PDOException $e) {
    error_log("logGiris hatası: " . $e->getMessage());
    return false;
  }
}

/**
 * Kullanıcı çıkışını logla
 *
 * @param int $kullaniciId Kullanıcı LOGICALREF
 * @param string $kullaniciAdi Kullanıcı kodu/adı
 * @param string $aciklama Ek açıklama
 * @return bool İşlem başarılı mı
 */
function logCikis(int|string $kullaniciId, string $kullaniciAdi, string $aciklama = ''): bool
{
  global $dbh;
  try {
    $stmt = $dbh->prepare("
      INSERT INTO M_GIRIS_LOG
        (KULLANICI_ID, KULLANICI_ADI, ISLEM_TIPI, BASARILI, IP_ADRESI, TARAYICI, TARIH, ACIKLAMA)
      VALUES
        (:kullaniciId, :kullaniciAdi, 'CIKIS', 1, :ip, :tarayici, GETDATE(), :aciklama)
    ");
    return $stmt->execute([':kullaniciId'  => $kullaniciId, ':kullaniciAdi' => $kullaniciAdi, ':ip'           => getUserIP(), ':tarayici'     => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500) : '', ':aciklama'     => $aciklama]);
  } catch (PDOException $e) {
    error_log("logCikis hatası: " . $e->getMessage());
    return false;
  }
}

/**
 * ============================================================================
 * YAZDIRMA LOGLAMA SİSTEMİ
 * ============================================================================
 * Fiş yazdırma işlemlerini loglar
 * Tablo: M_YAZDIR_LOG
 * ============================================================================
 */

/**
 * Yazdırma işlemini logla
 *
 * @param int $fisRef Fiş LOGICALREF
 * @param string $ficheno Fiş numarası
 * @param string $yazdirmaTipi Yazdırma tipi (FIYATLI, FIYATSIZ, EXCEL, HTML, KOLI, DOVIZ, vb.)
 * @param int $kullaniciId Kullanıcı ID
 * @param string $aciklama Ek açıklama
 * @return bool İşlem başarılı mı
 */
function logYazdir(int|string $fisRef, string $ficheno, string $yazdirmaTipi, int|string $kullaniciId, string $aciklama = ''): bool
{
  global $dbh;
  try {
    // Önce bu fiş için yazdırma sayısını al
    $stmtCount = $dbh->prepare("SELECT COUNT(*) as SAYI FROM M_YAZDIR_LOG WHERE FIS_REF = :fisRef");
    $stmtCount->execute([':fisRef' => $fisRef]);
    $count = $stmtCount->fetch(PDO::FETCH_ASSOC);
    $yazdirmaSayisi = intval($count['SAYI']) + 1;

    $stmt = $dbh->prepare("
      INSERT INTO M_YAZDIR_LOG
        (FIS_REF, FICHENO, YAZDIRMA_TIPI, YAZDIRMA_SAYISI, KULLANICI_ID, IP_ADRESI, TARIH, ACIKLAMA)
      VALUES
        (:fisRef, :ficheno, :yazdirmaTipi, :yazdirmaSayisi, :kullaniciId, :ip, GETDATE(), :aciklama)
    ");
    return $stmt->execute([':fisRef'         => $fisRef, ':ficheno'        => $ficheno, ':yazdirmaTipi'   => $yazdirmaTipi, ':yazdirmaSayisi' => $yazdirmaSayisi, ':kullaniciId'    => $kullaniciId, ':ip'             => getUserIP(), ':aciklama'       => $aciklama]);
  } catch (PDOException $e) {
    error_log("logYazdir hatası: " . $e->getMessage());
    return false;
  }
}

/**
 * ============================================================================
 * FİYAT DEĞİŞİKLİK LOGLAMA SİSTEMİ
 * ============================================================================
 * Satır bazında fiyat değişikliklerini loglar
 * Tablo: M_FIYAT_LOG
 * ============================================================================
 */

/**
 * Fiyat değişikliğini logla
 *
 * @param int $fisRef Fiş LOGICALREF
 * @param int $satirRef Satır LOGICALREF
 * @param string $ficheno Fiş numarası
 * @param string $stokKodu Stok kodu
 * @param string $stokAdi Stok adı
 * @param float $eskiFiyat Eski fiyat
 * @param float $yeniFiyat Yeni fiyat
 * @param float $miktar Miktar (toplam hesabı için)
 * @param int $kullaniciId Kullanıcı ID
 * @param string $aciklama Ek açıklama
 * @return bool İşlem başarılı mı
 */
function logFiyatDegisiklik(int|string $fisRef, int|string $satirRef, string $ficheno, string $stokKodu, string $stokAdi, float|int|string|null $eskiFiyat, float|int|string|null $yeniFiyat, float|int|string|null $miktar, int|string $kullaniciId, string $aciklama = ''): bool
{
  global $dbh;
  try {
    // Fark hesapla
    $eskiFiyatF = (float)$eskiFiyat;
    $yeniFiyatF = (float)$yeniFiyat;
    $miktarF = (float)$miktar;
    $fark = $yeniFiyatF - $eskiFiyatF;
    $toplamFark = $fark * $miktarF;
    $yuzdelikFark = ($eskiFiyatF > 0) ? round((($yeniFiyatF - $eskiFiyatF) / $eskiFiyatF) * 100, 2) : 0;

    $stmt = $dbh->prepare("
      INSERT INTO M_FIYAT_LOG
        (FIS_REF, SATIR_REF, FICHENO, STOK_KODU, STOK_ADI,
         ESKI_FIYAT, YENI_FIYAT, FARK, MIKTAR, TOPLAM_FARK, YUZDELIK_FARK,
         KULLANICI_ID, IP_ADRESI, TARIH, ACIKLAMA)
      VALUES
        (:fisRef, :satirRef, :ficheno, :stokKodu, :stokAdi,
         :eskiFiyat, :yeniFiyat, :fark, :miktar, :toplamFark, :yuzdelikFark,
         :kullaniciId, :ip, GETDATE(), :aciklama)
    ");
    return $stmt->execute([':fisRef'        => $fisRef, ':satirRef'      => $satirRef, ':ficheno'       => $ficheno, ':stokKodu'      => $stokKodu, ':stokAdi'       => $stokAdi, ':eskiFiyat'     => $eskiFiyatF, ':yeniFiyat'     => $yeniFiyatF, ':fark'          => $fark, ':miktar'        => $miktarF, ':toplamFark'    => $toplamFark, ':yuzdelikFark'  => $yuzdelikFark, ':kullaniciId'   => $kullaniciId, ':ip'            => getUserIP(), ':aciklama'      => $aciklama]);
  } catch (PDOException $e) {
    error_log("logFiyatDegisiklik hatası: " . $e->getMessage());
    return false;
  }
}

// ================================================================
// SESSION BAZLI PARAMETRE YÖNETİM SİSTEMİ
// ================================================================
// URL'de hassas bilgilerin görünmesini engeller
// GET parametreleri otomatik olarak session'a aktarılır
// ================================================================

/**
 * Sayfa parametresini session'a kaydet
 *
 * @param string $key Parametre adı
 * @param mixed $value Parametre değeri
 * @param string|null $page Sayfa adı (varsayılan: mevcut sayfa)
 */
function setPageParam(string $key, mixed $value, ?string $page = null): void
{
    if ($page === null) {
        $page = basename($_SERVER['PHP_SELF'], '.php');
    }

    if (!isset($_SESSION['page_params'])) {
        $_SESSION['page_params'] = [];
    }
    if (!isset($_SESSION['page_params'][$page])) {
        $_SESSION['page_params'][$page] = [];
    }

    $_SESSION['page_params'][$page][$key] = $value;
}

/**
 * Sayfa parametresini al (önce GET, sonra session'dan)
 * GET'ten alındıysa otomatik olarak session'a kaydeder
 *
 * @param string $key Parametre adı
 * @param mixed $default Varsayılan değer
 * @param string|null $page Sayfa adı (varsayılan: mevcut sayfa)
 * @return mixed Parametre değeri
 */
function getPageParam(string $key, mixed $default = null, ?string $page = null): mixed
{
    if ($page === null) {
        $page = basename($_SERVER['PHP_SELF'], '.php');
    }

    // Önce GET parametresine bak
    if (isset($_GET[$key])) {
        $value = $_GET[$key];
        // GET'ten geldiyse session'a kaydet
        setPageParam($key, $value, $page);
        return $value;
    }

    // POST parametresine bak
    if (isset($_POST[$key])) {
        $value = $_POST[$key];
        setPageParam($key, $value, $page);
        return $value;
    }

    // Session'dan al
    if (isset($_SESSION['page_params'][$page][$key])) {
        return $_SESSION['page_params'][$page][$key];
    }

    return $default;
}

/**
 * Sayfa parametresini integer olarak al
 *
 * @param string $key Parametre adı
 * @param int $default Varsayılan değer
 * @param string|null $page Sayfa adı
 * @return int Parametre değeri
 */
function getPageParamInt(string $key, int $default = 0, ?string $page = null): int
{
    return (int) getPageParam($key, $default, $page);
}

/**
 * Sayfa parametresini string olarak al (trim edilmiş)
 *
 * @param string $key Parametre adı
 * @param string $default Varsayılan değer
 * @param string|null $page Sayfa adı
 * @return string Parametre değeri
 */
function getPageParamString(string $key, string $default = '', ?string $page = null): string
{
    return trim((string) getPageParam($key, $default, $page));
}

function safeLocalReturnUrl(?string $url, string $default = 'index.php'): string
{
    $url = trim((string) $url);

    if ($url === '' || str_contains($url, "\r") || str_contains($url, "\n")) {
        return $default;
    }

    if (str_starts_with($url, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
        return $default;
    }

    $parts = parse_url($url);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return $default;
    }

    $path = $parts['path'] ?? '';
    if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
        return $default;
    }

    if (!preg_match('/^[A-Za-z0-9_\/.-]+\.php$/', $path)) {
        return $default;
    }

    return $url;
}

/**
 * Belirli bir sayfanın tüm parametrelerini temizle
 *
 * @param string|null $page Sayfa adı (null = mevcut sayfa)
 */
function clearPageParams(?string $page = null): void
{
    if ($page === null) {
        $page = basename($_SERVER['PHP_SELF'], '.php');
    }

    if (isset($_SESSION['page_params'][$page])) {
        unset($_SESSION['page_params'][$page]);
    }
}

/**
 * URL'de GET parametresi olup olmadığını kontrol et
 * Eğer varsa, yönlendirme yapılması gerektiğini bildirir
 *
 * @return bool GET parametresi var mı
 */
function hasUrlParams(): bool
{
    return !empty($_GET) && $_SERVER['REQUEST_METHOD'] === 'GET';
}

/**
 * URL temizleme için JavaScript kodu döndürür
 * Sayfa yüklendikten sonra URL'deki parametreleri temizler
 *
 * @return string JavaScript kodu
 */
function getUrlCleanerScript(): string
{
    // Sadece GET parametresi varsa çalış
    if (empty($_GET)) {
        return '';
    }

    $cleanUrl = strtok($_SERVER['REQUEST_URI'], '?');

    return <<<JS
<script>
(function() {
    // URL'yi temizle (parametreleri kaldır)
    if (window.history && window.history.replaceState) {
        window.history.replaceState({}, document.title, '{$cleanUrl}');
    }
})();
</script>
JS;
}

/**
 * URL parametrelerini session'a aktar ve yönlendir
 * Bu fonksiyon sayfa başında çağrılmalı (herhangi bir çıktıdan önce)
 *
 * @param array $allowedParams İzin verilen parametre listesi
 * @param bool $redirect true ise temiz URL'ye yönlendir
 * @return bool Yönlendirme yapıldı mı
 */
function migrateUrlToSession(array $allowedParams = [], bool $redirect = true): bool
{
    // POST isteği ise atla
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        return false;
    }

    // GET parametresi yoksa atla
    if (empty($_GET)) {
        return false;
    }

    $page = basename($_SERVER['PHP_SELF'], '.php');
    $hasParams = false;

    foreach ($_GET as $key => $value) {
        // İzin listesi varsa kontrol et
        if (!empty($allowedParams) && !in_array($key, $allowedParams)) {
            continue;
        }

        setPageParam($key, $value, $page);
        $hasParams = true;
    }

    // Yönlendirme yap
    if ($hasParams && $redirect) {
        $cleanUrl = strtok($_SERVER['REQUEST_URI'], '?');
        header('Location: ' . $cleanUrl);
        exit;
    }

    return $hasParams;
}

/**
 * Fiş için toplam hacim (m³) ve ağırlık (kg) hesaplar
 * ITMUNITA tablosundan koli ölçülerini alır
 *
 * @param int $fisId Fiş LOGICALREF (ORFICHE veya STFICHE)
 * @param string $fisTipi 'siparis' (ORFLINE) veya 'stok' (STLINE)
 * @return array ['hacim' => float, 'agirlik' => float, 'hacim_str' => string, 'agirlik_str' => string]
 */
function fis_hacim_agirlik_hesapla(int $fisId, string $fisTipi = 'siparis'): array
{
    global $dbh, $firma, $firmadonem;

    $result = [
        'hacim' => 0.0,
        'agirlik' => 0.0,
        'hacim_str' => '0.00 m³',
        'agirlik_str' => '0.0 kg'
    ];

    if ($fisId <= 0) {
        return $result;
    }

    try {
        // Fiş tipine göre tablo seç
        if ($fisTipi === 'siparis') {
            $lineTable = $firmadonem . 'ORFLINE';
            $refColumn = 'ORDFICHEREF';
        } else {
            $lineTable = $firmadonem . 'STLINE';
            $refColumn = 'STFICHEREF';
        }

        // Fiş satırlarını ve koli ölçülerini çek
        // AMOUNT: toplam miktar (adet cinsinden)
        // ITMUNITA LINENR=1: ana birim ölçüleri (1 kolinin ölçüleri)
        // ITMUNITA LINENR=2: koli birimi (CONVFACT2 = koli içi adet)
        // LENGTH, WIDTH, HEIGHT: mm cinsinden
        // WEIGHT: kg cinsinden
        $sql = "
            SELECT
                L.STOCKREF,
                L.AMOUNT,
                L.UOMREF,
                COALESCE(U1.LENGTH, 0) AS UZUNLUK,
                COALESCE(U1.WIDTH, 0) AS GENISLIK,
                COALESCE(U1.HEIGHT, 0) AS YUKSEKLIK,
                COALESCE(U1.WEIGHT, 0) AS AGIRLIK,
                COALESCE(U2.CONVFACT2, 1) AS KOLI_ICI_ADET
            FROM {$lineTable} L
            LEFT JOIN {$firma}ITMUNITA U1 ON L.STOCKREF = U1.ITEMREF AND U1.LINENR = 1
            LEFT JOIN {$firma}ITMUNITA U2 ON L.STOCKREF = U2.ITEMREF AND U2.LINENR = 2
            WHERE L.{$refColumn} = :fisId
            AND L.LINETYPE = 0
        ";

        $stmt = $dbh->prepare($sql);
        $stmt->execute([':fisId' => $fisId]);

        $toplamHacim = 0.0;
        $toplamAgirlik = 0.0;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $miktar = (float)$row['AMOUNT'];
            $koliIciAdet = (float)$row['KOLI_ICI_ADET'];

            // Koli adedini hesapla: miktar / koli içi adet
            // Örn: 120 adet sipariş, 1 kolide 120 adet = 1 koli
            $koliAdet = ($koliIciAdet > 1) ? ($miktar / $koliIciAdet) : $miktar;

            // Hacim hesapla: Uzunluk x Genişlik x Yükseklik (mm³ -> m³)
            $uzunluk = (float)$row['UZUNLUK'];
            $genislik = (float)$row['GENISLIK'];
            $yukseklik = (float)$row['YUKSEKLIK'];

            if ($uzunluk > 0 && $genislik > 0 && $yukseklik > 0) {
                // mm³ -> m³: 1 m³ = 1.000.000.000 mm³
                $koliHacimM3 = ($uzunluk * $genislik * $yukseklik) / 1000000000;
                $toplamHacim += $koliHacimM3 * $koliAdet;
            }

            // Ağırlık hesapla (kg)
            $agirlik = (float)$row['AGIRLIK'];
            if ($agirlik > 0) {
                $toplamAgirlik += $agirlik * $koliAdet;
            }
        }

        $result['hacim'] = round($toplamHacim, 3);
        $result['agirlik'] = round($toplamAgirlik, 1);
        $result['hacim_str'] = number_format($toplamHacim, 2, ',', '.') . ' m³';
        $result['agirlik_str'] = number_format($toplamAgirlik, 1, ',', '.') . ' kg';

    } catch (PDOException $e) {
        error_log("fis_hacim_agirlik_hesapla hatası: " . $e->getMessage());
    }

    return $result;
}

// Dosya sonu - HTML şablonu kaldırıldı (header redirect'i engelliyordu)
// Her sayfa kendi HTML yapısını oluşturmalı
