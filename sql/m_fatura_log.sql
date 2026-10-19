-- M_FATURA_LOG + M_FATURA_LOG_DETAY
-- "Siparisi Faturala" (BETA, geri alinabilir) ozelligi icin geri-alma kayit tablolari.
-- Yalnizca bu uygulamanin kestigi faturalar buraya yazilir; geri alma islemi
-- bu kayitlara dayanir (LOGO'da elle kesilen faturalar etkilenmez).
-- Runtime'da fatura/fatura_lib.php icindeki fatura_log_tablo_olustur() zaten
-- guard'li olusturur; bu dosya repo izi / denetim icindir.
-- Idempotent (IF OBJECT_ID guard).

IF OBJECT_ID('dbo.M_FATURA_LOG','U') IS NULL
CREATE TABLE M_FATURA_LOG (
    ID                 INT IDENTITY(1,1) PRIMARY KEY,
    REQUEST_ID         VARCHAR(40)   NOT NULL,
    FIRMADONEM         VARCHAR(20)   NOT NULL,
    DURUM              VARCHAR(20)   NOT NULL CONSTRAINT DF_MFL_DURUM DEFAULT 'AKTIF', -- AKTIF | GERIALINDI | HATA
    INVOICE_REF        INT           NULL,
    INVOICE_FICHENO    VARCHAR(40)   NULL,
    STFICHE_REF        INT           NULL,
    STFICHE_FICHENO    VARCHAR(40)   NULL,
    CLFLINE_REF        INT           NULL,
    ORFICHE_REF        INT           NOT NULL,
    SIPARIS_NO         VARCHAR(40)   NULL,
    CLIENTREF          INT           NOT NULL,
    NETTOTAL           DECIMAL(28,8) NOT NULL CONSTRAINT DF_MFL_NET DEFAULT 0,
    KULLANICI          INT           NULL,
    IP                 VARCHAR(64)   NULL,
    OLUSTURMA          DATETIME      NOT NULL CONSTRAINT DF_MFL_OLU DEFAULT GETDATE(),
    HATA               NVARCHAR(MAX) NULL,
    GERIALAN_KULLANICI INT           NULL,
    GERIALMA           DATETIME      NULL,
    GERIALMA_NOT       VARCHAR(255)  NULL,
    CONSTRAINT UQ_MFL_REQ UNIQUE (REQUEST_ID)
);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_MFL_ORFICHE')
    CREATE INDEX IX_MFL_ORFICHE ON M_FATURA_LOG(ORFICHE_REF);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_MFL_INVOICE')
    CREATE INDEX IX_MFL_INVOICE ON M_FATURA_LOG(INVOICE_REF);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_MFL_DURUM')
    CREATE INDEX IX_MFL_DURUM ON M_FATURA_LOG(DURUM);

IF OBJECT_ID('dbo.M_FATURA_LOG_DETAY','U') IS NULL
CREATE TABLE M_FATURA_LOG_DETAY (
    ID                 INT IDENTITY(1,1) PRIMARY KEY,
    LOG_ID             INT           NOT NULL,
    SATIR_TIP          TINYINT       NOT NULL,            -- 1=STLINE(silinecek) | 2=ORFLINE(SHIPPEDAMOUNT geri al)
    STLINE_REF         INT           NULL,
    ORFLINE_REF        INT           NULL,
    ESKI_SHIPPEDAMOUNT DECIMAL(28,8) NULL,
    YENI_SHIPPEDAMOUNT DECIMAL(28,8) NULL,
    STOCKREF           INT           NULL,
    AMOUNT             DECIMAL(28,8) NULL,
    CONSTRAINT FK_MFLD_LOG FOREIGN KEY (LOG_ID) REFERENCES M_FATURA_LOG(ID)
);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_MFLD_LOG')
    CREATE INDEX IX_MFLD_LOG ON M_FATURA_LOG_DETAY(LOG_ID);
