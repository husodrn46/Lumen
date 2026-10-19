-- ============================================================
--  M32 = "Kasa ve Banka İşlemleri" yetki kolonu
--
--  Bağımsız modda tahsilat, ödeme, virman ve masraf fişi girme
--  yetkisi (finans/ modülü). Yönetici (YETKI=0) otomatik alır;
--  personele yetki matrisinden verilir.
--
--  Idempotent: kolon varsa tekrar eklemez.
-- ============================================================
IF COL_LENGTH('dbo.M_P_YETKI', 'M32') IS NULL
BEGIN
    ALTER TABLE dbo.M_P_YETKI
        ADD [M32] INT NOT NULL CONSTRAINT DF_M_P_YETKI_M32 DEFAULT (0) WITH VALUES;
END
GO
