<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/support/sqlserver-environment.php';
require __DIR__ . '/../siparis/kayit_lib.php';
require __DIR__ . '/../api/_idempotency.inc';
$checks = 0;
function real_idem_check(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) { throw new RuntimeException($message); } }
function receipt_order(PDO $pdo, string $key, string $hash, string $mode = '', int $personel = 1, int $firma = 999, string $donem = 'LG_999_99_'): array
{
    try {
        $pdo->beginTransaction();
        $previous = siparis_idempotency_baslat($pdo, $key, $firma, $donem, $personel, $hash);
        if ($previous !== null) { real_idem_check($pdo->commit(), 'Replay commit'); return $previous; }
        if ($mode === 'hold') {
            echo "LOCKED\n"; flush();
            $pdo->exec("WAITFOR DELAY '00:00:06'");
        }
        $number = siparis_baslik_numarasi_ayir($pdo, 'LG_999_99_', 'SIP');
        $s = siparis_baslik_insert_hazirla($pdo, 'INSERT INTO LG_999_99_ORFICHE (FICHENO, JOB) VALUES (:n, 501)');
        $s->execute([':n'=>$number]); $id = siparis_baslik_eklenen_id($s);
        $s = $pdo->prepare('INSERT INTO LG_999_99_ORFLINE (ORDFICHEREF,JOB) VALUES (:id,501)');
        $s->execute([':id'=>$id]); $s->closeCursor();
        if ($mode === 'rollback') { throw new RuntimeException('Synthetic rollback after order writes'); }
        $number = siparis_baslik_numarasini_kesinlestir($pdo, 'LG_999_99_', $id, 'SIP');
        $response = ['ok'=>true,'fis'=>['id'=>$id,'fisno'=>$number], 'satir_sayisi'=>1,'mesaj'=>'Sentetik sipariş oluşturuldu – Türkçe'];
        siparis_idempotency_tamamla($pdo, $key, $firma, $donem, $personel, $hash, $response);
        real_idem_check($pdo->commit(), 'Order/receipt commit');
        return $response;
    } catch (Throwable $e) {
        try { if ($pdo->inTransaction()) { $pdo->rollBack(); } } catch (Throwable $ignored) {}
        throw $e;
    }
}
function start_idem_worker(string $key, string $hash, string $mode = ''): array
{
    $pipes = [];
    $process = proc_open([PHP_BINARY,__FILE__,'--worker',$key,$hash,$mode], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
    real_idem_check(is_resource($process), 'Worker spawn'); fclose($pipes[0]);
    return [$process,$pipes];
}
function finish_idem_worker(array $job): string
{
    [$process,$pipes] = $job;
    $out=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
    real_idem_check(proc_close($process) === 0, 'Worker failed: '.$err);
    return $out;
}
$pdo=lumen_test_sql_connection(true);
if (($argv[1] ?? '') === '--worker') {
    $result=receipt_order($pdo,$argv[2],$argv[3],$argv[4] ?? '');
    if (($argv[4] ?? '') !== 'drop') { echo json_encode($result,JSON_THROW_ON_ERROR); }
    exit;
}
real_idem_check((int)$pdo->query('SELECT COUNT(*) FROM sys.tables WHERE is_ms_shipped=0')->fetchColumn()===0,'Fresh empty test DB required; nothing is deleted');
foreach ([__DIR__.'/fixtures/sqlserver-startup.sql',__DIR__.'/../sql/m_api_idempotency.sql'] as $file) {
    $sql=file_get_contents($file); real_idem_check($sql!==false,'Fixture readable');
    foreach (preg_split('/^\s*GO\s*;?\s*$/mi',$sql) as $batch) { if(trim($batch)!==''){$pdo->exec(trim($batch));} }
}
$body=['cari_id'=>60,'kalemler'=>[['stok_id'=>5,'miktar'=>2]]];
$hash=siparis_idempotency_parmakizi($body); $key=hash('sha256','parallel-real-test');
$workers=[];
for($i=0;$i<20;$i++){$workers[]=start_idem_worker($key,$hash);}
$results=[];
foreach($workers as $job){$results[]=json_decode(finish_idem_worker($job),true,64,JSON_THROW_ON_ERROR);}
foreach($results as $result){real_idem_check($result===$results[0],'20 identical keys return original result');}
real_idem_check((int)$pdo->query('SELECT COUNT(*) FROM LG_999_99_ORFICHE')->fetchColumn()===1,'Parallel key: one header');
real_idem_check((int)$pdo->query('SELECT COUNT(*) FROM LG_999_99_ORFLINE')->fetchColumn()===1,'Parallel key: one line');
real_idem_check((int)$pdo->query('SELECT COUNT(*) FROM M_API_IDEMPOTENCY')->fetchColumn()===1,'Parallel key: one receipt');
$other=receipt_order($pdo,hash('sha256','second-legitimate-order'),$hash);
real_idem_check($other['fis']['id']!==$results[0]['fis']['id'],'Same content and different key creates legitimate second order');
foreach ([['changed',hash('sha256',$hash.'x'),1,999,'LG_999_99_'],['owner',$hash,2,999,'LG_999_99_'],['company',$hash,1,998,'LG_998_99_'],['period',$hash,1,999,'LG_999_98_']] as [$label,$fingerprint,$personel,$firma,$donem]) {
    try {receipt_order($pdo,$key,$fingerprint,'',$personel,$firma,$donem);throw new LogicException('Reuse accepted: '.$label);}
    catch(SiparisIdempotencyCakisma $e){real_idem_check(true,'Rejected '.$label);}
}
$rollbackKey=hash('sha256','rollback-case');
try{receipt_order($pdo,$rollbackKey,$hash,'rollback');throw new LogicException('Rollback injection missing');}
catch(RuntimeException $e){real_idem_check($e->getMessage()==='Synthetic rollback after order writes','Expected rollback injection');}
real_idem_check((int)$pdo->query('SELECT COUNT(*) FROM LG_999_99_ORFICHE')->fetchColumn()===2,'Rolled-back header absent');
real_idem_check((int)$pdo->query('SELECT COUNT(*) FROM M_API_IDEMPOTENCY')->fetchColumn()===2,'Rolled-back receipt absent');
$retry=receipt_order($pdo,$rollbackKey,$hash);
real_idem_check($retry['ok']===true && (int)$pdo->query('SELECT COUNT(*) FROM LG_999_99_ORFICHE')->fetchColumn()===3,'Retry after rollback succeeds once');
$dropKey=hash('sha256','commit-response-lost');
real_idem_check(finish_idem_worker(start_idem_worker($dropKey,$hash,'drop'))==='','Worker committed but deliberately emitted no response');
$lost=receipt_order($pdo,$dropKey,$hash);
real_idem_check($lost['ok']===true && (int)$pdo->query('SELECT COUNT(*) FROM LG_999_99_ORFICHE')->fetchColumn()===4,'Lost response retry does not create another order');
$busyKey=hash('sha256','timeout-held-key'); $holder=start_idem_worker($busyKey,$hash,'hold');
real_idem_check(fgets($holder[1][1])==="LOCKED\n",'Holder owns key before timeout attempt');
try{receipt_order($pdo,$busyKey,$hash);throw new LogicException('Expected app-lock timeout');}
catch(SiparisIdempotencyBekle $e){real_idem_check(true,'Real 5s app-lock timeout');}
$held=json_decode(finish_idem_worker($holder),true,64,JSON_THROW_ON_ERROR);
real_idem_check(receipt_order($pdo,$busyKey,$hash)===$held,'Retry after timeout returns committed holder result');
real_idem_check((int)$pdo->query('SELECT COUNT(*) FROM LG_999_99_ORFICHE')->fetchColumn()===5,'Five legitimate orders across all cases');
echo "TAMAM: {$checks} gerçek SQL idempotency fixture kontrolü. Gerçek LOGO endpoint/kolonları değil; test DB korunur.\n";
