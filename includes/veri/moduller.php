<?php

declare(strict_types=1);

/**
 * Modül kayıt defteri — geçiş dönemi koruması.
 *
 * Bağımsız moda (Lumen veritabanı) alan alan geçiliyor (docs/mimari/bagimsiz-lumen.md).
 * Bir modül bağımsız modda henüz hazır değilse sayfaları LOGO tablolarını arayıp
 * çökmek yerine "bu modül henüz hazır değil" kartı gösterir; menüde de görünmez.
 * Bir alan taşındığında yalnız burada 'bagimsiz' => true yapılır.
 *
 * Bağlı (LOGO) modda her şey açıktır; bu dosya davranışı değiştirmez.
 */

require_once __DIR__ . '/kaynak.php';

if (!function_exists('lumen_moduller')) {
    /**
     * Modül → ad, yol önekleri (uygulama köküne göre) ve bağımsız modda hazır olup olmadığı.
     * Önek eşleşmesinde en uzun önek kazanır (ör. 'stok/uretim' 'stok/'tan önce).
     *
     * @return array<string, array{ad:string, yollar:string[], bagimsiz:bool, logo?:bool}>
     */
    function lumen_moduller(): array
    {
        return [
            'cekirdek' => ['ad' => 'Çekirdek', 'bagimsiz' => true, 'yollar' => [
                'index.php', 'giris.php', 'bildirimler.php', 'sifre_degistir.php', 'ayar/gorunum.php',
                'ayar/kart_favori_kaydet.php', 'js_hata_log.php', 'ajax_hava.php', 'kurulum.php',
            ]],
            'gorev' => ['ad' => 'Görevler', 'bagimsiz' => true, 'yollar' => ['gorev/']],
            'yonetim' => ['ad' => 'Kullanıcı ve yetki yönetimi', 'bagimsiz' => true, 'yollar' => ['ayar/', 'loglar.php']],
            // Özel cari kısıtları cari kartlarını listeler: cari ile birlikte taşınır.
            'cari' => ['ad' => 'Cari', 'bagimsiz' => true, 'yollar' => ['cari/', 'ayar/ozel_cari_kisitlari.php']],
            // Cari ekranlarından açılan ama başka alana ait sayfalar: o alanla birlikte taşınır.
            'cari_belge' => ['ad' => 'Fatura ve kasa detayları', 'bagimsiz' => false, 'yollar' => [
                'cari/lg_nakit.php', 'cari/lg_nakit_2025.php', 'cari/lg_fatura_yazdir.php', 'cari/lg_fatura_yazdir_2025.php',
                'cari/lg_alinan-hizmet.php', 'cari/lg_alinan-hizmet_2025.php', 'cari/lg_hareketdetay.php',
            ]],
            'cari_siparis' => ['ad' => 'Siparişli cari işlemleri', 'bagimsiz' => true, 'yollar' => [
                'cari/hizli_erisim.php', 'cari/cari_olustur_ve_aktar.php',
            ]],
            'stok' => ['ad' => 'Stok', 'bagimsiz' => true, 'yollar' => ['stok/', 'barkod/', 'fiyat/']],
            // stok/ altındaki sipariş satırı ve sipariş hareket ekranları sipariş modülündedir.
            'stok_siparis' => ['ad' => 'Sipariş satırları', 'bagimsiz' => true, 'yollar' => [
                'stok/lg_stok_ekle.php', 'stok/lg_stok_bul.php', 'stok/lg_stok_duzenle.php', 'stok/lg_stoksonalisx.php',
                'stok/favori_urunler_yukle.php', 'stok/stok_hareket_aktar.php', 'stok/stok_hareket_detay.php',
                'stok/stok_hareket_excel.php', 'stok/stok_hareket_excel_xlsx.php', 'stok/stok_hareket_pdf.php',
                'stok/stok_hareket_tarih_degistir.php',
            ]],
            'uretim' => ['ad' => 'Üretim fişleri', 'bagimsiz' => false, 'yollar' => [
                'stok/index.php', 'stok/uretim_giris.php', 'stok/uretim_miktar_onar.php',
            ]],
            'eticaret' => ['ad' => 'E-ticaret', 'bagimsiz' => true, 'yollar' => ['eticaret/']],
            'siparis' => ['ad' => 'Sipariş', 'bagimsiz' => true, 'yollar' => ['siparis/', 'yazdir/']],
            'doviz' => ['ad' => 'Dövizli sipariş', 'bagimsiz' => true, 'yollar' => ['doviz/']],
            'cek' => ['ad' => 'Çek / senet', 'bagimsiz' => true, 'yollar' => ['cek/']],
            // Lumen'in kendi kasa/banka fişleri: yalnız bağımsız modda (LOGO'da kasa/banka LOGO'dadır).
            'finans' => ['ad' => 'Kasa ve banka', 'bagimsiz' => true, 'logo' => false, 'yollar' => ['finans/']],
            // Firma, kasa/banka hesabı, depo, birim ve kur tanımları: LOGO modunda bunlar LOGO'da tanımlanır.
            // Cari ve stok kartı (hareket geçmişi): bağımsız modun kendi ekranları.
            'kart' => ['ad' => 'Cari ve stok kartı', 'bagimsiz' => true, 'logo' => false, 'yollar' => ['kart/']],
            'tanimlar' => ['ad' => 'Tanımlar', 'bagimsiz' => true, 'logo' => false, 'yollar' => ['tanimlar/']],
            'rapor' => ['ad' => 'Raporlar', 'bagimsiz' => true, 'yollar' => ['rapor/', 'dashboard_kullanici.php']],
            'api' => ['ad' => 'API', 'bagimsiz' => true, 'yollar' => ['api/']],
            // Rapor, çek ve fatura uçları kendi modülleriyle birlikte açılır.
            'api_rapor' => ['ad' => 'API raporları', 'bagimsiz' => true, 'yollar' => [
                'api/rapor_borc_alacak.php', 'api/rapor_cek_vade.php', 'api/rapor_karsilastirmali.php', 'api/rapor_kasa.php',
                'api/rapor_musteriler_top.php', 'api/rapor_nakit_akis.php', 'api/rapor_pazarlamaci.php', 'api/rapor_satis_aylik.php',
                'api/rapor_top_satis.php', 'api/rapor_yaslandirma.php', 'api/cek_listesi.php', 'api/fatura_listesi.php',
                'api/cari_hareket.php', 'api/cari_hareket_detay.php',
            ]],
            // Lumen'in kendi belge modülü (fatura, irsaliye, iade, üretim girişi, sayım): yalnız
            // bağımsız modda — LOGO modunda bu belgeler LOGO'da kesilir.
            'belge' => ['ad' => 'Faturalar ve belgeler', 'bagimsiz' => true, 'logo' => false, 'yollar' => ['belge/']],
            // LOGO'ya özgü: siparişi LOGO faturasına çevirir. Bağımsız modda fatura,
            // Lumen'in kendi belge modülüdür (Faz 4).
            'logo_fatura' => ['ad' => 'Faturalama (LOGO, BETA)', 'bagimsiz' => false, 'yollar' => ['fatura/']],
        ];
    }
}

