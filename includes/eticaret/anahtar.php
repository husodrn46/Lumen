<?php
declare(strict_types=1);

/** E-ticaret sırları SQL yedeğinden ayrı bir ana anahtarla korunur. */
function et_ana_anahtar(): string
{
    $yol=getenv('LUMEN_ETICARET_ANAHTAR_DOSYASI') ?: dirname(__DIR__,2).'/database/eticaret-master.key';
    $dizin=dirname($yol);
    if (!is_dir($dizin) && !mkdir($dizin,0750,true) && !is_dir($dizin)) { throw new RuntimeException('E-ticaret anahtar dizini oluşturulamadı.'); }
    $kilit=@fopen($yol.'.lock','c');
    if (!$kilit || !flock($kilit,LOCK_EX)) { throw new RuntimeException('E-ticaret anahtar dosyası kilitlenemedi.'); }
    try {
        if (!is_file($yol)) {
            $gecici=$yol.'.yeni-'.bin2hex(random_bytes(8));$f=@fopen($gecici,'x+b');
            if (!$f) { throw new RuntimeException('E-ticaret ana anahtarı oluşturulamadı.'); }
            try {
                if (!chmod($gecici,0600) || fwrite($f,random_bytes(32))!==32 || !fflush($f)) { throw new RuntimeException('E-ticaret ana anahtarı yazılamadı.'); }
            } catch (Throwable $e) { fclose($f);@unlink($gecici);throw $e; }
            fclose($f);
            if (!rename($gecici,$yol)) { @unlink($gecici);throw new RuntimeException('E-ticaret ana anahtarı kaydedilemedi.'); }
        }
        $anahtar=file_get_contents($yol);
        if ($anahtar===false || strlen($anahtar)!==32) { throw new RuntimeException('E-ticaret ana anahtarını yedekten geri yükleyin veya bağlantıyı yeniden tanımlayın.'); }
        return $anahtar;
    } finally { flock($kilit,LOCK_UN);fclose($kilit); }
}

function et_sir_sifrele(array $deger, int $firma, int $kanal): string
{
    $iv=random_bytes(12);$tag='';
    $sifre=openssl_encrypt(json_encode($deger,JSON_THROW_ON_ERROR),'aes-256-gcm',et_ana_anahtar(),OPENSSL_RAW_DATA,$iv,$tag,'eticaret:'.$firma.':'.$kanal,16);
    if ($sifre===false) { throw new RuntimeException('Bağlantı bilgileri şifrelenemedi.'); }
    return 'v1:'.base64_encode($iv.$tag.$sifre);
}

function et_sir_coz(string $sifre, int $firma, int $kanal): array
{
    $ham=str_starts_with($sifre,'v1:') ? base64_decode(substr($sifre,3),true) : false;
    $cozum=$ham!==false && strlen($ham)>28 ? openssl_decrypt(substr($ham,28),'aes-256-gcm',et_ana_anahtar(),OPENSSL_RAW_DATA,substr($ham,0,12),substr($ham,12,16),'eticaret:'.$firma.':'.$kanal) : false;
    if ($cozum===false) { throw new DomainException('Bağlantı açılamadı. E-ticaret ana anahtarını geri yükleyin veya API bilgilerini yeniden girin.'); }
    return json_decode($cozum,true,8,JSON_THROW_ON_ERROR);
}
