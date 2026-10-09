<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/support/TestPdo.php';
require __DIR__.'/../includes/lumen_preferences.php';
$n=0;
function fav_check(bool $ok,string $label): void { global $n; $n++; if(!$ok){throw new RuntimeException($label);} }
function fav_reject(Closure $fn): void { try{$fn();}catch(RuntimeException $e){fav_check(true,'Rejected');return;}fav_check(false,'Expected rejection'); }
$config=['LUMEN_DB_SERVER'=>'lumen-test,1433','LUMEN_DB_NAME'=>'LumenApp','LUMEN_DB_USER'=>'lumen_runtime','LUMEN_DB_PASS'=>'synthetic-not-real','AKL_DB_NAME'=>'LOGODB','AKL_DB_USER'=>'erp_runtime'];
$c=LumenConnectionFactory::configuration($config);
fav_check(str_contains($c['dsn'],'Encrypt=yes;TrustServerCertificate=no;LoginTimeout=5'),'Verified TLS and bounded login');
fav_check(!str_contains($c['dsn'],'LOGODB'),'No ERP DB fallback');
foreach([['LUMEN_DB_SERVER'=>'x;Database=LOGODB'],['LUMEN_DB_SERVER'=>'x,65536'],['LUMEN_DB_NAME'=>'LOGODB'],['LUMEN_DB_NAME'=>'X;TrustServerCertificate=yes'],['LUMEN_DB_USER'=>'sa'],['LUMEN_DB_USER'=>'ERP_RUNTIME'],['LUMEN_DB_PASS'=>'']] as $bad){fav_reject(fn()=>LumenConnectionFactory::configuration(array_replace($config,$bad)));}
foreach(['LUMEN_DB_NAME','LUMEN_DB_SERVER','LUMEN_DB_USER','LUMEN_DB_PASS'] as $key){$bad=$config;unset($bad[$key]);fav_reject(fn()=>LumenConnectionFactory::configuration($bad));}
foreach([['DATABASE_NAME'=>'LumenApp','IS_ADMIN'=>0,'CAN_DDL'=>0],['DATABASE_NAME'=>'LOGODB','IS_ADMIN'=>0,'CAN_DDL'=>0],['DATABASE_NAME'=>'LumenApp','IS_ADMIN'=>1,'CAN_DDL'=>0],['DATABASE_NAME'=>'LumenApp','IS_ADMIN'=>0,'CAN_DDL'=>1],['DATABASE_NAME'=>'LumenApp','IS_ADMIN'=>null,'CAN_DDL'=>0]] as $i=>$row){
 $db=new TestPdo(fn()=>['rows'=>[$row]]);$call=fn()=>LumenConnectionFactory::connect($config,fn()=>$db);
 if($i===0){fav_check($call()===$db,'Separate returned PDO');}else{fav_reject($call);}
}
fav_reject(fn()=>LumenConnectionFactory::connect($config,fn()=>throw new RuntimeException('Secret should not escape')));
try{LumenConnectionFactory::connect($config,fn()=>throw new RuntimeException('secret'));}catch(Throwable $e){fav_check(!str_contains($e->getMessage(),'secret') && $e->getPrevious()===null,'No credential-bearing chain');}
putenv('LUMEN_FAVORITES_ENABLED');fav_check(!lumen_favorites_enabled(),'Pilot absent off');
putenv('LUMEN_FAVORITES_ENABLED=0');fav_check(!lumen_favorites_enabled(),'Pilot explicit off');
putenv('LUMEN_FAVORITES_ENABLED=1');fav_check(lumen_favorites_enabled(),'Pilot explicit on');
putenv('LUMEN_FAVORITES_ENABLED=true');fav_reject(fn()=>lumen_favorites_enabled());
$scope=lumen_favorites_scope(['plasiyer_id'=>7],7,1,2,'LG_002_','test-logo');
fav_check($scope===['connection'=>'test-logo','firma'=>2,'personel'=>7],'Server scope normalized');
foreach([[[],7,1,2,'LG_002_','test-logo'],[['plasiyer_id'=>8],7,1,2,'LG_002_','test-logo'],[['plasiyer_id'=>7],7,3,2,'LG_002_','test-logo'],[['plasiyer_id'=>7],7,null,2,'LG_002_','test-logo'],[['plasiyer_id'=>7],7,1,2,'LG_003_','test-logo'],[['plasiyer_id'=>7],7,1,1000,'LG_1000_','test-logo'],[['plasiyer_id'=>7],7,1,2,'LG_002_',''],[['plasiyer_id'=>7],7,1,2,'LG_002_','x;DROP']] as $args){fav_reject(fn()=>lumen_favorites_scope(...$args));}
fav_check(lumen_favorites_normalize(' A.php,a.php, <script>, /x,')==='a.php,/x','CSV normalization/dedup');
fav_check(count(explode(',',lumen_favorites_normalize(implode(',',array_map(fn($i)=>'card'.$i,range(1,45))))))===40,'40 card bound');
$actors=['test-logo|2|7'=>'11111111-1111-4111-8111-111111111111','test-logo|3|7'=>'22222222-2222-4222-8222-222222222222','other-logo|2|7'=>'33333333-3333-4333-8333-333333333333','test-logo|2|8'=>'44444444-4444-4444-8444-444444444444'];
$values=[];$fail=false;$snapshot=[];
$db=new TestPdo(static function(string $sql,array $p) use(&$actors,&$values,&$fail):array {
 if(str_contains($sql,'LUMEN_ACTOR_LINK')){$id=$actors[$p[':connection'].'|'.$p[':firma'].'|'.$p[':personel']]??null;return ['rows'=>$id===null?[]:[[$id]]];}
 $key=($p[':connection']??'').'|'.($p[':firma']??'').'|'.($p[':actor']??'');
 if(str_starts_with($sql,'SELECT FAVORITES')){return ['rows'=>isset($values[$key])?[[$values[$key]]]:[]];}
 if(str_contains($sql,'WITH (UPDLOCK, HOLDLOCK)')){return ['rows'=>isset($values[$key])?[[$p[':actor']]]:[]];}
 if(str_starts_with($sql,'INSERT INTO dbo.LUMEN_FAVORITES') || str_starts_with($sql,'UPDATE dbo.LUMEN_FAVORITES')){if($fail){throw new RuntimeException('fixture-write-failure');}$values[$key]=$p[':value'];return ['affected'=>1];}
 throw new RuntimeException('Unexpected preference SQL');
},static function(string $event)use(&$snapshot,&$values):void{if($event==='begin'){$snapshot=$values;}if($event==='rollback'){$values=$snapshot;}});
$repo=new LumenFavoriteRepository($db);
fav_check($repo->read($scope)==='','Empty row means empty preference, not ERP import');
$repo->save($scope,'A.php,a.php');fav_check($repo->read($scope)==='a.php','First write/read');
$repo->save($scope,'b.php');fav_check($repo->read($scope)==='b.php' && count($values)===1,'Repeat save updates one row');
foreach([['firma'=>3],['connection'=>'other-logo'],['personel'=>8]] as $change){$other=array_replace($scope,$change);fav_check($repo->read($other)==='','Actor/company/connection isolated');$repo->save($other,'other.php');fav_check($repo->read($scope)==='b.php','Other scope cannot mutate original');}
$fail=true;fav_reject(fn()=>$repo->save($scope,'lost.php'));fav_check($repo->read($scope)==='b.php' && end($db->transactions)==='rollback','Write error rolls back');$fail=false;
fav_reject(fn()=>$repo->save(array_replace($scope,['personel'=>9]),'x.php'));fav_check(count($values)===4,'Missing mapping creates no actor/preference');
fav_reject(fn()=>$repo->read(array_replace($scope,['connection'=>'x;DROP'])));
fav_check(!str_contains(json_encode($db->events),'M_USER_SETTINGS') && !str_contains(json_encode($db->events),'LG_SLSMAN'),'Repository never queries ERP');
fav_check(count(array_filter($db->events,fn($e)=>preg_match('/CREATE|ALTER|DROP|DELETE FROM dbo.LUMEN_ACTOR_LINK/i',$e['sql'])))===0,'No runtime DDL or actor provision');
$writes=array_filter($db->events,fn($e)=>str_starts_with($e['sql'],'UPDATE ') || str_starts_with($e['sql'],'INSERT '));fav_check(count(array_filter($writes,fn($e)=>!$e['transaction']))===0,'All writes transactional');
// Execute actual single-key getter without bootstrapping the production ERP connection.
$source=file_get_contents(__DIR__.'/../ayr.php');
$start=strpos($source,'function kisisel_ayar(string');$end=strpos($source,'/**',$start);
eval(substr($source,$start,$end-$start));
function kisisel_ayarlar():array {return $_SESSION['_kis_ayar'];}
$_SESSION=['plasiyer_id'=>7,'_kis_ayar'=>['gor_kart_favori'=>'legacy.php','tema_vurgu'=>'mavi']];
$terminalkullanici=7;$yetkidurum=1;$firmano=2;$firma='LG_002_';
putenv('LUMEN_LOGO_CONNECTION_ID=test-logo');putenv('LUMEN_FAVORITES_ENABLED=0');
fav_check(kisisel_ayar('gor_kart_favori')==='legacy.php','Pilot off preserves legacy cache');
putenv('LUMEN_FAVORITES_ENABLED=1');
fav_check(kisisel_ayar('gor_kart_favori')==='','Missing Lumen config returns safe empty; no stale ERP cache fallback');
fav_check(kisisel_ayar('tema_vurgu')==='mavi','Other preferences stay legacy');
putenv('LUMEN_FAVORITES_ENABLED=bad');fav_check(kisisel_ayar('gor_kart_favori')==='','Bad flag fails closed');
putenv('LUMEN_FAVORITES_ENABLED=0');fav_check(kisisel_ayar('gor_kart_favori')==='legacy.php','Off rollback shows unchanged legacy preference');
echo "TAMAM: {$n} favorites/connection assertion (synthetic PDO; real new SQL schema not run).\n";
