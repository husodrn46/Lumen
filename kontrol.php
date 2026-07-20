<?php
declare(strict_types=1);

/**
 * Güvenli Oturum ve Yetki Kontrolü
 *
 * Bu betik, kullanıcı oturumunun geçerli olup olmadığını kontrol eder ve
 * oturumdaki kullanıcının yetki seviyesini belirler.
 *
 * Güvenlik geliştirmeleri:
 * - Güvenli çerez ayarları (HttpOnly, SameSite=Strict).
 * - Oturum sabitleme (session fixation) saldırılarına karşı önlem olarak
 *   giriş işleminde session_regenerate_id(true) kullanılması zorunludur.
 * - Doğru oturum değişkeni kontrolü (isset/empty).
 * - Sunucu taraflı güvenli yönlendirme (header).
 * - Belirsiz global değişkenlere ($kullanicigirisli) olan bağımlılık kaldırıldı.
 */

// 0. Uygulama kök ayarlarını dahil et ve APP_ROOT_URL tanımla
include_once __DIR__ . '/ayr.php';
// 1. Eğer istek login sayfası ise kontrolü atla
if (basename((string) $_SERVER['SCRIPT_NAME']) === 'giris.php') {
    return;
}

// 1.5 Saldırı modu: aktifse ve istek yerel ağ dışından geliyorsa erişimi engelle.
// (function_exists guard: ayr.php henüz güncellenmemişse sistem normal çalışmaya devam eder)
if (function_exists('saldiri_modu_erisim_engelli_mi') && saldiri_modu_erisim_engelli_mi()) {
    saldiri_modu_engelle_ve_cik();
}

if (!function_exists('akl_kontrol_json_istegi_mi')) {
    function akl_kontrol_json_istegi_mi(): bool
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $requestedWith = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        return strpos($accept, 'application/json') !== false
            || strtolower($requestedWith) === 'xmlhttprequest'
            || substr($script, -8) === '_api.php';
    }
}

if (!function_exists('akl_kontrol_yonlendir_veya_json')) {
    function akl_kontrol_yonlendir_veya_json(string $url, string $message, int $statusCode = 401)
    {
        if (akl_kontrol_json_istegi_mi()) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'message' => $message,
                'redirect' => $url,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        header('Location: ' . $url, true, 303);
        exit;
    }
}

