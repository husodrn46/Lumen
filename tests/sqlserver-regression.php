<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Opt-in: yalnız localhost'ta, boş LumenTest_* veritabanında sentetik veri yazar. */
require __DIR__ . '/../siparis/kayit_lib.php';
require __DIR__ . '/../api/_api.inc';
require __DIR__ . '/support/sqlserver-environment.php';

function sql_check(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
function insert_header(PDO $pdo, int $job, bool $fail = false): int {
    try {
        $pdo->beginTransaction();
        $number = siparis_baslik_numarasi_ayir($pdo, 'LG_999_99_', 'SIP');
        $s = siparis_baslik_insert_hazirla($pdo, 'INSERT INTO LG_999_99_ORFICHE (FICHENO, JOB) VALUES (:n, :j)');
        $s->execute([':n' => $number, ':j' => $job]);
        $id = siparis_baslik_eklenen_id($s);
        $s = $pdo->prepare('INSERT INTO LG_999_99_ORFLINE (ORDFICHEREF, JOB) VALUES (:id, :j)');
        $s->execute([':id' => $id, ':j' => $job]); $s->closeCursor();
        if ($fail) { throw new RuntimeException('Sentetik satır-ortası hatası'); }
        siparis_baslik_numarasini_kesinlestir($pdo, 'LG_999_99_', $id, 'SIP');
        usleep(10000);
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

$pdo = lumen_test_sql_connection(true);
if (($argv[1] ?? '') === '--token-worker') {
    $firmano = 999; $firma = 'LG_999_'; $firmadonem = 'LG_999_99_'; $firmadonemx = 'LV_999_99_';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat('a', 64);
    sql_check(api_token_dogrula($pdo)['personel'] === 1, 'Parallel legacy token auth');
    echo 'ok';
    exit;
}
if (($argv[1] ?? '') === '--worker') {
    $id = insert_header($pdo, (int) ($argv[2] ?? 0));
    echo $id;
    exit;
}
sql_check((int) $pdo->query('SELECT COUNT(*) FROM sys.tables WHERE is_ms_shipped = 0')->fetchColumn() === 0,
    'Test boş bir DB ister; mevcut şema silinmez veya yeniden kullanılmaz');
$fixture = file_get_contents(__DIR__ . '/fixtures/sqlserver-startup.sql');
if ($fixture === false) { throw new RuntimeException('Fixture okunamadı'); }
foreach (preg_split('/^\s*GO\s*;?\s*$/mi', $fixture) as $batch) {
    if (trim($batch) !== '') { $pdo->exec(trim($batch)); }
}
try { insert_header($pdo, -1, true); throw new LogicException('Sentetik hata oluşmadı'); }
catch (RuntimeException $e) { sql_check($e->getMessage() === 'Sentetik satır-ortası hatası', 'Rollback fixture farklı hata verdi'); }
sql_check((int) $pdo->query('SELECT COUNT(*) FROM LG_999_99_ORFICHE')->fetchColumn() === 0, 'Rollback başlığı temizlemeli');
sql_check((int) $pdo->query('SELECT COUNT(*) FROM LG_999_99_ORFLINE')->fetchColumn() === 0, 'Rollback satırı temizlemeli');
$jobs = [];
for ($job = 1; $job <= 20; $job++) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--worker', (string) $job], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Test worker açılamadı'); }
    fclose($pipes[0]); $jobs[] = [$process, $pipes];
}
foreach ($jobs as [$process, $pipes]) {
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    sql_check(proc_close($process) === 0 && ctype_digit($out), 'Paralel worker başarısız: ' . $err);
}
sql_check((int) $pdo->query('SELECT COUNT(*) FROM LG_999_99_ORFICHE')->fetchColumn() === 20, '20 paralel başlık');
sql_check((int) $pdo->query('SELECT COUNT(*) FROM LG_999_99_ORFLINE')->fetchColumn() === 20, '20 paralel satır');
sql_check((int) $pdo->query('SELECT COUNT(*) FROM LG_999_99_ORFLINE L JOIN LG_999_99_ORFICHE F ON F.LOGICALREF = L.ORDFICHEREF WHERE F.JOB <> L.JOB')->fetchColumn() === 0, 'Başka isteğin başlığına satır bağlanmamalı');
foreach ($pdo->query('SELECT LOGICALREF, FICHENO FROM LG_999_99_ORFICHE')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    sql_check($row['FICHENO'] === siparis_fis_numarasi('SIP', (int) $row['LOGICALREF']), 'Gerçek kimlik ve numara uyuşmalı');
}
// Token yaşam döngüsü, üretim oturum altyapısından bağımsız sentetik tablolarla.
// Synthetic legacy schema: guard must fail before token write; fixture-only ALTER.
$legacy = str_repeat('a', 64);
$seed = $pdo->prepare("INSERT INTO M_API_TOKEN (TOKEN, PERSONEL, KULLANICI_ADI) VALUES (:t, 1, 'DEMO')");
$seed->execute([':t' => $legacy]); $seed->closeCursor();
try { api_token_sema_kontrol($pdo); throw new LogicException('Short schema gate did not fail'); }
catch (RuntimeException $e) { sql_check((int) $pdo->query('SELECT COUNT(*) FROM M_API_TOKEN')->fetchColumn() === 1, 'Schema gate must preserve legacy row'); }
// This ALTER is ONLY for the known short/no-index synthetic table in this fixture.
// It is not a production migration script.
$pdo->exec('ALTER TABLE dbo.M_API_TOKEN ALTER COLUMN TOKEN VARCHAR(128) NOT NULL');
api_token_sema_kontrol($pdo);
$pdo->exec('CREATE INDEX IX_M_API_TOKEN_TOKEN ON dbo.M_API_TOKEN (TOKEN)');
sql_check($pdo->query('SELECT TOKEN FROM M_API_TOKEN')->fetchColumn() === $legacy, 'Fixture schema upgrade preserves raw token');
$firmano = 999; $firma = 'LG_999_'; $firmadonem = 'LG_999_99_'; $firmadonemx = 'LV_999_99_';
// Two concurrent workers must accept the same raw legacy token while upgrading it.
$tokenJobs = [];
for ($job = 0; $job < 2; $job++) {
    $pipes = [];
    $worker = proc_open([PHP_BINARY, __FILE__, '--token-worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    sql_check(is_resource($worker), 'Token worker spawn');
    fclose($pipes[0]); $tokenJobs[] = [$worker, $pipes];
}
foreach ($tokenJobs as [$worker, $pipes]) {
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    sql_check(proc_close($worker) === 0 && $out === 'ok', 'Concurrent legacy token worker failed: ' . $err);
}
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $legacy;
sql_check(api_token_dogrula($pdo)['personel'] === 1, 'Legacy bearer survives schema preparation');
sql_check($pdo->query('SELECT TOKEN FROM M_API_TOKEN')->fetchColumn() === api_token_saklama_degeri($legacy), 'Legacy bearer upgraded to marked hash');
$token = api_token_olustur($pdo, 1, 'DEMO');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
sql_check(api_token_dogrula($pdo)['personel'] === 1, 'Token kullanılabilir');
$stored = $pdo->query('SELECT TOP 1 TOKEN FROM M_API_TOKEN ORDER BY ID DESC')->fetchColumn();
sql_check($stored === api_token_saklama_degeri($token), 'Token DB kaydı hash olmalı');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . substr($stored, 7);
sql_check(api_token_dogrula($pdo) === null, 'DB hash replay reddi');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
$firmano = 998; $firma = 'LG_998_'; $firmadonem = 'LG_998_99_'; $firmadonemx = 'LV_998_99_';
sql_check(api_token_dogrula($pdo) === null, 'Başka firma token reddi'); $firmano = 999; $firma = 'LG_999_'; $firmadonem = 'LG_999_99_'; $firmadonemx = 'LV_999_99_';
$pdo->exec('UPDATE LG_SLSMAN SET ACTIVE = 1'); sql_check(api_token_dogrula($pdo) === null, 'Pasif kullanıcı token reddi');
$pdo->exec('UPDATE LG_SLSMAN SET ACTIVE = 0');
$pdo->exec('INSERT INTO M_OTURUM_KAPAT VALUES (1, DATEADD(SECOND, 1, GETDATE()))');
sql_check(api_token_dogrula($pdo) === null, 'Force-logout API tokenını da reddetmeli');
$pdo->exec('UPDATE M_OTURUM_KAPAT SET KAPATMA_TS = NULL');
api_token_iptal($pdo, $token, 1); sql_check(api_token_dogrula($pdo) === null, 'Çıkış sonrası token reddi');
echo "TAMAM: sentetik SQL Server trigger/20 paralel kayıt/rollback/token yaşam döngüsü. Test DB korunur; otomatik DROP yok.\n";
