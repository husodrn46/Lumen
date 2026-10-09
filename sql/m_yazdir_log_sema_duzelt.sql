-- Veri koruyan yazdırma-logu kurulum kontrolü.
-- Doğru şema dolu olsa da tekrar çalıştırılabilir. Legacy şema sessizce silinmez;
-- veri dönüşümü ayrıca incelenmelidir. Bu betik kayıt silmez veya DROP uygulamaz.
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

IF COL_LENGTH('dbo.M_YAZDIR_LOG', 'FIS_REF') IS NULL
 OR COL_LENGTH('dbo.M_YAZDIR_LOG', 'FICHENO') IS NULL
 OR COL_LENGTH('dbo.M_YAZDIR_LOG', 'YAZDIRMA_TIPI') IS NULL
 OR COL_LENGTH('dbo.M_YAZDIR_LOG', 'YAZDIRMA_SAYISI') IS NULL
 OR COL_LENGTH('dbo.M_YAZDIR_LOG', 'KULLANICI_ID') IS NULL
 OR COL_LENGTH('dbo.M_YAZDIR_LOG', 'IP_ADRESI') IS NULL
 OR COL_LENGTH('dbo.M_YAZDIR_LOG', 'TARIH') IS NULL
 OR COL_LENGTH('dbo.M_YAZDIR_LOG', 'ACIKLAMA') IS NULL
    THROW 51000, 'M_YAZDIR_LOG eski/eksik sema: veri koruyan donusum gerekli, kurulum durduruldu.', 1;
GO