// 1. Güvenli Oturum Ayarları
// session_start() öncesinde cookie parametreleri ayarlanır.
if (session_status() === PHP_SESSION_NONE) {
    if (function_exists('session_set_cookie_params')) {
        // HTTPS kontrolü - secure flag'i otomatik ayarla
        $is_https = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        );

        session_set_cookie_params([
            'lifetime' => 0,    // tarayıcı kapanana kadar
            'path' => '/',
            'domain' => '',
            'secure' => $is_https,  // HTTPS varsa secure flag aktif
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }
    session_start();
}

// 2. Session Fixation Koruması
// Oturum ID'sini yenile (sadece ilk kontrolde)
if (!isset($_SESSION['session_regenerated'])) {
    session_regenerate_id(true);
    $_SESSION['session_regenerated'] = true;
    // PHP 7.3+ session_set_cookie_params() SameSite desteği sağlar
    // Manuel Set-Cookie header'ı kaldırıldı (Secure flag eksikliği ve çift cookie sorunu)
}

// 3. Oturum Doğrulama
// 'plasiyer_id' oturum değişkeninin varlığını ve boş olmadığını kontrol et.
if (!isset($_SESSION['plasiyer_id']) || empty($_SESSION['plasiyer_id'])) {
    // Remember Me token kontrolü: cookie varsa otomatik oturum kurtarma
    $rememberRestored = false;
    if ((!function_exists('saldiri_modu_aktif_mi') || !saldiri_modu_aktif_mi()) && isset($_COOKIE['remember_selector']) && isset($_COOKIE['remember_token']) && function_exists('remember_token_dogrula')) {
        $personelId = remember_token_dogrula($_COOKIE['remember_selector'], $_COOKIE['remember_token']);
        if ($personelId !== false) {
            try {
                $stmtRemember = $dbh->prepare("
                    SELECT L.LOGICALREF, L.CODE, L.CYPHCODE, L.SPECODE
                    FROM LG_SLSMAN L
                    WHERE L.LOGICALREF = :personel_id AND L.ACTIVE = 0
                ");
                $stmtRemember->execute([':personel_id' => $personelId]);
                $personelRemember = $stmtRemember->fetch(PDO::FETCH_ASSOC);

                if ($personelRemember) {
                    session_regenerate_id(true);
                    $_SESSION['plasiyer_id']    = $personelRemember['LOGICALREF'];
                    $_SESSION['plasiyer_depo']  = $personelRemember['SPECODE'];
                    $_SESSION['plasiyer_yetki'] = $personelRemember['CYPHCODE'];
                    $_SESSION['kullanici_adi']  = $personelRemember['CODE'];
                    $_SESSION['session_regenerated'] = true;
                    $_SESSION['login_ts']       = time(); // force-logout referansi (oturum baslangici)

                    if (function_exists('m_p_yetki_cache_temizle')) {
                        m_p_yetki_cache_temizle($personelRemember['LOGICALREF']);
                    }
                    if (function_exists('logGiris')) {
                        logGiris($personelRemember['LOGICALREF'], $personelRemember['CODE'], true, 'Token ile otomatik oturum kurtarma (kontrol.php)');
                    }
                    $rememberRestored = true;
                }
            } catch (Exception $e) {
                error_log("kontrol.php remember me hatası: " . $e->getMessage());
            }
        }

        // Token geçersizse cookie'leri temizle
        if (!$rememberRestored) {
            setcookie('remember_selector', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
            setcookie('remember_token', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
            if (function_exists('remember_token_sil')) {
                remember_token_sil($_COOKIE['remember_selector']);
            }
        }
    }

    // Remember me ile de kurtarılamadıysa giriş sayfasına yönlendir
    if (!$rememberRestored) {
        akl_kontrol_yonlendir_veya_json(
            APP_ROOT_URL . '/giris.php?hata=oturum_gerekli',
            'Oturum suresi dolmus. Lutfen tekrar giris yap.',
            401
        );
    }
}

// 4. Yetki Seviyesini Belirle
// Bu noktada kullanıcının giriş yaptığından eminiz.
$terminalkullanici = $_SESSION['plasiyer_id'];

// FORCE-LOGOUT: yonetici bu kullanicinin oturumlarini kapattiysa cikis yaptir.
// Tamamen guard'li: M_OTURUM_KAPAT tablosu yoksa veya sorgu hata verirse force-logout
// pasif kalir ve istek normal devam eder (hicbir kullanici yanlislikla kilitlenmez).
if (!isset($_SESSION['login_ts'])) {
    $_SESSION['login_ts'] = time();
}
try {
    if (isset($dbh)) {
        $stmtOturumKapat = $dbh->prepare("SELECT KAPATMA_TS FROM M_OTURUM_KAPAT WHERE KULLANICI_ID = :id");
        $stmtOturumKapat->execute([':id' => (int) $terminalkullanici]);
        $oturumKapatRow = $stmtOturumKapat->fetch(PDO::FETCH_ASSOC);
        if ($oturumKapatRow && !empty($oturumKapatRow['KAPATMA_TS'])) {
            $oturumKapatmaTs = strtotime(substr((string) $oturumKapatRow['KAPATMA_TS'], 0, 19)) ?: 0;
            if ($oturumKapatmaTs > 0 && (int) $_SESSION['login_ts'] < $oturumKapatmaTs) {
                session_unset();
                session_destroy();
                akl_kontrol_yonlendir_veya_json(
                    APP_ROOT_URL . '/giris.php?hata=oturum_kapatildi',
                    'Oturumunuz yonetici tarafindan kapatildi. Lutfen tekrar giris yapin.',
                    401
                );
            }
        }
    }
} catch (Throwable $e) {
    // M_OTURUM_KAPAT henuz yoksa veya sorgu hata verirse: force-logout pasif, normal devam.
}

$yetkidurum = null; // Başlangıçta yetkiyi tanımsız yap

// Veritabanından kullanıcının yetki kodunu çeken fonksiyon.
// *KRİTİK:* m_p_yetki() içinde mutlaka prepare edilmiş sorgu (prepared statement) kullanın.
$kullaniciYetkiKodu = m_p_yetki($terminalkullanici, 'YETKI');

switch ($kullaniciYetkiKodu) {
    case 0: // Yönetici
        $yetkidurum = 0;
        break;
    case 1: // Personel
        $yetkidurum = 1;
        break;
    case 2: // Müşteri
        $yetkidurum = 2;
        break;
    default:
        // Beklenmedik veya geçersiz bir yetki kodu dönerse, bu bir güvenlik riskidir.
        // Güvenli bir varsayılan olarak, oturumu sonlandırıp kullanıcıyı dışarı at.
        session_unset();
        session_destroy();
        akl_kontrol_yonlendir_veya_json(
            APP_ROOT_URL . '/giris.php?hata=gecersiz_yetki',
            'Kullanici yetkisi gecersiz. Lutfen tekrar giris yap.',
            403
        );
}

// 5. Müşteri yetkisine sahip kullanıcılar personel paneline erişemez
// Müşteriler sadece musteri/ klasöründeki sayfalara erişebilir
if ($yetkidurum === 2) {
    session_unset();
    session_destroy();
    akl_kontrol_yonlendir_veya_json(
        APP_ROOT_URL . '/musteri/giris.php?hata=musteri_erisim',
        'Musteri kullanicisi bu ekrana erisemez.',
        403
    );
}

// Bu betik başarıyla çalıştığında, dahil edildiği sayfada
// $terminalkullanici (kullanıcı ID'si) ve $yetkidurum (0, 1, veya 2)
// değişkenleri güvenli bir şekilde kullanılabilir.
require_once __DIR__ . '/log_ip.php';
require_once __DIR__ . '/includes/page_visit_shortcuts.php';

if (function_exists('akl_log_current_page_visit')) {
    akl_log_current_page_visit($dbh, $terminalkullanici);
}

?>
