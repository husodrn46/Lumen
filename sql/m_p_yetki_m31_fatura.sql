-- ============================================================
--  M31 = "Faturalama" yetki kolonu   (BETA)
--
--  Siparişi satış faturasına çevirme yetkisi. Yönetici (YETKI=0)
--  otomatik alır; personele yetki matrisinden verilir.
--
--  UYARI: Faturalama modülü KDV'siz çalışır (TOTALVAT = 0),
--  muhasebe fişi ve e-fatura oluşturmaz. KDV mükellefi firmalarda
--  olduğu gibi kullanılmamalıdır. Ayrıntı: fatura/fatura_lib.php
--
--  Idempotent: kolon varsa tekrar eklemez.
-- ============================================================
IF COL_LENGTH('dbo.M_P_YETKI', 'M31') IS NULL
BEGIN
    ALTER TABLE dbo.M_P_YETKI
        ADD [M31] INT NOT NULL CONSTRAINT DF_M_P_YETKI_M31 DEFAULT (0) WITH VALUES;
END
GO
