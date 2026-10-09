<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../siparis/web_intent.php';
require __DIR__.'/support/TestPdo.php';
$n=0;
function web_check(bool $ok, string $message): void { global $n; $n++; if (!$ok) { throw new RuntimeException($message); } }
function web_conflict(Closure $fn): void { try { $fn(); } catch (SiparisIdempotencyCakisma $e) { web_check(true,'conflict'); return; } throw new RuntimeException('Expected conflict'); }
web_siparis_kapsam_dogrula('2','LG_002_','LG_002_03_'); web_check(true,'Valid company/period');
foreach (['LG_003_03_','LG_002_00_','LG_002_03_;DROP'] as $period) {
    try { web_siparis_kapsam_dogrula(2,'LG_002_',$period); throw new LogicException('Accepted invalid context'); }
    catch (RuntimeException $e) { web_check(!$e instanceof LogicException,'Context fail closed'); }
}
$session=[]; $scope='web_tl_baslik:v1'; $context=['personel'=>7,'firma'=>2,'donem'=>'LG_002_03_','cari'=>60];
$key=web_siparis_anahtari($session,$scope,$context);
web_check(strlen($key)===64 && ctype_xdigit($key),'Crypto key');
web_check(web_siparis_anahtari($session,$scope,$context)===$key,'GET refresh');
web_check(web_siparis_anahtari($session,$scope,$context,$key)===$key,'POST same key');
web_conflict(fn()=>web_siparis_anahtari($session,$scope,$context,str_repeat('a',64)));
web_conflict(fn()=>web_siparis_anahtari($session,'web_doviz_baslik:v1',$context,$key));
foreach (['personel'=>8,'firma'=>3,'cari'=>61,'donem'=>'LG_002_04_'] as $field=>$value) {
    web_conflict(fn()=>web_siparis_anahtari($session,$scope,[$field=>$value]+$context,$key));
}
$payload=['context'=>$context,'fields'=>['kur'=>32.123456,'not'=>'Türkçe <not>']];
$hash=web_siparis_dondur($session,$key,$payload);
web_check(web_siparis_dondur($session,$key,$payload)===$hash,'Identical frozen payload repeat');
web_conflict(fn()=>web_siparis_dondur($session,$key,['context'=>$context,'fields'=>['kur'=>33,'not'=>'Türkçe <not>']]));
web_check($session['web_siparis_intents'][$key]['payload']===$payload,'Conflict preserves original');
web_siparis_tamam($session,$key);
$new=web_siparis_anahtari($session,$scope,$context);
web_check($new!==$key,'Confirmed success gives new intent');
web_check(web_siparis_anahtari($session,$scope,$context,$key)===$key,'Old successful POST still recognized');
web_check(str_contains(web_siparis_hidden($key,['note'=>'"<script>']), '&quot;&lt;script&gt;'),'HTML escaped');
$row=['FIRMA'=>2,'DONEM'=>'LG_002_03_','PERSONEL'=>7,'KAPSAM'=>$scope,'BODYHASH'=>$hash,'YANIT'=>'{"ok":true,"fis":{"id":41}}'];
$pdo=new TestPdo(static function(string $sql,array $params) use($row): array {
    if (str_contains($sql,'sys.key_constraints')) return ['rows'=>[[1]]];
    if (str_contains($sql,'sp_getapplock')) return ['rows'=>[['LUMEN_LOCK_RESULT'=>0]]];
    if (str_starts_with($sql,'SELECT FIRMA')) return ['rows'=>[$row]];
    return ['rows'=>[]];
});
$pdo->beginTransaction();
web_check(siparis_idempotency_baslat($pdo,$key,2,'LG_002_03_',7,$hash,$scope)['fis']['id']===41,'Web scope receipt replay');
web_conflict(fn()=>siparis_idempotency_baslat($pdo,$key,2,'LG_002_03_',7,$hash));
$pdo->rollBack();
// Limit cannot silently evict a pending attempt and generate another key.
$full=[];
for ($i=0;$i<64;$i++) { web_siparis_anahtari($full,$scope,['cari'=>$i]); }
try { web_siparis_anahtari($full,$scope,['cari'=>65]); throw new LogicException('Pending limit ignored'); }
catch (RuntimeException $e) { web_check(!$e instanceof LogicException,'Pending map bounded/fail closed'); }
web_check(count($full['web_siparis_intents'])===64,'No pending state removed');
echo "TAMAM: {$n} web intent assertion (session helpers + synthetic PDO).\n";
