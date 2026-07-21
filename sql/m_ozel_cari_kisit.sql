-- ============================================================================
--  M_OZEL_CARI_KISIT — Özel Cari Kısıtlamaları
-- ============================================================================
--  Belirli carileri belirli personelden gizlemek için kullanılır.
--  Yönetim ekranı: ayar/ozel_cari_kisitlari.php
--  Okuyan yardımcılar (ayr.php): m_p_ozel_cari_sql_filtresi(),
--  m_p_cariid_goruntulebilir_mi(), m_p_siparis_goruntulebilir_mi()
--
--  Alanlar:
--    CARIREF          : Kısıtlanan carinin LOGICALREF'i (CLCARD)
--    PERSONEL_ID      : Kısıtın geçerli olduğu personel; 0 = tüm personel
--    YETKI_KODU       : Kısıtın kapsamı — 'M19' (cari listesi) / 'M4' (bakiye)
--    OLUSTURAN        : Kısıtı ekleyen personel
--    OLUSTURMA_TARIHI : Kayıt zamanı (DEFAULT şart — ayr.php'deki göç
--                       sorgusu bu sütunu her zaman açıkça yazmaz)
--
--  Betik yinelenebilir (idempotent): var olan tabloyu bozmaz, yalnız
--  eksik sütun/indeks/DEFAULT tanımlarını tamamlar.
-- ============================================================================

IF OBJECT_ID('dbo.M_OZEL_CARI_KISIT', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_OZEL_CARI_KISIT (
        LOGICALREF       INT IDENTITY(1,1) NOT NULL
            CONSTRAINT PK_M_OZEL_CARI_KISIT PRIMARY KEY,
        CARIREF          INT      NOT NULL,
        OLUSTURAN        INT      NULL,
        OLUSTURMA_TARIHI DATETIME NOT NULL
            CONSTRAINT DF_M_OZEL_CARI_KISIT_TARIH DEFAULT (GETDATE()),
        YETKI_KODU       NVARCHAR(10) NULL,
        PERSONEL_ID      INT      NULL
    )
END
GO

-- Eski kurulumlarda eksik olabilecek sütunlar
IF COL_LENGTH('dbo.M_OZEL_CARI_KISIT', 'YETKI_KODU') IS NULL
    ALTER TABLE dbo.M_OZEL_CARI_KISIT ADD YETKI_KODU NVARCHAR(10) NULL
GO

IF COL_LENGTH('dbo.M_OZEL_CARI_KISIT', 'PERSONEL_ID') IS NULL
    ALTER TABLE dbo.M_OZEL_CARI_KISIT ADD PERSONEL_ID INT NULL
GO

-- OLUSTURMA_TARIHI için DEFAULT yoksa ekle (NOT NULL + defaultsuz tablo,
-- ayr.php'deki toplu göç INSERT'ini hataya düşürür).
IF NOT EXISTS (
    SELECT 1 FROM sys.default_constraints dc
    JOIN sys.columns c ON c.object_id = dc.parent_object_id
                      AND c.column_id = dc.parent_column_id
    WHERE dc.parent_object_id = OBJECT_ID('dbo.M_OZEL_CARI_KISIT')
      AND c.name = 'OLUSTURMA_TARIHI'
)
BEGIN
    ALTER TABLE dbo.M_OZEL_CARI_KISIT
        ADD CONSTRAINT DF_M_OZEL_CARI_KISIT_TARIH DEFAULT (GETDATE()) FOR OLUSTURMA_TARIHI
END
GO

-- Aynı (personel, kapsam, cari) üçlüsü tek kayıt olsun
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'UX_M_OZEL_CARI_KISIT_PERSONEL_YETKI_CARIREF'
      AND object_id = OBJECT_ID('dbo.M_OZEL_CARI_KISIT')
)
BEGIN
    CREATE UNIQUE INDEX UX_M_OZEL_CARI_KISIT_PERSONEL_YETKI_CARIREF
        ON dbo.M_OZEL_CARI_KISIT (PERSONEL_ID, YETKI_KODU, CARIREF)
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_M_OZEL_CARI_KISIT_PERSONEL_ID'
      AND object_id = OBJECT_ID('dbo.M_OZEL_CARI_KISIT')
)
BEGIN
    CREATE INDEX IX_M_OZEL_CARI_KISIT_PERSONEL_ID
        ON dbo.M_OZEL_CARI_KISIT (PERSONEL_ID)
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_M_OZEL_CARI_KISIT_OLUSTURAN'
      AND object_id = OBJECT_ID('dbo.M_OZEL_CARI_KISIT')
)
BEGIN
    CREATE INDEX IX_M_OZEL_CARI_KISIT_OLUSTURAN
        ON dbo.M_OZEL_CARI_KISIT (OLUSTURAN)
END
GO
