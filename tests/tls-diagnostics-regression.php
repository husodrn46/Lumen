<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/support/tls-diagnostics.php';
$n=0;
foreach([
 ['secret-password certificate verify failed','08001','certificate-chain-or-validity'],
 ['secret-password certificate unable to get local issuer certificate','08001','certificate-untrusted-issuer'],
 ['secret-password certificate expired','08001','certificate-expired'],
 ['secret-password certificate not yet valid','08001','certificate-not-yet-valid'],
 ['secret-password certificate unsupported certificate purpose','08001','certificate-purpose'],
 ['secret-password certificate hostname mismatch','08001','certificate-name'],
 ['secret-password login failed','28000','authentication'],
 ['secret-password TCP error','08001','connection-or-startup'],
 ['secret-password timeout','HYT00','timeout'],
 ['secret-password unknown','42000','driver-other']
] as [$message,$state,$expected]){
 $e=new PDOException($message);$e->errorInfo=[$state,-1,$message];
 if(lumen_tls_failure_category($e)!==$expected){throw new RuntimeException('Wrong safe category');}$n++;
 if(str_contains(lumen_tls_failure_category($e),'secret') || strlen(lumen_tls_failure_category($e))>40){throw new RuntimeException('Unsafe category');}$n++;
}
if(lumen_tls_failure_category(new RuntimeException('secret-password'))!=='internal-or-factory'){throw new RuntimeException('Unexpected internal category');}$n++;
echo "TAMAM: {$n} redacted TLS diagnostic assertions; no actual driver error captured.\n";
