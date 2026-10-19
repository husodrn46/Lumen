-- ============================================================================
-- Lumen çekirdek şeması — 05: bakiye görünümleri
-- LOGO'nun LV_..._GNTOTCL (cari bakiye) ve LV_..._STINVTOT (stok miktarı)
-- view'larının karşılığı; ayrı toplam tablosu tutulmaz.
-- ============================================================================

CREATE OR ALTER VIEW dbo.LMV_CARI_BAKIYE AS
SELECT C.ID AS CARI_ID,
       ISNULL(SUM(H.BORC), 0) AS BORC,
       ISNULL(SUM(H.ALACAK), 0) AS ALACAK,
       ISNULL(SUM(H.BORC), 0) - ISNULL(SUM(H.ALACAK), 0) AS BAKIYE      -- + borçlu, - alacaklı
FROM dbo.LM_CARI C
LEFT JOIN dbo.LM_CARI_HAREKET H ON H.CARI_ID = C.ID
GROUP BY C.ID;
GO

CREATE OR ALTER VIEW dbo.LMV_STOK_MIKTAR AS
SELECT H.STOK_ID, H.DEPO_ID,
       SUM(H.GIRIS) - SUM(H.CIKIS) AS MIKTAR                              -- ana birimde
FROM dbo.LM_STOK_HAREKET H
GROUP BY H.STOK_ID, H.DEPO_ID;
GO

CREATE OR ALTER VIEW dbo.LMV_FINANS_BAKIYE AS
SELECT F.ID AS HESAP_ID,
       ISNULL(SUM(H.GIRIS), 0) - ISNULL(SUM(H.CIKIS), 0) AS BAKIYE
FROM dbo.LM_FINANS_HESAP F
LEFT JOIN dbo.LM_FINANS_HAREKET H ON H.HESAP_ID = F.ID
GROUP BY F.ID;
GO
