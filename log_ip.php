<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app_observability.php';

if (function_exists('app_php_error_log_file')) {
    @ini_set('log_errors', '1');
    @ini_set('error_log', app_php_error_log_file());
}

require_once __DIR__ . '/includes/oturum.php';
lumen_oturum_baslat();

// Kullanıcı bilgisi: öncelikle kullanıcı adı, yoksa kullanıcı ID, yoksa guest
if (isset($_SESSION['kullanici_adi'])) {
    $userId = $_SESSION['kullanici_adi'];
} elseif (isset($_SESSION['plasiyer_id'])) {
    $userId = $_SESSION['plasiyer_id'];
} else {
    $userId = 'guest';
}

// Sunucu değişkenleri (log injection koruması: newline karakterleri temizlenir)
$ipAddress  = str_replace(["\r", "\n"], '', $_SERVER['REMOTE_ADDR'] ?? '');
$requestUri = str_replace(["\r", "\n"], '', $_SERVER['REQUEST_URI'] ?? '');
$referrer   = str_replace(["\r", "\n"], '', $_SERVER['HTTP_REFERER'] ?? '');
$userAgent  = str_replace(["\r", "\n"], '', $_SERVER['HTTP_USER_AGENT'] ?? '');

// Zaman damgası ve log satırı
$timestamp = date('Y-m-d H:i:s');
$logLine   = "[$timestamp] IP:$ipAddress User:$userId Page:$requestUri Referrer:$referrer Agent:$userAgent\n";

// Log dosyası yolu (.env ile merkezi log kökü desteklenir)
$logFile = function_exists('app_access_log_file')
    ? app_access_log_file()
    : (__DIR__ . '/logs/access.log');
$logDir  = dirname($logFile);

// Klasör yoksa oluştur
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

// Eski kayıt temizliği artık zamanlanmış görevdir: scripts/log_hijyeni.php
// (eskiden günde bir kez bir kullanıcı isteği sırasında tüm log belleğe okunuyordu).

// Log satırını dosyaya ekle
@file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
