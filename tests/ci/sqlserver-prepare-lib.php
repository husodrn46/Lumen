<?php
declare(strict_types=1);

/** Fixed CI-only configuration; never accepts a caller-supplied host or DB name. */
function lumen_ci_sql_context(array $env): array
{
    foreach (['GITHUB_ACTIONS'=>'true', 'RUNNER_OS'=>'Linux', 'RUNNER_ARCH'=>'X64', 'RUNNER_ENVIRONMENT'=>'github-hosted',
        'GITHUB_REPOSITORY'=>'husodrn46/Lumen', 'GITHUB_EVENT_NAME'=>'workflow_dispatch',
        'LUMEN_CI_SQL_PREPARE'=>'synthetic-ci-approved'] as $key=>$expected) {
        if (($env[$key] ?? '') !== $expected) { throw new RuntimeException('CI environment gate rejected.'); }
    }
    foreach (['GITHUB_RUN_ID','GITHUB_RUN_ATTEMPT'] as $key) {
        if (!is_string($env[$key] ?? null) || !preg_match('/\A[1-9][0-9]{0,19}\z/', $env[$key])) {
            throw new RuntimeException('CI run identity gate rejected.');
        }
    }
    $expected = 'LumenCI-' . $env['GITHUB_RUN_ID'] . '-' . $env['GITHUB_RUN_ATTEMPT'] . '-aA1!';
    if (!is_string($env['LUMEN_CI_SQL_SA_PASSWORD'] ?? null) || !hash_equals($expected,$env['LUMEN_CI_SQL_SA_PASSWORD'])) {
        throw new RuntimeException('CI bootstrap credential gate rejected.');
    }
    $runnerTemp=$env['RUNNER_TEMP'] ?? '';
    $export=$env['GITHUB_ENV'] ?? '';
    if (!is_string($runnerTemp) || $runnerTemp === '' || !is_string($export)
        || !str_starts_with($export, '/home/runner/work/_temp/_runner_file_commands/')) {
        throw new RuntimeException('CI output file gate rejected.');
    }
    return ['dsn'=>'sqlsrv:Server=127.0.0.1,1433;Database=master;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=5',
        'password'=>$expected, 'user'=>'lumen_ci_' . $env['GITHUB_RUN_ID'] . '_' . $env['GITHUB_RUN_ATTEMPT'],
        'databases'=>['LumenTest_Start_CI','LumenTest_Idem_CI','LumenTest_Fav_CI'], 'export'=>$export];
}

/** Names are fixed/validated before reaching this function; no existing DB is reused. */
function lumen_ci_sql_prepare(PDO $pdo, array $context, string $password, string $runtimePassword): void
{
    $stmt=$pdo->query("SELECT COUNT(*) FROM sys.databases WHERE name IN ('LumenTest_Start_CI','LumenTest_Idem_CI','LumenTest_Fav_CI')");
    $exists=(int)$stmt->fetchColumn();$stmt->closeCursor();
    if ($exists !== 0) { throw new RuntimeException('CI database already exists; nothing is dropped or reused.'); }
    if (!preg_match('/\Alumen_ci_[1-9][0-9]{0,19}_[1-9][0-9]{0,19}\z/',$context['user'])
        || !preg_match('/\AaA1![a-f0-9]{64}\z/',$password)
        || !preg_match('/\AaA1![a-f0-9]{64}\z/',$runtimePassword)
        || $context['databases'] !== ['LumenTest_Start_CI','LumenTest_Idem_CI','LumenTest_Fav_CI']) {
        throw new RuntimeException('CI account/name gate rejected.');
    }
    $user=$context['user'];
    // Fixed ephemeral account; no production login, GRANT server role or CREATE DATABASE right.
    $pdo->exec("CREATE LOGIN [{$user}] WITH PASSWORD='{$password}', CHECK_POLICY=ON");
    foreach ($context['databases'] as $db) {
        $pdo->exec("CREATE DATABASE [{$db}]");
        $pdo->exec("USE [{$db}]; CREATE USER [{$user}] FOR LOGIN [{$user}];
            ALTER ROLE db_ddladmin ADD MEMBER [{$user}];
            ALTER ROLE db_datareader ADD MEMBER [{$user}];
            ALTER ROLE db_datawriter ADD MEMBER [{$user}];
            GRANT VIEW DEFINITION TO [{$user}]");
    }
    $runtimeUser=$user.'_fav';
    $pdo->exec("CREATE LOGIN [{$runtimeUser}] WITH PASSWORD='{$runtimePassword}', CHECK_POLICY=ON");
    $pdo->exec("USE [LumenTest_Fav_CI]; CREATE USER [{$runtimeUser}] FOR LOGIN [{$runtimeUser}]");
}
