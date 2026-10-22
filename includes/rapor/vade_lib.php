<?php

declare(strict_types=1);

/**
 * Vade takibi (yalnız bağımsız mod): carilerin açık kalemleri ve vadeleri.
 *
 * Açık kalem: bir carinin borç kalemleri (fatura, açılış borcu, cariye ödeme …) vadesine göre
 * eskiden yeniye dizilir; carinin bütün alacak kayıtları (tahsilat, iade, alınan çek …) bu
 * kalemleri sırayla kapatır (FIFO). Kalan kısım "tahsil edilecek" tutardır. Tedarikçi tarafı
 * simetriktir: alacak kalemleri borç kayıtlarıyla kapanır, kalan "ödenecek" tutardır.
 * Vade: hareketin vadesi; yoksa faturada tarih + carinin vade günü, diğer kayıtlarda (avans,
 * fazla tahsilat, açılış) kendi tarihi. Aynı cari hem alıcı hem satıcıysa açık kalemler net
 * bakiyenin olduğu taraftadır (ör. fazla tahsilat 'ödenecek' görünür).
 */

if (!function_exists('lumen_vade_acik_kalemler')) {
    /**
     * @param string $yon 'tahsil' (müşteriden alınacak) | 'odeme' (tedarikçiye ödenecek)
     * @param array{sql:string, params:array} $ozel özel cari kısıtı (görünür carilerin koşulu; boş: kısıt yok)
     * @return list<array{CARI_ID:int, KOD:string, UNVAN:string, HAREKET_ID:int, BELGE_ID:?int, BELGE_NO:?string, TARIH:string, VADE:string, TUTAR:float, ACIK:float, GUN:int}>
     */
    function lumen_vade_acik_kalemler(PDO $dbh, string $yon, ?int $cariId = null, array $ozel = ['sql' => '', 'params' => []], string $bugun = ''): array
    {
        [$kalem, $kapatan] = $yon === 'odeme' ? ['ALACAK', 'BORC'] : ['BORC', 'ALACAK'];
        $bugun = $bugun !== '' ? $bugun : date('Y-m-d');
        $kosul = '1 = 1';
        $p = [':bugun' => $bugun];
        if ($cariId !== null) {
            $kosul .= ' AND H.CARI_ID = :cari';
            $p[':cari'] = $cariId;
        }
        if (($ozel['sql'] ?? '') !== '') {
            $kosul .= ' AND ' . $ozel['sql'];
            $p += $ozel['params'];
        }
        $st = $dbh->prepare("
            WITH K AS (
                SELECT H.CARI_ID, H.ID, H.BELGE_ID, H.BELGE_NO, H.TARIH, H.{$kalem} AS TUTAR,
                       ISNULL(H.VADE, CASE WHEN H.KAYNAK = 1 THEN DATEADD(DAY, C.VADE_GUN, H.TARIH) ELSE H.TARIH END) AS VADE
                FROM dbo.LM_CARI_HAREKET H JOIN dbo.LM_CARI C ON C.ID = H.CARI_ID
                WHERE H.{$kalem} > 0 AND {$kosul}
            ), Y AS (
                SELECT K.*, SUM(K.TUTAR) OVER (PARTITION BY K.CARI_ID ORDER BY K.VADE, K.TARIH, K.ID ROWS UNBOUNDED PRECEDING) AS KUM
                FROM K
            ), O AS (
                SELECT H.CARI_ID, SUM(H.{$kapatan}) AS ODENEN FROM dbo.LM_CARI_HAREKET H
                WHERE H.CARI_ID IN (SELECT DISTINCT CARI_ID FROM K) GROUP BY H.CARI_ID
            )
            SELECT Y.CARI_ID, C.KOD, C.UNVAN, Y.ID AS HAREKET_ID, Y.BELGE_ID, Y.BELGE_NO, Y.TARIH, Y.VADE, Y.TUTAR,
                   CASE WHEN Y.KUM - ISNULL(O.ODENEN, 0) >= Y.TUTAR THEN Y.TUTAR ELSE Y.KUM - ISNULL(O.ODENEN, 0) END AS ACIK,
                   DATEDIFF(DAY, Y.VADE, CAST(:bugun AS DATE)) AS GUN
            FROM Y JOIN dbo.LM_CARI C ON C.ID = Y.CARI_ID LEFT JOIN O ON O.CARI_ID = Y.CARI_ID
            WHERE Y.KUM - ISNULL(O.ODENEN, 0) > 0.005
            ORDER BY Y.VADE, C.UNVAN, Y.ID");
        $st->execute($p);
        $sonuc = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $r['CARI_ID'] = (int) $r['CARI_ID'];
            $r['HAREKET_ID'] = (int) $r['HAREKET_ID'];
            $r['BELGE_ID'] = $r['BELGE_ID'] !== null ? (int) $r['BELGE_ID'] : null;
            $r['TUTAR'] = round((float) $r['TUTAR'], 2);
            $r['ACIK'] = round((float) $r['ACIK'], 2);
            $r['GUN'] = (int) $r['GUN'];   // > 0: gecikme günü, ≤ 0: vadeye kalan
            $r['TARIH'] = substr((string) $r['TARIH'], 0, 10);
            $r['VADE'] = substr((string) $r['VADE'], 0, 10);
            $sonuc[] = $r;
        }
        return $sonuc;
    }
}

if (!function_exists('lumen_vade_ozet')) {
    /**
     * Açık kalemlerin vade dilimleri: gecikmiş, bu hafta (0–7 gün), bu ay (8–30 gün), sonra.
     * @return array{gecikmis:float, hafta:float, ay:float, sonra:float, toplam:float, cari:int}
     */
    function lumen_vade_ozet(array $kalemler): array
    {
        $o = ['gecikmis' => 0.0, 'hafta' => 0.0, 'ay' => 0.0, 'sonra' => 0.0, 'toplam' => 0.0, 'cari' => 0];
        $cariler = [];
        foreach ($kalemler as $k) {
            $d = lumen_vade_dilim((int) $k['GUN']);
            $o[$d] += $k['ACIK'];
            $o['toplam'] += $k['ACIK'];
            $cariler[$k['CARI_ID']] = true;
        }
        $o['cari'] = count($cariler);
        return $o;
    }
}

if (!function_exists('lumen_vade_dilim')) {
    function lumen_vade_dilim(int $gun): string
    {
        return match (true) {
            $gun > 0 => 'gecikmis',
            $gun >= -7 => 'hafta',
            $gun >= -30 => 'ay',
            default => 'sonra',
        };
    }
}
