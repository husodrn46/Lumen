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

-- LOGO uyumlu kişi görünümü (geçiş dönemi): kullanıcı adı için LG_SLSMAN'a JOIN
-- yapan okuma sorguları bağımsız modda bu görünüme yönlenir ($GLOBALS['LUMEN_SLSMAN']).
-- ACTIVE: LOGO anlamıyla 0 = etkin. FIRMNR: tek firma; LM_AYAR.firma_no (yoksa 1),
-- .env FIRMA_NO ile aynı olmalıdır (sorgular FIRMNR = FIRMA_NO ile süzer).
CREATE OR ALTER VIEW dbo.LMV_SLSMAN AS
SELECT ID AS LOGICALREF, KOD AS CODE, AD AS DEFINITION_,
       CASE WHEN AKTIF = 1 THEN 0 ELSE 1 END AS ACTIVE,
       CAST(ISNULL((SELECT TRY_CAST(DEGER AS INT) FROM dbo.LM_AYAR WHERE ANAHTAR = 'firma_no'), 1) AS INT) AS FIRMNR,
       CAST(DEPO_ID AS NVARCHAR(20)) AS SPECODE, YETKI_KODU AS CYPHCODE, OLUSTURMA AS CAPIBLOCK_CREADEDDATE
FROM dbo.LM_PERSONEL;
GO

-- LOGO uyumlu cari kartı görünümü (geçiş adaptörü). Bağımsız modda tablo öneki
-- $firma = 'dbo.LMV_' olur; ana veriyi LOGO kolon adlarıyla okuyan sorgular
-- ("{$firma}CLCARD") kod değişmeden LM_CARI'yi okur. Yalnız okuma içindir; yazma
-- depolar üzerinden yapılır (cari/cari_olustur_lib.php). ACTIVE: 0 = etkin.
CREATE OR ALTER VIEW dbo.LMV_CLCARD AS
SELECT ID AS LOGICALREF, KOD AS CODE, UNVAN AS DEFINITION_, CAST(NULL AS NVARCHAR(200)) AS DEFINITION2,
       CAST(TUR AS SMALLINT) AS CARDTYPE, CASE WHEN AKTIF = 1 THEN 0 ELSE 1 END AS ACTIVE,
       IL AS CITY, ILCE AS TOWN, CAST(NULL AS NVARCHAR(60)) AS DISTRICT, ADRES AS ADDR1, CAST(NULL AS NVARCHAR(200)) AS ADDR2,
       CAST(NULL AS NVARCHAR(60)) AS COUNTRY, TELEFON AS TELNRS1, CAST(NULL AS NVARCHAR(40)) AS TELNRS2, EPOSTA AS EMAILADDR,
       VERGI_NO AS TAXNR, VERGI_DAIRESI AS TAXOFFICE, TC_NO AS TCKNO, OZEL_KOD AS SPECODE, CAST(NULL AS NVARCHAR(40)) AS CYPHCODE,
       PARA_BIRIMI AS CCURRENCY_KOD
FROM dbo.LM_CARI;
GO
