<?php
declare(strict_types=1);
require_once __DIR__.'/http.php';

function et_hb_merchant(string $v): string
{
    if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/Di',$v) || $v==='00000000-0000-0000-0000-000000000000') { throw new DomainException('Hepsiburada Merchant ID bilgisini UUID biçiminde girin.'); }
    return strtolower($v);
}

function et_hb_istemci(string $v): string
{
    if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._ -]{0,99}\z/D',$v)) { throw new DomainException('Hepsiburada için tanımlı User-Agent / entegratör adını girin (en fazla 100 karakter).'); }
    return $v;
}

function et_hb_kimlik(string $satici, string $kullanici, string $servis): array
{
    et_hb_merchant($satici);
    foreach ([$kullanici,$servis] as $v) {
        if (strlen($v)<1 || strlen($v)>256 || !preg_match('/\A[\x21-\x7E]+\z/D',$v)) { throw new DomainException('Hepsiburada API kullanıcı adı ve servis anahtarını boşluksuz, eksiksiz girin.'); }
    }
    if (str_contains($kullanici,':')) { throw new DomainException('API kullanıcı adı iki nokta içeremez.'); }
    return ['anahtar'=>$kullanici,'gizli'=>$servis];
}

/** Paket servisi 24 saati aşan bitişi yok sayar; Lumen bunu sessizce kabul etmez. */
function et_hb_aralik(string $bas, string $bit, ?DateTimeImmutable $simdi = null): array
{
    $tz=new DateTimeZone('Europe/Istanbul');$simdi=($simdi ?? new DateTimeImmutable('now',$tz))->setTimezone($tz);$t=[];
    foreach ([$bas,$bit] as $v) {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$v,$tz);
        if (!$d || $d->format('Y-m-d\TH:i')!==$v) { throw new DomainException('Geçerli başlangıç ve bitiş zamanı seçin.'); }$t[]=$d;
    }
    if ($t[0]>=$t[1] || $t[1]>$simdi || $t[0]->format('Y')<'2000' || $t[1]->getTimestamp()-$t[0]->getTimestamp()>86400) { throw new DomainException('Geleceğe taşmayan, en fazla 24 saatlik bir aralık seçin.'); }
    return ['begindate'=>$t[0]->format('Y-m-d H:i'),'enddate'=>$t[1]->format('Y-m-d H:i'),'limit'=>10];
}

/** Hepsiburada devam bilgisi bize ait offset/toplam zarfıdır; Trendyol cursor'ına uygulanmaz. */
function et_hb_devam(?string $imlec): array
{
    if ($imlec===null) { return ['offset'=>0,'total'=>null]; }
    try { $v=json_decode($imlec,true,4,JSON_THROW_ON_ERROR); } catch (JsonException) { $v=null; }
    if (!is_array($v) || !is_int($v['offset'] ?? null) || !is_int($v['total'] ?? null) || $v['offset']<10 || $v['offset']%10!==0 || $v['offset']>100000000 || $v['total']<=$v['offset'] || $v['total']>100000000) { throw new EtApiHatasi('Hepsiburada alımının devam bilgisi geçersiz. Yeni alım başlatın.'); }
    return $v;
}

function et_hb_istek(array $bag, array $sir, array $filtre, ?string $imlec): array
{
    $merchant=et_hb_merchant($bag['SATICI_ID']);et_hb_kimlik($merchant,$sir['anahtar'],$sir['gizli']);$istemci=et_hb_istemci($bag['ISTEMCI'] ?? '');
    $host=match ($bag['ORTAM']) { 'stage'=>'oms-external-sit.hepsiburada.com','prod'=>'oms-external.hepsiburada.com',default=>throw new DomainException('Bağlantı ortamı geçersiz.') };
    $devam=et_hb_devam($imlec);
    return ['url'=>'https://'.$host.'/packages/merchantid/'.$merchant.'?'.http_build_query(['begindate'=>$filtre['begindate'],'enddate'=>$filtre['enddate'],'limit'=>10,'offset'=>$devam['offset']],'','&',PHP_QUERY_RFC3986),
        'basliklar'=>['Authorization: Basic '.base64_encode($sir['anahtar'].':'.$sir['gizli']),'User-Agent: '.$istemci,'Accept: application/json']];
}

function et_hb_http(array $istek): array
{
    return et_api_http($istek,'~\Ahttps://oms-external(?:-sit)?\.hepsiburada\.com/packages/merchantid/[0-9a-f-]{36}\?~D','Hepsiburada');
}

