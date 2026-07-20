<?php
declare(strict_types=1);

include_once(__DIR__ . '/ayr.php'); // ayr.php önce yüklenmeli (m_p_yetki_cache_temizle fonksiyonu için)
if (session_status() === PHP_SESSION_NONE) {
    // HTTPS tespiti kontrol.php ile AYNI olmalı. IIS, HTTP isteklerinde de
    // $_SERVER['HTTPS']='off' set eder; !empty('off') yanlışlıkla true döner ve
    // lokal (HTTP) erişimde Secure cookie üretip oturumu anında düşürürdü.
    $is_https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    );
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
        'cookie_secure'   => $is_https,
    ]);
}
// require_once __DIR__ . '/kontrol.php'; // Giriş sayfasında kontrol.php gerekmez

// Saldırı modu: aktifse ve yerel ağ dışından geliniyorsa giriş sayfasına erişimi de engelle.
if (function_exists('saldiri_modu_erisim_engelli_mi') && saldiri_modu_erisim_engelli_mi()) {
    saldiri_modu_engelle_ve_cik();
}

// Hata mesajlarını tutar
$error_message = '';

function normalize_login_username(string $username): string
{
    $username = trim($username);
    $username = strtr($username, [
        'ç' => 'c', 'Ç' => 'C',
        'ğ' => 'g', 'Ğ' => 'G',
        'ı' => 'i', 'I' => 'I',
        'İ' => 'i', 'i' => 'i',
        'ö' => 'o', 'Ö' => 'O',
        'ş' => 's', 'Ş' => 'S',
        'ü' => 'u', 'Ü' => 'U',
    ]);
    $username = preg_replace('/[^A-Za-z0-9_.-]/', '', $username) ?? '';

    return strtoupper($username);
}

// Oturum zorunluluğu veya yetki hatası ile yönlendirme durumunda mesaj göster
if (isset($_GET['hata'])) {
    if ($_GET['hata'] === 'oturum_gerekli') {
        $error_message = 'Oturum gerekli: Lütfen giriş yapın.';
    } elseif ($_GET['hata'] === 'gecersiz_yetki') {
        $error_message = 'Geçersiz yetki: Erişim reddedildi.';
    }
}

