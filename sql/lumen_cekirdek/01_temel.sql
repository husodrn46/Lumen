-- ============================================================================
-- Lumen çekirdek şeması (bağımsız mod) — 01: temel tanımlar
--
-- Bağımsız modda Lumen LOGO'ya ihtiyaç duymaz; iş verisi bu LM_ tablolarındadır.
-- Bağlı modda (LOGO) bu tablolar kurulmaz; aynı ekranlar LOGO sürücüsüyle çalışır.
--
-- İlkeler:
--   * Tek firma = tek veritabanı. LOGO'daki firma/dönem tablo ön ekleri yoktur;
--     dönem kilidi LM_AYAR.donem_kilit_tarihi ile yönetilir.
--   * Bakiyeler (stok miktarı, cari bakiye, kasa) hareket tablolarından hesaplanır;
--     ayrıca toplam tutulmaz (LOGO'nun LV_ view'larının karşılığı: lm_* view'ları).
--   * Tutarlar DECIMAL(19,4), miktarlar DECIMAL(19,6), kurlar DECIMAL(19,6).
--   * Her betik tekrar çalıştırılabilir (IF OBJECT_ID ... IS NULL).
-- ============================================================================

-- Kullanıcılar (LOGO'da LG_SLSMAN). M_P_YETKI.PERSONEL bu tablonun ID'sine bağlanır.
IF OBJECT_ID('dbo.LM_PERSONEL', 'U') IS NULL
CREATE TABLE dbo.LM_PERSONEL (
    ID INT IDENTITY(1,1) PRIMARY KEY,
    KOD NVARCHAR(40) NOT NULL,
    AD NVARCHAR(120) NOT NULL,
    AKTIF BIT NOT NULL CONSTRAINT DF_LM_PERSONEL_AKTIF DEFAULT 1,
    EPOSTA NVARCHAR(160) NULL,
    TELEFON NVARCHAR(40) NULL,
    DEPO_ID INT NULL,                              -- varsayılan depo (LOGO: SPECODE); FK 02'de
    YETKI_KODU NVARCHAR(20) NULL,                  -- LOGO: CYPHCODE
    OLUSTURMA DATETIME2(0) NOT NULL CONSTRAINT DF_LM_PERSONEL_OLU DEFAULT SYSDATETIME(),
    CONSTRAINT UQ_LM_PERSONEL_KOD UNIQUE (KOD)
);
GO

-- Firma kimliği ve genel ayarlar (anahtar/değer). Tek satırlık firma bilgisi de burada.
IF OBJECT_ID('dbo.LM_AYAR', 'U') IS NULL
CREATE TABLE dbo.LM_AYAR (
    ANAHTAR VARCHAR(60) NOT NULL PRIMARY KEY,
    DEGER NVARCHAR(400) NULL
);
GO

-- Para birimleri ve günlük kurlar (LOGO'da L_DAILYEXCHANGES).
IF OBJECT_ID('dbo.LM_PARA_BIRIMI', 'U') IS NULL
CREATE TABLE dbo.LM_PARA_BIRIMI (
    KOD CHAR(3) NOT NULL PRIMARY KEY,          -- TRY, USD, EUR, GBP
    AD NVARCHAR(40) NOT NULL,
    SEMBOL NVARCHAR(4) NOT NULL,
    AKTIF BIT NOT NULL CONSTRAINT DF_LM_PB_AKTIF DEFAULT 1
);
GO
IF OBJECT_ID('dbo.LM_KUR', 'U') IS NULL
CREATE TABLE dbo.LM_KUR (
    TARIH DATE NOT NULL,
    PARA_BIRIMI CHAR(3) NOT NULL CONSTRAINT FK_LM_KUR_PB REFERENCES dbo.LM_PARA_BIRIMI(KOD),
    ALIS DECIMAL(19,6) NOT NULL,
    SATIS DECIMAL(19,6) NOT NULL,
    CONSTRAINT PK_LM_KUR PRIMARY KEY (TARIH, PARA_BIRIMI)
);
GO

-- Belge numaralandırma. Sayaç UPDLOCK ile artırılır (lm_seri_sonraki); MAX+1 yarışı yok.
IF OBJECT_ID('dbo.LM_SERI', 'U') IS NULL
CREATE TABLE dbo.LM_SERI (
    TUR VARCHAR(30) NOT NULL PRIMARY KEY,      -- SATIS_SIPARIS, SATIS_FATURA, IRSALIYE, TAHSILAT ...
    ONEK NVARCHAR(10) NOT NULL,
    SON_NO BIGINT NOT NULL CONSTRAINT DF_LM_SERI_SON DEFAULT 0,
    HANE TINYINT NOT NULL CONSTRAINT DF_LM_SERI_HANE DEFAULT 6
);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.LM_PARA_BIRIMI)
    INSERT INTO dbo.LM_PARA_BIRIMI (KOD, AD, SEMBOL) VALUES
        ('TRY', N'Türk Lirası', N'₺'), ('USD', N'ABD Doları', N'$'), ('EUR', N'Euro', N'€'), ('GBP', N'İngiliz Sterlini', N'£');
-- Eksik seri türleri tek tek eklenir (yeni sürümle gelen türler mevcut kurulumda da oluşur).
INSERT INTO dbo.LM_SERI (TUR, ONEK, HANE)
SELECT V.TUR, V.ONEK, 6 FROM (VALUES
    ('SATIS_SIPARIS', N'SIP'), ('ALIS_SIPARIS', N'ASP'), ('SATIS_IRSALIYE', N'IRS'), ('ALIS_IRSALIYE', N'AIR'),
    ('SATIS_FATURA', N'FTR'), ('ALIS_FATURA', N'AFT'), ('SATIS_IADE', N'IAD'), ('ALIS_IADE', N'AIA'),
    ('URETIM', N'URT'), ('SAYIM', N'SYM'), ('TAHSILAT', N'THS'), ('ODEME', N'ODM'), ('CEK_BORDRO', N'BRD'), ('VIRMAN', N'VRM'), ('MASRAF', N'MSR'), ('FINANS_ACILIS', N'FAC')
) V(TUR, ONEK) WHERE NOT EXISTS (SELECT 1 FROM dbo.LM_SERI S WHERE S.TUR = V.TUR);
GO
