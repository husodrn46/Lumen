<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/support/TestPdo.php';
require __DIR__ . '/../api/_api.inc';
/** Load only named production permission functions, never the app bootstrap/DB. */
function load_permission_function(string $wanted): void
{
    $source = file_get_contents(__DIR__ . '/../ayr.php');
    $tokens = token_get_all($source);
    $offset = 0; $start = null; $namePending = false; $selected = false; $depth = 0;
    foreach ($tokens as $token) {
        $text = is_array($token) ? $token[1] : $token;
        if (is_array($token) && $token[0] === T_FUNCTION) { $start = $offset; $namePending = true; }
        elseif ($namePending && is_array($token) && $token[0] === T_STRING) {
            $selected = $text === $wanted; $namePending = false;
        }
        if ($selected && !is_array($token)) {
            if ($text === '{') { $depth++; }
            elseif ($text === '}' && --$depth === 0) {
                eval(substr($source, $start, $offset + strlen($text) - $start));
                return;
            }
        }
        $offset += strlen($text);
    }
    throw new RuntimeException('Production permission function not found: ' . $wanted);
}
foreach (['m_p_yetki_tum_sutunlar', 'm_p_yetki_izin_sutunu_mu', 'm_p_yetki_satiri_getir', 'm_p_yetki', 'm_p_yetki_cache_temizle'] as $fn) { load_permission_function($fn); }
$checks = 0;
function permission_check(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) { throw new RuntimeException($message); } }
$record = ['YETKI' => 1, 'M1' => 0, 'M17' => 0];
$dbh = new TestPdo(static function (string $sql) use (&$record): array {
    if (str_contains($sql, 'INFORMATION_SCHEMA.COLUMNS')) { return ['rows' => [['DATA_TYPE' => 'varchar', 'CHARACTER_MAXIMUM_LENGTH' => 128]]]; }
    if (str_contains($sql, 'INFORMATION_SCHEMA.TABLES')) { return ['rows' => [[1]]]; }
    if (str_starts_with($sql, 'SELECT TOP 1 T.PERSONEL')) { return ['rows' => [['PERSONEL' => 7, 'KULLANICI_ADI' => 'DEMO', 'SAKLANAN_TOKEN' => str_repeat('a', 64)]]]; }
    if (str_starts_with($sql, 'UPDATE T SET')) { return ['affected' => 1]; }
    if (str_contains($sql, 'SELECT * FROM M_P_YETKI')) { return ['rows' => $record === null ? [] : [$record]]; }
    throw new RuntimeException('Unknown fixture SQL');
});
$firmano = 2; $firma = 'LG_002_'; $firmadonem = 'LG_002_03_'; $firmadonemx = 'LV_002_03_';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat('a', 64);
$_SESSION = ['yetki_row_7' => ['YETKI' => 0, 'M1' => 1], 'yetki_row_time_7' => time(), 'yetki_7_M1' => 1, 'yetki_time_7_M1' => time(), 'untouched' => 'other session content'];
permission_check(m_p_yetki(7, 'M1') === 1, 'Fixture reproduces stale web cache before API authentication');
permission_check(api_oturum_gerekli($dbh)['personel'] === 7, 'API auth uses real helper, synthetic DB');
permission_check(!api_yetki_var(7, 'M1'), 'Revoked M1 must not inherit web cookie-session cache');
permission_check(!api_yetki_var(7, 'M17'), 'Regular user module disabled');
permission_check($_SESSION['untouched'] === 'other session content', 'Cache clear scoped to authenticated person');
$record = ['YETKI' => 0, 'M1' => 0, 'M17' => 0];
api_oturum_gerekli($dbh);
permission_check(api_yetki_var(7, 'M17'), 'Existing admin bypass semantics preserved');
$before = count($dbh->events);
permission_check(!api_yetki_var(7, 'M17]; DROP TABLE X'), 'Unknown permission identifier rejected');
permission_check(count($dbh->events) === $before, 'Unknown identifier cannot reach SQL');
$record = null; api_oturum_gerekli($dbh);
permission_check(!api_yetki_var(7, 'M1'), 'Missing permission row fails closed');
echo "TAMAM: {$checks} permission assertion (gerçek izin/cache fonksiyonları, PDO sentetik).\n";
