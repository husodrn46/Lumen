<?php

declare(strict_types=1);

/**
 * js_hata_log.php — Tarayici (frontend) JS hatalarini app-error loguna yazar.
 * pwa-header.php icindeki window.onerror / unhandledrejection yakalayicilari
 * buraya JSON POST eder. Hafif tutulur (ayr.php / DB yuklenmez).
 */

require_once __DIR__ . '/includes/app_observability.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$d = json_decode($raw, true);
if (!is_array($d)) {
    $d = $_POST;
}

$kes = static fn($v, int $n): string => mb_substr(trim((string) ($v ?? '')), 0, $n);

$mesaj = $kes($d['message'] ?? '', 1000);
if ($mesaj === '') {
    echo json_encode(['ok' => false]);
    exit;
}

// Personel kimligi (varsa) — hafif, DB yok
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$personel = isset($_SESSION['plasiyer_id']) ? (int) $_SESSION['plasiyer_id'] : null;

if (function_exists('app_log_write') && function_exists('app_error_ref')) {
    app_log_write('app-error', [
        'ref' => app_error_ref(),
        'scope' => 'js-error',
        'message' => $mesaj,
        'code' => 0,
        'file' => $kes($d['source'] ?? '', 300),
        'line' => (int) ($d['line'] ?? 0),
        'context' => [
            'kind' => 'js_error',
            'col' => (int) ($d['col'] ?? 0),
            'stack' => $kes($d['stack'] ?? '', 2000),
            'personel' => $personel,
        ],
        'request' => [
            'method' => 'JS',
            'uri' => $kes($d['page'] ?? '', 300),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $kes($_SERVER['HTTP_USER_AGENT'] ?? '', 400),
        ],
        'trace' => '',
    ]);
}

echo json_encode(['ok' => true]);
