<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit;}
require __DIR__.'/support/TestPdo.php';
require __DIR__.'/../includes/lumen_favorite_transfer.php';
$n=0;function tr_check(bool $v,string $label):void{global $n;$n++;if(!$v){throw new RuntimeException($label);}}
function tr_reject(Closure $fn):void{try{$fn();}catch(Throwable $e){tr_check(!str_contains($e->getMessage(),'synthetic-secret') && $e->getPrevious()===null,'Generic safe failure');return;}tr_check(false,'Expected failure');}
$a='11111111-1111-4111-8111-111111111111';$b='22222222-2222-4222-8222-222222222222';
$item=['connection'=>'transfer-logo','firma'=>2,'personel'=>7,'actor'=>$a,'value'=>'card.php','expected_raw'=>null];
$plan=LumenFavoriteTransfer::prepare('key',[$item]);tr_check(count($plan['items'])===1,'Validated plan');
foreach([['firma'=>true],['personel'=>'7'],['actor'=>'bad'],['value'=>'K.php'],['value'=>'a.php,a.php'],['expected_raw'=>'other.php'],['connection'=>'x;DROP']] as $change){tr_reject(fn()=>LumenFavoriteTransfer::prepare('key',[array_replace($item,$change)]));}
tr_reject(fn()=>LumenFavoriteTransfer::prepare('key',[$item+['password'=>'synthetic-secret']]));tr_reject(fn()=>LumenFavoriteTransfer::prepare('key',[$item,$item]));tr_reject(fn()=>LumenFavoriteTransfer::prepare('',[$item]));tr_reject(fn()=>LumenFavoriteTransfer::prepare('key',[]));
$second=array_replace($item,['personel'=>8,'actor'=>$b]);
tr_check(LumenFavoriteTransfer::prepare('key',[$item,$second])['fingerprint']===LumenFavoriteTransfer::prepare('key',[$second,array_reverse($item,true)])['fingerprint'],'Batch/object ordering stable');
tr_reject(fn()=>LumenFavoriteTransfer::prepare('key',[$item,array_replace($second,['firma'=>3])]));
$links=['transfer-logo|2|7'=>$a,'transfer-logo|2|8'=>$b];$favorites=[];$ledger=[];$snapshot=[];$fail=false;$ledgerFail=false;$commitFail=false;$driverCode=0;
$db=new TestPdo(function($sql,$p)use(&$links,&$favorites,&$ledger,&$fail,&$ledgerFail,&$driverCode){
 $scope=($p[':c']??'').'|'.($p[':f']??'');
 if(str_starts_with($sql,'SELECT FINGERPRINT')){return ['rows'=>isset($ledger[$scope.'|'.$p[':k']])?[$ledger[$scope.'|'.$p[':k']]]:[]];}
 if(str_starts_with($sql,'SELECT ACTOR_ID')){return ['rows'=>isset($links[$scope.'|'.$p[':p']])?[['ACTOR_ID'=>$links[$scope.'|'.$p[':p']]]]:[]];}
 if(str_starts_with($sql,'SELECT FAVORITES')){return ['rows'=>array_key_exists($scope.'|'.$p[':a'],$favorites)?[['FAVORITES'=>$favorites[$scope.'|'.$p[':a']]]]:[]];}
 if(str_starts_with($sql,'INSERT INTO dbo.LUMEN_FAVORITES')){if($fail && $p[':a']==='22222222-2222-4222-8222-222222222222'){$e=new PDOException('synthetic-secret');$e->errorInfo=['HYT00',$driverCode,'synthetic-secret'];throw $e;}$favorites[$scope.'|'.$p[':a']]=$p[':v'];return [];}
 if(str_starts_with($sql,'INSERT INTO dbo.LUMEN_FAVORITE_TRANSFER')){if($ledgerFail){throw new RuntimeException('synthetic-secret');}$ledger[$scope.'|'.$p[':k']]=['FINGERPRINT'=>$p[':h'],'INSERTED_COUNT'=>$p[':i'],'UNCHANGED_COUNT'=>$p[':u']];return [];}
 throw new RuntimeException('Unexpected SQL');
},function($event)use(&$snapshot,&$favorites,&$ledger,&$commitFail){if($event==='begin'){$snapshot=[$favorites,$ledger];}if($event==='rollback'){[$favorites,$ledger]=$snapshot;}if($event==='commit' && $commitFail){throw new RuntimeException('synthetic-secret');}});
$db->errorMode=PDO::ERRMODE_SILENT;tr_reject(fn()=>new LumenFavoriteTransfer($db));
$db->errorMode=PDO::ERRMODE_WARNING;tr_reject(fn()=>new LumenFavoriteTransfer($db));
$db->errorMode=PDO::ERRMODE_EXCEPTION;$repo=new LumenFavoriteTransfer($db);
tr_check($repo->apply('key',[$item])===['inserted'=>1,'unchanged'=>0,'replay'=>false],'Initial insert');
$before=[$favorites,$ledger];tr_check($repo->apply('key',[$item])['replay'],'Replay');tr_check($before===[$favorites,$ledger],'Replay no write');
tr_reject(fn()=>$repo->apply('key',[array_replace($item,['value'=>'other.php'])]));
tr_reject(fn()=>$repo->apply('stale',[$item]));tr_check($before===[$favorites,$ledger] && !$db->inTransaction(),'Stale transaction closed unchanged');
$expected=array_replace($item,['expected_raw'=>'card.php']);tr_check($repo->apply('no-change',[$expected])['unchanged']===1,'No overwrite');
$favorites['transfer-logo|2|'.$a]=' CARD.PHP ';tr_reject(fn()=>$repo->apply('raw-changed',[$expected]));
tr_check($repo->apply('key',[$item])['replay'] && $favorites['transfer-logo|2|'.$a]===' CARD.PHP ','Replay preserves later edit');
$links['transfer-logo|2|7']=$b;tr_reject(fn()=>$repo->apply('remap',[$expected]));$links['transfer-logo|2|7']=$a;
$favorites=[];$ledger=[];$fail=true;tr_reject(fn()=>$repo->apply('batch',[$item,$second]));tr_check($favorites===[] && $ledger===[],'Full rollback');$fail=false;
tr_check($repo->apply('batch',[$item,$second])['inserted']===2,'Retry failed batch');
$favorites=[];$ledger=[];$ledgerFail=true;tr_reject(fn()=>$repo->apply('ledger-fail',[$item]));tr_check($favorites===[] && $ledger===[],'Ledger failure rolls back favorites');$ledgerFail=false;
$commitFail=true;tr_reject(fn()=>$repo->apply('lost-response',[$item]));$commitFail=false;
tr_check(!$db->inTransaction() && count($ledger)===1,'Simulated uncertain commit persists ledger');
tr_check($repo->apply('lost-response',[$item])['replay'],'Uncertain commit recoverable by exact intent');
foreach([1205,1222,9999] as $code){
 $favorites=[];$ledger=[];$fail=true;$driverCode=$code;
 try{$repo->apply('driver-code',[$item,$second]);tr_check(false,'Expected driver error');}catch(RuntimeException $e){tr_check($e->getCode()===(in_array($code,[1205,1222],true)?$code:0) && $e->getPrevious()===null,'Only safe retry classification exposed');}
 tr_check($favorites===[] && $ledger===[] && !$db->inTransaction(),'Driver failure rollback');
}
$fail=false;$driverCode=0;
$db->beginTransaction();tr_reject(fn()=>$repo->apply('ambient',[$item]));tr_check($db->inTransaction(),'Caller transaction preserved');$db->rollBack();
tr_check(count(array_filter($db->events,fn($e)=>str_starts_with($e['sql'],'INSERT ')&&!$e['transaction']))===0,'Writes always in transaction');
tr_check(count(array_filter($db->events,fn($e)=>preg_match('/UPDATE |DELETE |CREATE |ALTER |DROP |LG_|M_USER_SETTINGS/',$e['sql'])))===0,'No overwrite/DDL/ERP access');
tr_check(count(array_filter($db->events,fn($e)=>str_starts_with($e['sql'],'SELECT ')&&!str_contains($e['sql'],'UPDLOCK,HOLDLOCK')))===0,'All acceptance reads use locks');
echo "TAMAM: {$n} transfer adapter assertions (PDO double; SQL Server not run).\n";
