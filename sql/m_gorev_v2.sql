/* ============================================================================
   M_GOREV v2 — Alt gorev (checklist), kategori ve onay genislemesi
   ----------------------------------------------------------------------------
   Idempotent; tekrar calistirilabilir.
     sqlcmd -S <sunucu> -d LOGODB -U sa -P <sifre> -i sql/m_gorev_v2.sql
   ----------------------------------------------------------------------------
   Yeni durum  : 5 = Onaylandi (DURUM kolonu zaten TINYINT, sema degismez)
   KATEGORI    : 0=Genel, 1=Depo, 2=Satis, 3=Sevkiyat, 4=Muhasebe (sabit liste)
   ============================================================================ */

/* 1) Alt gorev (checklist) tablosu ---------------------------------------- */
IF OBJECT_ID('dbo.M_GOREV_ALTGOREV', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_GOREV_ALTGOREV (
        ID               INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        GOREV_ID         INT           NOT NULL,
        METIN            NVARCHAR(300) NOT NULL,
        TAMAM            TINYINT       NOT NULL CONSTRAINT DF_M_GOREV_ALT_TAMAM DEFAULT 0,
        SIRA             INT           NOT NULL CONSTRAINT DF_M_GOREV_ALT_SIRA  DEFAULT 0,
        OLUSTURAN_ID     INT           NOT NULL,
        OLUSTURMA_TARIHI DATETIME      NOT NULL CONSTRAINT DF_M_GOREV_ALT_TARIH DEFAULT GETDATE()
    );
    CREATE INDEX IX_M_GOREV_ALTGOREV_GOREV ON dbo.M_GOREV_ALTGOREV (GOREV_ID, SIRA);
END
GO

/* 2) M_GOREV yeni kolonlari ----------------------------------------------- */
IF COL_LENGTH('dbo.M_GOREV', 'KATEGORI') IS NULL
    ALTER TABLE dbo.M_GOREV ADD KATEGORI TINYINT NULL;
GO
IF COL_LENGTH('dbo.M_GOREV', 'ONAY_TARIHI') IS NULL
    ALTER TABLE dbo.M_GOREV ADD ONAY_TARIHI DATETIME NULL;
GO

PRINT 'M_GOREV v2 (alt gorev, kategori, onay) hazir.';
GO
