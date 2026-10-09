<?php
declare(strict_types=1);

/** POST /api/logout.php — geçerli Bearer tokenı iptal eder. */
include_once __DIR__ . '/../ayr.php';
include_once __DIR__ . '/_api.inc';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(['ok' => false, 'mesaj' => 'Yalnızca POST desteklenir.'], 405);
}
$oturum = api_oturum_gerekli($dbh);
try {
    api_token_iptal($dbh, api_bearer_token(), (int) $oturum['personel']);
} catch (Throwable $e) {
    error_log('API çıkış: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Oturum kapatılamadı.'], 503);
}
api_json(['ok' => true, 'mesaj' => 'Oturum kapatıldı.']);
