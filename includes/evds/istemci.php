<?php
declare(strict_types=1);

/** EVDS 3 web servisi. MCP/LLM istemcisi değildir; ekonomik veri yalnız okunur. */
const EVDS_SERVIS = 'https://evds3.tcmb.gov.tr/igmevdsms-dis/';
const EVDS_KILAVUZ = 'https://evds3.tcmb.gov.tr/igmevdsms-dis/documents/showDocument?docId=8';

function evds_anahtar_dogrula(string $anahtar): string
{
    $anahtar=trim($anahtar);
    if (preg_match('/\A[A-Za-z0-9_-]{16,128}\z/D',$anahtar)!==1) {
        throw new DomainException('EVDS API anahtarını eksiksiz girin. Boşluk ve satır sonu kullanmayın.');
    }
    return $anahtar;
}

function evds_sorgu(string $seri, string $bas, string $bit): array
{
    $seri=strtoupper(trim($seri));
    if (strlen($seri)>100 || preg_match('/\ATP\.[A-Z0-9]+(?:\.[A-Z0-9]+){1,9}\z/D',$seri)!==1) {
        throw new DomainException('EVDS seri kodunu girin (ör. TP.DK.USD.S.YTL).');
    }
    $tarihler=[];
    foreach ([$bas,$bit] as $s) {
        $t=DateTimeImmutable::createFromFormat('!Y-m-d',$s);
        if (!$t || $t->format('Y-m-d')!==$s || $s<'1950-01-01' || $s>date('Y-m-d')) {
            throw new DomainException('1950 ile bugün arasında geçerli bir tarih seçin.');
        }
        $tarihler[]=$t;
    }
    if ($bas>$bit || (int) $tarihler[0]->diff($tarihler[1])->days>999) {
        throw new DomainException('Başlangıç bitişten sonra olamaz; en fazla 1.000 günlük bir aralık seçin.');
    }
    return ['seri'=>$seri,'bas'=>$bas,'bit'=>$bit];
}

function evds_adres(string $islem, array $sorgu): string
{
    $q=evds_sorgu($sorgu['seri'],$sorgu['bas'],$sorgu['bit']);
    if ($islem==='meta') { return EVDS_SERVIS.'serieList/'.http_build_query(['type'=>'json','code'=>$q['seri']],'','&',PHP_QUERY_RFC3986); }
    if ($islem!=='veri') { throw new InvalidArgumentException('Bilinmeyen EVDS işlemi.'); }
    return EVDS_SERVIS.http_build_query(['series'=>$q['seri'],
        'startDate'=>(new DateTimeImmutable($q['bas']))->format('d-m-Y'),
        'endDate'=>(new DateTimeImmutable($q['bit']))->format('d-m-Y'),'type'=>'json'],'','&',PHP_QUERY_RFC3986);
}

/** Dönen gövde veya cURL hatası günlük/ekrana taşınmaz; anahtar yalnız key başlığındadır. */
function evds_http(string $adres, string $anahtar): string
{
    $anahtar=evds_anahtar_dogrula($anahtar);
    if (!str_starts_with($adres,EVDS_SERVIS) || preg_match('/[\r\n]/',$adres)) { throw new InvalidArgumentException('Geçersiz EVDS adresi.'); }
    $govde='';$buyuk=false;
    $ch=curl_init($adres);
    curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['key: '.$anahtar,'Accept: application/json','User-Agent: Lumen-EVDS/1'],
        CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION=>static function ($ch,string $parca) use (&$govde,&$buyuk): int {
            if (strlen($govde)+strlen($parca)>4*1024*1024) { $buyuk=true;return 0; }
            $govde.=$parca;return strlen($parca);
        }]);
    $ok=curl_exec($ch);$durum=(int) curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if ($buyuk) { throw new DomainException('EVDS yanıtı çok büyük; tarih aralığını daraltın.'); }
    if (in_array($durum,[401,403],true)) { throw new DomainException('EVDS API anahtarı doğrulanamadı. Profilinizdeki anahtarı ve EVDS erişiminizi kontrol edin.'); }
    if ($durum===429) { throw new DomainException('EVDS istek sınırına ulaşıldı. Daha sonra tekrar deneyin.'); }
    if ($ok===false || $durum!==200) { throw new DomainException('EVDS hizmetine ulaşılamadı. Daha sonra tekrar deneyin.'); }
    return $govde;
}

