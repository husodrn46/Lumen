<?php

declare(strict_types=1);

/**
 * Cari ekstresi — ekran (lg_hareket.php), PDF ve Excel çıktılarının ortak verisi.
 * Eskiden üç dosyada üç ayrı sorgu kopyası vardı; artık tek yerde, iki modda:
 *   LOGO modu   : dönem tabloları (CLFLINE + CSTRANS/CSROLL/STFICHE/INVOICE) UNION ALL
 *   Bağımsız mod: LM_CARI_HAREKET (+ belge/finans fişi/çek hareketi)
 * Satırlarda işlem türü LOGO'nun TRCODE numaralarıyla döner (TRCODE_NO); bağımsız
 * moddaki hareketler bu kodlara eşlenir, böylece ekranların simge/etiket mantığı aynı kalır.
 * ayr.php önceden yüklenmiş olmalıdır.
 */

if (!function_exists('cari_islem_etiketi')) {
    /** LOGO cari işlem kodunun (CLFLINE.TRCODE) adı; $ascii: PDF yazı tipi için Türkçe harfsiz. */
    function cari_islem_etiketi(?int $kod, bool $ascii = false): string
    {
        $ad = match ($kod) {
            1 => 'Nakit Tahsilat', 2 => 'Nakit Ödeme', 3 => 'Borç Dekontu', 4 => 'Alacak Dekontu', 5 => 'Virman Fişi',
            6 => 'Kur Farkı Fişi', 12 => 'Özel Fiş', 14 => 'Açılış Fişi', 20 => 'Gelen Havale', 21 => 'Gönderilen Havale',
            24 => 'Döviz Alış Belgesi', 25 => 'Döviz Satış belgesi', 28 => 'Alınan Hizmet Faturası', 29 => 'Verilen Hizmet Faturası',
            31 => 'Satın Alma Faturası', 32 => 'Perakende Satış İade Faturası', 33 => 'Toptan Satış İade Faturası',
            34 => 'Alınan Hizmet Faturası', 35 => 'Alınan Proforma Fatura', 36 => 'Satın Alma İade Faturası',
            37 => 'Perakende Satış Faturası', 38 => 'Toptan Satış Faturası', 39 => 'Verilen Hizmet Faturası',
            40 => 'Verilen proforma fatura', 41 => 'Verilen Vade Farkı Faturası', 42 => 'Alınan Vade Farkı Faturası',
            43 => 'Satın Alma Fiyat Farkı Faturası', 44 => 'Satış Fiyat Farkı Faturası', 45 => 'Verilen Serbest Meslek Makbuzu',
            46 => 'Alınan Serbest Meslek Makbuzu', 56 => 'Müstahsil Makbuzu',
            61 => 'Çek Girişi', 62 => 'Senet Girişi', 63 => 'Çek Çıkışı (Cari Hesaba)', 64 => 'Senet Çıkışı (Cari Hesaba)',
            70 => 'Kredi Kartı Fişi', 71 => 'Kredi Kartı İade Fişi', 72 => 'Firma Kredi Kartı Fişi', 73 => 'Firma Kredi Kartı İade Fişi',
            81 => 'Satınalma Siparişi', 82 => 'Satış Siparişi',
            default => '',
        };
        return $ascii ? strtr($ad, ['ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I', 'ö' => 'o', 'Ö' => 'O',
            'ş' => 's', 'Ş' => 'S', 'ü' => 'u', 'Ü' => 'U']) : $ad;
    }
}

