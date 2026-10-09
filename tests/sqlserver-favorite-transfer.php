<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/support/sqlserver-environment.php';
require __DIR__.'/ci/sqlserver-prepare-lib.php';
require __DIR__.'/../includes/lumen_favorite_transfer.php';
$n=0;
function transfer_check(bool $ok,string $label):void{global $n;$n++;if(!$ok){throw new RuntimeException($label);}}
function transfer_reject(Closure $fn):void{try{$fn();}catch(Throwable $e){transfer_check(!($e instanceof LogicException) || $e instanceof DomainException,'Expected safe rejection');return;}throw new LogicException('Expected rejection');}
function transfer_item(int $personel=71,string $value='card.php',?string $expected=null,int $firma=17,string $connection='transfer-logo'):array{
 $actor=sprintf('77777777-7777-4777-8777-%012d',$personel);
 return ['connection'=>$connection,'firma'=>$firma,'personel'=>$personel,'actor'=>$actor,'value'=>$value,'expected_raw'=>$expected];
}
function transfer_operator(array $context):PDO{
 return new PDO((string)getenv('LUMEN_TEST_SQL_DSN'),$context['user'].'_transfer',(string)getenv('LUMEN_TEST_FAVORITE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
}
function transfer_workers(array $context,string $mode,int $count):array{
 $workers=[];
 for($i=0;$i<$count;$i++){
  $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--worker',$mode,(string)$i],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
  transfer_check(is_resource($process),'Worker spawned');fclose($pipes[0]);$workers[]=[$process,$pipes];
 }
 $results=[];
 foreach($workers as [$process,$pipes]){$out=stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);transfer_check(proc_close($process)===0,'Worker completed');$results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}
 return $results;
}
try{
 $env=[];foreach(['GITHUB_ACTIONS','RUNNER_OS','RUNNER_ARCH','RUNNER_ENVIRONMENT','GITHUB_REPOSITORY','GITHUB_EVENT_NAME','LUMEN_CI_SQL_PREPARE','GITHUB_RUN_ID','GITHUB_RUN_ATTEMPT','LUMEN_CI_SQL_SA_PASSWORD','RUNNER_TEMP','GITHUB_ENV'] as $k){$env[$k]=getenv($k);}
 $context=lumen_ci_sql_context($env);
 if((string)getenv('LUMEN_TEST_SQL_DSN')!=='sqlsrv:Server=127.0.0.1,1433;Database=LumenTest_Fav_CI;Encrypt=yes;TrustServerCertificate=yes'
 || !preg_match('/\AaA1![a-f0-9]{64}\z/',(string)getenv('LUMEN_TEST_FAVORITE_PASS'))){throw new RuntimeException('Fixed synthetic transfer context required.');}
 if(($argv[1]??'')==='--worker'){
  $pdo=transfer_operator($context);$adapter=new LumenFavoriteTransfer($pdo);$mode=$argv[2]??'';$index=(int)($argv[3]??0);
  if($mode==='timeout'){
   $pdo->exec('SET LOCK_TIMEOUT 300');
   try{$adapter->apply('timeout',[transfer_item(80,'before.php','before.php')]);throw new LogicException('Lock unexpectedly acquired');}
   catch(RuntimeException $e){if($e->getCode()!==1222){throw $e;}echo json_encode(['timeout'=>true,'closed'=>!$pdo->inTransaction()]);exit;}
  }
  if(!in_array($mode,['same','different'],true)){throw new RuntimeException('Unknown worker mode');}
  $key=$mode==='same'?'parallel-same':'parallel-different-'.$index;$person=$mode==='same'?81:82;
  // Deadlock victims retry exact same intent; bounded synthetic acceptance only.
  for($attempt=0;$attempt<5;$attempt++){
   try{echo json_encode($adapter->apply($key,[transfer_item($person)]));exit;}
   catch(DomainException $e){echo json_encode(['conflict'=>true,'closed'=>!$pdo->inTransaction()]);exit;}
   catch(RuntimeException $e){if($attempt===4 || !in_array($e->getCode(),[1205,1222],true)){throw $e;}usleep(50000*($attempt+1));}
  }
 }
 $fixture=lumen_test_sql_connection(true);
 transfer_check((int)$fixture->query("SELECT COUNT(*) FROM sys.tables WHERE name='LUMEN_FAVORITE_TRANSFER'")->fetchColumn()===0,'No prior transfer ledger');
 $migration=file_get_contents(__DIR__.'/../database/lumen/migrations/002_favorite_transfer.sql');if($migration===false){throw new RuntimeException('Draft unavailable');}
 transfer_reject(fn()=>$fixture->exec($migration));
 transfer_check((int)$fixture->query("SELECT COUNT(*) FROM sys.tables WHERE name='LUMEN_FAVORITE_TRANSFER'")->fetchColumn()===0,'Migration gate no changes');
 $fixture->exec("EXEC sys.sp_set_session_context @key=N'LumenFavoriteTransferMigration', @value=N'reviewed-separate-db'");$fixture->exec($migration);
 $admin=new PDO($context['dsn'],'sa',$context['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $user=$context['user'].'_transfer';$password=(string)getenv('LUMEN_TEST_FAVORITE_PASS');
 // Fixed ephemeral operator. Existing app favorite account never receives ledger grants.
 $admin->exec("CREATE LOGIN [{$user}] WITH PASSWORD='{$password}', CHECK_POLICY=ON; USE [LumenTest_Fav_CI]; CREATE USER [{$user}] FOR LOGIN [{$user}]; GRANT SELECT ON dbo.LUMEN_ACTOR_LINK TO [{$user}]; GRANT SELECT,INSERT ON dbo.LUMEN_FAVORITES TO [{$user}]; GRANT SELECT,INSERT ON dbo.LUMEN_FAVORITE_TRANSFER TO [{$user}]");
 $seed=$fixture->prepare('INSERT INTO dbo.LUMEN_ACTOR_LINK VALUES (:c,:f,:p,:a)');
 foreach([71,72,73,74,75,76,77,78,79,80,81,82] as $person){$item=transfer_item($person);$seed->execute([':c'=>$item['connection'],':f'=>$item['firma'],':p'=>$person,':a'=>$item['actor']]);$seed->closeCursor();}
 foreach([[18,'transfer-logo'],[17,'other-transfer']] as [$firm,$connection]){$item=transfer_item(71,'card.php',null,$firm,$connection);$seed->execute([':c'=>$connection,':f'=>$firm,':p'=>71,':a'=>$item['actor']]);$seed->closeCursor();}
 $pdo=transfer_operator($context);$adapter=new LumenFavoriteTransfer($pdo);
 foreach(['CREATE TABLE dbo.TRANSFER_DENIED(ID INT)','UPDATE dbo.LUMEN_FAVORITES SET FAVORITES=\'bad.php\'','DELETE FROM dbo.LUMEN_FAVORITE_TRANSFER','UPDATE dbo.LUMEN_ACTOR_LINK SET LOGO_PERSONEL=99','SELECT TOKEN FROM LumenTest_Start_CI.dbo.M_API_TOKEN'] as $sql){transfer_reject(fn()=>str_starts_with($sql,'SELECT ')?$pdo->query($sql):$pdo->exec($sql));}

 $first=$adapter->apply('first',[transfer_item()]);transfer_check($first===['inserted'=>1,'unchanged'=>0,'replay'=>false],'Initial apply');
 transfer_check($adapter->apply('first',[transfer_item()])['replay'],'Lost-response replay');
 transfer_reject(fn()=>$adapter->apply('first',[transfer_item(71,'different.php')]));
 transfer_reject(fn()=>$adapter->apply('stale',[transfer_item()]));
 transfer_check(!$pdo->inTransaction(),'Stale failure closes transaction');
 transfer_check($adapter->apply('same-value',[transfer_item(71,'card.php','card.php')])['unchanged']===1,'No-change existing row');
 $change=$fixture->prepare('UPDATE dbo.LUMEN_FAVORITES SET FAVORITES=:v WHERE CONNECTION_KEY=:c AND FIRMA=:f AND ACTOR_ID=:a');
 $changeTarget=function(int $person,string $value)use($change):void{$item=transfer_item($person);$change->execute([':v'=>$value,':c'=>$item['connection'],':f'=>$item['firma'],':a'=>$item['actor']]);$change->closeCursor();};
 $changeTarget(71,'later.php');
 transfer_check($adapter->apply('first',[transfer_item()])['replay'],'Replay preserves later edit');
 transfer_reject(fn()=>$adapter->apply('raw-changed',[transfer_item(71,'card.php','card.php')]));
 $read=function(int $person)use($fixture):mixed{$stmt=$fixture->prepare('SELECT FAVORITES FROM dbo.LUMEN_FAVORITES WHERE CONNECTION_KEY=:c AND FIRMA=:f AND ACTOR_ID=:a');$item=transfer_item($person);$stmt->execute([':c'=>$item['connection'],':f'=>$item['firma'],':a'=>$item['actor']]);$v=$stmt->fetchColumn();$stmt->closeCursor();return $v;};
 transfer_check($read(71)==='later.php','Later value retained');
 foreach([[18,'transfer-logo'],[17,'other-transfer']] as [$firm,$connection]){transfer_check($adapter->apply('first',[transfer_item(71,'scope.php',null,$firm,$connection)])['inserted']===1,'Same key separate scope');}
 // Snapshot invalidated by explicit actor remap.
 $fixture->exec("UPDATE dbo.LUMEN_ACTOR_LINK SET ACTOR_ID='99999999-9999-4999-8999-999999999999' WHERE CONNECTION_KEY='transfer-logo' AND FIRMA=17 AND LOGO_PERSONEL=73");
 transfer_reject(fn()=>$adapter->apply('remapped',[transfer_item(73)]));
 transfer_check($read(73)===false,'Remap creates no favorite');
 // Real constraint error on second insert must rollback first insert and ledger.
 $fixture->exec("ALTER TABLE dbo.LUMEN_FAVORITES ADD CONSTRAINT CK_TRANSFER_FIXTURE_FAILURE CHECK (FAVORITES <> 'transfer-fail.php')");
 transfer_reject(fn()=>$adapter->apply('rollback',[transfer_item(74),transfer_item(75,'transfer-fail.php')]));
 transfer_check($read(74)===false && $read(75)===false && !$pdo->inTransaction(),'Atomic batch rollback');
 $count=$fixture->prepare('SELECT COUNT(*) FROM dbo.LUMEN_FAVORITE_TRANSFER WHERE CONNECTION_KEY=:c AND FIRMA=:f AND REQUEST_KEY=:k');$count->execute([':c'=>'transfer-logo',':f'=>17,':k'=>'rollback']);transfer_check((int)$count->fetchColumn()===0,'Failed intent not recorded');$count->closeCursor();
 transfer_check($adapter->apply('rollback',[transfer_item(74),transfer_item(75)])['inserted']===2,'Failed key retry succeeds');
 $adapter->apply('existing',[transfer_item(77,'existing.php')]);
 transfer_reject(fn()=>$adapter->apply('late-conflict',[transfer_item(76),transfer_item(77)]));
 transfer_check($read(76)===false,'Later conflict rolls back earlier insert');
 // Exact raw-string comparison preserves presentation differences and empty-vs-missing.
 $adapter->apply('raw',[transfer_item(78)]);$changeTarget(78,' CARD.PHP ');
 transfer_reject(fn()=>$adapter->apply('raw-stale',[transfer_item(78,'card.php','card.php')]));
 transfer_check($adapter->apply('raw-noop',[transfer_item(78,'card.php',' CARD.PHP ')])['unchanged']===1 && $read(78)===' CARD.PHP ','Raw value preserved');
 transfer_check($adapter->apply('empty',[transfer_item(79,'')])['inserted']===1 && $read(79)==='','Empty preference is existing row');
 transfer_reject(fn()=>$adapter->apply('empty-stale',[transfer_item(79,'')]));
 // Parent holds an actual row lock; independent worker must timeout without a partial ledger.
 $adapter->apply('before-timeout',[transfer_item(80,'before.php')]);
 $fixture->beginTransaction();$changeTarget(80,'locked.php');
 $timeout=transfer_workers($context,'timeout',1);$fixture->rollBack();
 transfer_check($timeout[0]===['timeout'=>true,'closed'=>true] && $read(80)==='before.php','Lock timeout rollback');
 transfer_check($adapter->apply('timeout',[transfer_item(80,'before.php','before.php')])['unchanged']===1,'Timeout key retry');
 $same=transfer_workers($context,'same',10);
 transfer_check(count(array_filter($same,fn($r)=>isset($r['replay'])&&!$r['replay']))===1,'One same-key original');
 transfer_check(count(array_filter($same,fn($r)=>($r['replay']??false)))===9,'Nine independent connection replays');
 $different=transfer_workers($context,'different',10);
 transfer_check(count(array_filter($different,fn($r)=>($r['inserted']??0)===1))===1,'One different-key insert');
 transfer_check(count(array_filter($different,fn($r)=>($r['conflict']??false)&&($r['closed']??false)))===9,'Nine stale intents rejected');
 $row=$fixture->query("SELECT COUNT(*) FROM dbo.LUMEN_FAVORITE_TRANSFER WHERE CONNECTION_KEY='transfer-logo' AND FIRMA=17 AND REQUEST_KEY LIKE 'parallel-different-%'")->fetchColumn();transfer_check((int)$row===1,'Only winning intent persisted');
 $pdo->beginTransaction();transfer_reject(fn()=>$adapter->apply('ambient',[transfer_item(72)]));transfer_check($pdo->inTransaction(),'Caller transaction preserved');$pdo->rollBack();
 echo "TAMAM: {$n} real SQL transfer checks; scoped locks/parallel keys/rollback/lost-response/limited operator/timeout. Positive trusted TLS not run.\n";
}catch(Throwable $e){
 $code=(string)$e->getCode();$state=preg_match('/\A[A-Z0-9]{5}\z/',$code)?$code:'unavailable';
 fwrite(STDERR,'FAIL: transfer CI check '.$n.'; class='.get_class($e).'; SQLSTATE='.$state."; no credentials/driver trace printed.\n");exit(2);
}
