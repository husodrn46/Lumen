<?php

declare(strict_types=1);

/**
 * Belge modülü (yalnız bağımsız mod) ortak parçaları: erişim, sayfa iskeleti, biçimleme.
 * Her sayfa ayr.php + kontrol.php'den sonra yükler.
 */

// Parça dosyası: doğrudan açılmaz (sayfalar ayr.php + kontrol.php'den sonra yükler).
if (!isset($terminalkullanici, $dbh)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/belge/belge_lib.php';

// M31 (Faturalama): tüm belgeler. M15 (Stoklar) yalnız: üretimden giriş ve sayım
// (depo personeli; fatura, irsaliye ve fiyat görmez).
$belgeTam = (int) m_p_yetki($terminalkullanici, 'M31') === 1;
if (!$belgeTam && (int) m_p_yetki($terminalkullanici, 'M15') !== 1) {
    lumen_hata_sayfasi('Belge yetkiniz yok', 'Bu ekran için M31 (Faturalama) ya da üretim/sayım için M15 (Stoklar) yetkisi gerekir.', 403, 'fa-lock');
}
$belgeTurleri = lumen_belge_izinli_turler($belgeTam);

require_once __DIR__ . '/../includes/belge/sayfa.php';
