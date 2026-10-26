<?php
declare(strict_types=1);

/** Bu adaptör yalnız sipariş okur. Gövde/başlıklar hata mesajlarına taşınmaz. */
final class EtTrendyolHatasi extends DomainException
{
    public function __construct(string $mesaj, public readonly bool $tekrar = false, public readonly int $bekleme = 60)
    { parent::__construct($mesaj); }
}

function et_ty_kimlik(string $satici, string $anahtar, string $gizli): array
{
    if (!preg_match('/\A[1-9][0-9]{0,18}\z/D',$satici)) { throw new DomainException('Geçerli Trendyol satıcı numarası girin.'); }
    foreach ([$anahtar,$gizli] as $s) {
        if (strlen($s)<8 || strlen($s)>256 || !preg_match('/\A[\x21-\x7E]+\z/D',$s) || str_contains($s,':')) { throw new DomainException('API anahtarı ve gizli anahtarı eksiksiz, boşluksuz girin.'); }
    }
    return ['satici'=>$satici,'anahtar'=>$anahtar,'gizli'=>$gizli];
}

function et_ty_aralik(string $bas, string $bit, ?DateTimeImmutable $simdi = null): array
{
    $tz=new DateTimeZone('Europe/Istanbul');$simdi=($simdi ?? new DateTimeImmutable('now',$tz))->setTimezone($tz);$t=[];
    foreach ([$bas,$bit] as $s) {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$s,$tz);
        if (!$d || $d->format('Y-m-d\TH:i')!==$s) { throw new DomainException('Geçerli başlangıç ve bitiş zamanı seçin.'); }$t[]=$d;
    }
    if ($t[0]>=$t[1] || $t[1]>$simdi || $t[0]<$simdi->modify('-3 months') || $t[1]->getTimestamp()-$t[0]->getTimestamp()>14*86400) {
        throw new DomainException('Son üç ay içinde, geleceğe taşmayan ve en fazla 14 günlük bir aralık seçin.');
    }
    return ['lastModifiedStartDate'=>$t[0]->getTimestamp()*1000,'lastModifiedEndDate'=>$t[1]->getTimestamp()*1000,'size'=>10];
}

function et_ty_istek(array $bag, array $sir, array $filtre, ?string $imlec): array
{
    et_ty_kimlik($bag['SATICI_ID'],$sir['anahtar'],$sir['gizli']);
    $host=match ($bag['ORTAM']) { 'stage'=>'stageapigw.trendyol.com','prod'=>'apigw.trendyol.com',default=>throw new DomainException('Bağlantı ortamı geçersiz.') };
    $q=array_intersect_key($filtre,array_flip(['lastModifiedStartDate','lastModifiedEndDate','size']));
    if ($imlec!==null) { $q['nextCursor']=$imlec; }
    return ['url'=>'https://'.$host.'/integration/order/sellers/'.$bag['SATICI_ID'].'/orders/stream?'.http_build_query($q,'','&',PHP_QUERY_RFC3986),
        'basliklar'=>['Authorization: Basic '.base64_encode($sir['anahtar'].':'.$sir['gizli']),'User-Agent: '.$bag['SATICI_ID'].' - SelfIntegration','Accept: application/json']];
}

/** Taşıyıcı sözleşmesi: ['kod'=>int,'govde'=>string,'bekleme'=>int]. URL yalnız sabit iki API hostundan üretilir. */
function et_ty_http(array $istek): array
{
    $url=$istek['url'];
    if (!preg_match('~\Ahttps://(?:stageapigw|apigw)\.trendyol\.com/integration/order/sellers/[1-9][0-9]*/orders/stream\?~D',$url)) { throw new DomainException('Trendyol servis adresi geçersiz.'); }
    $govde='';$buyuk=false;$bekleme=60;$ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>$istek['basliklar'],CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION=>static function ($ch,string $s) use (&$bekleme): int {
            if (preg_match('/^Retry-After:\s*([0-9]+)\s*$/i',trim($s),$m)) { $bekleme=max(5,min(3600,(int) $m[1])); }return strlen($s);
        },CURLOPT_WRITEFUNCTION=>static function ($ch,string $s) use (&$govde,&$buyuk): int {
            if (strlen($govde)+strlen($s)>1048576) { $buyuk=true;return 0; }$govde.=$s;return strlen($s);
        }]);
    $ok=curl_exec($ch);$kod=(int) curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if ($buyuk) { throw new EtTrendyolHatasi('Yanıt 1 MB sınırını aştı. Tarih aralığını daraltın.'); }
    if ($ok===false) { throw new EtTrendyolHatasi('Trendyol bağlantısı tamamlanamadı. Kaldığı sayfa yeniden denenecek.',true); }
    return ['kod'=>$kod,'govde'=>$govde,'bekleme'=>$bekleme];
}

function et_ty_yanit(array $r, string $satici): array
{
    $kod=$r['kod'] ?? 0;
    if (in_array($kod,[401,403],true)) { throw new EtTrendyolHatasi('Trendyol erişimi doğrulanamadı. Ortamı, satıcı numarasını ve API bilgilerini kontrol edin.'); }
    if ($kod===429 || $kod>=500 || $kod===0) { throw new EtTrendyolHatasi('Trendyol geçici olarak yanıt veremiyor veya istek sınırına ulaşıldı.',true,max(5,min(3600,(int) ($r['bekleme'] ?? 60)))); }
    if ($kod!==200) { throw new EtTrendyolHatasi('Trendyol isteği reddedildi. Bağlantıyı ve tarih aralığını kontrol edin.'); }
    $ham=$r['govde'] ?? '';
    if (!is_string($ham) || strlen($ham)>1048576 || !mb_check_encoding($ham,'UTF-8')) { throw new EtTrendyolHatasi('Trendyol yanıtı geçersiz veya çok büyük.'); }
    try { $j=json_decode($ham,true,64,JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
    catch (JsonException) { throw new EtTrendyolHatasi('Trendyol JSON yanıtı okunamadı.'); }
    if (!is_array($j) || !is_array($j['content'] ?? null) || !array_is_list($j['content']) || count($j['content'])>10 || !is_bool($j['hasMore'] ?? null)) { throw new EtTrendyolHatasi('Trendyol sipariş sayfası beklenen biçimde değil.'); }
    $imlec=$j['hasMore'] ? ($j['nextCursor'] ?? null) : null;
    if ($j['hasMore'] && (!is_string($imlec) || $imlec==='' || strlen($imlec)>4000 || preg_match('/[\x00-\x1F\x7F]/',$imlec))) { throw new EtTrendyolHatasi('Trendyol devam bilgisi geçersiz.'); }
    foreach ($j['content'] as $p) {
        if (!is_array($p) || (isset($p['supplierId']) && (string) $p['supplierId']!==$satici)) { throw new EtTrendyolHatasi('Sipariş sayfasındaki satıcı bilgisi bağlantıyla uyuşmuyor.'); }
        foreach (['shipmentPackageId','orderNumber'] as $alan) {
            $deger=$p[$alan] ?? null;
            if ((!is_string($deger) && !is_int($deger)) || trim((string) $deger)==='' || mb_strlen((string) $deger)>150 || preg_match('/[\x00-\x1F\x7F]/',(string) $deger)) { throw new EtTrendyolHatasi('Sipariş veya paket kimliği geçersiz; sayfa ilerletilmedi.'); }
        }
    }
    return ['ham'=>$ham,'imlec'=>$imlec];
}
