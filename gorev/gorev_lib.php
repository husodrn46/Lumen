<?php

declare(strict_types=1);

/**
 * ============================================================================
 * gorev_lib.php — Gorev / Yapilacaklar modulu ortak fonksiyonlari
 * ----------------------------------------------------------------------------
 * Tablolar: M_GOREV, M_GOREV_HAREKET, M_GOREV_EK (bkz. sql/m_gorev.sql)
 * Yetkiler: M28 = modul erisimi, M29 = baskasina gorev atama
 * Kullanicilar LG_SLSMAN tablosundan okunur (LOGO tablosuna yazma yapilmaz).
 *
 * include_once("gorev_lib.php"); (ayr.php'den sonra — m_p_yetki gerekir)
 * ============================================================================
 */

/* ---- Durum kodlari -------------------------------------------------------- */
const GOREV_DURUM_YENI       = 0;
const GOREV_DURUM_GORULDU    = 1;
const GOREV_DURUM_YAPILIYOR  = 2;
const GOREV_DURUM_BITTI      = 3;
const GOREV_DURUM_REDDEDILDI = 4;
const GOREV_DURUM_ONAYLANDI  = 5;
const GOREV_DURUM_IPTAL      = 9;

/* ---- Oncelik kodlari ------------------------------------------------------ */
const GOREV_ONCELIK_DUSUK  = 1;
const GOREV_ONCELIK_NORMAL = 2;
const GOREV_ONCELIK_YUKSEK = 3;

/* ---- Hareket tipleri (M_GOREV_HAREKET.TIP) ------------------------------- */
const GOREV_HAR_DURUM    = 1;
const GOREV_HAR_YORUM    = 2;
const GOREV_HAR_OLUSTUR  = 3;
const GOREV_HAR_EK       = 4;

if (!function_exists('gorev_durum_bilgi')) {
    /**
     * Durum kodundan etiket + renk + ikon dondurur (cari.php paletiyle uyumlu).
     */
    function gorev_durum_bilgi(int $durum): array
    {
        $harita = [
            GOREV_DURUM_YENI       => ['etiket' => 'Yeni',       'renk' => '#1d4ed8', 'bg' => '#eff6ff', 'ikon' => 'fa-circle-dot'],
            GOREV_DURUM_GORULDU    => ['etiket' => 'Görüldü',    'renk' => '#4338ca', 'bg' => '#eef2ff', 'ikon' => 'fa-eye'],
            GOREV_DURUM_YAPILIYOR  => ['etiket' => 'Yapılıyor',  'renk' => '#b45309', 'bg' => '#fffbeb', 'ikon' => 'fa-person-digging'],
            GOREV_DURUM_BITTI      => ['etiket' => 'Bitti',      'renk' => '#047857', 'bg' => '#ecfdf5', 'ikon' => 'fa-circle-check'],
            GOREV_DURUM_REDDEDILDI => ['etiket' => 'Reddedildi', 'renk' => '#b91c1c', 'bg' => '#fef2f2', 'ikon' => 'fa-circle-xmark'],
            GOREV_DURUM_ONAYLANDI  => ['etiket' => 'Onaylandı',  'renk' => '#065f46', 'bg' => '#d1fae5', 'ikon' => 'fa-check-double'],
            GOREV_DURUM_IPTAL      => ['etiket' => 'İptal',      'renk' => '#6b7280', 'bg' => '#f3f4f6', 'ikon' => 'fa-ban'],
        ];
        return $harita[$durum] ?? $harita[GOREV_DURUM_YENI];
    }
}

if (!function_exists('gorev_oncelik_bilgi')) {
    /**
     * Oncelik kodundan etiket + renk dondurur.
     */
    function gorev_oncelik_bilgi(int $oncelik): array
    {
        $harita = [
            GOREV_ONCELIK_DUSUK  => ['etiket' => 'Düşük',  'renk' => '#6b7280'],
            GOREV_ONCELIK_NORMAL => ['etiket' => 'Normal', 'renk' => '#2563eb'],
            GOREV_ONCELIK_YUKSEK => ['etiket' => 'Yüksek', 'renk' => '#6F1022'],
        ];
        return $harita[$oncelik] ?? $harita[GOREV_ONCELIK_NORMAL];
    }
}

