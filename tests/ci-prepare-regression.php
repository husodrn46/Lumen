<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/ci/sqlserver-prepare-lib.php';
require __DIR__.'/support/TestPdo.php';
$count=0;
function ci_check(bool $ok,string $label):void{global $count;$count++;if(!$ok){throw new RuntimeException($label);}}
function ci_reject(Closure $fn):void{try{$fn();}catch(RuntimeException $e){ci_check(true,'rejected');return;}throw new LogicException('Expected rejection');}
$env=['GITHUB_ACTIONS'=>'true','RUNNER_OS'=>'Linux','RUNNER_ARCH'=>'X64','RUNNER_ENVIRONMENT'=>'github-hosted',
    'GITHUB_REPOSITORY'=>'husodrn46/Lumen','GITHUB_EVENT_NAME'=>'workflow_dispatch',
    'LUMEN_CI_SQL_PREPARE'=>'synthetic-ci-approved','GITHUB_RUN_ID'=>'123','GITHUB_RUN_ATTEMPT'=>'1',
    'LUMEN_CI_SQL_SA_PASSWORD'=>'LumenCI-123-1-aA1!','RUNNER_TEMP'=>'/home/runner/work/_temp',
    'GITHUB_ENV'=>'/home/runner/work/_temp/_runner_file_commands/set_env_test'];
$context=lumen_ci_sql_context($env);
ci_check($context['databases']===['LumenTest_Start_CI','LumenTest_Idem_CI'],'Fixed DB allowlist');
ci_check(str_starts_with($context['dsn'],'sqlsrv:Server=localhost,1433;Database=master;'),'Admin localhost only');
foreach(['GITHUB_ACTIONS','RUNNER_OS','RUNNER_ARCH','RUNNER_ENVIRONMENT','GITHUB_REPOSITORY','GITHUB_EVENT_NAME','LUMEN_CI_SQL_PREPARE','GITHUB_RUN_ID','GITHUB_RUN_ATTEMPT','LUMEN_CI_SQL_SA_PASSWORD','GITHUB_ENV','RUNNER_TEMP'] as $key){
    $bad=$env;unset($bad[$key]);ci_reject(fn()=>lumen_ci_sql_context($bad));
}
foreach(['123;DROP','1e2','-1','0','1_1'] as $id){$bad=$env;$bad['GITHUB_RUN_ID']=$id;ci_reject(fn()=>lumen_ci_sql_context($bad));}
$password='aA1!'.str_repeat('b',64);
$empty=new TestPdo(static fn(string $s,array $p):array=>['rows'=>[[0]]]);
lumen_ci_sql_prepare($empty,$context,$password);
ci_check(count($empty->events)===6,'Read + login + exactly two DB/user setup batches');
$sql=implode("\n",array_column($empty->events,'sql'));
ci_check(substr_count($sql,'CREATE DATABASE [LumenTest_')===2,'Only fixed synthetic DBs created');
ci_check(!str_contains($sql,'sysadmin')&&!str_contains($sql,'DROP ')&&!str_contains($sql,'dbcreator'),'No server grant or teardown');
ci_check(substr_count($sql,'GRANT VIEW DEFINITION')===2,'Metadata visibility in fixture DBs');
$existing=new TestPdo(static fn(string $s,array $p):array=>['rows'=>[[1]]]);
ci_reject(fn()=>lumen_ci_sql_prepare($existing,$context,$password));
ci_check(count($existing->events)===1,'Existing DB rejected before mutation');
foreach(['user'=>'evil];DROP','databases'=>['LOGO_PRODUCTION']] as $key=>$value){$bad=$context;$bad[$key]=$value;ci_reject(fn()=>lumen_ci_sql_prepare($empty,$bad,$password));}
ci_reject(fn()=>lumen_ci_sql_prepare($empty,$context,"password';DROP"));
echo "TAMAM: {$count} CI preparation guard assertion (PDO double; SQL not executed).\n";
