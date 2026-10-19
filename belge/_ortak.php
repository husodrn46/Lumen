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

if ((int) m_p_yetki($terminalkullanici, 'M31') !== 1) {
    lumen_hata_sayfasi('Fatura yetkiniz yok', 'Bu ekran için M31 (Faturalama) yetkisi gerekir.', 403, 'fa-lock');
}

require_once __DIR__ . '/../includes/belge/sayfa.php';
