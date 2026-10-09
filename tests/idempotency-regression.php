<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../api/_idempotency.inc';
require __DIR__ . '/support/TestPdo.php';
$checks = 0;
function idem_check(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) { throw new RuntimeException($message); } }
function idem_throws(Closure $fn, string $class): void {
    try { $fn(); } catch (Throwable $e) { idem_check($e instanceof $class, 'Wrong error class'); return; }
    throw new RuntimeException('Expected rejection');
}
function idem_endpoint(array $steps): array {
    $pipes = [];
    $p = proc_open([PHP_BINARY, __DIR__ . '/support/endpoint.php'], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
    if (!is_resource($p)) { throw new RuntimeException('Fixture spawn failed'); }
    fwrite($pipes[0], json_encode(['sequence'=>$steps], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($p) !== 0) { throw new RuntimeException('Fixture failure: ' . $err . $out); }
    return json_decode($out, true, 64, JSON_THROW_ON_ERROR);
}
$key = 'a81876de-f852-4db6-8e4c-1cc2172a7a96';
unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
idem_check(siparis_idempotency_anahtari() === null, 'Legacy header absence');
$_SERVER['HTTP_IDEMPOTENCY_KEY'] = strtoupper($key);
idem_check(siparis_idempotency_anahtari() === $key, 'UUID case normalized');
$_SERVER['HTTP_IDEMPOTENCY_KEY'] = str_repeat('b',64);
idem_check(siparis_idempotency_anahtari() === str_repeat('b',64), '64 hex key accepted');
foreach (['', 'short', $key.',second', ' '.$key, 'a81876de-f852-1db6-8e4c-1cc2172a7a96'] as $bad) {
    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = $bad;
    idem_throws(static fn()=>siparis_idempotency_anahtari(), InvalidArgumentException::class);
}
$body = ['cari_id'=>60,'kalemler'=>[['stok_id'=>5,'miktar'=>2]]];
idem_check(siparis_idempotency_parmakizi($body) === siparis_idempotency_parmakizi(['kalemler'=>$body['kalemler'],'cari_id'=>60]), 'Object key order canonical');
idem_check(siparis_idempotency_parmakizi($body) !== siparis_idempotency_parmakizi(['cari_id'=>60,'kalemler'=>[['stok_id'=>5,'miktar'=>3]]]), 'Changed quantity not merged');
idem_check(siparis_idempotency_parmakizi(['n'=>1]) !== siparis_idempotency_parmakizi(['n'=>'1']), 'String vs int preserved');
idem_check(siparis_idempotency_parmakizi(['n'=>1]) !== siparis_idempotency_parmakizi(['n'=>1.0]), 'Float type preserved');
idem_check(siparis_idempotency_parmakizi(['items'=>[1,2]]) !== siparis_idempotency_parmakizi(['items'=>[2,1]]), 'Line/list order is part of content');
foreach (['fiyat'=>11, 'kdv'=>10, 'birim_carpan'=>12] as $field=>$value) {
    $changed=$body; $changed['kalemler'][0][$field]=$value;
    idem_check(siparis_idempotency_parmakizi($body)!==siparis_idempotency_parmakizi($changed),'Changed price/VAT/unit fingerprint: '.$field);
}
$first = ['endpoint'=>'siparis_olustur','body'=>$body,'permissions'=>['M1'],'key'=>$key];
$r = idem_endpoint([$first,$first]);
idem_check($r['responses'][0]['status']===200 && $r['responses'][1]['payload']===$r['responses'][0]['payload'], 'Same-key retry returns original result');
idem_check(count($r['committed_headers'])===1 && count($r['committed_lines'])===1 && $r['receipts']===1, 'Only one committed order/receipt');
idem_check(count(array_filter($r['responses'][1]['events'], static fn($e)=>str_contains($e['sql'],'ORFICHE') || str_contains($e['sql'],'AS carpan')))===0, 'Replay does not reprice/query order products or rewrite header');
$lost=$first; $lost['drop_response']=true;
$r = idem_endpoint([$lost,$first]);
idem_check($r['responses'][0]['discarded_response'] && $r['responses'][1]['payload']===$r['responses'][0]['payload'] && count($r['committed_headers'])===1, 'Lost response after commit replays receipt');
$other=$first; $other['key']=str_repeat('c',64);
$r=idem_endpoint([$first,$other]);
idem_check(count($r['committed_headers'])===2 && $r['responses'][0]['payload']['fis']['id']!==$r['responses'][1]['payload']['fis']['id'], 'Two legitimate identical orders with distinct keys remain separate');
foreach (['body','personel','firma','donem'] as $field) {
    $changed=$first;
    $changed[$field]=match($field) { 'body'=>['cari_id'=>60,'kalemler'=>[['stok_id'=>5,'miktar'=>3]]], 'personel'=>8, 'firma'=>3, 'donem'=>'04' };
    $r=idem_endpoint([$first,$changed]);
    idem_check($r['responses'][1]['status']===409 && !isset($r['responses'][1]['payload']['fis']) && count($r['committed_headers'])===1, 'Reuse rejected without revealing response: '.$field);
}
foreach (['fail_line'=>1, 'fail_receipt'=>true] as $flag=>$value) {
    $bad=$first; $bad[$flag]=$value;
    $r=idem_endpoint([$bad,$first]);
    idem_check($r['responses'][0]['status']===500 && $r['responses'][0]['transactions']===['begin','rollback'], 'Partial failure rolls back: '.$flag);
    idem_check($r['responses'][1]['status']===200 && count($r['committed_headers'])===1 && $r['receipts']===1, 'Failed transaction leaves key reusable: '.$flag);
}
$precommit=$first; $precommit['fail_commit_before']=true; $r=idem_endpoint([$precommit,$first]);
idem_check($r['responses'][0]['status']===500 && $r['responses'][0]['transactions']===['begin','rollback'],'Failure before commit rolls back receipt and order');
idem_check($r['responses'][1]['status']===200 && count($r['committed_headers'])===1 && $r['receipts']===1,'Retry after precommit failure creates one committed result');
$ackLost=$first; $ackLost['commit_ack_lost']=true; $r=idem_endpoint([$ackLost,$first]);
idem_check($r['responses'][0]['status']===500 && $r['responses'][0]['transactions']===['begin','commit'],'Simulated commit persisted but acknowledgement failed');
idem_check($r['responses'][1]['status']===200 && $r['responses'][1]['payload']['fis']['id']===$r['committed_headers'][0] && count($r['committed_headers'])===1 && $r['receipts']===1,'Unknown commit outcome retry returns original stored order');
idem_check(!str_contains($r['responses'][0]['payload']['mesaj'],'hiçbir kayıt yapılmadı'),'Unknown commit must not falsely assert rollback');
$busy=$first; $busy['lock_result']=-1;
$r=idem_endpoint([$busy,$first]);
idem_check($r['responses'][0]['status']===503 && $r['responses'][1]['status']===200 && count($r['committed_headers'])===1, 'Lock timeout retry with same key');
$broken=$first; $broken['lock_result']=-999; $r=idem_endpoint([$broken]);
idem_check($r['responses'][0]['status']===503 && $r['committed_headers']===[],'Invalid lock result fails closed, never inserts');
$missing=$first; $missing['missing_idempotency_schema']=true;
$r=idem_endpoint([$missing]);
idem_check($r['responses'][0]['status']===503 && $r['committed_headers']===[] && $r['receipts']===0, 'Missing migration never falls back to unprotected insert');
$denied=$first; $denied['permissions']=[];
$r=idem_endpoint([$first,$denied]);
idem_check($r['responses'][1]['status']===403 && $r['responses'][1]['events']===[], 'Permission revocation blocks replay before lookup');
$denied=$first; $denied['cari_allowed']=false;
$r=idem_endpoint([$first,$denied]);
idem_check($r['responses'][1]['status']===404 && !isset($r['responses'][1]['payload']['fis']), 'Cari revocation blocks cached result');
$legacy=$first; unset($legacy['key']); $r=idem_endpoint([$legacy,$legacy]);
idem_check(count($r['committed_headers'])===2 && $r['receipts']===0, 'Legacy clients still work but have no idempotency guarantee');
$badschema=$first; $badschema['missing_idempotency_schema']=true;
$badkey=$first; $badkey['key']='unsafe-key'; $r=idem_endpoint([$badkey]);
idem_check($r['responses'][0]['status']===400 && $r['committed_headers']===[],'Bad key rejected before writes');
$corrupt = new TestPdo(static function (string $sql) use ($body): array {
    if(str_contains($sql,'sys.key_constraints')){return ['rows'=>[[1]]];}
    if(str_contains($sql,'sp_getapplock')){return ['rows'=>[['LUMEN_LOCK_RESULT'=>0]]];}
    if(str_contains($sql,'WHERE KEYHASH')){return ['rows'=>[['FIRMA'=>2,'DONEM'=>'LG_002_03_','PERSONEL'=>7,'KAPSAM'=>'siparis_satir_ekle:v1','BODYHASH'=>siparis_idempotency_parmakizi($body),'YANIT'=>'{}']]];}
    return [];
});
$corrupt->beginTransaction();
idem_throws(static fn()=>siparis_idempotency_baslat($corrupt,$key,2,'LG_002_03_',7,siparis_idempotency_parmakizi($body)),SiparisIdempotencyCakisma::class);
$pdo = new TestPdo(static fn()=>[]);
idem_throws(static fn()=>siparis_idempotency_baslat($pdo,$key,2,'LG_002_03_',7,hash('sha256','body')),LogicException::class);
$pdo->beginTransaction();
idem_throws(static fn()=>siparis_idempotency_baslat($pdo,$key,3,'LG_002_03_',7,hash('sha256','body')),InvalidArgumentException::class);
idem_check($pdo->events===[], 'Scope mismatch rejected before SQL');
$schema = new TestPdo(static fn()=>['rows'=>[[0]]]);
idem_throws(static fn()=>siparis_idempotency_sema_kontrol($schema),RuntimeException::class);
echo "TAMAM: {$checks} idempotency assertion (stateful sentetik PDO; gerçek yarış/SQL değil).\n";