if (!function_exists('lumen_yolun_modulu')) {
    /** Uygulama köküne göre yolun (ör. 'cari/cari.php') modülü; bilinmiyorsa null. */
    function lumen_yolun_modulu(string $yol): ?string
    {
        // Windows/IIS dosya sistemi büyük/küçük harfe duyarsızdır: karşılaştırma da öyle olmalı
        // (yoksa "cari/LG_NAKIT.php" kapalı modül yerine üst klasörün modülüne düşerdi).
        $yol = strtolower(ltrim(str_replace('\\', '/', $yol), '/'));
        $yol = (string) preg_replace('#/+#', '/', $yol);
        $bulunan = null;
        $uzunluk = -1;
        foreach (lumen_moduller() as $ad => $m) {
            foreach ($m['yollar'] as $onek) {
                $onek = strtolower($onek);
                $eslesir = str_ends_with($onek, '/') ? str_starts_with($yol, $onek) : $yol === $onek;
                if ($eslesir && strlen($onek) > $uzunluk) {
                    $bulunan = $ad;
                    $uzunluk = strlen($onek);
                }
            }
        }
        return $bulunan;
    }
}

if (!function_exists('lumen_modul_hazir_mi')) {
    /** Modül bu çalışma modunda kullanılabilir mi? (LOGO modunda her zaman evet.) */
    function lumen_modul_hazir_mi(?string $modul): bool
    {
        if (!lumen_bagimsiz_mi()) {
            // LOGO modunda her şey açık; yalnız bağımsız moda özgü modüller ('logo' => false) kapalı.
            return $modul === null || (lumen_moduller()[$modul]['logo'] ?? true);
        }
        $m = $modul !== null ? (lumen_moduller()[$modul] ?? null) : null;
        // Kayıt defterinde olmayan sayfa bağımsız modda kapalıdır (güvenli varsayılan).
        return $m !== null && $m['bagimsiz'];
    }
}

if (!function_exists('lumen_yol_hazir_mi')) {
    function lumen_yol_hazir_mi(string $yol): bool
    {
        return lumen_modul_hazir_mi(lumen_yolun_modulu($yol));
    }
}

if (!function_exists('lumen_istek_yolu')) {
    /** Bu isteğin uygulama köküne göre betik yolu (ör. 'cari/cari.php'). */
    function lumen_istek_yolu(): string
    {
        // Gerçek yollar karşılaştırılır (sembolik bağlantı, sürücü harfi büyük/küçük farkı).
        $kok = str_replace('\\', '/', (string) (realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2)));
        $ham = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        $betik = str_replace('\\', '/', (string) (($ham !== '' ? realpath($ham) : false) ?: $ham));
        if ($betik !== '' && strncasecmp($betik, $kok . '/', strlen($kok) + 1) === 0) {
            return substr($betik, strlen($kok) + 1);
        }
        return ltrim((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    }
}

if (!function_exists('lumen_modul_korumasi')) {
    /**
     * Bağımsız modda hazır olmayan modülün sayfasını durdurur (501 + açıklama).
     * kontrol.php oturum doğrulamasından sonra çağırır.
     */
    function lumen_modul_korumasi(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $yol = lumen_istek_yolu();
        $modul = lumen_yolun_modulu($yol);
        if (lumen_modul_hazir_mi($modul)) {
            return;
        }
        $ad = $modul !== null ? lumen_moduller()[$modul]['ad'] : 'Bu sayfa';
        $mesaj = lumen_bagimsiz_mi()
            ? "{$ad} modülü bağımsız modda (Lumen veritabanı) henüz hazır değil; geçiş sürüyor."
            : "{$ad} yalnız bağımsız modda kullanılır; LOGO'ya bağlı kurulumda bu belgeler LOGO'da kesilir.";
        $ajax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
        if ($ajax || str_starts_with($yol, 'api/')) {
            http_response_code(501);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'success' => false, 'mesaj' => $mesaj], JSON_UNESCAPED_UNICODE);
            exit;
        }
        lumen_hata_sayfasi('Modül henüz hazır değil', $mesaj, 501, 'fa-person-digging');
    }
}
