<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/ci/tls-guard.php';
require __DIR__.'/../includes/lumen_connection.php';
try{
 $mode=$argv[1]??'';
 if(!in_array($mode,['trusted','wrong-ca','hostname'],true)){throw new RuntimeException('Invalid TLS mode.');}
 $root=(string)getenv('LUMEN_CI_TLS_DIR');$parent=(string)getenv('RUNNER_TEMP');
 if(realpath(dirname($root))!==realpath($parent) || !preg_match('/\Alumen-tls\.[a-zA-Z0-9]{8}\z/',basename($root)) || is_link($root)){throw new RuntimeException('Ephemeral TLS path required.');}
 $expectedCa=$root.'/'.($mode==='wrong-ca'?'wrong-ca.pem':'ca.pem');
 if(getenv('SSL_CERT_FILE')!==$expectedCa || getenv('SSL_CERT_DIR')!==$root.'/empty-ca-dir' || !is_file($expectedCa)){throw new RuntimeException('Process-only trust paths required.');}
 $run=(string)getenv('GITHUB_RUN_ID');$attempt=(string)getenv('GITHUB_RUN_ATTEMPT');$operator='lumen_ci_'.$run.'_'.$attempt.'_transfer';
 $password=(string)getenv('LUMEN_TEST_FAVORITE_PASS');
 if(!preg_match('/\AaA1![a-f0-9]{64}\z/',$password)){throw new RuntimeException('Synthetic runtime unavailable.');}
 $config=['LUMEN_DB_SERVER'=>$mode==='hostname'?'localhost,1433':'127.0.0.1,1433','LUMEN_DB_NAME'=>'LumenTest_Fav_CI','LUMEN_DB_USER'=>$operator,'LUMEN_DB_PASS'=>$password,'AKL_DB_NAME'=>'LumenTest_Start_CI','AKL_DB_USER'=>'lumen_ci_'.$run.'_'.$attempt];
 $connection=LumenConnectionFactory::configuration($config);
 if(!str_contains($connection['dsn'],'Encrypt=yes;TrustServerCertificate=no;')){throw new RuntimeException('Strict factory DSN required.');}
 if($mode==='trusted'){
  $db=null;
  // Restart/readiness retry only; strict verification is never weakened.
  for($i=0;$i<20;$i++){
   try{$db=LumenConnectionFactory::connect($config);break;}catch(RuntimeException $e){if($i===19){throw $e;}usleep(500000);}
  }
  $stmt=$db->query('SELECT DB_NAME() AS DATABASE_NAME');$row=$stmt->fetch(PDO::FETCH_ASSOC);$stmt->closeCursor();
  if(($row['DATABASE_NAME']??'')!=='LumenTest_Fav_CI'){throw new RuntimeException('Wrong database.');}
  echo "TAMAM: trusted test CA + IP SAN native production factory connection; strict TLS, limited runtime.\n";
 }else{
  $rejected=false;$hostname=false;
  try{new PDO($connection['dsn'],$connection['user'],$connection['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
  catch(PDOException $e){$message=strtolower($e->getMessage());$rejected=str_contains($message,'certificate');$hostname=str_contains($message,'mismatch')||str_contains($message,'hostname')||str_contains($message,'principal');}
  if(!$rejected || ($mode==='hostname'&&!$hostname)){throw new RuntimeException('Certificate-specific negative acceptance missing.');}
  $factoryRejected=false;try{LumenConnectionFactory::connect($config);}catch(RuntimeException $e){$factoryRejected=$e->getPrevious()===null;}
  if(!$factoryRejected){throw new RuntimeException('Factory did not reject invalid trust/name.');}
  echo 'TAMAM: strict TLS rejection '.$mode."; no bypass or driver trace.\n";
 }
}catch(Throwable $e){fwrite(STDERR,"FAIL: ephemeral strict TLS acceptance; no credentials/key/driver trace printed.\n");exit(2);}
