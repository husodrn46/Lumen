<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
// Private test router. Never delegates to the app/document root or loads ayr.php.
require __DIR__ . '/TestPdo.php';
require __DIR__ . '/../../api/_api.inc';
require __DIR__ . '/../../api/_idempotency.inc';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$firmano = 2; $firma = 'LG_002_'; $firmadonem = 'LG_002_03_'; $firmadonemx = 'LV_002_03_';
$token = str_repeat('a', 64);
$mode = $_GET['mode'] ?? 'legacy';
if ($mode === 'wrong-scope') { $firma = 'LG_001_'; }
$dbh = new TestPdo(static function (string $sql, array $params) use ($token, $mode): array {
    if ($mode === 'unavailable') { throw new RuntimeException('Synthetic infrastructure failure'); }
    if (str_contains($sql, 'INFORMATION_SCHEMA.COLUMNS')) { return ['rows' => [['DATA_TYPE' => 'varchar', 'CHARACTER_MAXIMUM_LENGTH' => $mode === 'short-schema' ? 64 : 128]]]; }
    if (str_contains($sql, 'INFORMATION_SCHEMA.TABLES')) { return ['rows' => [[1]]]; }
    if (str_contains($sql, 'SELECT TOP 1 T.PERSONEL')) {
        if (($params[':t'] ?? '') !== $token || $mode === 'invalid') { return ['rows' => []]; }
        return ['rows' => [['PERSONEL' => 7, 'KULLANICI_ADI' => 'DEMO',
            'SAKLANAN_TOKEN' => $mode === 'legacy' ? $token : api_token_saklama_degeri($token)]]];
    }
    if (str_starts_with($sql, 'UPDATE T SET')) { return ['affected' => $mode === 'revoked-during-auth' ? 0 : 1]; }
    if (str_contains($sql, 'UPDATE dbo.M_API_TOKEN')) { return ['affected' => 1]; }
    if (str_contains($sql, 'INSERT INTO dbo.M_API_TOKEN')) { return ['affected' => 1]; }
    throw new RuntimeException('Unknown synthetic SQL');
});
if ($path === '/idempotency-protocol') {
    $input = api_body();
    try { $key = siparis_idempotency_anahtari(); }
    catch (InvalidArgumentException $e) { api_json(['ok'=>false],400); }
    api_json(['ok'=>true,'key'=>$key,'hash'=>$key === null ? null : siparis_idempotency_parmakizi($input)]);
}
if ($path === '/body') { api_json(['ok' => true, 'body' => api_body()]); }
if ($path === '/bearer') { api_json(['ok' => true, 'token' => api_bearer_token()]); }
if ($path === '/issue') {
    $new = api_token_olustur($dbh, 7, 'DEMO');
    api_json(['ok' => true, 'token' => $new, 'stored' => end($dbh->events)['params'][':t']]);
}
if ($path === '/auth') {
    $session = api_oturum_gerekli($dbh);
    api_json(['ok' => true, 'session' => $session, 'events' => $dbh->events]);
}
if ($path === '/logout') {
    // Real endpoint body; only production bootstrap includes are removed.
    $code = file_get_contents(__DIR__ . '/../../api/logout.php');
    $code = preg_replace('/^include_once [^\n]+;\s*$/m', '', $code);
    $code = preg_replace('/\A<\?php\s*declare\(strict_types=1\);/', '', $code);
    eval($code);
}
api_json(['ok' => false, 'mesaj' => 'Fixture route absent'], 404);
