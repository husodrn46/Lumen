<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app_observability.php';

if (function_exists('app_php_error_log_file')) {
    @ini_set('log_errors', '1');
    @ini_set('error_log', app_php_error_log_file());
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

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

// 30 günden eski kayıtları temizle (günde 1 kez kontrol et)
$cleanupFlag = $logDir . '/.last_cleanup';
$shouldCleanup = false;

if (!file_exists($cleanupFlag)) {
    $shouldCleanup = true;
} else {
    $lastCleanup = filemtime($cleanupFlag);
    // 24 saat geçtiyse tekrar temizle
    if (time() - $lastCleanup > 86400) {
        $shouldCleanup = true;
    }
}

if ($shouldCleanup && file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $cutoffDate = date('Y-m-d H:i:s', strtotime('-30 days'));
    $newLines = [];

    foreach ($lines as $line) {
        // Satırdan tarih çıkar: [2025-08-06 16:15:48]
        if (preg_match('/^\[([^\]]+)\]/', $line, $matches)) {
            $logDate = $matches[1];
            // 30 günden yeniyse tut
            if ($logDate >= $cutoffDate) {
                $newLines[] = $line;
            }
        }
    }

    // Temizlenmiş logları geri yaz
    if (count($newLines) < count($lines)) {
        file_put_contents($logFile, implode("\n", $newLines) . "\n", LOCK_EX);
    }

    // Temizlik zamanını kaydet
    touch($cleanupFlag);
}

// Log satırını dosyaya ekle
@file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
