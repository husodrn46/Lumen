-- ============================================================================
-- Lumen denetim/kuyruk tabloları — önceden hiçbir kurulum betiği yoktu; uygulama
-- bu tablolara yazıp okuyordu ve temiz kurulumda ilgili işlemler (Beni hatırla,
-- fiş/fiyat/iskonto/KDV logları, hızlı yazdırma) sessizce başarısız oluyordu.
-- Kolonlar uygulamanın INSERT/SELECT ifadelerinden çıkarılmıştır.
-- Idempotent: mevcut tablolara dokunmaz.
-- ============================================================================

-- M_REMEMBER_TOKEN: "Beni hatırla" oturum belirteçleri (selector + validator sha256 özeti).
IF OBJECT_ID('dbo.M_REMEMBER_TOKEN', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_REMEMBER_TOKEN (
        [ID] INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        [EXPIRES_AT] DATETIME NOT NULL,
        [IP_ADDRESS] NVARCHAR(100) NULL,
        [PERSONEL_ID] INT NOT NULL,
        [SELECTOR] CHAR(32) NOT NULL,
        [TOKEN_HASH] CHAR(64) NOT NULL,
        [USER_AGENT] NVARCHAR(500) NULL
    );
    CREATE UNIQUE INDEX UX_M_REMEMBER_TOKEN_SELECTOR ON dbo.M_REMEMBER_TOKEN (SELECTOR);
    CREATE INDEX IX_M_REMEMBER_TOKEN_PERSONEL ON dbo.M_REMEMBER_TOKEN (PERSONEL_ID);
END
GO

-- M_FIS_LOG: Sipariş fişi oluşturma/güncelleme/silme denetim kaydı.
IF OBJECT_ID('dbo.M_FIS_LOG', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_FIS_LOG (
        [ID] INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        [ACIKLAMA] NVARCHAR(500) NULL,
        [CARI_ADI] NVARCHAR(500) NULL,
        [CARI_KODU] NVARCHAR(100) NULL,
        [CARI_REF] INT NULL,
        [DEPO] NVARCHAR(100) NULL,
        [DOVIZ_KURU] FLOAT NULL,
        [DOVIZ_TIPI] NVARCHAR(100) NULL,
        [DURUM] INT NULL,
        [ESKI_FIYAT] FLOAT NULL,
        [FICHENO] NVARCHAR(100) NULL,
        [FIS_REF] INT NULL,
        [GENEL_TOPLAM] FLOAT NULL,
        [IP_ADRESI] NVARCHAR(100) NULL,
        [ISLEM_TIPI] NVARCHAR(100) NULL,
        [KULLANICI] INT NULL,
        [KULLANICI_ID] INT NULL,
        [STOK_ADI] NVARCHAR(500) NULL,
        [STOK_KODU] NVARCHAR(100) NULL,
        [TARIH] DATETIME NOT NULL DEFAULT GETDATE(),
        [TOPLAM_FARK] FLOAT NULL,
        [TOPLAM_KDV] FLOAT NULL,
        [TOPLAM_TUTAR] FLOAT NULL,
        [YENI_FIYAT] FLOAT NULL,
        [YUZDELIK_FARK] FLOAT NULL
    );
    CREATE INDEX IX_M_FIS_LOG_FIS_REF ON dbo.M_FIS_LOG (FIS_REF);
END
GO

-- M_FIYAT_LOG: Satır fiyat değişikliği denetim kaydı.
IF OBJECT_ID('dbo.M_FIYAT_LOG', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_FIYAT_LOG (
        [ID] INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        [ACIKLAMA] NVARCHAR(500) NULL,
        [CARI_ADI] NVARCHAR(500) NULL,
        [CARI_KODU] NVARCHAR(100) NULL,
        [DOVIZ_TIPI] NVARCHAR(100) NULL,
        [ESKI_FIYAT] FLOAT NULL,
        [FARK] FLOAT NULL,
        [FICHENO] NVARCHAR(100) NULL,
        [FIS_REF] INT NULL,
        [GENEL_TOPLAM] FLOAT NULL,
        [IP_ADRESI] NVARCHAR(100) NULL,
        [ISLEM_TIPI] NVARCHAR(100) NULL,
        [KULLANICI] INT NULL,
        [KULLANICI_ID] INT NULL,
        [MIKTAR] FLOAT NULL,
        [SATIR_REF] INT NULL,
        [STOK_ADI] NVARCHAR(500) NULL,
        [STOK_KODU] NVARCHAR(100) NULL,
        [TARIH] DATETIME NOT NULL DEFAULT GETDATE(),
        [TOPLAM_FARK] FLOAT NULL,
        [TOPLAM_KDV] FLOAT NULL,
        [TOPLAM_TUTAR] FLOAT NULL,
        [YENI_FIYAT] FLOAT NULL,
        [YUZDELIK_FARK] FLOAT NULL
    );
    CREATE INDEX IX_M_FIYAT_LOG_FIS_REF ON dbo.M_FIYAT_LOG (FIS_REF);
END
GO

-- M_ISKONTO_LOG: İskonto değişikliği denetim kaydı.
IF OBJECT_ID('dbo.M_ISKONTO_LOG', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_ISKONTO_LOG (
        [ID] INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        [ACIKLAMA] NVARCHAR(500) NULL,
        [ESKI_DISCPER] FLOAT NULL,
        [ESKI_DISTDISC] FLOAT NULL,
        [FICHENO] NVARCHAR(100) NULL,
        [FIS_REF] INT NULL,
        [IP_ADRESI] NVARCHAR(100) NULL,
        [ISKONTO_TIPI] INT NULL,
        [KULLANICI] INT NULL,
        [ONAYLAYAN] INT NULL,
        [TARIH] DATETIME NOT NULL DEFAULT GETDATE(),
        [YENI_DISCPER] FLOAT NULL,
        [YENI_DISTDISC] FLOAT NULL
    );
    CREATE INDEX IX_M_ISKONTO_LOG_FIS_REF ON dbo.M_ISKONTO_LOG (FIS_REF);
END
GO

-- M_KDV_DEGISIKLIK_LOG: KDV oranı değişikliği denetim kaydı.
IF OBJECT_ID('dbo.M_KDV_DEGISIKLIK_LOG', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_KDV_DEGISIKLIK_LOG (
        [ID] INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        [ACIKLAMA] NVARCHAR(500) NULL,
        [ESKI_KDV_ORAN] FLOAT NULL,
        [FICHENO] NVARCHAR(100) NULL,
        [FIS_REF] INT NULL,
        [IP_ADRESI] NVARCHAR(100) NULL,
        [KULLANICI] INT NULL,
        [SATIR_REF] INT NULL,
        [STOK_ADI] NVARCHAR(500) NULL,
        [STOK_KODU] NVARCHAR(100) NULL,
        [TARIH] DATETIME NOT NULL DEFAULT GETDATE(),
        [YENI_KDV_ORAN] FLOAT NULL
    );
    CREATE INDEX IX_M_KDV_DEGISIKLIK_LOG_FIS_REF ON dbo.M_KDV_DEGISIKLIK_LOG (FIS_REF);
END
GO

-- M_SATIR_DEGISIKLIK_LOG: Sipariş satırı ekleme/düzenleme/silme denetim kaydı.
IF OBJECT_ID('dbo.M_SATIR_DEGISIKLIK_LOG', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_SATIR_DEGISIKLIK_LOG (
        [ID] INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        [ACIKLAMA] NVARCHAR(500) NULL,
        [ESKI_FIYAT] FLOAT NULL,
        [ESKI_MIKTAR] FLOAT NULL,
        [ESKI_TOTAL] FLOAT NULL,
        [FICHENO] NVARCHAR(100) NULL,
        [FIS_REF] INT NULL,
        [IP_ADRESI] NVARCHAR(100) NULL,
        [ISLEM_TIPI] NVARCHAR(100) NULL,
        [KULLANICI] INT NULL,
        [SATIR_REF] INT NULL,
        [STOK_ADI] NVARCHAR(500) NULL,
        [STOK_KODU] NVARCHAR(100) NULL,
        [TARIH] DATETIME NOT NULL DEFAULT GETDATE(),
        [YENI_FIYAT] FLOAT NULL,
        [YENI_MIKTAR] FLOAT NULL,
        [YENI_TOTAL] FLOAT NULL
    );
    CREATE INDEX IX_M_SATIR_DEGISIKLIK_LOG_FIS_REF ON dbo.M_SATIR_DEGISIKLIK_LOG (FIS_REF);
END
GO

-- M_MOBIL_DIZAYN: Yazdırma kuyruğu (harici yazdırma servisi DURUM=0 satırları işler).
IF OBJECT_ID('dbo.M_MOBIL_DIZAYN', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_MOBIL_DIZAYN (
        [ID] INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        [DIZAYN] NVARCHAR(100) NULL,
        [DURUM] INT NULL,
        [FIS] INT NULL,
        [ISLEM] INT NULL,
        [KULLANICI] INT NULL,
        [LOKASYON] NVARCHAR(100) NULL,
        [MAIL] NVARCHAR(100) NULL,
        [MIKTAR] FLOAT NULL,
        [TARIH] DATETIME NOT NULL DEFAULT GETDATE(),
        [YAZICI] NVARCHAR(100) NULL
    );
    CREATE INDEX IX_M_MOBIL_DIZAYN_DURUM ON dbo.M_MOBIL_DIZAYN (DURUM);
END
GO

-- M_MOBIL_DIZAYN_LOG: Yazdırma kuyruğunun kalıcı geçmişi.
IF OBJECT_ID('dbo.M_MOBIL_DIZAYN_LOG', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_MOBIL_DIZAYN_LOG (
        [ID] INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        [DIZAYN] NVARCHAR(100) NULL,
        [DONEM] INT NULL,
        [FICHENO] NVARCHAR(100) NULL,
        [FIS] INT NULL,
        [ISLEM] INT NULL,
        [KULLANICI] INT NULL,
        [LOKASYON] NVARCHAR(100) NULL,
        [MIKTAR] FLOAT NULL,
        [TARIH] DATETIME NOT NULL DEFAULT GETDATE(),
        [YAZICI] NVARCHAR(100) NULL
    );
END
GO

-- M_FIS_BASLIK: Sipariş fişi ek başlık bilgileri (ağırlık/hacim).
IF OBJECT_ID('dbo.M_FIS_BASLIK', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_FIS_BASLIK (
        [FIS_NO] INT NOT NULL PRIMARY KEY,
        [AGIRLIK] FLOAT NULL,
        [HACIM] FLOAT NULL
    );
END
GO
-- M_USER_SETTINGS: Kullanıcı tercihleri (görünüm, vurgu rengi vb.). Önceden yalnız
-- api/tercih_getir.php ilk çağrıldığında oluşuyordu; web tarafındaki Görünüm
-- ayarları bu tablo yokken çalışmıyordu. Yapı api/tercih_getir.php ile aynıdır.
IF OBJECT_ID('dbo.M_USER_SETTINGS', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_USER_SETTINGS (
        ID INT IDENTITY(1,1) PRIMARY KEY,
        USER_CODE VARCHAR(50) NOT NULL,
        SETTING_KEY VARCHAR(80) NOT NULL,
        SETTING_VALUE NVARCHAR(MAX) NULL
    );
    CREATE INDEX IX_M_USER_SETTINGS_USER ON dbo.M_USER_SETTINGS (USER_CODE, SETTING_KEY);
END
GO

-- M_API_TOKEN: Mobil API oturum belirteçleri (api/_api.inc ile aynı yapı).
IF OBJECT_ID('dbo.M_API_TOKEN', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_API_TOKEN (
        ID INT IDENTITY(1,1) PRIMARY KEY,
        TOKEN VARCHAR(128) NOT NULL,
        PERSONEL INT NOT NULL,
        KULLANICI_ADI VARCHAR(64) NULL,
        OLUSTURMA DATETIME NOT NULL DEFAULT GETDATE(),
        SON_KULLANIM DATETIME NULL,
        AKTIF SMALLINT NOT NULL DEFAULT 1,
        IP VARCHAR(64) NULL
    );
    CREATE INDEX IX_M_API_TOKEN_TOKEN ON dbo.M_API_TOKEN (TOKEN);
END
GO

-- M_ONAYLANMIS_IP: Saldırı modunda yerel ağ dışından erişimine izin verilen IP'ler
-- (loglar.php ile aynı yapı).
IF OBJECT_ID('dbo.M_ONAYLANMIS_IP', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_ONAYLANMIS_IP (
        ID INT IDENTITY(1,1) PRIMARY KEY,
        KULLANICI_ID INT NOT NULL,
        IP_ADRESI VARCHAR(45) NOT NULL,
        ONAYLAYAN_ID INT NOT NULL,
        ONAY_TARIHI DATETIME DEFAULT GETDATE(),
        ACIKLAMA NVARCHAR(255) NULL
    );
END
GO

-- M_REMEMBER_TOKEN belirteç yenileme kolonları: her otomatik girişte doğrulayıcı
-- yenilenir; önceki özet kısa bir süre (eşzamanlı istekler) kabul edilir, sonrasında
-- eski doğrulayıcıyla gelen istek çalıntı çerez sayılır. Kolonlar yoksa uygulama
-- yenilemesiz (eski) davranışla çalışmaya devam eder.
IF COL_LENGTH('dbo.M_REMEMBER_TOKEN', 'PREV_HASH') IS NULL
    ALTER TABLE dbo.M_REMEMBER_TOKEN ADD PREV_HASH CHAR(64) NULL;
GO
IF COL_LENGTH('dbo.M_REMEMBER_TOKEN', 'YENILEME') IS NULL
    ALTER TABLE dbo.M_REMEMBER_TOKEN ADD YENILEME DATETIME NULL;
GO