function evds_json(string $govde): array
{
    try { $j=json_decode($govde,true,32,JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new DomainException('EVDS yanıtı okunamadı. Daha sonra tekrar deneyin.'); }
    if (!is_array($j)) { throw new DomainException('EVDS beklenen veri biçimini döndürmedi.'); }
    return $j;
}

function evds_meta(string $govde, string $seri): array
{
    $j=evds_json($govde);$liste=$j['items'] ?? $j;
    if (!is_array($liste) || !array_is_list($liste) || count($liste)>1000) { throw new DomainException('EVDS seri bilgileri okunamadı.'); }
    foreach ($liste as $r) {
        if (!is_array($r)) { continue; }
        $r=array_change_key_case($r,CASE_UPPER);
        if (($r['SERIE_CODE'] ?? '')!==$seri) { continue; }
        if (!is_string($r['SERIE_NAME'] ?? null) || trim($r['SERIE_NAME'])==='') { break; }
        $metin=static fn(string $alan): string => is_scalar($r[$alan] ?? '') ? mb_substr((string) ($r[$alan] ?? ''),0,500) : '';
        return ['kod'=>$seri,'ad'=>$metin('SERIE_NAME'),'siklik'=>$metin('FREQUENCY_STR'),'kaynak'=>$metin('DATASOURCE')];
    }
    throw new DomainException('Seri EVDS kataloğunda bulunamadı. Seri kodunu EVDS üzerinden kontrol edin.');
}

/** Orijinal sıklık korunur; eksik gözlem sıfıra çevrilmez, arası doldurulmaz. */
function evds_gozlem_tarihi(string $s): string
{
    foreach (['d-m-Y','Y-m-d','d.m.Y'] as $bicim) {
        $t=DateTimeImmutable::createFromFormat('!'.$bicim,$s);
        if ($t && $t->format($bicim)===$s) { return $t->format('Y-m-d'); }
    }
    if (preg_match('/\A(19\d{2}|20\d{2})-(0[1-9]|1[0-2])\z/',$s)) { return $s.'-01'; }
    if (preg_match('/\A(19\d{2}|20\d{2})\z/',$s)) { return $s.'-01-01'; }
    if (preg_match('/\A(19\d{2}|20\d{2})-Q([1-4])\z/',$s,$m)) { return sprintf('%s-%02d-01',$m[1],((int) $m[2]-1)*3+1); }
    throw new DomainException('Bu serinin tarih biçimi henüz desteklenmiyor. Başka bir seri seçin.');
}

function evds_gozlemler(string $govde, array $sorgu): array
{
    $j=evds_json($govde);$liste=$j['items'] ?? null;
    if ($j===[]) { $liste=[]; }
    if (!is_array($liste) || !array_is_list($liste) || count($liste)>1000) { throw new DomainException('EVDS gözlem listesi okunamadı veya 1.000 gözlem sınırı aşıldı.'); }
    $alan=str_replace('.','_',$sorgu['seri']);$sonuc=[];
    foreach ($liste as $r) {
        if (!is_array($r) || !is_string($r['Tarih'] ?? null) || !array_key_exists($alan,$r)) { throw new DomainException('EVDS yanıtındaki tarih veya seri alanı beklenen biçimde değil.'); }
        $t=evds_gozlem_tarihi($r['Tarih']);
        if ($t<$sorgu['bas'] || $t>$sorgu['bit']) { continue; }
        $v=$r[$alan];
        if ($v===null || $v==='' || $v==='.') { $v=null; }
        elseif ((is_int($v) || is_float($v) || (is_string($v) && preg_match('/\A-?\d+(?:[.,]\d+)?(?:[eE][+-]?\d+)?\z/D',$v))) && is_finite((float) str_replace(',','.',(string) $v))) { $v=(float) str_replace(',','.',(string) $v); }
        else { throw new DomainException('EVDS sayısal gözlemi okunamadı.'); }
        if (isset($sonuc[$t]) && $sonuc[$t]['deger']!==$v) { throw new DomainException('Aynı dönem için çelişkili EVDS gözlemleri geldi.'); }
        $sonuc[$t]=['tarih'=>$t,'donem'=>$r['Tarih'],'deger'=>$v];
    }
    ksort($sonuc);return array_values($sonuc);
}

function evds_ozet(array $satirlar): array
{
    $dolu=array_values(array_filter($satirlar,static fn(array $r): bool => $r['deger']!==null));
    $ilk=$dolu[0] ?? null;$son=$dolu ? $dolu[count($dolu)-1] : null;
    $degisim=$ilk && $son && $ilk['deger']!=0 ? ($son['deger']/$ilk['deger']-1)*100 : null;
    return ['ilk'=>$ilk,'son'=>$son,'adet'=>count($dolu),'eksik'=>count($satirlar)-count($dolu),
        'degisim'=>$degisim!==null && is_finite($degisim) ? $degisim : null];
}

function evds_ornek(): array
{
    $j=evds_json((string) file_get_contents(dirname(__DIR__,2).'/docs/evds/sentetik.json'));
    $q=['seri'=>'TP.LUMEN.DEMO','bas'=>'2024-01-01','bit'=>'2024-06-30'];
    $meta=evds_meta(json_encode($j['meta'],JSON_THROW_ON_ERROR),$q['seri']);
    $satirlar=evds_gozlemler(json_encode($j['veri'],JSON_THROW_ON_ERROR),$q);
    return ['veri'=>['sorgu'=>$q,'meta'=>$meta,'satirlar'=>$satirlar,'ozet'=>evds_ozet($satirlar)],
        'alinma'=>null,'eski'=>false,'uyari'=>null,'ornek'=>true];
}

/** Taşıyıcı yalnız testlerde enjekte edilir; üretimde adres ve TLS kuralları sabittir. */
function evds_veri_oku(array $sorgu, string $anahtar, ?callable $tasiyici = null): array
{
    $sorgu=evds_sorgu($sorgu['seri'],$sorgu['bas'],$sorgu['bit']);$anahtar=evds_anahtar_dogrula($anahtar);
    $oku=$tasiyici ?? 'evds_http';
    $meta=evds_meta($oku(evds_adres('meta',$sorgu),$anahtar),$sorgu['seri']);
    $satirlar=evds_gozlemler($oku(evds_adres('veri',$sorgu),$anahtar),$sorgu);
    return ['sorgu'=>$sorgu,'meta'=>$meta,'satirlar'=>$satirlar,'ozet'=>evds_ozet($satirlar)];
}
