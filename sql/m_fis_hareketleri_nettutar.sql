-- ============================================================================
-- M_FIS_HAREKETLERI view'ina NETTUTAR kolonu eklendi (2026-07-13)
-- ----------------------------------------------------------------------------
-- NETTUTAR = O.LINENET = satirin iskonto dusulmus, KDV haric net toplami
--            (net fiyat x miktar). Ornek: 5 adet x 8 TL net = 40 TL.
--
-- Zaten mevcuttu: FIYATI (liste), NETFIYATI (net birim), TUTAR (brut=PRICE*AMOUNT).
-- Eksik olan tek deger NETTUTAR idi.
--
-- ALTER VIEW ile uygulandi (DROP degil - atomik, kesinti yok). Kolon en SONA
-- eklendi; mevcut hicbir kolonun ordinali kaymaz (pozisyona gore okuyan
-- yazdirma uygulamasi bozulmasin diye).
--
-- Geri donus: bu dosyadan NETTUTAR satirini cikarip tekrar ALTER VIEW calistir
-- (veya onceden aldiginiz yedekten geri yukleyin).
-- ============================================================================

ALTER VIEW [dbo].[M_FIS_HAREKETLERI] AS
    SELECT
        I.LOGICALREF STOK_ID,
        O.ORDFICHEREF AS FIS_NO,
        O.LINENO_ AS SIRA_NO,
        O.LINEEXP AS SATIR_ACIKLAMA,
        I.CODE AS STOK_KODU,
        I.NAME + ' ' + ISNULL(O.LINEEXP, '') AS STOK_ADI,
        I.STGRPCODE,
        I.SPECODE,
        O.VAT,
        O.AMOUNT AS MIKTAR,
        L.CODE AS BIRIM,
        CASE S.CODE WHEN 'KOLI' THEN (O.AMOUNT * O.UINFO2 / O.UINFO1 / ISNULL(NULLIF(U.CONVFACT2,0),1) / ISNULL(NULLIF(U.CONVFACT1,0),1))
        ELSE (O.AMOUNT * O.UINFO2 / O.UINFO1 / ISNULL(NULLIF(U2.CONVFACT2,0),1) / ISNULL(NULLIF(U2.CONVFACT1,0),1)) END AS UNIT2AMOUNT,
        CASE S.CODE WHEN 'KOLI' THEN (ISNULL(NULLIF(U.CONVFACT2,0),1)/ISNULL(NULLIF(U.CONVFACT1,0),1))
        ELSE (ISNULL(NULLIF(U2.CONVFACT2,0),1)/ISNULL(NULLIF(U2.CONVFACT1,0),1)) END AS UNIT2FACTOR,
        S.CODE AS BIRIM2,
        ISNULL(NULLIF(U.CONVFACT2,0),1) / ISNULL(NULLIF(U.CONVFACT1,0),1) AS KOLIICI,
        O.AMOUNT / NULLIF(ISNULL(NULLIF(U.CONVFACT2,0),1) / ISNULL(NULLIF(U.CONVFACT1,0),1), 0) AS KOLI,
        O.PRICE AS FIYATI,
        O.TOTAL AS TUTAR,
        CASE O.AMOUNT WHEN 0 THEN 0 ELSE O.LINENET / O.AMOUNT END AS NETFIYATI,
        (SELECT TOP 1 BARCODE FROM LG_001_UNITBARCODE WHERE ITEMREF = I.LOGICALREF AND LINENR = 1) BARKODU,

        -- ===== DÖVİZ SÜTUNLARI =====
        F.TRCURR,
        F.TRRATE,

        -- Satır seviyesi döviz fiyatları
        CASE
            WHEN F.TRRATE > 0 THEN O.PRICE / F.TRRATE
            ELSE O.PRICE
        END AS BRUT_DOVIZ_FIYATI,

        CASE
            WHEN F.TRRATE > 0 THEN (CASE O.AMOUNT WHEN 0 THEN 0 ELSE O.LINENET / O.AMOUNT END) / F.TRRATE
            ELSE (CASE O.AMOUNT WHEN 0 THEN 0 ELSE O.LINENET / O.AMOUNT END)
        END AS NET_DOVIZ_FIYATI,

        CASE
            WHEN F.TRRATE > 0 THEN (O.TOTAL + O.VATAMNT) / F.TRRATE
            ELSE (O.TOTAL + O.VATAMNT)
        END AS DOVIZLI_TUTAR,

        -- LOGO: TRCURR = 0:TL, 1:USD, 20:EUR (TRCODE değil!)
        CASE F.TRCURR
            WHEN 0 THEN N'TL'
            WHEN 1 THEN N'$'
            WHEN 20 THEN N'€'
            ELSE N'TL'
        END AS DOVIZ_SEMBOL,

        -- Fiş seviyesi döviz toplamları
        CASE
            WHEN F.TRRATE > 0 THEN F.GROSSTOTAL / F.TRRATE
            ELSE F.GROSSTOTAL
        END AS TOPLAM_DVZ,

        CASE
            WHEN F.TRRATE > 0 THEN F.TOTALDISCOUNTS / F.TRRATE
            ELSE F.TOTALDISCOUNTS
        END AS ORDSCT1_DVZ,

        CASE
            WHEN F.TRRATE > 0 THEN F.TOTALVAT / F.TRRATE
            ELSE F.TOTALVAT
        END AS TOTALVAT_DVZ,

        CASE
            WHEN F.TRRATE > 0 THEN F.NETTOTAL / F.TRRATE
            ELSE F.NETTOTAL
        END AS NETTOPLAM_DVZ,

        -- Müşteri bakiyesi (TL)
        ROUND(ISNULL(G.DEBIT, 0) - ISNULL(G.CREDIT, 0), 2) AS BAKIYE,

        -- Müşteri bakiyesi (Döviz cinsinden)
        ROUND(CASE
            WHEN F.TRRATE > 0 THEN (ISNULL(G.DEBIT, 0) - ISNULL(G.CREDIT, 0)) / F.TRRATE
            ELSE ISNULL(G.DEBIT, 0) - ISNULL(G.CREDIT, 0)
        END, 2) AS BAKIYE_DVZ,

        -- GENELTOPLAM_DVZ = NETTOPLAM_DVZ + BAKIYE_DVZ
        ROUND(CASE
            WHEN F.TRRATE > 0 THEN (F.NETTOTAL / F.TRRATE) + ((ISNULL(G.DEBIT, 0) - ISNULL(G.CREDIT, 0)) / F.TRRATE)
            ELSE F.NETTOTAL + (ISNULL(G.DEBIT, 0) - ISNULL(G.CREDIT, 0))
        END, 2) AS GENELTOPLAM_DVZ,

        -- Net satir tutari (iskonto dusulmus, KDV haric) = net fiyat x miktar
        O.LINENET AS NETTUTAR

    FROM dbo.LG_001_02_ORFLINE AS O WITH (NOLOCK)
    INNER JOIN dbo.LG_001_ITEMS AS I WITH (NOLOCK) ON O.STOCKREF = I.LOGICALREF AND O.LINETYPE = 0
    INNER JOIN dbo.LG_001_02_ORFICHE AS F WITH (NOLOCK) ON O.ORDFICHEREF = F.LOGICALREF
    LEFT OUTER JOIN dbo.LG_001_UNITSETL AS L WITH (NOLOCK) ON L.LOGICALREF = O.UOMREF
    LEFT OUTER JOIN dbo.LG_001_ITMUNITA AS U WITH (NOLOCK) ON I.LOGICALREF = U.ITEMREF AND U.LINENR = 2
    LEFT OUTER JOIN dbo.LG_001_ITMUNITA AS U2 WITH (NOLOCK) ON I.LOGICALREF = U2.ITEMREF AND U2.LINENR = 3
    LEFT OUTER JOIN dbo.LG_001_UNITSETL AS S WITH (NOLOCK) ON U.UNITLINEREF = S.LOGICALREF
    LEFT OUTER JOIN dbo.LV_001_02_GNTOTCL AS G WITH (NOLOCK) ON G.CARDREF = F.CLIENTREF AND G.TOTTYP = 1