/* ---- Kategori kodlari (sabit liste) -------------------------------------- */
const GOREV_KATEGORI_GENEL    = 0;
const GOREV_KATEGORI_DEPO     = 1;
const GOREV_KATEGORI_SATIS    = 2;
const GOREV_KATEGORI_SEVKIYAT = 3;
const GOREV_KATEGORI_MUHASEBE = 4;

if (!function_exists('gorev_kategori_bilgi')) {
    /**
     * Kategori kodundan etiket + renk + ikon dondurur.
     */
    function gorev_kategori_bilgi(?int $kat): array
    {
        $harita = [
            GOREV_KATEGORI_GENEL    => ['etiket' => 'Genel',    'renk' => '#6b7280', 'bg' => '#f3f4f6', 'ikon' => 'fa-tag'],
            GOREV_KATEGORI_DEPO     => ['etiket' => 'Depo',     'renk' => '#b45309', 'bg' => '#fffbeb', 'ikon' => 'fa-warehouse'],
            GOREV_KATEGORI_SATIS    => ['etiket' => 'Satış',    'renk' => '#047857', 'bg' => '#ecfdf5', 'ikon' => 'fa-cart-shopping'],
            GOREV_KATEGORI_SEVKIYAT => ['etiket' => 'Sevkiyat', 'renk' => '#1d4ed8', 'bg' => '#eff6ff', 'ikon' => 'fa-truck'],
            GOREV_KATEGORI_MUHASEBE => ['etiket' => 'Muhasebe', 'renk' => '#4338ca', 'bg' => '#eef2ff', 'ikon' => 'fa-calculator'],
        ];
        return $harita[$kat ?? GOREV_KATEGORI_GENEL] ?? $harita[GOREV_KATEGORI_GENEL];
    }
}

if (!function_exists('gorev_kategori_listesi')) {
    /**
     * Form/filtre icin kategori kod => etiket listesi.
     */
    function gorev_kategori_listesi(): array
    {
        return [
            GOREV_KATEGORI_GENEL    => 'Genel',
            GOREV_KATEGORI_DEPO     => 'Depo',
            GOREV_KATEGORI_SATIS    => 'Satış',
            GOREV_KATEGORI_SEVKIYAT => 'Sevkiyat',
            GOREV_KATEGORI_MUHASEBE => 'Muhasebe',
        ];
    }
}

if (!function_exists('gorev_durum_kapali_mi')) {
    /**
     * Durum "tamamlanmis/kapali" mi? Bitti/Reddedildi/Onaylandi/Iptal.
     * Ek/checklist ekleme, vade gosterimi gibi yerlerde kullanilir.
     */
    function gorev_durum_kapali_mi(int $durum): bool
    {
        return in_array($durum, [GOREV_DURUM_BITTI, GOREV_DURUM_REDDEDILDI, GOREV_DURUM_ONAYLANDI, GOREV_DURUM_IPTAL], true);
    }
}

if (!function_exists('gorev_durum_kesin_kapali_mi')) {
    /**
     * Durum atanan tarafindan dahi degistirilemeyecek kadar kapali mi?
     * (Reddedildi/Onaylandi/Iptal). "Bitti" haric — atanan onu geri alabilir.
     */
    function gorev_durum_kesin_kapali_mi(int $durum): bool
    {
        return in_array($durum, [GOREV_DURUM_REDDEDILDI, GOREV_DURUM_ONAYLANDI, GOREV_DURUM_IPTAL], true);
    }
}

if (!function_exists('gorev_altgorev_ilerleme')) {
    /**
     * Bir gorevin checklist ilerlemesi: ['toplam'=>n, 'tamam'=>m, 'yuzde'=>0-100].
     */
    function gorev_altgorev_ilerleme(PDO $dbh, int $gid): array
    {
        try {
            $st = $dbh->prepare(
                "SELECT COUNT(*) AS toplam, SUM(CASE WHEN TAMAM = 1 THEN 1 ELSE 0 END) AS tamam
                   FROM M_GOREV_ALTGOREV WITH(NOLOCK) WHERE GOREV_ID = :id"
            );
            $st->bindValue(':id', $gid, PDO::PARAM_INT);
            $st->execute();
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $toplam = (int) ($r['toplam'] ?? 0);
            $tamam = (int) ($r['tamam'] ?? 0);
            $yuzde = $toplam > 0 ? (int) round(($tamam / $toplam) * 100) : 0;
            return ['toplam' => $toplam, 'tamam' => $tamam, 'yuzde' => $yuzde];
        } catch (PDOException $e) {
            error_log('gorev_altgorev_ilerleme: ' . $e->getMessage());
            return ['toplam' => 0, 'tamam' => 0, 'yuzde' => 0];
        }
    }
}

