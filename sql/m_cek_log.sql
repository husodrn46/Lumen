-- M_CEK_LOG — mshop Çek Girişi (BETA) denetim + geri-alma kaydı.
-- Runtime'da cek_lib.php/cek_log_tablo_olustur() guard'lı oluşturur; bu dosya referans.
-- Faz-1: yalnız müşteri çeki girişi (tek çek = tek bordro). Çıkış/ciro faz-2 (TUR kolonu hazır).
IF OBJECT_ID('dbo.M_CEK_LOG','U') IS NULL
CREATE TABLE M_CEK_LOG (
    ID INT IDENTITY(1,1) PRIMARY KEY,
    REQUEST_ID VARCHAR(40) NOT NULL,
    FIRMADONEM VARCHAR(20) NOT NULL,
    DURUM VARCHAR(20) NOT NULL CONSTRAINT DF_MCL_DURUM DEFAULT 'AKTIF',   -- AKTIF | GERIALINDI | HATA
    TUR VARCHAR(10) NOT NULL CONSTRAINT DF_MCL_TUR DEFAULT 'giris',       -- giris | (cikis: faz-2)
    -- LOGO çek zinciri (4 tablo): CSCARD + CSROLL + CSTRANS + CLFLINE
    CSCARD_REF INT NULL, CSCARD_PORTFOYNO VARCHAR(40) NULL,               -- çek kartı + portföy no
    CSROLL_REF INT NULL, CSROLL_ROLLNO VARCHAR(40) NULL,                  -- giriş bordrosu + bordro no
    CSTRANS_REF INT NULL,                                                 -- giriş hareketi
    CLFLINE_REF INT NULL, CLFLINE_TRANNO VARCHAR(40) NULL,                -- cari alacak ayağı (M6/T61)
    CLIENTREF INT NOT NULL,                                               -- çeki veren cari
    TUTAR DECIMAL(28,8) NOT NULL CONSTRAINT DF_MCL_TUT DEFAULT 0,          -- bordro toplamı (N çek)
    DOCCNT INT NOT NULL CONSTRAINT DF_MCL_DOCCNT DEFAULT 1,               -- bordrodaki çek adedi (çoklu çek)
    KALEMLER NVARCHAR(MAX) NULL,                                          -- JSON: [{cscard_ref,cstrans_ref,portfoyno,cekno,tutar,vade,banka,sahibi},...]
    CEKNO NVARCHAR(60) NULL,                                              -- ilk çek seri no (CSCARD.NEWSERINO); çoklu için KALEMLER'e bak
    BANKNAME NVARCHAR(120) NULL,                                          -- banka (CSCARD.BANKNAME)
    VADE DATE NULL,                                                       -- vade (CSCARD.DUEDATE)
    SAHIBI NVARCHAR(200) NULL,                                            -- çek sahibi (CSCARD.OWING)
    ACIKLAMA NVARCHAR(255) NULL,
    KULLANICI INT NULL, IP VARCHAR(64) NULL,
    OLUSTURMA DATETIME NOT NULL CONSTRAINT DF_MCL_OLU DEFAULT GETDATE(),
    HATA NVARCHAR(MAX) NULL,
    GERIALAN_KULLANICI INT NULL, GERIALMA DATETIME NULL, GERIALMA_NOT VARCHAR(255) NULL,
    CONSTRAINT UQ_MCL_REQ UNIQUE (REQUEST_ID)
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_MCL_CARI')  CREATE INDEX IX_MCL_CARI  ON M_CEK_LOG(CLIENTREF);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_MCL_DURUM') CREATE INDEX IX_MCL_DURUM ON M_CEK_LOG(DURUM);
