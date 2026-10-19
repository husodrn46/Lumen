<?php

declare(strict_types=1);

/**
 * Uygulama içi bildirim kutusu (M_BILDIRIM; sql/m_bildirim.sql).
 *
 * Bildirim yazmak hiçbir işlemi bozmamalı: tüm fonksiyonlar hata halinde sessizce
 * false/0/[] döner ve loglar (tablo henüz kurulmamış olabilir).
 */

if (!function_exists('lumen_bildirim_ekle')) {
    /**
     * @param string|null $anahtar Verilirse aynı kişiye aynı anahtarla ikinci bildirim yazılmaz
     *                             (zamanlanmış hatırlatıcılar her gün çalışsa da bir kez bildirir).
     */
    function lumen_bildirim_ekle(PDO $dbh, int $personelId, string $tur, string $baslik, string $metin = '',
                                 string $baglanti = '', ?string $anahtar = null): bool
    {
        if ($personelId <= 0 || trim($baslik) === '') {
            return false;
        }
        try {
            $st = $dbh->prepare("
                INSERT INTO M_BILDIRIM (PERSONEL_ID, TUR, BASLIK, METIN, BAGLANTI, ANAHTAR)
                SELECT :p, :t, :b, :m, :l, :a
                WHERE :a2 IS NULL OR NOT EXISTS (SELECT 1 FROM M_BILDIRIM WHERE PERSONEL_ID = :p2 AND ANAHTAR = :a3)");
            $st->execute([
                ':p' => $personelId, ':t' => mb_substr($tur, 0, 20), ':b' => mb_substr($baslik, 0, 200),
                ':m' => $metin !== '' ? mb_substr($metin, 0, 1000) : null,
                ':l' => $baglanti !== '' ? mb_substr($baglanti, 0, 300) : null,
                ':a' => $anahtar, ':a2' => $anahtar, ':p2' => $personelId, ':a3' => $anahtar,
            ]);
            return $st->rowCount() === 1;
        } catch (Throwable $e) {
            error_log('lumen_bildirim_ekle: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('lumen_bildirim_yoneticilere')) {
    /** Tüm aktif yöneticilere (YETKI=0) bildirim; $haric genelde işlemi yapan kişidir. */
    function lumen_bildirim_yoneticilere(PDO $dbh, string $tur, string $baslik, string $metin = '',
                                         string $baglanti = '', ?string $anahtar = null, int $haric = 0): int
    {
        try {
            $ids = $dbh->query("SELECT Y.PERSONEL FROM M_P_YETKI Y INNER JOIN LG_SLSMAN S ON S.LOGICALREF = Y.PERSONEL
                                WHERE Y.YETKI = 0 AND S.ACTIVE = 0")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return 0;
        }
        $n = 0;
        foreach ($ids as $id) {
            if ((int) $id !== $haric && lumen_bildirim_ekle($dbh, (int) $id, $tur, $baslik, $metin, $baglanti, $anahtar)) {
                $n++;
            }
        }
        return $n;
    }
}

if (!function_exists('lumen_bildirim_okunmamis')) {
    function lumen_bildirim_okunmamis(PDO $dbh, int $personelId): int
    {
        try {
            $st = $dbh->prepare("SELECT COUNT(*) FROM M_BILDIRIM WHERE PERSONEL_ID = :p AND OKUNDU = 0");
            $st->execute([':p' => $personelId]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('lumen_bildirim_listesi')) {
    /** @return array<int,array<string,mixed>> */
    function lumen_bildirim_listesi(PDO $dbh, int $personelId, int $limit = 100): array
    {
        try {
            $limit = max(1, min(500, $limit));
            $st = $dbh->prepare("SELECT TOP {$limit} ID, TUR, BASLIK, METIN, BAGLANTI, OKUNDU, TARIH
                                 FROM M_BILDIRIM WHERE PERSONEL_ID = :p ORDER BY OKUNDU ASC, TARIH DESC, ID DESC");
            $st->execute([':p' => $personelId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('lumen_bildirim_okundu')) {
    /** $id = null → kişinin tüm bildirimleri. Yalnız kendi bildirimleri işaretlenir. */
    function lumen_bildirim_okundu(PDO $dbh, int $personelId, ?int $id = null): int
    {
        try {
            $sql = "UPDATE M_BILDIRIM SET OKUNDU = 1, OKUNMA_TARIHI = GETDATE() WHERE PERSONEL_ID = :p AND OKUNDU = 0";
            $p = [':p' => $personelId];
            if ($id !== null) {
                $sql .= " AND ID = :id";
                $p[':id'] = $id;
            }
            $st = $dbh->prepare($sql);
            $st->execute($p);
            return $st->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('lumen_bildirim_guvenli_baglanti')) {
    /** Bildirim bağlantısı yalnız uygulama içi göreli yol olabilir. */
    function lumen_bildirim_guvenli_baglanti(?string $b): string
    {
        $b = trim((string) $b);
        return preg_match('#^[A-Za-z0-9_\-/]+\.php(\?[A-Za-z0-9_\-=&%.]*)?$#', $b) === 1 && !str_contains($b, '..') ? $b : '';
    }
}
