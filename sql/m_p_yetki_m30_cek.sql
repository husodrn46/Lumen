-- ============================================================
--  M30 = "Cek Islemleri" yetki kolonu
--  Cek modulu beta'dan stabile alindi; erisim artik M30 yetki
--  koduyla yonetilir (yonetici YETKI=0 otomatik alir; personel
--  yetki matrisinden aktiflestirilir).
--  Idempotent: kolon varsa tekrar eklemez.
-- ============================================================
IF COL_LENGTH('dbo.M_P_YETKI', 'M30') IS NULL
BEGIN
    ALTER TABLE dbo.M_P_YETKI
        ADD [M30] INT NOT NULL CONSTRAINT DF_M_P_YETKI_M30 DEFAULT (0) WITH VALUES;
END
GO
