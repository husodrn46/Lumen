<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/support/sqlserver-environment.php';
require __DIR__.'/ci/sqlserver-prepare-lib.php';
require __DIR__.'/../includes/lumen_preferences.php';
$n=0;
function sql_fav_check(bool $ok,string $label):void{global $n;$n++;if(!$ok){throw new RuntimeException($label);}}
function sql_fav_scope(int $firma=2,int $personel=7,string $connection='synthetic-logo'):array{
 return lumen_favorites_scope(['plasiyer_id'=>$personel],$personel,1,$firma,'LG_'.str_pad((string)$firma,3,'0',STR_PAD_LEFT).'_',$connection);
}
function sql_fav_reject(Closure $fn,string $label):void{
 try{$fn();}catch(Throwable $e){sql_fav_check(!$e instanceof LogicException,$label);return;}throw new LogicException('Expected rejection: '.$label);
}
function sql_fav_runtime():PDO{
 $dsn=(string)getenv('LUMEN_TEST_SQL_DSN');
 if(lumen_test_sql_database($dsn)!=='LumenTest_Fav_CI'){throw new RuntimeException('Fixed CI favorite DB required.');}
 return new PDO($dsn,(string)getenv('LUMEN_TEST_FAVORITE_USER'),(string)getenv('LUMEN_TEST_FAVORITE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
}
try{
 // Administrative operations are CI-only and hard-bound to the disposable instance.
 $keys=['GITHUB_ACTIONS','RUNNER_OS','RUNNER_ARCH','RUNNER_ENVIRONMENT','GITHUB_REPOSITORY','GITHUB_EVENT_NAME','LUMEN_CI_SQL_PREPARE','GITHUB_RUN_ID','GITHUB_RUN_ATTEMPT','LUMEN_CI_SQL_SA_PASSWORD','RUNNER_TEMP','GITHUB_ENV'];
 $env=[];foreach($keys as $k){$env[$k]=getenv($k);}$context=lumen_ci_sql_context($env);
 if(lumen_test_sql_database((string)getenv('LUMEN_TEST_SQL_DSN'))!=='LumenTest_Fav_CI'){throw new RuntimeException('Fixed favorite DB required.');}
 if(getenv('LUMEN_TEST_FAVORITE_USER')!==$context['user'].'_fav' || !preg_match('/\AaA1![a-f0-9]{64}\z/',(string)getenv('LUMEN_TEST_FAVORITE_PASS'))){throw new RuntimeException('Runtime identity unavailable.');}
 if(($argv[1]??'')==='--worker'){$repo=new LumenFavoriteRepository(sql_fav_runtime());$repo->save(sql_fav_scope(),'parallel.php');echo 'ok';exit;}
 $fixture=lumen_test_sql_connection(true);
 sql_fav_check((int)$fixture->query('SELECT COUNT(*) FROM sys.tables WHERE is_ms_shipped=0')->fetchColumn()===0,'Fresh empty favorite DB');
 // Assert the draft requires explicit review even inside a disposable test DB.
 $sql=file_get_contents(__DIR__.'/../database/lumen/migrations/001_preferences.sql');
 if($sql===false){throw new RuntimeException('Migration draft unavailable');}
 sql_fav_reject(fn()=>$fixture->exec($sql),'Migration opt-in gate');
 sql_fav_check((int)$fixture->query('SELECT COUNT(*) FROM sys.tables WHERE is_ms_shipped=0')->fetchColumn()===0,'Rejected migration leaves empty DB');
 $fixture->exec("EXEC sys.sp_set_session_context @key=N'LumenPreferencesMigration', @value=N'reviewed-separate-db'");
 $fixture->exec($sql);
 $seed=$fixture->prepare('INSERT INTO dbo.LUMEN_ACTOR_LINK (CONNECTION_KEY,FIRMA,LOGO_PERSONEL,ACTOR_ID) VALUES (:c,:f,:p,:a)');
 foreach([[2,7,'synthetic-logo','11111111-1111-4111-8111-111111111111'],[3,7,'synthetic-logo','22222222-2222-4222-8222-222222222222'],[2,8,'synthetic-logo','33333333-3333-4333-8333-333333333333'],[2,7,'other-logo','44444444-4444-4444-8444-444444444444']] as [$f,$p,$c,$a]){$seed->execute([':c'=>$c,':f'=>$f,':p'=>$p,':a'=>$a]);$seed->closeCursor();}
 $admin=new PDO($context['dsn'],'sa',$context['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $runtime=$context['user'].'_fav';
 $admin->exec("USE [LumenTest_Fav_CI]; GRANT SELECT ON dbo.LUMEN_ACTOR_LINK TO [{$runtime}]; GRANT SELECT, INSERT, UPDATE ON dbo.LUMEN_FAVORITES TO [{$runtime}]");
 $pdo=sql_fav_runtime();$repo=new LumenFavoriteRepository($pdo);$scope=sql_fav_scope();
 $config=['LUMEN_DB_SERVER'=>'127.0.0.1,1433','LUMEN_DB_NAME'=>'LumenTest_Fav_CI','LUMEN_DB_USER'=>$runtime,'LUMEN_DB_PASS'=>getenv('LUMEN_TEST_FAVORITE_PASS'),'AKL_DB_NAME'=>'LumenTest_Start_CI','AKL_DB_USER'=>$context['user']];
 // Container self-signed TLS is deliberately trusted ONLY in this fixture connector.
 // This verifies actual SQL permission metadata, not positive trusted-certificate factory TLS.
 sql_fav_check(LumenConnectionFactory::connect($config,fn()=>$pdo)===$pdo,'Actual limited runtime metadata gate');
 sql_fav_reject(fn()=>LumenConnectionFactory::connect($config,fn()=>$fixture),'DDL fixture account rejected by factory');
 sql_fav_reject(fn()=>LumenConnectionFactory::connect($config,fn()=>new PDO((string)getenv('LUMEN_TEST_SQL_DSN'),$runtime,'invalid-synthetic-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION])),'Wrong credential fails safely');
 // Same valid user/endpoint succeeds above with fixture trust; strict TLS must reject the self-signed chain.
 $strict=LumenConnectionFactory::configuration($config);$certificateRejected=false;
 try{new PDO($strict['dsn'],$strict['user'],$strict['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
 catch(PDOException $e){$certificateRejected=str_contains(strtolower($e->getMessage()),'certificate');}
 sql_fav_check($certificateRejected,'Strict TLS specifically rejects untrusted certificate');
 sql_fav_reject(fn()=>LumenConnectionFactory::connect($config),'Production factory does not bypass TLS rejection');
 foreach(['CREATE TABLE dbo.RUNTIME_DENIED (ID INT)','INSERT INTO dbo.LUMEN_ACTOR_LINK VALUES (\'synthetic-logo\',2,9,\'99999999-9999-4999-8999-999999999999\')','UPDATE dbo.LUMEN_ACTOR_LINK SET LOGO_PERSONEL=9 WHERE LOGO_PERSONEL=7','DELETE FROM dbo.LUMEN_ACTOR_LINK','SELECT TOKEN FROM LumenTest_Start_CI.dbo.M_API_TOKEN'] as $denied){sql_fav_reject(fn()=>str_starts_with($denied,'SELECT ')?$pdo->query($denied):$pdo->exec($denied),'Runtime forbidden operation');}
 sql_fav_check($repo->read($scope)==='','Empty preference');$jobs=[];
 for($i=0;$i<10;$i++){$pipes=[];$worker=proc_open([PHP_BINARY,__FILE__,'--worker'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);sql_fav_check(is_resource($worker),'Worker spawn');fclose($pipes[0]);$jobs[]=[$worker,$pipes];}
 foreach($jobs as [$worker,$pipes]){$out=stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);sql_fav_check(proc_close($worker)===0 && $out==='ok','Concurrent runtime save');}
 sql_fav_check((int)$pdo->query('SELECT COUNT(*) FROM dbo.LUMEN_FAVORITES')->fetchColumn()===1,'10 same scope writes one preference');
 sql_fav_check($repo->read($scope)==='parallel.php','Same scope value');
 foreach([sql_fav_scope(3),sql_fav_scope(2,8),sql_fav_scope(2,7,'other-logo')] as $other){sql_fav_check($repo->read($other)==='','Other scope isolated');$repo->save($other,'other.php');sql_fav_check($repo->read($scope)==='parallel.php','Original unchanged');}
 sql_fav_reject(fn()=>$repo->save(sql_fav_scope(2,9),'unmapped.php'),'Missing mapping rejected');
 sql_fav_check(!$pdo->inTransaction(),'Missing mapping rolled back');
 $admin->exec("ALTER TABLE dbo.LUMEN_FAVORITES ADD CONSTRAINT CK_FIXTURE_WRITE_FAIL CHECK (FAVORITES <> 'fail.php')");
 sql_fav_reject(fn()=>$repo->save($scope,'fail.php'),'Constraint failure rejected');
 sql_fav_check(!$pdo->inTransaction() && $repo->read($scope)==='parallel.php','Failed save leaves original and no transaction');
 sql_fav_reject(fn()=>$pdo->exec("INSERT INTO dbo.LUMEN_FAVORITES VALUES ('synthetic-logo',2,'99999999-9999-4999-8999-999999999999','orphan.php')"),'Foreign-key orphan rejected');
 sql_fav_reject(fn()=>$pdo->exec("INSERT INTO dbo.LUMEN_FAVORITES VALUES ('synthetic-logo',2,'11111111-1111-4111-8111-111111111111','duplicate.php')"),'Primary-key duplicate rejected');
 sql_fav_check((int)$pdo->query('SELECT COUNT(*) FROM dbo.LUMEN_FAVORITES')->fetchColumn()===4,'Only four mapped scoped preferences');
 echo "TAMAM: {$n} real SQL favorite checks; migration/scopes/concurrency/rollback/limited grants/untrusted TLS rejection. Positive trusted TLS not run.\n";
}catch(Throwable $e){
 $code=(string)$e->getCode();$state=preg_match('/\A[A-Z0-9]{5}\z/',$code)?$code:'unavailable';
 fwrite(STDERR,'FAIL: favorite CI check '.$n.'; class='.get_class($e).'; SQLSTATE='.$state."; no credential/driver trace printed.\n");exit(2);
}
