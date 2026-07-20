<?php

declare(strict_types=1);

/**
 * kurulum_durumu.php — Başlangıç (kurulum tamamlanma) kontrol listesi.
 * Dashboard'da yöneticiye eksik/atlanan kurulum adımlarını gösterir.
 * Durum tamamen TÜRETİLİR (ayrı bir state tablosu tutulmaz):
 *   - Firma adı hâlâ varsayılan mı?
 *   - Logo hâlâ Lumen placeholder mı? (md5 karşılaştırması)
 *   - Fiş yazıcısı tanımlı mı?
 *   - Hiç sipariş oluşturulmuş mu?
 */

if (!function_exists('kurulum_eksik_adimlar')) {
    /**
     * @return array<int,array{anahtar:string,baslik:string,aciklama:string,ikon:string,link:string,tamam:bool,ops:bool}>
     */
    function kurulum_eksik_adimlar(PDO $dbh, string $firmadonem): array
    {
        global $firmabaslik, $fisyazici;
        $kok = dirname(__DIR__);

        // 1) Firma adı
        $fb = trim((string) ($firmabaslik ?? ''));
        $firmaTamam = ($fb !== '' && strcasecmp($fb, 'Lumen') !== 0 && strcasecmp($fb, 'Firma Adınız') !== 0);

        // 2) Logo — dağıtılan placeholder md5'inden farklıysa özelleştirilmiş sayılır
        $placeholderLogoMd5 = '649a234a5f173594479412b84809dae0';
        $logoYolu = $kok . '/logo.png';
        $logoTamam = is_file($logoYolu) && strtolower((string) @md5_file($logoYolu)) !== $placeholderLogoMd5;

        // 3) Fiş yazıcısı (opsiyonel)
        $fy = trim((string) ($fisyazici ?? ''));
        $yaziciTamam = ($fy !== '' && strcasecmp($fy, 'fisyazici') !== 0);

        // 4) İlk sipariş
        $siparisTamam = false;
        try {
            $siparisTamam = (int) $dbh->query("SELECT COUNT(*) FROM {$firmadonem}ORFICHE WITH(NOLOCK) WHERE TRCODE IN (1,2) AND CANCELLED = 0")->fetchColumn() > 0;
        } catch (Throwable $e) { /* tablo yoksa yoksay */ }

        return [
            ['anahtar' => 'firma', 'baslik' => 'Firma adınızı girin', 'aciklama' => 'Raporlarda ve sayfa başlıklarında görünür.', 'ikon' => 'fa-building', 'link' => 'ayar/sistem_ayarlari.php', 'tamam' => $firmaTamam, 'ops' => false],
            ['anahtar' => 'logo', 'baslik' => 'Kendi logonuzu yükleyin', 'aciklama' => 'Giriş ekranı ve üst menüde görünür.', 'ikon' => 'fa-image', 'link' => 'ayar/marka.php', 'tamam' => $logoTamam, 'ops' => false],
            ['anahtar' => 'yazici', 'baslik' => 'Fiş yazıcısını tanımlayın', 'aciklama' => 'Fiş/etiket çıktısı için (opsiyonel).', 'ikon' => 'fa-print', 'link' => 'ayar/sistem_ayarlari.php', 'tamam' => $yaziciTamam, 'ops' => true],
            ['anahtar' => 'siparis', 'baslik' => 'İlk siparişinizi oluşturun', 'aciklama' => 'Bir cari seçip ürün ekleyerek deneyin.', 'ikon' => 'fa-cart-plus', 'link' => 'cari.php', 'tamam' => $siparisTamam, 'ops' => false],
        ];
    }
}