if (!function_exists('gorev_erisim_var_mi')) {
    /**
     * Kullanici gorev modulune girebilir mi? (M28 ya da Yonetici)
     */
    function gorev_erisim_var_mi(int|string $id): bool
    {
        return ((int) (m_p_yetki($id, 'M28') ?? 0)) === 1;
    }
}

if (!function_exists('gorev_atama_yetkisi_var_mi')) {
    /**
     * Kullanici baskasina gorev atayabilir mi? (M29 ya da Yonetici)
     */
    function gorev_atama_yetkisi_var_mi(int|string $id): bool
    {
        return ((int) (m_p_yetki($id, 'M29') ?? 0)) === 1;
    }
}

if (!function_exists('gorev_atanabilir_kullanicilar')) {
    /**
     * Gorev atanabilecek aktif personel listesi (LG_SLSMAN).
     */
    function gorev_atanabilir_kullanicilar(PDO $dbh): array
    {
        global $firmano;
        try {
            $stmt = $dbh->prepare(
                "SELECT LOGICALREF, CODE, DEFINITION_
                   FROM LG_SLSMAN WITH(NOLOCK)
                  WHERE FIRMNR = :firma AND ACTIVE = 0
                  ORDER BY DEFINITION_"
            );
            $stmt->bindValue(':firma', (int) ($firmano ?? 1), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('gorev_atanabilir_kullanicilar: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('gorev_kullanici_adi')) {
    /**
     * Tek bir personel adi (statik cache ile).
     */
    function gorev_kullanici_adi(PDO $dbh, int $id): string
    {
        static $cache = [];
        if ($id <= 0) {
            return '-';
        }
        if (isset($cache[$id])) {
            return $cache[$id];
        }
        try {
            $stmt = $dbh->prepare("SELECT DEFINITION_, CODE FROM LG_SLSMAN WITH(NOLOCK) WHERE LOGICALREF = :id");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $ad = $row ? (trim((string) $row['DEFINITION_']) ?: trim((string) $row['CODE'])) : '';
            return $cache[$id] = ($ad !== '' ? $ad : ('#' . $id));
        } catch (PDOException $e) {
            error_log('gorev_kullanici_adi: ' . $e->getMessage());
            return '#' . $id;
        }
    }
}

if (!function_exists('gorev_bekleyen_sayisi')) {
    /**
     * Kullaniciya atanmis, henuz tamamlanmamis gorev sayisi (dashboard rozeti).
     * Yeni + Goruldu + Yapiliyor durumlari sayilir.
     */
    function gorev_bekleyen_sayisi(PDO $dbh, int $id): int
    {
        if ($id <= 0) {
            return 0;
        }
        try {
            $stmt = $dbh->prepare(
                "SELECT COUNT(*) FROM M_GOREV WITH(NOLOCK)
                  WHERE ATANAN_ID = :id AND AKTIF = 1 AND DURUM IN (0, 1, 2)"
            );
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('gorev_bekleyen_sayisi: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('gorev_bas_harfler')) {
    /**
     * Avatar icin ad-soyad bas harfleri.
     */
    function gorev_bas_harfler(string $ad): string
    {
        $ad = trim($ad);
        if ($ad === '') {
            return '?';
        }
        $parcalar = preg_split('/\s+/', $ad) ?: [$ad];
        $bas = mb_substr($parcalar[0], 0, 1, 'UTF-8');
        $son = count($parcalar) > 1 ? mb_substr((string) end($parcalar), 0, 1, 'UTF-8') : '';
        return mb_strtoupper($bas . $son, 'UTF-8');
    }
}

if (!function_exists('gorev_tarih_format')) {
    /**
     * SQL datetime degerini Turkce gosterime cevirir.
     */
    function gorev_tarih_format(?string $tarih, bool $saatli = true): string
    {
        if (empty($tarih)) {
            return '';
        }
        $ts = strtotime($tarih);
        if ($ts === false) {
            return '';
        }
        return $saatli ? date('d.m.Y H:i', $ts) : date('d.m.Y', $ts);
    }
}

if (!function_exists('gorev_vade_durumu')) {
    /**
     * Vade durumunu dondurur: '' (vade yok/kapali) | 'gecikti' | 'bugun' | 'normal'.
     * Tamamlanan/reddedilen/iptal gorevlerde bos doner.
     */
    function gorev_vade_durumu(?string $vade, int $durum): string
    {
        if (empty($vade) || gorev_durum_kapali_mi($durum)) {
            return '';
        }
        $vts = strtotime($vade);
        if ($vts === false) {
            return '';
        }
        $bugun = strtotime(date('Y-m-d'));
        $vadeGun = strtotime(date('Y-m-d', $vts));
        if ($vadeGun < $bugun) {
            return 'gecikti';
        }
        if ($vadeGun === $bugun) {
            return 'bugun';
        }
        return 'normal';
    }
}

if (!function_exists('gorev_vade_etiketi')) {
    /**
     * Vade icin insan tarafindan okunur kisa etiket ("Bugün", "Yarın", "3 gün gecikti"...).
     */
    function gorev_vade_etiketi(?string $vade, int $durum): string
    {
        if (empty($vade)) {
            return '';
        }
        $vts = strtotime($vade);
        if ($vts === false) {
            return '';
        }
        $bugun = strtotime(date('Y-m-d'));
        $vadeGun = strtotime(date('Y-m-d', $vts));
        $farkGun = (int) round(($vadeGun - $bugun) / 86400);

        if (gorev_durum_kapali_mi($durum)) {
            return date('d.m.Y', $vts);
        }
        if ($farkGun < 0) {
            return abs($farkGun) . ' gün gecikti';
        }
        if ($farkGun === 0) {
            return 'Bugün';
        }
        if ($farkGun === 1) {
            return 'Yarın';
        }
        if ($farkGun <= 7) {
            return $farkGun . ' gün kaldı';
        }
        return date('d.m.Y', $vts);
    }
}

if (!function_exists('gorev_getir_yetkili')) {
    /**
     * Goreve erisim yetkisi olan (atayan ya da atanan) kullanici icin satiri getirir.
     * Yetkisiz ya da bulunamazsa null doner.
     */
    function gorev_getir_yetkili(PDO $dbh, int $gid, int $benimId): ?array
    {
        if ($gid <= 0) {
            return null;
        }
        $stmt = $dbh->prepare("SELECT * FROM M_GOREV WITH(NOLOCK) WHERE ID = :id AND AKTIF = 1");
        $stmt->bindValue(':id', $gid, PDO::PARAM_INT);
        $stmt->execute();
        $g = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$g) {
            return null;
        }
        if ((int) $g['ATAYAN_ID'] !== $benimId && (int) $g['ATANAN_ID'] !== $benimId) {
            return null;
        }
        return $g;
    }
}

if (!function_exists('gorev_hareket_ekle')) {
    /**
     * M_GOREV_HAREKET'e kayit ekler. Transaction'i cagiran yonetir.
     */
    function gorev_hareket_ekle(PDO $dbh, int $gid, int $kim, int $tip, ?int $eski, ?int $yeni, ?string $mesaj): void
    {
        $st = $dbh->prepare(
            "INSERT INTO M_GOREV_HAREKET (GOREV_ID, PERSONEL_ID, TIP, ESKI_DURUM, YENI_DURUM, MESAJ, TARIH)
             VALUES (:g, :p, :t, :e, :y, :m, GETDATE())"
        );
        $st->bindValue(':g', $gid, PDO::PARAM_INT);
        $st->bindValue(':p', $kim, PDO::PARAM_INT);
        $st->bindValue(':t', $tip, PDO::PARAM_INT);
        $st->bindValue(':e', $eski, $eski === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->bindValue(':y', $yeni, $yeni === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->bindValue(':m', $mesaj, $mesaj === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->execute();
    }
}
