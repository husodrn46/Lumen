<?php

declare(strict_types=1);

/**
 * rol_helper.php — Hazır rol şablonlarının tek yönetim noktası.
 *
 * İki tür rol vardır:
 *   - Built-in (sabit): "Yönetici" (tüm aktif yetkiler) ve "Tümünü Sıfırla".
 *     Bunlar koda gömülüdür, düzenlenemez/silinemez.
 *   - Özel roller: Satış Temsilcisi, Depo Görevlisi, Üretim vb. JSON dosyasında
 *     saklanır ve ayar/roller.php'den yönetilir. Dosya yoksa/bozuksa varsayılana düşer.
 *
 * Güvenlik: JSON diskten okunduğunda rol_ozel_temizle() ile whitelist'ten geçirilir
 * (yetki kodları yalnız yetki_tanimlari.php'deki aktif kodlar olabilir). Böylece
 * elle bozulmuş dosya bile matrise/AJAX'a geçersiz kod sızdıramaz.
 */

require_once __DIR__ . '/yetki_tanimlari.php';

if (!function_exists('rol_veri_dosyasi')) {
    /** Özel rollerin saklandığı JSON dosyası (kullanıcı verisi — .gitignore'da). */
    function rol_veri_dosyasi(): string
    {
        return __DIR__ . '/roller_ozel.json';
    }
}

if (!function_exists('rol_varsayilan_ozel')) {
    /** Kutudan çıkan özel roller — JSON yoksa bunlar kullanılır. */
    function rol_varsayilan_ozel(): array
    {
        return [
            'satis_temsilcisi' => [
                'name' => 'Satış Temsilcisi',
                'icon' => 'fa-user-tag',
                'color' => 'blue',
                'aciklama' => 'Sipariş girişi, müşteri bakiye, stok arama ve satış fiyatları',
                'yetki_turu' => 1,
                'yetkiler' => ['M1', 'M2', 'M4', 'M7', 'M8', 'CR1', 'ST1', 'ST2', 'SP3', 'SP4'],
            ],
            'magaza_kasiyer' => [
                'name' => 'Mağaza / Kasiyer',
                'icon' => 'fa-cash-register',
                'color' => 'purple',
                'aciklama' => 'Mağaza / hızlı satış, kasa özeti ve stok / fiyat arama',
                'yetki_turu' => 1,
                'yetkiler' => ['M1', 'M3', 'M4', 'M7', 'M24', 'CR1', 'ST1', 'ST2'],
            ],
            'depo_gorevlisi' => [
                'name' => 'Depo Görevlisi',
                'icon' => 'fa-warehouse',
                'color' => 'teal',
                'aciklama' => 'Ambar, barkod, stok arama ve bekleyen siparişler (fiyat / bakiye yok)',
                'yetki_turu' => 1,
                'yetkiler' => ['M2', 'M6', 'M7', 'M8', 'M9', 'ST1', 'SP4'],
            ],
            'uretim' => [
                'name' => 'Üretim',
                'icon' => 'fa-industry',
                'color' => 'amber',
                'aciklama' => 'Üretim / stok modülü, ambar ve yeni stok kartı (fiyat / bakiye yok)',
                'yetki_turu' => 1,
                'yetkiler' => ['M7', 'M8', 'M9', 'M11', 'M15', 'ST1', 'SP4'],
            ],
        ];
    }
}

if (!function_exists('rol_ozel_temizle')) {
    /**
     * Ham rol dizisini doğrula/temizle: anahtar biçimi, ad, tip (1|2) ve
     * yalnızca aktif yetki kodları. Built-in anahtarlar (yonetici/sifirla) atlanır.
     */
    function rol_ozel_temizle(array $data): array
    {
        $gecerliKodlar = yetki_aktif_kodlar();
        $out = [];
        foreach ($data as $key => $rol) {
            if (!is_string($key) || !preg_match('/^[a-z0-9_]{1,40}$/', $key)) {
                continue;
            }
            if (in_array($key, ['yonetici', 'sifirla'], true)) {
                continue; // built-in çakışması engellenir
            }
            if (!is_array($rol)) {
                continue;
            }
            $name = trim((string) ($rol['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $tur = (int) ($rol['yetki_turu'] ?? 1);
            if (!in_array($tur, [1, 2], true)) {
                $tur = 1;
            }
            $kodlar = [];
            foreach ((array) ($rol['yetkiler'] ?? []) as $k) {
                if (is_string($k) && in_array($k, $gecerliKodlar, true)) {
                    $kodlar[] = $k;
                }
            }
            $icon = (string) ($rol['icon'] ?? '');
            $color = (string) ($rol['color'] ?? '');
            $out[$key] = [
                'name'       => mb_substr($name, 0, 40),
                'icon'       => preg_match('/^fa-[a-z0-9-]{1,40}$/', $icon) ? $icon : 'fa-user-tag',
                'color'      => preg_match('/^[a-z]{1,20}$/', $color) ? $color : 'blue',
                'aciklama'   => mb_substr(trim((string) ($rol['aciklama'] ?? '')), 0, 120),
                'yetki_turu' => $tur,
                'yetkiler'   => array_values(array_unique($kodlar)),
            ];
        }
        return $out;
    }
}

if (!function_exists('rol_ozel_yukle')) {
    /** Özel rolleri JSON'dan oku; dosya yoksa/bozuksa varsayılanları döndür. */
    function rol_ozel_yukle(): array
    {
        $f = rol_veri_dosyasi();
        if (is_file($f)) {
            $raw = (string) file_get_contents($f);
            $data = json_decode($raw, true);
            if (is_array($data)) {
                return rol_ozel_temizle($data);
            }
        }
        return rol_varsayilan_ozel();
    }
}

if (!function_exists('rol_ozel_kaydet')) {
    /** Özel rolleri (temizlenmiş) JSON'a yaz. Başarı: true/false. */
    function rol_ozel_kaydet(array $roller): bool
    {
        $temiz = rol_ozel_temizle($roller);
        $json = json_encode($temiz, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        return file_put_contents(rol_veri_dosyasi(), $json) !== false;
    }
}

if (!function_exists('rol_tum_sablonlar')) {
    /**
     * Matris için tam liste: built-in Yönetici + özel roller + built-in Tümünü Sıfırla.
     * Sıra: Yönetici önce, özel roller ortada, Sıfırla en sonda.
     */
    function rol_tum_sablonlar(): array
    {
        $out = [
            'yonetici' => [
                'name'       => 'Yönetici',
                'icon'       => 'fa-crown',
                'color'      => 'red',
                'aciklama'   => 'Tam yetki (YETKI türü Yönetici olur, kullanımda olan tüm yetkiler açılır)',
                'yetki_turu' => 0,
                'yetkiler'   => yetki_aktif_kodlar(),
            ],
        ];
        foreach (rol_ozel_yukle() as $k => $r) {
            $out[$k] = $r;
        }
        $out['sifirla'] = [
            'name'       => 'Tümünü Sıfırla',
            'icon'       => 'fa-ban',
            'color'      => 'rose',
            'aciklama'   => 'Tüm yetkileri kaldır (YETKI türü değişmez)',
            'yetki_turu' => null,
            'yetkiler'   => [],
        ];
        return $out;
    }
}
