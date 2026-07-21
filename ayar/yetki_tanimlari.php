<?php
declare(strict_types=1);

/**
 * yetki_tanimlari.php — M_P_YETKI kodlarinin TEK KAYNAGI (2026-07-10).
 *
 * Tum yetki kodlari (M1-M29, CR1-4, ST1-3, SP1-4) burada tanimlanir:
 * ad, ikon, renk, aciklama, grup ve aktiflik. Su dosyalar buradan beslenir:
 *   - yetki_matrisi.php      (duzenleme ekrani: gruplu matris + legend)
 *   - yetki_guncelle_ajax.php (kayit whitelist'i)
 *   - yetki_denetim_helper.php (alan yetki gruplari)
 *
 * YENI YETKI EKLERKEN: once M_P_YETKI'ye sutunu ekle (sql/), sonra buraya
 * tanimini yaz — matris, ajax ve denetim otomatik gorur.
 *
 * 'aktif' => false : kod tabaninda hicbir m_p_yetki() cagrisi kalmamis
 * (olu) kodlar. Matriste soluk + "kullanilmiyor" rozetiyle gosterilir;
 * sutunlar veri kaybi riskine karsi tabloda tutulur.
 */

if (!function_exists('yetki_gruplari')) {
    function yetki_gruplari(): array
    {
        return [
            'menu' => [
                'ad'    => 'Menü / Modüller',
                'ikon'  => 'fa-table-cells-large',
                'kodlar' => [
                    'M1'  => ['name' => 'Yeni Sipariş',                'icon' => 'fa-cart-plus',      'color' => 'blue',    'desc' => 'Cari seçip yeni sipariş girişi',                          'aktif' => true],
                    'M2'  => ['name' => 'Siparişler',                  'icon' => 'fa-list-check',     'color' => 'indigo',  'desc' => 'Açık sipariş listesi ve düzenleme',                       'aktif' => true],
                    'M3'  => ['name' => 'Mağaza Satış',                'icon' => 'fa-store',          'color' => 'purple',  'desc' => 'Mağaza / hızlı satış ekranı',                             'aktif' => true],
                    'M4'  => ['name' => 'Müşteri Bakiye',              'icon' => 'fa-wallet',         'color' => 'pink',    'desc' => 'Tek müşteri bakiye ekranı (M20 ile eşdeğer)',             'aktif' => true],
                    'M5'  => ['name' => 'Tüm Siparişler',              'icon' => 'fa-box-archive',    'color' => 'red',     'desc' => 'Geçmiş dahil tüm siparişler',                             'aktif' => true],
                    'M6'  => ['name' => 'Barkodlar',                   'icon' => 'fa-barcode',        'color' => 'orange',  'desc' => 'Barkod / koli araçları',                                  'aktif' => true],
                    'M7'  => ['name' => 'Stok Ara + Fiyat Listesi',    'icon' => 'fa-search',         'color' => 'amber',   'desc' => 'Stok arama ve fiyat listesi sayfaları',                   'aktif' => true],
                    'M8'  => ['name' => 'Bekleyen Ürünler',            'icon' => 'fa-clock',          'color' => 'yellow',  'desc' => 'Bekleyen sipariş / rezervasyon raporu',                   'aktif' => true],
                    'M9'  => ['name' => 'Ambar',                       'icon' => 'fa-warehouse',      'color' => 'lime',    'desc' => 'Ambar / depo işlemleri',                                  'aktif' => true],
                    'M10' => ['name' => 'Sil / Geri Dönüşüm',          'icon' => 'fa-trash',          'color' => 'green',   'desc' => 'Fiş silme ve geri dönüşüm kutusu',                        'aktif' => true],
                    'M11' => ['name' => 'Yeni Stok',                   'icon' => 'fa-box',            'color' => 'emerald', 'desc' => 'Yeni stok kartı oluşturma',                               'aktif' => true],
                    'M12' => ['name' => 'Yeni Cari',                   'icon' => 'fa-user-plus',      'color' => 'teal',    'desc' => 'Yeni cari kartı oluşturma',                               'aktif' => true],
                    'M13' => ['name' => 'Günlük İşlemler',             'icon' => 'fa-calendar-day',   'color' => 'cyan',    'desc' => 'Günlük işlem dökümü + Excel',                             'aktif' => true],
                    'M14' => ['name' => 'Hızlı Erişim',                'icon' => 'fa-bolt',           'color' => 'emerald', 'desc' => 'Cari bazlı hızlı arama: siparişleri, bakiyesi ve risk limiti özeti (bakiye için ayrıca CR1 gerekir)', 'aktif' => true],
                    'M15' => ['name' => 'Stoklar (Üretim)',            'icon' => 'fa-boxes-stacked',  'color' => 'blue',    'desc' => 'Stok/üretim modülü',                                      'aktif' => true],
                    'M16' => ['name' => 'Ayarlar',                     'icon' => 'fa-cog',            'color' => 'violet',  'desc' => 'Yönetim paneli (tüm ayar sayfaları)',                     'aktif' => true],
                    'M17' => ['name' => 'Raporlar',                    'icon' => 'fa-chart-line',     'color' => 'fuchsia', 'desc' => 'Rapor dashboard ve tüm raporlar',                         'aktif' => true],
                    'M18' => ['name' => 'Loglar / Değişiklik Geçmişi', 'icon' => 'fa-history',        'color' => 'rose',    'desc' => 'loglar.php + fiş geçmişi butonları',                      'aktif' => true],
                    'M19' => ['name' => 'Özel Cari Kısıtı',            'icon' => 'fa-user-shield',    'color' => 'gray',    'desc' => 'Özel cari görme / sipariş kısıtı',                        'aktif' => true],
                    'M20' => ['name' => 'Müşteri Bakiye (Eski)',       'icon' => 'fa-shield',         'color' => 'slate',   'desc' => 'M4 ile aynı ekrana erişim verir (eski kod)',              'aktif' => true],
                    'M21' => ['name' => 'Döviz İşlemleri',             'icon' => 'fa-dollar-sign',    'color' => 'indigo',  'desc' => 'Döviz modülü (doviz_guard)',                              'aktif' => true],
                    'M22' => ['name' => 'Yazdırma Geçmişi',            'icon' => 'fa-print',          'color' => 'blue',    'desc' => 'Sipariş listelerinde yazdırma geçmişi',                   'aktif' => true],
                    'M23' => ['name' => 'Kullanıcı Aktivite',          'icon' => 'fa-chart-simple',   'color' => 'green',   'desc' => 'Kullanıcı dashboard + sipariş aktivitesi',                'aktif' => true],
                    'M24' => ['name' => 'Kasa Özeti',                  'icon' => 'fa-cash-register',  'color' => 'amber',   'desc' => 'Ana sayfa mağaza satış / kasa özeti',                     'aktif' => true],
                    'M25' => ['name' => 'Dosya Portalı',               'icon' => 'fa-folder-open',    'color' => 'indigo',  'desc' => 'Dosya portalı erişimi',                                   'aktif' => true],
                    'M26' => ['name' => 'Fiyat Listesi (Eski)',        'icon' => 'fa-tags',           'color' => 'orange',  'desc' => 'Kullanılmıyor — fiyat listesi M7 ile açılır',             'aktif' => false],
                    'M27' => ['name' => 'İthalat Modülü',              'icon' => 'fa-ship',           'color' => 'sky',     'desc' => 'İthalat takip modülü',                                    'aktif' => true],
                    'M30' => ['name' => 'Çek İşlemleri',               'icon' => 'fa-money-check-dollar', 'color' => 'emerald', 'desc' => 'Çek giriş/çıkış: portföy, ciro, kendi çekimiz (bakiye ekranından)', 'aktif' => true],
                ],
            ],
            'gorev' => [
                'ad'    => 'Görevler',
                'ikon'  => 'fa-list-check',
                'kodlar' => [
                    'M28' => ['name' => 'Görevler Modülü',             'icon' => 'fa-clipboard-list', 'color' => 'indigo',  'desc' => 'Görev modülüne giriş',                                    'aktif' => true],
                    'M29' => ['name' => 'Görev Atama',                 'icon' => 'fa-user-check',     'color' => 'emerald', 'desc' => 'Başkasına görev atayabilme',                              'aktif' => true],
                ],
            ],
            'cari' => [
                'ad'    => 'Cari / Bakiye Alan Yetkileri',
                'ikon'  => 'fa-address-book',
                'kodlar' => [
                    'CR1' => ['name' => 'Cari Bakiye Görme',           'icon' => 'fa-scale-balanced', 'color' => 'red',     'desc' => 'Listelerde/raporlarda bakiye sütunu görünür',             'aktif' => true],
                    'CR2' => ['name' => 'Cari Yetki 2',                'icon' => 'fa-circle-question','color' => 'gray',    'desc' => 'Kod tabanında kullanılmıyor',                             'aktif' => false],
                    'CR3' => ['name' => 'Cari Yetki 3',                'icon' => 'fa-circle-question','color' => 'gray',    'desc' => 'Kod tabanında kullanılmıyor',                             'aktif' => false],
                    'CR4' => ['name' => 'Özel Cari Bakiyesi',          'icon' => 'fa-user-lock',      'color' => 'purple',  'desc' => 'Özel Cari ekranından kısıtlanmış carilerin bakiyesini görme', 'aktif' => true],
                ],
            ],
            'stok' => [
                'ad'    => 'Stok Alan Yetkileri',
                'ikon'  => 'fa-boxes-stacked',
                'kodlar' => [
                    'ST1' => ['name' => 'Stok Miktarı Görme',          'icon' => 'fa-warehouse',      'color' => 'blue',    'desc' => 'Stok listelerinde miktar görünür',                        'aktif' => true],
                    'ST2' => ['name' => 'Satış Fiyatı Görme/Düzenleme','icon' => 'fa-tag',            'color' => 'emerald', 'desc' => 'Fiyat görünür + fiyat güncelleme ekranı',                 'aktif' => true],
                    'ST3' => ['name' => 'Stok Yetki 3',                'icon' => 'fa-circle-question','color' => 'gray',    'desc' => 'Kod tabanında kullanılmıyor',                             'aktif' => false],
                ],
            ],
            'siparis' => [
                'ad'    => 'Sipariş / Fiyat Alan Yetkileri',
                'ikon'  => 'fa-cart-shopping',
                'kodlar' => [
                    'SP1' => ['name' => 'Sipariş Fiyatı Değiştirme',   'icon' => 'fa-pen-to-square',  'color' => 'red',     'desc' => 'Sipariş satırında fiyat alanı yazılabilir',               'aktif' => true],
                    'SP2' => ['name' => 'Son Alış Fiyatları',          'icon' => 'fa-clock-rotate-left','color' => 'amber', 'desc' => 'Ürünün son alış fiyatlarını görme',                       'aktif' => true],
                    'SP3' => ['name' => 'Son Satış Fiyatları',         'icon' => 'fa-receipt',        'color' => 'sky',     'desc' => 'Carinin son satış fiyatlarını görme',                     'aktif' => true],
                    'SP4' => ['name' => 'Bekleyen Sipariş Rozeti',     'icon' => 'fa-dolly',          'color' => 'green',   'desc' => 'Stok kartında bekleyen sipariş miktarı görünür',          'aktif' => true],
                ],
            ],
        ];
    }
}

if (!function_exists('yetki_tum_tanimlar')) {
    /** Duz kod => tanim listesi (grup bilgisi 'grup' anahtarina eklenir). */
    function yetki_tum_tanimlar(): array
    {
        $out = [];
        foreach (yetki_gruplari() as $grupKey => $grup) {
            foreach ($grup['kodlar'] as $kod => $tanim) {
                $tanim['grup'] = $grupKey;
                $out[$kod] = $tanim;
            }
        }
        return $out;
    }
}

if (!function_exists('yetki_tum_kodlar')) {
    /** Tum yetki kodlari (ajax whitelist icin). */
    function yetki_tum_kodlar(): array
    {
        return array_keys(yetki_tum_tanimlar());
    }
}

if (!function_exists('yetki_aktif_kodlar')) {
    /** Yalniz aktif (kullanimda olan) kodlar — rol sablonlari icin. */
    function yetki_aktif_kodlar(): array
    {
        $out = [];
        foreach (yetki_tum_tanimlar() as $kod => $t) {
            if (!empty($t['aktif'])) { $out[] = $kod; }
        }
        return $out;
    }
}
