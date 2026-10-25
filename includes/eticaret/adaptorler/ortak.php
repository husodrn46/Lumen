<?php
declare(strict_types=1);

/** Pazaryeri adaptörleri saf dönüşümdür: ağ, anahtar veya veritabanı erişimi yoktur. */
function et_api_metin(array $v, string $alan, int $sinir = 150): string
{
    $s=et_metin($v[$alan] ?? '',$sinir);
    if ($s==='') { throw new InvalidArgumentException($alan.' alanı gerekiyor.'); }
    return $s;
}
function et_api_para(mixed $v): float
{
    $n=et_sayi($v,0,100000000000);
    if (abs(round($n,2)-$n)>0.000001) { throw new InvalidArgumentException('Pazaryeri tutarları en fazla iki ondalık haneli olmalı.'); }
    return $n;
}
function et_api_esit(float $a, float $b, string $alan): void
{
    if (abs(round($a,2)-round($b,2))>0.005) { throw new InvalidArgumentException($alan.' tutarları uyuşmuyor; kaynak incelenmeli.'); }
}
function et_api_milisaniye(mixed $v): string
{
    $s=et_metin($v,16);
    if (!preg_match('/\A[0-9]{13}\z/',$s)) { throw new InvalidArgumentException('Pazaryeri zaman damgası milisaniye olmalı.'); }
    $dt=DateTimeImmutable::createFromFormat('U.u',substr($s,0,-3).'.'.substr($s,-3).'000');
    if (!$dt) { throw new InvalidArgumentException('Pazaryeri zaman damgası geçersiz.'); }
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
}
function et_api_iso_tarih(mixed $v): string
{
    $s=et_metin($v,40);
    if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})?\z/',$s)) {
        throw new InvalidArgumentException('Pazaryeri tarihi ISO 8601 biçiminde olmalı.');
    }
    try { $dt=new DateTimeImmutable($s,new DateTimeZone('Europe/Istanbul')); }
    catch (Exception) { throw new InvalidArgumentException('Pazaryeri tarihi geçersiz.'); }
    $hata=DateTimeImmutable::getLastErrors();
    if ($hata && ($hata['warning_count'] || $hata['error_count'])) { throw new InvalidArgumentException('Pazaryeri tarihi geçersiz.'); }
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
}
function et_api_gun(string $utc): string
{
    return (new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('Y-m-d');
}
function et_api_adres(mixed $a): array
{
    if (!is_array($a) || !$a) { throw new InvalidArgumentException('Pazaryeri adresi eksik.'); }
    $alanlar=['fullName'=>'ad','name'=>'ad','company'=>'unvan','address'=>'adres','fullAddress'=>'adres','city'=>'il',
        'district'=>'ilce','town'=>'ilce','email'=>'eposta','phone'=>'telefon','phoneNumber'=>'telefon','postalCode'=>'posta_kodu',
        'countryCode'=>'ulke','taxNumber'=>'vergi_no','taxOffice'=>'vergi_dairesi','turkishIdentityNumber'=>'tc_no'];
    $r=[];
    foreach ($alanlar as $kaynak=>$hedef) {
        if (isset($a[$kaynak]) && is_scalar($a[$kaynak]) && (string) $a[$kaynak]!=='') { $r[$hedef]=(string) $a[$kaynak]; }
    }
    if (!isset($r['ad']) && isset($a['firstName'],$a['lastName'])) { $r['ad']=trim(et_metin($a['firstName']).' '.et_metin($a['lastName'])); }
    if (!isset($r['adres']) && isset($a['address1'])) { $r['adres']=trim(et_metin($a['address1'],500).' '.et_metin($a['address2'] ?? '',500)); }
    if (!$r) { throw new InvalidArgumentException('Pazaryeri adres alanları tanınmadı.'); }
    return $r;
}
function et_api_durum_engeli(string $sistem, string $durum, array $izinli): ?string
{
    return in_array($durum,$izinli,true) ? null : $sistem.' durumu aktarım için uygun değil: '.($durum ?: 'belirsiz');
}
