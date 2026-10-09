<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/sqlserver-prepare-lib.php';
$keys=['GITHUB_ACTIONS','RUNNER_OS','RUNNER_ARCH','RUNNER_ENVIRONMENT','GITHUB_REPOSITORY','GITHUB_EVENT_NAME',
    'LUMEN_CI_SQL_PREPARE','GITHUB_RUN_ID','GITHUB_RUN_ATTEMPT','LUMEN_CI_SQL_SA_PASSWORD','RUNNER_TEMP','GITHUB_ENV'];
$env=[];foreach($keys as $key){$env[$key]=getenv($key);}
$phase='environment'; $sqlState='unavailable';
try {
    $context=lumen_ci_sql_context($env);
    if (PHP_VERSION_ID < 80300 || phpversion('pdo_sqlsrv') !== '5.13.3') { throw new RuntimeException('PHP 8.3/pdo_sqlsrv missing.'); }
    $export=realpath($context['export']);$temp=realpath($env['RUNNER_TEMP']);
    if ($export===false || $temp===false || !str_starts_with($export,$temp.'/') || !is_writable($export)) {
        throw new RuntimeException('CI output path cannot be verified.');
    }
    // Container TCP health isn't SQL/login readiness; wait boundedly without printing driver errors.
    $phase='sql-readiness';
    $pdo=null;
    for($i=0;$i<30;$i++) {
        try {
            $pdo=new PDO($context['dsn'],'sa',$context['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $stmt=$pdo->query('SELECT 1');$stmt->closeCursor();break;
        } catch(Throwable $e) {
            $code=(string)$e->getCode();
            $sqlState=preg_match('/\A[A-Z0-9]{5}\z/',$code) ? $code : 'unavailable';
            $pdo=null; usleep(2000000);
        }
    }
    if ($pdo===null) { throw new RuntimeException('Disposable CI SQL did not become ready.'); }
    $phase='sql-version';
    $info=$pdo->query("SELECT CAST(SERVERPROPERTY('ProductVersion') AS VARCHAR(50)) AS VERSION,
        CAST(SERVERPROPERTY('Edition') AS VARCHAR(100)) AS EDITION");
    $server=$info->fetch(PDO::FETCH_ASSOC);$info->closeCursor();
    if (!$server || !str_starts_with((string)$server['VERSION'],'16.') || !str_contains((string)$server['EDITION'],'Developer')) {
        throw new RuntimeException('Expected disposable SQL 2022 Developer.');
    }
    echo 'CI SQL version: '.$server['VERSION'].', edition: '.$server['EDITION']."\n";
    $password='aA1!'.bin2hex(random_bytes(32));
    echo '::add-mask::'.$password."\n";
    $runtimePassword='aA1!'.bin2hex(random_bytes(32));
    echo '::add-mask::'.$runtimePassword."\n";
    $phase='fresh-database';
    lumen_ci_sql_prepare($pdo,$context,$password,$runtimePassword);
    $phase='credential-export';
    $values="LUMEN_TEST_SQL_USER={$context['user']}\nLUMEN_TEST_SQL_PASS={$password}\nLUMEN_TEST_FAVORITE_USER={$context['user']}_fav\nLUMEN_TEST_FAVORITE_PASS={$runtimePassword}\n";
    $written=file_put_contents($export,$values,FILE_APPEND|LOCK_EX);
    if ($written!==strlen($values)) { throw new RuntimeException('CI test account export failed.'); }
    echo "TAMAM: three fresh synthetic CI DBs and database-limited account prepared.\n";
} catch(Throwable $e) {
    // No driver exception, SQL text, DSN credential or account password in output.
    fwrite(STDERR,"ENGEL: CI preparation phase={$phase}, SQLSTATE={$sqlState}; no credential or driver message printed; no test pass claimed.\n");
    exit(2);
}
