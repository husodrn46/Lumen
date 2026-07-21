<?php

declare(strict_types=1);

/**
 * giris_koruma.php — Giriş denemesi sertleştirme yardımcıları.
 *
 * Web girişi (giris.php) bu kontrolleri kendi akışı içinde satır içi yapar.
 * Bu dosya aynı kuralları API girişi (api/login.php) gibi diğer kimlik
 * doğrulama uçlarının da uygulayabilmesi için tek yerde toplar.
 *
 * Kurallar (giris.php ile birebir):
 *   - IP başına son 15 dk içinde 10 başarısız deneme (saldırı modunda 3)
 *   - Hesap başına son başarılı girişten sonraki 15 dk içinde 5 başarısız
 *     deneme (saldırı modunda 60 dk / 2 deneme) → geçici kilit
 *
 * M_GIRIS_LOG tablosu yoksa kontroller sessizce geçilir (girişi kilitlemez).
 */

if (!function_exists('giris_ip_engelli_mi')) {
    /**
     * IP bazlı brute-force eşiği aşıldı mı?
     */
    function giris_ip_engelli_mi(PDO $dbh, string $ip): bool
    {
        $esik = (function_exists('saldiri_modu_aktif_mi') && saldiri_modu_aktif_mi()) ? 3 : 10;

        try {
            $stmt = $dbh->prepare("
                SELECT COUNT(*) FROM M_GIRIS_LOG
                WHERE IP_ADRESI = :ip AND BASARILI = 0
                  AND TARIH > DATEADD(MINUTE, -15, GETDATE())
            ");
            $stmt->execute([':ip' => $ip]);
            return (int) $stmt->fetchColumn() >= $esik;
        } catch (Throwable $e) {
            error_log('giris_ip_engelli_mi: ' . $e->getMessage());
            return false; // log altyapisi yoksa girisi engelleme
        }
    }
}

if (!function_exists('giris_hesap_kilitli_mi')) {
    /**
     * Hesap bazlı geçici kilit durumu.
     *
     * @return array{kilitli:bool, kalan_dk:int}
     */
    function giris_hesap_kilitli_mi(PDO $dbh, string $kullaniciAdi): array
    {
        $saldiri = function_exists('saldiri_modu_aktif_mi') && saldiri_modu_aktif_mi();
        $esik    = $saldiri ? 2 : 5;
        $sureDk  = $saldiri ? 60 : 15;

        // DATEADD dakika argumani SQL'e literal int olarak gomulur; PDO sqlsrv
        // bu argumani parametre olarak nvarchar gonderip hata verir. Degerler
        // yukarida sabit int oldugundan enjeksiyon riski yoktur.
        $arti  = (int) $sureDk;
        $eksi  = -(int) $sureDk;

        try {
            $stmt = $dbh->prepare("
                SELECT COUNT(*) AS sayi,
                       DATEDIFF(SECOND, GETDATE(), DATEADD(MINUTE, {$arti}, MAX(TARIH))) AS kalan_saniye
                FROM M_GIRIS_LOG
                WHERE KULLANICI_ADI = :kullanici
                  AND ISLEM_TIPI = 'GIRIS'
                  AND BASARILI = 0
                  AND TARIH > DATEADD(MINUTE, {$eksi}, GETDATE())
                  AND TARIH > ISNULL((
                        SELECT MAX(TARIH) FROM M_GIRIS_LOG
                        WHERE KULLANICI_ADI = :kullanici_basarili
                          AND ISLEM_TIPI = 'GIRIS'
                          AND BASARILI = 1
                      ), CONVERT(datetime, '1900-01-01'))
            ");
            $stmt->bindValue(':kullanici', $kullaniciAdi);
            $stmt->bindValue(':kullanici_basarili', $kullaniciAdi);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && (int) $row['sayi'] >= $esik) {
                return [
                    'kilitli'  => true,
                    'kalan_dk' => max(1, (int) ceil(((int) $row['kalan_saniye']) / 60)),
                ];
            }
        } catch (Throwable $e) {
            error_log('giris_hesap_kilitli_mi: ' . $e->getMessage());
        }

        return ['kilitli' => false, 'kalan_dk' => 0];
    }
}
