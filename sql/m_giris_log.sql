/* ============================================================================
   M_GIRIS_LOG — giris/cikis denetimi ve brute-force korumasi
   ----------------------------------------------------------------------------
   Giris akislari bu tabloyu IP ve hesap bazli hiz sinirlamasi icin kullanir.
   Idempotenttir; kurulum.php tarafindan sql/*.sql ile otomatik uygulanir.
   ============================================================================ */

IF OBJECT_ID('dbo.M_GIRIS_LOG', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_GIRIS_LOG (
        ID             BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        KULLANICI_ID   INT            NOT NULL CONSTRAINT DF_M_GIRIS_LOG_KULLANICI DEFAULT 0,
        KULLANICI_ADI  NVARCHAR(100)  NULL,
        ISLEM_TIPI     NVARCHAR(30)   NOT NULL,
        BASARILI       BIT            NOT NULL CONSTRAINT DF_M_GIRIS_LOG_BASARILI DEFAULT 0,
        IP_ADRESI      VARCHAR(64)    NULL,
        TARAYICI       NVARCHAR(500)  NULL,
        TARIH          DATETIME       NOT NULL CONSTRAINT DF_M_GIRIS_LOG_TARIH DEFAULT GETDATE(),
        ACIKLAMA       NVARCHAR(1000) NULL
    );
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_M_GIRIS_LOG_IP_BASARILI_TARIH'
      AND object_id = OBJECT_ID('dbo.M_GIRIS_LOG')
)
    CREATE INDEX IX_M_GIRIS_LOG_IP_BASARILI_TARIH
        ON dbo.M_GIRIS_LOG (IP_ADRESI, BASARILI, TARIH DESC);
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_M_GIRIS_LOG_KULLANICI_ISLEM_BASARILI_TARIH'
      AND object_id = OBJECT_ID('dbo.M_GIRIS_LOG')
)
    CREATE INDEX IX_M_GIRIS_LOG_KULLANICI_ISLEM_BASARILI_TARIH
        ON dbo.M_GIRIS_LOG (KULLANICI_ADI, ISLEM_TIPI, BASARILI, TARIH DESC);
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_M_GIRIS_LOG_TARIH'
      AND object_id = OBJECT_ID('dbo.M_GIRIS_LOG')
)
    CREATE INDEX IX_M_GIRIS_LOG_TARIH ON dbo.M_GIRIS_LOG (TARIH DESC);
GO

PRINT 'M_GIRIS_LOG tablosu ve indeksleri hazir.';
GO
