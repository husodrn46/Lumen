<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli-server'){http_response_code(404);exit;}
require __DIR__.'/TestPdo.php';
// Production library, substituting only the connection factory for synthetic session-backed PDO.
$lib=file_get_contents(__DIR__.'/../../includes/lumen_preferences.php');
$lib=str_replace('function lumen_favorites_repository()', 'function fixture_unused_production_repository()', $lib);
$lib=str_replace('__DIR__',var_export(realpath(__DIR__.'/../../includes'),true),$lib);
$lib=preg_replace('/\A<\?php\s*declare\(strict_types=1\);/','',$lib);eval($lib);
session_start();$_SESSION['csrf_token']??=bin2hex(random_bytes(16));$_SESSION['favorites']??=[];$_SESSION['writes']??=0;
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/setup'){header('Content-Type: application/json');echo json_encode(['csrf'=>$_SESSION['csrf_token']]);exit;}
if($path==='/stats'){header('Content-Type: application/json');echo json_encode(['values'=>$_SESSION['favorites'],'writes'=>$_SESSION['writes']]);exit;}
if(!in_array($path,['/read','/ayar/kart_favori_kaydet.php'],true)){http_response_code(404);exit;}
// Auth/config fixture only: no actual LOGO login, cookie production session, or .env.
if(isset($_GET['unauth'])){http_response_code(401);echo '{"ok":false}';exit;}
$terminalkullanici=(int)($_GET['person']??7);$yetkidurum=(int)($_GET['role']??1);
if(!in_array($yetkidurum,[0,1,2],true)){http_response_code(403);echo '{"ok":false}';exit;}
$_SESSION['plasiyer_id']=$terminalkullanici;$firmano=(int)($_GET['firma']??2);$firma='LG_'.str_pad((string)$firmano,3,'0',STR_PAD_LEFT).'_';
putenv('LUMEN_LOGO_CONNECTION_ID='.($_GET['connection']??'test-logo'));putenv('LUMEN_FAVORITES_ENABLED='.($_GET['flag']??'1'));
$_SESSION['_kis_ayar']=['gor_kart_favori'=>'legacy.php'];
function csrf_verify(?string $token=null):bool{return is_string($token)&&hash_equals($_SESSION['csrf_token'],$token);}
function tema_kullanici_kodu():string{return 'fixture-user';}
$dbh=new TestPdo(static function(string $sql,array $p):array{
 if(str_starts_with($sql,'SELECT COUNT')){return ['rows'=>[[0]]];}
 if(str_starts_with($sql,'INSERT INTO M_USER_SETTINGS')){$_SESSION['legacy_saved']=$p[':v'];return ['affected'=>1];}
 throw new RuntimeException('Unexpected ERP SQL');
});
function lumen_favorites_repository():LumenFavoriteRepository{
 if(isset($_GET['connectfail'])){throw new RuntimeException('private driver error');}
 $snapshot=[];
 $pdo=new TestPdo(static function(string $sql,array $p):array{
  if(str_contains($sql,'LUMEN_ACTOR_LINK')){
   if(isset($_GET['unmapped'])){return ['rows'=>[]];}
   $h=hash('sha256',$p[':connection'].'|'.$p[':firma'].'|'.$p[':personel']);
   return ['rows'=>[[substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12)]]];
  }
  $key=$p[':connection'].'|'.$p[':firma'].'|'.$p[':actor'];
  if(str_starts_with($sql,'SELECT FAVORITES')){return ['rows'=>isset($_SESSION['favorites'][$key])?[[ $_SESSION['favorites'][$key] ]]:[]];}
  if(str_contains($sql,'WITH (UPDLOCK, HOLDLOCK)')){return ['rows'=>isset($_SESSION['favorites'][$key])?[[$p[':actor']]]:[]];}
  if(str_starts_with($sql,'INSERT ')||str_starts_with($sql,'UPDATE ')){
   if(isset($_GET['writefail'])){throw new RuntimeException('private write error');}
   $_SESSION['favorites'][$key]=$p[':value'];$_SESSION['writes']++;return ['affected'=>1];
  }
  throw new RuntimeException('Unexpected Lumen SQL');
 },static function(string $event)use(&$snapshot):void{if($event==='begin'){$snapshot=[$_SESSION['favorites'],$_SESSION['writes']];}if($event==='rollback'){[$_SESSION['favorites'],$_SESSION['writes']]=$snapshot;}});
 return new LumenFavoriteRepository($pdo);
}
if($path==='/read'){header('Content-Type: application/json');echo json_encode(['value'=>lumen_favorites_repository()->read(lumen_favorites_current_scope())]);exit;}
$code=file_get_contents(__DIR__.'/../../ayar/kart_favori_kaydet.php');
$code=preg_replace('/^include(?:_once)?\([^\n]+;\s*$/m','',$code);
$code=preg_replace('/\A<\?php\s*declare\(strict_types=1\);/','',$code);eval($code);
