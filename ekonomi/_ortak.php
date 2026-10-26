<?php
declare(strict_types=1);
if (!isset($dbh,$terminalkullanici)) { http_response_code(404);exit; }
require_once __DIR__.'/../includes/evds/depo.php';
require_once __DIR__.'/../includes/evds/gorunum.php';
require_once __DIR__.'/../includes/belge/sayfa.php';
if ((int) m_p_yetki($terminalkullanici,'M34')!==1) { lumen_hata_sayfasi('Ekonomik göstergeler yetkiniz yok','Bu ekran için M34 yetkisi gerekir.',403); }
header('Cache-Control: no-store, private');

function ek_post(): void
{
    if ($_SERVER['REQUEST_METHOD']!=='POST' || !csrf_verify()) { lumen_hata_sayfasi('Geçersiz istek','Güvenlik doğrulaması başarısız.',403); }
}
function ek_hata(Throwable $e): string
{
    if ($e instanceof DomainException) { return $e->getMessage(); }
    // Anahtar, sorgu gövdesi veya exception argümanları loglanmaz.
    error_log('EVDS işlemi tamamlanamadı: '.get_class($e));
    return 'EVDS işlemi tamamlanamadı. Şema ve anahtar dosyası izinlerini yöneticinizle kontrol edin.';
}
function ek_basla(string $baslik): void
{
    belge_sayfa_basla($baslik,'fa-chart-area');
    echo '<link rel="stylesheet" href="../assets/css/ekonomi.css?v='.(int) filemtime(__DIR__.'/../assets/css/ekonomi.css').'"><div class="ek-modul"><nav class="ek-nav" aria-label="Ekonomik göstergeler">';
    foreach (['index.php'=>['Göstergeler','fa-chart-line'],'baglanti.php'=>['Kişisel bağlantı','fa-plug']] as $yol=>[$ad,$ikon]) {
        echo '<a href="'.$yol.'"'.(basename($_SERVER['SCRIPT_NAME'])===$yol ? ' aria-current="page"' : '').'><i class="fa-solid '.$ikon.'" aria-hidden="true"></i>'.bh($ad).'</a>';
    }
    echo '</nav>';
}
function ek_bitir(): void { echo '</div>';belge_sayfa_bitir(); }
function ek_alan(string $ad, string $varsayilan = ''): string { return is_string($_POST[$ad] ?? null) ? mb_substr($_POST[$ad],0,150) : $varsayilan; }
function ek_hazir_seriler(): array
{
    // Kodlar güncel resmî web servis kılavuzundaki örneklerden alınmıştır.
    return ['TP.DK.USD.S.YTL'=>'ABD doları · Döviz satış','TP.DK.EUR.A.YTL'=>'Euro · Döviz alış'];
}
function ek_sonuc(PDO $db, int $firma, int $kullanici): ?array
{
    $r=$_SESSION['evds_sonuc'] ?? null;
    if (!is_array($r)) { return null; }
    if (!empty($r['ornek'])) { return evds_ornek(); }
    return isset($r['ozet']) && is_string($r['ozet']) ? evds_onbellek($db,$firma,$kullanici,$r['ozet']) : null;
}