if (!function_exists('cari_ekstre_cari')) {
    /**
     * Ekstre başlığı için cari bilgisi (ekranların beklediği LOGO adlı anahtarlarla).
     * @return array{CODE:string, DEFINITION_:string, TELNRS1:?string, TELNRS2:?string, ADDR1:?string, ADDR2:?string,
     *               TOWN:?string, DISTRICT:?string, CITY:?string, TAXOFFICE:?string, TAXNR:?string}|null
     */
    function cari_ekstre_cari(PDO $dbh, int $cariId): ?array
    {
        $st = $dbh->prepare("SELECT C.KOD AS CODE, C.UNVAN AS DEFINITION_, C.TELEFON AS TELNRS1, C.TELEFON2 AS TELNRS2,
                    C.ADRES AS ADDR1, C.ADRES2 AS ADDR2, C.ILCE AS TOWN, C.SEMT AS DISTRICT, C.IL AS CITY,
                    C.VERGI_DAIRESI AS TAXOFFICE, C.VERGI_NO AS TAXNR
             FROM " . lumen_tablo('cari') . " C WHERE C.ID = :cariid");
        $st->execute([':cariid' => $cariId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}

if (!function_exists('cari_ekstre_resmi_bakiye')) {
    /** Güncel bakiye (+ borçlu): LOGO'da GNTOTCL view'ı, bağımsızda LMV_CARI_BAKIYE. */
    function cari_ekstre_resmi_bakiye(PDO $dbh, int $cariId): float
    {
        $st = $dbh->prepare("SELECT ISNULL(G.BAKIYE, 0) FROM " . lumen_tablo('cari_bakiye') . " G WHERE G.CARI_ID = :cariid");
        $st->execute([':cariid' => $cariId]);
        return (float) $st->fetchColumn();
    }
}

if (!function_exists('cari_ekstre_hareketleri')) {
    /**
     * Cari hareketleri, tarih sırasıyla. Her satır: LOGICALREF, DATE_, DOCODE, SOURCEFREF,
     * TRCODE_NO, TRCODE (ad), KART_REF, CODE, DEFINITION_, ACIKLAMA, BORC, ALACAK, CSREF,
     * INVOICE_REF, DONEM_PREFIX, VADE (yalnız çek/senet girişinde).
     *
     * $donemSecim (LOGO): 'aktif' | 'onceki' | 'tumu'. 'tumu'de açılış fişleri (14) hariç
     * tutulur — GNTOTCL bakiyesi onları zaten içerir; ekran ile bakiye listesi aynı sonucu verir.
     * Bağımsız modda tek veritabanı vardır; dönem seçimi tüm hareketleri döndürür.
     *
     * @return array<int, array<string, mixed>>
     */
    function cari_ekstre_hareketleri(PDO $dbh, int $cariId, string $donemSecim = 'tumu'): array
    {
        if (lumen_bagimsiz_mi()) {
            // KAYNAK 1 belge, 2 finans fişi, 3 çek hareketi, 4 açılış → LOGO TRCODE karşılığı
            $kod = "CASE
                WHEN H.KAYNAK = 1 AND B.TUR = 3 THEN CASE WHEN B.YON = 1 THEN 38 ELSE 31 END
                WHEN H.KAYNAK = 1 AND B.TUR = 4 THEN CASE WHEN B.YON = 1 THEN 33 ELSE 36 END
                WHEN H.KAYNAK = 2 THEN CASE F.TUR WHEN 1 THEN 1 WHEN 2 THEN 2 WHEN 3 THEN 5 WHEN 5 THEN 14 ELSE 2 END
                WHEN H.KAYNAK = 3 AND H.ALACAK > 0 THEN CASE WHEN K.TUR IN (3, 4) THEN 62 ELSE 61 END
                WHEN H.KAYNAK = 3 THEN CASE WHEN K.TUR IN (3, 4) THEN 64 ELSE 63 END
                ELSE 14 END";
            $st = $dbh->prepare("
                SELECT H.ID AS LOGICALREF, H.TARIH AS DATE_, H.BELGE_NO AS DOCODE,
                       COALESCE(H.BELGE_ID, H.FINANS_FIS_ID, H.CEK_HAREKET_ID) AS SOURCEFREF,
                       {$kod} AS TRCODE_NO,
                       C.ID AS KART_REF, C.KOD AS CODE, C.UNVAN AS DEFINITION_, H.ACIKLAMA,
                       H.BORC, H.ALACAK, CH.CEK_ID AS CSREF,
                       CASE WHEN B.TUR IN (3, 4) THEN B.ID END AS INVOICE_REF, '' AS DONEM_PREFIX,
                       CASE WHEN H.KAYNAK = 3 AND H.ALACAK > 0 THEN K.VADE END AS VADE
                FROM dbo.LM_CARI_HAREKET H
                JOIN dbo.LM_CARI C ON C.ID = H.CARI_ID
                LEFT JOIN dbo.LM_BELGE B ON B.ID = H.BELGE_ID
                LEFT JOIN dbo.LM_FINANS_FIS F ON F.ID = H.FINANS_FIS_ID
                LEFT JOIN dbo.LM_CEK_HAREKET CH ON CH.ID = H.CEK_HAREKET_ID
                LEFT JOIN dbo.LM_CEK K ON K.ID = CH.CEK_ID
                WHERE H.CARI_ID = :cariid
                ORDER BY H.TARIH ASC, H.ID ASC");
            $st->execute([':cariid' => $cariId]);
        } else {
            $firma = lumen_logo_onek((string) ($GLOBALS['firma'] ?? ''));
            $aktif = lumen_logo_onek((string) ($GLOBALS['firmadonem'] ?? ''));
            $onceki = lumen_logo_onek((string) ($GLOBALS['eskifirmadonem'] ?? ''));
            $onekler = match ($donemSecim) {
                'onceki' => [$onceki],
                'tumu' => [$aktif, $onceki],
                default => [$aktif],
            };
            $acilis = $donemSecim === 'tumu' ? ' AND HAREKET.TRCODE <> 14' : '';
            $parcalar = [];
            foreach ($onekler as $p) {
                // Çek/senet girişinde SOURCEFREF bir CSROLL (bordro) referansıdır; aynı sayı
                // CSTRANS'ta da bulunabilir (kimlik uzayı çakışması). Önce bordro yolu (J),
                // o boşsa doğrudan CSTRANS eşleşmesi (CS).
                $parcalar[] = "
                SELECT HAREKET.LOGICALREF, HAREKET.DATE_, HAREKET.TRANNO AS DOCODE, HAREKET.SOURCEFREF,
                       HAREKET.TRCODE AS TRCODE_NO, KART.LOGICALREF AS KART_REF, KART.CODE, KART.DEFINITION_,
                       HAREKET.LINEEXP AS ACIKLAMA,
                       ISNULL((1 - HAREKET.SIGN) * HAREKET.AMOUNT, 0) AS BORC,
                       ISNULL(HAREKET.SIGN * HAREKET.AMOUNT, 0) AS ALACAK,
                       COALESCE(J.CSREF_FROM_ROLL, CS.CSREF) AS CSREF,
                       COALESCE(INV1.LOGICALREF, STF.INVOICEREF) AS INVOICE_REF,
                       '{$p}' AS DONEM_PREFIX,
                       VADE.DUEDATE AS VADE
                FROM {$p}CLFLINE AS HAREKET
                JOIN {$firma}CLCARD AS KART ON HAREKET.CLIENTREF = KART.LOGICALREF
                LEFT JOIN {$p}CSTRANS AS CS ON CS.LOGICALREF = HAREKET.SOURCEFREF
                OUTER APPLY (
                  SELECT TOP (1) t.CSREF AS CSREF_FROM_ROLL
                  FROM {$p}CSROLL r JOIN {$p}CSTRANS t ON t.ROLLREF = r.LOGICALREF
                  WHERE r.LOGICALREF = HAREKET.SOURCEFREF ORDER BY t.LOGICALREF DESC
                ) AS J
                OUTER APPLY (
                  SELECT TOP 1 CSV.DUEDATE FROM {$p}CSTRANS CT WITH(NOLOCK)
                  JOIN {$p}CSCARD CSV WITH(NOLOCK) ON CSV.LOGICALREF = CT.CSREF
                  WHERE HAREKET.TRCODE IN (61, 62) AND CT.LOGICALREF = HAREKET.SOURCEFREF
                ) AS VADE
                LEFT JOIN {$p}STFICHE AS STF ON STF.LOGICALREF = HAREKET.SOURCEFREF
                LEFT JOIN {$p}INVOICE AS INV1 ON INV1.LOGICALREF = HAREKET.SOURCEFREF
                WHERE HAREKET.CANCELLED = 0 AND KART.LOGICALREF = " . (int) $cariId . $acilis;
            }
            $st = $dbh->prepare("SELECT * FROM (" . implode(' UNION ALL ', $parcalar) . ") AS BIRLESIK ORDER BY DATE_ ASC, LOGICALREF ASC");
            $st->execute();
        }
        $satirlar = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($satirlar as &$r) {
            $r['TRCODE'] = cari_islem_etiketi($r['TRCODE_NO'] !== null ? (int) $r['TRCODE_NO'] : null);
        }
        unset($r);
        return $satirlar;
    }
}

if (!function_exists('cari_ekstre_donem_etiketi')) {
    /** Ekstre başlığındaki dönem adı. Bağımsız modda dönem ayrımı yok (tüm geçmiş tek tabloda). */
    function cari_ekstre_donem_etiketi(string $donemSecim): string
    {
        if (lumen_bagimsiz_mi()) {
            return 'Tum Hareketler';
        }
        return match ($donemSecim) {
            'onceki' => 'Onceki Donem',
            'tumu' => 'Tum Donemler',
            default => 'Aktif Donem',
        };
    }
}
