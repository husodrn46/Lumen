<?php
declare(strict_types=1);

class EtApiHatasi extends DomainException
{
    public function __construct(string $mesaj, public readonly bool $tekrar = false, public readonly int $bekleme = 60)
    { parent::__construct($mesaj); }
}

/** Yalnız adaptörün sabit izin listesindeki HTTPS adresine GET; sırlar hata metnine taşınmaz. */
function et_api_http(array $istek, string $adresDeseni, string $ad): array
{
    if (!preg_match($adresDeseni,$istek['url'])) { throw new DomainException($ad.' servis adresi geçersiz.'); }
    $govde='';$buyuk=false;$basliklar=[];$baslikHatasi=false;$ch=curl_init($istek['url']);
    curl_setopt_array($ch,[CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>$istek['basliklar'],CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION=>static function ($ch,string $s) use (&$basliklar,&$baslikHatasi): int {
            if (preg_match('~^HTTP/\S+\s+\d{3}~',$s)) { $basliklar=[];$baslikHatasi=false; }
            if (preg_match('/^([^:]+):\s*(.*?)\s*$/',trim($s),$m)) {
                $anahtar=strtolower($m[1]);
                if (in_array($anahtar,['limit','offset','totalcount','pagecount','retry-after'],true)) {
                    if (isset($basliklar[$anahtar]) && $basliklar[$anahtar]!==$m[2]) { $baslikHatasi=true; }
                    $basliklar[$anahtar]=$m[2];
                }
            }
            return strlen($s);
        },CURLOPT_WRITEFUNCTION=>static function ($ch,string $s) use (&$govde,&$buyuk): int {
            if (strlen($govde)+strlen($s)>1048576) { $buyuk=true;return 0; }$govde.=$s;return strlen($s);
        }]);
    $ok=curl_exec($ch);$kod=(int) curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if ($buyuk) { throw new EtApiHatasi('Yanıt 1 MB sınırını aştı. Tarih aralığını daraltın.'); }
    if ($ok===false) { throw new EtApiHatasi($ad.' bağlantısı tamamlanamadı. Kaldığı sayfa yeniden denenecek.',true); }
    if ($baslikHatasi) { throw new EtApiHatasi($ad.' yanıt başlıkları tutarsız; sayfa ilerletilmedi.'); }
    $bekleme=60;
    if (isset($basliklar['retry-after'])) {
        $v=$basliklar['retry-after'];$saniye=ctype_digit($v) ? (int) $v : (strtotime($v) ?: time()+60)-time();$bekleme=max(5,min(3600,$saniye));
    }
    return ['kod'=>$kod,'govde'=>$govde,'bekleme'=>$bekleme,'basliklar'=>$basliklar];
}
