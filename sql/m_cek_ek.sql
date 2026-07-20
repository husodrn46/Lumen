-- ============================================================================
-- M_CEK_EK : Cek/senet gorsel ekleri (on yuz, arka yuz, ek belge)
-- ----------------------------------------------------------------------------
-- CEK_REF  -> CSCARD.LOGICALREF (gorselin ait oldugu cek/senet)
-- ETIKET   -> 'on' | 'arka' | 'ek' (serbest)
-- Dosyalar cek_ekleri/ klasorunde; web erisimi kapali, cek_ek_goster.php ile servis.
-- Idempotent: tekrar calistirilabilir.
-- ============================================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'M_CEK_EK')
BEGIN
    CREATE TABLE M_CEK_EK (
        ID          INT IDENTITY(1,1) PRIMARY KEY,
        CEK_REF     INT NOT NULL,                 -- CSCARD.LOGICALREF
        ETIKET      NVARCHAR(20) NULL,            -- on / arka / ek
        DOSYA_ADI   NVARCHAR(200) NULL,           -- orijinal ad (gosterim)
        DOSYA_YOLU  NVARCHAR(255) NOT NULL,       -- diskteki guvenli ad
        DOSYA_TIP   NVARCHAR(100) NULL,           -- MIME
        BOYUT       INT NULL,
        PERSONEL_ID INT NULL,                     -- ekleyen
        TARIH       DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE INDEX IX_M_CEK_EK_CEKREF ON M_CEK_EK (CEK_REF);
END
GO
