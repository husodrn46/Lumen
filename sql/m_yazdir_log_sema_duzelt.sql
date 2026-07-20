-- ============================================================================
-- M_YAZDIR_LOG şema düzeltmesi (2026-07-07)
-- ============================================================================
-- SORUN: DB'deki tablo ESKİ şemadaydı (ID, STOKHAREKET, FICHENO, TIP, KULLANICI,
-- ACIKLAMA, TARIH) ama kod (ayr.php logYazdir) YENİ şemayı bekliyordu
-- (FIS_REF, FICHENO, YAZDIRMA_TIPI, YAZDIRMA_SAYISI, KULLANICI_ID, IP_ADRESI,
-- TARIH, ACIKLAMA). Sonuç: her fiş yazdırmada
--   "logYazdir hatası: Invalid column name 'FIS_REF'"
-- ve tabloda 0 kayıt (yazdırma logu HİÇ çalışmamış).
--
-- ÇÖZÜM: Tablo 0 kayıt olduğu için güvenle DROP + kodun beklediği şemayla CREATE.
-- (Tek yazan: ayr.php logYazdir; hizli_yazdir.php de onun üzerinden çağırır.)
-- GÜVENLİK: Kayıt varsa dokunmaz (yanlışlıkla dolu tabloyu silmesin).
-- ============================================================================

IF OBJECT_ID('dbo.M_YAZDIR_LOG', 'U') IS NOT NULL
BEGIN
    IF NOT EXISTS (SELECT 1 FROM dbo.M_YAZDIR_LOG)
        DROP TABLE dbo.M_YAZDIR_LOG;
    ELSE
        RAISERROR('M_YAZDIR_LOG dolu - elle inceleyin, otomatik migrate iptal.', 16, 1);
END
GO

IF OBJECT_ID('dbo.M_YAZDIR_LOG', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_YAZDIR_LOG (
        ID              INT IDENTITY(1,1) PRIMARY KEY,
        FIS_REF         INT           NOT NULL,                 -- ORFICHE LOGICALREF
        FICHENO         VARCHAR(40)   NULL,                     -- fiş no (AKLSP... dahil)
        YAZDIRMA_TIPI   VARCHAR(20)   NULL,                     -- FIYATLI/FIYATSIZ/EXCEL/HTML/KOLI/DOVIZ/BARKOD...
        YAZDIRMA_SAYISI INT           NOT NULL DEFAULT 1,       -- bu fişin kaçıncı yazdırması
        KULLANICI_ID    INT           NULL,
        IP_ADRESI       VARCHAR(64)   NULL,
        TARIH           DATETIME      NOT NULL DEFAULT GETDATE(),
        ACIKLAMA        NVARCHAR(255) NULL
    );
    CREATE INDEX IX_M_YAZDIR_LOG_FIS ON dbo.M_YAZDIR_LOG (FIS_REF);
END
GO
