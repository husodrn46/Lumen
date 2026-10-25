<?php
declare(strict_types=1);

if (!isset($dbh, $terminalkullanici)) { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/eticaret/depo.php';
require_once __DIR__ . '/../includes/belge/sayfa.php';
if ((int) m_p_yetki($terminalkullanici, 'M33') !== 1) {
    lumen_hata_sayfasi('E-ticaret yetkiniz yok', 'M33 yetkisi gerekiyor.', 403);
}
function et_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
        lumen_hata_sayfasi('Geçersiz istek', 'Güvenlik doğrulaması başarısız.', 403);
    }
}
function et_hata(Throwable $e): string
{
    if ($e instanceof DomainException || $e instanceof InvalidArgumentException) { return $e->getMessage(); }
    error_log('eticaret: ' . $e->getMessage());
    return 'İşlem tamamlanamadı. Sayfayı yenileyip tekrar kontrol edin.';
}
function et_basla(string $baslik): void
{
    belge_sayfa_basla($baslik, 'fa-inbox');
    echo '<nav class="kart kart-ic" style="display:flex;gap:12px;flex-wrap:wrap"><a href="index.php">Sipariş kutusu</a><a href="kanallar.php">Satış kanalları</a></nav>';
}
