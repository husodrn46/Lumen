<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
require __DIR__ . '/TestPdo.php';
require __DIR__ . '/../../siparis/web_intent.php';
session_start();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$_SESSION['fixture'] ??= ['headers'=>[], 'receipts'=>[], 'next'=>40];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/stats') { header('Content-Type: application/json'); echo json_encode($_SESSION['fixture']); exit; }
if ($path === '/siparis/web_intent.js') { header('Content-Type: text/javascript'); readfile(__DIR__.'/../../siparis/web_intent.js'); exit; }
if (!in_array($path, ['/siparis/fisekle.php','/doviz/fisekle.php'], true)) { http_response_code(404); exit; }
// Synthetic login/config/permissions. Production endpoint, intent/transaction and SQL helpers run unchanged.
const APP_ROOT_URL = '';
$terminalkullanici = (int)($_GET['person'] ?? 7); $yetkidurum = 1;
$firmano = 2; $firma = 'LG_002_'; $firmadonem = 'LG_002_03_';
$depo = 0; $sipno = 'WEB'; $fiyatgruplu = 1;
$kayitsaat = 1; $saat=1; $dakika=0; $saniye=0;
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="'.$_SESSION['csrf_token'].'">'; }
function csrf_verify(): bool { return is_string($_POST['csrf_token'] ?? null) && hash_equals($_SESSION['csrf_token'],$_POST['csrf_token']); }
function m_p_yetki(mixed $p, string $m): int { return isset($_GET['deny']) ? 0 : 1; }
function m_p_yetki_cache_temizle(int $p): void {}
function m_p_cariid_goruntulebilir_mi(mixed ...$args): bool { return !isset($_GET['deny_cari']); }
function m_p_siparis_goruntulebilir_mi(mixed ...$args): bool { return true; }
function migrateUrlToSession(array $keys): void { foreach ($keys as $key) { if (isset($_GET[$key])) { $_SESSION['page_params']['fisekle'][$key]=$_GET[$key]; } } }
function getPageParamInt(string $key): int { return (int)($_SESSION['page_params']['fisekle'][$key] ?? 0); }
function guid(): string { return 'synthetic-guid'; }
function logFisOlusturma(mixed ...$args): bool { if (isset($_GET['audit_fail'])) { throw new RuntimeException('Synthetic audit failure'); } return true; }
$mode = $_GET['mode'] ?? '';
$snapshot = null;
$dbh = new TestPdo(static function(string $sql, array $params) use ($mode): array {
    $state = &$_SESSION['fixture'];
    if (str_contains($sql, 'SELECT TOP 0 KEYHASH')) {
        if ($mode === 'missing') { throw new RuntimeException('Synthetic missing schema'); }
        return ['rows'=>[]];
    }
    if (str_contains($sql, 'sys.key_constraints')) { return ['rows'=>[[1]]]; }
    if (str_contains($sql, 'sp_getapplock')) { return ['rows'=>[['LUMEN_LOCK_RESULT'=>$mode === 'busy' ? -1 : 0]]]; }
    if (str_starts_with($sql, 'SELECT FIRMA, DONEM')) { return ['rows'=>isset($state['receipts'][$params[':key']]) ? [$state['receipts'][$params[':key']]] : []]; }
    if (str_starts_with($sql, 'INSERT INTO dbo.M_API_IDEMPOTENCY')) {
        if ($mode === 'receipt_fail') { throw new RuntimeException('Synthetic receipt failure'); }
        $state['receipts'][$params[':key']] = ['FIRMA'=>$params[':firma'], 'DONEM'=>$params[':donem'], 'PERSONEL'=>$params[':personel'], 'KAPSAM'=>$params[':scope'], 'BODYHASH'=>$params[':hash'], 'YANIT'=>$params[':response']];
        return ['affected'=>1];
    }
    if (str_contains($sql, 'MAX(LOGICALREF)')) { return ['rows'=>[[$state['next']+1]]]; }
    if (str_starts_with($sql, 'DECLARE @lumen_eklenen')) {
        $id = ++$state['next']; $state['headers'][$id]=$params;
        return ['rows'=>[['LUMEN_INSERTED_ID'=>$id]]];
    }
    if (str_contains($sql, 'SET FICHENO')) { return ['affected'=>1]; }
    if (str_contains($sql, 'SELECT CODE, DEFINITION_, CITY')) { return ['rows'=>[['CODE'=>'TEST', 'DEFINITION_'=>'Sentetik müşteri', 'CITY'=>'TEST']]]; }
    if (str_contains($sql, 'L_DAILYEXCHANGES')) { return ['rows'=>[['KUR'=>32.5]]]; }
    throw new RuntimeException('Unknown synthetic SQL');
}, static function(string $event) use (&$snapshot, $mode): void {
    if ($event === 'begin') { $snapshot = $_SESSION['fixture']; }
    if ($event === 'rollback') { $_SESSION['fixture'] = $snapshot; }
    if ($event === 'commit' && $mode === 'acklost') { throw new RuntimeException('Synthetic lost commit acknowledgement'); }
});
$file = __DIR__.'/../..'.$path;
$code = file_get_contents($file);
$code = preg_replace('/^include(?:_once)?\([^\n]+;\s*$/m', '', $code);
// Omit external layout includes; preserve endpoint HTML/form and script reference.
$code = preg_replace('/<\?php if \(file_exists\(__DIR__[^\n]+\?>/', '', $code);
$code = preg_replace('/<\?php include_once\(__DIR__[^\n]+\?>/', '', $code);
$code = str_replace('__DIR__', var_export(dirname(realpath($file)), true), $code);
$code = preg_replace('/\A<\?php\s*declare\(strict_types=1\);/', '', $code);
eval($code);