// ================================================================
// TOKEN TABANLI OTOMATIK GIRIS (REMEMBER ME)
// Cookie'de sifre yerine guvenli token kullanilir
// ================================================================
if (!isset($_SESSION['plasiyer_id']) && (!function_exists('saldiri_modu_aktif_mi') || !saldiri_modu_aktif_mi()) && isset($_COOKIE['remember_selector']) && isset($_COOKIE['remember_token'])) {
    $selector = $_COOKIE['remember_selector'];
    $validator = $_COOKIE['remember_token'];

    // Token dogrula
    $personelId = remember_token_dogrula($selector, $validator);

    if ($personelId !== false) {
        // Personel bilgilerini al
        try {
            $stmt = $dbh->prepare("
                SELECT L.LOGICALREF, L.CODE, L.CYPHCODE, L.SPECODE
                FROM LG_SLSMAN L
                WHERE L.LOGICALREF = :personel_id AND L.ACTIVE = 0
            ");
            $stmt->execute([':personel_id' => $personelId]);
            $personel = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($personel) {
                // Oturum baslat
                session_regenerate_id(true);
                $_SESSION['plasiyer_id']    = $personel['LOGICALREF'];
                $_SESSION['plasiyer_depo']  = $personel['SPECODE'];
                $_SESSION['plasiyer_yetki'] = $personel['CYPHCODE'];
                $_SESSION['kullanici_adi']  = $personel['CODE'];
                $_SESSION['login_ts']       = time(); // force-logout referansi (oturum baslangici)

                // Yetki cache'ini temizle
                if (function_exists('m_p_yetki_cache_temizle')) {
                    m_p_yetki_cache_temizle($personel['LOGICALREF']);
                }

                // Basarili otomatik girisi logla
                if (function_exists('logGiris')) {
                    logGiris($personel['LOGICALREF'], $personel['CODE'], true, 'Token ile otomatik giriş');
                }

                header('Location: index.php?giris=basarili');
                exit;
            }
        } catch (Exception $e) {
            // Token hatasi - cookie'leri temizle
        }
    }

    // Token gecersiz - cookie'leri temizle
    setcookie('remember_selector', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
    setcookie('remember_token', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
    remember_token_sil($selector);
}

// ================================================================
// FORM TABANLI GIRIS
// Sifre PHP tarafinda dogrulanir (hash destekli, geriye uyumlu)
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kullaniciadi'])) {
    $veri_kullanici = normalize_login_username((string) $_POST['kullaniciadi']);
    $veri_parola    = (string) ($_POST['kullanicisifre'] ?? '');

    // Brute-force koruması: Son 15 dakikada 10'dan fazla başarısız deneme varsa engelle
    try {
        $ipAddr = getUserIP();
        $stmtBF = $dbh->prepare("
            SELECT COUNT(*) FROM M_GIRIS_LOG
            WHERE IP_ADRESI = :ip AND BASARILI = 0
              AND TARIH > DATEADD(MINUTE, -15, GETDATE())
        ");
        $stmtBF->execute([':ip' => $ipAddr]);
        // Saldırı modunda IP eşiği sertleşir: 10 yerine 3
        $bfEsik = (function_exists('saldiri_modu_aktif_mi') && saldiri_modu_aktif_mi()) ? 3 : 10;
        if ((int)$stmtBF->fetchColumn() >= $bfEsik) {
            $error_message = 'Çok fazla hatalı giriş denemesi. Lütfen 15 dakika bekleyin.';
            // Brute-force girişimi logla
            if (function_exists('logGiris')) {
                logGiris(0, $veri_kullanici, false, 'Brute-force engellendi');
            }
            goto render_form;
        }
    } catch (Exception $e) {
        // Log tablosu yoksa veya hata olursa sessizce devam et
        error_log("Brute-force kontrol hatası: " . $e->getMessage());
    }

    // ================================================================
    // HESAP BAZLI GEÇİCİ KİLİT
    // Aynı kullanıcı adıyla, son başarılı girişten bu yana ve son
    // 15 dakika içinde 5+ başarısız deneme olursa o hesabı geçici
    // kilitle. IP bazlı korumadan bağımsız olarak, tek bir hesaba
    // yönelik şifre denemelerini durdurur. Kilitliyken yapılan
    // deneme loglanmaz; böylece kilit penceresi uzamaz (sliding değil).
    // ================================================================
    // Saldırı modunda eşik/süre sertleşir: 5 deneme / 15 dk yerine 2 deneme / 60 dk
    $saldiri_aktif = function_exists('saldiri_modu_aktif_mi') && saldiri_modu_aktif_mi();
    $hesap_kilit_esik = $saldiri_aktif ? 2 : 5;    // ardışık başarısız deneme sayısı
    $hesap_kilit_sure = $saldiri_aktif ? 60 : 15;  // dakika
    $sure_arti = (int) $hesap_kilit_sure;  // SQL'e literal gömülür (DATEADD parametre kabul etmez)
    $sure_eksi = -(int) $hesap_kilit_sure;
    try {
        // Not: DATEADD'in dakika argümanı SQL'e literal int olarak gömülür;
        // PDO sqlsrv bu argümanı parametre olarak nvarchar gönderip hata verir.
        // $sure_arti / $sure_eksi sabit int olduğundan injection riski yoktur.
        $stmtKilit = $dbh->prepare("
            SELECT COUNT(*) AS sayi,
                   DATEDIFF(SECOND, GETDATE(), DATEADD(MINUTE, {$sure_arti}, MAX(TARIH))) AS kalan_saniye
            FROM M_GIRIS_LOG
            WHERE KULLANICI_ADI = :kullanici
              AND ISLEM_TIPI = 'GIRIS'
              AND BASARILI = 0
              AND TARIH > DATEADD(MINUTE, {$sure_eksi}, GETDATE())
              AND TARIH > ISNULL((
                    SELECT MAX(TARIH) FROM M_GIRIS_LOG
                    WHERE KULLANICI_ADI = :kullanici_basarili
                      AND ISLEM_TIPI = 'GIRIS'
                      AND BASARILI = 1
                  ), CONVERT(datetime, '1900-01-01'))
        ");
        $stmtKilit->bindValue(':kullanici', $veri_kullanici);
        $stmtKilit->bindValue(':kullanici_basarili', $veri_kullanici);
        $stmtKilit->execute();
        $kilitRow = $stmtKilit->fetch(PDO::FETCH_ASSOC);

        if ($kilitRow && (int) $kilitRow['sayi'] >= $hesap_kilit_esik) {
            $kalan_dk = max(1, (int) ceil(((int) $kilitRow['kalan_saniye']) / 60));
            $error_message = "Çok fazla hatalı giriş nedeniyle hesabınız geçici olarak kilitlendi. Lütfen {$kalan_dk} dakika sonra tekrar deneyin.";
            goto render_form;
        }
    } catch (Exception $e) {
        // Log tablosu yoksa veya hata olursa girişi engelleme, sessizce devam et
        error_log("Hesap kilidi kontrol hatası: " . $e->getMessage());
    }

    // Kullaniciyi bul (sifre kontrolu PHP tarafinda yapilacak)
    $sorgu_str = "SELECT L.LOGICALREF, L.CYPHCODE, L.SPECODE, M.SIFRE
                  FROM LG_SLSMAN L
                  LEFT JOIN M_P_YETKI M ON L.LOGICALREF = M.PERSONEL
                  WHERE L.FIRMNR = :firmano
                    AND L.CODE   = :kullanici
                    AND L.ACTIVE = 0";
    $stmt = $dbh->prepare($sorgu_str);
    $stmt->bindParam(':firmano', $firmano, PDO::PARAM_INT);
    $stmt->bindParam(':kullanici', $veri_kullanici);
    $stmt->execute();

    $sorgu2 = $stmt->fetch(PDO::FETCH_ASSOC);

    // Sifre dogrulama (hash veya duz metin destekli)
    if ($sorgu2 && isset($sorgu2['SIFRE']) && sifre_dogrula($veri_parola, $sorgu2['SIFRE'])) {
        session_regenerate_id(true);
        $_SESSION['plasiyer_id']   = $sorgu2['LOGICALREF'];
        $_SESSION['plasiyer_depo'] = $sorgu2['SPECODE'];
        $_SESSION['plasiyer_yetki']= $sorgu2['CYPHCODE'];
        $_SESSION['kullanici_adi'] = $veri_kullanici;
        $_SESSION['login_ts']      = time(); // force-logout referansi (oturum baslangici)

        // Yetki cache'ini temizle (güncel yetkileri almak için)
        if (function_exists('m_p_yetki_cache_temizle')) {
            m_p_yetki_cache_temizle($sorgu2['LOGICALREF']);
        }

        // Sifre hash'lenmemisse otomatik hash'le (migration)
        if (sifre_yenilenmeli($sorgu2['SIFRE'])) {
            try {
                $yeni_hash = sifre_hashle($veri_parola);
                $stmt = $dbh->prepare("UPDATE M_P_YETKI SET SIFRE = :sifre WHERE PERSONEL = :personel");
                $stmt->execute([':sifre' => $yeni_hash, ':personel' => $sorgu2['LOGICALREF']]);
            } catch (Exception $e) {
                // Hash guncelleme hatasi - sessizce devam et
            }
        }

        // "Beni Hatirla" secilmisse token olustur
        if (isset($_POST['hatirla'])) {
            $tokenData = remember_token_olustur((int)$sorgu2['LOGICALREF'], 30);
            if ($tokenData) {
                $cookie_duration = time() + (86400 * 30); // 30 gun
                setcookie('remember_selector', $tokenData['selector'], [
                    'expires' => $cookie_duration,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Strict'
                ]);
                setcookie('remember_token', $tokenData['token'], [
                    'expires' => $cookie_duration,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Strict'
                ]);
            }
            // Kullanici adini da sakla (form icin)
            setcookie('giriskontroladi', (string) $veri_kullanici, ['expires' => $cookie_duration, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
            setcookie('giriskontrolhatirla', 'on', ['expires' => $cookie_duration, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
        } else {
            // Cookie'leri temizle
            setcookie('giriskontroladi', '', ['expires' => time() - 3600, 'path' => '/']);
            setcookie('giriskontrolhatirla', '', ['expires' => time() - 3600, 'path' => '/']);
            setcookie('remember_selector', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true]);
            setcookie('remember_token', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true]);
        }

        // Eski sifre cookie'sini temizle (guvenlik icin)
        if (isset($_COOKIE['giriskontrolsifre'])) {
            setcookie('giriskontrolsifre', '', ['expires' => time() - 3600, 'path' => '/']);
        }

        // Başarılı girişi logla
        if (function_exists('logGiris')) {
            logGiris($sorgu2['LOGICALREF'], $veri_kullanici, true, 'Başarılı giriş');
        }

        header('Location: index.php?giris=basarili');
        exit;
    }
    $error_message = 'Kullanıcı adı veya şifre hatalı.';
    // Başarısız girişi logla
    if (function_exists('logGiris')) {
        logGiris(0, $veri_kullanici, false, 'Hatalı kullanıcı adı veya şifre');
    }
}

render_form:
// Cookie'lerden gelen değerleri kontrol edip değişkene ata
$hatirlakontrol = (isset($_COOKIE['giriskontrolhatirla']) && $_COOKIE['giriskontrolhatirla'] === 'on') ? 'checked' : '';
$ck_kullanici   = normalize_login_username((string) ($_COOKIE['giriskontroladi'] ?? ''));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="googlebot" content="noindex, nofollow">
    <title>Lumen - Giris</title>
    <?php include_once(__DIR__ . '/pwa-header.php'); ?>
    <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
    <style>
        :root {
            --font-sans: "Segoe UI", Roboto, Arial, sans-serif;
            --bg: #f9fafb;
            --text-1: #1f2937;
            --text-2: #6b7280;
            --text-3: #9ca3af;
            --border: #e5e7eb;
            --red: #6F1022;
            --red-soft: #fef2f2;
            --emerald: #059669;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: var(--font-sans);
            background:
                radial-gradient(1200px 600px at 8% -10%, rgba(248, 113, 113, .14), transparent 60%),
                radial-gradient(900px 500px at 92% 110%, rgba(99, 102, 241, .08), transparent 55%),
                var(--bg);
            color: var(--text-1);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-wrapper {
            width: 100%;
            max-width: 440px;
            animation: pageRise 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.22);
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08);
            padding: 38px 30px 32px;
            animation: cardIn 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
        }

        /* ═══════ HERO / LOGO ═══════ */
        .logo-area {
            text-align: center;
            margin-bottom: 28px;
        }
        .logo-area .lock-box {
            width: 68px;
            height: 68px;
            border-radius: 18px;
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            color: var(--red);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 12px;
            box-shadow: 0 6px 18px rgba(239, 68, 68, 0.12);
        }
        .logo-area img {
            display: block;
            height: 42px;
            width: auto;
            margin: 0 auto 8px;
        }
        .logo-area h1 {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-1);
            letter-spacing: -0.2px;
        }
        .logo-area .subtitle {
            margin-top: 4px;
            font-size: 12px;
            font-weight: 500;
            color: var(--text-3);
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }

        /* ═══════ ERROR BAR ═══════ */
        .error-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid rgba(239, 68, 68, 0.22);
            background: var(--red-soft);
            margin-bottom: 22px;
            animation: shake 0.42s ease;
        }
        .error-bar i {
            color: var(--red);
            font-size: 15px;
            flex-shrink: 0;
        }
        .error-bar span {
            font-size: 12.5px;
            font-weight: 500;
            color: #991b1b;
            line-height: 1.4;
        }

        /* ═══════ FIELD ═══════ */
        .field {
            margin-bottom: 18px;
            animation: fadeUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .field:nth-of-type(1) { animation-delay: 0.15s; }
        .field:nth-of-type(2) { animation-delay: 0.22s; }
        .field-label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .input-wrap { position: relative; }
        .input-wrap .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-3);
            font-size: 14px;
            transition: color 0.25s ease;
            pointer-events: none;
        }
        .input-wrap input {
            width: 100%;
            padding: 13px 44px 13px 42px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-family: var(--font-sans);
            font-size: 14px;
            font-weight: 500;
            color: var(--text-1);
            background: #fff;
            outline: none;
            transition: all 0.25s cubic-bezier(0.22, 1, 0.36, 1);
        }
        .input-wrap input::placeholder {
            color: var(--text-3);
            font-weight: 400;
        }
        .input-wrap input:focus {
            border-color: rgba(239, 68, 68, 0.5);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }
        .input-wrap input:focus ~ .input-icon { color: var(--red); }

        /* Şifre toggle */
        .toggle-pw {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: var(--text-3);
            font-size: 15px;
            cursor: pointer;
            padding: 6px 8px;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        .toggle-pw:hover { color: var(--red); background: rgba(239, 68, 68, 0.06); }

        /* ═══════ REMEMBER ROW ═══════ */
        .remember-row {
            display: flex;
            align-items: center;
            margin-bottom: 22px;
            animation: fadeUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.29s both;
        }
        .remember-row input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--red);
            cursor: pointer;
            margin: 0;
        }
        .remember-row label {
            margin-left: 10px;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-2);
            cursor: pointer;
            user-select: none;
            transition: color 0.15s ease;
        }
        .remember-row label:hover { color: var(--red); }

        /* ═══════ SUBMIT BUTTON ═══════ */
        .btn-giris {
            width: 100%;
            padding: 14px 22px;
            border: none;
            border-radius: 12px;
            font-family: var(--font-sans);
            font-size: 14px;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--red,#ef4444);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.28);
            transition: all 0.25s cubic-bezier(0.22, 1, 0.36, 1);
            animation: fadeUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.35s both;
        }
        .btn-giris:hover {
            background: var(--red);
            transform: translateY(-1px);
            box-shadow: 0 10px 26px rgba(239, 68, 68, 0.38);
        }
        .btn-giris:active { transform: translateY(0); }
        .btn-giris i { font-size: 13px; transition: transform 0.25s ease; }
        .btn-giris:hover i { transform: translateX(3px); }

        /* ═══════ PORTAL DIVIDER ═══════ */
        .portal-divider {
            margin-top: 24px;
            padding-top: 22px;
            border-top: 1px dashed var(--border);
            text-align: center;
            animation: fadeUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.42s both;
        }
        .portal-divider p {
            font-size: 12px;
            color: var(--text-3);
            margin-bottom: 12px;
        }
        .btn-portal {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 11px 18px;
            border: 1px solid rgba(239, 68, 68, 0.25);
            border-radius: 12px;
            font-family: var(--font-sans);
            font-size: 13px;
            font-weight: 600;
            color: var(--red);
            background: #fff;
            text-decoration: none;
            transition: all 0.25s cubic-bezier(0.22, 1, 0.36, 1);
        }
        .btn-portal:hover {
            background: var(--red-soft);
            border-color: rgba(239, 68, 68, 0.45);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(239, 68, 68, 0.12);
        }
        .btn-portal i { font-size: 14px; }

        /* ═══════ FOOTER TEXT ═══════ */
        .login-footer {
            text-align: center;
            margin-top: 18px;
            font-size: 10.5px;
            color: var(--text-3);
            letter-spacing: 0.3px;
            animation: fadeUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.5s both;
        }

        /* ═══════ ANIMATIONS ═══════ */
        @keyframes pageRise {
            from { opacity: 0; transform: translateY(18px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 14px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-5px); }
            40% { transform: translateX(5px); }
            60% { transform: translateX(-3px); }
            80% { transform: translateX(3px); }
        }

        /* ═══════ RESPONSIVE ═══════ */
        @media (max-width: 480px) {
            body { padding: 14px; }
            .glass-card { padding: 32px 22px 26px; border-radius: 16px; }
            .logo-area .lock-box { width: 58px; height: 58px; font-size: 20px; }
            .logo-area img { height: 36px; }
            .logo-area h1 { font-size: 18px; }
            .input-wrap input {
                font-size: 16px; /* iOS zoom engeli */
                padding: 13px 42px 13px 40px;
            }
            .btn-giris { font-size: 14px; padding: 13px 20px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .login-wrapper, .glass-card, .logo-area, .field,
            .remember-row, .btn-giris, .portal-divider, .error-bar, .login-footer {
                animation: none !important;
                transition: none !important;
                transform: none !important;
                opacity: 1 !important;
            }
        }
    </style>
</head>
<body>

    <div class="login-wrapper">
        <div class="glass-card">

            <!-- Logo -->
            <div class="logo-area">
                <span class="lock-box"><i class="fa-solid fa-lock"></i></span>
                <img src="logo.png" alt="Lumen" onerror="this.style.display='none'">
                <h1>Lumen Siparis Sistemi</h1>
                <div class="subtitle">Guvenli Giris</div>
            </div>

            <!-- Hata mesaji -->
            <?php if ($error_message !== '' && $error_message !== '0'): ?>
                <div class="error-bar" role="alert">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <!-- Giris formu -->
            <form method="POST" action="">
                <div class="field">
                    <label for="kullaniciadi" class="field-label">Kullanici Adi</label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-user input-icon"></i>
                        <input type="text"
                               id="kullaniciadi"
                               name="kullaniciadi"
                               value="<?php echo htmlspecialchars((string) $ck_kullanici, ENT_QUOTES, 'UTF-8'); ?>"
                               required
                               placeholder="Kullanici adiniz"
                               autocomplete="username"
                               autocapitalize="characters"
                               spellcheck="false"
                               pattern="[A-Z0-9_.-]*"
                               autofocus>
                    </div>
                </div>

                <div class="field">
                    <label for="kullanicisifre" class="field-label">Sifre</label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-lock input-icon"></i>
                        <input type="password"
                               id="kullanicisifre"
                               name="kullanicisifre"
                               required
                               placeholder="Sifreniz"
                               autocomplete="current-password">
                        <button type="button" id="togglePassword" class="toggle-pw" aria-label="Sifreyi goster/gizle">
                            <i class="fa-solid fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="remember-row">
                    <input type="checkbox" id="hatirla" name="hatirla" <?php echo $hatirlakontrol; ?>>
                    <label for="hatirla">Beni Hatirla (30 gun)</label>
                </div>

                <button type="submit" class="btn-giris">
                    <span>Giris Yap</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>

        </div>

        <div class="login-footer">
            &copy; <?php echo date('Y'); ?> Lumen &middot; Tum haklari saklidir
        </div>
    </div>

<script>
    (function () {
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('kullanicisifre');
        const eyeIcon = document.getElementById('eyeIcon');
        const usernameInput = document.getElementById('kullaniciadi');
        function normalizeLoginUsername(value) {
            const map = {
                'ç': 'c', 'Ç': 'C',
                'ğ': 'g', 'Ğ': 'G',
                'ı': 'i', 'I': 'I',
                'İ': 'i', 'i': 'i',
                'ö': 'o', 'Ö': 'O',
                'ş': 's', 'Ş': 'S',
                'ü': 'u', 'Ü': 'U'
            };
            return value
                .replace(/[çÇğĞıIİiöÖşŞüÜ]/g, function (char) { return map[char] || char; })
                .replace(/[^A-Za-z0-9_.-]/g, '')
                .toUpperCase();
        }
        if (usernameInput) {
            usernameInput.addEventListener('input', function () {
                const normalized = normalizeLoginUsername(usernameInput.value);
                if (usernameInput.value !== normalized) {
                    usernameInput.value = normalized;
                }
            });
            usernameInput.value = normalizeLoginUsername(usernameInput.value);
        }
        if (togglePassword && passwordInput && eyeIcon) {
            togglePassword.addEventListener('click', function () {
                const isPassword = passwordInput.getAttribute('type') === 'password';
                passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
                eyeIcon.classList.toggle('fa-eye');
                eyeIcon.classList.toggle('fa-eye-slash');
            });
        }
    })();
</script>

</body>
</html>
