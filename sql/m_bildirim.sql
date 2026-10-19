-- ============================================================================
-- M_BILDIRIM : Uygulama içi bildirim kutusu (ana sayfa zili + bildirimler.php)
-- ----------------------------------------------------------------------------
-- PERSONEL_ID -> LG_SLSMAN.LOGICALREF (alıcı)
-- TUR         -> gorev / cek_vade / guvenlik / ayar / sistem
-- ANAHTAR     -> tekrarı önleyen anahtar (aynı kişiye aynı olay bir kez; NULL = serbest)
-- Idempotent: tekrar çalıştırılabilir.
-- ============================================================================
IF OBJECT_ID('dbo.M_BILDIRIM', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_BILDIRIM (
        ID            INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        PERSONEL_ID   INT            NOT NULL,
        TUR           VARCHAR(20)    NOT NULL,
        BASLIK        NVARCHAR(200)  NOT NULL,
        METIN         NVARCHAR(1000) NULL,
        BAGLANTI      NVARCHAR(300)  NULL,
        ANAHTAR       VARCHAR(120)   NULL,
        OKUNDU        BIT            NOT NULL CONSTRAINT DF_M_BILDIRIM_OKUNDU DEFAULT 0,
        TARIH         DATETIME       NOT NULL CONSTRAINT DF_M_BILDIRIM_TARIH DEFAULT GETDATE(),
        OKUNMA_TARIHI DATETIME       NULL
    );
    CREATE INDEX IX_M_BILDIRIM_KISI ON dbo.M_BILDIRIM (PERSONEL_ID, OKUNDU, TARIH DESC);
    CREATE UNIQUE INDEX UX_M_BILDIRIM_ANAHTAR ON dbo.M_BILDIRIM (PERSONEL_ID, ANAHTAR) WHERE ANAHTAR IS NOT NULL;
END
GO
