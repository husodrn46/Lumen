-- Kaynak güncellenme zamanı UTC'dir; eski dışa aktarma iptal/ödeme engelini kaldıramaz.
IF COL_LENGTH('dbo.M_ET_SIPARIS','KAYNAK_GUNCELLEME') IS NULL
    ALTER TABLE dbo.M_ET_SIPARIS ADD KAYNAK_GUNCELLEME DATETIME2(3) NULL;
GO
