<?php

declare(strict_types=1);

/**
 * Kasa / banka modülü (yalnız bağımsız mod) ortak parçaları: erişim ve sayfa iskeleti.
 * Her sayfa ayr.php + kontrol.php'den sonra yükler.
 */

// Parça dosyası: doğrudan açılmaz (sayfalar ayr.php + kontrol.php'den sonra yükler).
if (!isset($terminalkullanici, $dbh)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/finans/finans_lib.php';

if ((int) m_p_yetki($terminalkullanici, 'M32') !== 1) {
    lumen_hata_sayfasi('Kasa/banka yetkiniz yok', 'Bu ekran için M32 (Kasa ve Banka İşlemleri) yetkisi gerekir.', 403, 'fa-lock');
}

require_once __DIR__ . '/../includes/belge/sayfa.php';

/** @return list<array{ID:int|string, TUR:int|string, KOD:string, AD:string, BANKA_ADI:?string, IBAN:?string, PARA_BIRIMI:string, BAKIYE:string|float}> */
function finans_hesaplar(PDO $dbh): array
{
    return $dbh->query("SELECT H.ID, H.TUR, H.KOD, H.AD, H.BANKA_ADI, H.IBAN, H.PARA_BIRIMI,
                               ISNULL((SELECT SUM(R.GIRIS - R.CIKIS) FROM dbo.LM_FINANS_HAREKET R WHERE R.HESAP_ID = H.ID), 0) AS BAKIYE
                        FROM dbo.LM_FINANS_HESAP H WHERE H.AKTIF = 1 ORDER BY H.TUR, H.KOD")->fetchAll(PDO::FETCH_ASSOC);
}
