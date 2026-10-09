<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/sqlserver-prepare-lib.php';
try{
 $env=[];foreach(['GITHUB_ACTIONS','RUNNER_OS','RUNNER_ARCH','RUNNER_ENVIRONMENT','GITHUB_REPOSITORY','GITHUB_EVENT_NAME','LUMEN_CI_SQL_PREPARE','GITHUB_RUN_ID','GITHUB_RUN_ATTEMPT','LUMEN_CI_SQL_SA_PASSWORD','RUNNER_TEMP','GITHUB_ENV'] as $key){$env[$key]=getenv($key);}
 lumen_ci_sql_context($env);
 if(!preg_match('/\A[0-9a-f]{64}\z/',(string)getenv('LUMEN_CI_SQL_CONTAINER')) || !str_starts_with((string)getenv('RUNNER_TEMP'),'/home/runner/work/_temp')){throw new RuntimeException('Fixed runner container context required.');}
}catch(Throwable $e){fwrite(STDERR,"Ephemeral TLS CI gate rejected; no driver details.\n");exit(2);}
