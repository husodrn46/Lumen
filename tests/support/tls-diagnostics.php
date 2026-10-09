<?php
declare(strict_types=1);
/** Test-only diagnostic categories. Never returns driver text, DSN, account, path or key. */
function lumen_tls_failure_category(Throwable $error): string
{
    if(!$error instanceof PDOException){return 'internal-or-factory';}
    $message=strtolower($error->getMessage());
    if(str_contains($message,'certificate')){
        if(str_contains($message,'mismatch') || str_contains($message,'hostname') || str_contains($message,'principal')){return 'certificate-name';}
        if(str_contains($message,'unable to get local issuer') || str_contains($message,'unable to verify the first') || str_contains($message,'self-signed')){return 'certificate-untrusted-issuer';}
        if(str_contains($message,'expired')){return 'certificate-expired';}
        if(str_contains($message,'not yet valid')){return 'certificate-not-yet-valid';}
        if(str_contains($message,'unsupported certificate purpose')){return 'certificate-purpose';}
        return 'certificate-chain-or-validity';
    }
    $state=(string)($error->errorInfo[0]??$error->getCode());
    return match($state){'28000'=>'authentication','08001'=>'connection-or-startup','HYT00','HYT01'=>'timeout',default=>'driver-other'};
}
