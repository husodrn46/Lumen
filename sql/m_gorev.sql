/* ============================================================================
   M_GOREV - Gorev / Yapilacaklar Modulu Tablolari
   ----------------------------------------------------------------------------
   Bu script idempotenttir; tekrar calistirilabilir (IF OBJECT_ID / COL_LENGTH).
   Calistirma:
     sqlcmd -S <sunucu> -d LOGODB -U sa -P <sifre> -i sql/m_gorev.sql
   ya da SSMS icinde dosyayi acip Execute.
   ----------------------------------------------------------------------------
   DURUM kodlari  : 0=Yeni, 1=Goruldu, 2=Yapiliyor, 3=Bitti, 4=Reddedildi, 9=Iptal
   ONCELIK kodlari: 1=Dusuk, 2=Normal, 3=Yuksek
   ATAYAN_ID / ATANAN_ID : LG_SLSMAN.LOGICALREF referansi (FK kurulmaz, LOGO tablosu)
   ============================================================================ */

/* 1) Ana gorev tablosu ----------------------------------------------------- */
IF OBJECT_ID('dbo.M_GOREV', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_GOREV (
        ID                INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        BASLIK            NVARCHAR(200)  NOT NULL,
        ACIKLAMA          NVARCHAR(MAX)  NULL,
        ATAYAN_ID         INT            NOT NULL,   -- gorevi olusturan (LG_SLSMAN.LOGICALREF)
        ATANAN_ID         INT            NOT NULL,   -- gorevi yapacak kisi (LG_SLSMAN.LOGICALREF)
        DURUM             TINYINT        NOT NULL CONSTRAINT DF_M_GOREV_DURUM   DEFAULT 0,
        ONCELIK           TINYINT        NOT NULL CONSTRAINT DF_M_GOREV_ONCELIK DEFAULT 2,
        VADE_TARIHI       DATETIME       NULL,
        RET_SEBEBI        NVARCHAR(500)  NULL,       -- reddedildiyse gerekce
        OLUSTURMA_TARIHI  DATETIME       NOT NULL CONSTRAINT DF_M_GOREV_OLUSTUR DEFAULT GETDATE(),
        GORULME_TARIHI    DATETIME       NULL,       -- atanan ilk goruntuledigi an
        BASLAMA_TARIHI    DATETIME       NULL,       -- "Yapiliyor"a gectigi an
        TAMAMLANMA_TARIHI DATETIME       NULL,       -- "Bitti"ye gectigi an
        GUNCELLEME_TARIHI DATETIME       NULL,
        AKTIF             TINYINT        NOT NULL CONSTRAINT DF_M_GOREV_AKTIF   DEFAULT 1   -- 0=soft delete
    );

    CREATE INDEX IX_M_GOREV_ATANAN ON dbo.M_GOREV (ATANAN_ID, DURUM, AKTIF);
    CREATE INDEX IX_M_GOREV_ATAYAN ON dbo.M_GOREV (ATAYAN_ID, AKTIF);
    CREATE INDEX IX_M_GOREV_VADE   ON dbo.M_GOREV (VADE_TARIHI);
END
GO

/* 2) Gorev hareket / yorum gecmisi ---------------------------------------- */
IF OBJECT_ID('dbo.M_GOREV_HAREKET', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_GOREV_HAREKET (
        ID           INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        GOREV_ID     INT           NOT NULL,
        PERSONEL_ID  INT           NOT NULL,    -- islemi yapan (LG_SLSMAN.LOGICALREF)
        TIP          TINYINT       NOT NULL,    -- 1=durum degisimi, 2=yorum, 3=olusturma, 4=ek yukleme
        ESKI_DURUM   TINYINT       NULL,
        YENI_DURUM   TINYINT       NULL,
        MESAJ        NVARCHAR(MAX) NULL,
        TARIH        DATETIME      NOT NULL CONSTRAINT DF_M_GOREV_HAR_TARIH DEFAULT GETDATE()
    );
    CREATE INDEX IX_M_GOREV_HAREKET_GOREV ON dbo.M_GOREV_HAREKET (GOREV_ID, TARIH);
END
GO

/* 3) Gorev ekleri (dosya / fotograf) -------------------------------------- */
IF OBJECT_ID('dbo.M_GOREV_EK', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_GOREV_EK (
        ID           INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        GOREV_ID     INT           NOT NULL,
        PERSONEL_ID  INT           NOT NULL,
        DOSYA_ADI    NVARCHAR(255) NOT NULL,    -- kullanicinin yukledigi orijinal ad
        DOSYA_YOLU   NVARCHAR(400) NOT NULL,    -- sunucuda saklanan ad/yol
        DOSYA_TIP    NVARCHAR(100) NULL,        -- MIME tipi
        BOYUT        INT           NULL,        -- byte
        TARIH        DATETIME      NOT NULL CONSTRAINT DF_M_GOREV_EK_TARIH DEFAULT GETDATE()
    );
    CREATE INDEX IX_M_GOREV_EK_GOREV ON dbo.M_GOREV_EK (GOREV_ID);
END
GO

/* 4) Yetki kolonlari ------------------------------------------------------- */
/*    M28 = Gorevler (modul erisimi), M29 = Gorev Atama (baskasina gorev acma) */
/*    Mevcut M kolonlari INT tipinde oldugu icin INT NULL kullanildi.          */
IF COL_LENGTH('dbo.M_P_YETKI', 'M28') IS NULL
    ALTER TABLE dbo.M_P_YETKI ADD M28 INT NULL;
GO
IF COL_LENGTH('dbo.M_P_YETKI', 'M29') IS NULL
    ALTER TABLE dbo.M_P_YETKI ADD M29 INT NULL;
GO

PRINT 'M_GOREV modulu tablolari ve yetki kolonlari hazir.';
GO
