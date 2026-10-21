<?php

declare(strict_types=1);

/** Tanımlar (yalnız bağımsız mod) ortak parçaları: erişim (M16 Ayarlar) ve sayfa iskeleti. */

// Parça dosyası: doğrudan açılmaz (sayfalar ayr.php + kontrol.php'den sonra yükler).
if (!isset($terminalkullanici, $dbh)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/tanim/tanim_lib.php';

// İkinci savunma hattı: bu modül yalnız bağımsız modda çalışır (modül korumasından bağımsız).
if (!lumen_bagimsiz_mi()) {
    lumen_hata_sayfasi('Modül kullanılamıyor', 'Bu ekran yalnız bağımsız modda (Lumen veritabanı) kullanılır.', 501, 'fa-person-digging');
}


if ((int) m_p_yetki($terminalkullanici, 'M16') !== 1) {
    lumen_hata_sayfasi('Yetkiniz yok', 'Tanımlar için M16 (Ayarlar) yetkisi gerekir.', 403, 'fa-lock');
}

require_once __DIR__ . '/../includes/belge/sayfa.php';

/** @return array<string, array{0:string, 1:string}> sekme → [ad, ikon] */
function tanim_sekmeleri(): array
{
    return ['firma' => ['Firma', 'fa-building'], 'hesap' => ['Kasa ve banka', 'fa-building-columns'], 'depo' => ['Depolar', 'fa-warehouse'],
            'birim' => ['Birimler', 'fa-ruler'], 'kur' => ['Döviz kurları', 'fa-coins']];
}