function et_hb_yanit(array $r, string $satici, array $filtre, ?string $imlec): array
{
    $kod=$r['kod'] ?? 0;
    if (in_array($kod,[401,403],true)) { throw new EtApiHatasi('Hepsiburada erişimi doğrulanamadı. Ortamı, Merchant ID, kullanıcı adı, servis anahtarı ve User-Agent bilgisini kontrol edin.'); }
    if ($kod===0 || $kod===429 || $kod>=500) { throw new EtApiHatasi('Hepsiburada geçici olarak yanıt veremiyor veya istek sınırına ulaşıldı.',true,max(5,min(3600,(int) ($r['bekleme'] ?? 60)))); }
    if ($kod!==200) { throw new EtApiHatasi('Hepsiburada isteği reddedildi. Bağlantıyı ve tarih aralığını kontrol edin.'); }
    $ham=$r['govde'] ?? '';
    if (!is_string($ham) || strlen($ham)>1048576 || !mb_check_encoding($ham,'UTF-8')) { throw new EtApiHatasi('Hepsiburada yanıtı geçersiz veya çok büyük.'); }
    try { $paketler=json_decode($ham,true,64,JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); } catch (JsonException) { throw new EtApiHatasi('Hepsiburada JSON yanıtı okunamadı.'); }
    if ((ltrim($ham)[0] ?? '')!=='[' || !is_array($paketler) || !array_is_list($paketler) || count($paketler)>10) { throw new EtApiHatasi('Hepsiburada paket sayfası beklenen biçimde değil.'); }
    $devam=et_hb_devam($imlec);$h=[];
    foreach (['limit','offset','totalcount','pagecount'] as $alan) {
        $v=$r['basliklar'][$alan] ?? null;
        if ((!is_string($v) && !is_int($v)) || !preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D',(string) $v) || (int) $v>100000000) { throw new EtApiHatasi('Hepsiburada sayfalama başlığı eksik veya geçersiz: '.$alan); }$h[$alan]=(int) $v;
    }
    if ($h['limit']!==10 || $h['offset']!==$devam['offset'] || ($devam['total']!==null && $h['totalcount']!==$devam['total'])) { throw new EtApiHatasi('Hepsiburada listesi alım sırasında değişti veya sayfa bilgisi uyuşmuyor. Alımı durdurup aynı aralığı yeniden alın.'); }
    $beklenen=min(10,max(0,$h['totalcount']-$h['offset']));$sayfaSayisi=(int) ceil($h['totalcount']/10);
    if (count($paketler)!==$beklenen || ($h['totalcount']===0 ? !in_array($h['pagecount'],[0,1],true) : $h['pagecount']!==$sayfaSayisi)) { throw new EtApiHatasi('Hepsiburada paket sayısı ile sayfalama bilgisi tutarsız. Yeni alım başlatın.'); }
    $kimlikler=[];$merchant=et_hb_merchant($satici);
    foreach ($paketler as $p) {
        $id=$p['packageNumber'] ?? null;
        if ((!is_string($id) && !is_int($id)) || trim((string) $id)==='' || trim((string) $id)!==(string) $id || strlen((string) $id)>100 || preg_match('/[\x00-\x1F\x7F]/',(string) $id) || isset($kimlikler[(string) $id])) { throw new EtApiHatasi('Hepsiburada paket kimliği geçersiz veya sayfada tekrarlı; sayfa ilerletilmedi.'); }$kimlikler[(string) $id]=true;
        if (isset($p['merchantId']) && (!is_string($p['merchantId']) || strtolower($p['merchantId'])!==$merchant)) { throw new EtApiHatasi('Hepsiburada paketindeki satıcı bilgisi bağlantıyla uyuşmuyor.'); }
        // Satıcı her kalemde belgelenir; eksik veya yabancı satıcılı bir sayfa ilerletilmez.
        if (!is_array($p['items'] ?? null) || !array_is_list($p['items']) || !$p['items'] || count($p['items'])>200) { throw new EtApiHatasi('Hepsiburada paket kalemleri eksik veya sınır dışında.'); }
        foreach ($p['items'] as $kalem) {
            if (!is_array($kalem) || !is_string($kalem['merchantId'] ?? null) || strtolower($kalem['merchantId'])!==$merchant) { throw new EtApiHatasi('Hepsiburada paketindeki satıcı bilgisi bağlantıyla uyuşmuyor.'); }
            foreach (['lineItemId','orderNumber'] as $alan) {
                $v=$kalem[$alan] ?? null;
                if ((!is_string($v) && !is_int($v)) || trim((string) $v)==='' || mb_strlen((string) $v)>150 || preg_match('/[\x00-\x1F\x7F]/',(string) $v)) { throw new EtApiHatasi('Hepsiburada sipariş veya kalem kimliği geçersiz; sayfa ilerletilmedi.'); }
            }
        }
    }
    $sirali=array_keys($kimlikler);sort($sirali,SORT_STRING);$sonraki=$h['offset']+count($paketler);
    return ['ham'=>$ham,'imlec'=>$sonraki<$h['totalcount'] ? json_encode(['offset'=>$sonraki,'total'=>$h['totalcount']],JSON_THROW_ON_ERROR) : null,
        'kayit_ozeti'=>$sirali ? hash('sha256',json_encode($sirali,JSON_THROW_ON_ERROR)) : null,'meta'=>$h];
}
